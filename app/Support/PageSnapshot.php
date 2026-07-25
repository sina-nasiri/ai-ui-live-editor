<?php

namespace App\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Turns a fetched page into a static, script-free snapshot the editor can work on.
 *
 * Removing scripts is a security requirement, not a nicety. The preview iframe
 * is same-origin with the app (it has to be, or the editor could not read the
 * DOM), so any script from the loaded site would run with access to this
 * origin's storage. Stripping them also happens to make the editor far more
 * reliable: no SPA re-hydration wiping edits, no cookie banners reappearing,
 * no site JavaScript competing for clicks.
 */
class PageSnapshot
{
    /** Elements removed outright. */
    private const DROP_TAGS = ['script', 'noscript', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'base'];

    /** Attributes carrying URLs that need rewriting to absolute form. */
    private const URL_ATTRIBUTES = ['src', 'href', 'poster', 'data-src', 'data-original', 'data-lazy-src'];

    /** Attributes holding a comma-separated candidate list. */
    private const SRCSET_ATTRIBUTES = ['srcset', 'data-srcset', 'imagesrcset'];

    public function __construct(
        private readonly string $baseUrl,
        /** Charset from the HTTP Content-Type header, if the server sent one. */
        private readonly ?string $charsetHint = null,
        /**
         * URL template for routing sub-resources back through this app, with
         * a single %s for the target URL. Null leaves them pointing at origin.
         */
        private readonly ?string $assetProxy = null,
    ) {}

    /**
     * Route a sub-resource through this app so the browser sees it as
     * same-origin.
     *
     * This matters more than it looks. A cross-origin stylesheet is readable
     * by the browser but *not* by script: `sheet.cssRules` throws. That single
     * restriction blocks reading the site's CSS custom properties, finding its
     * `@font-face` sources, and capturing an element to PNG without tainting
     * the canvas. Proxying stylesheets removes all three limits at once — and
     * incidentally renders pages whose CDN refuses our server's request.
     */
    private function proxied(string $url): string
    {
        if ($this->assetProxy === null || $url === '' || str_starts_with($url, 'data:')) {
            return $url;
        }

        return sprintf($this->assetProxy, rawurlencode($url));
    }

    public function build(string $html): string
    {
        if (trim($html) === '') {
            return $this->wrap('<p>The page returned an empty document.</p>');
        }

        $html = $this->toUtf8($html);
        $document = $this->parse($html);
        $xpath = new DOMXPath($document);
        $resolver = new UrlResolver($this->baseUrl);

        $this->dropUnsafeElements($document, $xpath);
        $this->dropEventHandlers($xpath);
        $this->rewriteUrls($xpath, $resolver);
        $this->rewriteStyles($xpath, $resolver);
        $this->neutraliseNavigation($xpath);
        $this->routeStylesheets($xpath, $resolver);
        $this->injectHead($document, $xpath);

        $output = $document->saveHTML();

        if ($output === false) {
            return $this->wrap('<p>The page could not be parsed.</p>');
        }

        // libxml rewrites the doctype to HTML 4.01. Forcing the HTML5 doctype
        // keeps the preview in the same rendering mode as the real site —
        // otherwise layouts subtly shift and the edits you make are wrong.
        $output = preg_replace('/^\s*<!DOCTYPE[^>]*>\s*/i', '', $output, 1) ?? $output;

        return "<!DOCTYPE html>\n".ltrim($output);
    }

    /**
     * Normalise the document to UTF-8 before it reaches the parser.
     *
     * Plenty of live sites are still served as ISO-8859-1 or Windows-1252.
     * Handing those bytes to a UTF-8 parser turns every accented character
     * into a replacement glyph, which then gets baked into the export.
     */
    private function toUtf8(string $html): string
    {
        $charset = $this->charsetHint ?: $this->sniffCharset($html);

        if ($charset === null || $charset === '') {
            return $html;
        }

        $charset = strtoupper($charset);

        if ($charset === 'UTF-8' || $charset === 'UTF8') {
            return $html;
        }

        if (! in_array($charset, array_map('strtoupper', mb_list_encodings()), true)) {
            return $html;
        }

        $converted = @mb_convert_encoding($html, 'UTF-8', $charset);

        return is_string($converted) && $converted !== '' ? $converted : $html;
    }

