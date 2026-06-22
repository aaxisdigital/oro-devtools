import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import messenger from 'oroui/js/messenger';
import Modal from 'oroui/js/modal';
import BaseComponent from 'oroui/js/app/components/base/component';
import CodeMirror from 'codemirror';
import 'codemirror/mode/sql/sql';

interface ElasticViewerOptions {
    _sourceElement: any;
    indicesUrl: string;
    queryUrl: string;
    exportUrl: string;
    allowExport?: boolean;
}

interface EsIndex {
    name: string;
    docsCount: number;
    size: string;
    health: string;
}

interface QueryResponse {
    success: boolean;
    message?: string;
    columns?: string[];
    columnTypes?: Record<string, string>;
    rows?: Array<Record<string, unknown>>;
    rowCount?: number;
    truncated?: boolean;
    durationMs?: number;
}

type SelectionMode = 'none' | 'columns' | 'rows';

// ES|QL is pipe-based but shares many SQL keywords; the SQL mode gives reasonable highlighting.
const ESQL_MODE = 'text/x-sql';

/**
 * Elastic Viewer page component: lists Elasticsearch indices, runs ES|QL queries and renders a
 * Kibana-style result grid (column name + type) with selection, copy, hide and export.
 *
 * NOTE: instance state is initialised inside initialize() (not via class field initializers).
 * BaseComponent calls initialize() from within its constructor, so field initializers would run
 * afterwards and clobber values set during initialize().
 */
class ElasticViewerComponent extends BaseComponent {
    private $el!: any;
    private indicesUrl!: string;
    private queryUrl!: string;
    private exportUrl!: string;

    private allowExport!: boolean;

    private editor!: any;
    private running!: boolean;

    private indices!: EsIndex[];
    private filterText!: string;
    private sortKey!: 'name' | 'count';
    private sortDir!: 'asc' | 'desc';

    private lastResult!: {columns: string[]; columnTypes: Record<string, string>; rows: Array<Record<string, unknown>>} | null;
    private hiddenColumns!: string[];
    private hiddenRows!: number[];

    private selectionMode!: SelectionMode;
    private selectedColumns!: string[];
    private selectedRows!: number[];
    private lastColIndex!: number;
    private lastRowOrdinal!: number;

    private $contextMenu!: any;

    initialize(options: ElasticViewerOptions): void {
        this.$el = options._sourceElement;
        this.indicesUrl = options.indicesUrl;
        this.queryUrl = options.queryUrl;
        this.exportUrl = options.exportUrl;

        this.allowExport = options.allowExport !== false;

        this.editor = null;
        this.running = false;
        this.indices = [];
        this.filterText = '';
        this.sortKey = 'name';
        this.sortDir = 'asc';

        this.lastResult = null;
        this.hiddenColumns = [];
        this.hiddenRows = [];

        this.selectionMode = 'none';
        this.selectedColumns = [];
        this.selectedRows = [];
        this.lastColIndex = -1;
        this.lastRowOrdinal = -1;
        this.$contextMenu = null;

        this.initEditor();
        this.initSplitters();
        this.loadIndices();
        this.bindEvents();
        this.updateOrderButtons();
    }

