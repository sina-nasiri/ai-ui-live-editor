<?php

namespace App\Services\Ai;

/**
 * Builds the provider the request asked for, with the right key.
 *
 * Key resolution order matters. A key configured on the server wins, because
 * a self-hosted instance should not be overridable by whatever the browser
 * sends. Only when the server has no key — the shared-demo case — do we fall
 * back to the visitor's own key, and only if the instance allows it.
 */
class ProviderFactory
{
    /** @var array<string,class-string<AiProvider>> */
    private const PROVIDERS = [
        'anthropic' => AnthropicProvider::class,
        'openai' => OpenAiProvider::class,
        'gemini' => GeminiProvider::class,
    ];

    /**
     * @throws AiException
     */
    public function make(string $provider, ?string $clientKey): AiProvider
    {
        $config = config('editor.providers.'.$provider);

        if (! is_array($config) || ! isset(self::PROVIDERS[$provider])) {
            throw new AiException('Unknown AI provider: '.$provider, 422);
        }

        $key = $this->resolveKey($provider, $config, $clientKey);
        $class = self::PROVIDERS[$provider];

        return new $class($key, $config);
    }

    /**
     * The model to use, falling back to the provider's configured default.
     */
    public function resolveModel(string $provider, ?string $requested): string
    {
        $models = (array) config('editor.providers.'.$provider.'.models', []);

        if (is_string($requested) && $requested !== '' && array_key_exists($requested, $models)) {
            return $requested;
        }

        return (string) config('editor.providers.'.$provider.'.default_model');
    }

    /**
     * The provider catalogue the browser needs to render its picker.
     *
     * Deliberately reports only *whether* a server key exists — never the key
     * itself, and never a prefix of it.
     *
     * @return array<string,mixed>
     */
    public function catalogue(): array
    {
        $providers = [];

        foreach (self::PROVIDERS as $key => $_class) {
            $config = (array) config('editor.providers.'.$key, []);

            $providers[$key] = [
                'label' => $config['label'] ?? $key,
                'models' => $config['models'] ?? [],
                'default_model' => $config['default_model'] ?? null,
                'key_url' => $config['key_url'] ?? null,
                'key_prefix' => $config['key_prefix'] ?? '',
                'server_key' => filled($config['key'] ?? null),
            ];
        }

        return [
            'providers' => $providers,
            'default' => (string) config('editor.default_provider', 'anthropic'),
            'allow_client_keys' => (bool) config('editor.allow_client_keys', true),
        ];
    }

    /**
     * @param  array<string,mixed>  $config
     *
     * @throws AiException
     */
    private function resolveKey(string $provider, array $config, ?string $clientKey): string
    {
        $serverKey = $config['key'] ?? null;

        if (is_string($serverKey) && $serverKey !== '') {
            return $serverKey;
        }

        if (! config('editor.allow_client_keys', true)) {
            throw new AiException(
                'This instance has no server key for '.($config['label'] ?? $provider)
                .' and does not accept keys from the browser.',
                422
            );
        }

        $clientKey = trim((string) $clientKey);

        if ($clientKey === '') {
            throw new AiException('Add your '.($config['label'] ?? $provider).' API key in Settings first.', 422);
        }

        $prefix = (string) ($config['key_prefix'] ?? '');
        if ($prefix !== '' && ! str_starts_with($clientKey, $prefix)) {
            throw new AiException(
                'That does not look like a '.($config['label'] ?? $provider).' key — it should start with "'.$prefix.'".',
                422
            );
        }

        unset($provider);

        return $clientKey;
    }
}
