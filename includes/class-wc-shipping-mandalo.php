<?php
/**
 * WooCommerce Shipping Method - Mandalo
 *
 * @package Mandalo_Shipping
 */

defined('ABSPATH') || exit;

if (!class_exists('WC_Shipping_Mandalo')) {

    class WC_Shipping_Mandalo extends WC_Shipping_Method {

        private $distance_calculator;
        private $pricing_engine;
        private $scheduling;
        private $vehicle_handler;

        public function __construct($instance_id = 0) {
            $this->id = 'mandalo_shipping';
            $this->instance_id = absint($instance_id);
            $this->method_title = __('Mandalo Shipping', 'mandalo-shipping');
            $this->method_description = __('Envíos express CDMX - Múltiples opciones de entrega', 'mandalo-shipping');

            $this->supports = [
                'shipping-zones',
                'instance-settings',
                'instance-settings-modal',
            ];

            $this->init();

            // Initialize helpers
            $this->distance_calculator = new Mandalo_Distance_Calculator();
            $this->pricing_engine = new Mandalo_Pricing_Engine();
            $this->scheduling = new Mandalo_Scheduling();
            $this->vehicle_handler = new Mandalo_Vehicle_Handler();
        }

        public function init() {
            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title', $this->method_title);
            $this->enabled = $this->get_option('enabled', 'yes');

            add_action('woocommerce_update_options_shipping_' . $this->id, [$this, 'process_admin_options']);
        }

        public function init_form_fields() {
            $this->instance_form_fields = [
                'title' => [
                    'title' => __('Título del método', 'mandalo-shipping'),
                    'type' => 'text',
                    'description' => __('El título que verán los clientes en el checkout.', 'mandalo-shipping'),
                    'default' => __('Mandalo - Envío Express', 'mandalo-shipping'),
                    'desc_tip' => true,
                ],
                'enabled_types' => [
                    'title' => __('Tipos de envío habilitados', 'mandalo-shipping'),
                    'type' => 'multiselect',
                    'class' => 'wc-enhanced-select',
                    'default' => ['standard', 'express', 'scheduled'],
                    'options' => [
                        'standard' => __('Punto A-B (Estándar)', 'mandalo-shipping'),
                        'multi_optimized' => __('Multi-destino Optimizado', 'mandalo-shipping'),
                        'multi_ordered' => __('Multi-destino Orden Específico', 'mandalo-shipping'),
                        'express' => __('Express (Mismo día)', 'mandalo-shipping'),
                        'scheduled' => __('Programado', 'mandalo-shipping'),
                        'truck' => __('Camioneta', 'mandalo-shipping'),
                    ],
                ],
                'base_rate' => [
                    'title' => __('Tarifa base (MXN)', 'mandalo-shipping'),
                    'type' => 'number',
                    'description' => __('Costo base antes de calcular por distancia.', 'mandalo-shipping'),
                    'default' => 45,
                    'custom_attributes' => ['min' => 0, 'step' => 1],
                ],
                'per_km_rate' => [
                    'title' => __('Tarifa por km (MXN)', 'mandalo-shipping'),
                    'type' => 'number',
                    'description' => __('Costo adicional por cada kilómetro de distancia.', 'mandalo-shipping'),
                    'default' => 8,
                    'custom_attributes' => ['min' => 0, 'step' => 0.5],
                ],
                'express_multiplier' => [
                    'title' => __('Multiplicador Express', 'mandalo-shipping'),
                    'type' => 'number',
                    'description' => __('Factor de multiplicación para envíos Express (ej: 1.5 = 50% más).', 'mandalo-shipping'),
                    'default' => 1.5,
                    'custom_attributes' => ['min' => 1, 'max' => 3, 'step' => 0.1],
                ],
                'scheduled_discount' => [
                    'title' => __('Descuento Programado (%)', 'mandalo-shipping'),
                    'type' => 'number',
                    'description' => __('Porcentaje de descuento para envíos programados.', 'mandalo-shipping'),
                    'default' => 10,
                    'custom_attributes' => ['min' => 0, 'max' => 50, 'step' => 1],
                ],
                'multi_stop_discount' => [
                    'title' => __('Descuento Multi-parada Optimizada (%)', 'mandalo-shipping'),
                    'type' => 'number',
                    'description' => __('Descuento base por optimizar ruta multi-destino.', 'mandalo-shipping'),
                    'default' => 15,
                    'custom_attributes' => ['min' => 0, 'max' => 50, 'step' => 1],
                ],
                'free_shipping_minimum' => [
                    'title' => __('Envío gratis a partir de (MXN)', 'mandalo-shipping'),
                    'type' => 'number',
                    'description' => __('Monto mínimo del carrito para envío gratis. Dejar en 0 para deshabilitar.', 'mandalo-shipping'),
                    'default' => 0,
                    'custom_attributes' => ['min' => 0, 'step' => 1],
                ],
                'max_distance' => [
                    'title' => __('Distancia máxima (km)', 'mandalo-shipping'),
                    'type' => 'number',
                    'description' => __('Distancia máxima de cobertura. Dejar en 0 para sin límite.', 'mandalo-shipping'),
                    'default' => 50,
                    'custom_attributes' => ['min' => 0, 'step' => 1],
                ],
                'express_hours' => [
                    'title' => __('Horario Express', 'mandalo-shipping'),
                    'type' => 'text',
                    'description' => __('Horario en que está disponible el envío Express (formato: HH:MM-HH:MM).', 'mandalo-shipping'),
                    'default' => '08:00-20:00',
                ],

                // ── Geocoder settings ────────────────────────────────────────
                'geocoder_section' => [
                    'title' => __('Proveedor de Geocoding', 'mandalo-shipping'),
                    'type'  => 'title',
                    'description' => __('Selecciona el proveedor de geocoding para convertir direcciones a coordenadas.', 'mandalo-shipping'),
                ],
                'geocoder' => [
                    'title'   => __('Proveedor', 'mandalo-shipping'),
                    'type'    => 'select',
                    'default' => 'nominatim',
                    'options' => [
                        'nominatim' => __('Nominatim / OpenStreetMap (gratuito, default)', 'mandalo-shipping'),
                        'google'    => __('Google Maps (Geocoding API + Places Text Search)', 'mandalo-shipping'),
                    ],
                    'description' => __('Nominatim es gratuito y no requiere API key. Google ofrece mejor cobertura para POIs y plazas comerciales en CDMX.', 'mandalo-shipping'),
                    'desc_tip'    => true,
                ],
                'google_maps_api_key' => [
                    'title'       => __('Google Maps API Key', 'mandalo-shipping'),
                    'type'        => 'password',
                    'description' => __('Requerido solo con proveedor Google. La key debe tener habilitadas: Geocoding API y Places API (Text Search).', 'mandalo-shipping'),
                    'default'     => '',
                    'placeholder' => __('Pega tu API key aquí', 'mandalo-shipping'),
                    'desc_tip'    => true,
                ],

                // ── Checkout widget ──────────────────────────────────────────
                'checkout_widget_section' => [
                    'title' => __('Widget de Checkout', 'mandalo-shipping'),
                    'type'  => 'title',
                    'description' => __('Controla el widget embebido en el checkout. Con mandalo-core activo se recomienda mantenerlo desactivado.', 'mandalo-shipping'),
                ],
                'render_checkout_widget' => [
                    'title'   => __('Mostrar widget de shipping en checkout', 'mandalo-shipping'),
                    'type'    => 'checkbox',
                    'label'   => __('Activar el widget de selección de tipo de envío en la página de checkout', 'mandalo-shipping'),
                    'default' => 'no',
                    'description' => __('Desactivado por defecto. Activar solo en instalaciones sin mandalo-core o para pruebas de laboratorio. El código se mantiene intacto; rollback inmediato desmarcando esta opción.', 'mandalo-shipping'),
                ],
            ];
        }

        /**
         * Save instance settings and sync global options used by the plugin.
         *
         * We persist `mandalo_geocoder`, `mandalo_google_maps_api_key`, and
         * `mandalo_render_checkout_widget` as global wp_options so that the
         * Distance Calculator and the checkout hook can read them without
         * requiring an instance object.
         */
        public function process_admin_options(): bool {
            $saved = parent::process_admin_options();

            // Sync geocoder provider to global option.
            $provider = $this->get_option( 'geocoder', 'nominatim' );
            update_option( 'mandalo_geocoder', sanitize_key( $provider ) );

            // Sync Google API key to global option.
            $api_key = $this->get_option( 'google_maps_api_key', '' );
            update_option( 'mandalo_google_maps_api_key', sanitize_text_field( $api_key ) );

            // Sync checkout widget toggle to global option.
            $widget = $this->get_option( 'render_checkout_widget', 'no' );
            update_option( 'mandalo_render_checkout_widget', ( $widget === 'yes' ) ? 'yes' : 'no' );

            // Invalidate store geocode cache when geocoder changes.
            delete_option( 'mandalo_store_geocode_cache' );

            return $saved;
        }

        /**
         * Check if method is available
         */
        public function is_available($package) {
            $is_available = parent::is_available($package);

            if (!$is_available) {
                return false;
            }

            // Check if destination is within service area
            $max_distance = floatval($this->get_option('max_distance', 50));

            if ($max_distance > 0) {
                $dest = $this->build_destination_string($package);
                if ($dest) {
                    $distance = $this->distance_calculator->calculate_single_distance($dest);
                    if ($distance && $distance > $max_distance) {
                        return false;
                    }
                }
            }

            return true;
        }

        /**
         * Calculate shipping rates
         */
        public function calculate_shipping($package = []) {
            // Build destination
            $dest = $this->build_destination_string($package);

            if (empty($dest)) {
                return;
            }

            // Calculate distance
            $distance = $this->distance_calculator->calculate_single_distance($dest, [
                'street' => ($package['destination']['address_1'] ?? '') . ' ' . ($package['destination']['address_2'] ?? ''),
                'city' => $package['destination']['city'] ?? '',
                'state' => $package['destination']['state'] ?? '',
                'postcode' => $package['destination']['postcode'] ?? '',
                'country' => $package['destination']['country'] ?? 'MX',
            ]);

            if (!$distance) {
                return;
            }

            // Check max distance
            $max_distance = floatval($this->get_option('max_distance', 50));
            if ($max_distance > 0 && $distance > $max_distance) {
                return;
            }

            // Get enabled shipping types
            $enabled_types = $this->get_option('enabled_types', ['standard', 'express', 'scheduled']);

            // Check for free shipping
            $free_minimum = floatval($this->get_option('free_shipping_minimum', 0));
            $cart_total = WC()->cart ? WC()->cart->get_subtotal() : 0;
            $is_free = ($free_minimum > 0 && $cart_total >= $free_minimum);

            // Generate rates for each enabled type
            foreach ($enabled_types as $type) {
                $rate = $this->generate_rate_for_type($type, $distance, $is_free);
                if ($rate) {
                    $this->add_rate($rate);
                }
            }
        }

        /**
         * Generate rate for specific shipping type
         */
        private function generate_rate_for_type($type, $distance, $is_free = false) {
            $base_rate = floatval($this->get_option('base_rate', 45));
            $per_km_rate = floatval($this->get_option('per_km_rate', 8));

            // Calculate base cost
            $cost = $base_rate + ($per_km_rate * $distance);

            // Apply modifiers
            switch ($type) {
                case 'standard':
                    $label = sprintf(__('Envío Estándar (%.1f km)', 'mandalo-shipping'), $distance);
                    break;

                case 'multi_optimized':
                    $label = __('Multi-destino Optimizado', 'mandalo-shipping');
                    // Base rate, actual cost calculated via AJAX
                    break;

                case 'multi_ordered':
                    $label = __('Multi-destino (Orden específico)', 'mandalo-shipping');
                    break;

                case 'express':
                    if (!$this->scheduling->is_express_available()) {
                        return null; // Express not available now
                    }
                    $multiplier = floatval($this->get_option('express_multiplier', 1.5));
                    $cost *= $multiplier;
                    $label = sprintf(__('Express - Hoy (%.1f km)', 'mandalo-shipping'), $distance);
                    break;

                case 'scheduled':
                    $discount = floatval($this->get_option('scheduled_discount', 10)) / 100;
                    $cost *= (1 - $discount);
                    $label = sprintf(__('Programado (%.1f km) - 10%% desc.', 'mandalo-shipping'), $distance);
                    break;

                case 'truck':
                    // Check if cart needs truck
                    $vehicle = $this->vehicle_handler->detect_vehicle_from_cart();
                    if ($vehicle && $vehicle['fits'] && $vehicle['vehicle']['type'] !== 'moto') {
                        $cost = $vehicle['base_rate'] + ($vehicle['per_km_rate'] * $distance);
                        $label = sprintf(__('Camioneta - %s (%.1f km)', 'mandalo-shipping'), $vehicle['vehicle']['name'], $distance);
                    } else {
                        $label = __('Camioneta (Cotizar dimensiones)', 'mandalo-shipping');
                    }
                    break;

                default:
                    return null;
            }

            // Apply free shipping
            if ($is_free && in_array($type, ['standard', 'scheduled'])) {
                $cost = 0;
                $label .= ' - ' . __('GRATIS', 'mandalo-shipping');
            }

            return [
                'id' => $this->get_rate_id() . '_' . $type,
                'label' => $label,
                'cost' => max(0, round($cost, 2)),
                'meta_data' => [
                    'mandalo_type' => $type,
                    'mandalo_distance' => $distance,
                ],
            ];
        }

        /**
         * Build destination string from package
         */
        private function build_destination_string($package) {
            $dest = $package['destination'] ?? [];

            $parts = array_filter([
                $dest['address_1'] ?? '',
                $dest['address_2'] ?? '',
                $dest['city'] ?? '',
                $dest['state'] ?? '',
                $dest['postcode'] ?? '',
                $dest['country'] ?? '',
            ]);

            return implode(', ', $parts);
        }

        /**
         * Admin options output
         */
        public function admin_options() {
            parent::admin_options();

            // Add custom admin interface for rule builder
            ?>
            <div class="mandalo-admin-section" style="margin-top: 20px;">
                <h3><?php _e('Reglas de precios avanzadas', 'mandalo-shipping'); ?></h3>
                <p><?php _e('Configura reglas personalizadas para modificar precios basados en condiciones específicas.', 'mandalo-shipping'); ?></p>

                <table class="widefat" id="mandalo-rules-table">
                    <thead>
                        <tr>
                            <th><?php _e('Condición', 'mandalo-shipping'); ?></th>
                            <th><?php _e('Operador', 'mandalo-shipping'); ?></th>
                            <th><?php _e('Valor', 'mandalo-shipping'); ?></th>
                            <th><?php _e('Acción', 'mandalo-shipping'); ?></th>
                            <th><?php _e('Monto', 'mandalo-shipping'); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="mandalo-rules-body">
                        <!-- Rules loaded via AJAX -->
                    </tbody>
                </table>

                <p>
                    <button type="button" class="button" id="mandalo-add-rule">
                        <?php _e('+ Agregar regla', 'mandalo-shipping'); ?>
                    </button>
                    <button type="button" class="button button-primary" id="mandalo-save-rules">
                        <?php _e('Guardar reglas', 'mandalo-shipping'); ?>
                    </button>
                </p>
            </div>

            <script>
                var mandaloInstanceId = <?php echo $this->instance_id; ?>;
            </script>
            <?php
        }
    }
}