    private function sniffCharset(string $html): ?string
    {
        // Per the HTML spec the declaration must appear early; looking further
        // risks picking up a charset mentioned in body copy.
        $head = substr($html, 0, 4096);

        if (preg_match('/<meta[^>]+charset\s*=\s*["\']?\s*([a-z0-9_\-]+)/i', $head, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function parse(string $html): DOMDocument
    {
        $document = new DOMDocument;
        $document->preserveWhiteSpace = true;
        $document->formatOutput = false;

        $previous = libxml_use_internal_errors(true);

        // This meta prefix is what makes libxml both parse *and* serialise as
        // UTF-8. Without it saveHTML() escapes every non-ASCII character into
        // a numeric entity, which survives but reads terribly in the export.
        $document->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html,
            LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private function dropUnsafeElements(DOMDocument $document, DOMXPath $xpath): void
    {
        $query = implode('|', array_map(static fn (string $tag): string => '//'.$tag, self::DROP_TAGS));

        foreach ($this->collect($xpath, $query) as $node) {
            $node->parentNode?->removeChild($node);
        }

        // Meta refresh would navigate the preview away; CSP would block the
        // editor's own injected stylesheet.
        foreach ($this->collect($xpath, '//meta[@http-equiv]') as $meta) {
            $value = strtolower($meta->getAttribute('http-equiv'));
            if (in_array($value, ['refresh', 'content-security-policy', 'content-security-policy-report-only'], true)) {
                $meta->parentNode?->removeChild($meta);
            }
        }

        // Preloads and prefetches for scripts or documents are pure noise now.
        foreach ($this->collect($xpath, '//link[@rel]') as $link) {
            $rel = strtolower($link->getAttribute('rel'));
            $as = strtolower($link->getAttribute('as'));
            if (in_array($rel, ['preload', 'prefetch', 'modulepreload', 'prerender'], true) && $as !== 'style' && $as !== 'font') {
                $link->parentNode?->removeChild($link);
            }
        }

        unset($document);
    }

    private function dropEventHandlers(DOMXPath $xpath): void
    {
        foreach ($this->collect($xpath, '//*') as $element) {
            /** @var list<string> $remove */
            $remove = [];

            foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
                if (! $attribute instanceof DOMAttr) {
                    continue;
                }

                $name = strtolower($attribute->name);

                // Inline handlers are script by another name.
                if (str_starts_with($name, 'on')) {
                    $remove[] = $attribute->name;

                    continue;
                }

                // Subresource integrity hashes no longer match once URLs are
                // rewritten, which would block the stylesheet from loading.
                if ($name === 'integrity' || $name === 'nonce') {
                    $remove[] = $attribute->name;

                    continue;
                }

                if (in_array($name, self::URL_ATTRIBUTES, true)
                    && preg_match('/^\s*javascript:/i', $attribute->value) === 1) {
                    $remove[] = $attribute->name;
                }
            }

            foreach ($remove as $name) {
                $element->removeAttribute($name);
            }
        }
    }

    private function rewriteUrls(DOMXPath $xpath, UrlResolver $resolver): void
    {
        foreach (self::URL_ATTRIBUTES as $attribute) {
            foreach ($this->collect($xpath, sprintf('//*[@%s]', $attribute)) as $element) {
                $value = $element->getAttribute($attribute);
                if ($value !== '') {
                    $element->setAttribute($attribute, $resolver->resolve($value));
                }
            }
        }

        foreach (self::SRCSET_ATTRIBUTES as $attribute) {
            foreach ($this->collect($xpath, sprintf('//*[@%s]', $attribute)) as $element) {
                $value = $element->getAttribute($attribute);
                if ($value !== '') {
                    $element->setAttribute($attribute, $this->rewriteSrcset($value, $resolver));
                }
            }
        }
    }

    /**
     * `srcset` is a comma-separated list of "url descriptor" pairs. Missing it
     * is why responsive images break on most naive proxies.
     */
    private function rewriteSrcset(string $value, UrlResolver $resolver): string
    {
        $candidates = preg_split('/\s*,\s*/', trim($value)) ?: [];

        $rewritten = array_map(static function (string $candidate) use ($resolver): string {
            $parts = preg_split('/\s+/', trim($candidate), 2) ?: [];
            if ($parts === [] || $parts[0] === '') {
                return $candidate;
            }

            $url = $resolver->resolve($parts[0]);

            return isset($parts[1]) ? $url.' '.$parts[1] : $url;
        }, $candidates);

        return implode(', ', $rewritten);
    }

    private function rewriteStyles(DOMXPath $xpath, UrlResolver $resolver): void
    {
        foreach ($this->collect($xpath, '//style') as $style) {
            $style->textContent = $this->rewriteCssUrls($style->textContent, $resolver);
        }

        foreach ($this->collect($xpath, '//*[@style]') as $element) {
            $element->setAttribute('style', $this->rewriteCssUrls($element->getAttribute('style'), $resolver));
        }
    }

    private function rewriteCssUrls(string $css, UrlResolver $resolver): string
    {
        $css = preg_replace_callback(
            '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
            static function (array $matches) use ($resolver): string {
                $quote = $matches[1];
                $resolved = $resolver->resolve($matches[2]);

                return 'url('.$quote.$resolved.$quote.')';
            },
            $css
        ) ?? $css;

        return preg_replace_callback(
            '/@import\s+([\'"])([^\'"]+)\1/i',
            static fn (array $matches): string => '@import '.$matches[1].$resolver->resolve($matches[2]).$matches[1],
            $css
        ) ?? $css;
    }

    /**
     * Keep clicks inside the preview from navigating away mid-edit.
     */
    private function neutraliseNavigation(DOMXPath $xpath): void
    {
        foreach ($this->collect($xpath, '//a[@href]') as $anchor) {
            $anchor->setAttribute('data-editor-href', $anchor->getAttribute('href'));
            $anchor->removeAttribute('href');
        }

        foreach ($this->collect($xpath, '//form') as $form) {
            $form->removeAttribute('action');
            $form->setAttribute('onsubmit', 'return false');
        }
    }

    private function routeStylesheets(DOMXPath $xpath, UrlResolver $resolver): void
    {
        if ($this->assetProxy === null) {
            return;
        }

        foreach ($this->collect($xpath, '//link[@rel]') as $link) {
            if (! str_contains(strtolower($link->getAttribute('rel')), 'stylesheet')) {
                continue;
            }

            $href = $link->getAttribute('href');
            if ($href !== '') {
                $link->setAttribute('href', $this->proxied($resolver->resolve($href)));
            }
        }

        // An @import inside an inline <style> pulls in a second sheet that
        // would be cross-origin all over again.
        foreach ($this->collect($xpath, '//style') as $style) {
            $style->textContent = preg_replace_callback(
                '/@import\s+(?:url\(\s*)?([\'"]?)(https?:\/\/[^\'")]+)\1\s*\)?/i',
                fn (array $m): string => '@import url("'.$this->proxied($m[2]).'")',
                $style->textContent
            ) ?? $style->textContent;
        }
    }

    private function injectHead(DOMDocument $document, DOMXPath $xpath): void
    {
        $head = $xpath->query('//head')?->item(0);

        if (! $head instanceof DOMElement) {
            $head = $document->createElement('head');
            $html = $xpath->query('//html')?->item(0);
            if ($html instanceof DOMElement) {
                $html->insertBefore($head, $html->firstChild);
            } else {
                $document->appendChild($head);
            }
        }

        // The parser's own Content-Type meta is already in <head> and already
        // says UTF-8, so a second charset declaration would only add noise.
        $base = $document->createElement('base');
        $base->setAttribute('href', $this->baseUrl);
        $head->insertBefore($base, $head->firstChild);

        // Empty stylesheets the editor fills in at runtime. Declaring them
        // here puts them last in source order, after the site's own CSS,
        // which settles ties between equally specific rules.
        foreach (['editor-chrome', 'editor-patches'] as $id) {
            $style = $document->createElement('style');
            $style->setAttribute('id', $id);
            $head->appendChild($style);
        }
    }

    /**
     * DOMNodeList is live: removing nodes while iterating it skips entries.
     *
     * @return list<DOMElement>
     */
    private function collect(DOMXPath $xpath, string $query): array
    {
        $nodes = $xpath->query($query);

        if ($nodes === false) {
            return [];
        }

        $result = [];
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                $result[] = $node;
            }
        }

        return $result;
    }

    private function wrap(string $body): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            .'<style id="editor-chrome"></style><style id="editor-patches"></style>'
            .'</head><body>'.$body.'</body></html>';
    }
}
