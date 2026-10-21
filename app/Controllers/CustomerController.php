<?php
/**
 * OzyConnect Panel — CustomerController
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.1.0
 * @since      2025-01-01
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\Customer;
use App\Models\Marketplace;
use App\Models\Order;

final class CustomerController extends BaseController
{
    private readonly Customer $customerModel;
    private readonly Order $orderModel;
    private readonly Marketplace $marketplaceModel;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->customerModel    = $container->get(Customer::class);
        $this->orderModel       = $container->get(Order::class);
        $this->marketplaceModel = $container->get(Marketplace::class);
    }

    public function index(Request $request): void
    {
        $marketplaces = $this->marketplaceModel->getAll();
        $stats        = $this->customerModel->getOverviewStats();
        $mpCounts     = $this->customerModel->getMarketplaceCounts();

        $this->view('customers.index', [
            'pageTitle'    => 'Müşteriler',
            'marketplaces' => $marketplaces,
            'stats'        => $stats,
            'mpCounts'     => $mpCounts,
        ]);
    }

    /**
     * DataTable server-side AJAX endpoint
     */
    public function ajax(Request $request): void
    {
        $params = $request->all();

        // DataTables serverSide: search[value] array fix
        if (isset($params['search']) && is_array($params['search'])) {
            $params['search']['value'] = is_array($params['search']['value'] ?? '')
                ? ($params['search']['value'][0] ?? '')
                : ($params['search']['value'] ?? '');
        }

        $result = $this->customerModel->getFilteredDT($params);

        // Pazaryeri sayılarını ekle
        $result['mpCounts'] = $this->customerModel->getMarketplaceCounts();

        // Per-marketplace overview stats (overlay header)
        $mpId = trim((string) ($params['marketplace_id'] ?? ''));
        if ($mpId !== '') {
            $result['mpOverviewStats'] = $this->customerModel->getOverviewStats((int) $mpId);
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function show(Request $request, string $id): void
    {
        $customer = $this->customerModel->find((int) $id);
        if ($customer === false) {
            $this->response->notFound('Müşteri bulunamadı');
        }

        $orders = $this->customerModel->getOrders((int) $id);

        $this->view('customers.show', [
            'pageTitle' => $customer['name'],
            'customer'  => $customer,
            'orders'    => $orders,
        ]);
    }

    /**
     * Müşteri bilgilerini güncelle (AJAX)
     */
    public function update(Request $request, string $id): void
    {
        $customer = $this->customerModel->find((int) $id);
        if ($customer === false) {
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Müşteri bulunamadı']);
                exit;
            }
            $this->response->notFound('Müşteri bulunamadı');
        }

        $this->customerModel->update((int) $id, [
            'name'       => $request->post('name', $customer['name']),
            'email'      => $request->post('email', $customer['email']),
            'phone'      => $request->post('phone', $customer['phone']),
            'id_no'      => $request->post('id_no', $customer['id_no']),
            'tax_no'     => $request->post('tax_no', $customer['tax_no']),
            'tax_office' => $request->post('tax_office', $customer['tax_office']),
            'address'    => $request->post('address', $customer['address']),
            'city'       => $request->post('city', $customer['city']),
            'district'   => $request->post('district', $customer['district']),
            'notes'      => $request->post('notes', $customer['notes']),
        ]);

        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => true, 'message' => 'Müşteri bilgileri güncellendi']);
            exit;
        }

        $this->redirectWithSuccess("/customers/{$id}", 'Müşteri bilgileri güncellendi');
    }
}