    private bindEvents(): void {
        this.$el.on('click.aaxisEsViewer', '[data-role="run"]', this.onRun.bind(this));
        this.$el.on('click.aaxisEsViewer', '[data-role="index-item"]', this.onIndexClick.bind(this));
        this.$el.on('click.aaxisEsViewer', '[data-role="export-format"]', this.onExport.bind(this));
        this.$el.on('input.aaxisEsViewer', '[data-role="filter"]', (e: any) => {
            this.filterText = String(e.currentTarget.value || '').toLowerCase();
            this.updateFilterClear();
            this.renderIndices();
        });
        this.$el.on('click.aaxisEsViewer', '[data-role="filter-clear"]', this.onFilterClear.bind(this));
        this.$el.on('click.aaxisEsViewer', '[data-role="refresh"]', this.onRefresh.bind(this));
        this.$el.on('click.aaxisEsViewer', '[data-role="order-key"]', this.onOrderKey.bind(this));
        this.$el.on('click.aaxisEsViewer', '[data-role="order-dir"]', this.onOrderDir.bind(this));
        this.$el.on('click.aaxisEsViewer', '[data-role="help"]', this.onHelp.bind(this));

        this.$el.on('click.aaxisEsViewer', '[data-role="col-header"]', this.onColumnHeaderClick.bind(this));
        this.$el.on('click.aaxisEsViewer', '[data-role="data-cell"], [data-role="row-select"]', this.onRowClick.bind(this));
        this.$el.on('contextmenu.aaxisEsViewer', '[data-role="result"]', this.onResultContextMenu.bind(this));
    }

    // --- CodeMirror ----------------------------------------------------------

    private initEditor(): void {
        const textarea = this.$el.find('[data-role="editor"]').get(0);
        if (!textarea) {
            return;
        }
        this.editor = CodeMirror.fromTextArea(textarea, {
            mode: ESQL_MODE,
            lineNumbers: true,
            lineWrapping: true,
            indentWithTabs: false,
            smartIndent: false,
            extraKeys: {
                'Ctrl-Enter': () => this.onRun(),
                'Cmd-Enter': () => this.onRun()
            }
        });
        this.editor.setSize('100%', '100%');
    }

    // --- Splitters -----------------------------------------------------------

    private initSplitters(): void {
        const sidebar = this.$el.find('[data-role="sidebar"]').get(0);
        const editorPane = this.$el.find('[data-role="editor-pane"]').get(0);

        this.bindDrag('[data-role="v-splitter"]', (dx: number, _dy: number, startSize: number) => {
            if (!sidebar) {
                return;
            }
            const width = Math.min(Math.max(startSize + dx, 160), 640);
            sidebar.style.flex = '0 0 ' + width + 'px';
            this.refreshEditor();
        }, () => (sidebar ? sidebar.getBoundingClientRect().width : 0));

        this.bindDrag('[data-role="h-splitter"]', (_dx: number, dy: number, startSize: number) => {
            if (!editorPane) {
                return;
            }
            const height = Math.max(startSize + dy, 80);
            editorPane.style.flex = '0 0 ' + height + 'px';
            this.refreshEditor();
        }, () => (editorPane ? editorPane.getBoundingClientRect().height : 0));
    }

    private bindDrag(
        selector: string,
        onMove: (dx: number, dy: number, startSize: number) => void,
        getStartSize: () => number
    ): void {
        this.$el.on('mousedown.aaxisEsViewer', selector, (event: any) => {
            event.preventDefault();
            const startX = event.clientX;
            const startY = event.clientY;
            const startSize = getStartSize();

            $('body').addClass('aaxis-es-viewer-resizing');

            const move = (e: any) => onMove(e.clientX - startX, e.clientY - startY, startSize);
            const up = () => {
                $(document).off('mousemove.aaxisEsViewerDrag mouseup.aaxisEsViewerDrag');
                $('body').removeClass('aaxis-es-viewer-resizing');
            };

            $(document)
                .on('mousemove.aaxisEsViewerDrag', move)
                .on('mouseup.aaxisEsViewerDrag', up);
        });
    }

    private refreshEditor(): void {
        if (this.editor) {
            this.editor.refresh();
        }
    }

    // --- Index list ----------------------------------------------------------

    private loadIndices(): void {
        $.ajax({url: this.indicesUrl, method: 'GET'})
            .done((response: {indices?: EsIndex[]}) => {
                this.indices = response.indices || [];
                this.renderIndices();
            })
            .fail(() => {
                messenger.notificationFlashMessage('error', __('aaxis.devtools.elastic_viewer.indices_error'));
            });
    }

