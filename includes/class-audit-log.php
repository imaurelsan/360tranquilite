<?php
/**
 * Journal d'audit des actions sensibles.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRQ_Audit_Log {

    private static ?TRQ_Audit_Log $instance = null;

    private bool $booted = false;

    private const SENSITIVE_OPTIONS = [
        'siteurl',
        'home',
        'admin_email',
        'users_can_register',
        'default_role',
        'blog_public',
        'permalink_structure',
        'active_plugins',
        'stylesheet',
        'template',
        'trq_settings',
    ];

    private function __construct() {}

    public static function get_instance(): TRQ_Audit_Log {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init(): void {
        if ( $this->booted ) {
            return;
        }

        $this->booted = true;

        add_action( 'wp_login', [ $this, 'log_login' ], 20, 2 );
        add_action( 'user_register', [ $this, 'log_user_register' ] );
        add_action( 'profile_update', [ $this, 'log_profile_update' ], 10, 2 );
        add_action( 'deleted_user', [ $this, 'log_user_deleted' ], 10, 3 );
        add_action( 'set_user_role', [ $this, 'log_role_change' ], 10, 3 );
        add_action( 'activated_plugin', [ $this, 'log_plugin_activated' ], 10, 2 );
        add_action( 'deactivated_plugin', [ $this, 'log_plugin_deactivated' ], 10, 2 );
        add_action( 'switch_theme', [ $this, 'log_theme_switch' ], 10, 3 );
        add_action( 'updated_option', [ $this, 'log_option_update' ], 10, 3 );
    }

    public function log( string $event_type, string $message, string $severity = 'info', string $object_type = '', string $object_ref = '', ?int $actor_user_id = null ): void {
        if ( ! TRQ_Core::table_exists( 'trq_audit_log' ) ) {
            return;
        }

        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'trq_audit_log',
            [
                'created_at'    => current_time( 'mysql', true ),
                'event_type'    => substr( $event_type, 0, 100 ),
                'severity'      => in_array( $severity, [ 'info', 'warning', 'critical' ], true ) ? $severity : 'info',
                'actor_user_id' => $actor_user_id ?: get_current_user_id() ?: 0,
                'ip_address'    => TRQ_Core::get_client_ip(),
                'object_type'   => substr( $object_type, 0, 50 ),
                'object_ref'    => substr( $object_ref, 0, 255 ),
                'message'       => $message,
            ],
            [ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
        );
    }

    public function get_logs( int $limit = 50 ): array {
        if ( ! TRQ_Core::table_exists( 'trq_audit_log' ) ) {
            return [];
        }

        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$wpdb->prefix}trq_audit_log` ORDER BY created_at DESC LIMIT %d",
                $limit
            )
        );
    }

    public function get_stats(): array {
        if ( ! TRQ_Core::table_exists( 'trq_audit_log' ) ) {
            return [
                'total_today'    => 0,
                'critical_today' => 0,
            ];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'trq_audit_log';

        return [
            'total_today' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE DATE(created_at)=CURDATE()" ),
            'critical_today' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE severity='critical' AND DATE(created_at)=CURDATE()" ),
        ];
    }

    public function log_login( string $user_login, WP_User $user ): void {
        if ( user_can( $user, 'manage_options' ) ) {
            $current_ip = TRQ_Core::get_client_ip();
            $last_ip    = (string) get_user_meta( $user->ID, 'trq_last_login_ip', true );

            $this->log( 'admin_login', 'Connexion administrateur réussie pour ' . $user_login, 'info', 'user', (string) $user->ID, $user->ID );

            if ( $last_ip && $last_ip !== $current_ip ) {
                $message = 'Connexion administrateur depuis une nouvelle IP pour ' . $user_login . ' : ' . $current_ip . ' (précédente : ' . $last_ip . ')';
                $this->log( 'admin_new_ip', $message, 'warning', 'user', (string) $user->ID, $user->ID );
                TRQ_Core::notify( 'Nouvelle IP administrateur détectée', $message );
            }

            update_user_meta( $user->ID, 'trq_last_login_ip', $current_ip );
            update_user_meta( $user->ID, 'trq_last_login_at', current_time( 'mysql', true ) );
        }
    }

    public function log_user_register( int $user_id ): void {
        $user = get_userdata( $user_id );
        if ( $user ) {
            $this->log( 'user_created', 'Création du compte ' . $user->user_login, 'warning', 'user', (string) $user_id );
        }
    }

    public function log_profile_update( int $user_id, WP_User $old_user_data ): void {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        if ( $user->user_email !== $old_user_data->user_email || $user->display_name !== $old_user_data->display_name ) {
            $this->log( 'user_updated', 'Mise à jour du profil de ' . $user->user_login, 'info', 'user', (string) $user_id );
        }
    }

    public function log_user_deleted( int $user_id, int $reassign, WP_User $user ): void {
        $message = 'Suppression du compte ' . $user->user_login;
        if ( $reassign > 0 ) {
            $message .= ' avec réattribution du contenu à l’utilisateur ' . $reassign;
        }

        $this->log( 'user_deleted', $message, 'warning', 'user', (string) $user_id );
    }

    public function log_role_change( int $user_id, string $role, array $old_roles ): void {
        $this->log(
            'role_changed',
            'Changement de rôle utilisateur vers ' . $role . ' (anciens rôles : ' . implode( ', ', $old_roles ) . ')',
            in_array( 'administrator', $old_roles, true ) || 'administrator' === $role ? 'warning' : 'info',
            'user',
            (string) $user_id
        );
    }

    public function log_plugin_activated( string $plugin, bool $network_wide ): void {
        $message = 'Activation du plugin ' . $plugin;
        if ( $network_wide ) {
            $message .= ' en réseau';
        }

        $this->log( 'plugin_activated', $message, 'warning', 'plugin', $plugin );
    }

    public function log_plugin_deactivated( string $plugin, bool $network_wide ): void {
        $message = 'Désactivation du plugin ' . $plugin;
        if ( $network_wide ) {
            $message .= ' en réseau';
        }

        $this->log( 'plugin_deactivated', $message, 'warning', 'plugin', $plugin );
    }

    public function log_theme_switch( string $new_name, WP_Theme $new_theme, WP_Theme $old_theme ): void {
        $this->log( 'theme_switched', 'Changement de thème : ' . $old_theme->get( 'Name' ) . ' -> ' . $new_name, 'warning', 'theme', $new_theme->get_stylesheet() );
    }

    public function log_option_update( string $option, $old_value, $value ): void {
        if ( ! in_array( $option, self::SENSITIVE_OPTIONS, true ) ) {
            return;
        }

        $severity = in_array( $option, [ 'active_plugins', 'stylesheet', 'template', 'users_can_register', 'default_role' ], true ) ? 'warning' : 'info';
        $old_type = gettype( $old_value );
        $new_type = gettype( $value );
        $this->log( 'option_updated', 'Modification de l’option sensible ' . $option . ' (' . $old_type . ' -> ' . $new_type . ')', $severity, 'option', $option );
    }
}