<?php
/**
 * Advanced Scheduling System
 *
 * @package    Jezweb_Dynamic_Pricing
 * @subpackage Jezweb_Dynamic_Pricing/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Advanced Scheduling System class.
 *
 * Provides recurring schedules, time-of-day rules, holiday presets,
 * and flash sale scheduling capabilities.
 *
 * @since 1.3.0
 */
class JDPD_Advanced_Scheduling {

    /**
     * Single instance of the class.
     *
     * @var JDPD_Advanced_Scheduling
     */
    private static $instance = null;

    /**
     * Cached schedule results.
     *
     * @var array
     */
    private $schedule_cache = array();

    /**
     * Get single instance.
     *
     * @return JDPD_Advanced_Scheduling
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
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
        // Cron events for scheduled rules
        add_action( 'init', array( $this, 'register_cron_schedules' ) );
        add_action( 'jdpd_check_scheduled_rules', array( $this, 'process_scheduled_rules' ) );

        // Flash sale notifications
        add_action( 'jdpd_flash_sale_starting', array( $this, 'notify_flash_sale_start' ), 10, 2 );
        add_action( 'jdpd_flash_sale_ending', array( $this, 'notify_flash_sale_ending' ), 10, 2 );

        // Admin hooks
        add_action( 'wp_ajax_jdpd_get_schedule_preview', array( $this, 'ajax_get_schedule_preview' ) );
        add_action( 'wp_ajax_jdpd_get_holiday_presets', array( $this, 'ajax_get_holiday_presets' ) );
    }

    /**
     * Register custom cron schedules.
     */
    public function register_cron_schedules() {
        // Schedule the cron event if not already scheduled
        if ( ! wp_next_scheduled( 'jdpd_check_scheduled_rules' ) ) {
            wp_schedule_event( time(), 'every_five_minutes', 'jdpd_check_scheduled_rules' );
        }
    }

    /**
     * Add custom cron interval.
     *
     * @param array $schedules Existing schedules.
     * @return array Modified schedules.
     */
    public function add_cron_interval( $schedules ) {
        $schedules['every_five_minutes'] = array(
            'interval' => 300,
            'display'  => __( 'Every 5 Minutes', 'jezweb-dynamic-pricing' ),
        );
        return $schedules;
    }

