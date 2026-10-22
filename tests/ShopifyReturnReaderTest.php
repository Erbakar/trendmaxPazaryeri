<?php
declare(strict_types=1);
use App\Services\Marketplace\ShopifyReturnReader;
use PHPUnit\Framework\TestCase;
final class ShopifyReturnReaderTest extends TestCase
{
    public function testReadsEveryPageAndPreservesReturnIdentity(): void
    {
        $calls = [];
        $page = static fn($nodes, $more = false, $cursor = null) => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => $more, 'endCursor' => $cursor]];
        $reader = new ShopifyReturnReader(function($body) use (&$calls, $page) {
            $calls[] = $body;
            $v = $body['variables'];
            if (str_contains($body['query'], 'ReturnOrders')) {
                return ['data' => ['orders' => $v['cursor'] ? $page([]) : $page([['id'=>'gid://shopify/Order/8']], true, 'orders-next')]];
            }
            if (str_contains($body['query'], 'OrderReturns')) return ['data'=>['order'=>['returns'=>$page([['id'=>'gid://shopify/Return/9','status'=>'REQUESTED','name'=>'R1','createdAt'=>'2026-10-20T12:00:00Z']])]]];
            return ['data'=>['return'=>['returnLineItems'=>$v['cursor'] ? $page([]) : $page([['id'=>'line1','quantity'=>2,'returnReason'=>'OTHER','returnReasonNote'=>'Test','fulfillmentLineItem'=>['lineItem'=>['name'=>'Ürün','sku'=>'SKU-1']]]], true, 'lines-next')]]];
        });
        $rows = $reader->fetch(['startDate'=>'2026-10-01','endDate'=>'2026-10-22']);
        self::assertCount(1,$rows);self::assertSame('9',$rows[0]['marketplace_return_id']);
        self::assertSame('8',$rows[0]['marketplace_order_id']);self::assertSame(2,$rows[0]['quantity']);
        self::assertSame('SKU-1',$rows[0]['product_sku']);self::assertCount(5,$calls);
        self::assertStringContainsString('updated_at:>=2026-10-01',$calls[0]['variables']['search']);
    }
    public function testPermissionErrorsCannotMasqueradeAsNoReturns(): void
    {
        $reader = new ShopifyReturnReader(fn()=>['errors'=>[['message'=>'Access denied']]]);
        $this->expectException(RuntimeException::class);$reader->fetch([]);
    }
    public function testRepeatedCursorFailsInsteadOfLooping(): void
    {
        $reader = new ShopifyReturnReader(fn()=>['data'=>['orders'=>['nodes'=>[], 'pageInfo'=>['hasNextPage'=>true,'endCursor'=>'same']]]]);
        $this->expectException(RuntimeException::class);$reader->fetch([]);
    }
}
