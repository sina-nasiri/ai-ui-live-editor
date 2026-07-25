import { $, $$, el, clear, describe, debounce, copyText } from './util.js';
import { toast } from './toast.js';
import {
    state, subscribe, undo, redo, find, saveSession, loadSession, restoreSession, forgetSession,
    setRuleEnabled,
} from './store.js';
import {
    loadSnapshot, onSelectionChange, onKeyDown, onClickIntercept, onReflow,
    select, deselect, scrollTo, ancestry,
} from './frame.js';
import { mountInspector, renderInspector } from './inspector.js';
import { runAudit, auditAsMarkdown } from './audit.js';
import { extractTokens, tokensAsCss } from './tokens.js';
import {
    settings, initSettings, savePrefs, currentProvider, hasUsableKey, spend, cancelRequest,
    requestEdit, requestVariants, requestCritique, requestRestructure, previewVariant, applyChanges,
} from './ai.js';
import {
    copyChangesAsCss, downloadChangesAsCss, copyElementHtml, downloadPage,
} from './exporter.js';
import { downloadCapture } from './capture.js';
import { toggleCompare, stopCompare, isComparing } from './compare.js';
import {
    setAnnotating, isAnnotating, addAnnotation, removeAnnotation, clearAnnotations,
    renderPins, annotationsAsMarkdown, onAnnotationsChange,
} from './annotate.js';
import { installContextMenu } from './contextmenu.js';

const config = window.__EDITOR__;
const base = config.base;

let auditFindings = [];
let lastChangeset = null;
let pendingNoteTarget = null;
let elapsedTimer = null;

// --------------------------------------------------------------- bootstrap

initSettings(config.ai);
mountInspector($('#panel-style'), { onTextEdit: () => renderInspector() });
onSelectionChange(handleSelection);
onAnnotationsChange(() => { renderNotes(); saveSession(); });

// Pins are positioned from live geometry, so they have to be redrawn
// whenever the page reflows or an edit changes an element's box.
onReflow(() => renderPins());

onClickIntercept((node) => {
    if (!isAnnotating()) return false;
    openNoteDialog(node);
    return true;
});

subscribe(debounce(() => { renderHistory(); renderPins(); saveSession(); }, 250));
subscribe(syncButtons);

wireToolbar();
wireTabs();
wireDialogs();
wireReviewPanel();
wireTokensPanel();
wireKeyboard();
syncKeyPill();
renderInspector();
offerSessionRestore();

// ------------------------------------------------------------------ loading

async function loadUrl(url) {
    return openSnapshot(url, () =>
        fetch(`${base}proxy`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json, text/html',
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ url }),
        })
    );
}

/** Pasted markup and uploaded files take the same path as a proxied URL. */
async function loadImport({ html, file, baseUrl }) {
    const body = new FormData();
    if (file) body.append('file', file);
    else body.append('html', html);
    if (baseUrl) body.append('base', baseUrl);

    return openSnapshot(baseUrl || 'pasted markup', () =>
        fetch(`${base}import`, {
            method: 'POST',
            headers: {
                Accept: 'application/json, text/html',
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').content,
            },
            body,
        })
    );
}

async function openSnapshot(label, request) {
    setBusy(true, 'Fetching and cleaning the page…');
    setStatus('Loading…');
    stopCompare();
    setAnnotating(false);

    try {
        const response = await request();

        if (!response.ok) {
            let message = `The page could not be loaded (${response.status}).`;
            if ((response.headers.get('content-type') || '').includes('json')) {
                const data = await response.json().catch(() => ({}));
                message = data.error || message;
            }
            throw new Error(message);
        }

        const doc = await loadSnapshot($('#preview'), await response.text(), label);
        installContextMenu(doc, contextActions());

        $('#placeholder').dataset.open = 'false';
        setStatus('Ready — click anything in the page to select it.');
        toast('Page loaded.', 'success');

        auditFindings = [];
        lastChangeset = null;
        clear($('#audit-results'));
        clear($('#critique-results'));
        clear($('#tokens-results'));
        clear($('#note-results'));
        syncButtons();

        return doc;
    } catch (error) {
        setStatus('Could not load that page.');
        toast(error.message, 'error', 8000);
        return null;
    } finally {
        setBusy(false);
    }
}

