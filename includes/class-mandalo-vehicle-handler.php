<?php
/**
 * Vehicle Handler - Manejo de tipos de vehículo y camioneta
 *
 * @package Mandalo_Shipping
 */

defined('ABSPATH') || exit;

class Mandalo_Vehicle_Handler {

    public function __construct() {
        // Add vehicle fields to checkout
        add_action('woocommerce_after_checkout_shipping_form', [$this, 'render_vehicle_fields'], 30);

        // Validate vehicle fields
        add_action('woocommerce_checkout_process', [$this, 'validate_vehicle_fields']);
    }

    /**
     * Render vehicle/package dimension fields
     */
    public function render_vehicle_fields($checkout) {
        ?>
        <div id="mandalo-vehicle-wrapper" style="display: none;">
            <h4><?php _e('Información del paquete (Camioneta)', 'mandalo-shipping'); ?></h4>
            <p class="form-row form-row-wide">
                <em><?php _e('Para envíos en camioneta, necesitamos conocer las dimensiones de tu paquete.', 'mandalo-shipping'); ?></em>
            </p>

            <p class="form-row form-row-wide">
                <label for="mandalo_package_weight"><?php _e('Peso aproximado (kg)', 'mandalo-shipping'); ?> <span class="required">*</span></label>
                <input type="number"
                       class="input-text"
                       name="mandalo_package_weight"
                       id="mandalo_package_weight"
                       min="1"
                       max="1000"
                       step="0.5"
                       placeholder="<?php _e('Ej: 25', 'mandalo-shipping'); ?>">
            </p>

            <p class="form-row form-row-first">
                <label for="mandalo_package_length"><?php _e('Largo (cm)', 'mandalo-shipping'); ?> <span class="required">*</span></label>
                <input type="number"
                       class="input-text"
                       name="mandalo_package_length"
                       id="mandalo_package_length"
                       min="1"
                       max="500"
                       placeholder="<?php _e('Ej: 100', 'mandalo-shipping'); ?>">
            </p>

            <p class="form-row form-row-last">
                <label for="mandalo_package_width"><?php _e('Ancho (cm)', 'mandalo-shipping'); ?> <span class="required">*</span></label>
                <input type="number"
                       class="input-text"
                       name="mandalo_package_width"
                       id="mandalo_package_width"
                       min="1"
                       max="300"
                       placeholder="<?php _e('Ej: 50', 'mandalo-shipping'); ?>">
            </p>

            <div class="clear"></div>

            <p class="form-row form-row-first">
                <label for="mandalo_package_height"><?php _e('Alto (cm)', 'mandalo-shipping'); ?> <span class="required">*</span></label>
                <input type="number"
                       class="input-text"
                       name="mandalo_package_height"
                       id="mandalo_package_height"
                       min="1"
                       max="250"
                       placeholder="<?php _e('Ej: 60', 'mandalo-shipping'); ?>">
            </p>

            <div class="clear"></div>

            <div id="mandalo-vehicle-recommendation" style="display: none;">
                <h5><?php _e('Vehículo recomendado', 'mandalo-shipping'); ?></h5>
                <div id="mandalo-vehicle-details">
                    <p><strong><?php _e('Tipo:', 'mandalo-shipping'); ?></strong> <span id="mandalo-vehicle-type">-</span></p>
                    <p><strong><?php _e('Costo base:', 'mandalo-shipping'); ?></strong> <span id="mandalo-vehicle-base">-</span></p>
                    <p><strong><?php _e('Por kilómetro:', 'mandalo-shipping'); ?></strong> <span id="mandalo-vehicle-perkm">-</span></p>
                </div>
            </div>

            <div id="mandalo-vehicle-oversized" style="display: none;">
                <p class="woocommerce-info">
                    <?php _e('Tu paquete excede las dimensiones estándar. Por favor contáctanos para una cotización personalizada.', 'mandalo-shipping'); ?>
                    <br>
                    <a href="https://wa.me/525512345678" target="_blank" class="button"><?php _e('Contactar por WhatsApp', 'mandalo-shipping'); ?></a>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Validate vehicle fields
     */
    public function validate_vehicle_fields() {
        $shipping_type = isset($_POST['mandalo_shipping_type']) ? sanitize_text_field($_POST['mandalo_shipping_type']) : '';

        if ($shipping_type !== 'truck') {
            return;
        }

        $weight = floatval($_POST['mandalo_package_weight'] ?? 0);
        $length = floatval($_POST['mandalo_package_length'] ?? 0);
        $width = floatval($_POST['mandalo_package_width'] ?? 0);
        $height = floatval($_POST['mandalo_package_height'] ?? 0);

        if ($weight <= 0) {
            wc_add_notice(__('Por favor ingresa el peso del paquete.', 'mandalo-shipping'), 'error');
            return;
        }

        if ($length <= 0 || $width <= 0 || $height <= 0) {
            wc_add_notice(__('Por favor ingresa las dimensiones completas del paquete.', 'mandalo-shipping'), 'error');
            return;
        }

        // Check if package fits in any vehicle
        $vehicle = $this->get_required_vehicle($weight, $length, $width, $height);

        if (!$vehicle['fits']) {
            wc_add_notice(__('Tu paquete excede las dimensiones máximas de nuestros vehículos. Por favor contáctanos.', 'mandalo-shipping'), 'error');
            return;
        }
    }

    /**
     * Get required vehicle based on dimensions
     *
     * @param float $weight Weight in kg
     * @param float $length Length in cm
     * @param float $width Width in cm
     * @param float $height Height in cm
     * @return array Vehicle info or error
     */
    public function get_required_vehicle($weight, $length, $width, $height) {
        global $wpdb;

        $table = $wpdb->prefix . 'mandalo_vehicles';

        // Get all vehicles ordered by capacity (smallest first)
        $vehicles = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE enabled = 1 ORDER BY max_weight ASC",
            ARRAY_A
        );

        if (empty($vehicles)) {
            // Default vehicles if table is empty
            $vehicles = $this->get_default_vehicles();
        }

        // Find smallest vehicle that fits
        foreach ($vehicles as $vehicle) {
            if ($weight <= $vehicle['max_weight'] &&
                $length <= $vehicle['max_length'] &&
                $width <= $vehicle['max_width'] &&
                $height <= $vehicle['max_height']) {

                return [
                    'fits' => true,
                    'vehicle' => $vehicle,
                    'base_rate' => floatval($vehicle['base_rate']),
                    'per_km_rate' => floatval($vehicle['per_km_rate']),
                ];
            }
        }

        // Package doesn't fit in any vehicle
        return [
            'fits' => false,
            'message' => __('El paquete excede las dimensiones máximas disponibles.', 'mandalo-shipping'),
            'max_dimensions' => $this->get_max_dimensions($vehicles),
        ];
    }

