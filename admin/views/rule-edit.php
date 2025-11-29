<?php
/**
 * Admin Rule Edit View
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$rule_id = isset( $_GET['rule_id'] ) ? absint( $_GET['rule_id'] ) : 0;
$rule = $rule_id > 0 ? new JDPD_Rule( $rule_id ) : null;
$is_edit = $rule && $rule->get_id() > 0;

// Get existing data for edit
$rule_name = $is_edit ? $rule->get( 'name' ) : '';
$rule_type = $is_edit ? $rule->get( 'rule_type' ) : 'price_rule';
$rule_status = $is_edit ? ( 'active' === $rule->get( 'status' ) ) : true;
$rule_priority = $is_edit ? $rule->get( 'priority' ) : 10;
$discount_type = $is_edit ? $rule->get( 'discount_type' ) : 'percentage';
$discount_value = $is_edit ? $rule->get( 'discount_value' ) : '';
$apply_to = $is_edit ? $rule->get( 'apply_to' ) : 'all_products';
$schedule_from = $is_edit ? $rule->get( 'schedule_from' ) : '';
$schedule_to = $is_edit ? $rule->get( 'schedule_to' ) : '';
$usage_limit = $is_edit ? $rule->get( 'usage_limit' ) : '';
$exclusive = $is_edit ? $rule->get( 'exclusive' ) : false;
$show_badge = $is_edit ? $rule->get( 'show_badge' ) : true;
$badge_text = $is_edit ? $rule->get( 'badge_text' ) : '';
$conditions = $is_edit ? $rule->get( 'conditions' ) : array();

// Special Offer settings
$special_offer_type = $is_edit ? $rule->get( 'special_offer_type' ) : '';

// Event Sale settings
$event_type = $is_edit ? $rule->get( 'event_type' ) : '';
$custom_event_name = $is_edit ? $rule->get( 'custom_event_name' ) : '';
$event_discount_type = $is_edit ? $rule->get( 'event_discount_type' ) : 'percentage';
$event_discount_value = $is_edit ? $rule->get( 'event_discount_value' ) : 10;

// Badge customization
$badge_bg_color = $is_edit ? $rule->get( 'badge_bg_color' ) : '';
$badge_text_color = $is_edit ? $rule->get( 'badge_text_color' ) : '';

// Get quantity ranges
$quantity_ranges = $is_edit ? $rule->get_quantity_ranges() : array();

// Get selected products/categories/tags
$selected_products = array();
$selected_categories = array();
$selected_tags = array();
$exclude_products = array();
$exclude_categories = array();
$gift_products = array();

if ( $is_edit ) {
    $product_items = jdpd_get_rule_items( $rule_id, 'product' );
    foreach ( $product_items as $item ) {
        $product = wc_get_product( $item->item_id );
        if ( $product ) {
            $selected_products[ $item->item_id ] = $product->get_formatted_name();
        }
    }

    $category_items = jdpd_get_rule_items( $rule_id, 'category' );
    foreach ( $category_items as $item ) {
        $term = get_term( $item->item_id, 'product_cat' );
        if ( $term && ! is_wp_error( $term ) ) {
            $selected_categories[ $item->item_id ] = $term->name;
        }
    }

    $tag_items = jdpd_get_rule_items( $rule_id, 'tag' );
    foreach ( $tag_items as $item ) {
        $term = get_term( $item->item_id, 'product_tag' );
        if ( $term && ! is_wp_error( $term ) ) {
            $selected_tags[ $item->item_id ] = $term->name;
        }
    }

    $exclusions = jdpd_get_rule_exclusions( $rule_id );
    foreach ( $exclusions as $exclusion ) {
        if ( 'product' === $exclusion->exclusion_type ) {
            $product = wc_get_product( $exclusion->exclusion_id );
            if ( $product ) {
                $exclude_products[ $exclusion->exclusion_id ] = $product->get_formatted_name();
            }
        } elseif ( 'category' === $exclusion->exclusion_type ) {
            $term = get_term( $exclusion->exclusion_id, 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                $exclude_categories[ $exclusion->exclusion_id ] = $term->name;
            }
        }
    }

    $gift_items = $rule->get_gift_products();
    foreach ( $gift_items as $gift ) {
        $product = wc_get_product( $gift->product_id );
        if ( $product ) {
            $gift_products[] = array(
                'product_id'     => $gift->product_id,
                'product_name'   => $product->get_formatted_name(),
                'quantity'       => $gift->quantity,
                'discount_type'  => $gift->discount_type,
                'discount_value' => $gift->discount_value,
            );
        }
    }
}
?>

<div class="wrap jdpd-rule-edit-wrap">
    <!-- Jezweb Branded Header -->
    <div class="jdpd-page-header">
        <h1>
            <img src="https://www.jezweb.com.au/wp-content/uploads/2021/11/Jezweb-Logo-White-Transparent.svg" alt="Jezweb" class="jdpd-header-logo">
            <?php echo $is_edit ? esc_html__( 'Edit Rule', 'jezweb-dynamic-pricing' ) : esc_html__( 'Add New Rule', 'jezweb-dynamic-pricing' ); ?>
            <span class="jdpd-version-badge">v<?php echo esc_html( JDPD_VERSION ); ?></span>
        </h1>
    </div>

    <?php if ( isset( $_GET['saved'] ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Rule saved successfully.', 'jezweb-dynamic-pricing' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['duplicated'] ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Rule duplicated successfully. You are now editing the copy.', 'jezweb-dynamic-pricing' ); ?></p>
        </div>
    <?php endif; ?>

    <form method="post" action="" id="jdpd-rule-form">
        <?php wp_nonce_field( 'jdpd_save_rule', 'jdpd_rule_nonce' ); ?>
        <input type="hidden" name="jdpd_save_rule" value="1">

        <div id="poststuff">
            <div id="post-body" class="metabox-holder columns-2">

                <!-- Main Content -->
                <div id="post-body-content">
                    <!-- Basic Info -->
                    <div class="postbox">
                        <h2 class="hndle"><?php esc_html_e( 'Basic Information', 'jezweb-dynamic-pricing' ); ?></h2>
                        <div class="inside">
                            <div class="jdpd-form-fields">
                                <div class="jdpd-form-row">
                                    <div class="jdpd-form-label">
                                        <label for="rule_name"><?php esc_html_e( 'Rule Name', 'jezweb-dynamic-pricing' ); ?></label>
                                    </div>
                                    <div class="jdpd-form-field">
                                        <input type="text" name="rule_name" id="rule_name" class="regular-text"
                                               value="<?php echo esc_attr( $rule_name ); ?>" required>
                                    </div>
                                </div>
                                <div class="jdpd-form-row">
                                    <div class="jdpd-form-label">
                                        <label for="rule_type"><?php esc_html_e( 'Rule Type', 'jezweb-dynamic-pricing' ); ?></label>
                                    </div>
                                    <div class="jdpd-form-field">
                                        <select name="rule_type" id="rule_type" class="jdpd-rule-type-select">
                                            <?php foreach ( jdpd_get_rule_types() as $type_key => $type_label ) : ?>
                                                <option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $rule_type, $type_key ); ?>>
                                                    <?php echo esc_html( $type_label ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Discount Settings -->
                    <div class="postbox" id="jdpd-discount-settings">
                        <h2 class="hndle"><?php esc_html_e( 'Discount Settings', 'jezweb-dynamic-pricing' ); ?></h2>
                        <div class="inside">
                            <div class="jdpd-form-fields">
                                <div class="jdpd-form-row jdpd-discount-type-row">
                                    <div class="jdpd-form-label">
                                        <label for="discount_type"><?php esc_html_e( 'Discount Type', 'jezweb-dynamic-pricing' ); ?></label>
                                    </div>
                                    <div class="jdpd-form-field">
                                        <select name="discount_type" id="discount_type">
                                            <?php foreach ( jdpd_get_discount_types() as $type_key => $type_label ) : ?>
                                                <option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $discount_type, $type_key ); ?>>
                                                    <?php echo esc_html( $type_label ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="jdpd-form-row jdpd-discount-value-row">
                                    <div class="jdpd-form-label">
                                        <label for="discount_value"><?php esc_html_e( 'Discount Value', 'jezweb-dynamic-pricing' ); ?></label>
                                    </div>
                                    <div class="jdpd-form-field">
                                        <input type="number" name="discount_value" id="discount_value"
                                               value="<?php echo esc_attr( $discount_value ); ?>"
                                               step="0.01" min="0" class="small-text">
                                        <span class="jdpd-discount-suffix">%</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Quantity Ranges -->
                            <div class="jdpd-quantity-ranges" id="jdpd-quantity-ranges">
                                <h3><?php esc_html_e( 'Quantity Ranges', 'jezweb-dynamic-pricing' ); ?></h3>
                                <p class="description"><?php esc_html_e( 'Set different discounts based on quantity purchased.', 'jezweb-dynamic-pricing' ); ?></p>

                                <div class="jdpd-ranges-header">
                                    <div class="jdpd-range-col jdpd-range-col-min"><?php esc_html_e( 'Min Qty', 'jezweb-dynamic-pricing' ); ?></div>
                                    <div class="jdpd-range-col jdpd-range-col-max"><?php esc_html_e( 'Max Qty', 'jezweb-dynamic-pricing' ); ?></div>
                                    <div class="jdpd-range-col jdpd-range-col-type"><?php esc_html_e( 'Discount Type', 'jezweb-dynamic-pricing' ); ?></div>
                                    <div class="jdpd-range-col jdpd-range-col-value"><?php esc_html_e( 'Discount', 'jezweb-dynamic-pricing' ); ?></div>
                                    <div class="jdpd-range-col jdpd-range-col-action"></div>
                                </div>
                                <div class="jdpd-ranges-list" id="jdpd-ranges-body">
                                    <?php if ( ! empty( $quantity_ranges ) ) : ?>
                                        <?php foreach ( $quantity_ranges as $index => $range ) : ?>
                                            <div class="jdpd-range-row">
                                                <div class="jdpd-range-col jdpd-range-col-min">
                                                    <span class="jdpd-range-mobile-label"><?php esc_html_e( 'Min Qty:', 'jezweb-dynamic-pricing' ); ?></span>
                                                    <input type="number" name="quantity_ranges[<?php echo $index; ?>][min]"
                                                           value="<?php echo esc_attr( $range->min_quantity ); ?>"
                                                           min="1" class="small-text">
                                                </div>
                                                <div class="jdpd-range-col jdpd-range-col-max">
                                                    <span class="jdpd-range-mobile-label"><?php esc_html_e( 'Max Qty:', 'jezweb-dynamic-pricing' ); ?></span>
                                                    <input type="number" name="quantity_ranges[<?php echo $index; ?>][max]"
                                                           value="<?php echo esc_attr( $range->max_quantity ); ?>"
                                                           min="1" class="small-text"
                                                           placeholder="<?php esc_attr_e( 'No limit', 'jezweb-dynamic-pricing' ); ?>">
                                                </div>
                                                <div class="jdpd-range-col jdpd-range-col-type">
                                                    <span class="jdpd-range-mobile-label"><?php esc_html_e( 'Discount Type:', 'jezweb-dynamic-pricing' ); ?></span>
                                                    <select name="quantity_ranges[<?php echo $index; ?>][type]">
                                                        <?php foreach ( jdpd_get_discount_types() as $type_key => $type_label ) : ?>
                                                            <option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $range->discount_type, $type_key ); ?>>
                                                                <?php echo esc_html( $type_label ); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="jdpd-range-col jdpd-range-col-value">
                                                    <span class="jdpd-range-mobile-label"><?php esc_html_e( 'Discount:', 'jezweb-dynamic-pricing' ); ?></span>
                                                    <input type="number" name="quantity_ranges[<?php echo $index; ?>][value]"
                                                           value="<?php echo esc_attr( $range->discount_value ); ?>"
                                                           step="0.01" min="0" class="small-text">
                                                </div>
                                                <div class="jdpd-range-col jdpd-range-col-action">
                                                    <button type="button" class="button jdpd-remove-range">&times;</button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <p>
                                    <button type="button" class="button" id="jdpd-add-range">
                                        <?php esc_html_e( '+ Add Range', 'jezweb-dynamic-pricing' ); ?>
                                    </button>
                                </p>
                            </div>

                            <!-- Special Offer Settings -->
                            <div class="jdpd-special-offer-settings" id="jdpd-special-offer-settings" style="display: none;">
                                <h3><?php esc_html_e( 'Special Offer Settings', 'jezweb-dynamic-pricing' ); ?></h3>
                                <div class="jdpd-form-fields">
                                    <div class="jdpd-form-row">
                                        <div class="jdpd-form-label">
                                            <label for="special_offer_type"><?php esc_html_e( 'Offer Type', 'jezweb-dynamic-pricing' ); ?></label>
                                        </div>
                                        <div class="jdpd-form-field">
                                            <select name="special_offer_type" id="special_offer_type">
                                                <?php foreach ( jdpd_get_special_offer_types() as $type_key => $type_label ) : ?>
                                                    <option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $special_offer_type, $type_key ); ?>>
                                                        <?php echo esc_html( $type_label ); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="jdpd-form-row">
                                        <div class="jdpd-form-label">
                                            <label for="buy_quantity"><?php esc_html_e( 'Buy Quantity (X)', 'jezweb-dynamic-pricing' ); ?></label>
                                        </div>
                                        <div class="jdpd-form-field">
                                            <input type="number" name="buy_quantity" id="buy_quantity" value="1" min="1" class="small-text">
                                        </div>
                                    </div>
                                    <div class="jdpd-form-row">
                                        <div class="jdpd-form-label">
                                            <label for="get_quantity"><?php esc_html_e( 'Get Quantity (Y)', 'jezweb-dynamic-pricing' ); ?></label>
                                        </div>
                                        <div class="jdpd-form-field">
                                            <input type="number" name="get_quantity" id="get_quantity" value="1" min="1" class="small-text">
                                        </div>
                                    </div>
                                    <div class="jdpd-form-row">
                                        <div class="jdpd-form-label">
                                            <label for="get_discount"><?php esc_html_e( 'Discount on Y', 'jezweb-dynamic-pricing' ); ?></label>
                                        </div>
                                        <div class="jdpd-form-field">
                                            <input type="number" name="get_discount" id="get_discount" value="100" min="0" max="100" class="small-text">
                                            <span>%</span>
                                            <p class="description"><?php esc_html_e( '100% = Free item', 'jezweb-dynamic-pricing' ); ?></p>
                                        </div>
                                    </div>

                                    <!-- Event Sale Settings (shown when event_sale type is selected) -->
                                    <div class="jdpd-event-sale-settings" id="jdpd-event-sale-settings" style="display: none;">
                                        <div class="jdpd-form-row">
                                            <div class="jdpd-form-label">
                                                <label for="event_type"><?php esc_html_e( 'Select Event', 'jezweb-dynamic-pricing' ); ?></label>
                                            </div>
                                            <div class="jdpd-form-field">
                                                <select name="event_type" id="event_type">
                                                    <option value=""><?php esc_html_e( '-- Select Event --', 'jezweb-dynamic-pricing' ); ?></option>
                                                    <?php foreach ( jdpd_get_special_events() as $event_key => $event ) : ?>
                                                        <option value="<?php echo esc_attr( $event_key ); ?>" data-month="<?php echo esc_attr( $event['month'] ); ?>" data-categories="<?php echo esc_attr( $event['categories'] ); ?>" <?php selected( $event_type, $event_key ); ?>>
                                                            <?php echo esc_html( $event['name'] ); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="jdpd-form-row" id="custom-event-name-row" style="display: none;">
                                            <div class="jdpd-form-label">
                                                <label for="custom_event_name"><?php esc_html_e( 'Custom Event Name', 'jezweb-dynamic-pricing' ); ?></label>
                                            </div>
                                            <div class="jdpd-form-field">
                                                <input type="text" name="custom_event_name" id="custom_event_name" class="regular-text" value="<?php echo esc_attr( $custom_event_name ); ?>" placeholder="<?php esc_attr_e( 'e.g., Summer Clearance Sale', 'jezweb-dynamic-pricing' ); ?>">
                                                <p class="description"><?php esc_html_e( 'Enter a custom name for your special event. This will be displayed as the discount badge.', 'jezweb-dynamic-pricing' ); ?></p>
                                            </div>
                                        </div>
                                        <div class="jdpd-form-row" id="event-info-row" style="display: none;">
                                            <div class="jdpd-form-label">
                                                <label><?php esc_html_e( 'Event Info', 'jezweb-dynamic-pricing' ); ?></label>
                                            </div>
                                            <div class="jdpd-form-field">
                                                <div class="jdpd-event-info-box">
                                                    <p><strong><?php esc_html_e( 'Month:', 'jezweb-dynamic-pricing' ); ?></strong> <span id="event-month"></span></p>
                                                    <p><strong><?php esc_html_e( 'Best Categories:', 'jezweb-dynamic-pricing' ); ?></strong> <span id="event-categories"></span></p>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="jdpd-form-row">
                                            <div class="jdpd-form-label">
                                                <label for="event_discount_type"><?php esc_html_e( 'Discount Type', 'jezweb-dynamic-pricing' ); ?></label>
                                            </div>
                                            <div class="jdpd-form-field">
                                                <select name="event_discount_type" id="event_discount_type">
                                                    <option value="percentage" <?php selected( $event_discount_type, 'percentage' ); ?>><?php esc_html_e( 'Percentage Off', 'jezweb-dynamic-pricing' ); ?></option>
                                                    <option value="fixed" <?php selected( $event_discount_type, 'fixed' ); ?>><?php esc_html_e( 'Fixed Amount Off', 'jezweb-dynamic-pricing' ); ?></option>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="jdpd-form-row">
                                            <div class="jdpd-form-label">
                                                <label for="event_discount_value"><?php esc_html_e( 'Discount Value', 'jezweb-dynamic-pricing' ); ?></label>
                                            </div>
                                            <div class="jdpd-form-field">
                                                <input type="number" name="event_discount_value" id="event_discount_value" value="<?php echo esc_attr( $event_discount_value ); ?>" min="0" step="0.01" class="small-text">
                                                <span id="event-discount-suffix">%</span>
                                            </div>
                                        </div>
                                        <div class="jdpd-form-row">
                                            <div class="jdpd-form-label">
                                                <label><?php esc_html_e( 'Badge Preview', 'jezweb-dynamic-pricing' ); ?></label>
                                            </div>
                                            <div class="jdpd-form-field">
                                                <span class="jdpd-event-badge-preview" id="event-badge-preview" style="display: none;"></span>
                                                <p class="description"><?php esc_html_e( 'This badge will appear next to the product price.', 'jezweb-dynamic-pricing' ); ?></p>
                                            </div>
                                        </div>
                                        <div class="jdpd-form-row">
                                            <div class="jdpd-form-label">
                                                <label for="badge_bg_color"><?php esc_html_e( 'Badge Background Color', 'jezweb-dynamic-pricing' ); ?></label>
                                            </div>
                                            <div class="jdpd-form-field">
                                                <input type="color" name="badge_bg_color" id="badge_bg_color" value="<?php echo esc_attr( $badge_bg_color ? $badge_bg_color : '#d83a34' ); ?>" class="jdpd-color-picker">
                                                <input type="text" id="badge_bg_color_text" value="<?php echo esc_attr( $badge_bg_color ? $badge_bg_color : '#d83a34' ); ?>" class="small-text" style="width: 80px;">
                                                <p class="description"><?php esc_html_e( 'Choose the badge background color. Default: #d83a34 (red)', 'jezweb-dynamic-pricing' ); ?></p>
                                            </div>
                                        </div>
                                        <div class="jdpd-form-row">
                                            <div class="jdpd-form-label">
                                                <label for="badge_text_color"><?php esc_html_e( 'Badge Text Color', 'jezweb-dynamic-pricing' ); ?></label>
                                            </div>
                                            <div class="jdpd-form-field">
                                                <input type="color" name="badge_text_color" id="badge_text_color" value="<?php echo esc_attr( $badge_text_color ? $badge_text_color : '#ffffff' ); ?>" class="jdpd-color-picker">
                                                <input type="text" id="badge_text_color_text" value="<?php echo esc_attr( $badge_text_color ? $badge_text_color : '#ffffff' ); ?>" class="small-text" style="width: 80px;">
                                                <p class="description"><?php esc_html_e( 'Choose the badge text color. Default: #ffffff (white)', 'jezweb-dynamic-pricing' ); ?></p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Gift Products Settings -->
                            <div class="jdpd-gift-products-settings" id="jdpd-gift-products-settings" style="display: none;">
                                <h3><?php esc_html_e( 'Gift Products', 'jezweb-dynamic-pricing' ); ?></h3>
                                <p class="description"><?php esc_html_e( 'Select products to be added as gifts when this rule applies.', 'jezweb-dynamic-pricing' ); ?></p>

                                <div class="jdpd-gifts-list" id="jdpd-gifts-body">
                                    <?php if ( ! empty( $gift_products ) ) : ?>
                                        <?php foreach ( $gift_products as $index => $gift ) : ?>
                                            <div class="jdpd-gift-row">
                                                <div class="jdpd-gift-col jdpd-gift-col-product">
                                                    <span class="jdpd-gift-mobile-label"><?php esc_html_e( 'Product:', 'jezweb-dynamic-pricing' ); ?></span>
                                                    <select name="gift_products[<?php echo $index; ?>][product_id]" class="jdpd-product-search" style="width: 100%;">
                                                        <option value="<?php echo esc_attr( $gift['product_id'] ); ?>" selected>
                                                            <?php echo esc_html( $gift['product_name'] ); ?>
                                                        </option>
                                                    </select>
                                                </div>
                                                <div class="jdpd-gift-col jdpd-gift-col-qty">
                                                    <span class="jdpd-gift-mobile-label"><?php esc_html_e( 'Qty:', 'jezweb-dynamic-pricing' ); ?></span>
                                                    <input type="number" name="gift_products[<?php echo $index; ?>][quantity]"
                                                           value="<?php echo esc_attr( $gift['quantity'] ); ?>" min="1" class="small-text">
                                                </div>
                                                <div class="jdpd-gift-col jdpd-gift-col-type">
                                                    <span class="jdpd-gift-mobile-label"><?php esc_html_e( 'Type:', 'jezweb-dynamic-pricing' ); ?></span>
                                                    <select name="gift_products[<?php echo $index; ?>][discount_type]">
                                                        <option value="percentage" <?php selected( $gift['discount_type'], 'percentage' ); ?>>
                                                            <?php esc_html_e( 'Percentage', 'jezweb-dynamic-pricing' ); ?>
                                                        </option>
                                                        <option value="fixed" <?php selected( $gift['discount_type'], 'fixed' ); ?>>
                                                            <?php esc_html_e( 'Fixed', 'jezweb-dynamic-pricing' ); ?>
                                                        </option>
                                                    </select>
                                                </div>
                                                <div class="jdpd-gift-col jdpd-gift-col-value">
                                                    <span class="jdpd-gift-mobile-label"><?php esc_html_e( 'Discount:', 'jezweb-dynamic-pricing' ); ?></span>
                                                    <input type="number" name="gift_products[<?php echo $index; ?>][discount_value]"
                                                           value="<?php echo esc_attr( $gift['discount_value'] ); ?>"
                                                           step="0.01" min="0" class="small-text">
                                                </div>
                                                <div class="jdpd-gift-col jdpd-gift-col-action">
                                                    <button type="button" class="button jdpd-remove-gift">&times;</button>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <p>
                                    <button type="button" class="button" id="jdpd-add-gift">
                                        <?php esc_html_e( '+ Add Gift Product', 'jezweb-dynamic-pricing' ); ?>
                                    </button>
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Apply To -->
                    <div class="postbox">
                        <h2 class="hndle"><?php esc_html_e( 'Apply To', 'jezweb-dynamic-pricing' ); ?></h2>
                        <div class="inside">
                            <div class="jdpd-form-fields">
                                <div class="jdpd-form-row">
                                    <div class="jdpd-form-label">
                                        <label for="apply_to"><?php esc_html_e( 'Apply To', 'jezweb-dynamic-pricing' ); ?></label>
                                    </div>
                                    <div class="jdpd-form-field">
                                        <select name="apply_to" id="apply_to" class="jdpd-apply-to-select">
                                            <?php foreach ( jdpd_get_apply_to_options() as $key => $label ) : ?>
                                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $apply_to, $key ); ?>>
                                                    <?php echo esc_html( $label ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="jdpd-form-row jdpd-products-row" style="<?php echo 'specific_products' !== $apply_to ? 'display: none;' : ''; ?>">
                                    <div class="jdpd-form-label">
                                        <label for="products"><?php esc_html_e( 'Products', 'jezweb-dynamic-pricing' ); ?></label>
                                    </div>
                                    <div class="jdpd-form-field">
                                        <select name="products[]" id="products" class="jdpd-product-search" multiple style="width: 100%;">
                                            <?php foreach ( $selected_products as $id => $name ) : ?>
                                                <option value="<?php echo esc_attr( $id ); ?>" selected><?php echo esc_html( $name ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="jdpd-form-row jdpd-categories-row" style="<?php echo 'categories' !== $apply_to ? 'display: none;' : ''; ?>">
                                    <div class="jdpd-form-label">
                                        <label for="categories"><?php esc_html_e( 'Categories', 'jezweb-dynamic-pricing' ); ?></label>
                                    </div>
                                    <div class="jdpd-form-field">
                                        <select name="categories[]" id="categories" class="jdpd-category-search" multiple style="width: 100%;">
                                            <?php foreach ( $selected_categories as $id => $name ) : ?>
                                                <option value="<?php echo esc_attr( $id ); ?>" selected><?php echo esc_html( $name ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="jdpd-form-row jdpd-tags-row" style="<?php echo 'tags' !== $apply_to ? 'display: none;' : ''; ?>">
                                    <div class="jdpd-form-label">
                                        <label for="tags"><?php esc_html_e( 'Tags', 'jezweb-dynamic-pricing' ); ?></label>
                                    </div>
                                    <div class="jdpd-form-field">
                                        <select name="tags[]" id="tags" class="jdpd-tag-search" multiple style="width: 100%;">
                                            <?php foreach ( $selected_tags as $id => $name ) : ?>
                                                <option value="<?php echo esc_attr( $id ); ?>" selected><?php echo esc_html( $name ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Exclusions -->
                    <div class="postbox">
                        <h2 class="hndle"><?php esc_html_e( 'Exclusions', 'jezweb-dynamic-pricing' ); ?></h2>
                        <div class="inside">
                            <div class="jdpd-form-fields">
                                <div class="jdpd-form-row">
                                    <div class="jdpd-form-label">
                                        <label for="exclude_products"><?php esc_html_e( 'Exclude Products', 'jezweb-dynamic-pricing' ); ?></label>
                                    </div>
                                    <div class="jdpd-form-field">
                                        <select name="exclude_products[]" id="exclude_products" class="jdpd-product-search" multiple style="width: 100%;">
                                            <?php foreach ( $exclude_products as $id => $name ) : ?>
                                                <option value="<?php echo esc_attr( $id ); ?>" selected><?php echo esc_html( $name ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="jdpd-form-row">
                                    <div class="jdpd-form-label">
                                        <label for="exclude_categories"><?php esc_html_e( 'Exclude Categories', 'jezweb-dynamic-pricing' ); ?></label>
                                    </div>
                                    <div class="jdpd-form-field">
                                        <select name="exclude_categories[]" id="exclude_categories" class="jdpd-category-search" multiple style="width: 100%;">
                                            <?php foreach ( $exclude_categories as $id => $name ) : ?>
                                                <option value="<?php echo esc_attr( $id ); ?>" selected><?php echo esc_html( $name ); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Conditions -->
                    <div class="postbox">
                        <h2 class="hndle"><?php esc_html_e( 'Conditions', 'jezweb-dynamic-pricing' ); ?></h2>
                        <div class="inside">
                            <p class="description"><?php esc_html_e( 'Add conditions that must be met for this rule to apply.', 'jezweb-dynamic-pricing' ); ?></p>
                            <div id="jdpd-conditions">
                                <?php if ( ! empty( $conditions ) ) : ?>
                                    <?php foreach ( $conditions as $index => $condition ) : ?>
                                        <div class="jdpd-condition-row">
                                            <select name="conditions[<?php echo $index; ?>][type]" class="jdpd-condition-type">
                                                <option value="user_role" <?php selected( $condition['type'], 'user_role' ); ?>><?php esc_html_e( 'User Role', 'jezweb-dynamic-pricing' ); ?></option>
                                                <option value="cart_total" <?php selected( $condition['type'], 'cart_total' ); ?>><?php esc_html_e( 'Cart Total', 'jezweb-dynamic-pricing' ); ?></option>
                                                <option value="cart_items" <?php selected( $condition['type'], 'cart_items' ); ?>><?php esc_html_e( 'Cart Items Count', 'jezweb-dynamic-pricing' ); ?></option>
                                                <option value="total_spent" <?php selected( $condition['type'], 'total_spent' ); ?>><?php esc_html_e( 'Customer Total Spent', 'jezweb-dynamic-pricing' ); ?></option>
                                                <option value="order_count" <?php selected( $condition['type'], 'order_count' ); ?>><?php esc_html_e( 'Customer Order Count', 'jezweb-dynamic-pricing' ); ?></option>
                                                <option value="product_in_cart" <?php selected( $condition['type'], 'product_in_cart' ); ?>><?php esc_html_e( 'Product in Cart', 'jezweb-dynamic-pricing' ); ?></option>
                                                <option value="category_in_cart" <?php selected( $condition['type'], 'category_in_cart' ); ?>><?php esc_html_e( 'Category in Cart', 'jezweb-dynamic-pricing' ); ?></option>
                                            </select>
                                            <select name="conditions[<?php echo $index; ?>][operator]" class="jdpd-condition-operator">
                                                <option value="equals" <?php selected( $condition['operator'], 'equals' ); ?>>=</option>
                                                <option value="not_equals" <?php selected( $condition['operator'], 'not_equals' ); ?>>!=</option>
                                                <option value="greater" <?php selected( $condition['operator'], 'greater' ); ?>>&gt;</option>
                                                <option value="less" <?php selected( $condition['operator'], 'less' ); ?>>&lt;</option>
                                                <option value="greater_equal" <?php selected( $condition['operator'], 'greater_equal' ); ?>>&gt;=</option>
                                                <option value="less_equal" <?php selected( $condition['operator'], 'less_equal' ); ?>>&lt;=</option>
                                            </select>
                                            <input type="text" name="conditions[<?php echo $index; ?>][value]" value="<?php echo esc_attr( $condition['value'] ); ?>" class="regular-text">
                                            <button type="button" class="button jdpd-remove-condition">&times;</button>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <p>
                                <button type="button" class="button" id="jdpd-add-condition">
                                    <?php esc_html_e( '+ Add Condition', 'jezweb-dynamic-pricing' ); ?>
                                </button>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Sidebar -->
                <div id="postbox-container-1" class="postbox-container">
                    <!-- Publish Box -->
                    <div class="postbox">
                        <h2 class="hndle"><?php esc_html_e( 'Publish', 'jezweb-dynamic-pricing' ); ?></h2>
                        <div class="inside">
                            <div class="submitbox">
                                <p>
                                    <label>
                                        <input type="checkbox" name="rule_status" value="1" <?php checked( $rule_status, true ); ?>>
                                        <?php esc_html_e( 'Active', 'jezweb-dynamic-pricing' ); ?>
                                    </label>
                                </p>
                                <p>
                                    <label for="rule_priority"><?php esc_html_e( 'Priority', 'jezweb-dynamic-pricing' ); ?></label>
                                    <input type="number" name="rule_priority" id="rule_priority"
                                           value="<?php echo esc_attr( $rule_priority ); ?>" min="1" class="small-text">
                                    <span class="description"><?php esc_html_e( 'Lower = higher priority', 'jezweb-dynamic-pricing' ); ?></span>
                                </p>
                                <p>
                                    <label>
                                        <input type="checkbox" name="exclusive" value="1" <?php checked( $exclusive, true ); ?>>
                                        <?php esc_html_e( 'Exclusive (do not combine)', 'jezweb-dynamic-pricing' ); ?>
                                    </label>
                                </p>
                                <div class="clear"></div>
                                <div id="major-publishing-actions">
                                    <?php if ( $is_edit ) : ?>
                                        <div id="delete-action">
                                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=jdpd-rules&action=delete&rule_id=' . $rule_id ), 'jdpd_rule_action' ) ); ?>" class="submitdelete deletion">
                                                <?php esc_html_e( 'Delete', 'jezweb-dynamic-pricing' ); ?>
                                            </a>
                                        </div>
                                    <?php endif; ?>
                                    <div id="publishing-action">
                                        <button type="submit" class="button button-primary button-large">
                                            <?php echo $is_edit ? esc_html__( 'Update Rule', 'jezweb-dynamic-pricing' ) : esc_html__( 'Create Rule', 'jezweb-dynamic-pricing' ); ?>
                                        </button>
                                    </div>
                                    <div class="clear"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Schedule -->
                    <div class="postbox">
                        <h2 class="hndle"><?php esc_html_e( 'Schedule', 'jezweb-dynamic-pricing' ); ?></h2>
                        <div class="inside">
                            <p>
                                <label for="schedule_from"><?php esc_html_e( 'Start Date', 'jezweb-dynamic-pricing' ); ?></label>
                                <input type="text" name="schedule_from" id="schedule_from" class="jdpd-datepicker"
                                       value="<?php echo esc_attr( $schedule_from ? date( 'd-m-Y H:i', strtotime( $schedule_from ) ) : '' ); ?>"
                                       placeholder="dd-mm-yyyy hh:mm" autocomplete="off">
                            </p>
                            <p>
                                <label for="schedule_to"><?php esc_html_e( 'End Date', 'jezweb-dynamic-pricing' ); ?></label>
                                <input type="text" name="schedule_to" id="schedule_to" class="jdpd-datepicker"
                                       value="<?php echo esc_attr( $schedule_to ? date( 'd-m-Y H:i', strtotime( $schedule_to ) ) : '' ); ?>"
                                       placeholder="dd-mm-yyyy hh:mm" autocomplete="off">
                            </p>
                        </div>
                    </div>

                    <!-- Usage Limit -->
                    <div class="postbox">
                        <h2 class="hndle"><?php esc_html_e( 'Usage Limit', 'jezweb-dynamic-pricing' ); ?></h2>
                        <div class="inside">
                            <p>
                                <label for="usage_limit"><?php esc_html_e( 'Maximum Uses', 'jezweb-dynamic-pricing' ); ?></label>
                                <input type="number" name="usage_limit" id="usage_limit"
                                       value="<?php echo esc_attr( $usage_limit ); ?>" min="0" class="small-text"
                                       placeholder="<?php esc_attr_e( 'Unlimited', 'jezweb-dynamic-pricing' ); ?>">
                            </p>
                            <?php if ( $is_edit ) : ?>
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %d: usage count */
                                        esc_html__( 'Used %d times', 'jezweb-dynamic-pricing' ),
                                        $rule->get( 'usage_count' )
                                    );
                                    ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Display Settings -->
                    <div class="postbox">
                        <h2 class="hndle"><?php esc_html_e( 'Display', 'jezweb-dynamic-pricing' ); ?></h2>
                        <div class="inside">
                            <p>
                                <label>
                                    <input type="checkbox" name="show_badge" value="1" <?php checked( $show_badge, true ); ?>>
                                    <?php esc_html_e( 'Show Sale Badge', 'jezweb-dynamic-pricing' ); ?>
                                </label>
                            </p>
                            <p>
                                <label for="badge_text"><?php esc_html_e( 'Badge Text', 'jezweb-dynamic-pricing' ); ?></label>
                                <input type="text" name="badge_text" id="badge_text"
                                       value="<?php echo esc_attr( $badge_text ); ?>" class="regular-text"
                                       placeholder="<?php esc_attr_e( 'Sale', 'jezweb-dynamic-pricing' ); ?>">
                                <span class="description"><?php esc_html_e( 'Use {discount} for discount percentage', 'jezweb-dynamic-pricing' ); ?></span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- Jezweb Credits Footer -->
    <div class="jdpd-credits">
        <div class="jdpd-credits-logo">
            <img src="https://www.jezweb.com.au/wp-content/uploads/2023/05/Jezweb-logo-1.png" alt="Jezweb" style="height: 50px; width: auto;">
            <div class="jdpd-credits-text">
                <span class="jdpd-credits-title"><?php esc_html_e( 'Developed by Jezweb', 'jezweb-dynamic-pricing' ); ?></span>
                <span class="jdpd-credits-subtitle"><?php esc_html_e( 'Web Design & Digital Marketing Agency', 'jezweb-dynamic-pricing' ); ?></span>
            </div>
        </div>
        <div class="jdpd-credits-links">
            <a href="https://jezweb.com.au" target="_blank" class="jdpd-btn-primary">
                <span class="dashicons dashicons-admin-site-alt3"></span>
                <?php esc_html_e( 'Visit Jezweb', 'jezweb-dynamic-pricing' ); ?>
            </a>
            <a href="https://jezweb.com.au/contact/" target="_blank" class="jdpd-btn-secondary">
                <span class="dashicons dashicons-email-alt"></span>
                <?php esc_html_e( 'Contact Us', 'jezweb-dynamic-pricing' ); ?>
            </a>
        </div>
    </div>
