import { state } from './store.js';
import { describe } from './util.js';

/**
 * Right-click menu inside the preview.
 *
 * The old build had one of these and it broke in a specific, quiet way: the
 * handlers were bound per element at load time, so anything the AI replaced
 * afterwards had no handler and the menu silently stopped appearing on exactly
 * the elements you had just been working on. This version binds one listener
 * to the document and resolves the target at click time, so it cannot go stale.
 */

let menu = null;
let target = null;
let actions = [];

export function installContextMenu(doc, items) {
    actions = items;

    doc.addEventListener('contextmenu', (event) => {
        const node = event.target?.closest?.('[data-uie]');
        if (!node) return;

        event.preventDefault();
        target = node;
        show(doc, event.clientX, event.clientY);
    });

    doc.addEventListener('click', () => hide(), true);
    doc.addEventListener('scroll', () => hide(), true);
    document.addEventListener('click', () => hide(), true);
}

export function contextTarget() {
    return target;
}

function show(doc, x, y) {
    ensure(doc);

    menu.replaceChildren();

    const heading = doc.createElement('div');
    heading.className = 'uie-menu-head';
    heading.textContent = describe(target);
    menu.append(heading);

    for (const action of actions) {
        if (action.separator) {
            const rule = doc.createElement('div');
            rule.className = 'uie-menu-sep';
            menu.append(rule);
            continue;
        }

        const button = doc.createElement('button');
        button.type = 'button';
        button.className = 'uie-menu-item';
        button.textContent = action.label;
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            hide();
            action.run(target);
        });
        menu.append(button);
    }

    menu.style.display = 'block';

    // Measure, then keep it on screen — a menu opened near the right or
    // bottom edge would otherwise be half off it.
    const width = menu.offsetWidth;
    const height = menu.offsetHeight;
    const maxX = state.view.innerWidth - width - 8;
    const maxY = state.view.innerHeight - height - 8;

    menu.style.left = `${Math.max(8, Math.min(x, maxX)) + state.view.scrollX}px`;
    menu.style.top = `${Math.max(8, Math.min(y, maxY)) + state.view.scrollY}px`;
}

function hide() {
    if (menu) menu.style.display = 'none';
}

function ensure(doc) {
    // Same trap as the annotation layer: after a second page load the old
    // menu is still "connected" to the previous, detached document.
    if (menu && menu.isConnected && menu.ownerDocument === doc) return;

    const style = doc.getElementById('editor-chrome');
    if (style && !style.textContent.includes('uie-menu')) {
        style.textContent += `
            .uie-menu {
                position: absolute;
                display: none;
                z-index: 2147483647;
                min-width: 190px;
                padding: 4px;
                background: #1c1f24;
                border: 1px solid #3a414b;
                border-radius: 8px;
                box-shadow: 0 10px 30px rgba(0,0,0,.45);
                font: 13px/1.4 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            }
            .uie-menu-head {
                padding: 5px 9px 7px;
                color: #79828d;
                font: 600 11px/1.4 ui-monospace, Menlo, monospace;
                border-bottom: 1px solid #2b3038;
                margin-bottom: 4px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
            .uie-menu-item {
                display: block;
                width: 100%;
                padding: 7px 9px;
                border: 0;
                border-radius: 5px;
                background: transparent;
                color: #e8eaed;
                text-align: left;
                cursor: pointer;
                font: inherit;
            }
            .uie-menu-item:hover { background: #4f46e5; }
            .uie-menu-sep { height: 1px; background: #2b3038; margin: 4px 2px; }
        `;
    }

    menu = doc.createElement('div');
    menu.className = 'uie-menu';
    doc.body.append(menu);
}
