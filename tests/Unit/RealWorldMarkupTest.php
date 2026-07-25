<?php

namespace Tests\Unit;

use App\Support\PageSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * The snapshot pipeline against markup shaped like the real web.
 *
 * The rest of the suite uses tidy fixtures. Real pages are not tidy: they have
 * unclosed tags, a `<base>` that changes what every relative URL means, lazy
 * loading via data attributes, `<picture>` sources, protocol-relative CDN
 * links, inline SVG, `<template>` content, and a stray `<script>` somewhere
 * unhelpful. Each case here is a pattern that broke a naive proxy in practice.
 */
class RealWorldMarkupTest extends TestCase
{
    private function build(string $html, string $base = 'https://shop.example.com/products/hat'): string
    {
        return (new PageSnapshot($base, null, 'https://app.test/asset?u=%s'))->build($html);
    }

    /**
     * A `<base href>` re-points every relative URL on the page, including
     * root-relative ones. A browser honours it; so must we, or every image on
     * an Angular-style app points at the wrong host.
     */
    public function test_the_pages_own_base_tag_is_honoured(): void
    {
        $output = $this->build(
            '<html><head><base href="https://cdn.example.com/v2/"></head>'
            .'<body><img src="a.png"><img src="/root.png"></body></html>'
        );

        $this->assertSame(1, substr_count($output, '<base'), 'exactly one base tag should survive');
        $this->assertStringContainsString('https://cdn.example.com/v2/a.png', $output);
        $this->assertStringContainsString('https://cdn.example.com/root.png', $output);
        $this->assertStringNotContainsString('shop.example.com/products/a.png', $output);
    }

    public function test_a_relative_base_tag_is_resolved_against_the_fetch_address(): void
    {
        $output = $this->build('<html><head><base href="/assets/"></head><body><img src="a.png"></body></html>');

        $this->assertStringContainsString('https://shop.example.com/assets/a.png', $output);
    }

    public function test_without_a_base_tag_the_fetch_address_is_used(): void
    {
        $output = $this->build('<html><head></head><body><img src="a.png"></body></html>');

        $this->assertStringContainsString('https://shop.example.com/products/a.png', $output);
    }

    public function test_picture_sources_are_resolved(): void
    {
        $output = $this->build(
            '<picture><source srcset="/img/a.avif 1x, /img/a@2x.avif 2x" type="image/avif">'
            .'<img src="/img/a.jpg" alt="Hat"></picture>'
        );

        $this->assertStringContainsString('https://shop.example.com/img/a.avif 1x', $output);
        $this->assertStringContainsString('https://shop.example.com/img/a@2x.avif 2x', $output);
        $this->assertStringContainsString('https://shop.example.com/img/a.jpg', $output);
    }

    /**
     * Lazy loaders put the real URL in a data attribute and a placeholder in
     * src. Miss those and the page is a wall of grey boxes.
     */
    public function test_lazy_loading_attributes_are_resolved(): void
    {
        $output = $this->build('<img src="/spacer.gif" data-src="/img/real.jpg" data-srcset="/img/real.jpg 1x">');

        $this->assertStringContainsString('https://shop.example.com/img/real.jpg', $output);
    }

    public function test_protocol_relative_cdn_urls_get_a_scheme(): void
    {
        $output = $this->build('<html><head><link rel="stylesheet" href="//cdn.example.com/app.css"></head><body></body></html>');

        // Routed through the relay, and https rather than scheme-relative.
        $this->assertStringContainsString('asset?u='.urlencode('https://cdn.example.com/app.css'), $output);
    }

    public function test_unclosed_and_malformed_tags_do_not_lose_content(): void
    {
        $output = $this->build('<div><p>First<p>Second<span>Third</div><li>Loose item');

        foreach (['First', 'Second', 'Third', 'Loose item'] as $text) {
            $this->assertStringContainsString($text, $output);
        }
    }

    public function test_inline_svg_survives(): void
    {
        $output = $this->build('<svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>');

        $this->assertStringContainsString('<svg', $output);
        $this->assertStringContainsString('circle', $output);
    }

    /**
     * An SVG `<use>` can reference an external document, and `onload` works
     * inside SVG exactly as it does in HTML.
     */
    public function test_event_handlers_inside_svg_are_removed(): void
    {
        $output = $this->build('<svg onload="steal()"><rect onclick="x()" width="10" height="10"/></svg>');

        $this->assertStringNotContainsString('onload', $output);
        $this->assertStringNotContainsString('onclick', $output);
    }

    /**
     * `<template>` content is inert markup, but a script inside one becomes
     * live the moment anything clones it.
     */
    public function test_scripts_inside_templates_are_removed(): void
    {
        $output = $this->build('<template id="row"><div>Row</div><script>evil()</script></template>');

        $this->assertStringNotContainsString('<script', $output);
    }

    public function test_json_ld_and_other_script_types_are_all_removed(): void
    {
        $output = $this->build(
            '<script type="application/ld+json">{"@type":"Product"}</script>'
            .'<script type="module">import "./x.js"</script>'
            .'<p>Body</p>'
        );

        $this->assertStringNotContainsString('<script', $output);
        $this->assertStringContainsString('Body', $output);
    }

    public function test_a_stylesheet_with_media_and_crossorigin_still_routes_through_the_relay(): void
    {
        $output = $this->build(
            '<link rel="stylesheet" href="/print.css" media="print" crossorigin="anonymous" integrity="sha384-x">'
        );

        $this->assertStringContainsString('asset?u=', $output);
        $this->assertStringNotContainsString('integrity', $output);
        // media must survive — dropping it would apply print styles on screen.
        $this->assertStringContainsString('media="print"', $output);
    }

