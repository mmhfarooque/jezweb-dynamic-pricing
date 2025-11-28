<?php
/**
 * Admin Rules List View
 *
 * @package Jezweb_Dynamic_Pricing
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Get filter parameters
$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
$rule_type = isset( $_GET['rule_type'] ) ? sanitize_key( $_GET['rule_type'] ) : '';
$search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
$paged = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;

// Get rules
$result = JDPD_Admin_Rules::get_rules(
    array(
        'status'    => $status,
        'rule_type' => $rule_type,
        'search'    => $search,
        'page'      => $paged,
    )
);

$rules = $result['rules'];
$total_pages = $result['total_pages'];
?>

<div class="wrap jdpd-rules-wrap">
    <!-- Jezweb Branded Header -->
    <div class="jdpd-page-header">
        <h1>
            <img src="https://www.jezweb.com.au/wp-content/uploads/2021/11/Jezweb-Logo-White-Transparent.svg" alt="Jezweb" class="jdpd-header-logo">
            <?php esc_html_e( 'Discount Rules', 'jezweb-dynamic-pricing' ); ?>
            <span class="jdpd-version-badge">v<?php echo esc_html( JDPD_VERSION ); ?></span>
        </h1>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=jdpd-add-rule' ) ); ?>" class="page-title-action">
            <?php esc_html_e( 'Add New Rule', 'jezweb-dynamic-pricing' ); ?>
        </a>
    </div>

    <?php if ( isset( $_GET['deleted'] ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Rule deleted successfully.', 'jezweb-dynamic-pricing' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['activated'] ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Rule activated.', 'jezweb-dynamic-pricing' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( isset( $_GET['deactivated'] ) ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Rule deactivated.', 'jezweb-dynamic-pricing' ); ?></p>
        </div>
    <?php endif; ?>

    <hr class="wp-header-end">

    <!-- Filters -->
    <div class="jdpd-filters">
        <form method="get" action="" class="jdpd-filters-form">
            <input type="hidden" name="page" value="jdpd-rules">

            <div class="jdpd-filter-item">
                <select name="status">
                    <option value=""><?php esc_html_e( 'All Statuses', 'jezweb-dynamic-pricing' ); ?></option>
                    <option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'jezweb-dynamic-pricing' ); ?></option>
                    <option value="inactive" <?php selected( $status, 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'jezweb-dynamic-pricing' ); ?></option>
                </select>
            </div>

            <div class="jdpd-filter-item">
                <select name="rule_type">
                    <option value=""><?php esc_html_e( 'All Types', 'jezweb-dynamic-pricing' ); ?></option>
                    <?php foreach ( jdpd_get_rule_types() as $type_key => $type_label ) : ?>
                        <option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $rule_type, $type_key ); ?>>
                            <?php echo esc_html( $type_label ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="jdpd-filter-item jdpd-filter-search">
                <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
                       placeholder="<?php esc_attr_e( 'Search rules...', 'jezweb-dynamic-pricing' ); ?>">
            </div>

            <div class="jdpd-filter-actions">
                <button type="submit" class="button"><?php esc_html_e( 'Filter', 'jezweb-dynamic-pricing' ); ?></button>
                <?php if ( $status || $rule_type || $search ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=jdpd-rules' ) ); ?>" class="button">
                        <?php esc_html_e( 'Clear', 'jezweb-dynamic-pricing' ); ?>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Bulk Actions -->
    <form method="post" id="jdpd-rules-form">
        <?php wp_nonce_field( 'jdpd_bulk_action', 'jdpd_bulk_nonce' ); ?>

        <div class="jdpd-bulk-actions">
            <div class="jdpd-bulk-left">
                <label class="jdpd-select-all-wrap">
                    <input type="checkbox" id="cb-select-all">
                    <span><?php esc_html_e( 'Select All', 'jezweb-dynamic-pricing' ); ?></span>
                </label>
                <select name="bulk_action" id="bulk-action-selector">
                    <option value=""><?php esc_html_e( 'Bulk Actions', 'jezweb-dynamic-pricing' ); ?></option>
                    <option value="activate"><?php esc_html_e( 'Activate', 'jezweb-dynamic-pricing' ); ?></option>
                    <option value="deactivate"><?php esc_html_e( 'Deactivate', 'jezweb-dynamic-pricing' ); ?></option>
                    <option value="delete"><?php esc_html_e( 'Delete', 'jezweb-dynamic-pricing' ); ?></option>
                </select>
                <button type="button" class="button action" id="doaction">
                    <?php esc_html_e( 'Apply', 'jezweb-dynamic-pricing' ); ?>
                </button>
            </div>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="jdpd-pagination">
                    <span class="jdpd-displaying-num">
                        <?php
                        printf(
                            /* translators: %d: number of items */
                            esc_html( _n( '%d item', '%d items', $result['total'], 'jezweb-dynamic-pricing' ) ),
                            $result['total']
                        );
                        ?>
                    </span>
                    <span class="jdpd-pagination-links">
                        <?php
                        echo paginate_links(
                            array(
                                'base'      => add_query_arg( 'paged', '%#%' ),
                                'format'    => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total'     => $total_pages,
                                'current'   => $paged,
                            )
                        );
                        ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <!-- Rules List Header (Desktop) -->
        <div class="jdpd-rules-header">
            <div class="jdpd-rule-col jdpd-col-cb"></div>
            <div class="jdpd-rule-col jdpd-col-drag"></div>
            <div class="jdpd-rule-col jdpd-col-name"><?php esc_html_e( 'Name', 'jezweb-dynamic-pricing' ); ?></div>
            <div class="jdpd-rule-col jdpd-col-type"><?php esc_html_e( 'Type', 'jezweb-dynamic-pricing' ); ?></div>
            <div class="jdpd-rule-col jdpd-col-discount"><?php esc_html_e( 'Discount', 'jezweb-dynamic-pricing' ); ?></div>
            <div class="jdpd-rule-col jdpd-col-status"><?php esc_html_e( 'Status', 'jezweb-dynamic-pricing' ); ?></div>
            <div class="jdpd-rule-col jdpd-col-priority"><?php esc_html_e( 'Priority', 'jezweb-dynamic-pricing' ); ?></div>
            <div class="jdpd-rule-col jdpd-col-usage"><?php esc_html_e( 'Usage', 'jezweb-dynamic-pricing' ); ?></div>
        </div>

        <!-- Rules List -->
        <div class="jdpd-rules-list" id="the-list">
            <?php if ( empty( $rules ) ) : ?>
                <div class="jdpd-no-rules">
                    <p>
                        <?php esc_html_e( 'No rules found.', 'jezweb-dynamic-pricing' ); ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=jdpd-add-rule' ) ); ?>">
                            <?php esc_html_e( 'Create your first rule', 'jezweb-dynamic-pricing' ); ?>
                        </a>
                    </p>
                </div>
            <?php else : ?>
                <?php foreach ( $rules as $rule ) : ?>
                    <?php
                    $rule_types = jdpd_get_rule_types();
                    $discount_types = jdpd_get_discount_types();
                    $edit_url = admin_url( 'admin.php?page=jdpd-add-rule&rule_id=' . $rule->id );
                    $delete_url = wp_nonce_url(
                        admin_url( 'admin.php?page=jdpd-rules&action=delete&rule_id=' . $rule->id ),
                        'jdpd_rule_action'
                    );
                    $duplicate_url = wp_nonce_url(
                        admin_url( 'admin.php?page=jdpd-rules&action=duplicate&rule_id=' . $rule->id ),
                        'jdpd_rule_action'
                    );
                    ?>
                    <div class="jdpd-rule-item" data-rule-id="<?php echo esc_attr( $rule->id ); ?>">
                        <div class="jdpd-rule-col jdpd-col-cb">
                            <input type="checkbox" name="rule_ids[]" value="<?php echo esc_attr( $rule->id ); ?>">
                        </div>
                        <div class="jdpd-rule-col jdpd-col-drag">
                            <span class="jdpd-drag-handle dashicons dashicons-menu"></span>
                        </div>
                        <div class="jdpd-rule-col jdpd-col-name">
                            <span class="jdpd-mobile-label"><?php esc_html_e( 'Name:', 'jezweb-dynamic-pricing' ); ?></span>
                            <strong>
                                <a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $rule->name ); ?></a>
                            </strong>
                            <div class="jdpd-row-actions">
                                <a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'jezweb-dynamic-pricing' ); ?></a>
                                <span class="jdpd-sep">|</span>
                                <a href="<?php echo esc_url( $duplicate_url ); ?>"><?php esc_html_e( 'Duplicate', 'jezweb-dynamic-pricing' ); ?></a>
                                <span class="jdpd-sep">|</span>
                                <a href="<?php echo esc_url( $delete_url ); ?>" class="jdpd-delete-rule jdpd-delete"><?php esc_html_e( 'Delete', 'jezweb-dynamic-pricing' ); ?></a>
                            </div>
                        </div>
                        <div class="jdpd-rule-col jdpd-col-type">
                            <span class="jdpd-mobile-label"><?php esc_html_e( 'Type:', 'jezweb-dynamic-pricing' ); ?></span>
                            <span class="jdpd-badge jdpd-badge-<?php echo esc_attr( $rule->rule_type ); ?>">
                                <?php echo esc_html( $rule_types[ $rule->rule_type ] ?? $rule->rule_type ); ?>
                            </span>
                        </div>
                        <div class="jdpd-rule-col jdpd-col-discount">
                            <span class="jdpd-mobile-label"><?php esc_html_e( 'Discount:', 'jezweb-dynamic-pricing' ); ?></span>
                            <span class="jdpd-discount-value">
                                <?php
                                if ( 'percentage' === $rule->discount_type ) {
                                    echo esc_html( $rule->discount_value . '%' );
                                } elseif ( 'fixed' === $rule->discount_type ) {
                                    echo wc_price( $rule->discount_value ) . ' ' . esc_html__( 'off', 'jezweb-dynamic-pricing' );
                                } elseif ( 'fixed_price' === $rule->discount_type ) {
                                    echo wc_price( $rule->discount_value );
                                }
                                ?>
                            </span>
                        </div>
                        <div class="jdpd-rule-col jdpd-col-status">
                            <span class="jdpd-mobile-label"><?php esc_html_e( 'Status:', 'jezweb-dynamic-pricing' ); ?></span>
                            <label class="jdpd-status-toggle">
                                <input type="checkbox" class="jdpd-toggle-status"
                                       data-rule-id="<?php echo esc_attr( $rule->id ); ?>"
                                    <?php checked( $rule->status, 'active' ); ?>>
                                <span class="jdpd-toggle-slider"></span>
                            </label>
                        </div>
                        <div class="jdpd-rule-col jdpd-col-priority">
                            <span class="jdpd-mobile-label"><?php esc_html_e( 'Priority:', 'jezweb-dynamic-pricing' ); ?></span>
                            <span><?php echo esc_html( $rule->priority ); ?></span>
                        </div>
                        <div class="jdpd-rule-col jdpd-col-usage">
                            <span class="jdpd-mobile-label"><?php esc_html_e( 'Usage:', 'jezweb-dynamic-pricing' ); ?></span>
                            <span>
                                <?php
                                if ( $rule->usage_limit ) {
                                    printf( '%d / %d', $rule->usage_count, $rule->usage_limit );
                                } else {
                                    echo esc_html( $rule->usage_count );
                                }
                                ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
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
