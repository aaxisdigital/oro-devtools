import $ from 'jquery';
import CodeMirror from 'codemirror';
import {beforeEach, describe, expect, it} from 'vitest';
import messenger from 'oroui/js/messenger';
import Modal from 'oroui/js/modal';
import RedisViewerComponent from '../../../../Resources/js-src/app/components/redis-viewer-component';
import {AjaxRecorder, installAjax} from '../../helpers/ajax';

const FIXTURE = `
<div id="page">
    <button data-role="help">?</button>
    <button data-role="refresh">refresh</button>
    <ul data-role="databases"></ul>
    <div data-role="server"></div>
    <input data-role="pattern" value="">
    <button data-role="scan">scan</button>
    <div data-role="keys"></div>
    <div data-role="value"></div>
    <div data-role="help-content"><em>redis help</em></div>
</div>`;

const OVERVIEW = {
    success: true,
    server: {Version: '7.2', Mode: 'standalone'},
    databases: [{db: 0, keys: 0}, {db: 1, keys: 5}, {db: 2, keys: 3}]
};

describe('RedisViewerComponent', () => {
    let $page: any;
    let ajax: AjaxRecorder;

    beforeEach(() => {
        Modal.instances.length = 0;
        ajax = installAjax();
        $page = $(FIXTURE).appendTo(document.body);
    });

    function create(): any {
        return new (RedisViewerComponent as any)({
            _sourceElement: $page,
            overviewUrl: '/overview',
            keysUrl: '/keys',
            valueUrl: '/value'
        });
    }

    /** Boots with the standard overview, which auto-selects the first non-empty db and starts its scan. */
    function boot(overview: any = OVERVIEW): any {
        const component = create();
        ajax.last('/overview').resolve(overview);
        return component;
    }

    const flashed = () => (messenger.notificationFlashMessage as any).mock.calls;
    const keyRow = (key: string) => $page.find('[data-role="key"][data-key="' + key + '"]');

    describe('overview', () => {
        it('lists databases with key counts and the server info as a definition list', () => {
            boot();

            const dbs = $page.find('[data-role="db"]');
            expect(dbs).toHaveLength(3);
            expect(dbs.eq(1).text()).toBe('aaxis.devtools.redis_viewer.db_label5');
            expect(dbs.eq(1).find('.aaxis-redis__db-count').text()).toBe('5');
            const terms = $page.find('[data-role="server"] dt').map((_: number, el: HTMLElement) => el.textContent).get();
            const defs = $page.find('[data-role="server"] dd').map((_: number, el: HTMLElement) => el.textContent).get();
            expect(terms).toEqual(['Version', 'Mode']);
            expect(defs).toEqual(['7.2', 'standalone']);
        });

        it('selects the first database that has keys and scans it', () => {
            boot();

            expect($page.find('[data-role="db"]').eq(1).hasClass('is-active')).toBe(true);
            expect(ajax.last('/keys').options.data).toEqual({db: 1, pattern: '*', cursor: '', count: 200});
            expect($page.find('[data-role="value"]').text()).toBe('aaxis.devtools.redis_viewer.select_key');
        });

        it('falls back to the first database when all are empty', () => {
            boot({success: true, databases: [{db: 3, keys: 0}, {db: 4, keys: 0}]});

            expect(ajax.last('/keys').options.data.db).toBe(3);
        });

        it('notes the absence of databases and scans nothing', () => {
            boot({success: true});

            expect($page.find('[data-role="databases"]').text()).toBe('aaxis.devtools.redis_viewer.no_databases');
            expect(ajax.to('/keys')).toHaveLength(0);
            expect($page.find('[data-role="server"] dl')).toHaveLength(1);
        });

        it('keeps the current database and re-scans it when refreshed', () => {
            boot();
            ajax.last('/keys').resolve({success: true, cursor: '0', done: true, keys: []});

            $page.find('[data-role="refresh"]').trigger('click');
            ajax.last('/overview').resolve({...OVERVIEW, databases: [{db: 1, keys: 9}]});

            expect($page.find('[data-role="db"]').text()).toContain('9');
            expect(ajax.to('/keys')).toHaveLength(2);
            expect(ajax.last('/keys').options.data.db).toBe(1);
        });

        it('flashes when the overview reports failure or the call breaks', () => {
            create();
            ajax.last('/overview').resolve({success: false, message: 'auth failed'});
            expect(flashed()[0]).toEqual(['error', 'auth failed']);

            $page.find('[data-role="refresh"]').trigger('click');
            ajax.requests[1].resolve({success: false});
            expect(flashed()[1]).toEqual(['error', 'aaxis.devtools.redis_viewer.overview_error']);

            $page.find('[data-role="refresh"]').trigger('click');
            ajax.requests[2].reject({message: 'unreachable'});
            expect(flashed()[2]).toEqual(['error', 'unreachable']);
        });

        it('switches database on click', () => {
            boot();
            ajax.last('/keys').resolve({success: true, cursor: '0', done: true, keys: []});

            $page.find('[data-role="db"]').eq(2).trigger('click');

            expect($page.find('[data-role="db"]').eq(2).hasClass('is-active')).toBe(true);
            expect($page.find('[data-role="db"]').eq(1).hasClass('is-active')).toBe(false);
            expect(ajax.last('/keys').options.data.db).toBe(2);
        });
    });

    describe('key listing', () => {
        it('renders keys with type badge, name and TTL text', () => {
            boot();
            ajax.last('/keys').resolve({
                success: true,
                cursor: '0',
                done: true,
                keys: [
                    {key: 'a', type: 'string', ttl: -1},
                    {key: 'b', type: 'hash', ttl: 30},
                    {key: 'c', type: 'weird', ttl: -2}
                ]
            });

            expect($page.find('.aaxis-redis__key-name').map((_: number, el: HTMLElement) => el.textContent).get())
                .toEqual(['a', 'b', 'c']);
            const types = $page.find('.aaxis-redis__type');
            expect(types.eq(0).hasClass('is-string')).toBe(true);
            expect(types.eq(1).hasClass('is-hash')).toBe(true);
            expect(types.eq(2).attr('class')).toBe('aaxis-redis__type ');
            const ttls = $page.find('.aaxis-redis__ttl').map((_: number, el: HTMLElement) => el.textContent).get();
            expect(ttls).toEqual(['aaxis.devtools.redis_viewer.ttl_none', 'aaxis.devtools.redis_viewer.ttl_label', '']);
            expect($page.find('[data-role="load-more"]')).toHaveLength(0);
        });

        it('notes an empty keyspace', () => {
            boot();
            ajax.last('/keys').resolve({success: true, done: true});

            expect($page.find('[data-role="keys"]').text()).toBe('aaxis.devtools.redis_viewer.no_keys');
        });

        it('scans with the pattern from the input and starts over on a new scan', () => {
            boot();
            ajax.last('/keys').resolve({success: true, cursor: '17', done: false, keys: [{key: 'a', type: 'string', ttl: -1}]});

            $page.find('[data-role="pattern"]').val('user:*');
            $page.find('[data-role="scan"]').trigger('click');

            expect(ajax.last('/keys').options.data).toEqual({db: 1, pattern: 'user:*', cursor: '', count: 200});
        });

        it('pages with the returned cursor while the scan is not done and appends results', () => {
            boot();
            ajax.last('/keys').resolve({success: true, cursor: '17', done: false, keys: [{key: 'a', type: 'string', ttl: -1}]});
            expect($page.find('[data-role="load-more"]')).toHaveLength(1);

            $page.find('[data-role="load-more"]').trigger('click');
            expect(ajax.last('/keys').options.data.cursor).toBe('17');
            ajax.last('/keys').resolve({success: true, done: true, keys: [{key: 'b', type: 'list', ttl: -1}]});

            expect($page.find('[data-role="key"]')).toHaveLength(2);
            expect($page.find('[data-role="load-more"]')).toHaveLength(0);
        });

        it('defaults a missing cursor to "0"', () => {
            boot();
            ajax.last('/keys').resolve({success: true, done: false, keys: [{key: 'a', type: 'string', ttl: -1}]});
            $page.find('[data-role="load-more"]').trigger('click');

            expect(ajax.last('/keys').options.data.cursor).toBe('0');
        });

        it('scans on Enter in the pattern field only', () => {
            boot();
            ajax.last('/keys').resolve({success: true, done: true, keys: []});

            $page.find('[data-role="pattern"]').trigger($.Event('keydown', {keyCode: 65}));
            expect(ajax.to('/keys')).toHaveLength(1);

            const enter = $.Event('keydown', {keyCode: 13});
            $page.find('[data-role="pattern"]').trigger(enter);
            expect(enter.isDefaultPrevented()).toBe(true);
            expect(ajax.to('/keys')).toHaveLength(2);
        });

        it('ignores a scan while another is loading', () => {
            boot();
            $page.find('[data-role="scan"]').trigger('click');

            expect(ajax.to('/keys')).toHaveLength(1);
        });

        it('flashes on a failed scan and allows the next one', () => {
            boot();
            ajax.last('/keys').resolve({success: false, message: 'bad pattern'});
            expect(flashed().pop()).toEqual(['error', 'bad pattern']);

            $page.find('[data-role="scan"]').trigger('click');
            ajax.last('/keys').resolve({success: false});
            expect(flashed().pop()).toEqual(['error', 'aaxis.devtools.redis_viewer.keys_error']);

            $page.find('[data-role="scan"]').trigger('click');
            ajax.last('/keys').reject(undefined);
            expect(flashed().pop()).toEqual(['error', 'aaxis.devtools.redis_viewer.keys_error']);
            expect(ajax.to('/keys')).toHaveLength(3);
        });
    });

    describe('value inspector', () => {
        const KEYS = {
            success: true,
            cursor: '0',
            done: true,
            keys: [{key: 'k1', type: 'string', ttl: 60}]
        };

        function openValue(response: any): void {
            boot();
            ajax.last('/keys').resolve(KEYS);
            keyRow('k1').trigger('click');
            ajax.last('/value').resolve(response);
        }

        const value = () => $page.find('[data-role="value"]');

        it('requests the clicked key and marks its row active', () => {
            boot();
            ajax.last('/keys').resolve(KEYS);
            keyRow('k1').trigger('click');

            expect(ajax.last('/value').options).toMatchObject({method: 'GET', data: {db: 1, key: 'k1'}});
            expect(keyRow('k1').hasClass('is-active')).toBe(true);
        });

        it('renders the header with key, type, ttl and total', () => {
            openValue({success: true, key: 'k1', type: 'string', ttl: 60, total: 1, value: 'plain'});

            expect(value().find('.aaxis-redis__value-key').text()).toBe('k1');
            expect(value().find('.aaxis-redis__type').text()).toBe('string');
            expect(value().find('.aaxis-redis__value-meta').eq(0).text()).toBe('aaxis.devtools.redis_viewer.ttl_label');
            expect(value().find('.aaxis-redis__value-meta').eq(1).text()).toBe('aaxis.devtools.redis_viewer.total_label');
        });

        it('shows a plain string as text', () => {
            openValue({success: true, key: 'k1', type: 'string', ttl: -1, total: 1, value: 'just text'});

            expect(value().find('pre.aaxis-redis__pre').text()).toBe('just text');
            expect((CodeMirror as any).runMode).not.toHaveBeenCalled();
        });

        it('pretty-prints and highlights a JSON string', () => {
            openValue({success: true, key: 'k1', type: 'string', ttl: -1, total: 1, value: ' {"a":1,"b":[2]} '});

            const pre = value().find('pre').get(0);
            expect((CodeMirror as any).runMode).toHaveBeenCalledWith(
                JSON.stringify({a: 1, b: [2]}, null, 2), {name: 'javascript', json: true}, pre
            );
            // runMode is a stub, so it leaves the element untouched: the text is NOT dumped a second time
            expect(pre.textContent).toBe('');
        });

        it('pretty-prints and highlights a JSON array string too', () => {
            openValue({success: true, key: 'k1', type: 'string', ttl: -1, total: 1, value: '[1,{"a":2}]'});

            expect((CodeMirror as any).runMode).toHaveBeenCalledWith(
                JSON.stringify([1, {a: 2}], null, 2), {name: 'javascript', json: true}, value().find('pre').get(0)
            );
        });

        it('does not try to parse strings that only look numeric', () => {
            openValue({success: true, key: 'k1', type: 'string', ttl: -1, total: 1, value: '123'});

            expect((CodeMirror as any).runMode).not.toHaveBeenCalled();
            expect(value().find('pre').text()).toBe('123');
        });

        it('shows brace-led text that is not valid JSON verbatim', () => {
            openValue({success: true, key: 'k1', type: 'string', ttl: -1, total: 1, value: '{not json'});

            expect(value().find('pre').text()).toBe('{not json');
        });

        it('falls back to raw text when highlighting throws on valid JSON', () => {
            boot();
            ajax.last('/keys').resolve(KEYS);
            keyRow('k1').trigger('click');
            (CodeMirror as any).runMode.mockImplementationOnce(() => {
                throw new Error('boom');
            });
            ajax.last('/value').resolve({success: true, key: 'k1', type: 'string', ttl: -1, total: 1, value: '[1]'});

            expect(value().find('pre').text()).toBe('[1]');
        });

        it('treats a missing string value as empty', () => {
            openValue({success: true, key: 'k1', type: 'string', ttl: -1, total: 0});

            expect(value().find('pre').text()).toBe('');
        });

        it('renders a zset as member/score pairs', () => {
            openValue({
                success: true, key: 'k1', type: 'zset', ttl: -1, total: 2,
                items: [{member: 'alice', score: 1.5}, {member: 'bob', score: 2}]
            });

            const heads = value().find('th').map((_: number, el: HTMLElement) => el.textContent).get();
            expect(heads).toEqual(['aaxis.devtools.redis_viewer.member', 'aaxis.devtools.redis_viewer.score']);
            const cells = value().find('tbody td').map((_: number, el: HTMLElement) => el.textContent).get();
            expect(cells).toEqual(['alice', '1.5', 'bob', '2']);
        });

        it('renders a hash as field/value pairs', () => {
            openValue({
                success: true, key: 'k1', type: 'hash', ttl: -1, total: 1,
                items: [{field: 'f', value: 'v'}]
            });

            const heads = value().find('th').map((_: number, el: HTMLElement) => el.textContent).get();
            expect(heads).toEqual(['aaxis.devtools.redis_viewer.field', 'aaxis.devtools.redis_viewer.value']);
            expect(value().find('tbody td').map((_: number, el: HTMLElement) => el.textContent).get()).toEqual(['f', 'v']);
        });

        it.each(['list', 'set'])('renders a %s as an indexed list', type => {
            openValue({success: true, key: 'k1', type, ttl: -1, total: 2, items: ['x', 'y']});

            const cells = value().find('tbody td').map((_: number, el: HTMLElement) => el.textContent).get();
            expect(cells).toEqual(['0', 'x', '1', 'y']);
        });

        it('renders an empty collection type without rows', () => {
            openValue({success: true, key: 'k1', type: 'list', ttl: -1, total: 0});

            expect(value().find('tbody tr')).toHaveLength(0);
        });

        it('renders unknown types as a scalar', () => {
            openValue({success: true, key: 'k1', type: 'stream', ttl: -1, total: 1, value: 'raw'});

            expect(value().find('pre').text()).toBe('raw');
        });

        it('warns when a collection value was truncated', () => {
            openValue({success: true, key: 'k1', type: 'list', ttl: -1, total: 999, truncated: true, items: ['a', 'b']});

            expect(value().find('.aaxis-redis__value-warn').text()).toBe('aaxis.devtools.redis_viewer.truncated_note');
        });

        it('warns about truncated scalars using the total', () => {
            openValue({success: true, key: 'k1', type: 'string', ttl: -1, total: 5000, truncated: true, value: 'abc'});

            expect(value().find('.aaxis-redis__value-warn')).toHaveLength(1);
        });

        it('flashes when the value call reports failure or breaks', () => {
            boot();
            ajax.last('/keys').resolve(KEYS);
            keyRow('k1').trigger('click');
            ajax.last('/value').resolve({success: false, message: 'gone'});
            expect(flashed().pop()).toEqual(['error', 'gone']);

            keyRow('k1').trigger('click');
            ajax.last('/value').resolve({success: false});
            expect(flashed().pop()).toEqual(['error', 'aaxis.devtools.redis_viewer.value_error']);

            keyRow('k1').trigger('click');
            ajax.last('/value').reject({message: 'down'});
            expect(flashed().pop()).toEqual(['error', 'down']);
        });

        it('does not load a value before a database is selected', () => {
            create();
            ajax.last('/overview').resolve({success: true});
            $page.find('[data-role="keys"]').append('<div data-role="key" data-key="orphan"></div>');

            $page.find('[data-role="key"]').trigger('click');

            expect(ajax.to('/value')).toHaveLength(0);
        });
    });

    it('opens the help block in a close-only modal', () => {
        boot();
        const click = $.Event('click');
        $page.find('[data-role="help"]').trigger(click);

        expect(click.isDefaultPrevented()).toBe(true);
        expect(Modal.instances[0].options).toEqual({
            title: 'aaxis.devtools.redis_viewer.help',
            content: '<em>redis help</em>',
            allowOk: false,
            cancelText: 'Close'
        });
    });

    it('unbinds on dispose, idempotently', () => {
        const component = boot();
        component.dispose();

        const before = ajax.requests.length;
        $page.find('[data-role="scan"]').trigger('click');
        expect(ajax.requests).toHaveLength(before);
        expect(component.disposed).toBe(true);
        expect(() => component.dispose()).not.toThrow();
    });
});
