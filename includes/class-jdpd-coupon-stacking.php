<?php
/**
 * Coupon Stacking Rules
 *
 * Control how multiple coupons interact with each other and dynamic pricing rules.
 *
 * @package    Jezweb_Dynamic_Pricing
 * @subpackage Includes
 * @since      1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * JDPD_Coupon_Stacking class.
 *
 * Features:
 * - Define coupon groups and stacking rules
 * - Control interaction between coupons and dynamic pricing
 * - Set maximum discount limits (absolute or percentage)
 * - Priority-based coupon application
 * - Exclusive vs stackable coupons
 * - Coupon usage analytics
 *
 * @since 1.4.0
 */
class JDPD_Coupon_Stacking {

    /**
     * Single instance of the class.
     *
     * @var JDPD_Coupon_Stacking
     */
    private static $instance = null;

    /**
     * Stacking rules cache.
     *
     * @var array
     */
    private $rules_cache = array();

    /**
     * Get single instance.
     *
     * @return JDPD_Coupon_Stacking
     */
    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'), 25);
        add_action('admin_init', array($this, 'register_settings'));

        // Coupon validation hooks
        add_filter('woocommerce_coupon_is_valid', array($this, 'validate_coupon_stacking'), 10, 3);
        add_filter('woocommerce_coupon_is_valid_for_cart', array($this, 'validate_coupon_for_cart'), 10, 2);

        // Coupon application hooks
        add_action('woocommerce_applied_coupon', array($this, 'on_coupon_applied'), 10, 1);
        add_action('woocommerce_removed_coupon', array($this, 'on_coupon_removed'), 10, 1);

        // Discount calculation hooks
        add_filter('woocommerce_coupon_get_discount_amount', array($this, 'adjust_discount_amount'), 10, 5);

        // Cart total modification
        add_action('woocommerce_cart_calculate_fees', array($this, 'apply_maximum_discount_limit'), 999);

        // Add coupon meta fields
        add_action('woocommerce_coupon_options', array($this, 'add_coupon_stacking_fields'), 10, 2);
        add_action('woocommerce_coupon_options_save', array($this, 'save_coupon_stacking_fields'), 10, 2);

        // Analytics tracking
        add_action('woocommerce_order_status_completed', array($this, 'track_coupon_usage'), 10, 1);

