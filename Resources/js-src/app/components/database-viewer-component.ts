import $ from 'jquery';
import messenger from 'oroui/js/messenger';
import 'codemirror/mode/sql/sql';
import AbstractQueryViewerComponent, {QueryViewerOptions} from './abstract-query-viewer-component';

interface DatabaseViewerOptions extends QueryViewerOptions {
    objectsUrl: string;
    columnsUrl: string;
}

interface DatabaseObject {
    name: string;
    type: 'table' | 'view' | 'function';
    count?: string;
    countValue?: number;
}

interface ColumnInfo {
    name: string;
    type: string;
    nullable: boolean;
}

const TYPE_ICONS: Record<string, string> = {
    table: 'fa-table',
    view: 'fa-eye',
    function: 'fa-code'
};

// The object list is always grouped in this order, regardless of the chosen ordering.
const TYPE_RANK: Record<string, number> = {
    table: 0,
    view: 1,
    function: 2
};

// Order-key button states: alphabetic (no badge) -> item count -> size (bytes) -> back to name.
type OrderMode = 'name' | 'count' | 'size';
const ORDER_METRIC: Record<OrderMode, string> = {
    name: 'none',
    count: 'quantity',
    size: 'bytes'
};

/**
 * Database Viewer page component. Runs read-only SQL and browses the schema (tables, views,
 * functions) in the sidebar. All the editor/tabs/saved-queries/result-grid behaviour is shared
 * with the Elastic Viewer via {@see AbstractQueryViewerComponent}; this class only adds the
 * PostgreSQL sidebar, the SQL pretty-printer and the naming/config hooks.
 */
class DatabaseViewerComponent extends AbstractQueryViewerComponent {
    private objectsUrl!: string;
    private columnsUrl!: string;

    private objects!: DatabaseObject[];
    private columnsByName!: Record<string, ColumnInfo[]>;
    private expanded!: Record<string, boolean>;
    private loadingColumns!: Record<string, boolean>;
    private showTables!: boolean;
    private showViews!: boolean;
    private showFunctions!: boolean;
    private orderMode!: OrderMode;

    // --- Naming / config hooks -----------------------------------------------

    protected cssPrefix(): string {
        return 'aaxis-db-viewer';
    }

    protected transPrefix(): string {
        return 'aaxis.devtools.database_viewer';
    }

    protected eventNs(): string {
        return 'aaxisDbViewer';
    }

    protected storagePrefix(): string {
        return 'aaxis-dbv-result:';
    }

    protected editorMode(): string {
        return 'text/x-pgsql';
    }

    // --- Sidebar -------------------------------------------------------------

    protected setupSidebar(options: DatabaseViewerOptions): void {
        this.objectsUrl = options.objectsUrl;
        this.columnsUrl = options.columnsUrl;

        this.objects = [];
        this.columnsByName = {};
        this.expanded = {};
        this.loadingColumns = {};
        this.showTables = true;
        this.showViews = true;
        this.showFunctions = true;
        this.orderMode = 'name';
        this.sortDir = 'asc';
    }

    protected bindSidebarEvents(): void {
        const ns = '.' + this.ns;
        this.$el.on('click' + ns, '[data-role="object-item"]', this.onObjectClick.bind(this));
        this.$el.on('click' + ns, '[data-role="object-expand"]', this.onObjectExpand.bind(this));
        this.$el.on('click' + ns, '[data-role="type-toggle"]', this.onTypeToggle.bind(this));
        this.$el.on('click' + ns, '[data-role="order-key"]', this.onOrderKey.bind(this));
        this.$el.on('click' + ns, '[data-role="order-dir"]', this.onOrderDir.bind(this));
    }

    protected afterSetup(): void {
        this.updateOrderButtons();
    }

    protected loadSidebar(): void {
        // The metric (none/quantity/bytes) follows the order-key button so counts/sizes are only
        // computed server-side when the user asks for them.
        this.renderSidebarLoading();
        $.ajax({url: this.objectsUrl, method: 'GET', data: {metric: ORDER_METRIC[this.orderMode]}, errorHandlerMessage: false})
            .done((response: {objects?: DatabaseObject[]}) => {
                this.objects = response.objects || [];
                this.renderSidebar();
            })
            .fail(() => {
                this.objects = [];
                this.renderSidebar();
                messenger.notificationFlashMessage('error', this.t('tables_error'));
            });
    }