function offerSessionRestore() {
    const session = loadSession();
    if (!session || !session.patches.length) return;

    const age = Math.round((Date.now() - session.savedAt) / 60000);
    const when = age < 1 ? 'just now' : age < 60 ? `${age} min ago` : `${Math.round(age / 60)} h ago`;

    toast(`${session.patches.length} unsaved edits from ${when} — press Load to restore them.`, 'info', 9000);
    $('#url-input').value = session.url;

    // Replaying only makes sense once the same page is back on screen.
    const unsubscribe = subscribe(() => {
        if (!state.doc) return;
        unsubscribe();

        if (state.url !== session.url) {
            forgetSession();
            return;
        }

        const { restored, skipped } = restoreSession(session.patches, session.annotations);
        renderNotes();

        toast(
            skipped
                ? `Restored ${restored} edits. ${skipped} could not be replaced — the page has changed since.`
                : `Restored ${restored} edits.`,
            skipped ? 'warning' : 'success',
            7000
        );
    });
}

// ---------------------------------------------------------------- selection

function handleSelection(node) {
    renderInspector();
    renderCrumbs(node);
    syncButtons();
}

function renderCrumbs(node) {
    const host = $('#crumbs');
    clear(host);

    if (!node) return;

    const chain = ancestry(node);

    chain.forEach((entry, index) => {
        if (index) host.append(el('span', { class: 'sep', text: '›' }));
        host.append(
            el('button', {
                type: 'button',
                text: describe(entry),
                'aria-current': entry === node ? 'true' : 'false',
                onClick: () => { select(entry); scrollTo(entry); },
            })
        );
    });
}

// ------------------------------------------------------------------ toolbar

function wireToolbar() {
    $('#url-form').addEventListener('submit', (event) => {
        event.preventDefault();
        const url = $('#url-input').value.trim();
        if (url) loadUrl(url);
    });

    for (const button of $$('.seg [data-width]')) {
        button.addEventListener('click', () => {
            for (const other of $$('.seg [data-width]')) other.setAttribute('aria-pressed', 'false');
            button.setAttribute('aria-pressed', 'true');

            const width = Number(button.dataset.width);
            $('#frame-shell').style.maxWidth = width ? `${width}px` : '100%';
        });
    }

    $('#undo-btn').addEventListener('click', () => {
        if (!undo()) toast('Nothing to undo.', 'info', 2000);
        renderInspector();
    });

    $('#redo-btn').addEventListener('click', () => {
        if (!redo()) toast('Nothing to redo.', 'info', 2000);
        renderInspector();
    });

    $('#ask-btn').addEventListener('click', openPrompt);
    $('#settings-btn').addEventListener('click', openSettings);
    $('#export-btn').addEventListener('click', () => $('#export-dialog').showModal());
    $('#import-btn').addEventListener('click', () => $('#import-dialog').showModal());
    $('#cancel-btn').addEventListener('click', () => {
        if (cancelRequest()) toast('Request cancelled.', 'info', 2500);
        setBusy(false);
    });

    $('#compare-btn').addEventListener('click', (event) => {
        if (!state.patches.length && !isComparing()) {
            toast('Make an edit first — there is nothing to compare yet.', 'warning');
            return;
        }
        const on = toggleCompare($('#frame-shell'));
        event.currentTarget.setAttribute('aria-pressed', String(on));
    });

    $('#notes-btn').addEventListener('click', (event) => {
        const on = setAnnotating(!isAnnotating());
        event.currentTarget.setAttribute('aria-pressed', String(on));
        setStatus(on ? 'Redline mode — click any element to pin a note.' : 'Ready — click anything to select it.');
        if (on) showTab('review');
    });
}

/**
 * The right-click menu. Actions resolve their target at click time, so they
 * keep working on elements the AI replaced after the page loaded.
 */
function contextActions() {
    return [
        { label: 'Ask AI about this…', run: (node) => { select(node); openPrompt(); } },
        { label: 'Critique this', run: (node) => { select(node); $('#critique-btn').click(); } },
        { separator: true },
        {
            label: 'Copy HTML',
            run: async (node) => {
                await copyElementHtml(node);
                toast('Element HTML copied.', 'success');
            },
        },
        { label: 'Screenshot to PNG', run: (node) => captureNode(node) },
        { separator: true },
        { label: 'Pin a review note', run: (node) => openNoteDialog(node) },
        { label: 'Select parent', run: (node) => node.parentElement && select(node.parentElement) },
    ];
}

