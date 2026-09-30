import $ from 'jquery';
import CodeMirror from 'codemirror';
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import Modal from 'oroui/js/modal';
import StorageBrowserComponent from '../../../../Resources/js-src/app/components/storage-browser-component';
import {AjaxRecorder, installAjax} from '../../helpers/ajax';

const flash = () => (messenger.notificationFlashMessage as any).mock.calls;

const FIXTURE = `
<div id="page">
    <button data-role="help">?</button>
    <input type="text" data-role="path" value="">
    <button data-role="filter-toggle">filter</button>
    <input type="text" data-role="filter" hidden>
    <div data-role="tabs"></div>
    <div data-role="content" tabindex="0"></div>
    <button data-role="create-folder">new folder</button>
    <button data-role="upload">upload</button>
    <input type="file" data-role="upload-input">
    <div data-role="help-content"><p>storage help</p></div>
</div>`;

const ALL_COLUMNS = {
    type: true, created: true, modified: true, ownerUser: true, ownerGroup: true, filename: true, filesize: true, preview: true
};

function entry(name: string, type: 'dir' | 'file' = 'file', extra: Record<string, unknown> = {}): any {
    return {
        name,
        type,
        path: '/var/www/' + name,
        size: 0,
        sizeFormatted: '',
        created: 0,
        modified: 0,
        ownerUser: 'root',
        ownerGroup: 'wheel',
        readable: true,
        ...extra
    };
}

function listing(entries: any[], extra: Record<string, unknown> = {}): any {
    return {success: true, path: '/var/www', parent: '/var', entries, ...extra};
}

const CREATED = 1700000000;
const MODIFIED = 1700003600;

const DEFAULT_ENTRIES = [
    entry('src', 'dir', {size: 4096, created: CREATED, modified: MODIFIED, ownerUser: 'www', ownerGroup: 'staff'}),
    entry('index.php', 'file', {size: 1500, sizeFormatted: '1.5 KB', created: CREATED, modified: MODIFIED}),
    entry('a.txt', 'file', {size: 10, sizeFormatted: '10 B', created: CREATED + 5, modified: MODIFIED - 5, ownerUser: 'zed', ownerGroup: 'adm'})
];

