import { el, clear } from './util.js';
import { state, updateInspectorPatch, pushTextPatch } from './store.js';
import { parseColor, toHex } from './color.js';

/**
 * Direct manipulation for the boring 80%.
 *
 * "Make this 4px bigger" is a terrible use of a language model: it costs money,
 * takes seconds, and can come back with something you did not ask for. A
 * slider does it instantly, for free, offline. The AI is then free to be what
 * it is genuinely good at — "make this feel more premium" — and the two share
 * the same undo stack, so mixing them is seamless.
 */

const WEIGHTS = ['300', '400', '500', '600', '700', '800'];
const ALIGNS = ['left', 'center', 'right', 'justify'];

let host = null;
let onTextEdit = () => {};

export function mountInspector(node, callbacks = {}) {
    host = node;
    onTextEdit = callbacks.onTextEdit || (() => {});
}

export function renderInspector() {
    if (!host) return;
    clear(host);

    const node = state.selected;

    if (!node) {
        host.append(el('p', { class: 'empty', text: 'Select an element in the preview to style it.' }));
        return;
    }

    const style = state.view.getComputedStyle(node);
    const uie = node.dataset.uie;

    const set = (property, value, label) => updateInspectorPatch(uie, property, value, label);

    host.append(
        group('Text', [
            colorRow('Colour', style.color, (value) => set('color', value, 'Text colour')),
            numberRow('Size', parseFloat(style.fontSize), 8, 120, 1, 'px', (value) =>
                set('font-size', `${value}px`, 'Font size')
            ),
            selectRow('Weight', WEIGHTS, nearestWeight(style.fontWeight), (value) =>
                set('font-weight', value, 'Font weight')
            ),
            numberRow('Line height', lineHeight(style), 0.8, 3, 0.05, '', (value) =>
                set('line-height', String(value), 'Line height')
            ),
            numberRow('Tracking', parseFloat(style.letterSpacing) || 0, -3, 12, 0.1, 'px', (value) =>
                set('letter-spacing', `${value}px`, 'Letter spacing')
            ),
            selectRow('Align', ALIGNS, style.textAlign, (value) => set('text-align', value, 'Text align')),
        ]),

        group('Box', [
            colorRow('Background', style.backgroundColor, (value) =>
                set('background-color', value, 'Background colour')
            ),
            numberRow('Padding', parseFloat(style.paddingTop) || 0, 0, 160, 1, 'px', (value) =>
                set('padding', `${value}px`, 'Padding')
            ),
            numberRow('Margin', parseFloat(style.marginTop) || 0, 0, 160, 1, 'px', (value) =>
                set('margin', `${value}px`, 'Margin')
            ),
            numberRow('Radius', parseFloat(style.borderRadius) || 0, 0, 80, 1, 'px', (value) =>
                set('border-radius', `${value}px`, 'Border radius')
            ),
        ]),

        group('Content', [
            el('button', {
                class: 'btn btn-sm',
                type: 'button',
                text: 'Edit text in place',
                onClick: () => beginTextEdit(node),
            }),
            el('p', {
                class: 'hint',
                text: 'Click to type directly in the preview. Changes land in the same undo history.',
            }),
        ])
    );
}

/**
 * contenteditable for copy changes. The before/after text is captured so this
 * is one ordinary entry in the same history as everything else.
 */
function beginTextEdit(node) {
    const before = node.textContent;

    node.setAttribute('contenteditable', 'plaintext-only');
    node.focus();

    const finish = () => {
        node.removeAttribute('contenteditable');
        node.removeEventListener('blur', finish);

        const after = node.textContent;
        if (after !== before) {
            pushTextPatch({ uie: node.dataset.uie, before, after, label: 'Edited text' });
        }

        onTextEdit();
    };

    node.addEventListener('blur', finish);
}

// -------------------------------------------------------------- form pieces

function group(title, children) {
    return el('div', { class: 'group' }, [el('h3', { text: title }), ...children]);
}

function row(label, control) {
    return el('div', { class: 'row' }, [el('label', { text: label }), control]);
}

function colorRow(label, current, onChange) {
    const parsed = parseColor(current);
    const input = el('input', {
        type: 'color',
        class: 'field',
        value: parsed ? toHex(parsed) : '#000000',
    });

    input.addEventListener('input', () => onChange(input.value));

    return row(label, input);
}

function numberRow(label, current, min, max, step, unit, onChange) {
    const value = Number.isFinite(current) ? current : min;

    const slider = el('input', { type: 'range', class: 'field', min, max, step, value });
    const output = el('output', { text: `${round(value)}${unit}` });

    slider.addEventListener('input', () => {
        const next = Number(slider.value);
        output.textContent = `${round(next)}${unit}`;
        onChange(next);
    });

    return row(label, el('div', { style: 'display:flex;align-items:center;gap:8px' }, [
        slider,
        el('span', { style: 'min-width:52px;text-align:right;font-size:11px;color:var(--ink-faint)' }, [output]),
    ]));
}

function selectRow(label, options, current, onChange) {
    const select = el('select', { class: 'field' });

    for (const option of options) {
        select.append(el('option', { value: option, text: option, selected: option === current }));
    }

    select.addEventListener('change', () => onChange(select.value));

    return row(label, select);
}

function nearestWeight(weight) {
    const value = parseInt(weight, 10) || 400;
    return WEIGHTS.reduce((best, candidate) =>
        Math.abs(Number(candidate) - value) < Math.abs(Number(best) - value) ? candidate : best
    );
}

/** getComputedStyle reports line-height in px; the control edits the ratio. */
function lineHeight(style) {
    const raw = parseFloat(style.lineHeight);
    const size = parseFloat(style.fontSize) || 16;
    if (!Number.isFinite(raw)) return 1.5;
    return Math.round((raw / size) * 100) / 100;
}

function round(value) {
    return Math.round(value * 100) / 100;
}
