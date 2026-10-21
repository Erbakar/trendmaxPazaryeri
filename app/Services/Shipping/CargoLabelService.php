<?php
/**
 * OzyConnect Panel — CargoLabelService
 * Kargo etiketi oluşturma, ZPL→PDF dönüştürme, indirme
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 */
declare(strict_types=1);

namespace App\Services\Shipping;

use App\Core\Logger;

final class CargoLabelService
{
    private readonly string $storagePath;

    public function __construct(
        private readonly Logger $logger,
    ) {
        $this->storagePath = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 2))
            . '/storage/labels';

        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }

    /**
     * ZPL formatındaki etiketi PDF'e çevir
     * Labelary API kullanır (ücretsiz, kurulum gerektirmez)
     */
    public function zplToPdf(string $zplContent, string $filename): ?string
    {
        $url = 'https://api.labelary.com/v1/printers/8dpmm/labels/4x6/0/';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $zplContent,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/pdf',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($response)) {
            $this->logger->error("ZPL→PDF dönüştürme hatası: HTTP {$httpCode}", 'CargoLabel');
            return null;
        }

        $filePath = $this->storagePath . '/' . $filename;
        file_put_contents($filePath, $response);

        $this->logger->info("Kargo etiketi PDF oluşturuldu: {$filename}", 'CargoLabel');
        return $filePath;
    }

    /**
     * ZPL'i PNG'ye çevir (ekranda önizleme için)
     */
    public function zplToPng(string $zplContent): ?string
    {
        $url = 'https://api.labelary.com/v1/printers/8dpmm/labels/4x6/0/';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $zplContent,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Accept: image/png',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_SSL_VERIFYPEER => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($response)) {
            return null;
        }

        return 'data:image/png;base64,' . base64_encode($response);
    }

    /**
     * Sipariş bilgilerinden kendi kargo etiketi oluştur (Seller Pays model)
     * Trendyol API'den etiket alınamadığında kullanılır
     */
    public function generateCustomLabel(array $order): string
    {
        $shipAddr = json_decode($order['shipping_address_json'] ?? '{}', true) ?: [];
        $rawData  = json_decode($order['raw_data_json'] ?? '{}', true) ?: [];

        $name     = $this->toAscii($shipAddr['name'] ?? $order['customer_name'] ?? '');
        $address  = $this->toAscii($shipAddr['address'] ?? '');
        $city     = $this->toAscii($shipAddr['city'] ?? '');
        $district = $this->toAscii($shipAddr['district'] ?? '');
        $phone    = $shipAddr['phone'] ?? $order['customer_phone'] ?? '';
        $barcode  = $order['cargo_tracking_no'] ?? '';
        $orderNo  = $order['marketplace_order_id'] ?? '';
        $cargo    = $this->toAscii($order['cargo_company'] ?? '');

        // ZPL formatında etiket oluştur
        $zpl = "^XA\n";
        $zpl .= "^CI28\n"; // UTF-8 karakter seti
        $zpl .= "^CF0,30\n";
        $zpl .= "^FO50,30^FD{$cargo}^FS\n";
        $zpl .= "^FO50,70^GB700,3,3^FS\n";
        $zpl .= "^CF0,25\n";
        $zpl .= "^FO50,90^FDSiparis No: {$orderNo}^FS\n";
        $zpl .= "^FO50,130^GB700,3,3^FS\n";
        $zpl .= "^CF0,28\n";
        $zpl .= "^FO50,150^FDALICI:^FS\n";
        $zpl .= "^CF0,24\n";
        $zpl .= "^FO50,185^FD{$name}^FS\n";

        // Adresi satırlara böl (max 45 karakter)
        $addrLines = $this->wrapText($address, 45);
        $y = 215;
        foreach ($addrLines as $line) {
            $zpl .= "^FO50,{$y}^FD{$line}^FS\n";
            $y += 28;
        }

        $zpl .= "^FO50,{$y}^FD{$district} / {$city}^FS\n";
        $y += 28;
        if (!empty($phone)) {
            $zpl .= "^FO50,{$y}^FDTel: {$phone}^FS\n";
            $y += 28;
        }
        $y += 10;
        $zpl .= "^FO50,{$y}^GB700,3,3^FS\n";
        $y += 20;

        // Barkod
        if (!empty($barcode)) {
            $zpl .= "^FO100,{$y}^BY3\n";
            $zpl .= "^BCN,120,Y,N,N\n";
            $zpl .= "^FD{$barcode}^FS\n";
            $y += 160;
        }

        $zpl .= "^FO50,{$y}^GB700,3,3^FS\n";
        $y += 15;
        $zpl .= "^CF0,20\n";
        $zpl .= "^FO50,{$y}^FDEntegrator Yazilimi - " . date('d.m.Y H:i') . "^FS\n";
        $zpl .= "^XZ";

        return $zpl;
    }

    /**
     * Türkçe karakterleri ASCII'ye çevir (ZPL uyumluluğu)
     */
    private function toAscii(string $text): string
    {
        $map = [
            'ç' => 'c', 'Ç' => 'C',
            'ğ' => 'g', 'Ğ' => 'G',
            'ı' => 'i', 'İ' => 'I',
            'ö' => 'o', 'Ö' => 'O',
            'ş' => 's', 'Ş' => 'S',
            'ü' => 'u', 'Ü' => 'U',
            'â' => 'a', 'Â' => 'A',
            'î' => 'i', 'Î' => 'I',
            'û' => 'u', 'Û' => 'U',
        ];
        return str_replace(array_keys($map), array_values($map), $text);
    }

    /**
     * Metni belirli uzunlukta satırlara böl
     */
    private function wrapText(string $text, int $maxLen): array
    {
        $words = explode(' ', $text);
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            if (strlen($current . ' ' . $word) > $maxLen && $current !== '') {
                $lines[] = trim($current);
                $current = $word;
            } else {
                $current .= ($current === '' ? '' : ' ') . $word;
            }
        }
        if ($current !== '') {
            $lines[] = trim($current);
        }

        return array_slice($lines, 0, 4); // Max 4 satır
    }

    /**
     * Etiket dosyası var mı kontrol et
     */
    public function labelExists(string $filename): bool
    {
        return file_exists($this->storagePath . '/' . $filename);
    }

    /**
     * Etiket dosya yolunu döndür
     */
    public function getLabelPath(string $filename): string
    {
        return $this->storagePath . '/' . $filename;
    }

    /**
     * HTML etiketini PDF dosyasına kaydet
     * PttAVM kargo etiketleri HTML formatında gelir
     */
    public function htmlToPdf(string $htmlContent, string $filename): ?string
    {
        if (empty($htmlContent)) {
            return null;
        }

        // HTML etiketi doğrudan dosya olarak kaydet (.html uzantılı)
        // Tarayıcıda yazdırılabilir
        $htmlFilename = str_replace('.pdf', '.html', $filename);
        $filePath = $this->storagePath . '/' . $htmlFilename;
        file_put_contents($filePath, $htmlContent);

        $this->logger->info("PttAVM kargo etiketi oluşturuldu: {$htmlFilename}", 'CargoLabel');
        return $filePath;
    }
}
