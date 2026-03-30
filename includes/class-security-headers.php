<?php
/**
 * Module En-têtes de sécurité HTTP :
 * - X-Frame-Options
 * - X-Content-Type-Options
 * - X-XSS-Protection
 * - Referrer-Policy
 * - Strict-Transport-Security (HSTS)
 * - Permissions-Policy
 * - Content-Security-Policy (mode report-only configurable)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRQ_Security_Headers {

    private static ?TRQ_Security_Headers $instance = null;

    private function __construct() {}

    public static function get_instance(): TRQ_Security_Headers {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        add_action( 'send_headers', [ $this, 'send' ], 1 );
        // Pour la page de login également
        add_action( 'login_init',   [ $this, 'send' ], 1 );
    }

    public function send(): void {
        if ( headers_sent() ) {
            return;
        }

        // Empêche l'affichage dans des iframes (clickjacking)
        header( 'X-Frame-Options: SAMEORIGIN' );

        // Empêche le navigateur de deviner le type MIME
        header( 'X-Content-Type-Options: nosniff' );

        // Filtre XSS des anciens navigateurs
        header( 'X-XSS-Protection: 1; mode=block' );

        // Contrôle de l'en-tête Referer
        header( 'Referrer-Policy: strict-origin-when-cross-origin' );

        // Permissions API
        header( 'Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=(), magnetometer=(), gyroscope=()' );

        // HSTS — active uniquement si le site est en HTTPS
        if ( is_ssl() ) {
            // max-age=1 an, includeSubDomains
            header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
        }

        // CSP de base — autorise le CDN WordPress et désactive eval
        $csp = implode( '; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",   // unsafe-inline nécessaire pour wp-admin
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com",
            "img-src 'self' data: https:",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
        ] );
        header( 'Content-Security-Policy: ' . $csp );

        // Masque la technologie serveur
        header_remove( 'X-Powered-By' );
        header_remove( 'Server' );
    }
}
