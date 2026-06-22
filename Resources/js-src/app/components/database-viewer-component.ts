import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import messenger from 'oroui/js/messenger';
import Modal from 'oroui/js/modal';
import BaseComponent from 'oroui/js/app/components/base/component';
import CodeMirror from 'codemirror';
import 'codemirror/mode/sql/sql';

interface DatabaseViewerOptions {
    _sourceElement: any;
    objectsUrl: string;
    columnsUrl: string;
    queryUrl: string;
    exportUrl: string;
    savedQueriesUrl: string;
    showRowNumbers?: boolean;
    allowExport?: boolean;
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

interface QueryResponse {
    success: boolean;
    message?: string;
    columns?: string[];
    rows?: Array<Record<string, unknown>>;
    rowCount?: number;
    truncated?: boolean;
    durationMs?: number;
}

interface SavedQueryItem {
    id: number;
    name: string;
    public: boolean;
    text: string;
    owned: boolean;
}

interface QueryTab {
    id: string;
    name: string;
    sql: string;
    savedId: number | null;
    savedPublic: boolean;
    savedOwned: boolean;
    savedText: string | null;
}

type SelectionMode = 'none' | 'columns' | 'rows';

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

const SQL_MODE = 'text/x-pgsql';

/**
 * Database Viewer page component.
 *
 * NOTE: instance state is initialised inside initialize() (not via class field
 * initializers). BaseComponent calls initialize() from within its constructor, so
 * field initializers would run afterwards and clobber values set during initialize().
 */
class DatabaseViewerComponent extends BaseComponent {
    private $el!: any;
    private objectsUrl!: string;
    private columnsUrl!: string;
    private queryUrl!: string;
    private exportUrl!: string;
    private savedQueriesUrl!: string;

    private showRowNumbers!: boolean;
    private allowExport!: boolean;

    private editor!: any;
    private running!: boolean;

    private objects!: DatabaseObject[];
    private columnsByName!: Record<string, ColumnInfo[]>;
    private expanded!: Record<string, boolean>;
    private loadingColumns!: Record<string, boolean>;
    private showTables!: boolean;
    private showViews!: boolean;
    private showFunctions!: boolean;
    private filterText!: string;
    private orderMode!: OrderMode;
    private sortDir!: 'asc' | 'desc';

    private lastResult!: {columns: string[]; rows: Array<Record<string, unknown>>} | null;
    private hiddenColumns!: string[];
    private hiddenRows!: number[];

    private selectionMode!: SelectionMode;
    private selectedColumns!: string[];
    private selectedRows!: number[];
    private lastColIndex!: number;
    private lastRowOrdinal!: number;

    private tabs!: QueryTab[];
    private activeTabId!: string;
    private tabSeq!: number;

    private $contextMenu!: any;

    initialize(options: DatabaseViewerOptions): void {
        this.$el = options._sourceElement;
        this.objectsUrl = options.objectsUrl;
        this.columnsUrl = options.columnsUrl;
        this.queryUrl = options.queryUrl;
        this.exportUrl = options.exportUrl;
        this.savedQueriesUrl = options.savedQueriesUrl;

        this.showRowNumbers = options.showRowNumbers !== false;
        this.allowExport = options.allowExport !== false;

        this.editor = null;
        this.running = false;
        this.objects = [];
        this.columnsByName = {};
        this.expanded = {};
        this.loadingColumns = {};
        this.showTables = true;
        this.showViews = true;
        this.showFunctions = true;
        this.filterText = '';
        this.orderMode = 'name';
        this.sortDir = 'asc';

        this.lastResult = null;
        this.hiddenColumns = [];
        this.hiddenRows = [];

        this.selectionMode = 'none';
        this.selectedColumns = [];
        this.selectedRows = [];
        this.lastColIndex = -1;
        this.lastRowOrdinal = -1;

        this.tabs = [];
        this.activeTabId = '';
        this.tabSeq = 0;
        this.$contextMenu = null;

        this.initEditor();
        this.initTabs();
        this.initSplitters();
        this.loadObjects();
        this.loadQueriesMenu();
        this.bindEvents();
        this.updateUpdateButton();
        this.updateOrderButtons();
    }

