<?php
declare(strict_types=1);
namespace App\Services\Catalog;

/** Pure decisions: marketplace acceptance is not a semantic content review. */
final class CatalogAuditPolicy
{
    public static function images(array $product): array
    {
        $images=json_decode((string)($product['images_json']??'[]'),true)?:[];
        $urls=[];
        foreach(array_merge([$product['main_image']??''],$images)as$image){
            $url=is_array($image)?(string)($image['url']??''):(string)$image;
            if($url!=='')$urls[]=self::url($url);
        }
        return array_slice(array_values(array_unique($urls)),0,8);
    }
    public static function url(string $url): string
    {
        return preg_replace('~^http://cdn1\.xmlbankasi\.com/~i','https://cdn1.xmlbankasi.com/',trim($url));
    }
    public static function fingerprint(array $product, ?array $mapping): string
    {
        return hash('sha256',json_encode([
            (string)($product['sku']??''),(string)($product['name']??''),(string)($product['description']??''),
            array_intersect_key($product,array_flip(['brand_id','desi','tax_rate','weight','_attribute_values'])),
            self::images($product),(string)($mapping['marketplace_category_id']??''),
            json_decode((string)($mapping['attribute_mapping_json']??'{}'),true),
        ],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
    public static function sourceErrors(array $product, ?array $source): array
    {
        if($source===null)return ['Ürün doğrulanan XML kaynağında yok.'];
        $errors=[];
        if((int)$product['stock_qty']!==(int)$source['stock_qty'])$errors[]='Panel stoğu güncel XML ile farklı; XML senkronu bekleniyor.';
        foreach(['cost_price','base_price']as$key)if(abs((float)($product[$key]??0)-(float)($source[$key]??0))>0.011)$errors[]='Panel alış/satış tabanı XML ile farklı; fiyat doğrulanamadı.';
        $urls=array_slice(array_values(array_unique(array_map([self::class,'url'],$source['images']??[]))),0,8);
        if(self::images($product)!==$urls)$errors[]='Panel görselleri güncel XML ile farklı.';
        if((string)$product['sku']!==(string)$source['sku'])$errors[]='XML stok kodu eşleşmiyor.';
        return array_values(array_unique($errors));
    }
    public static function remoteRows(array $approved,array $unapproved): array
    {
        $rows=[];
        foreach(['approved'=>$approved,'unapproved'=>$unapproved]as$bucket=>$contents){
            foreach($contents as$c)foreach($c['variants']??[$c]as$v){
                $barcode=(string)($v['barcode']??'');if($barcode==='')throw new \RuntimeException('Katalogda barkodsuz satır var.');
                if(!isset($v['stock']['quantity'])&&!isset($v['quantity']))throw new \RuntimeException('Trendyol stok alanı eksik; sıfır kabul edilmedi.');
                if($bucket==='approved'&&!array_key_exists('onSale',$v))throw new \RuntimeException('Trendyol satış durumu eksik; kapalı varsayılmadı.');
                $rows[]=['barcode'=>$barcode,'sku'=>(string)($v['stockCode']??''),'name'=>(string)($c['title']??$c['name']??''),
                    'categoryId'=>(string)($c['category']['id']??$c['categoryId']??''),'brandId'=>(string)($c['brand']['id']??$c['brandId']??''),
                    'salePrice'=>(float)($v['price']['salePrice']??$v['salePrice']??0),'listPrice'=>(float)($v['price']['listPrice']??$v['listPrice']??0),
                    'quantity'=>(int)($v['stock']['quantity']??$v['quantity']??0),'onSale'=>(bool)($v['onSale']??false),
                    'images'=>array_values(array_map(static fn($x)=>is_array($x)?(string)($x['url']??''):(string)$x,$c['images']??[])),
                    'status'=>$bucket==='approved'?'approved':(string)($c['status']??'pending'),
                    'blocked'=>!empty($v['blacklisted'])||!empty($v['locked'])||!empty($v['archived'])||!empty($v['docNeeded'])||!empty($v['hasViolation']),
                    'reasons'=>$c['rejectReasonDetails']??[]];
            }
        }
        return $rows;
    }
    public static function barcodeAbsent(array $lookup): bool
    {
        $absent=true;
        foreach(['approved','unapproved']as$key){
            $page=$lookup[$key]??null;
            if(!is_array($page)||!isset($page['totalElements'])||!is_array($page['content']??null))throw new \RuntimeException('Barkod kontrol yanıtı eksik; yeni ürün gönderilmedi.');
            if((int)$page['totalElements']!==0||$page['content']!==[])$absent=false;
        }
        return $absent;
    }
    public static function canReplaceRejected(array $lookup,string $barcode,string $sku): bool
    {
        self::barcodeAbsent($lookup); // Also validates both response envelopes.
        if((int)$lookup['approved']['totalElements']!==0||$lookup['approved']['content']!==[])return false;
        $rows=$lookup['unapproved']['content'];
        if((int)$lookup['unapproved']['totalElements']!==1||count($rows)!==1)return false;
        $row=$rows[0];
        return str_contains(strtolower((string)($row['status']??'')),'reject')
            &&(string)($row['barcode']??'')===$barcode&&(string)($row['stockCode']??'')===$sku;
    }
    public static function outcome(array $checks, ?array $remote, bool $available): string
    {
        if(!$available)return $remote&&($remote['quantity']>0||$remote['onSale'])?'close_required':'unavailable';
        if($remote&&str_contains(strtolower($remote['status']),'reject'))return 'rejected';
        if($remote&&$remote['status']!=='approved')return 'pending_approval';
        if(!empty($checks['errors']))return 'needs_review';
        if(!$remote)return 'ready';
        return !empty($checks['differences'])?'sync_required':'verified';
    }
    public static function sameInventory(array $expected,array $remote): bool
    {
        foreach(['quantity','salePrice','listPrice']as$key){
            if(!array_key_exists($key,$expected))continue;
            if(!array_key_exists($key,$remote)||abs((float)$expected[$key]-(float)$remote[$key])>0.011)return false;
        }
        return !isset($expected['quantity'])||(int)$expected['quantity']!==0||empty($remote['onSale']);
    }
}
