<?php
/**
 * OzyConnect Panel — ClaudeAdapter (Anthropic Messages API)
 *
 * Kalite gerektiğinde (akıl yürütme/analiz). Vision destekli. baseUrl vars:
 * https://api.anthropic.com/v1
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Ai\Provider;

use App\Services\Ai\AiResult;

final class ClaudeAdapter extends AbstractAiProvider
{
    public function name(): string { return 'claude'; }

    private function base(): string
    {
        return rtrim($this->baseUrl !== '' ? $this->baseUrl : 'https://api.anthropic.com/v1', '/');
    }

    private function headers(): array
    {
        return ['x-api-key' => $this->apiKey, 'anthropic-version' => '2023-06-01'];
    }

    public function chat(string $model, ?string $system, array $messages, array $opts = []): AiResult
    {
        $msgs = [];
        foreach ($messages as $m) {
            $msgs[] = ['role' => (string) ($m['role'] ?? 'user'), 'content' => (string) ($m['content'] ?? '')];
        }
        return $this->send($model, $system, $msgs, $opts);
    }

    public function vision(string $model, string $prompt, string $imageBase64, string $mime = 'image/jpeg', ?string $system = null, array $opts = []): AiResult
    {
        $msgs = [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => $prompt],
            ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $imageBase64]],
        ]]];
        return $this->send($model, $system, $msgs, $opts);
    }

    /** @param array<int,mixed> $msgs */
    private function send(string $model, ?string $system, array $msgs, array $opts): AiResult
    {
        $body = [
            'model'      => $model,
            'max_tokens' => (int) ($opts['max_tokens'] ?? 2048),
            'messages'   => $msgs,
        ];
        if ($system !== null && $system !== '') {
            $body['system'] = $system;
        }
        if (isset($opts['temperature'])) {
            $body['temperature'] = (float) $opts['temperature'];
        }

        $resp = $this->postJson($this->base() . '/messages', $this->headers(), $body);

        $text = '';
        foreach ($resp['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }
        $usage = $resp['usage'] ?? [];

        return new AiResult(
            text: $text,
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            model: $model,
            provider: 'claude',
            raw: $resp,
        );
    }
}
