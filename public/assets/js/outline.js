import { isEditorChrome } from './util.js';

/**
 * Builds the description of a selection that gets sent to the model.
 *
 * This replaces shipping raw HTML. A hero section can easily be 15,000 tokens
 * of markup; the same section as an outline is a few hundred. The model gets
 * structure, text, and the styles that actually matter, and cannot respond by
 * paraphrasing away half the copy — because it never receives the copy as
 * something to rewrite.
 */

const MAX_NODES = 120;
const MAX_DEPTH = 5;
const MAX_TEXT = 120;

/** Styles worth spending tokens on — the ones a design change usually touches. */
const REPORTED = [
    'display',
    'font-family',
    'font-size',
    'font-weight',
    'line-height',
    'color',
    'background-color',
    'padding',
    'margin',
    'border-radius',
    'text-align',
];

export function buildOutline(root, view) {
    const lines = [];
    let count = 0;

    const walk = (node, depth) => {
        if (count >= MAX_NODES || depth > MAX_DEPTH || node.nodeType !== 1) return;
        if (isEditorChrome(node)) return;

        const tag = node.tagName.toLowerCase();
        if (['script', 'style', 'link', 'meta', 'noscript'].includes(tag)) return;

        count += 1;
        lines.push(`${'  '.repeat(depth)}${format(node, view, depth)}`);

        for (const child of node.children) walk(child, depth + 1);
    };

    walk(root, 0);

    if (count >= MAX_NODES) {
        lines.push(`\n(outline truncated at ${MAX_NODES} elements — select a smaller region for finer control)`);
    }

    return lines.join('\n');
}

function format(node, view, depth) {
    const id = node.dataset.uie || '?';
    const tag = node.tagName.toLowerCase();

    const classes = String(node.getAttribute('class') || '')
        .split(/\s+/)
        .filter((name) => name && !name.startsWith('uie-'))
        .slice(0, 3)
        .join(' ');

    let line = `[${id}] <${tag}${classes ? ` class="${classes}"` : ''}>`;

    const text = ownText(node);
    if (text) line += ` "${text}"`;

    // Reporting computed styles for every node is mostly noise. The top two
    // levels set the context the model needs; deeper nodes only report what
    // is unusual about them.
    const style = view.getComputedStyle(node);
    const properties = depth <= 1 ? REPORTED : ['font-size', 'font-weight', 'color', 'background-color'];
    const parts = [];

    for (const property of properties) {
        const value = style.getPropertyValue(property);
        if (!value || value === 'none' || value === 'normal' || value === '0px') continue;
        if (property === 'background-color' && value === 'rgba(0, 0, 0, 0)') continue;
        parts.push(`${property}: ${shorten(value)}`);
    }

    if (parts.length) line += `  (${parts.join('; ')})`;

    return line;
}

function ownText(node) {
    let text = '';
    for (const child of node.childNodes) {
        if (child.nodeType === 3) text += child.textContent;
    }

    text = text.replace(/\s+/g, ' ').trim();
    if (!text) return '';

    return text.length > MAX_TEXT ? `${text.slice(0, MAX_TEXT)}…` : text;
}

function shorten(value) {
    const trimmed = String(value).trim();
    return trimmed.length > 60 ? `${trimmed.slice(0, 60)}…` : trimmed;
}
