import $ from 'jquery';
import messenger from 'oroui/js/messenger';
import 'codemirror/mode/sql/sql';
import AbstractQueryViewerComponent, {QueryViewerOptions} from './abstract-query-viewer-component';

interface ElasticViewerOptions extends QueryViewerOptions {
    indicesUrl: string;
}

interface EsIndex {
    name: string;
    docsCount: number;
    size: string;
    health: string;
}

// ES|QL source/processing commands that each start their own pipe segment (longest first).
const ESQL_COMMANDS = [
    'FROM', 'ROW', 'SHOW', 'WHERE', 'EVAL', 'STATS', 'SORT', 'LIMIT', 'KEEP', 'DROP',
    'RENAME', 'DISSECT', 'GROK', 'ENRICH', 'MV_EXPAND', 'LOOKUP'
];

// Secondary keywords uppercased in place while beautifying (no line break of their own).
const ESQL_SECONDARY = ['BY', 'ASC', 'DESC', 'AND', 'OR', 'NOT', 'NULL', 'IS', 'IN', 'LIKE', 'RLIKE', 'AS', 'WITH'];

/**
 * Elastic Viewer page component. Runs read-only ES|QL and lists Elasticsearch indices in the
 * sidebar. All the editor/tabs/saved-queries/result-grid behaviour is shared with the Database
 * Viewer via {@see AbstractQueryViewerComponent}; this class only adds the index sidebar, the
 * ES|QL pretty-printer and the naming/config hooks.
 */
class ElasticViewerComponent extends AbstractQueryViewerComponent {
    private indicesUrl!: string;
    private indices!: EsIndex[];
    private sortKey!: 'name' | 'count';
    private sortDir!: 'asc' | 'desc';

    // --- Naming / config hooks -----------------------------------------------

    protected cssPrefix(): string {
        return 'aaxis-es-viewer';
    }

    protected transPrefix(): string {
        return 'aaxis.devtools.elastic_viewer';
    }

    protected eventNs(): string {
        return 'aaxisEsViewer';
    }

    protected storagePrefix(): string {
        return 'aaxis-esv-result:';
    }

    protected editorMode(): string {
        // ES|QL is pipe-based but shares many SQL keywords; the SQL mode gives reasonable highlighting.
        return 'text/x-sql';
    }

    // --- Sidebar -------------------------------------------------------------

    protected setupSidebar(options: ElasticViewerOptions): void {
        this.indicesUrl = options.indicesUrl;
        this.indices = [];
        this.sortKey = 'name';
        this.sortDir = 'asc';
    }

    protected bindSidebarEvents(): void {
        const ns = '.' + this.ns;
        this.$el.on('click' + ns, '[data-role="index-item"]', this.onIndexClick.bind(this));
        this.$el.on('click' + ns, '[data-role="order-key"]', this.onOrderKey.bind(this));
        this.$el.on('click' + ns, '[data-role="order-dir"]', this.onOrderDir.bind(this));
    }

    protected afterSetup(): void {
        this.updateOrderButtons();
    }

    protected loadSidebar(): void {
        this.renderSidebarLoading();
        $.ajax({url: this.indicesUrl, method: 'GET', errorHandlerMessage: false})
            .done((response: {indices?: EsIndex[]}) => {
                this.indices = response.indices || [];
                this.renderSidebar();
            })
            .fail(() => {
                this.indices = [];
                this.renderSidebar();
                messenger.notificationFlashMessage('error', this.t('indices_error'));
            });
    }

    protected renderSidebarLoading(): void {
        this.$el.find('[data-role="indices"]').empty().append(
            $('<li/>', {'class': this.cls('loading')}).append(
                $('<span/>', {'class': 'fa fa-spinner fa-spin', 'aria-hidden': 'true'}),
                $('<span/>', {text: this.t('loading')})
            )
        );
    }

    protected renderSidebar(): void {
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
            $list.append($('<li/>', {'class': this.cls('empty')}).text(this.t('no_indices')));
            return;
        }

