<?php
/**
 * OzyConnect Panel — AdminFaqController (Faz 8: SSS — GLOBAL kurumsal site içeriği)
 *
 * SSS CRUD + soft-delete. AdminAuthMiddleware arkasında (Pattern A: BaseController yok, AdminSession self-guard).
 * Global `faqs` tablosu (tenant-scoped DEĞİL); kurumsal sitede activeOrdered ile yayınlanır.
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
use App\Models\Faq;

final class AdminFaqController
{
    private readonly Response $response;
    private readonly Session $session;
    private readonly AdminSession $adminSession;
    private readonly Faq $faqs;

    public function __construct(Container $container)
    {
        $this->response     = $container->get(Response::class);
        $this->session      = $container->get(Session::class);
        $this->adminSession = $container->get(AdminSession::class);
        $this->faqs         = $container->get(Faq::class);
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
        $this->render('admin.faqs_index', [
            'pageTitle' => 'SSS',
            'faqs'      => $this->faqs->allAdmin(),
        ]);
    }

    public function create(Request $request): void
    {
        $this->renderForm(null);
    }

    public function edit(Request $request, string $id): void
    {
        $faq = $this->faqs->findOne((int) $id);
        if ($faq === false) {
            $this->session->flash('error', 'SSS kaydı bulunamadı.');
            $this->response->redirectTo('/admin/faqs');
            return;
        }
        $this->renderForm($faq);
    }

    private function renderForm(?array $faq): void
    {
        $this->render('admin.faq_form', [
            'pageTitle' => $faq !== null ? 'SSS Düzenle' : 'Yeni SSS',
            'faq'       => $faq,
        ]);
    }

    public function store(Request $request): void
    {
        $data = $this->validateInput($request);
        if ($data === null) {
            $this->response->redirectTo('/admin/faqs/create');
            return;
        }
        $this->faqs->createOne($data);
        $this->session->flash('success', 'SSS kaydı oluşturuldu.');
        $this->response->redirectTo('/admin/faqs');
    }

    public function update(Request $request, string $id): void
    {
        $faqId = (int) $id;
        $faq = $this->faqs->findOne($faqId);
        if ($faq === false) {
            $this->session->flash('error', 'SSS kaydı bulunamadı.');
            $this->response->redirectTo('/admin/faqs');
            return;
        }
        $data = $this->validateInput($request);
        if ($data === null) {
            $this->response->redirectTo('/admin/faqs/' . $faqId . '/edit');
            return;
        }
        $this->faqs->updateOne($faqId, $data);
        $this->session->flash('success', 'SSS kaydı güncellendi.');
        $this->response->redirectTo('/admin/faqs');
    }

    public function delete(Request $request, string $id): void
    {
        $faq = $this->faqs->findOne((int) $id);
        if ($faq === false) {
            $this->session->flash('error', 'SSS kaydı bulunamadı.');
        } else {
            $this->faqs->softDelete((int) $id);
            $this->session->flash('success', 'SSS kaydı silindi.');
        }
        $this->response->redirectTo('/admin/faqs');
    }

    /** @return array{category:string,question:string,answer:string,sort_order:int,is_active:int}|null */
    private function validateInput(Request $request): ?array
    {
        $question = trim((string) $request->post('question', ''));
        $answer   = trim((string) $request->post('answer', ''));
        if ($question === '' || $answer === '') {
            $this->session->flash('error', 'Soru ve cevap zorunludur.');
            return null;
        }
        $category = trim((string) $request->post('category', ''));
        if ($category === '') {
            $category = 'genel';
        }
        return [
            'category'   => mb_substr($category, 0, 80),
            'question'   => mb_substr($question, 0, 255),
            'answer'     => mb_substr($answer, 0, 20000),
            'sort_order' => max(0, (int) $request->post('sort_order', 0)),
            'is_active'  => $request->post('is_active', '0') === '1' ? 1 : 0,
        ];
    }
}
