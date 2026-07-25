<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

abstract class BaseProvider implements AiProvider
{
    public function __construct(
        protected readonly string $apiKey,
        /** @var array<string,mixed> */
        protected readonly array $config,
    ) {}

    protected function request(): PendingRequest
    {
        return Http::timeout((int) config('editor.request_timeout', 120))
            ->acceptJson()
            ->asJson();
    }

    /**
     * Turn a non-2xx response into a message a designer can act on.
     *
     * @throws AiException
     */
    protected function assertSuccessful(Response $response, string $vendor): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $body = $response->json();
        $detail = is_array($body)
            ? ($body['error']['message'] ?? $body['error']['msg'] ?? $body['message'] ?? null)
            : null;

        // Log the vendor's own wording for debugging, never the key.
        Log::warning($vendor.' request failed', ['status' => $status, 'detail' => $detail]);

        throw new AiException(match (true) {
            $status === 401 || $status === 403 => 'That API key was rejected by '.$vendor.'. Check it in Settings.',
            $status === 429 => $vendor.' is rate limiting this key. Wait a moment and try again.',
            $status >= 500 => $vendor.' is having trouble right now. Try again shortly.',
            default => $vendor.' rejected the request'.($detail ? ': '.$detail : '.'),
        }, $status === 401 || $status === 403 ? 401 : 502);
    }

    /**
     * Parse the model's reply as JSON.
     *
     * Structured-output modes make well-formed JSON the norm, but a model can
     * still wrap it in a markdown fence, so we unwrap that before giving up.
     *
     * @return array<string,mixed>
     *
     * @throws AiException
     */
    protected function decode(?string $text, string $vendor): array
    {
        $text = trim((string) $text);

        if ($text === '') {
            throw new AiException($vendor.' returned an empty response. Try selecting a smaller element.');
        }

        if (str_starts_with($text, '```')) {
            $text = trim(preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text);
        }

        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new AiException($vendor.' returned something that was not valid JSON. Try rephrasing the request.');
        }

        return $decoded;
    }

    /**
     * Strip schema keywords a vendor does not accept.
     *
     * @param  array<string,mixed>  $schema
     * @param  list<string>  $drop
     * @return array<string,mixed>
     */
    protected function pruneSchema(array $schema, array $drop): array
    {
        $result = [];

        foreach ($schema as $key => $value) {
            if (in_array($key, $drop, true)) {
                continue;
            }

            $result[$key] = is_array($value) ? $this->pruneSchema($value, $drop) : $value;
        }

        return $result;
    }
}
