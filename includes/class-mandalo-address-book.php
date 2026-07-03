<?php
/**
 * Address Book - Agenda de direcciones para clientes
 * Permite guardar direcciones frecuentes con contacto
 *
 * @package Mandalo_Shipping
 */

defined('ABSPATH') || exit;

class Mandalo_Address_Book {

    private static $instance = null;
    private $table_name;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'mandalo_addresses';

        add_action('init', [$this, 'init']);
        add_action('wp_ajax_mandalo_get_addresses', [$this, 'ajax_get_addresses']);
        add_action('wp_ajax_nopriv_mandalo_get_addresses', [$this, 'ajax_get_addresses']);
        add_action('wp_ajax_mandalo_save_address', [$this, 'ajax_save_address']);
        add_action('wp_ajax_nopriv_mandalo_save_address', [$this, 'ajax_save_address']);
        add_action('wp_ajax_mandalo_delete_address', [$this, 'ajax_delete_address']);
        add_action('wp_ajax_nopriv_mandalo_delete_address', [$this, 'ajax_delete_address']);
        add_action('wp_ajax_mandalo_set_default_address', [$this, 'ajax_set_default']);
        add_action('wp_ajax_nopriv_mandalo_set_default_address', [$this, 'ajax_set_default']);
    }

    public function init() {
        $this->maybe_create_table();
    }

    /**
     * Create database table if it doesn't exist
     */
    public function maybe_create_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned DEFAULT NULL,
            session_id varchar(100) DEFAULT NULL,
            title varchar(100) NOT NULL,
            address text NOT NULL,
            contact_name varchar(100) DEFAULT NULL,
            contact_phone varchar(50) DEFAULT NULL,
            lat decimal(10,8) DEFAULT NULL,
            lng decimal(11,8) DEFAULT NULL,
            address_type enum('origin','destination','both') DEFAULT 'both',
            is_default_origin tinyint(1) DEFAULT 0,
            is_default_destination tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY session_id (session_id),
            KEY address_type (address_type)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Get client identifier (user_id or session_id)
     */
    private function get_client_identifier() {
        if (is_user_logged_in()) {
            return ['user_id' => get_current_user_id(), 'session_id' => null];
        }

        // For guests, use WC session or create a unique identifier
        if (WC()->session) {
            $session_id = WC()->session->get_customer_id();
        } else {
            if (!isset($_COOKIE['mandalo_client_id'])) {
                $session_id = 'guest_' . wp_generate_uuid4();
                setcookie('mandalo_client_id', $session_id, time() + (86400 * 30), '/');
            } else {
                $session_id = sanitize_text_field($_COOKIE['mandalo_client_id']);
            }
        }

        return ['user_id' => null, 'session_id' => $session_id];
    }

    /**
     * Get all addresses for current client
     */
    public function get_addresses($type = 'all') {
        global $wpdb;
        $client = $this->get_client_identifier();

        $where = '';
        if ($client['user_id']) {
            $where = $wpdb->prepare("WHERE user_id = %d", $client['user_id']);
        } elseif ($client['session_id']) {
            $where = $wpdb->prepare("WHERE session_id = %s", $client['session_id']);
        } else {
            return [];
        }

        if ($type !== 'all') {
            $where .= $wpdb->prepare(" AND (address_type = %s OR address_type = 'both')", $type);
        }

        $addresses = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} {$where} ORDER BY is_default_origin DESC, is_default_destination DESC, title ASC"
        );

        return $addresses ?: [];
    }

    /**
     * Get single address by ID
     */
    public function get_address($id) {
        global $wpdb;
        $client = $this->get_client_identifier();

        $where = $wpdb->prepare("WHERE id = %d", $id);
        if ($client['user_id']) {
            $where .= $wpdb->prepare(" AND user_id = %d", $client['user_id']);
        } elseif ($client['session_id']) {
            $where .= $wpdb->prepare(" AND session_id = %s", $client['session_id']);
        }

        return $wpdb->get_row("SELECT * FROM {$this->table_name} {$where}");
    }

    /**
     * Save address (insert or update)
     */
    public function save_address($data) {
        global $wpdb;
        $client = $this->get_client_identifier();

        $address_data = [
            'title' => sanitize_text_field($data['title'] ?? ''),
            'address' => sanitize_textarea_field($data['address'] ?? ''),
            'contact_name' => sanitize_text_field($data['contact_name'] ?? ''),
            'contact_phone' => sanitize_text_field($data['contact_phone'] ?? ''),
            'lat' => isset($data['lat']) ? floatval($data['lat']) : null,
            'lng' => isset($data['lng']) ? floatval($data['lng']) : null,
            'address_type' => in_array($data['address_type'] ?? '', ['origin', 'destination', 'both']) ? $data['address_type'] : 'both',
        ];

        if (empty($address_data['title']) || empty($address_data['address'])) {
            return new WP_Error('missing_data', 'Titulo y direccion son requeridos');
        }

        // Check if updating or inserting
        if (!empty($data['id'])) {
            $existing = $this->get_address($data['id']);
            if (!$existing) {
                return new WP_Error('not_found', 'Direccion no encontrada');
            }

            $wpdb->update(
                $this->table_name,
                $address_data,
                ['id' => $data['id']],
                ['%s', '%s', '%s', '%s', '%f', '%f', '%s'],
                ['%d']
            );

            return $data['id'];
        } else {
            // Insert new
            $address_data['user_id'] = $client['user_id'];
            $address_data['session_id'] = $client['session_id'];

            $wpdb->insert(
                $this->table_name,
                $address_data,
                ['%s', '%s', '%s', '%s', '%f', '%f', '%s', '%d', '%s']
            );

            return $wpdb->insert_id;
        }
    }

    /**
     * Delete address
     */
    public function delete_address($id) {
        global $wpdb;
        $client = $this->get_client_identifier();

        $where = ['id' => $id];
        if ($client['user_id']) {
            $where['user_id'] = $client['user_id'];
        } elseif ($client['session_id']) {
            $where['session_id'] = $client['session_id'];
        }

        return $wpdb->delete($this->table_name, $where);
    }

    /**
     * Set address as default
     */
    public function set_default($id, $type) {
        global $wpdb;
        $client = $this->get_client_identifier();

        // First, unset all defaults of this type for this client
        $where_clause = '';
        if ($client['user_id']) {
            $where_clause = $wpdb->prepare("user_id = %d", $client['user_id']);
        } elseif ($client['session_id']) {
            $where_clause = $wpdb->prepare("session_id = %s", $client['session_id']);
        }

        $field = $type === 'origin' ? 'is_default_origin' : 'is_default_destination';

        $wpdb->query("UPDATE {$this->table_name} SET {$field} = 0 WHERE {$where_clause}");

        // Set new default
        $wpdb->update(
            $this->table_name,
            [$field => 1],
            ['id' => $id],
            ['%d'],
            ['%d']
        );

        return true;
    }

    /**
     * Get default address
     */
    public function get_default($type) {
        global $wpdb;
        $client = $this->get_client_identifier();

        $field = $type === 'origin' ? 'is_default_origin' : 'is_default_destination';

        $where = '';
        if ($client['user_id']) {
            $where = $wpdb->prepare("WHERE user_id = %d AND {$field} = 1", $client['user_id']);
        } elseif ($client['session_id']) {
            $where = $wpdb->prepare("WHERE session_id = %s AND {$field} = 1", $client['session_id']);
        }

        return $wpdb->get_row("SELECT * FROM {$this->table_name} {$where} LIMIT 1");
    }

    // AJAX Handlers

    public function ajax_get_addresses() {
        check_ajax_referer('mandalo_quote_nonce', 'nonce');

        $type = sanitize_text_field($_POST['type'] ?? 'all');
        $addresses = $this->get_addresses($type);

        wp_send_json_success([
            'addresses' => $addresses,
            'default_origin' => $this->get_default('origin'),
            'default_destination' => $this->get_default('destination'),
        ]);
    }

    public function ajax_save_address() {
        check_ajax_referer('mandalo_quote_nonce', 'nonce');

        $data = [
            'id' => intval($_POST['id'] ?? 0),
            'title' => $_POST['title'] ?? '',
            'address' => $_POST['address'] ?? '',
            'contact_name' => $_POST['contact_name'] ?? '',
            'contact_phone' => $_POST['contact_phone'] ?? '',
            'lat' => $_POST['lat'] ?? null,
            'lng' => $_POST['lng'] ?? null,
            'address_type' => $_POST['address_type'] ?? 'both',
        ];

        $result = $this->save_address($data);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success([
            'id' => $result,
            'message' => 'Direccion guardada correctamente',
            'addresses' => $this->get_addresses(),
        ]);
    }

    public function ajax_delete_address() {
        check_ajax_referer('mandalo_quote_nonce', 'nonce');

        $id = intval($_POST['id'] ?? 0);
        if (!$id) {
            wp_send_json_error(['message' => 'ID invalido']);
        }

        $this->delete_address($id);

        wp_send_json_success([
            'message' => 'Direccion eliminada',
            'addresses' => $this->get_addresses(),
        ]);
    }

    public function ajax_set_default() {
        check_ajax_referer('mandalo_quote_nonce', 'nonce');

        $id = intval($_POST['id'] ?? 0);
        $type = sanitize_text_field($_POST['default_type'] ?? '');

        if (!$id || !in_array($type, ['origin', 'destination'])) {
            wp_send_json_error(['message' => 'Datos invalidos']);
        }

        $this->set_default($id, $type);

        wp_send_json_success([
            'message' => 'Direccion predeterminada actualizada',
            'addresses' => $this->get_addresses(),
        ]);
    }
}

// Initialize
add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        Mandalo_Address_Book::instance();
    }
}, 30);