    private bindEvents(): void {
        this.$el.on('click.aaxisDbViewer', '[data-role="run"]', this.onRun.bind(this));
        this.$el.on('click.aaxisDbViewer', '[data-role="beautify"]', this.onBeautify.bind(this));
        this.$el.on('click.aaxisDbViewer', '[data-role="object-item"]', this.onObjectClick.bind(this));
        this.$el.on('click.aaxisDbViewer', '[data-role="object-expand"]', this.onObjectExpand.bind(this));
        this.$el.on('click.aaxisDbViewer', '[data-role="export-format"]', this.onExport.bind(this));
        this.$el.on('click.aaxisDbViewer', '[data-role="type-toggle"]', this.onTypeToggle.bind(this));
        this.$el.on('input.aaxisDbViewer', '[data-role="filter"]', (e: any) => {
            this.filterText = String(e.currentTarget.value || '').toLowerCase();
            this.updateFilterClear();
            this.renderObjects();
        });
        this.$el.on('click.aaxisDbViewer', '[data-role="filter-clear"]', this.onFilterClear.bind(this));
        this.$el.on('click.aaxisDbViewer', '[data-role="refresh"]', this.onRefresh.bind(this));
        this.$el.on('click.aaxisDbViewer', '[data-role="order-key"]', this.onOrderKey.bind(this));
        this.$el.on('click.aaxisDbViewer', '[data-role="order-dir"]', this.onOrderDir.bind(this));
        this.$el.on('click.aaxisDbViewer', '[data-role="help"]', this.onHelp.bind(this));

        // Tabs
        this.$el.on('click.aaxisDbViewer', '[data-role="tab-add"]', this.onAddTab.bind(this));
        this.$el.on('click.aaxisDbViewer', '[data-role="tab"]', this.onTabClick.bind(this));
        this.$el.on('click.aaxisDbViewer', '[data-role="tab-close"]', this.onCloseTab.bind(this));
        this.$el.on('click.aaxisDbViewer', '[data-role="tab-star"]', this.onStarClick.bind(this));

        // Saved queries
        this.$el.on('click.aaxisDbViewer', '[data-role="load-query"]', this.onLoadQuery.bind(this));
        this.$el.on('click.aaxisDbViewer', '[data-role="view-all-queries"]', this.onViewAllQueries.bind(this));
        this.$el.on('click.aaxisDbViewer', '[data-role="update"]', this.onUpdateQuery.bind(this));

        // Result selection + context menu
        this.$el.on('click.aaxisDbViewer', '[data-role="col-header"]', this.onColumnHeaderClick.bind(this));
        this.$el.on('click.aaxisDbViewer', '[data-role="data-cell"], [data-role="row-select"]', this.onRowClick.bind(this));
        this.$el.on('contextmenu.aaxisDbViewer', '[data-role="result"]', this.onResultContextMenu.bind(this));
    }

    private onFilterClear(event: any): void {
        event.preventDefault();
        const $input = this.$el.find('[data-role="filter"]');
        $input.val('');
        this.filterText = '';
        this.updateFilterClear();
        this.renderObjects();
        $input.trigger('focus');
    }

    private updateFilterClear(): void {
        this.$el.find('[data-role="filter-clear"]').prop('hidden', this.filterText === '');
    }

    private onRefresh(event: any): void {
        event.preventDefault();
        this.loadObjects();
    }

    private onOrderKey(event: any): void {
        event.preventDefault();
        // Cycle alphabetic -> item count -> size -> alphabetic.
        const cycle: Record<OrderMode, OrderMode> = {name: 'count', count: 'size', size: 'name'};
        this.orderMode = cycle[this.orderMode];
        this.updateOrderButtons();
        // Counts/sizes are computed server-side only when asked, so (re)fetch when leaving "name".
        if (this.orderMode === 'name') {
            this.renderObjects();
        } else {
            this.loadObjects();
        }
    }

    private onOrderDir(event: any): void {
        event.preventDefault();
        this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
        this.updateOrderButtons();
        this.renderObjects();
    }

    private updateOrderButtons(): void {
        const $key = this.$el.find('[data-role="order-key"]').empty();
        // A = alphabetic (no badge), 1 = item count, # = size (bytes).
        if (this.orderMode === 'count') {
            $key.append($('<span/>', {'class': 'aaxis-db-viewer__order-num', 'aria-hidden': 'true', text: '1'}));
            $key.attr('title', __('aaxis.devtools.database_viewer.order_by_count'));
        } else if (this.orderMode === 'size') {
            $key.append($('<span/>', {'class': 'fa fa-hashtag', 'aria-hidden': 'true'}));
            $key.attr('title', __('aaxis.devtools.database_viewer.order_by_size'));
        } else {
            $key.append($('<span/>', {'class': 'fa fa-font', 'aria-hidden': 'true'}));
            $key.attr('title', __('aaxis.devtools.database_viewer.order_by_name'));
        }

        const $dir = this.$el.find('[data-role="order-dir"]');
        $dir.find('.fa').attr('class', 'fa ' + (this.sortDir === 'desc' ? 'fa-sort-amount-desc' : 'fa-sort-amount-asc'));
        $dir.attr('title', __(this.sortDir === 'desc'
            ? 'aaxis.devtools.database_viewer.order_desc'
            : 'aaxis.devtools.database_viewer.order_asc'));
    }

