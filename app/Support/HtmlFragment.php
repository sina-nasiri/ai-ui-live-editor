<?php

namespace App\Support;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Cleans a fragment of HTML before it is injected into the preview.
 *
 * The model is instructed not to emit scripts, but "the model was told not
 * to" is not a security control. Anything on its way into a same-origin
 * document gets stripped here regardless of who produced it.
 */
class HtmlFragment
{
    private const DROP_TAGS = ['script', 'noscript', 'iframe', 'frame', 'object', 'embed', 'applet', 'base', 'link', 'meta'];

    public static function sanitize(string $html): string
    {
        $html = trim($html);

        if ($html === '') {
            return '';
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        // The Content-Type meta makes libxml parse *and* serialise UTF-8; the
        // xml declaration alternative parses correctly but writes every
        // non-ASCII character back out as a numeric entity.
        $document->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'
            .'<div id="__fragment__">'.$html.'</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);

        foreach (self::DROP_TAGS as $tag) {
            foreach (self::collect($xpath, '//'.$tag) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach (self::collect($xpath, '//*') as $element) {
            /** @var list<string> $remove */
            $remove = [];

            foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
                if (! $attribute instanceof DOMAttr) {
                    continue;
                }

                $name = strtolower($attribute->name);

                if (str_starts_with($name, 'on')) {
                    $remove[] = $attribute->name;

                    continue;
                }

                if (in_array($name, ['src', 'href', 'action', 'formaction', 'xlink:href'], true)
                    && preg_match('/^\s*(javascript|vbscript|data:text\/html)/i', $attribute->value) === 1) {
                    $remove[] = $attribute->name;
                }
            }

            foreach ($remove as $name) {
                $element->removeAttribute($name);
            }
        }

        $wrapper = $document->getElementById('__fragment__');

        if (! $wrapper instanceof DOMElement) {
            return '';
        }

        $inner = '';
        foreach ($wrapper->childNodes as $child) {
            $inner .= $document->saveHTML($child);
        }

        return trim($inner);
    }

    /**
     * @return list<DOMElement>
     */
    private static function collect(DOMXPath $xpath, string $query): array
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
}
