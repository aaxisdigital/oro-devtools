import $ from 'jquery';
import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import messenger from 'oroui/js/messenger';
import {AjaxRecorder, installAjax} from '../../helpers/ajax';
import {createViewer, disposeCreatedViewers, mountViewer} from '../../helpers/query-viewer';

const flash = () => (messenger.notificationFlashMessage as any).mock.calls;

const RESULT = {
    success: true,
    columns: ['id', 'name', 'meta'],
    rows: [
        {id: 1, name: 'alpha', meta: {a: 1}},
        {id: 2, name: 'be"ta, x', meta: null},
        {id: 3, name: 'gamma', meta: 'line\nbreak'}
    ],
    rowCount: 3,
    durationMs: 5
};

describe('AbstractQueryViewerComponent result grid', () => {
    let $el: any;
    let ajax: AjaxRecorder;

    beforeEach(() => {
        window.sessionStorage.clear();
        ajax = installAjax();
        $el = mountViewer();
    });

    afterEach(() => {
        disposeCreatedViewers();
        vi.useRealTimers();
        vi.unstubAllGlobals();
        document.cookie = '_csrf=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
        document.cookie = 'https-_csrf=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
    });

    /** Runs a query and answers it with `response`, leaving the grid rendered. */
    function withResult(response: any = RESULT, options: Record<string, unknown> = {}): any {
        const viewer = createViewer($el, options);
        $el.find('[data-role="run"]').trigger('click');
        ajax.last('/query').resolve(response);
        return viewer;
    }

    const headers = () => $el.find('[data-role="result"] thead th').map((_: number, th: HTMLElement) => th.textContent).get();
    const cells = (row: number) => $el.find('[data-role="result"] tbody tr').eq(row).find('td')
        .map((_: number, td: HTMLElement) => td.textContent).get();
    const $header = (column: string) => $el.find('th[data-role="col-header"][data-column="' + column + '"]');
    const $cell = (row: number, column: string) => $el.find('tbody tr').eq(row).find('td[data-column="' + column + '"]');
    const $rowNum = (row: number) => $el.find('tbody tr').eq(row).find('[data-role="row-select"]');
    const click = (target: any, props: object = {}) => target.trigger($.Event('click', props));

    describe('rendering', () => {
        it('draws a header and a body with a row-number column', () => {
            withResult();

            expect($el.find('table.grid.tv__grid')).toHaveLength(1);
            expect(headers()).toEqual(['#', 'id', 'name', 'meta']);
            expect($el.find('tbody tr')).toHaveLength(3);
            expect($rowNum(0).text()).toBe('1');
            expect($rowNum(2).text()).toBe('3');
            expect($el.find('tbody tr').eq(1).attr('data-row-index')).toBe('1');
        });

        it('omits the row-number column when disabled', () => {
            withResult(RESULT, {showRowNumbers: false});

            expect(headers()).toEqual(['id', 'name', 'meta']);
            expect($el.find('[data-role="row-select"]')).toHaveLength(0);
        });

        it('renders values: null as a marked (null), objects as JSON, everything else as text', () => {
            withResult({...RESULT, rows: [{id: 0, name: false, meta: {a: [1]}}, {id: 2, name: undefined, meta: null}]});

            expect(cells(0).slice(1)).toEqual(['0', 'false', '{"a":[1]}']);
            expect($cell(1, 'meta').text()).toBe('(null)');
            expect($cell(1, 'meta').hasClass('tv__null-cell')).toBe(true);
            expect($cell(1, 'name').text()).toBe('(null)');
            expect($cell(0, 'id').hasClass('tv__null-cell')).toBe(false);
        });

        it('shows a plain column name when the result has no column types', () => {
            withResult();

            expect($header('id').text()).toBe('id');
            expect($header('id').find('.tv__col-name')).toHaveLength(0);
        });

        it('shows a name + type sub-label when the result carries column types', () => {
            withResult({...RESULT, columnTypes: {id: 'long', name: 'keyword'}});

            expect($header('id').find('.tv__col-name').text()).toBe('id');
            expect($header('id').find('.tv__col-type').text()).toBe('long');
            // a column without a known type has a name but no type label
            expect($header('meta').find('.tv__col-name').text()).toBe('meta');
            expect($header('meta').find('.tv__col-type')).toHaveLength(0);
        });

        it('shows "no rows" when the result has no columns', () => {
            withResult({success: true, columns: [], rows: [], rowCount: 0, durationMs: 1});

            expect($el.find('[data-role="result"] table')).toHaveLength(0);
            expect($el.find('.tv__empty').text()).toBe('test.viewer.no_rows');
        });

        it('shows the stored error instead of a grid', () => {
            const viewer = createViewer($el);
            viewer.storeResult('tab-1', {error: 'stored failure'});
            viewer.renderActiveResult();

            expect($el.find('.alert-error').text()).toBe('stored failure');
            expect($el.find('table')).toHaveLength(0);
        });
    });

    describe('without a result', () => {
        it('has no visible columns or rows and renders no table', () => {
            const viewer = createViewer($el);

            expect(viewer.visibleColumns()).toEqual([]);
            expect(viewer.visibleRows()).toEqual([]);
            viewer.renderTable();
            expect($el.find('[data-role="result"]').children()).toHaveLength(0);
        });
    });

    describe('column selection', () => {
        it('selects a column header and its cells', () => {
            withResult();
            click($header('name'));

            expect($header('name').hasClass('is-col-selected')).toBe(true);
            expect($cell(0, 'name').hasClass('is-col-selected')).toBe(true);
            expect($cell(0, 'id').hasClass('is-col-selected')).toBe(false);
        });

        it('a plain click replaces the selection', () => {
            withResult();
            click($header('id'));
            click($header('name'));

            expect($el.find('.is-col-selected').filter('th')).toHaveLength(1);
            expect($header('name').hasClass('is-col-selected')).toBe(true);
        });

        it('ctrl/meta-click toggles columns in and out', () => {
            withResult();
            click($header('id'));
            click($header('meta'), {ctrlKey: true});
            expect($el.find('th.is-col-selected').map((_: number, e: HTMLElement) => e.textContent).get()).toEqual(['id', 'meta']);

            click($header('id'), {metaKey: true});
            expect($el.find('th.is-col-selected').map((_: number, e: HTMLElement) => e.textContent).get()).toEqual(['meta']);
        });

        it('shift-click selects the range from the last clicked column, in either direction', () => {
            withResult();
            click($header('meta'));
            click($header('id'), {shiftKey: true});
            expect($el.find('th.is-col-selected')).toHaveLength(3);

            click($header('name'));
            click($header('meta'), {shiftKey: true});
            expect($el.find('th.is-col-selected').map((_: number, e: HTMLElement) => e.textContent).get()).toEqual(['name', 'meta']);
        });

        it('shift-click without an anchor selects just that column', () => {
            withResult();
            click($header('name'), {shiftKey: true});

            expect($el.find('th.is-col-selected').map((_: number, e: HTMLElement) => e.textContent).get()).toEqual(['name']);
        });

        it('ignores a header whose column is not visible', () => {
            const viewer = withResult();
            viewer.onColumnHeaderClick($.Event('click', {currentTarget: $('<th data-column="ghost"/>').get(0)}));

            expect($el.find('.is-col-selected')).toHaveLength(0);
        });

        it('matches columns whose names contain quotes', () => {
            withResult({success: true, columns: ['we"ird'], rows: [{'we"ird': 1}], rowCount: 1, durationMs: 1});
            click($el.find('th[data-role="col-header"]'));

            expect($el.find('.is-col-selected')).toHaveLength(2);
        });

        it('switching from rows to columns clears the row selection', () => {
            withResult();
            click($rowNum(0));
            click($header('id'));

            expect($el.find('tr.is-row-selected, td.is-row-selected')).toHaveLength(0);
        });
    });

    describe('row selection', () => {
        const selectedRows = () => $el.find('tbody tr.is-row-selected').map((_: number, tr: HTMLElement) => tr.getAttribute('data-row-index')).get();

        it('clicking a cell or the row number selects that row', () => {
            withResult();
            click($cell(1, 'name'));
            expect(selectedRows()).toEqual(['1']);

            click($rowNum(2));
            expect(selectedRows()).toEqual(['2']);
        });

        it('ctrl-click toggles rows; shift-click selects a range', () => {
            withResult();
            click($rowNum(0));
            click($rowNum(2), {ctrlKey: true});
            expect(selectedRows()).toEqual(['0', '2']);

            click($rowNum(0), {ctrlKey: true});
            expect(selectedRows()).toEqual(['2']);

            click($rowNum(0));
            click($rowNum(2), {shiftKey: true});
            expect(selectedRows()).toEqual(['0', '1', '2']);

            click($rowNum(2));
            click($rowNum(1), {shiftKey: true});
            expect(selectedRows()).toEqual(['1', '2']);
        });

        it('shift-click without an anchor selects just that row', () => {
            withResult();
            click($rowNum(1), {shiftKey: true});

            expect(selectedRows()).toEqual(['1']);
        });

        it('meta-click (Cmd on macOS) toggles rows like ctrl-click', () => {
            withResult();
            click($rowNum(0));
            click($rowNum(2), {metaKey: true});

            expect(selectedRows()).toEqual(['0', '2']);
        });

        it('switching from columns to rows clears the column selection', () => {
            withResult();
            click($header('id'));
            click($rowNum(0));

            expect($el.find('.is-col-selected')).toHaveLength(0);
        });

        it('ignores clicks that are not inside a data row', () => {
            const viewer = withResult();
            viewer.onRowClick({currentTarget: $('<td/>').get(0)});

            expect(selectedRows()).toEqual([]);
        });

        it('a new result clears the selection', () => {
            withResult();
            click($rowNum(0));
            $el.find('[data-role="run"]').trigger('click');
            ajax.last('/query').resolve(RESULT);

            expect($el.find('.is-row-selected')).toHaveLength(0);
        });
    });

    describe('context menu', () => {
        const menu = () => $('.tv__context-menu');
        const contextMenu = (x = 30, y = 40) => $el.find('[data-role="result"]').trigger($.Event('contextmenu', {clientX: x, clientY: y}));

        beforeEach(() => {
            vi.useFakeTimers();
        });

        it('does not open without a selection', () => {
            withResult();
            const event = $.Event('contextmenu', {clientX: 1, clientY: 1});
            $el.find('[data-role="result"]').trigger(event);

            expect(event.isDefaultPrevented()).toBe(false);
            expect(menu()).toHaveLength(0);
        });

        it('does not open when export is not allowed', () => {
            withResult(RESULT, {allowExport: false});
            click($header('id'));
            const event = $.Event('contextmenu', {clientX: 1, clientY: 1});
            $el.find('[data-role="result"]').trigger(event);

            expect(event.isDefaultPrevented()).toBe(false);
            expect(menu()).toHaveLength(0);
        });

        it('opens at the cursor with copy JSON / copy CSV / hide entries, built once', () => {
            withResult();
            click($header('id'));

            const event = $.Event('contextmenu', {clientX: 30, clientY: 40});
            $el.find('[data-role="result"]').trigger(event);

            expect(event.isDefaultPrevented()).toBe(true);
            expect(menu()).toHaveLength(1);
            expect(menu().attr('role')).toBe('menu');
            expect(menu().css('display')).toBe('block');
            expect(menu().css('left')).toBe('30px');
            expect(menu().css('top')).toBe('40px');
            expect(menu().find('a').map((_: number, a: HTMLElement) => a.getAttribute('data-action')).get())
                .toEqual(['copy-json', 'copy-csv', 'hide']);
            expect(menu().find('a').map((_: number, a: HTMLElement) => a.textContent).get())
                .toEqual(['test.viewer.context_copy_json', 'test.viewer.context_copy_csv', 'test.viewer.context_hide']);

            contextMenu(50, 60);
            expect(menu()).toHaveLength(1);
            expect(menu().css('left')).toBe('50px');
        });

        it('closes on a mouse press outside the menu, not inside it', () => {
            withResult();
            click($header('id'));
            contextMenu();
            vi.runAllTimers();

            menu().find('a').first().trigger('mousedown');
            expect(menu().css('display')).toBe('block');

            $(document.body).trigger('mousedown');
            expect(menu().css('display')).toBe('none');
        });

        it('closes on Escape but not on other keys', () => {
            withResult();
            click($header('id'));
            contextMenu();
            vi.runAllTimers();

            $(document).trigger($.Event('keydown', {keyCode: 65}));
            expect(menu().css('display')).toBe('block');

            $(document).trigger($.Event('keydown', {keyCode: 27}));
            expect(menu().css('display')).toBe('none');
        });

        it('stops listening to the document once closed', () => {
            withResult();
            click($header('id'));
            contextMenu();
            vi.runAllTimers();
            $(document).trigger($.Event('keydown', {keyCode: 27}));
            menu().css('display', 'block');

            $(document).trigger($.Event('keydown', {keyCode: 27}));

            expect(menu().css('display')).toBe('block');
        });

        it('"hide" removes the selected columns from the grid and clears the selection', () => {
            const viewer = withResult();
            click($header('name'));
            click($header('meta'), {ctrlKey: true});
            contextMenu();

            menu().find('a[data-action="hide"]').trigger('click');

            expect(headers()).toEqual(['#', 'id']);
            expect(viewer.hiddenColumns).toEqual(['name', 'meta']);
            expect($el.find('.is-col-selected')).toHaveLength(0);
            expect(menu().css('display')).toBe('none');
        });

        it('"hide" removes selected rows but keeps their original indexes and renumbers the rest', () => {
            const viewer = withResult();
            click($rowNum(0));
            contextMenu();
            menu().find('a[data-action="hide"]').trigger('click');

            expect(viewer.hiddenRows).toEqual([0]);
            expect($el.find('tbody tr').map((_: number, tr: HTMLElement) => tr.getAttribute('data-row-index')).get()).toEqual(['1', '2']);
            expect($rowNum(0).text()).toBe('1');
        });

        it('hiding the same selection twice records it once', () => {
            const viewer = withResult();
            click($header('id'));
            viewer.hideSelection();
            viewer.selectionMode = 'columns';
            viewer.selectedColumns = ['id'];
            viewer.hideSelection();

            expect(viewer.hiddenColumns).toEqual(['id']);
        });

        it('hiding with nothing selected changes nothing', () => {
            const viewer = withResult();
            viewer.hideSelection();

            expect(headers()).toEqual(['#', 'id', 'name', 'meta']);
        });

        it('hidden columns and rows come back with the next result', () => {
            const viewer = withResult();
            click($header('id'));
            viewer.hideSelection();
            expect(headers()).not.toContain('id');

            $el.find('[data-role="run"]').trigger('click');
            ajax.last('/query').resolve(RESULT);

            expect(headers()).toContain('id');
            expect(viewer.hiddenColumns).toEqual([]);
        });
    });

    describe('copying', () => {
        let writeText: ReturnType<typeof vi.fn>;

        beforeEach(() => {
            writeText = vi.fn(() => Promise.resolve());
            Object.defineProperty(window.navigator, 'clipboard', {value: {writeText}, configurable: true});
        });

        afterEach(() => {
            delete (window.navigator as any).clipboard;
            delete (document as any).execCommand;
        });

        const tick = () => new Promise(resolve => setTimeout(resolve, 0));

        it('copies the selected columns as JSON (all visible rows) and confirms', async () => {
            const viewer = withResult();
            click($header('id'));
            click($header('name'), {ctrlKey: true});

            viewer.onContextAction('copy-json');
            await tick();

            expect(writeText).toHaveBeenCalledWith(JSON.stringify([
                {id: 1, name: 'alpha'}, {id: 2, name: 'be"ta, x'}, {id: 3, name: 'gamma'}
            ], null, 2));
            expect(flash()).toEqual([['success', 'test.viewer.copied']]);
        });

        it('copies the selected rows over the visible columns, skipping hidden rows', async () => {
            const viewer = withResult();
            click($rowNum(0));
            click($rowNum(1), {ctrlKey: true});
            viewer.hiddenColumns = ['meta'];
            viewer.hiddenRows = [1];

            viewer.onContextAction('copy-json');
            await tick();

            expect(writeText).toHaveBeenCalledWith(JSON.stringify([{id: 1, name: 'alpha'}], null, 2));
        });

        it('turns missing values into null when copying', async () => {
            const viewer = withResult({success: true, columns: ['a', 'b'], rows: [{a: 1}], rowCount: 1, durationMs: 1});
            click($header('b'));

            viewer.onContextAction('copy-json');
            await tick();

            expect(writeText).toHaveBeenCalledWith(JSON.stringify([{b: null}], null, 2));
        });

        it('copies CSV with a header row, quoting cells that need it', async () => {
            const viewer = withResult();
            click($header('name'));
            click($header('meta'), {ctrlKey: true});

            viewer.onContextAction('copy-csv');
            await tick();

            expect(writeText).toHaveBeenCalledWith([
                'name,meta',
                'alpha,"{""a"":1}"',
                '"be""ta, x",',
                'gamma,"line\nbreak"'
            ].join('\r\n'));
        });

        it('quotes cells containing a bare carriage return', async () => {
            const viewer = withResult({success: true, columns: ['a'], rows: [{a: 'x\ry'}, {a: 'plain'}], rowCount: 2, durationMs: 1});
            click($header('a'));

            viewer.onContextAction('copy-csv');
            await tick();

            expect(writeText).toHaveBeenCalledWith('a\r\n"x\ry"\r\nplain');
        });

        it('does not copy without a result', () => {
            const viewer = createViewer($el);

            viewer.onContextAction('copy-json');

            expect(writeText).not.toHaveBeenCalled();
        });

        it('does not copy a result that has nothing selected', () => {
            const viewer = withResult();

            viewer.onContextAction('copy-csv');

            expect(writeText).not.toHaveBeenCalled();
        });

        it('flashes an error when the clipboard rejects', async () => {
            writeText.mockReturnValue(Promise.reject(new Error('denied')));
            const viewer = withResult();
            click($header('id'));

            viewer.onContextAction('copy-json');
            await tick();

            expect(flash()).toEqual([['error', 'test.viewer.copy_error']]);
        });

        it('falls back to a hidden textarea and execCommand without the async clipboard API', () => {
            delete (window.navigator as any).clipboard;
            const execCommand = vi.fn(() => true);
            (document as any).execCommand = execCommand;
            const seen: string[] = [];
            execCommand.mockImplementation(() => {
                seen.push((document.body.querySelector('textarea[style*="fixed"]') as HTMLTextAreaElement).value);
                return true;
            });
            const viewer = withResult();
            click($header('id'));

            viewer.onContextAction('copy-json');

            expect(execCommand).toHaveBeenCalledWith('copy');
            expect(seen).toEqual([JSON.stringify([{id: 1}, {id: 2}, {id: 3}], null, 2)]);
            expect(flash()).toEqual([['success', 'test.viewer.copied']]);
            expect(document.body.querySelector('textarea[style*="fixed"]')).toBeNull();
        });

        it('flashes an error when the fallback copy throws, and still removes the textarea', () => {
            delete (window.navigator as any).clipboard;
            (document as any).execCommand = vi.fn(() => {
                throw new Error('unsupported');
            });
            const viewer = withResult();
            click($header('id'));

            viewer.onContextAction('copy-csv');

            expect(flash()).toEqual([['error', 'test.viewer.copy_error']]);
            expect(document.body.querySelector('textarea[style*="fixed"]')).toBeNull();
        });

        it('uses the fallback when only writeText is missing from the clipboard object', () => {
            Object.defineProperty(window.navigator, 'clipboard', {value: {}, configurable: true});
            (document as any).execCommand = vi.fn(() => true);
            const viewer = withResult();
            click($header('id'));

            viewer.onContextAction('copy-json');

            expect((document as any).execCommand).toHaveBeenCalledWith('copy');
        });
    });

    describe('export', () => {
        let fetchMock: ReturnType<typeof vi.fn>;
        let anchorClicks: Array<{href: string; download: string}>;

        beforeEach(() => {
            fetchMock = vi.fn(() => Promise.resolve({ok: true, blob: () => Promise.resolve(new Blob(['x']))}));
            vi.stubGlobal('fetch', fetchMock);
            anchorClicks = [];
            vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
                anchorClicks.push({href: this.href, download: this.download});
            });
            (window.URL as any).createObjectURL = vi.fn(() => 'blob:fake');
            (window.URL as any).revokeObjectURL = vi.fn();
        });

        afterEach(() => {
            delete (window.URL as any).createObjectURL;
            delete (window.URL as any).revokeObjectURL;
        });

        const tick = () => new Promise(resolve => setTimeout(resolve, 0));

        it('posts the visible columns and rows in the chosen format with the CSRF header', async () => {
            document.cookie = '_csrf=tok%20en';
            const viewer = withResult();
            viewer.hiddenColumns = ['meta'];
            viewer.hiddenRows = [1];

            const clickEvent = $.Event('click');
            $el.find('[data-role="export-format"][data-format="csv"]').trigger(clickEvent);
            await tick();

            expect(clickEvent.isDefaultPrevented()).toBe(true);
            const [url, init] = fetchMock.mock.calls[0];
            expect(url).toBe('/export');
            expect(init).toMatchObject({
                method: 'POST',
                credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Header': 'tok en'}
            });
            expect(JSON.parse(init.body)).toEqual({
                format: 'csv',
                columns: ['id', 'name'],
                rows: [{id: 1, name: 'alpha'}, {id: 3, name: 'gamma'}]
            });
        });

        it('downloads the returned blob as export.<format> and releases the object URL', async () => {
            withResult();
            $el.find('[data-role="export-format"][data-format="json"]').trigger('click');
            await tick();

            expect(anchorClicks).toEqual([{href: 'blob:fake', download: 'export.json'}]);
            expect((window.URL as any).revokeObjectURL).toHaveBeenCalledWith('blob:fake');
            expect(document.body.querySelector('a[download]')).toBeNull();
        });

        it('sends an empty CSRF token when the cookie is absent', async () => {
            withResult();
            $el.find('[data-role="export-format"]').first().trigger('click');
            await tick();

            expect(fetchMock.mock.calls[0][1].headers['X-CSRF-Header']).toBe('');
        });

        it('reads the https-prefixed CSRF cookie on https pages', async () => {
            const original = window.location;
            Object.defineProperty(window, 'location', {value: {...original, protocol: 'https:'}, configurable: true});
            try {
                document.cookie = 'https-_csrf=secure-token';
                document.cookie = '_csrf=plain-token';
                withResult();
                $el.find('[data-role="export-format"]').first().trigger('click');
                await tick();

                expect(fetchMock.mock.calls[0][1].headers['X-CSRF-Header']).toBe('secure-token');
            } finally {
                Object.defineProperty(window, 'location', {value: original, configurable: true});
            }
        });

        it('flashes an error when the server refuses', async () => {
            fetchMock.mockResolvedValue({ok: false});
            withResult();
            $el.find('[data-role="export-format"]').first().trigger('click');
            await tick();

            expect(flash()).toEqual([['error', 'test.viewer.export_error']]);
            expect(anchorClicks).toEqual([]);
        });

        it('flashes an error when the request itself fails', async () => {
            fetchMock.mockRejectedValue(new Error('network'));
            withResult();
            $el.find('[data-role="export-format"]').first().trigger('click');
            await tick();

            expect(flash()).toEqual([['error', 'test.viewer.export_error']]);
        });

        it('exports nothing without a result', () => {
            createViewer($el);

            $el.find('[data-role="export-format"]').first().trigger('click');

            expect(fetchMock).not.toHaveBeenCalled();
        });

        it('exports nothing when every column is hidden', () => {
            const viewer = withResult();
            viewer.hiddenColumns = ['id', 'name', 'meta'];

            $el.find('[data-role="export-format"]').first().trigger('click');

            expect(fetchMock).not.toHaveBeenCalled();
        });
    });
});
