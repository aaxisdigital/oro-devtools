import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import Modal from 'oroui/js/modal';

export interface FolderArchiveUrls {
    scanUrl: string;
    downloadUrl: string;
    generateUrl: string;
}

export interface FolderArchiveOptions extends FolderArchiveUrls {
    /** Whether the user may have the archive generated into the bucket (write access, not read-only). */
    canGenerate: boolean;
    /** The CSRF token for the POST endpoints. */
    csrfToken: () => string;
}

interface ScanResult {
    success: boolean;
    message?: string;
    id: string;
    path: string;
    files: number;
    bytes: number;
    bytesFormatted: string;
    complete: boolean;
    mode: 'empty' | 'direct' | 'background' | null;
    archiveName: string;
    suggestedFilename: string;
    limits: {files: number; bytes: number; bytesFormatted: string};
}

const T = 'aaxis.devtools.storage_browser.archive.';

/**
 * The Bucket Viewer's "download folder" dialog.
 *
 * It scans the folder recursively, one page per request, with a live file count and total size. The
 * server stores the file list as it goes, so the archive is exactly what was counted. Then:
 *  - small folder (below both configured limits): a "Download ZIP" button;
 *  - larger folder: no direct download. The user names an archive that a background job writes into
 *    the folder, and is told to check back later. This needs write access.
 * Closing the dialog stops the scan.
 */
export default class FolderArchiveDialog {
    private modal: any = null;
    private closed = false;
    private result: ScanResult | null = null;

    constructor(private readonly options: FolderArchiveOptions, private readonly path: string) {}

    open(): void {
        this.modal = new Modal({
            title: __(T + 'title'),
            content: '<div class="aaxis-fs-archive" data-role="archive-body"></div>',
            allowOk: false,
            cancelText: __('Close')
        });
        this.modal.on('hidden', () => {
            this.closed = true;
        });
        this.modal.open();
        this.renderScanning(null);
        this.scan({path: this.path});
    }

    private body(): any {
        return this.modal.$el.find('[data-role="archive-body"]');
    }

    private post(url: string, payload: Record<string, unknown>): any {
        return $.ajax({
            url,
            method: 'POST',
            contentType: 'application/json',
            headers: {'X-CSRF-Header': this.options.csrfToken()},
            data: JSON.stringify(payload),
            errorHandlerMessage: false
        });
    }

    // --- Scan ------------------------------------------------------------------

    private scan(payload: Record<string, unknown>): void {
        this.post(this.options.scanUrl, payload)
            .done((response: ScanResult) => {
                if (this.closed) {
                    return;
                }
                if (!response.success) {
                    this.renderError(response.message || __(T + 'scan_error'));
                    return;
                }
                this.result = response;
                if (!response.complete) {
                    this.renderScanning(response);
                    this.scan({id: response.id});
                    return;
                }
                this.renderResult(response);
            })
            .fail((jqXhr: any) => {
                if (!this.closed) {
                    this.renderError(jqXhr.responseJSON?.message || __(T + 'scan_error'));
                }
            });
    }

    // --- Rendering ---------------------------------------------------------------

    private folderLabel(): string {
        const path = this.result?.path ?? this.path;
        return path === '' || path === '/' ? __(T + 'root') : path;
    }

    private stats(result: ScanResult | null): any {
        const stat = (label: string, value: string): any => $('<span/>')
            .append(document.createTextNode(__(T + label) + ' '))
            .append($('<strong/>', {'data-role': 'archive-' + label, text: value}));

        return $('<div/>', {'class': 'aaxis-fs-archive__stats', 'data-role': 'archive-stats'})
            .append(stat('files', (result?.files ?? 0).toLocaleString()))
            .append(stat('size', result?.bytesFormatted ?? '0 B'));
    }

    private line(text: string, modifier = ''): any {
        return $('<p/>', {'class': 'aaxis-fs-archive__text' + modifier, text});
    }

    private spinner(text: string): any {
        return $('<p/>', {'class': 'aaxis-fs-archive__text'})
            .append($('<span/>', {'class': 'fa fa-spinner fa-spin', 'aria-hidden': 'true'}))
            .append(document.createTextNode(' ' + text));
    }

    private renderScanning(result: ScanResult | null): void {
        this.body().empty()
            .append(this.spinner(__(T + 'scanning', {path: this.folderLabel()})))
            .append(this.stats(result));
    }

    private renderError(message: string): void {
        this.body().empty().append(this.line(message, ' aaxis-fs-archive__text--error'));
    }

