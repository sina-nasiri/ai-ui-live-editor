<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * One test per provider proves the abstraction actually holds: three very
 * different request shapes, one response shape for the editor.
 */
class AiEditTest extends TestCase
{
    private const OUTLINE = "[e1] <section class=\"hero\">\n  [e2] <h1> \"Hello\"";

    protected function setUp(): void
    {
        parent::setUp();

        // Force the browser-supplied-key path so the tests do not depend on
        // whatever happens to be in the developer's environment.
        config()->set('editor.providers.anthropic.key', null);
        config()->set('editor.providers.openai.key', null);
        config()->set('editor.providers.gemini.key', null);
    }

    public function test_claude_style_patches_are_applied(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode([
                        'summary' => 'Made the headline bigger.',
                        'changes' => [[
                            'id' => 'e2',
                            'declarations' => [['property' => 'font-size', 'value' => '3rem']],
                            'text' => '',
                        ]],
                    ]),
                ]],
            ]),
        ]);

        $this->postJson('/ai/edit', [
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'prompt' => 'Bigger headline',
            'outline' => self::OUTLINE,
        ])
            ->assertOk()
            ->assertJsonPath('changes.0.id', 'e2')
            ->assertJsonPath('changes.0.declarations.0.property', 'font-size');
    }

    public function test_openai_style_patches_are_applied(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'content' => json_encode([
                            'summary' => 'Tightened the tracking.',
                            'changes' => [[
                                'id' => 'e2',
                                'declarations' => [['property' => 'letter-spacing', 'value' => '-0.02em']],
                                'text' => '',
                            ]],
                        ]),
                    ],
                ]],
            ]),
        ]);

        $this->postJson('/ai/edit', [
            'provider' => 'openai',
            'api_key' => 'sk-test',
            'prompt' => 'Tighter tracking',
            'outline' => self::OUTLINE,
        ])
            ->assertOk()
            ->assertJsonPath('changes.0.declarations.0.value', '-0.02em');
    }

    public function test_gemini_style_patches_are_applied(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'finishReason' => 'STOP',
                    'content' => ['parts' => [[
                        'text' => json_encode([
                            'summary' => 'Warmed the background.',
                            'changes' => [[
                                'id' => 'e1',
                                'declarations' => [['property' => 'background-color', 'value' => '#fdf6ec']],
                                'text' => '',
                            ]],
                        ]),
                    ]]],
                ]],
            ]),
        ]);

        $this->postJson('/ai/edit', [
            'provider' => 'gemini',
            'api_key' => 'AIzaTest',
            'prompt' => 'Warmer background',
            'outline' => self::OUTLINE,
        ])
            ->assertOk()
            ->assertJsonPath('changes.0.id', 'e1');
    }

    /**
     * A hallucinated id would silently match nothing in the browser; dropping
     * it server-side keeps the response honest about what will actually apply.
     */
    public function test_changes_naming_unknown_ids_are_discarded(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => json_encode([
                    'summary' => 'x',
                    'changes' => [
                        ['id' => '.hero h1', 'declarations' => [['property' => 'color', 'value' => 'red']], 'text' => ''],
                        ['id' => 'e2', 'declarations' => [['property' => 'color', 'value' => 'red']], 'text' => ''],
                    ],
                ])]],
            ]),
        ]);

        $this->postJson('/ai/edit', [
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'prompt' => 'Red',
            'outline' => self::OUTLINE,
        ])
            ->assertOk()
            ->assertJsonCount(1, 'changes')
            ->assertJsonPath('changes.0.id', 'e2');
    }

    public function test_dangerous_declarations_are_filtered_out(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => json_encode([
                    'summary' => 'x',
                    'changes' => [[
                        'id' => 'e2',
                        'declarations' => [
                            ['property' => 'background', 'value' => 'url(javascript:alert(1))'],
                            ['property' => 'color', 'value' => 'red'],
                        ],
                        'text' => '',
                    ]],
                ])]],
            ]),
        ]);

        $this->postJson('/ai/edit', [
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'prompt' => 'Red',
            'outline' => self::OUTLINE,
        ])
            ->assertOk()
            ->assertJsonCount(1, 'changes.0.declarations')
            ->assertJsonPath('changes.0.declarations.0.property', 'color');
    }

    public function test_a_truncated_reply_is_reported_rather_than_half_applied(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['stop_reason' => 'max_tokens', 'content' => []]),
        ]);

        $this->postJson('/ai/edit', [
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'prompt' => 'x',
            'outline' => self::OUTLINE,
        ])->assertStatus(422);
    }

    public function test_a_refusal_is_surfaced_instead_of_crashing_on_empty_content(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['stop_reason' => 'refusal', 'content' => []]),
        ]);

        $this->postJson('/ai/edit', [
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'prompt' => 'x',
            'outline' => self::OUTLINE,
        ])->assertStatus(422);
    }

    public function test_a_missing_key_is_a_clear_message_not_a_provider_round_trip(): void
    {
        Http::preventStrayRequests();

        $this->postJson('/ai/edit', [
            'provider' => 'anthropic',
            'prompt' => 'x',
            'outline' => self::OUTLINE,
        ])->assertStatus(422)->assertJsonStructure(['error']);
    }

    public function test_a_key_from_the_wrong_provider_is_caught_before_sending(): void
    {
        Http::preventStrayRequests();

        $this->postJson('/ai/edit', [
            'provider' => 'anthropic',
            'api_key' => 'sk-proj-an-openai-key',
            'prompt' => 'x',
            'outline' => self::OUTLINE,
        ])->assertStatus(422);
    }

    public function test_restructured_markup_is_stripped_of_scripts(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => json_encode([
                    'summary' => 'Rebuilt.',
                    'html' => '<section><h1 onclick="x()">Hi</h1><script>evil()</script></section>',
                ])]],
            ]),
        ]);

        $response = $this->postJson('/ai/restructure', [
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'prompt' => 'Rebuild',
            'html' => '<section><h1>Hi</h1></section>',
        ])->assertOk();

        $html = $response->json('html');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringContainsString('Hi', $html);
    }

    public function test_restructured_markup_keeps_multibyte_content_readable(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => json_encode([
                    'summary' => 'Rebuilt.',
                    'html' => '<section><h1>Grüße — 日本語</h1></section>',
                ])]],
            ]),
        ]);

        $html = $this->postJson('/ai/restructure', [
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'prompt' => 'Rebuild',
            'html' => '<section><h1>x</h1></section>',
        ])->assertOk()->json('html');

        $this->assertStringContainsString('Grüße — 日本語', $html);
    }

    public function test_critique_returns_ranked_findings(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => json_encode([
                    'summary' => 'Reads flat.',
                    'findings' => [[
                        'id' => 'e2',
                        'title' => 'Headline and body are the same size',
                        'severity' => 'high',
                        'recommendation' => 'Raise the headline to at least 2x the body size.',
                    ]],
                ])]],
            ]),
        ]);

        $this->postJson('/ai/critique', [
            'provider' => 'anthropic',
            'api_key' => 'sk-ant-test',
            'outline' => self::OUTLINE,
        ])
            ->assertOk()
            ->assertJsonPath('findings.0.severity', 'high');
    }
}
