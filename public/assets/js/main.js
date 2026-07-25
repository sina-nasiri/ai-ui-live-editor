import { $, $$, el, clear, describe, debounce, copyText } from './util.js';
import { toast } from './toast.js';
import {
    state, subscribe, undo, redo, find, saveSession, loadSession, restorePatches, forgetSession,
} from './store.js';
import { loadSnapshot, onSelectionChange, onKeyDown, select, deselect, scrollTo, ancestry } from './frame.js';
import { mountInspector, renderInspector } from './inspector.js';
import { runAudit, auditAsMarkdown } from './audit.js';
import { extractTokens, tokensAsCss } from './tokens.js';
import {
    settings, initSettings, savePrefs, currentProvider, hasUsableKey,
    requestEdit, requestVariants, requestCritique, requestRestructure, previewVariant, applyChanges,
} from './ai.js';
import {
    copyChangesAsCss, downloadChangesAsCss, copyElementHtml, downloadPage,
} from './exporter.js';

const config = window.__EDITOR__;
const base = config.base;

let auditFindings = [];

// --------------------------------------------------------------- bootstrap

initSettings(config.ai);
mountInspector($('#panel-style'), { onTextEdit: () => renderInspector() });
onSelectionChange(handleSelection);
subscribe(debounce(() => { renderHistory(); saveSession(); }, 250));
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
    setBusy(true, 'Fetching and cleaning the page…');
    setStatus('Loading…');

    try {
        const response = await fetch(`${base}proxy`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json, text/html',
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').content,
            },
            body: JSON.stringify({ url }),
        });

        if (!response.ok) {
            let message = `The page could not be loaded (${response.status}).`;
            if ((response.headers.get('content-type') || '').includes('json')) {
                const data = await response.json().catch(() => ({}));
                message = data.error || message;
            }
            throw new Error(message);
        }

        await loadSnapshot($('#preview'), await response.text(), url);

        $('#placeholder').dataset.open = 'false';
        setStatus('Ready — click anything in the page to select it.');
        toast('Page loaded.', 'success');

        auditFindings = [];
        clear($('#audit-results'));
        clear($('#critique-results'));
        clear($('#tokens-results'));
        syncButtons();
    } catch (error) {
        setStatus('Could not load that page.');
        toast(error.message, 'error', 8000);
    } finally {
        setBusy(false);
    }
}

function offerSessionRestore() {
    const session = loadSession();
    if (!session) return;

    const age = Math.round((Date.now() - session.savedAt) / 60000);
    const label = age < 1 ? 'just now' : age < 60 ? `${age} min ago` : `${Math.round(age / 60)} h ago`;

    toast(`Unsaved session from ${label} — press Load with the same URL to restore it.`, 'info', 9000);
    $('#url-input').value = session.url;

    // Restoring the patch stack only makes sense once the same page is back.
    const once = async () => {
        if (state.url === session.url && session.patches.length) {
            restorePatches(session.patches);
            toast(`Restored ${session.patches.length} previous edits.`, 'success');
        } else {
            forgetSession();
        }
        unsubscribe();
    };

    const unsubscribe = subscribe(() => {
        if (state.doc) { once(); }
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
    setBusy(true, restructure ? 'Rewriting the markup…' : 'Working out the change…');

    try {
        const result = restructure
            ? await requestRestructure(state.selected, prompt)
            : await requestEdit(state.selected, prompt);

        renderInspector();
        toast(result.summary || 'Applied.', 'success', 6000);
        $('#prompt-input').value = '';
    } catch (error) {
        toast(error.message, 'error', 9000);
    } finally {
        setBusy(false);
    }
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
    setBusy(true, 'Generating three directions…');

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

        setBusy(true, 'Reading the design…');

        try {
            const result = await requestCritique(state.selected);
            renderCritique(result);
            showTab('review');
        } catch (error) {
            toast(error.message, 'error', 9000);
        } finally {
            setBusy(false);
        }
    });
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

    host.append(
        swatches('Text colours', tokens.colors),
        swatches('Surfaces', tokens.backgrounds),
        scale('Type scale', tokens.fontSizes),
        scale('Spacing', tokens.spacing),
        scale('Typefaces', tokens.fontFamilies),
        scale('Radii', tokens.radii)
    );
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

function setBusy(on, message = '') {
    $('#busy').dataset.open = String(on);
    if (message) $('#busy-text').textContent = message;
}

function setStatus(text) {
    $('#status-text').textContent = text;
}

function syncButtons() {
    const hasSelection = Boolean(state.selected);
    const loaded = Boolean(state.doc);

    $('#ask-btn').disabled = !hasSelection;
    $('#critique-btn').disabled = !hasSelection;
    $('#export-btn').disabled = !loaded;
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
