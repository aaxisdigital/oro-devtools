import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import messenger from 'oroui/js/messenger';
import BaseComponent from 'oroui/js/app/components/base/component';
import CodeMirror from 'codemirror';
import 'codemirror/addon/runmode/runmode';
import 'codemirror/mode/javascript/javascript';
import {flashRequestError, openHelpModal} from './viewer-helpers';

interface MongoViewerOptions {
    _sourceElement: any;
    overviewUrl: string;
    collectionsUrl: string;
    documentsUrl: string;
}

interface DatabaseInfo { db: string; collections: number; sizeOnDisk: number; connection: string; }
interface CollectionInfo { name: string; count: number; }
interface ConnectionGroup { connection: string; server: Record<string, string>; databases: DatabaseInfo[]; error?: string; }

const PAGE_LIMIT = 20;

/**
 * MongoDB Viewer page component: a database/collection sidebar, a JSON filter bar, a document list
 * (paged) and a document inspector. All operations are read-only.
 */
class MongoViewerComponent extends BaseComponent {
    private $el!: any;
    private urls!: {overview: string; collections: string; documents: string};
    private connections!: ConnectionGroup[];
    private collectionsByDb!: Record<string, CollectionInfo[]>;
    private expanded!: Record<string, boolean>;
    private activeConnection!: string | null;
    private activeDb!: string | null;
    private activeCollection!: string | null;
    private docs!: any[];
    private activeDocIndex!: number;
    private skip!: number;
    private total!: number;
    private loading!: boolean;

    initialize(options: MongoViewerOptions): void {
        this.$el = options._sourceElement;
        this.urls = {overview: options.overviewUrl, collections: options.collectionsUrl, documents: options.documentsUrl};
        this.connections = [];
        this.collectionsByDb = {};
        this.expanded = {};
        this.activeConnection = null;
        this.activeDb = null;
        this.activeCollection = null;
        this.docs = [];
        this.activeDocIndex = -1;
        this.skip = 0;
        this.total = 0;
        this.loading = false;

        this.bindEvents();
        this.loadOverview();
    }

    private bindEvents(): void {
        this.$el.on('click.aaxisMongo', '[data-role="help"]', this.onHelp.bind(this));
        this.$el.on('click.aaxisMongo', '[data-role="refresh"]', () => this.loadOverview());
        this.$el.on('click.aaxisMongo', '[data-role="db"]', (e: any) => {
            const $t = $(e.currentTarget);
            this.onDbClick(String($t.data('connection')), String($t.data('db')));
        });
        this.$el.on('click.aaxisMongo', '[data-role="collection"]', (e: any) => {
            const $t = $(e.currentTarget);
            this.selectCollection(String($t.data('connection')), String($t.data('db')), String($t.data('collection')));
        });
        this.$el.on('click.aaxisMongo', '[data-role="find"]', () => this.startFind());
        this.$el.on('keydown.aaxisMongo', '[data-role="filter"]', (e: any) => {
            if (e.keyCode === 13) {
                e.preventDefault();
                this.startFind();
            }
        });
        this.$el.on('click.aaxisMongo', '[data-role="load-more"]', () => this.loadDocs(false));
        this.$el.on('click.aaxisMongo', '[data-role="doc-row"]', (e: any) => this.showDoc(Number($(e.currentTarget).data('index'))));
    }

    private loadOverview(): void {
        $.ajax({url: this.urls.overview, method: 'GET'})
            .done((response: {success: boolean; message?: string; connections?: ConnectionGroup[]}) => {
                if (!response.success) {
                    messenger.notificationFlashMessage('error', response.message || __('aaxis.devtools.mongodb_viewer.overview_error'));
                    return;
                }
                this.connections = response.connections || [];
                this.collectionsByDb = {};
                this.renderDatabases();
            })
            .fail((jqXhr: any) => this.flashError(jqXhr, 'aaxis.devtools.mongodb_viewer.overview_error'));
    }

    /** Composite key so identically-named databases on different connections never collide. */
    private dbKey(connection: string, db: string): string {
        // U+0000 as the separator, written as an escape: a raw NUL byte in the source makes
        // the whole file read as binary to grep/diff and can be mangled by text tooling.
        return connection + '\u0000' + db;
    }

