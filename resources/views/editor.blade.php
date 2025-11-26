<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AI UI Live Editor</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f5f5;
        }

        .top-bar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
        }

        .top-bar h1 {
            font-size: 24px;
            margin-bottom: 15px;
        }

        .url-form {
            display: flex;
            gap: 10px;
            max-width: 800px;
        }

        .url-input {
            flex: 1;
            padding: 12px 16px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: #10b981;
            color: white;
        }

        .btn-primary:hover {
            background: #059669;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.4);
        }

        .btn-primary:disabled {
            background: #6b7280;
            cursor: not-allowed;
            transform: none;
        }

        .content-area {
            margin-top: 120px;
            padding: 20px;
        }

        .editor-frame {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            overflow: hidden;
            min-height: 600px;
            position: relative;
        }

        .editor-frame iframe {
            width: 100%;
            min-height: 600px;
            border: none;
        }

        .loading {
            display: none;
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }

        .loading.active {
            display: block;
        }

        .loading-spinner {
            border: 4px solid #f3f4f6;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .prompt-panel {
            position: fixed;
            bottom: -400px;
            left: 0;
            right: 0;
            background: white;
            box-shadow: 0 -4px 20px rgba(0,0,0,0.15);
            padding: 30px;
            transition: bottom 0.3s ease;
            z-index: 999;
            max-height: 400px;
        }

        .prompt-panel.active {
            bottom: 0;
        }

        .prompt-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .prompt-header h3 {
            font-size: 18px;
            color: #1f2937;
        }

        .btn-close {
            background: #ef4444;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 8px 16px;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-close:hover {
            background: #dc2626;
        }

        .prompt-textarea {
            width: 100%;
            padding: 16px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
            resize: vertical;
            min-height: 120px;
            outline: none;
            transition: border-color 0.3s ease;
        }

        .prompt-textarea:focus {
            border-color: #667eea;
        }

        .prompt-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 15px;
        }

        .btn-submit {
            background: #667eea;
            color: white;
            padding: 12px 32px;
        }

        .btn-submit:hover {
            background: #5568d3;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        .btn-submit:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
        }

        .error-message {
            background: #fef2f2;
            color: #dc2626;
            padding: 15px;
            border-radius: 8px;
            margin: 20px;
            border-left: 4px solid #dc2626;
            display: none;
        }

        .error-message.active {
            display: block;
        }

        .placeholder {
            text-align: center;
            padding: 80px 20px;
            color: #6b7280;
        }

        .placeholder svg {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            opacity: 0.5;
        }

        .placeholder h2 {
            font-size: 24px;
            margin-bottom: 10px;
            color: #374151;
        }

        .placeholder p {
            font-size: 16px;
        }

        .instructions {
            background: #f0f9ff;
            border: 2px solid #0ea5e9;
            border-radius: 8px;
            padding: 20px;
            margin: 20px;
        }

        .instructions h4 {
            color: #0c4a6e;
            margin-bottom: 10px;
            font-size: 16px;
        }

        .instructions ul {
            list-style: none;
            padding-left: 0;
        }

        .instructions li {
            color: #0369a1;
            padding: 5px 0;
            padding-left: 25px;
            position: relative;
        }

        .instructions li:before {
            content: "→";
            position: absolute;
            left: 0;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="top-bar">
        <h1>AI UI Live Editor</h1>
        <form class="url-form" id="urlForm">
            <input
                type="url"
                class="url-input"
                id="urlInput"
                placeholder="Enter website URL (e.g., https://example.com)"
                required
            >
            <button type="submit" class="btn btn-primary" id="loadBtn">
                Load Website
            </button>
        </form>
    </div>

    <div class="content-area">
        <div class="error-message" id="errorMessage"></div>

        <div class="instructions">
            <h4>How to use:</h4>
            <ul>
                <li>Enter a website URL and click "Load Website"</li>
                <li>Hover over any section to see it highlighted</li>
                <li>Click on a section to select it</li>
                <li>Describe your desired changes in the prompt box</li>
                <li>Click "Apply Changes" to see AI-powered modifications in real-time</li>
            </ul>
        </div>

        <div class="editor-frame">
            <div class="loading" id="loading">
                <div class="loading-spinner"></div>
                <p>Loading website...</p>
            </div>

            <div class="placeholder" id="placeholder">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                </svg>
                <h2>Enter a URL to get started</h2>
                <p>Load any website and start editing with AI</p>
            </div>

            <iframe id="previewFrame" style="display: none;"></iframe>
        </div>
    </div>

    <div class="prompt-panel" id="promptPanel">
        <div class="prompt-header">
            <h3>Describe your changes</h3>
            <button class="btn-close" id="closePrompt">Close</button>
        </div>
        <textarea
            class="prompt-textarea"
            id="promptInput"
            placeholder="Example: Make the text larger and change the background to blue..."
        ></textarea>
        <div class="prompt-actions">
            <button class="btn btn-submit" id="submitPrompt">Apply Changes</button>
        </div>
    </div>

    <script>
        // CSRF token setup for all AJAX requests
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

        // Get base URL for API calls (handles subdirectory installations)
        const baseUrl = '{{ url("/") }}';

        // State management
        let selectedElement = null;
        let currentUrl = null;

        // Elements
        const urlForm = document.getElementById('urlForm');
        const urlInput = document.getElementById('urlInput');
        const loadBtn = document.getElementById('loadBtn');
        const previewFrame = document.getElementById('previewFrame');
        const loading = document.getElementById('loading');
        const placeholder = document.getElementById('placeholder');
        const promptPanel = document.getElementById('promptPanel');
        const promptInput = document.getElementById('promptInput');
        const submitPrompt = document.getElementById('submitPrompt');
        const closePrompt = document.getElementById('closePrompt');
        const errorMessage = document.getElementById('errorMessage');

        // Load website
        urlForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const url = urlInput.value.trim();
            if (!url) return;

            currentUrl = url;
            showLoading();
            hideError();
            placeholder.style.display = 'none';
            previewFrame.style.display = 'none';
            loadBtn.disabled = true;

            try {
                const response = await fetch(`${baseUrl}/proxy`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json, text/html',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ url })
                });

                if (!response.ok) {
                    let errorMessage = 'Failed to load website';
                    const contentType = response.headers.get('content-type');
                    if (contentType && contentType.includes('application/json')) {
                        try {
                            const error = await response.json();
                            errorMessage = error.error || error.message || errorMessage;
                        } catch (e) {
                            // JSON parsing failed, use default message
                        }
                    } else {
                        // Response is not JSON (likely HTML error page)
                        const text = await response.text();
                        console.error('Server returned non-JSON error:', text);
                    }
                    throw new Error(errorMessage);
                }

                const html = await response.text();
                loadWebsiteInFrame(html);

            } catch (error) {
                console.error('Error loading website:', error);
                showError(error.message);
                placeholder.style.display = 'block';
            } finally {
                hideLoading();
                loadBtn.disabled = false;
            }
        });

        // Load HTML into iframe
        function loadWebsiteInFrame(html) {
            previewFrame.style.display = 'block';

            // Create a blob URL for the HTML content
            const blob = new Blob([html], { type: 'text/html' });
            const blobUrl = URL.createObjectURL(blob);

            previewFrame.onload = () => {
                initializeEditor();
            };

            previewFrame.src = blobUrl;
        }

        // Initialize editor functionality in iframe
        function initializeEditor() {
            try {
                const iframeDoc = previewFrame.contentDocument || previewFrame.contentWindow.document;
                const iframeBody = iframeDoc.body;

                if (!iframeBody) return;

                // Make all major sections selectable
                const selectableElements = iframeBody.querySelectorAll('header, nav, main, section, article, aside, footer, div[class*="container"], div[class*="wrapper"], div[class*="section"]');

                selectableElements.forEach(element => {
                    // Skip if element is too small or hidden
                    const rect = element.getBoundingClientRect();
                    if (rect.width < 50 || rect.height < 50) return;

                    element.classList.add('editor-selectable-section');

                    element.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        selectSection(element);
                    });
                });

            } catch (error) {
                console.error('Error initializing editor:', error);
                showError('Failed to initialize editor. The website may not allow embedding.');
            }
        }

        // Select a section
        function selectSection(element) {
            const iframeDoc = previewFrame.contentDocument || previewFrame.contentWindow.document;

            // Deselect previous
            if (selectedElement) {
                selectedElement.classList.remove('selected');
            }

            // Select new
            selectedElement = element;
            element.classList.add('selected');

            // Show prompt panel
            promptPanel.classList.add('active');
            promptInput.focus();
        }

        // Close prompt panel
        closePrompt.addEventListener('click', () => {
            promptPanel.classList.remove('active');
            if (selectedElement) {
                selectedElement.classList.remove('selected');
                selectedElement = null;
            }
            promptInput.value = '';
        });

        // Submit prompt for AI processing
        submitPrompt.addEventListener('click', async () => {
            if (!selectedElement || !promptInput.value.trim()) return;

            const prompt = promptInput.value.trim();
            const htmlContent = selectedElement.outerHTML;

            submitPrompt.disabled = true;
            submitPrompt.textContent = 'Processing...';
            hideError();

            try {
                const response = await fetch(`${baseUrl}/edit-section`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({
                        html: htmlContent,
                        prompt: prompt
                    })
                });

                const contentType = response.headers.get('content-type');

                if (!response.ok) {
                    let errorMessage = 'Failed to process AI request';
                    if (contentType && contentType.includes('application/json')) {
                        try {
                            const error = await response.json();
                            errorMessage = error.error || error.message || errorMessage;
                        } catch (e) {
                            // JSON parsing failed, use default message
                        }
                    } else {
                        // Response is not JSON (likely HTML error page)
                        const text = await response.text();
                        console.error('Server returned non-JSON error:', text);
                    }
                    throw new Error(errorMessage);
                }

                if (!contentType || !contentType.includes('application/json')) {
                    throw new Error('Server returned unexpected response format');
                }

                const result = await response.json();

                if (result.success && result.html) {
                    // Replace the element with the new HTML
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = result.html;
                    const newElement = tempDiv.firstElementChild;

                    selectedElement.parentNode.replaceChild(newElement, selectedElement);
                    selectedElement = newElement;

                    // Re-add editor functionality
                    newElement.classList.add('editor-selectable-section', 'selected');
                    newElement.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        selectSection(newElement);
                    });

                    // Clear prompt and show success
                    promptInput.value = '';
                    alert('Changes applied successfully!');
                } else {
                    throw new Error('Invalid response from server');
                }

            } catch (error) {
                console.error('Error processing AI request:', error);
                showError(error.message);
            } finally {
                submitPrompt.disabled = false;
                submitPrompt.textContent = 'Apply Changes';
            }
        });

        // Helper functions
        function showLoading() {
            loading.classList.add('active');
        }

        function hideLoading() {
            loading.classList.remove('active');
        }

        function showError(message) {
            errorMessage.textContent = message;
            errorMessage.classList.add('active');
        }

        function hideError() {
            errorMessage.classList.remove('active');
        }
    </script>
</body>
</html>
