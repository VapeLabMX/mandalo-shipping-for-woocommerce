<?php
/**
 * Google Geocoder - Provider for Mandalo Distance Calculator
 *
 * Uses Google Geocoding API + Places Text Search for POI/plaza resolution.
 * Strategy:
 *   1. Places Text Search (textsearch) with CDMX location bias + region=mx&language=es
 *   2. Fallback: Google Geocoding API
 *
 * API key stored in option `mandalo_google_maps_api_key`.
 *
 * @package Mandalo_Shipping
 */

defined('ABSPATH') || exit;

class Mandalo_Geocoder_Google {

    /**
     * CDMX center for location bias (lat,lng).
     */
    private const CDMX_LAT = 19.432608;
    private const CDMX_LNG = -99.133209;

    /**
     * Radius in meters for Places location bias (50 km covers metro CDMX).
     */
    private const BIAS_RADIUS = 50000;

    private string $api_key;

    public function __construct() {
        $this->api_key = (string) get_option('mandalo_google_maps_api_key', '');
    }

    /**
     * Geocode an address using Google APIs.
     *
     * @param string $address  Full address string or POI/plaza name.
     * @param array  $components  Optional structured components (street, city, state, postcode, country).
     * @return array{lat:float,lon:float}|null Coordinates or null on failure.
     */
    public function geocode( string $address, array $components = [] ): ?array {
        if ( empty( $this->api_key ) ) {
            return null;
        }

        // Step 1: Places Text Search (best for named places / plazas).
        $result = $this->geocode_via_places( $address );
        if ( $result ) {
            return $result;
        }

        // Step 2: Geocoding API (best for structured street addresses).
        return $this->geocode_via_geocoding_api( $address, $components );
    }

    /**
     * Google Places Text Search with CDMX location bias.
     */
    private function geocode_via_places( string $query ): ?array {
        $params = [
            'query'    => $query,
            'key'      => $this->api_key,
            'region'   => 'mx',
            'language' => 'es',
            'location' => self::CDMX_LAT . ',' . self::CDMX_LNG,
            'radius'   => self::BIAS_RADIUS,
        ];

        $url      = 'https://maps.googleapis.com/maps/api/place/textsearch/json?' . http_build_query( $params );
        $response = wp_remote_get( $url, [ 'timeout' => 15 ] );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if (
            isset( $data['status'] ) &&
            $data['status'] === 'OK' &&
            ! empty( $data['results'][0]['geometry']['location'] )
        ) {
            $loc = $data['results'][0]['geometry']['location'];
            return [
                'lat' => (float) $loc['lat'],
                'lon' => (float) $loc['lng'],
            ];
        }

        return null;
    }

    /**
     * Google Geocoding API with component filtering for MX.
     */
    private function geocode_via_geocoding_api( string $address, array $components = [] ): ?array {
        // Build address string enriched with available components.
        $parts = array_filter( [
            $components['street']   ?? $address,
            $components['city']     ?? '',
            $components['state']    ?? '',
            $components['postcode'] ?? '',
        ] );
        $full_address = implode( ', ', $parts );
        if ( stripos( $full_address, 'mexico' ) === false && stripos( $full_address, 'cdmx' ) === false ) {
            $full_address .= ', Mexico';
        }

        $params = [
            'address'    => $full_address,
            'key'        => $this->api_key,
            'region'     => 'mx',
            'language'   => 'es',
            'components' => 'country:MX',
        ];

        $url      = 'https://maps.googleapis.com/maps/api/geocode/json?' . http_build_query( $params );
        $response = wp_remote_get( $url, [ 'timeout' => 15 ] );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if (
            isset( $data['status'] ) &&
            $data['status'] === 'OK' &&
            ! empty( $data['results'][0]['geometry']['location'] )
        ) {
            $loc = $data['results'][0]['geometry']['location'];
            return [
                'lat' => (float) $loc['lat'],
                'lon' => (float) $loc['lng'],
            ];
        }

        return null;
    }
}