async function captureNode(node) {
    setBusy(true, 'Rendering the element…');

    try {
        const name = `${describe(node).replace(/[^a-z0-9]+/gi, '-')}.png`;
        const { missingFonts } = await downloadCapture(node, state.view, name);

        toast(
            missingFonts
                ? 'Screenshot saved. Some web fonts could not be embedded, so those fall back to a system face.'
                : 'Screenshot saved.',
            missingFonts ? 'warning' : 'success',
            missingFonts ? 8000 : 4000
        );
    } catch (error) {
        toast(error.message, 'error', 8000);
    } finally {
        setBusy(false);
    }
}

function wireTabs() {
    for (const tab of $$('.tabs [data-tab]')) {
        tab.addEventListener('click', () => {
            for (const other of $$('.tabs [data-tab]')) {
                const active = other === tab;
                other.setAttribute('aria-selected', String(active));
                $(`#panel-${other.dataset.tab}`).hidden = !active;
            }
        });
    }
}

function wireKeyboard() {
    const handler = (event) => {
        const meta = event.metaKey || event.ctrlKey;
        const tag = event.target && event.target.tagName;
        const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || event.target?.isContentEditable;

        // Cmd on macOS, Ctrl elsewhere — the previous build only checked Ctrl,
        // so the shortcut did nothing on a Mac.
        if (meta && event.key.toLowerCase() === 'z') {
            if (typing) return;
            event.preventDefault();
            (event.shiftKey ? redo : undo)();
            renderInspector();
            return;
        }

        if (meta && event.key.toLowerCase() === 'k' && state.selected) {
            event.preventDefault();
            openPrompt();
            return;
        }

        if (event.key === 'Escape' && !document.querySelector('dialog[open]')) deselect();
    };

    document.addEventListener('keydown', handler);

    // The same handler runs inside the preview document, where focus sits
    // after you click an element. frame.js attaches it to each new snapshot.
    onKeyDown(handler);
}

// ------------------------------------------------------------------ dialogs

function wireDialogs() {
    for (const button of $$('dialog [data-close]')) {
        button.addEventListener('click', () => button.closest('dialog').close());
    }

    for (const chip of $$('#prompt-chips .chip')) {
        chip.addEventListener('click', () => {
            $('#prompt-input').value = chip.dataset.prompt;
            $('#prompt-input').focus();
        });
    }

    $('#restructure-toggle').addEventListener('change', (event) => {
        $('#prompt-mode-hint').textContent = event.target.checked
            ? 'Markup mode rewrites the element. Slower, costlier, and it can change your copy — undo still works.'
            : 'Style mode returns CSS only, so your copy and markup are never at risk.';
        $('#variants-btn').disabled = event.target.checked;
    });

    $('#apply-btn').addEventListener('click', runApply);
    $('#variants-btn').addEventListener('click', runVariants);

    $('#provider-select').addEventListener('change', (event) => {
        settings.provider = event.target.value;
        settings.model = currentProvider().default_model;
        renderModelOptions();
        renderKeyField();
    });

    $('#settings-save').addEventListener('click', () => {
        settings.model = $('#model-select').value;

        const key = $('#key-input').value.trim();
        if (key) settings.keys[settings.provider] = key;
        else delete settings.keys[settings.provider];

        savePrefs();
        syncKeyPill();
        syncButtons();
        $('#settings-dialog').close();
        toast('Settings saved.', 'success');
    });

    $('#export-css-copy').addEventListener('click', async () => {
        await copyChangesAsCss();
        toast('CSS copied.', 'success');
    });

    $('#export-css-file').addEventListener('click', () => downloadChangesAsCss());

    $('#export-html-copy').addEventListener('click', async () => {
        if (!state.selected) return toast('Select an element first.', 'warning');
        await copyElementHtml(state.selected);
        toast('Element HTML copied.', 'success');
    });

    $('#export-page').addEventListener('click', () => downloadPage());

    $('#export-shot').addEventListener('click', () => {
        if (!state.selected) return toast('Select an element first.', 'warning');
        $('#export-dialog').close();
        captureNode(state.selected);
    });

    $('#export-notes').addEventListener('click', async () => {
        await copyText(annotationsAsMarkdown());
        toast('Review notes copied as Markdown.', 'success');
    });

    $('#import-go').addEventListener('click', async () => {
        const file = $('#import-file').files[0] || null;
        const html = $('#import-html').value.trim();
        const baseUrl = $('#import-base').value.trim();

        if (!file && !html) return toast('Paste some HTML or choose a file.', 'warning');

        $('#import-dialog').close();
        await loadImport({ html, file, baseUrl });
    });

    $('#note-save').addEventListener('click', () => {
        const note = $('#note-input').value.trim();
        if (!note || !pendingNoteTarget) return;

        addAnnotation(pendingNoteTarget, note);
        $('#note-dialog').close();
        $('#note-input').value = '';
        pendingNoteTarget = null;
        showTab('review');
    });
}

