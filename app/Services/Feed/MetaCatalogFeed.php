<?php
/**
 * OzyConnect Panel — MetaCatalogFeed (Facebook/Instagram)
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

final class MetaCatalogFeed
{
    /**
     * Meta Commerce Manager uyumlu XML üret
     *
     * @param array<int, array<string, mixed>> $products
     * @param array<string, string> $settings
     */
    public function generate(array $products, array $settings): string
    {
        $siteUrl  = $settings['site_url'] ?? 'http://localhost';
        $currency = $settings['currency'] ?? 'TRY';

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        $xml .= "<channel>\n";

        foreach ($products as $product) {
            $xml .= "  <item>\n";
            $xml .= "    <g:id>" . $this->escape($product['sku'] ?? '') . "</g:id>\n";
            $xml .= "    <g:title>" . $this->escape($product['name'] ?? '') . "</g:title>\n";
            $xml .= "    <g:description>" . $this->escape($product['description'] ?? $product['name'] ?? '') . "</g:description>\n";
            $xml .= "    <g:availability>" . (((int) ($product['stock_qty'] ?? 0)) > 0 ? 'in stock' : 'out of stock') . "</g:availability>\n";
            $xml .= "    <g:condition>new</g:condition>\n";
            $xml .= "    <g:price>" . number_format((float) ($product['base_price'] ?? 0), 2, '.', '') . " {$currency}</g:price>\n";
            $xml .= "    <g:link>{$siteUrl}/product/" . $this->escape($product['sku'] ?? '') . "</g:link>\n";

            if (!empty($product['main_image'])) {
                $xml .= "    <g:image_link>" . $this->escape($product['main_image']) . "</g:image_link>\n";
            }

            if (!empty($product['brand_name'])) {
                $xml .= "    <g:brand>" . $this->escape($product['brand_name']) . "</g:brand>\n";
            }

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
