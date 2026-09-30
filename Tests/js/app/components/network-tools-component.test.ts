import $ from 'jquery';
import {beforeEach, describe, expect, it} from 'vitest';
import Modal from 'oroui/js/modal';
import NetworkToolsComponent from '../../../../Resources/js-src/app/components/network-tools-component';
import {AjaxRecorder, installAjax} from '../../helpers/ajax';

const FIXTURE = `
<div id="page">
    <select data-role="tool">
        <option value="dns" selected>dns</option>
        <option value="ping">ping</option>
        <option value="socket">socket</option>
        <option value="curl">curl</option>
        <option value="unknown">unknown</option>
    </select>
    <input data-role="host" value="">
    <input data-role="port" value="">
    <input data-role="path" value="">
    <span data-role="dns-mode-wrap"><select data-role="dns-mode"><option value="a">A</option><option value="mx">MX</option></select></span>
    <input data-role="timeout" value="">
    <button data-role="run">Run</button>
    <button data-role="clear">Clear</button>
    <button data-role="help">?</button>
    <span data-role="running" hidden></span>
    <pre data-role="console"></pre>
    <div data-role="help-content"><u>net help</u></div>
</div>`;

describe('NetworkToolsComponent', () => {
    let $page: any;
    let ajax: AjaxRecorder;

    beforeEach(() => {
        Modal.instances.length = 0;
        ajax = installAjax();
        $page = $(FIXTURE).appendTo(document.body);
    });

    function create(): any {
        return new (NetworkToolsComponent as any)({_sourceElement: $page, runUrl: '/run'});
    }

    const field = (role: string) => $page.find('[data-role="' + role + '"]');
    const consoleText = () => field('console').text();
    const selectTool = (tool: string) => field('tool').val(tool).trigger('change');
    const run = () => field('run').trigger('click');

    it('shows only the fields the selected tool needs', () => {
        create();

        // dns: no port/path, dns-mode visible
        expect(field('port').hasClass('aaxis-net-tools__hidden')).toBe(true);
        expect(field('port').prop('disabled')).toBe(true);
        expect(field('path').hasClass('aaxis-net-tools__hidden')).toBe(true);
        expect(field('dns-mode-wrap').hasClass('aaxis-net-tools__hidden')).toBe(false);
        expect(field('dns-mode').prop('disabled')).toBe(false);

        selectTool('curl');
        expect(field('port').hasClass('aaxis-net-tools__hidden')).toBe(false);
        expect(field('path').hasClass('aaxis-net-tools__hidden')).toBe(false);
        expect(field('dns-mode-wrap').hasClass('aaxis-net-tools__hidden')).toBe(true);
        expect(field('dns-mode').prop('disabled')).toBe(true);

        selectTool('ping');
        expect(field('port').hasClass('aaxis-net-tools__hidden')).toBe(false);
        expect(field('path').hasClass('aaxis-net-tools__hidden')).toBe(true);
    });

    it('hides every optional field for an unknown tool', () => {
        create();
        selectTool('unknown');

        expect(field('port').hasClass('aaxis-net-tools__hidden')).toBe(true);
        expect(field('path').hasClass('aaxis-net-tools__hidden')).toBe(true);
        expect(field('dns-mode-wrap').hasClass('aaxis-net-tools__hidden')).toBe(true);
    });

    it('refuses to run without a host', () => {
        create();
        run();

        expect(ajax.requests).toHaveLength(0);
        expect(consoleText()).toBe('aaxis.devtools.network_tools.host_required\n');
    });

    it('requires a port for the socket tool', () => {
        create();
        selectTool('socket');
        field('host').val('example.com');
        run();

        expect(ajax.requests).toHaveLength(0);
        expect(consoleText()).toBe('aaxis.devtools.network_tools.port_required\n');
    });

    it('sends a dns lookup with the chosen record type, echoes the command and shows the output', () => {
        create();
        field('host').val(' example.com ');
        field('dns-mode').val('mx');
        field('timeout').val('5');
        run();

        expect(ajax.requests[0].options).toMatchObject({
            url: '/run',
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({tool: 'dns', host: 'example.com', mode: 'mx', timeout: '5'})
        });
        expect(consoleText()).toBe('> dns example.com mx 5s\n');
        expect($page.find('[data-role="running"]').prop('hidden')).toBe(false);
        expect(field('run').prop('disabled')).toBe(true);
        expect(field('host').prop('disabled')).toBe(true);
        expect(field('dns-mode').prop('disabled')).toBe(true);

        ajax.requests[0].resolve({success: true, output: '93.184.216.34'});

        expect(consoleText()).toBe('> dns example.com mx 5s\n93.184.216.34\n\n');
        expect($page.find('[data-role="running"]').prop('hidden')).toBe(true);
        expect(field('run').prop('disabled')).toBe(false);
        // the tool-specific state is restored after the run
        expect(field('dns-mode').prop('disabled')).toBe(false);
        expect(field('port').prop('disabled')).toBe(true);
    });

    it('falls back to the "a" record type when the select has no value', () => {
        create();
        field('dns-mode').empty();
        field('host').val('example.com');
        run();

        expect(JSON.parse(ajax.requests[0].options.data)).toEqual({tool: 'dns', host: 'example.com', mode: 'a'});
    });

    it('includes port and path only when filled in for the curl tool', () => {
        create();
        selectTool('curl');
        field('host').val('example.com');
        field('port').val('8080');
        field('path').val('/health');
        run();

        expect(JSON.parse(ajax.requests[0].options.data))
            .toEqual({tool: 'curl', host: 'example.com', port: '8080', path: '/health'});
        expect(consoleText()).toContain('> curl example.com 8080 /health\n');
    });

    it('omits empty optional port and path', () => {
        create();
        selectTool('curl');
        field('host').val('example.com');
        run();

        expect(JSON.parse(ajax.requests[0].options.data)).toEqual({tool: 'curl', host: 'example.com'});
    });

    it('ignores extra runs while a request is in flight', () => {
        create();
        field('host').val('example.com');
        run();
        field('run').prop('disabled', false).trigger('click');

        expect(ajax.requests).toHaveLength(1);
    });

    it('prints an empty body when the response has no output', () => {
        create();
        field('host').val('example.com');
        run();
        ajax.requests[0].resolve({success: true});

        expect(consoleText()).toBe('> dns example.com a\n\n\n');
    });

    it('prints the server output of a failed call, else the generic error', () => {
        create();
        field('host').val('example.com');
        run();
        ajax.requests[0].reject({output: 'blocked target'});
        expect(consoleText()).toContain('blocked target\n');

        $page.find('[data-role="console"]').empty();
        run();
        ajax.requests[1].reject(undefined);
        expect(consoleText()).toContain('aaxis.devtools.network_tools.error\n');
    });

    it('runs on Enter in the host field and not on other keys', () => {
        create();
        field('host').val('example.com');
        field('host').trigger($.Event('keydown', {keyCode: 65}));
        expect(ajax.requests).toHaveLength(0);

        const enter = $.Event('keydown', {keyCode: 13});
        field('host').trigger(enter);
        expect(enter.isDefaultPrevented()).toBe(true);
        expect(ajax.requests).toHaveLength(1);
    });

    it('clears the console', () => {
        create();
        field('console').text('old output');
        const click = $.Event('click');
        field('clear').trigger(click);

        expect(click.isDefaultPrevented()).toBe(true);
        expect(consoleText()).toBe('');
    });

    it('opens the help block in a close-only modal', () => {
        create();
        field('help').trigger('click');

        expect(Modal.instances[0].options).toEqual({
            title: 'aaxis.devtools.network_tools.help',
            content: '<u>net help</u>',
            allowOk: false,
            cancelText: 'Close'
        });
        expect(Modal.instances[0].open).toHaveBeenCalled();
    });

    it('unbinds on dispose, idempotently', () => {
        const component = create();
        component.dispose();

        field('host').val('example.com');
        run();
        expect(ajax.requests).toHaveLength(0);
        expect(component.disposed).toBe(true);
        expect(() => component.dispose()).not.toThrow();
    });
});
