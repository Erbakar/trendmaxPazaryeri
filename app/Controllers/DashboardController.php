<?php
/**
 * OzyConnect Panel — DashboardController
 *
 * @package    OzyConnect
 * @author     OziyWeb <oziyweb@hotmail.com>
 * @copyright  © 2025 OziyWeb. Tüm Hakları Saklıdır.
 * @license    Proprietary
 * @version    1.0.0
 * @since      2025-01-01
 * @link       https://www.oziyweb.com.tr
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\AppUpdate;
use App\Models\Invoice;
use App\Models\Marketplace;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReturnRequest;
use App\Models\SyncLog;

final class DashboardController extends BaseController
{
    private readonly Order $orderModel;
    private readonly Product $productModel;
    private readonly Invoice $invoiceModel;
    private readonly Marketplace $marketplaceModel;
    private readonly ReturnRequest $returnModel;
    private readonly SyncLog $syncLogModel;
    private readonly AppUpdate $appUpdate;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->orderModel   = $container->get(Order::class);
        $this->productModel = $container->get(Product::class);
        $this->invoiceModel = $container->get(Invoice::class);
        $this->marketplaceModel = $container->get(Marketplace::class);
        $this->returnModel  = $container->get(ReturnRequest::class);
        $this->syncLogModel = $container->get(SyncLog::class);
        $this->appUpdate    = $container->get(AppUpdate::class);
    }

    public function index(Request $request): void
    {
        $todayStats = $this->orderModel->getTodayStats();
        $weekStats  = $this->orderModel->getWeekStats();
        $stockSummary = $this->productModel->getStockSummary();
        $dailyChart = $this->orderModel->getDailyChart(7);
        $marketplaceStats = $this->orderModel->getMarketplaceStats();
        $recentOrders = $this->orderModel->getRecent(10);
        $lowStockProducts = $this->productModel->getLowStock(10);
        $pendingReturns = $this->returnModel->getPendingCount();
        $pendingInvoices = $this->orderModel->getPendingInvoiceCount();
        $lastSyncs = $this->syncLogModel->getLastSuccessful();
        $appUpdates = $this->appUpdate->published(5);
        $marketplaces = $this->marketplaceModel->getActive();

        $this->view('dashboard.index', [
            'pageTitle'         => 'Dashboard',
            'appUpdates'        => $appUpdates,
            'todayStats'        => $todayStats,
            'weekStats'         => $weekStats,
            'stockSummary'      => $stockSummary,
            'dailyChart'        => $dailyChart,
            'marketplaceStats'  => $marketplaceStats,
            'marketplaces'      => $marketplaces,
            'recentOrders'      => $recentOrders,
            'lowStockProducts'  => $lowStockProducts,
            'pendingReturns'    => $pendingReturns,
            'pendingInvoices'   => $pendingInvoices,
            'lastSyncs'         => $lastSyncs,
        ]);
    }

    /**
     * Dashboard AJAX veri yenileme
     */
    public function stats(Request $request): void
    {
        $this->success([
            'today'       => $this->orderModel->getTodayStats(),
            'week'        => $this->orderModel->getWeekStats(),
            'stock'       => $this->productModel->getStockSummary(),
            'dailyChart'  => $this->orderModel->getDailyChart(7),
            'marketplace' => $this->orderModel->getMarketplaceStats(),
        ]);
    }
}
