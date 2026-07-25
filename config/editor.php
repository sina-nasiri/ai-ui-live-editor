<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proxy
    |--------------------------------------------------------------------------
    |
    | Settings for fetching third-party pages. The defaults are deliberately
    | strict: TLS is verified, private networks are unreachable, and scripts
    | are removed from the snapshot.
    |
    */

    'proxy' => [
        // Verify TLS certificates on outbound requests. Only disable this for
        // a local site with a self-signed certificate, and never in production.
        'verify_ssl' => env('EDITOR_VERIFY_SSL', true),

        'timeout' => (int) env('EDITOR_PROXY_TIMEOUT', 20),

        // Hard ceiling on the size of a fetched page, in bytes.
        'max_bytes' => (int) env('EDITOR_PROXY_MAX_BYTES', 5 * 1024 * 1024),

        'max_redirects' => (int) env('EDITOR_PROXY_MAX_REDIRECTS', 5),

        // Cache proxied pages for this many seconds (0 disables caching).
        'cache_ttl' => (int) env('EDITOR_PROXY_CACHE_TTL', 300),

        // Allow the proxy to reach private/loopback/link-local addresses.
        // This opens an SSRF hole. Only enable on a trusted, single-user
        // machine where you want to edit http://localhost pages.
        'allow_private_networks' => env('EDITOR_ALLOW_PRIVATE_NETWORKS', false),

        // When non-empty, only these hostnames (and their subdomains) may be
        // loaded. The strongest control available for a public instance.
        'allowed_hosts' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('EDITOR_ALLOWED_HOSTS', '')))
        )),

        'user_agent' => env(
            'EDITOR_USER_AGENT',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 '
            .'(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36'
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI providers
    |--------------------------------------------------------------------------
    |
    | Each provider can take its key from the server environment (best for a
    | self-hosted instance — the key never leaves the server) or from the
    | browser (best for a shared demo — each visitor brings their own key).
    |
    | Model lists are plain config: add, remove, or reorder them freely
    | without touching any PHP.
    |
    */

    'default_provider' => env('EDITOR_DEFAULT_PROVIDER', 'anthropic'),

    // Allow the browser to supply an API key. Turn this off on a private
    // instance so only the server-side key in .env can ever be used.
    'allow_client_keys' => env('EDITOR_ALLOW_CLIENT_KEYS', true),

    'request_timeout' => (int) env('EDITOR_AI_TIMEOUT', 120),

    'providers' => [

        'anthropic' => [
            'label' => 'Claude',
            'key' => env('ANTHROPIC_API_KEY'),
            'key_prefix' => 'sk-ant-',
            'key_url' => 'https://console.anthropic.com/settings/keys',
            'default_model' => env('ANTHROPIC_MODEL', 'claude-opus-5'),
            'models' => [
                'claude-opus-5' => 'Claude Opus 5 — most capable',
                'claude-sonnet-5' => 'Claude Sonnet 5 — balanced',
                'claude-haiku-4-5' => 'Claude Haiku 4.5 — fastest',
            ],
            // How hard the model should think. Style patches are small, so
            // "medium" is a good default; raise it for restructure work.
            'effort' => env('ANTHROPIC_EFFORT', 'medium'),
            // Models that reject the effort parameter outright.
            'effort_unsupported' => ['claude-haiku-4-5'],
        ],

        'openai' => [
            'label' => 'OpenAI',
            'key' => env('OPENAI_API_KEY'),
            'key_prefix' => 'sk-',
            'key_url' => 'https://platform.openai.com/api-keys',
            'default_model' => env('OPENAI_MODEL', 'gpt-5'),
            'models' => [
                'gpt-5' => 'GPT-5',
                'gpt-5-mini' => 'GPT-5 mini — faster',
                'gpt-4.1' => 'GPT-4.1',
            ],
        ],

        'gemini' => [
            'label' => 'Gemini',
            'key' => env('GEMINI_API_KEY'),
            'key_prefix' => 'AIza',
            'key_url' => 'https://aistudio.google.com/app/apikey',
            'default_model' => env('GEMINI_MODEL', 'gemini-2.5-pro'),
            'models' => [
                'gemini-2.5-pro' => 'Gemini 2.5 Pro',
                'gemini-2.5-flash' => 'Gemini 2.5 Flash — faster',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    'limits' => [
        // Max nodes described to the model when building edit context.
        'context_nodes' => (int) env('EDITOR_CONTEXT_NODES', 120),

        // Max characters of raw HTML accepted in restructure mode.
        'max_html_chars' => (int) env('EDITOR_MAX_HTML_CHARS', 60000),

        'max_prompt_chars' => (int) env('EDITOR_MAX_PROMPT_CHARS', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    */

    'repo_url' => env('EDITOR_REPO_URL', 'https://github.com/sina-nasiri/ai-ui-live-editor'),

];
