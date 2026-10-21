<?php
/**
 * OzyConnect Panel — GİB e-Arşiv Portal Provider
 * GİB portalına direkt bağlanarak e-Arşiv fatura keser
 * Özel entegratör gerektirmez
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 */
declare(strict_types=1);

namespace App\Services\Invoice;

use App\Core\Logger;
use App\Core\TenantContext;
use App\Models\StoreSetting;

final class GibEarsivProvider implements InvoiceProviderInterface
{
    private const PROD_URL = 'https://earsivportal.efatura.gov.tr';
    private const TEST_URL = 'https://earsivportaltest.efatura.gov.tr';

    private const PATH_ESIGN    = '/earsiv-services/esign';
    private const PATH_LOGIN    = '/earsiv-services/assos-login';
    private const PATH_DISPATCH = '/earsiv-services/dispatch';
    private const PATH_DOWNLOAD = '/earsiv-services/download';

    private string $token = '';
    private string $lastError = '';
    private string $cookieFile;
    private bool $testMode = true;
    private string $username = '';
    private string $password = '';
    private ?int $credStoreId = null;
    private bool $explicitCreds = false;

    public function __construct(
        private readonly StoreSetting $storeSetting,
        private readonly TenantContext $tenant,
        private readonly Logger $logger,
    ) {
        $this->cookieFile = sys_get_temp_dir() . '/OzyConnect_gib_' . md5(__FILE__) . '.txt';
    }

    /**
     * GİB kimlik bilgilerini AKTİF mağazadan (store_settings) lazy yükler.
     * Provider Container'da tekildir; mağaza değişince (ör. cron tek process'te mağaza
     * döngüsü) token sıfırlanır → yeni mağaza için temiz login. Çapraz-kiracı koruması.
     * Tenant yoksa TenantContext exception fırlatır (fail-closed: yanlış cred yerine sesli hata).
     */
    private function resolveCreds(): void
    {
        if ($this->explicitCreds) { return; } // Platform/açık-cred modu: store_settings'ten OKUMA (admin tarafı).
        $sid = $this->tenant->currentStoreId();
        if ($sid === $this->credStoreId) {
            return;
        }
        $this->credStoreId = $sid;
        $this->testMode = (bool) $this->storeSetting->getValue('gib_test_mode', '1');
        $this->username = (string) $this->storeSetting->getValue('gib_username', '');
        $this->password = (string) $this->storeSetting->getValue('gib_password', '');
        $this->token = '';
    }

