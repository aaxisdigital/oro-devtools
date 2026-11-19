import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import Modal from 'oroui/js/modal';
import FolderArchiveDialog from '../../../../Resources/js-src/app/components/folder-archive-dialog';
import {AjaxRecorder, installAjax} from '../../helpers/ajax';

const T = 'aaxis.devtools.storage_browser.archive.';
const LIMITS = {files: 1000, bytes: 5242880, bytesFormatted: '5.0 MB'};

function scan(extra: Record<string, unknown> = {}): any {
    return {
        success: true,
        id: 'scan-1',
        path: 'reports/',
        files: 0,
        bytes: 0,
        bytesFormatted: '0 B',
        complete: false,
        mode: null,
        archiveName: 'reports.zip',
        suggestedFilename: 'reports-20261119-101500.zip',
        limits: LIMITS,
        ...extra
    };
}

const flush = () => new Promise(resolve => setTimeout(resolve, 0));

describe('FolderArchiveDialog', () => {
    let ajax: AjaxRecorder;
    let clicked: Array<{href: string; download: string}>;

    beforeEach(() => {
        Modal.instances.length = 0;
        ajax = installAjax();
        clicked = [];
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function (this: HTMLAnchorElement) {
            clicked.push({href: this.href, download: this.download});
        });
        (URL as any).createObjectURL = vi.fn(() => 'blob:zip');
        (URL as any).revokeObjectURL = vi.fn();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        delete (URL as any).createObjectURL;
        delete (URL as any).revokeObjectURL;
    });

    function open(path = 'reports/', canGenerate = true): {dialog: FolderArchiveDialog; modal: any} {
        const dialog = new FolderArchiveDialog({
            scanUrl: '/scan',
            downloadUrl: '/zip',
            generateUrl: '/generate',
            canGenerate,
            csrfToken: () => 'tok'
        }, path);
        dialog.open();
        return {dialog, modal: Modal.instances[Modal.instances.length - 1]};
    }

    const body = (modal: any) => modal.$el.find('[data-role="archive-body"]');
    const text = (modal: any) => body(modal).text();

    /** Opens the dialog and completes the scan in one page with the given outcome. */
    function scanned(extra: Record<string, unknown>, canGenerate = true): any {
        const {modal} = open('reports/', canGenerate);
        ajax.last('/scan').resolve(scan({complete: true, ...extra}));
        return modal;
    }

    describe('scanning', () => {
        it('opens a dialog with only a close button and starts counting the folder', () => {
            const {modal} = open();

            expect(modal.options).toMatchObject({title: T + 'title', allowOk: false, cancelText: 'Close'});
            expect(modal.open).toHaveBeenCalled();
            expect(body(modal).find('.fa-spinner')).toHaveLength(1);
            expect(text(modal)).toContain(T + 'scanning');
            expect(body(modal).find('[data-role="archive-files"]').text()).toBe('0');
            expect(body(modal).find('[data-role="archive-size"]').text()).toBe('0 B');

            const request = ajax.last('/scan');
            expect(request.options).toMatchObject({
                method: 'POST',
                contentType: 'application/json',
                headers: {'X-CSRF-Header': 'tok'},
                errorHandlerMessage: false
            });
            expect(JSON.parse(request.options.data)).toEqual({path: 'reports/'});
        });

        it('shows the running count and asks for the next page until the scan is complete', () => {
            const {modal} = open();
            ajax.last('/scan').resolve(scan({files: 1500, bytesFormatted: '3.0 MB'}));

            expect(body(modal).find('[data-role="archive-files"]').text()).toBe((1500).toLocaleString());
            expect(body(modal).find('[data-role="archive-size"]').text()).toBe('3.0 MB');
            expect(JSON.parse(ajax.requests[1].options.data)).toEqual({id: 'scan-1'});

            ajax.requests[1].resolve(scan({files: 2000, complete: true, mode: 'background'}));

            expect(ajax.to('/scan')).toHaveLength(2);
            expect(body(modal).find('.fa-spinner')).toHaveLength(0);
        });

        it('names the bucket root instead of an empty path', () => {
            const {modal} = open('');

            expect(text(modal)).toContain(T + 'scanning');
            expect(body(modal).find('p').first().text()).toBe(' ' + T + 'scanning');

            ajax.last('/scan').resolve(scan({path: '', complete: true, mode: 'empty'}));

            expect(text(modal)).toContain(T + 'folder');
        });

        it('reports a refused scan', () => {
            const {modal} = open();
            ajax.last('/scan').resolve({success: false, message: 'Bucket not configured.'});

            expect(body(modal).find('.aaxis-fs-archive__text--error').text()).toBe('Bucket not configured.');
        });

        it('falls back to a generic message for a refusal without one', () => {
            const {modal} = open();
            ajax.last('/scan').resolve({success: false});

            expect(text(modal)).toBe(T + 'scan_error');
        });

        it('reports a failed scan request', () => {
            const {modal} = open();
            ajax.last('/scan').reject({message: 'Storage error (HTTP 403).'});

            expect(text(modal)).toBe('Storage error (HTTP 403).');
        });

        it('reports a failed scan request without a message', () => {
            const {modal} = open();
            ajax.last('/scan').reject();

            expect(text(modal)).toBe(T + 'scan_error');
        });

        it('stops scanning once the dialog is closed', () => {
            const {modal} = open();
            modal.emit('hidden');
            ajax.last('/scan').resolve(scan({files: 10}));

            expect(ajax.to('/scan')).toHaveLength(1);
            expect(text(modal)).not.toContain('10');
        });

        it('ignores a failure that arrives after closing', () => {
            const {modal} = open();
            modal.emit('hidden');
            ajax.last('/scan').reject({message: 'late'});

            expect(text(modal)).not.toContain('late');
        });
    });

    describe('outcome', () => {
        it('says when the folder has nothing to download', () => {
            const modal = scanned({mode: 'empty'});

            expect(text(modal)).toContain(T + 'empty');
            expect(body(modal).find('button')).toHaveLength(0);
        });

        it('offers a direct download for a small folder', () => {
            const modal = scanned({mode: 'direct', files: 12, bytesFormatted: '40.0 KB'});

            expect(body(modal).find('[data-role="archive-files"]').text()).toBe('12');
            expect(body(modal).find('[data-role="archive-size"]').text()).toBe('40.0 KB');
            expect(text(modal)).toContain(T + 'direct_hint');
            expect(body(modal).find('[data-role="archive-download"]').text()).toBe(T + 'download');
            expect(body(modal).find('[data-role="archive-generate"]')).toHaveLength(0);
        });

        it('offers only a background archive, with a suggested name, for a large folder', () => {
            const modal = scanned({mode: 'background', files: 4321});

            expect(text(modal)).toContain(T + 'too_large');
            expect(text(modal)).toContain(T + 'background_hint');
            expect(body(modal).find('[data-role="archive-download"]')).toHaveLength(0);
            expect(body(modal).find('[data-role="archive-filename"]').val()).toBe('reports-20261119-101500.zip');
            expect(body(modal).find('label').attr('for')).toBe(body(modal).find('[data-role="archive-filename"]').attr('id'));
        });

        it('explains that a large folder needs write access when the user has none', () => {
            const modal = scanned({mode: 'background'}, false);

            expect(text(modal)).toContain(T + 'no_write');
            expect(body(modal).find('[data-role="archive-filename"], [data-role="archive-generate"]')).toHaveLength(0);
        });
    });

    describe('direct download', () => {
        function stubFetch(response: Promise<any>): any {
            const fetchMock = vi.fn(() => response);
            vi.stubGlobal('fetch', fetchMock);
            return fetchMock;
        }

        it('fetches the zip, saves it under the archive name and closes', async () => {
            const blob = new Blob(['PK']);
            const fetchMock = stubFetch(Promise.resolve({ok: true, blob: () => Promise.resolve(blob)}));
            const modal = scanned({mode: 'direct', id: 'a b'});

            body(modal).find('[data-role="archive-download"]').trigger('click');

            expect(fetchMock).toHaveBeenCalledWith('/zip?id=a%20b', {credentials: 'same-origin'});
            expect(body(modal).find('[data-role="archive-download"]').prop('disabled')).toBe(true);
            expect(body(modal).find('[data-role="archive-preparing"] .fa-spinner')).toHaveLength(1);

            await flush();

            expect(URL.createObjectURL).toHaveBeenCalledWith(blob);
            expect(clicked).toEqual([{href: 'blob:zip', download: 'reports.zip'}]);
            expect(modal.close).toHaveBeenCalled();
            expect(document.body.querySelector('a[download]')).toBeNull();
        });

        it('shows the server error for a refused download', async () => {
            stubFetch(Promise.resolve({ok: false, json: () => Promise.resolve({message: 'The folder scan has expired.'})}));
            const modal = scanned({mode: 'direct'});

            body(modal).find('[data-role="archive-download"]').trigger('click');
            await flush();

            expect(text(modal)).toBe('The folder scan has expired.');
            expect(modal.close).not.toHaveBeenCalled();
        });

        it('falls back to a generic message when the error is not JSON', async () => {
            stubFetch(Promise.resolve({ok: false, json: () => Promise.reject(new Error('not json'))}));
            const modal = scanned({mode: 'direct'});

            body(modal).find('[data-role="archive-download"]').trigger('click');
            await flush();

            expect(text(modal)).toBe(T + 'download_error');
        });

        it('reports a network failure', async () => {
            stubFetch(Promise.reject(new Error('')));
            const modal = scanned({mode: 'direct'});

            body(modal).find('[data-role="archive-download"]').trigger('click');
            await flush();

            expect(text(modal)).toBe(T + 'download_error');
        });

        it('does nothing once the dialog was closed meanwhile', async () => {
            stubFetch(Promise.resolve({ok: true, blob: () => Promise.resolve(new Blob(['PK']))}));
            const modal = scanned({mode: 'direct'});

            body(modal).find('[data-role="archive-download"]').trigger('click');
            modal.emit('hidden');
            await flush();

            expect(clicked).toEqual([]);
            expect(modal.close).not.toHaveBeenCalled();
        });

        it('keeps quiet about an error once the dialog was closed', async () => {
            stubFetch(Promise.reject(new Error('late')));
            const modal = scanned({mode: 'direct'});

            body(modal).find('[data-role="archive-download"]').trigger('click');
            modal.emit('hidden');
            await flush();

            expect(text(modal)).not.toContain('late');
        });
    });

    describe('background archive', () => {
        function large(): any {
            return scanned({mode: 'background', files: 5000});
        }

        const generate = (modal: any) => body(modal).find('[data-role="archive-generate"]');

        it('requests the archive under the chosen name and says to check back later', () => {
            const modal = large();
            body(modal).find('[data-role="archive-filename"]').val('  all.zip ');
            generate(modal).trigger('click');

            const request = ajax.last('/generate');
            expect(request.options).toMatchObject({method: 'POST', headers: {'X-CSRF-Header': 'tok'}});
            expect(JSON.parse(request.options.data)).toEqual({id: 'scan-1', name: 'all.zip'});
            expect(generate(modal).prop('disabled')).toBe(true);

            request.resolve({success: true, name: 'all.zip'});

            expect(body(modal).find('.aaxis-fs-archive__text--success').text()).toBe(T + 'queued');
            expect(body(modal).find('input, button')).toHaveLength(0);
        });

        it('falls back to the typed name when the response has none', () => {
            const modal = large();
            generate(modal).trigger('click');
            ajax.last('/generate').resolve({success: true});

            expect(text(modal)).toContain(T + 'queued');
        });

        it('asks for a name instead of sending an empty one', () => {
            const modal = large();
            body(modal).find('[data-role="archive-filename"]').val('   ');

            generate(modal).trigger('click');
            generate(modal).trigger('click');

            expect(ajax.to('/generate')).toHaveLength(0);
            expect(body(modal).find('[data-role="archive-message"]')).toHaveLength(1);
            expect(body(modal).find('[data-role="archive-message"]').text()).toBe(T + 'filename_required');
        });

        it('lets the user fix a refused name and try again', () => {
            const modal = large();
            generate(modal).trigger('click');
            ajax.last('/generate').resolve({success: false, message: '"x.zip" already exists in this folder.'});

            expect(generate(modal).prop('disabled')).toBe(false);
            expect(body(modal).find('[data-role="archive-message"]').text()).toBe('"x.zip" already exists in this folder.');

            generate(modal).trigger('click');
            ajax.last('/generate').reject({message: 'Access denied.'});

            expect(body(modal).find('[data-role="archive-message"]')).toHaveLength(1);
            expect(body(modal).find('[data-role="archive-message"]').text()).toBe('Access denied.');
        });

        it('falls back to a generic message for errors without one', () => {
            const modal = large();
            generate(modal).trigger('click');
            ajax.last('/generate').reject();

            expect(body(modal).find('[data-role="archive-message"]').text()).toBe(T + 'generate_error');
        });

        it('ignores answers that arrive after closing', () => {
            const modal = large();
            generate(modal).trigger('click');
            modal.emit('hidden');
            ajax.last('/generate').resolve({success: true});
            generate(modal).trigger('click');
            ajax.last('/generate').reject({message: 'late'});

            expect(text(modal)).not.toContain(T + 'queued');
            expect(text(modal)).not.toContain('late');
        });
    });
});