    private onHelp(event: any): void {
        event.preventDefault();
        const html = this.$el.find('[data-role="help-content"]').html();
        const modal = new Modal({
            title: __('aaxis.devtools.database_viewer.help'),
            content: html,
            allowOk: false,
            cancelText: __('Close')
        });
        modal.open();
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

        this.renderObjects();
    }

    // --- CodeMirror ----------------------------------------------------------

    private initEditor(): void {
        const textarea = this.$el.find('[data-role="editor"]').get(0);
        if (!textarea) {
            return;
        }

        this.editor = CodeMirror.fromTextArea(textarea, {
            mode: SQL_MODE,
            lineNumbers: true,
            lineWrapping: true,
            indentWithTabs: false,
            smartIndent: true,
            extraKeys: {
                'Ctrl-Enter': () => this.onRun(),
                'Cmd-Enter': () => this.onRun()
            }
        });
        this.editor.setSize('100%', '100%');
        this.editor.on('change', () => this.updateUpdateButton());
    }

    // --- Query tabs ----------------------------------------------------------

    private initTabs(): void {
        const initialSql = this.editor ? this.editor.getValue() : '';
        const tab = this.createTab({sql: initialSql});
        this.tabs.push(tab);
        this.activeTabId = tab.id;
        this.renderTabs();
    }

    private createTab(opts: Partial<QueryTab> & {sql: string}): QueryTab {
        this.tabSeq += 1;
        return {
            id: 'tab-' + this.tabSeq,
            name: opts.name || __('aaxis.devtools.database_viewer.tab_name', {number: this.tabSeq}),
            sql: opts.sql,
            savedId: opts.savedId ?? null,
            savedPublic: opts.savedPublic ?? false,
            savedOwned: opts.savedOwned ?? false,
            savedText: opts.savedText ?? null
        };
    }

    private activeTab(): QueryTab | undefined {
        return this.tabs.find(t => t.id === this.activeTabId);
    }

    private renderTabs(): void {
        const $list = this.$el.find('[data-role="tabs-list"]').empty();
        const closable = this.tabs.length > 1;

        this.tabs.forEach(tab => {
            const $tab = $('<span/>', {
                'class': 'aaxis-db-viewer__tab' + (tab.id === this.activeTabId ? ' is-active' : ''),
                'data-role': 'tab',
                'data-id': tab.id
            });

            let starClass = 'aaxis-db-viewer__tab-star fa ';
            if (tab.savedId === null) {
                starClass += 'fa-star-o';
            } else {
                starClass += 'fa-star ' + (tab.savedPublic ? 'is-public' : 'is-private');
            }
            $tab.append($('<span/>', {
                'class': starClass,
                'data-role': 'tab-star',
                'data-id': tab.id,
                'aria-hidden': 'true'
            }));

            $tab.append($('<span/>', {'class': 'aaxis-db-viewer__tab-name', text: tab.name}));
            if (closable) {
                $tab.append($('<button/>', {
                    type: 'button',
                    'class': 'aaxis-db-viewer__tab-close',
                    'data-role': 'tab-close',
                    'data-id': tab.id,
                    'aria-label': __('aaxis.devtools.database_viewer.close_tab'),
                    title: __('aaxis.devtools.database_viewer.close_tab'),
                    html: '&times;'
                }));
            }
            $list.append($tab);
        });
    }

    private saveActiveTab(): void {
        const tab = this.activeTab();
        if (tab && this.editor) {
            tab.sql = this.editor.getValue();
        }
    }

    private switchTab(id: string): void {
        if (id === this.activeTabId) {
            return;
        }
        this.saveActiveTab();
        const tab = this.tabs.find(t => t.id === id);
        if (!tab) {
            return;
        }
        this.activeTabId = id;
        if (this.editor) {
            this.editor.setValue(tab.sql);
            this.editor.focus();
        }
        this.renderTabs();
        this.updateUpdateButton();
    }

    private onTabClick(event: any): void {
        if ($(event.target).closest('[data-role="tab-close"], [data-role="tab-star"]').length) {
            return;
        }
        this.switchTab(String($(event.currentTarget).data('id')));
    }

    private onAddTab(event: any): void {
        event.preventDefault();
        this.saveActiveTab();
        const tab = this.createTab({sql: ''});
        this.tabs.push(tab);
        this.activeTabId = tab.id;
        if (this.editor) {
            this.editor.setValue('');
            this.editor.focus();
        }
        this.renderTabs();
        this.updateUpdateButton();
    }

