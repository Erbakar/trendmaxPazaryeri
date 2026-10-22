<?php
declare(strict_types=1);
namespace App\Services\Import;

/** Shared suggestions for the analyzer, manual editor and importer. */
final class XmlProfileMapping
{
    public const ATTRIBUTE_FIELDS = [
        'attribute_color'=>'Renk', 'attribute_web_color'=>'Web Color',
        'attribute_origin'=>'Menşei', 'attribute_size'=>'Beden',
        'attribute_material'=>'Materyal', 'attribute_gender'=>'Cinsiyet',
        'attribute_age_group'=>'Yaş Grubu',
    ];

    public const CANDIDATES = [
        'sku'=>['StokKodu','stok_kodu','sku','Product_code','productCode','urunKodu','kod','code'],
        'name'=>['UrunAdi','urun_adi','Name','baslik','title','ad'],
        'description'=>['Aciklama','description','detay','icerik'],
        'barcode'=>['Barkod','barcode','gtin','ean'],
        'price'=>['SatisFiyati_TL','satis_fiyati','fiyat','Price','satisFiyati'],
        'list_price'=>['ListeFiyati_TL','liste_fiyati','list_price','piyasaFiyati'],
        'cost'=>['AlisFiyati_TL','alis_fiyati','cost','maliyet','alisFiyati'],
        'stock'=>['StokAdedi','stok','Stock','miktar','qty','adet','quantity'],
        'tax'=>['KdvOrani','kdv','Tax','vat'], 'desi'=>['Desi'], 'weight'=>['Agirlik','weight'],
        'brand'=>['Marka','Brand','vendor'],
        'category'=>['KategoriTree','mainCategory','anakategori','kategori','category','kategori_yolu'],
        'category2'=>['category','altkategori','kategori2'],
        'category3'=>['subCategory','altkategori2','kategori3'],
        'category4'=>['kategorialt','kategori4'], 'category5'=>['kategorialt2','kategori5'],
        'image'=>['Resim','image','img','gorsel','Image1','Resim_1','resim1'],
        'currency'=>['CurrencyType','currency','parabirimi','doviz'],
        'attribute_color'=>['Renk','Color','Colour','UrunRengi'],
        'attribute_web_color'=>['WebColor','Web_Color','web_color','Renk','Color','Colour'],
        'attribute_origin'=>['Mensei','Menşei','Origin','CountryOfOrigin','UretimYeri'],
        'attribute_size'=>['Beden','Size','UrunBedeni'],
        'attribute_material'=>['Materyal','Material'],
        'attribute_gender'=>['Cinsiyet','Gender'],
        'attribute_age_group'=>['YasGrubu','YaşGrubu','AgeGroup','Age_Group'],
    ];

    public static function suggest(array $tags, string $itemTag): array
    {
        $map = ['item_tag'=>$itemTag, 'category_sep'=>'|'];
        foreach (self::CANDIDATES as $field=>$candidates) {
            foreach ($candidates as $candidate) {
                foreach ($tags as $tag) {
                    if (strcasecmp($tag, $candidate) === 0) {
                        $map[$field] = $tag;
                        break 2;
                    }
                }
            }
        }
        if (($map['category'] ?? '') === ($map['category2'] ?? null)) unset($map['category2']);
        if (preg_match('/^(.*?)[_]?1$/', $map['image'] ?? '', $match)) {
            $map['image_prefix'] = rtrim($match[1], '_');
        }
        return $map;
    }

    public static function validate(array $map): void
    {
        foreach (['item_tag'=>'Ürün düğümü', 'sku'=>'SKU / Stok Kodu', 'name'=>'Ürün Adı'] as $field=>$label) {
            if (!is_string($map[$field] ?? null) || trim($map[$field]) === '') {
                throw new \InvalidArgumentException($label . ' eşlemesi zorunludur. XML’i analiz edip listeden seçin.');
            }
        }
        foreach ($map as $key=>$value) {
            if (str_starts_with((string) $key, '_')) continue;
            if (!is_string($value)) throw new \InvalidArgumentException('XML eşlemeleri metin olmalıdır.');
        }
    }
}
