<?php
/**
 * OzyConnect Panel — AiClient (sağlayıcı-bağımsız cephe)
 *
 * Admin panelinden (settings group='ai_suite') seçilen aktif sağlayıcıyı + modeli +
 * şifreli API anahtarını çözüp uygun adaptörü kurar. Üst katman yalnız buna bağlıdır;
 * sağlayıcı/model/fiyat değişikliği kod değil ayar işidir.
 *
 * Ayar anahtarları (group='ai_suite'):
 *   ai_enabled, ai_provider(gemini|openai|claude),
 *   ai_key_gemini|ai_key_openai|ai_key_claude (CryptoService ile şifreli),
 *   ai_openai_base_url, ai_model_default, ai_model_vision, ai_model_advisor,
 *   ai_model_prices(JSON {model:{in,out}} USD/1M), ai_credit_costs(JSON {action:kredi})
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Setting;
use App\Services\CryptoService;
use App\Services\Ai\Provider\AiProviderInterface;
use App\Services\Ai\Provider\ClaudeAdapter;
use App\Services\Ai\Provider\GeminiAdapter;
use App\Services\Ai\Provider\OpenAiCompatAdapter;
use RuntimeException;

final class AiClient
{
    /** Sağlayıcı başına yerleşik varsayılan model (admin override edebilir). */
    private const DEFAULT_MODEL = [
        'gemini' => 'gemini-2.0-flash',
        'openai' => 'gpt-4o-mini',
        'claude' => 'claude-haiku-4-5',
    ];

    /** Yerleşik kredi maliyetleri (admin ai_credit_costs ile override). */
    private const DEFAULT_CREDIT_COSTS = [
        'ai_content' => 5, 'ai_bulk' => 3, 'ai_vision' => 8,
        'ai_optimize' => 8, 'ai_competitor' => 12, 'ai_advisor' => 15, 'ai_trends' => 0,
    ];

    /** Satılabilir kredi paketleri (kredi miktarı sabit; fiyat admin ai_credit_package_prices ile override, TL). */
    private const DEFAULT_CREDIT_PACKAGES = [
        ['key' => '5k',   'credits' => 5000,   'price' => 499.0,  'label' => '5.000 Kredi'],
        ['key' => '20k',  'credits' => 20000,  'price' => 1499.0, 'label' => '20.000 Kredi'],
        ['key' => '100k', 'credits' => 100000, 'price' => 5999.0, 'label' => '100.000 Kredi'],
    ];

    /** Yerleşik model fiyatları (USD / 1M token) — admin ai_model_prices ile override. */
    private const DEFAULT_PRICES = [
        'gemini-2.0-flash'      => ['in' => 0.10, 'out' => 0.40],
        'gemini-2.0-flash-lite' => ['in' => 0.075, 'out' => 0.30],
        'gpt-4o-mini'           => ['in' => 0.15, 'out' => 0.60],
        'deepseek-chat'         => ['in' => 0.27, 'out' => 1.10],
        'claude-haiku-4-5'      => ['in' => 1.00, 'out' => 5.00],
        'claude-sonnet-4-6'     => ['in' => 3.00, 'out' => 15.00],
    ];

    public function __construct(
        private readonly Setting $settings,
        private readonly CryptoService $crypto,
    ) {}

    public function isEnabled(): bool
    {
        return (string) $this->settings->get('ai_enabled', '0') === '1';
    }

    public function activeProviderName(): string
    {
        $p = (string) $this->settings->get('ai_provider', 'gemini');
        return in_array($p, ['gemini', 'openai', 'claude'], true) ? $p : 'gemini';
    }

    /** Aktif sağlayıcı adaptörü. */
    public function provider(): AiProviderInterface
    {
        return $this->buildProvider($this->activeProviderName());
    }

    /** Belirli sağlayıcı adaptörünü kur (admin "bağlantı testi" için de). */
    public function buildProvider(string $name): AiProviderInterface
    {
        $key = $this->apiKey($name);
        if ($key === '') {
            throw new RuntimeException("AI sağlayıcı '{$name}' için API anahtarı tanımlı değil (/admin/ai-suite).");
        }
        return match ($name) {
            'gemini' => new GeminiAdapter($key),
            'claude' => new ClaudeAdapter($key),
            'openai' => new OpenAiCompatAdapter($key, (string) $this->settings->get('ai_openai_base_url', '')),
            default  => throw new RuntimeException("Bilinmeyen AI sağlayıcı: {$name}"),
        };
    }

    /** Sağlayıcı API anahtarını çöz (şifreli saklanır). */
    public function apiKey(string $provider): string
    {
        $enc = (string) $this->settings->get('ai_key_' . $provider, '');
        if ($enc === '') {
            return '';
        }
        try {
            return $this->crypto->decrypt($enc);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Görev tipine göre model çöz: 'default' | 'vision' | 'advisor'.
     * Önce ai_model_<task>, yoksa ai_model_default, yoksa sağlayıcı yerleşiği.
     */
    public function modelFor(string $task = 'default'): string
    {
        $m = trim((string) $this->settings->get('ai_model_' . $task, ''));
        if ($m === '' && $task !== 'default') {
            $m = trim((string) $this->settings->get('ai_model_default', ''));
        }
        if ($m === '') {
            $m = self::DEFAULT_MODEL[$this->activeProviderName()] ?? 'gemini-2.0-flash';
        }
        return $m;
    }

    /**
     * Satılabilir kredi paketleri (admin fiyatlı). 'key' ile anahtarlı döner.
     * @return array<string,array{key:string,credits:int,price:float,label:string}>
     */
    public function creditPackages(): array
    {
        $prices = json_decode((string) $this->settings->get('ai_credit_package_prices', ''), true);
        $out = [];
        foreach (self::DEFAULT_CREDIT_PACKAGES as $p) {
            $price = (is_array($prices) && isset($prices[$p['key']]) && (float) $prices[$p['key']] > 0)
                ? (float) $prices[$p['key']]
                : (float) $p['price'];
            $out[$p['key']] = ['key' => $p['key'], 'credits' => (int) $p['credits'], 'price' => $price, 'label' => $p['label']];
        }
        return $out;
    }

    /** İşlem başı kredi maliyeti. */
    public function creditCost(string $action): int
    {
        $costs = json_decode((string) $this->settings->get('ai_credit_costs', ''), true);
        if (is_array($costs) && isset($costs[$action])) {
            return (int) $costs[$action];
        }
        return self::DEFAULT_CREDIT_COSTS[$action] ?? 1;
    }

    /** Model için (in,out) USD/1M fiyat — kullanım maliyeti hesabı. */
    public function priceFor(string $model): array
    {
        $prices = json_decode((string) $this->settings->get('ai_model_prices', ''), true);
        if (is_array($prices) && isset($prices[$model]['in'], $prices[$model]['out'])) {
            return ['in' => (float) $prices[$model]['in'], 'out' => (float) $prices[$model]['out']];
        }
        return self::DEFAULT_PRICES[$model] ?? ['in' => 0.0, 'out' => 0.0];
    }

    /** Token sayımından gerçek $ maliyet. */
    public function costUsd(AiResult $r): float
    {
        $p = $this->priceFor($r->model);
        return ($r->inputTokens / 1_000_000) * $p['in'] + ($r->outputTokens / 1_000_000) * $p['out'];
    }

    // ─── Kısayollar ───────────────────────────────────────────
    /** @param array<int,array{role:string,content:string}> $messages */
    public function chat(?string $system, array $messages, array $opts = [], string $task = 'default'): AiResult
    {
        return $this->provider()->chat($this->modelFor($task), $system, $messages, $opts);
    }

    public function vision(string $prompt, string $imageBase64, string $mime = 'image/jpeg', ?string $system = null, array $opts = []): AiResult
    {
        return $this->provider()->vision($this->modelFor('vision'), $prompt, $imageBase64, $mime, $system, $opts);
    }
}
