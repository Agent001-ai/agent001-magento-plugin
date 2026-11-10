define(['jquery'], function ($) {
    'use strict';

    return function (config, element) {
        var root = $(element),
            poll;

        root.on('click', '[data-confirm]', function (event) {
            if (!window.confirm($(this).attr('data-confirm'))) {
                event.preventDefault();
            }
        });
        root.find('[data-role="category-group"]').each(function () {
            var group = $(this),
                select = group.find('[data-role="category-select"]'),
                summary = group.find('[data-role="category-summary"]'),
                render,
                pick,
                cascade;

            render = function () {
                var checked = select.find('option:selected');

                summary.empty();
                if (!checked.length || checked.length === select.find('option').length) {
                    summary.append($('<span>').text(summary.attr(checked.length ? 'data-all' : 'data-empty')));

                    return;
                }
                checked.each(function () {
                    var option = this,
                        label = $(option).attr('data-label'),
                        chip = $('<span class="agent001-chip">').text(label);

                    $('<button type="button">')
                        .attr('aria-label', summary.attr('data-remove') + ': ' + label)
                        .text('×')
                        .on('click', function () {
                            option.selected = false;
                            cascade(option);
                            render();
                        })
                        .appendTo(chip);
                    summary.append(chip);
                });
            };
            cascade = function (option) {
                var prefix = $(option).attr('data-path') + '/';

                select.find('option').each(function () {
                    if (($(this).attr('data-path') || '').indexOf(prefix) === 0) {
                        this.selected = option.selected;
                    }
                });
            };
            pick = function (on) {
                select.find('option').prop('selected', on);
                render();
            };
            select.on('mousedown', 'option', function (event) {
                var top = select.scrollTop();

                event.preventDefault();
                this.selected = !this.selected;
                cascade(this);
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
            group.find('[data-role="select-all"]').on('click', pick.bind(null, true));
            group.find('[data-role="clear-all"]').on('click', pick.bind(null, false));
            render();
        });
        poll = function () {
            if (!document.contains(element) || !root.find('[data-store]').length) {
                return;
            }
            $.getJSON(config.progressUrl).done(function (data) {
                if (config.step && data.step > config.step) {
                    window.location.reload();

                    return;
                }
                $.each(data.channels, function (id, channel) {
                    var scope = root.find('[data-store="' + id + '"]'),
                        running = ['queued', 'reading', 'indexing'].indexOf(channel.status) !== -1;

                    scope.find('[data-role="sync-status"]').text(channel.label).attr('data-status', channel.status);
                    scope.find('[data-role="sync-count"]').text(channel.count);
                    scope.find('[data-role="sync-skipped"]').text(channel.skipped);
                    scope.find('[data-role="skipped-row"]').prop('hidden', !channel.skipped);
                    scope.find('[data-role="sync-message"]').text(channel.message).prop('hidden', !channel.message);
                    scope.find('[data-role="sync-date"]')
                        .text(channel.last_sync_at)
                        .prop('hidden', !channel.last_sync_at);
                    scope.find('[data-role="failed-jobs"]').text(channel.failed_jobs);
                    scope.find('[data-role="failed-row"]').prop('hidden', !channel.failed_jobs);
                    scope.find('[data-role="worker-warning"]').prop('hidden', !channel.stalled);
                    scope.find('[data-role="sync-progress"]').prop('hidden', !running);
                });
            }).always(function () {
                window.setTimeout(poll, 5000);
            });
        };
        window.setTimeout(poll, 5000);
    };
});
