import $ from 'jquery';
import {vi} from 'vitest';
import AbstractQueryViewerComponent
    from '../../../Resources/js-src/app/components/abstract-query-viewer-component';

/** Spies for the subclass hooks; reset by the global `vi.clearAllMocks()` before every test. */
export const hooks = {
    setupSidebar: vi.fn(),
    loadSidebar: vi.fn(),
    renderSidebar: vi.fn(),
    bindSidebarEvents: vi.fn(),
    updateOrderButtons: vi.fn(),
    afterSetup: vi.fn(),
    disposeSidebar: vi.fn()
};

/** Minimal concrete viewer: the abstract class supplies everything except these naming/sidebar hooks. */
export class TestViewer extends AbstractQueryViewerComponent {
    protected cssPrefix(): string {
        return 'tv';
    }

    protected transPrefix(): string {
        return 'test.viewer';
    }

    protected eventNs(): string {
        return 'testViewer';
    }

    protected storagePrefix(): string {
        return 'tv-result:';
    }

    protected editorMode(): string {
        return 'text/x-test';
    }

    protected formatQuery(raw: string): string {
        if (raw.includes('BOOM')) {
            throw new Error('cannot format');
        }
        return raw.toUpperCase() + ' /*formatted*/';
    }

    protected setupSidebar(options: any): void {
        hooks.setupSidebar(options);
        this.sortDir = 'asc';
    }

    protected loadSidebar(): void {
        hooks.loadSidebar();
    }

    protected renderSidebar(): void {
        hooks.renderSidebar();
    }

    protected sidebarListRole(): string {
        return 'objects';
    }

    protected bindSidebarEvents(): void {
        hooks.bindSidebarEvents();
    }

    protected updateOrderButtons(): void {
        hooks.updateOrderButtons();
        this.renderOrderDirection();
    }

    protected afterSetup(): void {
        hooks.afterSetup();
    }

    protected disposeSidebar(): void {
        hooks.disposeSidebar();
    }
}

export const QUERY_VIEWER_FIXTURE = `
<div id="viewer">
    <button data-role="help">?</button>
    <div data-role="help-content"><p>query help</p></div>
    <aside data-role="sidebar">
        <input type="text" data-role="filter" value="">
        <button data-role="filter-clear" hidden>x</button>
        <button data-role="refresh">refresh</button>
        <button data-role="type-toggle" data-type="table" class="is-active" aria-pressed="true">tables</button>
        <button data-role="type-toggle" data-type="view" class="is-active" aria-pressed="true">views</button>
        <button data-role="type-toggle" data-type="function" class="is-active" aria-pressed="true">functions</button>
        <button data-role="order-key"><span class="fa fa-font"></span></button>
        <button data-role="order-dir"><span class="fa fa-sort-amount-asc"></span></button>
        <ul data-role="objects"></ul>
        <ul data-role="indices"></ul>
    </aside>
    <div data-role="v-splitter"></div>
    <div data-role="editor-pane">
        <div data-role="tabs-list"></div>
        <button data-role="tab-add">+</button>
        <textarea data-role="editor">SELECT 1</textarea>
        <button data-role="beautify">beautify</button>
        <ul data-role="queries-menu"></ul>
        <button data-role="update" hidden>update</button>
        <span data-role="status"></span>
        <button data-role="export" disabled>export</button>
        <a href="#" data-role="export-format" data-format="csv">CSV</a>
        <a href="#" data-role="export-format" data-format="json">JSON</a>
        <button data-role="run"><span data-role="run-spinner" style="display: none"></span>run</button>
    </div>
    <div data-role="h-splitter"></div>
    <div data-role="result"></div>
</div>`;

export function mountViewer(fixture: string = QUERY_VIEWER_FIXTURE): any {
    return $(fixture).appendTo(document.body);
}

const created: any[] = [];

export function createViewer($el: any, options: Record<string, unknown> = {}, viewerClass: any = TestViewer): any {
    const viewer = new viewerClass({
        _sourceElement: $el,
        queryUrl: '/query',
        exportUrl: '/export',
        savedQueriesUrl: '/saved',
        ...options
    });
    created.push(viewer);
    return viewer;
}

/** Call from afterEach: viewers bind on window/document, which outlive the per-test body reset. */
export function disposeCreatedViewers(): void {
    created.splice(0).forEach(viewer => viewer.dispose());
}