function openNoteDialog(node) {
    pendingNoteTarget = node;
    $('#note-target').textContent = describe(node);
    $('#note-input').value = '';
    $('#note-dialog').showModal();
    $('#note-input').focus();
}

function openPrompt() {
    if (!state.selected) return;
    $('#prompt-target').textContent = describe(state.selected);
    $('#prompt-dialog').showModal();
    $('#prompt-input').focus();
}

function openSettings() {
    renderProviderOptions();
    renderModelOptions();
    renderKeyField();
    $('#settings-dialog').showModal();
}

function renderProviderOptions() {
    const select = $('#provider-select');
    clear(select);

    for (const [key, provider] of Object.entries(settings.catalogue.providers)) {
        select.append(el('option', { value: key, text: provider.label, selected: key === settings.provider }));
    }
}

function renderModelOptions() {
    const select = $('#model-select');
    clear(select);

    for (const [id, label] of Object.entries(currentProvider().models || {})) {
        select.append(el('option', { value: id, text: label, selected: id === settings.model }));
    }
}

function renderKeyField() {
    const provider = currentProvider();

    // When the server holds a key there is nothing for the visitor to supply,
    // and asking anyway would imply the key is going somewhere it is not.
    $('#key-row').hidden = Boolean(provider.server_key) || !settings.catalogue.allow_client_keys;
    $('#key-input').value = settings.keys[settings.provider] || '';
    $('#key-input').placeholder = provider.key_prefix ? `${provider.key_prefix}…` : '';

    const hint = $('#key-hint');
    clear(hint);

    if (provider.server_key) {
        hint.append(document.createTextNode('This server has a key configured — nothing to enter, and your browser never sees it.'));
    } else if (provider.key_url) {
        hint.append(document.createTextNode('Get a key from '));
        hint.append(el('a', { href: provider.key_url, target: '_blank', rel: 'noopener noreferrer', text: provider.key_url }));
    }
}

// ---------------------------------------------------------------- AI actions

async function runApply() {
    const prompt = $('#prompt-input').value.trim();
    if (!prompt) return toast('Describe what should change.', 'warning');
    if (!state.selected) return toast('Select an element first.', 'warning');
    if (!hasUsableKey()) { $('#prompt-dialog').close(); openSettings(); return toast('Add an API key first.', 'warning'); }

    const restructure = $('#restructure-toggle').checked;
    $('#prompt-dialog').close();
    setBusy(true, restructure ? 'Rewriting the markup…' : 'Working out the change…', true);

    try {
        const result = restructure
            ? await requestRestructure(state.selected, prompt)
            : await requestEdit(state.selected, prompt);

        if (result.patch) {
            lastChangeset = { patch: result.patch, summary: result.summary };
            renderChangeset();
        } else {
            renderInspector();
        }

        showTab('style');
        toast(result.summary || 'Applied.', 'success', 6000);
        $('#prompt-input').value = '';
        syncSpend();
    } catch (error) {
        if (error.message !== 'Cancelled.') toast(error.message, 'error', 9000);
    } finally {
        setBusy(false);
    }
}

/**
 * The change list, with a switch per element.
 *
 * A model rarely gets a whole section wrong — it gets one heading wrong.
 * Without this the only remedy is undo, which throws away the good work too.
 */
function renderChangeset() {
    const host = $('#panel-style');
    clear(host);

    const { patch, summary } = lastChangeset;

    host.append(
        el('div', { class: 'group' }, [
            el('h3', { text: 'This change' }),
            summary ? el('p', { class: 'hint', style: 'margin-top:0', text: summary }) : null,

            ...patch.rules.map((rule) =>
                changeRow(patch, rule)
            ),

            el('button', {
                type: 'button',
                class: 'btn btn-sm',
                text: 'Back to styles',
                onClick: () => { lastChangeset = null; renderInspector(); },
            }),
        ])
    );
}

