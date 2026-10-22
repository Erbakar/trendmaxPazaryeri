<?php
declare(strict_types=1);
namespace App\Services\Marketplace;
use App\Core\TenantContext;

/** Store-scoped cache prevents a request for every product in a bulk publish. */
final class TrendyolAttributeCatalog
{
    private array $memory = [];
    public function __construct(private readonly TrendyolService $trendyol, private readonly TenantContext $tenant) {}
    public function get(string $categoryId, bool $refresh = false): array
    {
        if (!ctype_digit($categoryId)) throw new \InvalidArgumentException('Geçersiz Trendyol kategori kimliği.');
        $key = $this->tenant->currentStoreId() . '_' . $categoryId;
        if (!$refresh && isset($this->memory[$key])) return $this->memory[$key];
        $path = BASE_PATH . '/storage/cache/trendyol_attributes_v2_' . $key . '.json';
        if (!$refresh && is_file($path) && filemtime($path) > time()-21600) {
            $cached = json_decode((string)file_get_contents($path), true);
            if (is_array($cached) && isset($cached['attributes'])) return $this->memory[$key] = $cached;
        }
        $attributes = $this->trendyol->fetchCategoryAttributes($categoryId);
        foreach ($attributes as &$attribute) {
            if (empty($attribute['allowCustom']) && empty($attribute['values'])) {
                $attribute['values'] = $this->trendyol->fetchCategoryAttributeValues($categoryId, (string)$attribute['id']);
            }
        }
        unset($attribute);
        $result = ['attributes'=>$attributes, 'fetched_at'=>date(DATE_ATOM), 'source'=>'trendyol'];
        $tmp = tempnam(dirname($path), 'ty-attributes-');
        if ($tmp !== false) {
            file_put_contents($tmp, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (!rename($tmp, $path)) @unlink($tmp);
        }
        return $this->memory[$key] = $result;
    }
}
