<?php
/**
 * OzyConnect Panel — FinanceController
 */
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Request;
use App\Models\FinancialTransaction;
use App\Models\Marketplace;
use App\Models\Order;
use App\Services\Marketplace\TrendyolService;
use App\Services\Marketplace\HepsiburadaService;

final class FinanceController extends BaseController
{
    private readonly FinancialTransaction $financeModel;
    private readonly Marketplace $marketplaceModel;
    private readonly Order $orderModel;

    public function __construct(Container $container)
    {
        parent::__construct($container);
        $this->financeModel     = $container->get(FinancialTransaction::class);
        $this->marketplaceModel = $container->get(Marketplace::class);
        $this->orderModel       = $container->get(Order::class);
    }

    public function index(Request $request): void
    {
        $filters = [
            'marketplace_id'   => $request->query('marketplace_id', ''),
            'transaction_type' => $request->query('type', ''),
            'date_from'        => $request->query('from', date('Y-m-d', strtotime('-30 days'))),
            'date_to'          => $request->query('to', date('Y-m-d')),
        ];

        $page = max(1, (int) $request->query('page', '1'));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $transactions = $this->financeModel->getFiltered($filters, $perPage, $offset);
        $totalCount = $this->financeModel->countFiltered($filters);

        // Birleşik özet: brüt/satış/iade/adet SİPARİŞLERDEN (tüm pazaryerleri, finans API'si olmasa da),
        // komisyon/stopaj/hakediş finans API'sinden (Trendyol/HB vb. bağlıysa). Net = satış - komisyon - stopaj.
        $finSum     = $this->financeModel->getSummary($filters);
        $orderSum   = $this->orderModel->getFinanceSummary($filters);
        $commission = (float) ($finSum['total_commission'] ?? 0);
        $stoppage   = (float) ($finSum['total_stoppage'] ?? 0);
        $summary = [
            'total_gross'      => $orderSum['gross'],
            'total_sales'      => $orderSum['sales'],
            'total_refunds'    => $orderSum['returns_amount'],
            'total_commission' => $commission,
            'total_stoppage'   => $stoppage,
            'total_net'        => $orderSum['sales'] - $commission - $stoppage,
            'total_payments'   => (float) ($finSum['total_payments'] ?? 0),
            'total_count'      => $orderSum['order_count'],
        ];
        $breakdown = $this->financeModel->getMarketplaceBreakdown($filters);
        $marketplaces = $this->marketplaceModel->getAll();

        $this->view('finance.index', [
            'pageTitle'      => 'Finans',
            'transactions'   => $transactions,
            'summary'        => $summary,
            'breakdown'      => $breakdown,
            'marketplaces'   => $marketplaces,
            'filters'        => $filters,
            'totalCount'     => $totalCount,
            'currentPage'    => $page,
            'totalPages'     => max(1, (int) ceil($totalCount / $perPage)),
        ]);
    }

    /**
     * AJAX endpoint — Pazaryeri popup'ı için işlem verilerini JSON olarak döner
     */
    public function ajax(Request $request): void
    {
        $mpId = $request->query('marketplace_id', '');
        $type = $request->query('type', '');
        $from = $request->query('from', date('Y-m-01'));
        $to   = $request->query('to', date('Y-m-d'));
        $page = max(1, (int) $request->query('page', '1'));
        $perPage = 50;
        $offset = ($page - 1) * $perPage;

        $filters = [
            'marketplace_id'   => $mpId,
            'transaction_type' => $type,
            'date_from'        => $from,
            'date_to'          => $to,
        ];

        $transactions = $this->financeModel->getFiltered($filters, $perPage, $offset);
        $totalCount   = $this->financeModel->countFiltered($filters);
        $summary      = $this->financeModel->getSummary($filters);
        $totalPages   = max(1, (int) ceil($totalCount / $perPage));

        $this->success([
            'transactions' => $transactions,
            'summary'      => $summary,
            'totalCount'   => $totalCount,
            'currentPage'  => $page,
            'totalPages'   => $totalPages,
        ]);
    }

