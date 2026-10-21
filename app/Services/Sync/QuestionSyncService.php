<?php
/**
 * OzyConnect Panel — QuestionSyncService (Faz 6)
 *
 * Soru & cevap senkronizasyonu. Daha önce cron/sync_questions.php içinde INLINE'dı;
 * Faz 6'da servise taşındı ki hem cron hem KUYRUK (QuestionSyncJob) tek kaynaktan
 * çağırsın. Diğer sync servisleriyle simetrik: FAIL-CLOSED (TenantContext zorunlu) +
 * yalnız ENTITLEMENT'li pazaryerleri (getActiveForStore).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Sync;

use App\Core\Logger;
use App\Core\TenantContext;
use App\Models\Marketplace;
use App\Models\Question;
use App\Models\SyncLog;
use App\Services\Marketplace\TrendyolService;
use App\Services\Marketplace\IdefixService;
use App\Services\Marketplace\PazaramaService;
use App\Services\Marketplace\HepsiburadaService;

final class QuestionSyncService
{
    /** Soru API'si olan pazaryerleri (diğerlerinde senkron yok, log yazılmaz). */
    private const SUPPORTED_SLUGS = ['trendyol', 'idefix', 'pazarama', 'hepsiburada'];

    /** sync_logs.triggered_by enum değerleri. */
    private const LOG_TRIGGERS = ['cron', 'manual', 'api', 'initial_import'];

    /**
     * Periyodik (cron/kuyruk) HB senkronunda kapanmış durumların yalnız ilk sayfaları çekilir
     * (en yeni kayıtlar); bekleyen sorular her zaman tam çekilir. Tam tarama elle senkronda.
     */
    private const HB_PERIODIC_CLOSED_PAGES = 2;

    public function __construct(
        private readonly Marketplace $marketplaceModel,
        private readonly Question $questionModel,
        private readonly TrendyolService $trendyolService,
        private readonly IdefixService $idefixService,
        private readonly PazaramaService $pazaramaService,
        private readonly HepsiburadaService $hepsiburadaService,
        private readonly Logger $logger,
        private readonly TenantContext $tenant,
        private readonly SyncLog $syncLogModel,
    ) {}

    /**
     * Aktif (entitlement'li) pazaryerlerinden soruları senkronize eder.
     *
     * Her pazaryeri için sync_logs kaydı yazılır (sync_type 'questions'): mağaza aralığı
     * (sync_questions_interval) cron/HTTP cron'da da uygulanır ve /sync ekranı son koşuyu gösterir.
     * Bir pazaryerinin hatası diğerlerini durdurmaz; hata yutulmaz, 'failed'/'errors' ile döner
     * (OrderSync/ReturnSync ile aynı biçim: errors[] = {marketplace, error}).
     *
     * @return array<string, mixed>  slug => senkronize edilen soru sayısı, 'failed' => hatalı pazaryeri
     *                               sayısı, 'errors' => list<array{marketplace:string, error:string}>
     */
    public function syncAll(string $triggeredBy = 'manual'): array
    {
        $storeId = $this->tenant->currentStoreId();              // FAIL-CLOSED
        $marketplaces = $this->marketplaceModel->getActiveForStore($storeId); // ENTITLEMENT-filtreli
        $logTrigger = in_array($triggeredBy, self::LOG_TRIGGERS, true) ? $triggeredBy : 'cron';
        $fullScan = $triggeredBy !== 'cron';
        $result = [];
        $errors = [];

        foreach ($marketplaces as $mp) {
            $slug = (string) ($mp['slug'] ?? '');
            $mpId = (int) $mp['id'];
            if (!in_array($slug, self::SUPPORTED_SLUGS, true)) {
                $result[$slug] = 0;
                continue;
            }

            $logId = $this->startLog($mpId, $logTrigger);
            try {
                $count = $this->syncBySlug($mpId, $slug, $fullScan);
                $result[$slug] = $count;
                $this->finishLog($logId, 'success', $count, $count);
            } catch (\Throwable $e) {
                $this->logger->error("Soru sync hatası ({$slug}): " . $e->getMessage(), 'QuestionSync');
                $result[$slug] = 0;
                $errors[] = ['marketplace' => $slug, 'error' => $e->getMessage()];
                $this->finishLog($logId, 'failed', 0, 0, 1, [['error' => $e->getMessage()]]);
            }
        }

        $result['failed'] = count($errors);
        $result['errors'] = $errors;
        return $result;
    }

    /** sync_logs başlangıç kaydı; log yazılamazsa senkron yine çalışır. */
    private function startLog(int $mpId, string $triggeredBy): ?int
    {
        try {
            return $this->syncLogModel->start($mpId, 'questions', $triggeredBy);
        } catch (\Throwable $e) {
            $this->logger->warning('Soru sync log kaydı açılamadı: ' . $e->getMessage(), 'QuestionSync');
            return null;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $errors
     */
    private function finishLog(?int $logId, string $status, int $fetched, int $processed, int $failed = 0, array $errors = []): void
    {
        if ($logId === null) {
            return;
        }
        try {
            $this->syncLogModel->finish($logId, $status, $fetched, $processed, $failed, $errors);
        } catch (\Throwable $e) {
            $this->logger->warning('Soru sync log kaydı kapatılamadı: ' . $e->getMessage(), 'QuestionSync');
        }
    }

    /**
     * Tek pazaryerinin sorularını senkronize eder (panelden elle senkron için).
     *
     * Yetki KONTROLLÜ: pazaryeri aktif mağazanın entitlement listesinde
     * (Marketplace::getActiveForStore) değilse ya da slug kayıtla uyuşmuyorsa
     * \RuntimeException fırlatılır — hiçbir API çağrısı yapılmaz. Pazaryeri API
     * hataları da yutulmaz; çağıran yakalayıp kullanıcıya gösterir.
     *
     * @return int Senkronlanan soru sayısı (soru desteği olmayan pazaryerinde 0)
     */
    public function syncOne(int $mpId, string $slug): int
    {
        $storeId = $this->tenant->currentStoreId();              // FAIL-CLOSED

        $marketplace = null;
        foreach ($this->marketplaceModel->getActiveForStore($storeId) as $mp) {
            if ((int) ($mp['id'] ?? 0) === $mpId) {
                $marketplace = $mp;
                break;
            }
        }

        if ($marketplace === null) {
            throw new \RuntimeException('Bu pazaryeri mağazanız için etkin değil; soru senkronu yapılamadı.');
        }

        $actualSlug = (string) ($marketplace['slug'] ?? '');
        if ($slug !== '' && $slug !== $actualSlug) {
            throw new \RuntimeException('Pazaryeri bilgisi kayıtla uyuşmuyor; soru senkronu yapılamadı.');
        }

        if (!in_array($actualSlug, self::SUPPORTED_SLUGS, true)) {
            return 0;
        }

        $logId = $this->startLog($mpId, 'manual');
        try {
            $count = $this->syncBySlug($mpId, $actualSlug, true);
        } catch (\Throwable $e) {
            $this->finishLog($logId, 'failed', 0, 0, 1, [['error' => $e->getMessage()]]);
            throw $e;
        }
        $this->finishLog($logId, 'success', $count, $count);

        return $count;
    }

    /** @param bool $fullScan false → periyodik: HB'de kapanmış durumların yalnız en yeni sayfaları */
    private function syncBySlug(int $mpId, string $slug, bool $fullScan = true): int
    {
        return match ($slug) {
            'trendyol'    => $this->syncTrendyol($mpId),
            'idefix'      => $this->syncIdefix($mpId),
            'pazarama'    => $this->syncPazarama($mpId),
            'hepsiburada' => $this->syncHepsiburada($mpId, $fullScan),
            default       => 0,
        };
    }

    private function upsert(int $mpId, string $qId, array $data): void
    {
        $existing = $this->questionModel->findByMarketplaceQuestionId($mpId, $qId);
        if ($existing) {
            $this->questionModel->update((int) $existing['id'], $data);
        } else {
            $this->questionModel->create($data);
        }
    }

    private function syncTrendyol(int $mpId): int
    {
        $this->trendyolService->loadCredentials();
        $questions = $this->trendyolService->getQuestions();
        $synced = 0;
        foreach ($questions as $q) {
            $this->upsert($mpId, (string) $q['id'], [
                'marketplace_id'          => $mpId,
                'marketplace_question_id' => (string) $q['id'],
                'marketplace_product_id'  => (string) ($q['productId'] ?? ''),
                'product_name'            => $q['productName'] ?? null,
                'product_image_url'       => $q['productImage'] ?? null,
                'question_text'           => $q['text'] ?? '',
                'question_date'           => isset($q['creationDate'])
                    ? date('Y-m-d H:i:s', (int) ($q['creationDate'] / 1000)) : date('Y-m-d H:i:s'),
                'customer_name'           => $q['customerFirstName'] ?? null,
                'status'                  => !empty($q['answers']) ? 'answered' : 'waiting',
                'answer_text'             => !empty($q['answers']) ? ($q['answers'][0]['text'] ?? null) : null,
                'answer_date'             => !empty($q['answers']) && isset($q['answers'][0]['creationDate'])
                    ? date('Y-m-d H:i:s', (int) ($q['answers'][0]['creationDate'] / 1000)) : null,
                'synced_at'               => date('Y-m-d H:i:s'),
            ]);
            $synced++;
        }
        return $synced;
    }

    private function syncIdefix(int $mpId): int
    {
        $this->idefixService->loadCredentials();
        $questions = $this->idefixService->fetchQuestions();
        $synced = 0;
        foreach ($questions as $q) {
            $qId = (string) ($q['id'] ?? '');
            if ($qId === '') continue;
            $hasAnswer = !empty($q['productQuestionAnswer']);
            $answerText = $hasAnswer ? ($q['productQuestionAnswer'][0]['answerBody'] ?? null) : null;
            $questionDate = $q['createdAt'] ?? null;
            if (is_string($questionDate)) {
                $ts = strtotime($questionDate);
                $questionDate = $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
            } else {
                $questionDate = date('Y-m-d H:i:s');
            }
            $this->upsert($mpId, $qId, [
                'marketplace_id'          => $mpId,
                'marketplace_question_id' => $qId,
                'marketplace_product_id'  => '',
                'product_name'            => $q['product'] ?? null,
                'product_image_url'       => null,
                'question_text'           => $q['question'] ?? '',
                'question_date'           => $questionDate,
                'customer_name'           => $q['customerName'] ?? null,
                'status'                  => $hasAnswer ? 'answered' : 'waiting',
                'answer_text'             => $answerText,
                'answer_date'             => $hasAnswer && isset($q['readAt'])
                    ? date('Y-m-d H:i:s', strtotime($q['readAt'])) : null,
                'synced_at'               => date('Y-m-d H:i:s'),
            ]);
            $synced++;
        }
        return $synced;
    }

    private function syncPazarama(int $mpId): int
    {
        $this->pazaramaService->loadCredentials();
        $synced = 0;
        foreach ([0, 1] as $statusFilter) {
            $page = 1;
            $pageSize = 100;
            do {
                $filters = ['page' => $page, 'page_size' => $pageSize];
                if ($statusFilter > 0) $filters['status'] = $statusFilter;
                $questions = $this->pazaramaService->fetchQuestions($filters);
                if (empty($questions)) break;
                foreach ($questions as $q) {
                    $qId = (string) ($q['questionId'] ?? $q['id'] ?? '');
                    if ($qId === '') continue;
                    $answerText = $q['answer'] ?? $q['answerText'] ?? null;
                    $hasAnswer = !empty($answerText);
                    $questionDate = $q['questionDate'] ?? $q['createdDate'] ?? null;
                    if (is_string($questionDate)) { $ts = strtotime($questionDate); $questionDate = $ts !== false ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s'); }
                    else { $questionDate = date('Y-m-d H:i:s'); }
                    $answerDate = $q['answerDate'] ?? $q['answerCreatedDate'] ?? null;
                    if (is_string($answerDate) && $answerDate !== '') { $ts = strtotime($answerDate); $answerDate = $ts !== false ? date('Y-m-d H:i:s', $ts) : null; }
                    else { $answerDate = null; }
                    $this->upsert($mpId, $qId, [
                        'marketplace_id' => $mpId, 'marketplace_question_id' => $qId,
                        'marketplace_product_id' => (string) ($q['productId'] ?? $q['productCode'] ?? ''),
                        'product_name' => $q['productName'] ?? $q['productTitle'] ?? null,
                        'product_image_url' => $q['productImage'] ?? $q['productImageUrl'] ?? null,
                        'question_text' => $q['questionText'] ?? $q['question'] ?? $q['text'] ?? '',
                        'question_date' => $questionDate, 'customer_name' => $q['customerName'] ?? $q['askerName'] ?? null,
                        'status' => $hasAnswer ? 'answered' : 'waiting',
                        'answer_text' => $hasAnswer ? $answerText : null,
                        'answer_date' => $hasAnswer ? $answerDate : null,
                        'synced_at' => date('Y-m-d H:i:s'),
                    ]);
                    $synced++;
                }
                $page++;
            } while (count($questions) >= $pageSize && $page <= 50);
        }
        return $synced;
    }

    /**
     * Hepsiburada Satıcıya Sor: durumlar (WaitingForAnswer, Answered, Rejected, AutoClosed) servis
     * içinde sayfalı çekilir; marketplace_question_id = issueNumber (cevaplama ucu da bunu kullanır).
     * Tam taramada (elle senkron) tüm sayfalar; periyodik senkronda bekleyen sorular tam, kapanmış
     * durumlar yalnız en yeni sayfalar. Satır eşlemesi servisteki ortak normalizeQuestion'dan gelir.
     * API hatası yukarı fırlar. Panelden verilen cevap HB'de henüz görünmüyorsa (gecikme) yerel
     * cevap/durum ezilmez. Eski biçimli (soru numarasız) aynı metinli satır yeni numaraya taşınır.
     */
    private function syncHepsiburada(int $mpId, bool $fullScan = true): int
    {
        $synced = 0;
        foreach ($this->fetchHepsiburadaQuestions($fullScan) as $raw) {
            if (!is_array($raw)) {
                continue;
            }

            $row = $this->hepsiburadaService->normalizeQuestion($raw);
            if ($row === null) {
                continue;
            }

            $row['marketplace_id'] = $mpId;
            $row['synced_at']      = date('Y-m-d H:i:s');

            $existing = $this->questionModel->findByMarketplaceQuestionId($mpId, (string) $row['marketplace_question_id'])
                ?: $this->questionModel->findLegacyHepsiburadaMatch($mpId, (string) ($row['question_text'] ?? ''));
            if ($existing) {
                if (($row['answer_text'] ?? null) === null && !empty($existing['answer_text'])) {
                    unset($row['answer_text'], $row['answer_date'], $row['status']);
                }
                $this->questionModel->update((int) $existing['id'], $row);
            } else {
                $this->questionModel->create($row);
            }
            $synced++;
        }
        return $synced;
    }

    /**
     * @return list<array<string, mixed>> issueNumber ile tekilleştirilmiş ham HB soruları
     */
    private function fetchHepsiburadaQuestions(bool $fullScan): array
    {
        if ($fullScan) {
            return $this->hepsiburadaService->fetchQuestions();
        }

        $questions = [];
        foreach (HepsiburadaService::QUESTION_STATUSES as $status) {
            $filters = ['status' => $status];
            if ($status !== 'WaitingForAnswer') {
                $filters['max_pages'] = self::HB_PERIODIC_CLOSED_PAGES;
            }
            foreach ($this->hepsiburadaService->fetchQuestions($filters) as $raw) {
                $issueNumber = is_array($raw) ? (string) ($raw['issueNumber'] ?? '') : '';
                if ($issueNumber !== '') {
                    $questions[$issueNumber] = $raw;
                }
            }
        }
        return array_values($questions);
    }
}
