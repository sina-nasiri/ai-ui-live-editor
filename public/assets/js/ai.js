import { state, pushStylePatch, pushTextPatch, pushHtmlPatch, find, setPreview } from './store.js';
import { buildOutline } from './outline.js';
import { extractTokens, tokensAsText } from './tokens.js';
import { indexElements } from './frame.js';

/**
 * Talking to the model, and turning what comes back into edits.
 *
 * Provider choice lives entirely in configuration and in one field of the
 * request body. Nothing downstream of `send()` knows or cares whether Claude,
 * GPT, or Gemini produced the JSON — they all answer the same schema.
 */

const KEY_STORE = 'aiuie.keys.v1';
const PREF_STORE = 'aiuie.prefs.v1';

export const settings = {
    catalogue: null,
    provider: 'anthropic',
    model: null,
    keys: {},
};

export function initSettings(catalogue) {
    settings.catalogue = catalogue;
    settings.provider = catalogue.default;

    try {
        const prefs = JSON.parse(localStorage.getItem(PREF_STORE) || '{}');
        if (prefs.provider && catalogue.providers[prefs.provider]) settings.provider = prefs.provider;
        if (prefs.model) settings.model = prefs.model;
        settings.keys = JSON.parse(localStorage.getItem(KEY_STORE) || '{}');
    } catch {
        settings.keys = {};
    }

    if (!settings.model) settings.model = catalogue.providers[settings.provider].default_model;
}

export function savePrefs() {
    try {
        localStorage.setItem(PREF_STORE, JSON.stringify({ provider: settings.provider, model: settings.model }));
        localStorage.setItem(KEY_STORE, JSON.stringify(settings.keys));
    } catch {
        /* storage unavailable — the session still works, it just won't persist */
    }
}

export function currentProvider() {
    return settings.catalogue.providers[settings.provider] || {};
}

/** True when this provider can be used without the visitor pasting a key. */
export function hasUsableKey() {
    const provider = currentProvider();
    return Boolean(provider.server_key || (settings.keys[settings.provider] || '').trim());
}

// ----------------------------------------------------------------- requests

async function send(endpoint, body) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const base = (window.__EDITOR__ && window.__EDITOR__.base) || '/';

    const response = await fetch(base + endpoint, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': token || '',
        },
        body: JSON.stringify({
            provider: settings.provider,
            model: settings.model,
            // Omitted entirely when the server has its own key.
            api_key: currentProvider().server_key ? undefined : settings.keys[settings.provider],
            ...body,
        }),
    });

    let data = {};
    try {
        data = await response.json();
    } catch {
        throw new Error('The server returned an unreadable response.');
    }

    if (!response.ok) throw new Error(data.error || `Request failed (${response.status}).`);

    return data;
}

/** Outline + design tokens: everything the model needs, and nothing else. */
function context(node) {
    const tokens = extractTokens(state.doc, state.view);
    return {
        outline: buildOutline(node, state.view),
        theme: tokensAsText(tokens),
    };
}

export async function requestEdit(node, prompt) {
    const data = await send('ai/edit', { prompt, ...context(node) });
    applyChanges(data.changes, prompt);
    return data;
}

export async function requestVariants(node, prompt, count = 3) {
    return send('ai/variants', { prompt, count, ...context(node) });
}

export async function requestCritique(node) {
    return send('ai/critique', { prompt: 'Critique this section.', ...context(node) });
}

export async function requestRestructure(node, prompt) {
    const tokens = extractTokens(state.doc, state.view);
    const data = await send('ai/restructure', {
        prompt,
        theme: tokensAsText(tokens),
        html: cleanHtml(node),
    });

    const uie = node.dataset.uie;
    const before = node.outerHTML;

    node.outerHTML = data.html;

    const replacement = find(uie) || state.doc.querySelector(`[data-uie="${uie}"]`);
    if (replacement) {
        // The model does not echo our ids back, so re-stamp the new subtree.
        replacement.dataset.uie = uie;
        indexElements(state.doc, replacement);
    }

    pushHtmlPatch({ uie, before, after: data.html, label: prompt });

    return data;
}

// ------------------------------------------------------------------- apply

/**
 * Turn a change list into exactly one undo step.
 *
 * Style declarations become a single patch; text edits are separate because
 * they mutate the DOM and need their own before/after record.
 */
export function applyChanges(changes, label) {
    const rules = [];
    let applied = 0;

    for (const change of changes || []) {
        const node = find(change.id);
        if (!node) continue;

        if (change.declarations && change.declarations.length) {
            rules.push({ uie: change.id, declarations: change.declarations });
            applied += 1;
        }

        if (change.text && change.text.trim() && change.text.trim() !== node.textContent.trim()) {
            pushTextPatch({
                uie: change.id,
                before: node.textContent,
                after: change.text,
                label: `Text: ${label}`,
            });
            node.textContent = change.text;
            applied += 1;
        }
    }

    if (rules.length) pushStylePatch({ label, rules, source: 'ai' });

    return applied;
}

/** Show a variant without committing it, so switching between them is free. */
export function previewVariant(variant) {
    if (!variant) {
        setPreview(null);
        return;
    }

    setPreview(
        (variant.changes || [])
            .filter((change) => change.declarations && change.declarations.length && find(change.id))
            .map((change) => ({ uie: change.id, declarations: change.declarations }))
    );
}

/** Strip editor bookkeeping before sending markup out for restructuring. */
function cleanHtml(node) {
    const clone = node.cloneNode(true);

    for (const element of [clone, ...clone.querySelectorAll('*')]) {
        element.removeAttribute?.('data-uie');
        element.classList?.remove('uie-box', 'uie-hover', 'uie-select');
        if (element.classList && element.classList.length === 0) element.removeAttribute('class');
    }

    for (const box of clone.querySelectorAll('.uie-box')) box.remove();

    return clone.outerHTML;
}
