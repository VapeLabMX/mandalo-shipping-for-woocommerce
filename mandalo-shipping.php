<?php
/**
 * Plugin Name: Mandalo Shipping for WooCommerce
 * Plugin URI: https://mandalo.mx
 * Description: Sistema de envíos express CDMX con múltiples opciones: A-B, Multi-destino, Express, Programado, Camioneta
 * Version: 2.3.1
 * Author: Mandalo / VapeLab
 * Author URI: https://mandalo.mx
 * Text Domain: mandalo-shipping
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.4
 *
 * @package Mandalo_Shipping
 */

defined('ABSPATH') || exit;

// Plugin constants
define('MANDALO_SHIPPING_VERSION', '2.3.1');
define('MANDALO_SHIPPING_PATH', plugin_dir_path(__FILE__));
define('MANDALO_SHIPPING_URL', plugin_dir_url(__FILE__));
define('MANDALO_SHIPPING_BASENAME', plugin_basename(__FILE__));

/**
 * Check if WooCommerce is active
 */
function mandalo_shipping_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p><strong>Mandalo Shipping</strong> requiere WooCommerce para funcionar.</p></div>';
        });
        return false;
    }
    return true;
}

/**
 * Initialize the plugin
 */
function mandalo_shipping_init() {
    if (!mandalo_shipping_check_woocommerce()) {
        return;
    }

    // Load text domain
    load_plugin_textdomain('mandalo-shipping', false, dirname(MANDALO_SHIPPING_BASENAME) . '/languages');

    // Include required files
    require_once MANDALO_SHIPPING_PATH . 'includes/class-mandalo-geocoder-google.php';
    require_once MANDALO_SHIPPING_PATH . 'includes/class-mandalo-distance-calculator.php';
    require_once MANDALO_SHIPPING_PATH . 'includes/class-mandalo-pricing-engine.php';
    require_once MANDALO_SHIPPING_PATH . 'includes/class-mandalo-multi-address.php';
    require_once MANDALO_SHIPPING_PATH . 'includes/class-mandalo-scheduling.php';
    require_once MANDALO_SHIPPING_PATH . 'includes/class-mandalo-vehicle-handler.php';
    require_once MANDALO_SHIPPING_PATH . 'includes/class-wc-shipping-mandalo.php';
    require_once MANDALO_SHIPPING_PATH . 'includes/class-mandalo-quote-system.php';
    require_once MANDALO_SHIPPING_PATH . 'includes/class-mandalo-address-book.php';
}
add_action('plugins_loaded', 'mandalo_shipping_init', 20);

/**
 * Initialize shipping method
 */
function mandalo_shipping_method_init() {
    if (!class_exists('WC_Shipping_Mandalo')) {
        return;
    }
}
add_action('woocommerce_shipping_init', 'mandalo_shipping_method_init');

/**
 * Add shipping method to WooCommerce
 */
function mandalo_add_shipping_method($methods) {
    $methods['mandalo_shipping'] = 'WC_Shipping_Mandalo';
    return $methods;
}
add_filter('woocommerce_shipping_methods', 'mandalo_add_shipping_method');

/**
 * Activation hook
 */
register_activation_hook(__FILE__, function() {
    require_once MANDALO_SHIPPING_PATH . 'includes/class-mandalo-installer.php';
    Mandalo_Installer::activate();
});

/**
 * Deactivation hook
 */
register_deactivation_hook(__FILE__, function() {
    require_once MANDALO_SHIPPING_PATH . 'includes/class-mandalo-installer.php';
    Mandalo_Installer::deactivate();
});

/**
 * Enqueue frontend scripts
 */
