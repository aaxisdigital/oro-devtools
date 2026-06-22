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
const FILES_TAB_ID = 'files';
const IMAGE_EXTS = ['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'bmp', 'ico', 'avif'];
const TEXT_MODES = {
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
 * Shared read-only "browser" page component used by both the Filesystem Browser and the Bucket
 * Browser: path/prefix bar + filter, a sortable/selectable listing with keyboard navigation, and a
 * tabbed preview that syntax-highlights known text files, renders images/PDFs inline, and downloads
 * binaries. The data source (local filesystem vs S3 bucket) is entirely behind the JSON endpoints.
 */
class StorageBrowserComponent extends BaseComponent {
    initialize(options) {
        this.$el = options._sourceElement;
        this.listUrl = options.listUrl;
        this.previewUrl = options.previewUrl;
        this.rawUrl = options.rawUrl;
        this.downloadUrl = options.downloadUrl;
        this.basePath = options.basePath;
        this.restricted = options.restricted === true;
        this.columns = options.columns;
        this.helpTitle = options.helpTitle;
        this.configUrl = options.configUrl || '';
        this.initialLoadDone = false;
        this.createFolderUrl = options.createFolderUrl || '';
        this.uploadUrl = options.uploadUrl || '';
        this.deleteUrl = options.deleteUrl || '';
        this.writable = options.readOnly !== true && this.deleteUrl !== '';
        this.lastListing = null;
        this.rows = [];
        this.selectedIndex = 0;
        this.filterText = '';
        this.sortKey = 'type';
        this.sortDir = 'asc';
        this.tabs = [{ id: FILES_TAB_ID, kind: 'files', title: __('aaxis.devtools.storage_browser.files_tab') }];
        this.activeTabId = FILES_TAB_ID;
        this.previewSeq = 0;
        this.bindEvents();
        this.renderTabs();
        this.navigate(this.basePath);
    }
    bindEvents() {
        this.$el.on('click.aaxisFs', '[data-role="help"]', this.onHelp.bind(this));
        this.$el.on('keydown.aaxisFs', '[data-role="path"]', (e) => {
            if (e.keyCode === 13) {
                e.preventDefault();
                this.onPathEnter(String(e.currentTarget.value || ''));
            }
        });
        this.$el.on('click.aaxisFs', '[data-role="filter-toggle"]', this.onToggleFilter.bind(this));
        this.$el.on('input.aaxisFs', '[data-role="filter"]', (e) => {
            this.filterText = String(e.currentTarget.value || '').toLowerCase();
            this.selectedIndex = 0;
            this.renderListing();
        });
        this.$el.on('click.aaxisFs', '[data-role="row"]', this.onRowClick.bind(this));
        this.$el.on('dblclick.aaxisFs', '[data-role="row"]', this.onRowDblClick.bind(this));
        this.$el.on('click.aaxisFs', '[data-role="sort"]', this.onSortClick.bind(this));
        this.$el.on('click.aaxisFs', '[data-role="download-btn"]', this.onDownloadClick.bind(this));
        this.$el.on('click.aaxisFs', '[data-role="preview-btn"]', this.onPreviewBtnClick.bind(this));
        this.$el.on('click.aaxisFs', '[data-role="delete-btn"]', this.onDeleteClick.bind(this));
        this.$el.on('click.aaxisFs', '[data-role="create-folder"]', this.onCreateFolder.bind(this));
        this.$el.on('click.aaxisFs', '[data-role="upload"]', this.onUploadClick.bind(this));
        this.$el.on('change.aaxisFs', '[data-role="upload-input"]', this.onUploadChange.bind(this));
        this.$el.on('keydown.aaxisFs', '[data-role="content"]', this.onContentKeydown.bind(this));
        this.$el.on('click.aaxisFs', '[data-role="tab"]', this.onTabClick.bind(this));
        this.$el.on('click.aaxisFs', '[data-role="tab-close"]', this.onTabClose.bind(this));
    }
    hasDotDot(path) {
        return path.split(/[/\\]+/).indexOf('..') !== -1;
    }
    ext(name) {
        const dot = name.lastIndexOf('.');
        return dot === -1 ? '' : name.slice(dot + 1).toLowerCase();
    }
    // --- Filter --------------------------------------------------------------
    onToggleFilter(event) {
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
    onPathEnter(value) {
        const path = value.trim();
        if (this.hasDotDot(path)) {
            messenger.notificationFlashMessage('warning', __('aaxis.devtools.storage_browser.dotdot_error'));
            return;
        }
        this.navigate(path);
    }
    navigate(path) {
        this.renderLoading();
        $.ajax({ url: this.listUrl, method: 'GET', data: { path } })
            .done((response) => {
            if (!response.success) {
                if (this.handleLoadFailure(response.message)) {
                    return;
                }
                messenger.notificationFlashMessage('error', response.message || __('aaxis.devtools.storage_browser.list_error'));
                this.renderActiveContent();
                return;
            }
            this.lastListing = { path: response.path, parent: response.parent, entries: response.entries || [] };
            this.initialLoadDone = true;
            this.selectedIndex = 0;
            this.$el.find('[data-role="path"]').val(response.path);
            this.activeTabId = FILES_TAB_ID;
            this.renderTabs();
            this.renderActiveContent();
        })
            .fail((jqXhr) => {
            const message = (jqXhr.responseJSON && jqXhr.responseJSON.message)
                || __('aaxis.devtools.storage_browser.list_error');
            if (this.handleLoadFailure(message)) {
                return;
            }
            messenger.notificationFlashMessage('error', message);
            this.renderActiveContent();
        });
    }
    /**
     * When the very first listing fails and a config URL is provided (Bucket Browser), the tool is
     * not usable. Show a blocking dialog and only navigate to the configuration page on confirm.
     */
    handleLoadFailure(message) {
        if (this.initialLoadDone || this.configUrl === '') {
            return false;
        }
        const detail = message ? message + ' ' : '';
        const modal = new Modal({
            title: __('aaxis.devtools.storage_browser.connect_failed_title'),
            content: detail + __('aaxis.devtools.storage_browser.connect_failed_body'),
            okText: __('aaxis.devtools.storage_browser.go_to_config'),
            allowCancel: false
        });
        modal.on('ok', () => {
            window.location.href = this.configUrl;
        });
        modal.open();
        return true;
    }
    renderLoading() {
        this.$el.find('[data-role="content"]').empty().append($('<div/>', { 'class': 'aaxis-fs__loading' })
            .append($('<span/>', { 'class': 'fa fa-spinner fa-spin', 'aria-hidden': 'true' })));
    }
    // --- Sorting -------------------------------------------------------------
    onSortClick(event) {
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
    buildRows() {
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
                cmp = a[key] - b[key];
            }
            else if (key === 'type') {
                cmp = (a.type === 'dir' ? 0 : 1) - (b.type === 'dir' ? 0 : 1);
                if (cmp === 0) {
                    cmp = a.name.localeCompare(b.name);
                }
            }
            else {
                cmp = String(a[key]).localeCompare(String(b[key]));
            }
            return cmp * dir;
        });
        // ".." is pinned at the top, but only when there is a parent to go to. At the root
        // (parent === null) it is omitted entirely.
        if (this.lastListing.parent === null) {
            return entries;
        }
        const parent = {
            name: '..', type: 'parent', path: this.lastListing.parent,
            size: 0, sizeFormatted: '', created: 0, modified: 0, ownerUser: '', ownerGroup: '', readable: true
        };
        return [parent, ...entries];
    }
    // --- Listing rendering ---------------------------------------------------
    renderListing() {
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
            $headRow.append(this.sortHeader('type', __('aaxis.devtools.storage_browser.col.type')));
        }
        $headRow.append(this.sortHeader('name', __('aaxis.devtools.storage_browser.col.name')));
        if (this.columns.filesize) {
            $headRow.append(this.sortHeader('size', __('aaxis.devtools.storage_browser.col.size')));
        }
        if (this.columns.modified) {
            $headRow.append(this.sortHeader('modified', __('aaxis.devtools.storage_browser.col.modified')));
        }
        if (this.columns.created) {
            $headRow.append(this.sortHeader('created', __('aaxis.devtools.storage_browser.col.created')));
        }
        if (this.columns.ownerUser) {
            $headRow.append(this.sortHeader('ownerUser', __('aaxis.devtools.storage_browser.col.owner')));
        }
        if (this.columns.ownerGroup) {
            $headRow.append(this.sortHeader('ownerGroup', __('aaxis.devtools.storage_browser.col.group')));
        }
        $headRow.append($('<th/>', { 'class': 'aaxis-fs__col-actions' }));
        const $body = $('<tbody/>').appendTo($table);
        this.rows.forEach((entry, index) => this.appendRow($body, entry, index));
        $content.append($table);
        this.applySelection();
    }
    sortHeader(key, label) {
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
    appendRow($body, entry, index) {
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
                ? __('aaxis.devtools.storage_browser.type.folder')
                : __('aaxis.devtools.storage_browser.type.file');
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
                    title: __('aaxis.devtools.storage_browser.preview'),
                    'aria-label': __('aaxis.devtools.storage_browser.preview')
                }).append($('<span/>', { 'class': 'fa fa-eye', 'aria-hidden': 'true' })));
            }
            $actions.append($('<button/>', {
                type: 'button',
                'class': 'aaxis-fs__icon-btn',
                'data-role': 'download-btn',
                'data-path': entry.path,
                title: __('aaxis.devtools.storage_browser.download'),
                'aria-label': __('aaxis.devtools.storage_browser.download')
            }).append($('<span/>', { 'class': 'fa fa-download', 'aria-hidden': 'true' })));
        }
        if (this.writable && entry.type !== 'parent') {
            $actions.append($('<button/>', {
                type: 'button',
                'class': 'aaxis-fs__icon-btn aaxis-fs__icon-btn--danger',
                'data-role': 'delete-btn',
                'data-path': entry.path,
                'data-type': entry.type,
                'data-name': entry.name,
                title: __('aaxis.devtools.storage_browser.delete'),
                'aria-label': __('aaxis.devtools.storage_browser.delete')
            }).append($('<span/>', { 'class': 'fa fa-trash-o', 'aria-hidden': 'true' })));
        }
        $tr.append($actions);
        $body.append($tr);
    }
    applySelection() {
        const $rows = this.$el.find('[data-role="row"]');
        $rows.removeClass('is-selected');
        const $selected = $rows.filter('[data-index="' + this.selectedIndex + '"]');
        $selected.addClass('is-selected');
        const el = $selected.get(0);
        if (el && el.scrollIntoView) {
            el.scrollIntoView({ block: 'nearest' });
        }
    }
    onRowClick(event) {
        if ($(event.target).closest('[data-role="preview-btn"], [data-role="download-btn"], [data-role="delete-btn"]').length) {
            return;
        }
        this.selectedIndex = Number($(event.currentTarget).data('index'));
        this.applySelection();
        this.$el.find('[data-role="content"]').trigger('focus');
    }
    onRowDblClick(event) {
        if ($(event.target).closest('[data-role="preview-btn"], [data-role="download-btn"], [data-role="delete-btn"]').length) {
            return;
        }
        this.openEntry(this.rows[Number($(event.currentTarget).data('index'))]);
    }
    onContentKeydown(event) {
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
    formatDate(timestamp) {
        return timestamp ? new Date(timestamp * 1000).toLocaleString() : '';
    }
    // --- Open / preview ------------------------------------------------------
    openEntry(entry) {
        if (!entry) {
            return;
        }
        if (entry.type === 'dir') {
            this.navigate(entry.path);
            return;
        }
        if (entry.type === 'parent') {
            // Only present when a parent exists; the path may legitimately be '' (storage root).
            this.navigate(entry.path);
            return;
        }
        this.openFile(entry.path, entry.name);
    }
    onPreviewBtnClick(event) {
        event.preventDefault();
        event.stopPropagation();
        this.openFile(String($(event.currentTarget).data('path')), String($(event.currentTarget).data('name')));
    }
    onDownloadClick(event) {
        event.preventDefault();
        event.stopPropagation();
        this.triggerDownload(String($(event.currentTarget).data('path')));
    }
    // --- Write operations (bucket, when not read-only) -----------------------
    csrf() {
        const name = window.location.protocol === 'https:' ? 'https-_csrf' : '_csrf';
        const match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
        return match ? decodeURIComponent(match[1]) : '';
    }
    currentPath() {
        return this.lastListing ? this.lastListing.path : this.basePath;
    }
    onCreateFolder(event) {
        event.preventDefault();
        if (!this.writable) {
            return;
        }
        const name = (window.prompt(__('aaxis.devtools.storage_browser.prompt_folder')) || '').trim();
        if (name === '') {
            return;
        }
        $.ajax({
            url: this.createFolderUrl,
            method: 'POST',
            contentType: 'application/json',
            headers: { 'X-CSRF-Header': this.csrf() },
            data: JSON.stringify({ path: this.currentPath(), name })
        })
            .done((response) => {
            if (!response.success) {
                messenger.notificationFlashMessage('error', response.message || __('aaxis.devtools.storage_browser.action_error'));
                return;
            }
            messenger.notificationFlashMessage('success', __('aaxis.devtools.storage_browser.folder_created'));
            this.navigate(this.currentPath());
        })
            .fail((jqXhr) => this.flashAjaxError(jqXhr));
    }
    onUploadClick(event) {
        event.preventDefault();
        if (!this.writable) {
            return;
        }
        this.$el.find('[data-role="upload-input"]').trigger('click');
    }
    onUploadChange(event) {
        const input = event.currentTarget;
        const file = input.files && input.files[0];
        if (!file) {
            return;
        }
        const formData = new FormData();
        formData.append('path', this.currentPath());
        formData.append('file', file);
        $.ajax({
            url: this.uploadUrl,
            method: 'POST',
            headers: { 'X-CSRF-Header': this.csrf() },
            data: formData,
            processData: false,
            contentType: false
        })
            .done((response) => {
            if (!response.success) {
                messenger.notificationFlashMessage('error', response.message || __('aaxis.devtools.storage_browser.action_error'));
                return;
            }
            messenger.notificationFlashMessage('success', __('aaxis.devtools.storage_browser.uploaded'));
            this.navigate(this.currentPath());
        })
            .fail((jqXhr) => this.flashAjaxError(jqXhr))
            .always(() => { input.value = ''; });
    }
    onDeleteClick(event) {
        event.preventDefault();
        event.stopPropagation();
        if (!this.writable) {
            return;
        }
        const $btn = $(event.currentTarget);
        const path = String($btn.data('path'));
        const type = String($btn.data('type'));
        const name = String($btn.data('name'));
        const confirmKey = type === 'dir'
            ? 'aaxis.devtools.storage_browser.confirm_delete'
            : 'aaxis.devtools.storage_browser.confirm_delete_file';
        if (!window.confirm(__(confirmKey, { name }))) {
            return;
        }
        $.ajax({
            url: this.deleteUrl,
            method: 'POST',
            contentType: 'application/json',
            headers: { 'X-CSRF-Header': this.csrf() },
            data: JSON.stringify({ path, type })
        })
            .done((response) => {
            if (!response.success) {
                messenger.notificationFlashMessage('error', response.message || __('aaxis.devtools.storage_browser.action_error'));
                return;
            }
            messenger.notificationFlashMessage('success', __('aaxis.devtools.storage_browser.deleted'));
            this.navigate(this.currentPath());
        })
            .fail((jqXhr) => this.flashAjaxError(jqXhr));
    }
    flashAjaxError(jqXhr) {
        const message = (jqXhr.responseJSON && jqXhr.responseJSON.message)
            || __('aaxis.devtools.storage_browser.action_error');
        messenger.notificationFlashMessage('error', message);
    }
    openFile(path, name) {
        // If this exact path is already previewed, just switch to its tab.
        const existing = this.tabs.find(t => t.kind !== 'files' && t.path === path);
        if (existing) {
            this.activeTabId = existing.id;
            this.renderTabs();
            this.renderActiveContent();
            return;
        }
        const ext = this.ext(name);
        if (IMAGE_EXTS.indexOf(ext) !== -1) {
            this.openMediaTab(name, path, 'image');
            return;
        }
        if (ext === 'pdf') {
            this.openMediaTab(name, path, 'pdf');
            return;
        }
        // Open a tab immediately in a loading state, then fill it once the preview arrives.
        this.previewSeq += 1;
        const id = 'tab-' + this.previewSeq;
        this.tabs.push({ id, kind: 'code', title: name, path, loading: true });
        this.activeTabId = id;
        this.renderTabs();
        this.renderActiveContent();
        $.ajax({ url: this.previewUrl, method: 'GET', data: { path } })
            .done((response) => {
            const tab = this.tabs.find(t => t.id === id);
            if (!tab) {
                return;
            }
            if (!response.success) {
                this.closeTabById(id);
                messenger.notificationFlashMessage('error', response.message || __('aaxis.devtools.storage_browser.preview_error'));
                return;
            }
            if (response.binary) {
                // Binary files are never previewed; download them instead.
                this.closeTabById(id);
                messenger.notificationFlashMessage('info', __('aaxis.devtools.storage_browser.binary_download'));
                this.triggerDownload(path);
                return;
            }
            let text = String(response.content || '');
            if (ext === 'json') {
                try {
                    text = JSON.stringify(JSON.parse(text), null, 2);
                }
                catch (e) {
                    // leave as-is if it isn't valid JSON
                }
            }
            tab.text = text;
            tab.mode = TEXT_MODES[ext];
            tab.truncated = !!response.truncated;
            tab.loading = false;
            if (this.activeTabId === id) {
                this.renderActiveContent();
            }
        })
            .fail((jqXhr) => {
            this.closeTabById(id);
            const message = (jqXhr.responseJSON && jqXhr.responseJSON.message)
                || __('aaxis.devtools.storage_browser.preview_error');
            messenger.notificationFlashMessage('error', message);
        });
    }
    closeTabById(id) {
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
    triggerDownload(path) {
        const link = document.createElement('a');
        link.href = this.downloadUrl + '?path=' + encodeURIComponent(path);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
    openMediaTab(name, path, kind) {
        this.previewSeq += 1;
        const id = 'tab-' + this.previewSeq;
        const url = this.rawUrl + '?path=' + encodeURIComponent(path);
        this.tabs.push({ id, kind, title: name, url, path });
        this.activeTabId = id;
        this.renderTabs();
        this.renderActiveContent();
    }
    // --- Tabs ----------------------------------------------------------------
    renderTabs() {
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
                    'aria-label': __('aaxis.devtools.storage_browser.close_tab'),
                    title: __('aaxis.devtools.storage_browser.close_tab'),
                    html: '&times;'
                }));
            }
            $tabs.append($tab);
        });
    }
    onTabClick(event) {
        if ($(event.target).closest('[data-role="tab-close"]').length) {
            return;
        }
        this.activeTabId = String($(event.currentTarget).data('id'));
        this.renderTabs();
        this.renderActiveContent();
    }
    onTabClose(event) {
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
    renderActiveContent() {
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
    renderCode(tab) {
        const $content = this.$el.find('[data-role="content"]').empty();
        if (tab.loading) {
            $content.append($('<div/>', { 'class': 'aaxis-fs__loading' })
                .append($('<span/>', { 'class': 'fa fa-spinner fa-spin', 'aria-hidden': 'true' })));
            return;
        }
        if (tab.truncated) {
            $content.append($('<div/>', {
                'class': 'aaxis-fs__preview-meta aaxis-fs__preview-warn',
                text: __('aaxis.devtools.storage_browser.truncated')
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
    renderMedia(tab) {
        const $content = this.$el.find('[data-role="content"]').empty();
        const $meta = $('<div/>', { 'class': 'aaxis-fs__preview-meta' }).append($('<span/>', { text: tab.path || '' }));
        $content.append($meta);
        const $spinner = $('<div/>', { 'class': 'aaxis-fs__loading' })
            .append($('<span/>', { 'class': 'fa fa-spinner fa-spin', 'aria-hidden': 'true' }));
        $content.append($spinner);
        if (tab.kind === 'image') {
            const $img = $('<img/>', { src: tab.url, alt: tab.title, 'class': 'aaxis-fs__image' });
            $img.on('load error', () => $spinner.remove());
            $content.append($('<div/>', { 'class': 'aaxis-fs__image-wrap' }).append($img));
        }
        else {
            const $iframe = $('<iframe/>', { src: tab.url, 'class': 'aaxis-fs__pdf', title: tab.title });
            $iframe.on('load', () => $spinner.remove());
            $content.append($iframe);
        }
    }
    onHelp(event) {
        event.preventDefault();
        const html = this.$el.find('[data-role="help-content"]').html();
        const modal = new Modal({
            title: this.helpTitle,
            content: html,
            allowOk: false,
            cancelText: __('Close')
        });
        modal.open();
    }
    dispose() {
        if (this.disposed) {
            return;
        }
        this.$el.off('.aaxisFs');
        super.dispose();
    }
}
export default StorageBrowserComponent;
