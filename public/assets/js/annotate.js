import { state } from './store.js';
import { describe, cssPath } from './util.js';

/**
 * Redline mode — pin numbered comments to elements.
 *
 * This is the actual daily deliverable of a UX reviewer: not a redesign, a
 * marked-up page saying what is wrong and where. Annotations are deliberately
 * *not* patches — they change nothing about the page, so they sit outside the
 * undo stack and survive every edit you make around them.
 */

let active = false;
let layer = null;
let onChange = () => {};
let nextNumber = 1;

export function onAnnotationsChange(fn) {
    onChange = fn;
}

export function isAnnotating() {
    return active;
}

export function setAnnotating(on) {
    active = on;
    state.doc?.documentElement.classList.toggle('uie-annotating', on);
    return active;
}

/** Pin a note to an element. Returns the created annotation. */
export function addAnnotation(node, note) {
    if (!node || !state.doc) return null;

    const annotation = {
        id: `a${Date.now()}${Math.round(performance.now())}`,
        uie: node.dataset.uie,
        label: describe(node),
        selector: cssPath(node, state.doc.body),
        note: String(note || '').slice(0, 2000),
        number: nextNumber++,
    };

    state.annotations.push(annotation);
    renderPins();
    onChange();

    return annotation;
}

export function removeAnnotation(id) {
    state.annotations = state.annotations.filter((entry) => entry.id !== id);
    renderPins();
    onChange();
}

export function clearAnnotations() {
    state.annotations = [];
    nextNumber = 1;
    renderPins();
    onChange();
}

/**
 * Draw the pins.
 *
 * Positions are computed from the live element each time rather than stored,
 * so a pin stays attached to its element after an edit reflows the page.
 */
export function renderPins() {
    if (!state.doc || !state.doc.body) return;

    ensureLayer();
    layer.replaceChildren();

    for (const annotation of state.annotations) {
        const node = state.doc.querySelector(`[data-uie="${CSS.escape(annotation.uie)}"]`);
        if (!node) continue;

        const rect = node.getBoundingClientRect();

        const pin = state.doc.createElement('div');
        pin.className = 'uie-pin';
        pin.textContent = String(annotation.number);
        pin.title = annotation.note;
        pin.style.top = `${rect.top + state.view.scrollY - 10}px`;
        pin.style.left = `${rect.left + state.view.scrollX - 10}px`;

        const outline = state.doc.createElement('div');
        outline.className = 'uie-pin-outline';
        outline.style.top = `${rect.top + state.view.scrollY}px`;
        outline.style.left = `${rect.left + state.view.scrollX}px`;
        outline.style.width = `${rect.width}px`;
        outline.style.height = `${rect.height}px`;

        layer.append(outline, pin);
    }
}

function ensureLayer() {
    // `isConnected` is not enough. A node in a *replaced* iframe document is
    // still connected — to the old, detached document. Checking the owner
    // document is what catches a second page load; without it the pins are
    // appended somewhere invisible and silently never appear.
    if (layer && layer.isConnected && layer.ownerDocument === state.doc) return;

    const style = state.doc.getElementById('editor-chrome');
    if (style && !style.textContent.includes('uie-pin')) {
        style.textContent += `
            .uie-annotate-layer { position: absolute; inset: 0; pointer-events: none; z-index: 2147483645; }
            .uie-pin {
                position: absolute;
                width: 20px; height: 20px;
                border-radius: 50%;
                background: #dc2626; color: #fff;
                font: 700 11px/20px ui-monospace, Menlo, monospace;
                text-align: center;
                box-shadow: 0 1px 4px rgba(0,0,0,.4);
            }
            .uie-pin-outline { position: absolute; border: 1px dashed rgba(220,38,38,.8); border-radius: 2px; }
            html.uie-annotating, html.uie-annotating * { cursor: cell !important; }
        `;
    }

    layer = state.doc.createElement('div');
    layer.className = 'uie-annotate-layer';
    state.doc.body.append(layer);
}

/** The review document a reader can act on without opening the tool. */
export function annotationsAsMarkdown() {
    if (!state.annotations.length) return '# Review notes\n\nNo notes yet.\n';

    const lines = [`# Review notes — ${state.url}`, '', `${state.annotations.length} notes.`, ''];

    for (const annotation of state.annotations) {
        lines.push(`### ${annotation.number}. \`${annotation.label}\``);
        lines.push('');
        lines.push(annotation.note || '_No note._');
        lines.push('');
        lines.push(`<sub>Selector: \`${annotation.selector}\`</sub>`);
        lines.push('');
    }

    return lines.join('\n');
}
