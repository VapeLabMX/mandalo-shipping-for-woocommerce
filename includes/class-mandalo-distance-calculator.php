<?php
/**
 * Distance Calculator - Reutiliza lógica de distance-rate-shipping
 * Geocoding: pluggable provider (nominatim | google) via option mandalo_geocoder
 * Routing: OSRM (servidor propio) — unchanged
 * Fallback: Haversine ×1.18 — unchanged
 *
 * @package Mandalo_Shipping
 */

defined('ABSPATH') || exit;

class Mandalo_Distance_Calculator {

    private string $osrm_endpoint;
    private float  $distance_multiplier;
    private array  $runtime_cache = [];

    /** @var string 'nominatim'|'google' */
    private string $geocoder_provider;

    /** @var Mandalo_Geocoder_Google|null Lazy-loaded Google geocoder instance. */
    private ?Mandalo_Geocoder_Google $google_geocoder = null;

    public function __construct() {
        $this->osrm_endpoint       = (string) get_option( 'mandalo_osrm_endpoint', 'http://31.97.98.61:5001' );
        $this->distance_multiplier = (float)  get_option( 'mandalo_distance_multiplier', 1.12 );
        $this->geocoder_provider   = (string) get_option( 'mandalo_geocoder', 'nominatim' );
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Calculate distance from store to single destination.
     *
     * @param string $destination  Destination address string.
     * @param array  $dest_components  Optional structured components.
     * @return float|null Distance in km or null on failure.
     */
    public function calculate_single_distance( string $destination, array $dest_components = [] ): ?float {
        $cache_key = 'mandalo_dist_' . md5( $this->geocoder_provider . $destination );

        // Check transient cache.
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return (float) $cached;
        }

        // Get store coordinates.
        $store = $this->get_store_coords();
        if ( ! $store ) {
            return null;
        }

        // Geocode destination.
        $dest = $this->geocode( $destination, $dest_components );
        if ( ! $dest ) {
            // Fallback to CDMX city center.
            $dest = [ 'lat' => 19.432608, 'lon' => -99.133209 ];
        }

        // Calculate route via OSRM.
        $distance = $this->get_osrm_distance( $store, $dest );

        if ( $distance === null ) {
            // Fallback to Haversine.
            $distance = $this->haversine_distance( $store['lat'], $store['lon'], $dest['lat'], $dest['lon'] );
        }

        // Apply multiplier.
        $distance = $distance * $this->distance_multiplier;

        // Cache result for 5 minutes.
        set_transient( $cache_key, $distance, 5 * MINUTE_IN_SECONDS );

        return round( $distance, 2 );
    }

    /**
     * Calculate multi-stop route.
     *
     * @param array $stops     Array of address strings.
     * @param bool  $optimize  Whether to optimize route order.
     * @return array|WP_Error  Result with total_distance and optimized_order.
     */
    public function calculate_multi_stop_route( array $stops, bool $optimize = true ) {
        if ( count( $stops ) < 2 ) {
            return new WP_Error( 'insufficient_stops', 'Se requieren al menos 2 paradas' );
        }

        // Get store coords as origin.
        $store = $this->get_store_coords();
        if ( ! $store ) {
            return new WP_Error( 'store_not_found', 'No se pudo obtener ubicación de origen' );
        }

        // Geocode all stops.
        $coords = [];
        foreach ( $stops as $index => $stop ) {
            $geocoded = $this->geocode( $stop );
            if ( ! $geocoded ) {
                return new WP_Error( 'geocode_failed', "No se pudo geocodificar: {$stop}" );
            }
            $coords[ $index ] = $geocoded;
        }

        if ( $optimize && count( $coords ) > 2 ) {
            return $this->get_optimized_route( $store, $coords );
        }

        return $this->get_sequential_route( $store, $coords );
    }

    // -------------------------------------------------------------------------
    // Geocoding — provider dispatch
    // -------------------------------------------------------------------------

    /**
     * Geocode an address using the configured provider.
     *
     * With provider=nominatim  → same three-step logic as before (structured,
     *                            free-text, CDMX viewbox fallback). Behavior
     *                            is EXACTLY identical to v2.1.0.
     * With provider=google     → Places Text Search (POI bias CDMX) then
     *                            Geocoding API (component filter MX).
     *
     * @param string $address     Address or place name.
     * @param array  $components  Optional structured components.
     * @return array{lat:float,lon:float}|null
     */
    public function geocode( string $address, array $components = [] ): ?array {
        if ( $this->geocoder_provider === 'google' ) {
            return $this->get_google_geocoder()->geocode( $address, $components );
        }

        // Default: Nominatim (original behavior preserved 100%).
        $result = $this->geocode_nominatim( $address, $components );
        if ( ! $result ) {
            $result = $this->geocode_nominatim_fallback( $address );
        }
        return $result;
    }