    private renderIndices(): void {
        const $list = this.$el.find('[data-role="indices"]').empty();
        const visible = this.indices.filter(index =>
            this.filterText === '' || index.name.toLowerCase().indexOf(this.filterText) !== -1
        );

        const dir = this.sortDir === 'desc' ? -1 : 1;
        visible.sort((a, b) => {
            let cmp: number;
            if (this.sortKey === 'count') {
                cmp = (a.docsCount || 0) - (b.docsCount || 0);
                if (cmp === 0) {
                    cmp = a.name.localeCompare(b.name);
                }
            } else {
                cmp = a.name.localeCompare(b.name);
            }
            return cmp * dir;
        });

        if (visible.length === 0) {
            $list.append($('<li/>', {'class': 'aaxis-es-viewer__empty'})
                .text(__('aaxis.devtools.elastic_viewer.no_indices')));
            return;
        }

        visible.forEach(index => {
            const $link = $('<a/>', {
                href: '#',
                'data-role': 'index-item',
                'data-name': index.name,
                title: index.name
            }).append(
                $('<span/>', {
                    'class': 'fa fa-database aaxis-es-viewer__index-icon',
                    'aria-hidden': 'true'
                }),
                $('<span/>', {'class': 'aaxis-es-viewer__index-name', text: index.name})
            );
            if (this.sortKey === 'count') {
                $link.append($('<span/>', {
                    'class': 'aaxis-es-viewer__index-count',
                    text: String(index.docsCount),
                    title: __('aaxis.devtools.elastic_viewer.doc_count')
                }));
            }
            $list.append($('<li/>').append($link));
        });
    }

    private onIndexClick(event: any): void {
        event.preventDefault();
        const name = String($(event.currentTarget).data('name'));
        if (!this.editor) {
            return;
        }
        this.editor.setValue(`FROM ${name}\n| LIMIT 100`);
        this.editor.focus();
    }

    private onFilterClear(event: any): void {
        event.preventDefault();
        const $input = this.$el.find('[data-role="filter"]');
        $input.val('');
        this.filterText = '';
        this.updateFilterClear();
        this.renderIndices();
        $input.trigger('focus');
    }

    private updateFilterClear(): void {
        this.$el.find('[data-role="filter-clear"]').prop('hidden', this.filterText === '');
    }

    private onRefresh(event: any): void {
        event.preventDefault();
        this.loadIndices();
    }

    private onOrderKey(event: any): void {
        event.preventDefault();
        // Toggle alphabetic (A, no badge) <-> document count (1, badge shown).
        this.sortKey = this.sortKey === 'name' ? 'count' : 'name';
        this.updateOrderButtons();
        this.renderIndices();
    }

    private onOrderDir(event: any): void {
        event.preventDefault();
        this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
        this.updateOrderButtons();
        this.renderIndices();
    }

    private updateOrderButtons(): void {
        const $key = this.$el.find('[data-role="order-key"]').empty();
        // A = alphabetic (no badge), 1 = document count (matches the Database Viewer standard).
        if (this.sortKey === 'count') {
            $key.append($('<span/>', {'class': 'aaxis-es-viewer__order-num', 'aria-hidden': 'true', text: '1'}));
            $key.attr('title', __('aaxis.devtools.elastic_viewer.order_by_count'));
        } else {
            $key.append($('<span/>', {'class': 'fa fa-font', 'aria-hidden': 'true'}));
            $key.attr('title', __('aaxis.devtools.elastic_viewer.order_by_name'));
        }

        const $dir = this.$el.find('[data-role="order-dir"]');
        $dir.find('.fa').attr('class', 'fa ' + (this.sortDir === 'desc' ? 'fa-sort-amount-desc' : 'fa-sort-amount-asc'));
        $dir.attr('title', __(this.sortDir === 'desc'
            ? 'aaxis.devtools.elastic_viewer.order_desc'
            : 'aaxis.devtools.elastic_viewer.order_asc'));
    }

