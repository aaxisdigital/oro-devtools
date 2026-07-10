import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import messenger from 'oroui/js/messenger';
import mediator from 'oroui/js/mediator';
import Modal from 'oroui/js/modal';
import BaseComponent from 'oroui/js/app/components/base/component';
import CodeMirror from 'codemirror';
import 'codemirror/mode/sql/sql';

/**
 * Options shared by the query-style viewers. Subclasses extend this with their sidebar URLs.
 */
export interface QueryViewerOptions {
    _sourceElement: any;
    queryUrl: string;
    exportUrl: string;
    savedQueriesUrl: string;
    showRowNumbers?: boolean;
    allowExport?: boolean;
}

export interface QueryResponse {
    success: boolean;
    message?: string;
    columns?: string[];
    columnTypes?: Record<string, string>;
    rows?: Array<Record<string, unknown>>;
    rowCount?: number;
    truncated?: boolean;
    durationMs?: number;
}

export interface SavedQueryItem {
    id: number;
    name: string;
    public: boolean;
    text: string;
    owned: boolean;
}

export interface QueryTab {
    id: string;
    name: string;
    sql: string;
    savedId: number | null;
    savedPublic: boolean;
    savedOwned: boolean;
    savedText: string | null;
    // Per-tab run state so tabs execute independently (a run keeps going while you view another tab).
    running: boolean;
    pending: any;
}

/**
 * What we persist per tab so switching tabs restores that tab's own result instead of showing
 * whatever the last-run tab produced. Either a successful grid payload or an error message.
 */
export interface StoredResult {
    columns?: string[];
    columnTypes?: Record<string, string>;
    rows?: Array<Record<string, unknown>>;
    rowCount?: number;
    durationMs?: number;
    truncated?: boolean;
    error?: string;
}

type SelectionMode = 'none' | 'columns' | 'rows';

/**
 * Shared behaviour of the SQL-style viewers (Database Viewer, Elastic Viewer): the query editor,
 * independent query tabs, saved-query favourites, the unsaved-changes guard, resizable splitters,
 * per-tab result storage, the result grid with column/row selection, copy/hide context menu and
 * export.
 *
 * A subclass supplies the parts that genuinely differ: the sidebar (tables/views/functions vs
 * indices), the editor mode, the pretty-printer, and a handful of naming hooks (CSS prefix,
 * translation root, event namespace, result-storage prefix). Everything else lives here once.
 *
 * NOTE: instance state is initialised inside initialize() (not via class field initializers).
 * BaseComponent calls initialize() from within its constructor, so field initializers would run
 * afterwards and clobber values set during initialize().
 */
abstract class AbstractQueryViewerComponent extends BaseComponent {
    protected $el!: any;
    protected editor!: any;
    protected ns!: string;

    protected queryUrl!: string;
    protected exportUrl!: string;
    protected savedQueriesUrl!: string;
    protected allowExport!: boolean;
    protected showRowNumbers!: boolean;

    protected filterText!: string;

    // In-memory fallback for results too large for sessionStorage (quota); keyed by tab id.
    private resultFallback!: Record<string, StoredResult>;

    protected lastResult!: {
        columns: string[];
        rows: Array<Record<string, unknown>>;
        columnTypes?: Record<string, string>;
    } | null;
    protected hiddenColumns!: string[];
    protected hiddenRows!: number[];

    private selectionMode!: SelectionMode;
    private selectedColumns!: string[];
    private selectedRows!: number[];
    private lastColIndex!: number;
    private lastRowOrdinal!: number;

    protected tabs!: QueryTab[];
    protected activeTabId!: string;
    protected tabSeq!: number;

    private $contextMenu!: any;

    // --- Subclass hooks ------------------------------------------------------