    /**
     * Get holiday presets.
     *
     * @param string $country Country code (default: AU for Australia).
     * @return array Array of holiday presets.
     */
    public function get_holiday_presets( $country = 'AU' ) {
        $current_year = (int) date( 'Y' );
        $next_year = $current_year + 1;

        $holidays = array();

        // Universal holidays
        $universal = array(
            'new_year' => array(
                'name'        => __( 'New Year\'s Day', 'jezweb-dynamic-pricing' ),
                'date'        => $next_year . '-01-01',
                'duration'    => 1,
                'suggested_discount' => 20,
            ),
            'valentines' => array(
                'name'        => __( 'Valentine\'s Day', 'jezweb-dynamic-pricing' ),
                'date'        => $next_year . '-02-14',
                'duration'    => 3,
                'suggested_discount' => 15,
            ),
            'easter' => array(
                'name'        => __( 'Easter', 'jezweb-dynamic-pricing' ),
                'date'        => $this->get_easter_date( $next_year ),
                'duration'    => 4,
                'suggested_discount' => 25,
            ),
            'mothers_day' => array(
                'name'        => __( 'Mother\'s Day', 'jezweb-dynamic-pricing' ),
                'date'        => $this->get_mothers_day( $next_year, $country ),
                'duration'    => 7,
                'suggested_discount' => 20,
            ),
            'fathers_day' => array(
                'name'        => __( 'Father\'s Day', 'jezweb-dynamic-pricing' ),
                'date'        => $this->get_fathers_day( $next_year, $country ),
                'duration'    => 7,
                'suggested_discount' => 20,
            ),
            'halloween' => array(
                'name'        => __( 'Halloween', 'jezweb-dynamic-pricing' ),
                'date'        => $next_year . '-10-31',
                'duration'    => 7,
                'suggested_discount' => 25,
            ),
            'black_friday' => array(
                'name'        => __( 'Black Friday', 'jezweb-dynamic-pricing' ),
                'date'        => $this->get_black_friday( $next_year ),
                'duration'    => 4,
                'suggested_discount' => 40,
            ),
            'cyber_monday' => array(
                'name'        => __( 'Cyber Monday', 'jezweb-dynamic-pricing' ),
                'date'        => $this->get_cyber_monday( $next_year ),
                'duration'    => 1,
                'suggested_discount' => 35,
            ),
            'christmas' => array(
                'name'        => __( 'Christmas', 'jezweb-dynamic-pricing' ),
                'date'        => $current_year . '-12-25',
                'duration'    => 7,
                'suggested_discount' => 30,
            ),
            'boxing_day' => array(
                'name'        => __( 'Boxing Day', 'jezweb-dynamic-pricing' ),
                'date'        => $current_year . '-12-26',
                'duration'    => 3,
                'suggested_discount' => 50,
            ),
        );

        $holidays = array_merge( $holidays, $universal );

        // Country-specific holidays
        if ( 'AU' === $country ) {
            $holidays['australia_day'] = array(
                'name'        => __( 'Australia Day', 'jezweb-dynamic-pricing' ),
                'date'        => $next_year . '-01-26',
                'duration'    => 3,
                'suggested_discount' => 20,
            );
            $holidays['anzac_day'] = array(
                'name'        => __( 'ANZAC Day', 'jezweb-dynamic-pricing' ),
                'date'        => $next_year . '-04-25',
                'duration'    => 1,
                'suggested_discount' => 15,
            );
            $holidays['eofy'] = array(
                'name'        => __( 'End of Financial Year Sale', 'jezweb-dynamic-pricing' ),
                'date'        => $next_year . '-06-25',
                'duration'    => 7,
                'suggested_discount' => 40,
            );
        } elseif ( 'US' === $country ) {
            $holidays['independence_day'] = array(
                'name'        => __( 'Independence Day', 'jezweb-dynamic-pricing' ),
                'date'        => $next_year . '-07-04',
                'duration'    => 3,
                'suggested_discount' => 25,
            );
            $holidays['thanksgiving'] = array(
                'name'        => __( 'Thanksgiving', 'jezweb-dynamic-pricing' ),
                'date'        => $this->get_thanksgiving( $next_year ),
                'duration'    => 4,
                'suggested_discount' => 30,
            );
            $holidays['memorial_day'] = array(
                'name'        => __( 'Memorial Day', 'jezweb-dynamic-pricing' ),
                'date'        => $this->get_memorial_day( $next_year ),
                'duration'    => 3,
                'suggested_discount' => 25,
            );
            $holidays['labor_day'] = array(
                'name'        => __( 'Labor Day', 'jezweb-dynamic-pricing' ),
                'date'        => $this->get_labor_day( $next_year ),
                'duration'    => 3,
                'suggested_discount' => 25,
            );
        } elseif ( 'UK' === $country ) {
            $holidays['bank_holiday_may'] = array(
                'name'        => __( 'May Bank Holiday', 'jezweb-dynamic-pricing' ),
                'date'        => $this->get_uk_may_bank_holiday( $next_year ),
                'duration'    => 3,
                'suggested_discount' => 20,
            );
            $holidays['bank_holiday_august'] = array(
                'name'        => __( 'August Bank Holiday', 'jezweb-dynamic-pricing' ),
                'date'        => $this->get_uk_august_bank_holiday( $next_year ),
                'duration'    => 3,
                'suggested_discount' => 20,
            );
        }

        return apply_filters( 'jdpd_holiday_presets', $holidays, $country );
    }

    /**
     * Check if a schedule is currently active.
     *
     * @param array $schedule Schedule configuration.
     * @return bool Whether schedule is active.
     */
    public function is_schedule_active( $schedule ) {
        if ( empty( $schedule ) || empty( $schedule['type'] ) ) {
            return true; // No schedule means always active
        }

        // Check cache
        $cache_key = md5( wp_json_encode( $schedule ) );
        if ( isset( $this->schedule_cache[ $cache_key ] ) ) {
            return $this->schedule_cache[ $cache_key ];
        }

        $is_active = false;
        $now = current_time( 'timestamp' );

        switch ( $schedule['type'] ) {
            case 'date_range':
                $is_active = $this->check_date_range( $schedule, $now );
                break;

            case 'time_of_day':
                $is_active = $this->check_time_of_day( $schedule, $now );
                break;

            case 'day_of_week':
                $is_active = $this->check_day_of_week( $schedule, $now );
                break;

            case 'recurring':
                $is_active = $this->check_recurring( $schedule, $now );
                break;

            case 'flash_sale':
                $is_active = $this->check_flash_sale( $schedule, $now );
                break;

            case 'combined':
                $is_active = $this->check_combined_schedule( $schedule, $now );
                break;
        }

        // Cache result for 60 seconds
        $this->schedule_cache[ $cache_key ] = $is_active;

        return $is_active;
    }

