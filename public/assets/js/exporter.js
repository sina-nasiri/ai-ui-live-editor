import { state, exportCss } from './store.js';
import { cssPath, download, copyText } from './util.js';

/**
 * Getting work back out of the editor.
 *
 * Without this the tool is a toy: everything you did vanishes on refresh and
 * there is nothing to hand a developer. Two deliverables cover the real
 * workflows — a standalone HTML file to send round for review, and a plain
 * CSS diff of exactly what changed.
 */

/** The CSS a developer receives: real selectors, merged, no editor artefacts. */
export function changesAsCss() {
    return exportCss((node) => cssPath(node, state.doc.body));
}

export async function copyChangesAsCss() {
    return copyText(changesAsCss());
}

export function downloadChangesAsCss() {
    download(`${slug(state.url)}-changes.css`, changesAsCss(), 'text/css');
}

/** Clean outerHTML of one element — no ids, no overlays, links restored. */
export function elementHtml(node) {
    const clone = node.cloneNode(true);
    strip(clone);
    return clone.outerHTML;
}

export async function copyElementHtml(node) {
    return copyText(elementHtml(node));
}

/**
 * A self-contained snapshot of the edited page.
 *
 * The patch stylesheet is inlined as-is, so the exported file renders exactly
 * what was on screen — the edits are not baked into the markup, they travel as
 * the same CSS you would hand to a developer.
 */
export function downloadPage() {
    if (!state.doc) return;

    const clone = state.doc.documentElement.cloneNode(true);
    strip(clone);

    const note = state.doc.createElement('meta');
    note.setAttribute('name', 'generator');
    note.setAttribute('content', `AI UI Live Editor — edited snapshot of ${state.url}`);
    clone.querySelector('head')?.prepend(note);

    download(`${slug(state.url)}-edited.html`, `<!DOCTYPE html>\n${clone.outerHTML}`, 'text/html');
}

/**
 * Remove everything the editor added.
 *
 * Data attributes, overlay elements, and the chrome stylesheet are all ours.
 * The patch stylesheet stays — that is the user's work. Anchors get their
 * href back, since the proxy parked it on data-editor-href to stop the
 * preview navigating away mid-edit.
 */
function strip(root) {
    for (const box of root.querySelectorAll?.('.uie-box') || []) box.remove();
    root.querySelector?.('#editor-chrome')?.remove();

    const all = root.querySelectorAll ? [root, ...root.querySelectorAll('*')] : [root];

    for (const node of all) {
        if (!node.removeAttribute) continue;

        node.removeAttribute('data-uie');
        node.removeAttribute('contenteditable');
        node.classList?.remove('uie-box', 'uie-hover', 'uie-select');

        const parked = node.getAttribute?.('data-editor-href');
        if (parked) {
            node.setAttribute('href', parked);
            node.removeAttribute('data-editor-href');
        }

        if (node.classList && node.classList.length === 0 && node.getAttribute('class') !== null) {
            node.removeAttribute('class');
        }
    }
}

function slug(url) {
    try {
        return new URL(url).hostname.replace(/^www\./, '').replace(/[^a-z0-9.-]/gi, '-');
    } catch {
        return 'page';
    }
}