    protected sidebarListRole(): string {
        return 'objects';
    }

    protected renderSidebar(): void {
        const $list = this.$el.find('[data-role="objects"]').empty();

        const visible = this.objects.filter(object => {
            if (object.type === 'table' && !this.showTables) {
                return false;
            }
            if (object.type === 'view' && !this.showViews) {
                return false;
            }
            if (object.type === 'function' && !this.showFunctions) {
                return false;
            }
            return this.filterText === '' || object.name.toLowerCase().indexOf(this.filterText) !== -1;
        });

        this.sortObjects(visible);

        if (visible.length === 0) {
            $list.append($('<li/>', {'class': this.cls('empty')}).text(this.t('no_objects')));
            return;
        }

        visible.forEach(object => {
            const iconClass = TYPE_ICONS[object.type] || 'fa-question';
            const expandable = object.type === 'table' || object.type === 'view';
            const isExpanded = expandable && this.expanded[object.name] === true;

            const $row = $('<div/>', {'class': this.cls('object-row')});

            if (expandable) {
                $row.append($('<button/>', {
                    type: 'button',
                    'class': this.cls('object-toggle'),
                    'data-role': 'object-expand',
                    'data-name': object.name,
                    'aria-expanded': isExpanded ? 'true' : 'false',
                    'aria-label': object.name
                }).append($('<span/>', {
                    'class': 'fa ' + (isExpanded ? 'fa-chevron-down' : 'fa-chevron-right'),
                    'aria-hidden': 'true'
                })));
            } else {
                $row.append($('<span/>', {'class': this.cls('object-toggle') + ' ' + this.cls('object-toggle') + '--empty'}));
            }

            const $link = $('<a/>', {
                href: '#',
                'data-role': 'object-item',
                'data-name': object.name,
                'data-type': object.type,
                title: object.name
            }).append(
                $('<span/>', {'class': 'fa ' + iconClass + ' ' + this.cls('object-icon'), 'aria-hidden': 'true'}),
                $('<span/>', {'class': this.cls('object-name'), text: object.name})
            );
            if (this.orderMode !== 'name' && object.count !== undefined) {
                $link.append($('<span/>', {'class': this.cls('object-count'), text: object.count}));
            }
            $row.append($link);

            const $li = $('<li/>').append($row);
            if (isExpanded) {
                $li.append(this.renderColumns(object.name));
            }
            $list.append($li);
        });
    }

    private renderColumns(name: string): any {
        const $cols = $('<ul/>', {'class': this.cls('columns')});

        if (this.loadingColumns[name]) {
            $cols.append($('<li/>', {'class': this.cls('columns-info')})
                .append($('<span/>', {'class': 'fa fa-spinner fa-spin', 'aria-hidden': 'true'})));
            return $cols;
        }

        const columns = this.columnsByName[name] || [];
        if (columns.length === 0) {
            $cols.append($('<li/>', {'class': this.cls('columns-info'), text: this.t('no_columns')}));
            return $cols;
        }

        columns.forEach(col => {
            $cols.append($('<li/>', {'class': this.cls('column')}).append(
                $('<span/>', {
                    'class': this.cls('column-name') + (col.nullable ? '' : ' is-required'),
                    text: col.name,
                    title: col.nullable ? col.name : col.name + ' (not null)'
                }),
                $('<span/>', {'class': this.cls('column-type'), text: col.type})
            ));
        });
        return $cols;
    }

    private onObjectExpand(event: any): void {
        event.preventDefault();
        event.stopPropagation();
        const name = String($(event.currentTarget).data('name'));
        const willExpand = this.expanded[name] !== true;
        this.expanded[name] = willExpand;

        if (willExpand && !this.columnsByName[name] && !this.loadingColumns[name]) {
            this.loadingColumns[name] = true;
            $.ajax({url: this.columnsUrl, method: 'GET', data: {name}, errorHandlerMessage: false})
                .done((response: {columns?: ColumnInfo[]}) => {
                    this.columnsByName[name] = response.columns || [];
                })
                .fail(() => {
                    this.columnsByName[name] = [];
                    messenger.notificationFlashMessage('error', this.t('columns_error'));
                })
                .always(() => {
                    delete this.loadingColumns[name];
                    this.renderSidebar();
                });
        }
        this.renderSidebar();
    }

