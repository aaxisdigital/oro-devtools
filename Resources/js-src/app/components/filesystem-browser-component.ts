import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import messenger from 'oroui/js/messenger';
import Modal from 'oroui/js/modal';
import BaseComponent from 'oroui/js/app/components/base/component';
import CodeMirror from 'codemirror';
import 'codemirror/addon/runmode/runmode';
import 'codemirror/mode/sql/sql';
import 'codemirror/mode/javascript/javascript';
import 'codemirror/mode/xml/xml';
import 'codemirror/mode/yaml/yaml';
import 'codemirror/mode/htmlmixed/htmlmixed';
import 'codemirror/mode/php/php';
import 'codemirror/mode/twig/twig';

interface FilesystemBrowserOptions {
    _sourceElement: any;
    listUrl: string;
    previewUrl: string;
    rawUrl: string;
    downloadUrl: string;
    basePath: string;
    restricted: boolean;
    columns: Record<string, boolean>;
}

interface FsEntry {
    name: string;
    type: string;
    path: string;
    size: number;
    sizeFormatted: string;
    created: number;
    modified: number;
    ownerUser: string;
    ownerGroup: string;
    readable: boolean;
}

interface FsListing {
    path: string;
    parent: string;
    entries: FsEntry[];
}

interface FsTab {
    id: string;
    kind: string;
    title: string;
    text?: string;
    mode?: any;
    truncated?: boolean;
    url?: string;
    path?: string;
}

const FILES_TAB_ID = 'files';
const IMAGE_EXTS = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'bmp', 'ico', 'avif'];
const TEXT_MODES: Record<string, any> = {
    sql: 'text/x-sql',
    json: { name: 'javascript', json: true },
    js: 'text/javascript',
    mjs: 'text/javascript',
    cjs: 'text/javascript',
    jsx: 'text/javascript',
    ts: { name: 'javascript', typescript: true },
    tsx: { name: 'javascript', typescript: true },
    xml: 'xml',
    yml: 'yaml',
    yaml: 'yaml',
    php: 'application/x-httpd-php',
    html: 'htmlmixed',
    htm: 'htmlmixed',
    twig: 'twig'
};
/**
 * Filesystem Browser page component: path bar + filename filter, a sortable/selectable directory
 * listing with keyboard navigation, and a tabbed preview that syntax-highlights known text files,
 * renders images/PDFs inline, and downloads binaries.
 */
class FilesystemBrowserComponent extends BaseComponent {
    private $el!: any;
    private listUrl!: string;
    private previewUrl!: string;
    private rawUrl!: string;
    private downloadUrl!: string;
    private basePath!: string;
    private restricted!: boolean;
    private columns!: Record<string, boolean>;
    private lastListing!: FsListing | null;
    private rows!: FsEntry[];
    private selectedIndex!: number;
    private filterText!: string;
    private sortKey!: string;
    private sortDir!: string;
    private tabs!: FsTab[];
    private activeTabId!: string;
    private previewSeq!: number;

