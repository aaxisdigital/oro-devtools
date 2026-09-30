import $ from 'jquery';
import CodeMirror from 'codemirror';
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import mediator from 'oroui/js/mediator';
import messenger from 'oroui/js/messenger';
import Modal from 'oroui/js/modal';
import {AjaxRecorder, installAjax} from '../../helpers/ajax';
import {createViewer, disposeCreatedViewers, hooks, mountViewer} from '../../helpers/query-viewer';

const flash = () => (messenger.notificationFlashMessage as any).mock.calls;
const lastModal = () => Modal.instances[Modal.instances.length - 1];
/** jQuery 3 settles `$.when(...)` on a timer tick. */
const settle = () => new Promise(resolve => setTimeout(resolve, 0));

const SAVED = {id: 7, name: 'Mine', public: false, text: 'SELECT saved', owned: true};
const SHARED = {id: 8, name: 'Theirs', public: true, text: 'SELECT shared', owned: false};

describe('AbstractQueryViewerComponent', () => {
    let $el: any;
    let ajax: AjaxRecorder;

    beforeEach(() => {
        Modal.instances.length = 0;
        window.sessionStorage.clear();
        ajax = installAjax();
        $el = mountViewer();
    });

    afterEach(() => {
        disposeCreatedViewers();
        vi.unstubAllGlobals();
    });

    const tabNames = () => $el.find('[data-role="tab"] .tv__tab-name').map((_: number, e: HTMLElement) => e.textContent).get();
    const activeTabName = () => $el.find('[data-role="tab"].is-active .tv__tab-name').text();
    const status = () => $el.find('[data-role="status"]').text();
    const menuLoads = () => ajax.to('=/saved').filter(request => request.options.method === 'GET').length;

    /** Viewer with the saved-queries menu answered, so no request is left dangling. */
    function boot(options: Record<string, unknown> = {}): any {
        const viewer = createViewer($el, options);
        return viewer;
    }

    function loadMenu(data: any = {public: [], private: [], hasMorePublic: false, hasMorePrivate: false}): void {
        ajax.last('=/saved').resolve(data);
    }

    /** Opens the saved query in a new tab through the real menu path. */
    function openSaved(viewer: any, item: any = SAVED): void {
        loadMenu({public: [], private: [item], hasMorePublic: false, hasMorePrivate: false});
        $el.find('[data-role="load-query"]').trigger('click');
        expect(viewer.activeTab().savedId).toBe(item.id);
    }

    describe('initialisation', () => {
        it('reads its options, defaulting the export and row-number switches to on', () => {
            const viewer = boot();

            expect(viewer.queryUrl).toBe('/query');
            expect(viewer.exportUrl).toBe('/export');
            expect(viewer.savedQueriesUrl).toBe('/saved');
            expect(viewer.allowExport).toBe(true);
            expect(viewer.showRowNumbers).toBe(true);
            expect(viewer.ns).toBe('testViewer');
        });

        it('honours allowExport=false and showRowNumbers=false', () => {
            const viewer = boot({allowExport: false, showRowNumbers: false});

            expect(viewer.allowExport).toBe(false);
            expect(viewer.showRowNumbers).toBe(false);
        });

        it('runs the subclass hooks: options first, sidebar load, event binding, after-setup', () => {
            const order: string[] = [];
            hooks.setupSidebar.mockImplementation(() => order.push('setupSidebar:' + (CodeMirror as any).fromTextArea.mock.calls.length));
            hooks.loadSidebar.mockImplementation(() => order.push('loadSidebar'));
            hooks.bindSidebarEvents.mockImplementation(() => order.push('bindSidebarEvents'));
            hooks.afterSetup.mockImplementation(() => order.push('afterSetup'));

            boot();

            expect(hooks.setupSidebar).toHaveBeenCalledWith(expect.objectContaining({queryUrl: '/query'}));
            // the editor does not exist yet when setupSidebar runs
            expect(order).toEqual(['setupSidebar:0', 'loadSidebar', 'bindSidebarEvents', 'afterSetup']);
        });

        it('requests the saved-queries menu', () => {
            boot();

            expect(ajax.last('=/saved').options).toMatchObject({url: '/saved', method: 'GET'});
        });

        it('builds a CodeMirror editor from the textarea and wires Ctrl/Cmd-Enter to run', () => {
            const viewer = boot();

            const [textarea, config] = (CodeMirror as any).fromTextArea.mock.calls[0];
            expect(textarea).toBe($el.find('[data-role="editor"]').get(0));
            expect(config).toMatchObject({mode: 'text/x-test', lineNumbers: true, lineWrapping: true, indentWithTabs: false});
            expect(viewer.editor.setSize).toHaveBeenCalledWith('100%', '100%');

            config.extraKeys['Ctrl-Enter']();
            expect(ajax.last('/query').options.data).toBe(JSON.stringify({query: 'SELECT 1'}));
            viewer.activeTab().running = false;
            config.extraKeys['Cmd-Enter']();
            expect(ajax.to('/query')).toHaveLength(2);
        });

        it('works without an editor textarea', () => {
            $el.find('[data-role="editor"]').remove();
            const viewer = boot();

            expect(viewer.editor).toBeNull();
            expect(viewer.tabs).toHaveLength(1);
            expect(viewer.activeTab().sql).toBe('');
            expect(() => viewer.refreshEditor()).not.toThrow();
            $el.find('[data-role="beautify"]').trigger('click');
            $el.find('[data-role="tab-add"]').trigger('click');
            expect(viewer.tabs).toHaveLength(2);
        });

        it('starts with one tab holding the editor text, and drops stale results of its own prefix only', () => {
            window.sessionStorage.setItem('tv-result:old', '{}');
            window.sessionStorage.setItem('other-result:keep', '{}');

            const viewer = boot();

            expect(window.sessionStorage.getItem('tv-result:old')).toBeNull();
            expect(window.sessionStorage.getItem('other-result:keep')).toBe('{}');
            expect(viewer.tabs).toHaveLength(1);
            expect(viewer.tabs[0]).toMatchObject({id: 'tab-1', sql: 'SELECT 1', savedId: null, running: false, pending: null});
            expect(tabNames()).toEqual(['test.viewer.tab_name']);
        });

        it('registers the navigation guard and a beforeunload handler', () => {
            const viewer = boot();

            expect(mediator.on).toHaveBeenCalledWith('openLink:before', expect.any(Function), viewer);
        });

        it('enables the run button and hides the update button initially', () => {
            boot();

            expect($el.find('[data-role="run"]').prop('disabled')).toBe(false);
            expect($el.find('[data-role="update"]').prop('hidden')).toBe(true);
            expect($el.find('[data-role="update"]').prop('disabled')).toBe(true);
        });
    });

    describe('shared sidebar behaviour', () => {
        it('typing in the filter lower-cases the text, shows the clear button and repaints', () => {
            boot();
            $el.find('[data-role="filter"]').val('UsEr').trigger('input');

            expect(hooks.renderSidebar).toHaveBeenCalledTimes(1);
            expect($el.find('[data-role="filter-clear"]').prop('hidden')).toBe(false);
        });

        it('the clear button empties the filter, hides itself, repaints and refocuses', () => {
            const viewer = boot();
            $el.find('[data-role="filter"]').val('abc').trigger('input');
            const $input = $el.find('[data-role="filter"]');
            const focus = vi.fn();
            $input.on('focus', focus);
            hooks.renderSidebar.mockClear();

            const click = $.Event('click');
            $el.find('[data-role="filter-clear"]').trigger(click);

            expect(click.isDefaultPrevented()).toBe(true);
            expect($input.val()).toBe('');
            expect(viewer.filterText).toBe('');
            expect($el.find('[data-role="filter-clear"]').prop('hidden')).toBe(true);
            expect(hooks.renderSidebar).toHaveBeenCalledTimes(1);
            expect(focus).toHaveBeenCalled();
        });

        it('refresh reloads the sidebar', () => {
            boot();
            hooks.loadSidebar.mockClear();
            const click = $.Event('click');
            $el.find('[data-role="refresh"]').trigger(click);

            expect(click.isDefaultPrevented()).toBe(true);
            expect(hooks.loadSidebar).toHaveBeenCalledTimes(1);
        });

        it('onOrderDir flips the direction, repaints the buttons and the sidebar', () => {
            const viewer = boot();
            hooks.updateOrderButtons.mockClear();
            hooks.renderSidebar.mockClear();

            viewer.onOrderDir($.Event('click'));
            expect(viewer.sortDir).toBe('desc');
            expect(hooks.updateOrderButtons).toHaveBeenCalledTimes(1);
            expect(hooks.renderSidebar).toHaveBeenCalledTimes(1);

            viewer.onOrderDir($.Event('click'));
            expect(viewer.sortDir).toBe('asc');
        });

        it('renderOrderDirection sets the arrow icon and title for the direction', () => {
            const viewer = boot();
            const $dir = $el.find('[data-role="order-dir"]');

            viewer.renderOrderDirection();
            expect($dir.find('.fa').attr('class')).toBe('fa fa-sort-amount-asc');
            expect($dir.attr('title')).toBe('test.viewer.order_asc');

            viewer.sortDir = 'desc';
            viewer.renderOrderDirection();
            expect($dir.find('.fa').attr('class')).toBe('fa fa-sort-amount-desc');
            expect($dir.attr('title')).toBe('test.viewer.order_desc');
        });

        it('renderSidebarLoading puts a spinner row in the list the subclass names', () => {
            const viewer = boot();
            $el.find('[data-role="objects"]').append('<li>old</li>');

            viewer.renderSidebarLoading();

            const $rows = $el.find('[data-role="objects"] li');
            expect($rows).toHaveLength(1);
            expect($rows.hasClass('tv__loading')).toBe(true);
            expect($rows.find('.fa-spinner')).toHaveLength(1);
            expect($rows.text()).toBe('test.viewer.loading');
        });

        it('the literal store swaps text for U+0001 placeholders and restores it', () => {
            const viewer = boot();
            const {stash, restore} = viewer.createLiteralStore();

            const a = stash("'a'");
            const b = stash('"b"');
            expect(a).toBe('\u00010\u0001');
            expect(b).toBe('\u00011\u0001');
            expect(restore('x ' + b + ' y ' + a)).toBe('x "b" y \'a\'');
            // independent stores do not share state
            expect(viewer.createLiteralStore().stash('z')).toBe('\u00010\u0001');
        });

        it('opens the help block in a close-only modal', () => {
            boot();
            const click = $.Event('click');
            $el.find('[data-role="help"]').trigger(click);

            expect(click.isDefaultPrevented()).toBe(true);
            expect(lastModal().options).toEqual({
                title: 'test.viewer.help',
                content: '<p>query help</p>',
                allowOk: false,
                cancelText: 'Close'
            });
            expect(lastModal().open).toHaveBeenCalled();
        });

        it('cls() and t() build BEM classes and translation keys from the subclass prefixes', () => {
            const viewer = boot();

            expect(viewer.cls('tab')).toBe('tv__tab');
            expect(viewer.t('run')).toBe('test.viewer.run');
        });
    });

    describe('tabs', () => {
        it('marks the active tab and shows a star per tab, a close button only with several tabs', () => {
            boot();
            expect($el.find('[data-role="tab-close"]')).toHaveLength(0);
            expect($el.find('[data-role="tab-star"]').hasClass('fa-star-o')).toBe(true);

            $el.find('[data-role="tab-add"]').trigger('click');

            expect($el.find('[data-role="tab"]')).toHaveLength(2);
            expect($el.find('[data-role="tab"]').eq(1).hasClass('is-active')).toBe(true);
            expect($el.find('[data-role="tab-close"]')).toHaveLength(2);
            expect($el.find('[data-role="tab-close"]').first().attr('aria-label')).toBe('test.viewer.close_tab');
        });

        it('shows the star of a saved tab as public or private', () => {
            const viewer = boot();
            openSaved(viewer, SAVED);
            let $star = $el.find('[data-role="tab"]').eq(1).find('[data-role="tab-star"]');
            expect($star.hasClass('fa-star')).toBe(true);
            expect($star.hasClass('is-private')).toBe(true);

            viewer.activeTab().savedPublic = true;
            viewer.renderTabs();
            $star = $el.find('[data-role="tab"]').eq(1).find('[data-role="tab-star"]');
            expect($star.hasClass('is-public')).toBe(true);
        });

        it('adding a tab keeps the previous tab text and empties the editor', () => {
            const viewer = boot();
            viewer.editor.setValue('SELECT typed');
            $el.find('[data-role="tab-add"]').trigger('click');

            expect(viewer.tabs.map((t: any) => t.sql)).toEqual(['SELECT typed', '']);
            expect(viewer.editor.getValue()).toBe('');
            expect(viewer.editor.focus).toHaveBeenCalled();
            expect(viewer.activeTabId).toBe('tab-2');
        });

        it('switches tabs, saving the current text and restoring the target text', () => {
            const viewer = boot();
            viewer.editor.setValue('first text');
            $el.find('[data-role="tab-add"]').trigger('click');
            viewer.editor.setValue('second text');

            $el.find('[data-role="tab"]').eq(0).trigger('click');

            expect(viewer.activeTabId).toBe('tab-1');
            expect(viewer.editor.getValue()).toBe('first text');
            expect(viewer.tabs[1].sql).toBe('second text');
        });

        it('clicking the active tab does nothing', () => {
            const viewer = boot();
            viewer.editor.setValue('typed');
            viewer.editor.setValue.mockClear();

            $el.find('[data-role="tab"]').trigger('click');

            expect(viewer.editor.setValue).not.toHaveBeenCalled();
        });

        it('ignores a switch to an unknown tab id', () => {
            const viewer = boot();

            viewer.switchTab('tab-99');

            expect(viewer.activeTabId).toBe('tab-1');
        });

        it('a click on the close button or the star inside a tab does not switch tabs', () => {
            const viewer = boot();
            $el.find('[data-role="tab-add"]').trigger('click');
            expect(viewer.activeTabId).toBe('tab-2');

            // star click on the inactive first tab is routed to onStarClick (which switches deliberately),
            // so drive onTabClick directly with a target inside the close button
            const $close = $el.find('[data-role="tab-close"]').first();
            viewer.onTabClick({target: $close.get(0), currentTarget: $el.find('[data-role="tab"]').get(0)});

            expect(viewer.activeTabId).toBe('tab-2');
        });

        it('closes an unmodified tab, activates its left neighbour, aborts a pending run and drops its result', () => {
            const viewer = boot();
            $el.find('[data-role="tab-add"]').trigger('click');
            viewer.editor.setValue('SELECT 2');
            $el.find('[data-role="run"]').trigger('click');
            const pending = ajax.last('/query');
            window.sessionStorage.setItem('tv-result:tab-2', '{}');

            $el.find('[data-role="tab-close"]').eq(1).trigger('click');

            expect(pending.abort).toHaveBeenCalledTimes(1);
            expect(window.sessionStorage.getItem('tv-result:tab-2')).toBeNull();
            expect(viewer.tabs.map((t: any) => t.id)).toEqual(['tab-1']);
            expect(viewer.activeTabId).toBe('tab-1');
            expect(viewer.editor.getValue()).toBe('SELECT 1');
            expect($el.find('[data-role="tab-close"]')).toHaveLength(0);
        });

        it('closing a background tab keeps the active one', () => {
            const viewer = boot();
            $el.find('[data-role="tab-add"]').trigger('click');

            $el.find('[data-role="tab-close"]').eq(0).trigger('click');

            expect(viewer.tabs.map((t: any) => t.id)).toEqual(['tab-2']);
            expect(viewer.activeTabId).toBe('tab-2');
        });

        it('never closes the last tab', () => {
            const viewer = boot();

            viewer.closeTab('tab-1');
            viewer.onCloseTab($.Event('click', {currentTarget: $('<b data-id="tab-1"/>').get(0)}));

            expect(viewer.tabs).toHaveLength(1);
        });

        it('does not even ask about discarding when the only tab is closed', () => {
            const viewer = boot();
            openSaved(viewer);
            viewer.closeTab('tab-1'); // leaves the saved query as the only tab
            expect(viewer.tabs.map((t: any) => t.id)).toEqual(['tab-2']);
            viewer.editor.setValue('SELECT edited');
            const before = Modal.instances.length;

            viewer.onCloseTab($.Event('click', {currentTarget: $('<b data-id="tab-2"/>').get(0)}));

            expect(Modal.instances).toHaveLength(before);
            expect(viewer.tabs).toHaveLength(1);
        });

        it('ignores closing an unknown tab', () => {
            const viewer = boot();
            $el.find('[data-role="tab-add"]').trigger('click');

            viewer.closeTab('tab-42');
            viewer.onCloseTab($.Event('click', {currentTarget: $('<b data-id="tab-42"/>').get(0)}));

            expect(viewer.tabs).toHaveLength(2);
        });

        it('asks before closing a saved tab with unsaved edits and closes it on confirm', () => {
            const viewer = boot();
            openSaved(viewer);
            viewer.editor.setValue('SELECT edited');

            $el.find('[data-role="tab-close"]').eq(1).trigger('click');

            expect(viewer.tabs).toHaveLength(2);
            const modal = lastModal();
            expect(modal.options.title).toBe('test.viewer.unsaved_title');
            expect(modal.options.okText).toBe('test.viewer.unsaved_discard');
            expect(modal.open).toHaveBeenCalled();

            modal.emit('ok');
            expect(viewer.tabs).toHaveLength(1);
        });

        it('escapes the tab name inside the discard confirmation', () => {
            const viewer = boot();
            openSaved(viewer, {...SAVED, name: '<img src=x>'});
            viewer.editor.setValue('changed');

            $el.find('[data-role="tab-close"]').eq(1).trigger('click');

            expect(lastModal().options.content).toBe('test.viewer.unsaved_close_confirm');
            expect(lastModal().options.content).not.toContain('<img');
        });

        it('shows a spinner on the tab that is running', () => {
            boot();
            $el.find('[data-role="run"]').trigger('click');

            expect($el.find('[data-role="tab"] .tv__tab-spinner')).toHaveLength(1);
        });
    });

    describe('favourites', () => {
        function saveDialog(viewer: any, mode: 'create' | 'edit' = 'create'): any {
            viewer.openSaveDialog(mode);
            return lastModal();
        }

        it('clicking the star of an unsaved tab opens the create dialog', () => {
            boot();
            $el.find('[data-role="tab-star"]').trigger('click');

            expect(lastModal().options.title).toBe('test.viewer.sq_title');
            expect(lastModal().options.okText).toBe('test.viewer.sq_save');
            expect(lastModal().options.okCloses).toBe(false);
            expect(lastModal().$el.find('input[name="sq-visibility"][value="private"]').prop('checked')).toBe(true);
            expect(lastModal().open).toHaveBeenCalled();
        });

        it('clicking the star of an owned saved tab opens the edit dialog preselecting its visibility', () => {
            const viewer = boot();
            openSaved(viewer, {...SAVED, public: true});

            $el.find('[data-role="tab-star"]').eq(1).trigger('click');

            expect(lastModal().options.title).toBe('test.viewer.sq_edit_title');
            expect(lastModal().$el.find('input[value="public"]').prop('checked')).toBe(true);
            expect(lastModal().$el.find('input[value="private"]').prop('checked')).toBe(false);
        });

        it('a star click on a saved query owned by someone else does nothing', () => {
            const viewer = boot();
            openSaved(viewer, SHARED);
            const before = Modal.instances.length;

            $el.find('[data-role="tab-star"]').eq(1).trigger('click');

            expect(Modal.instances).toHaveLength(before);
        });

        it('a star click on a background tab activates it first', () => {
            const viewer = boot();
            $el.find('[data-role="tab-add"]').trigger('click');

            $el.find('[data-role="tab-star"]').eq(0).trigger('click');

            expect(viewer.activeTabId).toBe('tab-1');
            expect(lastModal().options.title).toBe('test.viewer.sq_title');
        });

        it('does not open a dialog without an editor', () => {
            $el.find('[data-role="editor"]').remove();
            const viewer = boot();
            const before = Modal.instances.length;

            viewer.openSaveDialog('create');

            expect(Modal.instances).toHaveLength(before);
        });

        it('prefills the current tab name, HTML-escaped', () => {
            const viewer = boot();
            viewer.activeTab().name = 'a"b<c>&d';

            const modal = saveDialog(viewer);

            expect(modal.$el.find('[data-role="sq-name"]').val()).toBe('a"b<c>&d');
        });

        it('requires a name', () => {
            const viewer = boot();
            const modal = saveDialog(viewer);
            modal.$el.find('[data-role="sq-name"]').val('   ');

            modal.emit('ok');

            expect(flash()).toEqual([['warning', 'test.viewer.sq_name_required']]);
            expect(ajax.to('/saved')).toHaveLength(1); // only the menu load
        });

        it('creates a saved query by POSTing name, text and visibility, then updates the tab and menu', () => {
            const viewer = boot();
            loadMenu();
            viewer.editor.setValue('SELECT mine');
            const modal = saveDialog(viewer);
            modal.$el.find('[data-role="sq-name"]').val('  My query ');
            modal.$el.find('input[value="public"]').prop('checked', true);

            modal.emit('ok');

            const request = ajax.requests[ajax.requests.length - 1];
            expect(request.options).toMatchObject({url: '/saved', method: 'POST', contentType: 'application/json', errorHandlerMessage: false});
            expect(JSON.parse(request.options.data)).toEqual({name: 'My query', text: 'SELECT mine', public: true});

            request.resolve({success: true, query: {id: 5, name: 'My query', public: true, text: 'SELECT mine', owned: true}});

            expect(viewer.activeTab()).toMatchObject({savedId: 5, savedPublic: true, savedOwned: true, savedText: 'SELECT mine', name: 'My query'});
            expect(activeTabName()).toBe('My query');
            expect(flash()).toEqual([['success', 'test.viewer.sq_saved']]);
            expect(modal.close).toHaveBeenCalled();
            expect(menuLoads()).toBe(2); // reloaded after the save
        });

        it('edits an existing saved query by PUTting name and visibility only', () => {
            const viewer = boot();
            openSaved(viewer, SAVED);
            const modal = saveDialog(viewer, 'edit');
            modal.$el.find('[data-role="sq-name"]').val('Renamed');

            modal.emit('ok');

            const request = ajax.requests[ajax.requests.length - 1];
            expect(request.options).toMatchObject({url: '/saved/7', method: 'PUT'});
            expect(JSON.parse(request.options.data)).toEqual({name: 'Renamed', public: false});
        });

        it('closes the dialog without touching the tab when the server declines', () => {
            const viewer = boot();
            const modal = saveDialog(viewer);
            modal.$el.find('[data-role="sq-name"]').val('x');
            modal.emit('ok');

            ajax.requests[ajax.requests.length - 1].resolve({success: false});

            expect(viewer.activeTab().savedId).toBeNull();
            expect(flash()).toEqual([]);
            expect(modal.close).toHaveBeenCalled();
        });

        it('keeps the dialog open and flashes the server message, or the fallback, on failure', () => {
            const viewer = boot();
            const modal = saveDialog(viewer);
            modal.$el.find('[data-role="sq-name"]').val('x');

            modal.emit('ok');
            ajax.requests[ajax.requests.length - 1].reject({message: 'duplicate name'});
            modal.emit('ok');
            ajax.requests[ajax.requests.length - 1].reject(undefined);

            expect(flash()).toEqual([['error', 'duplicate name'], ['error', 'test.viewer.sq_error']]);
            expect(modal.close).not.toHaveBeenCalled();
        });
    });

    describe('saved-queries menu', () => {
        const menuTexts = () => $el.find('[data-role="queries-menu"] li').map((_: number, li: HTMLElement) => li.textContent).get();

        it('says so when there are no saved queries', () => {
            boot();
            loadMenu();

            expect($el.find('.tv__menu-empty').text()).toBe('test.viewer.queries_empty');
        });

        it('lists private then public queries under headers with dividers', () => {
            boot();
            loadMenu({public: [SHARED], private: [SAVED], hasMorePublic: false, hasMorePrivate: false});

            expect(menuTexts()).toEqual(['test.viewer.queries_private', 'Mine', '', 'test.viewer.queries_public', 'Theirs']);
            const $items = $el.find('[data-role="load-query"]');
            expect($items.eq(0).find('.fa').hasClass('is-private')).toBe(true);
            expect($items.eq(1).find('.fa').hasClass('is-public')).toBe(true);
            expect($items.eq(0).attr('data-query')).toBe(JSON.stringify(SAVED));
        });

        it('omits the public block when there are none, and adds "view all" when more exist', () => {
            boot();
            loadMenu({public: [], private: [SAVED], hasMorePublic: false, hasMorePrivate: true});

            expect(menuTexts()).toEqual(['test.viewer.queries_private', 'Mine', '', 'test.viewer.queries_view_all']);
            expect($el.find('[data-role="view-all-queries"]').attr('role')).toBe('menuitem');
        });

        it('offers "view all" when only the public list overflows', () => {
            boot();
            loadMenu({public: [SHARED], private: [], hasMorePublic: true, hasMorePrivate: false});

            expect($el.find('[data-role="view-all-queries"]')).toHaveLength(1);
            expect(menuTexts()).not.toContain('test.viewer.queries_private');
        });

        it('opens a clicked query in a new tab with its saved metadata', () => {
            const viewer = boot();
            viewer.editor.setValue('SELECT typed');
            openSaved(viewer, SHARED);

            expect(viewer.tabs).toHaveLength(2);
            expect(viewer.tabs[0].sql).toBe('SELECT typed');
            expect(viewer.activeTab()).toMatchObject({name: 'Theirs', sql: 'SELECT shared', savedId: 8, savedPublic: true, savedOwned: false, savedText: 'SELECT shared'});
            expect(viewer.editor.getValue()).toBe('SELECT shared');
            expect(activeTabName()).toBe('Theirs');
        });

        it('ignores a menu entry without query data', () => {
            const viewer = boot();
            $el.find('[data-role="queries-menu"]').append('<li><a href="#" data-role="load-query">empty</a></li>');

            $el.find('[data-role="load-query"]').trigger('click');

            expect(viewer.tabs).toHaveLength(1);
        });

        it('"view all" fetches the full list and shows it in a modal', () => {
            boot();
            loadMenu({public: [], private: [SAVED], hasMorePublic: true, hasMorePrivate: false});

            const click = $.Event('click');
            $el.find('[data-role="view-all-queries"]').trigger(click);
            expect(click.isDefaultPrevented()).toBe(true);
            expect(ajax.last('/saved/all').options).toMatchObject({method: 'GET'});
            ajax.last('/saved/all').resolve({items: [SAVED, SHARED]});

            const modal = lastModal();
            expect(modal.options.title).toBe('test.viewer.all_queries_title');
            expect(modal.options.allowOk).toBe(false);
            expect(modal.$el.find('[data-role="all-query-item"]').map((_: number, a: HTMLElement) => a.textContent).get())
                .toEqual(['Mine', 'Theirs']);
            expect(modal.open).toHaveBeenCalled();
        });

        it('picking an entry of the full list closes the modal and opens the query', () => {
            const viewer = boot();
            loadMenu({public: [], private: [SAVED], hasMorePublic: true, hasMorePrivate: false});
            $el.find('[data-role="view-all-queries"]').trigger('click');
            ajax.last('/saved/all').resolve({items: [SAVED, SHARED]});
            const modal = lastModal();

            modal.$el.find('[data-role="all-query-item"]').eq(1).trigger('click');

            expect(modal.close).toHaveBeenCalled();
            expect(viewer.activeTab()).toMatchObject({savedId: 8, sql: 'SELECT shared'});
        });

        it('shows an empty note when the full list is empty or missing', () => {
            boot();
            loadMenu({public: [], private: [SAVED], hasMorePublic: true, hasMorePrivate: false});
            $el.find('[data-role="view-all-queries"]').trigger('click');
            ajax.last('/saved/all').resolve({});

            expect(lastModal().$el.text()).toBe('test.viewer.queries_empty');
        });
    });

    describe('update button', () => {
        const $update = () => $el.find('[data-role="update"]');

        it('is shown for an owned saved tab and enabled only once the text differs from the saved text', () => {
            const viewer = boot();
            openSaved(viewer, SAVED);

            expect($update().prop('hidden')).toBe(false);
            expect($update().prop('disabled')).toBe(true);

            viewer.editor.setValue('SELECT changed');
            expect($update().prop('disabled')).toBe(false);

            viewer.editor.setValue('SELECT saved');
            expect($update().prop('disabled')).toBe(true);
        });

        it('stays hidden for tabs that are unsaved or not owned', () => {
            const viewer = boot();
            expect($update().prop('hidden')).toBe(true);

            openSaved(viewer, SHARED);
            expect($update().prop('hidden')).toBe(true);
            expect($update().prop('disabled')).toBe(true);
        });

        it('PUTs the new text, then records it as saved and reloads the menu', () => {
            const viewer = boot();
            openSaved(viewer, SAVED);
            viewer.editor.setValue('SELECT changed');

            const click = $.Event('click');
            $update().trigger(click);

            const request = ajax.requests[ajax.requests.length - 1];
            expect(click.isDefaultPrevented()).toBe(true);
            expect(request.options).toMatchObject({url: '/saved/7', method: 'PUT', contentType: 'application/json', errorHandlerMessage: false});
            expect(JSON.parse(request.options.data)).toEqual({text: 'SELECT changed'});

            const loadsBefore = menuLoads();
            request.resolve({success: true});

            expect(viewer.activeTab().savedText).toBe('SELECT changed');
            expect($update().prop('disabled')).toBe(true);
            expect(menuLoads()).toBe(loadsBefore + 1);
            expect(flash()).toEqual([['success', 'test.viewer.uq_updated']]);
        });

        it('keeps the tab as modified when the server answers success=false', () => {
            const viewer = boot();
            openSaved(viewer, SAVED);
            viewer.editor.setValue('SELECT changed');
            $update().trigger('click');

            ajax.requests[ajax.requests.length - 1].resolve({success: false});

            expect(viewer.activeTab().savedText).toBe('SELECT saved');
            expect(flash()).toEqual([]);
        });

        it('flashes an error when the update request fails', () => {
            const viewer = boot();
            openSaved(viewer, SAVED);
            viewer.editor.setValue('SELECT changed');
            $update().trigger('click');

            ajax.requests[ajax.requests.length - 1].reject({});

            expect(flash()).toEqual([['error', 'test.viewer.uq_error']]);
        });

        it('does nothing for an unsaved tab', () => {
            const viewer = boot();
            const before = ajax.requests.length;

            viewer.onUpdateQuery($.Event('click'));

            expect(ajax.requests).toHaveLength(before);
        });
    });

    describe('unsaved-changes guard', () => {
        const beforePageChange = (): {handler: (payload: any) => void; context: any} => {
            const call = (mediator.on as any).mock.calls.find((c: any[]) => c[0] === 'openLink:before');
            return {handler: call[1], context: call[2]};
        };
        const navigate = (payload: any) => {
            const {handler, context} = beforePageChange();
            handler.call(context, payload);
        };

        it('does not block the beforeunload of a viewer without modified saved tabs', () => {
            const viewer = boot();
            const event = $.Event('beforeunload');

            expect(viewer.onWindowUnload(event)).toBeUndefined();
            expect(event.isDefaultPrevented()).toBe(false);
        });

        it('a new never-saved tab with edits is not considered modified', () => {
            const viewer = boot();
            viewer.editor.setValue('typed a lot');

            expect(viewer.modifiedSavedTabs()).toEqual([]);
        });

        it('asks the browser to confirm leaving when a saved query has unsaved edits', () => {
            const viewer = boot();
            openSaved(viewer, SAVED);
            viewer.editor.setValue('SELECT edited');
            const event: any = $.Event('beforeunload');
            event.originalEvent = {preventDefault: vi.fn()};

            const result = viewer.onWindowUnload(event);

            expect(result).toBe('test.viewer.unsaved_leave_intro');
            expect(event.isDefaultPrevented()).toBe(true);
            expect(event.originalEvent.returnValue).toBe('test.viewer.unsaved_leave_intro');
        });

        it('the beforeunload binding reaches the handler through the window', () => {
            const viewer = boot();
            openSaved(viewer, SAVED);
            viewer.editor.setValue('SELECT edited');
            const event = $.Event('beforeunload');

            $(window).trigger(event);

            expect(event.isDefaultPrevented()).toBe(true);
        });

        it('tolerates a beforeunload event without originalEvent', () => {
            const viewer = boot();
            openSaved(viewer, SAVED);
            viewer.editor.setValue('SELECT edited');

            expect(viewer.onWindowUnload($.Event('beforeunload'))).toBe('test.viewer.unsaved_leave_intro');
        });

        it('leaves navigation alone when nothing is modified or it was already prevented', () => {
            const viewer = boot();
            const payload = {prevented: false, target: document.createElement('a')};
            navigate(payload);
            expect(payload.prevented).toBe(false);

            openSaved(viewer, SAVED);
            viewer.editor.setValue('SELECT edited');
            const already = {prevented: true, target: document.createElement('a')};
            const before = Modal.instances.length;
            navigate(already);
            expect(Modal.instances).toHaveLength(before);
        });

        function leaveDialog(viewer: any, target: HTMLElement): {payload: any; modal: any} {
            const payload = {prevented: false, target};
            navigate(payload);
            return {payload, modal: lastModal()};
        }

        const link = (href: string, options?: object) => {
            const a = document.createElement('a');
            a.setAttribute('href', href);
            if (options) {
                a.setAttribute('data-options', JSON.stringify(options));
            }
            return a;
        };

        it('holds the navigation back and lists the modified queries', () => {
            const viewer = boot();
            openSaved(viewer, SAVED);
            viewer.editor.setValue('SELECT edited');

            const {payload, modal} = leaveDialog(viewer, link('/go'));

            expect(payload.prevented).toBe(true);
            expect(modal.options.title).toBe('test.viewer.unsaved_title');
            expect(modal.options.okText).toBe('test.viewer.unsaved_leave_ok');
            expect(modal.options.okCloses).toBe(false);
            expect(modal.$el.find('.tv__leave-name').text()).toBe('Mine');
            expect(modal.$el.find('input[name="leave-tab-2"][value="discard"]').prop('checked')).toBe(true);
            expect(modal.$el.find('input[name="leave-tab-2"][value="save"]')).toHaveLength(1);
            expect(modal.$el.find('[data-role="leave-all"]')).toHaveLength(0);
            expect(modal.open).toHaveBeenCalled();
        });

        it('discarding proceeds to the original link with its navigation options', () => {
            const viewer = boot();
            openSaved(viewer, SAVED);
            viewer.editor.setValue('SELECT edited');
            const {modal} = leaveDialog(viewer, link('/go', {cache: false}));

            modal.emit('ok');

            expect(ajax.requests.filter(r => r.options.method === 'PUT')).toHaveLength(0);
            expect(modal.close).toHaveBeenCalled();
            expect(mediator.execute).toHaveBeenCalledWith('redirectTo', {url: '/go'}, {cache: false});
        });

        it('falls back to data-href and empty options for the redirect', () => {
            const viewer = boot();
            openSaved(viewer, SAVED);
            viewer.editor.setValue('SELECT edited');
            const a = document.createElement('a');
            a.setAttribute('data-href', '/from-data');
            const {modal} = leaveDialog(viewer, a);

            modal.emit('ok');

            expect(mediator.execute).toHaveBeenCalledWith('redirectTo', {url: '/from-data'}, {});
        });

        it('does not redirect when the target has no URL', () => {
            const viewer = boot();
            openSaved(viewer, SAVED);
            viewer.editor.setValue('SELECT edited');
            const {modal} = leaveDialog(viewer, document.createElement('span'));

            modal.emit('ok');

            expect(modal.close).toHaveBeenCalled();
            expect(mediator.execute).not.toHaveBeenCalled();
        });

        it('saving first PUTs each chosen query and only then proceeds', async () => {
            const viewer = boot();
            openSaved(viewer, SAVED);
            viewer.editor.setValue('SELECT edited');
            const {modal} = leaveDialog(viewer, link('/go'));
            modal.$el.find('input[name="leave-tab-2"][value="save"]').prop('checked', true);

            modal.emit('ok');

            const put = ajax.requests[ajax.requests.length - 1];
            expect(put.options).toMatchObject({url: '/saved/7', method: 'PUT', errorHandlerMessage: false});
            expect(JSON.parse(put.options.data)).toEqual({text: 'SELECT edited'});
            expect(mediator.execute).not.toHaveBeenCalled();

            put.resolve({success: true});
            await settle();

            expect(viewer.tabs[1].savedText).toBe('SELECT edited');
            expect(modal.close).toHaveBeenCalled();
            expect(mediator.execute).toHaveBeenCalledWith('redirectTo', {url: '/go'}, {});
        });

        it('stays on the page and flashes when saving before leaving fails', async () => {
            const viewer = boot();
            openSaved(viewer, SAVED);
            viewer.editor.setValue('SELECT edited');
            const {modal} = leaveDialog(viewer, link('/go'));
            modal.$el.find('input[name="leave-tab-2"][value="save"]').prop('checked', true);

            modal.emit('ok');
            ajax.requests[ajax.requests.length - 1].reject({});
            await settle();

            expect(flash()).toEqual([['error', 'test.viewer.uq_error']]);
            expect(modal.close).not.toHaveBeenCalled();
            expect(mediator.execute).not.toHaveBeenCalled();
            expect(viewer.tabs[1].savedText).toBe('SELECT saved');
        });

        it('offers no save option for queries owned by someone else', () => {
            const viewer = boot();
            openSaved(viewer, SHARED);
            viewer.editor.setValue('SELECT edited');

            const {modal} = leaveDialog(viewer, link('/go'));

            expect(modal.$el.find('input[value="save"]')).toHaveLength(0);
            modal.emit('ok');
            expect(ajax.requests.filter(r => r.options.method === 'PUT')).toHaveLength(0);
        });

        it('with several modified queries offers discard-all / save-all shortcuts', () => {
            const viewer = boot();
            openSaved(viewer, SAVED);
            viewer.loadQueriesMenu();
            loadMenu({public: [], private: [{...SAVED, id: 9, name: 'Second'}], hasMorePublic: false, hasMorePrivate: false});
            $el.find('[data-role="load-query"]').trigger('click');
            viewer.editor.setValue('SELECT second edited');
            viewer.switchTab('tab-2');
            viewer.editor.setValue('SELECT first edited');

            const {modal} = leaveDialog(viewer, link('/go'));

            expect(modal.$el.find('.tv__leave-name').map((_: number, e: HTMLElement) => e.textContent).get()).toEqual(['Mine', 'Second']);
            expect(modal.$el.find('[data-role="leave-all"]')).toHaveLength(2);

            const saveAll = $.Event('click');
            modal.$el.find('[data-role="leave-all"][data-value="save"]').trigger(saveAll);
            expect(saveAll.isDefaultPrevented()).toBe(true);
            expect(modal.$el.find('input[value="save"]:checked')).toHaveLength(2);

            modal.$el.find('[data-role="leave-all"][data-value="discard"]').trigger('click');
            expect(modal.$el.find('input[value="discard"]:checked')).toHaveLength(2);
            expect(modal.$el.find('input[value="save"]:checked')).toHaveLength(0);
        });

        it('escapes query names in the list', () => {
            const viewer = boot();
            openSaved(viewer, {...SAVED, name: '<script>x</script>'});
            viewer.editor.setValue('SELECT edited');

            const {modal} = leaveDialog(viewer, link('/go'));

            expect(modal.$el.find('script')).toHaveLength(0);
            expect(modal.$el.find('.tv__leave-name').text()).toBe('<script>x</script>');
        });
    });

    describe('splitters', () => {
        const stubSize = (selector: string, size: {width?: number; height?: number}) => {
            $el.find(selector).get(0).getBoundingClientRect = () => size;
        };
        const drag = (selector: string, from: [number, number], to: [number, number]) => {
            $el.find(selector).trigger($.Event('mousedown', {clientX: from[0], clientY: from[1]}));
            $(document).trigger($.Event('mousemove', {clientX: to[0], clientY: to[1]}));
        };

        it('dragging the vertical splitter resizes the sidebar and refreshes the editor', () => {
            const viewer = boot();
            stubSize('[data-role="sidebar"]', {width: 300});

            const down = $.Event('mousedown', {clientX: 100, clientY: 0});
            $el.find('[data-role="v-splitter"]').trigger(down);
            expect(down.isDefaultPrevented()).toBe(true);
            expect($('body').hasClass('tv-resizing')).toBe(true);

            $(document).trigger($.Event('mousemove', {clientX: 150, clientY: 0}));

            expect($el.find('[data-role="sidebar"]').get(0).style.flex).toBe('0 0 350px');
            expect(viewer.editor.refresh).toHaveBeenCalled();
        });

        it('clamps the sidebar width between 160px and 640px', () => {
            boot();
            stubSize('[data-role="sidebar"]', {width: 300});

            drag('[data-role="v-splitter"]', [500, 0], [0, 0]);
            expect($el.find('[data-role="sidebar"]').get(0).style.flex).toBe('0 0 160px');

            $(document).trigger($.Event('mouseup'));
            drag('[data-role="v-splitter"]', [0, 0], [9999, 0]);
            expect($el.find('[data-role="sidebar"]').get(0).style.flex).toBe('0 0 640px');
        });

        it('dragging the horizontal splitter resizes the editor pane, minimum 80px', () => {
            boot();
            stubSize('[data-role="editor-pane"]', {height: 200});

            drag('[data-role="h-splitter"]', [0, 100], [0, 160]);
            expect($el.find('[data-role="editor-pane"]').get(0).style.flex).toBe('0 0 260px');

            drag('[data-role="h-splitter"]', [0, 100], [0, -900]);
            expect($el.find('[data-role="editor-pane"]').get(0).style.flex).toBe('0 0 80px');
        });

        it('releasing the mouse ends the drag', () => {
            boot();
            stubSize('[data-role="sidebar"]', {width: 300});
            drag('[data-role="v-splitter"]', [100, 0], [120, 0]);

            $(document).trigger($.Event('mouseup'));
            expect($('body').hasClass('tv-resizing')).toBe(false);

            $(document).trigger($.Event('mousemove', {clientX: 400, clientY: 0}));
            expect($el.find('[data-role="sidebar"]').get(0).style.flex).toBe('0 0 320px');
        });

        it('is inert when the pane it would resize is missing', () => {
            $el.find('[data-role="sidebar"]').remove();
            $el.find('[data-role="editor-pane"]').remove();
            boot();

            expect(() => {
                drag('[data-role="v-splitter"]', [0, 0], [10, 0]);
                drag('[data-role="h-splitter"]', [0, 0], [0, 10]);
            }).not.toThrow();
        });
    });

    describe('beautify', () => {
        it('replaces the editor text with the subclass formatting', () => {
            const viewer = boot();
            viewer.editor.setValue('select 1');

            const click = $.Event('click');
            $el.find('[data-role="beautify"]').trigger(click);

            expect(click.isDefaultPrevented()).toBe(true);
            expect(viewer.editor.getValue()).toBe('SELECT 1 /*formatted*/');
        });

        it('leaves a blank editor alone', () => {
            const viewer = boot();
            viewer.editor.setValue('  \n ');
            viewer.editor.setValue.mockClear();

            $el.find('[data-role="beautify"]').trigger('click');

            expect(viewer.editor.setValue).not.toHaveBeenCalled();
        });

        it('warns and keeps the text when formatting throws', () => {
            const viewer = boot();
            viewer.editor.setValue('BOOM');
            viewer.editor.setValue.mockClear();

            $el.find('[data-role="beautify"]').trigger('click');

            expect(viewer.editor.setValue).not.toHaveBeenCalled();
            expect(flash()).toEqual([['warning', 'test.viewer.beautify_error']]);
        });
    });

    describe('running queries', () => {
        const run = () => $el.find('[data-role="run"]').trigger('click');
        const grid = () => $el.find('[data-role="result"] table');

        it('warns instead of running an empty query', () => {
            const viewer = boot();
            viewer.editor.setValue('   ');

            run();

            expect(flash()).toEqual([['warning', 'test.viewer.empty_query']]);
            expect(ajax.to('/query')).toHaveLength(0);
            expect(viewer.activeTab().running).toBe(false);
        });

        it('POSTs the trimmed query as JSON without the global ajax error flash', () => {
            const viewer = boot();
            viewer.editor.setValue('  SELECT 5  ');

            run();

            expect(ajax.last('/query').options).toMatchObject({
                url: '/query', method: 'POST', contentType: 'application/json', errorHandlerMessage: false,
                data: JSON.stringify({query: 'SELECT 5'})
            });
            expect(viewer.activeTab().running).toBe(true);
            expect(viewer.activeTab().pending).not.toBeNull();
        });

        it('shows the running state: spinner, disabled run button, status text, tab spinner', () => {
            boot();
            run();

            expect($el.find('[data-role="run"]').prop('disabled')).toBe(true);
            expect($el.find('[data-role="run-spinner"]').css('display')).not.toBe('none');
            expect(status()).toBe('test.viewer.running');
            expect($el.find('.tv__tab-spinner')).toHaveLength(1);
        });

        it('ignores a second run of the tab that is still running (e.g. Ctrl-Enter while running)', () => {
            boot();
            const ctrlEnter = (CodeMirror as any).fromTextArea.mock.calls[0][1].extraKeys['Ctrl-Enter'];
            ctrlEnter();
            ctrlEnter();

            expect(ajax.to('/query')).toHaveLength(1);
        });

        it('does nothing when there is no active tab', () => {
            const viewer = boot();
            viewer.activeTabId = 'gone';

            viewer.onRun();

            expect(ajax.to('/query')).toHaveLength(0);
            expect(flash()).toEqual([]);
        });

        it('renders a successful result, stores it and resets the running state', () => {
            const viewer = boot();
            run();

            ajax.last('/query').resolve({
                success: true, columns: ['id', 'name'], rows: [{id: 1, name: 'a'}], rowCount: 1, durationMs: 12, truncated: false
            });

            expect(viewer.activeTab().running).toBe(false);
            expect(viewer.activeTab().pending).toBeNull();
            expect($el.find('[data-role="run"]').prop('disabled')).toBe(false);
            expect($el.find('[data-role="run-spinner"]').css('display')).toBe('none');
            expect(grid()).toHaveLength(1);
            expect(status()).toBe('test.viewer.result_summary');
            expect($el.find('[data-role="export"]').prop('disabled')).toBe(false);
            expect(JSON.parse(window.sessionStorage.getItem('tv-result:tab-1') as string)).toEqual({
                columns: ['id', 'name'], rows: [{id: 1, name: 'a'}], rowCount: 1, durationMs: 12, truncated: false
            });
        });

        it('appends the truncation note to the status', () => {
            boot();
            run();

            ajax.last('/query').resolve({success: true, columns: ['id'], rows: [{id: 1}], rowCount: 1, durationMs: 1, truncated: true});

            expect(status()).toBe('test.viewer.result_summary test.viewer.truncated');
        });

        it('defaults every missing part of the response', () => {
            boot();
            run();

            ajax.last('/query').resolve({success: true});

            expect(JSON.parse(window.sessionStorage.getItem('tv-result:tab-1') as string))
                .toEqual({columns: [], rows: [], rowCount: 0, durationMs: 0, truncated: false});
            expect($el.find('[data-role="export"]').prop('disabled')).toBe(true);
            expect($el.find('.tv__empty').text()).toBe('test.viewer.no_rows');
        });

        it('shows the server message of a failed query in an alert and disables export', () => {
            boot();
            run();

            ajax.last('/query').reject({message: 'syntax error near FROM'});

            const $alert = $el.find('[data-role="result"] .alert.alert-error');
            expect($alert.text()).toBe('syntax error near FROM');
            expect($alert.attr('role')).toBe('alert');
            expect($el.find('[data-role="export"]').prop('disabled')).toBe(true);
            expect(status()).toBe('');
        });

        it('falls back to the generic error text when the failure has no body', () => {
            boot();
            run();

            ajax.last('/query').reject(undefined);

            expect($el.find('[data-role="result"] .alert').text()).toBe('test.viewer.query_error');
        });

        it('stores nothing for an aborted request but still frees the tab', () => {
            const viewer = boot();
            run();

            ajax.last('/query').reject(undefined, 'abort');

            expect(window.sessionStorage.getItem('tv-result:tab-1')).toBeNull();
            expect(viewer.activeTab().running).toBe(false);
            expect($el.find('[data-role="result"] .alert')).toHaveLength(0);
        });

        it('clears the previous error but keeps the previous grid while the next run is pending', () => {
            boot();
            run();
            ajax.last('/query').reject({message: 'first failure'});
            expect($el.find('[data-role="result"] .alert')).toHaveLength(1);

            run();
            expect($el.find('[data-role="result"] .alert')).toHaveLength(0);

            ajax.last('/query').resolve({success: true, columns: ['id'], rows: [{id: 1}], rowCount: 1, durationMs: 1});
            run();
            expect(grid()).toHaveLength(1);
        });

        it('a run that finishes on a background tab lands in that tab and is shown when it is opened', () => {
            const viewer = boot();
            run();
            $el.find('[data-role="tab-add"]').trigger('click'); // tab-2 becomes active, tab-1 keeps running
            expect($el.find('[data-role="run"]').prop('disabled')).toBe(false);

            ajax.last('/query').resolve({success: true, columns: ['id'], rows: [{id: 1}], rowCount: 1, durationMs: 1});

            expect(grid()).toHaveLength(0); // tab-2 has no result
            expect(window.sessionStorage.getItem('tv-result:tab-1')).not.toBeNull();
            expect(viewer.tabs[0].running).toBe(false);

            $el.find('[data-role="tab"]').eq(0).trigger('click');
            expect(grid()).toHaveLength(1);
            expect(status()).toBe('test.viewer.result_summary');
        });

        it('a background run finishing does not disturb the selection or hidden columns of the visible tab', () => {
            const viewer = boot();
            run();
            const pending = ajax.last('/query');
            $el.find('[data-role="tab-add"]').trigger('click');
            viewer.editor.setValue('SELECT 2');
            run();
            ajax.last('/query').resolve({success: true, columns: ['id', 'name'], rows: [{id: 1, name: 'a'}], rowCount: 1, durationMs: 1});
            $el.find('th[data-role="col-header"][data-column="id"]').trigger('click');
            expect($el.find('.is-col-selected')).not.toHaveLength(0);

            pending.resolve({success: true, columns: ['x'], rows: [{x: 1}], rowCount: 1, durationMs: 1});

            expect($el.find('.is-col-selected')).not.toHaveLength(0);
            expect($el.find('th[data-role="col-header"]').map((_: number, th: HTMLElement) => th.textContent).get()).toEqual(['id', 'name']);
        });

        it('tabs run independently', () => {
            const viewer = boot();
            run();
            $el.find('[data-role="tab-add"]').trigger('click');
            viewer.editor.setValue('SELECT 2');
            run();

            expect(ajax.to('/query')).toHaveLength(2);
            expect(viewer.tabs.map((t: any) => t.running)).toEqual([true, true]);
        });

        it('switching to a running tab shows its running status and disables run', () => {
            boot();
            run();
            $el.find('[data-role="tab-add"]').trigger('click');
            $el.find('[data-role="tab"]').eq(0).trigger('click');

            expect(status()).toBe('test.viewer.running');
            expect($el.find('[data-role="run"]').prop('disabled')).toBe(true);
        });
    });

    describe('per-tab result storage', () => {
        it('falls back to memory when sessionStorage is over quota, and reads the result from there', () => {
            const viewer = boot();
            vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
                throw new DOMException('full', 'QuotaExceededError');
            });
            $el.find('[data-role="run"]').trigger('click');

            ajax.last('/query').resolve({success: true, columns: ['id'], rows: [{id: 1}], rowCount: 1, durationMs: 1});

            expect(window.sessionStorage.getItem('tv-result:tab-1')).toBeNull();
            expect($el.find('[data-role="result"] table')).toHaveLength(1);
            expect(viewer.loadResult('tab-1')).toMatchObject({columns: ['id']});
        });

        it('survives sessionStorage being completely unusable', () => {
            const viewer = boot();
            vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
                throw new Error('denied');
            });
            vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
                throw new Error('denied');
            });
            vi.spyOn(Storage.prototype, 'getItem').mockImplementation(() => {
                throw new Error('denied');
            });

            viewer.storeResult('tab-1', {columns: ['a'], rows: []});
            expect(viewer.loadResult('tab-1')).toEqual({columns: ['a'], rows: []});

            viewer.removeResult('tab-1');
            expect(() => viewer.clearAllResults()).not.toThrow();
            // nothing in memory and storage throws on read: no result rather than an exception
            expect(viewer.loadResult('tab-1')).toBeNull();
        });

        it('clearing results tolerates storage that refuses removals', () => {
            const viewer = boot();
            window.sessionStorage.setItem('tv-result:tab-9', '{}');
            vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
                throw new Error('denied');
            });

            expect(() => viewer.clearAllResults()).not.toThrow();
        });

        it('a fresh successful store supersedes an older in-memory copy', () => {
            const viewer = boot();
            viewer.resultFallback['tab-1'] = {error: 'stale'};

            viewer.storeResult('tab-1', {columns: ['a'], rows: []});

            expect(viewer.resultFallback['tab-1']).toBeUndefined();
            expect(viewer.loadResult('tab-1')).toEqual({columns: ['a'], rows: []});
        });

        it('treats corrupt stored JSON as "no result"', () => {
            const viewer = boot();
            window.sessionStorage.setItem('tv-result:tab-1', '{not json');

            expect(viewer.loadResult('tab-1')).toBeNull();
        });

        it('returns null when nothing is stored', () => {
            const viewer = boot();

            expect(viewer.loadResult('tab-1')).toBeNull();
        });
    });

    describe('dispose', () => {
        it('releases everything: handlers, pending runs, results, context menu, editor, sidebar', () => {
            const viewer = boot();
            $el.find('[data-role="run"]').trigger('click');
            const pending = ajax.last('/query');
            window.sessionStorage.setItem('tv-result:tab-1', '{}');
            $('body').addClass('tv-resizing');
            const editor = viewer.editor;
            viewer.ensureContextMenu();
            expect($('.tv__context-menu')).toHaveLength(1);

            viewer.dispose();

            expect(viewer.disposed).toBe(true);
            expect(pending.abort).toHaveBeenCalled();
            expect(window.sessionStorage.getItem('tv-result:tab-1')).toBeNull();
            expect($('.tv__context-menu')).toHaveLength(0);
            expect($('body').hasClass('tv-resizing')).toBe(false);
            expect(editor.toTextArea).toHaveBeenCalled();
            expect(viewer.editor).toBeNull();
            expect(hooks.disposeSidebar).toHaveBeenCalledTimes(1);
            expect(mediator.off).toHaveBeenCalledWith('openLink:before', expect.any(Function), viewer);

            // handlers are gone
            const before = ajax.requests.length;
            $el.find('[data-role="tab-add"]').trigger('click');
            expect(ajax.requests).toHaveLength(before);
            expect(viewer.tabs).toHaveLength(1);
        });

        it('is idempotent', () => {
            const viewer = boot();
            viewer.dispose();
            hooks.disposeSidebar.mockClear();

            expect(() => viewer.dispose()).not.toThrow();
            expect(hooks.disposeSidebar).not.toHaveBeenCalled();
        });

        it('tolerates a viewer without editor or context menu', () => {
            $el.find('[data-role="editor"]').remove();
            const viewer = boot();

            expect(() => viewer.dispose()).not.toThrow();
        });
    });
});