</div>

<script type="text/template" id="tmpl-jdpd-quantity-range">
    <div class="jdpd-range-row">
        <div class="jdpd-range-col jdpd-range-col-min">
            <span class="jdpd-range-mobile-label"><?php esc_html_e( 'Min Qty:', 'jezweb-dynamic-pricing' ); ?></span>
            <input type="number" name="quantity_ranges[{{data.index}}][min]" value="1" min="1" class="small-text">
        </div>
        <div class="jdpd-range-col jdpd-range-col-max">
            <span class="jdpd-range-mobile-label"><?php esc_html_e( 'Max Qty:', 'jezweb-dynamic-pricing' ); ?></span>
            <input type="number" name="quantity_ranges[{{data.index}}][max]" min="1" class="small-text" placeholder="<?php esc_attr_e( 'No limit', 'jezweb-dynamic-pricing' ); ?>">
        </div>
        <div class="jdpd-range-col jdpd-range-col-type">
            <span class="jdpd-range-mobile-label"><?php esc_html_e( 'Discount Type:', 'jezweb-dynamic-pricing' ); ?></span>
            <select name="quantity_ranges[{{data.index}}][type]">
                <?php foreach ( jdpd_get_discount_types() as $type_key => $type_label ) : ?>
                    <option value="<?php echo esc_attr( $type_key ); ?>"><?php echo esc_html( $type_label ); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="jdpd-range-col jdpd-range-col-value">
            <span class="jdpd-range-mobile-label"><?php esc_html_e( 'Discount:', 'jezweb-dynamic-pricing' ); ?></span>
            <input type="number" name="quantity_ranges[{{data.index}}][value]" value="0" step="0.01" min="0" class="small-text">
        </div>
        <div class="jdpd-range-col jdpd-range-col-action">
            <button type="button" class="button jdpd-remove-range">&times;</button>
        </div>
    </div>
