<?php
declare(strict_types=1);
namespace App\Services\Catalog;

use App\Core\Container;
use App\Models\{Product,CategoryMarketplaceMapping,ProductCategoryOverride};
use App\Services\Marketplace\{TrendyolAttributeCatalog,TrendyolAttributeResolver};

/** Store-scoped, reversible field completion. Never changes source facts or live content. */
final class CatalogEvidenceEnricher
{
    public function __construct(private readonly Container $container,private readonly CatalogAuditRepository $repo) {}
    public function complete(int $mid,array $entity,array $snapshot): ?array
    {
        if(!empty($entity['identity_error'])||empty($entity['product_id']))return null;
        $product=$this->container->get(Product::class)->find((int)$entity['product_id']);
        if(!$product||empty($product['xml_profile_id'])||empty($product['is_active'])||!empty($product['is_archived'])||!empty($product['missing_stock']))return null;
        if($this->repo->blockedReason($mid,$product))return null;
        $source=$snapshot['sources'][(int)$entity['profile_id']]['rows'][(string)$entity['sku']]??null;
        if(!$source||CatalogAuditPolicy::sourceErrors($product,$source)||(string)$source['name']!==(string)$product['name']
            ||!array_key_exists('description',$source)||CatalogSourceEvidence::text((string)$source['description'])!==CatalogSourceEvidence::text((string)$product['description']))return null;
        $maps=$this->container->get(CategoryMarketplaceMapping::class);$mapping=$maps->forProduct($product,$mid)?:null;
        $guess=CatalogSourceEvidence::category($product);$current=(string)($mapping['marketplace_category_id']??'');$target=$guess['id']??$current;
        $remote=$entity['remote'];
        if($remote&&$remote['status']==='approved'&&$target!==$remote['categoryId'])return null;
        if(!$target)return null;
        $leaf=$maps->getMarketplaceCategoryLeafStatus($mid,$target);if(!$leaf||!$leaf['sellable'])return null;
        $attributes=$this->container->get(TrendyolAttributeCatalog::class)->get($target)['attributes'];
        $config=json_decode($mapping['attribute_mapping_json']??'{}',true)?:[];
        $values=array_replace($config['defaults']??[],$config['products']['id:'.$product['id']]??[]);
        if($current!==$target)$values=array_intersect_key($values,array_flip(['47','348','1192','343','346']));
        $attributeMap=array_column($attributes,null,'id');
        foreach($values as$id=>$value){
            if(!isset($attributeMap[$id])){unset($values[$id]);continue;}
            try{TrendyolAttributeResolver::value($attributeMap[$id],(string)$value);}catch(\InvalidArgumentException){return null;}
        }
        // Explicit field-source mappings retain their authority and are never flattened into defaults.
        if(array_filter($config['sources']??[]))return null;
        $proposed=CatalogSourceEvidence::attributes($product,$attributes,$values);
        $proof=['type'=>'supplier_text_rules','version'=>CatalogSourceEvidence::VERSION,'category'=>$guess,
            'attributes'=>$proposed['evidence'],'source_hash'=>hash('sha256',$product['name']."\n".$product['description'])];
        if($current!==$target||$proposed['evidence']){
            $db=$this->repo->db;$db->beginTransaction();
            try{
                $locked=$db->fetchOne('SELECT * FROM products WHERE store_id=? AND id=? FOR UPDATE',[$this->repo->store(),$product['id']]);
                $now=$maps->forProduct($locked,$mid)?:null;
                if($locked['name']!==$product['name']||$locked['description']!==$product['description']||$now!==$mapping)throw new \RuntimeException('Ürün/eşleştirme değişti; kaynak tamamlama sonraki kontrolde yinelenecek.');
                $overrides=$this->container->get(ProductCategoryOverride::class);
                $overrides->assign((int)$product['category_id'],$mid,[(int)$product['id']],$target);
                $own=$overrides->forProduct((int)$product['id'],$mid);
                $overrides->saveAttributes((int)$own['id'],$target,$proposed['values'],[],(int)$product['id']);
                $after=$overrides->forProduct((int)$product['id'],$mid);
                $db->query('INSERT INTO catalog_enrichment_events(store_id,marketplace_id,product_id,before_json,after_json,evidence_json,created_at) VALUES(?,?,?,?,?,?,NOW())',[$this->repo->store(),$mid,$product['id'],CatalogAuditRepository::json($mapping),CatalogAuditRepository::json($after),CatalogAuditRepository::json($proof)]);
                $db->commit();
            }catch(\Throwable $e){$db->rollBack();throw $e;}
        }
        return $proof;
    }
}