    private onHelp(event: any): void {
        event.preventDefault();
        const html = this.$el.find('[data-role="help-content"]').html();
        const modal = new Modal({
            title: __('aaxis.devtools.elastic_viewer.help'),
            content: html,
            allowOk: false,
            cancelText: __('Close')
        });
        modal.open();
    }

    // --- Query execution -----------------------------------------------------

    private onRun(): void {
        if (this.running) {
            return;
        }
        const query = this.editor ? String(this.editor.getValue()).trim() : '';
        if (query === '') {
            messenger.notificationFlashMessage('warning', __('aaxis.devtools.elastic_viewer.empty_query'));
            return;
        }

        this.setRunning(true);
        this.setStatus(__('aaxis.devtools.elastic_viewer.running'));

        $.ajax({
            url: this.queryUrl,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({query})
        }).done((response: QueryResponse) => {
            this.onResult(response);
        }).fail((jqXhr: any) => {
            const response: QueryResponse = (jqXhr.responseJSON as QueryResponse) || {};
            this.renderError(response.message || __('aaxis.devtools.elastic_viewer.query_error'));
        }).always(() => {
            this.setRunning(false);
        });
    }

    private onResult(response: QueryResponse): void {
        this.lastResult = {
            columns: response.columns || [],
            columnTypes: response.columnTypes || {},
            rows: response.rows || []
        };
        this.hiddenColumns = [];
        this.hiddenRows = [];
        this.clearSelection();
        this.setExportEnabled(this.lastResult.columns.length > 0);

        const summary = __('aaxis.devtools.elastic_viewer.result_summary', {
            count: response.rowCount || 0,
            duration: response.durationMs || 0
        });
        this.setStatus(summary + (response.truncated ? ' ' + __('aaxis.devtools.elastic_viewer.truncated') : ''));

        this.renderTable();
    }

    // --- Result grid ---------------------------------------------------------

    private visibleColumns(): string[] {
        if (!this.lastResult) {
            return [];
        }
        return this.lastResult.columns.filter(column => this.hiddenColumns.indexOf(column) === -1);
    }

    /** @return array of [originalIndex, row] for non-hidden rows */
    private visibleRows(): Array<[number, Record<string, unknown>]> {
        if (!this.lastResult) {
            return [];
        }
        const result: Array<[number, Record<string, unknown>]> = [];
        this.lastResult.rows.forEach((row, index) => {
            if (this.hiddenRows.indexOf(index) === -1) {
                result.push([index, row]);
            }
        });
        return result;
    }

    private renderTable(): void {
        const $result = this.$el.find('[data-role="result"]').empty();
        if (!this.lastResult) {
            return;
        }

        const columns = this.visibleColumns();
        if (columns.length === 0) {
            $result.append($('<p/>', {'class': 'aaxis-es-viewer__empty'})
                .text(__('aaxis.devtools.elastic_viewer.no_rows')));
            return;
        }

        const types = this.lastResult.columnTypes;
        const $table = $('<table/>', {
            'class': 'grid table-hover table table-bordered table-condensed aaxis-es-viewer__grid'
        });

        const $headRow = $('<tr/>').appendTo($('<thead/>').appendTo($table));
        $headRow.append($('<th/>', {'class': 'aaxis-es-viewer__rownum-cell', text: '#'}));
        columns.forEach(column => {
            const $th = $('<th/>', {'data-role': 'col-header', 'data-column': column});
            $th.append($('<span/>', {'class': 'aaxis-es-viewer__col-name', text: column}));
            if (types[column]) {
                $th.append($('<span/>', {'class': 'aaxis-es-viewer__col-type', text: types[column]}));
            }
            $headRow.append($th);
        });

        const $body = $('<tbody/>').appendTo($table);
        let ordinal = 0;
        this.visibleRows().forEach(([originalIndex, row]) => {
            ordinal += 1;
            const $tr = $('<tr/>', {'data-row-index': originalIndex}).appendTo($body);
            $tr.append($('<td/>', {
                'class': 'aaxis-es-viewer__rownum-cell',
                'data-role': 'row-select',
                text: ordinal
            }));
            columns.forEach(column => {
                const value = row[column];
                const $td = $('<td/>', {'data-role': 'data-cell', 'data-column': column});
                if (value === null || value === undefined) {
                    $td.addClass('aaxis-es-viewer__null-cell').text('(null)');
                } else if (typeof value === 'object') {
                    $td.text(JSON.stringify(value));
                } else {
                    $td.text(String(value));
                }
                $tr.append($td);
            });
        });

        $result.append($table);
        this.applySelectionHighlight();
    }

