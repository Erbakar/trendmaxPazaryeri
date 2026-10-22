<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$opts=getopt('', ['store:','apply']);
$sid=filter_var($opts['store']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$sid) { fwrite(STDERR,"Kullanım: php tools/reconcile-trendyol-listings.php --store=ID [--apply]\n"); exit(1); }
$lock=fopen(sys_get_temp_dir().'/trendmax-reconcile-'.sha1(dirname(__DIR__)).'-'.$sid.'.lock','c');
if ($lock===false || !flock($lock,LOCK_EX|LOCK_NB)) { fwrite(STDERR,"Bu mağaza için eşleştirme zaten çalışıyor.\n"); exit(1); }
require dirname(__DIR__).'/public/index.php';
$container=$GLOBALS['container'];
$tenant=$container->get(\App\Core\TenantContext::class);
$tenant->setStoreId((int)$sid);
try {
    echo json_encode($container->get(\App\Services\TrendyolListingReconciler::class)->run(isset($opts['apply'])),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (\Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
finally { $tenant->clear(); }
