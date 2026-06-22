import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import messenger from 'oroui/js/messenger';
import Modal from 'oroui/js/modal';
import BaseComponent from 'oroui/js/app/components/base/component';
import CodeMirror from 'codemirror';
import 'codemirror/addon/runmode/runmode';
import 'codemirror/mode/javascript/javascript';

interface RedisViewerOptions {
    _sourceElement: any;
    overviewUrl: string;
    keysUrl: string;
    valueUrl: string;
}

interface DatabaseInfo { db: number; keys: number; }
interface KeyInfo { key: string; type: string; ttl: number; }

const TYPE_CLASS: Record<string, string> = {
    string: 'is-string', list: 'is-list', set: 'is-set', zset: 'is-zset', hash: 'is-hash'
};

/**
 * Redis Viewer page component: database sidebar + server info, SCAN-based key listing (paged via
 * cursor), and a per-type value inspector. All operations are read-only.
 */
class RedisViewerComponent extends BaseComponent {
    private $el!: any;
    private urls!: {overview: string; keys: string; value: string};
    private databases!: DatabaseInfo[];
    private activeDb!: number | null;
    private activeKey!: string | null;
    private cursor!: string;
    private done!: boolean;
    private keys!: KeyInfo[];
    private loading!: boolean;

    initialize(options: RedisViewerOptions): void {
        this.$el = options._sourceElement;
        this.urls = {overview: options.overviewUrl, keys: options.keysUrl, value: options.valueUrl};
        this.databases = [];
        this.activeDb = null;
        this.activeKey = null;
        this.cursor = '';
        this.done = false;
        this.keys = [];
        this.loading = false;

        this.bindEvents();
        this.loadOverview();
    }

    private bindEvents(): void {
        this.$el.on('click.aaxisRedis', '[data-role="help"]', this.onHelp.bind(this));
        this.$el.on('click.aaxisRedis', '[data-role="refresh"]', () => this.loadOverview());
        this.$el.on('click.aaxisRedis', '[data-role="db"]', (e: any) => this.selectDb(Number($(e.currentTarget).data('db'))));
        this.$el.on('click.aaxisRedis', '[data-role="scan"]', () => this.startScan());
        this.$el.on('keydown.aaxisRedis', '[data-role="pattern"]', (e: any) => {
            if (e.keyCode === 13) {
                e.preventDefault();
                this.startScan();
            }
        });
        this.$el.on('click.aaxisRedis', '[data-role="load-more"]', () => this.scan(false));
        this.$el.on('click.aaxisRedis', '[data-role="key"]', (e: any) => this.loadValue(String($(e.currentTarget).data('key'))));
    }

    private loadOverview(): void {
        $.ajax({url: this.urls.overview, method: 'GET'})
            .done((response: {success: boolean; message?: string; server?: Record<string, string>; databases?: DatabaseInfo[]}) => {
                if (!response.success) {
                    messenger.notificationFlashMessage('error', response.message || __('aaxis.devtools.redis_viewer.overview_error'));
                    return;
                }
                this.databases = response.databases || [];
                this.renderDatabases();
                this.renderServer(response.server || {});
                if (this.activeDb === null) {
                    const first = this.databases.find(d => d.keys > 0) || this.databases[0];
                    if (first) {
                        this.selectDb(first.db);
                    }
                } else {
                    this.startScan();
                }
            })
            .fail((jqXhr: any) => this.flashError(jqXhr, 'aaxis.devtools.redis_viewer.overview_error'));
    }

    private renderDatabases(): void {
        const $list = this.$el.find('[data-role="databases"]').empty();
        if (!this.databases.length) {
            $list.append($('<li/>', {'class': 'aaxis-redis__empty', text: __('aaxis.devtools.redis_viewer.no_databases')}));
            return;
        }
        this.databases.forEach(info => {
            $list.append($('<li/>', {
                'class': 'aaxis-redis__db' + (info.db === this.activeDb ? ' is-active' : ''),
                'data-role': 'db',
                'data-db': info.db
            }).append(
                $('<span/>', {text: __('aaxis.devtools.redis_viewer.db_label', {db: info.db})}),
                $('<span/>', {'class': 'aaxis-redis__db-count', text: info.keys})
            ));
        });
    }