function mandalo_shipping_enqueue_scripts() {
    if (!is_checkout() && !is_cart()) {
        return;
    }

    wp_enqueue_style(
        'mandalo-shipping-checkout',
        MANDALO_SHIPPING_URL . 'assets/css/checkout.css',
        [],
        MANDALO_SHIPPING_VERSION
    );

    wp_enqueue_script(
        'mandalo-shipping-checkout',
        MANDALO_SHIPPING_URL . 'assets/js/checkout.js',
        ['jquery', 'wc-checkout'],
        MANDALO_SHIPPING_VERSION,
        true
    );

    wp_localize_script('mandalo-shipping-checkout', 'MandaloShipping', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('mandalo_shipping_nonce'),
        'express_hours' => get_option('mandalo_express_hours', '08:00-20:00'),
        'i18n' => [
            // Stops
            'add_stop' => __('Agregar parada', 'mandalo-shipping'),
            'remove_stop' => __('Eliminar', 'mandalo-shipping'),
            'stop_label' => __('Parada', 'mandalo-shipping'),
            'drag_to_reorder' => __('Arrastra para reordenar', 'mandalo-shipping'),
            'address_placeholder' => __('Ingresa la direccion completa', 'mandalo-shipping'),
            // Route
            'optimize_route' => __('Optimizar ruta (descuento)', 'mandalo-shipping'),
            'keep_order' => __('Mantener orden especifico', 'mandalo-shipping'),
            'route_preview' => __('Vista previa de ruta', 'mandalo-shipping'),
            'optimized_order' => __('Orden optimizado de entrega', 'mandalo-shipping'),
            'distance' => __('Distancia', 'mandalo-shipping'),
            'stops' => __('Paradas', 'mandalo-shipping'),
            'estimated_time' => __('Tiempo estimado', 'mandalo-shipping'),
            // Quote
            'quote_title' => __('Cotizacion de envio', 'mandalo-shipping'),
            'calculated' => __('Calculado', 'mandalo-shipping'),
            'base_fee' => __('Tarifa base', 'mandalo-shipping'),
            'total' => __('Total', 'mandalo-shipping'),
            'delivery_eta' => __('Entrega estimada', 'mandalo-shipping'),
            'today' => __('Hoy', 'mandalo-shipping'),
            'business_days' => __('dias habiles', 'mandalo-shipping'),
            // States
            'calculating' => __('Calculando...', 'mandalo-shipping'),
            'loading' => __('Cargando...', 'mandalo-shipping'),
            'route_error' => __('Error al calcular la ruta', 'mandalo-shipping'),
            'connection_error' => __('Error de conexion', 'mandalo-shipping'),
            // Scheduling
            'select_date' => __('Selecciona fecha', 'mandalo-shipping'),
            'select_time' => __('Selecciona horario', 'mandalo-shipping'),
            'available' => __('disponibles', 'mandalo-shipping'),
            'no_slots' => __('No hay horarios disponibles', 'mandalo-shipping'),
            'error_loading' => __('Error al cargar', 'mandalo-shipping'),
            // Express
            'express_available' => __('Disponible ahora', 'mandalo-shipping'),
            'express_unavailable' => __('No disponible', 'mandalo-shipping'),
            // Vehicle
            'vehicle_type' => __('Vehiculo', 'mandalo-shipping'),
            'base_cost' => __('Costo base', 'mandalo-shipping'),
            'per_km' => __('Por km', 'mandalo-shipping'),
        ],
        'shipping_types' => [
            'standard' => __('Punto A-B', 'mandalo-shipping'),
            'multi_optimized' => __('Multi-destino (optimizado)', 'mandalo-shipping'),
            'multi_ordered' => __('Multi-destino (orden especifico)', 'mandalo-shipping'),
            'express' => __('Express (mismo dia)', 'mandalo-shipping'),
            'scheduled' => __('Programado', 'mandalo-shipping'),
            'truck' => __('Camioneta', 'mandalo-shipping'),
        ],
    ]);
}
add_action('wp_enqueue_scripts', 'mandalo_shipping_enqueue_scripts');

/**
 * Enqueue admin scripts
 */
