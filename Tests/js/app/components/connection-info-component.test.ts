import $ from 'jquery';
import {beforeEach, describe, expect, it} from 'vitest';
import Modal from 'oroui/js/modal';
import ConnectionInfoComponent from '../../../../Resources/js-src/app/components/connection-info-component';
import {AjaxRecorder, installAjax} from '../../helpers/ajax';

const FIXTURE = `
<div id="page">
    <input type="text" data-role="trusted-proxies" value=" 10.0.0.1, 10.0.0.2 ">
    <button data-role="apply">Apply</button>
    <span data-role="applying" hidden>working</span>
    <button data-role="help">?</button>
    <span data-role="resolved-ip"></span>
    <span data-role="resolved-ip-inline"></span>
    <span data-role="resolved-source"></span>
    <div data-role="chain"><span>stale hop</span></div>
    <div data-role="help-content"><i>connection help</i></div>
</div>`;

describe('ConnectionInfoComponent', () => {
    let $page: any;
    let ajax: AjaxRecorder;

    beforeEach(() => {
        Modal.instances.length = 0;
        ajax = installAjax();
        $page = $(FIXTURE).appendTo(document.body);
    });

    function create(): any {
        return new (ConnectionInfoComponent as any)({_sourceElement: $page, resolveUrl: '/resolve'});
    }

    const text = (role: string) => $page.find('[data-role="' + role + '"]').text();

    it('posts the trimmed proxy list as JSON and locks the form while it runs', () => {
        create();
        $page.find('[data-role="apply"]').trigger('click');

        expect(ajax.requests).toHaveLength(1);
        expect(ajax.requests[0].options).toMatchObject({
            url: '/resolve',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({trustedProxies: '10.0.0.1, 10.0.0.2'})
        });
        expect($page.find('[data-role="applying"]').prop('hidden')).toBe(false);
        expect($page.find('[data-role="apply"]').prop('disabled')).toBe(true);
        expect($page.find('[data-role="trusted-proxies"]').prop('disabled')).toBe(true);
    });

    it('ignores further applies while a request is in flight', () => {
        create();
        $page.find('[data-role="apply"]').trigger('click');
        $page.find('[data-role="apply"]').prop('disabled', false).trigger('click');

        expect(ajax.requests).toHaveLength(1);
    });

    it('applies on Enter in the proxy field only', () => {
        create();
        const other = $.Event('keydown', {keyCode: 65});
        $page.find('[data-role="trusted-proxies"]').trigger(other);
        expect(ajax.requests).toHaveLength(0);

        const enter = $.Event('keydown', {keyCode: 13});
        $page.find('[data-role="trusted-proxies"]').trigger(enter);
        expect(enter.isDefaultPrevented()).toBe(true);
        expect(ajax.requests).toHaveLength(1);
    });

    it('renders a forwarded result with the hop chain and re-enables the form', () => {
        create();
        $page.find('[data-role="apply"]').trigger('click');

        ajax.requests[0].resolve({
            success: true,
            client: {
                ip: '203.0.113.9',
                source: 'forwarded',
                via: 'X-Forwarded-For',
                chain: [
                    {value: '203.0.113.9', trusted: false},
                    {value: '10.0.0.1', trusted: true}
                ]
            }
        });

        expect(text('resolved-ip')).toBe('203.0.113.9');
        expect(text('resolved-ip-inline')).toBe('203.0.113.9');
        expect(text('resolved-source')).toBe('aaxis.devtools.connection_info.source.forwarded');
        const hops = $page.find('.aaxis-cinfo__hop');
        expect(hops).toHaveLength(2);
        expect(hops.eq(0).hasClass('is-trusted')).toBe(false);
        expect(hops.eq(0).find('.aaxis-cinfo__hop-tag')).toHaveLength(0);
        expect(hops.eq(1).hasClass('is-trusted')).toBe(true);
        expect(hops.eq(1).find('.aaxis-cinfo__hop-tag').text()).toBe('aaxis.devtools.connection_info.trusted_tag');
        // one separator between the two hops, none after the last
        expect($page.find('.aaxis-cinfo__hop-sep')).toHaveLength(1);
        expect($page.find('.aaxis-cinfo__hop-sep').attr('aria-hidden')).toBe('true');
        expect($page.find('[data-role="chain"]').text()).not.toContain('stale hop');
        expect($page.find('[data-role="applying"]').prop('hidden')).toBe(true);
        expect($page.find('[data-role="apply"]').prop('disabled')).toBe(false);
    });

    it.each([
        ['all_trusted', 'aaxis.devtools.connection_info.source.all_trusted'],
        ['remote_addr', 'aaxis.devtools.connection_info.source.remote_addr']
    ])('labels a "%s" source', (source, label) => {
        create();
        $page.find('[data-role="apply"]').trigger('click');

        ajax.requests[0].resolve({success: true, client: {ip: '1.1.1.1', source, via: null, chain: []}});

        expect(text('resolved-source')).toBe(label);
        expect($page.find('.aaxis-cinfo__hop')).toHaveLength(0);
    });

    it('leaves the page alone when a successful response carries no client', () => {
        create();
        $page.find('[data-role="apply"]').trigger('click');

        ajax.requests[0].resolve({success: false});

        expect(text('resolved-ip')).toBe('');
        expect($page.find('[data-role="apply"]').prop('disabled')).toBe(false);
    });

    it('shows the error text when the request fails and unlocks the form', () => {
        create();
        $page.find('[data-role="apply"]').trigger('click');

        ajax.requests[0].reject({});

        expect(text('resolved-source')).toBe('aaxis.devtools.connection_info.error');
        expect($page.find('[data-role="trusted-proxies"]').prop('disabled')).toBe(false);
    });

    it('opens the help block in a close-only modal', () => {
        create();
        const click = $.Event('click');
        $page.find('[data-role="help"]').trigger(click);

        expect(click.isDefaultPrevented()).toBe(true);
        expect(Modal.instances[0].options).toEqual({
            title: 'aaxis.devtools.connection_info.help',
            content: '<i>connection help</i>',
            allowOk: false,
            cancelText: 'Close'
        });
        expect(Modal.instances[0].open).toHaveBeenCalled();
    });

    it('unbinds on dispose, idempotently', () => {
        const component = create();
        component.dispose();

        $page.find('[data-role="apply"]').trigger('click');
        expect(ajax.requests).toHaveLength(0);
        expect(component.disposed).toBe(true);
        expect(() => component.dispose()).not.toThrow();
    });
});
