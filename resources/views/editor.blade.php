<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AI UI Live Editor</title>
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --success: #10b981;
            --success-dark: #059669;
            --danger: #ef4444;
            --warning: #f59e0b;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --radius: 12px;
            --shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--gray-100);
            color: var(--gray-800);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Top Navigation */
        .navbar {
            background: white;
            border-bottom: 1px solid var(--gray-200);
            padding: 12px 24px;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 700;
            font-size: 18px;
            color: var(--primary);
            white-space: nowrap;
        }

        .logo-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, var(--primary) 0%, #8b5cf6 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
        }

        .url-form {
            display: flex;
            flex: 1;
            gap: 8px;
            max-width: 600px;
        }

        .url-input {
            flex: 1;
            padding: 10px 16px;
            border: 2px solid var(--gray-200);
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.2s;
            background: var(--gray-50);
        }

        .url-input:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }

        .btn-primary:disabled {
            background: var(--gray-400);
            cursor: not-allowed;
            transform: none;
        }

        .btn-secondary {
            background: var(--gray-100);
            color: var(--gray-700);
            border: 1px solid var(--gray-300);
        }

        .btn-secondary:hover {
            background: var(--gray-200);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: var(--success-dark);
        }

        .btn-icon {
            padding: 10px;
            background: transparent;
            color: var(--gray-500);
        }

        .btn-icon:hover {
            background: var(--gray-100);
            color: var(--gray-700);
        }

        .nav-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-left: auto;
        }

        /* Main Content */
        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            padding: 16px;
            gap: 16px;
        }

        /* Status Bar */
        .status-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 16px;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            font-size: 13px;
        }

        .status-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--gray-400);
        }

        .status-indicator.ready { background: var(--success); }
        .status-indicator.loading { background: var(--warning); animation: pulse 1s infinite; }
        .status-indicator.error { background: var(--danger); }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .element-path {
            color: var(--gray-500);
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 12px;
        }

        .element-path span {
            color: var(--primary);
        }

        /* Editor Frame */
        .editor-container {
            flex: 1;
            background: white;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }

        .editor-frame {
            flex: 1;
            position: relative;
            min-height: 500px;
        }

        .editor-frame iframe {
            width: 100%;
            height: 100%;
            border: none;
            position: absolute;
            top: 0;
            left: 0;
        }

        .loading-overlay {
            position: absolute;
            inset: 0;
            background: rgba(255,255,255,0.9);
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 16px;
            z-index: 10;
        }

        .loading-overlay.active {
            display: flex;
        }

        .spinner {
            width: 48px;
            height: 48px;
            border: 4px solid var(--gray-200);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .placeholder {
            position: absolute;
            inset: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 40px;
            color: var(--gray-500);
        }

        .placeholder-icon {
            width: 80px;
            height: 80px;
            background: var(--gray-100);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 24px;
        }

        .placeholder-icon svg {
            width: 40px;
            height: 40px;
            color: var(--gray-400);
        }

        .placeholder h2 {
            font-size: 20px;
            color: var(--gray-700);
            margin-bottom: 8px;
        }

        .placeholder p {
            font-size: 14px;
            max-width: 300px;
        }

        /* Full Screen Modal */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 24px;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal-overlay.active {
            display: flex;
            opacity: 1;
        }

        .modal {
            background: white;
            border-radius: 16px;
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow-xl);
            transform: scale(0.95) translateY(20px);
            transition: transform 0.3s ease;
        }

        .modal-overlay.active .modal {
            transform: scale(1) translateY(0);
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .modal-title {
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-title-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, var(--primary) 0%, #8b5cf6 100%);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .modal-close {
            width: 36px;
            height: 36px;
            border: none;
            background: var(--gray-100);
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--gray-500);
            transition: all 0.2s;
        }

        .modal-close:hover {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .modal-body {
            padding: 24px;
            flex: 1;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .selected-preview {
            background: var(--gray-50);
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            overflow: hidden;
        }

        .preview-header {
            padding: 12px 16px;
            background: var(--gray-100);
            border-bottom: 1px solid var(--gray-200);
            font-size: 12px;
            font-weight: 600;
            color: var(--gray-600);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .preview-content {
            padding: 16px;
            max-height: 200px;
            overflow: auto;
            font-family: 'Monaco', 'Menlo', monospace;
            font-size: 12px;
            line-height: 1.6;
            color: var(--gray-600);
            white-space: pre-wrap;
            word-break: break-all;
        }

        .prompt-section label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 8px;
            color: var(--gray-700);
        }

        .prompt-textarea {
            width: 100%;
            padding: 16px;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 14px;
            font-family: inherit;
            resize: none;
            min-height: 140px;
            transition: all 0.2s;
            background: var(--gray-50);
        }

        .prompt-textarea:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
        }

        .prompt-suggestions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }

        .suggestion-chip {
            padding: 6px 12px;
            background: var(--gray-100);
            border: 1px solid var(--gray-200);
            border-radius: 20px;
            font-size: 12px;
            color: var(--gray-600);
            cursor: pointer;
            transition: all 0.2s;
        }

        .suggestion-chip:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .keyboard-hint {
            font-size: 12px;
            color: var(--gray-400);
        }

        .keyboard-hint kbd {
            padding: 2px 6px;
            background: var(--gray-100);
            border: 1px solid var(--gray-300);
            border-radius: 4px;
            font-family: inherit;
            font-size: 11px;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
        }

        /* Toast Notifications */
        .toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 2000;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .toast {
            padding: 14px 20px;
            background: var(--gray-800);
            color: white;
            border-radius: 10px;
            box-shadow: var(--shadow-lg);
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 300px;
            animation: slideIn 0.3s ease;
        }

        .toast.success { background: var(--success); }
        .toast.error { background: var(--danger); }
        .toast.warning { background: var(--warning); color: var(--gray-900); }

        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        .toast-icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        .toast-message {
            flex: 1;
            font-size: 14px;
        }

        .toast-close {
            background: none;
            border: none;
            color: inherit;
            opacity: 0.7;
            cursor: pointer;
            padding: 4px;
        }

        .toast-close:hover {
            opacity: 1;
        }

        /* Help Dropdown */
        .help-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 8px;
            background: white;
            border-radius: 12px;
            box-shadow: var(--shadow-xl);
            padding: 16px;
            min-width: 300px;
            display: none;
            z-index: 200;
        }

        .help-dropdown.open {
            display: block;
        }

        .help-dropdown h4 {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 12px;
            color: var(--gray-800);
        }

        .help-dropdown ul {
            list-style: none;
        }

        .help-dropdown li {
            padding: 8px 0;
            font-size: 13px;
            color: var(--gray-600);
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .help-dropdown li::before {
            content: "→";
            color: var(--primary);
            font-weight: bold;
        }

        .help-wrapper {
            position: relative;
        }

        /* Processing Overlay */
        .processing-overlay {
            position: fixed;
            inset: 0;
            background: rgba(255,255,255,0.8);
            backdrop-filter: blur(4px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 3000;
            flex-direction: column;
            gap: 16px;
        }

        .processing-overlay.active {
            display: flex;
        }

        .processing-text {
            font-size: 16px;
            color: var(--gray-700);
            font-weight: 500;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .navbar {
                padding: 12px 16px;
                flex-wrap: wrap;
            }

            .url-form {
                order: 3;
                flex: 1 1 100%;
                max-width: none;
            }

            .nav-actions {
                order: 2;
            }

            .modal {
                max-height: 95vh;
                border-radius: 12px;
            }

            .modal-body {
                padding: 16px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar">
        <div class="logo">
            <div class="logo-icon">AI</div>
            <span>UI Editor</span>
        </div>

        <form class="url-form" id="urlForm">
            <input
                type="url"
                class="url-input"
                id="urlInput"
                placeholder="Enter website URL (e.g., https://example.com)"
                required
            >
            <button type="submit" class="btn btn-primary" id="loadBtn">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    <path d="M9 12l2 2 4-4"/>
                </svg>
                Load
            </button>
        </form>

        <div class="nav-actions">
            <div class="help-wrapper">
                <button class="btn btn-icon" id="helpToggle" title="Help">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/>
                        <path d="M12 17h.01"/>
                    </svg>
                </button>
                <div class="help-dropdown" id="helpDropdown">
                    <h4>How to use</h4>
                    <ul>
                        <li>Enter a website URL and click Load</li>
                        <li>Hover over elements to highlight them</li>
                        <li>Click any element to select it</li>
                        <li>Describe changes in the prompt modal</li>
                        <li>Click Apply to see AI modifications</li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Status Bar -->
        <div class="status-bar">
            <div class="status-indicator" id="statusIndicator"></div>
            <span id="statusText">Ready to load a website</span>
            <div class="element-path" id="elementPath" style="display: none;"></div>
        </div>

        <!-- Editor -->
        <div class="editor-container">
            <div class="editor-frame">
                <div class="placeholder" id="placeholder">
                    <div class="placeholder-icon">
                        <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/>
                        </svg>
                    </div>
                    <h2>Load a website to start editing</h2>
                    <p>Enter any URL above and click Load to begin making AI-powered changes</p>
                </div>

                <div class="loading-overlay" id="loadingOverlay">
                    <div class="spinner"></div>
                    <p>Loading website...</p>
                </div>

                <iframe id="previewFrame" style="display: none;"></iframe>
            </div>
        </div>
    </main>

    <!-- Edit Modal -->
    <div class="modal-overlay" id="modalOverlay">
        <div class="modal">
            <div class="modal-header">
                <div class="modal-title">
                    <div class="modal-title-icon">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
                            <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
                        </svg>
                    </div>
                    <span>Edit Element</span>
                </div>
                <button class="modal-close" id="modalClose">
                    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>

            <div class="modal-body">
                <div class="selected-preview">
                    <div class="preview-header">
                        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/>
                        </svg>
                        Selected Element Preview
                    </div>
                    <div class="preview-content" id="previewContent">
                        <!-- HTML preview will be inserted here -->
                    </div>
                </div>

                <div class="prompt-section">
                    <label for="promptInput">Describe your changes</label>
                    <textarea
                        class="prompt-textarea"
                        id="promptInput"
                        placeholder="Example: Make the text larger and change the background to a gradient blue..."
                    ></textarea>
                    <div class="prompt-suggestions">
                        <span class="suggestion-chip" data-prompt="Make this larger">Make larger</span>
                        <span class="suggestion-chip" data-prompt="Change the background color to blue">Blue background</span>
                        <span class="suggestion-chip" data-prompt="Add rounded corners">Round corners</span>
                        <span class="suggestion-chip" data-prompt="Make it more modern">Modernize</span>
                        <span class="suggestion-chip" data-prompt="Add a shadow effect">Add shadow</span>
                        <span class="suggestion-chip" data-prompt="Center the content">Center content</span>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <div class="keyboard-hint">
                    Press <kbd>Esc</kbd> to close, <kbd>Ctrl</kbd>+<kbd>Enter</kbd> to apply
                </div>
                <div class="modal-actions">
                    <button class="btn btn-secondary" id="cancelBtn">Cancel</button>
                    <button class="btn btn-success" id="applyBtn">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M5 13l4 4L19 7"/>
                        </svg>
                        Apply Changes
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Processing Overlay -->
    <div class="processing-overlay" id="processingOverlay">
        <div class="spinner"></div>
        <p class="processing-text">AI is processing your changes...</p>
    </div>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <script>
        // Configuration
        const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
        const baseUrl = '{{ url("/") }}';

        // State
        let selectedElement = null;
        let currentUrl = null;

        // DOM Elements
        const elements = {
            urlForm: document.getElementById('urlForm'),
            urlInput: document.getElementById('urlInput'),
            loadBtn: document.getElementById('loadBtn'),
            previewFrame: document.getElementById('previewFrame'),
            placeholder: document.getElementById('placeholder'),
            loadingOverlay: document.getElementById('loadingOverlay'),
            modalOverlay: document.getElementById('modalOverlay'),
            modalClose: document.getElementById('modalClose'),
            cancelBtn: document.getElementById('cancelBtn'),
            applyBtn: document.getElementById('applyBtn'),
            promptInput: document.getElementById('promptInput'),
            previewContent: document.getElementById('previewContent'),
            statusIndicator: document.getElementById('statusIndicator'),
            statusText: document.getElementById('statusText'),
            elementPath: document.getElementById('elementPath'),
            helpToggle: document.getElementById('helpToggle'),
            helpDropdown: document.getElementById('helpDropdown'),
            processingOverlay: document.getElementById('processingOverlay'),
            toastContainer: document.getElementById('toastContainer')
        };

        // Toast System
        function showToast(message, type = 'info', duration = 4000) {
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;

            const icons = {
                success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><path d="M22 4L12 14.01l-3-3"/></svg>',
                error: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M15 9l-6 6M9 9l6 6"/></svg>',
                warning: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><path d="M12 9v4M12 17h.01"/></svg>',
                info: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>'
            };

            toast.innerHTML = `
                <div class="toast-icon">${icons[type] || icons.info}</div>
                <span class="toast-message">${message}</span>
                <button class="toast-close">&times;</button>
            `;

            elements.toastContainer.appendChild(toast);

            const closeBtn = toast.querySelector('.toast-close');
            closeBtn.addEventListener('click', () => toast.remove());

            setTimeout(() => {
                toast.style.animation = 'slideIn 0.3s ease reverse';
                setTimeout(() => toast.remove(), 300);
            }, duration);
        }

        // Status Updates
        function setStatus(status, text) {
            elements.statusIndicator.className = `status-indicator ${status}`;
            elements.statusText.textContent = text;
        }

        // Help Dropdown
        elements.helpToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            elements.helpDropdown.classList.toggle('open');
        });

        document.addEventListener('click', (e) => {
            if (!elements.helpDropdown.contains(e.target)) {
                elements.helpDropdown.classList.remove('open');
            }
        });

        // Load Website
        elements.urlForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const url = elements.urlInput.value.trim();
            if (!url) return;

            currentUrl = url;
            elements.placeholder.style.display = 'none';
            elements.previewFrame.style.display = 'none';
            elements.loadingOverlay.classList.add('active');
            elements.loadBtn.disabled = true;
            setStatus('loading', 'Loading website...');

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
                        const error = await response.json();
                        errorMessage = error.error || error.message || errorMessage;
                    }
                    throw new Error(errorMessage);
                }

                const html = await response.text();
                loadWebsiteInFrame(html);
                setStatus('ready', 'Website loaded - Click any element to edit');
                showToast('Website loaded successfully!', 'success');

            } catch (error) {
                console.error('Error:', error);
                setStatus('error', 'Failed to load website');
                showToast(error.message, 'error');
                elements.placeholder.style.display = 'flex';
            } finally {
                elements.loadingOverlay.classList.remove('active');
                elements.loadBtn.disabled = false;
            }
        });

        // Load HTML into iframe
        function loadWebsiteInFrame(html) {
            elements.previewFrame.style.display = 'block';
            const blob = new Blob([html], { type: 'text/html' });
            const blobUrl = URL.createObjectURL(blob);
            elements.previewFrame.onload = initializeEditor;
            elements.previewFrame.src = blobUrl;
        }

        // Initialize Editor
        function initializeEditor() {
            try {
                const iframeDoc = elements.previewFrame.contentDocument || elements.previewFrame.contentWindow.document;
                const iframeBody = iframeDoc.body;
                if (!iframeBody) return;

                const allElements = iframeBody.querySelectorAll('*');

                allElements.forEach(element => {
                    const tagName = element.tagName.toLowerCase();
                    if (['script', 'style', 'link', 'meta', 'head', 'html', 'br', 'hr', 'noscript', 'base'].includes(tagName)) return;
                    if (element.id === 'editor-styles' || element.id === 'editor-script') return;

                    const rect = element.getBoundingClientRect();
                    const style = window.getComputedStyle(element);
                    if (rect.width < 20 || rect.height < 20) return;
                    if (style.display === 'none' || style.visibility === 'hidden') return;

                    element.classList.add('editor-selectable-section');

                    element.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        selectElement(element);
                    });
                });

            } catch (error) {
                console.error('Editor init error:', error);
                showToast('Failed to initialize editor', 'error');
            }
        }

        // Get element path
        function getElementPath(element) {
            const path = [];
            let current = element;

            while (current && current.tagName) {
                let selector = current.tagName.toLowerCase();
                if (current.id) {
                    selector += `#${current.id}`;
                } else if (current.className && typeof current.className === 'string') {
                    const classes = current.className.split(' ').filter(c => c && !c.startsWith('editor-')).slice(0, 2);
                    if (classes.length) selector += `.${classes.join('.')}`;
                }
                path.unshift(selector);
                current = current.parentElement;
                if (path.length >= 4) break;
            }

            return path.join(' > ');
        }

        // Select Element
        function selectElement(element) {
            const iframeDoc = elements.previewFrame.contentDocument;

            if (selectedElement) {
                selectedElement.classList.remove('selected');
            }

            selectedElement = element;
            element.classList.add('selected');

            // Update element path
            const path = getElementPath(element);
            elements.elementPath.innerHTML = `<span>${path}</span>`;
            elements.elementPath.style.display = 'block';

            // Update preview
            let htmlPreview = element.outerHTML;
            if (htmlPreview.length > 1000) {
                htmlPreview = htmlPreview.substring(0, 1000) + '...';
            }
            elements.previewContent.textContent = htmlPreview;

            // Show modal
            openModal();
        }

        // Modal Controls
        function openModal() {
            elements.modalOverlay.classList.add('active');
            elements.promptInput.focus();
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            elements.modalOverlay.classList.remove('active');
            document.body.style.overflow = '';
            elements.promptInput.value = '';

            if (selectedElement) {
                selectedElement.classList.remove('selected');
                selectedElement = null;
            }
            elements.elementPath.style.display = 'none';
        }

        elements.modalClose.addEventListener('click', closeModal);
        elements.cancelBtn.addEventListener('click', closeModal);
        elements.modalOverlay.addEventListener('click', (e) => {
            if (e.target === elements.modalOverlay) closeModal();
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && elements.modalOverlay.classList.contains('active')) {
                closeModal();
            }
            if (e.ctrlKey && e.key === 'Enter' && elements.modalOverlay.classList.contains('active')) {
                applyChanges();
            }
        });

        // Suggestion chips
        document.querySelectorAll('.suggestion-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                elements.promptInput.value = chip.dataset.prompt;
                elements.promptInput.focus();
            });
        });

        // Apply Changes
        async function applyChanges() {
            if (!selectedElement || !elements.promptInput.value.trim()) {
                showToast('Please enter a description of your changes', 'warning');
                return;
            }

            const prompt = elements.promptInput.value.trim();
            const htmlContent = selectedElement.outerHTML;

            elements.processingOverlay.classList.add('active');
            elements.applyBtn.disabled = true;

            try {
                const response = await fetch(`${baseUrl}/edit-section`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken
                    },
                    body: JSON.stringify({ html: htmlContent, prompt })
                });

                const contentType = response.headers.get('content-type');

                if (!response.ok) {
                    let errorMessage = 'Failed to process request';
                    if (contentType && contentType.includes('application/json')) {
                        const error = await response.json();
                        errorMessage = error.error || error.message || errorMessage;
                    }
                    throw new Error(errorMessage);
                }

                const result = await response.json();

                if (result.success && result.html) {
                    const tempDiv = document.createElement('div');
                    tempDiv.innerHTML = result.html;
                    const newElement = tempDiv.firstElementChild;

                    selectedElement.parentNode.replaceChild(newElement, selectedElement);
                    selectedElement = newElement;

                    newElement.classList.add('editor-selectable-section', 'selected');
                    newElement.addEventListener('click', (e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        selectElement(newElement);
                    });

                    closeModal();
                    showToast('Changes applied successfully!', 'success');
                } else {
                    throw new Error('Invalid response from server');
                }

            } catch (error) {
                console.error('Error:', error);
                showToast(error.message, 'error');
            } finally {
                elements.processingOverlay.classList.remove('active');
                elements.applyBtn.disabled = false;
            }
        }

        elements.applyBtn.addEventListener('click', applyChanges);
    </script>
</body>
</html>