    private onCloseTab(event: any): void {
        event.preventDefault();
        event.stopPropagation();
        if (this.tabs.length <= 1) {
            return;
        }
        const id = String($(event.currentTarget).data('id'));
        const index = this.tabs.findIndex(t => t.id === id);
        if (index === -1) {
            return;
        }
        this.tabs.splice(index, 1);
        if (this.activeTabId === id) {
            const next = this.tabs[Math.max(0, index - 1)];
            this.activeTabId = next.id;
            if (this.editor) {
                this.editor.setValue(next.sql);
            }
        }
        this.renderTabs();
        this.updateUpdateButton();
    }

    // --- Favorites / saving --------------------------------------------------

    private onStarClick(event: any): void {
        event.preventDefault();
        event.stopPropagation();
        const id = String($(event.currentTarget).data('id'));
        if (id !== this.activeTabId) {
            this.switchTab(id);
        }
        const tab = this.activeTab();
        if (!tab) {
            return;
        }
        if (tab.savedId === null) {
            this.openSaveDialog('create');
        } else if (tab.savedOwned) {
            this.openSaveDialog('edit');
        }
    }

    private openSaveDialog(mode: 'create' | 'edit'): void {
        const tab = this.activeTab();
        if (!tab || !this.editor) {
            return;
        }
        const text = String(this.editor.getValue());
        const currentPublic = mode === 'edit' ? tab.savedPublic : false;

        const content =
            '<form class="aaxis-db-viewer__save-form">' +
                '<div class="control-group">' +
                    '<label class="control-label">' + __('aaxis.devtools.database_viewer.sq_name') + '</label>' +
                    '<input type="text" class="form-control" data-role="sq-name" maxlength="40" value="' +
                        this.escapeHtml(tab.name) + '">' +
                '</div>' +
                '<div class="control-group">' +
                    '<label class="control-label">' + __('aaxis.devtools.database_viewer.sq_visibility') + '</label>' +
                    '<label class="radio"><input type="radio" name="sq-visibility" value="private"' +
                        (currentPublic ? '' : ' checked') + '> ' +
                        __('aaxis.devtools.database_viewer.sq_private') + '</label>' +
                    '<label class="radio"><input type="radio" name="sq-visibility" value="public"' +
                        (currentPublic ? ' checked' : '') + '> ' +
                        __('aaxis.devtools.database_viewer.sq_public') + '</label>' +
                '</div>' +
            '</form>';

        const modal = new Modal({
            title: mode === 'edit'
                ? __('aaxis.devtools.database_viewer.sq_edit_title')
                : __('aaxis.devtools.database_viewer.sq_title'),
            content,
            okText: __('aaxis.devtools.database_viewer.sq_save'),
            okCloses: false
        });

        modal.on('ok', () => {
            const name = String(modal.$el.find('[data-role="sq-name"]').val() || '').trim();
            const isPublic = modal.$el.find('input[name="sq-visibility"]:checked').val() === 'public';
            if (name === '') {
                messenger.notificationFlashMessage('warning', __('aaxis.devtools.database_viewer.sq_name_required'));
                return;
            }

            const request = mode === 'edit' && tab.savedId !== null
                ? $.ajax({
                    url: this.savedQueriesUrl + '/' + tab.savedId,
                    method: 'PUT',
                    contentType: 'application/json',
                    data: JSON.stringify({name, public: isPublic})
                })
                : $.ajax({
                    url: this.savedQueriesUrl,
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({name, text, public: isPublic})
                });

            request.done((response: {success: boolean; query?: SavedQueryItem}) => {
                if (response.success && response.query) {
                    this.applySavedQuery(tab, response.query);
                    messenger.notificationFlashMessage('success', __('aaxis.devtools.database_viewer.sq_saved'));
                    this.loadQueriesMenu();
                }
                modal.close();
            }).fail((jqXhr: any) => {
                const message = (jqXhr.responseJSON && jqXhr.responseJSON.message)
                    || __('aaxis.devtools.database_viewer.sq_error');
                messenger.notificationFlashMessage('error', message);
            });
        });

        modal.open();
    }

    private applySavedQuery(tab: QueryTab, query: SavedQueryItem): void {
        tab.savedId = query.id;
        tab.savedPublic = query.public;
        tab.savedOwned = query.owned;
        tab.savedText = query.text;
        tab.name = query.name;
        this.renderTabs();
        this.updateUpdateButton();
    }

    // --- Queries menu --------------------------------------------------------

    private loadQueriesMenu(): void {
        $.ajax({url: this.savedQueriesUrl, method: 'GET'})
            .done((response: {
                public: SavedQueryItem[];
                private: SavedQueryItem[];
                hasMorePublic: boolean;
                hasMorePrivate: boolean;
            }) => {
                this.renderQueriesMenu(response);
            });
    }