    public function test_preconnect_and_dns_prefetch_links_are_left_alone(): void
    {
        $output = $this->build('<link rel="preconnect" href="https://fonts.gstatic.com"><p>Hi</p>');

        // Harmless, and stripping them is not our business.
        $this->assertStringContainsString('preconnect', $output);
    }

    public function test_font_preloads_survive_but_script_preloads_do_not(): void
    {
        $output = $this->build(
            '<link rel="preload" as="font" href="/f.woff2">'
            .'<link rel="preload" as="script" href="/app.js">'
        );

        $this->assertStringContainsString('f.woff2', $output);
        $this->assertStringNotContainsString('app.js', $output);
    }

    public function test_query_strings_and_hashes_survive_url_rewriting(): void
    {
        $output = $this->build('<img src="/img/a.jpg?v=3&amp;w=800"><a href="/x#section">x</a>');

        $this->assertStringContainsString('https://shop.example.com/img/a.jpg?v=3', $output);
        $this->assertStringContainsString('https://shop.example.com/x#section', $output);
    }

    public function test_deeply_nested_markup_does_not_blow_up(): void
    {
        $html = str_repeat('<div>', 200).'Deep'.str_repeat('</div>', 200);

        $output = $this->build($html);

        $this->assertStringContainsString('Deep', $output);
    }

    public function test_a_form_cannot_submit_out_of_the_preview(): void
    {
        $output = $this->build('<form action="/checkout" method="post"><input name="q"><button>Go</button></form>');

        $this->assertStringNotContainsString('action="/checkout"', $output);
        $this->assertStringContainsString('onsubmit', $output);
    }

    public function test_css_custom_properties_and_media_queries_survive(): void
    {
        $output = $this->build(
            '<style>:root{--brand:#c0ffee}@media (min-width:40em){.a{color:var(--brand)}}</style>'
        );

        $this->assertStringContainsString('--brand:#c0ffee', $output);
        $this->assertStringContainsString('@media', $output);
    }

    public function test_a_page_that_is_only_a_fragment_still_produces_a_document(): void
    {
        $output = $this->build('<p>Just a paragraph</p>');

        $this->assertStringContainsString('<body>', $output);
        $this->assertStringContainsString('Just a paragraph', $output);
        $this->assertStringContainsString('id="editor-patches"', $output);
    }

    /**
     * Entities must not be double-escaped on the way through, or copy fills
     * up with visible &amp;amp; sequences.
     */
    public function test_entities_are_not_double_escaped(): void
    {
        $output = $this->build('<p>Tom &amp; Jerry &mdash; &quot;quoted&quot;</p>');

        $this->assertStringNotContainsString('&amp;amp;', $output);
        $this->assertStringContainsString('Tom &amp; Jerry', $output);
    }

    public function test_a_meta_refresh_cannot_navigate_the_preview_away(): void
    {
        $output = $this->build('<meta http-equiv="refresh" content="0;url=https://elsewhere.test/"><p>Hi</p>');

        $this->assertStringNotContainsString('refresh', $output);
    }

    /**
     * A realistic page pulls all of these at once; the combination is where
     * ordering bugs between the rewrite passes show up.
     */
    public function test_a_composite_page_comes_through_intact(): void
    {
        $output = $this->build(<<<'HTML'
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <base href="https://cdn.example.com/">
                <link rel="stylesheet" href="//cdn.example.com/app.css" integrity="sha384-z">
                <style>.hero{background:url('../img/hero.jpg')}@import "extra.css";</style>
                <script src="/analytics.js"></script>
            </head>
            <body class="page">
                <header><a href="/"><img src="/logo.svg" alt="Shop"></a></header>
                <main>
                    <h1 style="background:url(/img/underline.png)">Hats</h1>
                    <img src="/spacer.gif" data-src="/img/hat.jpg" srcset="/img/hat.jpg 1x, /img/hat@2x.jpg 2x" alt="A hat">
                    <form action="/search"><input name="q" placeholder="Search"></form>
                </main>
                <script>window.dataLayer=[]</script>
            </body>
            </html>
            HTML);

        // Nothing executable survives.
        $this->assertStringNotContainsString('<script', $output);
        $this->assertStringNotContainsString('analytics.js', $output);
        $this->assertStringNotContainsString('dataLayer', $output);

        // Everything visual does — and because this page declares
        // <base href="https://cdn.example.com/">, every reference resolves
        // against the CDN rather than the address we fetched. That has to
        // hold across all four rewrite passes: attributes, srcset, inline
        // style, and the contents of <style>.
        $this->assertStringContainsString('Hats', $output);
        $this->assertStringContainsString('https://cdn.example.com/img/hat.jpg 1x', $output);
        $this->assertStringContainsString('https://cdn.example.com/img/hat@2x.jpg 2x', $output);
        $this->assertStringContainsString('https://cdn.example.com/img/hero.jpg', $output);
        $this->assertStringContainsString('https://cdn.example.com/img/underline.png', $output);
        $this->assertStringContainsString('https://cdn.example.com/logo.svg', $output);
        $this->assertStringNotContainsString('shop.example.com', $output);
        $this->assertStringContainsString('asset?u=', $output);
        $this->assertStringContainsString('id="editor-patches"', $output);
    }
}