    initialize(options: FilesystemBrowserOptions): void {
        this.$el = options._sourceElement;
        this.listUrl = options.listUrl;
        this.previewUrl = options.previewUrl;
        this.rawUrl = options.rawUrl;
        this.downloadUrl = options.downloadUrl;
        this.basePath = options.basePath;
        this.restricted = options.restricted;
        this.columns = options.columns;
        this.lastListing = null;
        this.rows = [];
        this.selectedIndex = 0;
        this.filterText = '';
        this.sortKey = 'type';
        this.sortDir = 'asc';
        this.tabs = [{ id: FILES_TAB_ID, kind: 'files', title: __('aaxis.devtools.filesystem_browser.files_tab') }];
        this.activeTabId = FILES_TAB_ID;
        this.previewSeq = 0;
        this.bindEvents();
        this.renderTabs();
        this.navigate(this.basePath);
    }
    bindEvents(): void {
        this.$el.on('click.aaxisFs', '[data-role="help"]', this.onHelp.bind(this));
        this.$el.on('keydown.aaxisFs', '[data-role="path"]', (e: any) => {
            if (e.keyCode === 13) {
                e.preventDefault();
                this.onPathEnter(String(e.currentTarget.value || ''));
            }
        });
        this.$el.on('click.aaxisFs', '[data-role="filter-toggle"]', this.onToggleFilter.bind(this));
        this.$el.on('input.aaxisFs', '[data-role="filter"]', (e: any) => {
            this.filterText = String(e.currentTarget.value || '').toLowerCase();
            this.selectedIndex = 0;
            this.renderListing();
        });
        this.$el.on('click.aaxisFs', '[data-role="row"]', this.onRowClick.bind(this));
        this.$el.on('dblclick.aaxisFs', '[data-role="row"]', this.onRowDblClick.bind(this));
        this.$el.on('click.aaxisFs', '[data-role="sort"]', this.onSortClick.bind(this));
        this.$el.on('click.aaxisFs', '[data-role="download-btn"]', this.onDownloadClick.bind(this));
        this.$el.on('click.aaxisFs', '[data-role="preview-btn"]', this.onPreviewBtnClick.bind(this));
        this.$el.on('keydown.aaxisFs', '[data-role="content"]', this.onContentKeydown.bind(this));
        this.$el.on('click.aaxisFs', '[data-role="tab"]', this.onTabClick.bind(this));
        this.$el.on('click.aaxisFs', '[data-role="tab-close"]', this.onTabClose.bind(this));
    }
    hasDotDot(path: string): boolean {
        return path.split(/[/\\]+/).indexOf('..') !== -1;
    }
    ext(name: string): string {
        const dot = name.lastIndexOf('.');
        return dot === -1 ? '' : name.slice(dot + 1).toLowerCase();
    }
    // --- Filter --------------------------------------------------------------
    onToggleFilter(event: any): void {
        event.preventDefault();
        const $filter = this.$el.find('[data-role="filter"]');
        const willShow = $filter.prop('hidden');
        $filter.prop('hidden', !willShow);
        if (willShow) {
            $filter.trigger('focus');
        }
        else {
            $filter.val('');
            if (this.filterText !== '') {
                this.filterText = '';
                this.selectedIndex = 0;
                this.renderListing();
            }
        }
    }
    // --- Navigation ----------------------------------------------------------
    onPathEnter(value: string): void {
        const path = value.trim();
        if (this.hasDotDot(path)) {
            messenger.notificationFlashMessage('warning', __('aaxis.devtools.filesystem_browser.dotdot_error'));
            return;
        }
        this.navigate(path);
    }
    navigate(path: string): void {
        $.ajax({ url: this.listUrl, method: 'GET', data: { path } })
            .done((response: any) => {
            if (!response.success) {
                messenger.notificationFlashMessage('error', response.message || __('aaxis.devtools.filesystem_browser.list_error'));
                return;
            }
            this.lastListing = { path: response.path, parent: response.parent, entries: response.entries || [] };
            this.selectedIndex = 0;
            this.$el.find('[data-role="path"]').val(response.path);
            this.activeTabId = FILES_TAB_ID;
            this.renderTabs();
            this.renderActiveContent();
        })
            .fail((jqXhr: any) => {
            const message = (jqXhr.responseJSON && jqXhr.responseJSON.message)
                || __('aaxis.devtools.filesystem_browser.list_error');
            messenger.notificationFlashMessage('error', message);
        });
    }
    // --- Sorting -------------------------------------------------------------
    onSortClick(event: any): void {
        event.preventDefault();
        const key = String($(event.currentTarget).data('sort'));
        if (this.sortKey === key) {
            this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
        }
        else {
            this.sortKey = key;
            this.sortDir = 'asc';
        }
        this.renderListing();
    }
    buildRows(): FsEntry[] {
        if (!this.lastListing) {
            return [];
        }
        let entries = this.lastListing.entries.slice();
        if (this.filterText !== '') {
            entries = entries.filter(e => e.name.toLowerCase().indexOf(this.filterText) !== -1);
        }
        const dir = this.sortDir === 'desc' ? -1 : 1;
        const key = this.sortKey;
        entries.sort((a, b) => {
            let cmp = 0;
            if (key === 'name') {
                cmp = a.name.localeCompare(b.name);
            }
            else if (key === 'size') {
                cmp = a.size - b.size;
            }
            else if (key === 'modified' || key === 'created') {
                cmp = (a as any)[key] - (b as any)[key];
            }
            else if (key === 'type') {
                cmp = (a.type === 'dir' ? 0 : 1) - (b.type === 'dir' ? 0 : 1);
                if (cmp === 0) {
                    cmp = a.name.localeCompare(b.name);
                }
            }
            else {
                cmp = String((a as any)[key]).localeCompare(String((b as any)[key]));
            }
            return cmp * dir;
        });
        // ".." is always pinned at the top, regardless of sort/filter.
        const parent = {
            name: '..', type: 'parent', path: this.lastListing.parent || '',
            size: 0, sizeFormatted: '', created: 0, modified: 0, ownerUser: '', ownerGroup: '', readable: true
        };
        return [parent, ...entries];
    }
    // --- Listing rendering ---------------------------------------------------
    renderListing(): void {
        const $content = this.$el.find('[data-role="content"]').empty();
        if (!this.lastListing) {
            return;
        }
        this.rows = this.buildRows();
        if (this.selectedIndex >= this.rows.length) {
            this.selectedIndex = this.rows.length - 1;
        }
        const $table = $('<table/>', {
            'class': 'grid table-hover table table-bordered table-condensed aaxis-fs__grid'
        });
        const $headRow = $('<tr/>').appendTo($('<thead/>').appendTo($table));
        if (this.columns.type) {
            $headRow.append(this.sortHeader('type', __('aaxis.devtools.filesystem_browser.col.type')));
        }
        $headRow.append(this.sortHeader('name', __('aaxis.devtools.filesystem_browser.col.name')));
        if (this.columns.filesize) {
            $headRow.append(this.sortHeader('size', __('aaxis.devtools.filesystem_browser.col.size')));
        }
        if (this.columns.modified) {
            $headRow.append(this.sortHeader('modified', __('aaxis.devtools.filesystem_browser.col.modified')));
        }
        if (this.columns.created) {
            $headRow.append(this.sortHeader('created', __('aaxis.devtools.filesystem_browser.col.created')));
        }
        if (this.columns.ownerUser) {
            $headRow.append(this.sortHeader('ownerUser', __('aaxis.devtools.filesystem_browser.col.owner')));
        }
        if (this.columns.ownerGroup) {
            $headRow.append(this.sortHeader('ownerGroup', __('aaxis.devtools.filesystem_browser.col.group')));
        }
        $headRow.append($('<th/>', { 'class': 'aaxis-fs__col-actions' }));
        const $body = $('<tbody/>').appendTo($table);
        this.rows.forEach((entry, index) => this.appendRow($body, entry, index));
        $content.append($table);
        this.applySelection();
    }
    sortHeader(key: string, label: string): any {
        const $th = $('<th/>', { 'data-role': 'sort', 'data-sort': key, 'class': 'aaxis-fs__sortable' });
        const $inner = $('<span/>', { 'class': 'aaxis-fs__sort-inner' });
        $inner.append($('<span/>', { text: label }));
        if (this.sortKey === key) {
            $inner.append($('<span/>', {
                'class': 'fa ' + (this.sortDir === 'desc' ? 'fa-caret-down' : 'fa-caret-up') + ' aaxis-fs__sort-caret',
                'aria-hidden': 'true'
            }));
        }
        $th.append($inner);
        return $th;
    }
    appendRow($body: any, entry: FsEntry, index: number): void {
        const $tr = $('<tr/>', {
            'class': 'aaxis-fs__row',
            'data-role': 'row',
            'data-index': index,
            'data-type': entry.type,
            'data-path': entry.path,
            'data-name': entry.name
        });
        const iconClass = entry.type === 'dir' ? 'fa-folder'
            : (entry.type === 'parent' ? 'fa-level-up' : 'fa-file-o');
        if (this.columns.type) {
            const typeLabel = entry.type === 'dir' || entry.type === 'parent'
                ? __('aaxis.devtools.filesystem_browser.type.folder')
                : __('aaxis.devtools.filesystem_browser.type.file');
            $tr.append($('<td/>', { text: entry.type === 'parent' ? '' : typeLabel }));
        }
        const $name = $('<td/>', { 'class': 'aaxis-fs__name-cell' }).append($('<span/>', { 'class': 'fa ' + iconClass + ' aaxis-fs__entry-icon', 'aria-hidden': 'true' }));
        if (this.columns.filename || entry.type === 'parent') {
            $name.append($('<span/>', { 'class': 'aaxis-fs__entry-name', text: entry.name }));
        }
        $tr.append($name);
        if (this.columns.filesize) {
            $tr.append($('<td/>', { 'class': 'aaxis-fs__num', text: entry.type === 'file' ? entry.sizeFormatted : '' }));
        }
        if (this.columns.modified) {
            $tr.append($('<td/>', { text: this.formatDate(entry.modified) }));
        }
        if (this.columns.created) {
            $tr.append($('<td/>', { text: this.formatDate(entry.created) }));
        }
        if (this.columns.ownerUser) {
            $tr.append($('<td/>', { text: entry.ownerUser }));
        }
        if (this.columns.ownerGroup) {
            $tr.append($('<td/>', { text: entry.ownerGroup }));
        }
        const $actions = $('<td/>', { 'class': 'aaxis-fs__col-actions' });
        if (entry.type === 'file') {
            if (this.columns.preview) {
                $actions.append($('<button/>', {
                    type: 'button',
                    'class': 'aaxis-fs__icon-btn',
                    'data-role': 'preview-btn',
                    'data-path': entry.path,
                    'data-name': entry.name,
                    title: __('aaxis.devtools.filesystem_browser.preview'),
                    'aria-label': __('aaxis.devtools.filesystem_browser.preview')
                }).append($('<span/>', { 'class': 'fa fa-eye', 'aria-hidden': 'true' })));
            }
            $actions.append($('<button/>', {
                type: 'button',
                'class': 'aaxis-fs__icon-btn',
                'data-role': 'download-btn',
                'data-path': entry.path,
                title: __('aaxis.devtools.filesystem_browser.download'),
                'aria-label': __('aaxis.devtools.filesystem_browser.download')
            }).append($('<span/>', { 'class': 'fa fa-download', 'aria-hidden': 'true' })));
        }
        $tr.append($actions);
        $body.append($tr);
    }
    applySelection(): void {
        const $rows = this.$el.find('[data-role="row"]');
        $rows.removeClass('is-selected');
        const $selected = $rows.filter('[data-index="' + this.selectedIndex + '"]');
        $selected.addClass('is-selected');
        const el = $selected.get(0);
        if (el && el.scrollIntoView) {
            el.scrollIntoView({ block: 'nearest' });
        }
    }
    onRowClick(event: any): void {
        if ($(event.target).closest('[data-role="preview-btn"], [data-role="download-btn"]').length) {
            return;
        }
        this.selectedIndex = Number($(event.currentTarget).data('index'));
        this.applySelection();
        this.$el.find('[data-role="content"]').trigger('focus');
    }
    onRowDblClick(event: any): void {
        if ($(event.target).closest('[data-role="preview-btn"], [data-role="download-btn"]').length) {
            return;
        }
        this.openEntry(this.rows[Number($(event.currentTarget).data('index'))]);
    }
    onContentKeydown(event: any): void {
        if (this.activeTabId !== FILES_TAB_ID || this.rows.length === 0) {
            return;
        }
        if (event.keyCode === 40) { // down
            event.preventDefault();
            this.selectedIndex = Math.min(this.rows.length - 1, this.selectedIndex + 1);
            this.applySelection();
        }
        else if (event.keyCode === 38) { // up
            event.preventDefault();
            this.selectedIndex = Math.max(0, this.selectedIndex - 1);
            this.applySelection();
        }
        else if (event.keyCode === 13) { // enter
            event.preventDefault();
            this.openEntry(this.rows[this.selectedIndex]);
        }
    }
    formatDate(timestamp: number): string {
        return timestamp ? new Date(timestamp * 1000).toLocaleString() : '';
    }
    // --- Open / preview ------------------------------------------------------
    openEntry(entry: FsEntry): void {
        if (!entry) {
            return;
        }
        if (entry.type === 'dir') {
            this.navigate(entry.path);
            return;
        }
        if (entry.type === 'parent') {
            if (entry.path === '') {
                messenger.notificationFlashMessage('warning', __('aaxis.devtools.filesystem_browser.up_blocked'));
                return;
            }
            this.navigate(entry.path);
            return;
        }
        this.openFile(entry.path, entry.name);
    }
    onPreviewBtnClick(event: any): void {
        event.preventDefault();
        event.stopPropagation();
        this.openFile(String($(event.currentTarget).data('path')), String($(event.currentTarget).data('name')));
    }
    onDownloadClick(event: any): void {
        event.preventDefault();
        event.stopPropagation();
        this.triggerDownload(String($(event.currentTarget).data('path')));
    }
    openFile(path: string, name: string): void {
        const ext = this.ext(name);
        if (IMAGE_EXTS.indexOf(ext) !== -1) {
            this.openMediaTab(name, path, 'image');
            return;
        }
        if (ext === 'pdf') {
            this.openMediaTab(name, path, 'pdf');
            return;
        }
        $.ajax({ url: this.previewUrl, method: 'GET', data: { path } })
            .done((response: any) => {
            if (!response.success) {
                messenger.notificationFlashMessage('error', response.message || __('aaxis.devtools.filesystem_browser.preview_error'));
                return;
            }
            if (response.binary) {
                // Binary files are never previewed; download them instead.
                messenger.notificationFlashMessage('info', __('aaxis.devtools.filesystem_browser.binary_download'));
                this.triggerDownload(path);
                return;
            }
            this.openCodeTab(name, String(response.content || ''), ext, !!response.truncated);
        })
            .fail((jqXhr: any) => {
            const message = (jqXhr.responseJSON && jqXhr.responseJSON.message)
                || __('aaxis.devtools.filesystem_browser.preview_error');
            messenger.notificationFlashMessage('error', message);
        });
    }
    triggerDownload(path: string): void {
        const link = document.createElement('a');
        link.href = this.downloadUrl + '?path=' + encodeURIComponent(path);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    openCodeTab(name: string, content: string, ext: string, truncated: boolean): void {
        let text = content;
        let mode = TEXT_MODES[ext];
        if (ext === 'json') {
            try {
                text = JSON.stringify(JSON.parse(content), null, 2);
            }
            catch (e) {
                // leave as-is if it isn't valid JSON
            }
        }
        this.previewSeq += 1;
        const id = 'tab-' + this.previewSeq;
        this.tabs.push({ id, kind: 'code', title: name, text, mode, truncated });
        this.activeTabId = id;
        this.renderTabs();
        this.renderActiveContent();
    }
    openMediaTab(name: string, path: string, kind: string): void {
        this.previewSeq += 1;
        const id = 'tab-' + this.previewSeq;
        const url = this.rawUrl + '?path=' + encodeURIComponent(path);
        this.tabs.push({ id, kind, title: name, url, path });
        this.activeTabId = id;
        this.renderTabs();
        this.renderActiveContent();
    }
    // --- Tabs ----------------------------------------------------------------
    renderTabs(): void {
        const $tabs = this.$el.find('[data-role="tabs"]').empty();
        this.tabs.forEach(tab => {
            const $tab = $('<span/>', {
                'class': 'aaxis-fs__tab' + (tab.id === this.activeTabId ? ' is-active' : ''),
                'data-role': 'tab',
                'data-id': tab.id
            });
            const icon = tab.kind === 'files' ? 'fa-list'
                : (tab.kind === 'image' ? 'fa-picture-o' : (tab.kind === 'pdf' ? 'fa-file-pdf-o' : 'fa-file-text-o'));
            $tab.append($('<span/>', { 'class': 'fa ' + icon + ' aaxis-fs__tab-icon', 'aria-hidden': 'true' }));
            $tab.append($('<span/>', { 'class': 'aaxis-fs__tab-title', text: tab.title }));
            if (tab.kind !== 'files') {
                $tab.append($('<button/>', {
                    type: 'button',
                    'class': 'aaxis-fs__tab-close',
                    'data-role': 'tab-close',
                    'data-id': tab.id,
                    'aria-label': __('aaxis.devtools.filesystem_browser.close_tab'),
                    title: __('aaxis.devtools.filesystem_browser.close_tab'),
                    html: '&times;'
                }));
            }
            $tabs.append($tab);
        });
    }
    onTabClick(event: any): void {
        if ($(event.target).closest('[data-role="tab-close"]').length) {
            return;
        }
        this.activeTabId = String($(event.currentTarget).data('id'));
        this.renderTabs();
        this.renderActiveContent();
    }
    onTabClose(event: any): void {
        event.preventDefault();
        event.stopPropagation();
        const id = String($(event.currentTarget).data('id'));
        const index = this.tabs.findIndex(t => t.id === id);
        if (index === -1) {
            return;
        }
        this.tabs.splice(index, 1);
        if (this.activeTabId === id) {
            this.activeTabId = FILES_TAB_ID;
        }
        this.renderTabs();
        this.renderActiveContent();
    }
    renderActiveContent(): void {
        const tab = this.tabs.find(t => t.id === this.activeTabId);
        if (!tab) {
            return;
        }
        if (tab.kind === 'files') {
            this.renderListing();
        }
        else if (tab.kind === 'code') {
            this.renderCode(tab);
        }
        else {
            this.renderMedia(tab);
        }
    }
    renderCode(tab: FsTab): void {
        const $content = this.$el.find('[data-role="content"]').empty();
        if (tab.truncated) {
            $content.append($('<div/>', {
                'class': 'aaxis-fs__preview-meta aaxis-fs__preview-warn',
                text: __('aaxis.devtools.filesystem_browser.truncated')
            }));
        }
        const pre = document.createElement('pre');
        pre.className = 'cm-s-default aaxis-fs__code';
        if (tab.mode) {
            try {
                CodeMirror.runMode(String(tab.text || ''), tab.mode, pre);
            }
            catch (e) {
                pre.textContent = String(tab.text || '');
            }
        }
        else {
            pre.textContent = String(tab.text || '');
        }
        $content.append(pre);
    }
    renderMedia(tab: FsTab): void {
        const $content = this.$el.find('[data-role="content"]').empty();
        const $meta = $('<div/>', { 'class': 'aaxis-fs__preview-meta' }).append($('<span/>', { text: tab.path || '' }));
        $content.append($meta);
        if (tab.kind === 'image') {
            $content.append($('<div/>', { 'class': 'aaxis-fs__image-wrap' })
                .append($('<img/>', { src: tab.url, alt: tab.title, 'class': 'aaxis-fs__image' })));
        }
        else {
            $content.append($('<iframe/>', {
                src: tab.url,
                'class': 'aaxis-fs__pdf',
                title: tab.title
            }));
        }
    }
    onHelp(event: any): void {
        event.preventDefault();
        const html = this.$el.find('[data-role="help-content"]').html();
        const modal = new Modal({
            title: __('aaxis.devtools.filesystem_browser.help'),
            content: html,
            allowOk: false,
            cancelText: __('Close')
        });
        modal.open();
    }
    dispose(): void {
        if (this.disposed) {
            return;
        }
        this.$el.off('.aaxisFs');
        super.dispose();
    }
}
export default FilesystemBrowserComponent;