    private renderQueriesMenu(data: {
        public: SavedQueryItem[];
        private: SavedQueryItem[];
        hasMorePublic: boolean;
        hasMorePrivate: boolean;
    }): void {
        const $menu = this.$el.find('[data-role="queries-menu"]').empty();

        if (data.private.length === 0 && data.public.length === 0) {
            $menu.append($('<li/>', {'class': 'aaxis-db-viewer__menu-empty'})
                .text(__('aaxis.devtools.database_viewer.queries_empty')));
            return;
        }

        this.appendQuerySection($menu, __('aaxis.devtools.database_viewer.queries_private'), data.private);
        if (data.public.length > 0) {
            $menu.append($('<li/>', {'class': 'divider'}));
            this.appendQuerySection($menu, __('aaxis.devtools.database_viewer.queries_public'), data.public);
        }

        if (data.hasMorePublic || data.hasMorePrivate) {
            $menu.append($('<li/>', {'class': 'divider'}));
            $menu.append($('<li/>').append($('<a/>', {
                href: '#',
                role: 'menuitem',
                'data-role': 'view-all-queries',
                text: __('aaxis.devtools.database_viewer.queries_view_all')
            })));
        }
    }

    private appendQuerySection($menu: any, title: string, items: SavedQueryItem[]): void {
        if (items.length === 0) {
            return;
        }
        $menu.append($('<li/>', {'class': 'dropdown-header', text: title}));
        items.forEach(item => $menu.append(this.buildQueryMenuItem(item)));
    }

    private buildQueryMenuItem(item: SavedQueryItem): any {
        const iconClass = item.public ? 'fa fa-star is-public' : 'fa fa-star is-private';
        return $('<li/>').append($('<a/>', {
            href: '#',
            role: 'menuitem',
            'data-role': 'load-query',
            'data-query': JSON.stringify(item)
        }).append(
            $('<span/>', {'class': iconClass + ' aaxis-db-viewer__menu-icon', 'aria-hidden': 'true'}),
            $('<span/>', {text: item.name})
        ));
    }

    private onLoadQuery(event: any): void {
        event.preventDefault();
        const item = $(event.currentTarget).data('query') as SavedQueryItem;
        if (item) {
            this.loadSavedQuery(item);
        }
    }

    private loadSavedQuery(item: SavedQueryItem): void {
        this.saveActiveTab();
        const tab = this.createTab({
            name: item.name,
            sql: item.text,
            savedId: item.id,
            savedPublic: item.public,
            savedOwned: item.owned,
            savedText: item.text
        });
        this.tabs.push(tab);
        this.activeTabId = tab.id;
        if (this.editor) {
            this.editor.setValue(item.text);
            this.editor.focus();
        }
        this.renderTabs();
        this.updateUpdateButton();
    }

    private onViewAllQueries(event: any): void {
        event.preventDefault();
        $.ajax({url: this.savedQueriesUrl + '/all', method: 'GET'})
            .done((response: {items: SavedQueryItem[]}) => {
                this.openAllQueriesModal(response.items || []);
            });
    }

    private openAllQueriesModal(items: SavedQueryItem[]): void {
        const $list = $('<ul/>', {'class': 'aaxis-db-viewer__all-queries'});
        if (items.length === 0) {
            $list.append($('<li/>').text(__('aaxis.devtools.database_viewer.queries_empty')));
        } else {
            items.forEach(item => {
                const iconClass = item.public ? 'fa fa-star is-public' : 'fa fa-star is-private';
                $list.append($('<li/>').append($('<a/>', {
                    href: '#',
                    'data-role': 'all-query-item'
                }).data('query', item).append(
                    $('<span/>', {'class': iconClass + ' aaxis-db-viewer__menu-icon', 'aria-hidden': 'true'}),
                    $('<span/>', {text: item.name})
                )));
            });
        }

        const modal = new Modal({
            title: __('aaxis.devtools.database_viewer.all_queries_title'),
            content: $list,
            allowOk: false,
            cancelText: __('Close')
        });

        $list.on('click', '[data-role="all-query-item"]', (e: any) => {
            e.preventDefault();
            const item = $(e.currentTarget).data('query') as SavedQueryItem;
            modal.close();
            if (item) {
                this.loadSavedQuery(item);
            }
        });

        modal.open();
    }

    // --- Update --------------------------------------------------------------

