<?php
declare(strict_types=1);
namespace App\Services\Marketplace;
use App\Models\CategoryMarketplaceMapping;

final class TrendyolAttributeResolver
{
    /** Shared wording: the issue report and support API classify resolver output by these fields, not by parsing text. */
    public const MISSING_PREFIX = 'Zorunlu Trendyol özelliği eksik: ';
    public const VALUE_MAP_LIMIT = 500;

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

    /**
     * Product/XML value → Trendyol option id table saved per category: [attributeId => [normalized value => option id]].
     * Only option-list attributes use it; free-text attributes send the XML value as is.
     * @return array<string,array<string,string>>
     */
    public static function valueMap(array $config): array
    {
        $out = [];
        foreach ((array)($config['value_map'] ?? []) as $aid => $pairs) {
            if (!is_array($pairs)) continue;
            foreach ($pairs as $key => $option) {
                $key = self::normalize((string)$key);
                if ($key !== '' && is_scalar($option) && ctype_digit((string)$option)) $out[(string)$aid][$key] = (string)$option;
            }
        }
        return $out;
    }

    /** Raw local value of one product attribute, exactly as the resolver reads it. */
    public static function localValue(array $row): string
    {
        return trim((string)((($row['custom_value'] ?? '') !== '') ? $row['custom_value'] : ($row['attribute_value_text'] ?? '')));
    }

    public static function resolve(array $product, array $mapping, array $attributes, array $localAttributes): array
    {
        $config = CategoryMarketplaceMapping::decodeAttributeMapping($mapping);
        if (array_is_list($config)) $config = [];
        $own = CategoryMarketplaceMapping::productAttributeValues($mapping, 'id:'.(int)$product['id']);
        $defaults = CategoryMarketplaceMapping::attributeDefaults($mapping);
        $sources = (array)($config['sources'] ?? []);
        $valueMap = self::valueMap($config);
        $payload = []; $errors = []; $details = []; $missing = []; $unmatched = []; $warnings = [];
        foreach ($attributes as $a) {
            $id = (string)$a['id'];
            $raw = ''; $source = ''; $local = false; $explicit = false; $localAttributeId = null;
            if (isset($own[$id])) { $raw=$own[$id]; $source='Ürün değeri'; }
            elseif (isset($sources[$id]) && $sources[$id] !== '') {
                $explicit = true;
                foreach ($localAttributes as $p) if ((string)$p['attribute_id']===(string)$sources[$id]) {
                    $raw = self::localValue($p); $source = (string)$p['attribute_name']; $local=true; $localAttributeId=(int)$p['attribute_id']; break;
                }
                if ($raw === '' && isset($defaults[$id])) { $raw=$defaults[$id]; $source='Kategori varsayılanı'; $local=false; }
            } elseif (isset($defaults[$id])) { $raw=$defaults[$id]; $source='Kategori varsayılanı'; }
            else foreach ($localAttributes as $p) {
                if (self::normalize((string)$p['attribute_name'])===self::normalize((string)$a['name'])) {
                    $raw = self::localValue($p); $source=(string)$p['attribute_name']; $local=true; $localAttributeId=(int)$p['attribute_id']; break;
                }
            }
            if ($local && $raw !== '' && empty($a['allowCustom']) && isset($valueMap[$id][self::normalize($raw)])) {
                // An explicit user choice for this XML value; the Trendyol option id is re-validated below.
                $raw = $valueMap[$id][self::normalize($raw)]; $local = false; $source .= ' (değer eşleştirmesi)';
            }
            try {
                $item = self::value($a, $raw, $local);
                if ($item !== []) $payload[]=$item;
                elseif (!empty($a['required'])) {
                    $errors[]=self::MISSING_PREFIX.$a['name'].'. Kategori Eşleştirme > Özellikler bölümünden tamamlayın.';
                    $missing[]=['id'=>$id,'name'=>(string)$a['name']];
                }
                $details[]=['id'=>$id,'name'=>$a['name'],'value'=>$raw,'source'=>$source,'payload'=>$item];
            } catch (\InvalidArgumentException $e) {
                if ($local && !$explicit && empty($a['required'])) {
                    // A same-name optional field is only a guess; an unknown value is left out instead of blocking the product.
                    $warnings[]=$a['name'].': "'.$raw.'" Trendyol seçeneklerinde yok; isteğe bağlı olduğu için gönderilmedi.';
                    $details[]=['id'=>$id,'name'=>$a['name'],'value'=>$raw,'source'=>$source,'payload'=>[],'skipped'=>true];
                    continue;
                }
                $errors[]=$a['name'].': '.$e->getMessage().($local?' (Ürün değeri: "'.$raw.'". Özellikler ekranındaki “XML değerlerini eşleştir” bölümünden Trendyol seçeneğini seçin.)':'');
                if ($local) $unmatched[]=['id'=>$id,'name'=>(string)$a['name'],'value'=>$raw,'local_attribute_id'=>$localAttributeId,'required'=>!empty($a['required'])];
                elseif (!empty($a['required'])) $missing[]=['id'=>$id,'name'=>(string)$a['name'],'invalid'=>true];
            }
        }
        return ['attributes'=>$payload,'details'=>$details,'errors'=>$errors,'missing'=>$missing,'unmatched'=>$unmatched,'warnings'=>$warnings];
    }
}
