import $ from 'jquery';
import {afterEach, beforeEach, describe, expect, it} from 'vitest';
import messenger from 'oroui/js/messenger';
import DatabaseViewerComponent from '../../../../Resources/js-src/app/components/database-viewer-component';
import {AjaxRecorder, installAjax} from '../../helpers/ajax';
import {createViewer, disposeCreatedViewers, mountViewer} from '../../helpers/query-viewer';

const flash = () => (messenger.notificationFlashMessage as any).mock.calls;

const OBJECTS = [
    {name: 'oro_user', type: 'table', count: '120', countValue: 120},
    {name: 'a_table', type: 'table', count: '5', countValue: 5},
    {name: 'v_report', type: 'view', count: '9', countValue: 9},
    {name: 'fn_calc', type: 'function'},
    {name: 'z_table', type: 'table'},
    {name: 'weird', type: 'sequence'}
];

describe('DatabaseViewerComponent', () => {
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
        return createViewer($el, {objectsUrl: '/objects', columnsUrl: '/columns'}, DatabaseViewerComponent);
    }

    /** Boots and answers the objects request with `objects`. */
    function withObjects(objects: any[] = OBJECTS): any {
        const viewer = boot();
        ajax.last('/objects').resolve({objects});
        return viewer;
    }

    const names = () => $el.find('[data-role="object-item"]').map((_: number, a: HTMLElement) => a.getAttribute('data-name')).get();
    const item = (name: string) => $el.find('[data-role="object-item"][data-name="' + name + '"]');
    const expander = (name: string) => $el.find('[data-role="object-expand"][data-name="' + name + '"]');

    describe('configuration', () => {
        it('exposes its naming hooks to the shared base', () => {
            const viewer = boot();

            expect(viewer.cssPrefix()).toBe('aaxis-db-viewer');
            expect(viewer.transPrefix()).toBe('aaxis.devtools.database_viewer');
            expect(viewer.eventNs()).toBe('aaxisDbViewer');
            expect(viewer.storagePrefix()).toBe('aaxis-dbv-result:');
            expect(viewer.editorMode()).toBe('text/x-pgsql');
            expect(viewer.sidebarListRole()).toBe('objects');
        });

        it('starts with every type visible, alphabetical, ascending', () => {
            const viewer = boot();

            expect(viewer.showTables && viewer.showViews && viewer.showFunctions).toBe(true);
            expect(viewer.orderMode).toBe('name');
            expect(viewer.sortDir).toBe('asc');
        });
    });

    describe('loading the sidebar', () => {
        it('shows a spinner while the objects are fetched without any metric', () => {
            boot();

            expect($el.find('[data-role="objects"] .aaxis-db-viewer__loading .fa-spinner')).toHaveLength(1);
            expect(ajax.last('/objects').options).toMatchObject({
                url: '/objects', method: 'GET', data: {metric: 'none'}, errorHandlerMessage: false
            });
        });

        it('groups objects tables, then views, then functions, each alphabetical', () => {
            withObjects();

            expect(names()).toEqual(['a_table', 'oro_user', 'z_table', 'v_report', 'fn_calc', 'weird']);
        });

        it('shows the empty note when there are no objects, and tolerates a missing list', () => {
            const viewer = boot();
            ajax.last('/objects').resolve({});

            expect($el.find('[data-role="objects"]').text()).toBe('aaxis.devtools.database_viewer.no_objects');
            expect(viewer.objects).toEqual([]);
        });

        it('flashes and shows the empty list when loading fails', () => {
            boot();
            ajax.last('/objects').reject({});

            expect(flash()).toEqual([['error', 'aaxis.devtools.database_viewer.tables_error']]);
            expect($el.find('[data-role="objects"]').text()).toBe('aaxis.devtools.database_viewer.no_objects');
        });

        it('reloads on refresh', () => {
            withObjects();
            $el.find('[data-role="refresh"]').trigger('click');

            expect(ajax.to('/objects')).toHaveLength(2);
        });
    });

    describe('rendering', () => {
        it('gives tables and views an expander and a type icon; functions get a placeholder', () => {
            withObjects();

            expect(expander('a_table').attr('aria-expanded')).toBe('false');
            expect(expander('a_table').attr('aria-label')).toBe('a_table');
            expect(expander('a_table').find('.fa').hasClass('fa-chevron-right')).toBe(true);
            expect(expander('v_report')).toHaveLength(1);
            expect(expander('fn_calc')).toHaveLength(0);
            expect(item('fn_calc').closest('.aaxis-db-viewer__object-row').find('.aaxis-db-viewer__object-toggle--empty')).toHaveLength(1);
            expect(item('a_table').find('.fa').first().hasClass('fa-table')).toBe(true);
            expect(item('v_report').find('.fa').first().hasClass('fa-eye')).toBe(true);
            expect(item('fn_calc').find('.fa').first().hasClass('fa-code')).toBe(true);
            expect(item('weird').find('.fa').first().hasClass('fa-question')).toBe(true);
            expect(item('a_table').attr('data-type')).toBe('table');
            expect(item('a_table').attr('title')).toBe('a_table');
        });

        it('hides the count badge while ordering by name', () => {
            withObjects();

            expect($el.find('.aaxis-db-viewer__object-count')).toHaveLength(0);
        });

        it('filters by the sidebar text, case-insensitively', () => {
            withObjects();
            $el.find('[data-role="filter"]').val('TABLE').trigger('input');

            expect(names()).toEqual(['a_table', 'z_table']);

            $el.find('[data-role="filter"]').val('nothing-matches').trigger('input');
            expect($el.find('[data-role="objects"]').text()).toBe('aaxis.devtools.database_viewer.no_objects');
        });

        it('the direction button reverses each group (groups keep their order)', () => {
            withObjects();
            $el.find('[data-role="order-dir"]').trigger('click');

            expect(names()).toEqual(['z_table', 'oro_user', 'a_table', 'v_report', 'fn_calc', 'weird']);
            expect($el.find('[data-role="order-dir"] .fa').attr('class')).toBe('fa fa-sort-amount-desc');
        });
    });

    describe('type toggles', () => {
        const toggle = (type: string) => $el.find('[data-role="type-toggle"][data-type="' + type + '"]');

        it('hides and re-shows each object type and reflects the state on the button', () => {
            withObjects();

            toggle('table').trigger('click');
            expect(names()).toEqual(['v_report', 'fn_calc', 'weird']);
            expect(toggle('table').hasClass('is-active')).toBe(false);
            expect(toggle('table').attr('aria-pressed')).toBe('false');

            toggle('view').trigger('click');
            toggle('function').trigger('click');
            expect(names()).toEqual(['weird']);

            toggle('table').trigger('click');
            toggle('view').trigger('click');
            toggle('function').trigger('click');
            expect(names()).toEqual(['a_table', 'oro_user', 'z_table', 'v_report', 'fn_calc', 'weird']);
            expect(toggle('table').attr('aria-pressed')).toBe('true');
        });

        it('a toggle of an unknown type only repaints', () => {
            withObjects();
            $el.find('[data-role="type-toggle"]').first().attr('data-type', 'mystery').trigger('click');

            expect(names()).toHaveLength(6);
        });
    });

    describe('ordering', () => {
        const key = () => $el.find('[data-role="order-key"]');

        it('cycles name -> count -> size -> name, fetching the matching metric each time', () => {
            withObjects();

            key().trigger('click');
            expect(ajax.last('/objects').options.data).toEqual({metric: 'quantity'});
            expect(key().attr('title')).toBe('aaxis.devtools.database_viewer.order_by_count');
            expect(key().find('.aaxis-db-viewer__order-num').text()).toBe('1');

            key().trigger('click');
            expect(ajax.last('/objects').options.data).toEqual({metric: 'bytes'});
            expect(key().attr('title')).toBe('aaxis.devtools.database_viewer.order_by_size');
            expect(key().find('.fa-hashtag')).toHaveLength(1);

            const requests = ajax.to('/objects').length;
            key().trigger('click');
            expect(ajax.to('/objects')).toHaveLength(requests); // back to name: no reload
            expect(key().attr('title')).toBe('aaxis.devtools.database_viewer.order_by_name');
            expect(key().find('.fa-font')).toHaveLength(1);
        });

        it('the initial button state is alphabetical', () => {
            boot();

            expect(key().attr('title')).toBe('aaxis.devtools.database_viewer.order_by_name');
            expect($el.find('[data-role="order-dir"]').attr('title')).toBe('aaxis.devtools.database_viewer.order_asc');
        });

        it('sorts each type group by its numeric value when ordering by count, showing the badge', () => {
            withObjects();
            key().trigger('click');
            ajax.last('/objects').resolve({objects: OBJECTS});

            // z_table has no countValue: it sorts first in ascending order (-1), then 5, then 120
            expect(names()).toEqual(['z_table', 'a_table', 'oro_user', 'v_report', 'fn_calc', 'weird']);
            expect(item('a_table').find('.aaxis-db-viewer__object-count').text()).toBe('5');
            expect(item('z_table').find('.aaxis-db-viewer__object-count')).toHaveLength(0);
        });

        it('breaks equal counts by name and reverses on descending order', () => {
            withObjects();
            key().trigger('click');
            ajax.last('/objects').resolve({objects: [
                {name: 'b', type: 'table', count: '1', countValue: 1},
                {name: 'a', type: 'table', count: '1', countValue: 1},
                {name: 'c', type: 'table', count: '9', countValue: 9}
            ]});
            expect(names()).toEqual(['a', 'b', 'c']);

            $el.find('[data-role="order-dir"]').trigger('click');
            expect(names()).toEqual(['c', 'b', 'a']);
        });
    });

    describe('expanding tables', () => {
        it('loads the columns of an expanded table once, showing a spinner meanwhile', () => {
            withObjects();
            expander('a_table').trigger('click');

            expect(ajax.last('/columns').options).toMatchObject({method: 'GET', data: {name: 'a_table'}, errorHandlerMessage: false});
            expect(expander('a_table').attr('aria-expanded')).toBe('true');
            expect(expander('a_table').find('.fa').hasClass('fa-chevron-down')).toBe(true);
            expect($el.find('.aaxis-db-viewer__columns .fa-spinner')).toHaveLength(1);

            ajax.last('/columns').resolve({columns: [
                {name: 'id', type: 'integer', nullable: false},
                {name: 'title', type: 'text', nullable: true}
            ]});

            const $names = $el.find('.aaxis-db-viewer__column-name');
            expect($names.map((_: number, e: HTMLElement) => e.textContent).get()).toEqual(['id', 'title']);
            expect($names.eq(0).hasClass('is-required')).toBe(true);
            expect($names.eq(0).attr('title')).toBe('id (not null)');
            expect($names.eq(1).hasClass('is-required')).toBe(false);
            expect($names.eq(1).attr('title')).toBe('title');
            expect($el.find('.aaxis-db-viewer__column-type').map((_: number, e: HTMLElement) => e.textContent).get()).toEqual(['integer', 'text']);
        });

        it('collapses and re-expands from the cache without a second request', () => {
            withObjects();
            expander('a_table').trigger('click');
            ajax.last('/columns').resolve({columns: [{name: 'id', type: 'integer', nullable: false}]});

            expander('a_table').trigger('click');
            expect($el.find('.aaxis-db-viewer__columns')).toHaveLength(0);

            expander('a_table').trigger('click');
            expect($el.find('.aaxis-db-viewer__column')).toHaveLength(1);
            expect(ajax.to('/columns')).toHaveLength(1);
        });

        it('does not start a second request while one is loading', () => {
            withObjects();
            expander('a_table').trigger('click');
            expander('a_table').trigger('click'); // collapse
            expander('a_table').trigger('click'); // expand again while still loading

            expect(ajax.to('/columns')).toHaveLength(1);
        });

        it('notes a table without columns', () => {
            withObjects();
            expander('a_table').trigger('click');
            ajax.last('/columns').resolve({});

            expect($el.find('.aaxis-db-viewer__columns-info').text()).toBe('aaxis.devtools.database_viewer.no_columns');
        });

        it('flashes when the columns cannot be loaded and shows no columns', () => {
            withObjects();
            expander('a_table').trigger('click');
            ajax.last('/columns').reject({});

            expect(flash()).toEqual([['error', 'aaxis.devtools.database_viewer.columns_error']]);
            expect($el.find('.aaxis-db-viewer__columns-info').text()).toBe('aaxis.devtools.database_viewer.no_columns');
        });

        it('expanding does not also insert the object into the editor', () => {
            const viewer = withObjects();
            const before = viewer.editor.getValue();
            expander('a_table').trigger('click');

            expect(viewer.editor.getValue()).toBe(before);
        });
    });

    describe('clicking an object', () => {
        it('writes a SELECT for a table or view into the editor and focuses it', () => {
            const viewer = withObjects();

            const click = $.Event('click');
            item('oro_user').trigger(click);

            expect(click.isDefaultPrevented()).toBe(true);
            expect(viewer.editor.getValue()).toBe('SELECT *\nFROM "oro_user"\nLIMIT 100;');
            expect(viewer.editor.focus).toHaveBeenCalled();

            item('v_report').trigger('click');
            expect(viewer.editor.getValue()).toBe('SELECT *\nFROM "v_report"\nLIMIT 100;');
        });

        it('calls a function without arguments', () => {
            const viewer = withObjects();
            item('fn_calc').trigger('click');

            expect(viewer.editor.getValue()).toBe('SELECT "fn_calc"();');
        });

        it('does nothing without an editor', () => {
            $el.find('[data-role="editor"]').remove();
            const viewer = withObjects();

            expect(() => item('oro_user').trigger('click')).not.toThrow();
            expect(viewer.editor).toBeNull();
        });
    });

    describe('SQL beautifier', () => {
        const format = (sql: string) => boot().formatQuery(sql);

        it('breaks clauses onto lines, indents continuations and upper-cases keywords', () => {
            expect(format('select a, b from t where x = 1 and y = 2 order by a desc limit 10')).toBe([
                'SELECT a,',
                '  b',
                'FROM t',
                'WHERE x = 1',
                '  AND y = 2',
                'ORDER BY a DESC',
                'LIMIT 10'
            ].join('\n'));
        });

        it('keeps a join clause whole and the AND of BETWEEN on its line', () => {
            expect(format('select * from a left join b on a.id = b.id and a.x = b.x where a.z between 1 and 5 and a.q in (1,2,3)')).toBe([
                'SELECT *',
                'FROM a',
                'LEFT JOIN b',
                '  ON a.id = b.id',
                '  AND a.x = b.x',
                'WHERE a.z BETWEEN 1 AND 5',
                '  AND a.q IN (1,2,3)'
            ].join('\n'));
        });

        it('starts each statement on its own line', () => {
            expect(format('select 1; select 2')).toBe('SELECT 1;\nSELECT 2');
            expect(format('select 1;commit;rollback')).toBe('SELECT 1;\n  commit;\n  rollback');
        });

        it('formats CTE bodies and subqueries like top-level SQL, one indent level deeper', () => {
            expect(format('with c as (select id, name from u) select * from c')).toBe([
                'WITH c AS (',
                '    SELECT id,',
                '      name',
                '    FROM u',
                '  )',
                'SELECT *',
                'FROM c'
            ].join('\n'));

            expect(format('select * from t where id in (select id from u where x = 1 or y = 2)')).toBe([
                'SELECT *',
                'FROM t',
                'WHERE id IN (',
                '    SELECT id',
                '    FROM u',
                '    WHERE x = 1',
                '      OR y = 2',
                '  )'
            ].join('\n'));
        });

        it('keeps commas inside function calls and value tuples inline', () => {
            expect(format('select coalesce(a, b), max(c) from t')).toBe('SELECT COALESCE(a, b),\n  max(c)\nFROM t');
            expect(format('insert into t (a,b) values (1,2),(3,4) returning id')).toBe([
                'INSERT INTO t (a,b)',
                'VALUES (1,2),',
                '  (3,4)',
                'RETURNING id'
            ].join('\n'));
        });

        it('formats UNION ALL, GROUP BY / HAVING, UPDATE / SET and DELETE', () => {
            expect(format('select count(*) from t group by a having count(*) > 1 union all select 1')).toBe([
                'SELECT count(*)',
                'FROM t',
                'GROUP BY a',
                'HAVING count(*) > 1',
                'UNION ALL',
                'SELECT 1'
            ].join('\n'));
            expect(format('update t set a = 1, b = 2 where c = 3')).toBe('UPDATE t\nSET a = 1,\n  b = 2\nWHERE c = 3');
            expect(format('delete from t where a is not null')).toBe('DELETE FROM t\nWHERE a IS NOT NULL');
        });

        it('upper-cases secondary keywords in place', () => {
            expect(format('select case when a then b else c end from t where n like \'x%\''))
                .toBe("SELECT CASE WHEN a THEN b ELSE c END\nFROM t\nWHERE n LIKE 'x%'");
        });

        it('never touches string literals, quoted identifiers or comments', () => {
            const out = format("select 'from a, b and c' as \"select, x\" from t where n = 'it''s' /* where, and */");

            expect(out).toBe([
                "SELECT 'from a, b and c' AS \"select, x\"",
                'FROM t',
                "WHERE n = 'it''s' /* where, and */"
            ].join('\n'));
        });

        it('keeps a line comment on its line', () => {
            expect(format('select 1 -- pick, one\nfrom t')).toBe('SELECT 1 -- pick, one\nFROM t');
        });

        it('returns an empty string for blank input and survives unbalanced parentheses', () => {
            expect(format('')).toBe('');
            expect(format('   ')).toBe('');
            expect(format('select * from (select 1) x)')).toBe('SELECT *\nFROM (\n    SELECT 1\n  ) x)');
        });
    });
});
