<?php

namespace App\Http\Controllers;

use App\Services\Ai\ProviderFactory;
use App\Support\BlockedUrlException;
use App\Support\PageSnapshot;
use App\Support\UrlGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

class EditorController extends Controller
{
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

        $guard = UrlGuard::fromConfig();

        try {
            $url = $guard->validate($validated['url']);
        } catch (BlockedUrlException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $ttl = (int) config('editor.proxy.cache_ttl', 300);
        $cacheKey = 'editor:page:'.sha1($url);

        if ($ttl > 0 && ($cached = Cache::get($cacheKey)) !== null) {
            return response($cached)->header('Content-Type', 'text/html; charset=utf-8');
        }

        try {
            [$html, $charset] = $this->fetch($url, $guard);
        } catch (BlockedUrlException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::warning('Proxy fetch failed', ['host' => parse_url($url, PHP_URL_HOST), 'reason' => $e->getMessage()]);

            return response()->json([
                'error' => 'That page could not be loaded. Some sites block automated requests.',
            ], 502);
        }

        $snapshot = (new PageSnapshot($url, $charset))->build($html);

        if ($ttl > 0) {
            Cache::put($cacheKey, $snapshot, $ttl);
        }

        return response($snapshot)->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * @return array{0:string,1:?string} the body, and the charset the server declared
     *
     * @throws BlockedUrlException
     */
    private function fetch(string $url, UrlGuard $guard): array
    {
        $maxBytes = (int) config('editor.proxy.max_bytes', 5 * 1024 * 1024);

        $response = Http::timeout((int) config('editor.proxy.timeout', 20))
            ->withHeaders([
                'User-Agent' => (string) config('editor.proxy.user_agent'),
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ])
            ->withOptions([
                'stream' => true,
                'verify' => (bool) config('editor.proxy.verify_ssl', true),
                'allow_redirects' => [
                    'max' => (int) config('editor.proxy.max_redirects', 5),
                    'strict' => true,
                    'referer' => false,
                    'protocols' => ['http', 'https'],
                    // Every hop is re-checked. Without this, a public host can
                    // 302 the fetch straight at a private address and the
                    // initial validation counts for nothing.
                    'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) use ($guard): void {
                        unset($request, $response);
                        $guard->validateRedirect((string) $uri);
                    },
                ],
            ])
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('Upstream returned HTTP '.$response->status());
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        if ($contentType !== '' && ! str_contains($contentType, 'html') && ! str_contains($contentType, 'xml')) {
            throw new \RuntimeException('That URL is not an HTML page.');
        }

        // Read with a hard ceiling rather than trusting Content-Length, which
        // is absent on chunked responses and can simply lie.
        $body = $response->toPsrResponse()->getBody();
        $html = '';

        while (! $body->eof() && strlen($html) < $maxBytes) {
            $chunk = $body->read(65536);
            if ($chunk === '') {
                break;
            }
            $html .= $chunk;
        }

        $body->close();

        $charset = null;
        if (preg_match('/charset=\s*"?([a-z0-9_\-]+)/i', $contentType, $matches) === 1) {
            $charset = $matches[1];
        }

        return [substr($html, 0, $maxBytes), $charset];
    }
}