    private updateUpdateButton(): void {
        const tab = this.activeTab();
        const $btn = this.$el.find('[data-role="update"]');
        const isSavedOwned = !!tab && tab.savedId !== null && tab.savedOwned;
        $btn.prop('hidden', !isSavedOwned);

        if (!isSavedOwned || !this.editor || !tab) {
            $btn.prop('disabled', true);
            return;
        }
        const changed = String(this.editor.getValue()) !== String(tab.savedText ?? '');
        $btn.prop('disabled', !changed);
    }

    private onUpdateQuery(event: any): void {
        event.preventDefault();
        const tab = this.activeTab();
        if (!tab || tab.savedId === null || !this.editor) {
            return;
        }
        const text = String(this.editor.getValue());

        $.ajax({
            url: this.savedQueriesUrl + '/' + tab.savedId,
            method: 'PUT',
            contentType: 'application/json',
            data: JSON.stringify({text})
        }).done((response: {success: boolean}) => {
            if (response.success) {
                tab.savedText = text;
                this.updateUpdateButton();
                this.loadQueriesMenu();
                messenger.notificationFlashMessage('success', __('aaxis.devtools.database_viewer.uq_updated'));
            }
        }).fail(() => {
            messenger.notificationFlashMessage('error', __('aaxis.devtools.database_viewer.uq_error'));
        });
    }

    // --- Resizable splitters -------------------------------------------------

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
        this.$el.on('mousedown.aaxisDbViewer', selector, (event: any) => {
            event.preventDefault();
            const startX = event.clientX;
            const startY = event.clientY;
            const startSize = getStartSize();

            $('body').addClass('aaxis-db-viewer-resizing');

            const move = (e: any) => onMove(e.clientX - startX, e.clientY - startY, startSize);
            const up = () => {
                $(document).off('mousemove.aaxisDbViewerDrag mouseup.aaxisDbViewerDrag');
                $('body').removeClass('aaxis-db-viewer-resizing');
            };

            $(document)
                .on('mousemove.aaxisDbViewerDrag', move)
                .on('mouseup.aaxisDbViewerDrag', up);
        });
    }

    private refreshEditor(): void {
        if (this.editor) {
            this.editor.refresh();
        }
    }

    // --- SQL beautify --------------------------------------------------------

    private onBeautify(event: any): void {
        event.preventDefault();
        if (!this.editor) {
            return;
        }
        const value = String(this.editor.getValue());
        if (value.trim() === '') {
            return;
        }
        try {
            this.editor.setValue(this.formatSql(value));
        } catch (e) {
            messenger.notificationFlashMessage('warning', __('aaxis.devtools.database_viewer.beautify_error'));
        }
    }

    /**
     * Lightweight SQL pretty-printer: only adds whitespace/newlines and uppercases keywords.
     * String literals, quoted identifiers and comments are protected so they are never altered.
     */
    private formatSql(raw: string): string {
        const store: string[] = [];
        const stash = (m: string): string => {
            store.push(m);
            return '\u0001' + (store.length - 1) + '\u0001';
        };

        let s = raw
            .replace(/--[^\n]*/g, stash)
            .replace(/\/\*[\s\S]*?\*\//g, stash)
            .replace(/'(?:[^']|'')*'/g, stash)
            .replace(/"(?:[^"]|"")*"/g, stash)
            .replace(/\s+/g, ' ')
            .trim();

        // Break top-level commas (paren depth 0) so list items land on their own line.
        let depth = 0;
        let commaBroken = '';
        for (let i = 0; i < s.length; i++) {
            const ch = s.charAt(i);
            if (ch === '(') {
                depth++;
            } else if (ch === ')') {
                depth = Math.max(0, depth - 1);
            }
            commaBroken += (ch === ',' && depth === 0) ? ',\n' : ch;
        }
        s = commaBroken;

        // Clause keywords that start a new line (multi-word variants first so they win).
        const clauses = [
            'LEFT OUTER JOIN', 'RIGHT OUTER JOIN', 'FULL OUTER JOIN', 'LEFT JOIN', 'RIGHT JOIN',
            'INNER JOIN', 'CROSS JOIN', 'FULL JOIN', 'GROUP BY', 'ORDER BY', 'DELETE FROM',
            'INSERT INTO', 'UNION ALL', 'SELECT', 'FROM', 'WHERE', 'HAVING', 'LIMIT', 'OFFSET',
            'UNION', 'VALUES', 'UPDATE', 'SET', 'RETURNING', 'JOIN'
        ];
        clauses.forEach(kw => {
            const re = new RegExp('\\s*\\b' + kw.replace(/ /g, '\\s+') + '\\b\\s*', 'gi');
            s = s.replace(re, '\n' + kw + ' ');
        });

        // Sub-clause keywords get their own indented line.
        ['AND', 'OR', 'ON'].forEach(kw => {
            const re = new RegExp('\\s*\\b' + kw + '\\b\\s*', 'gi');
            s = s.replace(re, '\n' + kw + ' ');
        });

