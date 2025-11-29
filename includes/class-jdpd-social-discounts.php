<?php
/**
 * Social Share Discounts
 *
 * Reward customers who share products on social media.
 *
 * @package    Jezweb_Dynamic_Pricing
 * @subpackage Includes
 * @since      1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * JDPD_Social_Discounts class.
 *
 * Features:
 * - Share buttons on product pages
 * - Discounts for sharing on Facebook, Twitter/X, Pinterest, LinkedIn
 * - Track sharing activity
 * - Generate unique coupon codes for sharers
 * - Referral tracking from social shares
 * - Social share analytics
 *
 * @since 1.4.0
 */
class JDPD_Social_Discounts {

    /**
     * Single instance of the class.
     *
     * @var JDPD_Social_Discounts
     */
    private static $instance = null;

    /**
     * Supported social platforms.
     *
     * @var array
     */
    private $platforms = array(
        'facebook' => array(
            'name' => 'Facebook',
            'icon' => 'facebook',
            'color' => '#1877f2',
        ),
        'twitter' => array(
            'name' => 'X (Twitter)',
            'icon' => 'twitter',
            'color' => '#000000',
        ),
        'pinterest' => array(
            'name' => 'Pinterest',
            'icon' => 'pinterest',
            'color' => '#bd081c',
        ),
        'linkedin' => array(
            'name' => 'LinkedIn',
            'icon' => 'linkedin',
            'color' => '#0a66c2',
        ),
        'whatsapp' => array(
            'name' => 'WhatsApp',
            'icon' => 'whatsapp',
            'color' => '#25d366',
        ),
        'email' => array(
            'name' => 'Email',
            'icon' => 'email',
            'color' => '#666666',
        ),
    );

    /**
     * Get single instance.
     *
     * @return JDPD_Social_Discounts
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

        // Frontend hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('woocommerce_share', array($this, 'display_share_buttons'));
        add_action('woocommerce_single_product_summary', array($this, 'display_share_for_discount'), 35);

        // Shortcode
        add_shortcode('jdpd_social_share', array($this, 'share_shortcode'));

        // AJAX handlers
        add_action('wp_ajax_jdpd_track_share', array($this, 'ajax_track_share'));
        add_action('wp_ajax_nopriv_jdpd_track_share', array($this, 'ajax_track_share'));
        add_action('wp_ajax_jdpd_verify_share', array($this, 'ajax_verify_share'));
        add_action('wp_ajax_nopriv_jdpd_verify_share', array($this, 'ajax_verify_share'));
        add_action('wp_ajax_jdpd_claim_share_discount', array($this, 'ajax_claim_discount'));
        add_action('wp_ajax_nopriv_jdpd_claim_share_discount', array($this, 'ajax_claim_discount'));

        // Track referral clicks
        add_action('template_redirect', array($this, 'track_referral_click'));

        // Apply discount for verified sharers
        add_filter('woocommerce_product_get_price', array($this, 'apply_share_discount'), 97, 2);
        add_filter('woocommerce_product_get_sale_price', array($this, 'apply_share_discount'), 97, 2);
    }

    /**
     * Add admin menu.
     */
    public function add_admin_menu() {
        add_submenu_page(
            'jezweb-dynamic-pricing',
            __('Social Discounts', 'jezweb-dynamic-pricing'),
            __('Social Discounts', 'jezweb-dynamic-pricing'),
            'manage_woocommerce',
            'jdpd-social-discounts',
            array($this, 'render_admin_page')
        );
    }

    /**
     * Register settings.
     */
    public function register_settings() {
        register_setting('jdpd_social_discounts', 'jdpd_social_options');
    }

