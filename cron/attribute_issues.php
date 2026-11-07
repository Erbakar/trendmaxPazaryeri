<?php
declare(strict_types=1);
// Every 10 minutes. Recounts each store's missing/unmatched Trendyol attributes when the
// report is older than ATTRIBUTE_ISSUES_INTERVAL_SECONDS (default 6 h) or a refresh was requested.
// Read-only towards Trendyol (category attribute lists only); sending stays with catalog_audit.php.
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
@set_time_limit(0);ini_set('memory_limit','512M');
$lock=fopen(sys_get_temp_dir().'/trendmax-attribute-issues-'.sha1(dirname(__DIR__)).'.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))exit;
define('BASE_PATH',dirname(__DIR__));require BASE_PATH.'/public/index.php';
$container=$GLOBALS['container'];
$db=$container->get(App\Core\Database::class);$tenant=$container->get(App\Core\TenantContext::class);$deadline=microtime(true)+50;
$stores=$db->fetchAll("SELECT DISTINCT s.id FROM stores s JOIN store_marketplaces sm ON sm.store_id=s.id AND sm.status='active' AND (sm.expires_at IS NULL OR sm.expires_at>NOW()) JOIN marketplaces m ON m.id=sm.marketplace_id AND m.slug='trendyol' AND m.is_active=1 WHERE s.status='active' ORDER BY s.id");
foreach($stores as$store){
    if(microtime(true)>$deadline-5)break;
    $tenant->setStoreId((int)$store['id']);
    try{
        $report=$container->get(App\Services\Catalog\AttributeIssueReport::class);
        $result=$report->due()?['ok'=>true,'result'=>$report->tick($deadline-3)]:['ok'=>true,'result'=>'not_due'];
    }catch(Throwable $e){$result=['ok'=>false,'error'=>$e->getMessage()];}
    finally{$tenant->clear();}
    if(($result['result']??'')!=='not_due')echo json_encode(['at'=>date(DATE_ATOM),'store'=>$store['id'],'result'=>$result],JSON_UNESCAPED_UNICODE).PHP_EOL;
}
