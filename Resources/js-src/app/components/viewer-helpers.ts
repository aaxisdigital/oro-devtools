import __ from 'orotranslation/js/translator';
import messenger from 'oroui/js/messenger';
import Modal from 'oroui/js/modal';

/**
 * Shared UI helpers for the standalone (non-query) viewers — MongoDB and Redis — which extend
 * BaseComponent directly and so have no common ancestor to inherit these from.
 */

/** Flashes the server's own error message when it sent one, else the translated fallback. */
export function flashRequestError(jqXhr: any, fallbackKey: string): void {
    messenger.notificationFlashMessage('error', jqXhr.responseJSON?.message || __(fallbackKey));
}

/** Opens the page's inline [data-role="help-content"] block in a modal. */
export function openHelpModal($el: any, titleKey: string): void {
    const modal = new Modal({
        title: __(titleKey),
        content: $el.find('[data-role="help-content"]').html(),
        allowOk: false,
        cancelText: __('Close')
    });
    modal.open();
}
