<?php
declare(strict_types=1);
namespace App\Services\Marketplace;

use Closure;
use RuntimeException;

/** Read-only GraphQL return retrieval. All connections are cursor-paginated. */
final class ShopifyReturnReader
{
    public function __construct(private readonly Closure $request) {}

    private function query(string $query, array $variables): array
    {
        $response = ($this->request)(['query' => $query, 'variables' => $variables]);
        if (!empty($response['errors']) || !is_array($response['data'] ?? null)) {
            throw new RuntimeException('Shopify iade sorgusu başarısız. read_orders ve read_returns yetkilerini kontrol edin.');
        }
        return $response['data'];
    }

    private function pages(string $query, array $variables, Closure $extract): \Generator
    {
        $cursor = null;
        $seen = [];
        do {
            $data = $this->query($query, $variables + ['cursor' => $cursor]);
            $connection = $extract($data);
            if (!is_array($connection) || !isset($connection['nodes'], $connection['pageInfo'])) {
                throw new RuntimeException('Shopify eksik iade yanıtı döndürdü.');
            }
            foreach ($connection['nodes'] as $node) { yield $node; }
            $more = (bool) ($connection['pageInfo']['hasNextPage'] ?? false);
            $cursor = $connection['pageInfo']['endCursor'] ?? null;
            if ($more && (!$cursor || isset($seen[$cursor]))) {
                throw new RuntimeException('Shopify iade sayfalaması ilerlemiyor.');
            }
            if ($cursor) { $seen[$cursor] = true; }
        } while ($more);
    }

    public function fetch(array $filters): array
    {
        $start = (string) ($filters['startDate'] ?? $filters['start_date'] ?? date('Y-m-d', strtotime('-30 days')));
        $end = (string) ($filters['endDate'] ?? $filters['end_date'] ?? date('Y-m-d'));
        foreach ([$start, $end] as $date) {
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new RuntimeException('Geçerli YYYY-MM-DD tarih aralığı girin.');
        }
        if ($start > $end) throw new RuntimeException('Başlangıç tarihi bitişten sonra olamaz.');
        // Query by update time so returns on older orders are not missed.
        $orders = $this->pages('query ReturnOrders($search: String!, $cursor: String) {
            orders(first: 50, after: $cursor, query: $search, sortKey: UPDATED_AT) {
                nodes { id } pageInfo { hasNextPage endCursor }
            }
        }', ['search' => "updated_at:>={$start} updated_at:<=" . $end . 'T23:59:59Z'], fn($d) => $d['orders'] ?? null);
        $result = [];
        foreach ($orders as $order) {
            $returns = $this->pages('query OrderReturns($id: ID!, $cursor: String) {
                order(id: $id) { id name
                    returns(first: 50, after: $cursor) { nodes { id name status createdAt }
                        pageInfo { hasNextPage endCursor } }
                }
            }', ['id' => $order['id']], fn($d) => $d['order']['returns'] ?? null);
            foreach ($returns as $return) {
                $items = [];
                $lines = $this->pages('query ReturnItems($id: ID!, $cursor: String) {
                    return(id: $id) { returnLineItems(first: 100, after: $cursor) {
                        nodes { ... on ReturnLineItem { id quantity returnReason returnReasonNote
                            fulfillmentLineItem { lineItem { name sku } } } }
                        pageInfo { hasNextPage endCursor }
                    } }
                }', ['id' => $return['id']], fn($d) => $d['return']['returnLineItems'] ?? null);
                $reasons = [];
                foreach ($lines as $line) {
                    if (empty($line['id'])) throw new RuntimeException('Shopify desteklenmeyen iade kalemi döndürdü.');
                    $item = $line['fulfillmentLineItem']['lineItem'] ?? [];
                    $items[] = ['merchant_sku' => $item['sku'] ?? '', 'product_name' => $item['name'] ?? '',
                        'quantity' => (int) $line['quantity'], 'marketplace_item_id' => $line['id']];
                    $reasons[] = trim(($line['returnReason'] ?? '') . ' ' . ($line['returnReasonNote'] ?? ''));
                }
                $result[] = [
                    'marketplace_return_id' => basename($return['id']),
                    'marketplace_order_id' => basename($order['id']),
                    'status' => match ($return['status']) {
                        'REQUESTED' => 'requested', 'OPEN' => 'approved', 'CLOSED' => 'completed',
                        'DECLINED' => 'rejected', 'CANCELED' => 'cancelled',
                        default => throw new RuntimeException('Bilinmeyen Shopify iade durumu.'),
                    },
                    'status_raw' => $return['status'], 'reason' => implode('; ', array_unique($reasons)),
                    'quantity' => array_sum(array_column($items, 'quantity')), 'items' => $items,
                    'product_name' => $items[0]['product_name'] ?? '', 'product_sku' => $items[0]['merchant_sku'] ?? '',
                    'request_date' => date('Y-m-d H:i:s', strtotime($return['createdAt'])), 'raw_data' => $return,
                ];
            }
        }
        return $result;
    }
}
