<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\Request;
use App\Core\Database;
use App\Core\TenantContext;
use App\Models\CategoryMarketplaceMapping;
use App\Models\Marketplace;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Services\Marketplace\TrendyolAttributeCatalog;
use App\Services\Marketplace\TrendyolAttributeResolver;
use App\Services\ProductPublishService;

final class TrendyolAttributeController extends BaseController
{
    private function authorization(): \App\Services\AuthorizationService
    {
        return $this->container->get(\App\Services\AuthorizationService::class);
    }
    private function mapping(string $id): array
    {
        if (preg_match('/^product-(\d+)-(\d+)$/',$id,$match)) {
            $product=$this->container->get(Product::class)->find((int)$match[1]);
            $mapping=$product ? $this->container->get(CategoryMarketplaceMapping::class)->forProduct($product,(int)$match[2]) : false;
            if (!$mapping || empty($mapping['_override_id'])) $this->error('Ürüne özel kategori seçimi bulunamadı.',404);
        } else {
        $mapping = $this->container->get(CategoryMarketplaceMapping::class)->find((int)$id);
        }
        if (!$mapping) $this->error('Eşleştirme bulunamadı.',404);
        $mp = $this->container->get(Marketplace::class)->find((int)$mapping['marketplace_id']);
        if (!$mp || $mp['slug'] !== 'trendyol') $this->error('Trendyol eşleştirmesi seçin.',422);
        if (!$this->authorization()->canAccessMarketplace((int)$mp['id'])) $this->error('Bu pazaryerine erişiminiz yok.',403);
        return $mapping;
    }
    private function product(array $mapping, int $id): array
    {
        $product = $this->container->get(Product::class)->find($id);
        if (!$product || (int)$product['category_id'] !== (int)$mapping['category_id']) $this->error('Bu kategoride ürün bulunamadı.',404);
        $effective=$this->container->get(CategoryMarketplaceMapping::class)->forProduct($product,(int)$mapping['marketplace_id']);
        if (!empty($effective['_override_id']) && ($effective['_override_id']??null)!==($mapping['_override_id']??null)) $this->error('Bu ürün ayrı kategoriye eşlenmiş. Ürünleri ayrı eşleştir ekranındaki özellikleri kullanın.',422);
        return $product;
    }
    public function show(Request $request, string $id): void
    {
        $mapping = $this->mapping($id);
        try {
            $catalog = $this->container->get(TrendyolAttributeCatalog::class)->get((string)$mapping['marketplace_category_id'], $request->boolean('refresh'));
            $config = CategoryMarketplaceMapping::decodeAttributeMapping($mapping);
            $sid = $this->container->get(TenantContext::class)->currentStoreId();
            $products = $this->container->get(Database::class)->fetchAll('SELECT id,sku,name FROM products WHERE store_id=:sid AND category_id=:cid AND NOT EXISTS (SELECT 1 FROM product_marketplace_category_overrides po WHERE po.store_id=products.store_id AND po.product_id=products.id AND po.marketplace_id='.(int)$mapping['marketplace_id'].' ) AND CONCAT_WS(CHAR(32),sku,name) LIKE :query ORDER BY id LIMIT 101', ['sid'=>$sid,'cid'=>$mapping['category_id'],'query'=>'%'.trim((string)$request->query('q','')).'%']);
            if (!empty($mapping['_override_id'])) $products=[$this->product($mapping,(int)$mapping['product_id'])];
            $more = count($products)>100; $products = array_slice($products,0,100);
            $productId = $request->integer('product_id');
            if (!empty($mapping['_override_id']) && $productId!==(int)$mapping['product_id']) $this->error('Bu eşleştirme yalnız seçilen ürün içindir.',422);
            if ($productId>0) $this->product($mapping,$productId);
            if ($productId>0 && !in_array($productId,array_map('intval',array_column($products,'id')),true)) $products[]=$this->product($mapping,$productId);
            $this->success($catalog + [
                'mapping'=>['id'=>$id,'category_id'=>(int)$mapping['category_id'],'name'=>$mapping['marketplace_category_name'],'target'=>$mapping['marketplace_category_id']],
                'defaults'=>(object)CategoryMarketplaceMapping::attributeDefaults($mapping),
                'sources'=>(object)($config['sources'] ?? []),
                'product_values'=>(object)($productId>0 ? CategoryMarketplaceMapping::productAttributeValues($mapping,'id:'.$productId) : []),
                'local_attributes'=>$this->container->get(ProductAttribute::class)->all([], 'name ASC'),
                'products'=>$products,'more_products'=>$more,'can_edit'=>$this->authorization()->hasPermission('categories.edit')]);
        } catch (\Throwable $e) { $this->error('Trendyol özellikleri alınamadı: '.$e->getMessage(),502); }
    }
    public function save(Request $request, string $id): void
    {
        $mapping = $this->mapping($id);
        try {
            $values = json_decode((string)$request->post('values','{}'),true,64,JSON_THROW_ON_ERROR);
            $sources = json_decode((string)$request->post('sources','{}'),true,64,JSON_THROW_ON_ERROR);
            if (!is_array($values) || !is_array($sources)) throw new \InvalidArgumentException('Geçersiz eşleme.');
            $productId = $request->integer('product_id');
            if (!empty($mapping['_override_id']) && $productId!==(int)$mapping['product_id']) $this->error('Bu eşleştirme yalnız seçilen ürün içindir.',422);
            if ($productId>0) $this->product($mapping,$productId);
            if ((string)$request->post('category_target','') !== (string)$mapping['marketplace_category_id']) throw new \InvalidArgumentException('Kategori değişti; ekranı yeniden açın.');
            $catalog = $this->container->get(TrendyolAttributeCatalog::class)->get((string)$mapping['marketplace_category_id']);
            $definitions = array_column($catalog['attributes'],null,'id');
            $validValues=[]; $validSources=[];
            foreach ($values as $aid=>$value) {
                if (!isset($definitions[$aid]) || !is_string($value)) throw new \InvalidArgumentException('Geçersiz özellik.');
                if (trim($value)==='') continue;
                TrendyolAttributeResolver::value($definitions[$aid],$value);
                $validValues[$aid]=trim($value);
            }
            $locals = array_column($this->container->get(ProductAttribute::class)->all(),'id');
            foreach ($sources as $aid=>$source) {
                if ($source==='') continue;
                if (!isset($definitions[$aid]) || !is_scalar($source) || !in_array((int)$source,$locals,true)) throw new \InvalidArgumentException('Geçersiz yerel özellik eşlemesi.');
                $validSources[$aid]=(string)$source;
            }
            $db=$this->container->get(Database::class);
            if (!empty($mapping['_override_id'])) {
                $expected=(string)$request->post('category_target','');
                if ($expected!==$mapping['marketplace_category_id']) throw new \InvalidArgumentException('Kategori değişti; ekranı yeniden açın.');
                $this->container->get(\App\Models\ProductCategoryOverride::class)->saveAttributes((int)$mapping['_override_id'],$expected,$validValues,$validSources,$productId);
            } else {
            $db->transaction(function() use ($db,$id,$mapping,$validValues,$validSources,$productId) {
                $sid=$this->container->get(TenantContext::class)->currentStoreId();
                $current=$db->fetchOne('SELECT marketplace_category_id FROM category_marketplace_mapping WHERE id=:id AND store_id=:sid FOR UPDATE',['id'=>(int)$id,'sid'=>$sid]);
                if (!$current || $current['marketplace_category_id']!==$mapping['marketplace_category_id']) throw new \RuntimeException('Kategori değişti; ekranı yenileyin.');
                $this->container->get(CategoryMarketplaceMapping::class)->saveTrendyolAttributes((int)$id,$validValues,$validSources,$productId>0?$productId:null);
            });
            }
            $this->success([],'Özellik eşleştirmeleri kaydedildi.');
        } catch (\InvalidArgumentException|\JsonException $e) { $this->error($e->getMessage(),422); }
        catch (\Throwable $e) { $this->error('Kaydedilemedi: '.$e->getMessage(),502); }
    }
    public function preview(Request $request, string $id): void
    {
        if (!$this->authorization()->hasPermission('products.view')) $this->error('Ürün görüntüleme yetkiniz yok.',403);
        $mapping=$this->mapping($id);
        if (!empty($mapping['_override_id']) && $request->integer('product_id')!==(int)$mapping['product_id']) $this->error('Bu eşleştirme yalnız seçilen ürün içindir.',422);
        $product=$this->product($mapping,$request->integer('product_id'));
        try {
            $service=$this->container->get(ProductPublishService::class);
            $this->success(['category'=>['id'=>$mapping['marketplace_category_id'],'name'=>$mapping['marketplace_category_name']], 'resolution'=>$service->resolveTrendyolAttributes($product,$mapping),
                'preview'=>$service->previewForPublish((int)$product['id'],(int)$mapping['marketplace_id'])]);
        } catch (\Throwable $e) { $this->error('Gönderim kontrol edilemedi: '.$e->getMessage(),502); }
    }
}