function mandalo_shipping_admin_enqueue_scripts($hook) {
    if ($hook !== 'woocommerce_page_wc-settings') {
        return;
    }

    if (!isset($_GET['instance_id'])) {
        return;
    }

    wp_enqueue_style(
        'mandalo-shipping-admin',
        MANDALO_SHIPPING_URL . 'assets/css/admin.css',
        [],
        MANDALO_SHIPPING_VERSION
    );

    wp_enqueue_script(
        'mandalo-shipping-admin',
        MANDALO_SHIPPING_URL . 'assets/js/admin.js',
        ['jquery', 'jquery-ui-sortable'],
        MANDALO_SHIPPING_VERSION,
        true
    );

    wp_localize_script('mandalo-shipping-admin', 'MandaloAdmin', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('mandalo_admin_nonce'),
    ]);
}
add_action('admin_enqueue_scripts', 'mandalo_shipping_admin_enqueue_scripts');

/**
 * AJAX: Calculate multi-stop route
 */
add_action('wp_ajax_mandalo_calculate_route', 'mandalo_ajax_calculate_route');
add_action('wp_ajax_nopriv_mandalo_calculate_route', 'mandalo_ajax_calculate_route');

function mandalo_ajax_calculate_route() {
    check_ajax_referer('mandalo_shipping_nonce', 'nonce');

    $stops = isset($_POST['stops']) ? array_map('sanitize_text_field', $_POST['stops']) : [];
    $optimize = isset($_POST['optimize']) ? filter_var($_POST['optimize'], FILTER_VALIDATE_BOOLEAN) : true;
    $shipping_type = sanitize_text_field($_POST['shipping_type'] ?? 'standard');
    $origin = isset($_POST['origin']) ? sanitize_text_field($_POST['origin']) : '';

    if (empty($stops)) {
        wp_send_json_error(['message' => __('No se proporcionaron direcciones', 'mandalo-shipping')]);
    }

    $calculator = new Mandalo_Distance_Calculator();

    // For single destination, calculate simple distance
    if (count($stops) === 1) {
        $distance = $calculator->calculate_single_distance($stops[0]);
        if ($distance === null) {
            wp_send_json_error(['message' => __('No se pudo calcular la distancia', 'mandalo-shipping')]);
        }
        $result = [
            'total_distance' => $distance,
            'optimized_order' => null,
        ];
    } else {
        // Multi-stop route
        $result = $calculator->calculate_multi_stop_route($stops, $optimize);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }
    }

    $pricing = new Mandalo_Pricing_Engine();
    $price = $pricing->calculate_price($result['total_distance'], $shipping_type, count($stops));
    $breakdown = $pricing->get_price_breakdown($result['total_distance'], $shipping_type, count($stops));

    // Calculate estimated duration (approx 3 min per km in city traffic)
    $duration = round($result['total_distance'] * 3);

    wp_send_json_success([
        'total_distance' => round($result['total_distance'], 2),
        'duration' => $duration,
        'optimized_order' => $result['optimized_order'] ?? null,
        'price' => round($price, 2),
        'formatted_price' => wc_price($price),
        'breakdown' => $breakdown,
        'shipping_type' => $shipping_type,
        'stop_count' => count($stops),
    ]);
}

/**
 * AJAX: Get available time slots
 */
add_action('wp_ajax_mandalo_get_time_slots', 'mandalo_ajax_get_time_slots');
add_action('wp_ajax_nopriv_mandalo_get_time_slots', 'mandalo_ajax_get_time_slots');

function mandalo_ajax_get_time_slots() {
    check_ajax_referer('mandalo_shipping_nonce', 'nonce');

    $date = sanitize_text_field($_POST['date'] ?? '');

    if (empty($date)) {
        wp_send_json_error(['message' => 'No date provided']);
    }

    $scheduling = new Mandalo_Scheduling();
    $slots = $scheduling->get_available_slots($date);

    wp_send_json_success(['slots' => $slots]);
}

/**
 * AJAX: Validate vehicle requirements
 */
add_action('wp_ajax_mandalo_validate_vehicle', 'mandalo_ajax_validate_vehicle');
add_action('wp_ajax_nopriv_mandalo_validate_vehicle', 'mandalo_ajax_validate_vehicle');

