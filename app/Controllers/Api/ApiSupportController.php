<?php
/**
 * Trendmax — Destek paneli köprüsü (trendmaxdestek.com)
 *
 * Mağazaya bağlı API anahtarıyla (X-API-Key + X-API-Secret) çalışır. Destek paneli müşteri
 * sitesinin veritabanı bilgisini hiç tutmaz; yalnız bu uçların döndürdüğü özetleri görür.
 *   support:read      → durum, hata ve zorunlu özellik özeti, eşleştirme şablonu dışa aktarımı
 *   support:mappings  → eşleştirme şablonunu bu siteye uygulama (yalnız açıkça verilirse; "*" yetmez)
 * Yenileme isteği (attribute-issues/refresh) support:read ile çalışır: veri değiştirmez, yalnız
 * sitenin kendi cron'unun raporu erken hesaplamasını ister.
 */
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Request;
use App\Services\Catalog\AttributeIssueReport;
use App\Services\Support\{AttributeTemplateService,SupportStatusService};

final class ApiSupportController extends ApiBaseController
{
    /** GET /api/v1/support/status */
    public function status(Request $request): void
    {
        $this->requirePermission('support:read');
        $this->apiSuccess($this->container->get(SupportStatusService::class)->snapshot());
    }

    /** GET /api/v1/support/attribute-issues?limit=500 */
    public function attributeIssues(Request $request): void
    {
        $this->requirePermission('support:read');
        $report = $this->container->get(AttributeIssueReport::class);
        try {
            $summary = $report->summary();
            $state = (array)($summary['state'] ?? []); unset($summary['state']);
            $this->apiSuccess(['summary' => $summary + ['refreshed_at' => $state['refreshed_at'] ?? null, 'refreshing' => !empty($state['cursor_active'])],
                'items' => $report->rows(max(1, min(2000, $request->integer('limit', 500))))]);
        } catch (\Throwable $e) {
            $this->apiError('Zorunlu özellik raporu okunamadı: '.AttributeIssueReport::publicError($e), 'REPORT_UNAVAILABLE', 503);
        }
    }

    /** POST /api/v1/support/attribute-issues/refresh — asks the site's cron to recount soon. */
    public function refreshAttributeIssues(Request $request): void
    {
        $this->requirePermission('support:read');
        $report = $this->container->get(AttributeIssueReport::class);
        if ($report->marketplace() === null) $this->apiError('Trendyol bu sitede aktif değil.', 'MARKETPLACE_INACTIVE', 409);
        $report->request();
        $this->apiSuccess(['requested' => true], 'Yenileme sıraya alındı; sitenin cron görevi birkaç dakika içinde hesaplar.');
    }

    /** GET /api/v1/support/attribute-mappings — portable templates of this site's category attribute choices. */
    public function exportMappings(Request $request): void
    {
        $this->requirePermission('support:read');
        try { $this->apiSuccess($this->container->get(AttributeTemplateService::class)->export()); }
        catch (\RuntimeException $e) { $this->apiError($e->getMessage(), 'MARKETPLACE_INACTIVE', 409); }
    }

    /**
     * POST /api/v1/support/attribute-mappings
     * Body: {"format":"trendmax.trendyol-attribute-template.v1","mode":"fill|replace","dry_run":true,"templates":[...]}
     * 'fill' only completes empty choices; 'replace' overwrites the attributes the template carries.
     */
    public function importMappings(Request $request): void
    {
        // Writing another site's choices needs an explicit grant; a legacy "*" or "support:*" token is not enough.
        $granted = json_decode((string)($this->apiToken()['permissions'] ?? '[]'), true);
        if (!is_array($granted) || !in_array('support:mappings', $granted, true)) $this->apiError('Bu işlem için anahtara açıkça support:mappings izni verilmeli.', 'FORBIDDEN', 403);
        $body = $this->jsonBody($request);
        if (($body['format'] ?? '') !== AttributeTemplateService::FORMAT) $this->apiValidationError(['format' => AttributeTemplateService::FORMAT.' bekleniyor']);
        if (!isset($body['templates']) || !is_array($body['templates']) || !array_is_list($body['templates'])) $this->apiValidationError(['templates' => 'templates dizisi zorunlu']);
        // Safe default: preview. Only a real boolean (or "true"/"false"/1/0) is accepted.
        $dryRun = array_key_exists('dry_run', $body) ? filter_var($body['dry_run'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) : true;
        if ($dryRun === null) $this->apiValidationError(['dry_run' => 'true veya false olmalı']);
        try {
            $result = $this->container->get(AttributeTemplateService::class)->import($body['templates'], (string)($body['mode'] ?? 'fill'), $dryRun);
        } catch (\InvalidArgumentException $e) { $this->apiValidationError(['body' => $e->getMessage()]); }
        catch (\RuntimeException $e) { $this->apiError($e->getMessage(), 'MARKETPLACE_INACTIVE', 409); }
        if (!$dryRun && $result['applied'] !== []) {
            try { $this->container->get(AttributeIssueReport::class)->request(); } catch (\Throwable) {}
        }
        $this->apiSuccess($result, $dryRun ? 'Önizleme: hiçbir değişiklik yazılmadı.' : 'Şablon uygulandı.');
    }
}