    private renderServer(server: Record<string, string>): void {
        const $server = this.$el.find('[data-role="server"]').empty();
        const $dl = $('<dl/>');
        Object.keys(server).forEach(label => {
            $dl.append($('<dt/>', {text: label}), $('<dd/>', {text: server[label]}));
        });
        $server.append($dl);
    }

    private selectDb(db: number): void {
        this.activeDb = db;
        this.activeKey = null;
        this.renderDatabases();
        this.$el.find('[data-role="value"]').html('')
            .append($('<div/>', {'class': 'aaxis-redis__empty', text: __('aaxis.devtools.redis_viewer.select_key')}));
        this.startScan();
    }

    private startScan(): void {
        this.cursor = '';
        this.done = false;
        this.keys = [];
        this.scan(true);
    }

    private scan(reset: boolean): void {
        if (this.activeDb === null || this.loading) {
            return;
        }
        this.loading = true;
        const pattern = String(this.$el.find('[data-role="pattern"]').val() || '*');
        $.ajax({
            url: this.urls.keys,
            method: 'GET',
            data: {db: this.activeDb, pattern, cursor: this.cursor, count: 200}
        })
            .done((response: {success: boolean; message?: string; cursor?: string; done?: boolean; keys?: KeyInfo[]}) => {
                if (!response.success) {
                    messenger.notificationFlashMessage('error', response.message || __('aaxis.devtools.redis_viewer.keys_error'));
                    return;
                }
                this.cursor = response.cursor || '0';
                this.done = !!response.done;
                this.keys = reset ? (response.keys || []) : this.keys.concat(response.keys || []);
                this.renderKeys();
            })
            .fail((jqXhr: any) => this.flashError(jqXhr, 'aaxis.devtools.redis_viewer.keys_error'))
            .always(() => { this.loading = false; });
    }

    private renderKeys(): void {
        const $keys = this.$el.find('[data-role="keys"]').empty();
        if (!this.keys.length) {
            $keys.append($('<div/>', {'class': 'aaxis-redis__empty', text: __('aaxis.devtools.redis_viewer.no_keys')}));
            return;
        }
        const $table = $('<table/>', {'class': 'grid table table-hover table-condensed aaxis-redis__key-table'});
        const $body = $('<tbody/>').appendTo($table);
        this.keys.forEach(info => {
            const $row = $('<tr/>', {
                'class': 'aaxis-redis__key-row' + (info.key === this.activeKey ? ' is-active' : ''),
                'data-role': 'key',
                'data-key': info.key
            });
            $row.append($('<td/>').append($('<span/>', {
                'class': 'aaxis-redis__type ' + (TYPE_CLASS[info.type] || ''), text: info.type
            })));
            $row.append($('<td/>', {'class': 'aaxis-redis__key-name', text: info.key}));
            $row.append($('<td/>', {'class': 'aaxis-redis__ttl', text: this.formatTtl(info.ttl)}));
            $body.append($row);
        });
        $keys.append($table);

        if (!this.done) {
            $keys.append($('<div/>', {'class': 'aaxis-redis__load-more'}).append(
                $('<button/>', {type: 'button', 'class': 'btn btn-sm', 'data-role': 'load-more',
                    text: __('aaxis.devtools.redis_viewer.load_more')})
            ));
        }
    }

    private formatTtl(ttl: number): string {
        if (ttl === -1) {
            return __('aaxis.devtools.redis_viewer.ttl_none');
        }
        if (ttl < 0) {
            return '';
        }
        return __('aaxis.devtools.redis_viewer.ttl_label', {ttl});
    }

    private loadValue(key: string): void {
        if (this.activeDb === null) {
            return;
        }
        this.activeKey = key;
        this.renderKeys();
        $.ajax({url: this.urls.value, method: 'GET', data: {db: this.activeDb, key}})
            .done((response: any) => {
                if (!response.success) {
                    messenger.notificationFlashMessage('error', response.message || __('aaxis.devtools.redis_viewer.value_error'));
                    return;
                }
                this.renderValue(response);
            })
            .fail((jqXhr: any) => this.flashError(jqXhr, 'aaxis.devtools.redis_viewer.value_error'));
    }

