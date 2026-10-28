<?php
declare(strict_types=1);
namespace App\Services\Catalog;

use App\Core\Container;
use App\Models\{Marketplace,Product,CategoryMarketplaceMapping,StoreSetting};
use App\Services\{EntitlementService,PriceRuleEngine,ProductPublishService,XmlStockLinks,StockAvailability};
use App\Services\Import\XmlProfileImportService;
use App\Services\Marketplace\{TrendyolService,TrendyolProductRules};

/** Durable, opt-in reconciliation. Neither a partial feed nor an API acknowledgement proves a listing. */
final class CatalogAuditService
{
    public function __construct(private readonly Container $container,private readonly CatalogAuditRepository $repo,
        private readonly StoreSetting $settings,private readonly CatalogImageProbe $images) {}
    private function get(string $class): object { return $this->container->get($class); }
    public function marketplace(): int
    {
        $mp=$this->get(Marketplace::class)->findBySlug('trendyol');
        if(!$mp||empty($mp['is_active'])||!$this->get(EntitlementService::class)->canAccessMarketplace((int)$mp['id'])||!$this->get(EntitlementService::class)->canAccessModule('products'))throw new \RuntimeException('Aktif ve yetkili Trendyol bağlantısı gerekli.');
        return (int)$mp['id'];
    }
    public function configure(bool $enabled,bool $publish,?bool $enrich=null): void
    {
        $this->marketplace();
        if($enrich!==null)$this->settings->setValue('catalog_source_enrichment',$enrich?'1':'0');
        $this->settings->setMany(['catalog_audit_enabled'=>$enabled?'1':'0','catalog_auto_publish'=>$publish?'1':'0']);
    }
    public function configuration(): array
    {
        return ['enabled'=>$this->settings->getValue('catalog_audit_enabled','0')==='1',
            'publish'=>$this->settings->getValue('catalog_auto_publish','0')==='1',
            'enrich'=>$this->settings->getValue('catalog_source_enrichment','0')==='1',
            'heartbeat'=>$this->settings->getValue('catalog_audit_heartbeat')];
    }
    public function request(): array
    {
        $mid=$this->marketplace();$lock='catalog_audit_'.$this->repo->store();
        if(!(int)($this->repo->db->fetchOne('SELECT GET_LOCK(?,0) ok',[$lock])['ok']??0))return $this->repo->latest($mid)??[];
        try {
            $last=$this->repo->latest($mid);
            if($last&&in_array($last['status'],['preparing','running'],true))return $last;
            return $this->repo->create($mid);
        }finally{$this->repo->db->query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    public function tick(int $seconds=45): array
    {
        if(!$this->configuration()['enabled'])return ['paused'=>true];
        $mid=$this->marketplace();$lock='catalog_audit_'.$this->repo->store();
        if(!(int)($this->repo->db->fetchOne('SELECT GET_LOCK(?,0) ok',[$lock])['ok']??0))return ['busy'=>true];
        $run=null;$deadline=microtime(true)+max(10,$seconds);
        try {
            $this->settings->setValue('catalog_audit_heartbeat',date('Y-m-d H:i:s'));
            $this->reconcileReceipts($mid,$deadline);
            $run=$this->repo->latest($mid);
            if(!$run||in_array($run['status'],['complete','failed'],true)){
                $delay=($run['status']??'')==='failed'?300:900;
                if($run&&strtotime($run['updated_at'])>time()-$delay)return ['waiting'=>true];
                $run=$this->repo->create($mid);
            }
            if($run['status']==='preparing'){
                $snapshot=$this->prepare($mid);$this->repo->saveSnapshot((int)$run['id'],$snapshot);
                $run['total']=count($snapshot['entities']);$this->repo->progress((int)$run['id'],'running',0,$run['total']);
            }else $snapshot=$this->repo->snapshot((int)$run['id']);
            // Refresh source data during long passes; a failed refresh never turns missing rows into zero stock.
            if(($snapshot['source_at']??0)<time()-900){
                $snapshot['sources']=$this->sources();$snapshot['source_at']=time();$this->repo->saveSnapshot((int)$run['id'],$snapshot);
            }
            $cursor=(int)$run['cursor_pos'];$processed=0;
            while($cursor<count($snapshot['entities'])&&microtime(true)<$deadline){
                if(!$this->configuration()['enabled'])break;
                if($processed%10===0){
                    $urls=[];
                    foreach(array_slice($snapshot['entities'],$cursor,10)as$next){
                        $source=$snapshot['sources'][(int)$next['profile_id']]['rows'][(string)$next['sku']]??null;
                        array_push($urls,...array_map([CatalogAuditPolicy::class,'url'],$source['images']??[]),...($next['remote']['images']??[]));
                    }
                    $this->images->prefetch($urls);
                }
                $entity=$snapshot['entities'][$cursor];
                try {
                    $proof=$this->configuration()['enrich']?$this->get(CatalogEvidenceEnricher::class)->complete($mid,$entity,$snapshot):null;
                    $item=$this->inspect($mid,$entity,$snapshot);
                    if($proof&&!empty($proof['category'])&&(string)$proof['category']['id']===(string)($item['checks']['category_id']??'')){
                        $remaining=array_values(array_diff($item['checks']['errors'],['Kategori, özellik ve görsel içerik onayı gerekli.']));
                        // Matching supplier facts plus all technical/image checks are evidence; API acceptance alone is not.
                        if(!$remaining&&empty($item['checks']['reviewed'])&&!empty($item['fingerprint'])){
                            $proof['images']='XML/panel image identity and fetched image checks passed';
                            $this->repo->review($mid,(int)$item['product_id'],$item['fingerprint'],$proof,null);
                            $item=$this->inspect($mid,$entity,$snapshot);
                        }
                        $item['checks']['source_evidence']=$proof;
                    }
                    if($this->configuration()['publish'])$item=$this->dispatch($mid,(int)$run['id'],$item,$snapshot);
                }catch(\Throwable $e){
                    $item=['key'=>$entity['key'],'product_id'=>$entity['product_id']??null,'source_product_id'=>$entity['source_product_id']??null,
                        'sku'=>$entity['sku']??'','barcode'=>$entity['remote']['barcode']??'','name'=>$entity['remote']['name']??'',
                        'status'=>'check_failed','checks'=>['errors'=>[$e->getMessage()]],'remote'=>$entity['remote']??null];
                }
                $this->repo->put($mid,(int)$run['id'],$item);$cursor++;$processed++;
                $this->repo->progress((int)$run['id'],'running',$cursor,(int)$run['total']);
            }
            if($cursor===count($snapshot['entities'])){
                // Retain disappeared identities in the same report; do not silently discard old problems.
                $this->repo->db->query("UPDATE catalog_audit_items SET status='not_observed' WHERE store_id=? AND marketplace_id=? AND run_id<>?",[$this->repo->store(),$mid,$run['id']]);
                $this->repo->progress((int)$run['id'],'complete',$cursor,(int)$run['total']);
                $this->repo->db->query('DELETE FROM catalog_image_checks WHERE store_id=? AND checked_at < DATE_SUB(NOW(),INTERVAL 7 DAY)',[$this->repo->store()]);
                $keep=$this->repo->db->fetchAll('SELECT id FROM catalog_audit_runs WHERE store_id=? AND marketplace_id=? ORDER BY id DESC LIMIT 2',[$this->repo->store(),$mid]);
                $keepIds=array_column($keep,'id');
                foreach(glob($this->repo->directory().'/run-*.json')?:[]as$file)if(preg_match('/run-([0-9]+)\.json$/',$file,$match)&&!in_array($match[1],$keepIds))unlink($file);
            }
            return ['run_id'=>$run['id'],'checked'=>$cursor,'total'=>$run['total'],'processed'=>$processed];
        }catch(\Throwable $e){
            if($run)$this->repo->progress((int)$run['id'],'failed',(int)$run['cursor_pos'],(int)$run['total'],$e->getMessage());
            throw $e;
        }finally{$this->repo->db->query('SELECT RELEASE_LOCK(?)',[$lock]);}
    }
    private function sources(): array
    {
        $sources=[];
        $profiles=$this->repo->db->fetchAll("SELECT id FROM xml_profiles WHERE store_id=? AND is_active=1 AND direction='import'",[$this->repo->store()]);
        foreach($profiles as$p){
            try {
                $s=$this->get(XmlProfileImportService::class)->snapshotForAudit((int)$p['id']);
                // Credentials/source URLs are deliberately excluded from audit files and reports.
                foreach($s['rows']as&$row)$row=array_intersect_key($row,array_flip(['sku','name','description','stock_qty','cost_price','base_price','images']));unset($row);
                $sources[$p['id']]=['checked_at'=>$s['checked_at'],'sha256'=>$s['sha256'],'rows'=>$s['rows']];
            }catch(\Throwable){
                // An unavailable supplier blocks only its own products, and never means an empty feed.
                $sources[$p['id']]=['checked_at'=>date(DATE_ATOM),'error'=>'XML profili #'.$p['id'].' tam okunamadı; bağlantı ve alan eşleştirmesi kontrol edilmeli.'];
            }
        }
        return $sources;
    }
    private function prepare(int $mid): array
    {
        $sources=$this->sources();$api=$this->get(TrendyolService::class);
        $remote=CatalogAuditPolicy::remoteRows($api->readProductCatalog(true),$api->readProductCatalog(false));
        $db=$this->repo->db;$sid=$this->repo->store();
        $products=$db->fetchAll('SELECT id,sku,barcode,xml_profile_id FROM products WHERE store_id=? ORDER BY id',[$sid]);
        $byId=array_column($products,null,'id');$barcodes=[];$skus=[];$profileSku=[];
        foreach($products as$p){if($p['barcode']!=='')$barcodes[(string)$p['barcode']][]=(int)$p['id'];$skus[(string)$p['sku']][]=(int)$p['id'];if($p['xml_profile_id'])$profileSku[$p['xml_profile_id']][(string)$p['sku']][]=(int)$p['id'];}
        foreach($db->fetchAll('SELECT product_id,marketplace_barcode FROM marketplace_products WHERE store_id=? AND marketplace_id=?',[$sid,$mid])as$l){if($l['marketplace_barcode']!=='')$barcodes[(string)$l['marketplace_barcode']][]=(int)$l['product_id'];}
        $links=array_column($this->get(XmlStockLinks::class)->rows($mid),null,'barcode');$entities=[];$used=[];$seen=[];$barcodeCounts=array_count_values(array_column($remote,'barcode'));
        foreach($remote as$r){
            $barcode=$r['barcode'];$link=$links[$barcode]??null;$ids=array_values(array_unique($barcodes[$barcode]??[]));
            if(!$ids)$ids=array_values(array_unique($skus[$r['sku']]??[]));
            $pid=$link['legacy_product_id']??(count($ids)===1?$ids[0]:null);$source=$link?($link['source_product_id']??null):$pid;
            $p=$source?($byId[$source]??null):null;
            $error=($barcodeCounts[$barcode]??0)>1?'Aynı barkod birden çok Trendyol durumunda; kimlik yeniden kontrol edilmeli.':null;
            $seen[$barcode]=true;
            if(!$pid&&!$link)$error='Trendyol ilanının panel bağlantısı eksik veya belirsiz.';
            elseif(!$link&&$r['sku']!==($byId[$pid]['sku']??''))$error='Barkod eşleşiyor ancak stok kodu farklı; kimlik onayı gerekli.';
            $entities[]=['key'=>'barcode:'.hash('sha256',$barcode).($error&&isset($used['remote:'.$barcode])?':duplicate':''),'product_id'=>$pid,'source_product_id'=>$source,
                'profile_id'=>$link['xml_profile_id']??($p['xml_profile_id']??null),'sku'=>$link['source_sku']??($p['sku']??$r['sku']),
                'remote'=>$r,'identity_error'=>$error,'linked'=>(bool)$link];
            $used['remote:'.$barcode]=true;if($pid)$used[$pid]=true;if($source)$used[$source]=true;
        }
        foreach($products as$p)if(!isset($used[$p['id']]))$entities[]=['key'=>$p['barcode']!==''&&count(array_unique($barcodes[(string)$p['barcode']]??[]))===1?'barcode:'.hash('sha256',(string)$p['barcode']):'product:'.$p['id'],'product_id'=>(int)$p['id'],'source_product_id'=>(int)$p['id'],
            'profile_id'=>$p['xml_profile_id'],'sku'=>$p['sku'],'remote'=>null,'identity_error'=>(count(array_unique($barcodes[(string)$p['barcode']]??[]))>1||count(array_unique($skus[(string)$p['sku']]??[]))>1)?'Aynı stok kodu veya barkod birden çok panel kaydına bağlı; mükerrer gönderim engellendi.':null,'linked'=>false];
        foreach($sources as$profile=>$s)foreach($s['rows']??[]as$sku=>$row)if(empty($profileSku[$profile][(string)$sku]))$entities[]=['key'=>'xml:'.$profile.':'.hash('sha256',(string)$sku),
            'product_id'=>null,'source_product_id'=>null,'profile_id'=>$profile,'sku'=>(string)$sku,'remote'=>null,'identity_error'=>'XML ürünü henüz panele alınmamış.','linked'=>false];
        return ['source_at'=>time(),'remote_at'=>date(DATE_ATOM),'sources'=>$sources,'entities'=>$entities];
    }
    private function linkedPricesEnabled(int $mid): bool
    {
        $config=json_decode($this->get(\App\Models\Marketplace::class)->find($mid)['extra_config_json']??'{}',true)?:[];
        return !empty($config['xml_linked_prices']);
    }
    private function inspect(int $mid,array $entity,array $snapshot): array
    {
        $product=$entity['product_id']?$this->get(Product::class)->find((int)$entity['product_id']):null;
        $sourceProduct=$entity['source_product_id']?$this->get(Product::class)->find((int)$entity['source_product_id']):null;
        $profile=(int)$entity['profile_id'];$remote=$entity['remote'];$errors=[];
        if($entity['identity_error'])$errors[]=$entity['identity_error'];
        $sourceKnown=isset($snapshot['sources'][$profile]['rows'])&&empty($snapshot['sources'][$profile]['error']);$source=$snapshot['sources'][$profile]['rows'][(string)$entity['sku']]??null;
        $item=['key'=>$entity['key'],'product_id'=>$entity['product_id'],'source_product_id'=>$entity['source_product_id'],'sku'=>$entity['sku'],
            'barcode'=>$remote['barcode']??($product['barcode']??''),'name'=>$product['name']??$remote['name']??$source['name']??$entity['sku'],'remote'=>$remote,
            'checks'=>['errors'=>[],'differences'=>[],'xml_at'=>$snapshot['sources'][$profile]['checked_at']??null,'remote_at'=>$snapshot['remote_at']],
            'source_profile_id'=>$profile,'expected'=>null,'status'=>'needs_review'];
        if(!$sourceKnown)$errors[]=$snapshot['sources'][$profile]['error']??'Açık ve doğrulanmış bir XML kaynağı bulunamadı.';
        $unavailable=$sourceKnown&&(!$source||(int)$source['stock_qty']<=0);
        if($unavailable&&!$entity['identity_error']){
            $item['expected']=['barcode'=>$item['barcode'],'quantity'=>0];
            $item['checks']['errors']=$errors;$item['checks']['stock_reason']=$source?'XML stoğu sıfır.':'Ürün tam XML kaynağında bulunmuyor.';
            $item['status']=CatalogAuditPolicy::outcome($item['checks'],$remote,false);return $item;
        }
        if(!$product||!$sourceProduct){$item['checks']['errors']=array_values(array_unique(array_merge($errors,['Panel ürün bağlantısı bulunamadı.'])));return $item;}
        $mapping=$this->get(CategoryMarketplaceMapping::class)->forProduct($product,$mid)?:null;
        $item['fingerprint']=$this->contentFingerprint($product,$mapping);
        $item['checks']['images']=CatalogAuditPolicy::images($product);
        $item['checks']['category_id']=$mapping['marketplace_category_id']??null;
        $item['checks']['category_name']=$mapping['marketplace_category_name']??'';
        $item['checks']['reviewed']=$this->repo->reviewed($mid,(int)$product['id'],$item['fingerprint']);
        if($blocked=$this->repo->blockedReason($mid,$product))$errors[]='İçerik çelişkisi: '.$blocked;
        if($sourceKnown)$errors=array_merge($errors,CatalogAuditPolicy::sourceErrors($sourceProduct,$source));
        if(empty($product['is_active'])||!empty($product['is_archived'])||!empty($product['missing_stock']))$errors[]='Ürün panelde kapalı, arşivde veya XML stoğu kapalı.';
        if(!$mapping)$errors[]='Trendyol kategori eşleştirmesi eksik.';
        if(!$item['checks']['reviewed'])$errors[]='Kategori, özellik ve görsel içerik onayı gerekli.';
        $images=$item['checks']['images'];
        if(!$images)$errors[]='Ürün görseli yok.';
        $oldestImageAt=time();
        foreach($images as$url){
            $probe=$this->images->inspect($url);$oldestImageAt=min($oldestImageAt,(int)$probe['at']);
            $item['checks']['image_checks'][]=['url'=>$url,'ok'=>!empty($probe['ok']),'checked_at'=>date(DATE_ATOM,(int)$probe['at'])];
            if(empty($probe['ok']))$errors[]='Görsel erişim hatası ('.basename((string)parse_url($url,PHP_URL_PATH)).'): '.($probe['error']??'Okunamadı');
        }
        $item['checks']['images_checked_at']=date(DATE_ATOM,$oldestImageAt);
        $xmlPrice=$entity['linked']&&$this->linkedPricesEnabled($mid);
        $price=$this->get(PriceRuleEngine::class)->calculatePrice($xmlPrice?$sourceProduct:$product,$mid);
        $listing=$this->repo->db->fetchOne('SELECT * FROM marketplace_products WHERE store_id=? AND marketplace_id=? AND product_id=?',[$this->repo->store(),$mid,$product['id']])?:[];
        if(isset($listing['is_active'])&&!(int)$listing['is_active'])$errors[]='Pazaryeri ilanı panelde kapalı.';
        $quantity=TrendyolProductRules::quantity(max(0,StockAvailability::quantity($sourceProduct)-max(0,(int)($listing['stock_offset']??0))));
        $item['expected']=['barcode'=>$item['barcode'],'quantity'=>$quantity,'salePrice'=>(float)$price['sale_price'],'listPrice'=>(float)$price['list_price']];
        if((float)$price['sale_price']<=0)$errors[]='Hesaplanan fiyat sıfır veya geçersiz.';
        if($entity['linked']&&!$xmlPrice&&$sourceProduct['id']!==$product['id']){
            // A stock alias is not permission to replace the legacy price owner or content.
            if(abs((float)$product['cost_price']-(float)$sourceProduct['cost_price'])>.011||abs((float)$product['base_price']-(float)$sourceProduct['base_price'])>.011)$errors[]='Eski ilanın fiyat tabanı XML ile farklı; fiyat kaynağı incelemesi gerekli.';
        }
        $rejected=$remote&&str_contains(strtolower($remote['status']),'reject');
        if(!$remote||$rejected){
            if(!CatalogAuditPolicy::validPublishBarcode($item['barcode']))$errors[]='Trendyol barkodu en fazla 40 karakter olmalı; harf, rakam, nokta, tire ve alt çizgi kullanılabilir. Barkod kimliği düzeltilmeden otomatik gönderilmez.';
            $preview=$this->get(ProductPublishService::class)->previewForPublish((int)$product['id'],$mid);
            $errors=array_merge($errors,$preview['errors']);
            if(!empty($preview['item'])){$item['payload']=$preview['item'];$item['expected']=array_intersect_key($preview['item'],array_flip(['barcode','quantity','salePrice','listPrice','categoryId']));}
        }else{
            if($remote['blocked'])$errors[]='Trendyol ilanı kilitli/arşivli veya kısıtlı.';
            if($remote['status']==='approved'&&$remote['quantity']>0&&!$remote['onSale'])$errors[]='Trendyol onaylı ancak satışta değil; ilan durumu incelenmeli.';
            if((string)($mapping['marketplace_category_id']??'')!==$remote['categoryId'])$errors[]='Trendyol kategorisi panel eşleştirmesinden farklı.';
            if(!$this->images->compare($images,$remote['images']))$errors[]='Trendyol görsellerinin XML/panel görselleriyle eşliği doğrulanamadı.';
            if(!CatalogAuditPolicy::sameInventory($item['expected'],$remote))$item['checks']['differences'][]='Trendyol stok/fiyatı hesaplanan değerlerden farklı.';
        }
        $item['checks']['errors']=array_values(array_unique($errors));
        $item['status']=CatalogAuditPolicy::outcome($item['checks'],$remote,true);
        if($rejected&&!$errors&&!empty($item['payload'])){
            $last=$this->repo->db->fetchOne("SELECT payload_hash FROM catalog_audit_actions WHERE store_id=? AND marketplace_id=? AND entity_key=? AND operation='publish' ORDER BY id DESC LIMIT 1",[$this->repo->store(),$mid,$item['key']]);
            $old=$this->repo->db->fetchOne('SELECT reviewed_fingerprint,checked_at FROM catalog_audit_items WHERE store_id=? AND marketplace_id=? AND entity_key=?',[$this->repo->store(),$mid,$item['key']]);
            $review=$this->repo->db->fetchOne('SELECT reviewed_at FROM catalog_content_reviews WHERE store_id=? AND marketplace_id=? AND product_id=?',[$this->repo->store(),$mid,$item['product_id']]);
            $changed=$last?($last['payload_hash']!==hash('sha256',CatalogAuditRepository::json($item['payload']))):($old&&($old['reviewed_fingerprint']!==$item['fingerprint']||strtotime($review['reviewed_at']??'')>strtotime($old['checked_at'])));
            if($changed){$item['status']='ready';$item['replace_rejected']=true;}
        }

        // A rejected request may never enter the remote catalogue. Keep the rejection visible
        // and do not automatically resend identical content simply because the barcode is absent.
        if(!$remote&&!empty($item['payload'])){
            $last=$this->repo->db->fetchOne("SELECT status,payload_hash,result_json FROM catalog_audit_actions WHERE store_id=? AND marketplace_id=? AND entity_key=? AND operation='publish' ORDER BY id DESC LIMIT 1",[$this->repo->store(),$mid,$item['key']]);
            if($last&&in_array($last['status'],['rejected','not_submitted'],true)&&hash('sha256',CatalogAuditRepository::json($item['payload']))===$last['payload_hash']){
                $result=json_decode($last['result_json'],true)?:[];
                $item['status']=$last['status']==='not_submitted'?'needs_review':'rejected';$item['checks']['errors'][]='Gönderim engeli: '.($result['rejection']??'Aynı içerik yeniden gönderilmedi.');
            }
        }
        return $item;
    }
    private function reconcileReceipts(int $mid,float $deadline): void
    {
        $db=$this->repo->db;$sid=$this->repo->store();
        $actions=$db->fetchAll("SELECT * FROM catalog_audit_actions WHERE store_id=? AND marketplace_id=? AND operation='publish' AND status IN ('accepted','unknown','submitting') ORDER BY updated_at,id LIMIT 20",[$sid,$mid]);
        foreach($actions as$action){
            if(microtime(true)>$deadline-5)break;
            $result=json_decode($action['result_json']??'null',true)?:[];$payload=json_decode($action['payload_json'],true);
            if(($result['receipt_checked_at']??0)>time()-300||empty($result['batch_ids']))continue;
            // Receipts expire after four hours. Absence or a failed read never proves rejection.
            if(strtotime($action['created_at'])<time()-4*3600)continue;
            $reason=null;$result['receipt_checked_at']=time();
            foreach($result['batch_ids']as$batch){
                try{
                    $receipt=$this->get(TrendyolService::class)->getBatchStatus((string)$batch);$matches=[];
                    foreach($receipt['items']??[]as$entry){
                        $request=$entry['requestItem']['product']??$entry['requestItem']??[];
                        $barcode=(string)($request['barcode']??'');$sku=(string)($request['stockCode']??'');
                        // The API can strip spaces from invalid barcodes in its own receipt.
                        // An exact, unique SKU in this action's batch still identifies its rejection.
                        if(($barcode===(string)$payload['barcode']&&($sku===''||$sku===(string)$payload['stockCode']))
                            ||($sku!==''&&$sku===(string)$payload['stockCode']))$matches[]=$entry;
                    }
                    if(count($matches)===1&&strtoupper((string)($matches[0]['status']??''))==='FAILED'){
                        $reasons=$matches[0]['failureReasons']??[];
                        $reason=is_array($reasons)?implode('; ',array_map(static fn($r)=>is_array($r)?(string)($r['message']??json_encode($r,JSON_UNESCAPED_UNICODE)):(string)$r,$reasons)):(string)$reasons;
                        $reason=$reason?:'Pazaryeri ürün isteğini reddetti.';break;
                    }
                }catch(\Throwable $e){$result['receipt_error']=$e->getMessage();}
            }
            if($reason!==null){
                $result['rejection']=$reason;$this->repo->actionResult((int)$action['id'],'rejected',$result);
                $row=$db->fetchOne('SELECT checks_json FROM catalog_audit_items WHERE store_id=? AND marketplace_id=? AND entity_key=?',[$sid,$mid,$action['entity_key']]);
                if($row){$checks=json_decode($row['checks_json'],true)?:[];$checks['errors']=array_values(array_unique(array_merge($checks['errors']??[],['Trendyol reddetti: '.$reason])));
                    $db->query("UPDATE catalog_audit_items SET status='rejected',checks_json=? WHERE store_id=? AND marketplace_id=? AND entity_key=? AND status='awaiting_result'",[CatalogAuditRepository::json($checks),$sid,$mid,$action['entity_key']]);}
            }else $this->repo->actionResult((int)$action['id'],$action['status'],$result);
        }
    }
    private function dispatch(int $mid,int $run,array $item,array $snapshot): array
    {
        $pending=$this->repo->pending($mid,$item['key']);
        if($pending){
            $payload=json_decode($pending['payload_json'],true);
            $confirmed=$item['remote']&&($pending['operation']==='publish'||CatalogAuditPolicy::sameInventory($payload,$item['remote']));
            if($confirmed)$this->repo->actionResult((int)$pending['id'],str_contains(strtolower($item['remote']['status']),'reject')?'rejected':'confirmed',['observed_at'=>$snapshot['remote_at']]);
            elseif($pending['operation']!=='publish'&&(strtotime($pending['created_at'])<time()-300||($item['status']==='close_required'&&(int)($payload['quantity']??0)>0))){
                // Inventory writes are idempotent; a fresh safe zero must supersede an old positive request.
                $this->repo->actionResult((int)$pending['id'],'superseded',['reason'=>'Fresh inventory will be revalidated before retry']);
            }
            else{$item['status']='awaiting_result';$item['checks']['errors'][]='Önceki isteğin sonucu henüz doğrulanamadı; mükerrer istek gönderilmedi.';return $item;}
        }
        if(!in_array($item['status'],['ready','sync_required','close_required'],true))return $item;
        if(($snapshot['source_at']??0)<time()-900){$item['status']='needs_review';$item['checks']['errors'][]='Güncel XML kontrolü bekleniyor.';return $item;}
        $payload=$item['expected'];$publish=$item['status']==='ready';
        if($item['status']==='close_required'){
            // Existing zero-stock protection remains the final authority; never bypass it.
            $guarded=$this->get(XmlStockLinks::class)->guardPayload([$payload],$mid)[0];
            $source=$this->get(Product::class)->find((int)$item['source_product_id']);
            if($guarded['quantity']!==0||($source&&StockAvailability::quantity($source)>0)){
                $item['checks']['errors'][]='XML stok kapatma senkronu henüz panele işlenmemiş; güvenli sıfırlama bekleniyor.';return $item;
            }
        }else{
            if($item['checks']['errors'])return $item;
            foreach($item['checks']['images']??[]as$url)if(empty($this->images->inspect($url,900)['ok']))throw new \RuntimeException('Gönderim öncesi görsel erişimi doğrulanamadı.');
            $current=$this->get(Product::class)->find((int)$item['product_id']);
            $mapping=$this->get(CategoryMarketplaceMapping::class)->forProduct($current,$mid)?:null;
            if($this->contentFingerprint($current,$mapping)!==$item['fingerprint'])throw new \RuntimeException('Ürün kontrol sırasında değişti; tekrar incelenecek.');
            $sourceNow=$this->get(Product::class)->find((int)$item['source_product_id']);
            $sourceRow=$snapshot['sources'][(int)($item['source_profile_id']??0)]['rows'][(string)$item['sku']]??null;
            if(!$sourceNow||CatalogAuditPolicy::sourceErrors($sourceNow,$sourceRow))throw new \RuntimeException('Gönderim öncesi XML/panel stok veya fiyatı değişti; tekrar incelenecek.');
            if(!$publish){
                $xmlPrice=$this->linkedPricesEnabled($mid)&&!empty($this->get(XmlStockLinks::class)->productForListing($mid,(string)$payload['barcode']));
                $priceNow=$this->get(PriceRuleEngine::class)->calculatePrice($xmlPrice?$sourceNow:$current,$mid);
                $listingNow=$this->repo->db->fetchOne('SELECT is_active,stock_offset FROM marketplace_products WHERE store_id=? AND marketplace_id=? AND product_id=?',[$this->repo->store(),$mid,$current['id']])?:[];
                $quantityNow=TrendyolProductRules::quantity(max(0,StockAvailability::quantity($sourceNow)-max(0,(int)($listingNow['stock_offset']??0))));
                if(empty($current['is_active'])||!empty($current['is_archived'])||(isset($listingNow['is_active'])&&!$listingNow['is_active'])||$quantityNow!==(int)$payload['quantity']||abs($priceNow['sale_price']-$payload['salePrice'])>.011||abs($priceNow['list_price']-$payload['listPrice'])>.011)throw new \RuntimeException('Stok/fiyat kuralı kontrol sırasında değişti; eski değer gönderilmedi.');
            }
            if($publish){
                $preview=$this->get(ProductPublishService::class)->previewForPublish((int)$item['product_id'],$mid);
                if(empty($preview['valid'])||$preview['item']!==$item['payload'])throw new \RuntimeException('Gönderim önizlemesi değişti; tekrar incelenecek.');
                $existing=$this->get(TrendyolService::class)->findProductsByBarcode((string)$payload['barcode']);
                if(!CatalogAuditPolicy::barcodeAbsent($existing)&&!( !empty($item['replace_rejected'])&&CatalogAuditPolicy::canReplaceRejected($existing,(string)$payload['barcode'],(string)$current['sku']) )){$item['status']='awaiting_result';$item['checks']['errors'][]='Barkod Trendyol’da bulundu; sonraki tam katalog kontrolü bekleniyor.';return $item;}
            }
        }
        $operation=$publish?'publish':($item['status']==='close_required'?'close':'inventory');
        $id=$this->repo->intent($mid,$run,$item,$operation,$publish?$item['payload']:$payload);
        try {
            $result=$publish?$this->get(ProductPublishService::class)->publishProducts([(int)$item['product_id']],$mid):$this->get(TrendyolService::class)->updateStock([$payload]);
            // A transport response is only a submission. Remote observation confirms completion on the next pass.
            $state=$publish?CatalogAuditPolicy::submissionState($result):'accepted';
            if(in_array($state,['rejected','not_submitted'],true))$result['rejection']=self::responseErrors($result['errors']??[])?:'Gönderim doğrulaması başarısız.';
            $this->repo->actionResult($id,$state,$result);
            $item['status']=$state==='rejected'?'rejected':($state==='not_submitted'?($item['remote']?'rejected':'needs_review'):'awaiting_result');
            if(!empty($result['errors']))$item['checks']['errors'][]='Gönderim yanıtı: '.self::responseErrors($result['errors']);
        }catch(\Throwable $e){$this->repo->actionResult($id,'unknown',['error'=>$e->getMessage()]);$item['status']='awaiting_result';$item['checks']['errors'][]='İstek sonucu belirsiz: '.$e->getMessage();}
        return $item;
    }
    private static function responseErrors(array $errors): string
    {
        return implode('; ',array_map(static fn($e)=>is_array($e)?(string)($e['error']??'Pazaryeri hatası'):(string)$e,array_slice($errors,0,5)));
    }
    public function contentFingerprint(array $product,?array $mapping): string
    {
        $product['_attribute_values']=$this->get(\App\Models\ProductAttributeValue::class)->getByProduct((int)$product['id']);
        return CatalogAuditPolicy::fingerprint($product,$mapping);
    }
    public function approve(int $itemId,string $fingerprint,int $user): void
    {
        $mid=$this->marketplace();
        $item=$this->repo->db->fetchOne('SELECT * FROM catalog_audit_items WHERE id=? AND store_id=? AND marketplace_id=?',[$itemId,$this->repo->store(),$mid]);
        if(!$item||empty($item['product_id']))throw new \RuntimeException('Ürün inceleme kaydı bulunamadı.');
        $p=$this->get(Product::class)->find((int)$item['product_id']);$m=$this->get(CategoryMarketplaceMapping::class)->forProduct($p,$mid)?:null;
        if(!$m||!hash_equals($this->contentFingerprint($p,$m),$fingerprint))throw new \RuntimeException('Ürün veya kategori değişti; sayfayı yenileyip yeniden inceleyin.');
        $this->repo->review($mid,(int)$p['id'],$fingerprint,['type'=>'panel_content_review','item_id'=>$itemId],$user);
    }
}
