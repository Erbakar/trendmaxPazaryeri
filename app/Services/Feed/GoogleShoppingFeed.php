<?php
/**
 * OzyConnect Panel — GoogleShoppingFeed
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

use App\Helpers\StringHelper;

final class GoogleShoppingFeed
{
    /**
     * Google Merchant Center uyumlu XML üret
     *
     * @param array<int, array<string, mixed>> $products
     * @param array<string, string> $settings
     */
    public function generate(array $products, array $settings): string
    {
        $siteName = $settings['site_name'] ?? 'Entegratör Yazılımı';
        $siteUrl  = $settings['site_url'] ?? 'http://localhost';
        $currency = $settings['currency'] ?? 'TRY';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        $xml .= "<channel>\n";
        $xml .= "  <title>" . $this->escape($siteName) . "</title>\n";
        $xml .= "  <link>{$siteUrl}</link>\n";
        $xml .= "  <description>{$siteName} Ürün Feed</description>\n";

        foreach ($products as $product) {
            $xml .= "  <item>\n";
            $xml .= "    <g:id>" . $this->escape($product['sku'] ?? '') . "</g:id>\n";
            $xml .= "    <g:title>" . $this->escape($product['name'] ?? '') . "</g:title>\n";
            $xml .= "    <g:description>" . $this->escape($product['description'] ?? $product['name'] ?? '') . "</g:description>\n";
            $xml .= "    <g:link>{$siteUrl}/product/" . $this->escape($product['sku'] ?? '') . "</g:link>\n";

            if (!empty($product['main_image'])) {
                $xml .= "    <g:image_link>" . $this->escape($product['main_image']) . "</g:image_link>\n";
            }

            $xml .= "    <g:price>" . number_format((float) ($product['base_price'] ?? 0), 2, '.', '') . " {$currency}</g:price>\n";

            if (!empty($product['list_price']) && (float) $product['list_price'] > (float) ($product['base_price'] ?? 0)) {
                $xml .= "    <g:sale_price>" . number_format((float) ($product['base_price'] ?? 0), 2, '.', '') . " {$currency}</g:sale_price>\n";
            }

            $availability = ((int) ($product['stock_qty'] ?? 0)) > 0 ? 'in_stock' : 'out_of_stock';
            $xml .= "    <g:availability>{$availability}</g:availability>\n";

            if (!empty($product['brand_name'])) {
                $xml .= "    <g:brand>" . $this->escape($product['brand_name']) . "</g:brand>\n";
            }

            if (!empty($product['barcode'])) {
                $xml .= "    <g:gtin>" . $this->escape($product['barcode']) . "</g:gtin>\n";
            }

            if (!empty($product['category_path'])) {
                $xml .= "    <g:product_type>" . $this->escape($product['category_path']) . "</g:product_type>\n";
            }

            $xml .= "    <g:condition>new</g:condition>\n";
            $xml .= "  </item>\n";
        }

        $xml .= "</channel>\n</rss>";

        return $xml;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
