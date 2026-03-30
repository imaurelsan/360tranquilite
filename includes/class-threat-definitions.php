<?php
/**
 * Gestionnaire de definitions de menaces.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRQ_Threat_Definitions {

    private static ?TRQ_Threat_Definitions $instance = null;

    private bool $booted = false;

    private function __construct() {}

    public static function get_instance(): TRQ_Threat_Definitions {
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

        add_action( 'trq_update_definitions', [ $this, 'refresh_definitions' ] );
        $this->ensure_schedule();
    }

    public function ensure_schedule(): void {
        $enabled = (bool) TRQ_Core::get_instance()->get( 'definitions_auto_update_enabled', false );
        $next    = wp_next_scheduled( 'trq_update_definitions' );

        if ( $enabled && ! $next ) {
            wp_schedule_event( time() + ( 4 * HOUR_IN_SECONDS ), 'daily', 'trq_update_definitions' );
            return;
        }

        if ( ! $enabled && $next ) {
            wp_clear_scheduled_hook( 'trq_update_definitions' );
        }
    }

    public function refresh_definitions( bool $manual = false ): array {
        $bundled = $this->get_bundled_definitions();
        $url     = esc_url_raw( (string) TRQ_Core::get_instance()->get( 'definitions_update_url', '' ) );
        $report  = [
            'success'      => true,
            'updated_at'   => current_time( 'mysql', true ),
            'source'       => 'bundled',
            'version'      => $bundled['version'],
            'message'      => '',
            'remote_url'   => $url,
            'remote_used'  => false,
        ];
        $definitions = $bundled;

        if ( '' !== $url ) {
            $response = wp_remote_get(
                $url,
                [
                    'timeout'    => 20,
                    'user-agent' => '360Tranquillite/' . ( defined( 'TRQ_VERSION' ) ? TRQ_VERSION : 'unknown' ),
                ]
            );

            if ( is_wp_error( $response ) ) {
                $report['success'] = false;
                $report['message'] = 'Echec de recuperation des definitions distantes: ' . $response->get_error_message();
            } else {
                $decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
                if ( is_array( $decoded ) ) {
                    $definitions = $this->merge_remote_definitions( $bundled, $decoded );
                    $report['source'] = $url;
                    $report['remote_used'] = true;
                    $report['version'] = sanitize_text_field( (string) ( $decoded['version'] ?? $bundled['version'] ) );
                    $report['message'] = 'Definitions distantes mises a jour avec succes.';
                } else {
                    $report['success'] = false;
                    $report['message'] = 'Le fichier JSON de definitions est invalide.';
                }
            }
        }

        if ( '' === $report['message'] ) {
            $report['message'] = $manual
                ? 'Definitions locales synchronisees. Ajoutez une URL JSON pour des mises a jour distantes.'
                : 'Definitions locales synchronisees.';
        }

        update_option( 'trq_threat_definitions_cache', $definitions, false );
        update_option( 'trq_threat_definitions_status', $report, false );

        if ( ! $report['success'] ) {
            TRQ_Core::notify( 'Mise a jour des definitions en echec', $report['message'] );
        }

        return $report;
    }

    public function get_status(): array {
        $status = get_option( 'trq_threat_definitions_status', [] );

        if ( ! is_array( $status ) ) {
            return [
                'success' => true,
                'updated_at' => '',
                'source' => 'bundled',
                'version' => ( defined( 'TRQ_VERSION' ) ? TRQ_VERSION : 'unknown' ),
                'message' => 'Aucune mise a jour effectuee pour le moment.',
                'remote_url' => '',
                'remote_used' => false,
            ];
        }

        return $status;
    }

    public function get_file_monitor_config(): array {
        $definitions = $this->get_definitions();
        return $definitions['file_monitor'];
    }

    public function get_system_scanner_config(): array {
        $definitions = $this->get_definitions();
        return $definitions['system_scanner'];
    }

    private function get_definitions(): array {
        $cached = get_option( 'trq_threat_definitions_cache', [] );
        if ( ! is_array( $cached ) || empty( $cached['file_monitor'] ) || empty( $cached['system_scanner'] ) ) {
            $cached = $this->get_bundled_definitions();
            update_option( 'trq_threat_definitions_cache', $cached, false );
        }

        return $cached;
    }

    private function get_bundled_definitions(): array {
        return [
            'version' => defined( 'TRQ_VERSION' ) ? TRQ_VERSION : 'bundled',
            'file_monitor' => [
                'upload_forbidden_exts' => [ 'php', 'phtml', 'phar', 'php5', 'php7', 'shtml', 'cgi', 'pl' ],
                'suspicious_filenames' => '/(shell|backdoor|mailer|wso|r57|c99|cmd|mini|upload|anonymous|b374k)/i',
                'suspicious_content_patterns' => [
                    'eval_base64' => '/eval\s*\(\s*base64_decode\s*\(/i',
                    'assert_post' => '/assert\s*\(\s*\$_(POST|REQUEST|GET)/i',
                    'gzinflate_base64' => '/gzinflate\s*\(\s*base64_decode\s*\(/i',
                    'str_rot13_exec' => '/str_rot13\s*\(/i',
                    'preg_replace_eval' => '/preg_replace\s*\(.+\/e[\'\"]/i',
                    'system_call' => '/\b(shell_exec|passthru|system|proc_open|popen)\s*\(/i',
                ],
            ],
            'system_scanner' => [
                'suspicious_regexes' => [
                    'base64_eval' => '/base64_decode\s*\(|eval\s*\(/i',
                    'iframe_injection' => '/<iframe[^>]+src=/i',
                    'script_injection' => '/<script[^>]*>.*<\/script>/is',
                    'obfuscated_php' => '/gzinflate\s*\(|str_rot13\s*\(|shell_exec\s*\(/i',
                    'remote_loader' => '/https?:\\/\\/[^\s\"\']+/i',
                ],
                'suspicious_user_regex' => '/^(admin|administrator|support|seo|test|temp|backup|wp|manager)[0-9_-]*$/i',
                'suspicious_cron_regex' => '/(base64|eval|shell|mailer|spam|backdoor|cmd|wso|r57|inject)/i',
            ],
        ];
    }

    private function merge_remote_definitions( array $bundled, array $remote ): array {
        $merged = $bundled;

        if ( isset( $remote['file_monitor']['upload_forbidden_exts'] ) && is_array( $remote['file_monitor']['upload_forbidden_exts'] ) ) {
            $merged['file_monitor']['upload_forbidden_exts'] = array_values(
                array_filter(
                    array_map( 'sanitize_key', $remote['file_monitor']['upload_forbidden_exts'] )
                )
            );
        }

        if ( ! empty( $remote['file_monitor']['suspicious_filenames'] ) && is_string( $remote['file_monitor']['suspicious_filenames'] ) ) {
            $merged['file_monitor']['suspicious_filenames'] = $remote['file_monitor']['suspicious_filenames'];
        }

        if ( isset( $remote['file_monitor']['suspicious_content_patterns'] ) && is_array( $remote['file_monitor']['suspicious_content_patterns'] ) ) {
            $merged['file_monitor']['suspicious_content_patterns'] = array_merge(
                $bundled['file_monitor']['suspicious_content_patterns'],
                $this->sanitize_regex_map( $remote['file_monitor']['suspicious_content_patterns'] )
            );
        }

        if ( isset( $remote['system_scanner']['suspicious_regexes'] ) && is_array( $remote['system_scanner']['suspicious_regexes'] ) ) {
            $merged['system_scanner']['suspicious_regexes'] = array_merge(
                $bundled['system_scanner']['suspicious_regexes'],
                $this->sanitize_regex_map( $remote['system_scanner']['suspicious_regexes'] )
            );
        }

        if ( ! empty( $remote['system_scanner']['suspicious_user_regex'] ) && is_string( $remote['system_scanner']['suspicious_user_regex'] ) ) {
            $merged['system_scanner']['suspicious_user_regex'] = $remote['system_scanner']['suspicious_user_regex'];
        }

        if ( ! empty( $remote['system_scanner']['suspicious_cron_regex'] ) && is_string( $remote['system_scanner']['suspicious_cron_regex'] ) ) {
            $merged['system_scanner']['suspicious_cron_regex'] = $remote['system_scanner']['suspicious_cron_regex'];
        }

        $merged['version'] = sanitize_text_field( (string) ( $remote['version'] ?? $bundled['version'] ) );

        return $merged;
    }

    private function sanitize_regex_map( array $items ): array {
        $patterns = [];

        foreach ( $items as $key => $pattern ) {
            if ( ! is_string( $key ) || ! is_string( $pattern ) ) {
                continue;
            }

            $key = sanitize_key( $key );
            if ( '' === $key || '' === trim( $pattern ) ) {
                continue;
            }

            $patterns[ $key ] = $pattern;
        }

        return $patterns;
    }
}