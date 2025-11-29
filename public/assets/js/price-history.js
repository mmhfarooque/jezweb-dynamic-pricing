/**
 * Price History JavaScript
 *
 * @package Jezweb_Dynamic_Pricing
 * @since 1.4.0
 */

(function($) {
    'use strict';

    var JDPDPriceHistory = {
        charts: {},

        /**
         * Initialize price history charts.
         */
        init: function() {
            var self = this;

            $('.jdpd-price-history-wrap').each(function() {
                var $wrap = $(this);
                var productId = $wrap.data('product-id');
                var days = $wrap.data('days') || 90;

                self.loadChartData(productId, days, $wrap);
            });

            // Handle variation changes
            $(document).on('found_variation', function(event, variation) {
                var productId = variation.variation_id;
                var $wrap = $('.jdpd-price-history-wrap');

                if ($wrap.length) {
                    var days = $wrap.data('days') || 90;
                    self.loadChartData($wrap.data('product-id'), days, $wrap, variation.variation_id);
                }
            });
        },

        /**
         * Load chart data via AJAX.
         *
         * @param {int} productId Product ID.
         * @param {int} days Number of days.
         * @param {jQuery} $wrap Container element.
         * @param {int} variationId Optional variation ID.
         */
        loadChartData: function(productId, days, $wrap, variationId) {
            var self = this;

            $.ajax({
                url: jdpdPriceHistory.ajax_url,
                type: 'POST',
                data: {
                    action: 'jdpd_get_price_history',
                    nonce: jdpdPriceHistory.nonce,
                    product_id: productId,
                    variation_id: variationId || 0,
                    days: days
                },
                success: function(response) {
                    if (response.success && response.data.labels.length > 0) {
                        self.renderChart(productId, response.data, $wrap);
                    } else {
                        self.showNoData($wrap);
                    }
                },
                error: function() {
                    self.showNoData($wrap);
                }
            });
        },

        /**
         * Render price history chart.
         *
         * @param {int} productId Product ID.
         * @param {Object} data Chart data.
         * @param {jQuery} $wrap Container element.
         */
        renderChart: function(productId, data, $wrap) {
            var self = this;
            var canvasId = 'jdpd-price-chart-' + productId;
            var canvas = document.getElementById(canvasId);

            if (!canvas) {
                return;
            }

            // Destroy existing chart
            if (this.charts[canvasId]) {
                this.charts[canvasId].destroy();
            }

            var ctx = canvas.getContext('2d');

            // Parse colors
            var lineColor = jdpdPriceHistory.line_color || '#2271b1';
            var fillColor = jdpdPriceHistory.fill_color || '#e8f4fc';

            this.charts[canvasId] = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: jdpdPriceHistory.i18n.price,
                        data: data.prices,
                        borderColor: lineColor,
                        backgroundColor: fillColor,
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        pointBackgroundColor: lineColor
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return jdpdPriceHistory.currency_symbol + context.parsed.y.toFixed(2);
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            display: true,
                            grid: {
                                display: false
                            },
                            ticks: {
                                maxRotation: 45,
                                minRotation: 0,
                                maxTicksLimit: 8
                            }
                        },
                        y: {
                            display: true,
                            beginAtZero: false,
                            grid: {
                                color: 'rgba(0,0,0,0.05)'
                            },
                            ticks: {
                                callback: function(value) {
                                    return jdpdPriceHistory.currency_symbol + value.toFixed(2);
                                }
                            }
                        }
                    },
                    interaction: {
                        intersect: false,
                        mode: 'index'
                    }
                }
            });

            // Update stats
            if (data.lowest) {
                $wrap.find('.jdpd-price-low strong').next().text(
                    jdpdPriceHistory.currency_symbol + parseFloat(data.lowest).toFixed(2)
                );
            }
            if (data.highest) {
                $wrap.find('.jdpd-price-high strong').next().text(
                    jdpdPriceHistory.currency_symbol + parseFloat(data.highest).toFixed(2)
                );
            }
        },

        /**
         * Show no data message.
         *
         * @param {jQuery} $wrap Container element.
         */
        showNoData: function($wrap) {
            $wrap.find('.jdpd-price-chart-container').html(
                '<p class="jdpd-no-history">' + jdpdPriceHistory.i18n.no_data + '</p>'
            );
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        // Wait for Chart.js to load
        if (typeof Chart !== 'undefined') {
            JDPDPriceHistory.init();
        } else {
            // Retry after a short delay
            setTimeout(function() {
                JDPDPriceHistory.init();
            }, 500);
        }
    });

})(jQuery);