    /**
     * Render admin page.
     */
    public function render_admin_page() {
        $options = get_option('jdpd_social_options', $this->get_default_options());
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Social Share Discounts', 'jezweb-dynamic-pricing'); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields('jdpd_social_discounts'); ?>

                <h2><?php esc_html_e('General Settings', 'jezweb-dynamic-pricing'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Social Discounts', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_social_options[enabled]" value="1"
                                    <?php checked(!empty($options['enabled'])); ?>>
                                <?php esc_html_e('Offer discounts for sharing products on social media', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Discount Type', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <select name="jdpd_social_options[discount_type]">
                                <option value="percentage" <?php selected($options['discount_type'] ?? 'percentage', 'percentage'); ?>>
                                    <?php esc_html_e('Percentage discount', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="fixed_product" <?php selected($options['discount_type'] ?? 'percentage', 'fixed_product'); ?>>
                                    <?php esc_html_e('Fixed product discount', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="fixed_cart" <?php selected($options['discount_type'] ?? 'percentage', 'fixed_cart'); ?>>
                                    <?php esc_html_e('Fixed cart discount (via coupon)', 'jezweb-dynamic-pricing'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Discount Amount', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="number" name="jdpd_social_options[discount_amount]"
                                value="<?php echo esc_attr($options['discount_amount'] ?? 10); ?>" min="0" step="0.01">
                            <p class="description"><?php esc_html_e('Percentage or fixed amount depending on discount type.', 'jezweb-dynamic-pricing'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Discount Duration', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="number" name="jdpd_social_options[discount_duration]"
                                value="<?php echo esc_attr($options['discount_duration'] ?? 24); ?>" min="1">
                            <?php esc_html_e('hours', 'jezweb-dynamic-pricing'); ?>
                            <p class="description"><?php esc_html_e('How long the share discount is valid after sharing.', 'jezweb-dynamic-pricing'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Per Share or Once', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <select name="jdpd_social_options[reward_mode]">
                                <option value="once" <?php selected($options['reward_mode'] ?? 'once', 'once'); ?>>
                                    <?php esc_html_e('One discount per product (any share)', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="per_platform" <?php selected($options['reward_mode'] ?? 'once', 'per_platform'); ?>>
                                    <?php esc_html_e('One discount per platform', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="cumulative" <?php selected($options['reward_mode'] ?? 'once', 'cumulative'); ?>>
                                    <?php esc_html_e('Cumulative (each share adds more)', 'jezweb-dynamic-pricing'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Enabled Platforms', 'jezweb-dynamic-pricing'); ?></h2>
                <table class="form-table">
                    <?php foreach ($this->platforms as $platform_id => $platform): ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($platform['name']); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="jdpd_social_options[platforms][<?php echo esc_attr($platform_id); ?>]" value="1"
                                        <?php checked(!empty($options['platforms'][$platform_id])); ?>>
                                    <?php esc_html_e('Enable', 'jezweb-dynamic-pricing'); ?>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <h2><?php esc_html_e('Display Settings', 'jezweb-dynamic-pricing'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Button Style', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <select name="jdpd_social_options[button_style]">
                                <option value="icons" <?php selected($options['button_style'] ?? 'icons', 'icons'); ?>>
                                    <?php esc_html_e('Icons only', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="icons_text" <?php selected($options['button_style'] ?? 'icons', 'icons_text'); ?>>
                                    <?php esc_html_e('Icons with text', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="buttons" <?php selected($options['button_style'] ?? 'icons', 'buttons'); ?>>
                                    <?php esc_html_e('Full buttons', 'jezweb-dynamic-pricing'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Share Message', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="text" name="jdpd_social_options[share_message]"
                                value="<?php echo esc_attr($options['share_message'] ?? 'Check out {product_name} from {site_name}!'); ?>"
                                class="large-text">
                            <p class="description">
                                <?php esc_html_e('Placeholders: {product_name}, {product_price}, {site_name}, {product_url}', 'jezweb-dynamic-pricing'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Promo Text', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="text" name="jdpd_social_options[promo_text]"
                                value="<?php echo esc_attr($options['promo_text'] ?? 'Share and get {discount}% off!'); ?>"
                                class="large-text">
                            <p class="description">
                                <?php esc_html_e('Text shown above share buttons. Use {discount} for the discount amount.', 'jezweb-dynamic-pricing'); ?>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Position', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <select name="jdpd_social_options[position]">
                                <option value="after_add_to_cart" <?php selected($options['position'] ?? 'after_add_to_cart', 'after_add_to_cart'); ?>>
                                    <?php esc_html_e('After Add to Cart button', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="after_price" <?php selected($options['position'] ?? 'after_add_to_cart', 'after_price'); ?>>
                                    <?php esc_html_e('After price', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="after_description" <?php selected($options['position'] ?? 'after_add_to_cart', 'after_description'); ?>>
                                    <?php esc_html_e('After short description', 'jezweb-dynamic-pricing'); ?>
                                </option>
                                <option value="shortcode_only" <?php selected($options['position'] ?? 'after_add_to_cart', 'shortcode_only'); ?>>
                                    <?php esc_html_e('Via shortcode only', 'jezweb-dynamic-pricing'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e('Referral Tracking', 'jezweb-dynamic-pricing'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable Referral Tracking', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_social_options[track_referrals]" value="1"
                                    <?php checked(!empty($options['track_referrals'])); ?>>
                                <?php esc_html_e('Track clicks from shared links', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Referral Parameter', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="text" name="jdpd_social_options[referral_param]"
                                value="<?php echo esc_attr($options['referral_param'] ?? 'jdpd_ref'); ?>">
                            <p class="description"><?php esc_html_e('URL parameter used for tracking shares.', 'jezweb-dynamic-pricing'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Reward Original Sharer', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="jdpd_social_options[reward_sharer]" value="1"
                                    <?php checked(!empty($options['reward_sharer'])); ?>>
                                <?php esc_html_e('Give original sharer a reward when someone buys through their link', 'jezweb-dynamic-pricing'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('Sharer Reward', 'jezweb-dynamic-pricing'); ?></th>
                        <td>
                            <input type="number" name="jdpd_social_options[sharer_reward]"
                                value="<?php echo esc_attr($options['sharer_reward'] ?? 5); ?>" min="0" step="0.01">%
                            <p class="description"><?php esc_html_e('Commission percentage for the person who shared.', 'jezweb-dynamic-pricing'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>

            <h2><?php esc_html_e('Share Analytics', 'jezweb-dynamic-pricing'); ?></h2>
            <?php $this->render_analytics(); ?>
        </div>
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
            'discount_type' => 'percentage',
            'discount_amount' => 10,
            'discount_duration' => 24,
            'reward_mode' => 'once',
            'platforms' => array(
                'facebook' => true,
                'twitter' => true,
                'pinterest' => true,
                'whatsapp' => true,
            ),
            'button_style' => 'icons',
            'share_message' => 'Check out {product_name} from {site_name}!',
            'promo_text' => 'Share and get {discount}% off!',
            'position' => 'after_add_to_cart',
            'track_referrals' => true,
            'referral_param' => 'jdpd_ref',
            'reward_sharer' => false,
            'sharer_reward' => 5,
        );
    }

    /**
     * Enqueue scripts.
     */
    public function enqueue_scripts() {
        if (!is_product()) {
            return;
        }

        wp_enqueue_script(
            'jdpd-social-share',
            JDPD_PLUGIN_URL . 'public/assets/js/social-share.js',
            array('jquery'),
            JDPD_VERSION,
            true
        );

        wp_localize_script('jdpd-social-share', 'jdpdSocial', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('jdpd_social_nonce'),
            'i18n' => array(
                'share_success' => __('Thanks for sharing! Your discount has been applied.', 'jezweb-dynamic-pricing'),
                'error' => __('An error occurred. Please try again.', 'jezweb-dynamic-pricing'),
                'discount_applied' => __('Discount applied!', 'jezweb-dynamic-pricing'),
            ),
        ));

        wp_enqueue_style(
            'jdpd-social-share',
            JDPD_PLUGIN_URL . 'public/assets/css/social-share.css',
            array(),
            JDPD_VERSION
        );
    }

    /**
     * Display share buttons.
     */
    public function display_share_buttons() {
        $options = get_option('jdpd_social_options', $this->get_default_options());

        if (empty($options['enabled'])) {
            return;
        }

        if (($options['position'] ?? 'after_add_to_cart') === 'shortcode_only') {
            return;
        }

        echo $this->render_share_buttons();
    }

    /**
     * Display share for discount section.
     */
    public function display_share_for_discount() {
        $options = get_option('jdpd_social_options', $this->get_default_options());

        if (empty($options['enabled'])) {
            return;
        }

        // Check position setting
        $position = $options['position'] ?? 'after_add_to_cart';
        if ($position !== 'after_add_to_cart') {
            return;
        }

        echo $this->render_share_buttons();
    }

    /**
     * Render share buttons HTML.
     *
     * @param int $product_id Product ID (optional).
     * @return string
     */
    private function render_share_buttons($product_id = null) {
        global $product;

        if (!$product_id && $product) {
            $product_id = $product->get_id();
        }

        if (!$product_id) {
            return '';
        }

        $product_obj = wc_get_product($product_id);
        if (!$product_obj) {
            return '';
        }

        $options = get_option('jdpd_social_options', $this->get_default_options());
        $enabled_platforms = array_filter($options['platforms'] ?? array());

        if (empty($enabled_platforms)) {
            return '';
        }

        // Check if user already has share discount
        $has_discount = $this->user_has_share_discount($product_id);

        // Build share URLs
        $share_urls = $this->get_share_urls($product_obj);

        // Promo text
        $promo_text = $options['promo_text'] ?? 'Share and get {discount}% off!';
        $promo_text = str_replace('{discount}', $options['discount_amount'] ?? 10, $promo_text);

        $style = $options['button_style'] ?? 'icons';

        ob_start();
        ?>
        <div class="jdpd-social-share-wrap" data-product-id="<?php echo esc_attr($product_id); ?>">
            <?php if (!$has_discount): ?>
                <p class="jdpd-share-promo"><?php echo esc_html($promo_text); ?></p>
            <?php else: ?>
                <p class="jdpd-share-applied">
                    <?php echo esc_html(sprintf(
                        __('You\'re getting %s%% off for sharing!', 'jezweb-dynamic-pricing'),
                        $options['discount_amount'] ?? 10
                    )); ?>
                </p>
            <?php endif; ?>

            <div class="jdpd-share-buttons <?php echo esc_attr('style-' . $style); ?>">
                <?php foreach ($enabled_platforms as $platform_id => $enabled):
                    if (!$enabled || !isset($this->platforms[$platform_id])) continue;
                    $platform = $this->platforms[$platform_id];
                    $share_url = $share_urls[$platform_id] ?? '#';
                ?>
                    <a href="<?php echo esc_url($share_url); ?>"
                       class="jdpd-share-btn jdpd-share-<?php echo esc_attr($platform_id); ?>"
                       data-platform="<?php echo esc_attr($platform_id); ?>"
                       target="_blank"
                       rel="noopener noreferrer"
                       style="--platform-color: <?php echo esc_attr($platform['color']); ?>">
                        <span class="jdpd-share-icon"><?php echo $this->get_platform_icon($platform_id); ?></span>
                        <?php if ($style !== 'icons'): ?>
                            <span class="jdpd-share-text"><?php echo esc_html($platform['name']); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get share URLs for product.
     *
     * @param WC_Product $product Product object.
     * @return array
     */
    private function get_share_urls($product) {
        $options = get_option('jdpd_social_options', $this->get_default_options());

        $product_url = $product->get_permalink();
        $product_name = $product->get_name();
        $product_price = wc_price($product->get_price());
        $site_name = get_bloginfo('name');
        $product_image = wp_get_attachment_url($product->get_image_id());

        // Add referral tracking if enabled
        if (!empty($options['track_referrals'])) {
            $ref_param = $options['referral_param'] ?? 'jdpd_ref';
            $ref_id = $this->generate_referral_id();
            $product_url = add_query_arg($ref_param, $ref_id, $product_url);

            // Store referral for tracking
            $this->store_referral($ref_id, $product->get_id());
        }

        // Build share message
        $message = $options['share_message'] ?? 'Check out {product_name}!';
        $message = str_replace(
            array('{product_name}', '{product_price}', '{site_name}', '{product_url}'),
            array($product_name, $product_price, $site_name, $product_url),
            $message
        );

        $encoded_url = rawurlencode($product_url);
        $encoded_message = rawurlencode($message);
        $encoded_name = rawurlencode($product_name);

        return array(
            'facebook' => "https://www.facebook.com/sharer/sharer.php?u={$encoded_url}&quote={$encoded_message}",
            'twitter' => "https://twitter.com/intent/tweet?url={$encoded_url}&text={$encoded_message}",
            'pinterest' => "https://pinterest.com/pin/create/button/?url={$encoded_url}&media=" . rawurlencode($product_image) . "&description={$encoded_message}",
            'linkedin' => "https://www.linkedin.com/sharing/share-offsite/?url={$encoded_url}",
            'whatsapp' => "https://wa.me/?text={$encoded_message}%20{$encoded_url}",
            'email' => "mailto:?subject={$encoded_name}&body={$encoded_message}%20{$encoded_url}",
        );
    }

    /**
     * Get platform icon SVG.
     *
     * @param string $platform Platform ID.
     * @return string
     */
    private function get_platform_icon($platform) {
        $icons = array(
            'facebook' => '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>',
            'twitter' => '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
            'pinterest' => '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M12 0C5.373 0 0 5.372 0 12c0 5.084 3.163 9.426 7.627 11.174-.105-.949-.2-2.405.042-3.441.218-.937 1.407-5.965 1.407-5.965s-.359-.719-.359-1.782c0-1.668.967-2.914 2.171-2.914 1.023 0 1.518.769 1.518 1.69 0 1.029-.655 2.568-.994 3.995-.283 1.194.599 2.169 1.777 2.169 2.133 0 3.772-2.249 3.772-5.495 0-2.873-2.064-4.882-5.012-4.882-3.414 0-5.418 2.561-5.418 5.207 0 1.031.397 2.138.893 2.738.098.119.112.224.083.345l-.333 1.36c-.053.22-.174.267-.402.161-1.499-.698-2.436-2.889-2.436-4.649 0-3.785 2.75-7.262 7.929-7.262 4.163 0 7.398 2.967 7.398 6.931 0 4.136-2.607 7.464-6.227 7.464-1.216 0-2.359-.631-2.75-1.378l-.748 2.853c-.271 1.043-1.002 2.35-1.492 3.146C9.57 23.812 10.763 24 12 24c6.627 0 12-5.373 12-12 0-6.628-5.373-12-12-12z"/></svg>',
            'linkedin' => '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z"/></svg>',
            'whatsapp' => '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>',
            'email' => '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>',
        );

        return $icons[$platform] ?? '';
    }

    /**
     * Generate referral ID.
     *
     * @return string
     */
    private function generate_referral_id() {
        return substr(md5(uniqid(mt_rand(), true)), 0, 12);
    }

    /**
     * Store referral for tracking.
     *
     * @param string $ref_id     Referral ID.
     * @param int    $product_id Product ID.
     */
    private function store_referral($ref_id, $product_id) {
        $user_id = get_current_user_id();

        set_transient('jdpd_ref_' . $ref_id, array(
            'product_id' => $product_id,
            'user_id' => $user_id,
            'created' => current_time('mysql'),
        ), DAY_IN_SECONDS * 30);
    }

    /**
     * Track referral click.
     */
    public function track_referral_click() {
        $options = get_option('jdpd_social_options', $this->get_default_options());

        if (empty($options['track_referrals'])) {
            return;
        }

        $ref_param = $options['referral_param'] ?? 'jdpd_ref';

        if (!isset($_GET[$ref_param])) {
            return;
        }

        $ref_id = sanitize_text_field($_GET[$ref_param]);
        $ref_data = get_transient('jdpd_ref_' . $ref_id);

        if (!$ref_data) {
            return;
        }

        // Track the click
        $stats = get_option('jdpd_social_stats', array());
        $stats['referral_clicks'] = ($stats['referral_clicks'] ?? 0) + 1;

        // Track per product
        $product_id = $ref_data['product_id'];
        if (!isset($stats['products'][$product_id])) {
            $stats['products'][$product_id] = array('shares' => 0, 'clicks' => 0, 'conversions' => 0);
        }
        $stats['products'][$product_id]['clicks']++;

        update_option('jdpd_social_stats', $stats);

        // Store referrer in session for conversion tracking
        if (WC()->session) {
            WC()->session->set('jdpd_referrer', array(
                'ref_id' => $ref_id,
                'product_id' => $product_id,
                'sharer_id' => $ref_data['user_id'],
            ));
        }
    }

    /**
     * Share shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string
     */
    public function share_shortcode($atts) {
        $atts = shortcode_atts(array(
            'product_id' => 0,
        ), $atts);

        $product_id = intval($atts['product_id']);

        if (!$product_id) {
            global $product;
            if ($product) {
                $product_id = $product->get_id();
            }
        }

        if (!$product_id) {
            return '';
        }

        return $this->render_share_buttons($product_id);
    }

    /**
     * Check if user has share discount for product.
     *
     * @param int $product_id Product ID.
     * @return bool
     */
    public function user_has_share_discount($product_id) {
        $user_id = get_current_user_id();

        if ($user_id) {
            $shares = get_user_meta($user_id, '_jdpd_social_shares', true) ?: array();
            if (isset($shares[$product_id])) {
                // Check if not expired
                $options = get_option('jdpd_social_options', $this->get_default_options());
                $duration = intval($options['discount_duration'] ?? 24);
                $share_time = strtotime($shares[$product_id]['timestamp']);

                if ($share_time > strtotime("-{$duration} hours")) {
                    return true;
                }
            }
        } else {
            // Check session for guests
            if (WC()->session) {
                $shares = WC()->session->get('jdpd_social_shares', array());
                return isset($shares[$product_id]);
            }
        }

        return false;
    }

    /**
     * AJAX: Track share.
     */
    public function ajax_track_share() {
        check_ajax_referer('jdpd_social_nonce', 'nonce');

        $product_id = intval($_POST['product_id'] ?? 0);
        $platform = sanitize_text_field($_POST['platform'] ?? '');

        if (!$product_id || !$platform) {
            wp_send_json_error(array('message' => __('Invalid request.', 'jezweb-dynamic-pricing')));
        }

        $user_id = get_current_user_id();

        // Record the share
        $share_data = array(
            'platform' => $platform,
            'timestamp' => current_time('mysql'),
        );

        if ($user_id) {
            $shares = get_user_meta($user_id, '_jdpd_social_shares', true) ?: array();
            $shares[$product_id] = $share_data;
            update_user_meta($user_id, '_jdpd_social_shares', $shares);
        } else {
            if (WC()->session) {
                $shares = WC()->session->get('jdpd_social_shares', array());
                $shares[$product_id] = $share_data;
                WC()->session->set('jdpd_social_shares', $shares);
            }
        }

        // Update statistics
        $stats = get_option('jdpd_social_stats', array());
        $stats['total_shares'] = ($stats['total_shares'] ?? 0) + 1;
        $stats['platforms'][$platform] = ($stats['platforms'][$platform] ?? 0) + 1;

        if (!isset($stats['products'][$product_id])) {
            $stats['products'][$product_id] = array('shares' => 0, 'clicks' => 0, 'conversions' => 0);
        }
        $stats['products'][$product_id]['shares']++;

        update_option('jdpd_social_stats', $stats);

        $options = get_option('jdpd_social_options', $this->get_default_options());

        wp_send_json_success(array(
            'message' => __('Thanks for sharing!', 'jezweb-dynamic-pricing'),
            'discount' => $options['discount_amount'] ?? 10,
        ));
    }

    /**
     * AJAX: Verify share.
     */
    public function ajax_verify_share() {
        check_ajax_referer('jdpd_social_nonce', 'nonce');

        $product_id = intval($_POST['product_id'] ?? 0);

        if (!$product_id) {
            wp_send_json_error(array('message' => __('Invalid product.', 'jezweb-dynamic-pricing')));
        }

        $has_discount = $this->user_has_share_discount($product_id);

        wp_send_json_success(array('has_discount' => $has_discount));
    }

    /**
     * AJAX: Claim discount.
     */
    public function ajax_claim_discount() {
        check_ajax_referer('jdpd_social_nonce', 'nonce');

        $product_id = intval($_POST['product_id'] ?? 0);

        if (!$product_id) {
            wp_send_json_error(array('message' => __('Invalid product.', 'jezweb-dynamic-pricing')));
        }

        if (!$this->user_has_share_discount($product_id)) {
            wp_send_json_error(array('message' => __('No share discount available.', 'jezweb-dynamic-pricing')));
        }

        $options = get_option('jdpd_social_options', $this->get_default_options());

        // If discount type is coupon, generate one
        if ($options['discount_type'] === 'fixed_cart') {
            $coupon_code = $this->generate_share_coupon();

            wp_send_json_success(array(
                'type' => 'coupon',
                'coupon_code' => $coupon_code,
                'message' => sprintf(__('Use coupon code: %s', 'jezweb-dynamic-pricing'), $coupon_code),
            ));
        }

        wp_send_json_success(array(
            'type' => 'automatic',
            'message' => __('Discount applied automatically!', 'jezweb-dynamic-pricing'),
        ));
    }

    /**
     * Generate share coupon.
     *
     * @return string
     */
    private function generate_share_coupon() {
        $options = get_option('jdpd_social_options', $this->get_default_options());
        $coupon_code = 'SHARE-' . strtoupper(wp_generate_password(8, false));

        $coupon = new WC_Coupon();
        $coupon->set_code($coupon_code);
        $coupon->set_discount_type('fixed_cart');
        $coupon->set_amount($options['discount_amount'] ?? 10);
        $coupon->set_individual_use(true);
        $coupon->set_usage_limit(1);
        $coupon->set_date_expires(strtotime('+' . ($options['discount_duration'] ?? 24) . ' hours'));

        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $coupon->set_email_restrictions(array($user->user_email));
        }

        $coupon->save();

        return $coupon_code;
    }

    /**
     * Apply share discount to price.
     *
     * @param float      $price   Price.
     * @param WC_Product $product Product.
     * @return float
     */
    public function apply_share_discount($price, $product) {
        // Don't apply in admin
        if (is_admin() && !wp_doing_ajax()) {
            return $price;
        }

        $options = get_option('jdpd_social_options', $this->get_default_options());

        if (empty($options['enabled'])) {
            return $price;
        }

        // Only apply for percentage or fixed_product types
        if (!in_array($options['discount_type'] ?? 'percentage', array('percentage', 'fixed_product'))) {
            return $price;
        }

        $product_id = $product->get_id();

        if (!$this->user_has_share_discount($product_id)) {
            return $price;
        }

        $discount_amount = floatval($options['discount_amount'] ?? 10);

        if ($options['discount_type'] === 'percentage') {
            $discount = ($price * $discount_amount) / 100;
        } else {
            $discount = $discount_amount;
        }

        return max(0, $price - $discount);
    }

    /**
     * Render analytics.
     */
    private function render_analytics() {
        $stats = get_option('jdpd_social_stats', array());
        ?>
        <div class="jdpd-analytics-grid">
            <div class="jdpd-stat-box">
                <span class="stat-value"><?php echo esc_html($stats['total_shares'] ?? 0); ?></span>
                <span class="stat-label"><?php esc_html_e('Total Shares', 'jezweb-dynamic-pricing'); ?></span>
            </div>
            <div class="jdpd-stat-box">
                <span class="stat-value"><?php echo esc_html($stats['referral_clicks'] ?? 0); ?></span>
                <span class="stat-label"><?php esc_html_e('Referral Clicks', 'jezweb-dynamic-pricing'); ?></span>
            </div>
        </div>

        <h3><?php esc_html_e('Shares by Platform', 'jezweb-dynamic-pricing'); ?></h3>
        <?php if (empty($stats['platforms'])): ?>
            <p><?php esc_html_e('No share data yet.', 'jezweb-dynamic-pricing'); ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped" style="max-width: 400px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Platform', 'jezweb-dynamic-pricing'); ?></th>
                        <th><?php esc_html_e('Shares', 'jezweb-dynamic-pricing'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($stats['platforms'] as $platform => $count): ?>
                        <tr>
                            <td><?php echo esc_html($this->platforms[$platform]['name'] ?? ucfirst($platform)); ?></td>
                            <td><?php echo esc_html($count); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3><?php esc_html_e('Most Shared Products', 'jezweb-dynamic-pricing'); ?></h3>
        <?php if (empty($stats['products'])): ?>
            <p><?php esc_html_e('No product share data yet.', 'jezweb-dynamic-pricing'); ?></p>
        <?php else:
            uasort($stats['products'], function($a, $b) {
                return ($b['shares'] ?? 0) - ($a['shares'] ?? 0);
            });

            $top = array_slice($stats['products'], 0, 10, true);
        ?>
            <table class="wp-list-table widefat fixed striped" style="max-width: 600px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Product', 'jezweb-dynamic-pricing'); ?></th>
                        <th><?php esc_html_e('Shares', 'jezweb-dynamic-pricing'); ?></th>
                        <th><?php esc_html_e('Clicks', 'jezweb-dynamic-pricing'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top as $product_id => $data):
                        $product = wc_get_product($product_id);
                        if (!$product) continue;
                    ?>
                        <tr>
                            <td>
                                <a href="<?php echo esc_url(get_edit_post_link($product_id)); ?>">
                                    <?php echo esc_html($product->get_name()); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html($data['shares'] ?? 0); ?></td>
                            <td><?php echo esc_html($data['clicks'] ?? 0); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <style>
            .jdpd-analytics-grid {
                display: flex;
                gap: 20px;
                margin-bottom: 20px;
            }
            .jdpd-stat-box {
                background: #fff;
                border: 1px solid #ccd0d4;
                padding: 20px;
                text-align: center;
                min-width: 150px;
            }
            .jdpd-stat-box .stat-value {
                display: block;
                font-size: 32px;
                font-weight: bold;
                color: #2271b1;
            }
            .jdpd-stat-box .stat-label {
                display: block;
                color: #50575e;
                margin-top: 5px;
            }
        </style>
        <?php
    }
}

// Initialize the class
JDPD_Social_Discounts::get_instance();
