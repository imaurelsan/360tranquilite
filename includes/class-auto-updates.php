<?php
/**
 * Gestion automatisée et sécurisée des mises à jour WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRQ_Auto_Updates {

    private static ?TRQ_Auto_Updates $instance = null;

    private bool $booted = false;

    /** @var array<int, array{type: string, name: string, new_version: string, reason: string}> */
    private array $compat_skipped = [];

    private const ROLLBACK_CONTEXT_OPTION = 'trq_updates_rollback_context';
    private const HEALTHCHECK_EVENT_HOOK  = 'trq_updates_run_healthcheck';

    private function __construct() {}

    public static function get_instance(): TRQ_Auto_Updates {
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
        add_filter( 'auto_update_plugin', [ $this, 'should_auto_update_plugin' ], 10, 2 );
        add_filter( 'auto_update_theme', [ $this, 'should_auto_update_theme' ], 10, 2 );
        add_filter( 'auto_update_translation', [ $this, 'should_auto_update_translation' ], 10, 2 );
        add_filter( 'allow_minor_auto_core_updates', [ $this, 'allow_minor_core_updates' ] );
        add_filter( 'allow_major_auto_core_updates', [ $this, 'allow_major_core_updates' ] );
        add_filter( 'allow_dev_auto_core_updates', '__return_false' );
        add_filter( 'upgrader_pre_install', [ $this, 'maybe_backup_before_update' ], 10, 2 );
        add_action( 'upgrader_process_complete', [ $this, 'handle_upgrader_process_complete' ], 10, 2 );
        add_action( self::HEALTHCHECK_EVENT_HOOK, [ $this, 'run_post_update_healthcheck' ] );
        add_action( 'automatic_updates_complete', [ $this, 'record_auto_updates_report' ] );
    }

    /**
     * Assure une sauvegarde locale avant toute mise à jour core/plugin/theme.
     * En cas d'échec de backup, la mise à jour est bloquée pour éviter une fenêtre sans rollback.
     *
     * @param mixed $response
     * @param mixed $hook_extra
     * @return mixed
     */
    public function maybe_backup_before_update( $response, $hook_extra ) {
        if ( is_wp_error( $response ) || false === $response ) {
            return $response;
        }

        if ( ! $this->is_update_management_enabled() ) {
            return $response;
        }

        $core = TRQ_Core::get_instance();
        if ( ! (bool) $core->get( 'updates_pre_update_backup_enabled', true ) ) {
            return $response;
        }

        if ( ! $this->is_supported_update_context( $hook_extra ) ) {
            return $response;
        }

        $backup = TRQ_Backup_Manager::get_instance()->run_backup( 'pre_update' );
        if ( empty( $backup['success'] ) ) {
            return new WP_Error(
                'trq_pre_update_backup_failed',
                'Mise à jour bloquée : impossible de créer une sauvegarde pré-update.'
            );
        }

        $archive_name = (string) ( $backup['archive_name'] ?? '' );
        if ( '' === $archive_name ) {
            $last_report = TRQ_Backup_Manager::get_instance()->get_last_report();
            $archive_name = (string) ( $last_report['archive_name'] ?? '' );
        }

        if ( '' === $archive_name ) {
            return new WP_Error(
                'trq_pre_update_backup_missing',
                'Mise à jour bloquée : sauvegarde pré-update incomplète (archive introuvable).'
            );
        }

        $interval_minutes = max( 1, min( 30, (int) $core->get( 'updates_healthcheck_interval_minutes', 3 ) ) );
        $attempts = max( 1, min( 20, (int) $core->get( 'updates_healthcheck_retries', 5 ) ) );

        update_option(
            self::ROLLBACK_CONTEXT_OPTION,
            [
                'created_at'       => current_time( 'mysql', true ),
                'backup_archive'   => $archive_name,
                'type'             => (string) ( is_array( $hook_extra ) ? ( $hook_extra['type'] ?? '' ) : '' ),
                'items'            => $this->extract_update_items( $hook_extra ),
                'attempts_left'    => $attempts,
                'interval_minutes' => $interval_minutes,
                'healthcheck_url'  => (string) $core->get( 'updates_healthcheck_url', home_url( '/' ) ),
                'timeout'          => max( 3, min( 30, (int) $core->get( 'updates_healthcheck_timeout', 10 ) ) ),
            ],
            false
        );

        return $response;
    }

    /**
     * Lance la phase de health-check post-update si le rollback automatique est activé.
     *
     * @param mixed $upgrader
     * @param mixed $hook_extra
     */
    public function handle_upgrader_process_complete( $upgrader, $hook_extra ): void {
        unset( $upgrader );

        if ( ! $this->is_update_management_enabled() ) {
            return;
        }

        $core = TRQ_Core::get_instance();
        if ( ! (bool) $core->get( 'updates_auto_rollback_enabled', false ) ) {
            delete_option( self::ROLLBACK_CONTEXT_OPTION );
            return;
        }

        if ( ! (bool) $core->get( 'updates_post_update_healthcheck_enabled', true ) ) {
            delete_option( self::ROLLBACK_CONTEXT_OPTION );
            return;
        }

        if ( ! $this->is_supported_update_context( $hook_extra ) ) {
            return;
        }

        $context = get_option( self::ROLLBACK_CONTEXT_OPTION, [] );
        if ( ! is_array( $context ) || empty( $context['backup_archive'] ) ) {
            return;
        }

        $interval_minutes = max( 1, min( 30, (int) ( $context['interval_minutes'] ?? 3 ) ) );
        wp_clear_scheduled_hook( self::HEALTHCHECK_EVENT_HOOK );
        wp_schedule_single_event( time() + ( $interval_minutes * MINUTE_IN_SECONDS ), self::HEALTHCHECK_EVENT_HOOK );
    }

    public function run_post_update_healthcheck(): void {
        $context = get_option( self::ROLLBACK_CONTEXT_OPTION, [] );
        if ( ! is_array( $context ) || empty( $context['backup_archive'] ) ) {
            return;
        }

        $check = $this->perform_healthcheck( $context );
        if ( ! empty( $check['ok'] ) ) {
            delete_option( self::ROLLBACK_CONTEXT_OPTION );
            return;
        }

        $attempts_left = max( 0, (int) ( $context['attempts_left'] ?? 0 ) - 1 );
        $context['attempts_left'] = $attempts_left;
        update_option( self::ROLLBACK_CONTEXT_OPTION, $context, false );

        if ( $attempts_left > 0 ) {
            $interval_minutes = max( 1, min( 30, (int) ( $context['interval_minutes'] ?? 3 ) ) );
            wp_schedule_single_event( time() + ( $interval_minutes * MINUTE_IN_SECONDS ), self::HEALTHCHECK_EVENT_HOOK );
            return;
        }

        $archive = (string) $context['backup_archive'];
        $restore = TRQ_Backup_Manager::get_instance()->restore_local_backup( $archive );

        $subject = '[360 Tranquillité] Rollback automatique après mise à jour';
        $reason  = (string) ( $check['reason'] ?? 'Health-check échoué.' );
        if ( ! empty( $restore['success'] ) ) {
            TRQ_Core::notify(
                $subject,
                'Rollback exécuté avec succès.' . "\n" .
                'Archive restaurée : ' . $archive . "\n" .
                'Raison : ' . $reason
            );
        } else {
            TRQ_Core::notify(
                $subject,
                'Rollback automatique échoué.' . "\n" .
                'Archive : ' . $archive . "\n" .
                'Raison health-check : ' . $reason . "\n" .
                'Erreur restauration : ' . (string) ( $restore['message'] ?? 'inconnue' )
            );
        }

        delete_option( self::ROLLBACK_CONTEXT_OPTION );
    }

    public function should_auto_update_plugin( $update, $item ) {
        if ( ! $this->is_update_management_enabled() ) {
            return $update;
        }

        if ( false === $update ) {
            return false;
        }

        if ( ! $this->is_updates_allowed_now() || ! (bool) TRQ_Core::get_instance()->get( 'updates_plugins_auto', true ) ) {
            return false;
        }

        if ( TRQ_Core::get_instance()->get( 'updates_check_compat', true ) ) {
            $reason = $this->get_compat_reason( (object) $item );
            if ( '' !== $reason ) {
                $this->compat_skipped[] = [
                    'type'        => 'plugin',
                    'name'        => (string) ( $item->name ?? $item->plugin ?? 'inconnu' ),
                    'new_version' => (string) ( $item->new_version ?? '?' ),
                    'reason'      => $reason,
                ];
                return false;
            }
        }

        return true;
    }

    public function should_auto_update_theme( $update, $item ) {
        if ( ! $this->is_update_management_enabled() ) {
            return $update;
        }

        if ( false === $update ) {
            return false;
        }

        if ( ! $this->is_updates_allowed_now() || ! (bool) TRQ_Core::get_instance()->get( 'updates_themes_auto', true ) ) {
            return false;
        }

        if ( TRQ_Core::get_instance()->get( 'updates_check_compat', true ) ) {
            $reason = $this->get_compat_reason( (object) $item );
            if ( '' !== $reason ) {
                $this->compat_skipped[] = [
                    'type'        => 'theme',
                    'name'        => (string) ( $item->theme ?? 'inconnu' ),
                    'new_version' => (string) ( $item->new_version ?? '?' ),
                    'reason'      => $reason,
                ];
                return false;
            }
        }

        return true;
    }

    public function should_auto_update_translation( $update, $item ) {
        unset( $item );

        if ( ! $this->is_update_management_enabled() ) {
            return $update;
        }

        if ( false === $update ) {
            return false;
        }

        return $this->is_updates_allowed_now() && (bool) TRQ_Core::get_instance()->get( 'updates_translations_auto', true );
    }

    public function allow_minor_core_updates( $allowed ): bool {
        if ( ! $this->is_update_management_enabled() ) {
            return (bool) $allowed;
        }

        if ( false === $allowed ) {
            return false;
        }

        if ( ! $this->is_updates_allowed_now() ) {
            return false;
        }

        $mode = (string) TRQ_Core::get_instance()->get( 'updates_core_mode', 'minor' );
        return in_array( $mode, [ 'minor', 'all' ], true );
    }

    public function allow_major_core_updates( $allowed ): bool {
        if ( ! $this->is_update_management_enabled() ) {
            return (bool) $allowed;
        }

        if ( false === $allowed ) {
            return false;
        }

        if ( ! $this->is_updates_allowed_now() ) {
            return false;
        }

        return 'all' === (string) TRQ_Core::get_instance()->get( 'updates_core_mode', 'minor' );
    }

    public function get_status_summary(): array {
        $core = TRQ_Core::get_instance();

        return [
            'enabled' => (bool) $core->get( 'updates_auto_enabled', false ),
            'core_mode' => (string) $core->get( 'updates_core_mode', 'minor' ),
            'plugins' => (bool) $core->get( 'updates_plugins_auto', true ),
            'themes' => (bool) $core->get( 'updates_themes_auto', true ),
            'translations' => (bool) $core->get( 'updates_translations_auto', true ),
            'window_enabled' => (bool) $core->get( 'updates_window_enabled', false ),
            'window_start' => (string) $core->get( 'updates_window_start', '02:00' ),
            'window_duration_hours' => (int) $core->get( 'updates_window_duration_hours', 3 ),
            'blocked_by_file_mods' => (bool) $core->get( 'disable_file_mods', false ),
            'window_open_now'       => $this->is_in_window_now(),
            'notify_mode'           => (string) $core->get( 'updates_notify_mode', 'all' ),
            'check_compat'          => (bool) $core->get( 'updates_check_compat', true ),
        ];
    }

    public function get_last_report(): array {
        $report = get_option( 'trq_last_auto_updates_report', [] );
        return is_array( $report ) ? $report : [];
    }

    public function record_auto_updates_report( array $results ): void {
        $counts   = [ 'plugins' => 0, 'themes' => 0, 'translations' => 0, 'core' => 0 ];
        $failures = [ 'plugins' => 0, 'themes' => 0, 'translations' => 0, 'core' => 0 ];

        foreach ( (array) ( $results['plugin'] ?? [] ) as $item ) {
            if ( is_wp_error( $item->result ?? null ) ) {
                $failures['plugins']++;
            } elseif ( ! empty( $item->result ) ) {
                $counts['plugins']++;
            }
        }

        foreach ( (array) ( $results['theme'] ?? [] ) as $item ) {
            if ( is_wp_error( $item->result ?? null ) ) {
                $failures['themes']++;
            } elseif ( ! empty( $item->result ) ) {
                $counts['themes']++;
            }
        }

        foreach ( (array) ( $results['translation'] ?? [] ) as $item ) {
            if ( is_wp_error( $item->result ?? null ) ) {
                $failures['translations']++;
            } elseif ( ! empty( $item->result ) ) {
                $counts['translations']++;
            }
        }

        foreach ( (array) ( $results['core'] ?? [] ) as $item ) {
            if ( is_wp_error( $item->result ?? null ) ) {
                $failures['core']++;
            } elseif ( ! empty( $item->result ) ) {
                $counts['core']++;
            }
        }

        $report = [
            'generated_at'   => current_time( 'mysql', true ),
            'counts'         => $counts,
            'failures'       => $failures,
            'compat_skipped' => $this->compat_skipped,
            'summary'        => [
                'plugins'      => array_map( static fn( $i ) => (string) ( $i->name ?? '' ), (array) ( $results['plugin'] ?? [] ) ),
                'themes'       => array_map( static fn( $i ) => (string) ( $i->name ?? '' ), (array) ( $results['theme'] ?? [] ) ),
                'translations' => count( (array) ( $results['translation'] ?? [] ) ),
                'core'         => count( (array) ( $results['core'] ?? [] ) ),
            ],
        ];

        update_option( 'trq_last_auto_updates_report', $report, false );

        $total_updated  = array_sum( $counts );
        $total_failures = array_sum( $failures );
        $total_skipped  = count( $this->compat_skipped );

        $mode = (string) TRQ_Core::get_instance()->get( 'updates_notify_mode', 'all' );

        $should_notify = false;
        if ( 'all' === $mode ) {
            $should_notify = $total_updated > 0 || $total_failures > 0 || $total_skipped > 0;
        } elseif ( 'failures' === $mode ) {
            $should_notify = $total_failures > 0;
        } elseif ( 'problems' === $mode ) {
            $should_notify = $total_skipped > 0 || $total_failures > 0;
        }

        if ( $should_notify ) {
            $subject = '[360 Tranquillité] Rapport des mises à jour automatiques';
            $lines   = [];

            if ( $total_updated > 0 ) {
                $lines[] = "Mises à jour appliquées : {$total_updated}";
                $lines[] = "  – Plugins : {$counts['plugins']}";
                $lines[] = "  – Thèmes : {$counts['themes']}";
                $lines[] = "  – Traductions : {$counts['translations']}";
                $lines[] = "  – Core : {$counts['core']}";
            }

            if ( $total_failures > 0 ) {
                $lines[] = '';
                $lines[] = "Échecs : {$total_failures}";
                $lines[] = "  – Plugins : {$failures['plugins']}";
                $lines[] = "  – Thèmes : {$failures['themes']}";
                $lines[] = "  – Traductions : {$failures['translations']}";
                $lines[] = "  – Core : {$failures['core']}";
            }

            if ( $total_skipped > 0 ) {
                $lines[] = '';
                $lines[] = "Mises à jour bloquées pour incompatibilité : {$total_skipped}";
                foreach ( $this->compat_skipped as $skipped ) {
                    $lines[] = "  – [{$skipped['type']}] {$skipped['name']} v{$skipped['new_version']} : {$skipped['reason']}";
                }
            }

            TRQ_Core::notify( $subject, implode( "\n", $lines ) );
        }
    }

    private function is_updates_allowed_now(): bool {
        if ( ! $this->is_update_management_enabled() ) {
            return false;
        }

        $core = TRQ_Core::get_instance();

        if ( $core->get( 'disable_file_mods', false ) ) {
            return false;
        }

        return $this->is_in_window_now();
    }

    private function is_update_management_enabled(): bool {
        return (bool) TRQ_Core::get_instance()->get( 'updates_auto_enabled', false );
    }

    /**
     * @param mixed $hook_extra
     */
    private function is_supported_update_context( $hook_extra ): bool {
        if ( ! is_array( $hook_extra ) ) {
            return false;
        }

        if ( 'update' !== (string) ( $hook_extra['action'] ?? '' ) ) {
            return false;
        }

        return in_array( (string) ( $hook_extra['type'] ?? '' ), [ 'plugin', 'theme', 'core' ], true );
    }

    /**
     * @param mixed $hook_extra
     * @return array<int, string>
     */
    private function extract_update_items( $hook_extra ): array {
        if ( ! is_array( $hook_extra ) ) {
            return [];
        }

        $type = (string) ( $hook_extra['type'] ?? '' );
        if ( 'plugin' === $type ) {
            $plugins = $hook_extra['plugins'] ?? [];
            return array_values( array_map( 'strval', is_array( $plugins ) ? $plugins : [] ) );
        }

        if ( 'theme' === $type ) {
            $themes = $hook_extra['themes'] ?? [];
            return array_values( array_map( 'strval', is_array( $themes ) ? $themes : [] ) );
        }

        if ( 'core' === $type ) {
            return [ 'core' ];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $context
     * @return array{ok: bool, reason: string}
     */
    private function perform_healthcheck( array $context ): array {
        $core = TRQ_Core::get_instance();
        $url = esc_url_raw( (string) ( $context['healthcheck_url'] ?? $core->get( 'updates_healthcheck_url', home_url( '/' ) ) ) );
        if ( '' === $url ) {
            $url = home_url( '/' );
        }

        $timeout = max( 3, min( 30, (int) ( $context['timeout'] ?? $core->get( 'updates_healthcheck_timeout', 10 ) ) ) );

        $response = wp_remote_get(
            add_query_arg(
                [
                    'trq_healthcheck' => '1',
                    'ts' => (string) time(),
                ],
                $url
            ),
            [
                'timeout' => $timeout,
                'redirection' => 5,
                'sslverify' => true,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [
                'ok' => false,
                'reason' => 'Erreur HTTP: ' . (string) $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = strtolower( (string) wp_remote_retrieve_body( $response ) );

        if ( $code < 200 || $code >= 400 ) {
            return [
                'ok' => false,
                'reason' => 'Code HTTP inattendu: ' . $code,
            ];
        }

        $fatal_markers = [
            'there has been a critical error',
            'erreur critique sur votre site',
            'fatal error',
            'parse error',
            'uncaught',
        ];

        foreach ( $fatal_markers as $marker ) {
            if ( false !== strpos( $body, $marker ) ) {
                return [
                    'ok' => false,
                    'reason' => 'Marqueur de crash détecté dans la réponse front.',
                ];
            }
        }

        return [
            'ok' => true,
            'reason' => '',
        ];
    }

    private function is_in_window_now(): bool {
        $core = TRQ_Core::get_instance();
        if ( ! $core->get( 'updates_window_enabled', false ) ) {
            return true;
        }

        $start = (string) $core->get( 'updates_window_start', '02:00' );
        $duration = max( 1, min( 12, (int) $core->get( 'updates_window_duration_hours', 3 ) ) );

        if ( ! preg_match( '/^(2[0-3]|[01]\d):[0-5]\d$/', $start ) ) {
            $start = '02:00';
        }

        [ $hour, $minute ] = array_map( 'intval', explode( ':', $start ) );

        $timezone = wp_timezone();
        $now = new DateTimeImmutable( 'now', $timezone );
        $window_start = $now->setTime( $hour, $minute, 0 );
        $window_end = $window_start->modify( '+' . $duration . ' hours' );

        if ( $window_end <= $window_start ) {
            return true;
        }

        if ( $now >= $window_start && $now < $window_end ) {
            return true;
        }

        // Gérer une fenêtre qui chevauche minuit.
        $previous_start = $window_start->modify( '-1 day' );
        $previous_end = $previous_start->modify( '+' . $duration . ' hours' );

        return $now >= $previous_start && $now < $previous_end;
    }

    /**
     * Retourne la raison d'incompatibilité d'un item de mise à jour, ou une chaîne vide si compatible.
     * Vérifie : version WP minimale requise, version PHP minimale requise, écart de version majeure WordPress.
     */
    private function get_compat_reason( object $item ): string {
        global $wp_version;

        if ( ! empty( $item->requires ) && version_compare( $wp_version, (string) $item->requires, '<' ) ) {
            return sprintf( 'Nécessite WordPress %s minimum (installé : %s)', $item->requires, $wp_version );
        }

        if ( ! empty( $item->requires_php ) && version_compare( PHP_VERSION, (string) $item->requires_php, '<' ) ) {
            return sprintf(
                'Nécessite PHP %s minimum (installé : %s)',
                $item->requires_php,
                PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION
            );
        }

        if ( ! empty( $item->tested ) ) {
            $tested_major = (int) explode( '.', (string) $item->tested )[0];
            $wp_major     = (int) explode( '.', $wp_version )[0];
            if ( $wp_major > $tested_major ) {
                return sprintf( "Non testé sur WordPress %s (dernière version testée : %s)", $wp_version, $item->tested );
            }
        }

        return '';
    }

    /**
     * Scanne les mises à jour WordPress disponibles et retourne celles présentant des problèmes de compatibilité.
     * Utilisé pour l'affichage préventif dans l'onglet Mises à jour.
     *
     * @return array<int, array{type: string, name: string, current_version: string, new_version: string, reason: string}>
     */
    public function get_pending_compat_issues(): array {
        if ( ! function_exists( 'get_plugin_updates' ) ) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        $issues = [];

        foreach ( (array) get_plugin_updates() as $plugin_file => $plugin_data ) {
            $update = $plugin_data->update ?? null;
            if ( null === $update ) {
                continue;
            }
            $reason = $this->get_compat_reason( (object) $update );
            if ( '' !== $reason ) {
                $issues[] = [
                    'type'            => 'plugin',
                    'name'            => $plugin_data->Name ?? $plugin_file,
                    'current_version' => $plugin_data->Version ?? '?',
                    'new_version'     => $update->new_version ?? '?',
                    'reason'          => $reason,
                ];
            }
        }

        if ( function_exists( 'get_theme_updates' ) ) {
            foreach ( (array) get_theme_updates() as $theme_slug => $theme ) {
                $update = $theme->update ?? null;
                if ( null === $update ) {
                    continue;
                }
                $reason = $this->get_compat_reason( (object) $update );
                if ( '' !== $reason ) {
                    $issues[] = [
                        'type'            => 'theme',
                        'name'            => $theme->get( 'Name' ) ?: $theme_slug,
                        'current_version' => $theme->get( 'Version' ) ?: '?',
                        'new_version'     => is_object( $update ) ? ( $update->new_version ?? '?' ) : ( $update['new_version'] ?? '?' ),
                        'reason'          => $reason,
                    ];
                }
            }
        }

        return $issues;
    }
}
