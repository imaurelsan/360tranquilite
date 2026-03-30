<?php
/**
 * Module Surveillance de l'intégrité des fichiers.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRQ_File_Monitor {

    private static ?TRQ_File_Monitor $instance = null;

    private const CORE_MONITORED_DIRS = [
        'wp-admin',
        'wp-includes',
    ];

    private const STANDARD_MONITORED_EXTS = [ 'php', 'js', 'css', 'htaccess', 'html', 'txt', 'json' ];

    private function __construct() {}

    public static function get_instance(): TRQ_File_Monitor {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function init(): void {
        add_action( 'trq_file_scan', [ $this, 'run_scan' ] );
        if ( ! wp_next_scheduled( 'trq_file_scan' ) ) {
            wp_schedule_event( time(), 'daily', 'trq_file_scan' );
        }
    }

    private function get_definitions(): array {
        return TRQ_Threat_Definitions::get_instance()->get_file_monitor_config();
    }

    public function run_scan(): array {
        $files           = $this->collect_files();
        $changes         = [];
        $findings        = [];
        $known_checksums = $this->get_all_stored_checksums();
        $has_baseline    = ! empty( $known_checksums );

        foreach ( $files as $path ) {
            $checksum = hash_file( 'sha256', $path );
            $stored   = $known_checksums[ $path ] ?? null;

            if ( ! $has_baseline ) {
                $this->store_checksum( $path, $checksum );
            } elseif ( null === $stored ) {
                $changes[] = [
                    'path' => $path,
                    'old'  => '',
                    'new'  => $checksum,
                    'type' => 'added',
                ];
            } elseif ( ! hash_equals( $stored, $checksum ) ) {
                $changes[] = [
                    'path' => $path,
                    'old'  => $stored,
                    'new'  => $checksum,
                    'type' => 'modified',
                ];
            }

            $findings = array_merge( $findings, $this->inspect_file( $path ) );
        }

        foreach ( array_keys( $known_checksums ) as $stored_path ) {
            if ( ! file_exists( $stored_path ) ) {
                $changes[] = [
                    'path' => $stored_path,
                    'old'  => $known_checksums[ $stored_path ],
                    'new'  => '',
                    'type' => 'deleted',
                ];
            }
        }

        $report = [
            'generated_at' => current_time( 'mysql', true ),
            'changes'      => $changes,
            'findings'     => $findings,
        ];

        update_option( 'trq_last_scan_report', $report, false );

        if ( ! empty( $changes ) || ! empty( $findings ) ) {
            $this->notify_changes( $changes, $findings );
        }

        return $report;
    }

    public function build_baseline(): int {
        global $wpdb;

        $wpdb->query( "TRUNCATE TABLE `{$wpdb->prefix}trq_file_checksums`" );

        $files = $this->collect_files();
        foreach ( $files as $path ) {
            $this->store_checksum( $path, hash_file( 'sha256', $path ) );
        }

        update_option(
            'trq_last_scan_report',
            [
                'generated_at' => current_time( 'mysql', true ),
                'changes'      => [],
                'findings'     => [],
            ],
            false
        );

        return count( $files );
    }

    private function collect_files(): array {
        $files = [];

        foreach ( self::CORE_MONITORED_DIRS as $dir ) {
            $files = array_merge( $files, $this->collect_files_from_dir( ABSPATH . $dir, self::STANDARD_MONITORED_EXTS ) );
        }

        $core = TRQ_Core::get_instance();

        if ( $core->get( 'file_monitor_scan_plugins' ) && defined( 'WP_PLUGIN_DIR' ) ) {
            $files = array_merge( $files, $this->collect_files_from_dir( WP_PLUGIN_DIR, self::STANDARD_MONITORED_EXTS ) );
        }

        if ( $core->get( 'file_monitor_scan_themes' ) && defined( 'WP_CONTENT_DIR' ) ) {
            $files = array_merge( $files, $this->collect_files_from_dir( WP_CONTENT_DIR . '/themes', self::STANDARD_MONITORED_EXTS ) );
        }

        if ( $core->get( 'file_monitor_scan_muplugins' ) && defined( 'WPMU_PLUGIN_DIR' ) ) {
            $files = array_merge( $files, $this->collect_files_from_dir( WPMU_PLUGIN_DIR, self::STANDARD_MONITORED_EXTS ) );
        }

        if ( $core->get( 'file_monitor_scan_uploads' ) ) {
            $upload_dir = wp_get_upload_dir();
            if ( ! empty( $upload_dir['basedir'] ) ) {
                $definitions = $this->get_definitions();
                $forbidden_exts = isset( $definitions['upload_forbidden_exts'] ) && is_array( $definitions['upload_forbidden_exts'] ) ? $definitions['upload_forbidden_exts'] : [];
                $files = array_merge( $files, $this->collect_files_from_dir( $upload_dir['basedir'], array_merge( self::STANDARD_MONITORED_EXTS, $forbidden_exts ) ) );
            }
        }

        foreach ( [ 'wp-config.php', '.htaccess', 'wp-settings.php', 'wp-load.php' ] as $f ) {
            $full = ABSPATH . $f;
            if ( file_exists( $full ) ) {
                $files[] = $full;
            }
        }

        $files = array_values( array_unique( $files ) );
        sort( $files );

        return $files;
    }

    private function collect_files_from_dir( string $full_dir, array $extensions ): array {
        $files = [];

        if ( ! is_dir( $full_dir ) ) {
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $full_dir, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }

            $ext = strtolower( $file->getExtension() );
            if ( in_array( $ext, $extensions, true ) ) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function inspect_file( string $path ): array {
        $findings = [];
        $normalized_path = wp_normalize_path( $path );
        $filename = basename( $path );
        $extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        $uploads_dir = wp_get_upload_dir();
        $uploads_base = ! empty( $uploads_dir['basedir'] ) ? wp_normalize_path( $uploads_dir['basedir'] ) : '';
        $definitions = $this->get_definitions();
        $forbidden_exts = isset( $definitions['upload_forbidden_exts'] ) && is_array( $definitions['upload_forbidden_exts'] ) ? $definitions['upload_forbidden_exts'] : [];
        $filename_regex = is_string( $definitions['suspicious_filenames'] ?? null ) ? $definitions['suspicious_filenames'] : '';
        $content_patterns = isset( $definitions['suspicious_content_patterns'] ) && is_array( $definitions['suspicious_content_patterns'] ) ? $definitions['suspicious_content_patterns'] : [];
        $code_extensions = array_unique( array_merge( [ 'php', 'phtml', 'php5', 'php7' ], $forbidden_exts ) );

        if ( $uploads_base && 0 === strpos( $normalized_path, $uploads_base . '/' ) && in_array( $extension, $forbidden_exts, true ) ) {
            $findings[] = $this->build_finding( $path, 'php_in_uploads', 'critical', 'Fichier exécutable détecté dans uploads.' );
        }

        if ( '' !== $filename_regex && preg_match( $filename_regex, $filename ) ) {
            $findings[] = $this->build_finding( $path, 'suspicious_filename', 'warning', 'Nom de fichier potentiellement malveillant.' );
        }

        if ( in_array( $extension, $code_extensions, true ) ) {
            $content = $this->read_file_excerpt( $path );

            foreach ( $content_patterns as $pattern_name => $pattern ) {
                if ( preg_match( $pattern, $content ) ) {
                    $findings[] = $this->build_finding( $path, $pattern_name, 'warning', 'Signature de code obfusqué ou dangereux détectée.' );
                }
            }
        }

        return $findings;
    }

    private function build_finding( string $path, string $type, string $severity, string $message ): array {
        return [
            'path'     => $path,
            'type'     => $type,
            'severity' => $severity,
            'message'  => $message,
        ];
    }

    private function read_file_excerpt( string $path, int $max_bytes = 262144 ): string {
        $filesystem = $this->get_filesystem();
        if ( ! $filesystem || ! $filesystem->exists( $path ) ) {
            return '';
        }

        $content = $filesystem->get_contents( $path );
        if ( ! is_string( $content ) || '' === $content ) {
            return '';
        }

        return substr( $content, 0, $max_bytes );
    }

    private function get_stored_checksum( string $path ): ?string {
        global $wpdb;

        return $wpdb->get_var( $wpdb->prepare(
            "SELECT checksum FROM `{$wpdb->prefix}trq_file_checksums` WHERE file_path = %s",
            $path
        ) );
    }

    private function store_checksum( string $path, string $checksum ): void {
        global $wpdb;

        $wpdb->replace(
            $wpdb->prefix . 'trq_file_checksums',
            [
                'file_path'    => $path,
                'checksum'     => $checksum,
                'last_checked' => current_time( 'mysql', true ),
            ],
            [ '%s', '%s', '%s' ]
        );
    }

    private function get_all_stored_checksums(): array {
        global $wpdb;

        $rows = $wpdb->get_results( "SELECT file_path, checksum FROM `{$wpdb->prefix}trq_file_checksums`", ARRAY_A );
        $checksums = [];

        foreach ( $rows as $row ) {
            $checksums[ $row['file_path'] ] = $row['checksum'];
        }

        return $checksums;
    }

    private function notify_changes( array $changes, array $findings ): void {
        $lines = [];

        foreach ( $changes as $change ) {
            $label = '[MODIFIÉ]';
            if ( 'deleted' === $change['type'] ) {
                $label = '[SUPPRIMÉ]';
            } elseif ( 'added' === $change['type'] ) {
                $label = '[AJOUTÉ]';
            }

            $lines[] = $label . ' ' . $change['path'];
        }

        foreach ( $findings as $finding ) {
            $lines[] = '[SUSPECT][' . strtoupper( $finding['severity'] ) . '] ' . $finding['path'] . ' - ' . $finding['type'];
        }

        TRQ_Core::notify(
            count( $changes ) . ' changement(s) et ' . count( $findings ) . ' signal(s) suspects détectés',
            "Le moniteur de fichiers a détecté les éléments suivants :\n\n" . implode( "\n", $lines ) . "\n\nVérifiez immédiatement l'intégrité de votre site.\n"
        );

        if ( ! empty( $findings ) && TRQ_Core::get_instance()->get( 'audit_log_enabled' ) ) {
            TRQ_Audit_Log::get_instance()->log(
                'file_scan_alert',
                'Le scan de fichiers a produit ' . count( $findings ) . ' signal(s) suspects.',
                'warning',
                'scan',
                'file_monitor'
            );
        }
    }

    public function get_stats(): array {
        global $wpdb;

        $report = $this->get_last_report();

        return [
            'total_files'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}trq_file_checksums`" ),
            'last_checked'  => $wpdb->get_var( "SELECT MAX(last_checked) FROM `{$wpdb->prefix}trq_file_checksums`" ),
            'last_changes'  => count( $report['changes'] ?? [] ),
            'last_findings' => count( $report['findings'] ?? [] ),
        ];
    }

    public function get_last_report(): array {
        $report = get_option( 'trq_last_scan_report', [] );

        if ( ! is_array( $report ) ) {
            return [ 'generated_at' => '', 'changes' => [], 'findings' => [] ];
        }

        return [
            'generated_at' => is_string( $report['generated_at'] ?? null ) ? $report['generated_at'] : '',
            'changes'      => is_array( $report['changes'] ?? null ) ? $report['changes'] : [],
            'findings'     => is_array( $report['findings'] ?? null ) ? $report['findings'] : [],
        ];
    }

    public function quarantine_findings_from_last_report( int $max_items = 200 ): array {
        $report = $this->get_last_report();
        $findings = is_array( $report['findings'] ?? null ) ? $report['findings'] : [];

        if ( empty( $findings ) ) {
            return [
                'processed' => 0,
                'quarantined' => 0,
                'failed' => 0,
                'details' => [],
            ];
        }

        $paths = [];
        foreach ( array_slice( $findings, 0, max( 1, $max_items ) ) as $finding ) {
            $path = (string) ( $finding['path'] ?? '' );
            if ( '' !== $path ) {
                $paths[] = $path;
            }
        }

        $paths = array_values( array_unique( $paths ) );

        $result = [
            'processed' => count( $paths ),
            'quarantined' => 0,
            'failed' => 0,
            'details' => [],
        ];

        foreach ( $paths as $path ) {
            $move = $this->quarantine_file( $path );
            if ( ! empty( $move['success'] ) ) {
                $result['quarantined']++;
            } else {
                $result['failed']++;
            }

            if ( count( $result['details'] ) < 20 ) {
                $result['details'][] = [
                    'path' => $path,
                    'success' => ! empty( $move['success'] ),
                    'message' => (string) ( $move['message'] ?? '' ),
                ];
            }
        }

        return $result;
    }

    public function quarantine_file( string $path ): array {
        if ( ! TRQ_Core::get_instance()->get( 'file_monitor_quarantine_enabled' ) ) {
            return [ 'success' => false, 'message' => 'La quarantaine est désactivée.' ];
        }

        $real_path = realpath( $path );
        if ( false === $real_path || ! is_file( $real_path ) ) {
            return [ 'success' => false, 'message' => 'Fichier introuvable.' ];
        }

        $normalized = wp_normalize_path( $real_path );
        $wp_content = defined( 'WP_CONTENT_DIR' ) ? wp_normalize_path( WP_CONTENT_DIR ) : '';
        if ( ! $wp_content || 0 !== strpos( $normalized, $wp_content . '/' ) ) {
            return [ 'success' => false, 'message' => 'Seuls les fichiers dans wp-content peuvent être placés en quarantaine.' ];
        }

        $quarantine_path = $real_path . '.trq-quarantine-' . gmdate( 'YmdHis' );
        $filesystem = $this->get_filesystem();
        if ( ! $filesystem || ! $filesystem->move( $real_path, $quarantine_path, true ) ) {
            return [ 'success' => false, 'message' => 'Le fichier n’a pas pu être déplacé en quarantaine.' ];
        }

        $map = get_option( 'trq_quarantined_files', [] );
        if ( ! is_array( $map ) ) {
            $map = [];
        }

        $map[] = [
            'original_path'   => $real_path,
            'quarantine_path' => $quarantine_path,
            'quarantined_at'  => current_time( 'mysql', true ),
        ];
        update_option( 'trq_quarantined_files', $map, false );

        if ( TRQ_Core::get_instance()->get( 'audit_log_enabled' ) ) {
            TRQ_Audit_Log::get_instance()->log(
                'file_quarantined',
                'Fichier déplacé en quarantaine : ' . $real_path,
                'critical',
                'file',
                $real_path
            );
        }

        return [
            'success' => true,
            'message' => 'Fichier placé en quarantaine.',
            'path'    => $quarantine_path,
        ];
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
}
