<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The relay exists so third-party stylesheets and images arrive same-origin —
 * which is what makes `sheet.cssRules` readable and keeps a canvas capture
 * from being tainted. It is also, by construction, a thing that fetches URLs
 * on request, so it carries the same guards as the page proxy.
 */
class AssetRelayTest extends TestCase
{
    public function test_a_stylesheet_is_relayed(): void
    {
        Http::fake([
            'cdn.example.com/*' => Http::response('.a{color:red}', 200, ['Content-Type' => 'text/css']),
        ]);

        $response = $this->get('/asset?u='.urlencode('https://cdn.example.com/a.css'));

        $response->assertOk();
        // Laravel appends a charset to text/* responses.
        $this->assertStringStartsWith('text/css', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('color:red', $response->getContent());
    }

    public function test_relative_urls_inside_a_relayed_stylesheet_are_made_absolute(): void
    {
        Http::fake([
            'cdn.example.com/*' => Http::response(
                '.a{background:url(../img/bg.png)}',
                200,
                ['Content-Type' => 'text/css']
            ),
        ]);

        $body = $this->get('/asset?u='.urlencode('https://cdn.example.com/css/a.css'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('https://cdn.example.com/img/bg.png', $body);
    }

    public function test_a_nested_import_is_routed_back_through_the_relay(): void
    {
        Http::fake([
            'cdn.example.com/*' => Http::response(
                '@import url("more.css"); .a{color:red}',
                200,
                ['Content-Type' => 'text/css']
            ),
        ]);

        $body = $this->get('/asset?u='.urlencode('https://cdn.example.com/a.css'))
            ->assertOk()
            ->getContent();

        // Otherwise the imported sheet would be cross-origin all over again
        // and its rules unreadable.
        $this->assertStringContainsString('/asset?u=', $body);
        $this->assertStringContainsString(urlencode('https://cdn.example.com/more.css'), $body);
    }

    public function test_an_image_is_relayed(): void
    {
        Http::fake([
            'cdn.example.com/*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png']),
        ]);

        $this->get('/asset?u='.urlencode('https://cdn.example.com/a.png'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    /**
     * Relaying HTML would serve attacker-chosen markup from our own origin.
     */
    public function test_html_is_refused(): void
    {
        Http::fake([
            'evil.example.com/*' => Http::response('<script>x()</script>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->get('/asset?u='.urlencode('https://evil.example.com/x'))->assertStatus(415);
    }

    public function test_javascript_is_refused(): void
    {
        Http::fake([
            'evil.example.com/*' => Http::response('alert(1)', 200, ['Content-Type' => 'application/javascript']),
        ]);

        $this->get('/asset?u='.urlencode('https://evil.example.com/x.js'))->assertStatus(415);
    }

    public function test_the_relay_will_not_reach_the_private_network(): void
    {
        Http::preventStrayRequests();

        $this->get('/asset?u='.urlencode('http://169.254.169.254/latest/meta-data/'))->assertStatus(422);
        $this->get('/asset?u='.urlencode('http://127.0.0.1:6379/'))->assertStatus(422);
        $this->get('/asset?u='.urlencode('http://10.0.0.5/secret.css'))->assertStatus(422);
    }

    public function test_a_missing_url_is_a_bad_request(): void
    {
        $this->get('/asset')->assertStatus(400);
    }

    public function test_relayed_assets_are_marked_nosniff(): void
    {
        Http::fake([
            'cdn.example.com/*' => Http::response('svg', 200, ['Content-Type' => 'image/svg+xml']),
        ]);

        $this->get('/asset?u='.urlencode('https://cdn.example.com/a.svg'))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_snapshot_routes_stylesheets_through_the_relay(): void
    {
        Http::fake([
            'example.com' => Http::response(
                '<html><head><link rel="stylesheet" href="/a.css"></head><body><p>Hi</p></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $body = $this->postJson('/proxy', ['url' => 'https://example.com'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('/asset?u=', $body);
        $this->assertStringContainsString(urlencode('https://example.com/a.css'), $body);
    }
}