    private sortObjects(objects: DatabaseObject[]): void {
        const dir = this.sortDir === 'desc' ? -1 : 1;
        objects.sort((a, b) => {
            // Always group tables, then views, then functions, irrespective of the ordering.
            const rank = (TYPE_RANK[a.type] ?? 99) - (TYPE_RANK[b.type] ?? 99);
            if (rank !== 0) {
                return rank;
            }

            let cmp: number;
            if (this.orderMode !== 'name') {
                // Sort by the raw numeric value so 99 kB < 2 MB; missing counts sort last.
                cmp = (a.countValue ?? -1) - (b.countValue ?? -1);
                if (cmp === 0) {
                    cmp = a.name.localeCompare(b.name);
                }
            } else {
                cmp = a.name.localeCompare(b.name);
            }
            return cmp * dir;
        });
    }

    private onObjectClick(event: any): void {
        event.preventDefault();
        const $target = $(event.currentTarget);
        const name = String($target.data('name'));
        const type = String($target.data('type'));
        if (!this.editor) {
            return;
        }

        const sql = type === 'function'
            ? `SELECT "${name}"();`
            : `SELECT *\nFROM "${name}"\nLIMIT 100;`;
        this.editor.setValue(sql);
        this.editor.focus();
    }

    private onTypeToggle(event: any): void {
        event.preventDefault();
        const $toggle = $(event.currentTarget);
        const type = String($toggle.data('type'));
        const active = !$toggle.hasClass('is-active');

        $toggle.toggleClass('is-active', active).attr('aria-pressed', active ? 'true' : 'false');

        if (type === 'table') {
            this.showTables = active;
        } else if (type === 'view') {
            this.showViews = active;
        } else if (type === 'function') {
            this.showFunctions = active;
        }

        this.renderSidebar();
    }

    private onOrderKey(event: any): void {
        event.preventDefault();
        // Cycle alphabetic -> item count -> size -> alphabetic.
        const cycle: Record<OrderMode, OrderMode> = {name: 'count', count: 'size', size: 'name'};
        this.orderMode = cycle[this.orderMode];
        this.updateOrderButtons();
        // Counts/sizes are computed server-side only when asked, so (re)fetch when leaving "name".
        if (this.orderMode === 'name') {
            this.renderSidebar();
        } else {
            this.loadSidebar();
        }
    }

    protected updateOrderButtons(): void {
        const $key = this.$el.find('[data-role="order-key"]').empty();
        // A = alphabetic (no badge), 1 = item count, # = size (bytes).
        if (this.orderMode === 'count') {
            $key.append($('<span/>', {'class': this.cls('order-num'), 'aria-hidden': 'true', text: '1'}));
            $key.attr('title', this.t('order_by_count'));
        } else if (this.orderMode === 'size') {
            $key.append($('<span/>', {'class': 'fa fa-hashtag', 'aria-hidden': 'true'}));
            $key.attr('title', this.t('order_by_size'));
        } else {
            $key.append($('<span/>', {'class': 'fa fa-font', 'aria-hidden': 'true'}));
            $key.attr('title', this.t('order_by_name'));
        }

        this.renderOrderDirection();
    }

    // --- SQL beautify --------------------------------------------------------

    /**
     * Lightweight SQL pretty-printer: only adds whitespace/newlines and uppercases keywords.
     * String literals, quoted identifiers and comments are protected so they are never altered.
     */
    protected formatQuery(raw: string): string {
        const {stash, restore} = this.createLiteralStore();

        let s = raw
            .replace(/--[^\n]*/g, stash)
            .replace(/\/\*[\s\S]*?\*\//g, stash)
            .replace(/'(?:[^']|'')*'/g, stash)
            .replace(/"(?:[^"]|"")*"/g, stash)
            .replace(/\s+/g, ' ')
            .trim();

        // Protect the AND of "BETWEEN x AND y" so the sub-clause pass doesn't split it.
        s = s.replace(/(\bBETWEEN\b[^()]*?)\bAND\b/gi, '$1\u0002');

