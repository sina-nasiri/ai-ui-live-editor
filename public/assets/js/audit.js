import { parseColor, flatten, contrastRatio, requiredRatio, effectiveBackground, toHex } from './color.js';
import { isEditorChrome } from './util.js';

/**
 * A WCAG-oriented audit that runs entirely in the browser.
 *
 * No API key, no request, no cost — which is the point. It works on any page
 * the editor can load, and the output is a report a UX reviewer can paste
 * straight into a findings doc. Every check below is one a human reviewer
 * would otherwise do by hand with a colour picker and a ruler.
 */

const VAGUE_LINK_TEXT = new Set([
    'click here', 'here', 'read more', 'more', 'learn more', 'link',
    'this', 'this link', 'details', 'continue', 'go',
]);

const INTERACTIVE = 'a[href], a[data-editor-href], button, input, select, textarea, [role="button"], [role="link"], [tabindex]';

export function runAudit(doc, view) {
    if (!doc || !doc.body) return [];

    const findings = [
        ...checkStylesheetsLoaded(doc),
        ...checkLanguage(doc),
        ...checkContrast(doc, view),
        ...checkImages(doc),
        ...checkHeadings(doc),
        ...checkLinks(doc),
        ...checkFormLabels(doc),
        ...checkTapTargets(doc, view),
        ...checkTabIndex(doc),
    ];

    const rank = { high: 0, medium: 1, low: 2 };
    return findings.sort((a, b) => rank[a.severity] - rank[b.severity]);
}

// ------------------------------------------------------------------- checks

/**
 * Say so when the page is being audited without its own CSS.
 *
 * Some CDNs refuse the relay's request, and the page then renders with only
 * whatever was inline. Every colour the audit measures after that is the
 * browser default rather than the design, so the contrast findings below are
 * about a page nobody will ever see. Reporting them without this warning is
 * how a reviewer ends up filing bugs against a stylesheet that simply did not
 * arrive.
 */
function checkStylesheetsLoaded(doc) {
    const links = Array.from(doc.querySelectorAll('link[rel~="stylesheet"]'));
    if (!links.length) return [];

    // A sheet that failed to load leaves its <link> with no entry here.
    const loaded = new Set(Array.from(doc.styleSheets, (sheet) => sheet.ownerNode));
    const missing = links.filter((link) => !loaded.has(link));
    if (!missing.length) return [];

    return [
        finding(
            'high',
            `${missing.length} of ${links.length} stylesheets did not load`,
            doc.body,
            'The page is rendering without its own CSS, so the colour, size and '
            + 'spacing findings below describe browser defaults rather than the '
            + 'real design. Treat them as unreliable until the stylesheet loads.'
        ),
    ];
}

function checkLanguage(doc) {
    const lang = doc.documentElement.getAttribute('lang');
    if (lang && lang.trim()) return [];

    return [
        finding('low', 'Page has no lang attribute', doc.body,
            'Screen readers use <html lang="…"> to pick a pronunciation. Without it they guess.'),
    ];
}

function checkContrast(doc, view) {
    const findings = [];
    const seen = new Set();

    for (const node of doc.body.querySelectorAll('*')) {
        if (findings.length >= 25) break;
        if (isEditorChrome(node)) continue;
        if (!hasVisibleText(node)) continue;

        const style = view.getComputedStyle(node);
        if (style.visibility === 'hidden' || style.display === 'none' || parseFloat(style.opacity) < 0.1) continue;

        const foreground = parseColor(style.color);
        const background = effectiveBackground(node, view);

        // A background image means we cannot know the real backdrop; a
        // confident wrong ratio is worse than no finding.
        if (!foreground || !background) continue;

        // Fully transparent text is not a contrast failure. Tailwind's
        // `text-transparent` is how gradient text (`bg-clip-text`) and
        // stroked headings are built, and flattening alpha 0 onto the
        // backdrop yields "#ffffff on #ffffff, 1.00:1" — a high-severity
        // finding about text that has no colour to begin with.
        if (foreground.a === 0) continue;

        const flat = flatten(foreground, background);
        const size = parseFloat(style.fontSize) || 16;
        const needed = requiredRatio(size, style.fontWeight);
        const ratio = contrastRatio(flat, background);

        if (ratio >= needed) continue;

        const key = `${toHex(flat)}|${toHex(background)}|${Math.round(size)}`;
        if (seen.has(key)) continue;
        seen.add(key);

        findings.push(
            finding(
                ratio < needed - 1.5 ? 'high' : 'medium',
                `Contrast ${ratio.toFixed(2)}:1 — needs ${needed}:1`,
                node,
                `${toHex(flat)} text on ${toHex(background)} at ${Math.round(size)}px. `
                + `Darken the text or lighten the background until it reaches ${needed}:1.`
            )
        );
    }

    return findings;
}

function checkImages(doc) {
    const findings = [];

    for (const image of doc.querySelectorAll('img')) {
        if (image.hasAttribute('alt')) continue;
        findings.push(
            finding('high', 'Image has no alt attribute', image,
                'Add alt text describing what the image conveys, or alt="" if it is purely decorative.')
        );
        if (findings.length >= 15) break;
    }

    return findings;
}

