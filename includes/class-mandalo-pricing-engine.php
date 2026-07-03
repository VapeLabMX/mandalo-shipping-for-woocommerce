<?php
/**
 * Pricing Engine - Calcula precios según tipo de envío
 *
 * @package Mandalo_Shipping
 */

defined('ABSPATH') || exit;

class Mandalo_Pricing_Engine {

    private $express_multiplier;
    private $multi_stop_discount;
    private $scheduled_discount;

    public function __construct() {
        $this->express_multiplier = floatval(get_option('mandalo_express_multiplier', 1.5));
        $this->multi_stop_discount = floatval(get_option('mandalo_multi_stop_discount', 0.15));
        $this->scheduled_discount = floatval(get_option('mandalo_scheduled_discount', 0.10));
    }

    /**
     * Calculate shipping price
     *
     * @param float  $distance_km Total distance in km
     * @param string $shipping_type Type of shipping
     * @param int    $stop_count Number of stops (for multi-stop)
     * @param array  $vehicle_info Optional vehicle info for truck shipping
     * @return float Price in store currency
     */
    public function calculate_price($distance_km, $shipping_type = 'standard', $stop_count = 1, $vehicle_info = []) {
        // Get base rates from vehicle or default
        $base_rate = $this->get_base_rate($shipping_type, $vehicle_info);
        $per_km_rate = $this->get_per_km_rate($shipping_type, $vehicle_info);

        // Calculate base price
        $price = $base_rate + ($per_km_rate * $distance_km);

        // Apply modifiers based on shipping type
        switch ($shipping_type) {
            case 'express':
                // Express is more expensive (50% more by default)
                $price *= $this->express_multiplier;
                break;

            case 'multi_optimized':
                // Optimized multi-stop gets discount
                $price = $this->calculate_multi_stop_price($price, $stop_count, true);
                break;

            case 'multi_ordered':
                // Non-optimized multi-stop (specific order) - no discount
                $price = $this->calculate_multi_stop_price($price, $stop_count, false);
                break;

            case 'scheduled':
                // Scheduled is cheaper (10% discount by default)
                $price *= (1 - $this->scheduled_discount);
                break;

            case 'truck':
                // Truck pricing is handled by vehicle rates
                // Additional fee per stop if multi-stop
                if ($stop_count > 1) {
                    $price += ($stop_count - 1) * 50; // $50 MXN per extra stop
                }
                break;

            case 'standard':
            default:
                // Standard A-B pricing, no modifiers
                break;
        }

        // Round to 2 decimals
        return round($price, 2);
    }

    /**
     * Calculate multi-stop price with optional discount
     */
    private function calculate_multi_stop_price($base_price, $stop_count, $optimized = true) {
        // Fee per additional stop
        $stop_fee = 25; // $25 MXN per extra stop

        // Add stop fees
        $total = $base_price + (($stop_count - 1) * $stop_fee);

        // Apply discount if route is optimized
        if ($optimized && $stop_count > 2) {
            // Discount increases with more stops
            $discount = min($this->multi_stop_discount * ($stop_count - 1), 0.30); // Max 30%
            $total *= (1 - $discount);
        }

        return $total;
    }

    /**
     * Get base rate for shipping type
     */
    private function get_base_rate($shipping_type, $vehicle_info = []) {
        if ($shipping_type === 'truck' && !empty($vehicle_info['base_rate'])) {
            return floatval($vehicle_info['base_rate']);
        }

        // Default base rates by type
        $rates = [
            'standard' => 45,
            'express' => 45, // Will be multiplied
            'multi_optimized' => 45,
            'multi_ordered' => 45,
            'scheduled' => 45, // Will get discount
            'truck' => 150, // Default if no vehicle
        ];

        return $rates[$shipping_type] ?? 45;
    }

    /**
     * Get per-km rate for shipping type
     */
    private function get_per_km_rate($shipping_type, $vehicle_info = []) {
        if ($shipping_type === 'truck' && !empty($vehicle_info['per_km_rate'])) {
            return floatval($vehicle_info['per_km_rate']);
        }

        // Default per-km rates
        $rates = [
            'standard' => 8,
            'express' => 8,
            'multi_optimized' => 7, // Slightly cheaper per km
            'multi_ordered' => 8,
            'scheduled' => 7,
            'truck' => 18,
        ];

        return $rates[$shipping_type] ?? 8;
    }

