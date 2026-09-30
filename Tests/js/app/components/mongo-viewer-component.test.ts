import $ from 'jquery';
import CodeMirror from 'codemirror';
import {beforeEach, describe, expect, it} from 'vitest';
import messenger from 'oroui/js/messenger';
import Modal from 'oroui/js/modal';
import MongoViewerComponent from '../../../../Resources/js-src/app/components/mongo-viewer-component';
import {AjaxRecorder, installAjax} from '../../helpers/ajax';

const FIXTURE = `
<div id="page">
    <button data-role="help">?</button>
    <button data-role="refresh">refresh</button>
    <ul data-role="databases"></ul>
    <input data-role="filter" value="">
    <button data-role="find">find</button>
    <div data-role="docs"></div>
    <div data-role="doc"></div>
    <div data-role="help-content"><em>mongo help</em></div>
</div>`;

const OVERVIEW = {
    success: true,
    connections: [
        {
            connection: 'default',
            server: {Version: '7.0.2'},
            databases: [
                {db: 'shop', collections: 2, sizeOnDisk: 10, connection: 'default'},
                {db: 'logs', collections: 0, sizeOnDisk: 5, connection: 'default'}
            ]
        }
    ]
};

describe('MongoViewerComponent', () => {
    let $page: any;
    let ajax: AjaxRecorder;

    beforeEach(() => {
        Modal.instances.length = 0;
        ajax = installAjax();
        $page = $(FIXTURE).appendTo(document.body);
    });

    function create(): any {
        return new (MongoViewerComponent as any)({
            _sourceElement: $page,
            overviewUrl: '/overview',
            collectionsUrl: '/collections',
            documentsUrl: '/documents'
        });
    }

    /** Boots the viewer with the standard overview already loaded. */
    function boot(overview: any = OVERVIEW): any {
        const component = create();
        ajax.last('/overview').resolve(overview);
        return component;
    }

    const flash = () => messenger.notificationFlashMessage as any;
    const dbRow = (db: string) => $page.find('[data-role="db"][data-db="' + db + '"]');
    const flashed = () => flash().mock.calls;

    describe('overview', () => {
        it('requests the overview on start and renders each connection with its version and databases', () => {
            create();
            expect(ajax.requests[0].options).toMatchObject({url: '/overview', method: 'GET'});

            ajax.last('/overview').resolve(OVERVIEW);

            expect($page.find('.aaxis-mongo__conn-label').text()).toBe('aaxis.devtools.mongodb_viewer.connection_default');
            expect($page.find('.aaxis-mongo__conn-version').text()).toBe('v7.0.2');
            expect(dbRow('shop').find('.aaxis-mongo__db-count').text()).toBe('2');
            expect(dbRow('logs')).toHaveLength(1);
            expect(dbRow('shop').find('.fa').hasClass('fa-caret-right')).toBe(true);
        });

        it('omits the version badge when the server reports none', () => {
            boot({success: true, connections: [{connection: 'default', server: {}, databases: [{db: 'a', collections: 1}]}]});

            expect($page.find('.aaxis-mongo__conn-version')).toHaveLength(0);
        });

        it('shows the "no databases" note when nothing is reachable and nothing failed', () => {
            boot({success: true, connections: []});

            expect($page.find('[data-role="databases"]').text()).toBe('aaxis.devtools.mongodb_viewer.no_databases');
        });

        it('shows a connection error in place of its databases', () => {
            boot({success: true, connections: [{connection: 'secondary', server: {}, databases: [], error: 'refused'}]});

            expect($page.find('.aaxis-mongo__conn-error').text()).toBe('refused');
            expect($page.find('[data-role="db"]')).toHaveLength(0);
        });

        it('shows a per-connection "no databases" note when a healthy connection is empty', () => {
            boot({success: true, connections: [
                {connection: 'a', server: {}, databases: [{db: 'x', collections: 1}]},
                {connection: 'b', server: {}, databases: []}
            ]});

            const notes = $page.find('.aaxis-mongo__empty').map((_: number, el: HTMLElement) => el.textContent).get();
            expect(notes).toEqual(['aaxis.devtools.mongodb_viewer.no_databases']);
        });

        it('flashes the server message when the overview reports failure, else the fallback', () => {
            create();
            ajax.last('/overview').resolve({success: false, message: 'no access'});
            expect(flashed()[0]).toEqual(['error', 'no access']);

            $page.find('[data-role="refresh"]').trigger('click');
            ajax.requests[1].resolve({success: false});
            expect(flashed()[1]).toEqual(['error', 'aaxis.devtools.mongodb_viewer.overview_error']);
        });

        it('flashes the request error when the overview call fails', () => {
            create();
            ajax.last('/overview').reject({message: 'server down'});
            expect(flashed()[0]).toEqual(['error', 'server down']);
        });

        it('reloads on refresh and forgets cached collections', () => {
            boot();
            dbRow('shop').trigger('click');
            ajax.last('/collections').resolve({success: true, collections: [{name: 'orders', count: 4}]});

            $page.find('[data-role="refresh"]').trigger('click');
            ajax.requests[ajax.requests.length - 1].resolve(OVERVIEW);
            dbRow('shop').trigger('click'); // collapse
            dbRow('shop').trigger('click'); // expand again: cache was dropped, so it is fetched again

            expect(ajax.to('/collections')).toHaveLength(2);
        });
    });

    describe('collections', () => {
        it('loads and lists the collections of an expanded database', () => {
            boot();
            dbRow('shop').trigger('click');

            expect(ajax.last('/collections').options).toMatchObject({
                method: 'GET',
                data: {connection: 'default', db: 'shop'}
            });
            ajax.last('/collections').resolve({success: true, collections: [{name: 'orders', count: 4}, {name: 'users', count: 9}]});

            expect(dbRow('shop').find('.fa').hasClass('fa-caret-down')).toBe(true);
            const names = $page.find('.aaxis-mongo__collection-name').map((_: number, el: HTMLElement) => el.textContent).get();
            expect(names).toEqual(['orders', 'users']);
            expect($page.find('.aaxis-mongo__collection-name').first().attr('title')).toBe('orders');
        });

        it('keeps same-named databases of different connections apart', () => {
            boot({success: true, connections: [
                {connection: 'default', server: {}, databases: [{db: 'shop', collections: 1}]},
                {connection: 'archive', server: {}, databases: [{db: 'shop', collections: 1}]}
            ]});
            const $rows = $page.find('[data-role="db"][data-db="shop"]');

            $rows.eq(1).trigger('click');
            ajax.last('/collections').resolve({success: true, collections: [{name: 'old_orders', count: 1}]});

            expect(ajax.last('/collections').options.data).toEqual({connection: 'archive', db: 'shop'});
            expect($page.find('[data-role="db"][data-connection="default"] .fa').hasClass('fa-caret-right')).toBe(true);
            expect($page.find('[data-role="db"][data-connection="archive"] .fa').hasClass('fa-caret-down')).toBe(true);
            expect($page.find('.aaxis-mongo__collection')).toHaveLength(1);
            expect($page.find('[data-role="db"][data-connection="default"]').parent().find('.aaxis-mongo__collection')).toHaveLength(0);
        });

        it('notes an empty database', () => {
            boot();
            dbRow('logs').trigger('click');
            ajax.last('/collections').resolve({success: true});

            expect($page.find('.aaxis-mongo__collections').text()).toBe('aaxis.devtools.mongodb_viewer.no_collections');
        });

        it('collapses on the second click without asking again', () => {
            boot();
            dbRow('shop').trigger('click');
            ajax.last('/collections').resolve({success: true, collections: [{name: 'orders', count: 4}]});

            dbRow('shop').trigger('click');
            expect($page.find('.aaxis-mongo__collections')).toHaveLength(0);

            dbRow('shop').trigger('click');
            expect($page.find('.aaxis-mongo__collection')).toHaveLength(1);
            expect(ajax.to('/collections')).toHaveLength(1);
        });

        it('flashes when the collections call reports failure or breaks', () => {
            boot();
            dbRow('shop').trigger('click');
            ajax.last('/collections').resolve({success: false, message: 'denied'});
            expect(flashed().pop()).toEqual(['error', 'denied']);

            dbRow('logs').trigger('click');
            ajax.last('/collections').resolve({success: false});
            expect(flashed().pop()).toEqual(['error', 'aaxis.devtools.mongodb_viewer.collections_error']);

            dbRow('logs').trigger('click'); // collapse
            dbRow('logs').trigger('click'); // expand again, not cached
            ajax.last('/collections').reject(undefined);
            expect(flashed().pop()).toEqual(['error', 'aaxis.devtools.mongodb_viewer.collections_error']);
        });
    });

    describe('documents', () => {
        function openCollection(): void {
            boot();
            dbRow('shop').trigger('click');
            ajax.last('/collections').resolve({success: true, collections: [{name: 'orders', count: 4}]});
            $page.find('[data-role="collection"]').trigger('click');
        }

        it('selecting a collection marks it active, resets the doc pane and queries page one', () => {
            openCollection();

            expect($page.find('[data-role="collection"]').hasClass('is-active')).toBe(true);
            expect(dbRow('shop').hasClass('is-active')).toBe(true);
            expect($page.find('[data-role="doc"]').text()).toBe('aaxis.devtools.mongodb_viewer.select_doc');
            expect(ajax.last('/documents').options).toMatchObject({
                method: 'GET',
                data: {connection: 'default', db: 'shop', collection: 'orders', filter: '', skip: 0, limit: 20}
            });
        });

        it('renders documents with their ids, snippets and the total', () => {
            openCollection();
            ajax.last('/documents').resolve({
                success: true,
                total: 4,
                documents: [
                    {_id: {$oid: 'abc123'}, name: 'first'},
                    {_id: 7, name: 'second'},
                    {_id: {a: 1}},
                    {name: 'no id'}
                ]
            });

            expect($page.find('.aaxis-mongo__meta').text()).toBe('aaxis.devtools.mongodb_viewer.total_label');
            const ids = $page.find('.aaxis-mongo__doc-id').map((_: number, el: HTMLElement) => el.textContent).get();
            expect(ids).toEqual(['abc123', '7', '{"a":1}', '(no _id)']);
            const snippets = $page.find('.aaxis-mongo__doc-snippet').map((_: number, el: HTMLElement) => el.textContent).get();
            expect(snippets).toEqual([' {"name":"first"}', ' {"name":"second"}', ' {}', ' {"name":"no id"}']);
        });

        it('truncates long snippets to 90 characters plus an ellipsis', () => {
            openCollection();
            ajax.last('/documents').resolve({success: true, total: 1, documents: [{_id: 1, blob: 'x'.repeat(200)}]});

            const snippet = $page.find('.aaxis-mongo__doc-snippet').text();
            expect(snippet).toBe(' ' + JSON.stringify({blob: 'x'.repeat(200)}).slice(0, 90) + '…');
        });

        it('notes an empty result', () => {
            openCollection();
            ajax.last('/documents').resolve({success: true});

            expect($page.find('[data-role="docs"] .aaxis-mongo__empty').text()).toBe('aaxis.devtools.mongodb_viewer.no_documents');
            expect($page.find('[data-role="load-more"]')).toHaveLength(0);
        });

        it('offers "load more" while documents remain and appends the next page from the current offset', () => {
            openCollection();
            ajax.last('/documents').resolve({success: true, total: 3, documents: [{_id: 1}, {_id: 2}]});
            expect($page.find('[data-role="load-more"]')).toHaveLength(1);

            $page.find('[data-role="load-more"]').trigger('click');
            expect(ajax.last('/documents').options.data).toMatchObject({skip: 2, limit: 20});
            ajax.last('/documents').resolve({success: true, total: 3, documents: [{_id: 3}]});

            expect($page.find('[data-role="doc-row"]')).toHaveLength(3);
            expect($page.find('[data-role="load-more"]')).toHaveLength(0);
        });

        it('does not start a second load while one is in flight', () => {
            openCollection();
            $page.find('[data-role="find"]').trigger('click');

            expect(ajax.to('/documents')).toHaveLength(1);
        });

        it('lets a new load start after the previous one failed', () => {
            openCollection();
            ajax.last('/documents').reject({message: 'bad filter'});
            expect(flashed().pop()).toEqual(['error', 'bad filter']);

            $page.find('[data-role="find"]').trigger('click');
            expect(ajax.to('/documents')).toHaveLength(2);
        });

        it('sends the filter text and restarts from the first page on find', () => {
            openCollection();
            ajax.last('/documents').resolve({success: true, total: 2, documents: [{_id: 1}]});

            $page.find('[data-role="filter"]').val('{"a":1}');
            $page.find('[data-role="find"]').trigger('click');

            expect(ajax.last('/documents').options.data).toMatchObject({filter: '{"a":1}', skip: 0});
        });

        it('finds on Enter in the filter and ignores other keys', () => {
            openCollection();
            ajax.last('/documents').resolve({success: true, total: 0, documents: []});

            $page.find('[data-role="filter"]').trigger($.Event('keydown', {keyCode: 65}));
            expect(ajax.to('/documents')).toHaveLength(1);

            const enter = $.Event('keydown', {keyCode: 13});
            $page.find('[data-role="filter"]').trigger(enter);
            expect(enter.isDefaultPrevented()).toBe(true);
            expect(ajax.to('/documents')).toHaveLength(2);
        });

        it('flashes when the documents call reports failure', () => {
            openCollection();
            ajax.last('/documents').resolve({success: false, message: 'bad json'});
            expect(flashed().pop()).toEqual(['error', 'bad json']);

            $page.find('[data-role="find"]').trigger('click');
            ajax.last('/documents').resolve({success: false});
            expect(flashed().pop()).toEqual(['error', 'aaxis.devtools.mongodb_viewer.documents_error']);
        });

        it('does nothing on find before a collection is chosen', () => {
            boot();
            $page.find('[data-role="find"]').trigger('click');

            expect(ajax.to('/documents')).toHaveLength(0);
        });

        it('shows the clicked document as highlighted JSON and marks its row active', () => {
            openCollection();
            const doc = {_id: 1, nested: {a: [1, 2]}};
            ajax.last('/documents').resolve({success: true, total: 1, documents: [doc]});

            $page.find('[data-role="doc-row"]').trigger('click');

            expect($page.find('[data-role="doc-row"]').hasClass('is-active')).toBe(true);
            const pre = $page.find('[data-role="doc"] pre.aaxis-mongo__pre');
            expect(pre).toHaveLength(1);
            expect((CodeMirror as any).runMode).toHaveBeenCalledWith(
                JSON.stringify(doc, null, 2), {name: 'javascript', json: true}, pre.get(0)
            );
        });

        it('falls back to plain text when highlighting throws', () => {
            openCollection();
            ajax.last('/documents').resolve({success: true, total: 1, documents: [{_id: 1}]});
            (CodeMirror as any).runMode.mockImplementationOnce(() => {
                throw new Error('no mode');
            });

            $page.find('[data-role="doc-row"]').trigger('click');

            expect($page.find('[data-role="doc"] pre').text()).toBe(JSON.stringify({_id: 1}, null, 2));
        });
    });

    it('opens the help block in a close-only modal', () => {
        boot();
        const click = $.Event('click');
        $page.find('[data-role="help"]').trigger(click);

        expect(click.isDefaultPrevented()).toBe(true);
        expect(Modal.instances[0].options).toEqual({
            title: 'aaxis.devtools.mongodb_viewer.help',
            content: '<em>mongo help</em>',
            allowOk: false,
            cancelText: 'Close'
        });
    });

    it('unbinds on dispose, idempotently', () => {
        const component = boot();
        component.dispose();

        dbRow('shop').trigger('click');
        expect(ajax.to('/collections')).toHaveLength(0);
        expect(component.disposed).toBe(true);
        expect(() => component.dispose()).not.toThrow();
    });
});
