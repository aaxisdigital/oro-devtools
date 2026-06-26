import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import Modal from 'oroui/js/modal';
import BaseComponent from 'oroui/js/app/components/base/component';

interface NetworkToolsOptions {
    _sourceElement: any;
    runUrl: string;
}

interface ToolFields {
    port: boolean;
    path: boolean;
    dnsMode: boolean;
    portRequired?: boolean;
}

const FIELDS: Record<string, ToolFields> = {
    dns: {port: false, path: false, dnsMode: true},
    ping: {port: true, path: false, dnsMode: false},
    traceroute: {port: true, path: false, dnsMode: false},
    socket: {port: true, path: false, dnsMode: false, portRequired: true},
    curl: {port: true, path: true, dnsMode: false},
    'ssl-cert': {port: true, path: false, dnsMode: false},
    ciphers: {port: true, path: false, dnsMode: false}
};

/**
 * Network connectivity tools console: pick a tool, enter host/port/path, run, and view
 * the text output in a console area.
 */
class NetworkToolsComponent extends BaseComponent {
    private $el!: any;
    private runUrl!: string;
    private running!: boolean;

    initialize(options: NetworkToolsOptions): void {
        this.$el = options._sourceElement;
        this.runUrl = options.runUrl;
        this.running = false;

        this.$el.on('change.aaxisNet', '[data-role="tool"]', this.onToolChange.bind(this));
        this.$el.on('click.aaxisNet', '[data-role="run"]', this.onRun.bind(this));
        this.$el.on('click.aaxisNet', '[data-role="clear"]', this.onClear.bind(this));
        this.$el.on('click.aaxisNet', '[data-role="help"]', this.onHelp.bind(this));
        this.$el.on('keydown.aaxisNet', '[data-role="host"], [data-role="port"], [data-role="path"]', (e: any) => {
            if (e.keyCode === 13) {
                e.preventDefault();
                this.onRun();
            }
        });

        this.onToolChange();
    }

    private currentTool(): string {
        return String(this.$el.find('[data-role="tool"]').val() || 'dns');
    }

    private onToolChange(): void {
        const cfg = FIELDS[this.currentTool()] || {port: false, path: false, dnsMode: false};
        this.toggleField('port', cfg.port);
        this.toggleField('path', cfg.path);
        // The dns-mode <select> is upgraded to an Oro input widget, so toggle its wrapper
        // (which contains the generated widget) rather than the now-hidden native control.
        this.toggleField('dns-mode-wrap', cfg.dnsMode);
        // setRunning() disables the native <select> directly during a request, so its enabled
        // state must be restored here too — otherwise it stays disabled after the first run.
        this.$el.find('[data-role="dns-mode"]').prop('disabled', !cfg.dnsMode);
    }

    /** Shows or hides a field (and keeps it out of the submitted data when hidden). */
    private toggleField(role: string, visible: boolean): void {
        this.$el.find('[data-role="' + role + '"]')
            .toggleClass('aaxis-net-tools__hidden', !visible)
            .prop('disabled', !visible);
    }

    private onRun(event?: any): void {
        if (event && event.preventDefault) {
            event.preventDefault();
        }
        if (this.running) {
            return;
        }

        const tool = this.currentTool();
        const cfg = FIELDS[tool] || {port: false, path: false, dnsMode: false};
        const host = this.field('host');
        if (host === '') {
            this.append(__('aaxis.devtools.network_tools.host_required'));
            return;
        }

        // Validate required fields client-side before hitting the backend.
        if (cfg.portRequired && this.field('port') === '') {
            this.append(__('aaxis.devtools.network_tools.port_required'));
            return;
        }

        // Only include the inputs that are relevant to the selected tool.
        const payload: Record<string, string> = {tool, host};
        const echo: string[] = [tool, host];

        if (cfg.dnsMode) {
            const mode = this.field('dns-mode') || 'a';
            payload.mode = mode;
            echo.push(mode);
        }
        if (cfg.port) {
            const port = this.field('port');
            if (port !== '') {
                payload.port = port;
                echo.push(port);
            }
        }
        if (cfg.path) {
            const path = this.field('path');
            if (path !== '') {
                payload.path = path;
                echo.push(path);
            }
        }
        const timeout = this.field('timeout');
        if (timeout !== '') {
            payload.timeout = timeout;
            echo.push(timeout + 's');
        }

        this.append('> ' + echo.join(' '));
        this.setRunning(true);

        $.ajax({
            url: this.runUrl,
            method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(payload)
        }).done((response: {success: boolean; output?: string}) => {
            this.append(response.output || '');
        }).fail((jqXhr: any) => {
            const response = jqXhr.responseJSON || {};
            this.append(response.output || __('aaxis.devtools.network_tools.error'));
        }).always(() => {
            this.append('');
            this.setRunning(false);
        });
    }

    private field(role: string): string {
        return String(this.$el.find('[data-role="' + role + '"]').val() || '').trim();
    }

    private onClear(event: any): void {
        event.preventDefault();
        this.$el.find('[data-role="console"]').empty();
    }

    private onHelp(event: any): void {
        event.preventDefault();
        const html = this.$el.find('[data-role="help-content"]').html();
        const modal = new Modal({
            title: __('aaxis.devtools.network_tools.help'),
            content: html,
            allowOk: false,
            cancelText: __('Close')
        });
        modal.open();
    }

    private append(text: string): void {
        const $console = this.$el.find('[data-role="console"]');
        $console.append(document.createTextNode(text + '\n'));
        $console.scrollTop($console.prop('scrollHeight'));
    }

    private setRunning(running: boolean): void {
        this.running = running;
        this.$el.find('[data-role="running"]').prop('hidden', !running);
        ['run', 'clear', 'tool', 'host', 'timeout'].forEach(role => {
            this.$el.find('[data-role="' + role + '"]').prop('disabled', running);
        });
        if (running) {
            this.$el.find('[data-role="port"], [data-role="path"], [data-role="dns-mode"]').prop('disabled', true);
        } else {
            // Restore the port/path/dns-mode visibility + enabled state for the current tool.
            this.onToolChange();
        }
    }

    dispose(): void {
        if (this.disposed) {
            return;
        }
        this.$el.off('.aaxisNet');
        super.dispose();
    }
}

export default NetworkToolsComponent;
