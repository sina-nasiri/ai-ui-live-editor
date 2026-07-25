<?php

namespace Tests\Unit;

use App\Support\PageSnapshot;
use PHPUnit\Framework\TestCase;

class PageSnapshotTest extends TestCase
{
    private function build(string $html, string $base = 'https://example.com/blog/post'): string
    {
        return (new PageSnapshot($base))->build($html);
    }

    /**
     * The preview iframe is same-origin with the app. If a script survived
     * this step it would run with access to the app's local storage — which
     * is exactly where a browser-supplied API key lives.
     */
    public function test_scripts_are_removed(): void
    {
        $output = $this->build('<html><body><p>Hi</p><script>fetch("//evil")</script></body></html>');

        $this->assertStringNotContainsString('<script', $output);
        $this->assertStringNotContainsString('evil', $output);
        $this->assertStringContainsString('Hi', $output);
    }

    public function test_inline_event_handlers_are_removed(): void
    {
        $output = $this->build('<html><body><div onclick="steal()" onmouseover="x()">Hi</div></body></html>');

        $this->assertStringNotContainsString('onclick', $output);
        $this->assertStringNotContainsString('onmouseover', $output);
    }

    public function test_javascript_urls_are_removed(): void
    {
        $output = $this->build('<html><body><a href="javascript:alert(1)">x</a></body></html>');

        $this->assertStringNotContainsString('javascript:', $output);
    }

    public function test_nested_frames_are_removed(): void
    {
        $output = $this->build('<html><body><iframe src="https://evil.test"></iframe><p>Hi</p></body></html>');

        $this->assertStringNotContainsString('<iframe', $output);
    }

    public function test_relative_urls_become_absolute(): void
    {
        $output = $this->build('<html><body><img src="../img/a.png"><img src="/b.png"></body></html>');

        $this->assertStringContainsString('https://example.com/img/a.png', $output);
        $this->assertStringContainsString('https://example.com/b.png', $output);
    }

    /**
     * Responsive images are why most naive proxies show a page full of broken
     * placeholders — srcset is a comma-separated list, not a plain URL.
     */
    public function test_srcset_candidates_are_each_resolved(): void
    {
        $output = $this->build('<html><body><img srcset="/a.png 1x, /b.png 2x"></body></html>');

        $this->assertStringContainsString('https://example.com/a.png 1x', $output);
        $this->assertStringContainsString('https://example.com/b.png 2x', $output);
    }

    public function test_css_url_references_are_resolved(): void
    {
        $output = $this->build(
            '<html><head><style>.a{background:url(/bg.png)}</style></head>'
            .'<body><div style="background:url(\'../hero.jpg\')"></div></body></html>'
        );

        $this->assertStringContainsString('url(https://example.com/bg.png)', $output);
        $this->assertStringContainsString('https://example.com/hero.jpg', $output);
    }

    /**
     * Rewriting a stylesheet's URL invalidates its integrity hash, and the
     * browser would then refuse to apply it — leaving an unstyled page.
     */
    public function test_integrity_attributes_are_dropped(): void
    {
        $output = $this->build('<html><head><link rel="stylesheet" href="/a.css" integrity="sha384-abc"></head><body></body></html>');

        $this->assertStringNotContainsString('integrity', $output);
        $this->assertStringContainsString('https://example.com/a.css', $output);
    }

    public function test_content_security_policy_meta_is_dropped(): void
    {
        $output = $this->build('<html><head><meta http-equiv="Content-Security-Policy" content="default-src none"></head><body></body></html>');

        $this->assertStringNotContainsString('Content-Security-Policy', $output);
    }

    public function test_links_are_parked_so_the_preview_cannot_navigate_away(): void
    {
        $output = $this->build('<html><body><a href="/next">Next</a></body></html>');

        $this->assertStringContainsString('data-editor-href="https://example.com/next"', $output);
        // The leading space matters: data-editor-href contains "href=" too.
        $this->assertStringNotContainsString(' href="https://example.com/next"', $output);
    }

    public function test_patch_stylesheets_are_present_for_the_editor_to_fill(): void
    {
        $output = $this->build('<html><head></head><body><p>Hi</p></body></html>');

        $this->assertStringContainsString('id="editor-patches"', $output);
        $this->assertStringContainsString('id="editor-chrome"', $output);
    }

    public function test_multibyte_text_survives_parsing(): void
    {
        $output = $this->build('<html><body><p>Grüße — 日本語 — café</p></body></html>');

        $this->assertStringContainsString('Grüße', $output);
        $this->assertStringContainsString('日本語', $output);
        $this->assertStringContainsString('café', $output);
    }

    /**
     * A good share of the live web is still served as Windows-1252. Parsing
     * those bytes as UTF-8 turns every accent into a replacement glyph, and
     * that damage then gets baked into the export.
     */
    public function test_legacy_encodings_are_converted_before_parsing(): void
    {
        $latin1 = mb_convert_encoding('<html><body><p>Café Münster</p></body></html>', 'Windows-1252', 'UTF-8');

        $output = (new PageSnapshot('https://example.com/', 'windows-1252'))->build($latin1);

        $this->assertStringContainsString('Café Münster', $output);
        $this->assertStringNotContainsString('�', $output);
    }

    public function test_the_charset_is_sniffed_from_the_markup_when_no_header_is_given(): void
    {
        $latin1 = mb_convert_encoding(
            '<html><head><meta charset="ISO-8859-1"></head><body><p>Café</p></body></html>',
            'Windows-1252',
            'UTF-8'
        );

        $output = (new PageSnapshot('https://example.com/'))->build($latin1);

        $this->assertStringContainsString('Café', $output);
    }

    public function test_the_html5_doctype_is_forced(): void
    {
        $output = $this->build('<html><body><p>Hi</p></body></html>');

        // libxml emits an HTML 4.01 doctype, which changes how the browser
        // lays the page out — the preview would not match the real site.
        $this->assertStringStartsWith('<!DOCTYPE html>', $output);
        $this->assertStringNotContainsString('W3C//DTD HTML 4.0', $output);
    }

    public function test_empty_input_produces_a_usable_document(): void
    {
        $output = $this->build('   ');

        $this->assertStringContainsString('<body>', $output);
    }
}
