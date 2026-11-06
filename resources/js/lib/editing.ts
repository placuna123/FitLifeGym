// The portals refresh their data every few seconds. A refresh must never run while
// someone is typing or has a pop-up open, or it would replace what they typed.

const MODAL_SELECTOR = [
    '[role="dialog"]',
    '.modal-overlay',
    '.modal-content',
    '.modal-card',
    '.fixed.inset-0.z-50',
].join(', ');

/** True when a text field, dropdown or editable area has the cursor. */
function isTypingInField(): boolean {
    const el = document.activeElement as HTMLElement | null;
    if (!el) return false;
    if (el.isContentEditable) return true;
    if (el instanceof HTMLTextAreaElement || el instanceof HTMLSelectElement) return true;
    if (el instanceof HTMLInputElement) {
        return !['button', 'submit', 'reset', 'checkbox', 'radio', 'range', 'color', 'file', 'image', 'hidden'].includes(el.type);
    }
    return false;
}

/** True when a pop-up (add / edit form, confirmation, etc.) is on screen. */
function hasOpenModal(): boolean {
    return document.querySelector(MODAL_SELECTOR) !== null;
}

/** Skip the background refresh this time: the person is typing or has a form open. */
export function isUserEditing(): boolean {
    return isTypingInField() || hasOpenModal();
}
