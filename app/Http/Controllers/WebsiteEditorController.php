<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebsiteEditorController extends Controller
{
    /**
     * Display the main page
     */
    public function index()
    {
        return view('editor');
    }

    /**
     * Proxy endpoint to fetch external website content
     */
    public function proxy(Request $request)
    {
        $request->validate([
            'url' => 'required|url'
        ]);

        $url = $request->input('url');

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                ])
                ->get($url);

            // Get the HTML content
            $html = $response->body();

            // Parse and modify the HTML to make it work in our editor
            $html = $this->processHtml($html, $url);

            return response($html)
                ->header('Content-Type', 'text/html');

        } catch (\Exception $e) {
            Log::error('Proxy error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to load website: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process HTML content to make it work in our editor
     */
    private function processHtml($html, $baseUrl)
    {
        // Add base tag to resolve relative URLs
        $baseTag = '<base href="' . $baseUrl . '">';
        $html = preg_replace('/(<head[^>]*>)/i', '$1' . $baseTag, $html);

        // Add our custom CSS and JS for the editor
        $customScript = <<<'EOT'
<style id="editor-styles">
    .editor-selectable-section {
        cursor: pointer;
        transition: all 0.2s ease;
    }
    .editor-selectable-section:hover {
        outline: 2px solid #3B82F6 !important;
        outline-offset: 2px;
    }
    .editor-selectable-section.selected {
        outline: 3px solid #10B981 !important;
        outline-offset: 2px;
    }
</style>
<script id="editor-script">
    window.editorMode = true;
</script>
EOT;

        $html = preg_replace('/(<\/head>)/i', $customScript . '$1', $html);

        return $html;
    }

    /**
     * Process AI edit request
     */
    public function editSection(Request $request)
    {
        $request->validate([
            'html' => 'required|string',
            'prompt' => 'required|string'
        ]);

        $html = $request->input('html');
        $prompt = $request->input('prompt');
        $apiKey = env('ANTHROPIC_API_KEY');

        if (!$apiKey) {
            return response()->json([
                'error' => 'ANTHROPIC_API_KEY not configured in .env file'
            ], 500);
        }

        try {
            // Call Claude API
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01'
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-3-5-sonnet-20241022',
                'max_tokens' => 4096,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => "You are a UI/UX expert. I will provide you with an HTML section and a prompt describing how to modify it. Please return ONLY the modified HTML, without any explanation or markdown formatting.\n\nOriginal HTML:\n```html\n{$html}\n```\n\nModification Request:\n{$prompt}\n\nReturn only the modified HTML:"
                    ]
                ]
            ]);

            if (!$response->successful()) {
                Log::error('Claude API error: ' . $response->body());
                return response()->json([
                    'error' => 'Claude API error: ' . $response->status()
                ], 500);
            }

            $result = $response->json();
            $editedHtml = $result['content'][0]['text'] ?? '';

            // Clean up the response (remove markdown code blocks if any)
            $editedHtml = preg_replace('/```html\s*/', '', $editedHtml);
            $editedHtml = preg_replace('/```\s*$/', '', $editedHtml);
            $editedHtml = trim($editedHtml);

            return response()->json([
                'success' => true,
                'html' => $editedHtml
            ]);

        } catch (\Exception $e) {
            Log::error('AI processing error: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to process AI request: ' . $e->getMessage()
            ], 500);
        }
    }
}
