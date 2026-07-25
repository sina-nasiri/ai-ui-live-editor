<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>AI UI Live Editor</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('assets/editor.css') }}">
</head>
<body>

<header class="toolbar">
    <div class="brand">
        <span class="brand-mark" aria-hidden="true">UI</span>
        <span>Live Editor</span>
    </div>

    <form class="url-form" id="url-form">
        <label class="sr-only" for="url-input">Website URL</label>
        <input type="url" id="url-input" placeholder="https://example.com" required autocomplete="url" spellcheck="false">
        <button type="submit" class="btn btn-primary" id="load-btn">Load</button>
        <button type="button" class="btn" id="import-btn" title="Paste or upload HTML instead — works for localhost, staging and logged-in pages">Paste</button>
    </form>

    <div class="seg" role="group" aria-label="Preview width">
        <button type="button" data-width="375" title="Mobile — 375px">Mobile</button>
        <button type="button" data-width="768" title="Tablet — 768px">Tablet</button>
        <button type="button" data-width="1280" title="Desktop — 1280px">Desktop</button>
        <button type="button" data-width="0" aria-pressed="true" title="Fill available width">Fit</button>
    </div>

    <div class="toolbar-group">
        <button type="button" class="btn" id="undo-btn" disabled title="Undo (Ctrl/Cmd+Z)">Undo</button>
        <button type="button" class="btn" id="redo-btn" disabled title="Redo (Ctrl/Cmd+Shift+Z)">Redo</button>
        <button type="button" class="btn btn-primary" id="ask-btn" disabled title="Describe a change (Ctrl/Cmd+K)">Ask AI</button>
    </div>

    <div class="toolbar-group">
        <button type="button" class="btn" id="compare-btn" disabled aria-pressed="false" title="Drag to compare before and after">Compare</button>
        <button type="button" class="btn" id="notes-btn" disabled aria-pressed="false" title="Redline mode — click elements to pin review notes">Notes</button>
    </div>

    <div class="spacer"></div>

    <div class="toolbar-group">
        <span class="pill" id="spend-pill" hidden title="Tokens and cost for this session"><span id="spend-text"></span></span>
        <span class="pill" id="key-pill"><span class="dot"></span><span id="key-pill-text">No API key</span></span>
        <button type="button" class="btn btn-ghost" id="export-btn" disabled title="Export your work">Export</button>
        <button type="button" class="btn btn-ghost" id="settings-btn" title="Settings">Settings</button>
        <a class="btn btn-ghost" href="{{ $repoUrl }}" target="_blank" rel="noopener noreferrer" title="Star on GitHub">GitHub</a>
    </div>
</header>