    /** BEM block, e.g. 'aaxis-db-viewer'. */
    protected abstract cssPrefix(): string;
    /** Translation root, e.g. 'aaxis.devtools.database_viewer'. */
    protected abstract transPrefix(): string;
    /** jQuery event namespace (no dot), e.g. 'aaxisDbViewer'. */
    protected abstract eventNs(): string;
    /** sessionStorage key prefix for this viewer's per-tab results. */
    protected abstract storagePrefix(): string;
    /** CodeMirror mode for the editor. */
    protected abstract editorMode(): string;
    /** Pretty-print the query text (SQL / ES|QL). */
    protected abstract formatQuery(raw: string): string;
    /** Read sidebar-specific options and initialise sidebar state. Runs before the editor. */
    protected abstract setupSidebar(options: any): void;
    /** (Re)load the sidebar list from the server. */
    protected abstract loadSidebar(): void;
    /** Render the sidebar list from current state (also used by the filter). */
    protected abstract renderSidebar(): void;
    /** Render a loading placeholder in the sidebar list. */
    protected abstract renderSidebarLoading(): void;
    /** Bind sidebar-specific events (ordering, item click, expand, type toggles). */
    protected abstract bindSidebarEvents(): void;
    /** Optional: run after the whole viewer is set up (e.g. render order buttons). */
    protected afterSetup(): void {
        // no-op by default
    }
    /** Optional: sidebar cleanup on dispose. */
    protected disposeSidebar(): void {
        // no-op by default
    }

    // --- Naming helpers ------------------------------------------------------

    /** BEM element class: cls('tab') -> 'aaxis-db-viewer__tab'. */
    protected cls(element: string): string {
        return this.cssPrefix() + '__' + element;
    }

    /** Translate a key under this viewer's root: t('run') -> __('aaxis.devtools.<viewer>.run'). */
    protected t(key: string, params?: Record<string, unknown>): string {
        return __(this.transPrefix() + '.' + key, params);
    }

    // --- Lifecycle -----------------------------------------------------------

    initialize(options: QueryViewerOptions): void {
        this.$el = options._sourceElement;
        this.ns = this.eventNs();
        this.queryUrl = options.queryUrl;
        this.exportUrl = options.exportUrl;
        this.savedQueriesUrl = options.savedQueriesUrl;
        this.allowExport = options.allowExport !== false;
        this.showRowNumbers = options.showRowNumbers !== false;
        this.filterText = '';

        this.editor = null;
        this.resultFallback = {};
        this.clearAllResults();

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

        this.setupSidebar(options);
        this.initEditor();
        this.initTabs();
        this.initSplitters();
        this.loadSidebar();
        this.loadQueriesMenu();
        this.bindEvents();
        this.bindSidebarEvents();
        this.updateUpdateButton();
        this.afterSetup();

        // Guard saved queries with unsaved edits against being lost on navigation.
        mediator.on('openLink:before', this.onBeforePageChange, this);
        $(window).on('beforeunload.' + this.ns, this.onWindowUnload.bind(this));
    }

    private bindEvents(): void {
        const ns = '.' + this.ns;
        this.$el.on('click' + ns, '[data-role="run"]', this.onRun.bind(this));
        this.$el.on('click' + ns, '[data-role="beautify"]', this.onBeautify.bind(this));
        this.$el.on('click' + ns, '[data-role="export-format"]', this.onExport.bind(this));
        this.$el.on('click' + ns, '[data-role="refresh"]', this.onRefresh.bind(this));
        this.$el.on('click' + ns, '[data-role="help"]', this.onHelp.bind(this));
        this.$el.on('input' + ns, '[data-role="filter"]', (e: any) => {
            this.filterText = String(e.currentTarget.value || '').toLowerCase();
            this.updateFilterClear();
            this.renderSidebar();
        });
        this.$el.on('click' + ns, '[data-role="filter-clear"]', this.onFilterClear.bind(this));

        // Tabs
        this.$el.on('click' + ns, '[data-role="tab-add"]', this.onAddTab.bind(this));
        this.$el.on('click' + ns, '[data-role="tab"]', this.onTabClick.bind(this));
        this.$el.on('click' + ns, '[data-role="tab-close"]', this.onCloseTab.bind(this));
        this.$el.on('click' + ns, '[data-role="tab-star"]', this.onStarClick.bind(this));

        // Saved queries
        this.$el.on('click' + ns, '[data-role="load-query"]', this.onLoadQuery.bind(this));
        this.$el.on('click' + ns, '[data-role="view-all-queries"]', this.onViewAllQueries.bind(this));
        this.$el.on('click' + ns, '[data-role="update"]', this.onUpdateQuery.bind(this));

        // Result selection + context menu
        this.$el.on('click' + ns, '[data-role="col-header"]', this.onColumnHeaderClick.bind(this));
        this.$el.on('click' + ns, '[data-role="data-cell"], [data-role="row-select"]', this.onRowClick.bind(this));
        this.$el.on('contextmenu' + ns, '[data-role="result"]', this.onResultContextMenu.bind(this));
    }

