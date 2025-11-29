/**
 * Wishlist JavaScript
 *
 * @package Jezweb_Dynamic_Pricing
 * @since 1.4.0
 */

(function($) {
    'use strict';

    var JDPDWishlist = {
        /**
         * Initialize wishlist functionality.
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind event handlers.
         */
        bindEvents: function() {
            var self = this;

            // Add to wishlist button
            $(document).on('click', '.jdpd-wishlist-button', function(e) {
                e.preventDefault();
                var $button = $(this);
                var productId = $button.data('product-id');

                if ($button.hasClass('in-wishlist')) {
                    // Go to wishlist page
                    window.location.href = $button.data('wishlist-url');
                } else {
                    self.addToWishlist($button, productId);
                }
            });

            // Remove from wishlist
            $(document).on('click', '.jdpd-remove-wishlist', function(e) {
                e.preventDefault();
                var $link = $(this);
                var productId = $link.data('product-id');
                self.removeFromWishlist($link, productId);
            });
        },

        /**
         * Add product to wishlist.
         *
         * @param {jQuery} $button Button element.
         * @param {int} productId Product ID.
         */
        addToWishlist: function($button, productId) {
            var self = this;

            $button.addClass('loading');

            $.ajax({
                url: jdpdWishlist.ajax_url,
                type: 'POST',
                data: {
                    action: 'jdpd_add_to_wishlist',
                    nonce: jdpdWishlist.nonce,
                    product_id: productId
                },
                success: function(response) {
                    if (response.success) {
                        $button.addClass('in-wishlist');
                        $button.find('.jdpd-wishlist-icon').html('&#9829;');
                        $button.find('.jdpd-wishlist-text').text(jdpdWishlist.i18n.added);

                        // Update data attribute
                        $button.data('wishlist-url', response.data.wishlist_url);

                        // Show success message
                        self.showNotice(response.data.message, 'success');

                        // After a delay, update text to "View Wishlist"
                        setTimeout(function() {
                            $button.find('.jdpd-wishlist-text').text('View Wishlist');
                        }, 2000);
                    } else {
                        self.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    self.showNotice(jdpdWishlist.i18n.error, 'error');
                },
                complete: function() {
                    $button.removeClass('loading');
                }
            });
        },

        /**
         * Remove product from wishlist.
         *
         * @param {jQuery} $link Link element.
         * @param {int} productId Product ID.
         */
        removeFromWishlist: function($link, productId) {
            var self = this;
            var $row = $link.closest('tr');

            $row.addClass('removing');

            $.ajax({
                url: jdpdWishlist.ajax_url,
                type: 'POST',
                data: {
                    action: 'jdpd_remove_from_wishlist',
                    nonce: jdpdWishlist.nonce,
                    product_id: productId
                },
                success: function(response) {
                    if (response.success) {
                        $row.fadeOut(300, function() {
                            $(this).remove();

                            // Check if wishlist is empty
                            if ($('.jdpd-wishlist-table tbody tr').length === 0) {
                                location.reload();
                            }
                        });

                        self.showNotice(response.data.message, 'success');
                    } else {
                        $row.removeClass('removing');
                        self.showNotice(response.data.message, 'error');
                    }
                },
                error: function() {
                    $row.removeClass('removing');
                    self.showNotice(jdpdWishlist.i18n.error, 'error');
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
            var $notice = $('<div class="jdpd-wishlist-notice ' + type + '">' + message + '</div>');

            $('body').append($notice);

            setTimeout(function() {
                $notice.addClass('show');
            }, 10);

            setTimeout(function() {
                $notice.removeClass('show');
                setTimeout(function() {
                    $notice.remove();
                }, 300);
            }, 3000);
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        JDPDWishlist.init();
    });

})(jQuery);
