<?php
declare(strict_types=1);
namespace App\Services\Marketplace;
use App\Models\CategoryMarketplaceMapping;

final class TrendyolAttributeResolver
{
    public static function normalize(string $text): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtr(mb_strtolower(trim($text)), ['ı'=>'i','ö'=>'o','ü'=>'u','ş'=>'s','ç'=>'c','ğ'=>'g','i̇'=>'i']));
    }
    public static function value(array $attribute, string $raw, bool $local = false): array
    {
        $raw = trim($raw);
        if ($raw === '') return [];
        $item = ['attributeId'=>(int)$attribute['id']];
        if (!empty($attribute['allowCustom'])) {
            if (mb_strlen($raw)>1000) throw new \InvalidArgumentException('Değer en fazla 1000 karakter olabilir.');
            return $item + ['customAttributeValue'=>$raw];
        }
        $multiple = !empty($attribute['allowMultipleAttributeValues']);
        $decoded = $multiple ? json_decode($raw, true) : null;
        $requested = is_array($decoded) && array_is_list($decoded) ? $decoded : [$raw];
        if (!$multiple && count($requested)>1) throw new \InvalidArgumentException('Bu özellik tek değer kabul ediyor.');
        if ($requested === []) throw new \InvalidArgumentException('En az bir seçenek seçin.');
        $ids = [];
        foreach ($requested as $value) {
            if (!is_scalar($value)) throw new \InvalidArgumentException('Geçersiz seçenek.');
            $found = null;
            foreach ($attribute['values'] ?? [] as $option) {
                if ((!$local && (string)$option['id'] === (string)$value)
                    || ($local && self::normalize((string)$option['name']) === self::normalize((string)$value))) {
                    $found = (int)$option['id']; break;
                }
            }
            if ($found === null) throw new \InvalidArgumentException('Trendyol’un geçerli seçeneklerinden birini seçin.');
            $ids[] = $found;
        }
        $ids = array_values(array_unique($ids));
        return $item + ($multiple ? ['attributeValueIds'=>$ids] : ['attributeValueId'=>$ids[0]]);
    }
    public static function resolve(array $product, array $mapping, array $attributes, array $localAttributes): array
    {
        $config = CategoryMarketplaceMapping::decodeAttributeMapping($mapping);
        $own = CategoryMarketplaceMapping::productAttributeValues($mapping, 'id:'.(int)$product['id']);
        $defaults = CategoryMarketplaceMapping::attributeDefaults($mapping);
        $sources = (array)($config['sources'] ?? []);
        $payload = []; $errors = []; $details = [];
        foreach ($attributes as $a) {
            $id = (string)$a['id'];
            $raw = ''; $source = ''; $local = false;
            if (isset($own[$id])) { $raw=$own[$id]; $source='Ürün değeri'; }
            elseif (isset($sources[$id]) && $sources[$id] !== '') {
                foreach ($localAttributes as $p) if ((string)$p['attribute_id']===(string)$sources[$id]) {
                    $raw = trim((string)(($p['custom_value'] ?? '') !== '' ? $p['custom_value'] : ($p['attribute_value_text'] ?? '')));
                    $source = (string)$p['attribute_name']; $local=true; break;
                }
                if ($raw === '' && isset($defaults[$id])) { $raw=$defaults[$id]; $source='Kategori varsayılanı'; }
            } elseif (isset($defaults[$id])) { $raw=$defaults[$id]; $source='Kategori varsayılanı'; }
            else foreach ($localAttributes as $p) {
                if (self::normalize((string)$p['attribute_name'])===self::normalize((string)$a['name'])) {
                    $raw = trim((string)(($p['custom_value'] ?? '') !== '' ? $p['custom_value'] : ($p['attribute_value_text'] ?? '')));
                    $source=(string)$p['attribute_name']; $local=true; break;
                }
            }
            try {
                $item = self::value($a, $raw, $local);
                if ($item !== []) $payload[]=$item;
                elseif (!empty($a['required'])) $errors[]='Zorunlu Trendyol özelliği eksik: '.$a['name'].'. Kategori Eşleştirme > Özellikler bölümünden tamamlayın.';
                $details[]=['id'=>$id,'name'=>$a['name'],'value'=>$raw,'source'=>$source,'payload'=>$item];
            } catch (\InvalidArgumentException $e) { $errors[]=$a['name'].': '.$e->getMessage(); }
        }
        return ['attributes'=>$payload,'details'=>$details,'errors'=>$errors];
    }
}
