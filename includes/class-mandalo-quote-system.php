<?php
/**
 * Quote System - Sistema de cotizacion para servicio de delivery
 * Mandalo.mx vende SERVICIO de envio, no productos
 *
 * @package Mandalo_Shipping
 */

defined('ABSPATH') || exit;

class Mandalo_Quote_System {

    private static $instance = null;
    private $service_product_id = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'init']);
        add_shortcode('mandalo_cotizador', [$this, 'render_quote_form']);
        add_shortcode('mandalo_quote', [$this, 'render_quote_form']);

        add_action('wp_ajax_mandalo_quote_calculate', [$this, 'ajax_calculate_quote']);
        add_action('wp_ajax_nopriv_mandalo_quote_calculate', [$this, 'ajax_calculate_quote']);
        add_action('wp_ajax_mandalo_add_service_to_cart', [$this, 'ajax_add_to_cart']);
        add_action('wp_ajax_nopriv_mandalo_add_service_to_cart', [$this, 'ajax_add_to_cart']);

        add_action('woocommerce_before_calculate_totals', [$this, 'set_custom_cart_item_price'], 20, 1);
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_data'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'save_order_item_meta'], 10, 4);
    }

    public function init() {
        $this->service_product_id = $this->get_or_create_service_product();
    }

    public function get_or_create_service_product() {
        $product_id = get_option('mandalo_service_product_id');
        if ($product_id) {
            $product = wc_get_product($product_id);
            if ($product && $product->get_status() === 'publish') {
                return $product_id;
            }
        }
        return $this->create_service_product();
    }

    public function create_service_product() {
        $existing = wc_get_product_id_by_sku('mandalo-delivery-service');
        if ($existing) {
            update_option('mandalo_service_product_id', $existing);
            return $existing;
        }

        $product = new WC_Product_Simple();
        $product->set_name('Servicio de Envio Mandalo');
        $product->set_slug('servicio-envio-mandalo');
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden');
        $product->set_sku('mandalo-delivery-service');
        $product->set_price(0);
        $product->set_regular_price(0);
        $product->set_virtual(true);
        $product->set_sold_individually(true);
        $product->set_manage_stock(false);
        $product->set_stock_status('instock');
        $product->set_tax_status('none');
        $product_id = $product->save();

        if ($product_id) {
            update_option('mandalo_service_product_id', $product_id);
        }
        return $product_id;
    }

    public function render_quote_form($atts = []) {
        $atts = shortcode_atts([
            'title' => __('Cotiza tu envio', 'mandalo-shipping'),
            'show_types' => 'standard,express,scheduled,truck',
            'default_type' => 'standard',
        ], $atts);

        $enabled_types = array_map('trim', explode(',', $atts['show_types']));

        $types = [
            'standard' => ['name' => 'Mismo dia', 'desc' => 'Entrega hoy', 'icon' => 'truck', 'badge' => ''],
            'express' => ['name' => 'Express', 'desc' => '2-3 horas', 'icon' => 'bolt', 'badge' => ''],
            'scheduled' => ['name' => 'Programado', 'desc' => 'Tu eliges', 'icon' => 'calendar', 'badge' => '-10%'],
            'truck' => ['name' => 'Camioneta', 'desc' => 'Carga grande', 'icon' => 'truck-loading', 'badge' => ''],
        ];

        // Determine maps provider: Google Maps if option key exists, else Leaflet fallback.
        $gmaps_api_key = get_option('mandalo_google_maps_api_key', '');
        $use_google_maps = !empty($gmaps_api_key);

        ob_start();

        // Output CSS link (filemtime version = automatic CDN cache-bust on deploy)
        $css_ver = @filemtime(MANDALO_SHIPPING_PATH . 'assets/css/quote-form.css') ?: MANDALO_SHIPPING_VERSION;
        $css_url = MANDALO_SHIPPING_URL . 'assets/css/quote-form.css?ver=' . $css_ver;
        echo '<link data-no-optimize="1" rel="stylesheet" href="' . esc_url($css_url) . '">';
        if (!$use_google_maps) {
            // Leaflet CSS fallback (only when no Google Maps key configured)
            echo '<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="">';
        }
        ?>

        <div id="mandalo-quote-container" class="mandalo-quote-widget">
            <div class="mandalo-quote-header">
                <h2><?php echo esc_html($atts['title']); ?></h2>
                <p class="mandalo-quote-subtitle">Servicio de mensajeria express en Ciudad de Mexico</p>
            </div>

            <form id="mandalo-quote-form" class="mandalo-quote-form">
                <?php wp_nonce_field('mandalo_quote_nonce', 'mandalo_quote_nonce'); ?>

                <div class="mandalo-form-section">
                    <label class="mandalo-section-label">Tipo de servicio</label>
                    <div class="mandalo-service-types">
                        <?php
                        $first = true;
                        foreach ($types as $type_id => $type):
                            if (!in_array($type_id, $enabled_types)) continue;
                            $selected_class = $first ? ' selected' : '';
                            $checked = $first ? 'checked' : '';
                            $first = false;
                        ?>
                        <label class="mandalo-service-type-option<?php echo $selected_class; ?>">
                            <input type="radio" name="service_type" value="<?php echo esc_attr($type_id); ?>" <?php echo $checked; ?>>
                            <div class="mandalo-type-content">
                                <span class="mandalo-type-icon" data-icon="<?php echo esc_attr($type['icon']); ?>"></span>
                                <span class="mandalo-type-name"><?php echo esc_html($type['name']); ?></span>
                                <span class="mandalo-type-desc"><?php echo esc_html($type['desc']); ?></span>
                                <?php if (!empty($type['badge'])): ?>
                                <span class="mandalo-type-badge"><?php echo esc_html($type['badge']); ?></span>
                                <?php endif; ?>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="mandalo-form-section">
                    <label class="mandalo-section-label" for="origin_address">
                        <span class="mandalo-label-icon origin"></span>
                        Recoger en
                    </label>
                    <div class="mandalo-address-row">
                        <div class="mandalo-address-input">
                            <span class="mandalo-input-icon">📍</span>
                            <input type="text" id="origin_address" name="origin_address" class="mandalo-input mandalo-input-with-icon"
                                   placeholder="Calle, colonia, CP" autocomplete="off" required>
                            <input type="text" id="origin_number" name="origin_number" class="mandalo-input mandalo-number-input"
                                   placeholder="No. Ext" maxlength="10">
                            <button type="button" class="mandalo-map-btn" data-field="origin" title="Ver en mapa">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                    <circle cx="12" cy="10" r="3"></circle>
                                </svg>
                            </button>
                            <div class="mandalo-address-suggestions" id="origin_suggestions"></div>
                        </div>
                        <button type="button" class="mandalo-address-book-btn" data-type="origin" title="Mis direcciones">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="mandalo-form-section">
                    <label class="mandalo-section-label">
                        <span class="mandalo-label-icon destination"></span>
                        Entregar en
                    </label>
                    <div id="mandalo-destinations-container">
                        <div class="mandalo-destination-row" data-index="0">
                            <div class="mandalo-address-input">
                                <span class="mandalo-input-icon">📍</span>
                                <input type="text" name="destinations[]" class="mandalo-input mandalo-input-with-icon mandalo-destination-input"
                                       placeholder="Calle, colonia, CP" autocomplete="off" required>
                                <input type="text" name="destination_numbers[]" class="mandalo-input mandalo-number-input mandalo-destination-number"
                                       placeholder="No. Ext" maxlength="10">
                                <button type="button" class="mandalo-map-btn" data-field="destination" data-index="0" title="Ver en mapa">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                        <circle cx="12" cy="10" r="3"></circle>
                                    </svg>
                                </button>
                                <div class="mandalo-address-suggestions"></div>
                            </div>
                            <button type="button" class="mandalo-remove-destination" style="display: none;">
                                <span>&times;</span>
                            </button>
                        </div>
                    </div>
                    <button type="button" id="mandalo-add-destination" class="mandalo-add-btn">
                        <span>+</span> Agregar destino
                    </button>
                </div>

                <div class="mandalo-form-section mandalo-scheduled-options" style="display: none;">
                    <label class="mandalo-section-label">Fecha y hora de entrega</label>
                    <div class="mandalo-datetime-row">
                        <input type="date" id="scheduled_date" name="scheduled_date" class="mandalo-input"
                               min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                               max="<?php echo date('Y-m-d', strtotime('+14 days')); ?>">
                        <select id="scheduled_time" name="scheduled_time" class="mandalo-input">
                            <option value="">Selecciona horario</option>
                            <option value="09:00-12:00">09:00 - 12:00</option>
                            <option value="12:00-15:00">12:00 - 15:00</option>
                            <option value="15:00-18:00">15:00 - 18:00</option>
                            <option value="18:00-21:00">18:00 - 21:00</option>
                        </select>
                    </div>
                </div>

                <div class="mandalo-form-section mandalo-package-options" style="display: none;">
                    <label class="mandalo-section-label">Dimensiones del paquete</label>
                    <div class="mandalo-dimensions-row">
                        <div class="mandalo-dimension">
                            <input type="number" id="package_weight" name="package_weight" class="mandalo-input" min="1" max="1000" step="0.5" placeholder="25">
                            <span class="mandalo-unit">kg</span>
                        </div>
                        <div class="mandalo-dimension">
                            <input type="number" id="package_length" name="package_length" class="mandalo-input" min="1" max="500" placeholder="100">
                            <span class="mandalo-unit">cm L</span>
                        </div>
                        <div class="mandalo-dimension">
                            <input type="number" id="package_width" name="package_width" class="mandalo-input" min="1" max="300" placeholder="50">
                            <span class="mandalo-unit">cm A</span>
                        </div>
                        <div class="mandalo-dimension">
                            <input type="number" id="package_height" name="package_height" class="mandalo-input" min="1" max="250" placeholder="40">
                            <span class="mandalo-unit">cm H</span>
                        </div>
                    </div>
                </div>

                <div class="mandalo-form-section">
                    <label class="mandalo-section-label">Quien envia</label>
                    <div class="mandalo-contact-row">
                        <input type="text" id="sender_name" name="sender_name" class="mandalo-input" placeholder="Nombre de quien envia" required>
                        <input type="tel" id="sender_phone" name="sender_phone" class="mandalo-input" placeholder="Telefono" required>
                    </div>
                </div>

                <div class="mandalo-form-section">
                    <label class="mandalo-section-label">Quien recibe</label>
                    <div id="mandalo-recipients-container">
                        <div class="mandalo-recipient-row" data-index="0">
                            <div class="mandalo-contact-row">
                                <input type="text" name="recipient_names[]" class="mandalo-input mandalo-recipient-name" placeholder="Nombre del destinatario" required>
                                <input type="tel" name="recipient_phones[]" class="mandalo-input mandalo-recipient-phone" placeholder="Telefono">
                            </div>
                            <button type="button" class="mandalo-remove-recipient" style="display: none;">
                                <span>&times;</span>
                            </button>
                        </div>
                    </div>
                    <button type="button" id="mandalo-add-recipient" class="mandalo-add-btn">
                        <span>+</span> Agregar destinatario
                    </button>
                    <p class="mandalo-help-text">Si tienes multiples destinos, agrega un contacto por cada parada.</p>
                </div>

                <div class="mandalo-form-section mandalo-map-section" style="display: none;">
                    <label class="mandalo-section-label">Confirma las ubicaciones</label>
                    <div id="mandalo-map-container" style="height: 250px;"></div>
                    <p class="mandalo-help-text">Verifica que los puntos en el mapa sean correctos.</p>
                </div>

                <div class="mandalo-form-section">
                    <button type="submit" id="mandalo-calculate-btn" class="mandalo-btn mandalo-btn-primary">
                        Calcular precio
                    </button>
                </div>
            </form>

            <div id="mandalo-quote-result" class="mandalo-quote-result" style="display: none;">
                <div class="mandalo-result-header">
                    <h3>Tu cotizacion</h3>
                </div>
                <div class="mandalo-result-details">
                    <div class="mandalo-result-row">
                        <span class="mandalo-result-label">Distancia</span>
                        <span class="mandalo-result-value" id="result-distance">-</span>
                    </div>
                    <div class="mandalo-result-row">
                        <span class="mandalo-result-label">Tiempo estimado</span>
                        <span class="mandalo-result-value" id="result-time">-</span>
                    </div>
                    <div class="mandalo-result-row">
                        <span class="mandalo-result-label">Tipo de servicio</span>
                        <span class="mandalo-result-value" id="result-type">-</span>
                    </div>
                    <div id="mandalo-result-breakdown"></div>
                </div>
                <div class="mandalo-result-total">
                    <span class="mandalo-total-label">Total</span>
                    <span class="mandalo-total-price" id="result-total">$0.00 MXN</span>
                </div>
                <div class="mandalo-result-actions">
                    <button type="button" id="mandalo-hire-btn" class="mandalo-btn mandalo-btn-success">
                        Contratar Servicio
                    </button>
                    <button type="button" id="mandalo-recalculate-btn" class="mandalo-btn mandalo-btn-secondary">
                        Modificar
                    </button>
                </div>
            </div>

            <div id="mandalo-loading" class="mandalo-loading" style="display: none;">
                <div class="mandalo-spinner"></div>
                <span>Calculando ruta...</span>
            </div>
        </div>

        <!-- Address Book Modal -->
        <div id="mandalo-address-modal" class="mandalo-modal" style="display: none;">
            <div class="mandalo-modal-content">
                <div class="mandalo-modal-header">
                    <h3>Mis Direcciones</h3>
                    <button type="button" class="mandalo-modal-close">&times;</button>
                </div>
                <div class="mandalo-modal-body">
                    <div id="mandalo-address-list" class="mandalo-address-list">
                        <p class="mandalo-loading-text">Cargando direcciones...</p>
                    </div>
                    <button type="button" id="mandalo-add-new-address" class="mandalo-btn mandalo-btn-secondary">
                        + Agregar nueva direccion
                    </button>
                </div>

                <!-- Add/Edit Form (hidden by default) -->
                <div id="mandalo-address-form" class="mandalo-address-form" style="display: none;">
                    <h4 id="mandalo-form-title">Nueva Direccion</h4>
                    <input type="hidden" id="edit_address_id" value="">
                    <div class="mandalo-form-row">
                        <label>Titulo (ej: Casa, Oficina, Bodega)</label>
                        <input type="text" id="address_title" class="mandalo-input" placeholder="Mi Casa" required>
                    </div>
                    <div class="mandalo-form-row">
                        <label>Direccion completa</label>
                        <input type="text" id="address_full" class="mandalo-input" placeholder="Calle, numero, colonia, CP" required>
                        <div class="mandalo-address-suggestions" id="address_form_suggestions"></div>
                    </div>
                    <div class="mandalo-form-row mandalo-form-row-2col">
                        <div>
                            <label>Nombre de contacto</label>
                            <input type="text" id="address_contact_name" class="mandalo-input" placeholder="Juan Perez">
                        </div>
                        <div>
                            <label>Telefono</label>
                            <input type="tel" id="address_contact_phone" class="mandalo-input" placeholder="55 1234 5678">
                        </div>
                    </div>
                    <div class="mandalo-form-row">
                        <label>Tipo de direccion</label>
                        <select id="address_type" class="mandalo-input">
                            <option value="both">Origen y Destino</option>
                            <option value="origin">Solo para recoger (origen)</option>
                            <option value="destination">Solo para entregar (destino)</option>
                        </select>
                    </div>
                    <div class="mandalo-form-actions">
                        <button type="button" id="mandalo-save-address" class="mandalo-btn mandalo-btn-primary">Guardar</button>
                        <button type="button" id="mandalo-cancel-address" class="mandalo-btn mandalo-btn-secondary">Cancelar</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fullscreen Map Modal -->
        <div id="mandalo-fullscreen-map-modal" class="mandalo-fullscreen-modal" style="display: none;">
            <div class="mandalo-fullscreen-header">
                <button type="button" class="mandalo-fullscreen-back" id="mandalo-map-back">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M19 12H5M12 19l-7-7 7-7"/>
                    </svg>
                </button>
                <div class="mandalo-fullscreen-title">
                    <span id="mandalo-map-title">Confirmar ubicacion</span>
                    <small id="mandalo-map-subtitle">Arrastra el pin para ajustar la ubicacion exacta</small>
                </div>
            </div>
            <div id="mandalo-fullscreen-map" class="mandalo-fullscreen-map-container"></div>
            <div class="mandalo-fullscreen-footer">
                <div class="mandalo-selected-address" id="mandalo-selected-address">
                    <span class="mandalo-address-pin">📍</span>
                    <span class="mandalo-address-text">Selecciona una ubicacion en el mapa</span>
                </div>
                <button type="button" class="mandalo-btn mandalo-btn-primary mandalo-btn-block" id="mandalo-confirm-location">
                    Confirmar ubicacion
                </button>
            </div>
        </div>

        <?php
        // Output JS config and script
        $js_config = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mandalo_quote_nonce'),
            'checkout_url' => wc_get_checkout_url(),
            'cart_url' => wc_get_cart_url(),
            'currency_symbol' => get_woocommerce_currency_symbol(),
            'maps_provider' => $use_google_maps ? 'google' : 'leaflet',
            // Map ID para AdvancedMarkerElement (crear uno propio en GCP y guardarlo
            // en la opcion mandalo_google_maps_map_id; DEMO_MAP_ID es el de ejemplo de Google)
            'maps_map_id' => get_option('mandalo_google_maps_map_id', 'DEMO_MAP_ID'),
            'i18n' => [
                'calculating' => 'Calculando...',
                'add_to_cart' => 'Contratar Servicio',
                'error' => 'Error al calcular',
                'origin_required' => 'Ingresa la direccion de recoleccion',
                'destination_required' => 'Ingresa al menos un destino',
                'processing' => 'Procesando...',
                'success' => 'Servicio agregado',
            ],
        ];
        $js_ver = @filemtime(MANDALO_SHIPPING_PATH . 'assets/js/quote-form.js') ?: MANDALO_SHIPPING_VERSION;
        $js_url = MANDALO_SHIPPING_URL . 'assets/js/quote-form.js?ver=' . $js_ver;
        ?>
        <script data-no-optimize="1">var MandaloQuote = <?php echo json_encode($js_config); ?>;</script>
        <?php if ($use_google_maps): ?>
        <script data-no-optimize="1">
        /* Google Maps async loader — fires MandaloGMapsReady when API is available */
        window.MandaloGMapsReady = function() {
            window._mandaloGMapsLoaded = true;
            if (typeof MandaloQuoteForm !== 'undefined' && MandaloQuoteForm._pendingMapInit) {
                MandaloQuoteForm._pendingMapInit();
                MandaloQuoteForm._pendingMapInit = null;
            }
        };
        </script>
        <script data-no-optimize="1" async
            src="https://maps.googleapis.com/maps/api/js?key=<?php echo esc_attr($gmaps_api_key); ?>&libraries=marker&loading=async&callback=MandaloGMapsReady">
        </script>
        <?php else: ?>
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
        <?php endif; ?>
        <script data-no-optimize="1" src="<?php echo esc_url($js_url); ?>"></script>
        <?php

        return ob_get_clean();
    }

    public function ajax_calculate_quote() {
        check_ajax_referer('mandalo_quote_nonce', 'nonce');

        $origin = sanitize_text_field($_POST['origin_address'] ?? '');
        $origin_number = sanitize_text_field($_POST['origin_number'] ?? '');
        if (!empty($origin_number)) {
            $origin .= ' #' . $origin_number;
        }

        $destinations = isset($_POST['destinations']) ? array_map('sanitize_text_field', $_POST['destinations']) : [];
        $destination_numbers = isset($_POST['destination_numbers']) ? array_map('sanitize_text_field', $_POST['destination_numbers']) : [];

        // Concatenate destination numbers
        foreach ($destinations as $i => $dest) {
            if (!empty($destination_numbers[$i])) {
                $destinations[$i] = $dest . ' #' . $destination_numbers[$i];
            }
        }

        $service_type = sanitize_text_field($_POST['service_type'] ?? 'standard');

        if (empty($origin)) {
            wp_send_json_error(['message' => 'Direccion de origen requerida']);
        }

        $destinations = array_filter($destinations);
        if (empty($destinations)) {
            wp_send_json_error(['message' => 'Al menos un destino requerido']);
        }

        $calculator = new Mandalo_Distance_Calculator();
        $pricing = new Mandalo_Pricing_Engine();

        // Get recipients
        $recipient_names = isset($_POST['recipient_names']) ? array_map('sanitize_text_field', $_POST['recipient_names']) : [];
        $recipient_phones = isset($_POST['recipient_phones']) ? array_map('sanitize_text_field', $_POST['recipient_phones']) : [];

        // Check if coordinates were sent from frontend (from autocomplete)
        $origin_coords_sent = isset($_POST['origin_coords']) && !empty($_POST['origin_coords']) ? $_POST['origin_coords'] : null;
        $dest_coords_sent = isset($_POST['dest_coords']) ? $_POST['dest_coords'] : [];

        // Geocode origin (use sent coords or geocode)
        if ($origin_coords_sent && isset($origin_coords_sent['lat'])) {
            $origin_coords = [
                'lat' => floatval($origin_coords_sent['lat']),
                'lon' => floatval($origin_coords_sent['lon'])
            ];
        } else {
            $origin_coords = $calculator->geocode($origin);
            if (!$origin_coords) {
                $origin_coords = ['lat' => 19.432608, 'lon' => -99.133209];
            }
        }

        // Calculate distance and collect destination coords
        $total_distance = 0;
        $prev_coords = $origin_coords;
        $all_dest_coords = [];

        foreach ($destinations as $i => $dest) {
            // Use sent coords or geocode
            if (isset($dest_coords_sent[$i]) && !empty($dest_coords_sent[$i]) && isset($dest_coords_sent[$i]['lat'])) {
                $dest_coords = [
                    'lat' => floatval($dest_coords_sent[$i]['lat']),
                    'lon' => floatval($dest_coords_sent[$i]['lon'])
                ];
            } else {
                $dest_coords = $calculator->geocode($dest);
                if (!$dest_coords) {
                    $dest_coords = ['lat' => 19.432608, 'lon' => -99.133209];
                }
            }
            $all_dest_coords[] = $dest_coords;
            $total_distance += $this->calculate_point_distance($prev_coords, $dest_coords);
            $prev_coords = $dest_coords;
        }

        $stop_count = count($destinations);
        $price = $pricing->calculate_price($total_distance, $service_type, $stop_count);
        $breakdown = $pricing->get_price_breakdown($total_distance, $service_type, $stop_count);
        $minutes = round(($total_distance / 25) * 60);

        $type_labels = [
            'standard' => 'Mismo dia',
            'express' => 'Express (2-3h)',
            'scheduled' => 'Programado',
            'truck' => 'Camioneta',
        ];

        wp_send_json_success([
            'distance' => round($total_distance, 2),
            'distance_formatted' => number_format($total_distance, 1) . ' km',
            'duration_minutes' => $minutes,
            'duration_formatted' => $minutes . ' min',
            'service_type' => $service_type,
            'service_type_label' => $type_labels[$service_type] ?? $service_type,
            'stop_count' => $stop_count,
            'price' => round($price, 2),
            'price_formatted' => '$' . number_format($price, 2) . ' MXN',
            'breakdown' => $breakdown,
            'origin_coords' => $origin_coords,
            'dest_coords' => $all_dest_coords,
            'cart_data' => [
                'origin' => $origin,
                'destinations' => $destinations,
                'service_type' => $service_type,
                'distance' => round($total_distance, 2),
                'price' => round($price, 2),
                'scheduled_date' => sanitize_text_field($_POST['scheduled_date'] ?? ''),
                'scheduled_time' => sanitize_text_field($_POST['scheduled_time'] ?? ''),
                'sender_name' => sanitize_text_field($_POST['sender_name'] ?? ''),
                'sender_phone' => sanitize_text_field($_POST['sender_phone'] ?? ''),
                'recipient_names' => $recipient_names,
                'recipient_phones' => $recipient_phones,
            ],
        ]);
    }

    private function calculate_point_distance($from, $to) {
        $osrm_endpoint = get_option('mandalo_osrm_endpoint', 'http://31.97.98.61:5001');
        $url = sprintf('%s/route/v1/driving/%s,%s;%s,%s?overview=false',
            $osrm_endpoint, $from['lon'], $from['lat'], $to['lon'], $to['lat']);

        $response = wp_remote_get($url, ['timeout' => 20]);
        if (!is_wp_error($response)) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (!empty($data['routes'][0]['distance'])) {
                return $data['routes'][0]['distance'] / 1000;
            }
        }

        // Fallback haversine
        $R = 6371;
        $lat1 = deg2rad($from['lat']);
        $lat2 = deg2rad($to['lat']);
        $dLat = $lat2 - $lat1;
        $dLon = deg2rad($to['lon'] - $from['lon']);
        $a = sin($dLat/2) * sin($dLat/2) + cos($lat1) * cos($lat2) * sin($dLon/2) * sin($dLon/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return $R * $c * 1.18;
    }

    public function ajax_add_to_cart() {
        check_ajax_referer('mandalo_quote_nonce', 'nonce');

        $cart_data = isset($_POST['cart_data']) ? $_POST['cart_data'] : [];
        if (empty($cart_data) || empty($cart_data['price'])) {
            wp_send_json_error(['message' => 'Datos de cotizacion invalidos']);
        }

        $cart_data = [
            'origin' => sanitize_text_field($cart_data['origin'] ?? ''),
            'destinations' => isset($cart_data['destinations']) ? array_map('sanitize_text_field', $cart_data['destinations']) : [],
            'service_type' => sanitize_text_field($cart_data['service_type'] ?? 'standard'),
            'distance' => floatval($cart_data['distance'] ?? 0),
            'price' => floatval($cart_data['price'] ?? 0),
            'scheduled_date' => sanitize_text_field($cart_data['scheduled_date'] ?? ''),
            'scheduled_time' => sanitize_text_field($cart_data['scheduled_time'] ?? ''),
            'sender_name' => sanitize_text_field($cart_data['sender_name'] ?? ''),
            'sender_phone' => sanitize_text_field($cart_data['sender_phone'] ?? ''),
            'recipient_names' => isset($cart_data['recipient_names']) ? array_map('sanitize_text_field', $cart_data['recipient_names']) : [],
            'recipient_phones' => isset($cart_data['recipient_phones']) ? array_map('sanitize_text_field', $cart_data['recipient_phones']) : [],
        ];

        $product_id = $this->get_or_create_service_product();
        if (!$product_id) {
            wp_send_json_error(['message' => 'Error al crear el servicio']);
        }

        WC()->cart->empty_cart();
        $cart_item_key = WC()->cart->add_to_cart($product_id, 1, 0, [], ['mandalo_service_data' => $cart_data]);

        if (!$cart_item_key) {
            wp_send_json_error(['message' => 'Error al agregar al carrito']);
        }

        wp_send_json_success([
            'message' => 'Servicio agregado al carrito',
            'cart_url' => wc_get_cart_url(),
            'checkout_url' => wc_get_checkout_url(),
            'redirect' => wc_get_checkout_url(),
        ]);
    }

    public function set_custom_cart_item_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        foreach ($cart->get_cart() as $cart_item) {
            if (isset($cart_item['mandalo_service_data']['price'])) {
                $cart_item['data']->set_price(floatval($cart_item['mandalo_service_data']['price']));
            }
        }
    }

    public function display_cart_item_data($item_data, $cart_item) {
        if (!isset($cart_item['mandalo_service_data'])) return $item_data;
        $data = $cart_item['mandalo_service_data'];
        $type_labels = ['standard' => 'Mismo dia', 'express' => 'Express (2-3h)', 'scheduled' => 'Programado', 'truck' => 'Camioneta'];

        $item_data[] = ['key' => 'Tipo', 'value' => $type_labels[$data['service_type']] ?? $data['service_type']];
        $item_data[] = ['key' => 'Origen', 'value' => $data['origin']];
        $destinations = is_array($data['destinations']) ? $data['destinations'] : [$data['destinations']];
        foreach ($destinations as $i => $dest) {
            $item_data[] = ['key' => count($destinations) > 1 ? sprintf('Destino %d', $i + 1) : 'Destino', 'value' => $dest];
        }
        $item_data[] = ['key' => 'Distancia', 'value' => number_format($data['distance'], 1) . ' km'];
        if (!empty($data['scheduled_date'])) {
            $item_data[] = ['key' => 'Fecha programada', 'value' => $data['scheduled_date'] . ' ' . $data['scheduled_time']];
        }
        return $item_data;
    }

    public function save_order_item_meta($item, $cart_item_key, $values, $order) {
        if (!isset($values['mandalo_service_data'])) return;
        $data = $values['mandalo_service_data'];
        $item->add_meta_data('_mandalo_origin', $data['origin']);
        $item->add_meta_data('_mandalo_destinations', $data['destinations']);
        $item->add_meta_data('_mandalo_service_type', $data['service_type']);
        $item->add_meta_data('_mandalo_distance', $data['distance']);
        $item->add_meta_data('_mandalo_sender_name', $data['sender_name']);
        $item->add_meta_data('_mandalo_sender_phone', $data['sender_phone']);
        if (!empty($data['recipient_names'])) {
            $item->add_meta_data('_mandalo_recipient_names', $data['recipient_names']);
            $item->add_meta_data('_mandalo_recipient_phones', $data['recipient_phones']);
        }
        if (!empty($data['scheduled_date'])) {
            $item->add_meta_data('_mandalo_scheduled_date', $data['scheduled_date']);
            $item->add_meta_data('_mandalo_scheduled_time', $data['scheduled_time']);
        }
    }
}

add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        Mandalo_Quote_System::instance();
    }
}, 25);
