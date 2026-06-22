import $ from 'jquery';
import __ from 'orotranslation/js/translator';
import Modal from 'oroui/js/modal';
import BaseComponent from 'oroui/js/app/components/base/component';

/**
 * Runtime Config view: tabbed sections (Environment / Application Parameters / PHP Runtime) with
 * live client-side filtering. Each tab shows the count of items it currently contains, updated as
 * the filter narrows results; the active tab's panel shows a "no results" note when empty.
 */
class RuntimeConfigComponent extends BaseComponent {
    initialize(options) {
        this.$el = options._sourceElement;
        this.$tabs = this.$el.find('[data-role="tab"]');
        this.$panels = this.$el.find('[data-role="panel"]');

        this.$el.on('click.aaxisRtCfg', '[data-role="tab"]', this.onTab.bind(this));
        this.$el.on('input.aaxisRtCfg', '[data-role="filter"]', this.onFilter.bind(this));
        this.$el.on('click.aaxisRtCfg', '[data-role="help"]', this.onHelp.bind(this));

        this.applyFilter('');
    }

    onHelp(event) {
        event.preventDefault();
        const modal = new Modal({
            title: __('aaxis.devtools.runtime_config.help'),
            content: this.$el.find('[data-role="help-content"]').html(),
            allowOk: false,
            cancelText: __('Close')
        });
        modal.open();
    }

    onTab(event) {
        event.preventDefault();
        const target = $(event.currentTarget).data('target');

        this.$tabs.each((i, tab) => {
            const active = $(tab).data('target') === target;
            $(tab).toggleClass('is-active', active).attr('aria-selected', active ? 'true' : 'false');
        });
        this.$panels.each((i, panel) => {
            panel.hidden = $(panel).data('name') !== target;
        });
    }

    onFilter(event) {
        this.applyFilter(String($(event.currentTarget).val() || '').trim().toLowerCase());
    }

    applyFilter(term) {
        this.$panels.each((i, panel) => {
            const name = $(panel).data('name');
            const $rows = $(panel).find('[data-role="row"]');
            let visible = 0;

            $rows.each((j, row) => {
                const match = term === '' || (row.getAttribute('data-search') || '').indexOf(term) !== -1;
                row.hidden = !match;
                if (match) {
                    visible++;
                }
            });

            // Update this tab's count badge and the panel's empty-state note.
            this.$el.find('[data-role="tab-count"][data-name="' + name + '"]').text(visible);
            $(panel).find('[data-role="empty"]').prop('hidden', visible !== 0);
        });
    }

    dispose() {
        if (this.disposed) {
            return;
        }
        this.$el.off('.aaxisRtCfg');
        super.dispose();
    }
}

export default RuntimeConfigComponent;