    /**
     * Get price breakdown for display
     */
    public function get_price_breakdown($distance_km, $shipping_type, $stop_count = 1, $vehicle_info = []) {
        $base_rate = $this->get_base_rate($shipping_type, $vehicle_info);
        $per_km_rate = $this->get_per_km_rate($shipping_type, $vehicle_info);

        $breakdown = [
            'base_fee' => $base_rate,
            'distance_km' => $distance_km,
            'per_km_rate' => $per_km_rate,
            'distance_fee' => $per_km_rate * $distance_km,
            'subtotal' => $base_rate + ($per_km_rate * $distance_km),
            'modifiers' => [],
            'total' => 0,
        ];

        $total = $breakdown['subtotal'];

        // Add modifiers
        switch ($shipping_type) {
            case 'express':
                $modifier = $total * ($this->express_multiplier - 1);
                $breakdown['modifiers'][] = [
                    'label' => 'Cargo Express (+' . (($this->express_multiplier - 1) * 100) . '%)',
                    'amount' => $modifier,
                ];
                $total *= $this->express_multiplier;
                break;

            case 'multi_optimized':
                if ($stop_count > 1) {
                    $stop_fee = ($stop_count - 1) * 25;
                    $breakdown['modifiers'][] = [
                        'label' => 'Paradas adicionales (' . ($stop_count - 1) . ')',
                        'amount' => $stop_fee,
                    ];
                    $total += $stop_fee;

                    if ($stop_count > 2) {
                        $discount = min($this->multi_stop_discount * ($stop_count - 1), 0.30);
                        $discount_amount = -($total * $discount);
                        $breakdown['modifiers'][] = [
                            'label' => 'Descuento ruta optimizada (-' . ($discount * 100) . '%)',
                            'amount' => $discount_amount,
                        ];
                        $total *= (1 - $discount);
                    }
                }
                break;

            case 'multi_ordered':
                if ($stop_count > 1) {
                    $stop_fee = ($stop_count - 1) * 25;
                    $breakdown['modifiers'][] = [
                        'label' => 'Paradas adicionales (' . ($stop_count - 1) . ')',
                        'amount' => $stop_fee,
                    ];
                    $total += $stop_fee;
                }
                break;

            case 'scheduled':
                $discount_amount = -($total * $this->scheduled_discount);
                $breakdown['modifiers'][] = [
                    'label' => 'Descuento envío programado (-' . ($this->scheduled_discount * 100) . '%)',
                    'amount' => $discount_amount,
                ];
                $total *= (1 - $this->scheduled_discount);
                break;
        }

        $breakdown['total'] = round($total, 2);

        return $breakdown;
    }

    /**
     * Apply custom rules from database
     */
    public function apply_custom_rules($price, $instance_id, $context = []) {
        global $wpdb;

        $table = $wpdb->prefix . 'mandalo_shipping_rules';

        $rules = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE instance_id = %d AND enabled = 1 ORDER BY priority ASC",
            $instance_id
        ), ARRAY_A);

        if (empty($rules)) {
            return $price;
        }

        foreach ($rules as $rule) {
            $conditions = json_decode($rule['conditions'], true);

            if (!is_array($conditions) || !$this->evaluate_conditions($conditions, $context)) {
                continue;
            }

            switch ($rule['rate_type']) {
                case 'abort':
                    return null; // Don't show shipping method

                case 'free':
                    return 0;

                case 'flat':
                    $price += floatval($rule['rate_value']);
                    break;

                case 'discount':
                    $price -= floatval($rule['rate_value']);
                    break;

                case 'extra_fee':
                    $price += floatval($rule['rate_value']);
                    break;

                case 'per_km':
                    $distance = $context['distance_km'] ?? 0;
                    $price += floatval($rule['rate_value']) * $distance;
                    break;
            }
        }

        return max(0, $price);
    }

    /**
     * Evaluate rule conditions
     */
    private function evaluate_conditions($conditions, $context) {
        $result = true;

        foreach ($conditions as $index => $cond) {
            $field = $cond['field'] ?? '';
            $operator = $cond['operator'] ?? '';
            $value = $cond['value'] ?? '';

            $actual = $context[$field] ?? null;

            $match = $this->compare($actual, $operator, $value);

            if ($index === 0) {
                $result = $match;
            } else {
                $logic = strtoupper($cond['logic'] ?? 'AND');
                $result = ($logic === 'OR') ? ($result || $match) : ($result && $match);
            }
        }

        return $result;
    }

    /**
     * Compare values
     */
    private function compare($actual, $operator, $value) {
        $operator = strtolower($operator);

        // Numeric comparison
        if (is_numeric($actual) || is_numeric($value)) {
            $a = floatval($actual);
            $b = floatval($value);

            switch ($operator) {
                case 'is':
                    return $a == $b;
                case 'is not':
                    return $a != $b;
                case 'greater than':
                    return $a > $b;
                case 'greater than or is':
                    return $a >= $b;
                case 'less than':
                    return $a < $b;
                case 'less than or is':
                    return $a <= $b;
                case 'between':
                    $parts = array_map('trim', explode(',', $value));
                    if (count($parts) === 2) {
                        return $a >= floatval($parts[0]) && $a <= floatval($parts[1]);
                    }
                    return false;
            }
        }

        // String comparison
        $a = (string) $actual;
        $b = (string) $value;

        switch ($operator) {
            case 'is':
                return $a === $b;
            case 'is not':
                return $a !== $b;
            case 'contains':
                return stripos($a, $b) !== false;
            case 'does not contain':
                return stripos($a, $b) === false;
            case 'starts with':
                return stripos($a, $b) === 0;
            case 'ends with':
                return substr($a, -strlen($b)) === $b;
            case 'is empty':
                return empty($a);
            case 'is not empty':
                return !empty($a);
        }

        return false;
    }
}
