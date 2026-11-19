<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\Mapping\CategoryCrosswalkService;

/**
 * Trendyol kategori kararlarını Hepsiburada'ya taşıma ekranı (/categories/crosswalk).
 *
 * Ürünler Trendyol'da kullandıkları kategoriye göre gruplanır; her grup için bir Hepsiburada
 * kategorisi seçilip onaylanır, onaylanan karşılık o ürünlere Hepsiburada eşleştirmesi olarak yazılır.
 */
final class CategoryCrosswalkController extends BaseController
{
    private const SOURCE_SLUG = 'trendyol';
    private const TARGET_SLUG = 'hepsiburada';

    private function authorization(): \App\Services\AuthorizationService
    {
        return $this->container->get(\App\Services\AuthorizationService::class);
    }

    private function service(): CategoryCrosswalkService
    {
        return $this->container->get(CategoryCrosswalkService::class);
    }

    /** @return array{0: int, 1: int} */
    private function pair(): array
    {
        $s = $this->service();
        $src = $s->marketplaceIdBySlug(self::SOURCE_SLUG);
        $tgt = $s->marketplaceIdBySlug(self::TARGET_SLUG);
        if ($src <= 0 || $tgt <= 0) {
            $this->error('Trendyol ya da Hepsiburada pazaryeri kaydı bulunamadı.', 404);
        }
        if (!$this->authorization()->canAccessMarketplace($tgt)) {
            $this->error('Hepsiburada paketinizde bulunmuyor.', 403);
        }

        return [$src, $tgt];
    }

    public function index(Request $request): void
    {
        $this->requireModule('products');
        $s = $this->service();
        $tgt = $s->marketplaceIdBySlug(self::TARGET_SLUG);
        $this->view('categories/crosswalk', [
            'pageTitle'     => "Trendyol'dan Hepsiburada'ya kategori aktarımı",
            'targetId'      => $tgt,
            'canEdit'       => $this->authorization()->hasPermission('categories.edit'),
            'canApply'      => $this->authorization()->hasPermission('categories.edit') && $this->authorization()->hasPermission('products.edit'),
        ]);
    }

    public function groups(Request $request): void
    {
        $this->requireModule('products');
        [$src, $tgt] = $this->pair();
        try {
            @set_time_limit(120);
            $this->success(['groups' => $this->service()->groups($src, $tgt)]);
        } catch (\Throwable $e) {
            $this->error('Gruplar okunamadı: ' . $e->getMessage(), 500);
        }
    }

    public function candidates(Request $request): void
    {
        $this->requireModule('products');
        [$src, $tgt] = $this->pair();
        $limit = max(1, min(20, $request->integer('limit', 12)));
        $this->success(['candidates' => $this->service()->candidates($src, $tgt, (string) $request->query('source', ''), $limit)]);
    }

    public function suggest(Request $request): void
    {
        $this->requireModule('products');
        [$src, $tgt] = $this->pair();
        try {
            @set_time_limit(120);
            $this->success($this->service()->suggest($src, $tgt));
        } catch (\Throwable $e) {
            $this->error('Öneri üretilemedi: ' . $e->getMessage(), 500);
        }
    }

    public function save(Request $request): void
    {
        $this->requireModule('products');
        [$src, $tgt] = $this->pair();
        try {
            $this->success(['target' => $this->service()->setTarget(
                $src,
                $tgt,
                (string) $request->post('source', ''),
                (string) $request->post('target', ''),
                $request->post('confirmed', '1') === '1'
            )], 'Kaydedildi');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->error('Kaydedilemedi: ' . $e->getMessage(), 500);
        }
    }

    public function import(Request $request): void
    {
        $this->requireModule('products');
        [$src, $tgt] = $this->pair();
        $rows = json_decode((string) $request->post('rows', '[]'), true);
        if (!is_array($rows)) {
            $this->error('Geçersiz JSON. Biçim: [{"source":"766","target":"60001234"}]', 422);
        }
        try {
            @set_time_limit(300);
            $r = $this->service()->import($src, $tgt, $rows);
            $this->success($r, $r['imported'] . ' öneri içeri alındı');
        } catch (\Throwable $e) {
            $this->error('İçeri alınamadı: ' . $e->getMessage(), 500);
        }
    }

    public function apply(Request $request): void
    {
        $this->requireModule('products');
        [$src, $tgt] = $this->pair();
        try {
            @set_time_limit(120);
            $this->success($this->service()->apply($src, $tgt, (string) $request->post('source', ''), max(0, $request->integer('after'))));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->error('Uygulanamadı: ' . $e->getMessage(), 500);
        }
    }
}
