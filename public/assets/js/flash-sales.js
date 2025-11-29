/**
 * Flash Sales JavaScript
 */
(function($) {
    'use strict';

    // Initialize countdown timers
    function initCountdowns() {
        $('.jdpd-flash-countdown, .jdpd-flash-countdown-widget').each(function() {
            var $container = $(this);
            var endTime = parseInt($container.data('end'), 10) * 1000;

            if (!endTime) return;

            updateCountdown($container, endTime);

            setInterval(function() {
                updateCountdown($container, endTime);
            }, 1000);
        });
    }

    function updateCountdown($container, endTime) {
        var now = Date.now();
        var remaining = Math.max(0, endTime - now);

        if (remaining <= 0) {
            $container.find('.countdown-timer').html('<span style="color:#ff6b6b;">Sale Ended!</span>');
            return;
        }

        var days = Math.floor(remaining / (1000 * 60 * 60 * 24));
        var hours = Math.floor((remaining % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        var minutes = Math.floor((remaining % (1000 * 60 * 60)) / (1000 * 60));
        var seconds = Math.floor((remaining % (1000 * 60)) / 1000);

        // Update individual elements if they exist
        $container.find('#jdpd-days, .days').text(pad(days));
        $container.find('#jdpd-hours, .hours').text(pad(hours));
        $container.find('#jdpd-minutes, .minutes').text(pad(minutes));
        $container.find('#jdpd-seconds, .seconds').text(pad(seconds));
    }

    function pad(num) {
        return num < 10 ? '0' + num : num;
    }

    // Initialize on document ready
    $(document).ready(function() {
        initCountdowns();
    });

})(jQuery);
