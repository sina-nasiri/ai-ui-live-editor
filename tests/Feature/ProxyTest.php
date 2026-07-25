<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProxyTest extends TestCase
{
    public function test_the_editor_page_renders(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Live Editor', escape: false);
    }

    /**
     * The single most important test in the suite: an unauthenticated visitor
     * must not be able to make the server fetch its own cloud metadata.
     */
    public function test_cloud_metadata_addresses_are_refused(): void
    {
        Http::preventStrayRequests();

        $this->postJson('/proxy', ['url' => 'http://169.254.169.254/latest/meta-data/'])
            ->assertStatus(422)
            ->assertJsonStructure(['error']);
    }

    public function test_loopback_is_refused(): void
    {
        Http::preventStrayRequests();

        $this->postJson('/proxy', ['url' => 'http://127.0.0.1:6379/'])->assertStatus(422);
    }

    public function test_private_network_addresses_are_refused(): void
    {
        Http::preventStrayRequests();

        $this->postJson('/proxy', ['url' => 'http://10.0.0.1/admin'])->assertStatus(422);
        $this->postJson('/proxy', ['url' => 'http://192.168.1.1/'])->assertStatus(422);
    }

    public function test_non_http_schemes_are_refused(): void
    {
        Http::preventStrayRequests();

        $this->postJson('/proxy', ['url' => 'file:///etc/passwd'])->assertStatus(422);
    }

    public function test_a_url_is_required(): void
    {
        $this->postJson('/proxy', [])->assertStatus(422);
    }

    public function test_a_fetched_page_comes_back_as_a_script_free_snapshot(): void
    {
        Http::fake([
            'example.com/*' => Http::response(
                '<html><head><title>T</title></head><body><h1>Hello</h1><script>evil()</script></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $response = $this->postJson('/proxy', ['url' => 'https://example.com/']);

        $response->assertOk();
        $body = $response->getContent();

        $this->assertStringContainsString('Hello', $body);
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringContainsString('id="editor-patches"', $body);
    }

    public function test_non_html_responses_are_refused(): void
    {
        Http::fake([
            'example.com/*' => Http::response('{"a":1}', 200, ['Content-Type' => 'application/json']),
        ]);

        $this->postJson('/proxy', ['url' => 'https://example.com/api'])->assertStatus(502);
    }
}