function changeRow(patch, rule) {
    const box = el('input', { type: 'checkbox', checked: rule.enabled !== false });

    const row = el('label', { class: 'change', dataset: { enabled: String(rule.enabled !== false) } }, [
        box,
        el('span', { class: 'change-body' }, [
            el('strong', { text: rule.label || rule.uie }),
            el('code', {
                text: rule.declarations.map((d) => `${d.property}: ${d.value}`).join('; '),
            }),
        ]),
    ]);

    box.addEventListener('change', () => {
        setRuleEnabled(patch.id, rule.uie, box.checked);
        row.dataset.enabled = String(box.checked);
    });

    // Hovering a row shows you which element it is talking about.
    row.addEventListener('mouseenter', () => {
        const node = find(rule.uie);
        if (node) scrollTo(node);
    });

    return row;
}

/**
 * Three directions side by side, auditioned in place.
 *
 * Previewing writes to a slot outside the history stack, so flipping between
 * options costs nothing and leaves no undo entries behind — only the one you
 * commit becomes an edit.
 */
async function runVariants() {
    const prompt = $('#prompt-input').value.trim();
    if (!prompt) return toast('Describe what should change.', 'warning');
    if (!hasUsableKey()) { $('#prompt-dialog').close(); openSettings(); return toast('Add an API key first.', 'warning'); }

    $('#prompt-dialog').close();
    setBusy(true, 'Generating three directions…', true);

    try {
        const { variants } = await requestVariants(state.selected, prompt, 3);
        if (!variants || !variants.length) throw new Error('No usable variants came back.');

        showTab('style');
        renderVariants(variants, prompt);
        toast('Hover a variant to preview it, then Apply the one you want.', 'info', 7000);
    } catch (error) {
        toast(error.message, 'error', 9000);
    } finally {
        setBusy(false);
    }
}

function renderVariants(variants, prompt) {
    const host = $('#panel-style');
    clear(host);

    host.append(
        el('div', { class: 'group' }, [
            el('h3', { text: 'Variants' }),
            ...variants.map((variant) =>
                el('div', { class: 'variant' }, [
                    el('h4', { text: variant.label }),
                    el('p', { text: variant.rationale }),
                    el('button', {
                        type: 'button',
                        class: 'btn btn-sm',
                        text: 'Preview',
                        onClick: () => previewVariant(variant),
                    }),
                    el('button', {
                        type: 'button',
                        class: 'btn btn-sm btn-primary',
                        text: 'Apply',
                        onClick: () => {
                            previewVariant(null);
                            applyChanges(variant.changes, `${prompt} — ${variant.label}`);
                            renderInspector();
                            toast(`Applied "${variant.label}".`, 'success');
                        },
                    }),
                ])
            ),
            el('button', {
                type: 'button',
                class: 'btn btn-sm',
                text: 'Clear preview',
                onClick: () => { previewVariant(null); renderInspector(); },
            }),
        ])
    );
}

// ------------------------------------------------------------ review panel

function wireReviewPanel() {
    $('#audit-btn').addEventListener('click', () => {
        if (!state.doc) return toast('Load a page first.', 'warning');

        auditFindings = runAudit(state.doc, state.view);
        renderAudit();
        $('#audit-copy-btn').hidden = auditFindings.length === 0;
        showTab('review');
    });

    $('#audit-copy-btn').addEventListener('click', async () => {
        await copyText(auditAsMarkdown(auditFindings, state.url));
        toast('Report copied as Markdown.', 'success');
    });

    $('#critique-btn').addEventListener('click', async () => {
        if (!state.selected) return toast('Select an element first.', 'warning');
        if (!hasUsableKey()) { openSettings(); return toast('Add an API key first.', 'warning'); }

        setBusy(true, 'Reading the design…', true);

        try {
            const result = await requestCritique(state.selected);
            renderCritique(result);
            showTab('review');
        } catch (error) {
            if (error.message !== 'Cancelled.') toast(error.message, 'error', 9000);
        } finally {
            setBusy(false);
            syncSpend();
        }
    });

    $('#note-add-btn').addEventListener('click', () => {
        if (!state.selected) return toast('Select an element first.', 'warning');
        openNoteDialog(state.selected);
    });

    $('#note-copy-btn').addEventListener('click', async () => {
        await copyText(annotationsAsMarkdown());
        toast('Review notes copied as Markdown.', 'success');
    });

    $('#note-clear-btn').addEventListener('click', () => {
        clearAnnotations();
        toast('Notes cleared.', 'info', 2500);
    });
}

