<?php
declare(strict_types=1);
namespace App\Models;

/** User choices survive XML refreshes and never change observed live listing metadata. */
final class ProductCategoryOverride extends BaseModel
{
    protected string $table = 'product_marketplace_category_overrides';

    public function forProduct(int $productId, int $marketplaceId): array|false
    {
        return $this->findBy(['product_id'=>$productId,'marketplace_id'=>$marketplaceId]);
    }

    public function assign(int $categoryId, int $marketplaceId, array $ids, ?string $target): int
    {
        $ids = array_values(array_unique(array_map('intval',$ids)));
        if (!$ids || count($ids)>500 || min($ids)<1) throw new \InvalidArgumentException('1–500 ürün seçin.');
        $mapping = new CategoryMarketplaceMapping($this->db,$this->tenant);
        $leaf = $target===null ? null : $mapping->getMarketplaceCategoryLeafStatus($marketplaceId,$target);
        if ($target!==null && (!$leaf || !$leaf['sellable'])) throw new \InvalidArgumentException('Satışa açık bir alt kategori seçin.');
        return $this->db->transaction(function() use($categoryId,$marketplaceId,$ids,$target,$leaf) {
            $sid=$this->tenantId();
            $marks=implode(',',array_fill(0,count($ids),'?'));
            $rows=$this->db->fetchAll("SELECT id FROM products WHERE store_id=? AND category_id=? AND id IN ($marks) FOR UPDATE",[$sid,$categoryId,...$ids]);
            if(count($rows)!==count($ids)) throw new \InvalidArgumentException('Seçilen ürünlerden biri bu mağaza ve kategoriye ait değil. Ekranı yenileyin.');
            foreach($ids as $id) {
                if($target===null) {
                    $this->db->query('DELETE FROM product_marketplace_category_overrides WHERE store_id=? AND product_id=? AND marketplace_id=?',[$sid,$id,$marketplaceId]);
                } else {
                    $this->db->query('INSERT INTO product_marketplace_category_overrides (store_id,product_id,marketplace_id,marketplace_category_id,marketplace_category_name,marketplace_category_path) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE attribute_mapping_json=IF(marketplace_category_id=VALUES(marketplace_category_id),attribute_mapping_json,NULL),marketplace_category_id=VALUES(marketplace_category_id),marketplace_category_name=VALUES(marketplace_category_name),marketplace_category_path=VALUES(marketplace_category_path)',[$sid,$id,$marketplaceId,$target,$leaf['name'],$leaf['full_path']]);
                }
            }
            return count($ids);
        });
    }

    public function saveAttributes(int $id, string $expectedTarget, array $values, array $sources, int $productId): void
    {
        $this->db->transaction(function() use($id,$expectedTarget,$values,$sources,$productId) {
            $current=$this->db->fetchOne('SELECT * FROM product_marketplace_category_overrides WHERE id=? AND store_id=? AND product_id=? FOR UPDATE',[$id,$this->tenantId(),$productId]);
            if(!$current || $current['marketplace_category_id']!==$expectedTarget) throw new \InvalidArgumentException('Kategori değişti; ekranı yeniden açın.');
            $json=json_encode(['defaults'=>(object)[],'sources'=>(object)$sources,'products'=>['id:'.$productId=>(object)$values]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $this->db->query('UPDATE product_marketplace_category_overrides SET attribute_mapping_json=? WHERE id=? AND store_id=?',[$json,$id,$this->tenantId()]);
        });
    }

    /**
     * Adds values inferred from the product itself to its own override. Hand-entered values win
     * (see CategoryMarketplaceMapping::mergeInferred); what was written is marked so it can be undone.
     * @param array<string,string> $values attribute id => value
     */
    public function mergeInferredAttributes(int $id, int $productId, array $values): void
    {
        $this->db->transaction(function() use($id,$productId,$values) {
            $current=$this->db->fetchOne('SELECT attribute_mapping_json FROM product_marketplace_category_overrides WHERE id=? AND store_id=? AND product_id=? FOR UPDATE',[$id,$this->tenantId(),$productId]);
            if(!$current) throw new \InvalidArgumentException('Ürüne özel kategori seçimi bulunamadı.');
            $config=json_decode((string)($current['attribute_mapping_json']??''),true);
            if(!is_array($config)||array_is_list($config)) $config=[];
            [$products,$inferred]=CategoryMarketplaceMapping::mergeInferred(is_array($config['products']??null)?$config['products']:[],is_array($config['inferred']??null)?$config['inferred']:[],['id:'.$productId=>$values]);
            $config['products']=$products; $config['inferred']=$inferred;
            $config+=['defaults'=>new \stdClass(),'sources'=>new \stdClass()];
            foreach(['defaults','sources'] as $key) if($config[$key]===[]) $config[$key]=new \stdClass();
            $this->db->query('UPDATE product_marketplace_category_overrides SET attribute_mapping_json=? WHERE id=? AND store_id=?',[json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$id,$this->tenantId()]);
        });
    }

    /** Removes inferred values that were not edited since, in every override of the store. @return int removed values */
    public function clearInferredAttributes(int $marketplaceId): int
    {
        $removed=0;
        $rows=$this->db->fetchAll("SELECT id FROM product_marketplace_category_overrides WHERE store_id=? AND marketplace_id=? AND attribute_mapping_json LIKE '%\"inferred\"%'",[$this->tenantId(),$marketplaceId]);
        foreach($rows as $row) {
            $this->db->transaction(function() use($row,&$removed) {
                $current=$this->db->fetchOne('SELECT attribute_mapping_json FROM product_marketplace_category_overrides WHERE id=? AND store_id=? FOR UPDATE',[(int)$row['id'],$this->tenantId()]);
                $config=json_decode((string)($current['attribute_mapping_json']??''),true);
                if(!is_array($config)||!is_array($config['inferred']??null)) return;
                [$products,$n]=CategoryMarketplaceMapping::withoutInferred(is_array($config['products']??null)?$config['products']:[],$config['inferred']);
                $config['products']=$products===[]?new \stdClass():$products; unset($config['inferred']); $removed+=$n;
                foreach(['defaults','sources'] as $key) if(($config[$key]??[])===[]) $config[$key]=new \stdClass();
                $this->db->query('UPDATE product_marketplace_category_overrides SET attribute_mapping_json=? WHERE id=? AND store_id=?',[json_encode($config,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),(int)$row['id'],$this->tenantId()]);
            });
        }
        return $removed;
    }
}
