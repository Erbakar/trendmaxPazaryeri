<?php
/**
 * OzyConnect — AdminDocumentController (ADMIN: KYC belge inceleme + belge TÜRÜ yönetimi)
 *
 * Belgeleri görüntüle/indir, onayla/reddet; mağaza doğrulamasını tetikler (StoreDocumentService).
 * Belge türü kataloğunu (vergi levhası, banka dekontu vb.) admin yönetir. AdminAuthMiddleware.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\AdminSession;
use App\Core\Container;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\StoreDocument;
use App\Models\StoreDocumentType;
use App\Services\StoreDocumentService;

final class AdminDocumentController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly StoreDocument $docs;
    private readonly StoreDocumentType $types;
    private readonly StoreDocumentService $service;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->adminSession = $container->get(AdminSession::class);
        $this->docs         = $container->get(StoreDocument::class);
        $this->types        = $container->get(StoreDocumentType::class);
        $this->service      = $container->get(StoreDocumentService::class);
    }

    private function render(string $view, array $data = []): void
    {
        $data['_csrf']       = $this->session->csrfToken();
        $data['_flash']      = ['error' => $this->session->getFlash('error'), 'success' => $this->session->getFlash('success')];
        $data['_adminEmail'] = $this->adminSession->adminEmail();
        $this->response->view($view, $data, 'admin');
    }

    public function index(Request $request): void
    {
        $status = (string) $request->query('status', '');
        $this->render('admin.documents_index', [
            'pageTitle' => 'Mağaza Belgeleri',
            'documents' => $this->docs->adminList(null), // tümü gelir; filtre client-side (DataTables)
            'counts'    => $this->docs->adminCounts(),
            'current'   => $status,
        ]);
    }

    public function download(Request $request, string $id): void
    {
        $doc = $this->docs->adminFind((int) $id);
        if ($doc === false) { $this->response->notFound('Belge bulunamadı.'); }
        $abs = $this->service->absolutePath((string) $doc['file_path']);
        if ($abs === null) { $this->response->notFound('Dosya bulunamadı.'); }
        $name = (string) ($doc['original_name'] ?? '') ?: ('belge-' . $id);
        $this->response->download($abs, $name, (string) ($doc['mime'] ?? 'application/octet-stream'));
    }

    public function review(Request $request, string $id): void
    {
        $action = (string) $request->post('action', '');
        $status = $action === 'approve' ? 'approved' : ($action === 'reject' ? 'rejected' : '');
        if ($status === '') {
            $this->session->flash('error', 'Geçersiz işlem.');
            $this->response->redirectTo('/admin/documents');
            return;
        }
        $note = trim((string) $request->post('note', ''));
        try {
            $this->service->review((int) $id, $status, (int) $this->adminSession->adminId() ?: null, $note !== '' ? $note : null);
        } catch (\RuntimeException $e) {
            $this->session->flash('error', $e->getMessage());
            $this->response->redirectTo('/admin/documents');
            return;
        }
        $this->session->flash('success', $status === 'approved' ? 'Belge onaylandı.' : 'Belge reddedildi.');
        $this->response->redirectTo('/admin/documents');
    }

    // ─── Belge türü kataloğu ───────────────────────────────────
    public function types(Request $request): void
    {
        $this->render('admin.document_types', [
            'pageTitle' => 'Belge Türleri',
            'types'     => $this->types->allTypes(),
            'formats'   => StoreDocumentService::formatOptions(),
        ]);
    }

    public function saveType(Request $request): void
    {
        $id    = (int) $request->post('id', 0);
        $label = trim((string) $request->post('label', ''));
        if ($label === '') {
            $this->session->flash('error', 'Belge adı zorunludur.');
            $this->response->redirectTo('/admin/document-types');
            return;
        }
        $required = $request->post('is_required', '0') === '1' ? 1 : 0;
        $active   = $request->post('is_active', '0') === '1' ? 1 : 0;
        $allowed  = (array) $request->post('allowed', []);
        $fields = [
            'label'         => mb_substr($label, 0, 150),
            'description'   => mb_substr(trim((string) $request->post('description', '')), 0, 500) ?: null,
            'is_required'   => $required,
            'is_active'     => $active,
            'sort_order'    => (int) $request->post('sort_order', 0),
            'allowed_types' => implode(',', StoreDocumentService::normalizeKeys(implode(',', $allowed))),
        ];
        if ($id > 0) {
            $existing = $this->types->find($id);
            $this->types->update($id, $fields);
            // Zorunluluk VEYA aktiflik değişince doğrulama seti (is_active=1 AND is_required=1) etkilenir → tazele.
            if ($existing !== false
                && ((int) ($existing['is_required'] ?? 0) !== $required || (int) ($existing['is_active'] ?? 0) !== $active)) {
                $this->service->recomputeAllStores();
            }
            $this->session->flash('success', 'Belge türü güncellendi.');
        } else {
            $fields['key'] = $this->types->makeKey($label);
            $this->types->create($fields);
            // Yeni AKTİF + ZORUNLU tür tüm mağaza doğrulamalarını etkiler.
            if ($required === 1 && $active === 1) { $this->service->recomputeAllStores(); }
            $this->session->flash('success', 'Belge türü eklendi.');
        }
        $this->response->redirectTo('/admin/document-types');
    }

    public function toggleType(Request $request, string $id): void
    {
        $t = $this->types->find((int) $id);
        if ($t !== false) {
            $this->types->update((int) $id, ['is_active' => (int) ($t['is_active'] ?? 0) === 1 ? 0 : 1]);
            // Zorunlu bir türün aktifliği değişince doğrulama seti (requiredActiveIds) değişir → tazele.
            if ((int) ($t['is_required'] ?? 0) === 1) { $this->service->recomputeAllStores(); }
            $this->session->flash('success', 'Belge türü durumu değişti.');
        }
        $this->response->redirectTo('/admin/document-types');
    }

    /** Zorunlu/Opsiyonel aç-kapat. Zorunlu seti değişince tüm mağaza doğrulamaları tazelenir. */
    public function toggleRequired(Request $request, string $id): void
    {
        $t = $this->types->find((int) $id);
        if ($t !== false) {
            $this->types->update((int) $id, ['is_required' => (int) ($t['is_required'] ?? 0) === 1 ? 0 : 1]);
            $this->service->recomputeAllStores();
            $this->session->flash('success', 'Zorunluluk durumu değişti.');
        }
        $this->response->redirectTo('/admin/document-types');
    }

    /** Belge türünü sil. store_documents.doc_type_id FK = ON DELETE SET NULL (belgeler türsüz kalır). */
    public function deleteType(Request $request, string $id): void
    {
        $t = $this->types->find((int) $id);
        if ($t === false) {
            $this->session->flash('error', 'Belge türü bulunamadı.');
            $this->response->redirectTo('/admin/document-types');
            return;
        }
        // Yalnız AKTİF+ZORUNLU tür doğrulama setindedir; pasif/opsiyonel silmek doğrulamayı etkilemez.
        $affectsVerification = (int) ($t['is_required'] ?? 0) === 1 && (int) ($t['is_active'] ?? 0) === 1;
        $this->types->delete((int) $id);
        if ($affectsVerification) { $this->service->recomputeAllStores(); }
        $this->session->flash('success', 'Belge türü silindi.');
        $this->response->redirectTo('/admin/document-types');
    }
}