function renderNotes() {
    const host = $('#note-results');
    if (!host) return;
    clear(host);

    const notes = state.annotations;
    $('#note-copy-btn').hidden = notes.length === 0;
    $('#note-clear-btn').hidden = notes.length === 0;

    if (!notes.length) {
        host.append(el('p', { class: 'hint', text: 'No notes pinned yet.' }));
        return;
    }

    for (const note of notes) {
        host.append(
            el('div', { class: 'finding note-item', dataset: { severity: 'high' } }, [
                el('h4', {}, [
                    el('span', { class: 'note-num', text: String(note.number) }),
                    el('span', { text: note.label }),
                ]),
                el('p', { text: note.note }),
                el('button', {
                    type: 'button',
                    class: 'btn btn-sm',
                    text: 'Remove',
                    onClick: (event) => { event.stopPropagation(); removeAnnotation(note.id); },
                }),
            ])
        );
    }
}

function renderAudit() {
    const host = $('#audit-results');
    clear(host);

    if (!auditFindings.length) {
        host.append(el('p', { class: 'hint', text: 'No automated issues found. Automated checks cover roughly a third of WCAG — still review by hand.' }));
        return;
    }

    const counts = auditFindings.reduce((acc, item) => ({ ...acc, [item.severity]: (acc[item.severity] || 0) + 1 }), {});
    host.append(el('p', {
        class: 'hint',
        text: `${auditFindings.length} findings — ${counts.high || 0} high, ${counts.medium || 0} medium, ${counts.low || 0} low.`,
    }));

    for (const item of auditFindings) host.append(findingCard(item));
}

function renderCritique(result) {
    const host = $('#critique-results');
    clear(host);

    if (result.summary) host.append(el('p', { class: 'hint', text: result.summary }));

    for (const item of result.findings || []) {
        host.append(findingCard({ ...item, uie: item.id, label: '' }));
    }

    if (!(result.findings || []).length) {
        host.append(el('p', { class: 'hint', text: 'Nothing flagged for this selection.' }));
    }
}

function findingCard(item) {
    return el(
        'div',
        {
            class: 'finding',
            dataset: { severity: item.severity },
            onClick: () => {
                const node = item.uie && find(item.uie);
                if (node) { select(node); scrollTo(node); }
            },
        },
        [
            el('h4', { text: item.title }),
            el('p', { text: item.recommendation }),
            item.label ? el('code', { text: `<${item.label}>` }) : null,
        ]
    );
}

// ------------------------------------------------------------ tokens panel

function wireTokensPanel() {
    let tokens = null;

    $('#tokens-btn').addEventListener('click', () => {
        if (!state.doc) return toast('Load a page first.', 'warning');

        tokens = extractTokens(state.doc, state.view);
        renderTokens(tokens);
        $('#tokens-copy-btn').hidden = false;
        showTab('tokens');
    });

    $('#tokens-copy-btn').addEventListener('click', async () => {
        if (!tokens) return;
        await copyText(tokensAsCss(tokens));
        toast('Tokens copied as CSS custom properties.', 'success');
    });
}

