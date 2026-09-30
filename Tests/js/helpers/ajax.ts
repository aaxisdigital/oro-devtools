import $ from 'jquery';
import {vi} from 'vitest';

/** One intercepted `$.ajax()` call that the test settles by hand. */
export interface FakeRequest {
    options: any;
    abort: ReturnType<typeof vi.fn>;
    resolve: (data?: any) => void;
    /** Fails the request like jQuery would: `jqXhr.responseJSON` carries the decoded body. */
    reject: (responseJSON?: any, statusText?: string) => void;
}

export interface AjaxRecorder {
    requests: FakeRequest[];
    /** All intercepted requests whose url contains the fragment (or equals it when it starts with `=`). */
    to: (urlFragment: string) => FakeRequest[];
    /** The most recent request to that url (throws when there is none, so a test fails with a clear message). */
    last: (urlFragment: string) => FakeRequest;
}

/**
 * Replaces `$.ajax` with a recorder. Nothing leaves the process: every call returns a jQuery
 * promise (with an `abort` spy) that stays pending until the test calls `resolve`/`reject`.
 */
export function installAjax(): AjaxRecorder {
    const requests: FakeRequest[] = [];

    vi.spyOn($, 'ajax').mockImplementation(((options: any) => {
        const deferred = $.Deferred();
        const abort = vi.fn();
        requests.push({
            options,
            abort,
            resolve: (data?: any) => deferred.resolve(data),
            reject: (responseJSON?: any, statusText = 'error') => deferred.reject({responseJSON, statusText})
        });
        return deferred.promise({abort});
    }) as any);

    const match = (url: string, fragment: string): boolean => (
        fragment.startsWith('=') ? url === fragment.slice(1) : url.includes(fragment)
    );

    return {
        requests,
        to: fragment => requests.filter(request => match(String(request.options.url), fragment)),
        last: fragment => {
            const found = requests.filter(request => match(String(request.options.url), fragment));
            if (found.length === 0) {
                throw new Error('No ajax request to "' + fragment + '"; saw: '
                    + requests.map(request => request.options.url).join(', '));
            }
            return found[found.length - 1];
        }
    };
}