    /**
     * Check date range schedule.
     *
     * @param array $schedule Schedule configuration.
     * @param int   $now Current timestamp.
     * @return bool Whether active.
     */
    private function check_date_range( $schedule, $now ) {
        $start = ! empty( $schedule['start_date'] ) ? strtotime( $schedule['start_date'] ) : 0;
        $end = ! empty( $schedule['end_date'] ) ? strtotime( $schedule['end_date'] . ' 23:59:59' ) : PHP_INT_MAX;

        return $now >= $start && $now <= $end;
    }

    /**
     * Check time of day schedule.
     *
     * @param array $schedule Schedule configuration.
     * @param int   $now Current timestamp.
     * @return bool Whether active.
     */
    private function check_time_of_day( $schedule, $now ) {
        if ( empty( $schedule['start_time'] ) || empty( $schedule['end_time'] ) ) {
            return true;
        }

        $current_time = date( 'H:i', $now );
        $start_time = $schedule['start_time'];
        $end_time = $schedule['end_time'];

        // Handle overnight schedules (e.g., 22:00 - 06:00)
        if ( $start_time > $end_time ) {
            return $current_time >= $start_time || $current_time <= $end_time;
        }

        return $current_time >= $start_time && $current_time <= $end_time;
    }

    /**
     * Check day of week schedule.
     *
     * @param array $schedule Schedule configuration.
     * @param int   $now Current timestamp.
     * @return bool Whether active.
     */
    private function check_day_of_week( $schedule, $now ) {
        if ( empty( $schedule['days'] ) ) {
            return true;
        }

        $current_day = (int) date( 'w', $now ); // 0 = Sunday, 6 = Saturday

        return in_array( $current_day, array_map( 'intval', $schedule['days'] ), true );
    }

    /**
     * Check recurring schedule.
     *
     * @param array $schedule Schedule configuration.
     * @param int   $now Current timestamp.
     * @return bool Whether active.
     */
    private function check_recurring( $schedule, $now ) {
        if ( empty( $schedule['recurrence_type'] ) ) {
            return true;
        }

        $start = ! empty( $schedule['start_date'] ) ? strtotime( $schedule['start_date'] ) : $now;

        switch ( $schedule['recurrence_type'] ) {
            case 'daily':
                // Check time window if specified
                if ( ! empty( $schedule['start_time'] ) && ! empty( $schedule['end_time'] ) ) {
                    return $this->check_time_of_day( $schedule, $now );
                }
                return true;

            case 'weekly':
                // Active on specific days each week
                return $this->check_day_of_week( $schedule, $now );

            case 'monthly':
                // Active on specific day of month
                $day_of_month = ! empty( $schedule['day_of_month'] ) ? (int) $schedule['day_of_month'] : 1;
                $duration = ! empty( $schedule['duration_days'] ) ? (int) $schedule['duration_days'] : 1;

                $current_day = (int) date( 'j', $now );
                return $current_day >= $day_of_month && $current_day < ( $day_of_month + $duration );

            case 'yearly':
                // Active on specific dates each year
                if ( ! empty( $schedule['yearly_dates'] ) ) {
                    $current_month_day = date( 'm-d', $now );
                    foreach ( $schedule['yearly_dates'] as $date ) {
                        $target_month_day = date( 'm-d', strtotime( $date ) );
                        if ( $current_month_day === $target_month_day ) {
                            return true;
                        }
                    }
                }
                return false;

            case 'interval':
                // Active every N days/hours
                $interval = ! empty( $schedule['interval'] ) ? (int) $schedule['interval'] : 1;
                $interval_unit = ! empty( $schedule['interval_unit'] ) ? $schedule['interval_unit'] : 'days';
                $duration = ! empty( $schedule['duration'] ) ? (int) $schedule['duration'] : 1;
                $duration_unit = ! empty( $schedule['duration_unit'] ) ? $schedule['duration_unit'] : 'hours';

                // Convert to seconds
                $interval_seconds = $this->convert_to_seconds( $interval, $interval_unit );
                $duration_seconds = $this->convert_to_seconds( $duration, $duration_unit );

                $elapsed = $now - $start;
                $cycle_position = $elapsed % $interval_seconds;

                return $cycle_position < $duration_seconds;
        }

        return false;
    }

