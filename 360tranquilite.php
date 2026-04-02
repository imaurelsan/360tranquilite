<?php
/**
 * Plugin Name:       360 Tranquillité
 * Plugin URI:        https://yaurel.com
 * Description:       Sécurité et continuité tout-en-un : Firewall WAF, anti-brute-force, 2FA TOTP, sauvegardes complètes/incrémentales locales ou cloud, restauration locale, URL de connexion personnalisée, Cloudflare, headers HTTP, surveillance d'intégrité et anti-spam.
 * Version:           1.10.6
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Aurel Yahouedeou
 * Author URI:        https://yaurel.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain:       360tranquilite
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TRQ_VERSION',     '1.10.6' );
define( 'TRQ_DB_VERSION',  '1.0' );
define( 'TRQ_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'TRQ_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'TRQ_PLUGIN_FILE', __FILE__ );

if ( ! function_exists( 'trq_record_boot_error' ) ) {
    /**
     * Enregistre un dernier incident bootstrap pour diagnostic admin.
     */
    function trq_record_boot_error( string $message, string $file = '', int $line = 0 ): void {
        if ( '' === $message || ! function_exists( 'update_option' ) ) {
            return;
        }

        update_option(
            'trq_last_boot_error',
            [
                'time'    => function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ),
                'message' => $message,
                'file'    => $file,
                'line'    => $line,
            ],
            false
        );
    }
}

if ( ! function_exists( 'trq_install_login_redirect_safety_net' ) ) {
    /**
     * Neutralise les callbacks legacy sur login_redirect et installe un callback robuste.
     */
    function trq_install_login_redirect_safety_net(): void {
        if ( ! function_exists( 'add_filter' ) ) {
            return;
        }

        global $wp_filter;

        if ( isset( $wp_filter['login_redirect'] ) && $wp_filter['login_redirect'] instanceof WP_Hook ) {
            foreach ( $wp_filter['login_redirect']->callbacks as $priority => $callbacks ) {
                foreach ( $callbacks as $id => $callback_data ) {
                    $fn = $callback_data['function'] ?? null;
                    if ( ! is_array( $fn ) || ! isset( $fn[0], $fn[1] ) ) {
                        continue;
                    }

                    if ( $fn[0] instanceof TRQ_Dev_Toolkit && 'filter_login_redirect' === $fn[1] ) {
                        unset( $wp_filter['login_redirect']->callbacks[ $priority ][ $id ] );
                    }
                }

                if ( empty( $wp_filter['login_redirect']->callbacks[ $priority ] ) ) {
                    unset( $wp_filter['login_redirect']->callbacks[ $priority ] );
                }
            }
        }

        // Callback final et sûr, indépendant d'une ancienne signature potentiellement en cache.
        add_filter(
            'login_redirect',
            static function ( $redirect_to, $requested_redirect_to, $user ) {
                unset( $requested_redirect_to );

                if ( ! is_string( $redirect_to ) || '' === $redirect_to ) {
                    $redirect_to = (string) admin_url();
                }

                if ( ! ( $user instanceof WP_User ) || is_wp_error( $user ) ) {
                    return $redirect_to;
                }

                if ( ! class_exists( 'TRQ_Core' ) ) {
                    return $redirect_to;
                }

                $core = TRQ_Core::get_instance();
                if ( ! (bool) $core->get( 'toolkit_enabled', false ) || ! (bool) $core->get( 'toolkit_login_redirect_enabled', false ) ) {
                    return $redirect_to;
                }

                $target = (string) $core->get( 'toolkit_login_redirect_url', '' );
                return '' !== $target ? esc_url_raw( $target ) : $redirect_to;
            },
            999,
            3
        );
    }
}

add_action( 'init', 'trq_install_login_redirect_safety_net', 1 );

// Chargement de tous les modules
foreach ( [
    'class-core',
    'class-localization',
    'class-threat-definitions',
    'class-audit-log',
    'class-firewall',
    'class-login-protection',
    'class-two-factor',
    'class-cloudflare',
    'class-backup-manager',
    'class-auto-updates',
    'class-github-updates',
    'class-media-cleanup',
    'class-dev-toolkit',
    'class-security-headers',
    'class-file-monitor',
    'class-system-scanner',
    'class-antispam',
] as $module ) {
    $module_file = TRQ_PLUGIN_DIR . 'includes/' . $module . '.php';

    // Certains hébergements gardent des opcodes périmés plus longtemps que prévu.
    // On invalide explicitement le module Toolkit pour éviter les signatures obsolètes en mémoire.
    if ( 'class-dev-toolkit' === $module && function_exists( 'opcache_invalidate' ) ) {
        @opcache_invalidate( $module_file, true );
    }

    require_once $module_file;
}

if ( is_admin() ) {
    require_once TRQ_PLUGIN_DIR . 'admin/class-admin.php';
}

// Hooks d'activation / désactivation / désinstallation
register_activation_hook(   __FILE__, [ 'TRQ_Core', 'activate'   ] );
register_deactivation_hook( __FILE__, [ 'TRQ_Core', 'deactivate' ] );

// Démarrage du plugin
add_action( 'plugins_loaded', function () {
    load_plugin_textdomain( '360tranquilite', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

    register_shutdown_function(
        static function (): void {
            $error = error_get_last();
            if ( ! is_array( $error ) ) {
                return;
            }

            $fatal_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ];
            if ( ! in_array( (int) ( $error['type'] ?? 0 ), $fatal_types, true ) ) {
                return;
            }

            $file = (string) ( $error['file'] ?? '' );
            $normalized_plugin_dir = wp_normalize_path( TRQ_PLUGIN_DIR );
            $normalized_file = wp_normalize_path( $file );
            if ( '' === $normalized_file || 0 !== strpos( $normalized_file, $normalized_plugin_dir ) ) {
                return;
            }

            trq_record_boot_error(
                (string) ( $error['message'] ?? '' ),
                $file,
                (int) ( $error['line'] ?? 0 )
            );
        }
    );

    try {
        TRQ_Github_Updates::get_instance()->init();
        TRQ_Core::get_instance()->init();

        // Si le plugin démarre correctement, on purge l'incident bootstrap précédent.
        // En cas de fatal plus tard dans la requête, le shutdown handler réécrira l'option.
        delete_option( 'trq_last_boot_error' );
    } catch ( Throwable $e ) {
        trq_record_boot_error( $e->getMessage(), $e->getFile(), $e->getLine() );
    }
} );

if ( is_admin() ) {
    add_action(
        'admin_notices',
        static function (): void {
            if ( ! current_user_can( 'manage_options' ) ) {
                return;
            }

            $last_error = get_option( 'trq_last_boot_error', [] );
            if ( ! is_array( $last_error ) || empty( $last_error['message'] ) ) {
                return;
            }

            $message = (string) $last_error['message'];
            $file = (string) ( $last_error['file'] ?? '' );
            $line = (int) ( $last_error['line'] ?? 0 );
            $time = (string) ( $last_error['time'] ?? '' );

            echo '<div class="notice notice-error"><p><strong>360 Tranquillité:</strong> Incident bootstrap détecté.</p>';
            echo '<p><code>' . esc_html( $message ) . '</code></p>';
            if ( '' !== $file ) {
                echo '<p>Fichier: <code>' . esc_html( $file ) . '</code>';
                if ( $line > 0 ) {
                    echo ' (ligne ' . esc_html( (string) $line ) . ')';
                }
                echo '</p>';
            }
            if ( '' !== $time ) {
                echo '<p>Horodatage UTC: ' . esc_html( $time ) . '</p>';
            }
            echo '</div>';
        }
    );
}