    public function __destruct()
    {
        if (file_exists($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function getLastError(): string { return $this->lastError; }
    public function isLoggedIn(): bool { return !empty($this->token); }
    private function baseUrl(): string { return $this->testMode ? self::TEST_URL : self::PROD_URL; }

    /**
     * Platform/açık kimlik bilgileriyle çalıştır — per-store resolveCreds'i ATLAR (TenantContext gerekmez).
     * Admin tarafı SaaS faturalama / bağlantı testi içindir; merchant akışı bunu çağırmaz (davranış değişmez).
     */
    public function useExplicitCredentials(string $username, string $password, bool $testMode): void
    {
        $this->username     = $username;
        $this->password     = $password;
        $this->testMode     = $testMode;
        $this->explicitCreds = true;
        $this->token        = '';
    }

    // ═══════════════════════════════════════════════════════
    // InvoiceProviderInterface
    // ═══════════════════════════════════════════════════════

    public function testConnection(): array
    {
        if (!$this->login()) {
            return ['success' => false, 'message' => 'GİB giriş hatası: ' . $this->lastError];
        }
        $userData = $this->dispatch('EARSIV_PORTAL_KULLANICI_BILGILERI_GETIR', 'RG_KULLANICI');
        $this->logout();
        if ($userData === false) {
            // Login token alınsa bile kullanıcı bilgisi dönmüyorsa bağlantı kullanılamaz demektir
            return ['success' => false, 'message' => 'GİB kullanıcı bilgisi alınamadı: ' . $this->lastError];
        }
        $suffix = $this->testMode ? ' (TEST portalı)' : '';
        return ['success' => true, 'message' => 'GİB bağlantısı başarılı' . $suffix, 'data' => $userData];
    }

    public function send(array $invoiceData): array
    {
        // Windows'ta max_execution_time duvar saatini sayar — uzun cURL zinciri fatal timeout üretmesin
        set_time_limit(300);

        $this->resolveCreds();
        if (!$this->isLoggedIn() && !$this->login()) {
            return ['success' => false, 'uuid' => '', 'signed' => false, 'message' => 'GİB giriş hatası: ' . $this->lastError];
        }

        // GİB yeni taslakta UUID kabul ETMEZ ("Ettn ya eksik ya boş..." hatası verir);
        // UUID'yi portal üretir, oluşturma sonrası taslak listesinden çözümlenir.
        unset($invoiceData['faturaUuid'], $invoiceData['ettn'], $invoiceData['uuid']);

        // Oluşturma öncesi bugünün taslak ETTN'lerini al — yeni satır diff ile bulunacak.
        // Liste ALINAMAZSA devam etme: snapshot'sız diff yanlış taslağı imzalayabilir.
        $beforeRows = $this->listDraftRows();
        if ($beforeRows === null) {
            $msg = 'GİB taslak listesi alınamadı — fatura kesilmedi, tekrar deneyin' . ($this->lastError !== '' ? ' (' . $this->lastError . ')' : '');
            $this->logout();
            return ['success' => false, 'uuid' => '', 'signed' => false, 'message' => $msg];
        }
        $beforeEttns = array_map(
            static fn(array $r): string => strtolower((string) ($r['ettn'] ?? '')),
            $beforeRows
        );

        // Taslak oluştur
        $result = $this->dispatch('EARSIV_PORTAL_FATURA_OLUSTUR', 'RG_BASITFATURA', $invoiceData);
        if ($result === false) {
            $msg = 'Taslak oluşturma hatası: ' . $this->lastError;
            $this->logout();
            return ['success' => false, 'uuid' => '', 'signed' => false, 'message' => $msg];
        }

        // Başarıda GİB "... başarıyla oluşturuldu" metni döner; aksi hâlde data
        // içindeki metin GERÇEK hata mesajıdır (ör. "String index out of range: 4").
        $raw = is_string($result) ? $result : (json_encode($result, JSON_UNESCAPED_UNICODE) ?: '');
        if (mb_stripos($raw, 'başarıyla') === false) {
            $this->logger->warning('GİB taslak oluşturma reddedildi: ' . mb_substr($raw, 0, 300), 'GibEarsiv');
            $this->logout();
            return [
                'success' => false,
                'uuid'    => '',
                'signed'  => false,
                'message' => 'GİB taslağı oluşturulamadı. GİB yanıtı: ' . mb_substr($raw, 0, 300),
            ];
        }

        // Yeni taslağı listeden çöz: önce diff, gerekirse kısa bekleyip tekrar dene
        $row = $this->resolveNewDraftRow($beforeEttns, $invoiceData);
        if ($row === null) {
            $this->logger->warning(
                'Taslak oluşturuldu görünüyor ama listede bulunamadı. GİB yanıtı: ' . mb_substr($raw, 0, 300),
                'GibEarsiv'
            );
            $this->logout();
            return [
                'success' => false,
                'uuid'    => '',
                'signed'  => false,
                'message' => 'GİB taslağı doğrulanamadı (listede görünmüyor). GİB yanıtı: ' . mb_substr($raw, 0, 300),
            ];
        }

        $uuid = strtolower((string) ($row['ettn'] ?? ''));
        $signed = $this->signRow($row);

        $this->logger->info(
            "e-Arşiv fatura GİB'e gönderildi: {$uuid} — imza: " . ($signed ? 'başarılı' : 'BAŞARISIZ'),
            'GibEarsiv'
        );
        $this->logout();

        return [
            'success' => true,
            'uuid'    => $uuid,
            'belgeNo' => (string) ($row['belgeNumarasi'] ?? ''),
            'signed'  => $signed,
            'message' => $signed
                ? 'Fatura GİB\'e gönderildi ve imzalandı'
                : 'Taslak oluşturuldu. Fatura detayındaki "SMS ile Onayla" butonuyla imzalayabilirsiniz.',
        ];
    }

    /**
     * Taslak durumdaki bir faturayı GİB'de imzalar (imza retry senaryosu).
     *
     * @return array{success: bool, signed: bool, message: string}
     */
    public function signDraft(string $uuid): array
    {
        set_time_limit(300);

        $this->resolveCreds();
        if (!$this->isLoggedIn() && !$this->login()) {
            return ['success' => false, 'signed' => false, 'message' => 'GİB giriş hatası: ' . $this->lastError];
        }

        $row = $this->findDraftRow($uuid);
        if ($row === null) {
            $this->logout();
            return ['success' => false, 'signed' => false, 'message' => 'Taslak bulunamadı: ' . $uuid];
        }

        $signed = $this->signRow($row);
        $this->logout();

        return [
            'success' => true,
            'signed'  => $signed,
            'message' => $signed
                ? 'Taslak imzalandı'
                : 'Taslak imzalanamadı. Fatura detayındaki "SMS ile Onayla" butonuyla imzalayabilirsiniz.'
                    . ($this->lastError !== '' ? ' (' . $this->lastError . ')' : ''),
        ];
    }

    /**
     * TASLAKLARI_GETIR ile bugünün taslakları içinden ettn === uuid satırını bulur.
     * Login yapılmış olmalı, logout çağırmaz. İmzalama komutu bu satır objesini bekler.
     *
     * @return array<string, mixed>|null
     */
    private function findDraftRow(string $uuid): ?array
    {
        foreach ($this->listDraftRows() ?? [] as $row) {
            if (strcasecmp((string) ($row['ettn'] ?? ''), $uuid) === 0) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Bugünün taslak satırlarını döndürür. Login yapılmış olmalı, logout çağırmaz.
     * HATA ile BOŞ LİSTE ayrıdır: sorgu başarısızsa null döner — çağıran boş
     * listeymiş gibi davranıp yanlış taslak eşleştirmemelidir.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function listDraftRows(): ?array
    {
        $today = date('d/m/Y');
        $drafts = $this->dispatch('EARSIV_PORTAL_TASLAKLARI_GETIR', 'RG_BASITTASLAKLAR', [
            'baslangic' => $today,
            'bitis'     => $today,
            'hangiTip'  => '5000/30000',
            'table'     => [],
        ]);
        if ($drafts === false || !is_array($drafts)) {
            return null;
        }
        // dispatch() 'data' alanını açar; bazı sürümlerde liste bir kat daha içeride olabilir
        if (isset($drafts['data']) && is_array($drafts['data'])) {
            $drafts = $drafts['data'];
        }
        return array_values(array_filter($drafts, 'is_array'));
    }

    /**
     * Oluşturma sonrası YENİ taslak satırını bulur: önce oluşturma öncesi ETTN
     * kümesinde olmayan satırlar aranır; birden fazlaysa alıcı VKN/TCKN ile
     * daraltılır. Liste gecikmesine karşı bir kez kısa bekleyip tekrar denenir.
     *
     * @param array<int, string>   $beforeEttns oluşturma öncesi ETTN'ler (küçük harf)
     * @param array<string, mixed> $invoiceData gönderilen fatura verisi
     * @return array<string, mixed>|null
     */
    private function resolveNewDraftRow(array $beforeEttns, array $invoiceData): ?array
    {
        $vknTckn = (string) ($invoiceData['vknTckn'] ?? '');

        for ($try = 0; $try < 2; $try++) {
            if ($try > 0) {
                sleep(2);
            }
            $rows = $this->listDraftRows();
            if ($rows === null) {
                continue; // sorgu hatası — boş liste sanıp yanlış eşleştirme yapma
            }
            $fresh = [];
            foreach ($rows as $row) {
                $ettn = strtolower((string) ($row['ettn'] ?? ''));
                if ($ettn !== '' && !in_array($ettn, $beforeEttns, true)) {
                    $fresh[] = $row;
                }
            }
            if (count($fresh) === 1) {
                return $fresh[0];
            }
            if (count($fresh) > 1) {
                // Aynı anda birden fazla taslak oluşmuşsa alıcıya göre daralt
                foreach (array_reverse($fresh) as $row) {
                    if ($vknTckn !== '' && (string) ($row['aliciVknTckn'] ?? '') === $vknTckn) {
                        return $row;
                    }
                }
                return end($fresh) ?: null;
            }
        }
        return null;
    }

    /**
     * Taslak satırını HSM komutuyla imzalar. Login yapılmış olmalı, logout çağırmaz.
     * GİB 'imzalanacaklar' anahtarında TASLAKLARI_GETIR çıktısındaki satır objelerini bekler.
     *
     * @param array<string, mixed> $row
     */
    private function signRow(array $row): bool
    {
        $signResult = $this->dispatch('EARSIV_PORTAL_FATURA_HSM_CIHAZI_ILE_IMZALA', 'RG_BASITTASLAKLAR', [
            'imzalanacaklar' => [$row],
        ]);
        $this->logger->info('İmzalama sonucu: ' . json_encode($signResult, JSON_UNESCAPED_UNICODE), 'GibEarsiv');

        if ($signResult === false) {
            return false;
        }
        // Yanıt içinde başarı ifadesi yoksa fatura İMZASIZ demektir
        if (is_array($signResult) && isset($signResult['sonuc'])) {
            return in_array($signResult['sonuc'], ['1', 1, true], true);
        }
        $text = is_string($signResult)
            ? $signResult
            : (string) (json_encode($signResult, JSON_UNESCAPED_UNICODE) ?: '');
        return mb_stripos($text, 'başar') !== false || mb_stripos($text, 'imzalan') !== false;
    }

    // ═══════════════════════════════════════════════════════
    // SMS İLE ONAY (İMZA) — HSM'siz hesaplarda taslak SMS koduyla imzalanır
    // ═══════════════════════════════════════════════════════

    /**
     * GİB'de kayıtlı cep telefonu numarasını sorgular. Login yapılmış olmalı.
     */
    public function getPhoneNumber(): ?string
    {
        $res = $this->dispatch('EARSIV_PORTAL_TELEFONNO_SORGULA', 'RG_BASITTASLAKLAR');
        if (is_array($res) && !empty($res['telefon'])) {
            return (string) $res['telefon'];
        }
        return null;
    }

    /**
     * SMS onay sürecini başlatır: GİB'de kayıtlı numaraya kod gönderilir.
     * Dönen token+oid, verifySmsSign çağrısına (sonraki HTTP isteği) taşınmalıdır —
     * doğrulama AYNI GİB oturumunda yapılmak zorundadır.
     *
     * @return array{success: bool, message: string, oid?: string, phone?: string, token?: string}
     */
    public function startSmsSign(): array
    {
        set_time_limit(120);
        $this->resolveCreds();
        if (!$this->isLoggedIn() && !$this->login()) {
            return ['success' => false, 'message' => 'GİB giriş hatası: ' . $this->lastError];
        }

        $phone = $this->getPhoneNumber();
        if ($phone === null || $phone === '') {
            $this->logout();
            return ['success' => false, 'message' => 'GİB\'de kayıtlı telefon numarası alınamadı' . ($this->lastError !== '' ? ' (' . $this->lastError . ')' : '')];
        }

        $res = $this->dispatch('EARSIV_PORTAL_SMSSIFRE_GONDER', 'RG_SMSONAY', [
            'CEPTEL'  => $phone,
            'KCEPTEL' => false,
            'TIP'     => '',
        ]);
        $oid = is_array($res) ? (string) ($res['oid'] ?? '') : '';
        if ($oid === '') {
            $this->logout();
            $msg = is_string($res) ? $res : $this->lastError;
            return ['success' => false, 'message' => 'SMS kodu gönderilemedi' . ($msg !== '' ? ': ' . mb_substr($msg, 0, 200) : '')];
        }

        // Token bilinçli olarak CANLI bırakılır (logout YOK) ve çağırana aktarılır;
        // kod doğrulaması bu oturumla yapılacak.
        $token = $this->token;
        $this->token = '';

        $masked = mb_strlen($phone) >= 4
            ? mb_substr($phone, 0, 3) . '*****' . mb_substr($phone, -2)
            : $phone;

        return [
            'success' => true,
            'oid'     => $oid,
            'phone'   => $masked,
            'token'   => $token,
            'message' => "SMS onay kodu {$masked} numarasına gönderildi. Gelen kodu girin.",
        ];
    }

    /**
     * SMS kodunu doğrular ve taslak faturayı imzalar.
     * $token, startSmsSign'ın döndürdüğü oturum anahtarıdır.
     *
     * @return array{success: bool, message: string}
     */
    public function verifySmsSign(string $oid, string $code, string $uuid, string $token): array
    {
        set_time_limit(120);
        $this->resolveCreds();
        $this->token = $token;

        $res = $this->dispatch('0lhozfib5410mp', 'RG_SMSONAY', [
            'DATA'  => [['belgeTuru' => 'FATURA', 'ettn' => $uuid]],
            'SIFRE' => $code,
            'OID'   => $oid,
            'OPR'   => 1,
        ]);
        $this->logger->info('SMS imza sonucu: ' . json_encode($res, JSON_UNESCAPED_UNICODE), 'GibEarsiv');

        if (is_array($res) && in_array($res['sonuc'] ?? null, ['1', 1, true], true)) {
            $this->logout();
            return ['success' => true, 'message' => 'Fatura SMS onayı ile imzalandı'];
        }

        // Hatada logout ETME — GİB oturumu ölürse OID geçersizleşir ve kullanıcı
        // yanlış kod girişinde aynı SMS koduyla tekrar deneyemez.
        $this->token = '';
        $msg = is_string($res) ? $res : ($this->lastError !== '' ? $this->lastError : 'Kod doğrulanamadı');
        return ['success' => false, 'message' => 'SMS doğrulama başarısız: ' . mb_substr($msg, 0, 200) . ' — kodu kontrol edip tekrar deneyin'];
    }

    public function checkStatus(string $uuid): array
    {
        $this->resolveCreds();
        if (!$this->isLoggedIn() && !$this->login()) {
            return ['status' => 'error', 'message' => $this->lastError];
        }
        $doc = $this->dispatch('EARSIV_PORTAL_FATURA_GETIR', 'RG_TASLAKLAR', ['ettn' => $uuid]);
        $this->logout();

        if ($doc === false) {
            return ['status' => 'not_found', 'message' => 'Fatura bulunamadı'];
        }
        return ['status' => 'success', 'message' => 'Fatura mevcut', 'data' => $doc];
    }

    public function cancel(string $uuid, string $reason): bool
    {
        $this->resolveCreds();
        if (!$this->isLoggedIn() && !$this->login()) { return false; }
        $result = $this->dispatch('EARSIV_PORTAL_FATURA_SIL', 'RG_TASLAKLAR', [
            'silinecekler' => [['belgeTuru' => 'FATURA', 'ettn' => $uuid]],
            'aciklama'     => $reason,
        ]);
        $this->logout();
        return $result !== false;
    }

    public function downloadPdf(string $uuid): ?string
    {
        $this->resolveCreds();
        if (!$this->isLoggedIn() && !$this->login()) { return null; }
        $html = $this->dispatch('EARSIV_PORTAL_FATURA_GOSTER', 'RG_TASLAKLAR', [
            'ettn' => $uuid, 'onayDurumu' => 'Onaylandı',
        ]);
        $this->logout();
        return is_string($html) ? $html : null;
    }

    /**
     * Fatura HTML'ini al (PDF oluşturmak veya göstermek için)
     */
    public function getInvoiceHtml(string $uuid): ?string
    {
        $this->lastError = '';
        $this->resolveCreds();
        if (!$this->isLoggedIn() && !$this->login()) { return null; }
        $html = $this->fetchInvoiceHtmlInternal($uuid);
        $this->logout();
        return $html;
    }

    /**
     * Dahili: Fatura HTML'ini birden fazla yöntemle çekmeyi dener.
     * Login yapılmış olmalı, logout çağırmaz.
     */
    private function fetchInvoiceHtmlInternal(string $uuid): ?string
    {
        $attemptErrors = [];
        $onayDurumlari = ['Onaylandı', 'Onaylanmadı', ''];
        // pageName alternatifleri — GİB versiyonuna göre farklılık gösterebilir
        $pageNames = ['RG_BASITFATURA', 'RG_BASITTASLAKLAR', 'RG_TASLAKLAR'];

        // ─── Yöntem 1: Download endpoint (POST ile) ───
        foreach ($onayDurumlari as $durumu) {
            $result = $this->curlPost($this->baseUrl() . self::PATH_DOWNLOAD, [
                'token'       => $this->token,
                'ettn'        => $uuid,
                'belgeTip'    => 'FATURA',
                'onayDurumu'  => $durumu,
                'cmd'         => 'downloadResource',
            ]);

            // curlPost JSON parse edebilir — string HTML bekliyoruz
            if ($this->isValidHtml($result)) {
                $this->logger->info("HTML alındı — download POST (durumu={$durumu})", 'GibEarsiv');
                return $result;
            }
            $this->rememberFetchError($attemptErrors, $result, 'GIB download servisi fatura HTML belgesini vermedi.');
        }

        // ─── Yöntem 2: Download endpoint (GET ile — eski yöntem) ───
        foreach ($onayDurumlari as $durumu) {
            $downloadUrl = $this->baseUrl() . self::PATH_DOWNLOAD
                . '?token=' . urlencode($this->token)
                . '&ettn=' . urlencode($uuid)
                . '&belgeTip=FATURA'
                . '&onayDurumu=' . urlencode($durumu)
                . '&cmd=downloadResource';

            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $downloadUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 30,
                // GİB kimlik bilgileri taşınıyor — TLS doğrulaması kapatılamaz (MITM riski).
                // CA paketi PHP'nin curl.cainfo/openssl.cafile ayarından ya da sistemden gelir.
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_COOKIEJAR      => $this->cookieFile,
                CURLOPT_COOKIEFILE     => $this->cookieFile,
                CURLOPT_HTTPHEADER     => [
                    'Accept: text/html, application/xhtml+xml, */*',
                    'Referer: ' . $this->baseUrl() . '/intragibi.html',
                ],
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
            ]);
            $downloadResult = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($this->isValidHtml($downloadResult)) {
                $this->logger->info("HTML alındı — download GET (durumu={$durumu}, boyut=" . strlen($downloadResult) . ")", 'GibEarsiv');
                return $downloadResult;
            }
            $this->rememberFetchError($attemptErrors, $downloadResult, 'GIB download servisi fatura HTML belgesini vermedi.');

            $len = strlen($downloadResult ?? '');
            if ($len > 0 && $len <= 500) {
                $this->logger->info("Download kısa yanıt (durumu={$durumu}): " . substr($downloadResult, 0, 200), 'GibEarsiv');
            }
        }

        // ─── Yöntem 3: Dispatch FATURA_GOSTER (birden fazla pageName ile) ───
        foreach ($pageNames as $pageName) {
            foreach ($onayDurumlari as $durumu) {
                $rawResult = $this->curlPost($this->baseUrl() . self::PATH_DISPATCH, [
                    'callid'   => $this->uuid(),
                    'token'    => $this->token,
                    'cmd'      => 'EARSIV_PORTAL_FATURA_GOSTER',
                    'pageName' => $pageName,
                    'jp'       => json_encode(['ettn' => $uuid, 'onayDurumu' => $durumu]),
                ]);

                if ($this->isValidHtml($rawResult)) {
                    $this->logger->info("HTML alındı — dispatch (pageName={$pageName}, durumu={$durumu})", 'GibEarsiv');
                    return $rawResult;
                }
                if (is_array($rawResult) && !empty($rawResult['data']) && $this->isValidHtml($rawResult['data'])) {
                    $this->logger->info("HTML alındı — dispatch data (pageName={$pageName}, durumu={$durumu})", 'GibEarsiv');
                    return $rawResult['data'];
                }
                $this->rememberFetchError($attemptErrors, $rawResult, 'GIB dispatch servisi fatura HTML belgesini vermedi.');
            }
        }

        // ─── Yöntem 4: eSign endpoint ile dene ───
        $esignUrl = $this->baseUrl() . self::PATH_ESIGN;
        $esignResult = $this->curlPost($esignUrl, [
            'cmd'      => 'downloadResource',
            'token'    => $this->token,
            'ettn'     => $uuid,
            'belgeTip' => 'FATURA',
        ]);
        if ($this->isValidHtml($esignResult)) {
            $this->logger->info("HTML alındı — eSign endpoint", 'GibEarsiv');
            return $esignResult;
        }

        $this->logger->warning("fetchInvoiceHtmlInternal: Tüm yöntemler başarısız — UUID: {$uuid}", 'GibEarsiv');
        return null;
    }

    /**
     * Yanıtın geçerli HTML olup olmadığını kontrol et
     */
    private function isValidHtml(mixed $content): bool
    {
        if (empty($content) || !is_string($content)) return false;
        if (strlen($content) <= 500) return false;
        return stripos($content, '<html') !== false
            || stripos($content, '<!DOCTYPE') !== false
            || stripos($content, '<table') !== false;
    }

    // ═══════════════════════════════════════════════════════
    // AUTH
    // ═══════════════════════════════════════════════════════

    /**
     * @deprecated Settings sayfasından gib_username/gib_password girin. Bu metod artık kullanılmıyor.
     */
    public function setTestCredentials(): bool
    {
        // KRİTİK: Bu metod devre dışı bırakıldı.
        // Settings'teki gib_username ve gib_password değerleri ASLA otomatik değiştirilmez.
        // Test hesabı bilgilerini Settings > Fatura sekmesinden girin.
        $this->logger->warning(
            'setTestCredentials() çağrıldı ancak devre dışı — Settings\'teki credentials kullanılacak',
            'GibEarsiv'
        );
        return !empty($this->username) && !empty($this->password);
    }

    public function login(): bool
    {
        $this->resolveCreds();
        if (empty($this->username) || empty($this->password)) {
            $this->lastError = 'GİB kullanıcı adı veya şifre boş. Lütfen Settings > Fatura sekmesinden gib_username ve gib_password bilgilerini girin.';
            return false;
        }

        // Önce eski oturumu kapatmayı dene
        $this->curlPost($this->baseUrl() . self::PATH_LOGIN, [
            'assoscmd' => 'logout', 'token' => 'x',
        ]);

        // Yeni cookie ile login
        $this->cookieFile = sys_get_temp_dir() . '/OzyConnect_gib_' . time() . '_' . mt_rand(1000,9999) . '.txt';

        // Mevcut kullanıcı ile dene
        $response = $this->curlPost($this->baseUrl() . self::PATH_LOGIN, [
            'assoscmd' => $this->testMode ? 'login' : 'anologin',
            'userid'   => $this->username,
            'sifre'    => $this->password,
            'sifre2'   => $this->password,
            'parola'   => $this->password,
        ]);

        if ($response && is_array($response) && isset($response['token'])) {
            $this->token = $response['token'];
            $this->logger->info("GİB giriş başarılı — Kullanıcı: {$this->username}", 'GibEarsiv');
            return true;
        }

        if ($this->testMode) {
            $this->lastError = 'GİB test hesabı ile giriş yapılamadı. Lütfen Settings > Fatura sekmesindeki kullanıcı bilgilerini kontrol edin. (Kullanıcı: ' . $this->username . ')';
        } else {
            $this->lastError = 'GİB hesabı ile giriş yapılamadı. Lütfen kullanıcı bilgilerini kontrol edin. (Kullanıcı: ' . $this->username . ')';
        }
        return false;
    }

    public function logout(): void
    {
        if (!$this->token) return;
        $this->curlPost($this->baseUrl() . self::PATH_LOGIN, [
            'assoscmd' => 'logout', 'token' => $this->token,
        ]);
        $this->token = '';
    }

    // ═══════════════════════════════════════════════════════
    // SİPARİŞTEN FATURA VERİSİ OLUŞTURMA
    // ═══════════════════════════════════════════════════════

    /**
     * OzyConnect sipariş verisinden GİB fatura formatına dönüştür
     *
     * @param string $faturaTipi 'SATIS' veya 'IADE'
     */
    /**
     * @param array{faturaNo: string, duzenlenmeTarihi: string}|null $iadeRef
     *        İade faturasında orijinal SATIŞ faturasının GİB belge no + tarihi (d/m/Y)
     */
    public function buildFromOrder(array $order, array $items, array $seller, string $faturaTipi = 'SATIS', ?array $iadeRef = null): array
    {
        $tarih = date('d/m/Y');
        $saat  = date('H:i:s');

        // Müşteri bilgileri
        $shipAddr = json_decode($order['shipping_address_json'] ?? '{}', true) ?: [];
        $billAddr = json_decode($order['billing_address_json'] ?? '{}', true) ?: [];
        $rawData  = json_decode($order['raw_data_json'] ?? '{}', true) ?: [];

        $vknTckn = trim((string) ($order['customer_id_no'] ?? $rawData['identityNumber'] ?? ''));
        // GİB 10 haneli VKN veya 11 haneli TCKN dışındaki değerlerde
        // "String index out of range" benzeri ham Java hataları üretir —
        // maskeli/kısa/boş değerlerde nihai tüketici TCKN'sine düş.
        if (!preg_match('/^\d{10}\d?$/', $vknTckn)) {
            $vknTckn = '11111111111'; // Bireysel varsayılan TCKN (nihai tüketici)
        }
        $musteriAdi    = '';
        $musteriSoyadi = '';
        $musteriUnvan  = '';

        $nameParts = explode(' ', trim($order['customer_name'] ?? ''));
        if (count($nameParts) >= 2) {
            $musteriSoyadi = array_pop($nameParts);
            $musteriAdi    = implode(' ', $nameParts);
        } else {
            $musteriAdi = $order['customer_name'] ?? '';
        }
        // GİB aliciSoyadi boş kabul etmez — tek kelimelik müşteri adında '.' gönder
        if (trim($musteriSoyadi) === '') {
            $musteriSoyadi = '.';
        }

        // Şehir: sipariş adresinden; boşsa satıcı ilinden (settings), o da boşsa 'Belirtilmemiş'
        $sehir = trim((string) ($shipAddr['city'] ?? $billAddr['city'] ?? ''));
        if ($sehir === '') {
            $sehir = trim((string) ($seller['seller_city'] ?? ''));
        }
        if ($sehir === '') {
            $sehir = 'Belirtilmemiş';
        }

        // Vergi dairesi: bireysel (11 haneli TCKN) müşteride boş gönderilir; kurumsalda (VKN) doldurulur
        $vergiDairesi = strlen($vknTckn) === 11 ? '' : trim((string) ($order['customer_tax_office'] ?? ''));

        // Fatura kalemleri
        $malHizmetTable = [];
        $araToplam = 0;
        $toplamKdv = 0;

        foreach ($items as $item) {
            $qty       = (int) ($item['qty'] ?? 1);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $discount  = (float) ($item['discount'] ?? 0);
            $taxRate   = (int) ($item['tax_rate'] ?? 20);
            $lineTotal = (float) ($item['line_total'] ?? ($qty * $unitPrice - $discount));

            // KDV dahil fiyattan KDV'yi ayır
            $kdvDahilTutar = $lineTotal;
            $kdvHaricTutar = round($kdvDahilTutar / (1 + $taxRate / 100), 2);
            $kdvTutari     = round($kdvDahilTutar - $kdvHaricTutar, 2);

            $malHizmetTable[] = [
                'malHizmet'        => $item['name'] ?? '',
                'miktar'           => (float) $qty,
                'birim'            => 'C62',
                'birimFiyat'       => round($kdvHaricTutar / max($qty, 1), 2),
                'fiyat'            => $kdvHaricTutar,
                'iskontoArttm'     => 'İskonto',
                'iskontoOrani'     => 0,
                'iskontoTutari'    => 0.0,
                'iskontoNedeni'    => '',
                'malHizmetTutari'  => $kdvHaricTutar,
                'kdvOrani'         => $taxRate,
                'kdvTutari'        => $kdvTutari,
                'tevkifatKodu'     => 0,
                'ozelMatrahNedeni' => 0,
                'ozelMatrahTutari' => 0.0,
                'gtip'             => '',
            ];

            $araToplam += $kdvHaricTutar;
            $toplamKdv += $kdvTutari;
        }

        $genelToplam = $araToplam + $toplamKdv;

        // GİB güncel şeması (mlevent/fatura ile birebir): UUID GÖNDERİLMEZ (send()
        // içinde ayıklanır), 'tip' ve üst-düzey ozelMatrah* alanları yoktur.
        // İade faturasında kalemler yine malHizmetTable'da kalır; iadeTable
        // orijinal SATIŞ faturasının {faturaNo, duzenlenmeTarihi} referansını taşır.
        return [
            'vknTckn'                  => $vknTckn,
            'hangiTip'                 => '5000/30000',
            'belgeNumarasi'            => '',
            'faturaTarihi'             => $tarih,
            'saat'                     => $saat,
            'paraBirimi'               => 'TRY',
            'dovzTLkur'                => 0.0,
            'faturaTipi'               => $faturaTipi,
            'siparisNumarasi'          => $order['marketplace_order_id'] ?? '',
            'siparisTarihi'            => date('d/m/Y', strtotime($order['ordered_at'] ?? 'now')),
            'irsaliyeNumarasi'         => '',
            'irsaliyeTarihi'           => '',
            'fisNo'                    => '',
            'fisTarihi'                => '',
            'fisSaati'                 => '',
            'fisTipi'                  => '',
            'zRaporNo'                 => '',
            'okcSeriNo'                => '',
            'aliciUnvan'               => $musteriUnvan,
            'aliciAdi'                 => $musteriAdi,
            'aliciSoyadi'              => $musteriSoyadi,
            'bulvarcaddesokak'         => $shipAddr['address'] ?? $billAddr['address'] ?? '',
            'binaAdi'                  => '',
            'binaNo'                   => '',
            'kapiNo'                   => '',
            'kasabaKoy'                => '',
            'mahalleSemtIlce'          => $shipAddr['district'] ?? $billAddr['district'] ?? '',
            'sehir'                    => $sehir,
            'ulke'                     => 'Türkiye',
            'postaKodu'                => '',
            'tel'                      => $order['customer_phone'] ?? '',
            'fax'                      => '',
            'eposta'                   => $order['customer_email'] ?? '',
            'websitesi'                => '',
            'vergiDairesi'             => $vergiDairesi,
            'iadeTable'                => ($faturaTipi === 'IADE' && $iadeRef !== null) ? [$iadeRef] : [],
            'malHizmetTable'           => $malHizmetTable,
            'not'                      => ($faturaTipi === 'IADE' ? 'İade Faturası — ' : '') . 'Sipariş No: ' . ($order['marketplace_order_id'] ?? ''),
            'matrah'                   => $araToplam,
            'malhizmetToplamTutari'    => $araToplam,
            'toplamIskonto'            => 0.0,
            'hesaplanankdv'            => $toplamKdv,
            'vergilerToplami'          => $toplamKdv,
            'vergilerDahilToplamTutar' => $genelToplam,
            'toplamMasraflar'          => 0.0,
            'odenecekTutar'            => $genelToplam,
        ];
    }

    // ═══════════════════════════════════════════════════════
    // ALTYAPI
    // ═══════════════════════════════════════════════════════

    private function dispatch(string $cmd, string $pageName, array $jpData = []): mixed
    {
        if (!$this->token) { $this->lastError = 'Oturum açılmamış'; return false; }
        $result = $this->curlPost($this->baseUrl() . self::PATH_DISPATCH, [
            'callid'   => $this->uuid(),
            'token'    => $this->token,
            'cmd'      => $cmd,
            'pageName' => $pageName,
            // DİKKAT: jp bilinçli olarak \uXXXX escape'li gönderilir (flag YOK).
            // Ham UTF-8 (JSON_UNESCAPED_UNICODE) gönderilirse GİB bazı alanlarda
            // (ör. aliciSoyadi içindeki 'ı/ğ') "ARJUNA016053: Could not commit
            // transaction" hatasıyla taslağı reddediyor.
            'jp'       => json_encode(empty($jpData) ? new \stdClass() : $jpData),
        ]);
        if ($result === false) return false;
        // HTML yanıt (fatura gösterme) — string olarak döner
        if (is_string($result)) return $result;
        // JSON yanıt — data alanını döndür
        if (is_array($result) && isset($result['data'])) return $result['data'];
        return $result;
    }

    private function curlPost(string $url, array $params): mixed
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 45,
            // GİB kimlik bilgileri taşınıyor — TLS doğrulaması kapatılamaz (MITM riski).
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION     => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_COOKIEJAR      => $this->cookieFile,
            CURLOPT_COOKIEFILE     => $this->cookieFile,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json, text/plain, */*',
                'Referer: ' . $this->baseUrl() . '/login.jsp',
            ],
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
            CURLOPT_POSTFIELDS     => http_build_query($params),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) { $this->lastError = "cURL: {$error}"; return false; }
        if ($httpCode < 200 || $httpCode >= 400) { $this->lastError = "HTTP {$httpCode}"; return false; }

        $decoded = json_decode((string) $response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // JSON değil — HTML olabilir (fatura gösterme)
            return (string) $response;
        }

        // GİB hata kontrolü — error alanı "1"/1 ise HATA demektir (dispatch başarısızlıkta
        // {"error":"1","messages":[{"text":"..."}]} döner; mlevent/fatura ve furkankadioglu/efatura
        // kütüphaneleri de error=1'i hata sayar). Sadece boş/"0" başarıdır.
        if (isset($decoded['error']) && !in_array($decoded['error'], ['', null, '0', 0], true)) {
            // messages varsa gerçek GİB mesajını kullan, yoksa hata kodunu göster
            $msgs = [];
            if (!empty($decoded['messages']) && is_array($decoded['messages'])) {
                $msgs = array_filter(array_map(
                    fn($m) => trim(is_array($m) ? (string) ($m['text'] ?? '') : (string) $m),
                    $decoded['messages']
                ));
            }
            $errMsg = is_string($decoded['error']) ? $decoded['error'] : (json_encode($decoded['error']) ?: '');
            $this->lastError = !empty($msgs) ? implode(' | ', $msgs) : ('GİB hata kodu: ' . $errMsg);
            return false;
        }
        return $decoded;
    }

    /**
     * @param array<int, string> $attemptErrors
     */
    private function rememberFetchError(array &$attemptErrors, mixed $response, string $fallback): void
    {
        $message = $this->extractResponseErrorMessage($response);
        if ($message === null && !empty($attemptErrors)) {
            return;
        }

        $message ??= $fallback;
        $message = trim($message);

        if ($message === '') {
            return;
        }

        $attemptErrors[] = $message;
        $attemptErrors = array_values(array_unique($attemptErrors));
        $this->lastError = implode(' | ', $attemptErrors);
    }

    private function extractResponseErrorMessage(mixed $response): ?string
    {
        if (is_array($response)) {
            $parts = [];

            if (isset($response['message']) && is_string($response['message'])) {
                $message = trim($response['message']);
                if ($message !== '' && !$this->shouldIgnoreFetchErrorText($message)) {
                    $parts[] = $message;
                }
            }

            if (!empty($response['messages']) && is_array($response['messages'])) {
                foreach ($response['messages'] as $message) {
                    if (is_array($message) && isset($message['text']) && is_string($message['text'])) {
                        $text = trim($message['text']);
                        if ($text !== '' && !$this->shouldIgnoreFetchErrorText($text)) {
                            $parts[] = $text;
                        }
                    } elseif (is_string($message)) {
                        $text = trim($message);
                        if ($text !== '' && !$this->shouldIgnoreFetchErrorText($text)) {
                            $parts[] = $text;
                        }
                    }
                }
            }

            if (empty($parts) && isset($response['error']) && !is_array($response['error'])) {
                $errorText = trim((string) $response['error']);
                if ($errorText !== '' && !in_array($errorText, ['0', '1'], true) && !$this->shouldIgnoreFetchErrorText($errorText)) {
                    $parts[] = $errorText;
                }
            }

            return empty($parts) ? null : implode(' | ', array_values(array_unique($parts)));
        }

        if (is_string($response)) {
            $trimmed = trim($response);
            if ($trimmed === '') {
                return null;
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->extractResponseErrorMessage($decoded);
            }

            if (strlen($trimmed) <= 300) {
                return $trimmed;
            }
        }

        return null;
    }

    private function shouldIgnoreFetchErrorText(string $text): bool
    {
        $normalized = strtolower(trim($text));
        return $normalized === ''
            || str_contains($normalized, 'string index out of range');
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
