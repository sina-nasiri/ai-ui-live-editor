<?php

namespace App\Support;

/**
 * Resolves a possibly-relative URL against a base, per RFC 3986.
 *
 * A `<base href>` tag handles this for the browser, but the proxy also needs
 * it in PHP so `srcset`, inline `style="background:url(...)"`, and the
 * contents of `<style>` blocks come out absolute — none of which a base tag
 * reliably fixes once the document is served from a blob URL.
 */
class UrlResolver
{
    public function __construct(private readonly string $base) {}

    public function resolve(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return $url;
        }

        // Leave anything that is not a network reference alone.
        if (str_starts_with($url, '#')
            || str_starts_with($url, 'data:')
            || str_starts_with($url, 'blob:')
            || str_starts_with($url, 'about:')
            || preg_match('/^(mailto|tel|sms|javascript):/i', $url) === 1) {
            return $url;
        }

        $baseParts = parse_url($this->base);
        if ($baseParts === false || ! isset($baseParts['scheme'], $baseParts['host'])) {
            return $url;
        }

        $scheme = $baseParts['scheme'];
        $authority = $baseParts['host'].(isset($baseParts['port']) ? ':'.$baseParts['port'] : '');

        // Protocol-relative: //cdn.example.com/a.png
        if (str_starts_with($url, '//')) {
            return $scheme.':'.$url;
        }

        // Already absolute.
        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url) === 1) {
            return $url;
        }

        // Root-relative: /assets/a.png
        if (str_starts_with($url, '/')) {
            return $scheme.'://'.$authority.$this->normalisePath($url);
        }

        // Path-relative: ../a.png
        $basePath = $baseParts['path'] ?? '/';
        $directory = substr($basePath, 0, strrpos($basePath, '/') + 1);
        if ($directory === '') {
            $directory = '/';
        }

        return $scheme.'://'.$authority.$this->normalisePath($directory.$url);
    }

    /**
     * Collapse `.` and `..` segments so the result is a clean absolute path.
     */
    private function normalisePath(string $path): string
    {
        $query = '';
        $hashPosition = strpos($path, '#');
        if ($hashPosition !== false) {
            $query = substr($path, $hashPosition);
            $path = substr($path, 0, $hashPosition);
        }

        $queryPosition = strpos($path, '?');
        if ($queryPosition !== false) {
            $query = substr($path, $queryPosition).$query;
            $path = substr($path, 0, $queryPosition);
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        $normalised = '/'.implode('/', $segments);

        // Preserve a trailing slash from the original path.
        if (str_ends_with($path, '/') && ! str_ends_with($normalised, '/')) {
            $normalised .= '/';
        }

        return $normalised.$query;
    }
}
