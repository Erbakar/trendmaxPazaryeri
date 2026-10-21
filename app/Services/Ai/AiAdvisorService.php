<?php
/**
 * OzyConnect Panel — AiAdvisorService (Modül #6: AI Satış Danışmanı)
 *
 * Mağazanın ANONİM operasyonel metriklerini (sipariş/finans/stok — müşteri PII'si YOK)
 * toplar ve kullanıcının sorusunu bu bağlamla AI'ya sorar. "Bu ay neden düştü?",
 * "Hangi ürünleri öne çıkarayım?", "Stok riski ne?" gibi sorulara veriye dayalı yanıt.
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\FinancialTransaction;
use App\Models\Order;
use App\Models\Product;

final class AiAdvisorService
{
    private const MODULE = 'ai_advisor';

    public function __construct(
        private readonly AiKernel $kernel,
        private readonly Order $orders,
        private readonly FinancialTransaction $finance,
        private readonly Product $products,
    ) {}

    public function available(): bool
    {
        return $this->kernel->available(self::MODULE, self::MODULE);
    }

    /** Mağaza anlık görüntüsü — yalnız toplu/anonim metrik (PII içermez). */
    public function snapshot(): array
    {
        $thisStart = date('Y-m-01');
        $lastStart = date('Y-m-01', strtotime('-1 month'));
        $lastEnd   = date('Y-m-t', strtotime('-1 month'));
        $last30    = date('Y-m-d', strtotime('-30 days'));

        return [
            'bugun'              => $this->orders->getTodayStats(),
            'bu_hafta'           => $this->orders->getWeekStats(),
            'bu_ay'              => $this->orders->getFinanceSummary(['date_from' => $thisStart]),
            'gecen_ay'           => $this->orders->getFinanceSummary(['date_from' => $lastStart, 'date_to' => $lastEnd]),
            'gunluk_trend_30g'   => $this->orders->getDailyChart(30),
            'pazaryeri_30g'      => $this->orders->getMarketplaceStats(),
            'finans_30g'         => $this->finance->getSummary(['date_from' => $last30]),
            'pazaryeri_finans'   => $this->finance->getMarketplaceBreakdown(['date_from' => $last30]),
            'stok_ozeti'         => $this->products->getStockSummary(),
        ];
    }

    /**
     * Soruyu mağaza bağlamıyla AI'ya sor.
     * @param array<int,array{role:string,content:string}> $history Önceki tur(lar) (opsiyonel)
     * @return array{answer:string}
     */
    public function ask(string $question, array $history = []): array
    {
        $question = trim($question);
        if ($question === '') {
            throw new \InvalidArgumentException('Soru boş olamaz.');
        }

        $snapshot = $this->snapshot();
        $system = 'Sen deneyimli bir Türk e-ticaret satış danışmanısın. Mağaza sahibine YALNIZCA aşağıdaki '
            . 'gerçek verilere dayanarak Türkçe, net, kısa ve EYLEME DÖNÜK tavsiye ver. Uydurma sayı/oran verme; '
            . 'veri yoksa "veri yetersiz" de. Cevabı maddeler ve somut önerilerle ver. Para birimi ₺.';

        $messages = $history;
        $messages[] = [
            'role'    => 'user',
            'content' => $question
                . "\n\n--- MAĞAZA VERİLERİ (anonim, JSON) ---\n"
                . json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $res = $this->kernel->execute(self::MODULE, self::MODULE, fn(AiClient $ai) => $ai->chat(
            $system,
            $messages,
            ['max_tokens' => 900, 'temperature' => 0.5],
            'advisor'
        ), ['type' => 'advisor']);

        return ['answer' => trim($res->text)];
    }
}