    /**
     * Check flash sale schedule.
     *
     * @param array $schedule Schedule configuration.
     * @param int   $now Current timestamp.
     * @return bool Whether active.
     */
    private function check_flash_sale( $schedule, $now ) {
        if ( empty( $schedule['flash_start'] ) || empty( $schedule['flash_duration'] ) ) {
            return false;
        }

        $start = strtotime( $schedule['flash_start'] );
        $duration_hours = (int) $schedule['flash_duration'];
        $end = $start + ( $duration_hours * 3600 );

        // Check for early bird period
        if ( ! empty( $schedule['early_bird_duration'] ) ) {
            $early_bird_end = $start + ( (int) $schedule['early_bird_duration'] * 60 ); // minutes
            if ( $now >= $start && $now < $early_bird_end ) {
                // Store early bird status for discount calculation
                $schedule['is_early_bird'] = true;
            }
        }

        return $now >= $start && $now <= $end;
    }

    /**
     * Check combined schedule (multiple conditions must be met).
     *
     * @param array $schedule Schedule configuration.
     * @param int   $now Current timestamp.
     * @return bool Whether active.
     */
    private function check_combined_schedule( $schedule, $now ) {
        if ( empty( $schedule['conditions'] ) ) {
            return true;
        }

        $operator = ! empty( $schedule['operator'] ) ? $schedule['operator'] : 'AND';

        foreach ( $schedule['conditions'] as $condition ) {
            $result = $this->is_schedule_active( $condition );

            if ( 'AND' === $operator && ! $result ) {
                return false;
            }

            if ( 'OR' === $operator && $result ) {
                return true;
            }
        }

        return 'AND' === $operator;
    }

    /**
     * Convert time value to seconds.
     *
     * @param int    $value Time value.
     * @param string $unit Time unit.
     * @return int Seconds.
     */
    private function convert_to_seconds( $value, $unit ) {
        switch ( $unit ) {
            case 'minutes':
                return $value * 60;
            case 'hours':
                return $value * 3600;
            case 'days':
                return $value * 86400;
            case 'weeks':
                return $value * 604800;
            default:
                return $value;
        }
    }

    /**
     * Get remaining time for a schedule.
     *
     * @param array $schedule Schedule configuration.
     * @return array|false Array with remaining time info or false if not active.
     */
    public function get_remaining_time( $schedule ) {
        if ( ! $this->is_schedule_active( $schedule ) ) {
            return false;
        }

        $now = current_time( 'timestamp' );
        $end_time = null;

        switch ( $schedule['type'] ) {
            case 'date_range':
                if ( ! empty( $schedule['end_date'] ) ) {
                    $end_time = strtotime( $schedule['end_date'] . ' 23:59:59' );
                }
                break;

            case 'time_of_day':
                if ( ! empty( $schedule['end_time'] ) ) {
                    $end_time = strtotime( date( 'Y-m-d' ) . ' ' . $schedule['end_time'] );
                    if ( $end_time < $now ) {
                        // Tomorrow
                        $end_time = strtotime( '+1 day', $end_time );
                    }
                }
                break;

            case 'flash_sale':
                if ( ! empty( $schedule['flash_start'] ) && ! empty( $schedule['flash_duration'] ) ) {
                    $start = strtotime( $schedule['flash_start'] );
                    $end_time = $start + ( (int) $schedule['flash_duration'] * 3600 );
                }
                break;
        }

        if ( null === $end_time ) {
            return false;
        }

        $remaining = $end_time - $now;

        if ( $remaining <= 0 ) {
            return false;
        }

        return array(
            'end_timestamp' => $end_time,
            'remaining_seconds' => $remaining,
            'remaining_formatted' => $this->format_remaining_time( $remaining ),
            'is_urgent' => $remaining < 3600, // Less than 1 hour
        );
    }

