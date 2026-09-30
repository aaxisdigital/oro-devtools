import $ from 'jquery';
import {beforeEach, describe, expect, it} from 'vitest';
import Modal from 'oroui/js/modal';
import RuntimeConfigComponent from '../../../../Resources/js-src/app/components/runtime-config-component';

const FIXTURE = `
<div id="page">
    <a href="#" data-role="tab" data-target="env" class="is-active" aria-selected="true">Env <span data-role="tab-count" data-name="env"></span></a>
    <a href="#" data-role="tab" data-target="params" aria-selected="false">Params <span data-role="tab-count" data-name="params"></span></a>
    <input type="text" data-role="filter">
    <button data-role="help">?</button>
    <div data-role="panel" data-name="env">
        <div data-role="row" data-search="app_env dev">a</div>
        <div data-role="row" data-search="database_url secret">b</div>
        <div data-role="row">no search attribute</div>
        <p data-role="empty" hidden>nothing</p>
    </div>
    <div data-role="panel" data-name="params" hidden>
        <div data-role="row" data-search="kernel.debug true">c</div>
        <p data-role="empty" hidden>nothing</p>
    </div>
    <div data-role="help-content"><b>runtime help</b></div>
</div>`;

describe('RuntimeConfigComponent', () => {
    let $page: any;
    let component: any;

    beforeEach(() => {
        $page = $(FIXTURE).appendTo(document.body);
        component = new (RuntimeConfigComponent as any)({_sourceElement: $page});
    });

    const rowsOf = (panel: string) => $page.find('[data-role="panel"][data-name="' + panel + '"] [data-role="row"]');
    const count = (panel: string) => $page.find('[data-role="tab-count"][data-name="' + panel + '"]').text();

    it('shows every row and the per-tab counts on start', () => {
        expect(rowsOf('env').filter((_: number, row: HTMLElement) => row.hidden)).toHaveLength(0);
        expect(count('env')).toBe('3');
        expect(count('params')).toBe('1');
        expect($page.find('[data-role="empty"]').filter((_: number, el: HTMLElement) => !el.hidden)).toHaveLength(0);
    });

    it('filters rows case-insensitively by their data-search text and updates counts', () => {
        $page.find('[data-role="filter"]').val('  APP_ENV ').trigger('input');

        const hidden = rowsOf('env').map((_: number, row: HTMLElement) => row.hidden).get();
        expect(hidden).toEqual([false, true, true]);
        expect(count('env')).toBe('1');
        expect(count('params')).toBe('0');
    });

    it('shows the empty note of a panel with no matches only', () => {
        $page.find('[data-role="filter"]').val('secret').trigger('input');

        const emptyEnv = $page.find('[data-role="panel"][data-name="env"] [data-role="empty"]').prop('hidden');
        const emptyParams = $page.find('[data-role="panel"][data-name="params"] [data-role="empty"]').prop('hidden');
        expect(emptyEnv).toBe(true);
        expect(emptyParams).toBe(false);
    });

    it('a cleared filter restores every row', () => {
        $page.find('[data-role="filter"]').val('secret').trigger('input');
        $page.find('[data-role="filter"]').val('').trigger('input');

        expect(count('env')).toBe('3');
        expect(count('params')).toBe('1');
    });

    it('switches the active tab and only shows its panel', () => {
        const click = $.Event('click');
        $page.find('[data-role="tab"][data-target="params"]').trigger(click);

        expect(click.isDefaultPrevented()).toBe(true);
        const tabs = $page.find('[data-role="tab"]');
        expect(tabs.eq(0).hasClass('is-active')).toBe(false);
        expect(tabs.eq(0).attr('aria-selected')).toBe('false');
        expect(tabs.eq(1).hasClass('is-active')).toBe(true);
        expect(tabs.eq(1).attr('aria-selected')).toBe('true');
        expect($page.find('[data-role="panel"][data-name="env"]').prop('hidden')).toBe(true);
        expect($page.find('[data-role="panel"][data-name="params"]').prop('hidden')).toBe(false);
    });

    it('opens the help block in a close-only modal', () => {
        Modal.instances.length = 0;
        const click = $.Event('click');
        $page.find('[data-role="help"]').trigger(click);

        expect(click.isDefaultPrevented()).toBe(true);
        const modal = Modal.instances[0];
        expect(modal.options.title).toBe('aaxis.devtools.runtime_config.help');
        expect(modal.options.content).toBe('<b>runtime help</b>');
        expect(modal.options.allowOk).toBe(false);
        expect(modal.open).toHaveBeenCalled();
    });

    it('unbinds its handlers on dispose, and dispose is idempotent', () => {
        component.dispose();
        expect(component.disposed).toBe(true);

        $page.find('[data-role="filter"]').val('zzz').trigger('input');
        expect(count('env')).toBe('3');

        expect(() => component.dispose()).not.toThrow();
    });
});
