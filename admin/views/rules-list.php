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
    <h1 class="wp-heading-inline"><?php esc_html_e( 'Discount Rules', 'jezweb-dynamic-pricing' ); ?></h1>
    <a href="<?php echo esc_url( admin_url( 'admin.php?page=jdpd-add-rule' ) ); ?>" class="page-title-action">
        <?php esc_html_e( 'Add New Rule', 'jezweb-dynamic-pricing' ); ?>
    </a>

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
        <form method="get" action="">
            <input type="hidden" name="page" value="jdpd-rules">

            <select name="status">
                <option value=""><?php esc_html_e( 'All Statuses', 'jezweb-dynamic-pricing' ); ?></option>
                <option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'jezweb-dynamic-pricing' ); ?></option>
                <option value="inactive" <?php selected( $status, 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'jezweb-dynamic-pricing' ); ?></option>
            </select>

            <select name="rule_type">
                <option value=""><?php esc_html_e( 'All Types', 'jezweb-dynamic-pricing' ); ?></option>
                <?php foreach ( jdpd_get_rule_types() as $type_key => $type_label ) : ?>
                    <option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $rule_type, $type_key ); ?>>
                        <?php echo esc_html( $type_label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>"
                   placeholder="<?php esc_attr_e( 'Search rules...', 'jezweb-dynamic-pricing' ); ?>">

            <button type="submit" class="button"><?php esc_html_e( 'Filter', 'jezweb-dynamic-pricing' ); ?></button>

            <?php if ( $status || $rule_type || $search ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=jdpd-rules' ) ); ?>" class="button">
                    <?php esc_html_e( 'Clear', 'jezweb-dynamic-pricing' ); ?>
                </a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Bulk Actions -->
    <form method="post" id="jdpd-rules-form">
        <?php wp_nonce_field( 'jdpd_bulk_action', 'jdpd_bulk_nonce' ); ?>

        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
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
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php
                        printf(
                            /* translators: %d: number of items */
                            esc_html( _n( '%d item', '%d items', $result['total'], 'jezweb-dynamic-pricing' ) ),
                            $result['total']
                        );
                        ?>
                    </span>
                    <span class="pagination-links">
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

        <table class="wp-list-table widefat fixed striped jdpd-rules-table">
            <thead>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <input type="checkbox" id="cb-select-all">
                    </td>
                    <th class="column-drag" width="30"></th>
                    <th class="column-name"><?php esc_html_e( 'Name', 'jezweb-dynamic-pricing' ); ?></th>
                    <th class="column-type"><?php esc_html_e( 'Type', 'jezweb-dynamic-pricing' ); ?></th>
                    <th class="column-discount"><?php esc_html_e( 'Discount', 'jezweb-dynamic-pricing' ); ?></th>
                    <th class="column-status"><?php esc_html_e( 'Status', 'jezweb-dynamic-pricing' ); ?></th>
                    <th class="column-priority"><?php esc_html_e( 'Priority', 'jezweb-dynamic-pricing' ); ?></th>
                    <th class="column-usage"><?php esc_html_e( 'Usage', 'jezweb-dynamic-pricing' ); ?></th>
                </tr>
            </thead>
            <tbody id="the-list">
                <?php if ( empty( $rules ) ) : ?>
                    <tr>
                        <td colspan="8">
                            <?php esc_html_e( 'No rules found.', 'jezweb-dynamic-pricing' ); ?>
                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=jdpd-add-rule' ) ); ?>">
                                <?php esc_html_e( 'Create your first rule', 'jezweb-dynamic-pricing' ); ?>
                            </a>
                        </td>
                    </tr>
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
                        <tr data-rule-id="<?php echo esc_attr( $rule->id ); ?>">
                            <th scope="row" class="check-column">
                                <input type="checkbox" name="rule_ids[]" value="<?php echo esc_attr( $rule->id ); ?>">
                            </th>
                            <td class="column-drag">
                                <span class="jdpd-drag-handle dashicons dashicons-menu"></span>
                            </td>
                            <td class="column-name">
                                <strong>
                                    <a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $rule->name ); ?></a>
                                </strong>
                                <div class="row-actions">
                                    <span class="edit">
                                        <a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'jezweb-dynamic-pricing' ); ?></a> |
                                    </span>
                                    <span class="duplicate">
                                        <a href="<?php echo esc_url( $duplicate_url ); ?>"><?php esc_html_e( 'Duplicate', 'jezweb-dynamic-pricing' ); ?></a> |
                                    </span>
                                    <span class="trash">
                                        <a href="<?php echo esc_url( $delete_url ); ?>" class="jdpd-delete-rule"><?php esc_html_e( 'Delete', 'jezweb-dynamic-pricing' ); ?></a>
                                    </span>
                                </div>
                            </td>
                            <td class="column-type">
                                <?php echo esc_html( $rule_types[ $rule->rule_type ] ?? $rule->rule_type ); ?>
                            </td>
                            <td class="column-discount">
                                <?php
                                if ( 'percentage' === $rule->discount_type ) {
                                    echo esc_html( $rule->discount_value . '%' );
                                } elseif ( 'fixed' === $rule->discount_type ) {
                                    echo wc_price( $rule->discount_value ) . ' ' . esc_html__( 'off', 'jezweb-dynamic-pricing' );
                                } elseif ( 'fixed_price' === $rule->discount_type ) {
                                    echo wc_price( $rule->discount_value );
                                }
                                ?>
                            </td>
                            <td class="column-status">
                                <label class="jdpd-status-toggle">
                                    <input type="checkbox" class="jdpd-toggle-status"
                                           data-rule-id="<?php echo esc_attr( $rule->id ); ?>"
                                        <?php checked( $rule->status, 'active' ); ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td class="column-priority">
                                <?php echo esc_html( $rule->priority ); ?>
                            </td>
                            <td class="column-usage">
                                <?php
                                if ( $rule->usage_limit ) {
                                    printf(
                                        '%d / %d',
                                        $rule->usage_count,
                                        $rule->usage_limit
                                    );
                                } else {
                                    echo esc_html( $rule->usage_count );
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </form>
</div>

<style>
.jdpd-rules-wrap .jdpd-filters {
    margin: 15px 0;
}
.jdpd-rules-wrap .jdpd-filters select,
.jdpd-rules-wrap .jdpd-filters input[type="search"] {
    margin-right: 5px;
}
.jdpd-rules-table .column-drag {
    cursor: move;
}
.jdpd-drag-handle {
    color: #c0c0c0;
    cursor: move;
}
.jdpd-status-toggle {
    position: relative;
    display: inline-block;
    width: 40px;
    height: 20px;
}
.jdpd-status-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
}
.jdpd-status-toggle .slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 20px;
}
.jdpd-status-toggle .slider:before {
    position: absolute;
    content: "";
    height: 14px;
    width: 14px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}
.jdpd-status-toggle input:checked + .slider {
    background-color: #2196F3;
}
.jdpd-status-toggle input:checked + .slider:before {
    transform: translateX(20px);
}
</style>
