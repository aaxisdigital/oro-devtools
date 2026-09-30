import $ from 'jquery';
import {describe, expect, it} from 'vitest';
import messenger from 'oroui/js/messenger';
import Modal from 'oroui/js/modal';
import {flashRequestError, openHelpModal} from '../../../../Resources/js-src/app/components/viewer-helpers';

describe('flashRequestError', () => {
    it('flashes the message the server sent', () => {
        flashRequestError({responseJSON: {message: 'Boom from server'}}, 'fallback.key');

        expect(messenger.notificationFlashMessage).toHaveBeenCalledWith('error', 'Boom from server');
    });

    it('falls back to the translated key when the response has no message', () => {
        flashRequestError({responseJSON: {}}, 'fallback.key');
        flashRequestError({responseJSON: {message: ''}}, 'fallback.empty');
        flashRequestError({}, 'fallback.no_json');

        expect(messenger.notificationFlashMessage).toHaveBeenNthCalledWith(1, 'error', 'fallback.key');
        expect(messenger.notificationFlashMessage).toHaveBeenNthCalledWith(2, 'error', 'fallback.empty');
        expect(messenger.notificationFlashMessage).toHaveBeenNthCalledWith(3, 'error', 'fallback.no_json');
    });
});

describe('openHelpModal', () => {
    it('opens a close-only modal with the page help block as content', () => {
        Modal.instances.length = 0;
        const $el = $('<div><div data-role="help-content"><p>Help body</p></div></div>');

        openHelpModal($el, 'help.title');

        expect(Modal.instances).toHaveLength(1);
        const modal = Modal.instances[0];
        expect(modal.options).toEqual({
            title: 'help.title',
            content: '<p>Help body</p>',
            allowOk: false,
            cancelText: 'Close'
        });
        expect(modal.open).toHaveBeenCalledTimes(1);
    });
});