    private renderResult(result: ScanResult): void {
        const $body = this.body().empty()
            .append(this.line(__(T + 'folder', {path: this.folderLabel()})))
            .append(this.stats(result));

        if (result.mode === 'empty') {
            $body.append(this.line(__(T + 'empty')));
        } else if (result.mode === 'direct') {
            $body.append(this.line(__(T + 'direct_hint')));
            $body.append($('<button/>', {
                type: 'button',
                'class': 'btn btn-primary',
                'data-role': 'archive-download',
                text: __(T + 'download')
            }).on('click', () => this.download()));
        } else {
            this.renderLarge($body, result);
        }
    }

    private renderLarge($body: any, result: ScanResult): void {
        $body.append(this.line(__(T + 'too_large', {
            maxFiles: result.limits.files.toLocaleString(),
            maxSize: result.limits.bytesFormatted
        })));
        if (!this.options.canGenerate) {
            $body.append(this.line(__(T + 'no_write'), ' aaxis-fs-archive__text--warn'));
            return;
        }

        $body.append(this.line(__(T + 'background_hint')));
        const inputId = 'aaxis-fs-archive-name-' + result.id;
        $body.append($('<label/>', {'class': 'aaxis-fs-archive__label', 'for': inputId, text: __(T + 'filename')}));
        $body.append($('<input/>', {
            type: 'text',
            id: inputId,
            'class': 'form-control aaxis-fs-archive__filename',
            'data-role': 'archive-filename',
            value: result.suggestedFilename,
            autocomplete: 'off',
            spellcheck: 'false'
        }));
        $body.append($('<button/>', {
            type: 'button',
            'class': 'btn btn-primary',
            'data-role': 'archive-generate',
            text: __(T + 'generate')
        }).on('click', () => this.generate()));
    }

    // --- Actions -----------------------------------------------------------------

    /**
     * Fetches the ZIP (rather than navigating to it), so the dialog can show progress and errors.
     */
    private download(): void {
        const result = this.result as ScanResult;
        this.body().find('[data-role="archive-download"]').prop('disabled', true);
        this.body().append(this.spinner(__(T + 'preparing')).attr('data-role', 'archive-preparing'));

        fetch(this.options.downloadUrl + '?id=' + encodeURIComponent(result.id), {credentials: 'same-origin'})
            .then(response => {
                if (!response.ok) {
                    return response.json()
                        .catch(() => ({}))
                        .then((data: {message?: string}) => {
                            throw new Error(data.message || __(T + 'download_error'));
                        });
                }
                return response.blob();
            })
            .then((blob: Blob) => {
                if (this.closed) {
                    return;
                }
                FolderArchiveDialog.save(blob, result.archiveName);
                this.modal.close();
            })
            .catch((error: Error) => {
                if (!this.closed) {
                    this.renderError(error.message || __(T + 'download_error'));
                }
            });
    }

    private generate(): void {
        const result = this.result as ScanResult;
        const $input = this.body().find('[data-role="archive-filename"]');
        const name = String($input.val() || '').trim();
        if (name === '') {
            this.body().find('[data-role="archive-message"]').remove();
            this.body().append(this.line(__(T + 'filename_required'), ' aaxis-fs-archive__text--error')
                .attr('data-role', 'archive-message'));
            $input.trigger('focus');
            return;
        }

        const $button = this.body().find('[data-role="archive-generate"]').prop('disabled', true);
        this.post(this.options.generateUrl, {id: result.id, name})
            .done((response: {success: boolean; message?: string; name?: string}) => {
                if (this.closed) {
                    return;
                }
                if (!response.success) {
                    this.showGenerateError($button, response.message);
                    return;
                }
                const archive = response.name || name;
                this.body().empty()
                    .append(this.line(__(T + 'folder', {path: this.folderLabel()})))
                    .append(this.stats(result))
                    .append(this.line(__(T + 'queued', {name: archive}), ' aaxis-fs-archive__text--success'));
            })
            .fail((jqXhr: any) => {
                if (!this.closed) {
                    this.showGenerateError($button, jqXhr.responseJSON?.message);
                }
            });
    }

    /** A rejected name (e.g. already taken) can be corrected and resubmitted. */
    private showGenerateError($button: any, message?: string): void {
        $button.prop('disabled', false);
        this.body().find('[data-role="archive-message"]').remove();
        this.body().append(this.line(message || __(T + 'generate_error'), ' aaxis-fs-archive__text--error')
            .attr('data-role', 'archive-message'));
    }

    private static save(blob: Blob, filename: string): void {
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
}