function mandalo_ajax_validate_vehicle() {
    check_ajax_referer('mandalo_shipping_nonce', 'nonce');

    $weight = floatval($_POST['weight'] ?? 0);
    $length = floatval($_POST['length'] ?? 0);
    $width = floatval($_POST['width'] ?? 0);
    $height = floatval($_POST['height'] ?? 0);

    $vehicle_handler = new Mandalo_Vehicle_Handler();
    $result = $vehicle_handler->get_required_vehicle($weight, $length, $width, $height);

    wp_send_json_success($result);
}

/**
 * Save shipping meta to order
 */
add_action('woocommerce_checkout_create_order', 'mandalo_save_shipping_meta', 10, 2);

function mandalo_save_shipping_meta($order, $data) {
    // Multi-stop addresses
    if (!empty($_POST['mandalo_stops'])) {
        $stops = array_map('sanitize_text_field', $_POST['mandalo_stops']);
        $order->update_meta_data('_mandalo_stops', $stops);
    }

    // Shipping type
    if (!empty($_POST['mandalo_shipping_type'])) {
        $order->update_meta_data('_mandalo_shipping_type', sanitize_text_field($_POST['mandalo_shipping_type']));
    }

    // Optimized route
    if (isset($_POST['mandalo_optimize_route'])) {
        $order->update_meta_data('_mandalo_optimize_route', filter_var($_POST['mandalo_optimize_route'], FILTER_VALIDATE_BOOLEAN));
    }

    // Scheduled date/time
    if (!empty($_POST['mandalo_scheduled_date'])) {
        $order->update_meta_data('_mandalo_scheduled_date', sanitize_text_field($_POST['mandalo_scheduled_date']));
    }
    if (!empty($_POST['mandalo_scheduled_time'])) {
        $order->update_meta_data('_mandalo_scheduled_time', sanitize_text_field($_POST['mandalo_scheduled_time']));
    }

    // Vehicle info for truck shipping
    if (!empty($_POST['mandalo_package_weight'])) {
        $order->update_meta_data('_mandalo_package_weight', floatval($_POST['mandalo_package_weight']));
    }
    if (!empty($_POST['mandalo_package_dimensions'])) {
        $order->update_meta_data('_mandalo_package_dimensions', sanitize_text_field($_POST['mandalo_package_dimensions']));
    }
}

/**
 * Display shipping meta in admin order
 */
add_action('woocommerce_admin_order_data_after_shipping_address', 'mandalo_display_admin_order_meta', 10, 1);

function mandalo_display_admin_order_meta($order) {
    $shipping_type = $order->get_meta('_mandalo_shipping_type');
    $stops = $order->get_meta('_mandalo_stops');
    $optimized = $order->get_meta('_mandalo_optimize_route');
    $scheduled_date = $order->get_meta('_mandalo_scheduled_date');
    $scheduled_time = $order->get_meta('_mandalo_scheduled_time');
    $weight = $order->get_meta('_mandalo_package_weight');
    $dimensions = $order->get_meta('_mandalo_package_dimensions');

    if (!$shipping_type) {
        return;
    }

    echo '<div class="mandalo-shipping-info" style="margin-top: 15px; padding: 10px; background: #f8f8f8; border-left: 3px solid #FFC107;">';
    echo '<h4 style="margin: 0 0 10px; color: #333;">Mandalo Shipping</h4>';

    $types = [
        'standard' => 'Punto A-B',
        'multi_optimized' => 'Multi-destino (optimizado)',
        'multi_ordered' => 'Multi-destino (orden específico)',
        'express' => 'Express',
        'scheduled' => 'Programado',
        'truck' => 'Camioneta',
    ];

    echo '<p><strong>Tipo:</strong> ' . esc_html($types[$shipping_type] ?? $shipping_type) . '</p>';

    if (!empty($stops) && is_array($stops)) {
        echo '<p><strong>Paradas:</strong></p><ol>';
        foreach ($stops as $stop) {
            echo '<li>' . esc_html($stop) . '</li>';
        }
        echo '</ol>';
        if ($optimized) {
            echo '<p><em>Ruta optimizada por distancia</em></p>';
        }
    }

    if ($scheduled_date) {
        echo '<p><strong>Fecha programada:</strong> ' . esc_html($scheduled_date);
        if ($scheduled_time) {
            echo ' - ' . esc_html($scheduled_time);
        }
        echo '</p>';
    }

    if ($weight) {
        echo '<p><strong>Peso:</strong> ' . esc_html($weight) . ' kg</p>';
    }
    if ($dimensions) {
        echo '<p><strong>Dimensiones:</strong> ' . esc_html($dimensions) . '</p>';
    }

    echo '</div>';
}

