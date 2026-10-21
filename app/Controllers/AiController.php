<?php
/**
 * OzyConnect Panel — AiController (merchant AI Suite uçları)
 *
 * Modül #1 İçerik Asistanı üretimi + kredi/kullanım sayfası. Erişim/kredi/log
 * mantığı AiKernel'de; burada yalnız HTTP + hata→mesaj eşlemesi var.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\TenantContext;
use App\Models\AiUsageLog;
use App\Models\Product;
use App\Services\AiCreditService;
use App\Services\Ai\AiAdvisorService;
use App\Services\Ai\AiBulkFillService;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiCompetitorService;
use App\Services\Ai\AiContentService;
use App\Services\Ai\AiOptimizeService;
use App\Services\Ai\AiTrendService;
use App\Services\Ai\AiVisionService;
use App\Services\Ai\AiNotEntitledException;
use App\Services\Ai\InsufficientCreditsException;

final class AiController extends BaseController
{
    private const ACTIONS = ['ai_content', 'ai_bulk', 'ai_vision', 'ai_optimize', 'ai_competitor', 'ai_advisor', 'ai_trends'];

    private readonly AiContentService $content;
    private readonly AiBulkFillService $bulk;
    private readonly AiVisionService $vision;
    private readonly AiOptimizeService $optimize;
    private readonly AiCompetitorService $competitor;
    private readonly AiTrendService $trends;
    private readonly AiAdvisorService $advisor;
    private readonly AiCreditService $credits;
    private readonly AiClient $ai;
    private readonly AiUsageLog $usage;
    private readonly Product $products;
    private readonly TenantContext $tenantCtx;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->content   = $container->get(AiContentService::class);
        $this->bulk      = $container->get(AiBulkFillService::class);
        $this->vision    = $container->get(AiVisionService::class);
        $this->optimize  = $container->get(AiOptimizeService::class);
        $this->competitor = $container->get(AiCompetitorService::class);
        $this->trends    = $container->get(AiTrendService::class);
        $this->advisor   = $container->get(AiAdvisorService::class);
        $this->credits   = $container->get(AiCreditService::class);
        $this->ai        = $container->get(AiClient::class);
        $this->usage     = $container->get(AiUsageLog::class);
        $this->products  = $container->get(Product::class);
        $this->tenantCtx = $container->get(TenantContext::class);
    }

    /** POST /ai/content — ürün adından içerik üret (JSON döner). */
    public function contentGenerate(Request $request): void
    {
        $name = trim((string) $request->post('name', ''));
        if ($name === '') {
            $this->error('Önce ürün adını girin.', 422);
        }
        $category = trim((string) $request->post('category', ''));
        $brand    = trim((string) $request->post('brand', ''));
        $notes    = trim((string) $request->post('notes', ''));
        $pid      = (int) $request->post('product_id', 0);

        try {
            $out = $this->content->generate(
                $name,
                $category !== '' ? $category : null,
                $brand !== '' ? $brand : null,
                $notes !== '' ? ['notes' => $notes] : [],
                $pid > 0 ? ['type' => 'product', 'id' => $pid] : []
            );
            $this->success(['content' => $out, 'balance' => $this->credits->balance()], 'İçerik üretildi.');
        } catch (InsufficientCreditsException $e) {
            $this->error('Yetersiz kredi — lütfen kredi yükleyin.', 402);
        } catch (AiNotEntitledException $e) {
            $this->error('AI İçerik Asistanı paketinizde yok. Abonelik sayfasından ekleyebilirsiniz.', 403);
        } catch (\Throwable $e) {
            $this->error('AI üretimi başarısız: ' . $e->getMessage(), 500);
        }
    }

    /** POST /ai/optimize — ürünün pazaryeri liste kalitesini analiz et (JSON döner). */
    public function optimizeAnalyze(Request $request): void
    {
        $pid = (int) $request->post('product_id', 0);
        if ($pid <= 0) {
            $this->error('Analiz için ürün gerekli (önce kaydedin).', 422);
        }
        try {
            $out = $this->optimize->analyzeProduct($pid, ['type' => 'product', 'id' => $pid]);
            $this->success(['optimization' => $out, 'balance' => $this->credits->balance()], 'Analiz tamam.');
        } catch (InsufficientCreditsException $e) {
            $this->error('Yetersiz kredi — lütfen kredi yükleyin.', 402);
        } catch (AiNotEntitledException $e) {
            $this->error('AI Pazaryeri Optimizasyonu paketinizde yok. Abonelik sayfasından ekleyebilirsiniz.', 403);
        } catch (\Throwable $e) {
            $this->error('Optimizasyon analizi başarısız: ' . $e->getMessage(), 500);
        }
    }

    /** GET /ai/competitor — rakip analizi sayfası (ürün seç + rakip gir). */
    public function competitor(Request $request): void
    {
        $rows = $this->products->all([], 'name ASC', 500);
        $list = array_map(static function (array $p): array {
            $price = (float) ($p['list_price'] ?? 0);
            if ($price <= 0) { $price = (float) ($p['base_price'] ?? 0); }
            return ['id' => (int) $p['id'], 'name' => (string) $p['name'], 'price' => $price];
        }, $rows);

        $this->view('ai.competitor', [
            'pageTitle' => 'AI Rakip Analizi',
            'enabled'   => $this->ai->isEnabled(),
            'available' => $this->competitor->available(),
            'balance'   => $this->credits->balance(),
            'cost'      => $this->ai->creditCost('ai_competitor'),
            'products'  => $list,
        ]);
    }

    /** POST /ai/competitor-analyze — kendi ürün + rakipleri karşılaştır (JSON döner). */
    public function competitorAnalyze(Request $request): void
    {
        $pid      = (int) $request->post('product_id', 0);
        $ownName  = trim((string) $request->post('own_name', ''));
        $ownPrice = (float) $request->post('own_price', 0);
        $features = trim((string) $request->post('own_features', ''));

        if ($ownName === '' && $pid > 0) {
            $p = $this->products->find($pid);
            if ($p !== false) {
                $ownName = (string) $p['name'];
                if ($ownPrice <= 0) {
                    $ownPrice = (float) ($p['list_price'] ?? 0);
                    if ($ownPrice <= 0) { $ownPrice = (float) ($p['base_price'] ?? 0); }
                }
            }
        }

        $titles = (array) $request->post('comp_title', []);
        $prices = (array) $request->post('comp_price', []);
        $competitors = [];
        foreach ($titles as $i => $t) {
            $t = trim((string) $t);
            if ($t === '') { continue; }
            $competitors[] = ['title' => $t, 'price' => (float) ($prices[$i] ?? 0)];
            if (count($competitors) >= 6) { break; }
        }

        try {
            $out = $this->competitor->analyze(
                ['name' => $ownName, 'price' => $ownPrice, 'features' => $features],
                $competitors,
                $pid > 0 ? ['type' => 'product', 'id' => $pid] : []
            );
            $this->success(['analysis' => $out, 'balance' => $this->credits->balance()], 'Rakip analizi tamam.');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422);
        } catch (InsufficientCreditsException $e) {
            $this->error('Yetersiz kredi — lütfen kredi yükleyin.', 402);
        } catch (AiNotEntitledException $e) {
            $this->error('AI Rakip Analizi paketinizde yok. Abonelik sayfasından ekleyebilirsiniz.', 403);
        } catch (\Throwable $e) {
            $this->error('Rakip analizi başarısız: ' . $e->getMessage(), 500);
        }
    }

    /** POST /ai/vision — ürün görselinden öneri üret (JSON döner). */
    public function visionAnalyze(Request $request): void
    {
        $imageUrl = trim((string) $request->post('image_url', ''));
        $pid      = (int) $request->post('product_id', 0);

        if ($imageUrl === '' && $pid > 0) {
            $p = $this->products->find($pid);
            if ($p !== false) {
                $imageUrl = trim((string) ($p['main_image'] ?? ''));
                if ($imageUrl === '') {
                    $imgs = json_decode((string) ($p['images_json'] ?? '[]'), true);
                    if (is_array($imgs) && isset($imgs[0])) {
                        $imageUrl = is_array($imgs[0]) ? (string) ($imgs[0]['url'] ?? '') : (string) $imgs[0];
                    }
                }
            }
        }
        if ($imageUrl === '') {
            $this->error('Analiz için bir ürün görseli gerekli.', 422);
        }

        try {
            $out = $this->vision->analyze($imageUrl, $pid > 0 ? ['type' => 'product', 'id' => $pid] : []);
            $this->success(['analysis' => $out, 'balance' => $this->credits->balance()], 'Görsel analiz edildi.');
        } catch (InsufficientCreditsException $e) {
            $this->error('Yetersiz kredi — lütfen kredi yükleyin.', 402);
        } catch (AiNotEntitledException $e) {
            $this->error('AI Görsel Analizi paketinizde yok. Abonelik sayfasından ekleyebilirsiniz.', 403);
        } catch (\Throwable $e) {
            $this->error('Görsel analizi başarısız: ' . $e->getMessage(), 500);
        }
    }

    /** GET /ai/bulk — toplu doldurma sayfası (ürün listesi + ilerleme). */
    public function bulk(Request $request): void
    {
        $items = $this->products->listForBulk(500);
        $this->view('ai.bulk', [
            'pageTitle' => 'AI Toplu Ürün Doldurma',
            'enabled'   => $this->ai->isEnabled(),
            'available' => $this->bulk->available(),
            'balance'   => $this->credits->balance(),
            'cost'      => $this->ai->creditCost('ai_bulk'),
            'items'     => $items,
        ]);
    }

    /** POST /ai/bulk-fill — verilen ürün id chunk'ını doldur (JSON döner). */
    public function bulkFill(Request $request): void
    {
        $raw = $request->post('ids', []);
        if (is_string($raw)) {
            $raw = array_filter(array_map('trim', explode(',', $raw)), fn($v) => $v !== '');
        }
        $ids = array_values(array_unique(array_map('intval', is_array($raw) ? $raw : [])));
        $ids = array_filter($ids, fn(int $v) => $v > 0);

        if ($ids === []) {
            $this->error('İşlenecek ürün seçilmedi.', 422);
        }
        if (count($ids) > 10) {
            $ids = array_slice($ids, 0, 10); // güvenlik: chunk başına üst sınır
        }

        try {
            $out = $this->bulk->fillBatch($ids);
            $this->success([
                'results' => $out['results'],
                'stopped' => $out['stopped'],
                'balance' => $this->credits->balance(),
            ], 'Parti işlendi.');
        } catch (AiNotEntitledException $e) {
            $this->error('AI Toplu Doldurma paketinizde yok. Abonelik sayfasından ekleyebilirsiniz.', 403);
        } catch (\Throwable $e) {
            $this->error('Toplu doldurma başarısız: ' . $e->getMessage(), 500);
        }
    }

    /** GET /ai/credits — bakiye, işlem maliyetleri ve kullanım/hareket geçmişi. */
    public function credits(Request $request): void
    {
        $costs = [];
        foreach (self::ACTIONS as $a) {
            $costs[$a] = $this->ai->creditCost($a);
        }
        $this->view('ai.credits', [
            'pageTitle'    => 'AI Kredi & Kullanım',
            'enabled'      => $this->ai->isEnabled(),
            'balance'      => $this->credits->balance(),
            'costs'        => $costs,
            'packages'     => $this->ai->creditPackages(),
            'transactions' => $this->credits->transactions(null, 50),
            'usage'        => $this->usage->listForStore($this->tenantCtx->currentStoreId(), 30),
        ]);
    }

    /** GET /ai/trends — Trend Merkezi (önceden hesaplı, salt okunur). */
    public function trendsPage(Request $request): void
    {
        $latest = $this->trends->latest();
        $this->view('ai.trends', [
            'pageTitle' => 'AI Trend Merkezi',
            'enabled'   => $this->ai->isEnabled(),
            'available' => $this->trends->available(),
            'date'      => $latest['date'],
            'trends'    => $latest['trends'],
        ]);
    }

    /** GET /ai/advisor — satış danışmanı sohbet sayfası. */
    public function advisor(Request $request): void
    {
        $this->view('ai.advisor', [
            'pageTitle' => 'AI Satış Danışmanı',
            'enabled'   => $this->ai->isEnabled(),
            'available' => $this->advisor->available(),
            'balance'   => $this->credits->balance(),
            'cost'      => $this->ai->creditCost('ai_advisor'),
        ]);
    }

    /** POST /ai/advisor/ask — soruya veriye dayalı AI yanıtı (JSON). */
    public function advisorAsk(Request $request): void
    {
        $q = trim((string) $request->post('question', ''));
        if ($q === '') {
            $this->error('Lütfen bir soru yazın.', 422);
        }
        try {
            $out = $this->advisor->ask($q);
            $this->success(['answer' => $out['answer'], 'balance' => $this->credits->balance()], 'Yanıt hazır.');
        } catch (InsufficientCreditsException $e) {
            $this->error('Yetersiz kredi — lütfen kredi yükleyin.', 402);
        } catch (AiNotEntitledException $e) {
            $this->error('AI Satış Danışmanı paketinizde yok. Abonelik sayfasından ekleyebilirsiniz.', 403);
        } catch (\Throwable $e) {
            $this->error('AI yanıtı başarısız: ' . $e->getMessage(), 500);
        }
    }
}
