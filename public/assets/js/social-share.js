/**
 * Social Share JavaScript
 *
 * @package Jezweb_Dynamic_Pricing
 * @since 1.4.0
 */

(function($) {
    'use strict';

    var JDPDSocialShare = {
        /**
         * Initialize social share functionality.
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function() {
            var self = this;

            // Share button click
            $(document).on('click', '.jdpd-share-btn', function(e) {
                e.preventDefault();

                var $btn = $(this);
                var productId = $btn.closest('.jdpd-social-share-wrap').data('product-id');
                var platform = $btn.data('platform');
                var shareUrl = $btn.attr('href');

                // Open share window
                self.openShareWindow(shareUrl, platform);

                // Track the share
                self.trackShare(productId, platform, $btn);
            });
        },

        /**
         * Open share popup window.
         *
         * @param {string} url Share URL.
         * @param {string} platform Platform name.
         */
        openShareWindow: function(url, platform) {
            // Email doesn't need popup
            if (platform === 'email') {
                window.location.href = url;
                return;
            }

            // WhatsApp on mobile
            if (platform === 'whatsapp' && /Android|webOS|iPhone|iPad|iPod/i.test(navigator.userAgent)) {
                window.location.href = url;
                return;
            }

            var width = 600;
            var height = 400;
            var left = (screen.width - width) / 2;
            var top = (screen.height - height) / 2;

            window.open(
                url,
                'share_' + platform,
                'width=' + width + ',height=' + height + ',left=' + left + ',top=' + top + ',toolbar=no,menubar=no,scrollbars=yes'
            );
        },

        /**
         * Track share event.
         *
         * @param {int} productId Product ID.
         * @param {string} platform Platform name.
         * @param {jQuery} $btn Button element.
         */
        trackShare: function(productId, platform, $btn) {
            var self = this;
            var $wrap = $btn.closest('.jdpd-social-share-wrap');

            $.ajax({
                url: jdpdSocial.ajax_url,
                type: 'POST',
                data: {
                    action: 'jdpd_track_share',
                    nonce: jdpdSocial.nonce,
                    product_id: productId,
                    platform: platform
                },
                success: function(response) {
                    if (response.success) {
                        self.showNotice(jdpdSocial.i18n.share_success, 'success');

                        // Update the promo text
                        $wrap.find('.jdpd-share-promo').fadeOut(300, function() {
                            $(this).html(
                                '<span class="jdpd-share-applied">' +
                                jdpdSocial.i18n.discount_applied +
                                ' (' + response.data.discount + '% off!)' +
                                '</span>'
                            ).fadeIn(300);
                        });

                        // Trigger price refresh if on product page
                        if (typeof wc_single_product_params !== 'undefined') {
                            $('form.variations_form').trigger('check_variations');
                        }
                    }
                },
                error: function() {
                    self.showNotice(jdpdSocial.i18n.error, 'error');
                }
            });
        },

        /**
         * Show notification.
         *
         * @param {string} message Message text.
         * @param {string} type Notice type (success/error).
         */
        showNotice: function(message, type) {
            var $notice = $('<div class="jdpd-social-notice ' + type + '">' + message + '</div>');

            $('body').append($notice);

            setTimeout(function() {
                $notice.addClass('show');
            }, 10);

            setTimeout(function() {
                $notice.removeClass('show');
                setTimeout(function() {
                    $notice.remove();
                }, 300);
            }, 4000);
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        JDPDSocialShare.init();
    });

})(jQuery);
