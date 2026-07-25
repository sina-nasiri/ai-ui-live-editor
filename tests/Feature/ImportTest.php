<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pasted markup is the path that works where the proxy cannot go: localhost,
 * staging behind a login, a page you are signed into.
 */
class ImportTest extends TestCase
{
    public function test_pasted_markup_becomes_a_snapshot(): void
    {
        Http::preventStrayRequests();

        $response = $this->post('/import', [
            'html' => '<section class="hero"><h1>Hello</h1></section>',
        ]);

        $response->assertOk();
        $body = $response->getContent();

        $this->assertStringContainsString('Hello', $body);
        $this->assertStringContainsString('id="editor-patches"', $body);
    }

    public function test_pasted_markup_is_still_stripped_of_scripts(): void
    {
        Http::preventStrayRequests();

        // Markup the user pasted is no more trustworthy than markup we
        // fetched — it may well have come from a site in the first place.
        $body = $this->post('/import', [
            'html' => '<div onclick="x()">Hi<script>evil()</script></div>',
        ])->assertOk()->getContent();

        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('onclick', $body);
        $this->assertStringContainsString('Hi', $body);
    }

    public function test_an_uploaded_file_is_accepted(): void
    {
        Http::preventStrayRequests();

        $file = UploadedFile::fake()->createWithContent(
            'page.html',
            '<html><body><h1>From a file</h1></body></html>'
        );

        $body = $this->post('/import', ['file' => $file])->assertOk()->getContent();

        $this->assertStringContainsString('From a file', $body);
    }

    public function test_an_empty_paste_is_rejected(): void
    {
        $this->postJson('/import', ['html' => '   '])->assertStatus(422);
    }

    public function test_something_must_be_supplied(): void
    {
        $this->postJson('/import', [])->assertStatus(422);
    }

    /**
     * The base URL is used to resolve relative paths, which means it is
     * fetched from later — so it goes through the same guard as everything else.
     */
    public function test_a_private_base_url_is_refused(): void
    {
        Http::preventStrayRequests();

        $this->postJson('/import', [
            'html' => '<p>Hi</p>',
            'base' => 'http://169.254.169.254/',
        ])->assertStatus(422);
    }

    public function test_a_base_url_makes_relative_paths_absolute(): void
    {
        Http::preventStrayRequests();

        $body = $this->post('/import', [
            'html' => '<img src="/a.png">',
            'base' => 'https://example.com/x/y',
        ])->assertOk()->getContent();

        $this->assertStringContainsString('https://example.com/a.png', $body);
    }
}