        visible.forEach(index => {
            const $link = $('<a/>', {
                href: '#',
                'data-role': 'index-item',
                'data-name': index.name,
                title: index.name
            }).append(
                $('<span/>', {'class': 'fa fa-database ' + this.cls('index-icon'), 'aria-hidden': 'true'}),
                $('<span/>', {'class': this.cls('index-name'), text: index.name})
            );
            if (this.sortKey === 'count') {
                $link.append($('<span/>', {
                    'class': this.cls('index-count'),
                    text: String(index.docsCount),
                    title: this.t('doc_count')
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

    private onOrderKey(event: any): void {
        event.preventDefault();
        // Toggle alphabetic (A, no badge) <-> document count (1, badge shown).
        this.sortKey = this.sortKey === 'name' ? 'count' : 'name';
        this.updateOrderButtons();
        this.renderSidebar();
    }

    private onOrderDir(event: any): void {
        event.preventDefault();
        this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
        this.updateOrderButtons();
        this.renderSidebar();
    }

    private updateOrderButtons(): void {
        const $key = this.$el.find('[data-role="order-key"]').empty();
        // A = alphabetic (no badge), 1 = document count (matches the Database Viewer standard).
        if (this.sortKey === 'count') {
            $key.append($('<span/>', {'class': this.cls('order-num'), 'aria-hidden': 'true', text: '1'}));
            $key.attr('title', this.t('order_by_count'));
        } else {
            $key.append($('<span/>', {'class': 'fa fa-font', 'aria-hidden': 'true'}));
            $key.attr('title', this.t('order_by_name'));
        }

        const $dir = this.$el.find('[data-role="order-dir"]');
        $dir.find('.fa').attr('class', 'fa ' + (this.sortDir === 'desc' ? 'fa-sort-amount-desc' : 'fa-sort-amount-asc'));
        $dir.attr('title', this.sortDir === 'desc' ? this.t('order_desc') : this.t('order_asc'));
    }

    // --- ES|QL beautify ------------------------------------------------------

    /**
     * Lightweight ES|QL pretty-printer: each pipe command onto its own line, leading command and a
     * few secondary keywords uppercased. Strings/comments are protected so they are never altered.
     */
    protected formatQuery(raw: string): string {
        const store: string[] = [];
        const stash = (m: string): string => {
            store.push(m);
            return '\u0001' + (store.length - 1) + '\u0001';
        };

        let s = raw
            .replace(/\/\/[^\n]*/g, stash)
            .replace(/"""[\s\S]*?"""/g, stash)
            .replace(/"(?:[^"\\]|\\.)*"/g, stash)
            .replace(/`(?:[^`]|``)*`/g, stash)
            .replace(/\s+/g, ' ')
            .trim();

        // Split on top-level pipes (depth 0) so each command starts a new line.
        let depth = 0;
        const segments: string[] = [];
        let current = '';
        for (let i = 0; i < s.length; i++) {
            const ch = s.charAt(i);
            if (ch === '(') {
                depth++;
            } else if (ch === ')') {
                depth = Math.max(0, depth - 1);
            }
            if (ch === '|' && depth === 0) {
                segments.push(current.trim());
                current = '';
            } else {
                current += ch;
            }
        }
        if (current.trim() !== '') {
            segments.push(current.trim());
        }

        const commandRe = new RegExp('^(' + ESQL_COMMANDS.join('|') + ')\\b', 'i');
        const secondaryRe = new RegExp('\\b(' + ESQL_SECONDARY.join('|') + ')\\b', 'gi');

        const lines = segments
            .filter(seg => seg !== '')
            .map((seg, index) => {
                let line = seg.replace(commandRe, (m: string) => m.toUpperCase());
                line = line.replace(secondaryRe, (m: string) => m.toUpperCase());
                return index === 0 ? line : '| ' + line;
            });

        s = lines.join('\n');

        return s.replace(/\u0001(\d+)\u0001/g, (_m, i) => store[Number(i)]);
    }
}

export default ElasticViewerComponent;
