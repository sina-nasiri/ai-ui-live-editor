<?php

namespace App\Services\Ai;

class GeminiProvider extends BaseProvider
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function key(): string
    {
        return 'gemini';
    }

    public function structured(string $system, string $prompt, array $schema, string $model, int $maxTokens): array
    {
        // Gemini's responseSchema is an OpenAPI subset: it has no concept of
        // additionalProperties, and sending it is a validation error.
        $geminiSchema = $this->pruneSchema($schema, ['additionalProperties']);

        $response = $this->request()
            ->withHeaders(['x-goog-api-key' => $this->apiKey])
            ->post(sprintf(self::ENDPOINT, rawurlencode($model)), [
                'systemInstruction' => [
                    'parts' => [['text' => $system]],
                ],
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $prompt]]],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'responseSchema' => $geminiSchema,
                    'maxOutputTokens' => $maxTokens,
                ],
            ]);

        $this->assertSuccessful($response, 'Gemini');

        $body = $response->json();

        if (isset($body['promptFeedback']['blockReason'])) {
            throw new AiException('Gemini declined this request. Try describing the change differently.', 422);
        }

        $candidate = $body['candidates'][0] ?? [];

        if (($candidate['finishReason'] ?? null) === 'MAX_TOKENS') {
            throw new AiException('The reply was cut off. Select a smaller element or ask for a narrower change.', 422);
        }

        $text = null;
        foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
            if (isset($part['text'])) {
                $text = ($text ?? '').$part['text'];
            }
        }

        return $this->decode($text, 'Gemini');
    }
}
