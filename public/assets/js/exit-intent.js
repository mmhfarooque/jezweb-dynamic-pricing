/**
 * Exit Intent Popup JavaScript
 */
(function($) {
    'use strict';

    var exitIntentEnabled = false;
    var popupShown = false;
    var settings = window.jdpdExitIntent || {};

    function init() {
        // Wait for delay before enabling
        setTimeout(function() {
            exitIntentEnabled = true;
            initDesktopTrigger();
            initMobileTrigger();
        }, settings.delay || 3000);

        // Bind events
        bindEvents();
    }

    function initDesktopTrigger() {
        if (isMobile()) return;

        $(document).on('mouseleave', function(e) {
            if (!exitIntentEnabled || popupShown) return;

            var sensitivity = settings.sensitivity || 20;
            if (e.clientY < sensitivity) {
                showPopup();
            }
        });
    }

    function initMobileTrigger() {
        if (!isMobile() || !settings.mobileEnabled) return;

        var trigger = settings.mobileTrigger || 'scroll';

        if (trigger === 'scroll') {
            var triggered = false;
            var scrollPct = settings.mobileScrollPct || 50;

            $(window).on('scroll', function() {
                if (triggered || popupShown) return;

                var scrollTop = $(window).scrollTop();
                var docHeight = $(document).height() - $(window).height();
                var scrollPercent = (scrollTop / docHeight) * 100;

                if (scrollPercent >= scrollPct) {
                    triggered = true;
                    // Show when user scrolls back up
                    $(window).on('scroll.exitIntent', function() {
                        if ($(window).scrollTop() < scrollTop - 100) {
                            $(window).off('scroll.exitIntent');
                            showPopup();
                        }
                    });
                }
            });
        } else if (trigger === 'time') {
            var timeSec = settings.mobileTimeSec || 30;
            setTimeout(function() {
                if (!popupShown) {
                    showPopup();
                }
            }, timeSec * 1000);
        }
    }

    function showPopup() {
        var $popup = $('#jdpd-exit-popup');

        if ($popup.length === 0 || popupShown) return;

        popupShown = true;
        $popup.addClass('active');

        // Animate in
        $popup.find('.exit-popup-content').css({
            'animation': 'popup-slide-in 0.4s ease'
        });
    }

    function hidePopup() {
        var $popup = $('#jdpd-exit-popup');
        $popup.removeClass('active');

        // Track dismissal
        $.post(settings.ajaxUrl, {
            action: 'jdpd_dismiss_exit_offer',
            nonce: settings.nonce
        });
    }

    function bindEvents() {
        // Close button
        $(document).on('click', '.exit-popup-close', function(e) {
            e.preventDefault();
            hidePopup();
        });

        // Overlay click
        $(document).on('click', '.exit-popup-overlay', function(e) {
            hidePopup();
        });

        // Dismiss link
        $(document).on('click', '.exit-popup-dismiss', function(e) {
            e.preventDefault();
            hidePopup();
        });

        // CTA button - track conversion
        $(document).on('click', '.exit-popup-button', function() {
            var offerId = $('#jdpd-exit-popup').data('offer-id');

            if (offerId) {
                $.post(settings.ajaxUrl, {
                    action: 'jdpd_track_exit_conversion',
                    nonce: settings.nonce,
                    offer_id: offerId
                });
            }
        });

        // Escape key
        $(document).on('keyup', function(e) {
            if (e.key === 'Escape') {
                hidePopup();
            }
        });
    }

    function isMobile() {
        return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    }

    // Initialize
    $(document).ready(init);

})(jQuery);
