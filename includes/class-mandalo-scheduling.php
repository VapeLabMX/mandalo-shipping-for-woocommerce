<?php
/**
 * Scheduling Handler - Envíos programados
 *
 * @package Mandalo_Shipping
 */

defined('ABSPATH') || exit;

class Mandalo_Scheduling {

    private $express_hours;
    private $max_days_ahead = 7;

    public function __construct() {
        $this->express_hours = get_option('mandalo_express_hours', '08:00-20:00');

        // Add scheduling fields to checkout
        add_action('woocommerce_after_checkout_shipping_form', [$this, 'render_scheduling_fields'], 20);

        // Validate scheduling fields
        add_action('woocommerce_checkout_process', [$this, 'validate_scheduling']);
    }

    /**
     * Render scheduling input fields
     */
    public function render_scheduling_fields($checkout) {
        ?>
        <div id="mandalo-scheduling-wrapper" style="display: none;">
            <h4><?php _e('Programar envío', 'mandalo-shipping'); ?></h4>

            <p class="form-row form-row-first">
                <label for="mandalo_scheduled_date"><?php _e('Fecha de entrega', 'mandalo-shipping'); ?> <span class="required">*</span></label>
                <input type="date"
                       class="input-text"
                       name="mandalo_scheduled_date"
                       id="mandalo_scheduled_date"
                       min="<?php echo esc_attr(date('Y-m-d', strtotime('+1 day'))); ?>"
                       max="<?php echo esc_attr(date('Y-m-d', strtotime('+' . $this->max_days_ahead . ' days'))); ?>">
            </p>

            <p class="form-row form-row-last">
                <label for="mandalo_scheduled_time"><?php _e('Horario preferido', 'mandalo-shipping'); ?></label>
                <select class="select" name="mandalo_scheduled_time" id="mandalo_scheduled_time">
                    <option value=""><?php _e('Selecciona horario', 'mandalo-shipping'); ?></option>
                </select>
            </p>

            <div class="clear"></div>

            <p class="form-row form-row-wide">
                <small><?php _e('Los envíos programados tienen un descuento del 10% sobre el precio regular.', 'mandalo-shipping'); ?></small>
            </p>
        </div>

        <!-- Express time validation -->
        <div id="mandalo-express-wrapper" style="display: none;">
            <h4><?php _e('Envío Express', 'mandalo-shipping'); ?></h4>
            <p class="form-row form-row-wide mandalo-express-info">
                <?php
                list($start, $end) = explode('-', $this->express_hours);
                printf(
                    __('El envío Express está disponible de %s a %s. Tu pedido será entregado hoy mismo.', 'mandalo-shipping'),
                    esc_html($start),
                    esc_html($end)
                );
                ?>
            </p>
            <div id="mandalo-express-unavailable" style="display: none;">
                <p class="woocommerce-error">
                    <?php _e('El envío Express no está disponible en este momento. Por favor selecciona otra opción de envío.', 'mandalo-shipping'); ?>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Validate scheduling fields
     */
    public function validate_scheduling() {
        $shipping_type = isset($_POST['mandalo_shipping_type']) ? sanitize_text_field($_POST['mandalo_shipping_type']) : '';

        if ($shipping_type === 'scheduled') {
            if (empty($_POST['mandalo_scheduled_date'])) {
                wc_add_notice(__('Por favor selecciona una fecha para el envío programado.', 'mandalo-shipping'), 'error');
                return;
            }

            $date = sanitize_text_field($_POST['mandalo_scheduled_date']);

            // Validate date is in the future
            if (strtotime($date) < strtotime('tomorrow')) {
                wc_add_notice(__('La fecha de envío debe ser al menos mañana.', 'mandalo-shipping'), 'error');
                return;
            }

            // Validate date is not too far in the future
            if (strtotime($date) > strtotime('+' . $this->max_days_ahead . ' days')) {
                wc_add_notice(sprintf(__('La fecha de envío no puede ser más de %d días en el futuro.', 'mandalo-shipping'), $this->max_days_ahead), 'error');
                return;
            }

            // Validate it's not a Sunday (optional)
            if (date('w', strtotime($date)) == 0) {
                wc_add_notice(__('No realizamos envíos los domingos. Por favor selecciona otro día.', 'mandalo-shipping'), 'error');
                return;
            }
        }

        if ($shipping_type === 'express') {
            if (!$this->is_express_available()) {
                wc_add_notice(__('El envío Express no está disponible en este momento.', 'mandalo-shipping'), 'error');
                return;
            }
        }
    }

    /**
     * Check if express shipping is available now
     */
    public function is_express_available() {
        list($start, $end) = explode('-', $this->express_hours);

        $now = current_time('H:i');
        $start_time = date('H:i', strtotime($start));
        $end_time = date('H:i', strtotime($end));

        // Must be within hours and have at least 1 hour before cutoff
        $one_hour_before_end = date('H:i', strtotime($end) - 3600);

        return ($now >= $start_time && $now <= $one_hour_before_end);
    }

    /**
     * Get available time slots for a date
     */
    public function get_available_slots($date) {
        global $wpdb;

        $day_of_week = date('w', strtotime($date));
        $table = $wpdb->prefix . 'mandalo_time_slots';

        // Get slots for this day
        $slots = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE day_of_week = %d AND enabled = 1 ORDER BY start_time ASC",
            $day_of_week
        ), ARRAY_A);