    // -------------------------------------------------------------------------
    // Nominatim geocoders (original logic — untouched)
    // -------------------------------------------------------------------------

    /**
     * Geocode using Nominatim (OpenStreetMap).
     */
    private function geocode_nominatim( string $address, array $components = [] ): ?array {
        $country  = $components['country']  ?? 'MX';
        $state    = $components['state']    ?? '';
        $city     = $components['city']     ?? '';
        $street   = $components['street']   ?? $address;
        $postcode = $components['postcode'] ?? '';

        // Normalize MX state codes.
        if ( strtoupper( $country ) === 'MX' ) {
            $country   = 'Mexico';
            $state_map = [
                'DF'   => 'Ciudad de México',
                'CDMX' => 'Ciudad de México',
                'CMX'  => 'Ciudad de México',
                'MEX'  => 'Estado de México',
            ];
            $state = $state_map[ strtoupper( $state ) ] ?? $state;
        }

        // Structured query.
        $params = [
            'format'          => 'json',
            'limit'           => 1,
            'street'          => $street,
            'city'            => $city,
            'state'           => $state,
            'postalcode'      => $postcode,
            'country'         => $country,
            'countrycodes'    => 'mx',
            'accept-language' => 'es',
        ];

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query( array_filter( $params ) );

        $response = wp_remote_get( $url, [
            'headers' => [ 'User-Agent' => 'MandaloShipping-WooCommerce/1.0' ],
            'timeout' => 15,
        ] );

        if ( ! is_wp_error( $response ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! empty( $data[0]['lat'] ) && ! empty( $data[0]['lon'] ) ) {
                return [
                    'lat' => (float) $data[0]['lat'],
                    'lon' => (float) $data[0]['lon'],
                ];
            }
        }

        // Try free-text query.
        $free_text = implode( ', ', array_filter( [ $street, $city, $state, $postcode, $country ] ) );
        $params    = [
            'q'               => $free_text,
            'format'          => 'json',
            'limit'           => 1,
            'countrycodes'    => 'mx',
            'accept-language' => 'es',
        ];

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query( $params );

        $response = wp_remote_get( $url, [
            'headers' => [ 'User-Agent' => 'MandaloShipping-WooCommerce/1.0' ],
            'timeout' => 15,
        ] );

        if ( ! is_wp_error( $response ) ) {
            $data = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! empty( $data[0]['lat'] ) && ! empty( $data[0]['lon'] ) ) {
                return [
                    'lat' => (float) $data[0]['lat'],
                    'lon' => (float) $data[0]['lon'],
                ];
            }
        }

