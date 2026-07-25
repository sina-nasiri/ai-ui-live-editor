<?php

namespace App\Services\Ai;

class AnthropicProvider extends BaseProvider
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const VERSION = '2023-06-01';

    public function key(): string
    {
        return 'anthropic';
    }

    public function structured(string $system, string $prompt, array $schema, string $model, int $maxTokens): array
    {
        $payload = [
            'model' => $model,
            // On current Claude models max_tokens covers thinking as well as
            // the reply, so this needs headroom beyond the JSON we expect.
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
            'output_config' => [
                'format' => [
                    'type' => 'json_schema',
                    'schema' => $schema,
                ],
            ],
        ];

        // Sampling parameters were removed from current Claude models and are
        // rejected outright, so there is deliberately no temperature here.
        $effort = $this->config['effort'] ?? null;
        $unsupported = (array) ($this->config['effort_unsupported'] ?? []);

        if (is_string($effort) && $effort !== '' && ! in_array($model, $unsupported, true)) {
            $payload['output_config']['effort'] = $effort;
        }

        $response = $this->request()
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => self::VERSION,
            ])
            ->post(self::ENDPOINT, $payload);

        $this->assertSuccessful($response, 'Claude');

        $body = $response->json();

        // Safety classifiers can decline a request with a normal 200 and an
        // empty content array — reading content[0] blindly would break here.
        if (($body['stop_reason'] ?? null) === 'refusal') {
            throw new AiException('Claude declined this request. Try describing the change differently.', 422);
        }

        if (($body['stop_reason'] ?? null) === 'max_tokens') {
            throw new AiException('The reply was cut off. Select a smaller element or ask for a narrower change.', 422);
        }

        $text = null;
        foreach ((array) ($body['content'] ?? []) as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text = ($text ?? '').$block['text'];
            }
        }

        return $this->decode($text, 'Claude');
    }
}
