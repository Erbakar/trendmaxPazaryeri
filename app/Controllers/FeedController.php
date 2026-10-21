<?php
/**
 * OzyConnect Panel — FeedController
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

namespace App\Controllers;

use App\Core\Container;
use App\Core\Database;
use App\Core\Request;
use App\Core\TenantContext;
use App\Models\Marketplace;
use App\Models\Setting;
use App\Models\XmlFeed;
use App\Services\EntitlementService;
use App\Services\Feed\XmlFeedService;

final class FeedController extends BaseController
{
    private readonly XmlFeed $feedModel;
    private readonly XmlFeedService $feedService;
    private readonly Marketplace $marketplaceModel;
    private readonly Setting $settingModel;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->feedModel        = $container->get(XmlFeed::class);
        $this->feedService      = $container->get(XmlFeedService::class);
        $this->marketplaceModel = $container->get(Marketplace::class);
        $this->settingModel     = $container->get(Setting::class);
    }

    public function index(Request $request): void
    {
        $feeds = $this->feedModel->all([], 'name ASC');
        $appUrl = function_exists('app_base_url') ? app_base_url() : env('APP_URL', 'http://localhost');
        $marketplaces = $this->marketplaceModel->getActive();

        $feedToken = $this->settingModel->get('feed_token', '');
        if (empty($feedToken)) {
            $feedToken = bin2hex(random_bytes(16));
            $this->settingModel->set('feed_token', $feedToken);
        }

        $this->view('feeds.index', [
            'pageTitle'      => 'XML Feedler',
            'feeds'          => $feeds,
            'appUrl'         => $appUrl,
            'marketplaces'   => $marketplaces,
            'feedToken'      => $feedToken,
            'feedIpWhitelist' => $this->settingModel->get('feed_ip_whitelist', ''),
            'feedPassword'   => $this->settingModel->get('feed_password', ''),
            'feedEnabled'    => (bool) $this->settingModel->get('feed_enabled', '0'),
            'feedCacheHours' => (int) $this->settingModel->get('feed_cache_hours', 4),
        ]);
    }

    public function create(Request $request): void
    {
        $marketplaces = $this->marketplaceModel->getActive();

        $this->view('feeds.form', [
            'pageTitle'    => 'Yeni Feed',
            'feed'         => null,
            'marketplaces' => $marketplaces,
        ]);
    }

    public function store(Request $request): void
    {
        $marketplaceId = $request->post('marketplace_id');

        $data = [
            'name'           => $request->post('name', ''),
            'feed_type'      => $request->post('feed_type', 'generic'),
            'filter_json'    => json_encode($request->post('filters', []), JSON_UNESCAPED_UNICODE),
            'marketplace_id' => ($marketplaceId !== null && $marketplaceId !== '' && $marketplaceId !== '0')
                                ? (int) $marketplaceId
                                : null,
            'is_active'      => $request->boolean('is_active') ? 1 : 0,
        ];

        try {
            $feedId = $this->feedService->createFeed($data);
            $this->feedService->generate($feedId);
            $this->redirectWithSuccess('/feeds', 'Feed oluşturuldu ve üretildi');
        } catch (\Throwable $e) {
            $this->redirectWithError('/feeds', 'Hata: ' . $e->getMessage());
        }
    }

    public function regenerate(Request $request, string $id): void
    {
        try {
            $count = $this->feedService->generate((int) $id);
            $this->redirectWithSuccess('/feeds', "Feed yeniden üretildi: {$count} ürün");
        } catch (\Throwable $e) {
            $this->redirectWithError('/feeds', 'Hata: ' . $e->getMessage());
        }
    }

    public function regenerateAll(Request $request): void
    {
        $results = $this->feedService->regenerateAll();
        $this->redirectWithSuccess('/feeds', count($results) . ' feed yeniden üretildi');
    }

    public function saveFeedSettings(Request $request): void
    {
        $this->settingModel->set('feed_enabled', $request->post('feed_enabled', '0'));
        $this->settingModel->set('feed_password', $request->post('feed_password', ''));
        $this->settingModel->set('feed_ip_whitelist', $request->post('feed_ip_whitelist', ''));
        $this->settingModel->set('feed_cache_hours', $request->post('feed_cache_hours', '4'));

        if ($request->post('regenerate_token') === '1') {
            $this->settingModel->set('feed_token', bin2hex(random_bytes(16)));
        }

        $this->redirectWithSuccess('/feeds', 'Feed ayarları kaydedildi');
    }

    /**
     * Public feed serving (auth gerektirmez; oturumsuz pazaryeri/feed okuyucuları çağırır).
     *
     * Feed ANAHTARI kimliktir: kayıt kiracı filtresiz yalnız anahtarla bulunur, mağazası etkin değilse
     * ya da mağazanın paketinde 'feeds' modülü yoksa 404. Ardından TenantContext feed'in mağazasına
     * kurulur ve feed (gerekirse yeniden üretimle) o mağazanın verisiyle sunulur.
     * Rota AuthorizationService::TOKEN_AUTH_ROUTES'ta (RBAC/modül kapısı oturuma dayandığı için burada uygulanır).
     */
    public function serve(Request $request, string $token): void
    {
        $token = trim($token);
        $owner = preg_match('/^[A-Za-z0-9_-]{8,64}$/', $token) === 1
            ? $this->container->get(Database::class)->fetchOne(
                "SELECT f.store_id FROM xml_feeds f
                   JOIN stores s ON s.id = f.store_id AND s.status = 'active'
                  WHERE f.token = :token AND f.is_active = 1
                  LIMIT 1",
                ['token' => $token]
            )
            : false;
        if ($owner === false || (int) ($owner['store_id'] ?? 0) <= 0) {
            $this->response->notFound('Feed bulunamadı');
        }

        $this->container->get(TenantContext::class)->setStoreId((int) $owner['store_id']);
        if (!$this->container->get(EntitlementService::class)->canAccessModule('feeds')) {
            $this->response->notFound('Feed bulunamadı');
        }

        $xml = $this->feedService->serveFeed($token);
        if ($xml === null) {
            $this->response->notFound('Feed bulunamadı');
        }

        header('Content-Type: application/xml; charset=utf-8');
        header('Cache-Control: public, max-age=3600');
        echo $xml;
        exit;
    }
}
