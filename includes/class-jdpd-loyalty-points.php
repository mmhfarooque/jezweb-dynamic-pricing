<?php
/**
 * Loyalty Points System
 *
 * Complete points-based loyalty program for WooCommerce.
 *
 * @package    Jezweb_Dynamic_Pricing
 * @subpackage Includes
 * @since      1.5.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * JDPD_Loyalty_Points class.
 *
 * Features:
 * - Earn points on purchases
 * - Redeem points for discounts
 * - Points multiplier events
 * - Points expiration
 * - Points history tracking
 * - Bonus points for actions (registration, reviews, referrals)
 * - VIP tiers based on points earned
 *
 * @since 1.5.0
 */
class JDPD_Loyalty_Points {

    /**
     * Single instance of the class.
     *
     * @var JDPD_Loyalty_Points
     */
    private static $instance = null;

    /**
     * Points table name.
     *
     * @var string
     */
    private $table_name;

    /**
     * Points log table name.
     *
     * @var string
     */
    private $log_table;

    /**
     * Get single instance.
     *
     * @return JDPD_Loyalty_Points
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
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'jdpd_loyalty_points';
        $this->log_table = $wpdb->prefix . 'jdpd_loyalty_log';
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Admin hooks
        add_action('admin_menu', array($this, 'add_admin_menu'), 25);
        add_action('admin_init', array($this, 'register_settings'));

        // Earn points on purchase
        add_action('woocommerce_order_status_completed', array($this, 'award_purchase_points'), 10, 1);
        add_action('woocommerce_order_status_processing', array($this, 'award_purchase_points'), 10, 1);

        // Deduct points on refund/cancel
        add_action('woocommerce_order_status_refunded', array($this, 'deduct_refund_points'), 10, 1);
        add_action('woocommerce_order_status_cancelled', array($this, 'deduct_refund_points'), 10, 1);

        // Bonus points actions
        add_action('user_register', array($this, 'award_registration_points'), 10, 1);
        add_action('comment_post', array($this, 'award_review_points'), 10, 3);

        // Redeem points at checkout
        add_action('woocommerce_review_order_before_payment', array($this, 'display_points_redemption'));
        add_action('woocommerce_cart_calculate_fees', array($this, 'apply_points_discount'));

        // AJAX handlers
        add_action('wp_ajax_jdpd_apply_points', array($this, 'ajax_apply_points'));
        add_action('wp_ajax_jdpd_remove_points', array($this, 'ajax_remove_points'));
        add_action('wp_ajax_jdpd_get_points_balance', array($this, 'ajax_get_balance'));
        add_action('wp_ajax_jdpd_admin_adjust_points', array($this, 'ajax_admin_adjust'));

        // Frontend display
        add_action('woocommerce_before_my_account', array($this, 'display_points_summary'));
        add_shortcode('jdpd_points_balance', array($this, 'points_balance_shortcode'));
        add_shortcode('jdpd_points_history', array($this, 'points_history_shortcode'));

        // Show potential points on product page
        add_action('woocommerce_single_product_summary', array($this, 'display_product_points'), 25);
        add_action('woocommerce_after_shop_loop_item_title', array($this, 'display_product_points_loop'), 15);

        // Show points in cart
        add_action('woocommerce_cart_totals_before_order_total', array($this, 'display_cart_points'));

        // Admin user profile
        add_action('show_user_profile', array($this, 'display_user_points_admin'));
        add_action('edit_user_profile', array($this, 'display_user_points_admin'));

        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Points expiration cron
        add_action('jdpd_points_expiration_check', array($this, 'process_expired_points'));

        // Email notifications
        add_action('jdpd_points_earned', array($this, 'send_points_earned_email'), 10, 3);
        add_action('jdpd_points_expiring_soon', array($this, 'send_expiring_points_email'), 10, 2);
    }

    /**
     * Create database tables.
     */
    public static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Points balance table
        $table_points = $wpdb->prefix . 'jdpd_loyalty_points';
        $sql_points = "CREATE TABLE IF NOT EXISTS $table_points (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            points_balance int(11) NOT NULL DEFAULT 0,
            points_earned int(11) NOT NULL DEFAULT 0,
            points_redeemed int(11) NOT NULL DEFAULT 0,
            points_expired int(11) NOT NULL DEFAULT 0,
            tier varchar(50) DEFAULT 'bronze',
            last_activity datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id),
            KEY tier (tier),
            KEY points_balance (points_balance)
        ) $charset_collate;";

        // Points log table
        $table_log = $wpdb->prefix . 'jdpd_loyalty_log';
        $sql_log = "CREATE TABLE IF NOT EXISTS $table_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            points int(11) NOT NULL,
            type varchar(50) NOT NULL,
            description varchar(255) DEFAULT NULL,
            order_id bigint(20) unsigned DEFAULT NULL,
            expires_at datetime DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY type (type),
            KEY order_id (order_id),
            KEY expires_at (expires_at),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql_points);
        dbDelta($sql_log);
    }

    /**
     * Add admin menu.
     */
    public function add_admin_menu() {
        add_submenu_page(
            'jezweb-dynamic-pricing',
            __('Loyalty Points', 'jezweb-dynamic-pricing'),
            __('Loyalty Points', 'jezweb-dynamic-pricing'),
            'manage_woocommerce',
            'jdpd-loyalty-points',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Register settings.
     */
    public function register_settings() {
        register_setting('jdpd_loyalty_points', 'jdpd_loyalty_options');
    }

    /**
     * Render admin page.
     */
    public function render_admin_page() {
        $options = get_option('jdpd_loyalty_options', $this->get_default_options());
        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Loyalty Points Program', 'jezweb-dynamic-pricing'); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=jdpd-loyalty-points&tab=settings" class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Settings', 'jezweb-dynamic-pricing'); ?>
                </a>
                <a href="?page=jdpd-loyalty-points&tab=tiers" class="nav-tab <?php echo $active_tab === 'tiers' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('VIP Tiers', 'jezweb-dynamic-pricing'); ?>
                </a>
                <a href="?page=jdpd-loyalty-points&tab=multipliers" class="nav-tab <?php echo $active_tab === 'multipliers' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Multipliers', 'jezweb-dynamic-pricing'); ?>
                </a>
                <a href="?page=jdpd-loyalty-points&tab=members" class="nav-tab <?php echo $active_tab === 'members' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Members', 'jezweb-dynamic-pricing'); ?>
                </a>
                <a href="?page=jdpd-loyalty-points&tab=reports" class="nav-tab <?php echo $active_tab === 'reports' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Reports', 'jezweb-dynamic-pricing'); ?>
                </a>
            </nav>

            <div class="tab-content">
                <?php
                switch ($active_tab) {
                    case 'tiers':
                        $this->render_tiers_tab($options);
                        break;
                    case 'multipliers':
                        $this->render_multipliers_tab($options);
                        break;
                    case 'members':
                        $this->render_members_tab();
                        break;
                    case 'reports':
                        $this->render_reports_tab();
                        break;
                    default:
                        $this->render_settings_tab($options);
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings tab.
     *
     * @param array $options Current options.
     */
    private function render_settings_tab($options) {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('jdpd_loyalty_points'); ?>

            <h2><?php esc_html_e('General Settings', 'jezweb-dynamic-pricing'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable Loyalty Program', 'jezweb-dynamic-pricing'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="jdpd_loyalty_options[enabled]" value="1"
                                <?php checked(!empty($options['enabled'])); ?>>
                            <?php esc_html_e('Enable points-based loyalty program', 'jezweb-dynamic-pricing'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Points Name', 'jezweb-dynamic-pricing'); ?></th>
                    <td>
                        <input type="text" name="jdpd_loyalty_options[points_name]"
                            value="<?php echo esc_attr($options['points_name'] ?? 'Points'); ?>" class="regular-text">
                        <p class="description"><?php esc_html_e('What to call your points (e.g., Points, Rewards, Coins).', 'jezweb-dynamic-pricing'); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Earning Points', 'jezweb-dynamic-pricing'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Points per Dollar Spent', 'jezweb-dynamic-pricing'); ?></th>
                    <td>
                        <input type="number" name="jdpd_loyalty_options[points_per_dollar]"
                            value="<?php echo esc_attr($options['points_per_dollar'] ?? 1); ?>" min="0" step="0.1">
                        <p class="description"><?php esc_html_e('Points earned for every dollar spent.', 'jezweb-dynamic-pricing'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Round Points', 'jezweb-dynamic-pricing'); ?></th>
                    <td>
                        <select name="jdpd_loyalty_options[round_points]">
                            <option value="floor" <?php selected($options['round_points'] ?? 'floor', 'floor'); ?>>
                                <?php esc_html_e('Round down', 'jezweb-dynamic-pricing'); ?>
                            </option>
                            <option value="ceil" <?php selected($options['round_points'] ?? 'floor', 'ceil'); ?>>
                                <?php esc_html_e('Round up', 'jezweb-dynamic-pricing'); ?>
                            </option>
                            <option value="round" <?php selected($options['round_points'] ?? 'floor', 'round'); ?>>
                                <?php esc_html_e('Round to nearest', 'jezweb-dynamic-pricing'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Registration Bonus', 'jezweb-dynamic-pricing'); ?></th>
                    <td>
                        <input type="number" name="jdpd_loyalty_options[registration_bonus]"
                            value="<?php echo esc_attr($options['registration_bonus'] ?? 100); ?>" min="0">
                        <p class="description"><?php esc_html_e('Bonus points for new registrations.', 'jezweb-dynamic-pricing'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Review Bonus', 'jezweb-dynamic-pricing'); ?></th>
                    <td>
                        <input type="number" name="jdpd_loyalty_options[review_bonus]"
                            value="<?php echo esc_attr($options['review_bonus'] ?? 50); ?>" min="0">
                        <p class="description"><?php esc_html_e('Bonus points for leaving a product review.', 'jezweb-dynamic-pricing'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Referral Bonus', 'jezweb-dynamic-pricing'); ?></th>
                    <td>
                        <input type="number" name="jdpd_loyalty_options[referral_bonus]"
                            value="<?php echo esc_attr($options['referral_bonus'] ?? 200); ?>" min="0">
                        <p class="description"><?php esc_html_e('Bonus points for successful referrals.', 'jezweb-dynamic-pricing'); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Redeeming Points', 'jezweb-dynamic-pricing'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Points Value', 'jezweb-dynamic-pricing'); ?></th>
                    <td>
                        <input type="number" name="jdpd_loyalty_options[points_value]"
                            value="<?php echo esc_attr($options['points_value'] ?? 100); ?>" min="1">
                        <?php esc_html_e('points =', 'jezweb-dynamic-pricing'); ?>
                        <?php echo get_woocommerce_currency_symbol(); ?>
                        <input type="number" name="jdpd_loyalty_options[points_monetary_value]"
                            value="<?php echo esc_attr($options['points_monetary_value'] ?? 1); ?>" min="0.01" step="0.01" style="width:80px;">
                        <p class="description"><?php esc_html_e('How much are points worth when redeemed.', 'jezweb-dynamic-pricing'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Minimum Points to Redeem', 'jezweb-dynamic-pricing'); ?></th>
                    <td>
                        <input type="number" name="jdpd_loyalty_options[min_redeem]"
                            value="<?php echo esc_attr($options['min_redeem'] ?? 100); ?>" min="0">
                        <p class="description"><?php esc_html_e('Minimum points required before redemption.', 'jezweb-dynamic-pricing'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Maximum Discount', 'jezweb-dynamic-pricing'); ?></th>
                    <td>
                        <input type="number" name="jdpd_loyalty_options[max_discount_percent]"
                            value="<?php echo esc_attr($options['max_discount_percent'] ?? 50); ?>" min="0" max="100">%
                        <p class="description"><?php esc_html_e('Maximum percentage of order that can be paid with points.', 'jezweb-dynamic-pricing'); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php esc_html_e('Points Expiration', 'jezweb-dynamic-pricing'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Points Expire', 'jezweb-dynamic-pricing'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="jdpd_loyalty_options[points_expire]" value="1"
                                <?php checked(!empty($options['points_expire'])); ?>>
                            <?php esc_html_e('Enable points expiration', 'jezweb-dynamic-pricing'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Expiration Period', 'jezweb-dynamic-pricing'); ?></th>
                    <td>
                        <input type="number" name="jdpd_loyalty_options[expiration_days]"
                            value="<?php echo esc_attr($options['expiration_days'] ?? 365); ?>" min="1">
                        <?php esc_html_e('days', 'jezweb-dynamic-pricing'); ?>
                        <p class="description"><?php esc_html_e('Points expire this many days after being earned.', 'jezweb-dynamic-pricing'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Expiration Warning', 'jezweb-dynamic-pricing'); ?></th>
                    <td>
                        <input type="number" name="jdpd_loyalty_options[expiration_warning_days]"
                            value="<?php echo esc_attr($options['expiration_warning_days'] ?? 30); ?>" min="1">
                        <?php esc_html_e('days before', 'jezweb-dynamic-pricing'); ?>
                        <p class="description"><?php esc_html_e('Send email warning this many days before points expire.', 'jezweb-dynamic-pricing'); ?></p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    /**
     * Render VIP tiers tab.
     *
     * @param array $options Current options.
     */
    private function render_tiers_tab($options) {
        $tiers = $options['tiers'] ?? $this->get_default_tiers();
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('jdpd_loyalty_points'); ?>
            <input type="hidden" name="jdpd_loyalty_options[enabled]" value="<?php echo esc_attr($options['enabled'] ?? ''); ?>">

            <h2><?php esc_html_e('VIP Tier Settings', 'jezweb-dynamic-pricing'); ?></h2>
            <p><?php esc_html_e('Configure VIP tiers based on lifetime points earned. Higher tiers earn more points per purchase.', 'jezweb-dynamic-pricing'); ?></p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Tier Name', 'jezweb-dynamic-pricing'); ?></th>
                        <th><?php esc_html_e('Points Required', 'jezweb-dynamic-pricing'); ?></th>
                        <th><?php esc_html_e('Points Multiplier', 'jezweb-dynamic-pricing'); ?></th>
                        <th><?php esc_html_e('Extra Benefits', 'jezweb-dynamic-pricing'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tiers as $key => $tier): ?>
                        <tr>
                            <td>
                                <input type="text" name="jdpd_loyalty_options[tiers][<?php echo esc_attr($key); ?>][name]"
                                    value="<?php echo esc_attr($tier['name']); ?>" class="regular-text">
                            </td>
                            <td>
                                <input type="number" name="jdpd_loyalty_options[tiers][<?php echo esc_attr($key); ?>][points_required]"
                                    value="<?php echo esc_attr($tier['points_required']); ?>" min="0">
                            </td>
                            <td>
                                <input type="number" name="jdpd_loyalty_options[tiers][<?php echo esc_attr($key); ?>][multiplier]"
                                    value="<?php echo esc_attr($tier['multiplier']); ?>" min="1" max="10" step="0.1">x
                            </td>
                            <td>
                                <input type="text" name="jdpd_loyalty_options[tiers][<?php echo esc_attr($key); ?>][benefits]"
                                    value="<?php echo esc_attr($tier['benefits'] ?? ''); ?>" class="large-text"
                                    placeholder="<?php esc_attr_e('e.g., Free shipping, Early access', 'jezweb-dynamic-pricing'); ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    /**
     * Render multipliers tab.
     *
     * @param array $options Current options.
     */
    private function render_multipliers_tab($options) {
        $multipliers = $options['multipliers'] ?? array();
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('jdpd_loyalty_points'); ?>
            <input type="hidden" name="jdpd_loyalty_options[enabled]" value="<?php echo esc_attr($options['enabled'] ?? ''); ?>">

            <h2><?php esc_html_e('Points Multiplier Events', 'jezweb-dynamic-pricing'); ?></h2>
            <p><?php esc_html_e('Schedule special events where customers earn bonus points.', 'jezweb-dynamic-pricing'); ?></p>

            <table class="wp-list-table widefat fixed striped" id="multipliers-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Event Name', 'jezweb-dynamic-pricing'); ?></th>
                        <th><?php esc_html_e('Multiplier', 'jezweb-dynamic-pricing'); ?></th>
                        <th><?php esc_html_e('Start Date', 'jezweb-dynamic-pricing'); ?></th>
                        <th><?php esc_html_e('End Date', 'jezweb-dynamic-pricing'); ?></th>
                        <th><?php esc_html_e('Status', 'jezweb-dynamic-pricing'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $index = 0;
                    foreach ($multipliers as $mult):
                    ?>
                        <tr>
                            <td>
                                <input type="text" name="jdpd_loyalty_options[multipliers][<?php echo $index; ?>][name]"
                                    value="<?php echo esc_attr($mult['name']); ?>" class="regular-text">
                            </td>
                            <td>
                                <input type="number" name="jdpd_loyalty_options[multipliers][<?php echo $index; ?>][multiplier]"
                                    value="<?php echo esc_attr($mult['multiplier']); ?>" min="1" max="10" step="0.5">x
                            </td>
                            <td>
                                <input type="datetime-local" name="jdpd_loyalty_options[multipliers][<?php echo $index; ?>][start]"
                                    value="<?php echo esc_attr($mult['start']); ?>">
                            </td>
                            <td>
                                <input type="datetime-local" name="jdpd_loyalty_options[multipliers][<?php echo $index; ?>][end]"
                                    value="<?php echo esc_attr($mult['end']); ?>">
                            </td>
                            <td>
                                <?php
                                $now = current_time('timestamp');
                                $start = strtotime($mult['start']);
                                $end = strtotime($mult['end']);

                                if ($now < $start) {
                                    echo '<span style="color:orange;">' . esc_html__('Scheduled', 'jezweb-dynamic-pricing') . '</span>';
                                } elseif ($now >= $start && $now <= $end) {
                                    echo '<span style="color:green;">' . esc_html__('Active', 'jezweb-dynamic-pricing') . '</span>';
                                } else {
                                    echo '<span style="color:gray;">' . esc_html__('Ended', 'jezweb-dynamic-pricing') . '</span>';
                                }
                                ?>
                            </td>
                        </tr>
                    <?php
                        $index++;
                    endforeach;
                    ?>
                    <tr>
                        <td>
                            <input type="text" name="jdpd_loyalty_options[multipliers][<?php echo $index; ?>][name]"
                                placeholder="<?php esc_attr_e('New Event', 'jezweb-dynamic-pricing'); ?>" class="regular-text">
                        </td>
                        <td>
                            <input type="number" name="jdpd_loyalty_options[multipliers][<?php echo $index; ?>][multiplier]"
                                value="2" min="1" max="10" step="0.5">x
                        </td>
                        <td>
                            <input type="datetime-local" name="jdpd_loyalty_options[multipliers][<?php echo $index; ?>][start]">
                        </td>
                        <td>
                            <input type="datetime-local" name="jdpd_loyalty_options[multipliers][<?php echo $index; ?>][end]">
                        </td>
                        <td></td>
                    </tr>
                </tbody>
            </table>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    /**
     * Render members tab.
     */
    private function render_members_tab() {
        global $wpdb;

        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;

        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $tier_filter = isset($_GET['tier']) ? sanitize_text_field($_GET['tier']) : '';

        $where = "WHERE 1=1";
        if ($search) {
            $where .= $wpdb->prepare(
                " AND (u.user_email LIKE %s OR u.display_name LIKE %s)",
                '%' . $wpdb->esc_like($search) . '%',
                '%' . $wpdb->esc_like($search) . '%'
            );
        }
        if ($tier_filter) {
            $where .= $wpdb->prepare(" AND p.tier = %s", $tier_filter);
        }

        $total = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table_name} p
            JOIN {$wpdb->users} u ON p.user_id = u.ID
            $where"
        );

        $members = $wpdb->get_results(
            "SELECT p.*, u.user_email, u.display_name
            FROM {$this->table_name} p
            JOIN {$wpdb->users} u ON p.user_id = u.ID
            $where
            ORDER BY p.points_balance DESC
            LIMIT $offset, $per_page"
        );

        $options = get_option('jdpd_loyalty_options', $this->get_default_options());
        $tiers = $options['tiers'] ?? $this->get_default_tiers();
        ?>
        <h2><?php esc_html_e('Loyalty Members', 'jezweb-dynamic-pricing'); ?></h2>

        <form method="get">
            <input type="hidden" name="page" value="jdpd-loyalty-points">
            <input type="hidden" name="tab" value="members">

            <p class="search-box">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search members...', 'jezweb-dynamic-pricing'); ?>">
                <select name="tier">
                    <option value=""><?php esc_html_e('All Tiers', 'jezweb-dynamic-pricing'); ?></option>
                    <?php foreach ($tiers as $key => $tier): ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($tier_filter, $key); ?>>
                            <?php echo esc_html($tier['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button"><?php esc_html_e('Filter', 'jezweb-dynamic-pricing'); ?></button>
            </p>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Customer', 'jezweb-dynamic-pricing'); ?></th>
                    <th><?php esc_html_e('Email', 'jezweb-dynamic-pricing'); ?></th>
                    <th><?php esc_html_e('Tier', 'jezweb-dynamic-pricing'); ?></th>
                    <th><?php esc_html_e('Balance', 'jezweb-dynamic-pricing'); ?></th>
                    <th><?php esc_html_e('Total Earned', 'jezweb-dynamic-pricing'); ?></th>
                    <th><?php esc_html_e('Redeemed', 'jezweb-dynamic-pricing'); ?></th>
                    <th><?php esc_html_e('Actions', 'jezweb-dynamic-pricing'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($members)): ?>
                    <tr>
                        <td colspan="7"><?php esc_html_e('No members found.', 'jezweb-dynamic-pricing'); ?></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($members as $member): ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url(get_edit_user_link($member->user_id)); ?>">
                                    <?php echo esc_html($member->display_name); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html($member->user_email); ?></td>
                            <td>
                                <span class="jdpd-tier-badge tier-<?php echo esc_attr($member->tier); ?>">
                                    <?php echo esc_html($tiers[$member->tier]['name'] ?? ucfirst($member->tier)); ?>
                                </span>
                            </td>
                            <td><strong><?php echo number_format($member->points_balance); ?></strong></td>
                            <td><?php echo number_format($member->points_earned); ?></td>
                            <td><?php echo number_format($member->points_redeemed); ?></td>
                            <td>
                                <button type="button" class="button button-small jdpd-adjust-points"
                                    data-user-id="<?php echo esc_attr($member->user_id); ?>"
                                    data-user-name="<?php echo esc_attr($member->display_name); ?>">
                                    <?php esc_html_e('Adjust', 'jezweb-dynamic-pricing'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php
        $total_pages = ceil($total / $per_page);
        if ($total_pages > 1):
        ?>
            <div class="tablenav">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'current' => $current_page,
                        'total' => $total_pages,
                    ));
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Adjust Points Modal -->
        <div id="adjust-points-modal" class="jdpd-modal" style="display:none;">
            <div class="jdpd-modal-content">
                <h3><?php esc_html_e('Adjust Points', 'jezweb-dynamic-pricing'); ?></h3>
                <form id="adjust-points-form">
                    <input type="hidden" name="user_id" id="adjust-user-id">
                    <p><strong id="adjust-user-name"></strong></p>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e('Action', 'jezweb-dynamic-pricing'); ?></th>
                            <td>
                                <select name="action_type" id="adjust-action">
                                    <option value="add"><?php esc_html_e('Add Points', 'jezweb-dynamic-pricing'); ?></option>
                                    <option value="deduct"><?php esc_html_e('Deduct Points', 'jezweb-dynamic-pricing'); ?></option>
                                    <option value="set"><?php esc_html_e('Set Balance', 'jezweb-dynamic-pricing'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Points', 'jezweb-dynamic-pricing'); ?></th>
                            <td>
                                <input type="number" name="points" id="adjust-points" min="0" required>
                            </td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Reason', 'jezweb-dynamic-pricing'); ?></th>
                            <td>
                                <input type="text" name="reason" id="adjust-reason" class="regular-text"
                                    placeholder="<?php esc_attr_e('Optional reason for adjustment', 'jezweb-dynamic-pricing'); ?>">
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Apply', 'jezweb-dynamic-pricing'); ?></button>
                        <button type="button" class="button jdpd-modal-close"><?php esc_html_e('Cancel', 'jezweb-dynamic-pricing'); ?></button>
                    </p>
                </form>
            </div>
        </div>

        <style>
            .jdpd-modal { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:100000; }
            .jdpd-modal-content { background:#fff; max-width:500px; margin:100px auto; padding:20px; border-radius:5px; }
            .jdpd-tier-badge { display:inline-block; padding:3px 8px; border-radius:3px; font-size:12px; }
            .tier-bronze { background:#cd7f32; color:#fff; }
            .tier-silver { background:#c0c0c0; color:#333; }
            .tier-gold { background:#ffd700; color:#333; }
            .tier-platinum { background:#e5e4e2; color:#333; }
        </style>

        <script>
        jQuery(document).ready(function($) {
            $('.jdpd-adjust-points').on('click', function() {
                var userId = $(this).data('user-id');
                var userName = $(this).data('user-name');
                $('#adjust-user-id').val(userId);
                $('#adjust-user-name').text(userName);
                $('#adjust-points-modal').show();
            });

            $('.jdpd-modal-close').on('click', function() {
                $('#adjust-points-modal').hide();
            });

            $('#adjust-points-form').on('submit', function(e) {
                e.preventDefault();
                $.post(ajaxurl, {
                    action: 'jdpd_admin_adjust_points',
                    nonce: '<?php echo wp_create_nonce('jdpd_admin_nonce'); ?>',
                    user_id: $('#adjust-user-id').val(),
                    action_type: $('#adjust-action').val(),
                    points: $('#adjust-points').val(),
                    reason: $('#adjust-reason').val()
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data.message);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render reports tab.
     */
    private function render_reports_tab() {
        global $wpdb;

        // Get statistics
        $total_members = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
        $total_points_issued = $wpdb->get_var("SELECT SUM(points_earned) FROM {$this->table_name}");
        $total_points_redeemed = $wpdb->get_var("SELECT SUM(points_redeemed) FROM {$this->table_name}");
        $total_points_balance = $wpdb->get_var("SELECT SUM(points_balance) FROM {$this->table_name}");

        $options = get_option('jdpd_loyalty_options', $this->get_default_options());
        $points_value = floatval($options['points_value'] ?? 100);
        $monetary_value = floatval($options['points_monetary_value'] ?? 1);

        $liability = ($total_points_balance / $points_value) * $monetary_value;

        // Recent activity
        $recent_activity = $wpdb->get_results(
            "SELECT l.*, u.display_name, u.user_email
            FROM {$this->log_table} l
            JOIN {$wpdb->users} u ON l.user_id = u.ID
            ORDER BY l.created_at DESC
            LIMIT 20"
        );
        ?>
        <h2><?php esc_html_e('Points Reports', 'jezweb-dynamic-pricing'); ?></h2>

        <div class="jdpd-stats-grid">
            <div class="jdpd-stat-box">
                <span class="stat-value"><?php echo number_format($total_members); ?></span>
                <span class="stat-label"><?php esc_html_e('Total Members', 'jezweb-dynamic-pricing'); ?></span>
            </div>
            <div class="jdpd-stat-box">
                <span class="stat-value"><?php echo number_format($total_points_issued); ?></span>
                <span class="stat-label"><?php esc_html_e('Points Issued', 'jezweb-dynamic-pricing'); ?></span>
            </div>
            <div class="jdpd-stat-box">
                <span class="stat-value"><?php echo number_format($total_points_redeemed); ?></span>
                <span class="stat-label"><?php esc_html_e('Points Redeemed', 'jezweb-dynamic-pricing'); ?></span>
            </div>
            <div class="jdpd-stat-box">
                <span class="stat-value"><?php echo number_format($total_points_balance); ?></span>
                <span class="stat-label"><?php esc_html_e('Outstanding Points', 'jezweb-dynamic-pricing'); ?></span>
            </div>
            <div class="jdpd-stat-box">
                <span class="stat-value"><?php echo wc_price($liability); ?></span>
                <span class="stat-label"><?php esc_html_e('Points Liability', 'jezweb-dynamic-pricing'); ?></span>
            </div>
        </div>

        <h3><?php esc_html_e('Recent Activity', 'jezweb-dynamic-pricing'); ?></h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Date', 'jezweb-dynamic-pricing'); ?></th>
                    <th><?php esc_html_e('Customer', 'jezweb-dynamic-pricing'); ?></th>
                    <th><?php esc_html_e('Type', 'jezweb-dynamic-pricing'); ?></th>
                    <th><?php esc_html_e('Points', 'jezweb-dynamic-pricing'); ?></th>
                    <th><?php esc_html_e('Description', 'jezweb-dynamic-pricing'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_activity as $activity): ?>
                    <tr>
                        <td><?php echo esc_html(date_i18n('M j, Y H:i', strtotime($activity->created_at))); ?></td>
                        <td><?php echo esc_html($activity->display_name); ?></td>
                        <td><?php echo esc_html(ucfirst(str_replace('_', ' ', $activity->type))); ?></td>
                        <td>
                            <span style="color: <?php echo $activity->points > 0 ? 'green' : 'red'; ?>">
                                <?php echo $activity->points > 0 ? '+' : ''; ?><?php echo number_format($activity->points); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($activity->description); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <style>
            .jdpd-stats-grid { display:flex; flex-wrap:wrap; gap:20px; margin:20px 0; }
            .jdpd-stat-box { background:#fff; border:1px solid #ccd0d4; padding:20px; min-width:150px; text-align:center; }
            .jdpd-stat-box .stat-value { display:block; font-size:28px; font-weight:bold; color:#2271b1; }
            .jdpd-stat-box .stat-label { display:block; color:#50575e; margin-top:5px; }
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
            'enabled' => false,
            'points_name' => 'Points',
            'points_per_dollar' => 1,
            'round_points' => 'floor',
            'registration_bonus' => 100,
            'review_bonus' => 50,
            'referral_bonus' => 200,
            'points_value' => 100,
            'points_monetary_value' => 1,
            'min_redeem' => 100,
            'max_discount_percent' => 50,
            'points_expire' => false,
            'expiration_days' => 365,
            'expiration_warning_days' => 30,
            'tiers' => $this->get_default_tiers(),
            'multipliers' => array(),
        );
    }

    /**
     * Get default VIP tiers.
     *
     * @return array
     */
    private function get_default_tiers() {
        return array(
            'bronze' => array(
                'name' => 'Bronze',
                'points_required' => 0,
                'multiplier' => 1,
                'benefits' => '',
            ),
            'silver' => array(
                'name' => 'Silver',
                'points_required' => 1000,
                'multiplier' => 1.25,
                'benefits' => '',
            ),
            'gold' => array(
                'name' => 'Gold',
                'points_required' => 5000,
                'multiplier' => 1.5,
                'benefits' => 'Free shipping',
            ),
            'platinum' => array(
                'name' => 'Platinum',
                'points_required' => 10000,
                'multiplier' => 2,
                'benefits' => 'Free shipping, Early access',
            ),
        );
    }

    /**
     * Award points for purchase.
     *
     * @param int $order_id Order ID.
     */
    public function award_purchase_points($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        // Check if already awarded
        if ($order->get_meta('_jdpd_points_awarded')) {
            return;
        }

        $user_id = $order->get_customer_id();
        if (!$user_id) return;

        $options = get_option('jdpd_loyalty_options', $this->get_default_options());

        if (empty($options['enabled'])) return;

        // Calculate points
        $order_total = $order->get_subtotal();
        $points_per_dollar = floatval($options['points_per_dollar'] ?? 1);

        // Get user tier multiplier
        $user_tier = $this->get_user_tier($user_id);
        $tiers = $options['tiers'] ?? $this->get_default_tiers();
        $tier_multiplier = floatval($tiers[$user_tier]['multiplier'] ?? 1);

        // Check for active multiplier events
        $event_multiplier = $this->get_active_multiplier();

        $base_points = $order_total * $points_per_dollar;
        $total_multiplier = $tier_multiplier * $event_multiplier;
        $points = $base_points * $total_multiplier;

        // Round points
        switch ($options['round_points'] ?? 'floor') {
            case 'ceil':
                $points = ceil($points);
                break;
            case 'round':
                $points = round($points);
                break;
            default:
                $points = floor($points);
        }

        if ($points > 0) {
            $this->add_points(
                $user_id,
                $points,
                'purchase',
                sprintf(__('Order #%d', 'jezweb-dynamic-pricing'), $order_id),
                $order_id
            );

            $order->update_meta_data('_jdpd_points_awarded', $points);
            $order->save();

            // Trigger email notification
            do_action('jdpd_points_earned', $user_id, $points, 'purchase');
        }
    }

    /**
     * Deduct points on refund.
     *
     * @param int $order_id Order ID.
     */
    public function deduct_refund_points($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $awarded_points = $order->get_meta('_jdpd_points_awarded');
        if (!$awarded_points) return;

        // Check if already deducted
        if ($order->get_meta('_jdpd_points_deducted')) return;

        $user_id = $order->get_customer_id();
        if (!$user_id) return;

        $this->deduct_points(
            $user_id,
            $awarded_points,
            'refund',
            sprintf(__('Order #%d refunded/cancelled', 'jezweb-dynamic-pricing'), $order_id),
            $order_id
        );

        $order->update_meta_data('_jdpd_points_deducted', $awarded_points);
        $order->save();
    }

    /**
     * Award registration bonus points.
     *
     * @param int $user_id User ID.
     */
    public function award_registration_points($user_id) {
        $options = get_option('jdpd_loyalty_options', $this->get_default_options());

        if (empty($options['enabled'])) return;

        $bonus = intval($options['registration_bonus'] ?? 100);

        if ($bonus > 0) {
            $this->add_points(
                $user_id,
                $bonus,
                'registration',
                __('Welcome bonus', 'jezweb-dynamic-pricing')
            );

            do_action('jdpd_points_earned', $user_id, $bonus, 'registration');
        }
    }

    /**
     * Award review bonus points.
     *
     * @param int        $comment_id Comment ID.
     * @param int|string $approved   Approval status.
     * @param array      $commentdata Comment data.
     */
    public function award_review_points($comment_id, $approved, $commentdata) {
        if ($approved !== 1) return;

        $comment = get_comment($comment_id);
        if ($comment->comment_type !== 'review') return;

        $user_id = $comment->user_id;
        if (!$user_id) return;

        // Check if already awarded for this product
        $product_id = $comment->comment_post_ID;
        $awarded = get_user_meta($user_id, '_jdpd_review_points_' . $product_id, true);
        if ($awarded) return;

        $options = get_option('jdpd_loyalty_options', $this->get_default_options());

        if (empty($options['enabled'])) return;

        $bonus = intval($options['review_bonus'] ?? 50);

        if ($bonus > 0) {
            $this->add_points(
                $user_id,
                $bonus,
                'review',
                sprintf(__('Review on %s', 'jezweb-dynamic-pricing'), get_the_title($product_id))
            );

            update_user_meta($user_id, '_jdpd_review_points_' . $product_id, $bonus);

            do_action('jdpd_points_earned', $user_id, $bonus, 'review');
        }
    }

    /**
     * Add points to user.
     *
     * @param int    $user_id     User ID.
     * @param int    $points      Points to add.
     * @param string $type        Transaction type.
     * @param string $description Description.
     * @param int    $order_id    Order ID (optional).
     */
    public function add_points($user_id, $points, $type = 'manual', $description = '', $order_id = null) {
        global $wpdb;

        $options = get_option('jdpd_loyalty_options', $this->get_default_options());

        // Calculate expiration
        $expires_at = null;
        if (!empty($options['points_expire'])) {
            $expiration_days = intval($options['expiration_days'] ?? 365);
            $expires_at = date('Y-m-d H:i:s', strtotime("+{$expiration_days} days"));
        }

        // Log the transaction
        $wpdb->insert(
            $this->log_table,
            array(
                'user_id' => $user_id,
                'points' => $points,
                'type' => $type,
                'description' => $description,
                'order_id' => $order_id,
                'expires_at' => $expires_at,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%s', '%d', '%s', '%s')
        );

        // Update balance
        $this->update_user_balance($user_id, $points);
    }

    /**
     * Deduct points from user.
     *
     * @param int    $user_id     User ID.
     * @param int    $points      Points to deduct.
     * @param string $type        Transaction type.
     * @param string $description Description.
     * @param int    $order_id    Order ID (optional).
     */
    public function deduct_points($user_id, $points, $type = 'manual', $description = '', $order_id = null) {
        global $wpdb;

        // Log the transaction (negative points)
        $wpdb->insert(
            $this->log_table,
            array(
                'user_id' => $user_id,
                'points' => -abs($points),
                'type' => $type,
                'description' => $description,
                'order_id' => $order_id,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%d', '%s', '%s', '%d', '%s')
        );

        // Update balance
        $this->update_user_balance($user_id, -abs($points));
    }

    /**
     * Update user points balance.
     *
     * @param int $user_id User ID.
     * @param int $points  Points to add (negative to deduct).
     */
    private function update_user_balance($user_id, $points) {
        global $wpdb;

        // Check if user exists in points table
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE user_id = %d",
            $user_id
        ));

        if ($exists) {
            if ($points > 0) {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->table_name}
                    SET points_balance = points_balance + %d,
                        points_earned = points_earned + %d,
                        last_activity = %s
                    WHERE user_id = %d",
                    $points,
                    $points,
                    current_time('mysql'),
                    $user_id
                ));
            } else {
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$this->table_name}
                    SET points_balance = GREATEST(0, points_balance + %d),
                        points_redeemed = points_redeemed + %d,
                        last_activity = %s
                    WHERE user_id = %d",
                    $points,
                    abs($points),
                    current_time('mysql'),
                    $user_id
                ));
            }
        } else {
            $wpdb->insert(
                $this->table_name,
                array(
                    'user_id' => $user_id,
                    'points_balance' => max(0, $points),
                    'points_earned' => $points > 0 ? $points : 0,
                    'points_redeemed' => $points < 0 ? abs($points) : 0,
                    'tier' => 'bronze',
                    'last_activity' => current_time('mysql'),
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%d', '%d', '%d', '%s', '%s', '%s')
            );
        }

        // Update tier
        $this->update_user_tier($user_id);
    }

    /**
     * Get user points balance.
     *
     * @param int $user_id User ID.
     * @return int
     */
    public function get_user_balance($user_id) {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT points_balance FROM {$this->table_name} WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Get user tier.
     *
     * @param int $user_id User ID.
     * @return string
     */
    public function get_user_tier($user_id) {
        global $wpdb;

        $tier = $wpdb->get_var($wpdb->prepare(
            "SELECT tier FROM {$this->table_name} WHERE user_id = %d",
            $user_id
        ));

        return $tier ?: 'bronze';
    }

    /**
     * Update user tier based on points earned.
     *
     * @param int $user_id User ID.
     */
    private function update_user_tier($user_id) {
        global $wpdb;

        $points_earned = $wpdb->get_var($wpdb->prepare(
            "SELECT points_earned FROM {$this->table_name} WHERE user_id = %d",
            $user_id
        ));

        if (!$points_earned) return;

        $options = get_option('jdpd_loyalty_options', $this->get_default_options());
        $tiers = $options['tiers'] ?? $this->get_default_tiers();

        // Sort tiers by points required (descending)
        uasort($tiers, function($a, $b) {
            return $b['points_required'] - $a['points_required'];
        });

        $new_tier = 'bronze';
        foreach ($tiers as $tier_key => $tier) {
            if ($points_earned >= $tier['points_required']) {
                $new_tier = $tier_key;
                break;
            }
        }

        $wpdb->update(
            $this->table_name,
            array('tier' => $new_tier),
            array('user_id' => $user_id),
            array('%s'),
            array('%d')
        );
    }

    /**
     * Get active multiplier.
     *
     * @return float
     */
    private function get_active_multiplier() {
        $options = get_option('jdpd_loyalty_options', $this->get_default_options());
        $multipliers = $options['multipliers'] ?? array();

        $now = current_time('timestamp');
        $active_multiplier = 1;

        foreach ($multipliers as $mult) {
            if (empty($mult['name']) || empty($mult['start']) || empty($mult['end'])) {
                continue;
            }

            $start = strtotime($mult['start']);
            $end = strtotime($mult['end']);

            if ($now >= $start && $now <= $end) {
                $active_multiplier = max($active_multiplier, floatval($mult['multiplier']));
            }
        }

        return $active_multiplier;
    }

    /**
     * Display points redemption at checkout.
     */
    public function display_points_redemption() {
        if (!is_user_logged_in()) return;

        $options = get_option('jdpd_loyalty_options', $this->get_default_options());
        if (empty($options['enabled'])) return;

        $user_id = get_current_user_id();
        $balance = $this->get_user_balance($user_id);
        $min_redeem = intval($options['min_redeem'] ?? 100);

        if ($balance < $min_redeem) return;

        $points_value = floatval($options['points_value'] ?? 100);
        $monetary_value = floatval($options['points_monetary_value'] ?? 1);
        $points_name = $options['points_name'] ?? 'Points';

        // Calculate max redeemable
        $cart_total = WC()->cart->get_subtotal();
        $max_discount_percent = floatval($options['max_discount_percent'] ?? 50);
        $max_discount = ($cart_total * $max_discount_percent) / 100;
        $max_points = floor(($max_discount / $monetary_value) * $points_value);
        $max_redeemable = min($balance, $max_points);

        // Check if already applied
        $applied = WC()->session->get('jdpd_points_applied', 0);
        ?>
        <div class="jdpd-points-redemption">
            <h4><?php printf(esc_html__('Redeem %s', 'jezweb-dynamic-pricing'), esc_html($points_name)); ?></h4>
            <p>
                <?php printf(
                    esc_html__('You have %s %s available (%s).', 'jezweb-dynamic-pricing'),
                    '<strong>' . number_format($balance) . '</strong>',
                    esc_html($points_name),
                    wc_price(($balance / $points_value) * $monetary_value)
                ); ?>
            </p>

            <?php if ($applied > 0): ?>
                <p class="jdpd-points-applied">
                    <?php printf(
                        esc_html__('%s %s applied (%s discount)', 'jezweb-dynamic-pricing'),
                        number_format($applied),
                        esc_html($points_name),
                        wc_price(($applied / $points_value) * $monetary_value)
                    ); ?>
                    <a href="#" class="jdpd-remove-points"><?php esc_html_e('Remove', 'jezweb-dynamic-pricing'); ?></a>
                </p>
            <?php else: ?>
                <div class="jdpd-points-form">
                    <input type="number" id="jdpd-redeem-points" min="<?php echo esc_attr($min_redeem); ?>"
                        max="<?php echo esc_attr($max_redeemable); ?>" step="1"
                        placeholder="<?php printf(esc_attr__('Enter %s to redeem', 'jezweb-dynamic-pricing'), $points_name); ?>">
                    <button type="button" class="button jdpd-apply-points">
                        <?php esc_html_e('Apply', 'jezweb-dynamic-pricing'); ?>
                    </button>
                </div>
                <p class="description">
                    <?php printf(
                        esc_html__('Min: %s | Max: %s', 'jezweb-dynamic-pricing'),
                        number_format($min_redeem),
                        number_format($max_redeemable)
                    ); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Apply points discount to cart.
     *
     * @param WC_Cart $cart Cart object.
     */
    public function apply_points_discount($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;

        $applied = WC()->session->get('jdpd_points_applied', 0);
        if ($applied <= 0) return;

        $options = get_option('jdpd_loyalty_options', $this->get_default_options());
        $points_value = floatval($options['points_value'] ?? 100);
        $monetary_value = floatval($options['points_monetary_value'] ?? 1);
        $points_name = $options['points_name'] ?? 'Points';

        $discount = ($applied / $points_value) * $monetary_value;

        $cart->add_fee(
            sprintf(__('%s Discount', 'jezweb-dynamic-pricing'), $points_name),
            -$discount,
            false
        );
    }

    /**
     * Display points summary on My Account.
     */
    public function display_points_summary() {
        if (!is_user_logged_in()) return;

        $options = get_option('jdpd_loyalty_options', $this->get_default_options());
        if (empty($options['enabled'])) return;

        $user_id = get_current_user_id();
        $balance = $this->get_user_balance($user_id);
        $tier = $this->get_user_tier($user_id);
        $tiers = $options['tiers'] ?? $this->get_default_tiers();
        $points_name = $options['points_name'] ?? 'Points';
        ?>
        <div class="jdpd-points-summary">
            <h3><?php printf(esc_html__('My %s', 'jezweb-dynamic-pricing'), esc_html($points_name)); ?></h3>
            <div class="jdpd-points-card">
                <div class="jdpd-points-balance">
                    <span class="balance-number"><?php echo number_format($balance); ?></span>
                    <span class="balance-label"><?php echo esc_html($points_name); ?></span>
                </div>
                <div class="jdpd-points-tier">
                    <span class="tier-badge tier-<?php echo esc_attr($tier); ?>">
                        <?php echo esc_html($tiers[$tier]['name'] ?? ucfirst($tier)); ?>
                    </span>
                    <?php if (!empty($tiers[$tier]['benefits'])): ?>
                        <span class="tier-benefits"><?php echo esc_html($tiers[$tier]['benefits']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <p><a href="<?php echo esc_url(wc_get_account_endpoint_url('points-history')); ?>"><?php esc_html_e('View History', 'jezweb-dynamic-pricing'); ?></a></p>
        </div>
        <style>
            .jdpd-points-summary { margin-bottom: 30px; }
            .jdpd-points-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #fff; padding: 25px; border-radius: 10px; display: flex; justify-content: space-between; align-items: center; }
            .jdpd-points-balance .balance-number { font-size: 42px; font-weight: bold; display: block; }
            .jdpd-points-balance .balance-label { opacity: 0.8; }
            .jdpd-points-tier .tier-badge { background: rgba(255,255,255,0.2); padding: 5px 15px; border-radius: 20px; display: inline-block; }
            .jdpd-points-tier .tier-benefits { display: block; margin-top: 10px; font-size: 12px; opacity: 0.8; }
        </style>
        <?php
    }

    /**
     * Display product points earning.
     */
    public function display_product_points() {
        global $product;
        if (!$product) return;

        $options = get_option('jdpd_loyalty_options', $this->get_default_options());
        if (empty($options['enabled'])) return;

        $points_per_dollar = floatval($options['points_per_dollar'] ?? 1);
        $price = $product->get_price();
        $points = floor($price * $points_per_dollar);
        $points_name = $options['points_name'] ?? 'Points';

        if ($points > 0):
        ?>
            <p class="jdpd-product-points">
                <?php printf(
                    esc_html__('Earn %s %s with this purchase', 'jezweb-dynamic-pricing'),
                    '<strong>' . number_format($points) . '</strong>',
                    esc_html($points_name)
                ); ?>
            </p>
        <?php
        endif;
    }

    /**
     * Display product points in loop.
     */
    public function display_product_points_loop() {
        global $product;
        if (!$product) return;

        $options = get_option('jdpd_loyalty_options', $this->get_default_options());
        if (empty($options['enabled'])) return;

        $points_per_dollar = floatval($options['points_per_dollar'] ?? 1);
        $price = $product->get_price();
        $points = floor($price * $points_per_dollar);
        $points_name = $options['points_name'] ?? 'Points';

        if ($points > 0):
        ?>
            <span class="jdpd-loop-points">
                <?php printf(esc_html__('Earn %s %s', 'jezweb-dynamic-pricing'), number_format($points), esc_html($points_name)); ?>
            </span>
        <?php
        endif;
    }

    /**
     * Display cart points.
     */
    public function display_cart_points() {
        if (!is_user_logged_in()) return;

        $options = get_option('jdpd_loyalty_options', $this->get_default_options());
        if (empty($options['enabled'])) return;

        $points_per_dollar = floatval($options['points_per_dollar'] ?? 1);
        $cart_total = WC()->cart->get_subtotal();
        $points = floor($cart_total * $points_per_dollar);
        $points_name = $options['points_name'] ?? 'Points';

        if ($points > 0):
        ?>
            <tr class="jdpd-cart-points">
                <th><?php printf(esc_html__('%s to Earn', 'jezweb-dynamic-pricing'), esc_html($points_name)); ?></th>
                <td><strong>+<?php echo number_format($points); ?></strong></td>
            </tr>
        <?php
        endif;
    }

    /**
     * Enqueue scripts.
     */
    public function enqueue_scripts() {
        if (!is_checkout()) return;

        wp_enqueue_script(
            'jdpd-loyalty-points',
            JDPD_PLUGIN_URL . 'public/assets/js/loyalty-points.js',
            array('jquery'),
            JDPD_VERSION,
            true
        );

        wp_localize_script('jdpd-loyalty-points', 'jdpdPoints', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jdpd_points_nonce'),
        ));
    }

    /**
     * AJAX: Apply points.
     */
    public function ajax_apply_points() {
        check_ajax_referer('jdpd_points_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => __('Please log in.', 'jezweb-dynamic-pricing')));
        }

        $points = intval($_POST['points'] ?? 0);
        $user_id = get_current_user_id();
        $balance = $this->get_user_balance($user_id);

        $options = get_option('jdpd_loyalty_options', $this->get_default_options());
        $min_redeem = intval($options['min_redeem'] ?? 100);

        if ($points < $min_redeem) {
            wp_send_json_error(array('message' => sprintf(__('Minimum %d points required.', 'jezweb-dynamic-pricing'), $min_redeem)));
        }

        if ($points > $balance) {
            wp_send_json_error(array('message' => __('Insufficient points.', 'jezweb-dynamic-pricing')));
        }

        WC()->session->set('jdpd_points_applied', $points);

        wp_send_json_success(array('message' => __('Points applied!', 'jezweb-dynamic-pricing')));
    }

    /**
     * AJAX: Remove points.
     */
    public function ajax_remove_points() {
        check_ajax_referer('jdpd_points_nonce', 'nonce');

        WC()->session->set('jdpd_points_applied', 0);

        wp_send_json_success(array('message' => __('Points removed.', 'jezweb-dynamic-pricing')));
    }

    /**
     * AJAX: Get balance.
     */
    public function ajax_get_balance() {
        check_ajax_referer('jdpd_points_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error();
        }

        $balance = $this->get_user_balance(get_current_user_id());

        wp_send_json_success(array('balance' => $balance));
    }

    /**
     * AJAX: Admin adjust points.
     */
    public function ajax_admin_adjust() {
        check_ajax_referer('jdpd_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'jezweb-dynamic-pricing')));
        }

        $user_id = intval($_POST['user_id'] ?? 0);
        $action_type = sanitize_text_field($_POST['action_type'] ?? 'add');
        $points = intval($_POST['points'] ?? 0);
        $reason = sanitize_text_field($_POST['reason'] ?? '');

        if (!$user_id || $points <= 0) {
            wp_send_json_error(array('message' => __('Invalid data.', 'jezweb-dynamic-pricing')));
        }

        $description = $reason ?: __('Admin adjustment', 'jezweb-dynamic-pricing');

        switch ($action_type) {
            case 'add':
                $this->add_points($user_id, $points, 'admin_add', $description);
                break;
            case 'deduct':
                $this->deduct_points($user_id, $points, 'admin_deduct', $description);
                break;
            case 'set':
                global $wpdb;
                $current = $this->get_user_balance($user_id);
                $diff = $points - $current;

                if ($diff > 0) {
                    $this->add_points($user_id, $diff, 'admin_set', $description);
                } elseif ($diff < 0) {
                    $this->deduct_points($user_id, abs($diff), 'admin_set', $description);
                }
                break;
        }

        wp_send_json_success(array('message' => __('Points adjusted.', 'jezweb-dynamic-pricing')));
    }

    /**
     * Display user points in admin.
     *
     * @param WP_User $user User object.
     */
    public function display_user_points_admin($user) {
        $options = get_option('jdpd_loyalty_options', $this->get_default_options());
        if (empty($options['enabled'])) return;

        $balance = $this->get_user_balance($user->ID);
        $tier = $this->get_user_tier($user->ID);
        $tiers = $options['tiers'] ?? $this->get_default_tiers();
        $points_name = $options['points_name'] ?? 'Points';
        ?>
        <h3><?php printf(esc_html__('Loyalty %s', 'jezweb-dynamic-pricing'), esc_html($points_name)); ?></h3>
        <table class="form-table">
            <tr>
                <th><?php printf(esc_html__('%s Balance', 'jezweb-dynamic-pricing'), esc_html($points_name)); ?></th>
                <td><strong><?php echo number_format($balance); ?></strong></td>
            </tr>
            <tr>
                <th><?php esc_html_e('Tier', 'jezweb-dynamic-pricing'); ?></th>
                <td><?php echo esc_html($tiers[$tier]['name'] ?? ucfirst($tier)); ?></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Points balance shortcode.
     *
     * @return string
     */
    public function points_balance_shortcode() {
        if (!is_user_logged_in()) {
            return '';
        }

        $balance = $this->get_user_balance(get_current_user_id());
        $options = get_option('jdpd_loyalty_options', $this->get_default_options());
        $points_name = $options['points_name'] ?? 'Points';

        return sprintf(
            '<span class="jdpd-points-balance-shortcode">%s %s</span>',
            number_format($balance),
            esc_html($points_name)
        );
    }

    /**
     * Points history shortcode.
     *
     * @return string
     */
    public function points_history_shortcode() {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('Please log in to view your points history.', 'jezweb-dynamic-pricing') . '</p>';
        }

        global $wpdb;

        $user_id = get_current_user_id();
        $history = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->log_table} WHERE user_id = %d ORDER BY created_at DESC LIMIT 50",
            $user_id
        ));

        $options = get_option('jdpd_loyalty_options', $this->get_default_options());
        $points_name = $options['points_name'] ?? 'Points';

        ob_start();
        ?>
        <div class="jdpd-points-history">
            <h3><?php printf(esc_html__('%s History', 'jezweb-dynamic-pricing'), esc_html($points_name)); ?></h3>
            <?php if (empty($history)): ?>
                <p><?php esc_html_e('No points activity yet.', 'jezweb-dynamic-pricing'); ?></p>
            <?php else: ?>
                <table class="jdpd-history-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'jezweb-dynamic-pricing'); ?></th>
                            <th><?php echo esc_html($points_name); ?></th>
                            <th><?php esc_html_e('Description', 'jezweb-dynamic-pricing'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $entry): ?>
                            <tr>
                                <td><?php echo esc_html(date_i18n('M j, Y', strtotime($entry->created_at))); ?></td>
                                <td class="<?php echo $entry->points > 0 ? 'positive' : 'negative'; ?>">
                                    <?php echo $entry->points > 0 ? '+' : ''; ?><?php echo number_format($entry->points); ?>
                                </td>
                                <td><?php echo esc_html($entry->description); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <style>
            .jdpd-history-table { width: 100%; border-collapse: collapse; }
            .jdpd-history-table th, .jdpd-history-table td { padding: 10px; text-align: left; border-bottom: 1px solid #eee; }
            .jdpd-history-table .positive { color: green; font-weight: bold; }
            .jdpd-history-table .negative { color: red; }
        </style>
        <?php
        return ob_get_clean();
    }

    /**
     * Process expired points.
     */
    public function process_expired_points() {
        global $wpdb;

        $options = get_option('jdpd_loyalty_options', $this->get_default_options());

        if (empty($options['points_expire'])) return;

        // Get expired points
        $expired = $wpdb->get_results(
            "SELECT user_id, SUM(points) as total_points
            FROM {$this->log_table}
            WHERE expires_at IS NOT NULL
            AND expires_at < NOW()
            AND points > 0
            AND type != 'expiration'
            GROUP BY user_id"
        );

        foreach ($expired as $exp) {
            $this->deduct_points(
                $exp->user_id,
                $exp->total_points,
                'expiration',
                __('Points expired', 'jezweb-dynamic-pricing')
            );

            // Update expired count
            $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table_name}
                SET points_expired = points_expired + %d
                WHERE user_id = %d",
                $exp->total_points,
                $exp->user_id
            ));
        }

        // Mark as processed
        $wpdb->query(
            "UPDATE {$this->log_table}
            SET expires_at = NULL
            WHERE expires_at IS NOT NULL
            AND expires_at < NOW()"
        );
    }

    /**
     * Send points earned email.
     *
     * @param int    $user_id User ID.
     * @param int    $points  Points earned.
     * @param string $type    Earning type.
     */
    public function send_points_earned_email($user_id, $points, $type) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $options = get_option('jdpd_loyalty_options', $this->get_default_options());
        $points_name = $options['points_name'] ?? 'Points';
        $balance = $this->get_user_balance($user_id);

        $subject = sprintf(
            __('You earned %d %s!', 'jezweb-dynamic-pricing'),
            $points,
            $points_name
        );

        $message = sprintf(
            __("Hi %s,\n\nYou just earned %d %s!\n\nYour new balance: %d %s\n\nThanks for being a loyal customer!\n\n%s", 'jezweb-dynamic-pricing'),
            $user->display_name,
            $points,
            $points_name,
            $balance,
            $points_name,
            get_bloginfo('name')
        );

        wp_mail($user->user_email, $subject, $message);
    }

    /**
     * Send expiring points warning email.
     *
     * @param int $user_id User ID.
     * @param int $points  Points expiring.
     */
    public function send_expiring_points_email($user_id, $points) {
        $user = get_userdata($user_id);
        if (!$user) return;

        $options = get_option('jdpd_loyalty_options', $this->get_default_options());
        $points_name = $options['points_name'] ?? 'Points';
        $days = $options['expiration_warning_days'] ?? 30;

        $subject = sprintf(
            __('Your %s are expiring soon!', 'jezweb-dynamic-pricing'),
            $points_name
        );

        $message = sprintf(
            __("Hi %s,\n\nYou have %d %s that will expire in %d days.\n\nDon't let them go to waste - shop now and redeem your %s!\n\n%s", 'jezweb-dynamic-pricing'),
            $user->display_name,
            $points,
            $points_name,
            $days,
            $points_name,
            get_bloginfo('name')
        );

        wp_mail($user->user_email, $subject, $message);
    }

    /**
     * Schedule events.
     */
    public static function schedule_events() {
        if (!wp_next_scheduled('jdpd_points_expiration_check')) {
            wp_schedule_event(time(), 'daily', 'jdpd_points_expiration_check');
        }
    }

    /**
     * Clear scheduled events.
     */
    public static function clear_events() {
        wp_clear_scheduled_hook('jdpd_points_expiration_check');
    }
}

// Initialize the class
JDPD_Loyalty_Points::get_instance();
