import { download } from './util.js';

/**
 * Renders an element to a PNG, in the browser, with no library.
 *
 * The previous implementation of this failed silently and is worth explaining,
 * because the fix is not obvious. It reached for `html2canvas` — which was
 * never loaded on the page — and fell through to an SVG `foreignObject`
 * containing the element's raw markup. Two things then went wrong: the markup
 * carried no styles (a `foreignObject` cannot see the page's stylesheets), and
 * any cross-origin image inside it *tainted* the canvas, so `toBlob()` threw a
 * SecurityError that nothing caught. The user got a spinner and no file.
 *
 * This version fixes both causes rather than the symptom:
 *
 *   1. Every computed style is copied inline onto a clone, so the fragment is
 *      self-describing and needs no stylesheet.
 *   2. Every image, background image and web font is fetched through this
 *      app's own asset relay and embedded as a data URI, so nothing
 *      cross-origin ever reaches the canvas and it cannot be tainted.
 *
 * Fonts are best-effort: `@font-face` sources are only discoverable in
 * stylesheets we can read, which is why the snapshot routes them through the
 * relay. Where a font cannot be resolved the capture falls back to a system
 * face — reported to the caller rather than hidden.
 */

const ASSET_ENDPOINT = () => `${(window.__EDITOR__ && window.__EDITOR__.base) || '/'}asset?u=`;

/** Bounded so a pathological page cannot hang the browser. */
const MAX_EMBEDDED_ASSETS = 60;
const FETCH_TIMEOUT_MS = 8000;

/** Properties never worth copying — they bloat the output or break layout. */
const SKIP_PROPERTIES = new Set([
    'animation', 'animation-name', 'transition', 'transition-property',
    'cursor', 'pointer-events', 'user-select', '-webkit-user-select',
    'scroll-behavior', 'will-change',
]);

/**
 * @returns {Promise<{blob: Blob, width: number, height: number, missingFonts: boolean}>}
 */
export async function captureElement(node, view, { scale = null } = {}) {
    if (!node || !view) throw new Error('Nothing to capture.');

    const rect = node.getBoundingClientRect();
    if (rect.width < 1 || rect.height < 1) throw new Error('That element has no visible size.');

    const cache = new Map();
    let embedded = 0;

    const clone = node.cloneNode(true);
    stripEditorArtefacts(clone);

    // Walk clone and original in lockstep: getComputedStyle only works on
    // elements that are in a document, and the clone is not.
    const originals = [node, ...node.querySelectorAll('*')];
    const clones = [clone, ...clone.querySelectorAll('*')];

    for (let i = 0; i < originals.length && i < 4000; i += 1) {
        if (!clones[i]) break;
        inlineComputedStyle(originals[i], clones[i], view);
    }

    // Images and background images, embedded through the relay.
    const jobs = [];

    for (let i = 0; i < clones.length; i += 1) {
        const target = clones[i];
        if (!target || target.nodeType !== 1) continue;

        if (target.tagName === 'IMG' && embedded < MAX_EMBEDDED_ASSETS) {
            embedded += 1;
            jobs.push(embedImage(target, cache));
        }

        const background = target.style.backgroundImage;
        if (background && background.includes('url(') && embedded < MAX_EMBEDDED_ASSETS) {
            embedded += 1;
            jobs.push(embedBackground(target, cache));
        }
    }

    const fontCss = await collectFontFaces(node.ownerDocument, cache);
    await Promise.all(jobs);

    const width = Math.ceil(rect.width);
    const height = Math.ceil(rect.height);

    // The clone is positioned at the origin of its own canvas.
    clone.style.margin = '0';
    clone.style.position = 'static';
    clone.style.top = 'auto';
    clone.style.left = 'auto';
    clone.style.transform = 'none';
    clone.style.width = `${width}px`;
    clone.style.height = `${height}px`;

    const serialised = new XMLSerializer().serializeToString(clone);

    const svg =
        `<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}">` +
        (fontCss.css ? `<defs><style type="text/css">${escapeForXml(fontCss.css)}</style></defs>` : '') +
        `<foreignObject x="0" y="0" width="100%" height="100%">` +
        `<div xmlns="http://www.w3.org/1999/xhtml" style="width:${width}px;height:${height}px">${serialised}</div>` +
        `</foreignObject></svg>`;

    const ratio = scale || Math.min(view.devicePixelRatio || 1, 2);
    const blob = await rasterise(svg, width, height, ratio);

    return { blob, width, height, missingFonts: fontCss.missing };
}

export async function downloadCapture(node, view, filename) {
    const { blob, missingFonts } = await captureElement(node, view);
    download(filename, blob, 'image/png');
    return { missingFonts };
}

export async function copyCaptureToClipboard(node, view) {
    const { blob, missingFonts } = await captureElement(node, view);

    if (!navigator.clipboard || typeof ClipboardItem === 'undefined') {
        throw new Error('This browser cannot copy images to the clipboard. Use Download instead.');
    }

    await navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]);
    return { missingFonts };
}

// ----------------------------------------------------------------- internals

function stripEditorArtefacts(root) {
    for (const box of root.querySelectorAll?.('.uie-box') || []) box.remove();

    for (const el of [root, ...(root.querySelectorAll?.('*') || [])]) {
        el.removeAttribute?.('data-uie');
        el.removeAttribute?.('contenteditable');
        el.removeAttribute?.('data-editor-href');
    }
}

/**
 * Copy the computed style onto the clone, skipping anything that matches the
 * browser default. Copying all ~340 properties verbatim would produce
 * megabytes of markup for a modest section.
 */
