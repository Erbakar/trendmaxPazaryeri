<?php
/**
 * OzyConnect Panel — GenericFeed
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

namespace App\Services\Feed;

final class GenericFeed
{
    /**
     * Genel amaçlı XML ürün feed'i üret
     *
     * @param array<int, array<string, mixed>> $products
     * @param array<string, string> $settings
     */
    public function generate(array $products, array $settings): string
    {
        $siteName = $settings['site_name'] ?? 'Entegratör Yazılımı';
        $currency = $settings['currency'] ?? 'TRY';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<products generator="EntegratorYazilimi/1.0" currency="' . $currency . '" date="' . date('Y-m-d H:i:s') . '">' . "\n";

        foreach ($products as $product) {
            $xml .= "  <product>\n";
            $xml .= "    <sku>" . $this->escape($product['sku'] ?? '') . "</sku>\n";
            $xml .= "    <barcode>" . $this->escape($product['barcode'] ?? '') . "</barcode>\n";
            $xml .= "    <name>" . $this->escape($product['name'] ?? '') . "</name>\n";
            $xml .= "    <category>" . $this->escape($product['category_name'] ?? '') . "</category>\n";
            $xml .= "    <brand>" . $this->escape($product['brand_name'] ?? '') . "</brand>\n";
            $xml .= "    <price>" . number_format((float) ($product['base_price'] ?? 0), 2, '.', '') . "</price>\n";
            $xml .= "    <list_price>" . number_format((float) ($product['list_price'] ?? 0), 2, '.', '') . "</list_price>\n";
            $xml .= "    <stock>" . ((int) ($product['stock_qty'] ?? 0)) . "</stock>\n";
            $xml .= "    <tax_rate>" . ((float) ($product['tax_rate'] ?? 20)) . "</tax_rate>\n";

            if (!empty($product['main_image'])) {
                $xml .= "    <image>" . $this->escape($product['main_image']) . "</image>\n";
            }

            $xml .= "  </product>\n";
        }

        $xml .= "</products>";

        return $xml;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
