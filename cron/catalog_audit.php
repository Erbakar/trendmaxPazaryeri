<?php
declare(strict_types=1);
// Every minute. Each store has a DB lock, durable cursor and an explicit opt-in.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
@set_time_limit(0);ini_set('memory_limit','1024M');
$lock=fopen(sys_get_temp_dir().'/trendmax-catalog-audit-'.sha1(dirname(__DIR__)).'.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
define('BASE_PATH',dirname(__DIR__));require BASE_PATH.'/public/index.php';
$container=$GLOBALS['container'];
$db=$container->get(App\Core\Database::class);$tenant=$container->get(App\Core\TenantContext::class);$deadline=microtime(true)+50;
// Oldest heartbeat first prevents a large store from starving other opted-in stores.
$stores=$db->fetchAll("SELECT s.id FROM stores s JOIN store_settings enabled ON enabled.store_id=s.id AND enabled.skey='catalog_audit_enabled' AND enabled.svalue='1' LEFT JOIN store_settings beat ON beat.store_id=s.id AND beat.skey='catalog_audit_heartbeat' WHERE s.status='active' ORDER BY COALESCE(beat.svalue,''),s.id");
foreach($stores as$store){
    if(microtime(true)>$deadline-10)break;
    $tenant->setStoreId((int)$store['id']);
    try{$result=['ok'=>true,'result'=>$container->get(App\Services\Catalog\CatalogAuditService::class)->tick(min(45,(int)($deadline-microtime(true))))];}
    catch(Throwable $e){$result=['ok'=>false,'error'=>$e->getMessage()];}
    finally{$tenant->clear();}
    echo json_encode(['at'=>date(DATE_ATOM),'store'=>$store['id'],'result'=>$result],JSON_UNESCAPED_UNICODE).PHP_EOL;
}
