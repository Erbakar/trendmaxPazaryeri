<?php
/**
 * OzyConnect Panel — PublicSiteController (Faz 8: kurumsal site — no-auth)
 *
 * Kök "/" anonim→site, giriş yapmış merchant→/dashboard. Pattern A (BaseController yok; merchant
 * authorization/_branding enjeksiyonu olmadan). 'site' layout + brand() ile dinamik marka.
 * Public POST'lar CsrfMiddleware arkasında + honeypot. ROUTE_PERMISSION_MAP/MODULE_MAP DIŞINDA → otomatik public.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\BlogPost;
use App\Models\Faq;
use App\Models\Lead;
use App\Models\Package;
use App\Models\Setting;
use App\Models\SiteModule;
use App\Models\SiteScreenshot;
use App\Models\StoreSignupRequest;
use App\Models\Testimonial;
use App\Services\MarketingTrackingService;
use App\Services\NotificationService;
use App\Services\StoreProvisioningService;

final class PublicSiteController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly Package $packages;
    private readonly Testimonial $testimonials;
    private readonly Faq $faqs;
    private readonly BlogPost $blog;
    private readonly Lead $leads;
    private readonly StoreSignupRequest $signups;
    private readonly Setting $settings;
    private readonly NotificationService $notifier;
    private readonly SiteModule $siteModules;
    private readonly SiteScreenshot $screenshots;
    private readonly StoreProvisioningService $provisioning;
    private readonly MarketingTrackingService $tracking;
    private readonly \App\Core\Database $db;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->packages     = $container->get(Package::class);
        $this->testimonials = $container->get(Testimonial::class);
        $this->faqs         = $container->get(Faq::class);
        $this->blog         = $container->get(BlogPost::class);
        $this->leads        = $container->get(Lead::class);
        $this->signups      = $container->get(StoreSignupRequest::class);
        $this->settings     = $container->get(Setting::class);
        $this->notifier     = $container->get(NotificationService::class);
        $this->siteModules  = $container->get(SiteModule::class);
        $this->screenshots  = $container->get(SiteScreenshot::class);
        $this->provisioning = $container->get(StoreProvisioningService::class);
        $this->tracking     = $container->get(MarketingTrackingService::class);
        $this->db           = $container->get(\App\Core\Database::class);
    }

    private function render(string $view, array $data = []): void
    {
        $data['_csrf']  = $this->session->csrfToken();
        $data['_flash'] = [
            'success'  => $this->session->getFlash('success'),
            'error'    => $this->session->getFlash('error'),
            'mk_event' => $data['mk_event'] ?? $this->session->getFlash('mk_event'),
        ];
        $this->response->view($view, $data, 'site');
    }

    /** Dönüşüm olayını sunucu-taraflı (Conversions API) gönder + client için event dizisi döndür (aynı event_id → dedup). PII flash'a girmez. */
    private function trackConversion(string $event, array $user = [], array $custom = []): array
    {
        // KVKK/GDPR: çerez onayı ('all') yoksa reklam platformlarına olay gönderme (Lead kaydı ayrıca tutulur).
        if (($_COOKIE['ozy_consent'] ?? '') !== 'all') { return []; }
        $eid    = bin2hex(random_bytes(8));
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $full   = isset($_SERVER['HTTP_HOST']) ? $scheme . '://' . $_SERVER['HTTP_HOST'] . (string) ($_SERVER['REQUEST_URI'] ?? '') : '';
        try { $this->tracking->track($event, $user, $custom, $eid, $full); } catch (\Throwable) {}
        return ['event' => $event, 'event_id' => $eid, 'params' => $custom];
    }

    /** Kök: authed merchant → /dashboard, değilse kurumsal Ana Sayfa. */
    public function root(Request $request): void
    {
        if ($this->session->isAuthenticated()) {
            $this->response->redirectTo('/dashboard');
            return;
        }
        $this->home($request);
    }

    public function home(Request $request): void
    {
        $this->render('public.home', [
            'pageTitle'    => 'Tüm pazaryerleri tek panelde',
            'seo'          => ['title' => ''],
            'packages'     => $this->packages->activePublic(),
            'testimonials' => $this->testimonials->activeOrdered(),
            'faqs'         => $this->faqs->activeOrdered(),
            'siteModules'  => $this->siteModules->activeOrdered(),
            'shotsHero'    => $this->screenshots->forSlot('hero'),
            'shotsBig'     => $this->screenshots->forSlot('big'),
            'shotsGallery' => $this->screenshots->forSlot('gallery'),
        ]);
    }

    public function features(Request $request): void
    {
        // Tek-sayfa kurumsal site: Özellikler ana sayfanın #features bölümüdür.
        $this->response->redirectTo('/#features');
    }

    public function privacy(Request $request): void
    {
        $this->render('public.privacy', ['pageTitle' => 'Gizlilik Politikası']);
    }

    public function terms(Request $request): void
    {
        $this->render('public.terms', ['pageTitle' => 'Kullanım Koşulları']);
    }

    public function cookies(Request $request): void
    {
        $this->render('public.cookies', ['pageTitle' => 'Çerez Politikası']);
    }

    public function pricing(Request $request): void
    {
        // Tek-sayfa kurumsal site: Paketler ana sayfanın #pricing bölümüdür.
        $this->response->redirectTo('/#pricing');
    }

    public function references(Request $request): void
    {
        $this->render('public.references', ['pageTitle' => 'Referanslar', 'testimonials' => $this->testimonials->activeOrdered()]);
    }

    public function faq(Request $request): void
    {
        // Tek-sayfa kurumsal site: SSS ana sayfanın #faq bölümüdür.
        $this->response->redirectTo('/#faq');
    }

    public function blog(Request $request): void
    {
        $this->render('public.blog', ['pageTitle' => 'Blog', 'posts' => $this->blog->publishedList(30)]);
    }

    public function blogPost(Request $request, string $slug): void
    {
        $post = $this->blog->findPublishedBySlug($slug);
        if ($post === false) {
            $this->response->notFound('Yazı bulunamadı');
            return;
        }
        $seo = json_decode((string) ($post['seo_json'] ?? ''), true) ?: [];
        $this->render('public.blog_post', [
            'pageTitle' => (string) $post['title'],
            'seo'       => ['title' => (string) $post['title'], 'description' => (string) ($post['excerpt'] ?? ($seo['description'] ?? ''))],
            'post'      => $post,
        ]);
    }

    public function contact(Request $request): void
    {
        $this->render('public.contact', ['pageTitle' => 'İletişim']);
    }

    public function demo(Request $request): void
    {
        $this->render('public.demo', ['pageTitle' => 'Demo Talebi']);
    }

    /** Demo + İletişim formu (type ile ayrılır). */
    public function demoSubmit(Request $request): void
    {
        $type = $request->post('type', 'contact') === 'demo' ? 'demo' : 'contact';
        $back = $type === 'demo' ? '/demo' : '/iletisim';

        if (trim((string) $request->post('website', '')) !== '') { // honeypot → bot
            $this->session->flash('success', 'Talebiniz alındı.');
            $this->response->redirectTo($back);
            return;
        }
        $name  = trim((string) $request->post('name', ''));
        $email = trim((string) $request->post('email', ''));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->session->flash('error', 'Lütfen ad ve geçerli bir e-posta girin.');
            $this->response->redirectTo($back);
            return;
        }
        $this->leads->createLead([
            'type' => $type, 'name' => $name, 'email' => $email,
            'company' => $request->post('company', ''), 'phone' => $request->post('phone', ''),
            'message' => $request->post('message', ''), 'source' => $type === 'demo' ? 'demo_form' : 'contact_form',
            'ip' => $request->ip(), 'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);
        $this->notifyAdmin(($type === 'demo' ? 'Yeni demo talebi' : 'Yeni iletişim mesajı') . ': ' . $name . ' <' . $email . '>');
        $this->session->flash('mk_event', $this->trackConversion('Lead', ['email' => $email, 'phone' => (string) $request->post('phone', '')], ['content_name' => $type === 'demo' ? 'Demo Talebi' : 'İletişim Formu']));
        $this->session->flash('success', $type === 'demo' ? 'Demo talebiniz alındı, en kısa sürede döneceğiz.' : 'Mesajınız alındı, teşekkürler.');
        $this->response->redirectTo($back);
    }

    public function storeCreate(Request $request): void
    {
        $this->render('public.store_create', [
            'pageTitle'    => 'Mağaza Oluştur',
            'packages'     => $this->packages->activePublic(),
            'selectedSlug' => (string) $request->query('paket', ''),
        ]);
    }

    public function storeCreateSubmit(Request $request): void
    {
        if (trim((string) $request->post('website', '')) !== '') {
            $this->response->redirectTo('/magaza-olustur/tesekkurler');
            return;
        }
        $company = trim((string) $request->post('company', ''));
        $contact = trim((string) $request->post('contact_name', ''));
        $email   = trim((string) $request->post('email', ''));
        if ($company === '' || $contact === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->session->flash('error', 'Firma adı, yetkili kişi ve geçerli e-posta zorunludur.');
            $this->response->redirectTo('/magaza-olustur');
            return;
        }

        // Firma şifresini burada belirler; hesap açılınca e-posta beklemeden
        // giriş yapabilsin. Şifre yalnız hash'lenmiş hâliyle saklanır.
        $sifre       = (string) $request->post('password', '');
        $sifreTekrar = (string) $request->post('password_confirmation', '');

        if (mb_strlen($sifre) < 8) {
            $this->session->flash('error', 'Şifre en az 8 karakter olmalıdır.');
            $this->response->redirectTo('/magaza-olustur');
            return;
        }

        if ($sifre !== $sifreTekrar) {
            $this->session->flash('error', 'Girdiğiniz iki şifre birbirini tutmuyor.');
            $this->response->redirectTo('/magaza-olustur');
            return;
        }

        // Bu e-posta zaten kayıtlıysa provizyon nasılsa reddedilecek;
        // kullanıcıya en baştan söyleyip formu boşa doldurtmayalım.
        if ($this->db->fetchOne("SELECT id FROM users WHERE email = :email LIMIT 1", ['email' => $email]) !== false) {
            $this->session->flash('error', 'Bu e-posta ile zaten bir hesap var. Giriş yapın ya da şifrenizi sıfırlayın.');
            $this->response->redirectTo('/magaza-olustur');
            return;
        }

        $sifreHash = password_hash($sifre, PASSWORD_BCRYPT, ['cost' => 12]);
        // Zorunlu sözleşme onayları (Kullanım Koşulları + Gizlilik + KVKK)
        if (!$request->post('agree_terms') || !$request->post('agree_kvkk')) {
            $this->session->flash('error', 'Devam etmek için Kullanım Koşulları, Gizlilik Politikası ve KVKK Aydınlatma Metni\'ni onaylamanız gerekir.');
            $this->response->redirectTo('/magaza-olustur');
            return;
        }
        $marketingConsent = !empty($request->post('agree_marketing')); // ticari elektronik ileti açık rızası (opsiyonel)
        $pkgId = (int) $request->post('requested_package_id', 0);
        $cycle = $request->post('billing_cycle', 'monthly') === 'yearly' ? 'yearly' : 'monthly';
        $phone = (string) $request->post('phone', '');

        $mode = strtolower((string) $this->settings->get('registration_mode', 'admin'));
        if (!in_array($mode, ['admin', 'email', 'auto'], true)) { $mode = 'admin'; }

        // Her modda admin gelen kutusuna iz düş.
        $logLead = function () use ($contact, $email, $company, $phone, $request, $marketingConsent): void {
            $msg = trim((string) $request->post('message', ''));
            $msg .= ($msg !== '' ? "\n\n" : '') . 'Sözleşmeler onaylandı (' . date('d.m.Y H:i') . '). Ticari ileti izni: ' . ($marketingConsent ? 'EVET' : 'Hayır') . '.';
            $this->leads->createLead([
                'type' => 'contact', 'name' => $contact, 'email' => $email, 'company' => $company,
                'phone' => $phone, 'message' => $msg,
                'source' => 'store_signup', 'ip' => $request->ip(), 'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ]);
        };

        // OTOMATİK: paket seçiliyse anında provizyon; paket yoksa admin onayına düşer (aşağıda).
        if ($mode === 'auto' && $pkgId > 0) {
            try {
                $signupId = $this->signups->createRequest([
                    'company' => $company, 'contact_name' => $contact, 'email' => $email, 'phone' => $phone,
                    'requested_package_id' => $pkgId, 'billing_cycle' => $cycle, 'source' => 'public', 'status' => 'pending',
                    'password_hash' => $sifreHash,
                ]);
                $this->provisioning->provision($signupId, $pkgId, $cycle);
                $logLead();
                $this->notifyAdmin('Otomatik mağaza açıldı: ' . $company . ' (' . $email . ')');
                $this->session->flash('mk_event', $this->trackConversion('Purchase', ['email' => $email, 'phone' => $phone], ['currency' => 'TRY', 'content_type' => 'subscription', 'content_ids' => [$pkgId]]));
                $this->response->redirectTo('/magaza-olustur/tesekkurler?d=auto');
                return;
            } catch (\RuntimeException $e) {
                $this->session->flash('error', $e->getMessage());
                $this->response->redirectTo('/magaza-olustur');
                return;
            }
        }

        // E-POSTA DOĞRULAMALI: token üret + doğrulama maili.
        if ($mode === 'email') {
            $token = bin2hex(random_bytes(24));
            $this->signups->createRequest([
                'company' => $company, 'contact_name' => $contact, 'email' => $email, 'phone' => $phone,
                'requested_package_id' => $pkgId > 0 ? $pkgId : null, 'billing_cycle' => $cycle,
                'source' => 'public', 'status' => 'unverified', 'verify_token' => $token,
                'password_hash' => $sifreHash,
            ]);
            $logLead();
            $verifyUrl = $this->signupVerifyUrl($token);
            try {
                $this->notifier->sendTemplate(
                    $email,
                    'signup_verify',
                    ['name' => $contact, 'verify_url' => $verifyUrl],
                    '{site_name} — e-posta doğrulama',
                    '<p>Merhaba {name}, başvurunuzu tamamlamak için e-posta adresinizi doğrulayın:</p>'
                        . '<p><a href="' . htmlspecialchars($verifyUrl, ENT_QUOTES) . '">' . htmlspecialchars($verifyUrl, ENT_QUOTES) . '</a></p>'
                );
            } catch (\Throwable) {
                // SMTP yoksa sessiz (admin yine de görür).
            }
            $this->notifyAdmin('Yeni mağaza başvurusu (e-posta doğrulama bekleniyor): ' . $company);
            $this->session->flash('mk_event', $this->trackConversion('Lead', ['email' => $email, 'phone' => $phone], ['content_name' => 'Mağaza Başvurusu']));
            $this->response->redirectTo('/magaza-olustur/tesekkurler?d=email');
            return;
        }

        // ADMIN ONAYLI (varsayılan; auto'da paket yoksa da buraya düşer): pending → admin onaylar.
        $this->signups->createRequest([
            'company' => $company, 'contact_name' => $contact, 'email' => $email, 'phone' => $phone,
            'requested_package_id' => $pkgId > 0 ? $pkgId : null, 'billing_cycle' => $cycle,
            'source' => 'public', 'status' => 'pending',
            'password_hash' => $sifreHash,
        ]);
        $logLead();
        $this->notifyAdmin('Yeni mağaza başvurusu: ' . $company . ' (' . $contact . ' <' . $email . '>)');
        $this->session->flash('mk_event', $this->trackConversion('Lead', ['email' => $email, 'phone' => $phone], ['content_name' => 'Mağaza Başvurusu']));
        $this->response->redirectTo('/magaza-olustur/tesekkurler');
    }

    /** E-posta doğrulama linki (GET /magaza-olustur/dogrula?token=). */
    public function storeCreateVerify(Request $request): void
    {
        $token  = trim((string) $request->query('token', ''));
        $signup = $token !== '' ? $this->signups->findByVerifyToken($token) : false;
        if ($signup === false) {
            $this->render('public.store_create_thanks', ['pageTitle' => 'Doğrulama', 'thanksMode' => 'invalid']);
            return;
        }

        $sid     = (int) $signup['id'];
        $pkgId   = (int) ($signup['requested_package_id'] ?? 0);
        $submode = strtolower((string) $this->settings->get('registration_email_submode', 'admin'));

        // Doğrula (token temizlenir, verified_at set).
        $this->signups->markVerified($sid, 'pending');

        if ($submode === 'auto' && $pkgId > 0) {
            try {
                $this->provisioning->provision($sid, $pkgId, (string) ($signup['billing_cycle'] ?? 'monthly'));
                $this->notifyAdmin('Mağaza otomatik açıldı (e-posta doğrulandı): ' . (string) ($signup['company'] ?? ''));
                $ev = $this->trackConversion('Purchase', ['email' => (string) ($signup['email'] ?? '')], ['currency' => 'TRY', 'content_type' => 'subscription', 'content_ids' => [$pkgId]]);
                $this->render('public.store_create_thanks', ['pageTitle' => 'Hesabınız Hazır', 'thanksMode' => 'provisioned', 'mk_event' => $ev]);
                return;
            } catch (\RuntimeException) {
                // Provizyon başarısız → pending kalır, admin görür.
            }
        }

        $this->notifyAdmin('Mağaza başvurusu e-posta doğrulandı: ' . (string) ($signup['company'] ?? ''));
        $ev = $this->trackConversion('CompleteRegistration', ['email' => (string) ($signup['email'] ?? '')], ['content_name' => 'Mağaza Üyeliği']);
        $this->render('public.store_create_thanks', ['pageTitle' => 'Doğrulandı', 'thanksMode' => 'verified', 'mk_event' => $ev]);
    }

    private function signupVerifyUrl(string $token): string
    {
        return $this->origin() . '/magaza-olustur/dogrula?token=' . urlencode($token);
    }

    public function storeCreateThanks(Request $request): void
    {
        $d = (string) $request->query('d', '');
        $mode = $d === 'email' ? 'email' : ($d === 'auto' ? 'provisioned' : 'received');
        $this->render('public.store_create_thanks', ['pageTitle' => 'Teşekkürler', 'thanksMode' => $mode]);
    }

    public function robots(Request $request): void
    {
        if (!headers_sent()) { header('Content-Type: text/plain; charset=utf-8'); }
        $base = $this->origin();
        echo "User-agent: *\n";
        foreach (['/admin', '/api', '/dashboard', '/orders', '/products', '/settings', '/stores', '/ops', '/logs', '/backups', '/feed'] as $d) {
            echo "Disallow: {$d}\n";
        }
        echo "Allow: /\n";
        echo "Sitemap: {$base}/sitemap.xml\n";
    }

    public function sitemap(Request $request): void
    {
        if (!headers_sent()) { header('Content-Type: application/xml; charset=utf-8'); }
        $base = $this->origin();
        $urls = ['/', '/ozellikler', '/paketler', '/referanslar', '/blog', '/sss', '/iletisim', '/demo', '/magaza-olustur'];
        foreach ($this->blog->publishedList(200) as $p) {
            $urls[] = '/blog/' . rawurlencode((string) $p['slug']);
        }
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            echo '  <url><loc>' . htmlspecialchars($base . $u, ENT_QUOTES) . '</loc></url>' . "\n";
        }
        echo '</urlset>' . "\n";
    }

    /** Site origin (scheme+host) — sitemap/robots mutlak URL için. */
    private function origin(): string
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $base = $scheme . '://' . $host;
        if (function_exists('app_base_path')) {
            $base .= rtrim((string) app_base_path(), '/');
        }
        return rtrim($base, '/');
    }

    /** Admin'e bilgilendirme e-postası (best-effort; SMTP yoksa sessiz). */
    private function notifyAdmin(string $message): void
    {
        try {
            $to = trim((string) $this->settings->get('contact_email', (string) $this->settings->get('admin_email', '')));
            if ($to !== '') {
                $this->notifier->sendMail($to, brand('site_name') . ' — ' . $message, '<p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>');
            }
        } catch (\Throwable) {
            // sessiz
        }
    }
}