    // --- Sidebar filter (shared) ---------------------------------------------

    protected onFilterClear(event: any): void {
        event.preventDefault();
        const $input = this.$el.find('[data-role="filter"]');
        $input.val('');
        this.filterText = '';
        this.updateFilterClear();
        this.renderSidebar();
        $input.trigger('focus');
    }

    protected updateFilterClear(): void {
        this.$el.find('[data-role="filter-clear"]').prop('hidden', this.filterText === '');
    }

    protected onRefresh(event: any): void {
        event.preventDefault();
        this.loadSidebar();
    }

    private onHelp(event: any): void {
        event.preventDefault();
        const html = this.$el.find('[data-role="help-content"]').html();
        const modal = new Modal({
            title: this.t('help'),
            content: html,
            allowOk: false,
            cancelText: __('Close')
        });
        modal.open();
    }

    // --- CodeMirror ----------------------------------------------------------

    private initEditor(): void {
        const textarea = this.$el.find('[data-role="editor"]').get(0);
        if (!textarea) {
            return;
        }
        this.editor = CodeMirror.fromTextArea(textarea, {
            mode: this.editorMode(),
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

    protected refreshEditor(): void {
        if (this.editor) {
            this.editor.refresh();
        }
    }

    // --- Query tabs ----------------------------------------------------------

    private initTabs(): void {
        const initialSql = this.editor ? this.editor.getValue() : '';
        const tab = this.createTab({sql: initialSql});
        this.tabs.push(tab);
        this.activeTabId = tab.id;
        this.renderTabs();
        this.renderActiveResult();
    }

    private createTab(opts: Partial<QueryTab> & {sql: string}): QueryTab {
        this.tabSeq += 1;
        return {
            id: 'tab-' + this.tabSeq,
            name: opts.name || this.t('tab_name', {number: this.tabSeq}),
            sql: opts.sql,
            savedId: opts.savedId ?? null,
            savedPublic: opts.savedPublic ?? false,
            savedOwned: opts.savedOwned ?? false,
            savedText: opts.savedText ?? null,
            running: false,
            pending: null
        };
    }

    protected activeTab(): QueryTab | undefined {
        return this.tabs.find(t => t.id === this.activeTabId);
    }

    private renderTabs(): void {
        const $list = this.$el.find('[data-role="tabs-list"]').empty();
        const closable = this.tabs.length > 1;

        this.tabs.forEach(tab => {
            const $tab = $('<span/>', {
                'class': this.cls('tab') + (tab.id === this.activeTabId ? ' is-active' : ''),
                'data-role': 'tab',
                'data-id': tab.id
            });

            let starClass = this.cls('tab-star') + ' fa ';
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

            $tab.append($('<span/>', {'class': this.cls('tab-name'), text: tab.name}));
            if (tab.running) {
                $tab.append($('<span/>', {
                    'class': this.cls('tab-spinner') + ' fa fa-spinner fa-spin',
                    'aria-hidden': 'true'
                }));
            }
            if (closable) {
                $tab.append($('<button/>', {
                    type: 'button',
                    'class': this.cls('tab-close'),
                    'data-role': 'tab-close',
                    'data-id': tab.id,
                    'aria-label': this.t('close_tab'),
                    title: this.t('close_tab'),
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
        this.renderActiveResult();
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
        this.renderActiveResult();
    }

    private onCloseTab(event: any): void {
        event.preventDefault();
        event.stopPropagation();
        if (this.tabs.length <= 1) {
            return;
        }
        const id = String($(event.currentTarget).data('id'));
        const tab = this.tabs.find(t => t.id === id);
        if (!tab) {
            return;
        }
        this.saveActiveTab();
        if (this.isTabModified(tab)) {
            this.confirmDiscardTab(tab);
            return;
        }
        this.closeTab(id);
    }

    private closeTab(id: string): void {
        const index = this.tabs.findIndex(t => t.id === id);
        if (index === -1 || this.tabs.length <= 1) {
            return;
        }
        const [removed] = this.tabs.splice(index, 1);
        if (removed.pending && typeof removed.pending.abort === 'function') {
            removed.pending.abort();
        }
        this.removeResult(removed.id);
        const wasActive = this.activeTabId === id;
        if (wasActive) {
            const next = this.tabs[Math.max(0, index - 1)];
            this.activeTabId = next.id;
            if (this.editor) {
                this.editor.setValue(next.sql);
            }
        }
        this.renderTabs();
        this.updateUpdateButton();
        if (wasActive) {
            this.renderActiveResult();
        }
    }

    private confirmDiscardTab(tab: QueryTab): void {
        const modal = new Modal({
            title: this.t('unsaved_title'),
            content: this.t('unsaved_close_confirm', {name: this.escapeHtml(tab.name)}),
            okText: this.t('unsaved_discard')
        });
        modal.on('ok', () => this.closeTab(tab.id));
        modal.open();
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
            '<form class="' + this.cls('save-form') + '">' +
                '<div class="control-group">' +
                    '<label class="control-label">' + this.t('sq_name') + '</label>' +
                    '<input type="text" class="form-control" data-role="sq-name" maxlength="40" value="' +
                        this.escapeHtml(tab.name) + '">' +
                '</div>' +
                '<div class="control-group">' +
                    '<label class="control-label">' + this.t('sq_visibility') + '</label>' +
                    '<label class="radio"><input type="radio" name="sq-visibility" value="private"' +
                        (currentPublic ? '' : ' checked') + '> ' +
                        this.t('sq_private') + '</label>' +
                    '<label class="radio"><input type="radio" name="sq-visibility" value="public"' +
                        (currentPublic ? ' checked' : '') + '> ' +
                        this.t('sq_public') + '</label>' +
                '</div>' +
            '</form>';

        const modal = new Modal({
            title: mode === 'edit' ? this.t('sq_edit_title') : this.t('sq_title'),
            content,
            okText: this.t('sq_save'),
            okCloses: false
        });

        modal.on('ok', () => {
            const name = String(modal.$el.find('[data-role="sq-name"]').val() || '').trim();
            const isPublic = modal.$el.find('input[name="sq-visibility"]:checked').val() === 'public';
            if (name === '') {
                messenger.notificationFlashMessage('warning', this.t('sq_name_required'));
                return;
            }

            const request = mode === 'edit' && tab.savedId !== null
                ? $.ajax({
                    url: this.savedQueriesUrl + '/' + tab.savedId,
                    method: 'PUT',
                    contentType: 'application/json',
                    data: JSON.stringify({name, public: isPublic}),
                    errorHandlerMessage: false
                })
                : $.ajax({
                    url: this.savedQueriesUrl,
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({name, text, public: isPublic}),
                    errorHandlerMessage: false
                });

            request.done((response: {success: boolean; query?: SavedQueryItem}) => {
                if (response.success && response.query) {
                    this.applySavedQuery(tab, response.query);
                    messenger.notificationFlashMessage('success', this.t('sq_saved'));
                    this.loadQueriesMenu();
                }
                modal.close();
            }).fail((jqXhr: any) => {
                const message = (jqXhr.responseJSON && jqXhr.responseJSON.message) || this.t('sq_error');
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
            $menu.append($('<li/>', {'class': this.cls('menu-empty')}).text(this.t('queries_empty')));
            return;
        }

        this.appendQuerySection($menu, this.t('queries_private'), data.private);
        if (data.public.length > 0) {
            $menu.append($('<li/>', {'class': 'divider'}));
            this.appendQuerySection($menu, this.t('queries_public'), data.public);
        }

        if (data.hasMorePublic || data.hasMorePrivate) {
            $menu.append($('<li/>', {'class': 'divider'}));
            $menu.append($('<li/>').append($('<a/>', {
                href: '#',
                role: 'menuitem',
                'data-role': 'view-all-queries',
                text: this.t('queries_view_all')
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
            $('<span/>', {'class': iconClass + ' ' + this.cls('menu-icon'), 'aria-hidden': 'true'}),
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
        this.renderActiveResult();
    }

    private onViewAllQueries(event: any): void {
        event.preventDefault();
        $.ajax({url: this.savedQueriesUrl + '/all', method: 'GET'})
            .done((response: {items: SavedQueryItem[]}) => {
                this.openAllQueriesModal(response.items || []);
            });
    }

    private openAllQueriesModal(items: SavedQueryItem[]): void {
        const $list = $('<ul/>', {'class': this.cls('all-queries')});
        if (items.length === 0) {
            $list.append($('<li/>').text(this.t('queries_empty')));
        } else {
            items.forEach(item => {
                const iconClass = item.public ? 'fa fa-star is-public' : 'fa fa-star is-private';
                $list.append($('<li/>').append($('<a/>', {
                    href: '#',
                    'data-role': 'all-query-item'
                }).data('query', item).append(
                    $('<span/>', {'class': iconClass + ' ' + this.cls('menu-icon'), 'aria-hidden': 'true'}),
                    $('<span/>', {text: item.name})
                )));
            });
        }

        const modal = new Modal({
            title: this.t('all_queries_title'),
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
            data: JSON.stringify({text}),
            errorHandlerMessage: false
        }).done((response: {success: boolean}) => {
            if (response.success) {
                tab.savedText = text;
                this.updateUpdateButton();
                this.loadQueriesMenu();
                messenger.notificationFlashMessage('success', this.t('uq_updated'));
            }
        }).fail(() => {
            messenger.notificationFlashMessage('error', this.t('uq_error'));
        });
    }

    // --- Unsaved changes guard ------------------------------------------------

    /** Only queries that are already saved count: new never-saved tabs die silently. */
    private isTabModified(tab: QueryTab): boolean {
        return tab.savedId !== null && tab.sql !== String(tab.savedText ?? '');
    }

    private modifiedSavedTabs(): QueryTab[] {
        this.saveActiveTab();
        return this.tabs.filter(tab => this.isTabModified(tab));
    }

    private onWindowUnload(event: any): string | undefined {
        if (this.modifiedSavedTabs().length === 0) {
            return undefined;
        }
        const message = this.t('unsaved_leave_intro');
        event.preventDefault();
        if (event.originalEvent) {
            event.originalEvent.returnValue = message;
        }
        return message;
    }

    /**
     * Oro SPA navigation (menu/link clicks) publishes 'openLink:before' and honours
     * payload.prevented. When saved queries carry unsaved edits, the navigation is held back,
     * the user resolves each query (save or discard) in a dialog, and on confirm the original
     * target URL is re-triggered via redirectTo.
     */
    private onBeforePageChange(payload: {prevented: boolean; target: HTMLElement}): void {
        if (payload.prevented) {
            return;
        }
        const modified = this.modifiedSavedTabs();
        if (modified.length === 0) {
            return;
        }
        payload.prevented = true;
        const $target = $(payload.target);
        const url = String($target.attr('href') || $target.data('href') || '');
        const options = $target.data('options') || {};
        this.openLeaveDialog(modified, () => {
            if (url) {
                mediator.execute('redirectTo', {url}, options);
            }
        });
    }

    private openLeaveDialog(modified: QueryTab[], proceed: () => void): void {
        let html = '<div class="' + this.cls('leave') + '">' +
            '<p>' + this.t('unsaved_leave_intro') + '</p>' +
            '<table class="' + this.cls('leave-list') + '">';
        modified.forEach(tab => {
            html += '<tr>' +
                '<td class="' + this.cls('leave-name') + '">' + this.escapeHtml(tab.name) + '</td>' +
                '<td><label class="radio">' +
                    '<input type="radio" name="leave-' + tab.id + '" value="discard" checked> ' +
                    this.t('unsaved_discard') + '</label></td>' +
                '<td>' + (tab.savedOwned
                    ? '<label class="radio"><input type="radio" name="leave-' + tab.id + '" value="save"> ' +
                        this.t('unsaved_save') + '</label>'
                    : '') + '</td>' +
                '</tr>';
        });
        html += '</table>';
        if (modified.length > 1) {
            html += '<div class="' + this.cls('leave-all') + '">' +
                '<a href="#" data-role="leave-all" data-value="discard">' +
                    this.t('unsaved_discard_all') + '</a>' +
                '<a href="#" data-role="leave-all" data-value="save">' +
                    this.t('unsaved_save_all') + '</a>' +
                '</div>';
        }
        html += '</div>';

        const modal = new Modal({
            title: this.t('unsaved_title'),
            content: html,
            okText: this.t('unsaved_leave_ok'),
            okCloses: false
        });

        modal.on('ok', () => {
            const toSave = modified.filter(tab => tab.savedOwned && tab.savedId !== null
                && modal.$el.find('input[name="leave-' + tab.id + '"]:checked').val() === 'save');
            const requests = toSave.map(tab => $.ajax({
                url: this.savedQueriesUrl + '/' + tab.savedId,
                method: 'PUT',
                contentType: 'application/json',
                data: JSON.stringify({text: tab.sql}),
                errorHandlerMessage: false
            }));
            $.when(...requests).done(() => {
                toSave.forEach(tab => {
                    tab.savedText = tab.sql;
                });
                modal.close();
                proceed();
            }).fail(() => {
                messenger.notificationFlashMessage('error', this.t('uq_error'));
            });
        });

        modal.open();
        modal.$el.on('click', '[data-role="leave-all"]', (e: any) => {
            e.preventDefault();
            const value = String($(e.currentTarget).data('value'));
            modal.$el.find('input[type="radio"][value="' + value + '"]').prop('checked', true);
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
        const resizingClass = this.cssPrefix() + '-resizing';
        this.$el.on('mousedown.' + this.ns, selector, (event: any) => {
            event.preventDefault();
            const startX = event.clientX;
            const startY = event.clientY;
            const startSize = getStartSize();

            $('body').addClass(resizingClass);

            const move = (e: any) => onMove(e.clientX - startX, e.clientY - startY, startSize);
            const up = () => {
                $(document).off('mousemove.' + this.ns + 'Drag mouseup.' + this.ns + 'Drag');
                $('body').removeClass(resizingClass);
            };

            $(document)
                .on('mousemove.' + this.ns + 'Drag', move)
                .on('mouseup.' + this.ns + 'Drag', up);
        });
    }

    // --- Beautify ------------------------------------------------------------

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
            this.editor.setValue(this.formatQuery(value));
        } catch (e) {
            messenger.notificationFlashMessage('warning', this.t('beautify_error'));
        }
    }

    // --- Query execution -----------------------------------------------------

    private onRun(): void {
        const tab = this.activeTab();
        if (!tab || tab.running) {
            return;
        }
        this.saveActiveTab();
        const query = String(tab.sql).trim();
        if (query === '') {
            messenger.notificationFlashMessage('warning', this.t('empty_query'));
            return;
        }

        tab.running = true;
        this.renderTabs();
        this.updateRunButton();
        this.setStatus(this.t('running'));
        // Clear the previous error only; a prior grid stays visible until the new result lands.
        this.$el.find('[data-role="result"]').children('.alert').remove();

        // The result is bound to the tab, so switching away lets the query keep running and
        // populate its own tab. Errors render in the pane; opt out of Oro's global ajax flash.
        tab.pending = $.ajax({
            url: this.queryUrl,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({query}),
            errorHandlerMessage: false
        }).done((response: QueryResponse) => {
            this.storeResult(tab.id, {
                columns: response.columns || [],
                columnTypes: response.columnTypes,
                rows: response.rows || [],
                rowCount: response.rowCount || 0,
                durationMs: response.durationMs || 0,
                truncated: !!response.truncated
            });
        }).fail((jqXhr: any) => {
            if (jqXhr.statusText === 'abort') {
                return;
            }
            const response: QueryResponse = (jqXhr.responseJSON as QueryResponse) || {};
            this.storeResult(tab.id, {error: response.message || this.t('query_error')});
        }).always(() => {
            tab.running = false;
            tab.pending = null;
            this.renderTabs();
            if (tab.id === this.activeTabId) {
                this.renderActiveResult();
            }
        });
    }

    /** Renders the active tab's stored result (grid, error or empty) and syncs status/run button. */
    private renderActiveResult(): void {
        const tab = this.activeTab();
        this.clearSelection();
        this.hiddenColumns = [];
        this.hiddenRows = [];
        this.lastResult = null;

        const $result = this.$el.find('[data-role="result"]').empty();
        const stored = tab ? this.loadResult(tab.id) : null;

        if (stored && stored.error) {
            this.setExportEnabled(false);
            this.setStatus('');
            $result.append($('<div/>', {'class': 'alert alert-error', role: 'alert'}).text(stored.error));
        } else if (stored) {
            this.lastResult = {
                columns: stored.columns || [],
                rows: stored.rows || [],
                columnTypes: stored.columnTypes
            };
            this.setExportEnabled(this.lastResult.columns.length > 0);
            this.setStatus(this.t('result_summary', {
                count: stored.rowCount || 0,
                duration: stored.durationMs || 0
            }) + (stored.truncated ? ' ' + this.t('truncated') : ''));
            this.renderTable();
        } else {
            this.setExportEnabled(false);
            this.setStatus('');
        }

        if (tab && tab.running) {
            this.setStatus(this.t('running'));
        }
        this.updateRunButton();
    }

    // --- Per-tab result storage (sessionStorage; in-memory fallback when over quota) ----------

    private resultKey(tabId: string): string {
        return this.storagePrefix() + tabId;
    }

    private storeResult(tabId: string, data: StoredResult): void {
        try {
            window.sessionStorage.setItem(this.resultKey(tabId), JSON.stringify(data));
            delete this.resultFallback[tabId];
        } catch (e) {
            // Quota exceeded or serialization failure: keep it in memory for this session instead.
            try {
                window.sessionStorage.removeItem(this.resultKey(tabId));
            } catch (ignore) {
                // sessionStorage unavailable; the in-memory fallback below still works.
            }
            this.resultFallback[tabId] = data;
        }
    }

    private loadResult(tabId: string): StoredResult | null {
        if (Object.prototype.hasOwnProperty.call(this.resultFallback, tabId)) {
            return this.resultFallback[tabId];
        }
        try {
            const raw = window.sessionStorage.getItem(this.resultKey(tabId));
            return raw ? JSON.parse(raw) as StoredResult : null;
        } catch (e) {
            return null;
        }
    }

    private removeResult(tabId: string): void {
        delete this.resultFallback[tabId];
        try {
            window.sessionStorage.removeItem(this.resultKey(tabId));
        } catch (e) {
            // ignore
        }
    }

    private clearAllResults(): void {
        this.resultFallback = {};
        try {
            const prefix = this.storagePrefix();
            const keys: string[] = [];
            for (let i = 0; i < window.sessionStorage.length; i++) {
                const key = window.sessionStorage.key(i);
                if (key && key.indexOf(prefix) === 0) {
                    keys.push(key);
                }
            }
            keys.forEach(key => window.sessionStorage.removeItem(key));
        } catch (e) {
            // ignore
        }
    }

    // --- Result grid ---------------------------------------------------------

    protected visibleColumns(): string[] {
        if (!this.lastResult) {
            return [];
        }
        return this.lastResult.columns.filter(column => this.hiddenColumns.indexOf(column) === -1);
    }

    /** @return array of [originalIndex, row] for non-hidden rows */
    protected visibleRows(): Array<[number, Record<string, unknown>]> {
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
            $result.append($('<p/>', {'class': this.cls('empty')}).text(this.t('no_rows')));
            return;
        }

        const $table = $('<table/>', {
            'class': 'grid table-hover table table-bordered table-condensed ' + this.cls('grid')
        });

        const $headRow = $('<tr/>').appendTo($('<thead/>').appendTo($table));
        if (this.showRowNumbers) {
            $headRow.append($('<th/>', {'class': this.cls('rownum-cell'), text: '#'}));
        }
        columns.forEach(column => $headRow.append(this.buildColumnHeaderCell(column)));

        const $body = $('<tbody/>').appendTo($table);
        let ordinal = 0;
        this.visibleRows().forEach(([originalIndex, row]) => {
            ordinal += 1;
            const $tr = $('<tr/>', {'data-row-index': originalIndex}).appendTo($body);
            if (this.showRowNumbers) {
                $tr.append($('<td/>', {
                    'class': this.cls('rownum-cell'),
                    'data-role': 'row-select',
                    text: ordinal
                }));
            }
            columns.forEach(column => {
                const $td = $('<td/>', {'data-role': 'data-cell', 'data-column': column});
                this.renderDataCell($td, row[column]);
                $tr.append($td);
            });
        });

        $result.append($table);
        this.applySelectionHighlight();
    }

    /**
     * Header cell for a column. Shows a type sub-label when the result carries column types
     * (Elastic); a plain column name otherwise (Database).
     */
    protected buildColumnHeaderCell(column: string): any {
        const $th = $('<th/>', {'data-role': 'col-header', 'data-column': column});
        const types = this.lastResult ? this.lastResult.columnTypes : undefined;
        if (types) {
            $th.append($('<span/>', {'class': this.cls('col-name'), text: column}));
            if (types[column]) {
                $th.append($('<span/>', {'class': this.cls('col-type'), text: types[column]}));
            }
        } else {
            $th.text(column);
        }
        return $th;
    }

    /** Renders a single data cell. Objects are JSON-stringified (Elastic nested values). */
    protected renderDataCell($td: any, value: unknown): void {
        if (value === null || value === undefined) {
            $td.addClass(this.cls('null-cell')).text('(null)');
        } else if (typeof value === 'object') {
            $td.text(JSON.stringify(value));
        } else {
            $td.text(String(value));
        }
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
        const $table = this.$el.find('.' + this.cls('grid'));
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

    protected escapeHtml(value: string): string {
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
        const $menu = $('<ul/>', {'class': this.cls('context-menu'), role: 'menu'});
        const items: Array<[string, string]> = [
            ['copy-json', this.t('context_copy_json')],
            ['copy-csv', this.t('context_copy_csv')],
            ['hide', this.t('context_hide')]
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
            $(document).on('mousedown.' + this.ns + 'Ctx', (e: any) => {
                if (!$(e.target).closest('.' + this.cls('context-menu')).length) {
                    this.hideContextMenu();
                }
            });
            $(document).on('keydown.' + this.ns + 'Ctx', (e: any) => {
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
        $(document).off('.' + this.ns + 'Ctx');
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
        const text = value === null || value === undefined
            ? ''
            : (typeof value === 'object' ? JSON.stringify(value) : String(value));
        return /[",\r\n]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
    }

    private copyToClipboard(text: string): void {
        const onSuccess = () => messenger.notificationFlashMessage('success', this.t('copied'));
        const onError = () => messenger.notificationFlashMessage('error', this.t('copy_error'));

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
            messenger.notificationFlashMessage('error', this.t('export_error'));
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

    protected setStatus(text: string): void {
        this.$el.find('[data-role="status"]').text(text);
    }

    /** Run button reflects the ACTIVE tab's run state (each tab runs independently). */
    private updateRunButton(): void {
        const tab = this.activeTab();
        const running = !!(tab && tab.running);
        this.$el.find('[data-role="run"]').prop('disabled', running);
        // .toggle() (inline display) rather than [hidden]: the fa display rules would win over it.
        this.$el.find('[data-role="run-spinner"]').toggle(running);
    }

    dispose(): void {
        if (this.disposed) {
            return;
        }
        const ns = '.' + this.ns;
        this.$el.off(ns);
        $(document).off(ns + 'Drag ' + ns + 'Ctx');
        $(window).off(ns);
        mediator.off('openLink:before', this.onBeforePageChange, this);
        $('body').removeClass(this.cssPrefix() + '-resizing');
        this.tabs.forEach(tab => {
            if (tab.pending && typeof tab.pending.abort === 'function') {
                tab.pending.abort();
            }
        });
        this.clearAllResults();
        this.disposeSidebar();
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

export default AbstractQueryViewerComponent;
