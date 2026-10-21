<?php
/**
 * OzyConnect Panel — Auth Middleware
 *
 * Oturum/IP doğrulaması + mağaza BELGE DOĞRULAMA KAPISI: zorunlu belgeleri onaylanmamış
 * (verified_at NULL) mağazalar yalnız belgeler/ayarlar/çıkış sayfalarına erişebilir;
 * diğer tüm panel /documents'e yönlendirilir. (Fail-open: DB hatasında kapı uygulanmaz.)
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.1.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\TenantContext;

final class AuthMiddleware
{
    /** Belge kapısı açıkken bile erişilebilen yollar (kullanıcı belgeyi tamamlayabilsin). */
    private const VERIFICATION_ALLOWLIST = ['/documents', '/settings', '/logout', '/password', '/profile'];

    public function __construct(
        private readonly Session $session,
        private readonly Response $response,
        private readonly TenantContext $tenant,
        private readonly Database $db,
    ) {}

    public function handle(Request $request): void
    {
        if (!$this->session->isAuthenticated()) {
            if ($request->isAjax()) {
                $this->response->error('Oturum süresi dolmuş, lütfen giriş yapın', 401);
            }

            $this->response->redirectTo('/login');
        }

        // Session hijacking koruması — IP değişmişse oturumu sonlandır
        if (!$this->session->validateSession()) {
            if ($request->isAjax()) {
                $this->response->error('Güvenlik ihlali: Oturum sonlandırıldı', 403);
            }

            $this->response->redirectTo('/login');
        }

        $this->enforceStoreVerification($request);
    }

    /**
     * Belge doğrulama kapısı: zorunlu+aktif belge türü varsa ve mağaza henüz
     * doğrulanmadıysa (verified_at NULL), allowlist dışındaki tüm istekleri /documents'e
     * yönlendirir. Sistemde zorunlu belge türü yoksa kapı yoktur (doğrulanmış sayılır).
     */
    private function enforceStoreVerification(Request $request): void
    {
        $storeId = $this->tenant->currentStoreId();
        if ($storeId <= 0) {
            return; // mağaza bağlamı yok (örn. admin/genel) → kapı uygulanmaz
        }

        // Allowlist: belgeler/ayarlar/çıkış/şifre — kapı açıkken de erişilebilir.
        $path = $request->getPath();
        foreach (self::VERIFICATION_ALLOWLIST as $allow) {
            if ($path === $allow || str_starts_with($path, $allow . '/')) {
                return;
            }
        }

        try {
            // Hiç zorunlu+aktif belge türü yoksa kapı yok.
            $req = (int) ($this->db->fetchOne(
                "SELECT COUNT(*) AS c FROM store_document_types WHERE is_required = 1 AND is_active = 1"
            )['c'] ?? 0);
            if ($req === 0) {
                return;
            }

            $store = $this->db->fetchOne("SELECT verified_at FROM stores WHERE id = :id", ['id' => $storeId]);
            if ($store === false || !empty($store['verified_at'])) {
                return; // mağaza bulunamadı (dokunma) ya da doğrulanmış → serbest
            }
        } catch (\Throwable) {
            return; // fail-open: doğrulama sorgusu patlarsa paneli kilitleme
        }

        // Doğrulanmamış → kısıtla
        if ($request->isAjax()) {
            $this->response->error('Mağazanız doğrulanana kadar bu işlem kullanılamaz. Lütfen gerekli belgeleri yükleyin.', 403);
        }
        $this->session->flash('error', 'Mağazanız doğrulanana kadar panel kullanımı kısıtlıdır. Lütfen gerekli belgeleri yükleyip onay bekleyin.');
        $this->response->redirectTo('/documents');
    }
}
