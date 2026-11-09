define(['jquery'], function ($) {
    'use strict';
    return function (config, element) {
        var root = $(element);
        root.on('click', '[data-confirm]', function (event) {
            if (!window.confirm($(this).attr('data-confirm'))) { event.preventDefault(); }
        });
        root.find('[data-role="category-group"]').each(function () {
            var group = $(this), select = group.find('[data-role="category-select"]'), summary = group.find('[data-role="category-summary"]');
            function render() {
                var checked = select.find('option:selected');
                summary.empty();
                if (!checked.length || checked.length === select.find('option').length) {
                    summary.append($('<span>').text(summary.attr(checked.length ? 'data-all' : 'data-empty')));
                    return;
                }
                checked.each(function () {
                    var option = this, chip = $('<span class="agent001-chip">').text($(option).attr('data-label'));
                    $('<button type="button">').attr('aria-label', summary.attr('data-remove') + ': ' + $(option).attr('data-label')).text('\u00d7')
                        .on('click', function () { option.selected = false; render(); }).appendTo(chip);
                    summary.append(chip);
                });
            }
            select.on('mousedown', 'option', function (event) {
                event.preventDefault();
                var top = select.scrollTop();
                this.selected = !this.selected;
                select.focus().scrollTop(top);
                render();
            });
            select.on('change', render);
            group.find('[data-role="category-search"]').on('input', function () {
                var query = this.value.toLocaleLowerCase();
                select.find('option').each(function () {
                    this.hidden = $(this).text().toLocaleLowerCase().indexOf(query) === -1;
                });
            });
            function pick(on) { select.find('option').prop('selected', on); render(); }
            group.find('[data-role="select-all"]').on('click', pick.bind(null, true));
            group.find('[data-role="clear-all"]').on('click', pick.bind(null, false));
            render();
        });
        function poll() {
            if (!document.contains(element) || !root.find('[data-store]').length) { return; }
            $.getJSON(config.progressUrl).done(function (data) {
                if (config.step && data.step > config.step) { window.location.reload(); return; }
                $.each(data.channels, function (id, channel) {
                    var scope = root.find('[data-store="' + id + '"]');
                    scope.find('[data-role="sync-status"]').text(channel.label).attr('data-status', channel.status);
                    scope.find('[data-role="sync-count"]').text(channel.count);
                    scope.find('[data-role="sync-message"]').text(channel.message).prop('hidden', !channel.message);
                    scope.find('[data-role="sync-date"]').text(channel.last_sync_at).prop('hidden', !channel.last_sync_at);
                    scope.find('[data-role="failed-jobs"]').text(channel.failed_jobs);
                    scope.find('[data-role="failed-row"]').prop('hidden', !channel.failed_jobs);
                    scope.find('[data-role="worker-warning"]').prop('hidden', !channel.stalled);
                    scope.find('[data-role="sync-progress"]').prop('hidden', ['queued', 'reading', 'indexing'].indexOf(channel.status) === -1);
                });
            }).always(function () { window.setTimeout(poll, 5000); });
        }
        window.setTimeout(poll, 5000);
    };
});
