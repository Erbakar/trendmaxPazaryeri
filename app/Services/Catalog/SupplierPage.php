<?php
declare(strict_types=1);
namespace App\Services\Catalog;

/** Reads supplier data only; navigation, prices, warranty and supplier categories are not facts. */
final class SupplierPage
{
    public const HOST = 'www.toptanarabul.com';
    public static function allowedUrl(string $url): bool
    {
        $u=parse_url($url);
        return $u&&($u['scheme']??'')==='https'&&in_array($u['host']??'',[self::HOST,'toptanarabul.com'],true)
            &&!isset($u['user'])&&!isset($u['pass'])
            &&(!isset($u['port'])||$u['port']===443)&&!isset($u['query'])&&!isset($u['fragment'])
            &&preg_match('~^/(?:arama|urun)/[^/]+$~D',$u['path']??'')===1;
    }
    private static function xpath(string $html): \DOMXPath
    {
        if(strlen($html)>3*1024*1024)throw new \RuntimeException('Tedarikçi sayfası boyut sınırını aşıyor.');
        $old=libxml_use_internal_errors(true);
        try{$d=new \DOMDocument();$d->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);return new \DOMXPath($d);}
        finally{libxml_clear_errors();libxml_use_internal_errors($old);}
    }
    private static function cls(string $name): string {return 'contains(concat(" ",normalize-space(@class)," ")," '.$name.' ")';}
    private static function text(\DOMNode $node): string
    {
        if($node instanceof \DOMText)return $node->textContent;
        if(in_array(strtolower($node->nodeName),['script','style','noscript'],true))return '';
        $text='';foreach($node->childNodes as$child)$text.=self::text($child);
        return in_array(strtolower($node->nodeName),['br','p','div','li','h1','h2','h3','tr'],true)?$text."\n":$text;
    }
    public static function searchLinks(string $html): array
    {
        $x=self::xpath($html);$out=[];
        foreach($x->query('//a[@href]')as$a){$u=$a->getAttribute('href');if(str_starts_with($u,'/urun/'))$u='https://'.self::HOST.$u;
            if(self::allowedUrl($u)&&str_starts_with(parse_url($u,PHP_URL_PATH),'/urun/'))$out[$u]=true;}
        return array_keys($out);
    }
    public static function product(string $html,string $url): array
    {
        if(!self::allowedUrl($url)||!str_starts_with(parse_url($url,PHP_URL_PATH),'/urun/'))throw new \InvalidArgumentException('Geçersiz tedarikçi ürün adresi.');
        $x=self::xpath($html);$h=$x->query('//h1');$details=$x->query('//*['.self::cls('product-detail').']');
        if($h->length!==1||$details->length!==1)throw new \RuntimeException('Tedarikçi ürün bilgisi tam okunamadı.');
        $fields=[];
        foreach($x->query('//*['.self::cls('product-list-row').']')as$row){
            $label=$x->query('./*['.self::cls('product-list-title').']',$row);$value=$x->query('./*['.self::cls('product-list-content').']',$row);
            if($label->length===1&&$value->length===1){$key=CatalogSourceEvidence::text($label->item(0)->textContent);
                if(isset($fields[$key]))throw new \RuntimeException('Tedarikçi ürün alanı birden fazla değer içeriyor.');$fields[$key]=trim($value->item(0)->textContent);}
        }
        $sku=trim($fields['stok kodu']??'');$name=trim($h->item(0)->textContent);
        if($sku===''||$name==='')throw new \RuntimeException('Tedarikçi ürün kimliği eksik.');
        $extras=[];
        foreach(['renk'=>'Renk','mensei'=>'Menşei','uretim yeri'=>'Üretim Yeri','boyut/ebat'=>'Boyut','urun olculeri'=>'Ürün Ölçüleri','materyal'=>'Materyal']as$key=>$label)
            if(trim($fields[$key]??'')!=='')$extras[$label]=$fields[$key];
        return ['url'=>$url,'sku'=>$sku,'name'=>$name,'description'=>trim(self::text($details->item(0))),'fields'=>$extras];
    }
    public static function supplement(array $product,array $page): ?string
    {
        if($page['sku']!==$product['sku']||CatalogSourceEvidence::text($page['name'])!==CatalogSourceEvidence::text($product['name']))return null;
        $extra=CatalogSourceEvidence::text($page['description'])!==CatalogSourceEvidence::text($product['description'])?$page['description']:'';
        foreach($page['fields']as$label=>$value)if(in_array($label,['Renk','Menşei','Üretim Yeri','Boyut','Ürün Ölçüleri','Materyal'],true)&&is_string($value))$extra.="\n".$label.': '.$value;
        return trim($extra);
    }
}