    // --- Selection -----------------------------------------------------------

    private clearSelection(): void {
        this.selectionMode = 'none';
        this.selectedColumns = [];
        this.selectedRows = [];
        this.lastColIndex = -1;
        this.lastRowOrdinal = -1;
        this.applySelectionHighlight();
    }

    private onColumnHeaderClick(event: any): void {
        event.preventDefault();
        const column = String($(event.currentTarget).data('column'));
        const columns = this.visibleColumns();
        const index = columns.indexOf(column);
        if (index === -1) {
            return;
        }

        if (this.selectionMode !== 'columns') {
            this.selectionMode = 'columns';
            this.selectedColumns = [];
            this.selectedRows = [];
        }

        if (event.shiftKey && this.lastColIndex !== -1) {
            const [from, to] = [this.lastColIndex, index].sort((a, b) => a - b);
            this.selectedColumns = columns.slice(from, to + 1);
        } else if (event.ctrlKey || event.metaKey) {
            this.toggleValue(this.selectedColumns, column);
            this.lastColIndex = index;
        } else {
            this.selectedColumns = [column];
            this.lastColIndex = index;
        }

        this.applySelectionHighlight();
    }

    private onRowClick(event: any): void {
        const $tr = $(event.currentTarget).closest('[data-row-index]');
        if (!$tr.length) {
            return;
        }
        const rowIndex = Number($tr.data('row-index'));
        const order = this.visibleRows().map(([originalIndex]) => originalIndex);
        const ordinal = order.indexOf(rowIndex);

        if (this.selectionMode !== 'rows') {
            this.selectionMode = 'rows';
            this.selectedColumns = [];
            this.selectedRows = [];
        }

        if (event.shiftKey && this.lastRowOrdinal !== -1) {
            const [from, to] = [this.lastRowOrdinal, ordinal].sort((a, b) => a - b);
            this.selectedRows = order.slice(from, to + 1);
        } else if (event.ctrlKey || event.metaKey) {
            this.toggleValue(this.selectedRows, rowIndex);
            this.lastRowOrdinal = ordinal;
        } else {
            this.selectedRows = [rowIndex];
            this.lastRowOrdinal = ordinal;
        }

        this.applySelectionHighlight();
    }

    private toggleValue(list: any[], value: any): void {
        const index = list.indexOf(value);
        if (index === -1) {
            list.push(value);
        } else {
            list.splice(index, 1);
        }
    }

    private applySelectionHighlight(): void {
        const $table = this.$el.find('.aaxis-es-viewer__grid');
        $table.find('.is-col-selected').removeClass('is-col-selected');
        $table.find('.is-row-selected').removeClass('is-row-selected');

        this.selectedColumns.forEach(column => {
            $table.find('[data-column="' + this.escapeAttr(column) + '"]').addClass('is-col-selected');
        });
        this.selectedRows.forEach(index => {
            $table.find('[data-row-index="' + index + '"]').addClass('is-row-selected');
        });
    }

