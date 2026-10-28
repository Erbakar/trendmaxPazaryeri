<?php
declare(strict_types=1);
namespace App\Services\Catalog;

use App\Core\{Database,TenantContext};
use App\Models\Product;
use App\Services\Import\XmlProfileSettings;

/** Durable secondary source; never writes product stock, prices, images or upstream XML. */
final class SupplierCatalog
{
    private array $providers=[];
    public function __construct(private readonly Database $db,private readonly TenantContext $tenant,
        private readonly Product $products,private readonly XmlProfileSettings $settings,private readonly SupplierPageClient $http){}
    public static function fingerprint(array $p): string
    {
        return hash('sha256',json_encode([$p['sku'],$p['name'],$p['description']??'',CatalogAuditPolicy::images($p)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
    private function enabled(array $p): bool
    {
        if(empty($p['xml_profile_id']))return false;$key=$this->tenant->currentStoreId().'_'.(int)$p['xml_profile_id'];
        return ($this->providers[$key]??=$this->settings->forProfile((int)$p['xml_profile_id'])['tedarikci_bilgisi'])==='toptanarabul';
    }
    private function row(int $pid): ?array
    {
        return $this->db->fetchOne('SELECT *,UNIX_TIMESTAMP(checked_at) checked_epoch,UNIX_TIMESTAMP(next_check_at) next_epoch FROM catalog_supplier_pages WHERE store_id=? AND product_id=?',[$this->tenant->currentStoreId(),$pid])?:null;
    }
    public function evidence(array $p): ?array
    {
        if(!$this->enabled($p))return null;$r=$this->row((int)$p['id']);
        if(!$r||$r['status']!=='extra'||$r['source_hash']!==self::fingerprint($p)||(int)$r['checked_epoch']<time()-86400||$this->errors($p))return null;
        return ['page'=>json_decode($r['page_json'],true),'facts_hash'=>$r['facts_hash'],'source_hash'=>$r['source_hash'],'checked_at'=>$r['checked_at']];
    }
    public function errors(array $p): array
    {
        if(!$this->enabled($p))return [];$r=$this->row((int)$p['id']);if(!$r||!$r['applied_source_hash'])return [];
        return $r['applied_source_hash']!==self::fingerprint($p)||$r['source_hash']!==self::fingerprint($p)
            ||$r['applied_facts_hash']!==$r['facts_hash']||(int)$r['checked_epoch']<time()-86400||!in_array($r['status'],['extra','no_extra'],true)
            ?['Ek tedarikçi bilgisi değişti veya güncelliği doğrulanamadı; ürün özellikleri yeniden incelenmeli.']:[];
    }
    public function markApplied(array $p,array $evidence): void
    {
        $stmt=$this->db->query('UPDATE catalog_supplier_pages SET applied_source_hash=source_hash,applied_facts_hash=facts_hash WHERE store_id=? AND product_id=? AND source_hash=? AND facts_hash=?',[$this->tenant->currentStoreId(),$p['id'],$evidence['source_hash'],$evidence['facts_hash']]);
        if($stmt->rowCount()!==1&&!$this->db->fetchOne('SELECT id FROM catalog_supplier_pages WHERE store_id=? AND product_id=? AND source_hash=? AND facts_hash=? AND applied_source_hash=source_hash AND applied_facts_hash=facts_hash',[$this->tenant->currentStoreId(),$p['id'],$evidence['source_hash'],$evidence['facts_hash']]))throw new \RuntimeException('Tedarikçi bilgisi işlem sırasında değişti; tamamlama yeniden denenecek.');
    }
    public function acknowledge(array $p): void
    {
        if(!$this->enabled($p))return;$r=$this->row((int)$p['id']);
        if(!$r||!$r['applied_source_hash'])return;
        if($r['source_hash']!==self::fingerprint($p)||!in_array($r['status'],['extra','no_extra'],true)||(int)$r['checked_epoch']<time()-86400)throw new \RuntimeException('Tedarikçi sayfasının güncel kontrolü bekleniyor; özellik onayı henüz kaydedilmedi.');
        $this->db->query('UPDATE catalog_supplier_pages SET applied_source_hash=source_hash,applied_facts_hash=facts_hash WHERE store_id=? AND product_id=?',[$this->tenant->currentStoreId(),$p['id']]);
    }
    public function record(array $p,?array $page,string $status='',?string $error=null,?int $observedAt=null): void
    {
        if((int)$p['store_id']!==$this->tenant->currentStoreId()||!$this->products->find((int)$p['id']))throw new \RuntimeException('Ürün mağazanızda bulunamadı.');
        if($page){if(!SupplierPage::allowedUrl($page['url']))throw new \InvalidArgumentException('Geçersiz tedarikçi adresi.');$extra=SupplierPage::supplement($p,$page);$status=$extra===null?'identity_conflict':($extra===''?'no_extra':'extra');}
        $json=$page?CatalogAuditRepository::json($page):null;$hash=$page?hash('sha256',CatalogAuditRepository::json([$page['sku'],$page['name'],$page['description'],$page['fields']])):null;
        $delay=$status==='error'?900:86400;
        $at=max(0,min(time(),$observedAt??time()));
        $this->db->query('INSERT INTO catalog_supplier_pages(store_id,profile_id,product_id,source_hash,status,page_json,facts_hash,error_text,product_seen_at,checked_at,next_check_at) VALUES(?,?,?,?,?,?,?,?,?,FROM_UNIXTIME(?),FROM_UNIXTIME(?)) ON DUPLICATE KEY UPDATE profile_id=VALUES(profile_id),source_hash=VALUES(source_hash),status=VALUES(status),page_json=VALUES(page_json),facts_hash=VALUES(facts_hash),error_text=VALUES(error_text),product_seen_at=VALUES(product_seen_at),checked_at=VALUES(checked_at),next_check_at=VALUES(next_check_at)',
            [$this->tenant->currentStoreId(),$p['xml_profile_id'],$p['id'],self::fingerprint($p),$status,$json,$hash,$error,$p['updated_at']??$p['created_at']??null,$at,$at+$delay]);
    }
    public function tick(int $seconds=40): array
    {
        $sid=$this->tenant->currentStoreId();$lock='supplier_catalog_'.$sid;
        if(!(int)$this->db->fetchOne('SELECT GET_LOCK(?,0) ok',[$lock])['ok'])return ['busy'=>true];
        $deadline=microtime(true)+max(5,$seconds);$count=0;$seen=0;
        try{
            while($count<4&&$seen<200&&microtime(true)<$deadline-12){
                $p=$this->db->fetchOne("SELECT p.*,cache.source_hash cached_hash,cache.next_check_at FROM products p JOIN xml_profiles xp ON xp.id=p.xml_profile_id AND xp.store_id=p.store_id JOIN store_settings opt ON opt.store_id=p.store_id AND opt.skey=CONCAT('xmlprofil_',xp.id,'_tedarikci_bilgisi') AND opt.svalue='toptanarabul' LEFT JOIN catalog_supplier_pages cache ON cache.product_id=p.id AND cache.store_id=p.store_id WHERE p.store_id=? AND xp.is_active=1 AND xp.direction='import' AND p.is_active=1 AND p.is_archived=0 AND COALESCE(p.missing_stock,0)=0 AND EXISTS(SELECT 1 FROM catalog_audit_items a WHERE a.store_id=p.store_id AND a.product_id=p.id AND a.status='needs_review') AND (cache.id IS NULL OR cache.next_check_at<=NOW() OR COALESCE(p.updated_at,p.created_at)>COALESCE(cache.product_seen_at,'1970-01-01')) ORDER BY CASE WHEN cache.applied_source_hash IS NOT NULL AND cache.next_check_at<=NOW() THEN 0 WHEN cache.status='error' AND cache.next_check_at<=NOW() THEN 1 ELSE 2 END,COALESCE(cache.checked_at,'1970-01-01'),p.id LIMIT 1",[$sid]);
                if(!$p)break;$seen++;unset($p['cached_hash'],$p['next_check_at']);$r=$this->row((int)$p['id']);
                if($r&&$r['source_hash']===self::fingerprint($p)&&(int)$r['next_epoch']>time()){
                    $this->db->query('UPDATE catalog_supplier_pages SET product_seen_at=? WHERE store_id=? AND product_id=?',[$p['updated_at']??$p['created_at'],$sid,$p['id']]);continue;
                }
                try{
                    $html=$this->http->get('https://'.SupplierPage::HOST.'/arama/'.rawurlencode($p['sku']));$links=SupplierPage::searchLinks($html);
                    if(count($links)>3){$this->record($p,null,'ambiguous');$count++;continue;}
                    $matches=[];
                    foreach($links as$url){if(microtime(true)>$deadline-12)throw new \RuntimeException('Tedarikçi araması sonraki turda tamamlanacak.');$page=SupplierPage::product($this->http->get($url),$url);if($page['sku']===$p['sku'])$matches[]=$page;}
                    $this->record($p,count($matches)===1?$matches[0]:null,count($matches)>1?'ambiguous':'not_found');$count++;
                }catch(\Throwable $e){$this->record($p,null,'error',mb_substr($e->getMessage(),0,1000));$count++;break;}
            }
            return ['checked'=>$count];
        }finally{$this->db->query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    public function summary(): array
    {
        $sid=$this->tenant->currentStoreId();
        $profiles=$this->db->fetchAll("SELECT xp.id,xp.name FROM xml_profiles xp JOIN store_settings s ON s.store_id=xp.store_id AND s.skey=CONCAT('xmlprofil_',xp.id,'_tedarikci_bilgisi') AND s.svalue='toptanarabul' WHERE xp.store_id=? AND xp.is_active=1 AND xp.direction='import'",[$sid]);
        if(!$profiles)return [];
        $where='store_id=? AND profile_id IN ('.implode(',',array_fill(0,count($profiles),'?')).')';$params=[$sid,...array_column($profiles,'id')];
        return ['profiles'=>$profiles,'states'=>$this->db->fetchAll('SELECT status,COUNT(*) n FROM catalog_supplier_pages WHERE '.$where.' GROUP BY status',$params),
            'applied'=>(int)$this->db->fetchOne('SELECT COUNT(*) n FROM catalog_supplier_pages WHERE '.$where.' AND applied_source_hash IS NOT NULL',$params)['n'],
            'last'=>$this->db->fetchOne('SELECT MAX(checked_at) at FROM catalog_supplier_pages WHERE '.$where,$params)['at']];
    }
}
