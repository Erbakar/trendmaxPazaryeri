<?php
declare(strict_types=1);
namespace App\Services;

use App\Models\{Marketplace,Product};

/** Explicit per-store opt-in: an XML stock alias does not imply price ownership. */
final class XmlLinkedPrices
{
    public function __construct(private readonly XmlStockLinks $links,private readonly Product $products,
        private readonly Marketplace $marketplaces,private readonly PriceRuleEngine $prices) {}

    public function all(int $mid, ?array $barcodes=null): array
    {
        $marketplace=$this->marketplaces->find($mid);
        $settings=json_decode($marketplace['extra_config_json']??'{}',true)?:[];
        if(($marketplace['slug']??'')!=='trendyol'||empty($settings['xml_linked_prices']))return [];
        $result=[];
        $wanted=$barcodes===null?null:array_fill_keys($barcodes,true);
        $rows=$this->links->rows($mid);$counts=array_count_values(array_column($rows,'barcode'));
        foreach($rows as$link){
            if($counts[$link['barcode']]!==1)continue;
            if($wanted!==null&&!isset($wanted[(string)$link['barcode']]))continue;
            if(empty($link['source_product_id'])||empty($link['evidence_json'])||!empty($link['blocked_reason']))continue;
            if((isset($link['legacy_active'])&&!$link['legacy_active'])||!empty($link['legacy_archived'])
                ||(isset($link['listing_active'])&&!$link['listing_active']))continue;
            $product=$this->products->find((int)$link['source_product_id']);
            if(!$product||(int)$product['xml_profile_id']!==(int)$link['xml_profile_id']||$product['sku']!==$link['source_sku']
                ||empty($product['is_active'])||!empty($product['is_archived'])||!empty($product['missing_stock'])
                ||(float)$product['cost_price']<=0)continue;
            $price=$this->prices->calculatePrice($product,$mid);
            if($price['sale_price']<=0)continue;
            $result[(string)$link['barcode']]=['barcode'=>(string)$link['barcode'],'salePrice'=>$price['sale_price'],'listPrice'=>$price['list_price']];
        }
        return $result;
    }

    /** Price-only ownership never adds or modifies quantity, images, category or identity. */
    public function guard(array $items,int $mid): array
    {
        if(!array_filter($items,static fn($item)=>array_key_exists('salePrice',$item)||array_key_exists('listPrice',$item)))return $items;
        $prices=$this->all($mid,array_column($items,'barcode'));
        foreach($items as&$item)if(isset($prices[(string)($item['barcode']??'')])){
            foreach(['salePrice','listPrice']as$key)if(array_key_exists($key,$item))$item[$key]=$prices[(string)$item['barcode']][$key];
        }
        unset($item);return $items;
    }
}
