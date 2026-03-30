<?php
/**
 * Scan système : base de données, comptes admin, tâches CRON et uploads.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRQ_System_Scanner {

    private static ?TRQ_System_Scanner $instance = null;

    private bool $booted = false;

    private const LARGE_AUTOLOAD_OPTION_BYTES = 200000;
    private const INCIDENT_REPORT_MAX_ITEMS = 200;

    private const UPLOADS_HTACCESS = "<FilesMatch \"\\.(php|php3|php4|php5|php7|phtml|phar|cgi|pl|py|sh)$\">\nRequire all denied\n</FilesMatch>\nOptions -ExecCGI\n";

    private const UPLOADS_WEB_CONFIG = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <handlers accessPolicy=\"Read, Script\" />\n    <security>\n      <requestFiltering>\n        <fileExtensions>\n          <add fileExtension=\".php\" allowed=\"false\" />\n          <add fileExtension=\".phtml\" allowed=\"false\" />\n          <add fileExtension=\".phar\" allowed=\"false\" />\n        </fileExtensions>\n      </requestFiltering>\n    </security>\n  </system.webServer>\n</configuration>\n";

    private function __construct() {}

    public static function get_instance(): TRQ_System_Scanner {
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

        add_action( 'trq_system_scan', [ $this, 'run_full_scan' ] );

        if ( TRQ_Core::get_instance()->get( 'uploads_hardening_enabled' ) ) {
            add_filter( 'upload_mimes', [ $this, 'filter_upload_mimes' ], 99 );
            add_filter( 'wp_handle_upload_prefilter', [ $this, 'validate_upload' ] );
            $this->ensure_uploads_hardening_files();
        }
    }

    private function get_definitions(): array {
        return TRQ_Threat_Definitions::get_instance()->get_system_scanner_config();
    }

    public function run_full_scan(): array {
        $report = [
            'generated_at' => current_time( 'mysql', true ),
            'core'         => $this->verify_core_checksums(),
            'database'     => $this->scan_database(),
            'admins'       => $this->inspect_admin_accounts(),
            'cron'         => $this->inspect_cron_events(),
            'uploads'      => $this->get_uploads_hardening_status(),
        ];

        update_option( 'trq_last_system_scan_report', $report, false );

        $total_findings = count( $report['core']['findings'] ) + count( $report['database']['findings'] ) + count( $report['admins']['findings'] ) + count( $report['cron']['findings'] );
        if ( $total_findings > 0 ) {
            TRQ_Core::notify(
                'Scan système : ' . $total_findings . ' signal(s) détecté(s)',
                "Le scan système a détecté des éléments à vérifier dans le core WordPress, la base de données, les comptes administrateurs ou les tâches planifiées. Consultez l’onglet Avancé."
            );

            if ( TRQ_Core::get_instance()->get( 'audit_log_enabled' ) ) {
                TRQ_Audit_Log::get_instance()->log(
                    'system_scan_alert',
                    'Le scan système a détecté ' . $total_findings . ' signal(s) à vérifier.',
                    'warning',
                    'scan',
                    'system_scanner'
                );
            }
        }

        return $report;
    }

    public function get_last_report(): array {
        $report = get_option( 'trq_last_system_scan_report', [] );

        if ( ! is_array( $report ) ) {
            return [ 'generated_at' => '', 'core' => [ 'findings' => [] ], 'database' => [ 'findings' => [] ], 'admins' => [ 'findings' => [] ], 'cron' => [ 'findings' => [] ], 'uploads' => [] ];
        }

        return array_merge(
            [ 'generated_at' => '', 'core' => [ 'findings' => [] ], 'database' => [ 'findings' => [] ], 'admins' => [ 'findings' => [] ], 'cron' => [ 'findings' => [] ], 'uploads' => [] ],
            $report
        );
    }

    public function get_summary(): array {
        $report = $this->get_last_report();

        return [
            'core_findings'  => count( $report['core']['findings'] ?? [] ),
            'db_findings'    => count( $report['database']['findings'] ?? [] ),
            'admin_findings' => count( $report['admins']['findings'] ?? [] ),
            'cron_findings'  => count( $report['cron']['findings'] ?? [] ),
        ];
    }

    public function build_incident_report(): array {
        $file_scan   = TRQ_File_Monitor::get_instance()->get_last_report();
        $system_scan = $this->get_last_report();

        if ( is_array( $file_scan['changes'] ?? null ) ) {
            $file_scan['changes'] = array_slice( $file_scan['changes'], 0, self::INCIDENT_REPORT_MAX_ITEMS );
        }

        if ( is_array( $file_scan['findings'] ?? null ) ) {
            $file_scan['findings'] = array_slice( $file_scan['findings'], 0, self::INCIDENT_REPORT_MAX_ITEMS );
        }

        foreach ( [ 'core', 'database', 'admins', 'cron' ] as $section ) {
            if ( is_array( $system_scan[ $section ]['findings'] ?? null ) ) {
                $system_scan[ $section ]['findings'] = array_slice( $system_scan[ $section ]['findings'], 0, self::INCIDENT_REPORT_MAX_ITEMS );
            }
        }

        return [
            'generated_at'   => current_time( 'mysql', true ),
            'plugin_version' => defined( 'TRQ_VERSION' ) ? TRQ_VERSION : 'unknown',
            'site_url'       => home_url(),
            'wp_version'     => get_bloginfo( 'version' ),
            'file_scan'      => $file_scan,
            'system_scan'    => $system_scan,
            'audit_log'      => TRQ_Audit_Log::get_instance()->get_logs( 100 ),
            'settings'       => [
                'firewall_enabled' => (bool) TRQ_Core::get_instance()->get( 'firewall_enabled' ),
                'two_factor_enabled' => (bool) TRQ_Core::get_instance()->get( 'two_factor_enabled' ),
                'cloudflare_enabled' => (bool) TRQ_Core::get_instance()->get( 'cloudflare_enabled' ),
                'security_headers_enabled' => (bool) TRQ_Core::get_instance()->get( 'security_headers_enabled' ),
                'file_monitor_enabled' => (bool) TRQ_Core::get_instance()->get( 'file_monitor_enabled' ),
                'db_scan_enabled' => (bool) TRQ_Core::get_instance()->get( 'db_scan_enabled' ),
            ],
        ];
    }

    public function unschedule_hook( string $hook ): array {
        $hook = sanitize_key( $hook );
        if ( '' === $hook ) {
            return [ 'success' => false, 'message' => 'Hook CRON invalide.' ];
        }

        $cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : [];
        if ( ! is_array( $cron ) ) {
            return [ 'success' => false, 'message' => 'Impossible de lire la file CRON.' ];
        }

        $removed = 0;

        foreach ( $cron as $timestamp => $hooks ) {
            if ( empty( $hooks[ $hook ] ) || ! is_array( $hooks[ $hook ] ) ) {
                continue;
            }

            foreach ( $hooks[ $hook ] as $sig => $event ) {
                $args = is_array( $event['args'] ?? null ) ? $event['args'] : [];
                if ( wp_unschedule_event( (int) $timestamp, $hook, $args ) ) {
                    $removed++;
                }
            }
        }

        if ( $removed > 0 && TRQ_Core::get_instance()->get( 'audit_log_enabled' ) ) {
            TRQ_Audit_Log::get_instance()->log( 'cron_unscheduled', 'Suppression manuelle de ' . $removed . ' occurrence(s) du hook ' . $hook, 'warning', 'cron', $hook );
        }

        return [
            'success' => $removed > 0,
            'message' => $removed > 0 ? 'Occurrences du hook CRON supprimées : ' . $removed : 'Aucune occurrence active trouvée pour ce hook.',
        ];
    }

    public function filter_upload_mimes( array $mimes ): array {
        unset( $mimes['php'], $mimes['php3'], $mimes['php4'], $mimes['php5'], $mimes['phtml'], $mimes['phar'], $mimes['exe'], $mimes['js'], $mimes['sh'] );
        return $mimes;
    }

    public function validate_upload( array $file ): array {
        if ( $this->should_bypass_admin_package_upload_validation() ) {
            return $file;
        }

        $name = strtolower( (string) ( $file['name'] ?? '' ) );
        if ( preg_match( '/\.(php|php3|php4|php5|php7|phtml|phar|exe|cgi|pl|sh|js)$/i', $name ) ) {
            $file['error'] = __( 'Ce type de fichier est bloqué par 360 Tranquillité.', '360tranquilite' );
        }

        return $file;
    }

    private function should_bypass_admin_package_upload_validation(): bool {
        if ( ! is_admin() || ! is_user_logged_in() ) {
            return false;
        }

        if ( ! current_user_can( 'install_plugins' ) && ! current_user_can( 'install_themes' ) ) {
            return false;
        }

        $script = sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ?? '' ) );
        $action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ?? '' ) );

        return 'update.php' === basename( $script )
            && in_array( $action, [ 'upload-plugin', 'upload-theme', 'install-plugin', 'install-theme' ], true );
    }

    public function ensure_uploads_hardening_files(): array {
        $upload_dir = wp_get_upload_dir();
        $status = [
            'upload_dir' => $upload_dir['basedir'] ?? '',
            'htaccess'   => false,
            'web_config' => false,
            'index_php'  => false,
            'writable'   => false,
        ];

        if ( empty( $upload_dir['basedir'] ) || ! is_dir( $upload_dir['basedir'] ) ) {
            update_option( 'trq_uploads_hardening_status', $status, false );
            return $status;
        }

        $base_dir = $upload_dir['basedir'];
        $status['writable'] = is_writable( $base_dir );

        $htaccess = trailingslashit( $base_dir ) . '.htaccess';
        $web_config = trailingslashit( $base_dir ) . 'web.config';
        $index_php = trailingslashit( $base_dir ) . 'index.php';

        $filesystem = $this->get_filesystem();

        if ( $filesystem && ! $filesystem->exists( $htaccess ) && $status['writable'] ) {
            $filesystem->put_contents( $htaccess, self::UPLOADS_HTACCESS, FS_CHMOD_FILE );
        }
        if ( $filesystem && ! $filesystem->exists( $web_config ) && $status['writable'] ) {
            $filesystem->put_contents( $web_config, self::UPLOADS_WEB_CONFIG, FS_CHMOD_FILE );
        }
        if ( $filesystem && ! $filesystem->exists( $index_php ) && $status['writable'] ) {
            $filesystem->put_contents( $index_php, "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
        }

        $status['htaccess'] = file_exists( $htaccess );
        $status['web_config'] = file_exists( $web_config );
        $status['index_php'] = file_exists( $index_php );

        update_option( 'trq_uploads_hardening_status', $status, false );

        return $status;
    }

    private function get_filesystem() {
        global $wp_filesystem;

        if ( $wp_filesystem ) {
            return $wp_filesystem;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        if ( ! WP_Filesystem() ) {
            return null;
        }

        return $wp_filesystem;
    }

    public function get_uploads_hardening_status(): array {
        $status = get_option( 'trq_uploads_hardening_status', [] );
        if ( ! is_array( $status ) || empty( $status['upload_dir'] ) ) {
            return $this->ensure_uploads_hardening_files();
        }

        return $status;
    }

    private function verify_core_checksums(): array {
        if ( ! TRQ_Core::get_instance()->get( 'core_checksum_enabled' ) ) {
            return [ 'status' => 'disabled', 'findings' => [] ];
        }

        $version = get_bloginfo( 'version' );
        $locale  = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
        $url     = add_query_arg(
            [
                'version' => $version,
                'locale'  => $locale,
            ],
            'https://api.wordpress.org/core/checksums/1.0/'
        );

        $response = wp_remote_get( $url, [ 'timeout' => 20 ] );
        if ( is_wp_error( $response ) ) {
            return [
                'status'   => 'error',
                'message'  => $response->get_error_message(),
                'findings' => [],
            ];
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $checksums = $body['checksums'] ?? [];
        if ( empty( $checksums ) || ! is_array( $checksums ) ) {
            return [
                'status'   => 'error',
                'message'  => 'Checksums WordPress.org indisponibles pour cette version.',
                'findings' => [],
            ];
        }

        $findings = [];
        foreach ( $checksums as $relative_path => $expected ) {
            $full_path = ABSPATH . ltrim( str_replace( '\\', '/', $relative_path ), '/' );
            if ( ! file_exists( $full_path ) ) {
                $findings[] = [
                    'path'     => $relative_path,
                    'type'     => 'missing_core_file',
                    'severity' => 'warning',
                    'message'  => 'Fichier du core absent par rapport aux checksums officiels.',
                ];
                continue;
            }

            $actual = md5_file( $full_path );
            if ( ! is_string( $actual ) || ! hash_equals( strtolower( $expected ), strtolower( $actual ) ) ) {
                $findings[] = [
                    'path'     => $relative_path,
                    'type'     => 'core_checksum_mismatch',
                    'severity' => 'critical',
                    'message'  => 'Le fichier du core ne correspond pas au checksum officiel WordPress.org.',
                ];
            }
        }

        return [
            'status'   => 'ok',
            'message'  => 'Checksums officiels récupérés pour WordPress ' . $version,
            'findings' => $findings,
        ];
    }

    private function scan_database(): array {
        global $wpdb;

        $max_rows = max( 50, min( 1000, (int) TRQ_Core::get_instance()->get( 'db_scan_max_rows', 200 ) ) );
        $findings = [];

        $sources = [
            [
                'label' => 'options',
                'query' => "SELECT option_name AS item_key, option_value AS content FROM `{$wpdb->options}` ORDER BY autoload DESC, option_id DESC LIMIT %d",
            ],
            [
                'label' => 'posts',
                'query' => "SELECT ID AS item_key, post_content AS content FROM `{$wpdb->posts}` WHERE post_status NOT IN ('trash','auto-draft') ORDER BY ID DESC LIMIT %d",
            ],
            [
                'label' => 'postmeta',
                'query' => "SELECT meta_id AS item_key, meta_value AS content FROM `{$wpdb->postmeta}` ORDER BY meta_id DESC LIMIT %d",
            ],
            [
                'label' => 'comments',
                'query' => "SELECT comment_ID AS item_key, comment_content AS content FROM `{$wpdb->comments}` ORDER BY comment_ID DESC LIMIT %d",
            ],
        ];

        foreach ( $sources as $source ) {
            $rows = $wpdb->get_results( $wpdb->prepare( $source['query'], $max_rows ), ARRAY_A );
            $definitions = $this->get_definitions();
            $regexes = isset( $definitions['suspicious_regexes'] ) && is_array( $definitions['suspicious_regexes'] ) ? $definitions['suspicious_regexes'] : [];
            foreach ( $rows as $row ) {
                $content = is_string( $row['content'] ) ? $row['content'] : maybe_serialize( $row['content'] );
                foreach ( $regexes as $type => $regex ) {
                    if ( preg_match( $regex, $content ) ) {
                        $findings[] = [
                            'scope'    => $source['label'],
                            'item_key' => (string) $row['item_key'],
                            'type'     => $type,
                            'severity' => 'warning',
                            'preview'  => $this->truncate_preview( wp_strip_all_tags( $content ) ),
                        ];
                        break;
                    }
                }
            }
        }

        $autoload_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, LENGTH(option_value) AS size_bytes FROM `{$wpdb->options}` WHERE autoload = %s ORDER BY LENGTH(option_value) DESC LIMIT %d",
                'yes',
                20
            ),
            ARRAY_A
        );

        foreach ( $autoload_rows as $row ) {
            $size_bytes = (int) ( $row['size_bytes'] ?? 0 );
            if ( $size_bytes >= self::LARGE_AUTOLOAD_OPTION_BYTES ) {
                $findings[] = [
                    'scope'    => 'options',
                    'item_key' => (string) $row['option_name'],
                    'type'     => 'oversized_autoload_option',
                    'severity' => 'warning',
                    'preview'  => 'Option autoload très volumineuse : ' . size_format( $size_bytes ),
                ];
            }
        }

        return [
            'rows_scanned' => $max_rows,
            'findings'     => $findings,
        ];
    }

    private function inspect_admin_accounts(): array {
        $findings = [];
        $admins = get_users( [
            'role__in' => [ 'administrator' ],
            'fields'   => [ 'ID', 'user_login', 'user_email', 'user_registered' ],
        ] );
        $definitions = $this->get_definitions();
        $suspicious_user_regex = is_string( $definitions['suspicious_user_regex'] ?? null ) ? $definitions['suspicious_user_regex'] : '';

        foreach ( $admins as $admin ) {
            if ( '' !== $suspicious_user_regex && preg_match( $suspicious_user_regex, $admin->user_login ) ) {
                $findings[] = [
                    'user'     => $admin->user_login,
                    'type'     => 'suspicious_login_name',
                    'severity' => 'warning',
                    'message'  => 'Nom de compte administrateur trop générique ou prévisible.',
                ];
            }

            if ( strtotime( $admin->user_registered ) > strtotime( '-14 days' ) ) {
                $findings[] = [
                    'user'     => $admin->user_login,
                    'type'     => 'recent_admin_account',
                    'severity' => 'warning',
                    'message'  => 'Compte administrateur créé récemment. Vérifiez qu’il est légitime.',
                ];
            }

            if ( TRQ_Core::get_instance()->get( 'two_factor_enabled' ) && ! get_user_meta( $admin->ID, 'trq_2fa_enabled', true ) ) {
                $findings[] = [
                    'user'     => $admin->user_login,
                    'type'     => 'admin_without_2fa',
                    'severity' => 'warning',
                    'message'  => 'Compte administrateur sans 2FA alors que la 2FA globale est activée.',
                ];
            }
        }

        return [
            'total_admins' => count( $admins ),
            'findings'     => $findings,
        ];
    }

    private function inspect_cron_events(): array {
        $findings = [];
        $cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : [];

        if ( ! is_array( $cron ) ) {
            return [ 'events_count' => 0, 'findings' => [] ];
        }

        $definitions = $this->get_definitions();
        $cron_regex = is_string( $definitions['suspicious_cron_regex'] ?? null ) ? $definitions['suspicious_cron_regex'] : '';

        foreach ( $cron as $timestamp => $hooks ) {
            foreach ( $hooks as $hook => $events ) {
                if ( '' !== $cron_regex && preg_match( $cron_regex, $hook ) ) {
                    $findings[] = [
                        'hook'     => $hook,
                        'type'     => 'suspicious_hook_name',
                        'severity' => 'warning',
                        'message'  => 'Nom de tâche planifiée potentiellement suspect.',
                        'next_run' => gmdate( 'Y-m-d H:i:s', (int) $timestamp ),
                    ];
                }

                if ( count( $events ) > 5 ) {
                    $findings[] = [
                        'hook'     => $hook,
                        'type'     => 'duplicated_cron_events',
                        'severity' => 'info',
                        'message'  => 'Beaucoup d’occurrences planifiées pour ce hook. Vérifiez qu’il n’y a pas de boucle anormale.',
                        'next_run' => gmdate( 'Y-m-d H:i:s', (int) $timestamp ),
                    ];
                }
            }
        }

        return [
            'events_count' => count( $cron ),
            'findings'     => $findings,
        ];
    }

    private function truncate_preview( string $content, int $length = 160 ): string {
        $content = trim( preg_replace( '/\s+/', ' ', $content ) );
        if ( strlen( $content ) <= $length ) {
            return $content;
        }

        return substr( $content, 0, $length ) . '...';
    }
}