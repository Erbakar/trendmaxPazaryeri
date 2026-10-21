<?php
/**
 * OzyConnect — StoreDocumentController (MERCHANT: KYC belge yükleme & durum)
 *
 * Tenant-scoped: mağaza yalnız KENDİ belgelerini görür/yükler/indirir. Dosyalar webroot DIŞI
 * storage/'da; indirme sahiplik kontrolünden geçer. AuthMiddleware arkasında.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\StoreDocument;
use App\Models\StoreDocumentType;
use App\Services\StoreDocumentService;

final class StoreDocumentController extends BaseController
{
    private readonly StoreDocument $docs;
    private readonly StoreDocumentType $types;
    private readonly StoreDocumentService $service;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->docs    = $container->get(StoreDocument::class);
        $this->types   = $container->get(StoreDocumentType::class);
        $this->service = $container->get(StoreDocumentService::class);
    }

    public function index(): void
    {
        $this->view('documents.index', [
            'pageTitle' => 'Belgeler & Doğrulama',
            'types'     => $this->types->active(),
            'docs'      => $this->docs->forCurrentStore(),
            'summary'   => $this->service->summaryForCurrentStore(),
        ]);
    }

    public function upload(Request $request): void
    {
        $typeId = (int) $request->post('doc_type_id', 0);
        $file   = $request->file('document');
        if ($typeId <= 0 || !is_array($file)) {
            $this->redirectWithError('/documents', 'Belge türü ve dosya zorunludur.');
        }
        try {
            $this->service->upload($typeId, $file, (int) $this->session->userId() ?: null);
        } catch (\RuntimeException $e) {
            $this->redirectWithError('/documents', $e->getMessage());
        }
        $this->redirectWithSuccess('/documents', 'Belge yüklendi. İncelemeye alındı.');
    }

    public function download(Request $request, string $id): void
    {
        $doc = $this->docs->findOwned((int) $id);   // sahiplik kontrolü (tenant)
        if ($doc === false) {
            $this->response->notFound('Belge bulunamadı.');
        }
        $abs = $this->service->absolutePath((string) $doc['file_path']);
        if ($abs === null) {
            $this->response->notFound('Dosya bulunamadı.');
        }
        $name = (string) ($doc['original_name'] ?? '') ?: ('belge-' . $id);
        $this->response->download($abs, $name, (string) ($doc['mime'] ?? 'application/octet-stream'));
    }
}