<div class="workspace">
    <main class="stage">
        <div class="statusbar">
            <span id="status-text">Load a page to start.</span>
            <nav class="crumbs" id="crumbs" aria-label="Selected element path"></nav>
        </div>

        <div class="frame-wrap">
            <div class="frame-shell" id="frame-shell">
                <iframe id="preview" title="Page preview"></iframe>

                <div class="overlay" id="placeholder" data-open="true">
                    <div>
                        <h2 style="margin:0 0 6px;font-size:17px">Nothing loaded yet</h2>
                        <p>Enter a URL above. The page is fetched server-side and stripped of its scripts, so what you edit is a stable, static snapshot.</p>
                    </div>
                </div>

                <div class="overlay" id="busy">
                    <div>
                        <div class="spinner"></div>
                        <p id="busy-text">Loading…</p>
                        <p class="hint" id="busy-elapsed"></p>
                        <button type="button" class="btn btn-sm" id="cancel-btn" hidden>Cancel</button>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <aside class="sidebar" id="sidebar">
        <div class="tabs" role="tablist">
            <button type="button" role="tab" data-tab="style" aria-selected="true">Style</button>
            <button type="button" role="tab" data-tab="review" aria-selected="false">Review</button>
            <button type="button" role="tab" data-tab="tokens" aria-selected="false">Tokens</button>
            <button type="button" role="tab" data-tab="history" aria-selected="false">History</button>
        </div>

        <div class="tabpanel" role="tabpanel" id="panel-style"></div>

        <div class="tabpanel" role="tabpanel" id="panel-review" hidden>
            <div class="group">
                <h3>Accessibility</h3>
                <button type="button" class="btn btn-sm" id="audit-btn">Run audit</button>
                <button type="button" class="btn btn-sm" id="audit-copy-btn" hidden>Copy report</button>
                <p class="hint">Runs in your browser. No API key, no cost, no data leaves the page.</p>
                <div id="audit-results"></div>
            </div>

            <div class="group">
                <h3>AI design critique</h3>
                <button type="button" class="btn btn-sm" id="critique-btn" disabled>Critique selection</button>
                <p class="hint">Read-only feedback on hierarchy, contrast, spacing and copy. Changes nothing.</p>
                <div id="critique-results"></div>
            </div>

            <div class="group">
                <h3>Review notes</h3>
                <button type="button" class="btn btn-sm" id="note-add-btn" disabled>Add note to selection</button>
                <button type="button" class="btn btn-sm" id="note-copy-btn" hidden>Copy notes</button>
                <button type="button" class="btn btn-sm" id="note-clear-btn" hidden>Clear</button>
                <p class="hint">Pinned comments that change nothing on the page. Turn on <strong>Notes</strong> in the toolbar to pin by clicking.</p>
                <div id="note-results"></div>
            </div>
        </div>

        <div class="tabpanel" role="tabpanel" id="panel-tokens" hidden>
            <button type="button" class="btn btn-sm" id="tokens-btn">Extract tokens</button>
            <button type="button" class="btn btn-sm" id="tokens-copy-btn" hidden>Copy as CSS</button>
            <p class="hint">The palette, type scale and spacing this page actually ships.</p>
            <div id="tokens-results"></div>
        </div>

        <div class="tabpanel" role="tabpanel" id="panel-history" hidden>
            <div id="history-results"></div>
        </div>
    </aside>
</div>

{{-- Ask AI --}}
<dialog id="prompt-dialog">
    <form method="dialog">
        <div class="dialog-head">
            <h2>Describe the change</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-close aria-label="Close">×</button>
        </div>

        <div class="dialog-body">
            <div class="target" id="prompt-target"></div>

            <label class="sr-only" for="prompt-input">What should change?</label>
            <textarea class="prompt-box" id="prompt-input"
                      placeholder="Make the headline feel more confident — bigger, tighter tracking, more space beneath it."></textarea>

            <div class="chips" id="prompt-chips">
                <button type="button" class="chip" data-prompt="Increase the visual hierarchy — make the headline clearly dominant">Stronger hierarchy</button>
                <button type="button" class="chip" data-prompt="Improve the spacing rhythm and vertical alignment">Fix spacing</button>
                <button type="button" class="chip" data-prompt="Make the call to action more prominent">Emphasise the CTA</button>
                <button type="button" class="chip" data-prompt="Increase the contrast so this passes WCAG AA">Fix contrast</button>
                <button type="button" class="chip" data-prompt="Make this feel more premium and considered">More premium</button>
                <button type="button" class="chip" data-prompt="Simplify — remove visual noise and let the content breathe">Simplify</button>
            </div>

            <p class="hint" id="prompt-mode-hint">
                Style mode returns CSS only, so your copy and markup are never at risk.
            </p>

            <label style="display:flex;gap:7px;align-items:center;margin-top:8px;font-size:12px;color:var(--ink-soft)">
                <input type="checkbox" id="restructure-toggle">
                Rewrite the markup instead (slower, and it can alter content)
            </label>
        </div>

        <div class="dialog-foot">
            <button type="button" class="btn" data-close>Cancel</button>
            <button type="button" class="btn" id="variants-btn">Give me 3 options</button>
            <button type="button" class="btn btn-primary" id="apply-btn">Apply</button>
        </div>
    </form>
</dialog>

