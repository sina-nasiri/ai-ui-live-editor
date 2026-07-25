/**
 * Application state and the edit history.
 *
 * The central idea: an edit is *data*, not a DOM mutation. Nearly every change
 * the editor makes is a list of CSS declarations keyed to an element id, held
 * in an ordered stack and rendered into one stylesheet inside the preview.
 *
 * That buys three things at once. Undo is removing an item from a list rather
 * than reconstructing markup. The user's content can never be destroyed by a
 * restyle. And the stack *is* the export — the CSS a developer receives is the
 * same data that drove the preview, not a diff reverse-engineered from it.
 */

const STORAGE_KEY = 'aiuie.session.v1';
const PATCH_STYLE_ID = 'editor-patches';

export const state = {
    url: null,
    frame: null,
    doc: null,
    view: null,
    selected: null,
    patches: [],
    undone: [],
    /** Not part of history — a variant being auditioned, discarded on commit. */
    preview: null,
    nextId: 1,
};

const listeners = new Set();

export function subscribe(fn) {
    listeners.add(fn);
    return () => listeners.delete(fn);
}

function emit() {
    for (const fn of listeners) fn(state);
}

export function attachDocument(doc, view, frame, url) {
    state.doc = doc;
    state.view = view;
    state.frame = frame;
    state.url = url;
    state.selected = null;
    state.patches = [];
    state.undone = [];
    state.preview = null;
    emit();
}

// ------------------------------------------------------------------ patches

/**
 * A style patch: one or more elements, each with a list of declarations.
 * `source` lets the inspector recognise and coalesce its own consecutive
 * edits so dragging a slider does not push fifty history entries.
 */
export function pushStylePatch({ label, rules, source = 'ai' }) {
    const patch = { kind: 'style', id: state.nextId++, label, rules, source };
    commit(patch);
    return patch;
}

export function pushTextPatch({ uie, before, after, label }) {
    commit({ kind: 'text', id: state.nextId++, uie, before, after, label });
}

export function pushHtmlPatch({ uie, before, after, label }) {
    commit({ kind: 'html', id: state.nextId++, uie, before, after, label });
}

function commit(patch) {
    state.patches.push(patch);
    // A new edit invalidates the redo branch, same as any editor.
    state.undone = [];
    state.preview = null;
    render();
    emit();
}

/**
 * Merge into the previous patch when the inspector is still adjusting the
 * same property on the same element, so one drag is one undo step.
 */
export function updateInspectorPatch(uie, property, value, label) {
    const top = state.patches[state.patches.length - 1];
    const matches =
        top &&
        top.kind === 'style' &&
        top.source === 'inspector' &&
        top.rules.length === 1 &&
        top.rules[0].uie === uie &&
        top.rules[0].declarations.length === 1 &&
        top.rules[0].declarations[0].property === property;

    if (matches) {
        top.rules[0].declarations[0].value = value;
        state.undone = [];
        render();
        emit();
        return;
    }

    pushStylePatch({
        label,
        source: 'inspector',
        rules: [{ uie, declarations: [{ property, value }] }],
    });
}

export function undo() {
    const patch = state.patches.pop();
    if (!patch) return false;
    revert(patch);
    state.undone.push(patch);
    render();
    emit();
    return true;
}

export function redo() {
    const patch = state.undone.pop();
    if (!patch) return false;
    reapply(patch);
    state.patches.push(patch);
    render();
    emit();
    return true;
}

export function clearPatches() {
    for (let i = state.patches.length - 1; i >= 0; i -= 1) revert(state.patches[i]);
    state.patches = [];
    state.undone = [];
    state.preview = null;
    render();
    emit();
}

// Style patches need no per-patch work: the whole stylesheet is re-rendered
// from the stack. Only DOM-mutating patches have to be walked back.
function revert(patch) {
    if (patch.kind === 'text') {
        const node = find(patch.uie);
        if (node) node.textContent = patch.before;
    } else if (patch.kind === 'html') {
        const node = find(patch.uie);
        if (node) node.outerHTML = patch.before;
    }
}

function reapply(patch) {
    if (patch.kind === 'text') {
        const node = find(patch.uie);
        if (node) node.textContent = patch.after;
    } else if (patch.kind === 'html') {
        const node = find(patch.uie);
        if (node) node.outerHTML = patch.after;
    }
}

