import { state } from './store.js';
import { el } from './util.js';

/**
 * Before/after comparison, dragged with a handle.
 *
 * The patch architecture makes this cheap: "before" is just the same snapshot
 * with no patch stylesheet, so a second iframe loading the same markup *is*
 * the original. No re-fetch, no second network request, and it stays correct
 * as edits accumulate because the two frames share one source.
 *
 * The original goes on top, clipped from the right. Dragging left reveals the
 * edited version underneath — the direction people expect from a "reveal".
 */

let overlay = null;
let handle = null;
let ghost = null;
let position = 50;
let syncing = false;

export function isComparing() {
    return overlay !== null;
}

export function toggleCompare(shell) {
    if (overlay) {
        stopCompare();
        return false;
    }

    if (!state.snapshot || !state.doc) return false;

    startCompare(shell);
    return true;
}

function startCompare(shell) {
    overlay = el('div', { class: 'compare-layer' });

    ghost = el('iframe', { class: 'compare-frame', title: 'Original, before edits', tabindex: '-1' });

    handle = el('div', { class: 'compare-handle', role: 'separator', 'aria-label': 'Before / after position', tabindex: '0' }, [
        el('span', { class: 'compare-grip', text: '⇔' }),
    ]);

    const labelBefore = el('span', { class: 'compare-label compare-label-before', text: 'Before' });
    const labelAfter = el('span', { class: 'compare-label compare-label-after', text: 'After' });

    overlay.append(ghost, labelBefore, labelAfter, handle);
    shell.append(overlay);

    // Same markup, no patch stylesheet — this is the unedited page.
    const blob = new Blob([state.snapshot], { type: 'text/html' });
    const url = URL.createObjectURL(blob);
    ghost.addEventListener('load', () => {
        URL.revokeObjectURL(url);
        syncScroll();
    });
    ghost.src = url;

    apply();
    bindDrag(shell);

    // Keep the two views on the same part of the page while you scroll.
    state.view.addEventListener('scroll', syncScroll, true);
}

export function stopCompare() {
    if (!overlay) return;

    state.view?.removeEventListener('scroll', syncScroll, true);
    overlay.remove();
    overlay = null;
    handle = null;
    ghost = null;
}

function syncScroll() {
    if (syncing || !ghost || !ghost.contentWindow || !state.view) return;

    syncing = true;
    try {
        ghost.contentWindow.scrollTo(state.view.scrollX, state.view.scrollY);
    } catch {
        // The ghost document may not be ready yet; the next scroll will catch up.
    }
    syncing = false;
}

function apply() {
    if (!ghost || !handle) return;

    ghost.style.clipPath = `inset(0 ${100 - position}% 0 0)`;
    handle.style.left = `${position}%`;
}

function bindDrag(shell) {
    const move = (clientX) => {
        const rect = shell.getBoundingClientRect();
        position = Math.min(100, Math.max(0, ((clientX - rect.left) / rect.width) * 100));
        apply();
    };

    const onPointerMove = (event) => move(event.clientX);
    const onPointerUp = () => {
        window.removeEventListener('pointermove', onPointerMove);
        window.removeEventListener('pointerup', onPointerUp);
    };

    handle.addEventListener('pointerdown', (event) => {
        event.preventDefault();
        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp);
    });

    // Draggable things need to be operable from the keyboard too.
    handle.addEventListener('keydown', (event) => {
        const step = event.shiftKey ? 10 : 2;
        if (event.key === 'ArrowLeft') position = Math.max(0, position - step);
        else if (event.key === 'ArrowRight') position = Math.min(100, position + step);
        else return;

        event.preventDefault();
        apply();
    });
}
