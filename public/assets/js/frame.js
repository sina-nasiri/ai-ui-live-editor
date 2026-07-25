import { onFrame, describe } from './util.js';
import { state, attachDocument } from './store.js';

/**
 * Loading pages into the preview, and picking elements out of them.
 *
 * Two decisions shape this file:
 *
 * 1. Highlights are drawn on floating overlays, not by putting an outline on
 *    the element itself. Styling the target changes its layout and pollutes
 *    the HTML the user later copies — the previous version needed two separate
 *    routines to scrub its own injected styles back out.
 *
 * 2. There is exactly one delegated listener per event, not two listeners per
 *    element. On a real page that is the difference between a handful of
 *    listeners and several thousand, and between a snappy load and a stall.
 */

let idCounter = 0;
let hoverBox = null;
let selectBox = null;
let onSelect = () => {};
let onKey = () => {};

/** Never selectable: structural, invisible, or ours. */
const SKIP = new Set(['html', 'head', 'meta', 'title', 'link', 'style', 'script', 'base', 'br']);

export function onSelectionChange(fn) {
    onSelect = fn;
}

/**
 * Register the app's keyboard handler so it also fires inside the preview.
 *
 * Once you click an element, keyboard focus is in the iframe document — and a
 * listener bound to the parent document never hears about it. Without this,
 * Cmd+Z stops working the moment you actually start editing, which is exactly
 * when you need it.
 */
export function onKeyDown(fn) {
    onKey = fn;
}

/**
 * Load a snapshot into the iframe.
 *
 * A blob URL keeps the document same-origin so the editor can read and write
 * its DOM. That is only safe because the server has already stripped every
 * script from the snapshot — see PageSnapshot.
 */
export function loadSnapshot(frame, html, url) {
    return new Promise((resolve, reject) => {
        const blob = new Blob([html], { type: 'text/html' });
        const blobUrl = URL.createObjectURL(blob);

        const done = () => {
            // The old blob URL leaked on every load; release it once the
            // document has taken ownership of the bytes.
            URL.revokeObjectURL(blobUrl);
            frame.removeEventListener('load', done);

            try {
                const doc = frame.contentDocument;
                const view = frame.contentWindow;
                if (!doc || !doc.body) throw new Error('The preview document did not initialise.');

                attachDocument(doc, view, frame, url);
                indexElements(doc);
                installOverlays(doc);
                installListeners(doc, view);
                resolve(doc);
            } catch (error) {
                reject(error);
            }
        };

        frame.addEventListener('load', done);
        frame.src = blobUrl;
    });
}

/**
 * Give every element a stable id.
 *
 * These ids are the vocabulary the AI speaks: it never sees a CSS selector,
 * only "e42". That makes its output impossible to misapply to the wrong node,
 * and trivial to validate server-side.
 */
export function indexElements(doc, root = doc.body) {
    const nodes = root === doc.body ? doc.body.querySelectorAll('*') : [root, ...root.querySelectorAll('*')];

    for (const node of nodes) {
        if (!node.dataset.uie) node.dataset.uie = `e${++idCounter}`;
    }
}

function installOverlays(doc) {
    const chrome = doc.getElementById('editor-chrome') || doc.createElement('style');
    chrome.id = 'editor-chrome';
    chrome.textContent = `
        .uie-box {
            position: absolute;
            pointer-events: none;
            z-index: 2147483646;
            display: none;
            border-radius: 2px;
        }
        .uie-hover { border: 1px solid rgba(79,70,229,.9); background: rgba(79,70,229,.07); }
        .uie-select { border: 2px solid #4f46e5; background: rgba(79,70,229,.1); }
        .uie-tag {
            position: absolute;
            top: -19px; left: -2px;
            background: #4f46e5; color: #fff;
            font: 600 10px/1.6 ui-monospace, Menlo, monospace;
            padding: 0 5px; border-radius: 3px; white-space: nowrap;
        }
        html.uie-picking, html.uie-picking * { cursor: crosshair !important; }
    `;
    if (!chrome.parentNode) doc.head.append(chrome);

    hoverBox = makeBox(doc, 'uie-hover');
    selectBox = makeBox(doc, 'uie-select');
    selectBox.append(Object.assign(doc.createElement('span'), { className: 'uie-tag' }));

    doc.documentElement.classList.add('uie-picking');
}