</script>

<script type="text/template" id="tmpl-jdpd-gift-product">
    <div class="jdpd-gift-row">
        <div class="jdpd-gift-col jdpd-gift-col-product">
            <span class="jdpd-gift-mobile-label"><?php esc_html_e( 'Product:', 'jezweb-dynamic-pricing' ); ?></span>
            <select name="gift_products[{{data.index}}][product_id]" class="jdpd-product-search" style="width: 100%;"></select>
        </div>
        <div class="jdpd-gift-col jdpd-gift-col-qty">
            <span class="jdpd-gift-mobile-label"><?php esc_html_e( 'Qty:', 'jezweb-dynamic-pricing' ); ?></span>
            <input type="number" name="gift_products[{{data.index}}][quantity]" value="1" min="1" class="small-text">
        </div>
        <div class="jdpd-gift-col jdpd-gift-col-type">
            <span class="jdpd-gift-mobile-label"><?php esc_html_e( 'Type:', 'jezweb-dynamic-pricing' ); ?></span>
            <select name="gift_products[{{data.index}}][discount_type]">
                <option value="percentage"><?php esc_html_e( 'Percentage', 'jezweb-dynamic-pricing' ); ?></option>
                <option value="fixed"><?php esc_html_e( 'Fixed', 'jezweb-dynamic-pricing' ); ?></option>
            </select>
        </div>
        <div class="jdpd-gift-col jdpd-gift-col-value">
            <span class="jdpd-gift-mobile-label"><?php esc_html_e( 'Discount:', 'jezweb-dynamic-pricing' ); ?></span>
            <input type="number" name="gift_products[{{data.index}}][discount_value]" value="100" step="0.01" min="0" class="small-text">
        </div>
        <div class="jdpd-gift-col jdpd-gift-col-action">
            <button type="button" class="button jdpd-remove-gift">&times;</button>
        </div>
    </div>