    private renderValue(data: any): void {
        const $value = this.$el.find('[data-role="value"]').empty();

        const $head = $('<div/>', {'class': 'aaxis-redis__value-head'});
        $head.append($('<span/>', {'class': 'aaxis-redis__value-key', text: data.key}));
        $head.append($('<span/>', {'class': 'aaxis-redis__type ' + (TYPE_CLASS[data.type] || ''), text: data.type}));
        $head.append($('<span/>', {'class': 'aaxis-redis__value-meta', text: this.formatTtl(data.ttl)}));
        $head.append($('<span/>', {'class': 'aaxis-redis__value-meta',
            text: __('aaxis.devtools.redis_viewer.total_label', {total: data.total})}));
        $value.append($head);

        if (data.truncated) {
            $value.append($('<div/>', {'class': 'aaxis-redis__value-warn',
                text: __('aaxis.devtools.redis_viewer.truncated_note', {limit: data.items ? data.items.length : data.total})}));
        }

        if (data.type === 'string') {
            $value.append(this.renderScalar(String(data.value ?? '')));
        } else if (data.type === 'zset') {
            $value.append(this.renderPairs(
                (data.items || []).map((i: any) => ({a: String(i.member), b: String(i.score)})),
                __('aaxis.devtools.redis_viewer.member'), __('aaxis.devtools.redis_viewer.score')));
        } else if (data.type === 'hash') {
            $value.append(this.renderPairs(
                (data.items || []).map((i: any) => ({a: String(i.field), b: String(i.value)})),
                __('aaxis.devtools.redis_viewer.field'), __('aaxis.devtools.redis_viewer.value')));
        } else if (data.type === 'list' || data.type === 'set') {
            $value.append(this.renderList(data.items || []));
        } else {
            $value.append(this.renderScalar(String(data.value ?? '')));
        }
    }

    private renderScalar(value: string): any {
        const pre = document.createElement('pre');
        pre.className = 'cm-s-default aaxis-redis__pre';
        let text = value;
        let highlighted = false;
        const trimmed = value.trim();
        if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
            try {
                text = JSON.stringify(JSON.parse(value), null, 2);
                (CodeMirror as any).runMode(text, {name: 'javascript', json: true}, pre);
                highlighted = true;
            } catch (e) {
                text = value;
            }
        }
        if (!highlighted) {
            pre.textContent = text;
        }
        return pre;
    }

    private renderList(items: string[]): any {
        const $table = $('<table/>', {'class': 'grid table table-bordered table-condensed aaxis-redis__items'});
        const $body = $('<tbody/>').appendTo($table);
        items.forEach((item, index) => {
            $body.append($('<tr/>').append(
                $('<td/>', {'class': 'aaxis-redis__ttl', text: index}),
                $('<td/>', {'class': 'aaxis-redis__key-name', text: String(item)})
            ));
        });
        return $table;
    }

    private renderPairs(pairs: Array<{a: string; b: string}>, labelA: string, labelB: string): any {
        const $table = $('<table/>', {'class': 'grid table table-bordered table-condensed aaxis-redis__items'});
        const $head = $('<tr/>').appendTo($('<thead/>').appendTo($table));
        $head.append($('<th/>', {text: labelA}), $('<th/>', {text: labelB}));
        const $body = $('<tbody/>').appendTo($table);
        pairs.forEach(pair => {
            $body.append($('<tr/>').append(
                $('<td/>', {'class': 'aaxis-redis__key-name', text: pair.a}),
                $('<td/>', {'class': 'aaxis-redis__key-name', text: pair.b})
            ));
        });
        return $table;
    }

    private flashError(jqXhr: any, fallbackKey: string): void {
        const message = (jqXhr.responseJSON && jqXhr.responseJSON.message) || __(fallbackKey);
        messenger.notificationFlashMessage('error', message);
    }

    private onHelp(event: any): void {
        event.preventDefault();
        const html = this.$el.find('[data-role="help-content"]').html();
        const modal = new Modal({
            title: __('aaxis.devtools.redis_viewer.help'),
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
        this.$el.off('.aaxisRedis');
        super.dispose();
    }
}

export default RedisViewerComponent;