    /**
     * Tek gün sync — AJAX ile frontend günlük çağırıyor
     */
    public function syncDay(Request $request): void
    {
        // Gün başına ~9 pazaryeri API çağrısı yapılıyor; varsayılan süre sınırı
        // yetmediğinde istek ölümcül hatayla düşüp tarayıcıya HTML 500 dönüyordu.
        @set_time_limit(0);
        @ignore_user_abort(true);

        try {
            $dayOffset = (int) $request->post('day', '0'); // 0=bugün, 1=dün, ...
            $slug = $request->post('marketplace', 'trendyol');

            // Desteklenen pazaryerleri kontrolü
            if (!in_array($slug, ['trendyol', 'hepsiburada'], true)) {
                $this->error("'{$slug}' pazaryeri için finansal API desteği bulunmuyor");
                return;
            }

            $marketplace = $this->marketplaceModel->findBySlug($slug);

            if (!$marketplace) {
                $this->error("'{$slug}' pazaryeri bulunamadı");
                return;
            }

            $marketplaceId = (int) $marketplace['id'];
            $now = time();
            $created = 0;
            $skipped = 0;
            $settlementOk = false;

            if ($slug === 'hepsiburada') {
                // HB: fetchTransactions ile gün bazlı çek
                $dayStartStr = date('Y-m-d', $now - ($dayOffset + 1) * 86400);
                $dayEndStr   = date('Y-m-d', $now - $dayOffset * 86400);
                $dateLabel   = date('d.m.Y', $now - ($dayOffset + 1) * 86400);

                $hb = $this->container->get(HepsiburadaService::class);

                try {
                    $offset = 0;
                    $limit = 100;

                    do {
                        $response = $hb->fetchTransactions([
                            'begin_date' => $dayStartStr,
                            'end_date'   => $dayEndStr,
                            'offset'     => $offset,
                            'limit'      => $limit,
                        ]);

                        $items = $response['data'] ?? $response['items'] ?? $response['content'] ?? [];
                        if (!is_array($items)) $items = [];

                        foreach ($items as $item) {
                            $externalId = (string) ($item['id'] ?? $item['transactionId'] ?? md5(json_encode($item)));
                            if ($this->financeModel->existsByExternalId($marketplaceId, $externalId)) {
                                $skipped++;
                                continue;
                            }

                            $gross = (float) ($item['amount'] ?? $item['transactionAmount'] ?? 0);
                            $rawType = (string) ($item['transactionType'] ?? $item['type'] ?? 'other');
                            $detectedType = match(true) {
                                str_contains($rawType, 'Sale') || str_contains($rawType, 'Payment') => 'sale',
                                str_contains($rawType, 'Commission') => 'commission',
                                str_contains($rawType, 'Return') || str_contains($rawType, 'Refund') => 'refund',
                                str_contains($rawType, 'Transfer') => 'wire_transfer',
                                str_contains($rawType, 'Shipping') || str_contains($rawType, 'Cargo') => 'shipping',
                                default => 'other',
                            };
                            $net = in_array($detectedType, ['refund', 'commission', 'shipping']) ? -abs($gross) : $gross;

                            $this->financeModel->create([
                                'marketplace_id'    => $marketplaceId,
                                'transaction_type'  => $detectedType,
                                'transaction_date'  => $item['transactionDate'] ?? $item['createdDate'] ?? $dayStartStr,
                                'order_number'      => (string) ($item['orderNumber'] ?? $item['orderId'] ?? ''),
                                'description'       => $item['description'] ?? $rawType,
                                'gross_amount'      => abs($gross),
                                'commission_amount' => abs((float) ($item['commissionAmount'] ?? $item['commission'] ?? 0)),
                                'net_amount'        => $net,
                                'currency'          => 'TRY',
                                'status'            => 'completed',
                                'external_id'       => $externalId,
                                'extra_data_json'   => json_encode($item),
                                'synced_at'         => date('Y-m-d H:i:s'),
                            ]);
                            $created++;
                        }

                        $offset += $limit;
                    } while (count($items) === $limit);
                } catch (\Throwable $e) {
                    $this->logger->error('Hepsiburada finans sync hatası (day=' . $dayOffset . '): ' . $e->getMessage(), 'Finance');
                    $this->error('Hepsiburada finans sync hatası: ' . $e->getMessage());
                    return;
                }

                $this->success([
                    'day'     => $dayOffset,
                    'date'    => $dateLabel,
                    'created' => $created,
                    'skipped' => $skipped,
                ], "{$dateLabel}: {$created} yeni, {$skipped} mevcut");
                return;
            }

            // ── Trendyol finans ──
            $trendyol = $this->container->get(TrendyolService::class);

            $dayStart = ($now - ($dayOffset + 1) * 86400) * 1000;
            $dayEnd   = ($now - $dayOffset * 86400) * 1000;
            $dateLabel = date('d.m.Y', $now - ($dayOffset + 1) * 86400);

            // 1) Settlements (opsiyonel — Trendyol bazı mağazalarda 500 veriyor)
            try {
                $page = 0;
                do {
                    $result = $trendyol->fetchSettlements((int)$dayStart, (int)$dayEnd, $page, 100);
                    $items = $result['content'] ?? [];
                    $settlementOk = true;

                    foreach ($items as $item) {
                        $normalized = $trendyol->normalizeSettlement($item);
                        $externalId = $normalized['external_id'];

                        if ($this->financeModel->existsByExternalId($marketplaceId, $externalId)) {
                            $skipped++;
                            continue;
                        }

                        $normalized['marketplace_id'] = $marketplaceId;
                        $normalized['synced_at'] = date('Y-m-d H:i:s');
                        $this->financeModel->create($normalized);
                        $created++;
                    }

                    $page++;
                } while ($page < ($result['totalPages'] ?? 1));
            } catch (\Throwable $e) {
                // Settlements endpoint'i bu mağaza için kapalı/500 dönüyor olabilir; otherFinancials akışı sürer.
                // Sessiz yutmak yerine logla — para akışında gizli boşluğa dönüşmesin.
                $settlementOk = false;
                $this->logger->warning('Trendyol settlements çekilemedi (day=' . $dayOffset . '): ' . $e->getMessage(), 'Finance');
            }

            // 2) OtherFinancials — tüm finansal türler
            try {
                $types = [
                    'PaymentOrder'               => 'payment',
                    'Stoppage'                   => 'stoppage',
                    'WireTransfer'               => 'wire_transfer',
                    'IncomingTransfer'           => 'wire_transfer',
                    'CashAdvance'                => 'payment',
                    'CommissionAgreementInvoice'  => 'commission',
                    'ReturnInvoice'              => 'refund',
                    'DeductionInvoices'          => 'other',
                ];

                foreach ($types as $txType => $localType) {
                    try {
                        $otherItems = $trendyol->fetchOtherFinancials((int)$dayStart, (int)$dayEnd, $txType);
                        if (!is_array($otherItems)) continue;

                        foreach ($otherItems as $item) {
                            $externalId = (string)($item['id'] ?? md5($txType . json_encode($item)));

                            if ($this->financeModel->existsByExternalId($marketplaceId, $externalId)) {
                                $skipped++;
                                continue;
                            }

                            // Trendyol debt/credit alanları kullanıyor
                            $debt   = (float)($item['debt'] ?? 0);
                            $credit = (float)($item['credit'] ?? 0);
                            $commAmt = (float)($item['commissionAmount'] ?? 0);
                            $gross  = $debt > 0 ? $debt : $credit;

                            // Türkçe transactionType'a göre yerel tür belirle
                            $rawType = $item['transactionType'] ?? $txType;
                            $detectedType = match(true) {
                                str_contains($rawType, 'deme') || str_contains($rawType, 'Payment') => 'payment',
                                str_contains($rawType, 'Stopaj') || str_contains($rawType, 'Stoppage') => 'stoppage',
                                str_contains($rawType, 'Kargo') || str_contains($rawType, 'Cargo') => 'other',
                                str_contains($rawType, 'Komisyon') || str_contains($rawType, 'Commission') => 'commission',
                                str_contains($rawType, 'Platform') => 'other',
                                str_contains($rawType, 'Return') || str_contains($rawType, 'ade') => 'refund',
                                str_contains($rawType, 'Transfer') || str_contains($rawType, 'Virman') => 'wire_transfer',
                                default => $localType,
                            };

                            // Ödeme/payment → size gelen para (pozitif), diğerleri gider (negatif)
                            $net = ($detectedType === 'payment') ? $gross : -$gross;

                            $this->financeModel->create([
                                'marketplace_id'    => $marketplaceId,
                                'transaction_type'  => $detectedType,
                                'transaction_date'  => isset($item['transactionDate'])
                                    ? date('Y-m-d H:i:s', (int)($item['transactionDate'] / 1000))
                                    : date('Y-m-d H:i:s'),
                                'order_number'      => (string)($item['orderNumber'] ?? ''),
                                'description'       => $item['description'] ?? $rawType,
                                'gross_amount'      => $gross,
                                'commission_amount' => abs($commAmt),
                                'net_amount'        => $net,
                                'currency'          => 'TRY',
                                'status'            => 'completed',
                                'external_id'       => $externalId,
                                'extra_data_json'   => json_encode($item),
                                'synced_at'         => date('Y-m-d H:i:s'),
                            ]);
                            $created++;
                        }
                    } catch (\Throwable $e) {
                        $this->logger->warning('Trendyol otherFinancials ' . $txType . ' çekilemedi (day=' . $dayOffset . '): ' . $e->getMessage(), 'Finance');
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error('Trendyol otherFinancials dış-blok hatası (day=' . $dayOffset . '): ' . $e->getMessage(), 'Finance');
            }

            $this->success([
                'day'          => $dayOffset,
                'date'         => $dateLabel,
                'created'      => $created,
                'skipped'      => $skipped,
                'settlementOk' => $settlementOk,
            ], "{$dateLabel}: {$created} yeni, {$skipped} mevcut");

        } catch (\Throwable $e) {
            $this->error('Sync hatası: ' . $e->getMessage());
        }
    }
}