{{-- Settings --}}
<dialog id="settings-dialog">
    <form method="dialog">
        <div class="dialog-head">
            <h2>Settings</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-close aria-label="Close">×</button>
        </div>

        <div class="dialog-body">
            <div class="row">
                <label for="provider-select">Provider</label>
                <select class="field" id="provider-select"></select>
            </div>

            <div class="row">
                <label for="model-select">Model</label>
                <select class="field" id="model-select"></select>
            </div>

            <div class="row" id="key-row">
                <label for="key-input">API key</label>
                <input type="password" class="field" id="key-input" autocomplete="off" spellcheck="false">
            </div>

            <p class="hint" id="key-hint"></p>

            <p class="hint">
                Keys are kept in this browser's local storage and sent to <em>your</em> server on each
                request, which forwards them to the provider. Nothing is stored server-side. On a
                self-hosted instance, put the key in <code>.env</code> instead and it never reaches
                the browser at all.
            </p>
        </div>

        <div class="dialog-foot">
            <button type="button" class="btn" data-close>Cancel</button>
            <button type="button" class="btn btn-primary" id="settings-save">Save</button>
        </div>
    </form>
</dialog>

{{-- Export --}}
<dialog id="export-dialog">
    <form method="dialog">
        <div class="dialog-head">
            <h2>Export</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-close aria-label="Close">×</button>
        </div>

        <div class="dialog-body" style="display:grid;gap:8px">
            <button type="button" class="btn" id="export-css-copy">Copy CSS changes</button>
            <button type="button" class="btn" id="export-css-file">Download CSS changes</button>
            <button type="button" class="btn" id="export-html-copy">Copy selected element HTML</button>
            <button type="button" class="btn" id="export-page">Download edited page (standalone HTML)</button>
            <button type="button" class="btn" id="export-shot">Screenshot selected element (PNG)</button>
            <button type="button" class="btn" id="export-notes">Copy review notes</button>
            <p class="hint">
                The CSS export uses real selectors and merges every edit, so it is the file to
                hand a developer. The HTML export is a self-contained snapshot for review.
            </p>
        </div>
    </form>
</dialog>

{{-- Paste / upload markup --}}
<dialog id="import-dialog">
    <form method="dialog">
        <div class="dialog-head">
            <h2>Paste or upload HTML</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-close aria-label="Close">×</button>
        </div>

        <div class="dialog-body">
            <p class="hint" style="margin-top:0">
                For anything the proxy cannot reach: a local dev server, a staging site behind
                a login, a page you are signed into, or a single component. In your browser use
                <em>Save page as…</em> or copy the element's HTML from DevTools.
            </p>

            <label class="sr-only" for="import-html">HTML</label>
            <textarea class="prompt-box" id="import-html" style="min-height:150px;font-family:var(--mono);font-size:12px"
                      placeholder="&lt;section class=&quot;hero&quot;&gt;…&lt;/section&gt;"></textarea>

            <div class="row" style="margin-top:10px">
                <label for="import-file">…or a file</label>
                <input type="file" class="field" id="import-file" accept=".html,.htm,text/html">
            </div>

            <div class="row">
                <label for="import-base">Base URL</label>
                <input type="url" class="field" id="import-base" placeholder="https://example.com (optional)">
            </div>

            <p class="hint">
                A base URL is only needed if the markup references images or stylesheets by
                relative path. Without one they simply will not load.
            </p>
        </div>

        <div class="dialog-foot">
            <button type="button" class="btn" data-close>Cancel</button>
            <button type="button" class="btn btn-primary" id="import-go">Open in editor</button>
        </div>
    </form>
</dialog>

{{-- Add a review note --}}
<dialog id="note-dialog">
    <form method="dialog">
        <div class="dialog-head">
            <h2>Review note</h2>
            <button type="button" class="btn btn-ghost btn-sm" data-close aria-label="Close">×</button>
        </div>

        <div class="dialog-body">
            <div class="target" id="note-target"></div>
            <label class="sr-only" for="note-input">Note</label>
            <textarea class="prompt-box" id="note-input" placeholder="The CTA competes with the headline — nothing tells me where to look first."></textarea>
        </div>

        <div class="dialog-foot">
            <button type="button" class="btn" data-close>Cancel</button>
            <button type="button" class="btn btn-primary" id="note-save">Pin note</button>
        </div>
    </form>
</dialog>

<div class="toasts" id="toasts" aria-live="polite"></div>

<script>
    window.__EDITOR__ = {
        ai: @json($ai),
        base: @json(rtrim(url('/'), '/').'/'),
    };
</script>
<script type="module" src="{{ asset('assets/js/main.js') }}"></script>

</body>
</html>
