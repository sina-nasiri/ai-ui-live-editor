// Small shared helpers. Kept free of app state so anything can import them.

export const $ = (selector, root = document) => root.querySelector(selector);
export const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

/** Build an element in one call. Children may be nodes or strings. */
export function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);

    for (const [key, value] of Object.entries(attrs)) {
        if (value === null || value === undefined || value === false) continue;
        if (key === 'class') node.className = value;
        else if (key === 'text') node.textContent = value;
        else if (key === 'dataset') Object.assign(node.dataset, value);
        else if (key.startsWith('on') && typeof value === 'function') {
            node.addEventListener(key.slice(2).toLowerCase(), value);
        } else node.setAttribute(key, value === true ? '' : String(value));
    }

    for (const child of [].concat(children)) {
        if (child === null || child === undefined) continue;
        node.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }

    return node;
}

export function clear(node) {
    while (node.firstChild) node.removeChild(node.firstChild);
}

/** Coalesce bursts of events (mousemove, scroll) into one frame. */
export function onFrame(fn) {
    let queued = false;
    let lastArgs = null;

    return (...args) => {
        lastArgs = args;
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => {
            queued = false;
            fn(...lastArgs);
        });
    };
}

export function debounce(fn, ms = 200) {
    let timer = null;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), ms);
    };
}

/**
 * True for the editor's own overlays inside the preview document.
 *
 * These have to be skipped everywhere we read the page, or the editor starts
 * measuring itself: the selection box's indigo shows up in the extracted
 * palette, its label contributes a monospace typeface to the type scale, and
 * the accessibility audit reports contrast findings against our own chrome.
 * Checking `closest` rather than the element's own class matters — the label
 * is a child of the box, not the box itself.
 */
export function isEditorChrome(node) {
    return Boolean(node && node.closest && node.closest('.uie-box'));
}

/** A short, readable label for an element — "section.hero" or "h1#title". */
export function describe(node) {
    if (!node || !node.tagName) return '';
    const tag = node.tagName.toLowerCase();
    if (node.id) return `${tag}#${node.id}`;

    const classes = String(node.getAttribute('class') || '')
        .split(/\s+/)
        .filter((name) => name && !name.startsWith('editor-'))
        .slice(0, 2);

    return classes.length ? `${tag}.${classes.join('.')}` : tag;
}

/**
 * Best-effort CSS selector for handing to a developer.
 *
 * Prefers an id, then a class chain, and only falls back to :nth-child when
 * there is nothing else to go on.
 */
export function cssPath(node, root) {
    const parts = [];
    let current = node;

    while (current && current !== root && current.nodeType === 1 && parts.length < 6) {
        if (current.id) {
            parts.unshift(`#${CSS.escape(current.id)}`);
            break;
        }

        const tag = current.tagName.toLowerCase();
        const classes = String(current.getAttribute('class') || '')
            .split(/\s+/)
            .filter((name) => name && !name.startsWith('editor-'))
            .slice(0, 2)
            .map((name) => `.${CSS.escape(name)}`)
            .join('');

        if (classes) {
            parts.unshift(tag + classes);
        } else {
            const siblings = current.parentElement
                ? Array.from(current.parentElement.children).filter((sibling) => sibling.tagName === current.tagName)
                : [];
            const index = siblings.indexOf(current) + 1;
            parts.unshift(siblings.length > 1 ? `${tag}:nth-of-type(${index})` : tag);
        }

        current = current.parentElement;
    }

    return parts.join(' > ') || (node.tagName || '').toLowerCase();
}

export function download(filename, content, type = 'text/plain') {
    const blob = new Blob([content], { type });
    const url = URL.createObjectURL(blob);
    const link = el('a', { href: url, download: filename });
    document.body.append(link);
    link.click();
    link.remove();
    // Revoking immediately can race the download in some browsers.
    setTimeout(() => URL.revokeObjectURL(url), 4000);
}

export async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        // Clipboard API needs a secure context; fall back for plain http.
        const area = el('textarea', { style: 'position:fixed;opacity:0;top:0' });
        area.value = text;
        document.body.append(area);
        area.select();
        const ok = document.execCommand('copy');
        area.remove();
        return ok;
    }
}
