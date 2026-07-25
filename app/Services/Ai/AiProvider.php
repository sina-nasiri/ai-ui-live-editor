<?php

namespace App\Services\Ai;

/**
 * One AI vendor, behind a shape the rest of the app can reuse.
 *
 * Every provider is asked the same thing: given a system prompt, a user
 * prompt, and a JSON Schema, return data matching that schema. Each vendor
 * has its own name for that feature — Anthropic calls it `output_config`,
 * OpenAI calls it `response_format`, Google calls it `responseSchema` — but
 * the contract here hides that difference so the editor never branches on
 * which model the user picked.
 */
interface AiProvider
{
    /** Config key for this provider, e.g. "anthropic". */
    public function key(): string;

    /**
     * Send a request and return the decoded, schema-shaped response.
     *
     * @param  array<string,mixed>  $schema  JSON Schema the reply must satisfy
     * @return array<string,mixed>
     *
     * @throws AiException
     */
    public function structured(string $system, string $prompt, array $schema, string $model, int $maxTokens): array;

    /**
     * Token usage from the most recent call.
     *
     * Reported rather than estimated: a running cost is only worth showing if
     * it is the real number the vendor billed.
     *
     * @return array{input:int,output:int}
     */
    public function lastUsage(): array;
}
