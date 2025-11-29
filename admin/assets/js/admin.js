/**
 * Jezweb Dynamic Pricing - Admin JavaScript
 */

(function($) {
    'use strict';

    var JDPD_Admin = {
        init: function() {
            this.initSelect2();
            this.initSortable();
            this.initRuleForm();
            this.initStatusToggle();
            this.initBulkActions();
            this.initDeleteConfirm();
            this.initEventSale();
            this.initColorPickers();
        },

        /**
         * Initialize Select2 for product/category/tag search
         */
        initSelect2: function() {
            // Product search
            $('.jdpd-product-search').select2({
                ajax: {
                    url: jdpd_admin.ajax_url,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            action: 'jdpd_search_products',
                            nonce: jdpd_admin.nonce,
                            q: params.term
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data.results
                        };
                    }
                },
                minimumInputLength: 2,
                placeholder: jdpd_admin.i18n.search_products
            });

            // Category search
            $('.jdpd-category-search').select2({
                ajax: {
                    url: jdpd_admin.ajax_url,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            action: 'jdpd_search_categories',
                            nonce: jdpd_admin.nonce,
                            q: params.term
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data.results
                        };
                    }
                },
                minimumInputLength: 1,
                placeholder: jdpd_admin.i18n.search_categories
            });

            // Tag search
            $('.jdpd-tag-search').select2({
                ajax: {
                    url: jdpd_admin.ajax_url,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            action: 'jdpd_search_tags',
                            nonce: jdpd_admin.nonce,
                            q: params.term
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data.results
                        };
                    }
                },
                minimumInputLength: 1,
                placeholder: jdpd_admin.i18n.search_tags
            });

            // User search
            $('.jdpd-user-search').select2({
                ajax: {
                    url: jdpd_admin.ajax_url,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            action: 'jdpd_search_users',
                            nonce: jdpd_admin.nonce,
                            q: params.term
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data.results
                        };
                    }
                },
                minimumInputLength: 2,
                placeholder: jdpd_admin.i18n.search_users
            });
        },

        /**
         * Initialize sortable for rules list
         */
        initSortable: function() {
            $('#the-list').sortable({
                handle: '.jdpd-drag-handle',
                axis: 'y',
                update: function(event, ui) {
                    var order = [];
                    $('#the-list tr').each(function() {
                        order.push($(this).data('rule-id'));
                    });

                    $.ajax({
                        url: jdpd_admin.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'jdpd_reorder_rules',
                            nonce: jdpd_admin.nonce,
                            order: order
                        }
                    });
                }
            });
        },

        /**
         * Initialize rule form functionality
         */
        initRuleForm: function() {
            var self = this;

            // Rule type change
            $('#rule_type').on('change', function() {
                self.toggleRuleTypeFields($(this).val());
            });

            // Apply to change
            $('#apply_to').on('change', function() {
                self.toggleApplyToFields($(this).val());
            });

            // Discount type change
            $('#discount_type').on('change', function() {
                self.updateDiscountSuffix($(this).val());
            });

            // Add quantity range
            $('#jdpd-add-range').on('click', function() {
                self.addQuantityRange();
            });

            // Remove quantity range
            $(document).on('click', '.jdpd-remove-range', function() {
                $(this).closest('tr').remove();
            });

            // Add gift product
            $('#jdpd-add-gift').on('click', function() {
                self.addGiftProduct();
            });

            // Remove gift product
            $(document).on('click', '.jdpd-remove-gift', function() {
                $(this).closest('tr').remove();
            });

            // Add condition
            $('#jdpd-add-condition').on('click', function() {
                self.addCondition();
            });

            // Remove condition
            $(document).on('click', '.jdpd-remove-condition', function() {
                $(this).closest('.jdpd-condition-row').remove();
            });

            // Initialize on load
            this.toggleRuleTypeFields($('#rule_type').val());
            this.toggleApplyToFields($('#apply_to').val());
            this.updateDiscountSuffix($('#discount_type').val());
        },

        /**
         * Toggle fields based on rule type
         */
        toggleRuleTypeFields: function(ruleType) {
            var $quantityRanges = $('#jdpd-quantity-ranges');
            var $specialOffer = $('#jdpd-special-offer-settings');
            var $giftProducts = $('#jdpd-gift-products-settings');
            var $discountRow = $('.jdpd-discount-type-row, .jdpd-discount-value-row');

            // Hide all first
            $quantityRanges.hide();
            $specialOffer.hide();
            $giftProducts.hide();
            $discountRow.show();

            switch (ruleType) {
                case 'price_rule':
                    $quantityRanges.show();
                    break;
                case 'cart_rule':
                    // Show basic discount fields
                    break;
                case 'special_offer':
                    $specialOffer.show();
                    $discountRow.hide();
                    break;
                case 'gift':
                    $giftProducts.show();
                    $discountRow.hide();
                    break;
            }
        },

        /**
         * Toggle fields based on apply to selection
         */
        toggleApplyToFields: function(applyTo) {
            $('.jdpd-products-row, .jdpd-categories-row, .jdpd-tags-row').hide();

            switch (applyTo) {
                case 'specific_products':
                    $('.jdpd-products-row').show();
                    break;
                case 'categories':
                    $('.jdpd-categories-row').show();
                    break;
                case 'tags':
                    $('.jdpd-tags-row').show();
                    break;
            }
        },

        /**
         * Update discount suffix based on type
         */
        updateDiscountSuffix: function(discountType) {
            var $suffix = $('.jdpd-discount-suffix');

            switch (discountType) {
                case 'percentage':
                    $suffix.text('%');
                    break;
                case 'fixed':
                case 'fixed_price':
                    $suffix.text(jdpd_admin.currency_symbol);
                    break;
            }
        },

        /**
         * Add quantity range row
         */
        addQuantityRange: function() {
            var index = $('#jdpd-ranges-body tr').length;
            var template = wp.template('jdpd-quantity-range');
            var html = template({ index: index });
            $('#jdpd-ranges-body').append(html);
        },

        /**
         * Add gift product row
         */
        addGiftProduct: function() {
            var index = $('#jdpd-gifts-body tr').length;
            var template = wp.template('jdpd-gift-product');
            var html = template({ index: index });
            $('#jdpd-gifts-body').append(html);

            // Initialize Select2 on new row
            $('#jdpd-gifts-body tr:last .jdpd-product-search').select2({
                ajax: {
                    url: jdpd_admin.ajax_url,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            action: 'jdpd_search_products',
                            nonce: jdpd_admin.nonce,
                            q: params.term
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data.results
                        };
                    }
                },
                minimumInputLength: 2,
                placeholder: jdpd_admin.i18n.search_products
            });
        },

        /**
         * Add condition row
         */
        addCondition: function() {
            var index = $('#jdpd-conditions .jdpd-condition-row').length;
            var template = wp.template('jdpd-condition');
            var html = template({ index: index });
            $('#jdpd-conditions').append(html);
        },

        /**
         * Initialize status toggle
         */
        initStatusToggle: function() {
            $(document).on('change', '.jdpd-toggle-status', function() {
                var $toggle = $(this);
                var ruleId = $toggle.data('rule-id');

                $.ajax({
                    url: jdpd_admin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'jdpd_toggle_rule_status',
                        nonce: jdpd_admin.nonce,
                        rule_id: ruleId
                    },
                    success: function(response) {
                        if (!response.success) {
                            $toggle.prop('checked', !$toggle.prop('checked'));
                        }
                    },
                    error: function() {
                        $toggle.prop('checked', !$toggle.prop('checked'));
                    }
                });
            });
        },

        /**
         * Initialize bulk actions
         */
        initBulkActions: function() {
            // Select all checkbox
            $('#cb-select-all').on('change', function() {
                $('input[name="rule_ids[]"]').prop('checked', $(this).prop('checked'));
            });

            // Bulk action button
            $('#doaction').on('click', function(e) {
                e.preventDefault();

                var action = $('#bulk-action-selector').val();
                var ruleIds = [];

                $('input[name="rule_ids[]"]:checked').each(function() {
                    ruleIds.push($(this).val());
                });

                if (!action || ruleIds.length === 0) {
                    return;
                }

                if (action === 'delete' && !confirm(jdpd_admin.i18n.confirm_bulk_delete)) {
                    return;
                }

                $.ajax({
                    url: jdpd_admin.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'jdpd_bulk_action',
                        nonce: jdpd_admin.nonce,
                        bulk_action: action,
                        rule_ids: ruleIds
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert(response.data.message || jdpd_admin.i18n.error);
                        }
                    }
                });
            });
        },

        /**
         * Initialize delete confirmation
         */
        initDeleteConfirm: function() {
            $(document).on('click', '.jdpd-delete-rule', function(e) {
                if (!confirm(jdpd_admin.i18n.confirm_delete)) {
                    e.preventDefault();
                }
            });
        },

        /**
         * Initialize event sale functionality
         */
        initEventSale: function() {
            var self = this;

            // Special offer type change - show/hide event sale settings
            $('#special_offer_type').on('change', function() {
                self.toggleEventSaleSettings($(this).val());
            });

            // Event type change - show event info
            $('#event_type').on('change', function() {
                self.showEventInfo($(this));
                self.updateBadgePreview();
            });

            // Custom event name change - update badge preview
            $('#custom_event_name').on('input', function() {
                self.updateBadgePreview();
            });

            // Event discount type change - update suffix
            $('#event_discount_type').on('change', function() {
                self.updateEventDiscountSuffix($(this).val());
            });

            // Initialize on load
            this.toggleEventSaleSettings($('#special_offer_type').val());
        },

        /**
         * Toggle event sale settings visibility
         */
        toggleEventSaleSettings: function(offerType) {
            var $eventSettings = $('#jdpd-event-sale-settings');
            var $buyGetFields = $('#special_offer_type').closest('.jdpd-form-fields').find('.jdpd-form-row').not(':first').not('.jdpd-event-sale-settings .jdpd-form-row');

            if (offerType === 'event_sale') {
                $eventSettings.show();
                // Hide Buy X Get Y fields for event sale
                $buyGetFields.slice(0, 3).hide();
            } else {
                $eventSettings.hide();
                // Show Buy X Get Y fields for other offer types
                $buyGetFields.slice(0, 3).show();
            }
        },

        /**
         * Show event info when event is selected
         */
        showEventInfo: function($select) {
            var $selected = $select.find('option:selected');
            var eventType = $select.val();
            var month = $selected.data('month');
            var categories = $selected.data('categories');
            var $infoRow = $('#event-info-row');
            var $customNameRow = $('#custom-event-name-row');

            // Show/hide custom event name field
            if (eventType === 'custom') {
                $customNameRow.show();
                $infoRow.hide();
            } else if (month && categories) {
                $customNameRow.hide();
                var monthNames = [
                    '', 'January', 'February', 'March', 'April', 'May', 'June',
                    'July', 'August', 'September', 'October', 'November', 'December'
                ];
                $('#event-month').text(monthNames[month]);
                $('#event-categories').text(categories);
                $infoRow.show();
            } else {
                $customNameRow.hide();
                $infoRow.hide();
            }
        },

        /**
         * Update badge preview
         */
        updateBadgePreview: function() {
            var $select = $('#event_type');
            var $selected = $select.find('option:selected');
            var eventType = $select.val();
            var $preview = $('#event-badge-preview');
            var badgeText = '';

            if (eventType === 'custom') {
                badgeText = $('#custom_event_name').val() || 'Custom Event';
            } else if (eventType) {
                badgeText = $selected.text();
            }

            if (badgeText) {
                $preview.text(badgeText).show();
            } else {
                $preview.hide();
            }
        },

        /**
         * Update event discount suffix based on type
         */
        updateEventDiscountSuffix: function(discountType) {
            var $suffix = $('#event-discount-suffix');

            if (discountType === 'percentage') {
                $suffix.text('%');
            } else {
                $suffix.text(jdpd_admin.currency_symbol || '$');
            }
        },

        /**
         * Initialize color pickers for badge styling
         */
        initColorPickers: function() {
            var self = this;

            // Background color picker
            $('#jdpd_event_badge_bg_color').on('input', function() {
                var color = $(this).val();
                $('#jdpd_event_badge_bg_color_text').val(color);
                self.updateBadgeLivePreview();
            });

            // Text color picker
            $('#jdpd_event_badge_text_color').on('input', function() {
                var color = $(this).val();
                $('#jdpd_event_badge_text_color_text').val(color);
                self.updateBadgeLivePreview();
            });
        },

        /**
         * Update badge live preview
         */
        updateBadgeLivePreview: function() {
            var bgColor = $('#jdpd_event_badge_bg_color').val();
            var textColor = $('#jdpd_event_badge_text_color').val();
            var $preview = $('#jdpd-badge-live-preview');

            if ($preview.length) {
                $preview.css({
                    'background-color': bgColor,
                    'color': textColor
                });
            }
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        JDPD_Admin.init();
    });

})(jQuery);