        // AJAX handlers
        add_action('wp_ajax_jdpd_get_stacking_rules', array($this, 'ajax_get_rules'));
        add_action('wp_ajax_jdpd_save_stacking_rule', array($this, 'ajax_save_rule'));
        add_action('wp_ajax_jdpd_delete_stacking_rule', array($this, 'ajax_delete_rule'));
    }

    /**
     * Add admin menu.
     */
    public function add_admin_menu() {
        add_submenu_page(
            'jezweb-dynamic-pricing',
            __('Coupon Stacking', 'jezweb-dynamic-pricing'),
            __('Coupon Stacking', 'jezweb-dynamic-pricing'),
            'manage_woocommerce',
            'jdpd-coupon-stacking',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Register settings.
     */
    public function register_settings() {
        register_setting('jdpd_coupon_stacking', 'jdpd_coupon_stacking_options');
    }

    /**
     * Render admin page.
     */
    public function render_admin_page() {
        $options = get_option('jdpd_coupon_stacking_options', $this->get_default_options());
        $rules = $this->get_stacking_rules();
        $coupon_groups = $this->get_coupon_groups();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Coupon Stacking Rules', 'jezweb-dynamic-pricing'); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields('jdpd_coupon_stacking'); ?>

                <h2><?php esc_html_e('Global Stacking Settings', 'jezweb-dynamic-pricing'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Coupon Stacking', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_coupon_stacking_options[enabled]" value="1"
                                    <?php checked(!empty($options['enabled'])); ?>>
                                <?php esc_html_e('Allow multiple coupons to be used together', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Maximum Coupons Per Order', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="number" name="jdpd_coupon_stacking_options[max_coupons]"
                                value="<?php echo esc_attr($options['max_coupons'] ?? 5); ?>" min="1" max="20">
                            <p class="description"><?php esc_html_e('Maximum number of coupons that can be applied to a single order.', 'jezweb-dynamic-pricing'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Maximum Total Discount', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="number" name="jdpd_coupon_stacking_options[max_discount_amount]"
                                value="<?php echo esc_attr($options['max_discount_amount'] ?? ''); ?>" min="0" step="0.01">
                            <?php echo esc_html(get_woocommerce_currency_symbol()); ?>
                            <p class="description"><?php esc_html_e('Maximum absolute discount amount. Leave empty for no limit.', 'jezweb-dynamic-pricing'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Maximum Discount Percentage', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="number" name="jdpd_coupon_stacking_options[max_discount_percent]"
                                value="<?php echo esc_attr($options['max_discount_percent'] ?? ''); ?>" min="0" max="100" step="0.1">%
                            <p class="description"><?php esc_html_e('Maximum discount as percentage of subtotal. Leave empty for no limit.', 'jezweb-dynamic-pricing'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Dynamic Pricing + Coupons', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <select name="jdpd_coupon_stacking_options[dynamic_pricing_mode]">
                                <option value="both" <?php selected($options['dynamic_pricing_mode'] ?? 'both', 'both'); ?>>
                                    <?php esc_html_e('Apply both (coupons stack with dynamic pricing)', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="best" <?php selected($options['dynamic_pricing_mode'] ?? 'both', 'best'); ?>>
                                    <?php esc_html_e('Apply best discount only', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="coupons_only" <?php selected($options['dynamic_pricing_mode'] ?? 'both', 'coupons_only'); ?>>
                                    <?php esc_html_e('Coupons override dynamic pricing', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="dynamic_only" <?php selected($options['dynamic_pricing_mode'] ?? 'both', 'dynamic_only'); ?>>
                                    <?php esc_html_e('Dynamic pricing overrides coupons', 'jezweb-dynamic-pricing'); ?>
                                </option>
                            </select>
                            <p class="description"><?php esc_html_e('How coupons interact with dynamic pricing rules.', 'jezweb-dynamic-pricing'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Discount Application Order', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <select name="jdpd_coupon_stacking_options[application_order]">
                                <option value="sequential" <?php selected($options['application_order'] ?? 'sequential', 'sequential'); ?>>
                                    <?php esc_html_e('Sequential (percentage discounts reduce base)', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="parallel" <?php selected($options['application_order'] ?? 'sequential', 'parallel'); ?>>
                                    <?php esc_html_e('Parallel (all discounts on original price)', 'jezweb-dynamic-pricing'); ?>
                                </option>
                            </select>
                            <p class="description"><?php esc_html_e('How multiple percentage discounts are calculated.', 'jezweb-dynamic-pricing'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>

            <h2><?php esc_html_e('Coupon Groups', 'jezweb-dynamic-pricing'); ?></h2>
            <p class="description"><?php esc_html_e('Define groups of coupons with specific stacking rules.', 'jezweb-dynamic-pricing'); ?></p>

            <div id="jdpd-coupon-groups">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Group Name', 'jezweb-dynamic-pricing'); ?></th>
                            <th><?php esc_html_e('Coupons', 'jezweb-dynamic-pricing'); ?></th>
                            <th><?php esc_html_e('Stacking Rule', 'jezweb-dynamic-pricing'); ?></th>
                            <th><?php esc_html_e('Priority', 'jezweb-dynamic-pricing'); ?></th>
                            <th><?php esc_html_e('Actions', 'jezweb-dynamic-pricing'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($coupon_groups)): ?>
                            <tr>
                                <td colspan="5"><?php esc_html_e('No coupon groups defined.', 'jezweb-dynamic-pricing'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($coupon_groups as $group): ?>
                                <tr data-group-id="<?php echo esc_attr($group['id']); ?>">
                                    <td><?php echo esc_html($group['name']); ?></td>
                                    <td><?php echo esc_html(implode(', ', $group['coupons'])); ?></td>
                                    <td><?php echo esc_html($this->get_rule_label($group['stacking_rule'])); ?></td>
                                    <td><?php echo esc_html($group['priority']); ?></td>
                                    <td>
                                        <a href="#" class="edit-group" data-group-id="<?php echo esc_attr($group['id']); ?>">
                                            <?php esc_html_e('Edit', 'jezweb-dynamic-pricing'); ?>
                                        </a> |
                                        <a href="#" class="delete-group" data-group-id="<?php echo esc_attr($group['id']); ?>">
                                            <?php esc_html_e('Delete', 'jezweb-dynamic-pricing'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <p>
                    <button type="button" class="button" id="add-coupon-group">
                        <?php esc_html_e('Add Coupon Group', 'jezweb-dynamic-pricing'); ?>
                    </button>
                </p>
            </div>

            <hr>

            <h2><?php esc_html_e('Stacking Rules', 'jezweb-dynamic-pricing'); ?></h2>
            <p class="description"><?php esc_html_e('Define specific rules for how coupon groups interact with each other.', 'jezweb-dynamic-pricing'); ?></p>

            <div id="jdpd-stacking-rules">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Rule Name', 'jezweb-dynamic-pricing'); ?></th>
                            <th><?php esc_html_e('Group A', 'jezweb-dynamic-pricing'); ?></th>
                            <th><?php esc_html_e('Interaction', 'jezweb-dynamic-pricing'); ?></th>
                            <th><?php esc_html_e('Group B', 'jezweb-dynamic-pricing'); ?></th>
                            <th><?php esc_html_e('Actions', 'jezweb-dynamic-pricing'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rules)): ?>
                            <tr>
                                <td colspan="5"><?php esc_html_e('No stacking rules defined.', 'jezweb-dynamic-pricing'); ?></td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rules as $rule): ?>
                                <tr data-rule-id="<?php echo esc_attr($rule['id']); ?>">
                                    <td><?php echo esc_html($rule['name']); ?></td>
                                    <td><?php echo esc_html($rule['group_a']); ?></td>
                                    <td><?php echo esc_html($this->get_interaction_label($rule['interaction'])); ?></td>
                                    <td><?php echo esc_html($rule['group_b']); ?></td>
                                    <td>
                                        <a href="#" class="edit-rule" data-rule-id="<?php echo esc_attr($rule['id']); ?>">
                                            <?php esc_html_e('Edit', 'jezweb-dynamic-pricing'); ?>
                                        </a> |
                                        <a href="#" class="delete-rule" data-rule-id="<?php echo esc_attr($rule['id']); ?>">
                                            <?php esc_html_e('Delete', 'jezweb-dynamic-pricing'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <p>
                    <button type="button" class="button" id="add-stacking-rule">
                        <?php esc_html_e('Add Stacking Rule', 'jezweb-dynamic-pricing'); ?>
                    </button>
                </p>
            </div>

            <hr>

            <h2><?php esc_html_e('Coupon Usage Analytics', 'jezweb-dynamic-pricing'); ?></h2>
            <?php $this->render_coupon_analytics(); ?>
        </div>

        <!-- Group Modal -->
        <div id="coupon-group-modal" class="jdpd-modal" style="display:none;">
            <div class="jdpd-modal-content">
                <h3><?php esc_html_e('Coupon Group', 'jezweb-dynamic-pricing'); ?></h3>
                <form id="coupon-group-form">
                    <input type="hidden" name="group_id" value="">
                    <table class="form-table">
                        <tr>
                            <th><label for="group_name"><?php esc_html_e('Group Name', 'jezweb-dynamic-pricing'); ?></label></th>
                            <td><input type="text" name="group_name" id="group_name" class="regular-text" required></td>
                        </tr>
                        <tr>
                            <th><label for="group_coupons"><?php esc_html_e('Coupons', 'jezweb-dynamic-pricing'); ?></label></th>
                            <td>
                                <select name="group_coupons[]" id="group_coupons" multiple class="regular-text">
                                    <?php
                                    $coupons = get_posts(array(
                                        'post_type' => 'shop_coupon',
                                        'posts_per_page' => -1,
                                        'post_status' => 'publish',
                                    ));
                                    foreach ($coupons as $coupon):
                                    ?>
                                        <option value="<?php echo esc_attr($coupon->post_title); ?>">
                                            <?php echo esc_html($coupon->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description"><?php esc_html_e('Hold Ctrl/Cmd to select multiple.', 'jezweb-dynamic-pricing'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="stacking_rule"><?php esc_html_e('Stacking Rule', 'jezweb-dynamic-pricing'); ?></label></th>
                            <td>
                                <select name="stacking_rule" id="stacking_rule">
                                    <option value="stackable"><?php esc_html_e('Stackable (can combine with others)', 'jezweb-dynamic-pricing'); ?></option>
                                    <option value="exclusive"><?php esc_html_e('Exclusive (cannot combine with others)', 'jezweb-dynamic-pricing'); ?></option>
                                    <option value="exclusive_same_group"><?php esc_html_e('Exclusive within group only', 'jezweb-dynamic-pricing'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="group_priority"><?php esc_html_e('Priority', 'jezweb-dynamic-pricing'); ?></label></th>
                            <td>
                                <input type="number" name="group_priority" id="group_priority" value="10" min="1" max="100">
                                <p class="description"><?php esc_html_e('Higher priority coupons are applied first.', 'jezweb-dynamic-pricing'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Save Group', 'jezweb-dynamic-pricing'); ?></button>
                        <button type="button" class="button jdpd-modal-close"><?php esc_html_e('Cancel', 'jezweb-dynamic-pricing'); ?></button>
                    </p>
                </form>
            </div>
        </div>

        <style>
            .jdpd-modal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 100000;
            }
            .jdpd-modal-content {
                background: #fff;
                max-width: 600px;
                margin: 100px auto;
                padding: 20px;
                border-radius: 5px;
            }
        </style>
        <?php
    }

    /**
     * Get default options.
     *
     * @return array
     */
    private function get_default_options() {
        return array(
            'enabled' => true,
            'max_coupons' => 5,
            'max_discount_amount' => '',
            'max_discount_percent' => '',
            'dynamic_pricing_mode' => 'both',
            'application_order' => 'sequential',
        );
    }

    /**
     * Add coupon stacking fields to coupon edit page.
     *
     * @param int    $coupon_id Coupon ID.
     * @param object $coupon    Coupon object.
     */
    public function add_coupon_stacking_fields($coupon_id, $coupon) {
        echo '<div class="options_group">';

        woocommerce_wp_checkbox(array(
            'id' => '_jdpd_exclusive_coupon',
            'label' => __('Exclusive Coupon', 'jezweb-dynamic-pricing'),
            'description' => __('This coupon cannot be combined with other coupons.', 'jezweb-dynamic-pricing'),
        ));

        woocommerce_wp_text_input(array(
            'id' => '_jdpd_coupon_priority',
            'label' => __('Priority', 'jezweb-dynamic-pricing'),
            'description' => __('Higher priority coupons are applied first. Default: 10', 'jezweb-dynamic-pricing'),
            'type' => 'number',
            'custom_attributes' => array(
                'min' => 1,
                'max' => 100,
            ),
            'placeholder' => '10',
        ));

        woocommerce_wp_select(array(
            'id' => '_jdpd_coupon_group',
            'label' => __('Coupon Group', 'jezweb-dynamic-pricing'),
            'description' => __('Assign to a coupon group for advanced stacking rules.', 'jezweb-dynamic-pricing'),
            'options' => $this->get_coupon_group_options(),
        ));

        woocommerce_wp_checkbox(array(
            'id' => '_jdpd_stack_with_dynamic',
            'label' => __('Stack with Dynamic Pricing', 'jezweb-dynamic-pricing'),
            'description' => __('Allow this coupon to stack with dynamic pricing discounts.', 'jezweb-dynamic-pricing'),
            'value' => 'yes',
        ));

        echo '</div>';
    }

    /**
     * Save coupon stacking fields.
     *
     * @param int    $coupon_id Coupon ID.
     * @param object $coupon    Coupon object.
     */
    public function save_coupon_stacking_fields($coupon_id, $coupon) {
        $exclusive = isset($_POST['_jdpd_exclusive_coupon']) ? 'yes' : 'no';
        $priority = isset($_POST['_jdpd_coupon_priority']) ? absint($_POST['_jdpd_coupon_priority']) : 10;
        $group = isset($_POST['_jdpd_coupon_group']) ? sanitize_text_field($_POST['_jdpd_coupon_group']) : '';
        $stack_dynamic = isset($_POST['_jdpd_stack_with_dynamic']) ? 'yes' : 'no';

        update_post_meta($coupon_id, '_jdpd_exclusive_coupon', $exclusive);
        update_post_meta($coupon_id, '_jdpd_coupon_priority', $priority);
        update_post_meta($coupon_id, '_jdpd_coupon_group', $group);
        update_post_meta($coupon_id, '_jdpd_stack_with_dynamic', $stack_dynamic);
    }

    /**
     * Validate coupon stacking.
     *
     * @param bool       $valid   Is valid.
     * @param WC_Coupon  $coupon  Coupon object.
     * @param WC_Discounts $discounts Discounts object.
     * @return bool
     */
    public function validate_coupon_stacking($valid, $coupon, $discounts) {
        if (!$valid) {
            return $valid;
        }

        $options = get_option('jdpd_coupon_stacking_options', $this->get_default_options());

        if (empty($options['enabled'])) {
            return $valid;
        }

        $cart = WC()->cart;
        if (!$cart) {
            return $valid;
        }

        $applied_coupons = $cart->get_applied_coupons();
        $coupon_code = $coupon->get_code();

        // Check if already applied
        if (in_array($coupon_code, $applied_coupons)) {
            return $valid;
        }

        // Check maximum coupons limit
        $max_coupons = intval($options['max_coupons'] ?? 5);
        if (count($applied_coupons) >= $max_coupons) {
            throw new Exception(
                sprintf(
                    __('Maximum of %d coupons allowed per order.', 'jezweb-dynamic-pricing'),
                    $max_coupons
                )
            );
        }

        // Check if new coupon is exclusive
        $is_exclusive = $this->is_exclusive_coupon($coupon);
        if ($is_exclusive && !empty($applied_coupons)) {
            throw new Exception(
                __('This coupon cannot be combined with other coupons.', 'jezweb-dynamic-pricing')
            );
        }

        // Check if any applied coupon is exclusive
        foreach ($applied_coupons as $applied_code) {
            $applied_coupon = new WC_Coupon($applied_code);
            if ($this->is_exclusive_coupon($applied_coupon)) {
                throw new Exception(
                    sprintf(
                        __('Cannot apply this coupon. "%s" is an exclusive coupon.', 'jezweb-dynamic-pricing'),
                        $applied_code
                    )
                );
            }
        }

        // Check group-level stacking rules
        $valid = $this->validate_group_stacking($coupon, $applied_coupons);

        return $valid;
    }

    /**
     * Check if coupon is exclusive.
     *
     * @param WC_Coupon $coupon Coupon object.
     * @return bool
     */
    private function is_exclusive_coupon($coupon) {
        $exclusive = get_post_meta($coupon->get_id(), '_jdpd_exclusive_coupon', true);
        return $exclusive === 'yes';
    }

    /**
     * Validate group stacking rules.
     *
     * @param WC_Coupon $coupon          New coupon.
     * @param array     $applied_coupons Applied coupons.
     * @return bool
     */
    private function validate_group_stacking($coupon, $applied_coupons) {
        $new_group = get_post_meta($coupon->get_id(), '_jdpd_coupon_group', true);

        if (empty($new_group)) {
            return true;
        }

        $groups = $this->get_coupon_groups();
        $new_group_data = null;

        foreach ($groups as $group) {
            if ($group['id'] === $new_group) {
                $new_group_data = $group;
                break;
            }
        }

        if (!$new_group_data) {
            return true;
        }

        // Check group stacking rule
        if ($new_group_data['stacking_rule'] === 'exclusive') {
            if (!empty($applied_coupons)) {
                throw new Exception(
                    __('This coupon group cannot be combined with other coupons.', 'jezweb-dynamic-pricing')
                );
            }
        }

        if ($new_group_data['stacking_rule'] === 'exclusive_same_group') {
            foreach ($applied_coupons as $applied_code) {
                $applied_coupon = new WC_Coupon($applied_code);
                $applied_group = get_post_meta($applied_coupon->get_id(), '_jdpd_coupon_group', true);

                if ($applied_group === $new_group) {
                    throw new Exception(
                        __('Only one coupon from this group can be used per order.', 'jezweb-dynamic-pricing')
                    );
                }
            }
        }

        // Check inter-group rules
        $rules = $this->get_stacking_rules();
        foreach ($rules as $rule) {
            if (!$this->check_rule_compatibility($rule, $new_group, $applied_coupons)) {
                throw new Exception(
                    sprintf(
                        __('This coupon cannot be combined with coupons from the "%s" group.', 'jezweb-dynamic-pricing'),
                        $rule['group_b']
                    )
                );
            }
        }

        return true;
    }

    /**
     * Check rule compatibility.
     *
     * @param array  $rule            Stacking rule.
     * @param string $new_group       New coupon group.
     * @param array  $applied_coupons Applied coupons.
     * @return bool
     */
    private function check_rule_compatibility($rule, $new_group, $applied_coupons) {
        if ($rule['group_a'] !== $new_group && $rule['group_b'] !== $new_group) {
            return true;
        }

        $other_group = $rule['group_a'] === $new_group ? $rule['group_b'] : $rule['group_a'];

        foreach ($applied_coupons as $applied_code) {
            $applied_coupon = new WC_Coupon($applied_code);
            $applied_group = get_post_meta($applied_coupon->get_id(), '_jdpd_coupon_group', true);

            if ($applied_group === $other_group) {
                if ($rule['interaction'] === 'block') {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Validate coupon for cart with dynamic pricing consideration.
     *
     * @param bool      $valid  Is valid.
     * @param WC_Coupon $coupon Coupon object.
     * @return bool
     */
    public function validate_coupon_for_cart($valid, $coupon) {
        if (!$valid) {
            return $valid;
        }

        $options = get_option('jdpd_coupon_stacking_options', $this->get_default_options());
        $stack_with_dynamic = get_post_meta($coupon->get_id(), '_jdpd_stack_with_dynamic', true);

        // Check dynamic pricing interaction mode
        if ($options['dynamic_pricing_mode'] === 'coupons_only') {
            // Coupons always valid, dynamic pricing will be disabled
            return $valid;
        }

        if ($options['dynamic_pricing_mode'] === 'dynamic_only') {
            // Check if cart has dynamic pricing applied
            if ($this->cart_has_dynamic_pricing() && $stack_with_dynamic !== 'yes') {
                throw new Exception(
                    __('Coupons cannot be applied when dynamic pricing is active.', 'jezweb-dynamic-pricing')
                );
            }
        }

        return $valid;
    }

    /**
     * Check if cart has dynamic pricing applied.
     *
     * @return bool
     */
    private function cart_has_dynamic_pricing() {
        $cart = WC()->cart;
        if (!$cart) {
            return false;
        }

        foreach ($cart->get_cart() as $cart_item) {
            if (!empty($cart_item['jdpd_discount'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adjust discount amount based on stacking rules.
     *
     * @param float     $discount       Discount amount.
     * @param float     $discounting    Amount being discounted.
     * @param array     $cart_item      Cart item.
     * @param bool      $single         Is single item.
     * @param WC_Coupon $coupon         Coupon object.
     * @return float
     */
    public function adjust_discount_amount($discount, $discounting, $cart_item, $single, $coupon) {
        $options = get_option('jdpd_coupon_stacking_options', $this->get_default_options());

        // Check dynamic pricing interaction
        if ($options['dynamic_pricing_mode'] === 'best' && !empty($cart_item['jdpd_discount'])) {
            $dynamic_discount = floatval($cart_item['jdpd_discount']);

            // Return 0 if dynamic pricing gives better discount
            if ($dynamic_discount >= $discount) {
                return 0;
            }
        }

        return $discount;
    }

    /**
     * Apply maximum discount limit to cart.
     *
     * @param WC_Cart $cart Cart object.
     */
    public function apply_maximum_discount_limit($cart) {
        $options = get_option('jdpd_coupon_stacking_options', $this->get_default_options());

        $max_amount = floatval($options['max_discount_amount'] ?? 0);
        $max_percent = floatval($options['max_discount_percent'] ?? 0);

        if (empty($max_amount) && empty($max_percent)) {
            return;
        }

        $subtotal = $cart->get_subtotal();
        $total_discount = $cart->get_discount_total();

        // Calculate maximum allowed discount
        $max_allowed = PHP_FLOAT_MAX;

        if (!empty($max_amount)) {
            $max_allowed = min($max_allowed, $max_amount);
        }

        if (!empty($max_percent)) {
            $percent_limit = ($subtotal * $max_percent) / 100;
            $max_allowed = min($max_allowed, $percent_limit);
        }

        // If discount exceeds maximum, add adjustment fee
        if ($total_discount > $max_allowed) {
            $adjustment = $total_discount - $max_allowed;

            $cart->add_fee(
                __('Discount Cap Adjustment', 'jezweb-dynamic-pricing'),
                $adjustment,
                false
            );
        }
    }

    /**
     * Handle coupon application.
     *
     * @param string $coupon_code Coupon code.
     */
    public function on_coupon_applied($coupon_code) {
        // Sort applied coupons by priority
        $this->sort_applied_coupons();

        // Log application for analytics
        $this->log_coupon_event($coupon_code, 'applied');
    }

    /**
     * Handle coupon removal.
     *
     * @param string $coupon_code Coupon code.
     */
    public function on_coupon_removed($coupon_code) {
        $this->log_coupon_event($coupon_code, 'removed');
    }

    /**
     * Sort applied coupons by priority.
     */
    private function sort_applied_coupons() {
        $cart = WC()->cart;
        if (!$cart) {
            return;
        }

        $applied_coupons = $cart->get_applied_coupons();

        if (count($applied_coupons) < 2) {
            return;
        }

        // Get priorities
        $coupon_priorities = array();
        foreach ($applied_coupons as $code) {
            $coupon = new WC_Coupon($code);
            $priority = get_post_meta($coupon->get_id(), '_jdpd_coupon_priority', true);
            $coupon_priorities[$code] = intval($priority ?: 10);
        }

        // Sort by priority (higher first)
        arsort($coupon_priorities);

        // Reapply in sorted order
        $sorted_coupons = array_keys($coupon_priorities);

        // Update session
        WC()->session->set('applied_coupons', $sorted_coupons);
    }

    /**
     * Log coupon event for analytics.
     *
     * @param string $coupon_code Coupon code.
     * @param string $event       Event type.
     */
    private function log_coupon_event($coupon_code, $event) {
        $log = get_option('jdpd_coupon_analytics_log', array());

        $log[] = array(
            'coupon' => $coupon_code,
            'event' => $event,
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id(),
            'session_id' => WC()->session ? WC()->session->get_customer_id() : '',
        );

        // Keep only last 1000 entries
        if (count($log) > 1000) {
            $log = array_slice($log, -1000);
        }

        update_option('jdpd_coupon_analytics_log', $log);
    }

    /**
     * Track coupon usage on order completion.
     *
     * @param int $order_id Order ID.
     */
    public function track_coupon_usage($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $coupons = $order->get_coupon_codes();

        if (empty($coupons)) {
            return;
        }

        $stats = get_option('jdpd_coupon_stacking_stats', array());

        // Track individual coupon usage
        foreach ($coupons as $code) {
            if (!isset($stats['coupons'][$code])) {
                $stats['coupons'][$code] = array(
                    'usage_count' => 0,
                    'total_discount' => 0,
                    'stacked_with' => array(),
                );
            }

            $stats['coupons'][$code]['usage_count']++;

            // Get discount amount for this coupon
            foreach ($order->get_items('coupon') as $item) {
                if ($item->get_code() === $code) {
                    $stats['coupons'][$code]['total_discount'] += $item->get_discount();
                }
            }

            // Track stacking combinations
            $other_coupons = array_diff($coupons, array($code));
            foreach ($other_coupons as $other) {
                $combo = $code < $other ? "$code|$other" : "$other|$code";
                if (!isset($stats['coupons'][$code]['stacked_with'][$combo])) {
                    $stats['coupons'][$code]['stacked_with'][$combo] = 0;
                }
                $stats['coupons'][$code]['stacked_with'][$combo]++;
            }
        }

        // Track stacking patterns
        if (count($coupons) > 1) {
            sort($coupons);
            $pattern = implode('|', $coupons);

            if (!isset($stats['patterns'][$pattern])) {
                $stats['patterns'][$pattern] = array(
                    'count' => 0,
                    'total_discount' => 0,
                );
            }

            $stats['patterns'][$pattern]['count']++;
            $stats['patterns'][$pattern]['total_discount'] += $order->get_discount_total();
        }

        update_option('jdpd_coupon_stacking_stats', $stats);
    }

    /**
     * Render coupon analytics.
     */
    private function render_coupon_analytics() {
        $stats = get_option('jdpd_coupon_stacking_stats', array());
        ?>
        <div class="jdpd-analytics-section">
            <h3><?php esc_html_e('Most Used Coupons', 'jezweb-dynamic-pricing'); ?></h3>
            <?php if (empty($stats['coupons'])): ?>
                <p><?php esc_html_e('No coupon usage data yet.', 'jezweb-dynamic-pricing'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Coupon', 'jezweb-dynamic-pricing'); ?></th>
                            <th><?php esc_html_e('Times Used', 'jezweb-dynamic-pricing'); ?></th>
                            <th><?php esc_html_e('Total Discount', 'jezweb-dynamic-pricing'); ?></th>
                            <th><?php esc_html_e('Avg per Use', 'jezweb-dynamic-pricing'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Sort by usage
                        uasort($stats['coupons'], function($a, $b) {
                            return $b['usage_count'] - $a['usage_count'];
                        });

                        $count = 0;
                        foreach ($stats['coupons'] as $code => $data):
                            if ($count++ >= 10) break;
                            $avg = $data['usage_count'] > 0 ? $data['total_discount'] / $data['usage_count'] : 0;
                        ?>
                            <tr>
                                <td><?php echo esc_html($code); ?></td>
                                <td><?php echo esc_html($data['usage_count']); ?></td>
                                <td><?php echo wc_price($data['total_discount']); ?></td>
                                <td><?php echo wc_price($avg); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h3><?php esc_html_e('Popular Stacking Combinations', 'jezweb-dynamic-pricing'); ?></h3>
            <?php if (empty($stats['patterns'])): ?>
                <p><?php esc_html_e('No stacking patterns recorded yet.', 'jezweb-dynamic-pricing'); ?></p>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Coupon Combination', 'jezweb-dynamic-pricing'); ?></th>
                            <th><?php esc_html_e('Times Used', 'jezweb-dynamic-pricing'); ?></th>
                            <th><?php esc_html_e('Total Discount', 'jezweb-dynamic-pricing'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        // Sort by usage
                        uasort($stats['patterns'], function($a, $b) {
                            return $b['count'] - $a['count'];
                        });

                        $count = 0;
                        foreach ($stats['patterns'] as $pattern => $data):
                            if ($count++ >= 10) break;
                            $coupons = str_replace('|', ' + ', $pattern);
                        ?>
                            <tr>
                                <td><?php echo esc_html($coupons); ?></td>
                                <td><?php echo esc_html($data['count']); ?></td>
                                <td><?php echo wc_price($data['total_discount']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Get coupon groups.
     *
     * @return array
     */
    public function get_coupon_groups() {
        return get_option('jdpd_coupon_groups', array());
    }

    /**
     * Get coupon group options for select field.
     *
     * @return array
     */
    private function get_coupon_group_options() {
        $groups = $this->get_coupon_groups();
        $options = array('' => __('No group', 'jezweb-dynamic-pricing'));

        foreach ($groups as $group) {
            $options[$group['id']] = $group['name'];
        }

        return $options;
    }

    /**
     * Get stacking rules.
     *
     * @return array
     */
    public function get_stacking_rules() {
        if (!empty($this->rules_cache)) {
            return $this->rules_cache;
        }

        $this->rules_cache = get_option('jdpd_stacking_rules', array());
        return $this->rules_cache;
    }

    /**
     * Get rule label.
     *
     * @param string $rule Rule key.
     * @return string
     */
    private function get_rule_label($rule) {
        $labels = array(
            'stackable' => __('Stackable', 'jezweb-dynamic-pricing'),
            'exclusive' => __('Exclusive', 'jezweb-dynamic-pricing'),
            'exclusive_same_group' => __('Exclusive within group', 'jezweb-dynamic-pricing'),
        );

        return $labels[$rule] ?? $rule;
    }

    /**
     * Get interaction label.
     *
     * @param string $interaction Interaction key.
     * @return string
     */
    private function get_interaction_label($interaction) {
        $labels = array(
            'allow' => __('Can stack with', 'jezweb-dynamic-pricing'),
            'block' => __('Cannot stack with', 'jezweb-dynamic-pricing'),
            'best_only' => __('Best discount only', 'jezweb-dynamic-pricing'),
        );

        return $labels[$interaction] ?? $interaction;
    }

    /**
     * AJAX: Get stacking rules.
     */
    public function ajax_get_rules() {
        check_ajax_referer('jdpd_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'jezweb-dynamic-pricing')));
        }

        wp_send_json_success(array(
            'rules' => $this->get_stacking_rules(),
            'groups' => $this->get_coupon_groups(),
        ));
    }

    /**
     * AJAX: Save stacking rule.
     */
    public function ajax_save_rule() {
        check_ajax_referer('jdpd_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'jezweb-dynamic-pricing')));
        }

        $rule_id = sanitize_text_field($_POST['rule_id'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $group_a = sanitize_text_field($_POST['group_a'] ?? '');
        $group_b = sanitize_text_field($_POST['group_b'] ?? '');
        $interaction = sanitize_text_field($_POST['interaction'] ?? 'allow');

        if (empty($name) || empty($group_a) || empty($group_b)) {
            wp_send_json_error(array('message' => __('All fields are required.', 'jezweb-dynamic-pricing')));
        }

        $rules = $this->get_stacking_rules();

        $rule_data = array(
            'id' => $rule_id ?: 'rule_' . uniqid(),
            'name' => $name,
            'group_a' => $group_a,
            'group_b' => $group_b,
            'interaction' => $interaction,
        );

        if ($rule_id) {
            // Update existing
            foreach ($rules as $key => $rule) {
                if ($rule['id'] === $rule_id) {
                    $rules[$key] = $rule_data;
                    break;
                }
            }
        } else {
            // Add new
            $rules[] = $rule_data;
        }

        update_option('jdpd_stacking_rules', $rules);
        $this->rules_cache = $rules;

        wp_send_json_success(array('rule' => $rule_data));
    }

    /**
     * AJAX: Delete stacking rule.
     */
    public function ajax_delete_rule() {
        check_ajax_referer('jdpd_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'jezweb-dynamic-pricing')));
        }

        $rule_id = sanitize_text_field($_POST['rule_id'] ?? '');

        if (empty($rule_id)) {
            wp_send_json_error(array('message' => __('Rule ID required.', 'jezweb-dynamic-pricing')));
        }

        $rules = $this->get_stacking_rules();

        foreach ($rules as $key => $rule) {
            if ($rule['id'] === $rule_id) {
                unset($rules[$key]);
                break;
            }
        }

        $rules = array_values($rules);
        update_option('jdpd_stacking_rules', $rules);
        $this->rules_cache = $rules;

        wp_send_json_success();
    }
}

// Initialize the class
JDPD_Coupon_Stacking::get_instance();