function checkHeadings(doc) {
    const findings = [];
    const headings = Array.from(doc.querySelectorAll('h1, h2, h3, h4, h5, h6'));

    const h1s = headings.filter((node) => node.tagName === 'H1');
    if (h1s.length === 0 && headings.length > 0) {
        findings.push(finding('medium', 'No <h1> on the page', headings[0],
            'The top-level heading tells assistive tech and search engines what this page is.'));
    } else if (h1s.length > 1) {
        findings.push(finding('low', `${h1s.length} <h1> elements`, h1s[1],
            'Multiple h1s flatten the document outline. Usually only the page title should be h1.'));
    }

    let previous = 0;
    for (const heading of headings) {
        const level = Number(heading.tagName[1]);
        if (previous && level > previous + 1) {
            findings.push(
                finding('medium', `Heading jumps from h${previous} to h${level}`, heading,
                    'Skipping a level breaks the outline someone navigating by headings relies on.')
            );
        }
        previous = level;
        if (findings.length >= 10) break;
    }

    return findings;
}

function checkLinks(doc) {
    const findings = [];

    for (const link of doc.querySelectorAll('a')) {
        const text = (link.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();

        if (!text) {
            const labelled = link.getAttribute('aria-label') || link.querySelector('img[alt]:not([alt=""])');
            if (!labelled) {
                findings.push(finding('high', 'Link has no accessible name', link,
                    'Add visible text, an aria-label, or alt text on the image inside it.'));
            }
        } else if (VAGUE_LINK_TEXT.has(text)) {
            findings.push(
                finding('low', `Link text is just "${text}"`, link,
                    'People navigating by link list see this with no surrounding context. Say where it goes.')
            );
        }

        if (findings.length >= 12) break;
    }

    return findings;
}

function checkFormLabels(doc) {
    const findings = [];

    for (const field of doc.querySelectorAll('input, select, textarea')) {
        const type = (field.getAttribute('type') || '').toLowerCase();
        if (['hidden', 'submit', 'button', 'reset', 'image'].includes(type)) continue;

        const labelled =
            field.getAttribute('aria-label') ||
            field.getAttribute('aria-labelledby') ||
            field.closest('label') ||
            (field.id && doc.querySelector(`label[for="${CSS.escape(field.id)}"]`));

        if (labelled) continue;

        findings.push(
            finding('high', `${field.tagName.toLowerCase()} has no label`, field,
                'A placeholder is not a label — it disappears on focus and is often skipped by screen readers.')
        );

        if (findings.length >= 10) break;
    }

    return findings;
}

function checkTapTargets(doc, view) {
    const findings = [];

    for (const node of doc.querySelectorAll(INTERACTIVE)) {
        const rect = node.getBoundingClientRect();
        // Zero-size elements are hidden, not small; skip rather than flag.
        if (rect.width === 0 || rect.height === 0) continue;
        if (view.getComputedStyle(node).display === 'inline') continue;

        if (rect.width >= 24 && rect.height >= 24) continue;

        findings.push(
            finding('medium', `Tap target is ${Math.round(rect.width)}×${Math.round(rect.height)}px`, node,
                'WCAG 2.2 asks for at least 24×24px. Add padding rather than growing the label.')
        );

        if (findings.length >= 10) break;
    }

    return findings;
}

function checkTabIndex(doc) {
    const findings = [];

    for (const node of doc.querySelectorAll('[tabindex]')) {
        if (Number(node.getAttribute('tabindex')) > 0) {
            findings.push(
                finding('medium', `Positive tabindex (${node.getAttribute('tabindex')})`, node,
                    'A positive tabindex pulls this out of document order and scrambles the tab sequence. Use 0.')
            );
        }
        if (findings.length >= 6) break;
    }

    return findings;
}

// ------------------------------------------------------------------ helpers

function hasVisibleText(node) {
    for (const child of node.childNodes) {
        if (child.nodeType === 3 && child.textContent.trim().length > 1) return true;
    }
    return false;
}

function finding(severity, title, node, recommendation) {
    return {
        severity,
        title,
        recommendation,
        uie: node && node.dataset ? node.dataset.uie : null,
        label: node && node.tagName ? node.tagName.toLowerCase() : '',
    };
}

/** Markdown report — the artefact that ends up in a research doc. */
export function auditAsMarkdown(findings, url) {
    const counts = { high: 0, medium: 0, low: 0 };
    for (const item of findings) counts[item.severity] += 1;

    const lines = [
        `# Accessibility review — ${url}`,
        '',
        `${findings.length} findings: ${counts.high} high, ${counts.medium} medium, ${counts.low} low.`,
        '',
    ];

    for (const severity of ['high', 'medium', 'low']) {
        const group = findings.filter((item) => item.severity === severity);
        if (!group.length) continue;

        lines.push(`## ${severity[0].toUpperCase()}${severity.slice(1)}`, '');
        for (const item of group) {
            lines.push(`- **${item.title}** \`<${item.label}>\`  `, `  ${item.recommendation}`);
        }
        lines.push('');
    }

    if (!findings.length) lines.push('No automated issues found. Automated checks cover roughly a third of WCAG — still review by hand.');

    return lines.join('\n');
}