    private escapeAttr(value: string): string {
        return value.replace(/"/g, '\\"');
    }

    private hasSelection(): boolean {
        return (this.selectionMode === 'columns' && this.selectedColumns.length > 0)
            || (this.selectionMode === 'rows' && this.selectedRows.length > 0);
    }

    // --- Context menu --------------------------------------------------------

    private ensureContextMenu(): any {
        if (this.$contextMenu) {
            return this.$contextMenu;
        }
        const $menu = $('<ul/>', {'class': 'aaxis-es-viewer__context-menu', role: 'menu'});
        const items: Array<[string, string]> = [
            ['copy-json', __('aaxis.devtools.elastic_viewer.context_copy_json')],
            ['copy-csv', __('aaxis.devtools.elastic_viewer.context_copy_csv')],
            ['hide', __('aaxis.devtools.elastic_viewer.context_hide')]
        ];
        items.forEach(([action, label]) => {
            $menu.append($('<li/>').append($('<a/>', {
                href: '#',
                role: 'menuitem',
                'data-action': action,
                text: label
            })));
        });
        $menu.on('click', 'a', (event: any) => {
            event.preventDefault();
            this.onContextAction(String($(event.currentTarget).data('action')));
            this.hideContextMenu();
        });
        $('body').append($menu);
        this.$contextMenu = $menu;
        return $menu;
    }

    private onResultContextMenu(event: any): void {
        if (!this.allowExport || !this.hasSelection()) {
            return;
        }
        event.preventDefault();
        const $menu = this.ensureContextMenu();
        $menu.css({display: 'block', left: event.clientX + 'px', top: event.clientY + 'px'});

        setTimeout(() => {
            $(document).on('mousedown.aaxisEsViewerCtx', (e: any) => {
                if (!$(e.target).closest('.aaxis-es-viewer__context-menu').length) {
                    this.hideContextMenu();
                }
            });
            $(document).on('keydown.aaxisEsViewerCtx', (e: any) => {
                if (e.keyCode === 27) {
                    this.hideContextMenu();
                }
            });
        }, 0);
    }

    private hideContextMenu(): void {
        if (this.$contextMenu) {
            this.$contextMenu.css('display', 'none');
        }
        $(document).off('.aaxisEsViewerCtx');
    }

    private onContextAction(action: string): void {
        if (action === 'hide') {
            this.hideSelection();
            return;
        }
        const data = this.getSelectedData();
        if (data.columns.length === 0) {
            return;
        }
        const text = action === 'copy-json' ? this.buildJson(data) : this.buildCsv(data);
        this.copyToClipboard(text);
    }

    private hideSelection(): void {
        if (this.selectionMode === 'columns') {
            this.selectedColumns.forEach(column => {
                if (this.hiddenColumns.indexOf(column) === -1) {
                    this.hiddenColumns.push(column);
                }
            });
        } else if (this.selectionMode === 'rows') {
            this.selectedRows.forEach(index => {
                if (this.hiddenRows.indexOf(index) === -1) {
                    this.hiddenRows.push(index);
                }
            });
        }
        this.clearSelection();
        this.renderTable();
    }

    private getSelectedData(): {columns: string[]; rows: Array<Record<string, unknown>>} {
        if (!this.lastResult) {
            return {columns: [], rows: []};
        }
        const visibleColumns = this.visibleColumns();

        if (this.selectionMode === 'columns') {
            const columns = visibleColumns.filter(column => this.selectedColumns.indexOf(column) !== -1);
            const rows = this.visibleRows().map(([, row]) => this.pick(row, columns));
            return {columns, rows};
        }

        if (this.selectionMode === 'rows') {
            const rows = this.selectedRows
                .filter(index => this.hiddenRows.indexOf(index) === -1)
                .map(index => this.pick(this.lastResult!.rows[index], visibleColumns));
            return {columns: visibleColumns, rows};
        }

        return {columns: [], rows: []};
    }

    private pick(row: Record<string, unknown>, columns: string[]): Record<string, unknown> {
        const result: Record<string, unknown> = {};
        columns.forEach(column => {
            result[column] = row[column] ?? null;
        });
        return result;
    }

    private buildJson(data: {columns: string[]; rows: Array<Record<string, unknown>>}): string {
        return JSON.stringify(data.rows, null, 2);
    }

    private buildCsv(data: {columns: string[]; rows: Array<Record<string, unknown>>}): string {
        const lines = [data.columns.map(column => this.csvCell(column)).join(',')];
        data.rows.forEach(row => {
            lines.push(data.columns.map(column => this.csvCell(row[column])).join(','));
        });
        return lines.join('\r\n');
    }

    private csvCell(value: unknown): string {
        const text = value === null || value === undefined ? '' : (typeof value === 'object' ? JSON.stringify(value) : String(value));
        return /[",\r\n]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
    }

    private copyToClipboard(text: string): void {
        const onSuccess = () => messenger.notificationFlashMessage('success', __('aaxis.devtools.elastic_viewer.copied'));
        const onError = () => messenger.notificationFlashMessage('error', __('aaxis.devtools.elastic_viewer.copy_error'));

        if (window.navigator.clipboard && window.navigator.clipboard.writeText) {
            window.navigator.clipboard.writeText(text).then(onSuccess).catch(onError);
            return;
        }

        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        try {
            document.execCommand('copy');
            onSuccess();
        } catch (e) {
            onError();
        }
        document.body.removeChild(textarea);
    }

    // --- Export --------------------------------------------------------------

    private onExport(event: any): void {
        event.preventDefault();
        const format = String($(event.currentTarget).data('format'));
        const columns = this.visibleColumns();
        if (!this.lastResult || columns.length === 0) {
            return;
        }

        const payload = {
            format,
            columns,
            rows: this.visibleRows().map(([, row]) => this.pick(row, columns))
        };

        fetch(this.exportUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Header': this.getCsrfToken()
            },
            body: JSON.stringify(payload)
        }).then(response => {
            if (!response.ok) {
                throw new Error('export failed');
            }
            return response.blob();
        }).then(blob => {
            this.triggerDownload(blob, 'export.' + format);
        }).catch(() => {
            messenger.notificationFlashMessage('error', __('aaxis.devtools.elastic_viewer.export_error'));
        });
    }