</script>

<script type="text/template" id="tmpl-jdpd-condition">
    <div class="jdpd-condition-row">
        <select name="conditions[{{data.index}}][type]" class="jdpd-condition-type">
            <option value="user_role"><?php esc_html_e( 'User Role', 'jezweb-dynamic-pricing' ); ?></option>
            <option value="cart_total"><?php esc_html_e( 'Cart Total', 'jezweb-dynamic-pricing' ); ?></option>
            <option value="cart_items"><?php esc_html_e( 'Cart Items Count', 'jezweb-dynamic-pricing' ); ?></option>
            <option value="total_spent"><?php esc_html_e( 'Customer Total Spent', 'jezweb-dynamic-pricing' ); ?></option>
            <option value="order_count"><?php esc_html_e( 'Customer Order Count', 'jezweb-dynamic-pricing' ); ?></option>
            <option value="product_in_cart"><?php esc_html_e( 'Product in Cart', 'jezweb-dynamic-pricing' ); ?></option>
            <option value="category_in_cart"><?php esc_html_e( 'Category in Cart', 'jezweb-dynamic-pricing' ); ?></option>
        </select>
        <select name="conditions[{{data.index}}][operator]" class="jdpd-condition-operator">
            <option value="equals">=</option>
            <option value="not_equals">!=</option>
            <option value="greater">&gt;</option>
            <option value="less">&lt;</option>
            <option value="greater_equal">&gt;=</option>
            <option value="less_equal">&lt;=</option>
        </select>
        <input type="text" name="conditions[{{data.index}}][value]" class="regular-text">
        <button type="button" class="button jdpd-remove-condition">&times;</button>
    </div>
</script>