function makeBox(doc, className) {
    const box = doc.createElement('div');
    box.className = `uie-box ${className}`;
    doc.body.append(box);
    return box;
}

function installListeners(doc, view) {
    const move = onFrame((event) => {
        const target = pick(event.target);
        if (!target || target === state.selected) {
            hide(hoverBox);
            return;
        }
        position(hoverBox, target, view);
    });

    doc.addEventListener('mousemove', move, true);
    doc.addEventListener('mouseleave', () => hide(hoverBox), true);

    doc.addEventListener(
        'click',
        (event) => {
            // The snapshot has no scripts, but links and buttons still have
            // default behaviour that would navigate the preview away.
            event.preventDefault();
            event.stopPropagation();

            // Leave clicks alone while the user is editing text in place.
            if (event.target && event.target.isContentEditable) return;

            const target = pick(event.target);
            if (target) select(target);
        },
        true
    );

    const reflow = onFrame(() => {
        if (state.selected) position(selectBox, state.selected, view);
        hide(hoverBox);
    });

    view.addEventListener('scroll', reflow, true);
    view.addEventListener('resize', reflow);

    // Every shortcut has to work from inside the frame too, because that is
    // where focus lands as soon as you select something.
    doc.addEventListener('keydown', (event) => {
        handleKeys(event);
        onKey(event);
    });
}

function handleKeys(event) {
    if (!event.altKey || !state.selected) return;

    const map = {
        ArrowUp: () => state.selected.parentElement,
        ArrowDown: () => state.selected.firstElementChild,
        ArrowLeft: () => state.selected.previousElementSibling,
        ArrowRight: () => state.selected.nextElementSibling,
    };

    const next = map[event.key] && map[event.key]();
    if (!next) return;

    event.preventDefault();
    if (selectable(next)) select(next);
}

export { handleKeys as handleTreeKeys };

/**
 * Resolve an event target to something worth selecting.
 *
 * Clicking usually lands on the deepest node — often a bare <span> wrapping
 * two words. Anything smaller than a few pixels is treated as decoration and
 * we walk up to its parent instead.
 */
function pick(node) {
    let current = node;

    while (current && current.nodeType === 1) {
        if (selectable(current)) {
            const rect = current.getBoundingClientRect();
            if (rect.width >= 4 && rect.height >= 4) return current;
        }
        current = current.parentElement;
    }

    return null;
}

function selectable(node) {
    if (!node || node.nodeType !== 1) return false;
    if (node.classList && node.classList.contains('uie-box')) return false;
    if (node.closest && node.closest('.uie-box')) return false;
    return !SKIP.has(node.tagName.toLowerCase());
}

export function select(node) {
    if (!node || !state.view) return;

    state.selected = node;
    if (!node.dataset.uie) node.dataset.uie = `e${++idCounter}`;

    position(selectBox, node, state.view);
    const tag = selectBox.querySelector('.uie-tag');
    if (tag) tag.textContent = describe(node);
    hide(hoverBox);

    onSelect(node);
}

export function deselect() {
    state.selected = null;
    hide(selectBox);
    hide(hoverBox);
    onSelect(null);
}

export function scrollTo(node) {
    if (!node || !node.scrollIntoView) return;
    node.scrollIntoView({ block: 'center', behavior: 'smooth' });
}

/** Position an overlay over a node, in document (not viewport) coordinates. */
function position(box, node, view) {
    if (!box || !node || !node.getBoundingClientRect) return;

    const rect = node.getBoundingClientRect();
    box.style.display = 'block';
    box.style.top = `${rect.top + view.scrollY}px`;
    box.style.left = `${rect.left + view.scrollX}px`;
    box.style.width = `${rect.width}px`;
    box.style.height = `${rect.height}px`;
}

function hide(box) {
    if (box) box.style.display = 'none';
}

/** Ancestor chain from <body> down to the node, for the breadcrumb. */
export function ancestry(node) {
    const chain = [];
    let current = node;

    while (current && current.nodeType === 1 && current.tagName.toLowerCase() !== 'html') {
        chain.unshift(current);
        if (current.tagName.toLowerCase() === 'body') break;
        current = current.parentElement;
    }

    return chain.slice(-8);
}