        if (empty($slots)) {
            return [];
        }

        // Count existing orders for each slot
        $available_slots = [];
        foreach ($slots as $slot) {
            $start = date('H:i', strtotime($slot['start_time']));
            $end = date('H:i', strtotime($slot['end_time']));

            // Count orders for this slot
            $order_count = $this->count_orders_for_slot($date, $start, $end);
            $remaining = max(0, intval($slot['max_orders']) - $order_count);

            if ($remaining > 0) {
                $available_slots[] = [
                    'id' => $slot['id'],
                    'label' => sprintf('%s - %s', $start, $end),
                    'value' => $start . '-' . $end,
                    'remaining' => $remaining,
                ];
            }
        }

        return $available_slots;
    }

    /**
     * Count orders for a time slot
     */
    private function count_orders_for_slot($date, $start, $end) {
        global $wpdb;

        // For HPOS compatibility
        if (class_exists('Automattic\WooCommerce\Utilities\OrderUtil') &&
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {

            $orders_table = $wpdb->prefix . 'wc_orders';
            $meta_table = $wpdb->prefix . 'wc_orders_meta';

            return $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT o.id)
                FROM {$orders_table} o
                JOIN {$meta_table} m1 ON o.id = m1.order_id AND m1.meta_key = '_mandalo_scheduled_date'
                JOIN {$meta_table} m2 ON o.id = m2.order_id AND m2.meta_key = '_mandalo_scheduled_time'
                WHERE o.status NOT IN ('wc-cancelled', 'wc-failed', 'wc-refunded')
                AND m1.meta_value = %s
                AND m2.meta_value LIKE %s",
                $date,
                $start . '%'
            ));
        }

        // Legacy post meta
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} m1 ON p.ID = m1.post_id AND m1.meta_key = '_mandalo_scheduled_date'
            JOIN {$wpdb->postmeta} m2 ON p.ID = m2.post_id AND m2.meta_key = '_mandalo_scheduled_time'
            WHERE p.post_type = 'shop_order'
            AND p.post_status NOT IN ('wc-cancelled', 'wc-failed', 'wc-refunded')
            AND m1.meta_value = %s
            AND m2.meta_value LIKE %s",
            $date,
            $start . '%'
        ));
    }

    /**
     * Get next available express slot
     */
    public function get_next_express_slot() {
        if (!$this->is_express_available()) {
            // Return tomorrow's first slot
            return [
                'date' => date('Y-m-d', strtotime('+1 day')),
                'time' => explode('-', $this->express_hours)[0],
                'available_today' => false,
            ];
        }

        return [
            'date' => date('Y-m-d'),
            'time' => 'ASAP',
            'available_today' => true,
        ];
    }

    /**
     * Format scheduled date for display
     */
    public static function format_scheduled_date($date, $time = '') {
        $formatted = date_i18n('l, j F Y', strtotime($date));

        if ($time) {
            $formatted .= ' - ' . $time;
        }

        return $formatted;
    }
}

// Initialize
new Mandalo_Scheduling();