function renderTokens(tokens) {
    const host = $('#tokens-results');
    clear(host);

    const swatches = (title, items) =>
        items.length
            ? el('div', { class: 'group' }, [
                  el('h3', { text: title }),
                  el(
                      'div',
                      { class: 'swatches' },
                      items.map((item) =>
                          el('button', {
                              type: 'button',
                              class: 'swatch',
                              style: `background:${item.value}`,
                              title: `${item.value} — used on ${item.count} elements`,
                              onClick: async () => { await copyText(item.value); toast(`${item.value} copied.`, 'success', 2000); },
                          })
                      )
                  ),
              ])
            : null;

    const scale = (title, items, unit = '') =>
        items.length
            ? el('div', { class: 'group' }, [
                  el('h3', { text: title }),
                  ...items.map((item) =>
                      el('div', { class: 'scale-item' }, [
                          el('span', { text: `${item.value}${unit}` }),
                          el('span', { text: `${item.count}×` }),
                      ])
                  ),
              ])
            : null;

    // Each builder returns null for an empty group, and `append(null)` writes
    // the string "null" into the panel. A page that ships no border radius —
    // or whose stylesheet failed to load — hit exactly that.
    const groups = [
        swatches('Text colours', tokens.colors),
        swatches('Surfaces', tokens.backgrounds),
        scale('Type scale', tokens.fontSizes),
        scale('Spacing', tokens.spacing),
        scale('Typefaces', tokens.fontFamilies),
        scale('Radii', tokens.radii),
    ].filter(Boolean);

    if (!groups.length) {
        host.append(el('p', { class: 'hint', text: 'No tokens found — the page may not have loaded its stylesheets.' }));

        return;
    }

    host.append(...groups);
}

// ------------------------------------------------------------ history panel

function renderHistory() {
    const host = $('#history-results');
    if (!host) return;
    clear(host);

    if (!state.patches.length) {
        host.append(el('p', { class: 'empty', text: 'No edits yet.' }));
        return;
    }

    host.append(
        el('div', { class: 'group' }, [
            el('h3', { text: `${state.patches.length} edits` }),
            ...state.patches
                .slice()
                .reverse()
                .map((patch) =>
                    el('div', { class: 'finding', dataset: { severity: 'low' } }, [
                        el('h4', { text: patch.label || patch.kind }),
                        el('p', { text: patch.kind === 'style' ? `${patch.rules.length} element(s) restyled` : `${patch.kind} change` }),
                    ])
                ),
        ])
    );
}

// ------------------------------------------------------------------ chrome

function showTab(name) {
    const tab = $(`.tabs [data-tab="${name}"]`);
    if (tab) tab.click();
}

/**
 * A model call can run for tens of seconds. An unmoving spinner with no way
 * out reads as a hang, so this counts up and offers a way to stop.
 */
function setBusy(on, message = '', cancellable = false) {
    $('#busy').dataset.open = String(on);
    if (message) $('#busy-text').textContent = message;

    $('#cancel-btn').hidden = !on || !cancellable;
    clearInterval(elapsedTimer);

    if (!on) {
        $('#busy-elapsed').textContent = '';
        return;
    }

    const started = performance.now();
    $('#busy-elapsed').textContent = '0s';
    elapsedTimer = setInterval(() => {
        $('#busy-elapsed').textContent = `${Math.round((performance.now() - started) / 1000)}s`;
    }, 500);
}

/** Running token and cost total, so exploring never produces a bill surprise. */
function syncSpend() {
    const pill = $('#spend-pill');

    if (!spend.requests) {
        pill.hidden = true;
        return;
    }

    const tokens = spend.inputTokens + spend.outputTokens;
    const cost = spend.priced ? `$${spend.costUsd.toFixed(4)}` : `~$${spend.costUsd.toFixed(4)}+`;

    pill.hidden = false;
    $('#spend-text').textContent = `${spend.requests} calls · ${tokens.toLocaleString()} tokens · ${cost}`;
    pill.title = spend.priced
        ? `${spend.inputTokens.toLocaleString()} in / ${spend.outputTokens.toLocaleString()} out this session`
        : 'One or more models used has no published price, so this total is a lower bound.';
}

function setStatus(text) {
    $('#status-text').textContent = text;
}

function syncButtons() {
    const hasSelection = Boolean(state.selected);
    const loaded = Boolean(state.doc);

    $('#ask-btn').disabled = !hasSelection;
    $('#critique-btn').disabled = !hasSelection;
    $('#note-add-btn').disabled = !hasSelection;
    $('#export-btn').disabled = !loaded;
    $('#compare-btn').disabled = !loaded;
    $('#notes-btn').disabled = !loaded;
    $('#undo-btn').disabled = state.patches.length === 0;
    $('#redo-btn').disabled = state.undone.length === 0;
}

function syncKeyPill() {
    const pill = $('#key-pill');
    const provider = currentProvider();
    const ready = hasUsableKey();

    pill.classList.toggle('ready', ready);
    $('#key-pill-text').textContent = ready
        ? `${provider.label} ready`
        : `${provider.label} — no key`;
}
