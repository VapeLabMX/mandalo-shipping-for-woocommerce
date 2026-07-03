<?php
/**
 * Plugin installer/uninstaller
 *
 * @package Mandalo_Shipping
 */

defined('ABSPATH') || exit;

class Mandalo_Installer {

    /**
     * Activation hook
     */
    public static function activate() {
        self::create_tables();
        self::create_options();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Deactivation hook
     */
    public static function deactivate() {
        // Clean up transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mandalo_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_mandalo_%'");
    }

    /**
     * Create database tables
     */
    private static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Pricing rules table
        $table_rules = $wpdb->prefix . 'mandalo_shipping_rules';

        $sql_rules = "CREATE TABLE IF NOT EXISTS {$table_rules} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            instance_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            shipping_type VARCHAR(50) NOT NULL DEFAULT 'standard',
            name VARCHAR(255) NOT NULL DEFAULT '',
            conditions LONGTEXT,
            rate_type VARCHAR(50) NOT NULL DEFAULT 'flat',
            rate_value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            priority INT NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY instance_id (instance_id),
            KEY shipping_type (shipping_type),
            KEY enabled (enabled)
        ) {$charset_collate};";

        // Time slots table for scheduled shipping
        $table_slots = $wpdb->prefix . 'mandalo_time_slots';

        $sql_slots = "CREATE TABLE IF NOT EXISTS {$table_slots} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            day_of_week TINYINT NOT NULL COMMENT '0=Sunday, 6=Saturday',
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            max_orders INT NOT NULL DEFAULT 10,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY day_of_week (day_of_week),
            KEY enabled (enabled)
        ) {$charset_collate};";

        // Vehicle types table
        $table_vehicles = $wpdb->prefix . 'mandalo_vehicles';

        $sql_vehicles = "CREATE TABLE IF NOT EXISTS {$table_vehicles} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            type VARCHAR(50) NOT NULL COMMENT 'moto, auto, camioneta',
            max_weight DECIMAL(10,2) NOT NULL DEFAULT 0,
            max_length DECIMAL(10,2) NOT NULL DEFAULT 0,
            max_width DECIMAL(10,2) NOT NULL DEFAULT 0,
            max_height DECIMAL(10,2) NOT NULL DEFAULT 0,
            base_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
            per_km_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY type (type),
            KEY enabled (enabled)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql_rules);
        dbDelta($sql_slots);
        dbDelta($sql_vehicles);

        // Insert default vehicles if table is empty
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_vehicles}");
        if ($count == 0) {
            self::insert_default_vehicles();
        }

        // Insert default time slots if table is empty
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_slots}");
        if ($count == 0) {
            self::insert_default_time_slots();
        }
    }

    /**
     * Insert default vehicles
     */
    private static function insert_default_vehicles() {
        global $wpdb;
        $table = $wpdb->prefix . 'mandalo_vehicles';

        $vehicles = [
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

        foreach ($vehicles as $vehicle) {
            $wpdb->insert($table, $vehicle);
        }
    }

    /**
     * Insert default time slots (Mon-Sat 9am-7pm)
     */
    private static function insert_default_time_slots() {
        global $wpdb;
        $table = $wpdb->prefix . 'mandalo_time_slots';

        // Monday (1) to Saturday (6)
        for ($day = 1; $day <= 6; $day++) {
            // Morning slot
            $wpdb->insert($table, [
                'day_of_week' => $day,
                'start_time' => '09:00:00',
                'end_time' => '13:00:00',
                'max_orders' => 15,
            ]);

            // Afternoon slot
            $wpdb->insert($table, [
                'day_of_week' => $day,
                'start_time' => '14:00:00',
                'end_time' => '19:00:00',
                'max_orders' => 15,
            ]);
        }
    }

    /**
     * Create default options
     */
    private static function create_options() {
        $defaults = [
            'mandalo_osrm_endpoint' => 'https://osrm.vapelab.mx',
            'mandalo_api_endpoint' => 'https://admin.mandalo.mx/api',
            'mandalo_distance_multiplier' => 1.12,
            'mandalo_express_multiplier' => 1.5,
            'mandalo_multi_stop_discount' => 0.15,
            'mandalo_scheduled_discount' => 0.10,
            'mandalo_express_hours' => '08:00-20:00',
            'mandalo_default_city' => 'Ciudad de México',
            'mandalo_default_country' => 'MX',
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }
}
