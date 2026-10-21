<?php
/**
 * OzyConnect Panel — OpenAiCompatAdapter (OpenAI Chat Completions şeması)
 *
 * base_url ile OpenAI, DeepSeek, OpenRouter, Together, yerel (LM Studio/Ollama) vb.
 * birçok ucuz sağlayıcıyı TEK adaptörle açar → "her zaman en ucuza" geçiş kolay.
 * Vision: image_url (data URI). baseUrl vars: https://api.openai.com/v1
 *
 * @package OzyConnect
 */
declare(strict_types=1);

namespace App\Services\Ai\Provider;

use App\Services\Ai\AiResult;

final class OpenAiCompatAdapter extends AbstractAiProvider
{
    public function name(): string { return 'openai'; }

    private function base(): string
    {
        return rtrim($this->baseUrl !== '' ? $this->baseUrl : 'https://api.openai.com/v1', '/');
    }

    public function chat(string $model, ?string $system, array $messages, array $opts = []): AiResult
    {
        $msgs = [];
        if ($system !== null && $system !== '') {
            $msgs[] = ['role' => 'system', 'content' => $system];
        }
        foreach ($messages as $m) {
            $msgs[] = ['role' => (string) ($m['role'] ?? 'user'), 'content' => (string) ($m['content'] ?? '')];
        }
        return $this->complete($model, $msgs, $opts);
    }

    public function vision(string $model, string $prompt, string $imageBase64, string $mime = 'image/jpeg', ?string $system = null, array $opts = []): AiResult
    {
        $msgs = [];
        if ($system !== null && $system !== '') {
            $msgs[] = ['role' => 'system', 'content' => $system];
        }
        $msgs[] = ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => $prompt],
            ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $imageBase64]],
        ]];
        return $this->complete($model, $msgs, $opts);
    }

    /** @param array<int,mixed> $msgs */
    private function complete(string $model, array $msgs, array $opts): AiResult
    {
        $body = [
            'model'      => $model,
            'messages'   => $msgs,
            'max_tokens' => (int) ($opts['max_tokens'] ?? 2048),
        ];
        if (isset($opts['temperature'])) {
            $body['temperature'] = (float) $opts['temperature'];
        }
        if (!empty($opts['json'])) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $resp = $this->postJson($this->base() . '/chat/completions', ['Authorization' => 'Bearer ' . $this->apiKey], $body);

        $text  = (string) ($resp['choices'][0]['message']['content'] ?? '');
        $usage = $resp['usage'] ?? [];

        return new AiResult(
            text: $text,
            inputTokens: (int) ($usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
            model: $model,
            provider: 'openai',
            raw: $resp,
        );
    }
}
