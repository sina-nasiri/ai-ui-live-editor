// Colour maths for the contrast checker and the token extractor.
// Implements the WCAG 2.x relative-luminance and contrast-ratio definitions.

/**
 * Parse anything getComputedStyle hands back into {r,g,b,a} (0–255, 0–1).
 * Returns null for `transparent`, `none`, gradients, and keywords we cannot
 * resolve without the browser — callers treat null as "keep looking".
 */
export function parseColor(input) {
    if (!input) return null;
    const value = String(input).trim().toLowerCase();

    if (value === 'transparent' || value === 'none' || value === '') return null;

    let match = value.match(/^rgba?\(([^)]+)\)$/);
    if (match) {
        const parts = match[1].split(/[\s,/]+/).filter(Boolean);
        const r = parseFloat(parts[0]);
        const g = parseFloat(parts[1]);
        const b = parseFloat(parts[2]);
        const a = parts[3] === undefined ? 1 : parseAlpha(parts[3]);
        if ([r, g, b].some(Number.isNaN)) return null;
        return { r, g, b, a };
    }

    match = value.match(/^#([0-9a-f]{3,8})$/);
    if (match) {
        let hex = match[1];
        if (hex.length === 3 || hex.length === 4) hex = hex.split('').map((c) => c + c).join('');
        if (hex.length !== 6 && hex.length !== 8) return null;
        return {
            r: parseInt(hex.slice(0, 2), 16),
            g: parseInt(hex.slice(2, 4), 16),
            b: parseInt(hex.slice(4, 6), 16),
            a: hex.length === 8 ? parseInt(hex.slice(6, 8), 16) / 255 : 1,
        };
    }

    return null;
}

function parseAlpha(token) {
    if (token.endsWith('%')) return parseFloat(token) / 100;
    const value = parseFloat(token);
    return Number.isNaN(value) ? 1 : value;
}

export function toHex({ r, g, b }) {
    const channel = (value) => Math.round(Math.min(255, Math.max(0, value))).toString(16).padStart(2, '0');
    return `#${channel(r)}${channel(g)}${channel(b)}`;
}

/** Composite a translucent colour over an opaque backdrop. */
export function flatten(front, back) {
    if (!front) return back;
    if (front.a >= 1) return { ...front, a: 1 };
    const a = front.a;
    return {
        r: front.r * a + back.r * (1 - a),
        g: front.g * a + back.g * (1 - a),
        b: front.b * a + back.b * (1 - a),
        a: 1,
    };
}

export function luminance({ r, g, b }) {
    const channel = (value) => {
        const v = value / 255;
        return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

export function contrastRatio(a, b) {
    const light = Math.max(luminance(a), luminance(b));
    const dark = Math.min(luminance(a), luminance(b));
    return (light + 0.05) / (dark + 0.05);
}

/**
 * WCAG treats large text more leniently: 18.66px bold or 24px counts as large,
 * needing 3:1 instead of 4.5:1.
 */
export function requiredRatio(fontSizePx, fontWeight) {
    const weight = parseInt(fontWeight, 10) || 400;
    const large = fontSizePx >= 24 || (fontSizePx >= 18.66 && weight >= 700);
    return large ? 3 : 4.5;
}

/**
 * Walk up the tree for the first ancestor with an opaque-enough background.
 * Returns null when a background image makes the answer unknowable — better
 * to skip the check than to report a confident wrong number.
 */
export function effectiveBackground(node, view) {
    let current = node;
    let accumulated = null;

    while (current && current.nodeType === 1) {
        const style = view.getComputedStyle(current);

        if (style.backgroundImage && style.backgroundImage !== 'none') return null;

        const color = parseColor(style.backgroundColor);
        if (color && color.a > 0) {
            accumulated = accumulated ? flatten(accumulated, color) : color;
            if (accumulated.a >= 1 || color.a >= 1) {
                return flatten(accumulated, { r: 255, g: 255, b: 255, a: 1 });
            }
        }

        current = current.parentElement;
    }

    // Nothing opaque all the way up: the canvas underneath is white.
    return accumulated
        ? flatten(accumulated, { r: 255, g: 255, b: 255, a: 1 })
        : { r: 255, g: 255, b: 255, a: 1 };
}
