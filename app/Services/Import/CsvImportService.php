<?php
/**
 * OzyConnect Panel — CsvImportService
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Services\Import;

use App\Core\Logger;
use App\Helpers\StringHelper;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;

final class CsvImportService
{
    /** @var array<string, string> Gerekli sütun eşleştirmeleri */
    private const REQUIRED_COLUMNS = ['sku', 'name', 'base_price'];

    /** @var array<string, string> Opsiyonel sütunlar */
    private const OPTIONAL_COLUMNS = [
        'barcode', 'brand', 'category', 'description', 'list_price',
        'cost_price', 'stock_qty', 'tax_rate', 'weight', 'is_active',
        'parent_sku',
    ];

    public function __construct(
        private readonly Product $productModel,
        private readonly Brand $brandModel,
        private readonly Category $categoryModel,
        private readonly Logger $logger,
    ) {}

    /**
     * CSV dosyasını içe aktar
     */
    public function import(string $filePath, string $delimiter = ';'): ImportResult
    {
        $result = new ImportResult();

        if (!file_exists($filePath)) {
            $result->addError(0, "Dosya bulunamadı: {$filePath}");
            return $result;
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            $result->addError(0, "Dosya açılamadı: {$filePath}");
            return $result;
        }

        // İlk satır: başlıklar
        $headers = fgetcsv($handle, 0, $delimiter);
        if ($headers === false || empty($headers)) {
            fclose($handle);
            $result->addError(0, 'CSV başlık satırı okunamadı');
            return $result;
        }

        $headers = array_map('trim', array_map('strtolower', $headers));

        // Zorunlu sütun kontrolü
        foreach (self::REQUIRED_COLUMNS as $col) {
            if (!in_array($col, $headers, true)) {
                fclose($handle);
                $result->addError(0, "Zorunlu sütun eksik: {$col}");
                return $result;
            }
        }

        $row = 1;
        while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
            $row++;
            $result->total++;

            try {
                $mapped = array_combine($headers, $data);
                if ($mapped === false) {
                    $result->addError($row, 'Sütun sayısı uyuşmuyor');
                    continue;
                }

                $this->processRow($mapped, $result, $row);
            } catch (\Throwable $e) {
                $result->addError($row, $e->getMessage());
            }
        }

        fclose($handle);

        $this->logger->info(
            "CSV import tamamlandı: {$result->total} satır, {$result->created} oluşturuldu, {$result->updated} güncellendi, {$result->failed} hatalı",
            'CsvImport'
        );

        return $result;
    }

    /**
     * Tek satırı işle
     *
     * @param array<string, string> $data
     */
    private function processRow(array $data, ImportResult $result, int $row): void
    {
        $sku = trim($data['sku'] ?? '');
        $name = trim($data['name'] ?? '');
        
        // Input validation ve sanitization
        if ($sku === '' || strlen($sku) > 100) {
            $result->addError($row, "SKU geçersiz veya çok uzun (max 100 karakter)");
            return;
        }
        
        if ($name === '' || strlen($name) > 500) {
            $result->addError($row, "Ürün adı geçersiz veya çok uzun (max 500 karakter)");
            return;
        }
        
        // Numeric field validation
        $basePrice = filter_var($data['base_price'] ?? 0, FILTER_VALIDATE_FLOAT);
        if ($basePrice === false || $basePrice < 0) {
            $result->addError($row, "Geçersiz fiyat değeri: {$data['base_price']}");
            return;
        }
        
        $listPrice = filter_var($data['list_price'] ?? $basePrice, FILTER_VALIDATE_FLOAT);
        if ($listPrice === false || $listPrice < 0) {
            $listPrice = $basePrice;
        }
        
        $costPrice = filter_var($data['cost_price'] ?? 0, FILTER_VALIDATE_FLOAT);
        if ($costPrice === false || $costPrice < 0) {
            $costPrice = 0;
        }
        
        $stockQty = filter_var($data['stock_qty'] ?? 0, FILTER_VALIDATE_INT);
        if ($stockQty === false || $stockQty < 0) {
            $stockQty = 0;
        }
        
        $taxRate = filter_var($data['tax_rate'] ?? 20, FILTER_VALIDATE_FLOAT);
        if ($taxRate === false || $taxRate < 0 || $taxRate > 100) {
            $taxRate = 20;
        }
        
        $weight = filter_var($data['weight'] ?? 0, FILTER_VALIDATE_FLOAT);
        if ($weight === false || $weight < 0) {
            $weight = 0;
        }

        // Marka
        $brandId = null;
        if (!empty($data['brand'])) {
            $brandName = trim($data['brand']);
            if (strlen($brandName) <= 200) {
                $brandId = $this->brandModel->findOrCreate($brandName);
            }
        }

        $productData = [
            'name'       => $name,
            'barcode'    => substr(trim($data['barcode'] ?? ''), 0, 100),
            'base_price' => $basePrice,
            'list_price' => $listPrice,
            'cost_price' => $costPrice,
            'stock_qty'  => $stockQty,
            'tax_rate'   => $taxRate,
            'weight'     => $weight,
            'brand_id'   => $brandId,
            'is_active'  => (int) ($data['is_active'] ?? 1),
        ];

        $existingProduct = $this->productModel->findBySku($sku);

        if ($existingProduct !== false) {
            $this->productModel->update((int) $existingProduct['id'], $productData);
            $productId = (int) $existingProduct['id'];
            $result->updated++;
        } else {
            $productData['sku'] = $sku;
            $productId = $this->productModel->create($productData);
            $result->created++;
        }

        // Varyant ilişkisi: parent_sku varsa bağla
        $parentSku = trim($data['parent_sku'] ?? $data['parent sku'] ?? '');
        if ($parentSku !== '' && $parentSku !== $sku) {
            $parentProduct = $this->productModel->findBySku($parentSku);
            if ($parentProduct !== false) {
                $parentId = (int) $parentProduct['id'];
                $this->productModel->update($productId, ['parent_id' => $parentId]);
                $this->productModel->update($parentId, ['has_variants' => 1]);
            }
        }
    }

    /**
     * CSV şablon indir (kullanıcı için referans)
     */
    public function getTemplateCsv(): string
    {
        $headers = array_merge(self::REQUIRED_COLUMNS, self::OPTIONAL_COLUMNS);
        return implode(';', $headers) . "\n" .
               'SKU001;Örnek Ürün;99.90;8690000000001;Marka Adı;Kategori;Açıklama;129.90;50.00;100;20;0.5;1;' . "\n" .
               'SKU001-M;Örnek Ürün M Beden;99.90;8690000000002;Marka Adı;Kategori;Açıklama;129.90;50.00;50;20;0.5;1;SKU001' . "\n";
    }
}
