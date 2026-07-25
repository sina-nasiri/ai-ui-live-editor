import { el, $ } from './util.js';

/**
 * Transient notifications.
 *
 * Messages are set with textContent, never innerHTML: several of them relay
 * text that originated on a page we just fetched, and that is not ours to
 * trust as markup.
 */
export function toast(message, kind = 'info', ms = 4500) {
    const host = $('#toasts');
    if (!host) return;

    const node = el('div', { class: 'toast', dataset: { kind }, role: 'status' }, [
        el('span', { class: 'toast-msg', text: String(message) }),
        el('button', { type: 'button', 'aria-label': 'Dismiss', text: '×', onClick: () => node.remove() }),
    ]);

    host.append(node);
    setTimeout(() => node.remove(), ms);
}
