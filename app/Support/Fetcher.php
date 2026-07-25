<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

/**
 * The single outbound-request path.
 *
 * Pages, stylesheets, images and fonts all leave through here so the SSRF
 * guard, the size ceiling, the redirect cap and TLS verification are applied
 * once and cannot be forgotten by a new caller.
 */
class Fetcher
{
    public function __construct(private readonly UrlGuard $guard) {}

    public static function make(): self
    {
        return new self(app(UrlGuard::class));
    }

    /**
     * @return array{body:string,content_type:string,charset:?string}
     *
     * @throws BlockedUrlException|RuntimeException
     */
    public function get(string $url, ?int $maxBytes = null, string $accept = '*/*'): array
    {
        $url = $this->guard->validate($url);
        $maxBytes ??= (int) config('editor.proxy.max_bytes', 5 * 1024 * 1024);

        $response = Http::timeout((int) config('editor.proxy.timeout', 20))
            ->withHeaders([
                'User-Agent' => (string) config('editor.proxy.user_agent'),
                'Accept' => $accept,
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
                    // Re-checked on every hop: the initial validation is
                    // worthless if a public host can 302 into the LAN.
                    'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri): void {
                        unset($request, $response);
                        $this->guard->validateRedirect((string) $uri);
                    },
                ],
            ])
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('Upstream returned HTTP '.$response->status());
        }

        $contentType = strtolower((string) $response->header('Content-Type'));

        // Read with a hard ceiling rather than trusting Content-Length, which
        // is absent on chunked responses and can simply lie.
        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        while (! $stream->eof() && strlen($body) < $maxBytes) {
            $chunk = $stream->read(65536);
            if ($chunk === '') {
                break;
            }
            $body .= $chunk;
        }

        $stream->close();

        $charset = null;
        if (preg_match('/charset=\s*"?([a-z0-9_\-]+)/i', $contentType, $matches) === 1) {
            $charset = $matches[1];
        }

        return [
            'body' => substr($body, 0, $maxBytes),
            'content_type' => trim(explode(';', $contentType)[0]),
            'charset' => $charset,
        ];
    }
}