    /**
     * Format remaining time for display.
     *
     * @param int $seconds Remaining seconds.
     * @return string Formatted time.
     */
    public function format_remaining_time( $seconds ) {
        if ( $seconds < 60 ) {
            return sprintf(
                _n( '%d second', '%d seconds', $seconds, 'jezweb-dynamic-pricing' ),
                $seconds
            );
        }

        if ( $seconds < 3600 ) {
            $minutes = floor( $seconds / 60 );
            return sprintf(
                _n( '%d minute', '%d minutes', $minutes, 'jezweb-dynamic-pricing' ),
                $minutes
            );
        }

        if ( $seconds < 86400 ) {
            $hours = floor( $seconds / 3600 );
            $minutes = floor( ( $seconds % 3600 ) / 60 );

            if ( $minutes > 0 ) {
                return sprintf(
                    /* translators: 1: hours, 2: minutes */
                    __( '%1$d hours %2$d minutes', 'jezweb-dynamic-pricing' ),
                    $hours,
                    $minutes
                );
            }

            return sprintf(
                _n( '%d hour', '%d hours', $hours, 'jezweb-dynamic-pricing' ),
                $hours
            );
        }

        $days = floor( $seconds / 86400 );
        $hours = floor( ( $seconds % 86400 ) / 3600 );

        if ( $hours > 0 ) {
            return sprintf(
                /* translators: 1: days, 2: hours */
                __( '%1$d days %2$d hours', 'jezweb-dynamic-pricing' ),
                $days,
                $hours
            );
        }

        return sprintf(
            _n( '%d day', '%d days', $days, 'jezweb-dynamic-pricing' ),
            $days
        );
    }

    /**
     * Create schedule from holiday preset.
     *
     * @param string $holiday_key Holiday key.
     * @param int    $discount_percent Discount percentage.
     * @param string $country Country code.
     * @return array Schedule configuration.
     */
    public function create_holiday_schedule( $holiday_key, $discount_percent = null, $country = 'AU' ) {
        $holidays = $this->get_holiday_presets( $country );

        if ( ! isset( $holidays[ $holiday_key ] ) ) {
            return false;
        }

        $holiday = $holidays[ $holiday_key ];
        $discount = $discount_percent !== null ? $discount_percent : $holiday['suggested_discount'];

        $start_date = $holiday['date'];
        $end_date = date( 'Y-m-d', strtotime( $start_date . ' + ' . ( $holiday['duration'] - 1 ) . ' days' ) );

        return array(
            'type'        => 'date_range',
            'start_date'  => $start_date,
            'end_date'    => $end_date,
            'name'        => $holiday['name'],
            'discount'    => $discount,
            'holiday_key' => $holiday_key,
        );
    }

    /**
     * Process scheduled rules via cron.
     */
    public function process_scheduled_rules() {
        $rules = get_option( 'jdpd_rules', array() );
        $now = current_time( 'timestamp' );

        foreach ( $rules as $rule_id => $rule ) {
            if ( empty( $rule['schedule'] ) ) {
                continue;
            }

            $schedule = $rule['schedule'];
            $was_active = ! empty( $rule['_schedule_active'] );
            $is_active = $this->is_schedule_active( $schedule );

            // Check for state change
            if ( ! $was_active && $is_active ) {
                // Rule just became active
                do_action( 'jdpd_scheduled_rule_activated', $rule_id, $rule );

                // Flash sale starting notification
                if ( 'flash_sale' === $schedule['type'] ) {
                    do_action( 'jdpd_flash_sale_starting', $rule_id, $rule );
                }

                $rules[ $rule_id ]['_schedule_active'] = true;
                $rules[ $rule_id ]['_activated_at'] = $now;
            } elseif ( $was_active && ! $is_active ) {
                // Rule just became inactive
                do_action( 'jdpd_scheduled_rule_deactivated', $rule_id, $rule );

                $rules[ $rule_id ]['_schedule_active'] = false;
                $rules[ $rule_id ]['_deactivated_at'] = $now;
            }

            // Check for flash sale ending soon (15 minutes warning)
            if ( $is_active && 'flash_sale' === $schedule['type'] ) {
                $remaining = $this->get_remaining_time( $schedule );
                if ( $remaining && $remaining['remaining_seconds'] <= 900 && $remaining['remaining_seconds'] > 600 ) {
                    do_action( 'jdpd_flash_sale_ending', $rule_id, $rule );
                }
            }
        }

        update_option( 'jdpd_rules', $rules );
    }

