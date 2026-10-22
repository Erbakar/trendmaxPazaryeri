<?php
declare(strict_types=1);
namespace App\Controllers;
use App\Core\{Request,Database,TenantContext};
use App\Models\{Category,Marketplace,CategoryMarketplaceMapping,ProductCategoryOverride};
use App\Services\AuthorizationService;

final class ProductCategoryMappingController extends BaseController
{
    private function context(Request $request, string $id): array
    {
        $category=$this->container->get(Category::class)->find((int)$id);
        $mp=$this->container->get(Marketplace::class)->find($request->integer('marketplace_id'));
        if(!$category || !$mp || $mp['slug']!=='trendyol') $this->error('Kategori veya Trendyol bağlantısı bulunamadı.',404);
        if(!$this->container->get(AuthorizationService::class)->canAccessMarketplace((int)$mp['id'])) $this->error('Pazaryeri erişiminiz yok.',403);
        return [$category,$mp];
    }
    public function index(Request $request, string $id): void
    {
        [$category,$mp]=$this->context($request,$id);
        $sid=$this->container->get(TenantContext::class)->currentStoreId();
        $db=$this->container->get(Database::class);
        $q=mb_substr(trim((string)$request->query('q','')),0,200);
        $page=max(1,$request->integer('page',1)); $offset=($page-1)*100;
        $params=['sid'=>$sid,'cid'=>(int)$id,'q'=>'%'.$q.'%'];
        $filter='p.store_id=:sid AND p.category_id=:cid AND CONCAT_WS(CHAR(32),p.sku,p.name) LIKE :q';
        $total=(int)$db->fetchOne("SELECT COUNT(*) AS n FROM products p WHERE $filter",$params)['n'];
        $products=$db->fetchAll("SELECT p.id,p.sku,p.name,p.main_image,o.marketplace_category_name,o.marketplace_category_path,o.id AS override_id FROM products p LEFT JOIN product_marketplace_category_overrides o ON o.product_id=p.id AND o.store_id=p.store_id AND o.marketplace_id=:mid WHERE $filter ORDER BY p.id LIMIT 100 OFFSET $offset",$params+['mid'=>(int)$mp['id']]);
        $default=$this->container->get(CategoryMarketplaceMapping::class)->findMapping((int)$id,(int)$mp['id']);
        $this->view('categories/product_mappings',compact('category','mp','products','default','total','q','page')+['pageTitle'=>'Ürün Kategori Eşleştirme']);
    }
    public function save(Request $request,string $id): void
    {
        [$category,$mp]=$this->context($request,$id);
        try {
            $ids=json_decode((string)$request->post('product_ids','[]'),true,32,JSON_THROW_ON_ERROR);
            if(!is_array($ids) || !array_is_list($ids) || array_filter($ids,static fn($v)=>!is_int($v)||$v<1)) throw new \InvalidArgumentException('Geçersiz ürün seçimi.');
            $target=trim((string)$request->post('target',''));
            if(!$request->boolean('reset') && $target==='') throw new \InvalidArgumentException('Trendyol kategorisini seçin.');
            $count=$this->container->get(ProductCategoryOverride::class)->assign((int)$category['id'],(int)$mp['id'],$ids,$request->boolean('reset')?null:$target);
            $this->success(['count'=>$count],$count.' ürünün kategori seçimi kaydedildi.');
        } catch(\JsonException|\InvalidArgumentException $e){$this->error($e->getMessage(),422);}
    }
}