    private renderDatabases(): void {
        const $list = this.$el.find('[data-role="databases"]').empty();
        const total = this.connections.reduce((sum, group) => sum + (group.databases || []).length, 0);
        if (!total && !this.connections.some(group => group.error)) {
            $list.append($('<li/>', {'class': 'aaxis-mongo__empty', text: __('aaxis.devtools.mongodb_viewer.no_databases')}));
            return;
        }
        this.connections.forEach(group => $list.append(this.renderConnectionGroup(group)));
    }

    private renderConnectionGroup(group: ConnectionGroup): any {
        const $items: any[] = [];
        const label = __('aaxis.devtools.mongodb_viewer.connection_' + group.connection);
        const version = group.server?.Version ? group.server.Version : '';
        $items.push($('<li/>', {'class': 'aaxis-mongo__conn-head'}).append(
            $('<span/>', {'class': 'aaxis-mongo__conn-label', text: label}),
            version ? $('<span/>', {'class': 'aaxis-mongo__conn-version', text: 'v' + version}) : null
        ));

        if (group.error) {
            $items.push($('<li/>', {'class': 'aaxis-mongo__empty aaxis-mongo__conn-error', text: group.error}));
            return $items;
        }
        if (!group.databases.length) {
            $items.push($('<li/>', {'class': 'aaxis-mongo__empty', text: __('aaxis.devtools.mongodb_viewer.no_databases')}));
            return $items;
        }

        group.databases.forEach(info => {
            const $li = $('<li/>');
            const key = this.dbKey(group.connection, info.db);
            const expanded = !!this.expanded[key];
            const isActive = group.connection === this.activeConnection && info.db === this.activeDb;
            $li.append($('<div/>', {
                'class': 'aaxis-mongo__db' + (isActive ? ' is-active' : ''),
                'data-role': 'db', 'data-connection': group.connection, 'data-db': info.db
            }).append(
                $('<span/>').append(
                    $('<span/>', {'class': 'fa ' + (expanded ? 'fa-caret-down' : 'fa-caret-right') + ' aaxis-mongo__db-icon', 'aria-hidden': 'true'}),
                    $('<span/>', {text: info.db})
                ),
                $('<span/>', {'class': 'aaxis-mongo__db-count', text: info.collections})
            ));
            if (expanded) {
                $li.append(this.renderCollections(group.connection, info.db));
            }
            $items.push($li);
        });
        return $items;
    }

    private renderCollections(connection: string, db: string): any {
        const $ul = $('<ul/>', {'class': 'aaxis-mongo__collections'});
        const collections = this.collectionsByDb[this.dbKey(connection, db)];
        if (!collections) {
            return $ul;
        }
        if (!collections.length) {
            $ul.append($('<li/>', {'class': 'aaxis-mongo__empty', text: __('aaxis.devtools.mongodb_viewer.no_collections')}));
            return $ul;
        }
        collections.forEach(col => {
            const isActive = connection === this.activeConnection && db === this.activeDb && col.name === this.activeCollection;
            $ul.append($('<li/>', {
                'class': 'aaxis-mongo__collection' + (isActive ? ' is-active' : ''),
                'data-role': 'collection', 'data-connection': connection, 'data-db': db, 'data-collection': col.name
            }).append(
                $('<span/>', {'class': 'aaxis-mongo__collection-name', text: col.name, title: col.name}),
                $('<span/>', {'class': 'aaxis-mongo__db-count', text: col.count})
            ));
        });
        return $ul;
    }

    private onDbClick(connection: string, db: string): void {
        const key = this.dbKey(connection, db);
        this.expanded[key] = !this.expanded[key];
        if (this.expanded[key] && !this.collectionsByDb[key]) {
            $.ajax({url: this.urls.collections, method: 'GET', data: {connection, db}})
                .done((response: {success: boolean; message?: string; collections?: CollectionInfo[]}) => {
                    if (!response.success) {
                        messenger.notificationFlashMessage('error', response.message || __('aaxis.devtools.mongodb_viewer.collections_error'));
                        return;
                    }
                    this.collectionsByDb[key] = response.collections || [];
                    this.renderDatabases();
                })
                .fail((jqXhr: any) => this.flashError(jqXhr, 'aaxis.devtools.mongodb_viewer.collections_error'));
        } else {
            this.renderDatabases();
        }
    }

    private selectCollection(connection: string, db: string, collection: string): void {
        this.activeConnection = connection;
        this.activeDb = db;
        this.activeCollection = collection;
        this.renderDatabases();
        this.$el.find('[data-role="doc"]').html('')
            .append($('<div/>', {'class': 'aaxis-mongo__empty', text: __('aaxis.devtools.mongodb_viewer.select_doc')}));
        this.startFind();
    }