    private triggerDownload(blob: Blob, filename: string): void {
        const url = window.URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(url);
    }

    private getCsrfToken(): string {
        const name = window.location.protocol === 'https:' ? 'https-_csrf' : '_csrf';
        const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }

    private setExportEnabled(enabled: boolean): void {
        this.$el.find('[data-role="export"]').prop('disabled', !enabled);
    }

    // --- Helpers -------------------------------------------------------------

    private renderError(message: string): void {
        this.setStatus('');
        this.lastResult = null;
        this.clearSelection();
        this.setExportEnabled(false);
        this.$el.find('[data-role="result"]').empty().append(
            $('<div/>', {'class': 'alert alert-error', role: 'alert'}).text(message)
        );
    }

    private setStatus(text: string): void {
        this.$el.find('[data-role="status"]').text(text);
    }

    private setRunning(running: boolean): void {
        this.running = running;
        this.$el.find('[data-role="run"]').prop('disabled', running);
    }

    dispose(): void {
        if (this.disposed) {
            return;
        }
        this.$el.off('.aaxisEsViewer');
        $(document).off('.aaxisEsViewerDrag .aaxisEsViewerCtx');
        $('body').removeClass('aaxis-es-viewer-resizing');
        if (this.$contextMenu) {
            this.$contextMenu.remove();
            this.$contextMenu = null;
        }
        if (this.editor) {
            this.editor.toTextArea();
            this.editor = null;
        }
        super.dispose();
    }
}

export default ElasticViewerComponent;