    /**
     * Get default vehicles
     */
    private function get_default_vehicles() {
        return [
            [
                'name' => 'Moto',
                'type' => 'moto',
                'max_weight' => 15,
                'max_length' => 50,
                'max_width' => 40,
                'max_height' => 40,
                'base_rate' => 50,
                'per_km_rate' => 8,
            ],
            [
                'name' => 'Auto',
                'type' => 'auto',
                'max_weight' => 50,
                'max_length' => 100,
                'max_width' => 80,
                'max_height' => 60,
                'base_rate' => 80,
                'per_km_rate' => 12,
            ],
            [
                'name' => 'Camioneta Chica',
                'type' => 'camioneta_chica',
                'max_weight' => 200,
                'max_length' => 180,
                'max_width' => 120,
                'max_height' => 120,
                'base_rate' => 150,
                'per_km_rate' => 18,
            ],
            [
                'name' => 'Camioneta Grande',
                'type' => 'camioneta_grande',
                'max_weight' => 500,
                'max_length' => 300,
                'max_width' => 180,
                'max_height' => 180,
                'base_rate' => 250,
                'per_km_rate' => 25,
            ],
        ];
    }

    /**
     * Get maximum dimensions from all vehicles
     */
    private function get_max_dimensions($vehicles) {
        $max = [
            'weight' => 0,
            'length' => 0,
            'width' => 0,
            'height' => 0,
        ];

        foreach ($vehicles as $v) {
            $max['weight'] = max($max['weight'], $v['max_weight']);
            $max['length'] = max($max['length'], $v['max_length']);
            $max['width'] = max($max['width'], $v['max_width']);
            $max['height'] = max($max['height'], $v['max_height']);
        }

        return $max;
    }

    /**
     * Get all available vehicles
     */
    public function get_available_vehicles() {
        global $wpdb;

        $table = $wpdb->prefix . 'mandalo_vehicles';

        $vehicles = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE enabled = 1 ORDER BY max_weight ASC",
            ARRAY_A
        );

        return !empty($vehicles) ? $vehicles : $this->get_default_vehicles();
    }

    /**
     * Auto-detect vehicle from cart contents
     */
    public function detect_vehicle_from_cart() {
        if (!WC()->cart) {
            return null;
        }

        $total_weight = 0;
        $max_length = 0;
        $max_width = 0;
        $max_height = 0;

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $qty = $cart_item['quantity'];

            // Weight
            $weight = $product->get_weight();
            if ($weight) {
                $total_weight += floatval($weight) * $qty;
            }

            // Dimensions
            $length = $product->get_length();
            $width = $product->get_width();
            $height = $product->get_height();

            if ($length) $max_length = max($max_length, floatval($length));
            if ($width) $max_width = max($max_width, floatval($width));
            if ($height) $max_height = max($max_height, floatval($height));
        }

        // If no dimensions, can't auto-detect
        if ($total_weight == 0 && $max_length == 0) {
            return null;
        }

        return $this->get_required_vehicle($total_weight, $max_length, $max_width, $max_height);
    }
}

// Initialize
new Mandalo_Vehicle_Handler();
