<?php

namespace App\Http\Controllers;

use App\Services\Ai\ProviderFactory;
use App\Support\BlockedUrlException;
use App\Support\Fetcher;
use App\Support\PageSnapshot;
use App\Support\UrlResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EditorController extends Controller
{
    /** Sub-resource types the asset proxy will relay. */
    private const ASSET_TYPES = [
        'text/css',
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon',
        'font/woff', 'font/woff2', 'font/ttf', 'font/otf',
        'application/font-woff', 'application/font-woff2', 'application/x-font-ttf', 'application/x-font-otf', 'application/vnd.ms-fontobject',
        'application/octet-stream',
    ];

    public function __construct(private readonly ProviderFactory $providers) {}

    public function index()
    {
        return view('editor', [
            'ai' => $this->providers->catalogue(),
            'repoUrl' => config('editor.repo_url'),
        ]);
    }

    /**
     * Fetch a page and return a static, script-free snapshot of it.
     */
    public function proxy(Request $request)
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
        ]);

        $ttl = (int) config('editor.proxy.cache_ttl', 300);
        $cacheKey = 'editor:page:'.sha1($validated['url']);

        if ($ttl > 0 && ($cached = Cache::get($cacheKey)) !== null) {
            return response($cached)->header('Content-Type', 'text/html; charset=utf-8');
        }

        try {
            $result = Fetcher::make()->get(
                $validated['url'],
                accept: 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
            );
        } catch (BlockedUrlException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::warning('Proxy fetch failed', [
                'host' => parse_url($validated['url'], PHP_URL_HOST),
                'reason' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'That page could not be loaded. Some sites block automated requests.',
            ], 502);
        }

        if ($result['content_type'] !== '' && ! str_contains($result['content_type'], 'html') && ! str_contains($result['content_type'], 'xml')) {
            return response()->json(['error' => 'That URL is not an HTML page.'], 502);
        }

        $snapshot = (new PageSnapshot($validated['url'], $result['charset'], $this->assetTemplate()))
            ->build($result['body']);

        if ($ttl > 0) {
            Cache::put($cacheKey, $snapshot, $ttl);
        }

        return response($snapshot)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Build a snapshot from markup the user pasted or uploaded.
     *
     * This is the escape hatch for everything the proxy cannot reach: a
     * localhost dev server, a staging site behind basic auth, a page you are
     * logged into, or a component pulled straight out of a design system. Save
     * the page from your browser, drop it here, and the editor works exactly
     * as it does on a proxied URL.
     */
    public function import(Request $request)
    {
        $validated = $request->validate([
            'html' => ['required_without:file', 'nullable', 'string', 'max:5000000'],
            'file' => ['required_without:html', 'nullable', 'file', 'max:5120'],
            'base' => ['nullable', 'string', 'max:2048'],
        ]);

        $html = $validated['html'] ?? '';

        if ($request->hasFile('file')) {
            $html = (string) file_get_contents($request->file('file')->getRealPath());
        }

        if (trim($html) === '') {
            return response()->json(['error' => 'That file or paste was empty.'], 422);
        }

        // A base URL is optional. Without one, relative paths in the markup
        // simply stay relative and their images will not resolve — which is
        // the honest outcome, not something to paper over.
        $base = trim((string) ($validated['base'] ?? ''));

        if ($base !== '') {
            try {
                $base = app(\App\Support\UrlGuard::class)->validate($base);
            } catch (BlockedUrlException $e) {
                return response()->json(['error' => $e->getMessage()], 422);
            }
        }

        $snapshot = (new PageSnapshot($base ?: 'about:blank', null, $base ? $this->assetTemplate() : null))
            ->build($html);

        return response($snapshot)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Relay a stylesheet, image or font so the browser treats it as
     * same-origin.
     *
     * Without this, `sheet.cssRules` throws on every third-party stylesheet,
     * which blocks reading the site's design tokens and blocks capturing an
     * element to PNG. See PageSnapshot::proxied().
     */
    public function asset(Request $request)
    {
        $url = (string) $request->query('u', '');

        if ($url === '') {
            abort(400, 'Missing asset URL.');
        }

        $cacheKey = 'editor:asset:'.sha1($url);
        $ttl = (int) config('editor.proxy.cache_ttl', 300);

        if ($ttl > 0 && ($cached = Cache::get($cacheKey)) !== null) {
            return $this->assetResponse($cached['body'], $cached['type']);
        }

        try {
            $result = Fetcher::make()->get(
                $url,
                maxBytes: (int) config('editor.proxy.max_asset_bytes', 3 * 1024 * 1024),
                accept: 'text/css,image/*,font/*,*/*;q=0.5'
            );
        } catch (BlockedUrlException $e) {
            abort(422, $e->getMessage());
        } catch (\Throwable) {
            abort(502, 'That asset could not be fetched.');
        }

        $type = $result['content_type'] !== '' ? $result['content_type'] : 'application/octet-stream';

        // Relaying arbitrary content types would turn this into an open
        // redirector for HTML and scripts served from our own origin.
        if (! in_array($type, self::ASSET_TYPES, true)) {
            abort(415, 'That asset type is not relayed.');
        }

        $body = $result['body'];

        if ($type === 'text/css') {
            $body = $this->rewriteCss($body, $url);
        }

        if ($ttl > 0) {
            Cache::put($cacheKey, ['body' => $body, 'type' => $type], $ttl);
        }

        return $this->assetResponse($body, $type);
    }

    private function assetResponse(string $body, string $type)
    {
        return response($body)
            ->header('Content-Type', $type)
            ->header('Cache-Control', 'private, max-age=300')
            // The relay can return SVG and fonts; stop the browser
            // second-guessing the declared type.
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Make a relayed stylesheet's own references absolute, and route any
     * nested @import back through the relay so it stays readable too.
     */
    private function rewriteCss(string $css, string $cssUrl): string
    {
        $resolver = new UrlResolver($cssUrl);
        $template = $this->assetTemplate();

        $css = preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
            static function (array $m) use ($resolver): string {
                if (str_starts_with(trim($m[2]), 'data:')) {
                    return $m[0];
                }

                return 'url("'.$resolver->resolve($m[2]).'")';
            },
            $css
        ) ?? $css;

        return preg_replace_callback(
            '/@import\s+(?:url\(\s*)?([\'"]?)([^\'")]+)\1\s*\)?/i',
            static fn (array $m): string => '@import url("'.sprintf($template, rawurlencode($resolver->resolve($m[2]))).'")',
            $css
        ) ?? $css;
    }

    private function assetTemplate(): string
    {
        return route('asset').'?u=%s';
    }
}
