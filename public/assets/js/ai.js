import {
    state, pushStylePatch, pushTextPatch, pushHtmlPatch, find, setPreview,
    rememberTurn, conversationFor,
} from './store.js';
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

/** Running totals for the session, so the cost of exploring is never a surprise. */
export const spend = { requests: 0, inputTokens: 0, outputTokens: 0, costUsd: 0, priced: true };

let inFlight = null;

/** Abort whatever request is running. Returns true if there was one. */
export function cancelRequest() {
    if (!inFlight) return false;
    inFlight.abort();
    inFlight = null;
    return true;
}

export function isBusy() {
    return inFlight !== null;
}

async function send(endpoint, body) {
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const base = (window.__EDITOR__ && window.__EDITOR__.base) || '/';

    // One request at a time: a second Ask while the first is still running
    // would race to apply two patches built from the same starting state.
    cancelRequest();
    const controller = new AbortController();
    inFlight = controller;

    let response;
    try {
        response = await fetch(base + endpoint, {
            method: 'POST',
            signal: controller.signal,
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
    } catch (error) {
        if (error.name === 'AbortError') throw new Error('Cancelled.');
        throw new Error('Could not reach the server.');
    } finally {
        if (inFlight === controller) inFlight = null;
    }

    let data = {};
    try {
        data = await response.json();
    } catch {
        throw new Error('The server returned an unreadable response.');
    }

    if (!response.ok) throw new Error(data.error || `Request failed (${response.status}).`);

    recordSpend(data.usage);

    return data;
}

function recordSpend(usage) {
    if (!usage) return;

    spend.requests += 1;
    spend.inputTokens += usage.input_tokens || 0;
    spend.outputTokens += usage.output_tokens || 0;

    // A model with no published price makes the running total incomplete;
    // say so rather than quietly under-reporting.
    if (usage.cost_usd === null || usage.cost_usd === undefined) spend.priced = false;
    else spend.costUsd += usage.cost_usd;
}

/** Outline + design tokens + prior turns: what the model needs, nothing else. */
function context(node) {
    const tokens = extractTokens(state.doc, state.view);
    return {
        outline: buildOutline(node, state.view),
        theme: tokensAsText(tokens),
        history: conversationFor(node.dataset.uie),
    };
}

export async function requestEdit(node, prompt) {
    const uie = node.dataset.uie;
    const data = await send('ai/edit', { prompt, ...context(node) });

    const patch = applyChanges(data.changes, prompt);

    // Recorded after the fact so a failed or cancelled request does not
    // pollute the next refinement with something that never happened.
    rememberTurn(uie, prompt, data.summary || '');

    return { ...data, patch };
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

    for (const change of changes || []) {
        const node = find(change.id);
        if (!node) continue;

        if (change.declarations && change.declarations.length) {
            rules.push({
                uie: change.id,
                declarations: change.declarations,
                // Every rule starts accepted; the review list can switch
                // individual ones back off without discarding the rest.
                enabled: true,
                label: describeTarget(node),
            });
        }

        if (change.text && change.text.trim() && change.text.trim() !== node.textContent.trim()) {
            pushTextPatch({
                uie: change.id,
                before: node.textContent,
                after: change.text,
                label: `Text: ${label}`,
            });
            node.textContent = change.text;
        }
    }

    return rules.length ? pushStylePatch({ label, rules, source: 'ai' }) : null;
}

function describeTarget(node) {
    const tag = node.tagName.toLowerCase();
    const text = (node.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 40);
    return text ? `${tag} — "${text}"` : tag;
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
