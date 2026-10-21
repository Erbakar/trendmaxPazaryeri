<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Core\TenantContext;
use App\Models\CannedResponse;
use App\Models\Marketplace;
use App\Models\Question;
use App\Services\AuthorizationService;
use App\Services\Marketplace\TrendyolService;
use App\Services\Marketplace\PazaramaService;
use App\Services\Marketplace\IdefixService;
use App\Services\Marketplace\HepsiburadaService;
use App\Services\Sync\QuestionSyncService;

final class QuestionController extends BaseController
{
    private const DEFAULT_PAGE_SIZE = 25;
    private const MAX_PAGE_SIZE = 100;
    private const MAX_ANSWER_LENGTH = 2000;
    /** Soru-cevap API'si olan pazaryerleri */
    private const QUESTION_SLUGS = ['trendyol', 'pazarama', 'hepsiburada'];
    private const CLOSED_STATUSES = ['rejected', 'reddedildi'];
    private const HB_CLOSED_STATUSES = ['Rejected', 'AutoClosed'];

    private readonly Marketplace $marketplaceModel;
    private readonly Question $questionModel;
    private readonly CannedResponse $cannedModel;
    private readonly TrendyolService $trendyolService;
    private readonly PazaramaService $pazaramaService;
    private readonly IdefixService $idefixService;
    private readonly HepsiburadaService $hepsiburadaService;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->marketplaceModel = $container->get(Marketplace::class);
        $this->questionModel    = $container->get(Question::class);
        $this->cannedModel      = $container->get(CannedResponse::class);
        $this->trendyolService  = $container->get(TrendyolService::class);
        $this->pazaramaService  = $container->get(PazaramaService::class);
        $this->idefixService    = $container->get(IdefixService::class);
        $this->hepsiburadaService = $container->get(HepsiburadaService::class);
    }

    /**
     * GET /questions — Ana sayfa
     */
    public function index(Request $request): void
    {
        $filters = [
            'marketplace_id' => $request->query('marketplace_id'),
            'status'         => $request->query('status'),
            'date_from'      => $request->query('date_from'),
            'date_to'        => $request->query('date_to'),
            'search'         => $request->query('search'),
        ];

        $marketplaces = $this->marketplaceModel->getAll();
        $mpQuestionCounts = $this->questionModel->getMarketplaceQuestionCounts();
        $statusCounts = $this->questionModel->getStatusCounts($filters);

        $this->view('questions.index', [
            'pageTitle'        => 'Sorular',
            'filters'          => $filters,
            'marketplaces'     => $marketplaces,
            'mpQuestionCounts' => $mpQuestionCounts,
            'statusCounts'     => $statusCounts,
            'supportedQuestionMarketplaceSlugs' => self::QUESTION_SLUGS,
            'noQaSupportSlugs'                  => ['n11', 'ciceksepeti', 'pttavm', 'idefix'],
        ]);
    }

    /**
     * GET /questions/canned — Mağazanın hazır yanıtları (JSON)
     */
    public function cannedList(Request $request): void
    {
        $this->json(['success' => true, 'data' => $this->cannedModel->forStore()]);
    }

    /**
     * POST /questions/canned — Yeni hazır yanıt ekle (tenant-scoped)
     */
    public function cannedStore(Request $request): void
    {
        $title = mb_substr(trim((string) $request->post('title', '')), 0, 120);
        $body  = trim((string) $request->post('body', ''));
        if ($title === '' || $body === '') {
            $this->json(['success' => false, 'message' => 'Başlık ve içerik zorunludur.'], 422);
        }
        $id = $this->cannedModel->create(['title' => $title, 'body' => $body]);
        $this->json(['success' => true, 'message' => 'Hazır yanıt eklendi.', 'id' => $id]);
    }

    /**
     * POST /questions/canned/{id}/delete — Hazır yanıt sil (store-scoped)
     */
    public function cannedDelete(Request $request, string $id): void
    {
        $cannedId = (int) $id;
        if ($cannedId <= 0 || !$this->cannedModel->delete($cannedId)) {
            $this->json(['success' => false, 'message' => 'Hazır yanıt bulunamadı.'], 404);
        }
        $this->json(['success' => true, 'message' => 'Hazır yanıt silindi.']);
    }

    /**
     * GET /questions/ajax — DataTables AJAX
     */
    public function ajaxList(Request $request): void
    {
        $draw   = (int) ($_GET['draw'] ?? 1);
        $start  = max(0, (int) ($_GET['start'] ?? 0));
        $length = max(10, min(self::MAX_PAGE_SIZE, (int) ($_GET['length'] ?? self::DEFAULT_PAGE_SIZE)));
        $page   = (int) floor($start / max(1, $length)) + 1;

        $searchValue = is_array($_GET['search'] ?? null)
            ? trim((string) ($_GET['search']['value'] ?? ''))
            : trim((string) ($_GET['search'] ?? ''));

        $filters = [
            'marketplace_id' => trim((string) ($_GET['marketplace_id'] ?? '')),
            'status'         => trim((string) ($_GET['status'] ?? '')),
            'date_from'      => trim((string) ($_GET['date_from'] ?? '')),
            'date_to'        => trim((string) ($_GET['date_to'] ?? '')),
            'search'         => $searchValue,
        ];

        try {
            $result = $this->questionModel->getFiltered($filters, $page, $length);

            // Only fetch counts on first draw to avoid redundant queries on pagination/sort
            $mpCounts = null;
            $statusCounts = null;
            $mpStatusCounts = ['all' => 0];
            if ($draw <= 1) {
                $mpCounts = $this->questionModel->getMarketplaceQuestionCounts();
                $statusCounts = $this->questionModel->getStatusCounts($filters);
            }

            // Per-marketplace status counts (overlay header stats)
            $mpId = trim($filters['marketplace_id'] ?? '');
            if ($mpId !== '' && $draw <= 1) {
                $mpStatusCounts = $this->questionModel->getStatusCountsByMarketplace((int) $mpId);
            }

            $rows = array_map(
                fn(array $row): array => array_merge($row, $this->questionState($row)),
                $result['data']
            );

            $this->json([
                'draw'             => $draw,
                'recordsTotal'     => $result['total'],
                'recordsFiltered'  => $result['total'],
                'data'             => $rows,
                'mpQuestionCounts' => $mpCounts,
                'statusCounts'     => $statusCounts,
                'mpStatusCounts'   => $mpStatusCounts,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Soru listesi yüklenemedi: ' . $e->getMessage(), 'QuestionController');
            $this->json(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []], 500);
        }
    }

    /**
     * POST /questions/{id}/answer — Soruyu cevapla
     */
    public function answer(Request $request, string $id): void
    {
        $questionId = (int) $id;
        $question = $questionId > 0 ? $this->questionModel->find($questionId) : false;
        if ($question === false) {
            $this->json(['success' => false, 'message' => 'Soru bulunamadı.'], 404);
        }

        $marketplaceId = (int) $question['marketplace_id'];
        if (!$this->authorization()->canAccessMarketplace($marketplaceId)) {
            $this->json(['success' => false, 'message' => 'Bu pazaryeri paketinizde bulunmuyor.'], 403);
        }

        $answerText = trim((string) $request->post('answer_text', ''));
        if ($answerText === '') {
            $this->json(['success' => false, 'message' => 'Cevap metni boş olamaz.'], 422);
        }
        if (mb_strlen($answerText) > self::MAX_ANSWER_LENGTH) {
            $this->json(['success' => false, 'message' => 'Cevap en fazla ' . self::MAX_ANSWER_LENGTH . ' karakter olabilir.'], 422);
        }

        $closedReason = $this->questionState($question)['closed_reason'];
        if ($closedReason !== '') {
            $this->json(['success' => false, 'message' => $closedReason === 'expired'
                ? 'Cevap süresi dolduğu için pazaryeri bu soruyu kapattı; cevap gönderilemez.'
                : 'Bu soru pazaryeri tarafından reddedildi; cevap gönderilemez.'], 422);
        }

        // Pazaryerine cevap gönder
        try {
            $marketplace = $this->marketplaceModel->find($marketplaceId);
            if ($marketplace === false) {
                throw new \RuntimeException('Pazaryeri bulunamadı.');
            }

            $this->sendAnswer((string) ($marketplace['slug'] ?? ''), $question, $answerText);

            // Yerel DB güncelle
            $this->questionModel->answer($questionId, $answerText);

            $this->json(['success' => true, 'message' => 'Cevap başarıyla gönderildi.']);
        } catch (\DomainException $e) {
            // Kullanıcının düzeltebileceği durum (eski kayıt, desteklenmeyen pazaryeri): sunucu hatası değil
            $this->json(['success' => false, 'message' => 'Cevap gönderilemedi: ' . $e->getMessage()], 422);
        } catch (\Throwable $e) {
            $this->logger->error('Soru cevaplanamadı: ' . $e->getMessage(), 'QuestionController');
            $this->json(['success' => false, 'message' => 'Cevap gönderilemedi: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Cevabı pazaryerine iletir. Hepsiburada'da soru kimliği issueNumber'dır (marketplace_question_id).
     *
     * @param array<string, mixed> $question
     */
    private function sendAnswer(string $slug, array $question, string $answerText): void
    {
        $remoteId = trim((string) ($question['marketplace_question_id'] ?? ''));
        if ($remoteId === '') {
            throw new \DomainException('Sorunun pazaryeri kimliği yok; soruları yeniden senkronize edin.');
        }

        if ($slug === 'trendyol') {
            $this->trendyolService->loadCredentials();
            $this->trendyolService->answerQuestion($remoteId, $answerText);
        } elseif ($slug === 'pazarama') {
            $this->pazaramaService->loadCredentials();
            $this->pazaramaService->answerQuestion($remoteId, $answerText);
        } elseif ($slug === 'hepsiburada') {
            if (!ctype_digit($remoteId)) {
                throw new \DomainException('Bu Hepsiburada sorusu eski biçimde kaydedilmiş (soru numarası yok). Soruları yeniden senkronize edip tekrar deneyin.');
            }
            $this->hepsiburadaService->loadCredentials();
            if ($this->hepsiburadaService->answerQuestion($remoteId, $answerText) === false) {
                throw new \RuntimeException('Hepsiburada cevabı kabul etmedi.');
            }
        } elseif ($slug === 'idefix') {
            throw new \DomainException('İdefix soru-cevap API desteği bulunmuyor.');
        } else {
            throw new \DomainException('Bu pazaryeri için soru cevaplama desteklenmiyor.');
        }
    }

    /**
     * POST /questions/sync — Soruları pazaryerinden çek (yalnız mağazanın erişebildiği pazaryerleri)
     */
    public function sync(Request $request): void
    {
        $marketplaceId = (int) $request->post('marketplace_id', '0');
        @set_time_limit(300);

        try {
            if ($marketplaceId > 0) {
                $this->syncSingleMarketplace($marketplaceId);
            }

            $totalSynced = 0;
            $errors = [];
            $marketplaces = $this->syncableMarketplaces();
            if ($marketplaces === []) {
                $this->json(['success' => true, 'message' => 'Soru senkronu yapılabilecek bağlı pazaryeri yok.']);
            }

            foreach ($marketplaces as $mp) {
                try {
                    $totalSynced += $this->syncMarketplace($mp);
                } catch (\Throwable $e) {
                    $mpName = $mp['display_name'] ?? $mp['slug'] ?? 'Bilinmeyen';
                    $errors[] = $mpName . ': ' . $e->getMessage();
                    $this->logger->error("Soru sync hatası ({$mp['slug']}): " . $e->getMessage(), 'QuestionController');
                }
            }

            $msg = "Toplam {$totalSynced} soru senkronize edildi.";
            if (!empty($errors)) {
                $msg .= ' Hatalar: ' . implode('; ', $errors);
            }
            $this->json(['success' => true, 'message' => $msg]);
        } catch (\Throwable $e) {
            $this->logger->error('Soru senkronizasyonu başarısız: ' . $e->getMessage(), 'QuestionController');
            $this->json(['success' => false, 'message' => 'Senkronizasyon hatası: ' . $e->getMessage()], 500);
        }
    }

    private function syncSingleMarketplace(int $marketplaceId): never
    {
        if (!$this->authorization()->canAccessMarketplace($marketplaceId)) {
            $this->json(['success' => false, 'message' => 'Bu pazaryeri paketinizde bulunmuyor.'], 403);
        }

        $marketplace = $this->marketplaceModel->find($marketplaceId);
        if ($marketplace === false) {
            $this->json(['success' => false, 'message' => 'Pazaryeri bulunamadı.'], 404);
        }
        if (!in_array($marketplace['slug'] ?? '', self::QUESTION_SLUGS, true)) {
            $this->json(['success' => false, 'message' => 'Bu pazaryeri için soru senkronu desteklenmiyor.'], 422);
        }

        $synced = $this->syncMarketplace($marketplace);
        $this->json(['success' => true, 'message' => "{$synced} soru senkronize edildi."]);
    }

    /**
     * Mağazanın paketindeki (entitlement), aktif, bağlantısı girilmiş ve soru API'si olan pazaryerleri.
     *
     * @return array<int, array<string, mixed>>
     */
    private function syncableMarketplaces(): array
    {
        $authorization = $this->authorization();
        $storeId = $this->container->get(TenantContext::class)->currentStoreId();

        return array_values(array_filter(
            $this->marketplaceModel->getActiveForStore($storeId),
            fn(array $mp): bool => (int) ($mp['is_active'] ?? 0) === 1
                && in_array($mp['slug'] ?? '', self::QUESTION_SLUGS, true)
                && $authorization->canAccessMarketplace((int) $mp['id'])
                && $this->marketplaceModel->isConnectedForStore((int) $mp['id'])
        ));
    }

    /**
     * Soru durumu: HB ham durumu (extra_data_json.hb_status) ve kapalılık nedeni.
     * closed_reason: '' (açık/cevaplı) | 'expired' (AutoClosed — süresi doldu) | 'rejected'
     *
     * @param array<string, mixed> $question
     * @return array{hb_status: string, closed_reason: string}
     */
    private function questionState(array $question): array
    {
        $extra = json_decode((string) ($question['extra_data_json'] ?? ''), true);
        $hbStatus = is_array($extra) && is_string($extra['hb_status'] ?? null) ? $extra['hb_status'] : '';
        $status = (string) ($question['status'] ?? '');

        $closed = $status !== 'answered'
            && (in_array($status, self::CLOSED_STATUSES, true) || in_array($hbStatus, self::HB_CLOSED_STATUSES, true));

        return [
            'hb_status'     => $hbStatus,
            'closed_reason' => $closed ? ($hbStatus === 'AutoClosed' ? 'expired' : 'rejected') : '',
        ];
    }

    private function authorization(): AuthorizationService
    {
        return $this->container->get(AuthorizationService::class);
    }

    /**
     * Hepsiburada soru senkronu tek yerde: QuestionSyncService (normalizeQuestion + issueNumber).
     */
    private function syncHepsiburadaQuestions(int $mpId): int
    {
        $service = $this->container->get(QuestionSyncService::class);
        if (is_callable([$service, 'syncOne'])) {
            return (int) $service->syncOne($mpId, 'hepsiburada');
        }
        if (is_callable([$service, 'syncMarketplace'])) {
            return (int) $service->syncMarketplace($mpId, 'hepsiburada');
        }

        throw new \RuntimeException('Hepsiburada soru senkronu bu kurulumda henüz etkin değil (güncelleme eksik).');
    }

    /**
     * Tek pazaryerinden soruları çek
     */
    private function syncMarketplace(array $marketplace): int
    {
        $slug = $marketplace['slug'] ?? '';
        $mpId = (int) $marketplace['id'];
        $synced = 0;

        if ($slug === 'trendyol') {
            $this->trendyolService->loadCredentials();
            $questions = $this->trendyolService->getQuestions();

            foreach ($questions as $q) {
                $existing = $this->questionModel->findByMarketplaceQuestionId($mpId, (string) $q['id']);

                $data = [
                    'marketplace_id'          => $mpId,
                    'marketplace_question_id'  => (string) $q['id'],
                    'marketplace_product_id'   => (string) ($q['productId'] ?? ''),
                    'product_name'             => $q['productName'] ?? null,
                    'product_image_url'        => $q['productImage'] ?? null,
                    'question_text'            => $q['text'] ?? '',
                    'question_date'            => isset($q['creationDate'])
                        ? date('Y-m-d H:i:s', (int) ($q['creationDate'] / 1000))
                        : date('Y-m-d H:i:s'),
                    'customer_name'            => $q['customerFirstName'] ?? null,
                    'status'                   => !empty($q['answers']) ? 'answered' : 'waiting',
                    'answer_text'              => !empty($q['answers']) ? ($q['answers'][0]['text'] ?? null) : null,
                    'answer_date'              => !empty($q['answers']) && isset($q['answers'][0]['creationDate'])
                        ? date('Y-m-d H:i:s', (int) ($q['answers'][0]['creationDate'] / 1000))
                        : null,
                    'synced_at'                => date('Y-m-d H:i:s'),
                ];

                if ($existing) {
                    $this->questionModel->update((int) $existing['id'], $data);
                } else {
                    $this->questionModel->create($data);
                }
                $synced++;
            }
        } elseif ($slug === 'pazarama') {
            $this->pazaramaService->loadCredentials();

            // Tüm soruları çek — bekleyen + cevaplanmış
            foreach ([0, 1] as $statusFilter) {
                $page = 1;
                $pageSize = 100;

                do {
                    $filters = [
                        'page'      => $page,
                        'page_size' => $pageSize,
                    ];
                    if ($statusFilter > 0) {
                        $filters['status'] = $statusFilter;
                    }

                    $questions = $this->pazaramaService->fetchQuestions($filters);
                    if (empty($questions)) {
                        break;
                    }

                    foreach ($questions as $q) {
                        $qId = (string) ($q['questionId'] ?? $q['id'] ?? '');
                        if ($qId === '') continue;

                        $existing = $this->questionModel->findByMarketplaceQuestionId($mpId, $qId);

                        $answerText = $q['answer'] ?? $q['answerText'] ?? null;
                        $hasAnswer = !empty($answerText);

                        $questionDate = $q['questionDate'] ?? $q['createdDate'] ?? $q['creationDate'] ?? null;
                        if (is_string($questionDate)) {
                            $ts = strtotime($questionDate);
                            $questionDate = $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
                        } elseif (is_numeric($questionDate)) {
                            $questionDate = date('Y-m-d H:i:s', intdiv((int) $questionDate, ($questionDate > 9999999999 ? 1000 : 1)));
                        } else {
                            $questionDate = date('Y-m-d H:i:s');
                        }

                        $answerDate = $q['answerDate'] ?? $q['answerCreatedDate'] ?? null;
                        if (is_string($answerDate) && $answerDate !== '') {
                            $ts = strtotime($answerDate);
                            $answerDate = $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
                        } else {
                            $answerDate = null;
                        }

                        $data = [
                            'marketplace_id'           => $mpId,
                            'marketplace_question_id'  => $qId,
                            'marketplace_product_id'   => (string) ($q['productId'] ?? $q['productCode'] ?? ''),
                            'product_name'             => $q['productName'] ?? $q['productTitle'] ?? null,
                            'product_image_url'        => $q['productImage'] ?? $q['productImageUrl'] ?? null,
                            'question_text'            => $q['questionText'] ?? $q['question'] ?? $q['text'] ?? '',
                            'question_date'            => $questionDate,
                            'customer_name'            => $q['customerName'] ?? $q['askerName'] ?? null,
                            'status'                   => $hasAnswer ? 'answered' : 'waiting',
                            'answer_text'              => $hasAnswer ? $answerText : null,
                            'answer_date'              => $hasAnswer ? $answerDate : null,
                            'synced_at'                => date('Y-m-d H:i:s'),
                        ];

                        if ($existing) {
                            $this->questionModel->update((int) $existing['id'], $data);
                        } else {
                            $this->questionModel->create($data);
                        }
                        $synced++;
                    }

                    $page++;
                } while (count($questions) >= $pageSize);
            }
        } elseif ($slug === 'idefix') {
            // İdefix Q&A API desteği bulunmuyor — atla
            $this->logger->info('İdefix soru-cevap API desteği bulunmuyor, atlanıyor.', 'QuestionController');
        } elseif ($slug === 'hepsiburada') {
            $synced = $this->syncHepsiburadaQuestions($mpId);
        }

        return $synced;
    }
}
