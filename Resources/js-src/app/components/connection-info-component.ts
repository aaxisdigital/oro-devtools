import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import Modal from 'oroui/js/modal';
import BaseComponent from 'oroui/js/app/components/base/component';

interface ConnectionInfoOptions {
    _sourceElement: any;
    resolveUrl: string;
}

interface ChainHop {
    value: string;
    trusted: boolean;
}

interface ResolvedClient {
    ip: string;
    source: 'remote_addr' | 'forwarded' | 'all_trusted';
    via: string | null;
    chain: ChainHop[];
}

/**
 * Connection Info page: lets the user enter a comma-separated trusted-proxy list and re-resolves the
 * end-user IP live (the headers are taken from the current request, which traverses the same proxies
 * as this page). Only the resolved-IP card, the inline resolved value and the hop chain change; the
 * raw server/proxy tables are request-fixed and rendered server-side.
 */
class ConnectionInfoComponent extends BaseComponent {
    private $el!: any;
    private resolveUrl!: string;
    private applying!: boolean;

    initialize(options: ConnectionInfoOptions): void {
        this.$el = options._sourceElement;
        this.resolveUrl = options.resolveUrl;
        this.applying = false;

        this.$el.on('click.aaxisCinfo', '[data-role="apply"]', this.onApply.bind(this));
        this.$el.on('click.aaxisCinfo', '[data-role="help"]', this.onHelp.bind(this));
        this.$el.on('keydown.aaxisCinfo', '[data-role="trusted-proxies"]', (e: any) => {
            if (e.keyCode === 13) {
                e.preventDefault();
                this.onApply();
            }
        });
    }

    private onApply(event?: any): void {
        if (event && event.preventDefault) {
            event.preventDefault();
        }
        if (this.applying) {
            return;
        }

        const trustedProxies = String(this.$el.find('[data-role="trusted-proxies"]').val() || '').trim();
        this.setApplying(true);

        $.ajax({
            url: this.resolveUrl,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({trustedProxies})
        }).done((response: {success: boolean; client?: ResolvedClient}) => {
            if (response && response.client) {
                this.render(response.client);
            }
        }).fail(() => {
            this.$el.find('[data-role="resolved-source"]').text(__('aaxis.devtools.connection_info.error'));
        }).always(() => {
            this.setApplying(false);
        });
    }

    /** Updates the resolved-IP card, the inline resolved value and the hop chain. */
    private render(client: ResolvedClient): void {
        this.$el.find('[data-role="resolved-ip"]').text(client.ip);
        this.$el.find('[data-role="resolved-ip-inline"]').text(client.ip);
        this.$el.find('[data-role="resolved-source"]').text(this.sourceLabel(client));

        const $chain = this.$el.find('[data-role="chain"]').empty();
        client.chain.forEach((hop, index) => {
            const $hop = $('<span/>', {'class': 'aaxis-cinfo__hop' + (hop.trusted ? ' is-trusted' : '')});
            $hop.append(document.createTextNode(hop.value + ' '));
            if (hop.trusted) {
                $hop.append($('<span/>', {
                    'class': 'aaxis-cinfo__hop-tag',
                    text: __('aaxis.devtools.connection_info.trusted_tag')
                }));
            }
            $chain.append($hop);
            if (index < client.chain.length - 1) {
                $chain.append($('<span/>', {'class': 'aaxis-cinfo__hop-sep', 'aria-hidden': 'true', text: '→'}));
            }
        });
    }

    private sourceLabel(client: ResolvedClient): string {
        if (client.source === 'forwarded') {
            return __('aaxis.devtools.connection_info.source.forwarded', {via: client.via || ''});
        }
        if (client.source === 'all_trusted') {
            return __('aaxis.devtools.connection_info.source.all_trusted');
        }
        return __('aaxis.devtools.connection_info.source.remote_addr');
    }

    private onHelp(event: any): void {
        event.preventDefault();
        const html = this.$el.find('[data-role="help-content"]').html();
        const modal = new Modal({
            title: __('aaxis.devtools.connection_info.help'),
            content: html,
            allowOk: false,
            cancelText: __('Close')
        });
        modal.open();
    }

    private setApplying(applying: boolean): void {
        this.applying = applying;
        this.$el.find('[data-role="applying"]').prop('hidden', !applying);
        this.$el.find('[data-role="apply"], [data-role="trusted-proxies"]').prop('disabled', applying);
    }

    dispose(): void {
        if (this.disposed) {
            return;
        }
        this.$el.off('.aaxisCinfo');
        super.dispose();
    }
}

export default ConnectionInfoComponent;
