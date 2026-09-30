import $ from 'jquery';
import {afterEach, beforeEach, describe, expect, it} from 'vitest';
import messenger from 'oroui/js/messenger';
import ElasticViewerComponent from '../../../../Resources/js-src/app/components/elastic-viewer-component';
import {AjaxRecorder, installAjax} from '../../helpers/ajax';
import {createViewer, disposeCreatedViewers, mountViewer} from '../../helpers/query-viewer';

const flash = () => (messenger.notificationFlashMessage as any).mock.calls;

const INDICES = [
    {name: 'logs-2024', docsCount: 50, size: '1mb', health: 'green'},
    {name: 'Orders', docsCount: 900, size: '2mb', health: 'yellow'},
    {name: 'audit', docsCount: 0, size: '1kb', health: 'green'},
    {name: 'audit-b', docsCount: 0, size: '1kb', health: 'green'}
];

describe('ElasticViewerComponent', () => {
    let $el: any;
    let ajax: AjaxRecorder;

    beforeEach(() => {
        window.sessionStorage.clear();
        ajax = installAjax();
        $el = mountViewer();
    });

    afterEach(() => {
        disposeCreatedViewers();
    });

    function boot(): any {
        return createViewer($el, {indicesUrl: '/indices'}, ElasticViewerComponent);
    }

    function withIndices(indices: any[] = INDICES): any {
        const viewer = boot();
        ajax.last('/indices').resolve({indices});
        return viewer;
    }

    const names = () => $el.find('[data-role="index-item"]').map((_: number, a: HTMLElement) => a.getAttribute('data-name')).get();
    const key = () => $el.find('[data-role="order-key"]');

    describe('configuration', () => {
        it('exposes its naming hooks to the shared base', () => {
            const viewer = boot();

            expect(viewer.cssPrefix()).toBe('aaxis-es-viewer');
            expect(viewer.transPrefix()).toBe('aaxis.devtools.elastic_viewer');
            expect(viewer.eventNs()).toBe('aaxisEsViewer');
            expect(viewer.storagePrefix()).toBe('aaxis-esv-result:');
            expect(viewer.editorMode()).toBe('text/x-sql');
            expect(viewer.sidebarListRole()).toBe('indices');
        });
    });

    describe('sidebar', () => {
        it('loads the indices, showing a spinner meanwhile', () => {
            boot();

            expect($el.find('[data-role="indices"] .fa-spinner')).toHaveLength(1);
            expect(ajax.last('/indices').options).toMatchObject({url: '/indices', method: 'GET', errorHandlerMessage: false});
        });

        it('lists indices alphabetically (locale order) with icon, name and tooltip', () => {
            withIndices();

            expect(names()).toEqual(['audit', 'audit-b', 'logs-2024', 'Orders']);
            const $first = $el.find('[data-role="index-item"]').first();
            expect($first.attr('title')).toBe('audit');
            expect($first.find('.fa-database')).toHaveLength(1);
            expect($first.find('.aaxis-es-viewer__index-name').text()).toBe('audit');
            expect($el.find('.aaxis-es-viewer__index-count')).toHaveLength(0);
        });

        it('shows the empty note for no indices, and tolerates a missing list', () => {
            const viewer = boot();
            ajax.last('/indices').resolve({});

            expect($el.find('[data-role="indices"]').text()).toBe('aaxis.devtools.elastic_viewer.no_indices');
            expect(viewer.indices).toEqual([]);
        });

        it('flashes and empties the list when loading fails', () => {
            boot();
            ajax.last('/indices').reject({});

            expect(flash()).toEqual([['error', 'aaxis.devtools.elastic_viewer.indices_error']]);
            expect($el.find('[data-role="indices"]').text()).toBe('aaxis.devtools.elastic_viewer.no_indices');
        });

        it('filters by the sidebar text, case-insensitively', () => {
            withIndices();
            $el.find('[data-role="filter"]').val('AUD').trigger('input');
            expect(names()).toEqual(['audit', 'audit-b']);

            $el.find('[data-role="filter"]').val('zzz').trigger('input');
            expect($el.find('[data-role="indices"]').text()).toBe('aaxis.devtools.elastic_viewer.no_indices');
        });

        it('reverses the order with the direction button', () => {
            withIndices();
            $el.find('[data-role="order-dir"]').trigger('click');

            expect(names()).toEqual(['Orders', 'logs-2024', 'audit-b', 'audit']);
        });

        it('reloads on refresh', () => {
            withIndices();
            $el.find('[data-role="refresh"]').trigger('click');

            expect(ajax.to('/indices')).toHaveLength(2);
        });
    });

    describe('ordering', () => {
        it('starts alphabetical', () => {
            boot();

            expect(key().attr('title')).toBe('aaxis.devtools.elastic_viewer.order_by_name');
            expect(key().find('.fa-font')).toHaveLength(1);
        });

        it('toggles to document count, showing a badge with the count, and back', () => {
            withIndices();
            key().trigger('click');

            expect(key().attr('title')).toBe('aaxis.devtools.elastic_viewer.order_by_count');
            expect(key().find('.aaxis-es-viewer__order-num').text()).toBe('1');
            // ties (0 docs) fall back to the name
            expect(names()).toEqual(['audit', 'audit-b', 'logs-2024', 'Orders']);
            const $badge = $el.find('.aaxis-es-viewer__index-count');
            expect($badge.map((_: number, e: HTMLElement) => e.textContent).get()).toEqual(['0', '0', '50', '900']);
            expect($badge.first().attr('title')).toBe('aaxis.devtools.elastic_viewer.doc_count');

            $el.find('[data-role="order-dir"]').trigger('click');
            expect(names()).toEqual(['Orders', 'logs-2024', 'audit-b', 'audit']);

            key().trigger('click');
            expect(key().attr('title')).toBe('aaxis.devtools.elastic_viewer.order_by_name');
            expect($el.find('.aaxis-es-viewer__index-count')).toHaveLength(0);
        });

        it('treats a missing document count as zero', () => {
            withIndices([{name: 'b', size: '', health: 'green'}, {name: 'a', docsCount: 3, size: '', health: 'green'}]);
            key().trigger('click');

            expect(names()).toEqual(['b', 'a']);
        });
    });

    describe('clicking an index', () => {
        it('writes an ES|QL FROM query into the editor and focuses it', () => {
            const viewer = withIndices();
            const click = $.Event('click');
            $el.find('[data-role="index-item"][data-name="logs-2024"]').trigger(click);

            expect(click.isDefaultPrevented()).toBe(true);
            expect(viewer.editor.getValue()).toBe('FROM logs-2024\n| LIMIT 100');
            expect(viewer.editor.focus).toHaveBeenCalled();
        });

        it('does nothing without an editor', () => {
            $el.find('[data-role="editor"]').remove();
            withIndices();

            expect(() => $el.find('[data-role="index-item"]').first().trigger('click')).not.toThrow();
        });
    });

    describe('ES|QL beautifier', () => {
        const format = (query: string) => boot().formatQuery(query);

        it('puts each pipe command on its own line and upper-cases commands and secondary keywords', () => {
            expect(format('from logs* | where a > 1 and b == "x" | stats c = count(*) by host | sort c desc | limit 5')).toBe([
                'FROM logs*',
                '| WHERE a > 1 AND b == "x"',
                '| STATS c = count(*) BY host',
                '| SORT c DESC',
                '| LIMIT 5'
            ].join('\n'));
        });

        it('splits only on top-level pipes', () => {
            expect(format('from a | eval z = f(a | b) | where z in ("a","b")')).toBe([
                'FROM a',
                '| EVAL z = f(a | b)',
                '| WHERE z IN ("a","b")'
            ].join('\n'));
        });

        it('handles a source command without pipes and blank input', () => {
            expect(format('row a = 1')).toBe('ROW a = 1');
            expect(format('')).toBe('');
            expect(format('   \n ')).toBe('');
        });

        it('ignores empty segments from leading or trailing pipes', () => {
            expect(format('| from x |')).toBe('FROM x');
        });

        it('protects strings, triple-quoted strings, backtick identifiers and line comments', () => {
            expect(format('FROM x | WHERE `weird | name` is not null and s == """triple | quote"""')).toBe([
                'FROM x',
                '| WHERE `weird | name` IS NOT NULL AND s == """triple | quote"""'
            ].join('\n'));
            expect(format('from a // note | not a pipe\n| keep x, y')).toBe('FROM a // note | not a pipe\n| KEEP x, y');
            expect(format('from a | where s == "in | and"')).toBe('FROM a\n| WHERE s == "in | and"');
        });

        it('recovers from unbalanced closing parentheses', () => {
            expect(format('from a) | limit 1')).toBe('FROM a)\n| LIMIT 1');
        });
    });
});