/**
 * Add settings link in plugins page
 */
add_filter('plugin_action_links_' . MANDALO_SHIPPING_BASENAME, function($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=shipping') . '">' . __('Configuración', 'mandalo-shipping') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});

/**
 * Declare HPOS compatibility
 */
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Render main shipping UI container in checkout.
 *
 * Controlled by option `mandalo_render_checkout_widget` (default 'no').
 * Set to 'yes' to re-enable the legacy widget. With 'no' the checkout is
 * left clean for the canonical mandalo-core flow — rollback is a one-option
 * change, no code removal needed.
 */
if ( get_option( 'mandalo_render_checkout_widget', 'no' ) === 'yes' ) {
    add_action( 'woocommerce_before_checkout_shipping_form', 'mandalo_render_shipping_container', 5 );
}

function mandalo_render_shipping_container($checkout) {
    // Get store address for origin
    $store_address = implode(', ', array_filter([
        get_option('woocommerce_store_address'),
        get_option('woocommerce_store_city'),
        get_option('woocommerce_store_postcode'),
    ]));

    // Get enabled shipping types from settings - default all enabled for demo
    $enabled_types = get_option('mandalo_enabled_shipping_types', ['standard', 'multi_optimized', 'multi_ordered', 'express', 'scheduled', 'truck']);
    if (!is_array($enabled_types)) {
        $enabled_types = ['standard', 'multi_optimized', 'multi_ordered', 'express', 'scheduled', 'truck'];
    }

    // Shipping type definitions
    $shipping_types = [
        'standard' => [
            'name' => __('Envio Estandar', 'mandalo-shipping'),
            'description' => __('Entrega punto A a punto B. Ideal para envios sencillos.', 'mandalo-shipping'),
            'time' => __('1-2 dias habiles', 'mandalo-shipping'),
            'icon' => '<svg viewBox="0 0 24 24"><path d="M19.15 8a2 2 0 0 0-1.72-1H15V5a1 1 0 0 0-1-1H4a2 2 0 0 0-2 2v10a2 2 0 0 0 1 1.73 3.49 3.49 0 0 0 7 .27h3.1a3.48 3.48 0 0 0 6.9 0 2 2 0 0 0 2-2v-3a1.07 1.07 0 0 0-.14-.52zM15 9h2.43l1.8 3H15zM6.5 19A1.5 1.5 0 1 1 8 17.5 1.5 1.5 0 0 1 6.5 19zm10 0a1.5 1.5 0 1 1 1.5-1.5 1.5 1.5 0 0 1-1.5 1.5z"/></svg>',
            'badge' => '',
        ],
        'multi_optimized' => [
            'name' => __('Multi-destino Optimizado', 'mandalo-shipping'),
            'description' => __('Multiples paradas con ruta optimizada. Ahorra tiempo y dinero.', 'mandalo-shipping'),
            'time' => __('Segun distancia', 'mandalo-shipping'),
            'icon' => '<svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>',
            'badge' => 'discount',
            'badge_text' => __('Descuento', 'mandalo-shipping'),
        ],
        'multi_ordered' => [
            'name' => __('Multi-destino Orden Fijo', 'mandalo-shipping'),
            'description' => __('Multiples paradas en el orden que tu elijas.', 'mandalo-shipping'),
            'time' => __('Segun distancia', 'mandalo-shipping'),
            'icon' => '<svg viewBox="0 0 24 24"><path d="M3 13h2v-2H3v2zm0 4h2v-2H3v2zm0-8h2V7H3v2zm4 4h14v-2H7v2zm0 4h14v-2H7v2zM7 7v2h14V7H7z"/></svg>',
            'badge' => '',
        ],
        'express' => [
            'name' => __('Express', 'mandalo-shipping'),
            'description' => __('Entrega el mismo dia. Recogemos en minutos.', 'mandalo-shipping'),
            'time' => __('Hoy', 'mandalo-shipping'),
            'icon' => '<svg viewBox="0 0 24 24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>',
            'badge' => 'express',
            'badge_text' => '+50%',
        ],
        'scheduled' => [
            'name' => __('Programado', 'mandalo-shipping'),
            'description' => __('Elige fecha y hora de entrega. Planifica con anticipacion.', 'mandalo-shipping'),
            'time' => __('Tu eliges', 'mandalo-shipping'),
            'icon' => '<svg viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-1.99.9-1.99 2L3 20c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11zM9 11H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2zm-8 4H7v2h2v-2zm4 0h-2v2h2v-2zm4 0h-2v2h2v-2z"/></svg>',
            'badge' => 'discount',
            'badge_text' => '-10%',
        ],
        'truck' => [
            'name' => __('Camioneta', 'mandalo-shipping'),
            'description' => __('Para paquetes grandes o pesados. Cotiza segun dimensiones.', 'mandalo-shipping'),
            'time' => __('1-2 dias habiles', 'mandalo-shipping'),
            'icon' => '<svg viewBox="0 0 24 24"><path d="M20 8h-3V4H3c-1.1 0-2 .9-2 2v11h2c0 1.66 1.34 3 3 3s3-1.34 3-3h6c0 1.66 1.34 3 3 3s3-1.34 3-3h2v-5l-3-4zM6 18.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zm13.5-9l1.96 2.5H17V9.5h2.5zm-1.5 9c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z"/></svg>',
            'badge' => '',
        ],
    ];
    ?>
    <div id="mandalo-shipping-container">
        <h3><?php _e('Opciones de Envio Mandalo', 'mandalo-shipping'); ?></h3>

        <!-- Origin Section -->
        <div id="mandalo-origin-section">
            <h4><?php _e('Punto de Recoleccion', 'mandalo-shipping'); ?></h4>

            <div class="mandalo-origin-type-toggle">
                <label>
                    <input type="radio" name="mandalo_origin_type" value="store" checked>
                    <span><?php _e('Recoger en tienda', 'mandalo-shipping'); ?></span>
                </label>
                <label>
                    <input type="radio" name="mandalo_origin_type" value="custom">
                    <span><?php _e('Otra direccion', 'mandalo-shipping'); ?></span>
                </label>
            </div>

            <div id="mandalo-origin-custom-address" style="display: none;">
                <div class="mandalo-origin-address-input">
                    <input type="text"
                           id="mandalo_origin_address"
                           name="mandalo_origin_address"
                           placeholder="<?php _e('Ingresa la direccion de recoleccion', 'mandalo-shipping'); ?>"
                           autocomplete="off">
                </div>
            </div>

            <?php if ($store_address): ?>
            <p class="mandalo-store-address">
                <small><?php echo esc_html(sprintf(__('Tienda: %s', 'mandalo-shipping'), $store_address)); ?></small>
            </p>
            <?php endif; ?>
        </div>

        <!-- Shipping Type Selector -->
        <div id="mandalo-shipping-type-selector">
            <h4><?php _e('Selecciona tipo de envio', 'mandalo-shipping'); ?></h4>

            <div class="mandalo-shipping-types-grid">
                <?php
                $first = true;
                foreach ($shipping_types as $type_id => $type):
                    if (!in_array($type_id, $enabled_types)) continue;
                    $selected_class = $first ? ' selected' : '';
                    $first = false;
                ?>
                <div class="mandalo-shipping-type-card<?php echo $selected_class; ?>">
                    <input type="radio"
                           name="mandalo_shipping_type_select"
                           value="<?php echo esc_attr($type_id); ?>"
                           <?php echo $selected_class ? 'checked' : ''; ?>>

                    <div class="mandalo-type-header">
                        <div class="mandalo-type-icon">
                            <?php echo $type['icon']; ?>
                        </div>
                        <div class="mandalo-type-info">
                            <span class="mandalo-type-name"><?php echo esc_html($type['name']); ?></span>
                            <span class="mandalo-type-time"><?php echo esc_html($type['time']); ?></span>
                        </div>
                    </div>

                    <p class="mandalo-type-description"><?php echo esc_html($type['description']); ?></p>

                    <div class="mandalo-type-footer">
                        <span class="mandalo-type-price">
                            <small><?php _e('Desde', 'mandalo-shipping'); ?></small> $45 MXN
                        </span>
                        <?php if (!empty($type['badge'])): ?>
                        <span class="mandalo-type-badge <?php echo esc_attr($type['badge']); ?>">
                            <?php echo esc_html($type['badge_text']); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Multi-Address Section (shown for multi types) -->
        <div id="mandalo-multi-address-wrapper" style="display: none;">
            <h4><?php _e('Paradas de Entrega', 'mandalo-shipping'); ?></h4>
            <p class="mandalo-intro"><?php _e('Agrega las direcciones de entrega. Puedes arrastrar para reordenar.', 'mandalo-shipping'); ?></p>

            <div id="mandalo-stops-container">
                <!-- Stops added dynamically -->
            </div>

            <button type="button" id="mandalo-add-stop">
                <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
                <?php _e('Agregar parada', 'mandalo-shipping'); ?>
            </button>

            <div id="mandalo-route-options" style="display: none;">
                <label>
                    <input type="checkbox" name="mandalo_optimize_route" id="mandalo_optimize_route" value="1" checked>
                    <?php _e('Optimizar ruta automaticamente (obtener descuento)', 'mandalo-shipping'); ?>
                </label>
                <p class="optimize-description">
                    <?php _e('Desmarca si necesitas un orden especifico de entrega.', 'mandalo-shipping'); ?>
                </p>
            </div>

            <div id="mandalo-route-preview" style="display: none;">
                <!-- Route preview populated by JS -->
            </div>
        </div>

        <!-- Scheduling Section -->
        <div id="mandalo-scheduling-wrapper" style="display: none;">
            <h4><?php _e('Programar Envio', 'mandalo-shipping'); ?></h4>
            <p class="scheduling-intro"><?php _e('Selecciona la fecha y horario de entrega preferido.', 'mandalo-shipping'); ?></p>

            <div class="mandalo-scheduling-fields">
                <div class="mandalo-scheduling-field">
                    <label for="mandalo_scheduled_date"><?php _e('Fecha de entrega', 'mandalo-shipping'); ?> <span class="required">*</span></label>
                    <input type="date"
                           id="mandalo_scheduled_date"
                           name="mandalo_scheduled_date"
                           min="<?php echo esc_attr(date('Y-m-d', strtotime('+1 day'))); ?>"
                           max="<?php echo esc_attr(date('Y-m-d', strtotime('+7 days'))); ?>">
                </div>

                <div class="mandalo-scheduling-field">
                    <label for="mandalo_scheduled_time"><?php _e('Horario preferido', 'mandalo-shipping'); ?></label>
                    <select id="mandalo_scheduled_time" name="mandalo_scheduled_time">
                        <option value=""><?php _e('Selecciona fecha primero', 'mandalo-shipping'); ?></option>
                    </select>
                </div>
            </div>

            <div class="mandalo-scheduling-discount">
                <p>
                    <span class="discount-badge">-10%</span>
                    <?php _e('Los envios programados tienen descuento del 10%', 'mandalo-shipping'); ?>
                </p>
            </div>
        </div>

        <!-- Express Section -->
        <div id="mandalo-express-wrapper" style="display: none;">
            <h4><?php _e('Envio Express', 'mandalo-shipping'); ?></h4>

            <div class="mandalo-express-info">
                <div class="express-icon">
                    <svg viewBox="0 0 24 24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z"/></svg>
                </div>
                <div class="express-text">
                    <strong><?php _e('Entrega hoy mismo', 'mandalo-shipping'); ?></strong>
                    <span><?php
                        $express_hours = get_option('mandalo_express_hours', '08:00-20:00');
                        list($start, $end) = explode('-', $express_hours);
                        printf(__('Disponible de %s a %s', 'mandalo-shipping'), esc_html($start), esc_html($end));
                    ?></span>
                </div>
            </div>

            <div id="mandalo-express-unavailable" style="display: none;">
                <p><?php _e('El envio Express no esta disponible en este momento. Por favor selecciona otra opcion.', 'mandalo-shipping'); ?></p>
            </div>
        </div>

        <!-- Vehicle Section -->
        <div id="mandalo-vehicle-wrapper" style="display: none;">
            <h4><?php _e('Dimensiones del Paquete', 'mandalo-shipping'); ?></h4>
            <p class="vehicle-intro"><?php _e('Ingresa las dimensiones para cotizar el vehiculo adecuado.', 'mandalo-shipping'); ?></p>

            <div class="mandalo-vehicle-fields">
                <div class="mandalo-vehicle-field">
                    <label for="mandalo_package_weight"><?php _e('Peso', 'mandalo-shipping'); ?> <span class="required">*</span></label>
                    <input type="number" id="mandalo_package_weight" name="mandalo_package_weight" min="1" max="1000" step="0.5" placeholder="25">
                    <span class="unit">kg</span>
                </div>

                <div class="mandalo-vehicle-field">
                    <label for="mandalo_package_length"><?php _e('Largo', 'mandalo-shipping'); ?> <span class="required">*</span></label>
                    <input type="number" id="mandalo_package_length" name="mandalo_package_length" min="1" max="500" placeholder="100">
                    <span class="unit">cm</span>
                </div>

                <div class="mandalo-vehicle-field">
                    <label for="mandalo_package_width"><?php _e('Ancho', 'mandalo-shipping'); ?> <span class="required">*</span></label>
                    <input type="number" id="mandalo_package_width" name="mandalo_package_width" min="1" max="300" placeholder="50">
                    <span class="unit">cm</span>
                </div>

                <div class="mandalo-vehicle-field">
                    <label for="mandalo_package_height"><?php _e('Alto', 'mandalo-shipping'); ?> <span class="required">*</span></label>
                    <input type="number" id="mandalo_package_height" name="mandalo_package_height" min="1" max="250" placeholder="60">
                    <span class="unit">cm</span>
                </div>
            </div>

            <div id="mandalo-vehicle-recommendation" style="display: none;">
                <h5><?php _e('Vehiculo Recomendado', 'mandalo-shipping'); ?></h5>
                <div id="mandalo-vehicle-details">
                    <!-- Populated by JS -->
                </div>
            </div>

            <div id="mandalo-vehicle-oversized" style="display: none;">
                <p><?php _e('Tu paquete excede las dimensiones estandar. Contactanos para una cotizacion personalizada.', 'mandalo-shipping'); ?></p>
                <a href="https://wa.me/525512345678" target="_blank" class="button">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                    <?php _e('Contactar por WhatsApp', 'mandalo-shipping'); ?>
                </a>
            </div>
        </div>

        <!-- Quote Summary (populated by JS) -->
        <div id="mandalo-quote-summary" style="display: none;">
            <!-- Quote breakdown populated by JS -->
        </div>
    </div>
    <?php
}
