<?php
/**
 * Multi-Address Handler - UI y lógica para múltiples destinos
 *
 * @package Mandalo_Shipping
 */

defined('ABSPATH') || exit;

class Mandalo_Multi_Address {

    public function __construct() {
        // Add multi-address fields to checkout
        add_action('woocommerce_after_checkout_shipping_form', [$this, 'render_multi_address_fields']);

        // Validate multi-address fields
        add_action('woocommerce_checkout_process', [$this, 'validate_multi_address']);

        // Save multi-address to order
        add_action('woocommerce_checkout_create_order', [$this, 'save_multi_address_to_order'], 20, 2);
    }

    /**
     * Render multi-address input fields
     */
    public function render_multi_address_fields($checkout) {
        ?>
        <div id="mandalo-multi-address-wrapper" style="display: none;">
            <h4><?php _e('Paradas adicionales', 'mandalo-shipping'); ?></h4>
            <p class="form-row form-row-wide">
                <em><?php _e('Agrega las direcciones de entrega adicionales en el orden deseado.', 'mandalo-shipping'); ?></em>
            </p>

            <div id="mandalo-stops-container">
                <!-- Dynamic stops will be added here -->
            </div>

            <p class="form-row">
                <button type="button" id="mandalo-add-stop" class="button">
                    <?php _e('+ Agregar parada', 'mandalo-shipping'); ?>
                </button>
            </p>

            <div id="mandalo-route-options" style="display: none;">
                <p class="form-row form-row-wide">
                    <label class="checkbox">
                        <input type="checkbox" name="mandalo_optimize_route" id="mandalo_optimize_route" value="1" checked>
                        <?php _e('Optimizar ruta por distancia (obtén descuento)', 'mandalo-shipping'); ?>
                    </label>
                </p>
                <p class="form-row">
                    <small><?php _e('Si necesitas un orden específico de entrega, desmarca esta opción.', 'mandalo-shipping'); ?></small>
                </p>
            </div>

            <div id="mandalo-route-preview" style="display: none;">
                <h5><?php _e('Vista previa de la ruta', 'mandalo-shipping'); ?></h5>
                <div id="mandalo-route-details">
                    <p><strong><?php _e('Distancia total:', 'mandalo-shipping'); ?></strong> <span id="mandalo-total-distance">-</span></p>
                    <p><strong><?php _e('Costo estimado:', 'mandalo-shipping'); ?></strong> <span id="mandalo-estimated-cost">-</span></p>
                </div>
                <div id="mandalo-optimized-order" style="display: none;">
                    <p><strong><?php _e('Orden optimizado:', 'mandalo-shipping'); ?></strong></p>
                    <ol id="mandalo-optimized-list"></ol>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Validate multi-address fields
     */
    public function validate_multi_address() {
        $shipping_type = isset($_POST['mandalo_shipping_type']) ? sanitize_text_field($_POST['mandalo_shipping_type']) : '';

        if (!in_array($shipping_type, ['multi_optimized', 'multi_ordered'])) {
            return;
        }

        $stops = isset($_POST['mandalo_stops']) ? array_filter($_POST['mandalo_stops']) : [];

        if (empty($stops)) {
            wc_add_notice(__('Por favor agrega al menos una parada adicional para envío multi-destino.', 'mandalo-shipping'), 'error');
            return;
        }

        // Validate each stop has required info
        foreach ($stops as $index => $stop) {
            if (empty(trim($stop))) {
                wc_add_notice(sprintf(__('La parada %d no tiene dirección válida.', 'mandalo-shipping'), $index + 1), 'error');
            }
        }
    }

    /**
     * Save multi-address to order
     */
    public function save_multi_address_to_order($order, $data) {
        if (!empty($_POST['mandalo_stops'])) {
            $stops = array_map('sanitize_text_field', array_filter($_POST['mandalo_stops']));
            $order->update_meta_data('_mandalo_stops', $stops);
        }

        if (isset($_POST['mandalo_optimize_route'])) {
            $order->update_meta_data('_mandalo_optimize_route', true);
        }
    }

    /**
     * Get stop template HTML
     */
    public static function get_stop_template($index = 0) {
        ob_start();
        ?>
        <div class="mandalo-stop" data-index="<?php echo esc_attr($index); ?>">
            <p class="form-row form-row-wide">
                <label for="mandalo_stop_<?php echo esc_attr($index); ?>">
                    <?php printf(__('Parada %d', 'mandalo-shipping'), $index + 1); ?>
                    <span class="mandalo-remove-stop" title="<?php _e('Eliminar', 'mandalo-shipping'); ?>">&times;</span>
                </label>
                <input type="text"
                       class="input-text mandalo-stop-address"
                       name="mandalo_stops[]"
                       id="mandalo_stop_<?php echo esc_attr($index); ?>"
                       placeholder="<?php _e('Dirección completa', 'mandalo-shipping'); ?>"
                       autocomplete="off">
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Format stops for display
     */
    public static function format_stops_for_display($stops) {
        if (!is_array($stops) || empty($stops)) {
            return '';
        }

        $output = '<ol class="mandalo-stops-list">';
        foreach ($stops as $stop) {
            $output .= '<li>' . esc_html($stop) . '</li>';
        }
        $output .= '</ol>';

        return $output;
    }

    /**
     * Get total stops count including shipping address
     */
    public static function get_total_stops_count($order) {
        $stops = $order->get_meta('_mandalo_stops');

        if (!is_array($stops)) {
            return 1; // Just the shipping address
        }

        return count($stops) + 1; // Stops + shipping address
    }
}

// Initialize
new Mandalo_Multi_Address();
