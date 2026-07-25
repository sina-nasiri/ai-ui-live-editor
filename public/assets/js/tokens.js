import { parseColor, toHex } from './color.js';
import { isEditorChrome } from './util.js';

/**
 * Reads a page's de-facto design system back out of its rendered output.
 *
 * Useful twice over: as a panel (what palette and type scale is this
 * competitor actually shipping?) and as context for the AI, so a generated
 * edit lands inside the site's existing language instead of next to it.
 *
 * Frequency-weighted on purpose — the colour used on 200 elements is the
 * brand colour; the one used once is an accident.
 */

const SAMPLE_LIMIT = 1500;

export function extractTokens(doc, view) {
    const tokens = {
        variables: {},
        colors: [],
        backgrounds: [],
        fontFamilies: [],
        fontSizes: [],
        fontWeights: [],
        radii: [],
        spacing: [],
        shadows: [],
    };

    if (!doc || !doc.body) return tokens;

    tokens.variables = readCustomProperties(doc);

    const counters = {
        colors: new Map(),
        backgrounds: new Map(),
        fontFamilies: new Map(),
        fontSizes: new Map(),
        fontWeights: new Map(),
        radii: new Map(),
        spacing: new Map(),
        shadows: new Map(),
    };

    const nodes = Array.from(doc.body.querySelectorAll('*')).slice(0, SAMPLE_LIMIT);

    for (const node of nodes) {
        if (isEditorChrome(node)) continue;

        const style = view.getComputedStyle(node);
        const hasText = hasOwnText(node);

        if (hasText) {
            const color = parseColor(style.color);
            if (color && color.a > 0.2) bump(counters.colors, toHex(color));
            bump(counters.fontFamilies, primaryFamily(style.fontFamily));
            bump(counters.fontSizes, style.fontSize);
            bump(counters.fontWeights, style.fontWeight);
        }

        const background = parseColor(style.backgroundColor);
        if (background && background.a > 0.05) bump(counters.backgrounds, toHex(background));

        if (style.borderRadius && style.borderRadius !== '0px') bump(counters.radii, style.borderRadius);
        if (style.boxShadow && style.boxShadow !== 'none') bump(counters.shadows, style.boxShadow);

        for (const value of [style.paddingTop, style.paddingLeft, style.marginBottom]) {
            const px = parseFloat(value);
            if (px > 0) bump(counters.spacing, `${Math.round(px)}px`);
        }
    }

    tokens.colors = top(counters.colors, 10);
    tokens.backgrounds = top(counters.backgrounds, 8);
    tokens.fontFamilies = top(counters.fontFamilies, 4);
    tokens.fontWeights = top(counters.fontWeights, 5);
    tokens.radii = top(counters.radii, 5);
    tokens.shadows = top(counters.shadows, 3);

    // Type and spacing read best as an ordered scale, not a popularity chart.
    tokens.fontSizes = top(counters.fontSizes, 10).sort((a, b) => parseFloat(a.value) - parseFloat(b.value));
    tokens.spacing = top(counters.spacing, 8).sort((a, b) => parseFloat(a.value) - parseFloat(b.value));

    return tokens;
}

function hasOwnText(node) {
    for (const child of node.childNodes) {
        if (child.nodeType === 3 && child.textContent.trim().length > 1) return true;
    }
    return false;
}

function primaryFamily(fontFamily) {
    return String(fontFamily || '').split(',')[0].trim().replace(/^["']|["']$/g, '');
}

function bump(map, key) {
    if (!key) return;
    map.set(key, (map.get(key) || 0) + 1);
}

function top(map, limit) {
    return Array.from(map, ([value, count]) => ({ value, count }))
        .sort((a, b) => b.count - a.count)
        .slice(0, limit);
}

/**
 * Custom properties are the closest thing to a declared design system, so
 * they are read from the stylesheets directly rather than guessed at.
 */
function readCustomProperties(doc) {
    const variables = {};

    for (const sheet of Array.from(doc.styleSheets)) {
        let rules;
        try {
            rules = sheet.cssRules;
        } catch {
            // A cross-origin stylesheet: readable by the browser, not by us.
            continue;
        }

        for (const rule of Array.from(rules || [])) {
            if (!rule.style) continue;
            for (let i = 0; i < rule.style.length; i += 1) {
                const property = rule.style[i];
                if (!property.startsWith('--')) continue;
                const value = rule.style.getPropertyValue(property).trim();
                if (value && !variables[property] && Object.keys(variables).length < 40) {
                    variables[property] = value;
                }
            }
        }
    }

    return variables;
}

/** Compact plain-text form for the AI prompt. */
export function tokensAsText(tokens) {
    const lines = [];
    const list = (items) => items.map((item) => item.value).join(', ');

    if (tokens.fontFamilies.length) lines.push(`Fonts: ${list(tokens.fontFamilies)}`);
    if (tokens.fontSizes.length) lines.push(`Type scale: ${list(tokens.fontSizes)}`);
    if (tokens.fontWeights.length) lines.push(`Weights: ${list(tokens.fontWeights)}`);
    if (tokens.colors.length) lines.push(`Text colours: ${list(tokens.colors)}`);
    if (tokens.backgrounds.length) lines.push(`Backgrounds: ${list(tokens.backgrounds)}`);
    if (tokens.spacing.length) lines.push(`Spacing scale: ${list(tokens.spacing)}`);
    if (tokens.radii.length) lines.push(`Radii: ${list(tokens.radii)}`);

    const variables = Object.entries(tokens.variables).slice(0, 20);
    if (variables.length) {
        lines.push('CSS variables:');
        for (const [name, value] of variables) lines.push(`  ${name}: ${value}`);
    }

    return lines.join('\n');
}

/** Export shape a developer can paste straight into a stylesheet. */
export function tokensAsCss(tokens) {
    const lines = [':root {'];

    tokens.colors.forEach((item, index) => lines.push(`  --color-${index + 1}: ${item.value};`));
    tokens.backgrounds.forEach((item, index) => lines.push(`  --surface-${index + 1}: ${item.value};`));
    tokens.fontSizes.forEach((item, index) => lines.push(`  --text-${index + 1}: ${item.value};`));
    tokens.spacing.forEach((item, index) => lines.push(`  --space-${index + 1}: ${item.value};`));
    tokens.radii.forEach((item, index) => lines.push(`  --radius-${index + 1}: ${item.value};`));

    if (tokens.fontFamilies[0]) lines.push(`  --font-body: ${tokens.fontFamilies[0].value};`);

    lines.push('}');
    return lines.join('\n');
}