        return null;
    }

    /**
     * Geocode using Nominatim with CDMX viewbox bias (fallback).
     */
    private function geocode_nominatim_fallback( string $address ): ?array {
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query( [
            'q'               => $address,
            'format'          => 'json',
            'addressdetails'  => 1,
            'limit'           => 1,
            'countrycodes'    => 'mx',
            'viewbox'         => '-99.4,19.6,-98.9,19.1', // CDMX bounding box
            'bounded'         => 1,
            'accept-language' => 'es',
        ] );

        $response = wp_remote_get( $url, [
            'headers' => [ 'User-Agent' => 'MandaloShipping-WooCommerce/1.0' ],
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $data[0]['lat'] ) && ! empty( $data[0]['lon'] ) ) {
            return [
                'lat' => (float) $data[0]['lat'],
                'lon' => (float) $data[0]['lon'],
            ];
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Routing — OSRM (unchanged)
    // -------------------------------------------------------------------------

    /**
     * Get optimized route using OSRM trip service.
     */
    private function get_optimized_route( array $origin, array $stops ): array {
        $all_coords   = array_merge( [ $origin ], array_values( $stops ) );
        $coord_string = implode( ';', array_map( static function ( $c ) {
            return $c['lon'] . ',' . $c['lat'];
        }, $all_coords ) );

        $url = sprintf(
            '%s/trip/v1/driving/%s?roundtrip=false&source=first&destination=last&geometries=polyline&overview=false',
            $this->osrm_endpoint,
            $coord_string
        );

        $response = wp_remote_get( $url, [ 'timeout' => 30 ] );

        if ( is_wp_error( $response ) ) {
            return $this->get_sequential_route( $origin, $stops );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $data['trips'][0] ) ) {
            return $this->get_sequential_route( $origin, $stops );
        }

        $trip           = $data['trips'][0];
        $total_distance = round( ( $trip['distance'] / 1000 ) * $this->distance_multiplier, 2 );

        // Get optimized order from waypoints.
        $optimized_order = [];
        if ( ! empty( $data['waypoints'] ) ) {
            foreach ( $data['waypoints'] as $wp ) {
                if ( $wp['waypoint_index'] > 0 ) { // Skip origin.
                    $optimized_order[] = $wp['waypoint_index'] - 1;
                }
            }
        }

        return [
            'total_distance'  => $total_distance,
            'optimized_order' => $optimized_order,
            'legs'            => $trip['legs'] ?? [],
        ];
    }

    /**
     * Get sequential route (not optimized).
     */
    private function get_sequential_route( array $origin, array $stops ): array {
        $total_distance = 0;
        $previous       = $origin;

        foreach ( $stops as $stop ) {
            $leg_distance = $this->get_osrm_distance( $previous, $stop );
            if ( $leg_distance === null ) {
                $leg_distance = $this->haversine_distance(
                    $previous['lat'], $previous['lon'],
                    $stop['lat'],     $stop['lon']
                );
            }
            $total_distance += $leg_distance;
            $previous        = $stop;
        }

        return [
            'total_distance'  => round( $total_distance * $this->distance_multiplier, 2 ),
            'optimized_order' => null,
        ];
    }

    /**
     * Get OSRM driving distance between two coordinate pairs.
     */
    private function get_osrm_distance( array $from, array $to ): ?float {
        $url = sprintf(
            '%s/route/v1/driving/%s,%s;%s,%s?overview=false&alternatives=false&steps=false',
            $this->osrm_endpoint,
            $from['lon'], $from['lat'],
            $to['lon'],   $to['lat']
        );

        $response = wp_remote_get( $url, [ 'timeout' => 20 ] );

        if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $data['routes'][0]['distance'] ) ) {
            return round( $data['routes'][0]['distance'] / 1000, 3 );
        }

        return null;
    }

    // -------------------------------------------------------------------------
    // Store coords cache
    // -------------------------------------------------------------------------

    /**
     * Get store coordinates (cached in wp_options).
     * Cache key includes geocoder provider so switching providers invalidates cache.
     */
    public function get_store_coords(): ?array {
        $cache = get_option( 'mandalo_store_geocode_cache' );

        $addr_parts = [
            get_option( 'woocommerce_store_address' ),
            get_option( 'woocommerce_store_address_2' ),
            get_option( 'woocommerce_store_city' ),
            get_option( 'woocommerce_store_state' ),
            get_option( 'woocommerce_store_postcode' ),
            get_option( 'woocommerce_store_country' ) ?: get_option( 'woocommerce_default_country' ),
        ];
        // Include provider in hash so switching geocoders forces re-geocoding.
        $addr_hash = md5( implode( '|', $addr_parts ) . '|' . $this->geocoder_provider );

        if (
            is_array( $cache ) &&
            ( $cache['hash'] ?? '' ) === $addr_hash &&
            isset( $cache['lat'], $cache['lon'] )
        ) {
            return [ 'lat' => (float) $cache['lat'], 'lon' => (float) $cache['lon'] ];
        }

        $store_address = implode( ', ', array_filter( $addr_parts ) );
        $coords        = $this->geocode( $store_address, [
            'street'   => trim( ( $addr_parts[0] ?? '' ) . ' ' . ( $addr_parts[1] ?? '' ) ),
            'city'     => $addr_parts[2] ?? '',
            'state'    => $addr_parts[3] ?? '',
            'postcode' => $addr_parts[4] ?? '',
            'country'  => $addr_parts[5] ?? '',
        ] );

        if ( ! $coords ) {
            $coords = [ 'lat' => 19.432608, 'lon' => -99.133209 ]; // CDMX center fallback.
        }

        update_option( 'mandalo_store_geocode_cache', [
            'hash' => $addr_hash,
            'lat'  => $coords['lat'],
            'lon'  => $coords['lon'],
        ], false );

        return $coords;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Haversine formula for straight-line distance (fallback, inflated ×1.18).
     */
    private function haversine_distance(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2,
        float $inflate = 1.18
    ): float {
        $R           = 6371.0088; // Earth radius in km.
        $phi1        = deg2rad( $lat1 );
        $phi2        = deg2rad( $lat2 );
        $deltaPhi    = $phi2 - $phi1;
        $deltaLambda = deg2rad( $lon2 - $lon1 );

        $a = sin( $deltaPhi / 2 ) ** 2 + cos( $phi1 ) * cos( $phi2 ) * sin( $deltaLambda / 2 ) ** 2;
        $c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

        return round( $inflate * $R * $c, 3 );
    }

    /**
     * Lazy-load the Google geocoder instance.
     */
    private function get_google_geocoder(): Mandalo_Geocoder_Google {
        if ( $this->google_geocoder === null ) {
            $this->google_geocoder = new Mandalo_Geocoder_Google();
        }
        return $this->google_geocoder;
    }
}
