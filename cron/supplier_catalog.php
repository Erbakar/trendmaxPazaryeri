<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
@set_time_limit(0);ini_set('memory_limit','256M');
$fp=fopen(sys_get_temp_dir().'/trendmax-supplier-catalog-'.sha1(dirname(__DIR__)).'.lock','c');if(!$fp||!flock($fp,LOCK_EX|LOCK_NB))exit;
define('BASE_PATH',dirname(__DIR__));require BASE_PATH.'/public/index.php';$c=$GLOBALS['container'];$db=$c->get(App\Core\Database::class);$tenant=$c->get(App\Core\TenantContext::class);$deadline=microtime(true)+45;
$stores=$db->fetchAll("SELECT st.id FROM stores st JOIN xml_profiles xp ON xp.store_id=st.id AND xp.is_active=1 AND xp.direction='import' JOIN store_settings s ON s.store_id=st.id AND s.skey=CONCAT('xmlprofil_',xp.id,'_tedarikci_bilgisi') AND s.svalue='toptanarabul' LEFT JOIN (SELECT store_id,MAX(checked_at) last_checked FROM catalog_supplier_pages GROUP BY store_id) last ON last.store_id=st.id WHERE st.status='active' GROUP BY st.id ORDER BY COALESCE(MAX(last.last_checked),'1970-01-01'),st.id");
foreach($stores as$store){if(microtime(true)>$deadline-12)break;$tenant->setStoreId((int)$store['id']);
    try{$out=$c->get(App\Services\Catalog\SupplierCatalog::class)->tick((int)($deadline-microtime(true)));}
    catch(Throwable $e){$out=['error'=>$e->getMessage()];}finally{$tenant->clear();}
    echo json_encode(['at'=>date(DATE_ATOM),'store'=>$store['id'],'result'=>$out],JSON_UNESCAPED_UNICODE).PHP_EOL;
}
