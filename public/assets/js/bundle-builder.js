/**
 * Bundle Builder JavaScript
 *
 * @package Jezweb_Dynamic_Pricing
 * @since 1.4.0
 */

(function($) {
    'use strict';

    var JDPDBundleBuilder = {
        /**
         * Initialize bundle builders on the page.
         */
        init: function() {
            var self = this;

            $('.jdpd-bundle-builder').each(function() {
                self.initBundle($(this));
            });
        },

        /**
         * Initialize a single bundle builder.
         *
         * @param {jQuery} $bundle Bundle element.
         */
        initBundle: function($bundle) {
            var self = this;
            var bundleId = $bundle.data('bundle-id');

            // Quantity buttons
            $bundle.on('click', '.jdpd-qty-minus', function(e) {
                e.preventDefault();
                var $input = $(this).siblings('.jdpd-bundle-qty');
                var val = parseInt($input.val(), 10) || 0;
                if (val > 0) {
                    $input.val(val - 1).trigger('change');
                }
            });

            $bundle.on('click', '.jdpd-qty-plus', function(e) {
                e.preventDefault();
                var $input = $(this).siblings('.jdpd-bundle-qty');
                var val = parseInt($input.val(), 10) || 0;
                var max = parseInt($input.attr('max'), 10) || 99;
                if (val < max) {
                    $input.val(val + 1).trigger('change');
                }
            });

            // Quantity change
            $bundle.on('change', '.jdpd-bundle-qty', function() {
                self.updateBundle($bundle);
            });

            // Add to cart
            $bundle.on('click', '.jdpd-add-bundle-to-cart', function(e) {
                e.preventDefault();
                self.addToCart($bundle);
            });
        },

        /**
         * Get selected items from bundle.
         *
         * @param {jQuery} $bundle Bundle element.
         * @return {Array} Selected items.
         */
        getSelectedItems: function($bundle) {
            var items = [];

            $bundle.find('.jdpd-bundle-product').each(function() {
                var $product = $(this);
                var productId = $product.data('product-id');
                var quantity = parseInt($product.find('.jdpd-bundle-qty').val(), 10) || 0;

                if (quantity > 0) {
                    items.push({
                        product_id: productId,
                        quantity: quantity
                    });
                }

                // Update selected state
                $product.toggleClass('selected', quantity > 0);
            });

            return items;
        },

        /**
         * Update bundle display and pricing.
         *
         * @param {jQuery} $bundle Bundle element.
         */
        updateBundle: function($bundle) {
            var self = this;
            var bundleId = $bundle.data('bundle-id');
            var items = this.getSelectedItems($bundle);

            // Update selected count
            var totalQty = items.reduce(function(sum, item) {
                return sum + item.quantity;
            }, 0);

            $bundle.find('.jdpd-selected-count').text(totalQty);

            // Calculate pricing via AJAX
            if (items.length > 0) {
                $.ajax({
                    url: jdpdBundle.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'jdpd_calculate_bundle_price',
                        nonce: jdpdBundle.nonce,
                        bundle_id: bundleId,
                        items: items
                    },
                    success: function(response) {
                        if (response.success) {
                            self.updatePricing($bundle, response.data);
                        }
                    }
                });
            } else {
                this.resetPricing($bundle);
            }
        },

        /**
         * Update pricing display.
         *
         * @param {jQuery} $bundle Bundle element.
         * @param {Object} data Pricing data.
         */
        updatePricing: function($bundle, data) {
            var $button = $bundle.find('.jdpd-add-bundle-to-cart');
            var $message = $bundle.find('.jdpd-bundle-message');

            // Update prices
            $bundle.find('.jdpd-bundle-original-price .value').html(data.original_total_formatted);
            $bundle.find('.jdpd-bundle-total-price .value').html(data.bundle_price_formatted);
            $bundle.find('.jdpd-bundle-savings .value').html(
                data.savings_formatted + ' (' + data.savings_percent + '%)'
            );

            // Update button and message
            if (data.is_valid) {
                $button.prop('disabled', false);
                $message.removeClass('error').addClass('success').text('');
            } else {
                $button.prop('disabled', true);
                $message.removeClass('success').addClass('error').text(data.error_message);
            }
        },

        /**
         * Reset pricing display.
         *
         * @param {jQuery} $bundle Bundle element.
         */
        resetPricing: function($bundle) {
            $bundle.find('.jdpd-bundle-original-price .value').text('—');
            $bundle.find('.jdpd-bundle-total-price .value').text('—');
            $bundle.find('.jdpd-bundle-savings .value').text('—');
            $bundle.find('.jdpd-add-bundle-to-cart').prop('disabled', true);
            $bundle.find('.jdpd-bundle-message').removeClass('error success').text('');
        },

        /**
         * Add bundle to cart.
         *
         * @param {jQuery} $bundle Bundle element.
         */
        addToCart: function($bundle) {
            var self = this;
            var bundleId = $bundle.data('bundle-id');
            var items = this.getSelectedItems($bundle);
            var $button = $bundle.find('.jdpd-add-bundle-to-cart');
            var $message = $bundle.find('.jdpd-bundle-message');

            if (items.length === 0) {
                return;
            }

            $button.prop('disabled', true).text(jdpdBundle.i18n.adding);

            $.ajax({
                url: jdpdBundle.ajax_url,
                type: 'POST',
                data: {
                    action: 'jdpd_add_bundle_to_cart',
                    nonce: jdpdBundle.nonce,
                    bundle_id: bundleId,
                    items: items
                },
                success: function(response) {
                    if (response.success) {
                        $message.removeClass('error').addClass('success').text(response.data.message);

                        // Update cart fragments if available
                        if (response.data.fragments) {
                            $.each(response.data.fragments, function(key, value) {
                                $(key).replaceWith(value);
                            });
                        }

                        // Trigger WooCommerce event
                        $(document.body).trigger('wc_fragment_refresh');
                        $(document.body).trigger('added_to_cart');

                        // Reset bundle
                        setTimeout(function() {
                            $bundle.find('.jdpd-bundle-qty').val(0).trigger('change');
                        }, 2000);
                    } else {
                        $message.removeClass('success').addClass('error').text(response.data.message);
                    }
                },
                error: function() {
                    $message.removeClass('success').addClass('error').text(jdpdBundle.i18n.error);
                },
                complete: function() {
                    $button.prop('disabled', false).text($button.data('original-text') || 'Add Bundle to Cart');
                }
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        JDPDBundleBuilder.init();
    });

})(jQuery);
