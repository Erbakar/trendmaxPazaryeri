<?php
/**
 * OzyConnect Panel — InvoiceProviderInterface
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

namespace App\Services\Invoice;

interface InvoiceProviderInterface
{
    /**
     * Bağlantı testi
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array;

    /**
     * Fatura gönder
     *
     * @param array<string, mixed> $invoiceData
     * @return array{success: bool, uuid: string, message: string}
     */
    public function send(array $invoiceData): array;

    /**
     * Fatura durumu sorgula
     *
     * @return array{status: string, message: string}
     */
    public function checkStatus(string $uuid): array;

    /**
     * Fatura iptal
     */
    public function cancel(string $uuid, string $reason): bool;

    /**
     * PDF indir
     */
    public function downloadPdf(string $uuid): ?string;
}
