<?php
/**
 * OzyConnect Panel — MarketplaceInterface
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

namespace App\Services\Marketplace;

interface MarketplaceInterface
{
    /**
     * Bağlantı testi
     *
     * @return array{success: bool, message: string}
     */
    public function testConnection(): array;

    // ─── Sipariş İşlemleri ─────────────────────────────

    /**
     * Siparişleri çek
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function fetchOrders(array $filters = []): array;

    /**
     * Sipariş durumunu güncelle
     *
     * @param array<string, mixed> $extra
     */
    public function updateOrderStatus(string $orderId, string $status, array $extra = []): bool;

    /**
     * Kargo bilgisini güncelle
     */
    public function updateCargoInfo(string $orderId, string $trackingNo, string $cargoCompany): bool;

    // ─── Ürün İşlemleri (Panel → Pazaryeri) ────────────

    /**
     * Ürünleri getir (pazaryerinden)
     *
     * @return array<string, mixed>
     */
    public function fetchProducts(int $page = 0, int $size = 100): array;

    /**
     * Stok toplu güncelle
     *
     * @param array<int, array{barcode: string, quantity: int}> $items
     * @return array{success: int, failed: int, errors: array}
     */
    public function updateStock(array $items): array;

    /**
     * Fiyat toplu güncelle
     *
     * @param array<int, array{barcode: string, quantity: int, salePrice: float, listPrice: float}> $items
     * @return array{success: int, failed: int, errors: array}
     */
    public function updatePrice(array $items): array;

    // ─── Ürün İçe Aktarma (Pazaryeri → Panel) ──────────

    /**
     * Pazaryerindeki ürünleri import için çek
     *
     * @return array{products: array, totalPages: int, totalElements: int}
     */
    public function importProducts(int $page = 0, int $size = 100): array;

    /**
     * Kategori ağacını çek
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchCategories(int $parentId = 0): array;

    /**
     * Marka listesini çek
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchBrands(string $search = ''): array;

    /**
     * Kategori özelliklerini çek
     *
     * @return array<int, array{id: int, name: string, required: bool, allowCustom: bool, values: array}>
     */
    public function fetchCategoryAttributes(string $marketplaceCategoryId): array;

    // ─── İade İşlemleri ────────────────────────────────

    /**
     * İade taleplerini çek
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function fetchReturns(array $filters = []): array;

    /**
     * İade onayla
     */
    public function approveReturn(string $returnId): bool;

    /**
     * İade reddet
     */
    public function rejectReturn(string $returnId, string $reason): bool;
}
