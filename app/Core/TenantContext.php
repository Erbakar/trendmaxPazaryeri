<?php
/**
 * OzyConnect Panel — TenantContext
 *
 * Çok-kiracılı (multi-tenant) mimaride AKTİF MAĞAZANIN TEK DOĞRULUK KAYNAĞI.
 * Tüm tenant-scoped sorgular store_id'yi yalnızca buradan okur (Session'dan veya
 * caller'dan DEĞİL). Web isteğinde Session'dan, CLI/worker/API'de açıkça beslenir.
 *
 * TASARIM KURALLARI:
 *  - ASLA static state YOK. Container'da singleton; her istek/iş için tek örnek.
 *    Database PDO process-wide singleton olduğundan, uzun ömürlü CLI/worker'da
 *    her iş sonunda clear() çağrılmalı (tenant sızıntısını önlemek için).
 *  - FAIL-CLOSED: store kurulmadan currentStoreId() çağrılırsa exception.
 *  - Bypass yok: tenant-scoped modeller her zaman currentStoreId()'den geçer.
 *    Global/paylaşımlı tablolar model seviyesinde $tenantScoped=false ile zaten
 *    bu sınıfı hiç çağırmaz (escape hatch değil — ayrı tablo sınıfı).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Core;

final class TenantContext
{
    private ?int $storeId = null;

    /**
     * Aktif mağazayı belirle (Session bootstrap, CLI --store, API token store'undan).
     */
    public function setStoreId(int $storeId): void
    {
        if ($storeId <= 0) {
            throw new \InvalidArgumentException("Geçersiz store_id: {$storeId}");
        }
        $this->storeId = $storeId;
    }

    /**
     * Aktif mağaza var mı? (Public/login öncesi yollar için kontrol.)
     */
    public function hasStore(): bool
    {
        return $this->storeId !== null;
    }

    /**
     * Aktif mağaza ID'si — kurulmamışsa FAIL-CLOSED exception.
     * Tenant-scoped her sorgu buradan geçer; bu yüzden store yoksa "sessizce
     * scope'suz" çalışmak yerine gürültüyle patlar.
     */
    public function currentStoreId(): int
    {
        if ($this->storeId === null) {
            throw new TenantContextException(
                'Tenant bağlamı kurulmadan tenant-scoped sorgu çalıştırılamaz. '
                . 'Aktif store_id yok (Session/CLI/API bağlamı eksik).'
            );
        }
        return $this->storeId;
    }

    /**
     * Bağlamı temizle. CLI/worker'da HER İŞ SONUNDA çağrılmalı (singleton PDO +
     * uzun ömürlü süreçte tenant sızıntısını önler).
     */
    public function clear(): void
    {
        $this->storeId = null;
    }
}
