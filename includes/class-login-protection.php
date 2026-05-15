<?php
/**
 * Module Protection de la connexion :
 * - URL de connexion personnalisée (cache wp-login.php)
 * - Anti-brute-force (compte les tentatives, lockout par IP)
 * - Prévention de l'énumération des utilisateurs
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRQ_Login_Protection {

    private static ?TRQ_Login_Protection $instance = null;

    private function __construct() {}

    public static function get_instance(): TRQ_Login_Protection {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        $core = TRQ_Core::get_instance();

        // --- URL de connexion personnalisée ---
        $slug = trim( (string) $core->get( 'login_slug', '' ) );
        if ( $slug ) {
            add_action( 'init',              [ $this, 'add_rewrite_rule'      ], 10 );
            add_action( 'init',              [ $this, 'intercept_login_page'  ], 999 );
            add_filter( 'login_url',         [ $this, 'filter_login_url'      ], 10, 3 );
            add_filter( 'logout_url',        [ $this, 'filter_logout_url'     ], 10, 2 );
            add_filter( 'lostpassword_url',  [ $this, 'filter_lostpass_url'   ], 10, 2 );
            add_filter( 'network_site_url',  [ $this, 'filter_network_url'    ], 10, 3 );
            add_filter( 'wp_redirect',       [ $this, 'filter_redirect'       ], 10, 2 );
            // Flush rewrite si besoin (après activation)
            add_action( 'wp_loaded', [ $this, 'maybe_flush_rewrite' ] );
        }

        // --- Personnalisation visuelle de la page de connexion ---
        add_action( 'login_enqueue_scripts', [ $this, 'print_login_custom_styles' ] );
        add_filter( 'login_headerurl', [ $this, 'filter_login_header_url' ] );
        add_filter( 'login_headertext', [ $this, 'filter_login_header_text' ] );
        add_filter( 'login_headertitle', [ $this, 'filter_login_header_text' ] );

        // --- Anti-brute-force ---
        add_action( 'wp_login_failed',  [ $this, 'on_login_failed'   ] );
        add_filter( 'authenticate',     [ $this, 'check_lockout'     ], 5, 3 );
        add_action( 'wp_login',         [ $this, 'on_login_success'  ], 10, 2 );

        // --- Prévention énumération ---
        if ( $core->get( 'disable_user_enum' ) ) {
            add_action( 'init', [ $this, 'block_user_enum' ] );
            add_filter( 'rest_endpoints', [ $this, 'block_users_rest_endpoint' ] );
        }
    }

    // =========================================================================
    // URL DE CONNEXION PERSONNALISÉE
    // =========================================================================

    public function add_rewrite_rule(): void {
        $slug = $this->get_slug();
        add_rewrite_rule( '^' . preg_quote( $slug, '/' ) . '/?$', 'index.php?trq_login=1', 'top' );
        add_rewrite_tag( '%trq_login%', '([0-9]+)' );
    }

    public function maybe_flush_rewrite(): void {
        if ( get_transient( 'trq_flush_rewrite' ) ) {
            flush_rewrite_rules();
            delete_transient( 'trq_flush_rewrite' );
        }
    }

    /**
     * Intercepte :
     * 1. L'accès direct à wp-login.php → renvoie 404
     * 2. L'accès à notre slug personnalisé → sert wp-login.php
     */
    public function intercept_login_page(): void {
        global $pagenow;

        $slug    = $this->get_slug();
        $request = trim( parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), PHP_URL_PATH ), '/' );

        // Vider le préfixe de sous-dossier si WP est installé dans un sous-dossier
        $home_path = trim( parse_url( home_url(), PHP_URL_PATH ) ?? '', '/' );
        if ( $home_path && 0 === strpos( $request, $home_path ) ) {
            $request = trim( substr( $request, strlen( $home_path ) ), '/' );
        }

        // Notre slug → on sert wp-login.php
        if ( $request === $slug || $request === ( $slug . '/index.php' ) ) {
            // Corriger SCRIPT_FILENAME pour que wp-login.php fonctionne
            $_SERVER['SCRIPT_FILENAME'] = ABSPATH . 'wp-login.php';
            require_once ABSPATH . 'wp-login.php';
            exit;
        }

        // Accès direct à wp-login.php (pas par notre slug) → 404
        if (
            $pagenow === 'wp-login.php' ||
            false !== strpos( $request, 'wp-login.php' )
        ) {
            // Exception : les utilisateurs connectés accédant au back-office
            if ( is_user_logged_in() ) {
                return;
            }
            status_header( 404 );
            nocache_headers();
            $template = get_404_template();
            if ( ! empty( $template ) ) {
                include $template;
            }
            die();
        }
    }

    // --- Filtres d'URL ---

    public function filter_login_url( string $url, $redirect = '', $force_reauth = false ): string {
        unset( $redirect, $force_reauth );
        return $this->swap_login_url( $url );
    }

    public function filter_logout_url( string $url, $redirect = '' ): string {
        unset( $redirect );
        return $this->swap_login_url( $url );
    }

    public function filter_lostpass_url( string $url, $redirect = '' ): string {
        unset( $redirect );
        return $this->swap_login_url( $url );
    }

    public function filter_network_url( string $url, $path = '', $scheme = null ): string {
        unset( $path, $scheme );
        if ( false !== strpos( $url, 'wp-login.php' ) ) {
            return $this->swap_login_url( $url );
        }
        return $url;
    }

    public function filter_redirect( string $location, int $status ): string {
        if ( false !== strpos( $location, 'wp-login.php' ) ) {
            return $this->swap_login_url( $location );
        }
        return $location;
    }

    private function swap_login_url( string $url ): string {
        $slug = $this->get_slug();
        if ( '' === $slug ) {
            return $url;
        }

        return str_replace(
            [ site_url( 'wp-login.php' ), home_url( 'wp-login.php' ) ],
            home_url( $slug ),
            $url
        );
    }

    private function get_slug(): string {
        return trim( (string) TRQ_Core::get_instance()->get( 'login_slug', '' ) );
    }

    private function is_login_visual_customization_enabled(): bool {
        return (bool) TRQ_Core::get_instance()->get( 'login_visual_customization_enabled', false );
    }

    public function print_login_custom_styles(): void {
        if ( ! $this->is_login_visual_customization_enabled() ) {
            return;
        }

        $core = TRQ_Core::get_instance();

        $logo_url        = esc_url_raw( (string) $core->get( 'login_custom_logo_url', '' ) );
        $logo_width      = max( 60, min( 420, (int) $core->get( 'login_logo_width', 120 ) ) );
        $logo_height     = max( 40, min( 240, (int) $core->get( 'login_logo_height', 120 ) ) );
        $background      = sanitize_hex_color( (string) $core->get( 'login_bg_color', '#f0f2f5' ) ) ?: '#f0f2f5';
        $form_bg         = sanitize_hex_color( (string) $core->get( 'login_form_bg_color', '#ffffff' ) ) ?: '#ffffff';
        $form_border     = sanitize_hex_color( (string) $core->get( 'login_form_border_color', '#dcdcde' ) ) ?: '#dcdcde';
        $form_text       = sanitize_hex_color( (string) $core->get( 'login_form_text_color', '#1d2327' ) ) ?: '#1d2327';
        $input_bg        = sanitize_hex_color( (string) $core->get( 'login_input_bg_color', '#ffffff' ) ) ?: '#ffffff';
        $input_text      = sanitize_hex_color( (string) $core->get( 'login_input_text_color', '#1d2327' ) ) ?: '#1d2327';
        $input_border    = sanitize_hex_color( (string) $core->get( 'login_input_border_color', '#8c8f94' ) ) ?: '#8c8f94';
        $btn_bg          = sanitize_hex_color( (string) $core->get( 'login_button_bg_color', '#2271b1' ) ) ?: '#2271b1';
        $btn_text        = sanitize_hex_color( (string) $core->get( 'login_button_text_color', '#ffffff' ) ) ?: '#ffffff';
        $btn_hover       = sanitize_hex_color( (string) $core->get( 'login_button_hover_bg_color', '#135e96' ) ) ?: '#135e96';
        $link_color      = sanitize_hex_color( (string) $core->get( 'login_link_color', '#2271b1' ) ) ?: '#2271b1';
        $link_hover      = sanitize_hex_color( (string) $core->get( 'login_link_hover_color', '#135e96' ) ) ?: '#135e96';
        $message_bg      = sanitize_hex_color( (string) $core->get( 'login_message_bg_color', '#ffffff' ) ) ?: '#ffffff';
        $message_text    = sanitize_hex_color( (string) $core->get( 'login_message_text_color', '#1d2327' ) ) ?: '#1d2327';
        $radius          = max( 0, min( 48, (int) $core->get( 'login_form_border_radius', 8 ) ) );
        $shadow          = (bool) $core->get( 'login_form_shadow', true );
        $custom_css      = trim( (string) $core->get( 'login_custom_css', '' ) );

        $logo_css = '';
        if ( '' !== $logo_url ) {
            $logo_css =
                'body.login div#login h1 a, body.login .login h1 a {' .
                'background-image:url("' . esc_url( $logo_url ) . '");' .
                'background-size:contain;' .
                'width:' . $logo_width . 'px;' .
                'height:' . $logo_height . 'px;' .
                '}';
        }

        $shadow_css = $shadow
            ? 'box-shadow:0 18px 44px rgba(2, 18, 46, 0.14);'
            : 'box-shadow:none;';

        $css =
            'body.login{background:' . $background . ';}' .
            'body.login #login{width:min(92vw,420px);}' .
            'body.login form{background:' . $form_bg . ';border:1px solid ' . $form_border . ';border-radius:' . $radius . 'px;' . $shadow_css . '}' .
            'body.login label,body.login form .input,body.login form input[type=text],body.login form input[type=password],body.login form input[type=email]{color:' . $form_text . ';}' .
            'body.login form input[type=text],body.login form input[type=password],body.login form input[type=email]{background:' . $input_bg . ';color:' . $input_text . ';border-color:' . $input_border . ';border-radius:' . max( 0, $radius - 2 ) . 'px;}' .
            'body.login .button.wp-hide-pw{color:' . $input_text . ';}' .
            'body.login .wp-core-ui .button-primary{background:' . $btn_bg . ';border-color:' . $btn_bg . ';color:' . $btn_text . ';text-shadow:none;box-shadow:none;}' .
            'body.login .wp-core-ui .button-primary:hover,body.login .wp-core-ui .button-primary:focus{background:' . $btn_hover . ';border-color:' . $btn_hover . ';color:' . $btn_text . ';}' .
            'body.login #nav a,body.login #backtoblog a,body.login .privacy-policy-link{color:' . $link_color . ';}' .
            'body.login #nav a:hover,body.login #backtoblog a:hover,body.login .privacy-policy-link:hover{color:' . $link_hover . ';}' .
            'body.login #login_error,body.login .message,body.login .success{background:' . $message_bg . ';color:' . $message_text . ';border-left-color:' . $link_color . ';border-radius:' . max( 0, $radius - 2 ) . 'px;}' .
            $logo_css;

        if ( '' !== $custom_css ) {
            $css .= wp_strip_all_tags( $custom_css );
        }

        echo '<style id="trq-login-custom-style">' . esc_html( $css ) . '</style>';
    }

    public function filter_login_header_url( string $url ): string {
        if ( ! $this->is_login_visual_customization_enabled() ) {
            return $url;
        }

        $custom_url = esc_url_raw( (string) TRQ_Core::get_instance()->get( 'login_logo_link_url', '' ) );
        if ( '' !== $custom_url ) {
            return $custom_url;
        }

        return home_url( '/' );
    }

    public function filter_login_header_text( string $text ): string {
        if ( ! $this->is_login_visual_customization_enabled() ) {
            return $text;
        }

        $custom_text = trim( (string) TRQ_Core::get_instance()->get( 'login_logo_title', '' ) );
        if ( '' !== $custom_text ) {
            return $custom_text;
        }

        return get_bloginfo( 'name' );
    }

    // =========================================================================
    // ANTI-BRUTE-FORCE
    // =========================================================================

    /** Enregistre une tentative échouée */
    public function on_login_failed( string $username ): void {
        global $wpdb;
        $ip = TRQ_Core::get_client_ip();

        $wpdb->insert(
            $wpdb->prefix . 'trq_login_attempts',
            [
                'ip_address'   => $ip,
                'username'     => substr( sanitize_text_field( $username ), 0, 255 ),
                'attempted_at' => current_time( 'mysql', true ),
                'success'      => 0,
            ],
            [ '%s', '%s', '%s', '%d' ]
        );

        // Notifier si le seuil est atteint
        $core      = TRQ_Core::get_instance();
        $max       = (int) $core->get( 'login_max_attempts', 5 );
        $window    = (int) $core->get( 'login_lockout_minutes', 30 );
        $count     = $this->count_recent_failures( $ip, $window );

        if ( $count >= $max ) {
            TRQ_Firewall::get_instance()->block_ip(
                $ip,
                'Brute-force : ' . $count . ' tentatives échouées',
                $window
            );
            TRQ_Core::notify(
                'Brute-force détecté',
                sprintf(
                    "L'IP %s a été bloquée après %d tentatives échouées.\n\nDernier identifiant tenté : %s",
                    $ip, $count, $username
                )
            );
        }
    }

    /** Vérifie si l'IP est en lockout avant d'authentifier */
    public function check_lockout( $user, string $username, string $password ) {
        if ( empty( $username ) ) {
            return $user;
        }
        $ip = TRQ_Core::get_client_ip();
        if ( TRQ_Firewall::get_instance()->is_ip_blocked( $ip ) ) {
            return new WP_Error(
                'trq_lockout',
                sprintf(
                    __( 'Votre IP a été temporairement bloquée après plusieurs tentatives échouées. Réessayez dans %d minutes.', '360tranquilite' ),
                    (int) TRQ_Core::get_instance()->get( 'login_lockout_minutes', 30 )
                )
            );
        }
        return $user;
    }

    /** Marque la tentative comme réussie (nettoyage log) */
    public function on_login_success( string $user_login, WP_User $user ): void {
        global $wpdb;
        // On marque le succès — utile pour les stats
        $wpdb->insert(
            $wpdb->prefix . 'trq_login_attempts',
            [
                'ip_address'   => TRQ_Core::get_client_ip(),
                'username'     => substr( $user_login, 0, 255 ),
                'attempted_at' => current_time( 'mysql', true ),
                'success'      => 1,
            ],
            [ '%s', '%s', '%s', '%d' ]
        );

        update_user_meta( $user->ID, 'trq_last_login_at', current_time( 'mysql', true ) );
        update_user_meta( $user->ID, 'trq_last_login_ip', TRQ_Core::get_client_ip() );
    }

    private function count_recent_failures( string $ip, int $window_minutes ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->prefix}trq_login_attempts`
             WHERE ip_address = %s
               AND success = 0
               AND attempted_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d MINUTE)",
            $ip,
            $window_minutes
        ) );
    }

    // =========================================================================
    // ÉNUMÉRATION DES UTILISATEURS
    // =========================================================================

    public function block_user_enum(): void {
        if ( ! is_admin() && isset( $_GET['author'] ) ) {
            wp_die(
                esc_html__( 'Accès refusé.', '360tranquilite' ),
                '',
                [ 'response' => 403 ]
            );
        }
    }

    public function block_users_rest_endpoint( array $endpoints ): array {
        if ( ! current_user_can( 'list_users' ) ) {
            if ( isset( $endpoints['/wp/v2/users'] ) ) {
                unset( $endpoints['/wp/v2/users'] );
            }
            if ( isset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] ) ) {
                unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
            }
        }
        return $endpoints;
    }

    // =========================================================================
    // STATS (pour le dashboard)
    // =========================================================================

    public function get_stats(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'trq_login_attempts';
        return [
            'failed_today'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE success=0 AND DATE(attempted_at)=CURDATE()" ),
            'failed_week'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE success=0 AND attempted_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)" ),
            'success_today' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE success=1 AND DATE(attempted_at)=CURDATE()" ),
        ];
    }

    public function get_recent_attempts( int $limit = 30 ): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$wpdb->prefix}trq_login_attempts` ORDER BY attempted_at DESC LIMIT %d",
            $limit
        ) );
    }
}
