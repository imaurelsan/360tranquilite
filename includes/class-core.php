<?php
/**
 * Classe principale (singleton) : gestion des réglages, activation, BDD.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRQ_Core {

    private static ?TRQ_Core $instance = null;

    private const REQUIRED_TABLE_KEYS = [
        'trq_login_attempts',
        'trq_blocked_ips',
        'trq_firewall_log',
        'trq_file_checksums',
        'trq_audit_log',
    ];

    private const PROTECTED_OPTION_KEYS = [
        'cloudflare_api_key',
        'cloudflare_api_token',
        'backup_google_drive_client_secret',
        'backup_google_drive_refresh_token',
        'backup_s3_secret_key',
        'toolkit_smtp_pass',
    ];

    /** Réglages par défaut du plugin */
    private static array $defaults = [
        // Firewall
        'firewall_enabled'          => false,
        'firewall_block_sqli'       => false,
        'firewall_block_xss'        => false,
        'firewall_block_traversal'  => false,
        'firewall_block_bad_bots'   => false,
        // Login
        'login_slug'                => '',
        'login_max_attempts'        => 5,
        'login_lockout_minutes'     => 30,
        'disable_user_enum'         => false,
        'login_visual_customization_enabled' => false,
        'login_custom_logo_url'     => '',
        'login_logo_width'          => 120,
        'login_logo_height'         => 120,
        'login_logo_link_url'       => '',
        'login_logo_title'          => '',
        'login_bg_color'            => '#f0f2f5',
        'login_form_bg_color'       => '#ffffff',
        'login_form_border_color'   => '#dcdcde',
        'login_form_text_color'     => '#1d2327',
        'login_input_bg_color'      => '#ffffff',
        'login_input_text_color'    => '#1d2327',
        'login_input_border_color'  => '#8c8f94',
        'login_button_bg_color'     => '#2271b1',
        'login_button_text_color'   => '#ffffff',
        'login_button_hover_bg_color' => '#135e96',
        'login_link_color'          => '#2271b1',
        'login_link_hover_color'    => '#135e96',
        'login_message_bg_color'    => '#ffffff',
        'login_message_text_color'  => '#1d2327',
        'login_form_border_radius'  => 8,
        'login_form_shadow'         => false,
        'login_custom_css'          => '',
        // 2FA
        'two_factor_enabled'        => false,
        // Cloudflare
        'cloudflare_enabled'        => false,
        'cloudflare_auth_mode'      => 'token',
        'cloudflare_api_key'        => '',
        'cloudflare_api_token'      => '',
        'cloudflare_email'          => '',
        'cloudflare_zone_id'        => '',
        'cloudflare_sync_blocks'    => false,
        // Sauvegardes
        'backup_enabled'            => false,
        'backup_mode'               => 'full',
        'backup_frequency'          => 'daily',
        'backup_time'               => '02:00',
        'backup_day_of_week'        => 1,
        'backup_day_of_month'       => 1,
        'backup_retention_count'    => 5,
        'backup_destination_local'  => false,
        'backup_local_dir'          => '360tranquilite-backups',
        'backup_destination_google_drive' => false,
        'backup_google_drive_client_id' => '',
        'backup_google_drive_client_secret' => '',
        'backup_google_drive_refresh_token' => '',
        'backup_google_drive_folder_id' => '',
        'backup_google_drive_account_email' => '',
        'backup_google_drive_connected_at' => '',
        'backup_destination_s3'      => false,
        'backup_s3_endpoint'         => '',
        'backup_s3_region'           => 'us-east-1',
        'backup_s3_bucket'           => '',
        'backup_s3_access_key'       => '',
        'backup_s3_secret_key'       => '',
        'backup_s3_prefix'           => '',
        'backup_s3_path_style'       => true,
        'backup_include_files'      => false,
        'backup_include_database'   => false,
        'backup_exclude_cache_dirs' => false,
        // Sécurité générale
        'security_headers_enabled'  => false,
        'disable_xmlrpc'            => false,
        'hide_wp_version'           => false,
        'disable_file_edit'         => false,
        'disable_file_mods'         => false,
        'disable_application_passwords' => false,
        // Surveillance fichiers
        'file_monitor_enabled'      => false,
        'file_monitor_scan_plugins' => false,
        'file_monitor_scan_themes'  => false,
        'file_monitor_scan_muplugins' => false,
        'file_monitor_scan_uploads' => false,
        'file_monitor_quarantine_enabled' => false,
        'db_scan_enabled'           => false,
        'db_scan_max_rows'          => 200,
        'admin_review_enabled'      => false,
        'uploads_hardening_enabled' => false,
        'core_checksum_enabled'     => false,
        'definitions_auto_update_enabled' => false,
        'definitions_update_url'    => '',
        // Audit
        'audit_log_enabled'         => false,
        // Anti-spam
        'antispam_enabled'          => false,
        'antispam_form_protection_enabled' => false,
        // Mises à jour automatiques
        'updates_auto_enabled'      => false,
        'updates_core_mode'         => 'minor',
        'updates_plugins_auto'      => false,
        'updates_themes_auto'       => false,
        'updates_translations_auto' => false,
        'updates_window_enabled'    => false,
        'updates_window_start'      => '02:00',
        'updates_window_duration_hours' => 3,
        'updates_notify_mode'       => 'all',
        'updates_check_compat'      => false,
        'updates_pre_update_backup_enabled' => false,
        'updates_auto_rollback_enabled' => false,
        'updates_post_update_healthcheck_enabled' => false,
        'updates_healthcheck_url' => '',
        'updates_healthcheck_timeout' => 10,
        'updates_healthcheck_retries' => 5,
        'updates_healthcheck_interval_minutes' => 3,
        // Nettoyage medias
        'media_cleanup_enabled'      => false,
        'media_cleanup_auto_enabled' => false,
        'media_cleanup_dry_run'      => true,
        'media_cleanup_min_age_days' => 14,
        'media_cleanup_protected_keywords' => 'logo,icon,favicon,placeholder,banner,default',
        // Boite a outils dev
        'toolkit_enabled'            => false,
        'toolkit_allow_svg'          => false,
        'toolkit_allow_avif'         => false,
        'toolkit_duplicate_content'  => false,
        'toolkit_cpt_builder_enabled' => false,
        'toolkit_cpts_json'          => '',
        'toolkit_taxonomies_json'    => '',
        'toolkit_hide_admin_notices' => false,
        'toolkit_disable_dashboard_widgets' => false,
        'toolkit_admin_columns_enabled' => false,
        'toolkit_admin_columns_post_types' => 'post,page',
        'toolkit_admin_column_id'    => false,
        'toolkit_admin_column_thumbnail' => false,
        'toolkit_admin_column_slug'  => false,
        'toolkit_admin_column_modified' => false,
        'toolkit_media_replacer_enabled' => false,
        'toolkit_login_redirect_enabled' => false,
        'toolkit_login_redirect_url' => '',
        'toolkit_logout_redirect_enabled' => false,
        'toolkit_logout_redirect_url' => '',
        'toolkit_smtp_enabled'       => false,
        'toolkit_smtp_host'          => '',
        'toolkit_smtp_port'          => 587,
        'toolkit_smtp_secure'        => 'tls',
        'toolkit_smtp_auth'          => false,
        'toolkit_smtp_user'          => '',
        'toolkit_smtp_pass'          => '',
        'toolkit_smtp_from_email'    => '',
        'toolkit_smtp_from_name'     => '',
        'toolkit_maintenance_mode'   => false,
        'toolkit_maintenance_message' => 'Site en maintenance, revenez bientot.',
        'toolkit_admin_css'          => '',
        'toolkit_front_css'          => '',
        'toolkit_head_code'          => '',
        'toolkit_footer_code'        => '',
        'toolkit_robots_txt'         => '',
        'toolkit_ads_txt'            => '',
        'toolkit_app_ads_txt'        => '',
        'toolkit_revisions_limit'    => 10,
        'toolkit_heartbeat_mode'     => 'default',
        'toolkit_email_obfuscation'  => false,
        // Langue plugin
        'plugin_language'            => 'auto',
        // Notifications
        'notify_enabled'            => true,
        'notify_email'              => '',
        'data_retention_days'       => 30,
    ];

    private array $settings = [];

    private function __construct() {}

    public static function get_instance(): TRQ_Core {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Initialisation des modules en fonction des réglages */
    public function init(): void {
        self::ensure_database_schema();
        $this->settings = $this->load_settings();
        add_filter( 'plugin_locale', [ $this, 'filter_plugin_locale' ], 10, 2 );
        TRQ_Localization::get_instance()->init();

        add_action( 'trq_cleanup_data', [ __CLASS__, 'cleanup_old_data' ] );

        // Appliquer disable_file_edit dès que possible
        if ( $this->get( 'disable_file_edit' ) && ! defined( 'DISALLOW_FILE_EDIT' ) ) {
            define( 'DISALLOW_FILE_EDIT', true );
        }

        if ( $this->get( 'disable_file_mods' ) && ! defined( 'DISALLOW_FILE_MODS' ) ) {
            define( 'DISALLOW_FILE_MODS', true );
        }

        // Désactiver XML-RPC
        if ( $this->get( 'disable_xmlrpc' ) ) {
            add_filter( 'xmlrpc_enabled', '__return_false' );
            add_filter( 'xmlrpc_methods', '__return_empty_array' );
            add_filter( 'wp_headers', [ $this, 'remove_xmlrpc_pingback_header' ] );
            add_action( 'init', [ $this, 'block_xmlrpc_request' ], 0 );
        }

        // Masquer la version WordPress
        if ( $this->get( 'hide_wp_version' ) ) {
            remove_action( 'wp_head', 'wp_generator' );
            add_filter( 'the_generator', '__return_empty_string' );
        }

        if ( $this->get( 'disable_application_passwords' ) ) {
            add_filter( 'wp_is_application_passwords_available', '__return_false' );
        }

        if ( $this->get( 'audit_log_enabled' ) ) {
            TRQ_Audit_Log::get_instance()->init();
        }

        TRQ_Threat_Definitions::get_instance()->init();

        // Charger les modules actifs
        if ( $this->get( 'firewall_enabled' ) ) {
            TRQ_Firewall::get_instance()->init();
        }

        TRQ_Login_Protection::get_instance()->init();

        if ( $this->get( 'two_factor_enabled' ) ) {
            TRQ_Two_Factor::get_instance()->init();
        }

        if ( $this->get( 'cloudflare_enabled' ) ) {
            TRQ_Cloudflare::get_instance()->init();
        }

        TRQ_Backup_Manager::get_instance()->init();

        if ( $this->get( 'security_headers_enabled' ) ) {
            TRQ_Security_Headers::get_instance()->init();
        }

        if ( $this->get( 'file_monitor_enabled' ) ) {
            TRQ_File_Monitor::get_instance()->init();
        }

        if ( $this->get( 'core_checksum_enabled' ) || $this->get( 'db_scan_enabled' ) || $this->get( 'admin_review_enabled' ) || $this->get( 'uploads_hardening_enabled' ) ) {
            TRQ_System_Scanner::get_instance()->init();
        }

        if ( $this->get( 'antispam_enabled' ) ) {
            TRQ_Antispam::get_instance()->init();
        }

        TRQ_Auto_Updates::get_instance()->init();
        TRQ_Media_Cleanup::get_instance()->init();
        TRQ_Dev_Toolkit::get_instance()->init();

        // Interface admin
        if ( is_admin() ) {
            TRQ_Admin::get_instance()->init();
        }
    }

    // -----------------------------------------------------------------------
    // Gestion des réglages
    // -----------------------------------------------------------------------

    private function load_settings(): array {
        $saved = get_option( 'trq_settings', [] );
        if ( ! is_array( $saved ) ) {
            $saved = [];
        }

        $needs_migration = false;
        foreach ( self::PROTECTED_OPTION_KEYS as $key ) {
            if ( ! empty( $saved[ $key ] ) && is_string( $saved[ $key ] ) && 0 !== strpos( $saved[ $key ], 'trqenc:' ) ) {
                $needs_migration = true;
                break;
            }
        }

        $saved = $this->decrypt_protected_settings( $saved );

        if ( $needs_migration ) {
            update_option( 'trq_settings', $this->encrypt_protected_settings( array_merge( self::$defaults, $saved ) ) );
        }

        return array_merge( self::$defaults, $saved );
    }

    public function get( string $key, $fallback = null ) {
        return $this->settings[ $key ] ?? $fallback;
    }

    public function get_all(): array {
        return $this->settings;
    }

    public static function get_default_settings(): array {
        return self::$defaults;
    }

    public function update( array $new_values ): void {
        // Fusionner et sauvegarder
        $this->settings = array_merge( $this->settings, $new_values );
        update_option( 'trq_settings', $this->encrypt_protected_settings( $this->settings ) );
    }

    private function encrypt_protected_settings( array $settings ): array {
        foreach ( self::PROTECTED_OPTION_KEYS as $key ) {
            if ( ! isset( $settings[ $key ] ) || ! is_string( $settings[ $key ] ) || '' === $settings[ $key ] ) {
                continue;
            }

            $encrypted = $this->encrypt_secret( $settings[ $key ] );
            if ( null !== $encrypted ) {
                $settings[ $key ] = $encrypted;
            }
        }

        return $settings;
    }

    private function decrypt_protected_settings( array $settings ): array {
        foreach ( self::PROTECTED_OPTION_KEYS as $key ) {
            if ( ! isset( $settings[ $key ] ) || ! is_string( $settings[ $key ] ) || '' === $settings[ $key ] ) {
                continue;
            }

            $decrypted = $this->decrypt_secret( $settings[ $key ] );
            if ( null !== $decrypted ) {
                $settings[ $key ] = $decrypted;
            }
        }

        return $settings;
    }

    private function encrypt_secret( string $value ): ?string {
        if ( 0 === strpos( $value, 'trqenc:' ) ) {
            return $value;
        }

        if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'random_bytes' ) ) {
            return null;
        }

        $key = hash( 'sha256', wp_salt( 'auth' ), true );
        $iv  = random_bytes( 16 );

        $ciphertext = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
        if ( false === $ciphertext ) {
            return null;
        }

        return 'trqenc:' . base64_encode( $iv . $ciphertext );
    }

    private function decrypt_secret( string $value ): ?string {
        if ( 0 !== strpos( $value, 'trqenc:' ) ) {
            return null;
        }

        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return null;
        }

        $raw = base64_decode( substr( $value, 7 ), true );
        if ( false === $raw || strlen( $raw ) <= 16 ) {
            return null;
        }

        $key        = hash( 'sha256', wp_salt( 'auth' ), true );
        $iv         = substr( $raw, 0, 16 );
        $ciphertext = substr( $raw, 16 );
        $plaintext  = openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

        return false === $plaintext ? null : $plaintext;
    }

    // -----------------------------------------------------------------------
    // Activation
    // -----------------------------------------------------------------------

    public static function activate(): void {
        self::create_tables();
        self::schedule_events();
        // Sauvegarder les réglages par défaut si pas encore définis
        if ( ! get_option( 'trq_settings' ) ) {
            $defaults            = self::$defaults;
            $defaults['notify_email'] = get_option( 'admin_email' );
            update_option( 'trq_settings', self::get_instance()->encrypt_protected_settings( $defaults ) );
        }
        update_option( 'trq_db_version', TRQ_DB_VERSION );
        // Forcer la regénération des règles de réécriture
        set_transient( 'trq_flush_rewrite', true, 60 );
    }

    // -----------------------------------------------------------------------
    // Désactivation
    // -----------------------------------------------------------------------

    public static function deactivate(): void {
        self::clear_events();
        flush_rewrite_rules();
    }

    // -----------------------------------------------------------------------
    // Désinstallation
    // -----------------------------------------------------------------------

    public static function uninstall(): void {
        global $wpdb;

        self::clear_events();

        $tables = [
            $wpdb->prefix . 'trq_login_attempts',
            $wpdb->prefix . 'trq_blocked_ips',
            $wpdb->prefix . 'trq_firewall_log',
            $wpdb->prefix . 'trq_file_checksums',
            $wpdb->prefix . 'trq_audit_log',
        ];
        foreach ( $tables as $table ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL
            $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
        }

        delete_option( 'trq_settings' );
        delete_option( 'trq_db_version' );
        delete_option( 'trq_last_scan_report' );
        delete_option( 'trq_last_system_scan_report' );
        delete_option( 'trq_last_backup_report' );
        delete_option( 'trq_last_restore_report' );
        delete_option( 'trq_last_auto_updates_report' );
        delete_option( 'trq_backup_manifest' );
        delete_option( 'trq_backup_schedule_signature' );
        delete_option( 'trq_quarantined_files' );
        delete_option( 'trq_uploads_hardening_status' );
        delete_option( 'trq_last_media_cleanup_report' );
        delete_option( 'trq_threat_definitions_cache' );
        delete_option( 'trq_threat_definitions_status' );

        // Supprimer les méta utilisateurs 2FA
        $wpdb->delete( $wpdb->usermeta, [ 'meta_key' => 'trq_2fa_secret'  ] );
        $wpdb->delete( $wpdb->usermeta, [ 'meta_key' => 'trq_2fa_enabled' ] );
    }

    // -----------------------------------------------------------------------
    // Création des tables BDD
    // -----------------------------------------------------------------------

    private static function create_tables(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $p       = $wpdb->prefix;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( "CREATE TABLE {$p}trq_login_attempts (
            id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            ip_address   VARCHAR(45)  NOT NULL,
            username     VARCHAR(255) NOT NULL,
            attempted_at DATETIME     NOT NULL,
            success      TINYINT(1)   NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY ip_address (ip_address),
            KEY attempted_at (attempted_at)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$p}trq_blocked_ips (
            id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            ip_address   VARCHAR(45)  NOT NULL,
            reason       VARCHAR(255) NOT NULL,
            blocked_at   DATETIME     NOT NULL,
            expires_at   DATETIME     DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY ip_address (ip_address)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$p}trq_firewall_log (
            id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            ip_address   VARCHAR(45)   NOT NULL,
            request_uri  VARCHAR(2000) NOT NULL,
            threat_type  VARCHAR(100)  NOT NULL,
            blocked_at   DATETIME      NOT NULL,
            PRIMARY KEY  (id),
            KEY ip_address (ip_address),
            KEY blocked_at (blocked_at)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$p}trq_file_checksums (
            id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            file_path    VARCHAR(500) NOT NULL,
            checksum     VARCHAR(64)  NOT NULL,
            last_checked DATETIME     NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY file_path (file_path)
        ) $charset;" );

        dbDelta( "CREATE TABLE {$p}trq_audit_log (
            id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at    DATETIME      NOT NULL,
            event_type    VARCHAR(100)  NOT NULL,
            severity      VARCHAR(20)   NOT NULL DEFAULT 'info',
            actor_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            ip_address    VARCHAR(45)   NOT NULL,
            object_type   VARCHAR(50)   DEFAULT '',
            object_ref    VARCHAR(255)  DEFAULT '',
            message       TEXT          NOT NULL,
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY event_type (event_type),
            KEY actor_user_id (actor_user_id)
        ) $charset;" );

        update_option( 'trq_db_version', TRQ_DB_VERSION );
    }

    public static function ensure_database_schema(): void {
        if ( self::has_required_tables() ) {
            return;
        }

        self::create_tables();
    }

    public static function has_required_tables(): bool {
        foreach ( self::REQUIRED_TABLE_KEYS as $table_key ) {
            if ( ! self::table_exists( $table_key ) ) {
                return false;
            }
        }

        return true;
    }

    public static function table_exists( string $table_key ): bool {
        global $wpdb;

        $table_name = $wpdb->prefix . ltrim( $table_key, '_' );
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

        return $found === $table_name;
    }

    private static function schedule_events(): void {
        if ( ! wp_next_scheduled( 'trq_cleanup_data' ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'trq_cleanup_data' );
        }
        if ( ! wp_next_scheduled( 'trq_file_scan' ) ) {
            wp_schedule_event( time() + ( 2 * HOUR_IN_SECONDS ), 'daily', 'trq_file_scan' );
        }
        if ( ! wp_next_scheduled( 'trq_system_scan' ) ) {
            wp_schedule_event( time() + ( 3 * HOUR_IN_SECONDS ), 'daily', 'trq_system_scan' );
        }
    }

    private static function clear_events(): void {
        wp_clear_scheduled_hook( 'trq_cleanup_data' );
        wp_clear_scheduled_hook( 'trq_file_scan' );
        wp_clear_scheduled_hook( 'trq_system_scan' );
        wp_clear_scheduled_hook( 'trq_update_definitions' );
        wp_clear_scheduled_hook( 'trq_run_backup' );
        wp_clear_scheduled_hook( 'trq_media_cleanup_weekly_event' );
    }

    public static function cleanup_old_data(): void {
        global $wpdb;

        $settings        = self::get_instance()->load_settings();
        $retention_days  = max( 7, (int) ( $settings['data_retention_days'] ?? 30 ) );
        $attempts_table  = $wpdb->prefix . 'trq_login_attempts';
        $firewall_table  = $wpdb->prefix . 'trq_firewall_log';
        $blocked_table   = $wpdb->prefix . 'trq_blocked_ips';
        $audit_table     = $wpdb->prefix . 'trq_audit_log';

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$attempts_table}` WHERE attempted_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
                $retention_days
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$firewall_table}` WHERE blocked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
                $retention_days
            )
        );

        $wpdb->query(
            "DELETE FROM `{$blocked_table}` WHERE expires_at IS NOT NULL AND expires_at < UTC_TIMESTAMP()"
        );

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$audit_table}` WHERE created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)",
                $retention_days
            )
        );

        $quarantine_map = get_option( 'trq_quarantined_files', [] );
        if ( is_array( $quarantine_map ) && ! empty( $quarantine_map ) ) {
            $quarantine_map = array_filter(
                $quarantine_map,
                static function ( $item ): bool {
                    return is_array( $item ) && ! empty( $item['quarantine_path'] ) && file_exists( $item['quarantine_path'] );
                }
            );
            update_option( 'trq_quarantined_files', $quarantine_map, false );
        }
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /** Retourne l'IP réelle du visiteur (compatible Cloudflare) */
    public static function get_client_ip(): string {
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare (priorité max)
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];
        foreach ( $headers as $h ) {
            if ( ! empty( $_SERVER[ $h ] ) ) {
                $ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ) )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    /** Envoi d'une notification email à l'admin */
    public static function notify( string $subject, string $message ): void {
        $core = self::get_instance();
        if ( ! (bool) $core->get( 'notify_enabled', true ) ) {
            return;
        }

        $email = trim( (string) $core->get( 'notify_email', '' ) );
        if ( '' === $email ) {
            return;
        }

        wp_mail( $email, '[360 Tranquillité] ' . $subject, $message );
    }

    public function filter_plugin_locale( string $locale, string $domain ): string {
        if ( '360tranquilite' !== $domain ) {
            return $locale;
        }

        $selected = (string) $this->get( 'plugin_language', 'auto' );
        if ( in_array( $selected, [ 'fr_FR', 'en_US' ], true ) ) {
            return $selected;
        }

        return $locale;
    }

    public function block_xmlrpc_request(): void {
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
            status_header( 403 );
            exit;
        }
    }

    public function remove_xmlrpc_pingback_header( array $headers ): array {
        unset( $headers['X-Pingback'] );
        return $headers;
    }
}