        // Each statement starts on its own line.
        s = s.replace(/;\s*/g, ';\n');

        // Two kinds of paren groups: SELECT-groups (subqueries, CTE bodies) format like
        // top-level SQL — commas break and the closing paren gets its own line; every other
        // group (function calls, IN lists, value tuples) stays inline.
        const selectGroup: boolean[] = [];
        let commaBroken = '';
        for (let i = 0; i < s.length; i++) {
            const ch = s.charAt(i);
            if (ch === '(') {
                selectGroup.push(/^\s*select\b/i.test(s.slice(i + 1)));
                commaBroken += ch;
            } else if (ch === ')') {
                commaBroken += selectGroup.pop() === true ? '\n)' : ch;
            } else if (ch === ',' && (selectGroup.length === 0 || selectGroup[selectGroup.length - 1])) {
                commaBroken += ',\n';
            } else {
                commaBroken += ch;
            }
        }
        s = commaBroken;

        // Clause keywords that start a new line (multi-word variants first so they win).
        const clauses = [
            'LEFT OUTER JOIN', 'RIGHT OUTER JOIN', 'FULL OUTER JOIN', 'LEFT JOIN', 'RIGHT JOIN',
            'INNER JOIN', 'CROSS JOIN', 'FULL JOIN', 'GROUP BY', 'ORDER BY', 'DELETE FROM',
            'INSERT INTO', 'UNION ALL', 'WITH', 'SELECT', 'FROM', 'WHERE', 'HAVING', 'LIMIT',
            'OFFSET', 'UNION', 'VALUES', 'UPDATE', 'SET', 'RETURNING', 'JOIN'
        ];
        // One combined pass (longest alternative first): a per-keyword loop would let a later
        // single-word keyword re-split a multi-word clause already placed (LEFT JOIN -> LEFT\nJOIN).
        const alternation = clauses.map(kw => kw.replace(/ /g, '\\s+')).join('|');
        const clauseRe = new RegExp('\\s*\\b(' + alternation + ')\\b\\s*', 'gi');
        s = s.replace(clauseRe, (_m: string, kw: string) => '\n' + kw.replace(/\s+/g, ' ').toUpperCase() + ' ');

        // Sub-clause keywords get their own indented line. No leading \s* : "\s*\b" is an
        // ambiguous prefix the engine can backtrack over quadratically, and it is unnecessary here
        // because whitespace left before the inserted newline becomes trailing whitespace on the
        // previous line, which the per-line trim() below removes anyway.
        s = s.replace(/\b(AND|OR|ON)\b\s*/gi, (_m: string, kw: string) => '\n' + kw.toUpperCase() + ' ');

        // Restore the protected BETWEEN ... AND.
        s = s.replace(/\u0002/g, 'AND');

        // Secondary keywords are uppercased in place (no line break of their own).
        s = s.replace(
            /\b(AS|ASC|DESC|DISTINCT|IN|IS|NOT|NULL|LIKE|ILIKE|EXISTS|BETWEEN|CASE|WHEN|THEN|ELSE|END|INTERVAL|USING|COALESCE|CAST)\b/gi,
            (m: string) => m.toUpperCase()
        );

        // Indent one level per open paren (subqueries), plus an extra step for lines that
        // don't start a clause (column continuations, AND/OR/ON).
        const clauseStart = new RegExp('^(' + alternation + ')\\b', 'i');
        let depth = 0;
        const lines: string[] = [];
        s.split('\n').forEach(rawLine => {
            const line = rawLine.trim();
            if (line === '') {
                return;
            }
            // Closing parens at the start of the line outdent the line itself.
            let lead = 0;
            while (lead < line.length && line.charAt(lead) === ')') {
                lead++;
            }
            const lineDepth = Math.max(0, depth - lead);
            lines.push('    '.repeat(lineDepth) + (clauseStart.test(line) ? '' : '  ') + line);
            for (let i = 0; i < line.length; i++) {
                const ch = line.charAt(i);
                if (ch === '(') {
                    depth++;
                } else if (ch === ')') {
                    depth = Math.max(0, depth - 1);
                }
            }
        });
        s = lines.join('\n');

        return restore(s);
    }
}

export default DatabaseViewerComponent;