    private startFind(): void {
        this.skip = 0;
        this.docs = [];
        this.activeDocIndex = -1;
        this.loadDocs(true);
    }

    private loadDocs(reset: boolean): void {
        if (this.activeConnection === null || this.activeDb === null || this.activeCollection === null || this.loading) {
            return;
        }
        this.loading = true;
        const filter = String(this.$el.find('[data-role="filter"]').val() || '');
        $.ajax({
            url: this.urls.documents,
            method: 'GET',
            data: {connection: this.activeConnection, db: this.activeDb, collection: this.activeCollection, filter, skip: this.skip, limit: PAGE_LIMIT}
        })
            .done((response: {success: boolean; message?: string; total?: number; documents?: any[]; truncated?: boolean}) => {
                if (!response.success) {
                    messenger.notificationFlashMessage('error', response.message || __('aaxis.devtools.mongodb_viewer.documents_error'));
                    return;
                }
                this.total = response.total || 0;
                const incoming = response.documents || [];
                this.docs = reset ? incoming : this.docs.concat(incoming);
                this.skip = this.docs.length;
                this.renderDocs();
            })
            .fail((jqXhr: any) => this.flashError(jqXhr, 'aaxis.devtools.mongodb_viewer.documents_error'))
            .always(() => { this.loading = false; });
    }

    private renderDocs(): void {
        const $docs = this.$el.find('[data-role="docs"]').empty();
        $docs.append($('<div/>', {'class': 'aaxis-mongo__meta',
            text: __('aaxis.devtools.mongodb_viewer.total_label', {total: this.total})}));

        if (!this.docs.length) {
            $docs.append($('<div/>', {'class': 'aaxis-mongo__empty', text: __('aaxis.devtools.mongodb_viewer.no_documents')}));
            return;
        }
        this.docs.forEach((doc, index) => {
            $docs.append($('<div/>', {
                'class': 'aaxis-mongo__doc-row' + (index === this.activeDocIndex ? ' is-active' : ''),
                'data-role': 'doc-row', 'data-index': index
            }).append(
                $('<span/>', {'class': 'aaxis-mongo__doc-id', text: this.idOf(doc)}),
                $('<span/>', {'class': 'aaxis-mongo__doc-snippet', text: ' ' + this.snippet(doc)})
            ));
        });

        if (this.docs.length < this.total) {
            $docs.append($('<div/>', {'class': 'aaxis-mongo__load-more'}).append(
                $('<button/>', {type: 'button', 'class': 'btn btn-sm', 'data-role': 'load-more',
                    text: __('aaxis.devtools.mongodb_viewer.load_more')})
            ));
        }
    }

    private showDoc(index: number): void {
        this.activeDocIndex = index;
        this.renderDocs();
        const $doc = this.$el.find('[data-role="doc"]').empty();
        const pre = document.createElement('pre');
        pre.className = 'cm-s-default aaxis-mongo__pre';
        const text = JSON.stringify(this.docs[index], null, 2);
        try {
            (CodeMirror as any).runMode(text, {name: 'javascript', json: true}, pre);
        } catch (e) {
            pre.textContent = text;
        }
        $doc.append(pre);
    }

    private idOf(doc: any): string {
        const id = doc?._id;
        if (id === undefined || id === null) {
            return '(no _id)';
        }
        if (typeof id === 'object') {
            return id.$oid || JSON.stringify(id);
        }
        return String(id);
    }

    private snippet(doc: any): string {
        const copy: any = {};
        Object.keys(doc || {}).forEach(k => {
            if (k !== '_id') {
                copy[k] = doc[k];
            }
        });
        const json = JSON.stringify(copy);
        return json.length > 90 ? json.slice(0, 90) + '…' : json;
    }

    private flashError(jqXhr: any, fallbackKey: string): void {
        flashRequestError(jqXhr, fallbackKey);
    }

    private onHelp(event: any): void {
        event.preventDefault();
        openHelpModal(this.$el, 'aaxis.devtools.mongodb_viewer.help');
    }

    dispose(): void {
        if (this.disposed) {
            return;
        }
        this.$el.off('.aaxisMongo');
        super.dispose();
    }
}

export default MongoViewerComponent;