    /**
     * Notify flash sale starting.
     *
     * @param string $rule_id Rule ID.
     * @param array  $rule Rule data.
     */
    public function notify_flash_sale_start( $rule_id, $rule ) {
        // Trigger email notification if enabled
        if ( class_exists( 'JDPD_Email_Notifications' ) ) {
            JDPD_Email_Notifications::get_instance()->send_flash_sale_notification( $rule_id, $rule, 'start' );
        }

        // Log the event
        if ( class_exists( 'JDPD_Analytics' ) ) {
            JDPD_Analytics::get_instance()->log_event( 'flash_sale_start', array(
                'rule_id' => $rule_id,
                'rule_name' => $rule['name'] ?? '',
            ) );
        }
    }

    /**
     * Notify flash sale ending.
     *
     * @param string $rule_id Rule ID.
     * @param array  $rule Rule data.
     */
    public function notify_flash_sale_ending( $rule_id, $rule ) {
        // Trigger email notification if enabled
        if ( class_exists( 'JDPD_Email_Notifications' ) ) {
            JDPD_Email_Notifications::get_instance()->send_flash_sale_notification( $rule_id, $rule, 'ending' );
        }
    }

    /**
     * Get schedule preview via AJAX.
     */
    public function ajax_get_schedule_preview() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $schedule = isset( $_POST['schedule'] ) ? wp_unslash( $_POST['schedule'] ) : array();

        if ( empty( $schedule ) ) {
            wp_send_json_error( array( 'message' => __( 'No schedule provided.', 'jezweb-dynamic-pricing' ) ) );
        }

        // Generate preview for next 30 days
        $preview = $this->generate_schedule_preview( $schedule, 30 );

