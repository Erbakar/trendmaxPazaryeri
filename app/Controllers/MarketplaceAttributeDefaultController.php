<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Services\Mapping\MarketplaceAttributeDefaultService;

/**
 * Hepsiburada gönderiminde eksik kalanlar (/categories/hb-gaps): zorunlu özellik için HB kategorisi
 * başına varsayılan değer, hiç kategorisi olmayan panel kategorileri için HB eşleştirmesi.
 */
final class MarketplaceAttributeDefaultController extends BaseController
{
    private const TARGET_SLUG = 'hepsiburada';

    private function service(): MarketplaceAttributeDefaultService
    {
        return $this->container->get(MarketplaceAttributeDefaultService::class);
    }

    private function target(): int
    {
        $row = $this->container->get(\App\Core\Database::class)
            ->fetchOne('SELECT id FROM marketplaces WHERE slug = ? LIMIT 1', [self::TARGET_SLUG]);
        $id = $row !== false ? (int) $row['id'] : 0;
        if ($id <= 0) {
            $this->error('Hepsiburada pazaryeri kaydı bulunamadı.', 404);
        }
        if (!$this->container->get(\App\Services\AuthorizationService::class)->canAccessMarketplace($id)) {
            $this->error('Hepsiburada paketinizde bulunmuyor.', 403);
        }

        return $id;
    }

    public function index(Request $request): void
    {
        $this->requireModule('products');
        $auth = $this->container->get(\App\Services\AuthorizationService::class);
        $this->view('categories/hb_gaps', [
            'pageTitle' => 'Hepsiburada eksik özellikler',
            'targetId'  => $this->target(),
            'canEdit'   => $auth->hasPermission('categories.edit'),
        ]);
    }

    public function gaps(Request $request): void
    {
        $this->requireModule('products');
        $tgt = $this->target();
        try {
            @set_time_limit(120);
            $this->success($this->service()->gaps($tgt));
        } catch (\Throwable $e) {
            $this->error('Eksikler okunamadı: ' . $e->getMessage(), 500);
        }
    }

    public function values(Request $request): void
    {
        $this->requireModule('products');
        $this->target();
        try {
            $this->success($this->service()->values((string) $request->query('category', ''), (string) $request->query('attribute', '')));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->error('Değerler okunamadı: ' . $e->getMessage(), 500);
        }
    }

    public function save(Request $request): void
    {
        $this->requireModule('products');
        $tgt = $this->target();
        try {
            $value = $this->service()->save(
                $tgt,
                (string) $request->post('category', ''),
                (string) $request->post('attribute', ''),
                (string) $request->post('value', '')
            );
            $this->success(['value' => $value], $value === '' ? 'Varsayılan silindi' : 'Kaydedildi');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->error('Kaydedilemedi: ' . $e->getMessage(), 500);
        }
    }

    public function mapCategory(Request $request): void
    {
        $this->requireModule('products');
        $tgt = $this->target();
        try {
            $this->success($this->service()->mapPanelCategory($tgt, $request->integer('category_id'), (string) $request->post('target', '')), 'Eşleştirildi');
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            $this->error('Eşleştirilemedi: ' . $e->getMessage(), 500);
        }
    }
}