export function find(uie) {
    return state.doc ? state.doc.querySelector(`[data-uie="${CSS.escape(uie)}"]`) : null;
}

// ------------------------------------------------------------------- render

/** Audition a variant without touching history. */
export function setPreview(rules) {
    state.preview = rules && rules.length ? { rules } : null;
    render();
    emit();
}

/**
 * How hard a patch selector has to fight to win.
 *
 * `[data-uie="e7"]` on its own scores (0,1,0) — a plain `.hero h1` in the
 * site's own stylesheet outscores it and the patch silently does nothing,
 * which looks exactly like the AI ignoring you. Repeating the attribute
 * lifts the score to (0,3,0), and `!important` (added per declaration below)
 * covers the rest, including elements carrying an inline `style` attribute.
 *
 * This applies to the *preview* only. The CSS export uses ordinary selectors
 * with no `!important` — see exportCss.
 */
const SELECTOR_REPEATS = 3;

/**
 * Rebuild the patch stylesheet from the stack.
 *
 * Order still matters between patches: later ones emit later rules, so the
 * most recent edit to the same property wins.
 */
export function render() {
    if (!state.doc) return;

    let sheet = state.doc.getElementById(PATCH_STYLE_ID);
    if (!sheet) {
        sheet = state.doc.createElement('style');
        sheet.id = PATCH_STYLE_ID;
        state.doc.head.append(sheet);
    }

    const blocks = [];
    const all = state.preview ? [...state.patches, state.preview] : state.patches;

    for (const patch of all) {
        if (patch.kind && patch.kind !== 'style') continue;
        for (const rule of patch.rules || []) {
            const selector = `[data-uie="${rule.uie}"]`.repeat(SELECTOR_REPEATS);
            const body = (rule.declarations || [])
                .map(({ property, value }) => `  ${property}: ${value} !important;`)
                .join('\n');
            if (body) blocks.push(`${selector} {\n${body}\n}`);
        }
    }

    sheet.textContent = blocks.join('\n\n');
}

/** The stack rendered as CSS with real selectors — the developer handoff. */
export function exportCss(selectorFor) {
    const merged = new Map();

    for (const patch of state.patches) {
        if (patch.kind !== 'style') continue;
        for (const rule of patch.rules || []) {
            const existing = merged.get(rule.uie) || new Map();
            for (const { property, value } of rule.declarations || []) {
                existing.set(property, value);
            }
            merged.set(rule.uie, existing);
        }
    }

    const blocks = [];
    for (const [uie, declarations] of merged) {
        const node = find(uie);
        if (!node) continue;
        const body = Array.from(declarations, ([property, value]) => `  ${property}: ${value};`).join('\n');
        blocks.push(`${selectorFor(node)} {\n${body}\n}`);
    }

    if (!blocks.length) return '/* No style changes yet. */\n';

    // Deliberately no !important here, unlike the preview sheet. This file is
    // meant to be read and merged by a person, and shipping a wall of
    // !important would poison whatever stylesheet it lands in. Depending on
    // where it goes, some rules may need a more specific selector.
    return [
        `/* Changes made in AI UI Live Editor on ${state.url} */`,
        '/* Selectors are best-effort. Check specificity against your own',
        '   stylesheet — the editor preview forces these to win, this file',
        '   plays by the normal cascade rules. */',
        '',
        blocks.join('\n\n'),
        '',
    ].join('\n');
}

// -------------------------------------------------------------- persistence

/**
 * Sessions survive a refresh. Only the URL and the patch stack are stored —
 * never the page itself, and never an API key.
 */
export function saveSession() {
    if (!state.url) return;
    try {
        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({
                url: state.url,
                savedAt: Date.now(),
                patches: state.patches.filter((patch) => patch.kind === 'style'),
            })
        );
    } catch {
        // Private browsing or a full quota — losing the session is acceptable.
    }
}

export function loadSession() {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        if (!raw) return null;
        const data = JSON.parse(raw);
        return data && data.url && Array.isArray(data.patches) ? data : null;
    } catch {
        return null;
    }
}

export function restorePatches(patches) {
    state.patches = patches.map((patch) => ({ ...patch, id: state.nextId++ }));
    state.undone = [];
    render();
    emit();
}

export function forgetSession() {
    try {
        localStorage.removeItem(STORAGE_KEY);
    } catch {
        /* nothing to do */
    }
}
