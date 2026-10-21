<?php
/**
 * OzyConnect Panel — GeminiAdapter (Google Generative Language API)
 *
 * Genellikle token başına en ucuz seçenek (Gemini Flash / Flash-Lite). Vision destekli.
 * baseUrl vars: https://generativelanguage.googleapis.com/v1beta
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Ai\Provider;

use App\Services\Ai\AiResult;

final class GeminiAdapter extends AbstractAiProvider
{
    public function name(): string { return 'gemini'; }

    private function base(): string
    {
        return rtrim($this->baseUrl !== '' ? $this->baseUrl : 'https://generativelanguage.googleapis.com/v1beta', '/');
    }

    public function chat(string $model, ?string $system, array $messages, array $opts = []): AiResult
    {
        $contents = [];
        foreach ($messages as $m) {
            $contents[] = [
                'role'  => ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) ($m['content'] ?? '')]],
            ];
        }
        return $this->generate($model, $system, $contents, $opts);
    }

    public function vision(string $model, string $prompt, string $imageBase64, string $mime = 'image/jpeg', ?string $system = null, array $opts = []): AiResult
    {
        $contents = [[
            'role'  => 'user',
            'parts' => [
                ['text' => $prompt],
                ['inline_data' => ['mime_type' => $mime, 'data' => $imageBase64]],
            ],
        ]];
        return $this->generate($model, $system, $contents, $opts);
    }

    /** @param array<int,mixed> $contents */
    private function generate(string $model, ?string $system, array $contents, array $opts): AiResult
    {
        $body = [
            'contents'         => $contents,
            'generationConfig' => [
                'maxOutputTokens' => (int) ($opts['max_tokens'] ?? 2048),
            ],
        ];
        if (isset($opts['temperature'])) {
            $body['generationConfig']['temperature'] = (float) $opts['temperature'];
        }
        if ($system !== null && $system !== '') {
            $body['system_instruction'] = ['parts' => [['text' => $system]]];
        }
        if (!empty($opts['json'])) {
            $body['generationConfig']['responseMimeType'] = 'application/json';
        }

        $url = $this->base() . '/models/' . rawurlencode($model) . ':generateContent?key=' . rawurlencode($this->apiKey);
        $resp = $this->postJson($url, [], $body);

        $text = '';
        foreach ($resp['candidates'][0]['content']['parts'] ?? [] as $p) {
            $text .= (string) ($p['text'] ?? '');
        }
        $usage = $resp['usageMetadata'] ?? [];

        return new AiResult(
            text: $text,
            inputTokens: (int) ($usage['promptTokenCount'] ?? 0),
            outputTokens: (int) ($usage['candidatesTokenCount'] ?? 0),
            model: $model,
            provider: 'gemini',
            raw: $resp,
        );
    }
}
