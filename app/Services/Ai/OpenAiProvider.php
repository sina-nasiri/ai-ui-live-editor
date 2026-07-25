<?php

namespace App\Services\Ai;

class OpenAiProvider extends BaseProvider
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public function key(): string
    {
        return 'openai';
    }

    public function structured(string $system, string $prompt, array $schema, string $model, int $maxTokens): array
    {
        $response = $this->request()
            ->withToken($this->apiKey)
            ->post(self::ENDPOINT, [
                'model' => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $prompt],
                ],
                // Newer reasoning models take max_completion_tokens and reject
                // the older max_tokens field, so only this one is sent.
                'max_completion_tokens' => $maxTokens,
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'ui_edit',
                        'strict' => true,
                        'schema' => $schema,
                    ],
                ],
            ]);

        $this->assertSuccessful($response, 'OpenAI');

        $body = $response->json();
        $choice = $body['choices'][0] ?? [];

        if (($choice['finish_reason'] ?? null) === 'length') {
            throw new AiException('The reply was cut off. Select a smaller element or ask for a narrower change.', 422);
        }

        if (isset($choice['message']['refusal']) && $choice['message']['refusal'] !== null) {
            throw new AiException('OpenAI declined this request. Try describing the change differently.', 422);
        }

        return $this->decode($choice['message']['content'] ?? null, 'OpenAI');
    }
}
