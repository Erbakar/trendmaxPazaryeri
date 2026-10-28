<?php
declare(strict_types=1);
namespace App\Services\Catalog;

use App\Core\Database;
use App\Core\TenantContext;

/** Every record and private snapshot is scoped by the authenticated store. */
final class CatalogAuditRepository
{
    public function __construct(public readonly Database $db, private readonly TenantContext $tenant) {}
    public function store(): int { return $this->tenant->currentStoreId(); }
    public static function json(mixed $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); }
    public function latest(int $mid): ?array
    {
        return $this->db->fetchOne('SELECT * FROM catalog_audit_runs WHERE store_id=? AND marketplace_id=? ORDER BY id DESC LIMIT 1',[$this->store(),$mid]) ?: null;
    }
    public function create(int $mid): array
    {
        $this->db->query("INSERT INTO catalog_audit_runs (store_id,marketplace_id,status,created_at,updated_at) VALUES (?,?,'preparing',NOW(),NOW())",[$this->store(),$mid]);
        return $this->latest($mid);
    }
    public function progress(int $id,string $status,int $cursor,int $total,?string $error=null): void
    {
        $this->db->query('UPDATE catalog_audit_runs SET status=?,cursor_pos=?,total=?,error_text=?,updated_at=NOW(),finished_at=IF(? IN (\'complete\',\'failed\'),NOW(),NULL) WHERE store_id=? AND id=?',[$status,$cursor,$total,$error,$status,$this->store(),$id]);
    }
    public function directory(): string
    {
        $dir=BASE_PATH.'/storage/catalog-audit/'.$this->store();
        if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new \RuntimeException('Katalog çalışma klasörü oluşturulamadı.');
        return $dir;
    }
    public function saveSnapshot(int $id,array $data): void
    {
        $dir=$this->directory();$tmp=tempnam($dir,'snapshot-');
        try { if(file_put_contents($tmp,self::json($data))===false)throw new \RuntimeException('Katalog kaydedilemedi.');chmod($tmp,0600);if(!rename($tmp,$dir.'/run-'.$id.'.json'))throw new \RuntimeException('Katalog kaydedilemedi.'); }
        finally { if(is_file($tmp))unlink($tmp); }
    }
    public function snapshot(int $id): array
    {
        return json_decode((string)file_get_contents($this->directory().'/run-'.$id.'.json'),true,512,JSON_THROW_ON_ERROR);
    }
    public function put(int $mid,int $run,array $item): void
    {
        $this->db->query('INSERT INTO catalog_audit_items (store_id,marketplace_id,run_id,entity_key,product_id,source_product_id,sku,barcode,name,status,checks_json,expected_json,remote_json,reviewed_fingerprint,checked_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE run_id=VALUES(run_id),product_id=VALUES(product_id),source_product_id=VALUES(source_product_id),sku=VALUES(sku),barcode=VALUES(barcode),name=VALUES(name),status=VALUES(status),checks_json=VALUES(checks_json),expected_json=VALUES(expected_json),remote_json=VALUES(remote_json),reviewed_fingerprint=VALUES(reviewed_fingerprint),checked_at=NOW()',
            [$this->store(),$mid,$run,$item['key'],$item['product_id']??null,$item['source_product_id']??null,$item['sku']??'',$item['barcode']??'',mb_substr($item['name']??'',0,500),$item['status'],self::json($item['checks']),self::json($item['expected']??null),self::json($item['remote']??null),$item['fingerprint']??null]);
    }
    public function reviewed(int $mid,int $pid,string $fingerprint): bool
    {
        $row=$this->db->fetchOne('SELECT evidence_json FROM catalog_content_reviews WHERE store_id=? AND marketplace_id=? AND product_id=? AND fingerprint=?',[$this->store(),$mid,$pid,$fingerprint]);
        return $row&&empty((json_decode($row['evidence_json'],true)?:[])['blocked_reason']);
    }
    public static function imageContentHash(array $product): string
    {
        return hash('sha256',self::json([$product['name'],CatalogAuditPolicy::images($product)]));
    }
    public function blockedReason(int $mid,array $product): ?string
    {
        $row=$this->db->fetchOne('SELECT evidence_json FROM catalog_content_reviews WHERE store_id=? AND marketplace_id=? AND product_id=?',[$this->store(),$mid,$product['id']]);
        $proof=json_decode($row['evidence_json']??'{}',true)?:[];
        return !empty($proof['blocked_reason'])&&($proof['image_content_hash']??'')===self::imageContentHash($product)?(string)$proof['blocked_reason']:null;
    }
    public function review(int $mid,int $pid,string $fingerprint,array $evidence,?int $user): void
    {
        if(!$this->db->fetchOne('SELECT id FROM products WHERE store_id=? AND id=?',[$this->store(),$pid]))throw new \RuntimeException('Ürün mağazanızda bulunamadı.');
        $this->db->query('INSERT INTO catalog_content_reviews (store_id,marketplace_id,product_id,fingerprint,evidence_json,reviewed_by,reviewed_at) VALUES (?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE fingerprint=VALUES(fingerprint),evidence_json=VALUES(evidence_json),reviewed_by=VALUES(reviewed_by),reviewed_at=NOW()',[$this->store(),$mid,$pid,$fingerprint,self::json($evidence),$user]);
    }
    public function pending(int $mid,string $key): ?array
    {
        return $this->db->fetchOne("SELECT * FROM catalog_audit_actions WHERE store_id=? AND marketplace_id=? AND entity_key=? AND status IN ('submitting','accepted','unknown') ORDER BY id DESC LIMIT 1",[$this->store(),$mid,$key]) ?: null;
    }
    public function intent(int $mid,int $run,array $item,string $operation,array $payload): int
    {
        $json=self::json($payload);
        $this->db->query("INSERT INTO catalog_audit_actions (store_id,marketplace_id,run_id,entity_key,product_id,operation,payload_json,payload_hash,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,'submitting',NOW(),NOW())",[$this->store(),$mid,$run,$item['key'],$item['product_id'],$operation,$json,hash('sha256',$json)]);
        return (int)$this->db->getConnection()->lastInsertId();
    }
    public function actionResult(int $id,string $status,array $result): void
    {
        $this->db->query('UPDATE catalog_audit_actions SET status=?,result_json=?,updated_at=NOW() WHERE id=? AND store_id=?',[$status,self::json($result),$id,$this->store()]);
    }
    public function report(int $mid,string $status='',int $page=1,string $search=''): array
    {
        $params=[$this->store(),$mid];$where='store_id=? AND marketplace_id=?';
        $counts=$this->db->fetchAll('SELECT status,COUNT(*) n FROM catalog_audit_items WHERE '.$where.' GROUP BY status',$params);
        if($status!==''){$where.=' AND status=?';$params[]=$status;}
        if($search!==''){$where.=' AND (sku LIKE ? OR barcode LIKE ? OR name LIKE ? OR checks_json LIKE ? OR remote_json LIKE ?)';array_push($params,...array_fill(0,5,'%'.$search.'%'));}
        $total=(int)$this->db->fetchOne('SELECT COUNT(*) n FROM catalog_audit_items WHERE '.$where,$params)['n'];
        $offset=(max(1,$page)-1)*50;
        return ['run'=>$this->latest($mid),'counts'=>array_column($counts,'n','status'),'total'=>$total,'page'=>max(1,$page),
            'items'=>$this->db->fetchAll('SELECT * FROM catalog_audit_items WHERE '.$where.' ORDER BY id LIMIT 50 OFFSET '.$offset,$params)];
    }
}
