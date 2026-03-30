<?php
/**
 * Module Cloudflare :
 * - Détection de l'IP réelle via CF-Connecting-IP
 * - API Cloudflare : blocage d'IPs, purge du cache
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRQ_Cloudflare {

    private static ?TRQ_Cloudflare $instance = null;

    private const CF_RANGES = [
        '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '104.16.0.0/13', '104.24.0.0/14', '108.162.192.0/18',
        '131.0.72.0/22', '141.101.64.0/18', '162.158.0.0/15',
        '172.64.0.0/13', '173.245.48.0/20', '188.114.96.0/20',
        '190.93.240.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '2400:cb00::/32', '2405:8100::/32', '2405:b500::/32',
        '2606:4700::/32', '2803:f800::/32', '2a06:98c0::/29',
        '2c0f:f248::/32',
    ];

    private function __construct() {}

    public static function get_instance(): TRQ_Cloudflare {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        // Vérification IP Cloudflare authentique (liste officielle)
        add_action( 'init', [ $this, 'validate_cf_ip' ], 1 );
    }

    /**
     * Vérifie que la requête CF-Connecting-IP provient bien des serveurs Cloudflare.
     * Plages IPv4 officielles de Cloudflare (mise à jour 2024).
     */
    public function validate_cf_ip(): void {
        if ( empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            return; // Pas de header CF, requête directe
        }

        $remote_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );

        // Si la requête arrive d'un serveur Cloudflare, on fait confiance à CF-Connecting-IP
        if ( $this->is_cloudflare_ip( $remote_ip ) ) {
            // Valider que CF-Connecting-IP est une IP valide avant d'en faire confiance
            $cf_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
            if ( filter_var( $cf_ip, FILTER_VALIDATE_IP ) ) {
                // L'IP est déjà utilisée via TRQ_Core::get_client_ip()
                return;
            }
        }
        // Si la requête ne vient PAS de Cloudflare mais présente le header → possible spoofing
        // On supprime le header pour que TRQ_Core::get_client_ip() utilise REMOTE_ADDR
        unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
    }

    private function is_cloudflare_ip( string $ip ): bool {
        foreach ( self::CF_RANGES as $range ) {
            if ( $this->ip_in_range( $ip, $range ) ) {
                return true;
            }
        }
        return false;
    }

    private function ip_in_range( string $ip, string $cidr ): bool {
        [ $subnet, $bits ] = explode( '/', $cidr );
        $ip_bin            = inet_pton( $ip );
        $subnet_bin        = inet_pton( $subnet );

        if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
            return false;
        }

        $bits      = (int) $bits;
        $full      = intdiv( $bits, 8 );
        $remainder = $bits % 8;

        if ( $full > 0 && substr( $ip_bin, 0, $full ) !== substr( $subnet_bin, 0, $full ) ) {
            return false;
        }

        if ( 0 === $remainder ) {
            return true;
        }

        $mask = ( 0xff << ( 8 - $remainder ) ) & 0xff;

        return ( ord( $ip_bin[ $full ] ) & $mask ) === ( ord( $subnet_bin[ $full ] ) & $mask );
    }

    private function has_api_credentials(): bool {
        $core      = TRQ_Core::get_instance();
        $zone_id   = $core->get( 'cloudflare_zone_id' );
        $auth_mode = $core->get( 'cloudflare_auth_mode', 'token' );

        if ( ! $zone_id ) {
            return false;
        }

        if ( 'global_key' === $auth_mode ) {
            return (bool) ( $core->get( 'cloudflare_email' ) && $core->get( 'cloudflare_api_key' ) );
        }

        return (bool) $core->get( 'cloudflare_api_token' );
    }

    private function get_api_headers(): array {
        $core      = TRQ_Core::get_instance();
        $auth_mode = $core->get( 'cloudflare_auth_mode', 'token' );

        $headers = [
            'Content-Type' => 'application/json',
        ];

        if ( 'global_key' === $auth_mode ) {
            $headers['X-Auth-Email'] = (string) $core->get( 'cloudflare_email' );
            $headers['X-Auth-Key']   = (string) $core->get( 'cloudflare_api_key' );
            return $headers;
        }

        $headers['Authorization'] = 'Bearer ' . (string) $core->get( 'cloudflare_api_token' );

        return $headers;
    }

    private function request( string $method, string $endpoint, array $body = [] ) {
        $core    = TRQ_Core::get_instance();
        $zone_id = $core->get( 'cloudflare_zone_id' );

        if ( ! $this->has_api_credentials() ) {
            return new WP_Error( 'trq_cf_missing_credentials', 'Paramètres Cloudflare manquants.' );
        }

        $args = [
            'method'  => $method,
            'timeout' => 15,
            'headers' => $this->get_api_headers(),
        ];

        if ( ! empty( $body ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $endpoint = ltrim( $endpoint, '/' );
        $url      = "https://api.cloudflare.com/client/v4/zones/{$zone_id}";

        if ( '' !== $endpoint ) {
            $url .= '/' . $endpoint;
        }

        return wp_remote_request( $url, $args );
    }

    // =========================================================================
    // API CLOUDFLARE
    // =========================================================================

    /**
     * Bloque une IP via l'API Cloudflare (règle WAF).
     */
    public function api_block_ip( string $ip, string $note = '' ): bool {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return false;
        }

        $response = $this->request( 'POST', 'firewall/access_rules/rules', [
            'mode'          => 'block',
            'configuration' => [ 'target' => 'ip', 'value' => $ip ],
            'notes'         => $note ?: '360 Tranquillité – blocage automatique',
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return ! empty( $body['success'] );
    }

    /**
     * Purge le cache Cloudflare (tous les fichiers).
     */
    public function api_purge_cache(): bool {
        $response = $this->request( 'POST', 'purge_cache', [ 'purge_everything' => true ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return ! empty( $body['success'] );
    }

    /**
     * Teste la connexion à l'API Cloudflare.
     */
    public function api_test_connection(): array {
        $core    = TRQ_Core::get_instance();
        $zone_id = $core->get( 'cloudflare_zone_id' );

        if ( ! $zone_id || ! $this->has_api_credentials() ) {
            return [ 'success' => false, 'message' => 'Paramètres Cloudflare manquants.' ];
        }

        $response = $this->request( 'GET', '' );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! empty( $body['success'] ) ) {
            return [ 'success' => true, 'message' => 'Connexion établie avec la zone : ' . ( $body['result']['name'] ?? $zone_id ) ];
        }

        $errors = $body['errors'][0]['message'] ?? 'Erreur inconnue.';
        return [ 'success' => false, 'message' => $errors ];
    }
}
