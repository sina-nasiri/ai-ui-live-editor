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
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                    'Accept-Encoding' => 'gzip, deflate',
                    'Connection' => 'keep-alive',
                    'Upgrade-Insecure-Requests' => '1',
                ])
                ->withOptions([
                    'allow_redirects' => true,
                    'verify' => false, // Skip SSL verification for some problematic sites
                ])
                ->get($url);

            if (!$response->successful()) {
                throw new \Exception('Website returned status code: ' . $response->status());
            }

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
        // Parse the URL to get the origin (protocol + domain)
        $parsedUrl = parse_url($baseUrl);
        $origin = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
        if (isset($parsedUrl['port'])) {
            $origin .= ':' . $parsedUrl['port'];
        }

        // Use the full URL as base to handle relative paths correctly
        // Remove any existing base tags first
        $html = preg_replace('/<base[^>]*>/i', '', $html);

        // Add our base tag
        $baseTag = '<base href="' . htmlspecialchars($baseUrl) . '" target="_self">';
        $html = preg_replace('/(<head[^>]*>)/i', '$1' . $baseTag, $html);

        // Convert protocol-relative URLs to absolute URLs
        $html = preg_replace('/(["\'])(\/\/[^"\']+)(["\'])/', '$1' . $parsedUrl['scheme'] . ':$2$3', $html);

        // Convert root-relative URLs for src and href attributes to absolute
        $html = preg_replace('/(src|href)=(["\'])\/([^\/][^"\']*)\2/i', '$1=$2' . $origin . '/$3$2', $html);

        // Add our custom CSS and JS for the editor
        $customScript = <<<'EOT'
<style id="editor-styles">
    .editor-selectable-section {
        cursor: pointer !important;
        pointer-events: auto !important;
    }
    .editor-selectable-section:hover {
        outline: 3px solid #3B82F6 !important;
        outline-offset: -3px !important;
        box-shadow: inset 0 0 0 3px #3B82F6, 0 0 0 3px #3B82F6 !important;
        z-index: 9998 !important;
        position: relative !important;
    }
    .editor-selectable-section.selected {
        outline: 4px solid #10B981 !important;
        outline-offset: -4px !important;
        box-shadow: inset 0 0 0 4px #10B981, 0 0 0 4px #10B981 !important;
        z-index: 9999 !important;
        position: relative !important;
    }
    /* Override common blocking styles */
    .editor-selectable-section,
    .editor-selectable-section * {
        pointer-events: auto !important;
    }
    /* Ensure visibility */
    .editor-selectable-section:hover,
    .editor-selectable-section.selected {
        opacity: 1 !important;
        visibility: visible !important;
    }
    /* Hover Toolbar */
    .editor-hover-toolbar {
        position: fixed !important;
        display: none;
        background: #1f2937 !important;
        border-radius: 8px !important;
        padding: 4px !important;
        gap: 4px !important;
        z-index: 99999 !important;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3) !important;
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
        flex-direction: row !important;
    }
    .editor-hover-toolbar.visible {
        display: flex !important;
    }
    .editor-toolbar-btn {
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        gap: 6px !important;
        padding: 8px 12px !important;
        background: transparent !important;
        border: none !important;
        border-radius: 6px !important;
        color: white !important;
        font-size: 12px !important;
        font-weight: 500 !important;
        cursor: pointer !important;
        white-space: nowrap !important;
        transition: background 0.2s !important;
    }
    .editor-toolbar-btn:hover {
        background: #374151 !important;
    }
    .editor-toolbar-btn svg {
        width: 14px !important;
        height: 14px !important;
        flex-shrink: 0 !important;
    }
    .editor-toolbar-divider {
        width: 1px !important;
        background: #4b5563 !important;
        margin: 4px 2px !important;
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
            'prompt' => 'required|string',
            'api_key' => 'required|string'
        ]);

        $html = $request->input('html');
        $prompt = $request->input('prompt');
        $apiKey = $request->input('api_key');

        // Basic validation for Anthropic API key format
        if (!str_starts_with($apiKey, 'sk-ant-')) {
            return response()->json([
                'error' => 'Invalid API key format. Key should start with sk-ant-'
            ], 400);
        }

        try {
            // Call Claude API
            $response = Http::timeout(60)->withHeaders([
                'Content-Type' => 'application/json',
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01'
            ])->post('https://api.anthropic.com/v1/messages', [
                'model' => 'claude-sonnet-4-5-20250929',
                'max_tokens' => 4096,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => "You are a UI/UX expert. I will provide you with an HTML section and a prompt describing how to modify it.

IMPORTANT RULES:
1. Use INLINE STYLES (style=\"...\") for all CSS changes - do NOT use <style> tags or external CSS classes
2. Keep existing class names but add inline styles to override them
3. Return ONLY the modified HTML element, no explanation or markdown
4. Preserve the original structure and attributes as much as possible

Original HTML:
```html
{$html}
```

Modification Request: {$prompt}

Return only the modified HTML with inline styles:"
                    ]
                ]
            ]);

            if (!$response->successful()) {
                $errorBody = $response->json();
                $errorMessage = $errorBody['error']['message'] ?? $response->body();
                Log::error('Claude API error: ' . $response->body());
                return response()->json([
                    'error' => 'Claude API error: ' . $errorMessage
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
