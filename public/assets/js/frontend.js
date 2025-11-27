/**
 * Jezweb Dynamic Pricing - Frontend JavaScript
 */

(function($) {
    'use strict';

    var JDPD_Frontend = {
        init: function() {
            this.initQuantityChange();
            this.initModal();
            this.initCountdown();
        },

        /**
         * Handle quantity change for dynamic price updates
         */
        initQuantityChange: function() {
            var self = this;
            var $quantity = $('input.qty[name="quantity"]');
            var $priceDisplay = $('.jdpd-dynamic-price');

            if ($quantity.length === 0) {
                return;
            }

            var productId = $('[name="add-to-cart"]').val() ||
                           $('input[name="product_id"]').val() ||
                           $('.jdpd-quantity-table').data('product-id');

            if (!productId) {
                return;
            }

            // Debounce function
            var debounce = function(func, wait) {
                var timeout;
                return function() {
                    var context = this, args = arguments;
                    clearTimeout(timeout);
                    timeout = setTimeout(function() {
                        func.apply(context, args);
                    }, wait);
                };
            };

            // Update price on quantity change
            var updatePrice = debounce(function() {
                var quantity = parseInt($quantity.val()) || 1;

                $.ajax({
                    url: jdpd_frontend.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'jdpd_get_price_for_quantity',
                        nonce: jdpd_frontend.nonce,
                        product_id: productId,
                        quantity: quantity
                    },
                    success: function(response) {
                        if (response.success) {
                            self.updatePriceDisplay(response.data);
                        }
                    }
                });
            }, 300);

            $quantity.on('change input', updatePrice);

            // Also listen for WooCommerce variation changes
            $('form.variations_form').on('found_variation', function(event, variation) {
                productId = variation.variation_id;
            });
        },

        /**
         * Update price display on product page
         */
        updatePriceDisplay: function(data) {
            var $price = $('.product .price').first();

            if ($price.length) {
                $price.html(data.price_html);

                if (data.savings > 0) {
                    var $savings = $price.find('.jdpd-you-save');
                    if ($savings.length === 0) {
                        $price.append(
                            '<span class="jdpd-you-save">' +
                            jdpd_frontend.i18n.you_save + ' ' + data.savings_percent + '%' +
                            '</span>'
                        );
                    } else {
                        $savings.text(jdpd_frontend.i18n.you_save + ' ' + data.savings_percent + '%');
                    }
                }
            }

            // Update total if displayed
            var $total = $('.jdpd-total-price');
            if ($total.length && data.total_price_html) {
                $total.html(data.total_price_html);
            }

            // Highlight matching row in quantity table
            this.highlightQuantityRow(parseInt($('input.qty').val()) || 1);
        },

        /**
         * Highlight matching row in quantity table
         */
        highlightQuantityRow: function(quantity) {
            var $table = $('.jdpd-pricing-table');

            if ($table.length === 0) {
                return;
            }

            $table.find('tbody tr, thead th').removeClass('jdpd-active');

            // For horizontal table
            if ($table.closest('.jdpd-table-horizontal').length) {
                $table.find('thead th').each(function(index) {
                    if (index === 0) return; // Skip first column

                    var range = $(this).text().trim();
                    if (self.quantityInRange(quantity, range)) {
                        $(this).addClass('jdpd-active');
                        $table.find('tbody td').eq(index).addClass('jdpd-active');
                    }
                });
            }

            // For vertical table
            if ($table.closest('.jdpd-table-vertical').length) {
                $table.find('tbody tr').each(function() {
                    var range = $(this).find('td').first().text().trim();
                    if (self.quantityInRange(quantity, range)) {
                        $(this).addClass('jdpd-active');
                    }
                });
            }
        },

        /**
         * Check if quantity is in range string (e.g., "5 - 10" or "20+")
         */
        quantityInRange: function(quantity, rangeStr) {
            rangeStr = rangeStr.replace(/\s/g, '');

            if (rangeStr.indexOf('+') !== -1) {
                var min = parseInt(rangeStr);
                return quantity >= min;
            }

            if (rangeStr.indexOf('-') !== -1) {
                var parts = rangeStr.split('-');
                var min = parseInt(parts[0]);
                var max = parseInt(parts[1]);
                return quantity >= min && quantity <= max;
            }

            return false;
        },

        /**
         * Initialize modal handling
         */
        initModal: function() {
            $(document).on('click', '.jdpd-modal-close', function() {
                $(this).closest('.jdpd-modal-overlay').fadeOut();
            });

            $(document).on('click', '.jdpd-modal-overlay', function(e) {
                if (e.target === this) {
                    $(this).fadeOut();
                }
            });

            // Close on Escape key
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    $('.jdpd-modal-overlay').fadeOut();
                }
            });
        },

        /**
         * Initialize countdown timer
         */
        initCountdown: function() {
            var $countdown = $('.jdpd-countdown');

            if ($countdown.length === 0) {
                return;
            }

            var seconds = parseInt($countdown.data('seconds')) || 300;
            var $timer = $countdown.find('.jdpd-countdown-timer');
            var $container = $countdown.closest('.jdpd-checkout-deals');

            var updateDisplay = function() {
                var mins = Math.floor(seconds / 60);
                var secs = seconds % 60;
                $timer.find('.jdpd-minutes').text(String(mins).padStart(2, '0'));
                $timer.find('.jdpd-seconds').text(String(secs).padStart(2, '0'));
            };

            updateDisplay();

            var interval = setInterval(function() {
                seconds--;

                if (seconds <= 0) {
                    clearInterval(interval);
                    $container.slideUp();
                    return;
                }

                updateDisplay();

                // Add urgency styling when time is low
                if (seconds <= 60) {
                    $countdown.addClass('jdpd-urgency');
                }
            }, 1000);
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        JDPD_Frontend.init();
    });

    // Re-initialize after AJAX updates (for variable products)
    $(document).on('woocommerce_variation_has_changed', function() {
        JDPD_Frontend.initQuantityChange();
    });

})(jQuery);