let defaultStyleCache = null;

function inlineComputedStyle(original, clone, view) {
    const computed = view.getComputedStyle(original);
    const defaults = defaultStyles(original, view);

    let css = '';

    for (let i = 0; i < computed.length; i += 1) {
        const property = computed[i];
        if (SKIP_PROPERTIES.has(property)) continue;

        const value = computed.getPropertyValue(property);
        if (!value || value === defaults[property]) continue;

        css += `${property}:${value};`;
    }

    clone.setAttribute('style', css);
}

function defaultStyles(original, view) {
    const tag = original.tagName;

    if (!defaultStyleCache) defaultStyleCache = new Map();
    if (defaultStyleCache.has(tag)) return defaultStyleCache.get(tag);

    const doc = original.ownerDocument;
    const probe = doc.createElement(tag);
    // Detached elements report no styles, so the probe has to be in the tree —
    // hidden well out of the way so it cannot affect layout or be seen.
    probe.style.cssText = 'position:absolute;left:-99999px;top:0;visibility:hidden';
    doc.body.append(probe);

    const computed = view.getComputedStyle(probe);
    const map = {};
    for (let i = 0; i < computed.length; i += 1) {
        map[computed[i]] = computed.getPropertyValue(computed[i]);
    }

    probe.remove();
    defaultStyleCache.set(tag, map);

    return map;
}

async function embedImage(img, cache) {
    const source = img.getAttribute('src');
    if (!source || source.startsWith('data:')) return;

    const dataUri = await toDataUri(source, cache);
    if (dataUri) {
        img.setAttribute('src', dataUri);
    } else {
        // A broken image icon in the capture is worse than a gap.
        img.removeAttribute('src');
        img.style.background = 'rgba(0,0,0,.05)';
    }

    img.removeAttribute('srcset');
    img.removeAttribute('loading');
}

async function embedBackground(el, cache) {
    const value = el.style.backgroundImage;
    const match = value.match(/url\(["']?([^"')]+)["']?\)/);
    if (!match || match[1].startsWith('data:')) return;

    const dataUri = await toDataUri(match[1], cache);
    el.style.backgroundImage = dataUri ? `url("${dataUri}")` : 'none';
}

/**
 * Read @font-face rules and inline their sources.
 *
 * Only works for stylesheets we can actually read — which is why the server
 * routes them through the asset relay. A cross-origin sheet throws on
 * `.cssRules` and its fonts are simply unavailable.
 */
async function collectFontFaces(doc, cache) {
    const rules = [];
    let missing = false;

    for (const sheet of Array.from(doc.styleSheets || [])) {
        let cssRules;
        try {
            cssRules = sheet.cssRules;
        } catch {
            missing = true;
            continue;
        }

        for (const rule of Array.from(cssRules || [])) {
            if (rule.type !== 5 /* CSSFontFaceRule */) continue;
            if (rules.length >= 8) break;
            rules.push(rule);
        }
    }

    const parts = [];

    for (const rule of rules) {
        const src = rule.style.getPropertyValue('src');
        const match = src && src.match(/url\(["']?([^"')]+)["']?\)/);
        if (!match) continue;

        const dataUri = await toDataUri(match[1], cache);
        if (!dataUri) {
            missing = true;
            continue;
        }

        parts.push(
            `@font-face{font-family:${rule.style.getPropertyValue('font-family')};` +
            `font-weight:${rule.style.getPropertyValue('font-weight') || 'normal'};` +
            `font-style:${rule.style.getPropertyValue('font-style') || 'normal'};` +
            `src:url(${dataUri});}`
        );
    }

    return { css: parts.join('\n'), missing };
}

/**
 * Fetch through this app's relay so the bytes are same-origin, then inline
 * them. This is the step that stops the canvas being tainted.
 */
async function toDataUri(url, cache) {
    if (cache.has(url)) return cache.get(url);

    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);

    try {
        const response = await fetch(ASSET_ENDPOINT() + encodeURIComponent(url), { signal: controller.signal });
        if (!response.ok) throw new Error(String(response.status));

        const blob = await response.blob();
        const dataUri = await blobToDataUri(blob);
        cache.set(url, dataUri);

        return dataUri;
    } catch {
        cache.set(url, null);
        return null;
    } finally {
        clearTimeout(timer);
    }
}

function blobToDataUri(blob) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = () => reject(new Error('Could not read asset.'));
        reader.readAsDataURL(blob);
    });
}

function escapeForXml(text) {
    return String(text).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

function rasterise(svg, width, height, ratio) {
    return new Promise((resolve, reject) => {
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(width * ratio);
        canvas.height = Math.round(height * ratio);

        const context = canvas.getContext('2d');
        context.scale(ratio, ratio);
        // foreignObject renders with a transparent backdrop; most people
        // expect a screenshot to have the page's white behind it.
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, width, height);

        const image = new Image();
        // A data: URL avoids one more same-origin question entirely.
        const url = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`;

        image.onload = () => {
            try {
                context.drawImage(image, 0, 0, width, height);
                canvas.toBlob((blob) => {
                    if (blob) resolve(blob);
                    else reject(new Error('The capture could not be encoded.'));
                }, 'image/png');
            } catch (error) {
                // Historically this is where the old implementation died,
                // without anything catching it.
                reject(new Error('The capture was blocked by the browser: '.concat(error.message)));
            }
        };

        image.onerror = () => reject(new Error('The element could not be rendered to an image.'));
        image.src = url;
    });
}
