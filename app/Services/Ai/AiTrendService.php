<?php
/**
 * OzyConnect Panel — AiTrendService (Modül #7: AI Trend Merkezi)
 *
 * Günlük cron ile mağazanın satış verisinden YEREL trend sinyalleri hesaplar
 * (yükselen / düşen / fırsat=fazla-stok & stok-tükenen-satan ürünler) → AI YALNIZ
 * yorum/öneri ekler (ürün adlarını uydurmaz) → ai_trends tablosuna yazar.
 * Kredi maliyeti 0 (operatör maliyeti; merchant'a yansımaz, önceden hesaplı).
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Ai;

use App\Core\Database;
use App\Core\TenantContext;
use App\Models\AiTrend;

final class AiTrendService
{
    private const MODULE = 'ai_trends';

    public function __construct(
        private readonly AiKernel $kernel,
        private readonly Database $db,
        private readonly TenantContext $tenant,
        private readonly AiTrend $trends,
    ) {}

    public function available(): bool
    {
        return $this->kernel->available(self::MODULE, self::MODULE);
    }

    /** Widget için: en son hesaplanan trendler. */
    public function latest(): array
    {
        return $this->trends->latestForStore();
    }

    /**
     * Aktif mağaza (TenantContext) için trendleri hesapla, AI yorumu ekle, kaydet.
     * @return array{status:string,rising:int,falling:int,opportunity:int}
     */
    public function generateForStore(): array
    {
        $sid = $this->tenant->currentStoreId();
        $today = date('Y-m-d');
        $recentStart = date('Y-m-d H:i:s', strtotime('-14 days'));
        $priorStart  = date('Y-m-d H:i:s', strtotime('-28 days'));
        $days30      = date('Y-m-d H:i:s', strtotime('-30 days'));

        // ── Yükselen / Düşen: iki 14 günlük pencere karşılaştırması
        $rows = $this->db->fetchAll(
            "SELECT oi.product_id,
                    MAX(p.name) AS name,
                    SUM(CASE WHEN COALESCE(o.ordered_at,o.created_at) >= :rs1 THEN oi.qty ELSE 0 END) AS recent_qty,
                    SUM(CASE WHEN COALESCE(o.ordered_at,o.created_at) <  :rs2 THEN oi.qty ELSE 0 END) AS prior_qty
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id AND o.store_id = oi.store_id
             LEFT JOIN products p ON p.id = oi.product_id
             WHERE oi.store_id = :sid
               AND oi.product_id IS NOT NULL
               AND COALESCE(o.ordered_at,o.created_at) >= :ps
             GROUP BY oi.product_id",
            ['sid' => $sid, 'rs1' => $recentStart, 'rs2' => $recentStart, 'ps' => $priorStart]
        );

        $rising = [];
        $falling = [];
        foreach ($rows as $r) {
            $rec = (int) $r['recent_qty'];
            $pri = (int) $r['prior_qty'];
            $name = trim((string) ($r['name'] ?? '')) ?: ('#' . (int) $r['product_id']);
            if ($rec > $pri && $rec >= 2) {
                $rising[] = ['id' => (int) $r['product_id'], 'name' => $name, 'recent' => $rec, 'prior' => $pri, 'delta' => $rec - $pri];
            } elseif ($pri >= 2 && $rec < $pri) {
                $falling[] = ['id' => (int) $r['product_id'], 'name' => $name, 'recent' => $rec, 'prior' => $pri, 'drop' => $pri - $rec];
            }
        }
        usort($rising, fn($a, $b) => $b['delta'] <=> $a['delta']);
        usort($falling, fn($a, $b) => $b['drop'] <=> $a['drop']);
        $rising = array_slice($rising, 0, 8);
        $falling = array_slice($falling, 0, 8);

        // ── Fırsat: fazla stok + son 30 günde hiç satış yok (overstock)
        $overstock = $this->db->fetchAll(
            "SELECT p.id, p.name, p.stock_qty
             FROM products p
             WHERE p.store_id = :sid_a AND p.stock_qty >= 10
               AND p.id NOT IN (
                   SELECT DISTINCT oi.product_id FROM order_items oi
                   JOIN orders o ON o.id = oi.order_id
                   WHERE oi.store_id = :sid_b AND oi.product_id IS NOT NULL
                     AND COALESCE(o.ordered_at,o.created_at) >= :d30
               )
             ORDER BY p.stock_qty DESC LIMIT 6",
            ['sid_a' => $sid, 'sid_b' => $sid, 'd30' => $days30]
        );
        // ── Fırsat: stok tükenmiş ama son 14 günde satılmış (kaçan satış / restok)
        $restock = $this->db->fetchAll(
            "SELECT oi.product_id AS id, MAX(p.name) AS name, SUM(oi.qty) AS qty
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             JOIN products p ON p.id = oi.product_id
             WHERE oi.store_id = :sid AND p.stock_qty = 0
               AND COALESCE(o.ordered_at,o.created_at) >= :rs
             GROUP BY oi.product_id ORDER BY qty DESC LIMIT 6",
            ['sid' => $sid, 'rs' => $recentStart]
        );
        $opportunity = [];
        foreach ($overstock as $o) {
            $opportunity[] = ['id' => (int) $o['id'], 'name' => trim((string) $o['name']) ?: ('#' . (int) $o['id']), 'type' => 'overstock', 'detail' => 'Stok: ' . (int) $o['stock_qty'] . ', 30 gündür satış yok'];
        }
        foreach ($restock as $o) {
            $opportunity[] = ['id' => (int) $o['id'], 'name' => trim((string) $o['name']) ?: ('#' . (int) $o['id']), 'type' => 'restock', 'detail' => 'Stok bitti, son 14 günde ' . (int) $o['qty'] . ' adet satıldı'];
        }

        if ($rising === [] && $falling === [] && $opportunity === []) {
            return ['status' => 'no_data', 'rising' => 0, 'falling' => 0, 'opportunity' => 0];
        }

        // ── AI yorumu (yalnız hesaplanan listelere dayanır; ürün uydurmaz)
        $advice = $this->aiAdvice($rising, $falling, $opportunity);

        $this->trends->upsert($today, 'rising', ['items' => $rising, 'advice' => $advice['rising_advice'] ?? '', 'summary' => $advice['summary'] ?? '']);
        $this->trends->upsert($today, 'falling', ['items' => $falling, 'advice' => $advice['falling_advice'] ?? '']);
        $this->trends->upsert($today, 'opportunity', ['items' => $opportunity, 'advice' => $advice['opportunity_advice'] ?? '']);

        return ['status' => 'ok', 'rising' => count($rising), 'falling' => count($falling), 'opportunity' => count($opportunity)];
    }

    /**
     * @return array{summary:string,rising_advice:string,falling_advice:string,opportunity_advice:string}
     */
    private function aiAdvice(array $rising, array $falling, array $opportunity): array
    {
        $fmt = function (array $list): string {
            $out = [];
            foreach ($list as $i) {
                if (isset($i['delta'])) { $out[] = "{$i['name']} (son 14g: {$i['recent']}, önceki: {$i['prior']})"; }
                elseif (isset($i['drop'])) { $out[] = "{$i['name']} (son 14g: {$i['recent']}, önceki: {$i['prior']})"; }
                else { $out[] = "{$i['name']} ({$i['detail']})"; }
            }
            return $out === [] ? '(yok)' : implode("; ", $out);
        };

        $user = "Mağazanın satış trend verisi (adetler):\n"
            . "YÜKSELEN ürünler: " . $fmt($rising) . "\n"
            . "DÜŞEN ürünler: " . $fmt($falling) . "\n"
            . "FIRSAT ürünleri: " . $fmt($opportunity) . "\n\n"
            . "Bu verilere dayanarak şu JSON şemasını doldur (yeni ürün adı UYDURMA, yalnız genel öneri ver):\n"
            . '{'
            . '"summary":"genel satış/trend durumu, 2-3 cümle",'
            . '"rising_advice":"yükselen ürünler için ne yapılmalı, 1-2 cümle",'
            . '"falling_advice":"düşen ürünler için 1-2 cümle",'
            . '"opportunity_advice":"fırsat ürünleri için 1-2 cümle"'
            . '}';

        $system = 'Sen bir e-ticaret satış analistisin. Verilen trend verisini yorumla, uygulanabilir öneriler ver. '
            . 'Yalnızca GEÇERLİ JSON döndür; kod bloğu/``` ekleme. Türkçe yaz. Veride olmayan ürün adı uydurma.';

        $res = $this->kernel->execute(self::MODULE, self::MODULE, fn(AiClient $ai) => $ai->chat(
            $system,
            [['role' => 'user', 'content' => $user]],
            ['max_tokens' => 600, 'json' => true, 'temperature' => 0.5],
            'advisor'
        ), ['type' => 'trend']);

        $j = $res->json() ?? [];
        return [
            'summary'            => trim((string) ($j['summary'] ?? '')),
            'rising_advice'      => trim((string) ($j['rising_advice'] ?? '')),
            'falling_advice'     => trim((string) ($j['falling_advice'] ?? '')),
            'opportunity_advice' => trim((string) ($j['opportunity_advice'] ?? '')),
        ];
    }
}