        wp_send_json_success( array(
            'preview' => $preview,
            'is_active_now' => $this->is_schedule_active( $schedule ),
            'remaining_time' => $this->get_remaining_time( $schedule ),
        ) );
    }

    /**
     * Generate schedule preview for a number of days.
     *
     * @param array $schedule Schedule configuration.
     * @param int   $days Number of days to preview.
     * @return array Preview data.
     */
    public function generate_schedule_preview( $schedule, $days = 30 ) {
        $preview = array();
        $now = current_time( 'timestamp' );

        for ( $i = 0; $i < $days; $i++ ) {
            $day_start = strtotime( "+{$i} days midnight", $now );
            $day_active = false;
            $active_periods = array();

            // Check every hour of the day
            for ( $hour = 0; $hour < 24; $hour++ ) {
                $check_time = $day_start + ( $hour * 3600 );

                // Temporarily modify "now" for the check
                $temp_schedule = $schedule;

                // Simple check - just see if this hour would be active
                $is_hour_active = $this->is_schedule_active_at_time( $schedule, $check_time );

                if ( $is_hour_active ) {
                    $day_active = true;
                    $active_periods[] = $hour;
                }
            }

            $preview[] = array(
                'date'           => date( 'Y-m-d', $day_start ),
                'day_name'       => date_i18n( 'l', $day_start ),
                'is_active'      => $day_active,
                'active_periods' => $active_periods,
            );
        }

        return $preview;
    }

    /**
     * Check if schedule is active at a specific time.
     *
     * @param array $schedule Schedule configuration.
     * @param int   $timestamp Timestamp to check.
     * @return bool Whether active at that time.
     */
    public function is_schedule_active_at_time( $schedule, $timestamp ) {
        if ( empty( $schedule ) || empty( $schedule['type'] ) ) {
            return true;
        }

        switch ( $schedule['type'] ) {
            case 'date_range':
                return $this->check_date_range( $schedule, $timestamp );

            case 'time_of_day':
                return $this->check_time_of_day( $schedule, $timestamp );

            case 'day_of_week':
                return $this->check_day_of_week( $schedule, $timestamp );

            case 'recurring':
                return $this->check_recurring( $schedule, $timestamp );

            case 'flash_sale':
                return $this->check_flash_sale( $schedule, $timestamp );

            case 'combined':
                return $this->check_combined_schedule( $schedule, $timestamp );
        }

        return false;
    }

    /**
     * Get holiday presets via AJAX.
     */
    public function ajax_get_holiday_presets() {
        check_ajax_referer( 'jdpd_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'jezweb-dynamic-pricing' ) ) );
        }

        $country = isset( $_POST['country'] ) ? sanitize_text_field( $_POST['country'] ) : 'AU';
        $holidays = $this->get_holiday_presets( $country );

        wp_send_json_success( array( 'holidays' => $holidays ) );
    }

    // ========================
    // Holiday Date Calculations
    // ========================

    /**
     * Get Easter date for a year.
     *
     * @param int $year Year.
     * @return string Date string.
     */
    private function get_easter_date( $year ) {
        $base = new DateTime( "$year-03-21" );
        $days = easter_days( $year );
        $base->add( new DateInterval( "P{$days}D" ) );
        return $base->format( 'Y-m-d' );
    }

    /**
     * Get Mother's Day for a year and country.
     *
     * @param int    $year Year.
     * @param string $country Country code.
     * @return string Date string.
     */
    private function get_mothers_day( $year, $country ) {
        if ( 'AU' === $country || 'US' === $country ) {
            // Second Sunday of May
            $date = new DateTime( "second sunday of may $year" );
        } elseif ( 'UK' === $country ) {
            // Fourth Sunday of Lent (3 weeks before Easter)
            $easter = $this->get_easter_date( $year );
            $date = new DateTime( $easter );
            $date->sub( new DateInterval( 'P21D' ) );
        } else {
            $date = new DateTime( "second sunday of may $year" );
        }
        return $date->format( 'Y-m-d' );
    }

    /**
     * Get Father's Day for a year and country.
     *
     * @param int    $year Year.
     * @param string $country Country code.
     * @return string Date string.
     */
    private function get_fathers_day( $year, $country ) {
        if ( 'AU' === $country ) {
            // First Sunday of September
            $date = new DateTime( "first sunday of september $year" );
        } elseif ( 'US' === $country || 'UK' === $country ) {
            // Third Sunday of June
            $date = new DateTime( "third sunday of june $year" );
        } else {
            $date = new DateTime( "third sunday of june $year" );
        }
        return $date->format( 'Y-m-d' );
    }

    /**
     * Get Black Friday for a year.
     *
     * @param int $year Year.
     * @return string Date string.
     */
    private function get_black_friday( $year ) {
        // Day after Thanksgiving (fourth Thursday of November)
        $thanksgiving = new DateTime( "fourth thursday of november $year" );
        $thanksgiving->add( new DateInterval( 'P1D' ) );
        return $thanksgiving->format( 'Y-m-d' );
    }

    /**
     * Get Cyber Monday for a year.
     *
     * @param int $year Year.
     * @return string Date string.
     */
    private function get_cyber_monday( $year ) {
        // Monday after Black Friday
        $black_friday = $this->get_black_friday( $year );
        $date = new DateTime( $black_friday );
        $date->add( new DateInterval( 'P3D' ) );
        return $date->format( 'Y-m-d' );
    }

    /**
     * Get Thanksgiving for a year (US).
     *
     * @param int $year Year.
     * @return string Date string.
     */
    private function get_thanksgiving( $year ) {
        $date = new DateTime( "fourth thursday of november $year" );
        return $date->format( 'Y-m-d' );
    }

    /**
     * Get Memorial Day for a year (US).
     *
     * @param int $year Year.
     * @return string Date string.
     */
    private function get_memorial_day( $year ) {
        $date = new DateTime( "last monday of may $year" );
        return $date->format( 'Y-m-d' );
    }

    /**
     * Get Labor Day for a year (US).
     *
     * @param int $year Year.
     * @return string Date string.
     */
    private function get_labor_day( $year ) {
        $date = new DateTime( "first monday of september $year" );
        return $date->format( 'Y-m-d' );
    }

    /**
     * Get UK May Bank Holiday.
     *
     * @param int $year Year.
     * @return string Date string.
     */
    private function get_uk_may_bank_holiday( $year ) {
        $date = new DateTime( "first monday of may $year" );
        return $date->format( 'Y-m-d' );
    }

    /**
     * Get UK August Bank Holiday.
     *
     * @param int $year Year.
     * @return string Date string.
     */
    private function get_uk_august_bank_holiday( $year ) {
        $date = new DateTime( "last monday of august $year" );
        return $date->format( 'Y-m-d' );
    }

    /**
     * Clear schedule cache.
     */
    public function clear_cache() {
        $this->schedule_cache = array();
    }
}