describe('StorageBrowserComponent', () => {
    let $page: any;
    let ajax: AjaxRecorder;

    beforeEach(() => {
        Modal.instances.length = 0;
        ajax = installAjax();
        $page = $(FIXTURE).appendTo(document.body);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        document.cookie = '_csrf=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
        delete (Element.prototype as any).scrollIntoView;
    });

    function create(options: Record<string, unknown> = {}): any {
        return new (StorageBrowserComponent as any)({
            _sourceElement: $page,
            listUrl: '/list',
            previewUrl: '/preview',
            rawUrl: '/raw',
            downloadUrl: '/download',
            basePath: '/var/www',
            columns: ALL_COLUMNS,
            helpTitle: 'Storage help',
            ...options
        });
    }

    /** Boots the browser with the default listing rendered. */
    function boot(options: Record<string, unknown> = {}, response: any = listing(DEFAULT_ENTRIES)): any {
        const component = create(options);
        ajax.last('/list').resolve(response);
        return component;
    }

    const content = () => $page.find('[data-role="content"]');
    const rowNames = () => $page.find('[data-role="row"]').map((_: number, tr: HTMLElement) => tr.getAttribute('data-name')).get();
    const row = (name: string) => $page.find('[data-role="row"][data-name="' + name + '"]');
    const headerLabels = () => $page.find('thead th').map((_: number, th: HTMLElement) => th.textContent).get();
    const tabTitles = () => $page.find('[data-role="tab"] .aaxis-fs__tab-title').map((_: number, e: HTMLElement) => e.textContent).get();
    const activeTab = () => $page.find('[data-role="tab"].is-active .aaxis-fs__tab-title').text();
    const dblclick = (target: any) => target.trigger('dblclick');

    describe('loading a listing', () => {
        it('requests the base path on start, showing a spinner and the files tab', () => {
            create();

            expect(ajax.last('/list').options).toMatchObject({method: 'GET', data: {path: '/var/www'}});
            expect(content().find('.aaxis-fs__loading .fa-spinner')).toHaveLength(1);
            expect(tabTitles()).toEqual(['aaxis.devtools.storage_browser.files_tab']);
            expect($page.find('[data-role="tab-close"]')).toHaveLength(0);
        });

        it('renders the listing and updates the path bar', () => {
            boot();

            expect($page.find('[data-role="path"]').val()).toBe('/var/www');
            expect(content().find('table.aaxis-fs__grid')).toHaveLength(1);
        });

        it('flashes the server message and keeps the current content when the listing reports failure', () => {
            boot();
            $page.find('[data-role="path"]').val('/nope').trigger($.Event('keydown', {keyCode: 13}));
            ajax.last('/list').resolve({success: false, message: 'not found'});

            expect(flash()).toEqual([['error', 'not found']]);
            expect(rowNames()).toContain('src'); // the previous listing is shown again
        });

        it('uses the generic message when the failure carries none', () => {
            boot();
            $page.find('[data-role="path"]').val('/nope').trigger($.Event('keydown', {keyCode: 13}));
            ajax.last('/list').resolve({success: false});
            $page.find('[data-role="path"]').val('/nope').trigger($.Event('keydown', {keyCode: 13}));
            ajax.last('/list').reject(undefined);

            expect(flash()).toEqual([
                ['error', 'aaxis.devtools.storage_browser.list_error'],
                ['error', 'aaxis.devtools.storage_browser.list_error']
            ]);
        });

        it('flashes the server message when the request fails', () => {
            create();
            ajax.last('/list').reject({message: 'forbidden'});

            expect(flash()).toEqual([['error', 'forbidden']]);
        });

        it('tolerates a response without entries', () => {
            boot({}, {success: true, path: '/var/www', parent: null});

            expect($page.find('[data-role="row"]')).toHaveLength(0);
            expect(content().find('table')).toHaveLength(1);
        });

        describe('when the very first listing fails and a config page exists', () => {
            let original: Location;

            beforeEach(() => {
                original = window.location;
                Object.defineProperty(window, 'location', {value: {href: 'http://localhost/', protocol: 'http:'}, configurable: true});
            });

            afterEach(() => {
                Object.defineProperty(window, 'location', {value: original, configurable: true});
            });

            it('blocks with a dialog and only navigates to the configuration on confirm', () => {
                create({configUrl: '/config'});
                ajax.last('/list').resolve({success: false, message: 'no credentials'});

                const modal = Modal.instances[0];
                expect(modal.options).toMatchObject({
                    title: 'aaxis.devtools.storage_browser.connect_failed_title',
                    content: 'no credentials aaxis.devtools.storage_browser.connect_failed_body',
                    okText: 'aaxis.devtools.storage_browser.go_to_config',
                    allowCancel: false
                });
                expect(modal.open).toHaveBeenCalled();
                expect(flash()).toEqual([]);
                expect(window.location.href).toBe('http://localhost/');

                modal.emit('ok');
                expect(window.location.href).toBe('/config');
            });

            it('shows just the fixed text when the server gave no detail', () => {
                create({configUrl: '/config'});
                ajax.last('/list').resolve({success: false});

                expect(Modal.instances[0].options.content).toBe('aaxis.devtools.storage_browser.connect_failed_body');
            });

            it('prefixes the generic list error when the request itself failed', () => {
                create({configUrl: '/config'});
                ajax.last('/list').reject({});

                expect(Modal.instances[0].options.content)
                    .toBe('aaxis.devtools.storage_browser.list_error aaxis.devtools.storage_browser.connect_failed_body');
            });

            it('does not block once a listing has loaded', () => {
                boot({configUrl: '/config'});
                $page.find('[data-role="path"]').val('/x').trigger($.Event('keydown', {keyCode: 13}));
                ajax.last('/list').resolve({success: false, message: 'gone'});

                expect(Modal.instances).toHaveLength(0);
                expect(flash()).toEqual([['error', 'gone']]);
            });
        });
    });

    describe('table', () => {
        it('shows every configured column with sort headers and a trailing actions cell', () => {
            boot();

            expect(headerLabels()).toEqual([
                'aaxis.devtools.storage_browser.col.type',
                'aaxis.devtools.storage_browser.col.name',
                'aaxis.devtools.storage_browser.col.size',
                'aaxis.devtools.storage_browser.col.modified',
                'aaxis.devtools.storage_browser.col.created',
                'aaxis.devtools.storage_browser.col.owner',
                'aaxis.devtools.storage_browser.col.group',
                ''
            ]);
            expect($page.find('thead th[data-role="sort"]').map((_: number, th: HTMLElement) => th.getAttribute('data-sort')).get())
                .toEqual(['type', 'name', 'size', 'modified', 'created', 'ownerUser', 'ownerGroup']);
        });

        it('leaves out the columns that are switched off', () => {
            boot({columns: {...ALL_COLUMNS, type: false, created: false, modified: false, ownerUser: false, ownerGroup: false, filesize: false}});

            expect(headerLabels()).toEqual(['aaxis.devtools.storage_browser.col.name', '']);
            expect(row('index.php').find('td')).toHaveLength(2);
        });

        it('pins ".." on top when there is a parent, styled as a level-up row', () => {
            boot();

            expect(rowNames()[0]).toBe('..');
            const $parent = row('..');
            expect($parent.attr('data-type')).toBe('parent');
            expect($parent.attr('data-path')).toBe('/var');
            expect($parent.find('.fa-level-up')).toHaveLength(1);
            expect($parent.find('td').eq(0).text()).toBe(''); // no type label
            expect($parent.find('.aaxis-fs__col-actions button')).toHaveLength(0);
        });

        it('omits ".." at the storage root', () => {
            boot({}, listing(DEFAULT_ENTRIES, {parent: null}));

            expect(rowNames()).not.toContain('..');
        });

        it('renders entry details: type label, icon, size, dates and owners', () => {
            boot();

            const $dir = row('src');
            expect($dir.find('td').eq(0).text()).toBe('aaxis.devtools.storage_browser.type.folder');
            expect($dir.find('.fa-folder')).toHaveLength(1);
            expect($dir.find('.aaxis-fs__num').text()).toBe(''); // sizes only for files
            expect($dir.find('td').eq(3).text()).toBe(new Date(MODIFIED * 1000).toLocaleString());
            expect($dir.find('td').eq(4).text()).toBe(new Date(CREATED * 1000).toLocaleString());
            expect($dir.find('td').eq(5).text()).toBe('www');
            expect($dir.find('td').eq(6).text()).toBe('staff');

            const $file = row('index.php');
            expect($file.find('td').eq(0).text()).toBe('aaxis.devtools.storage_browser.type.file');
            expect($file.find('.fa-file-o')).toHaveLength(1);
            expect($file.find('.aaxis-fs__num').text()).toBe('1.5 KB');
        });

        it('shows an empty date for a zero timestamp', () => {
            boot({}, listing([entry('x.txt')]));

            expect(row('x.txt').find('td').eq(3).text()).toBe('');
            expect(row('x.txt').find('td').eq(4).text()).toBe('');
        });

        it('hides entry names when the filename column is off, except for ".."', () => {
            boot({columns: {...ALL_COLUMNS, filename: false}});

            expect(row('src').find('.aaxis-fs__entry-name')).toHaveLength(0);
            expect(row('..').find('.aaxis-fs__entry-name').text()).toBe('..');
        });

        it('gives files preview and download buttons, dirs none, and delete only when writable', () => {
            boot();

            const $file = row('index.php');
            expect($file.find('[data-role="preview-btn"]')).toHaveLength(1);
            expect($file.find('[data-role="download-btn"]')).toHaveLength(1);
            expect($file.find('[data-role="delete-btn"]')).toHaveLength(0);
            expect(row('src').find('button')).toHaveLength(0);
        });

        it('gives each action button its label and the data attributes its handler reads', () => {
            boot({deleteUrl: '/delete'});

            const attrs = (button: any) => Object.fromEntries(
                Array.from(button.get(0).attributes as NamedNodeMap).map(attribute => [attribute.name, attribute.value])
            );
            expect(attrs(row('index.php').find('[data-role="preview-btn"]'))).toEqual({
                type: 'button',
                class: 'aaxis-fs__icon-btn',
                'data-role': 'preview-btn',
                'data-path': '/var/www/index.php',
                'data-name': 'index.php',
                title: 'aaxis.devtools.storage_browser.preview',
                'aria-label': 'aaxis.devtools.storage_browser.preview'
            });
            expect(attrs(row('index.php').find('[data-role="download-btn"]'))).toEqual({
                type: 'button',
                class: 'aaxis-fs__icon-btn',
                'data-role': 'download-btn',
                'data-path': '/var/www/index.php',
                title: 'aaxis.devtools.storage_browser.download',
                'aria-label': 'aaxis.devtools.storage_browser.download'
            });
            expect(attrs(row('src').find('[data-role="delete-btn"]'))).toEqual({
                type: 'button',
                class: 'aaxis-fs__icon-btn aaxis-fs__icon-btn--danger',
                'data-role': 'delete-btn',
                'data-path': '/var/www/src',
                'data-type': 'dir',
                'data-name': 'src',
                title: 'aaxis.devtools.storage_browser.delete',
                'aria-label': 'aaxis.devtools.storage_browser.delete'
            });
            expect(row('index.php').find('.fa-eye, .fa-download')).toHaveLength(2);
            expect(row('src').find('.fa-trash-o')).toHaveLength(1);
        });

        it('omits the preview button when previews are off', () => {
            boot({columns: {...ALL_COLUMNS, preview: false}});

            expect(row('index.php').find('[data-role="preview-btn"]')).toHaveLength(0);
            expect(row('index.php').find('[data-role="download-btn"]')).toHaveLength(1);
        });

        it('adds a delete button to files and dirs (never "..") when writable', () => {
            boot({deleteUrl: '/delete'});

            expect(row('src').find('[data-role="delete-btn"]').attr('data-type')).toBe('dir');
            expect(row('index.php').find('[data-role="delete-btn"]').attr('data-type')).toBe('file');
            expect(row('..').find('[data-role="delete-btn"]')).toHaveLength(0);
        });

        it('is read-only without a delete URL or with readOnly', () => {
            boot({readOnly: true, deleteUrl: '/delete'});
            expect($page.find('[data-role="delete-btn"]')).toHaveLength(0);
        });
    });

    describe('sorting', () => {
        const sort = (key: string) => $page.find('thead th[data-sort="' + key + '"]').trigger('click');
        const order = () => rowNames().filter(name => name !== '..');

        it('defaults to folders first, then by name', () => {
            boot({}, listing([entry('b.txt'), entry('z', 'dir'), entry('a.txt'), entry('c', 'dir')]));

            expect(order()).toEqual(['c', 'z', 'a.txt', 'b.txt']);
        });

        it('sorts by name and reverses on a second click, showing a caret', () => {
            boot();
            sort('name');
            expect(order()).toEqual(['a.txt', 'index.php', 'src']);
            expect($page.find('thead .aaxis-fs__sort-caret.fa-caret-up')).toHaveLength(1);

            sort('name');
            expect(order()).toEqual(['src', 'index.php', 'a.txt']);
            expect($page.find('thead .fa-caret-down')).toHaveLength(1);
        });

        it('sorts by size', () => {
            boot();
            sort('size');

            expect(order()).toEqual(['a.txt', 'index.php', 'src']);
        });

        it('sorts by modified and created timestamps', () => {
            boot();
            sort('modified');
            expect(order()).toEqual(['a.txt', 'src', 'index.php']);

            sort('created');
            expect(order()).toEqual(['src', 'index.php', 'a.txt']);
        });

        it('sorts by owner and group as text', () => {
            boot();
            sort('ownerUser');
            expect(order()).toEqual(['index.php', 'src', 'a.txt']);

            sort('ownerGroup');
            expect(order()).toEqual(['a.txt', 'src', 'index.php']);
        });

        it('switching column restarts ascending; the caret follows the sorted column', () => {
            boot();
            sort('name');
            sort('name');
            sort('size');

            expect($page.find('thead .fa-caret-up')).toHaveLength(1);
            expect($page.find('thead th[data-sort="size"] .fa-caret-up')).toHaveLength(1);
        });

        it('keeps ".." pinned on top whatever the order', () => {
            boot();
            sort('name');
            sort('name');

            expect(rowNames()[0]).toBe('..');
        });
    });

    describe('filter', () => {
        it('reveals the filter input on toggle and focuses it', () => {
            boot();
            const focus = vi.fn();
            $page.find('[data-role="filter"]').on('focus', focus);

            const click = $.Event('click');
            $page.find('[data-role="filter-toggle"]').trigger(click);

            expect(click.isDefaultPrevented()).toBe(true);
            expect($page.find('[data-role="filter"]').prop('hidden')).toBe(false);
            expect(focus).toHaveBeenCalled();
        });

        it('narrows the rows by name (case-insensitive), keeping the parent row', () => {
            boot();
            $page.find('[data-role="filter-toggle"]').trigger('click');
            $page.find('[data-role="filter"]').val('INDEX').trigger('input');

            expect(rowNames()).toEqual(['..', 'index.php']);
        });

        it('hiding the filter clears it and restores the rows', () => {
            boot();
            $page.find('[data-role="filter-toggle"]').trigger('click');
            $page.find('[data-role="filter"]').val('index').trigger('input');

            $page.find('[data-role="filter-toggle"]').trigger('click');

            expect($page.find('[data-role="filter"]').prop('hidden')).toBe(true);
            expect($page.find('[data-role="filter"]').val()).toBe('');
            expect(rowNames()).toEqual(['..', 'src', 'a.txt', 'index.php']);
        });

        it('hiding an already empty filter does not repaint', () => {
            const component = boot();
            $page.find('[data-role="filter-toggle"]').trigger('click');
            const spy = vi.spyOn(component, 'renderListing');

            $page.find('[data-role="filter-toggle"]').trigger('click');

            expect(spy).not.toHaveBeenCalled();
        });
    });

    describe('navigation', () => {
        const enter = (value: string) => $page.find('[data-role="path"]').val(value).trigger($.Event('keydown', {keyCode: 13}));

        it('navigates to the typed, trimmed path on Enter', () => {
            boot();
            const event = $.Event('keydown', {keyCode: 13});
            $page.find('[data-role="path"]').val('  /var/log ').trigger(event);

            expect(event.isDefaultPrevented()).toBe(true);
            expect(ajax.last('/list').options.data).toEqual({path: '/var/log'});
        });

        it('ignores other keys', () => {
            boot();
            $page.find('[data-role="path"]').val('/x').trigger($.Event('keydown', {keyCode: 65}));

            expect(ajax.to('/list')).toHaveLength(1);
        });

        it.each(['../etc', '/var/../etc', '..\\etc', 'a//..'])('rejects the parent-directory path "%s"', path => {
            boot();
            enter(path);

            expect(ajax.to('/list')).toHaveLength(1);
            expect(flash()).toEqual([['warning', 'aaxis.devtools.storage_browser.dotdot_error']]);
        });

        it('accepts names that merely contain dots', () => {
            boot();
            enter('/var/my..file/.hidden');

            expect(ajax.to('/list')).toHaveLength(2);
        });

        it('double-clicking a folder opens it', () => {
            boot();
            dblclick(row('src'));

            expect(ajax.last('/list').options.data).toEqual({path: '/var/www/src'});
        });

        it('double-clicking ".." goes to the parent, even when it is the storage root ""', () => {
            boot({}, listing(DEFAULT_ENTRIES, {parent: ''}));
            dblclick(row('..'));

            expect(ajax.last('/list').options.data).toEqual({path: ''});
        });

        it('double-clicking a file previews it', () => {
            boot();
            dblclick(row('index.php'));

            expect(ajax.last('/preview').options.data).toEqual({path: '/var/www/index.php'});
        });

        it('ignores a double-click on an action button or on a stale index', () => {
            const component = boot();
            row('index.php').find('[data-role="download-btn"]').trigger('dblclick');
            expect(ajax.to('/preview')).toHaveLength(0);

            component.onRowDblClick({target: document.body, currentTarget: $('<tr data-index="99"/>').get(0)});
            expect(ajax.to('/list')).toHaveLength(1);
        });
    });

    describe('selection and keyboard', () => {
        const selected = () => $page.find('[data-role="row"].is-selected').map((_: number, tr: HTMLElement) => tr.getAttribute('data-name')).get();
        const key = (code: number) => {
            const event = $.Event('keydown', {keyCode: code});
            content().trigger(event);
            return event;
        };

        it('selects the first row initially', () => {
            boot();

            expect(selected()).toEqual(['..']);
        });

        it('a click selects a row and focuses the list', () => {
            boot();
            const focus = vi.fn();
            content().on('focus', focus);
            row('index.php').trigger('click');

            expect(selected()).toEqual(['index.php']);
            expect(focus).toHaveBeenCalled();
        });

        it('a click routed to the row from inside an action button leaves the selection alone', () => {
            const component = boot();
            const button = row('index.php').find('[data-role="download-btn"]').get(0);

            component.onRowClick({target: button, currentTarget: row('index.php').get(0)});

            expect(selected()).toEqual(['..']);
        });

        it('has no rows to build before a listing has loaded', () => {
            const component = create();

            expect(component.buildRows()).toEqual([]);
        });

        it('a click on an action button leaves the selection alone', () => {
            vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
            boot();
            row('index.php').find('[data-role="download-btn"]').trigger('click');

            expect(selected()).toEqual(['..']);
        });

        it('arrow keys move the selection within bounds', () => {
            boot();
            const down = key(40);
            expect(down.isDefaultPrevented()).toBe(true);
            expect(selected()).toEqual([rowNames()[1]]);

            key(40); key(40); key(40); key(40);
            expect(selected()).toEqual([rowNames()[3]]);

            key(38);
            expect(selected()).toEqual([rowNames()[2]]);
            key(38); key(38); key(38);
            expect(selected()).toEqual(['..']);
        });

        it('Enter opens the selected entry', () => {
            boot();
            key(40); // first real row
            const name = rowNames()[1];
            const event = key(13);

            expect(event.isDefaultPrevented()).toBe(true);
            expect(name).toBe('src');
            expect(ajax.last('/list').options.data).toEqual({path: '/var/www/src'});
        });

        it('other keys are ignored', () => {
            boot();
            const event = key(65);

            expect(event.isDefaultPrevented()).toBe(false);
            expect(selected()).toEqual(['..']);
        });

        it('ignores keys while a preview tab is active or the list is empty', () => {
            boot();
            dblclick(row('index.php'));
            ajax.last('/preview').resolve({success: true, content: 'x'});
            expect(key(40).isDefaultPrevented()).toBe(false);
        });

        it('ignores keys when there are no rows', () => {
            boot({}, listing([], {parent: null}));

            expect(key(40).isDefaultPrevented()).toBe(false);
        });

        it('clamps the selection when the filter leaves fewer rows', () => {
            boot();
            row('a.txt').trigger('click');
            $page.find('[data-role="filter-toggle"]').trigger('click');
            $page.find('[data-role="filter"]').val('src').trigger('input');

            expect(selected()).toEqual(['..']);
        });

        it('scrolls the selected row into view when the browser supports it', () => {
            const scrollIntoView = vi.fn();
            (Element.prototype as any).scrollIntoView = scrollIntoView;
            boot();
            key(40);

            expect(scrollIntoView).toHaveBeenLastCalledWith({block: 'nearest'});
        });
    });

    describe('previewing files', () => {
        const open = (name: string) => dblclick(row(name));
        const listOf = (...entries: any[]) => listing(entries);

        it('opens a loading tab, then shows the text with syntax highlighting for known types', () => {
            boot({}, listOf(entry('conf.yml')));
            open('conf.yml');

            expect(tabTitles()).toEqual(['aaxis.devtools.storage_browser.files_tab', 'conf.yml']);
            expect(activeTab()).toBe('conf.yml');
            expect(content().find('.aaxis-fs__loading')).toHaveLength(1);
            expect($page.find('[data-role="tab-close"]')).toHaveLength(1);

            ajax.last('/preview').resolve({success: true, content: 'a: 1'});

            const pre = content().find('pre.aaxis-fs__code').get(0);
            expect((CodeMirror as any).runMode).toHaveBeenCalledWith('a: 1', 'yaml', pre);
        });

        it('shows plain text for unknown extensions', () => {
            boot({}, listOf(entry('notes.xyz')));
            open('notes.xyz');
            ajax.last('/preview').resolve({success: true, content: 'plain <b>text</b>'});

            expect(content().find('pre').text()).toBe('plain <b>text</b>');
            expect((CodeMirror as any).runMode).not.toHaveBeenCalled();
        });

        it('treats a missing content as empty', () => {
            boot({}, listOf(entry('empty.xyz')));
            open('empty.xyz');
            ajax.last('/preview').resolve({success: true});

            expect(content().find('pre').text()).toBe('');
        });

        it('maps extensions to CodeMirror modes case-insensitively', () => {
            boot({}, listOf(entry('a.JSON'), entry('b.ts'), entry('c.php'), entry('d.sql')));
            const modes: Record<string, unknown> = {};
            ['a.JSON', 'b.ts', 'c.php', 'd.sql'].forEach(name => {
                open(name);
                ajax.last('/preview').resolve({success: true, content: '{}'});
                modes[name] = (CodeMirror as any).runMode.mock.calls.pop()[1];
                $page.find('[data-role="tab"]').eq(0).trigger('click');
            });

            expect(modes).toEqual({
                'a.JSON': {name: 'javascript', json: true},
                'b.ts': {name: 'javascript', typescript: true},
                'c.php': 'application/x-httpd-php',
                'd.sql': 'text/x-sql'
            });
        });

        it('pretty-prints JSON files, and leaves invalid JSON untouched', () => {
            boot({}, listOf(entry('ok.json'), entry('bad.json')));
            open('ok.json');
            ajax.last('/preview').resolve({success: true, content: '{"a":[1,2]}'});
            expect((CodeMirror as any).runMode.mock.calls.pop()[0]).toBe(JSON.stringify({a: [1, 2]}, null, 2));

            $page.find('[data-role="tab"]').eq(0).trigger('click');
            open('bad.json');
            ajax.last('/preview').resolve({success: true, content: '{oops'});
            expect((CodeMirror as any).runMode.mock.calls.pop()[0]).toBe('{oops');
        });

        it('falls back to plain text when highlighting throws', () => {
            boot({}, listOf(entry('conf.yml')));
            (CodeMirror as any).runMode.mockImplementationOnce(() => {
                throw new Error('no mode');
            });
            open('conf.yml');
            ajax.last('/preview').resolve({success: true, content: 'a: 1'});

            expect(content().find('pre').text()).toBe('a: 1');
        });

        it('warns above truncated previews', () => {
            boot({}, listOf(entry('big.xyz')));
            open('big.xyz');
            ajax.last('/preview').resolve({success: true, content: 'x', truncated: true});

            expect(content().find('.aaxis-fs__preview-warn').text()).toBe('aaxis.devtools.storage_browser.truncated');
        });

        it('does not warn for complete previews', () => {
            boot({}, listOf(entry('small.xyz')));
            open('small.xyz');
            ajax.last('/preview').resolve({success: true, content: 'x'});

            expect(content().find('.aaxis-fs__preview-warn')).toHaveLength(0);
        });

        it('closes the tab and flashes when the preview fails, with the server text or a fallback', () => {
            boot({}, listOf(entry('a.xyz'), entry('b.xyz'), entry('c.xyz')));
            open('a.xyz');
            ajax.last('/preview').resolve({success: false, message: 'unreadable'});
            expect(flash().pop()).toEqual(['error', 'unreadable']);
            expect(tabTitles()).toEqual(['aaxis.devtools.storage_browser.files_tab']);
            expect(activeTab()).toBe('aaxis.devtools.storage_browser.files_tab');

            open('b.xyz');
            ajax.last('/preview').resolve({success: false});
            expect(flash().pop()).toEqual(['error', 'aaxis.devtools.storage_browser.preview_error']);

            open('c.xyz');
            ajax.last('/preview').reject({message: 'denied'});
            expect(flash().pop()).toEqual(['error', 'denied']);
            open('c.xyz');
            ajax.last('/preview').reject(undefined);
            expect(flash().pop()).toEqual(['error', 'aaxis.devtools.storage_browser.preview_error']);
            expect(tabTitles()).toHaveLength(1);
        });

        it('never previews a binary file: it closes the tab, informs and downloads it', () => {
            const clicks: string[] = [];
            vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
                clicks.push(this.getAttribute('href') as string);
            });
            boot({}, listOf(entry('program.bin')));
            open('program.bin');
            ajax.last('/preview').resolve({success: true, binary: true});

            expect(tabTitles()).toHaveLength(1);
            expect(flash()).toEqual([['info', 'aaxis.devtools.storage_browser.binary_download']]);
            expect(clicks).toEqual(['/download?path=%2Fvar%2Fwww%2Fprogram.bin']);
        });

        it('ignores a response for a tab that has been closed meanwhile', () => {
            boot({}, listOf(entry('late.xyz')));
            open('late.xyz');
            $page.find('[data-role="tab-close"]').trigger('click');

            ajax.last('/preview').resolve({success: true, content: 'too late'});

            expect(tabTitles()).toHaveLength(1);
            expect(content().find('pre')).toHaveLength(0);
        });

        it('does not repaint the content when the finished preview is not the active tab', () => {
            boot({}, listOf(entry('slow.xyz')));
            open('slow.xyz');
            $page.find('[data-role="tab"]').eq(0).trigger('click');
            const table = content().find('table').get(0);
            ajax.last('/preview').resolve({success: true, content: 'later'});
            expect(content().find('table').get(0)).toBe(table); // the listing was not redrawn

            expect(content().find('pre')).toHaveLength(0);
            expect(content().find('table')).toHaveLength(1);
            $page.find('[data-role="tab"]').eq(1).trigger('click');
            expect(content().find('pre').text()).toBe('later');
        });

        it('opens the preview from the row button without selecting the row', () => {
            boot({}, listOf(entry('conf.yml')));
            const click = $.Event('click');
            row('conf.yml').find('[data-role="preview-btn"]').trigger(click);

            expect(click.isDefaultPrevented()).toBe(true);
            expect(ajax.last('/preview').options.data).toEqual({path: '/var/www/conf.yml'});
        });

        it('switches to the tab of an already previewed file instead of opening another', () => {
            boot({}, listOf(entry('conf.yml')));
            open('conf.yml');
            ajax.last('/preview').resolve({success: true, content: 'a: 1'});
            $page.find('[data-role="tab"]').eq(0).trigger('click');

            open('conf.yml');

            expect(tabTitles()).toHaveLength(2);
            expect(activeTab()).toBe('conf.yml');
            expect(ajax.to('/preview')).toHaveLength(1);
        });
    });

    describe('images and PDFs', () => {
        it.each(['logo.png', 'photo.JPG', 'pic.jpeg', 'a.gif', 'v.svg', 'w.webp', 'b.bmp', 'i.ico', 'x.avif'])('shows %s inline as an image', name => {
            boot({}, listing([entry(name)]));
            dblclick(row(name));

            expect(ajax.to('/preview')).toHaveLength(0);
            const $img = content().find('img.aaxis-fs__image');
            expect($img.attr('src')).toBe('/raw?path=' + encodeURIComponent('/var/www/' + name));
            expect($img.attr('alt')).toBe(name);
            expect(content().find('.aaxis-fs__preview-meta').text()).toBe('/var/www/' + name);
            expect($page.find('[data-role="tab"] .fa-picture-o')).toHaveLength(1);
        });

        it('removes the spinner once the image has loaded or failed', () => {
            boot({}, listing([entry('logo.png')]));
            dblclick(row('logo.png'));
            expect(content().find('.aaxis-fs__loading')).toHaveLength(1);

            content().find('img').trigger('load');
            expect(content().find('.aaxis-fs__loading')).toHaveLength(0);
        });

        it('shows a PDF in an iframe and removes the spinner on load', () => {
            boot({}, listing([entry('doc.pdf')]));
            dblclick(row('doc.pdf'));

            const $frame = content().find('iframe.aaxis-fs__pdf');
            expect($frame.attr('src')).toBe('/raw?path=' + encodeURIComponent('/var/www/doc.pdf'));
            expect($frame.attr('title')).toBe('doc.pdf');
            expect($page.find('[data-role="tab"] .fa-file-pdf-o')).toHaveLength(1);

            $frame.trigger('load');
            expect(content().find('.aaxis-fs__loading')).toHaveLength(0);
        });

        it('removes the spinner when the image fails to load', () => {
            boot({}, listing([entry('logo.png')]));
            dblclick(row('logo.png'));
            content().find('img').trigger('error');

            expect(content().find('.aaxis-fs__loading')).toHaveLength(0);
        });
    });

    describe('tabs', () => {
        function withTabs(): any {
            const component = boot({}, listing([entry('a.xyz'), entry('logo.png'), entry('doc.pdf'), entry('b.xyz')]));
            dblclick(row('a.xyz'));
            ajax.last('/preview').resolve({success: true, content: 'A'});
            $page.find('[data-role="tab"]').eq(0).trigger('click');
            dblclick(row('logo.png'));
            $page.find('[data-role="tab"]').eq(0).trigger('click');
            dblclick(row('doc.pdf'));
            return component;
        }

        it('lists a tab per open file with the icon of its kind', () => {
            withTabs();

            expect(tabTitles()).toEqual(['aaxis.devtools.storage_browser.files_tab', 'a.xyz', 'logo.png', 'doc.pdf']);
            const icons = $page.find('[data-role="tab"] .aaxis-fs__tab-icon').map((_: number, e: HTMLElement) => e.className).get();
            expect(icons[0]).toContain('fa-list');
            expect(icons[1]).toContain('fa-file-text-o');
            expect(icons[2]).toContain('fa-picture-o');
            expect(icons[3]).toContain('fa-file-pdf-o');
            expect(activeTab()).toBe('doc.pdf');
        });

        it('switches between tabs', () => {
            withTabs();
            $page.find('[data-role="tab"]').eq(1).trigger('click');

            expect(activeTab()).toBe('a.xyz');
            expect(content().find('pre').text()).toBe('A');

            $page.find('[data-role="tab"]').eq(0).trigger('click');
            expect(content().find('table.aaxis-fs__grid')).toHaveLength(1);
        });

        it('closing the active tab returns to the files tab', () => {
            withTabs();
            const click = $.Event('click');
            $page.find('[data-role="tab-close"]').last().trigger(click);

            expect(click.isDefaultPrevented()).toBe(true);
            expect(tabTitles()).toEqual(['aaxis.devtools.storage_browser.files_tab', 'a.xyz', 'logo.png']);
            expect(activeTab()).toBe('aaxis.devtools.storage_browser.files_tab');
            expect(content().find('table')).toHaveLength(1);
        });

        it('closing a background tab keeps the active one', () => {
            withTabs();
            $page.find('[data-role="tab-close"]').first().trigger('click');

            expect(tabTitles()).toEqual(['aaxis.devtools.storage_browser.files_tab', 'logo.png', 'doc.pdf']);
            expect(activeTab()).toBe('doc.pdf');
        });

        it('a click on a tab close button does not also switch to the tab', () => {
            const component = withTabs();
            component.onTabClick({target: $page.find('[data-role="tab-close"]').get(0), currentTarget: $page.find('[data-role="tab"]').get(1)});

            expect(activeTab()).toBe('doc.pdf');
        });

        it('ignores closing an unknown tab and rendering a vanished tab', () => {
            const component = withTabs();
            component.onTabClose($.Event('click', {currentTarget: $('<b data-id="tab-99"/>').get(0)}));
            component.activeTabId = 'tab-99';

            expect(() => component.renderActiveContent()).not.toThrow();
            expect(tabTitles()).toHaveLength(4);
        });

        it('closeTabById ignores unknown ids', () => {
            const component = withTabs();
            component.closeTabById('tab-99');

            expect(tabTitles()).toHaveLength(4);
        });
    });

    describe('downloads', () => {
        it('downloads through an invisible link to the download endpoint', () => {
            const links: Array<{href: string | null; connected: boolean}> = [];
            vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
                links.push({href: this.getAttribute('href'), connected: this.isConnected});
            });
            boot();
            const click = $.Event('click');
            row('index.php').find('[data-role="download-btn"]').trigger(click);

            expect(click.isDefaultPrevented()).toBe(true);
            expect(links).toEqual([{href: '/download?path=%2Fvar%2Fwww%2Findex.php', connected: true}]);
            expect(document.body.querySelector('a[href^="/download"]')).toBeNull();
        });
    });

    describe('write operations', () => {
        const options = {createFolderUrl: '/mkdir', uploadUrl: '/upload', deleteUrl: '/delete'};

        beforeEach(() => {
            document.cookie = '_csrf=csrf%20token';
        });

        describe('create folder', () => {
            it('asks for a name and POSTs it with the current path and the CSRF header', () => {
                vi.spyOn(window, 'prompt').mockReturnValue('  new dir ');
                boot(options);
                const click = $.Event('click');
                $page.find('[data-role="create-folder"]').trigger(click);

                expect(click.isDefaultPrevented()).toBe(true);
                expect(window.prompt).toHaveBeenCalledWith('aaxis.devtools.storage_browser.prompt_folder');
                const request = ajax.last('/mkdir');
                expect(request.options).toMatchObject({method: 'POST', contentType: 'application/json', headers: {'X-CSRF-Header': 'csrf token'}});
                expect(JSON.parse(request.options.data)).toEqual({path: '/var/www', name: 'new dir'});
            });

            it('refreshes the listing after success', () => {
                vi.spyOn(window, 'prompt').mockReturnValue('d');
                boot(options);
                $page.find('[data-role="create-folder"]').trigger('click');
                ajax.last('/mkdir').resolve({success: true});

                expect(flash()).toEqual([['success', 'aaxis.devtools.storage_browser.folder_created']]);
                expect(ajax.last('/list').options.data).toEqual({path: '/var/www'});
                expect(ajax.to('/list')).toHaveLength(2);
            });

            it('flashes the server message, the fallback, or the HTTP error message', () => {
                vi.spyOn(window, 'prompt').mockReturnValue('d');
                boot(options);
                $page.find('[data-role="create-folder"]').trigger('click');
                ajax.last('/mkdir').resolve({success: false, message: 'exists'});
                $page.find('[data-role="create-folder"]').trigger('click');
                ajax.last('/mkdir').resolve({success: false});
                $page.find('[data-role="create-folder"]').trigger('click');
                ajax.last('/mkdir').reject({message: 'denied'});
                $page.find('[data-role="create-folder"]').trigger('click');
                ajax.last('/mkdir').reject(undefined);

                expect(flash()).toEqual([
                    ['error', 'exists'],
                    ['error', 'aaxis.devtools.storage_browser.action_error'],
                    ['error', 'denied'],
                    ['error', 'aaxis.devtools.storage_browser.action_error']
                ]);
                expect(ajax.to('/list')).toHaveLength(1);
            });

            it.each([[null], ['   '], ['']])('does nothing when the prompt gives %j', answer => {
                vi.spyOn(window, 'prompt').mockReturnValue(answer as any);
                boot(options);
                $page.find('[data-role="create-folder"]').trigger('click');

                expect(ajax.to('/mkdir')).toHaveLength(0);
            });

            it('is disabled for a read-only browser', () => {
                const prompt = vi.spyOn(window, 'prompt').mockReturnValue('x');
                boot({...options, readOnly: true});
                $page.find('[data-role="create-folder"]').trigger('click');

                expect(prompt).not.toHaveBeenCalled();
                expect(ajax.to('/mkdir')).toHaveLength(0);
            });

            it('uses the browsed folder, not the base path, after navigating', () => {
                vi.spyOn(window, 'prompt').mockReturnValue('x');
                boot(options, listing(DEFAULT_ENTRIES, {path: '/var/www/deep'}));
                $page.find('[data-role="create-folder"]').trigger('click');

                expect(JSON.parse(ajax.last('/mkdir').options.data).path).toBe('/var/www/deep');
            });

            it('falls back to the base path before any listing has loaded', () => {
                vi.spyOn(window, 'prompt').mockReturnValue('x');
                create(options);
                $page.find('[data-role="create-folder"]').trigger('click');

                expect(JSON.parse(ajax.last('/mkdir').options.data).path).toBe('/var/www');
            });
        });

        describe('upload', () => {
            const chooseFile = (file: File | null) => {
                const input = $page.find('[data-role="upload-input"]').get(0);
                Object.defineProperty(input, 'files', {value: file ? [file] : [], configurable: true});
                $(input).trigger('change');
                return input;
            };

            it('the upload button opens the file chooser', () => {
                boot(options);
                const opened = vi.fn();
                $page.find('[data-role="upload-input"]').on('click', opened);
                const click = $.Event('click');
                $page.find('[data-role="upload"]').trigger(click);

                expect(click.isDefaultPrevented()).toBe(true);
                expect(opened).toHaveBeenCalled();
            });

            it('does not open the chooser for a read-only browser', () => {
                boot({...options, readOnly: true});
                const opened = vi.fn();
                $page.find('[data-role="upload-input"]').on('click', opened);
                $page.find('[data-role="upload"]').trigger('click');

                expect(opened).not.toHaveBeenCalled();
            });

            it('sends the chosen file as multipart form data with the current path', () => {
                boot(options);
                const file = new File(['hello'], 'hello.txt');
                chooseFile(file);

                const request = ajax.last('/upload');
                expect(request.options).toMatchObject({method: 'POST', processData: false, contentType: false, headers: {'X-CSRF-Header': 'csrf token'}});
                expect(request.options.data).toBeInstanceOf(FormData);
                expect(request.options.data.get('path')).toBe('/var/www');
                expect((request.options.data.get('file') as File).name).toBe('hello.txt');
            });

            it('confirms, refreshes and resets the chooser on success', () => {
                boot(options);
                const input = chooseFile(new File(['x'], 'x.txt'));
                ajax.last('/upload').resolve({success: true});

                expect(flash()).toEqual([['success', 'aaxis.devtools.storage_browser.uploaded']]);
                expect(ajax.to('/list')).toHaveLength(2);
                expect(input.value).toBe('');
            });

            it('flashes failures and still resets the chooser', () => {
                boot(options);
                const input = chooseFile(new File(['x'], 'x.txt'));
                ajax.last('/upload').resolve({success: false, message: 'too large'});
                chooseFile(new File(['x'], 'y.txt'));
                ajax.last('/upload').reject({message: 'quota'});

                expect(flash()).toEqual([['error', 'too large'], ['error', 'quota']]);
                expect(input.value).toBe('');
            });

            it('falls back to the generic message on an unsuccessful reply without text', () => {
                boot(options);
                chooseFile(new File(['x'], 'x.txt'));
                ajax.last('/upload').resolve({success: false});

                expect(flash()).toEqual([['error', 'aaxis.devtools.storage_browser.action_error']]);
            });

            it('does nothing when no file was chosen', () => {
                boot(options);
                chooseFile(null);

                expect(ajax.to('/upload')).toHaveLength(0);
            });

            it('does nothing when the input has no files list', () => {
                boot(options);
                const input = $page.find('[data-role="upload-input"]').get(0);
                Object.defineProperty(input, 'files', {value: null, configurable: true});
                $(input).trigger('change');

                expect(ajax.to('/upload')).toHaveLength(0);
            });
        });

        describe('delete', () => {
            const deleteBtn = (name: string) => row(name).find('[data-role="delete-btn"]');

            it('asks for confirmation naming the entry, then POSTs path and type', () => {
                const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
                boot(options);
                const click = $.Event('click');
                deleteBtn('index.php').trigger(click);

                expect(click.isDefaultPrevented()).toBe(true);
                expect(confirm).toHaveBeenCalledWith('aaxis.devtools.storage_browser.confirm_delete_file');
                const request = ajax.last('/delete');
                expect(request.options).toMatchObject({method: 'POST', contentType: 'application/json', headers: {'X-CSRF-Header': 'csrf token'}});
                expect(JSON.parse(request.options.data)).toEqual({path: '/var/www/index.php', type: 'file'});
            });

            it('uses the folder wording for directories', () => {
                const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
                boot(options);
                deleteBtn('src').trigger('click');

                expect(confirm).toHaveBeenCalledWith('aaxis.devtools.storage_browser.confirm_delete');
                expect(JSON.parse(ajax.last('/delete').options.data)).toEqual({path: '/var/www/src', type: 'dir'});
            });

            it('sends nothing when the user cancels', () => {
                vi.spyOn(window, 'confirm').mockReturnValue(false);
                boot(options);
                deleteBtn('index.php').trigger('click');

                expect(ajax.to('/delete')).toHaveLength(0);
            });

            it('refreshes the listing on success and flashes errors otherwise', () => {
                vi.spyOn(window, 'confirm').mockReturnValue(true);
                boot(options);
                deleteBtn('index.php').trigger('click');
                ajax.last('/delete').resolve({success: true});
                expect(flash().pop()).toEqual(['success', 'aaxis.devtools.storage_browser.deleted']);
                expect(ajax.to('/list')).toHaveLength(2);

                ajax.last('/list').resolve(listing(DEFAULT_ENTRIES));
                deleteBtn('index.php').trigger('click');
                ajax.last('/delete').resolve({success: false, message: 'protected'});
                expect(flash().pop()).toEqual(['error', 'protected']);

                deleteBtn('index.php').trigger('click');
                ajax.last('/delete').resolve({success: false});
                expect(flash().pop()).toEqual(['error', 'aaxis.devtools.storage_browser.action_error']);

                deleteBtn('index.php').trigger('click');
                ajax.last('/delete').reject({message: 'nope'});
                expect(flash().pop()).toEqual(['error', 'nope']);
            });

            it('does not select the row when its delete button is clicked', () => {
                vi.spyOn(window, 'confirm').mockReturnValue(false);
                boot(options);
                deleteBtn('index.php').trigger('click');

                expect(row('index.php').hasClass('is-selected')).toBe(false);
            });

            it('refuses when the browser is read-only, even if the handler is reached', () => {
                const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true);
                const component = boot({...options, readOnly: true});
                component.onDeleteClick($.Event('click', {currentTarget: $('<button data-path="/x" data-type="file" data-name="x"/>').get(0)}));

                expect(confirm).not.toHaveBeenCalled();
                expect(ajax.to('/delete')).toHaveLength(0);
            });
        });

        it('sends an empty CSRF token without the cookie, and the https-prefixed one on https', () => {
            document.cookie = '_csrf=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
            vi.spyOn(window, 'prompt').mockReturnValue('d');
            boot(options);
            $page.find('[data-role="create-folder"]').trigger('click');
            expect(ajax.last('/mkdir').options.headers['X-CSRF-Header']).toBe('');

            const original = window.location;
            Object.defineProperty(window, 'location', {value: {href: '', protocol: 'https:'}, configurable: true});
            try {
                document.cookie = 'https-_csrf=secure';
                $page.find('[data-role="create-folder"]').trigger('click');
                expect(ajax.last('/mkdir').options.headers['X-CSRF-Header']).toBe('secure');
            } finally {
                Object.defineProperty(window, 'location', {value: original, configurable: true});
                document.cookie = 'https-_csrf=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
            }
        });
    });

    it('opens the help block in a modal titled by the page', () => {
        boot();
        const click = $.Event('click');
        $page.find('[data-role="help"]').trigger(click);

        expect(click.isDefaultPrevented()).toBe(true);
        expect(Modal.instances[0].options).toEqual({
            title: 'Storage help',
            content: '<p>storage help</p>',
            allowOk: false,
            cancelText: 'Close'
        });
        expect(Modal.instances[0].open).toHaveBeenCalled();
    });

    it('unbinds on dispose, idempotently', () => {
        const component = boot();
        component.dispose();

        row('src').trigger('dblclick');
        expect(ajax.to('/list')).toHaveLength(1);
        expect(component.disposed).toBe(true);
        expect(() => component.dispose()).not.toThrow();
    });
});
