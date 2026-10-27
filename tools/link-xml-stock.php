<?php
declare(strict_types=1);
// Import a reviewed, evidence-backed legacy-stock plan. Default is read-only.
// php tools/link-xml-stock.php --plan=/private/reviewed-plan.json [--apply]
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$options=getopt('', ['plan:','apply']);
if (empty($options['plan'])) { fwrite(STDERR,"--plan gerekli. Varsayılan işlem yalnız kontroldür.\n"); exit(1); }
$plan=json_decode(file_get_contents($options['plan']),true,512,JSON_THROW_ON_ERROR);
foreach(['store_id','marketplace_id','profile_id','supplier_id','source_url','links'] as $key) {
    if (empty($plan[$key])) throw new RuntimeException('Eksik plan alanı: '.$key);
}
require dirname(__DIR__).'/public/index.php';
$c=$GLOBALS['container'];$tenant=$c->get(App\Core\TenantContext::class);$tenant->setStoreId((int)$plan['store_id']);
$db=$c->get(App\Core\Database::class);$links=$c->get(App\Services\XmlStockLinks::class);
$marketplace=$c->get(App\Models\Marketplace::class)->findBySlug('trendyol');
$extra=json_decode($marketplace['extra_config_json']??'{}',true);
$profile=$db->fetchOne('SELECT * FROM xml_profiles WHERE store_id=? AND id=?',[$plan['store_id'],$plan['profile_id']]);
if (!$profile || (int)$marketplace['id']!==(int)$plan['marketplace_id'] || (string)($extra['supplier_id']??'')!==(string)$plan['supplier_id']
    || str_replace('http:','https:',$profile['source_url'])!==str_replace('http:','https:',$plan['source_url'])) throw new RuntimeException('Mağaza/satıcı/XML kaynağı planla eşleşmiyor.');
$db->beginTransaction();
try {
    foreach ($plan['links'] as $row) $links->register((int)$plan['marketplace_id'],(int)$plan['profile_id'],$row);
    $preview=$links->items((int)$plan['marketplace_id']);
    if (isset($options['apply'])) {
        $links->refreshLegacyStock((int)$plan['profile_id']);
        $c->get(App\Services\Queue\QueueService::class)->enqueueUnique((int)$plan['store_id'],'stock_sync',(int)$plan['marketplace_id'],['source'=>'reviewed_xml_stock_links']);
        $db->commit();
    } else $db->rollBack();
    echo json_encode(['mode'=>isset($options['apply'])?'applied':'dry_run','links'=>count($plan['links']),
        'pending_inventory_changes'=>count($preview),'zero_quantity'=>count(array_filter($preview,static fn($r)=>$r['quantity']===0))],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
} catch(Throwable $e) {$db->rollBack();throw $e;}
finally {$tenant->clear();}