        // Lines that don't start a clause (column continuations, AND/OR/ON) are indented.
        const clauseStart = new RegExp('^(' + clauses.map(kw => kw.replace(/ /g, '\\s+')).join('|') + ')\\b', 'i');
        s = s.split('\n')
            .map(line => line.trim())
            .filter(line => line !== '')
            .map(line => (clauseStart.test(line) ? line : '  ' + line))
            .join('\n');

        return s.replace(/\u0001(\d+)\u0001/g, (_m, i) => store[Number(i)]);
    }

    // --- Object list ---------------------------------------------------------

    private loadObjects(): void {
        // The metric (none/quantity/bytes) follows the order-key button so counts/sizes are only
        // computed server-side when the user asks for them.
        $.ajax({url: this.objectsUrl, method: 'GET', data: {metric: ORDER_METRIC[this.orderMode]}})
            .done((response: {objects?: DatabaseObject[]}) => {
                this.objects = response.objects || [];
                this.renderObjects();
            })
            .fail(() => {
                messenger.notificationFlashMessage('error', __('aaxis.devtools.database_viewer.tables_error'));
            });
    }

    private renderObjects(): void {
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
            $list.append($('<li/>', {'class': 'aaxis-db-viewer__empty'})
                .text(__('aaxis.devtools.database_viewer.no_objects')));
            return;
        }

        visible.forEach(object => {
            const iconClass = TYPE_ICONS[object.type] || 'fa-question';
            const expandable = object.type === 'table' || object.type === 'view';
            const isExpanded = expandable && this.expanded[object.name] === true;

            const $row = $('<div/>', {'class': 'aaxis-db-viewer__object-row'});

            if (expandable) {
                $row.append($('<button/>', {
                    type: 'button',
                    'class': 'aaxis-db-viewer__object-toggle',
                    'data-role': 'object-expand',
                    'data-name': object.name,
                    'aria-expanded': isExpanded ? 'true' : 'false',
                    'aria-label': object.name
                }).append($('<span/>', {
                    'class': 'fa ' + (isExpanded ? 'fa-chevron-down' : 'fa-chevron-right'),
                    'aria-hidden': 'true'
                })));
            } else {
                $row.append($('<span/>', {'class': 'aaxis-db-viewer__object-toggle aaxis-db-viewer__object-toggle--empty'}));
            }

            const $link = $('<a/>', {
                href: '#',
                'data-role': 'object-item',
                'data-name': object.name,
                'data-type': object.type,
                title: object.name
            }).append(
                $('<span/>', {
                    'class': 'fa ' + iconClass + ' aaxis-db-viewer__object-icon',
                    'aria-hidden': 'true'
                }),
                $('<span/>', {'class': 'aaxis-db-viewer__object-name', text: object.name})
            );
            if (this.orderMode !== 'name' && object.count !== undefined) {
                $link.append($('<span/>', {
                    'class': 'aaxis-db-viewer__object-count',
                    text: object.count
                }));
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
        const $cols = $('<ul/>', {'class': 'aaxis-db-viewer__columns'});

        if (this.loadingColumns[name]) {
            $cols.append($('<li/>', {'class': 'aaxis-db-viewer__columns-info'})
                .append($('<span/>', {'class': 'fa fa-spinner fa-spin', 'aria-hidden': 'true'})));
            return $cols;
        }

        const columns = this.columnsByName[name] || [];
        if (columns.length === 0) {
            $cols.append($('<li/>', {'class': 'aaxis-db-viewer__columns-info', text: __('aaxis.devtools.database_viewer.no_columns')}));
            return $cols;
        }

        columns.forEach(col => {
            $cols.append($('<li/>', {'class': 'aaxis-db-viewer__column'}).append(
                $('<span/>', {
                    'class': 'aaxis-db-viewer__column-name' + (col.nullable ? '' : ' is-required'),
                    text: col.name,
                    title: col.nullable ? col.name : col.name + ' (not null)'
                }),
                $('<span/>', {'class': 'aaxis-db-viewer__column-type', text: col.type})
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
            $.ajax({url: this.columnsUrl, method: 'GET', data: {name}})
                .done((response: {columns?: ColumnInfo[]}) => {
                    this.columnsByName[name] = response.columns || [];
                })
                .fail(() => {
                    this.columnsByName[name] = [];
                    messenger.notificationFlashMessage('error', __('aaxis.devtools.database_viewer.columns_error'));
                })
                .always(() => {
                    delete this.loadingColumns[name];
                    this.renderObjects();
                });
        }
        this.renderObjects();
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

    // --- Query execution -----------------------------------------------------

    private onRun(): void {
        if (this.running) {
            return;
        }
        const query = this.editor ? String(this.editor.getValue()).trim() : '';
        if (query === '') {
            messenger.notificationFlashMessage('warning', __('aaxis.devtools.database_viewer.empty_query'));
            return;
        }

        this.setRunning(true);
        this.setStatus(__('aaxis.devtools.database_viewer.running'));

        $.ajax({
            url: this.queryUrl,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({query})
        }).done((response: QueryResponse) => {
            this.onResult(response);
        }).fail((jqXhr: any) => {
            const response: QueryResponse = (jqXhr.responseJSON as QueryResponse) || {};
            this.renderError(response.message || __('aaxis.devtools.database_viewer.query_error'));
        }).always(() => {
            this.setRunning(false);
        });
    }

    private onResult(response: QueryResponse): void {
        this.lastResult = {columns: response.columns || [], rows: response.rows || []};
        this.hiddenColumns = [];
        this.hiddenRows = [];
        this.clearSelection();
        this.setExportEnabled(this.lastResult.columns.length > 0);

        const summary = __('aaxis.devtools.database_viewer.result_summary', {
            count: response.rowCount || 0,
            duration: response.durationMs || 0
        });
        this.setStatus(summary + (response.truncated ? ' ' + __('aaxis.devtools.database_viewer.truncated') : ''));

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
            $result.append($('<p/>', {'class': 'aaxis-db-viewer__empty'})
                .text(__('aaxis.devtools.database_viewer.no_rows')));
            return;
        }

        const $table = $('<table/>', {
            'class': 'grid table-hover table table-bordered table-condensed aaxis-db-viewer__grid'
        });

        const $headRow = $('<tr/>').appendTo($('<thead/>').appendTo($table));
        if (this.showRowNumbers) {
            $headRow.append($('<th/>', {'class': 'aaxis-db-viewer__rownum-cell', text: '#'}));
        }
        columns.forEach(column => {
            $headRow.append($('<th/>', {
                'data-role': 'col-header',
                'data-column': column,
                text: column
            }));
        });

        const $body = $('<tbody/>').appendTo($table);
        let ordinal = 0;
        this.visibleRows().forEach(([originalIndex, row]) => {
            ordinal += 1;
            const $tr = $('<tr/>', {'data-row-index': originalIndex}).appendTo($body);
            if (this.showRowNumbers) {
                $tr.append($('<td/>', {
                    'class': 'aaxis-db-viewer__rownum-cell',
                    'data-role': 'row-select',
                    text: ordinal
                }));
            }
            columns.forEach(column => {
                const value = row[column];
                const $td = $('<td/>', {'data-role': 'data-cell', 'data-column': column});
                if (value === null || value === undefined) {
                    $td.addClass('aaxis-db-viewer__null-cell').text('(null)');
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
        const $table = this.$el.find('.aaxis-db-viewer__grid');
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

    private escapeHtml(value: string): string {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
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
        const $menu = $('<ul/>', {'class': 'aaxis-db-viewer__context-menu', role: 'menu'});
        const items: Array<[string, string]> = [
            ['copy-json', __('aaxis.devtools.database_viewer.context_copy_json')],
            ['copy-csv', __('aaxis.devtools.database_viewer.context_copy_csv')],
            ['hide', __('aaxis.devtools.database_viewer.context_hide')]
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
        $menu.css({
            display: 'block',
            left: event.clientX + 'px',
            top: event.clientY + 'px'
        });

        setTimeout(() => {
            $(document).on('mousedown.aaxisDbViewerCtx', (e: any) => {
                if (!$(e.target).closest('.aaxis-db-viewer__context-menu').length) {
                    this.hideContextMenu();
                }
            });
            $(document).on('keydown.aaxisDbViewerCtx', (e: any) => {
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
        $(document).off('.aaxisDbViewerCtx');
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
        const text = value === null || value === undefined ? '' : String(value);
        return /[",\r\n]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
    }

    private copyToClipboard(text: string): void {
        const onSuccess = () => messenger.notificationFlashMessage(
            'success', __('aaxis.devtools.database_viewer.copied')
        );
        const onError = () => messenger.notificationFlashMessage(
            'error', __('aaxis.devtools.database_viewer.copy_error')
        );

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
            messenger.notificationFlashMessage('error', __('aaxis.devtools.database_viewer.export_error'));
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
        this.$el.off('.aaxisDbViewer');
        $(document).off('.aaxisDbViewerDrag .aaxisDbViewerCtx');
        $('body').removeClass('aaxis-db-viewer-resizing');
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

export default DatabaseViewerComponent;
