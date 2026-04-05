<?php
/**
 * Nettoyage prudent des medias orphelins.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRQ_Media_Cleanup {

    private static ?TRQ_Media_Cleanup $instance = null;

    private bool $booted = false;

    private function __construct() {}

    public static function get_instance(): TRQ_Media_Cleanup {
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

        add_filter( 'cron_schedules', [ $this, 'register_weekly_schedule' ] );
        add_action( 'init', [ $this, 'ensure_scheduled_event' ] );
        add_action( 'trq_media_cleanup_weekly_event', [ $this, 'run_scheduled_cleanup' ] );
        add_action( 'before_delete_post', [ $this, 'cleanup_post_media' ], 10 );
        add_filter( 'big_image_size_threshold', [ $this, 'filter_big_image_size_threshold' ], 20, 4 );
        add_filter( 'wp_editor_set_quality', [ $this, 'filter_editor_quality' ], 20, 2 );
        add_filter( 'wp_generate_attachment_metadata', [ $this, 'generate_webp_variants' ], 20, 2 );
    }

    private function is_optimization_enabled(): bool {
        return (bool) TRQ_Core::get_instance()->get( 'media_optimization_enabled', false );
    }

    public function filter_big_image_size_threshold( $threshold, array $imagesize, string $file, int $attachment_id ) {
        unset( $imagesize, $file, $attachment_id );

        if ( ! $this->is_optimization_enabled() ) {
            return $threshold;
        }

        $max_width = max( 640, min( 6000, (int) TRQ_Core::get_instance()->get( 'media_optimization_max_width', 2560 ) ) );
        $max_height = max( 640, min( 6000, (int) TRQ_Core::get_instance()->get( 'media_optimization_max_height', 2560 ) ) );

        return max( $max_width, $max_height );
    }

    public function filter_editor_quality( int $quality, string $mime_type ): int {
        unset( $mime_type );

        if ( ! $this->is_optimization_enabled() ) {
            return $quality;
        }

        return max( 30, min( 100, (int) TRQ_Core::get_instance()->get( 'media_optimization_quality', 82 ) ) );
    }

    public function generate_webp_variants( array $metadata, int $attachment_id ): array {
        if ( ! $this->is_optimization_enabled() || ! TRQ_Core::get_instance()->get( 'media_optimization_generate_webp', false ) ) {
            return $metadata;
        }

        $attached_file = get_attached_file( $attachment_id );
        if ( ! is_string( $attached_file ) || '' === $attached_file || ! file_exists( $attached_file ) ) {
            return $metadata;
        }

        $quality = max( 30, min( 100, (int) TRQ_Core::get_instance()->get( 'media_optimization_quality', 82 ) ) );

        $paths = [ $attached_file ];
        if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            $base_dir = trailingslashit( dirname( $attached_file ) );
            foreach ( $metadata['sizes'] as $size_data ) {
                if ( ! is_array( $size_data ) || empty( $size_data['file'] ) ) {
                    continue;
                }

                $candidate = $base_dir . ltrim( (string) $size_data['file'], '/\\' );
                if ( file_exists( $candidate ) ) {
                    $paths[] = $candidate;
                }
            }
        }

        foreach ( array_values( array_unique( $paths ) ) as $source_file ) {
            $this->generate_webp_file( $source_file, $quality );
        }

        return $metadata;
    }

    private function generate_webp_file( string $source_file, int $quality ): void {
        $extension = strtolower( (string) pathinfo( $source_file, PATHINFO_EXTENSION ) );
        if ( in_array( $extension, [ 'webp', 'svg' ], true ) ) {
            return;
        }

        $target_file = preg_replace( '/\.[^.]+$/', '.webp', $source_file );
        if ( ! is_string( $target_file ) || '' === $target_file ) {
            return;
        }

        $editor = wp_get_image_editor( $source_file );
        if ( is_wp_error( $editor ) ) {
            return;
        }

        if ( method_exists( $editor, 'set_quality' ) ) {
            $editor->set_quality( $quality );
        }

        $saved = $editor->save( $target_file, 'image/webp' );
        if ( is_wp_error( $saved ) ) {
            // Fallback for editors expecting mime_type in an args array.
            $editor = wp_get_image_editor( $source_file );
            if ( is_wp_error( $editor ) ) {
                return;
            }
            if ( method_exists( $editor, 'set_quality' ) ) {
                $editor->set_quality( $quality );
            }
            $editor->save(
                $target_file,
                [
                    'mime_type' => 'image/webp',
                    'quality' => $quality,
                ]
            );
        }
    }

    public function register_weekly_schedule( array $schedules ): array {
        if ( ! isset( $schedules['trq_weekly'] ) ) {
            $schedules['trq_weekly'] = [
                'interval' => 7 * DAY_IN_SECONDS,
                'display'  => 'Une fois par semaine (360 Tranquillite)',
            ];
        }

        return $schedules;
    }

    public function ensure_scheduled_event(): void {
        if ( ! wp_next_scheduled( 'trq_media_cleanup_weekly_event' ) ) {
            wp_schedule_event( time() + ( 4 * HOUR_IN_SECONDS ), 'trq_weekly', 'trq_media_cleanup_weekly_event' );
        }
    }

    public function run_scheduled_cleanup(): void {
        $core = TRQ_Core::get_instance();
        if ( ! $core->get( 'media_cleanup_enabled', false ) || ! $core->get( 'media_cleanup_auto_enabled', false ) ) {
            return;
        }

        $this->scan_and_delete_orphans( false );
    }

    public function run_manual_cleanup(): array {
        if ( ! TRQ_Core::get_instance()->get( 'media_cleanup_enabled', false ) ) {
            return [
                'success' => false,
                'message' => 'Activez le module Medias avant de lancer une analyse.',
            ];
        }

        $report = $this->scan_and_delete_orphans( true );

        $message = ! empty( $report['dry_run'] )
            ? sprintf( 'Simulation terminee. Orphelins detectes : %d.', (int) ( $report['orphans_found'] ?? 0 ) )
            : sprintf( 'Nettoyage termine. Medias supprimés : %d.', (int) ( $report['deleted'] ?? 0 ) );

        return [
            'success' => true,
            'message' => $message,
        ];
    }

    public function get_last_report(): array {
        $report = get_option( 'trq_last_media_cleanup_report', [] );
        return is_array( $report ) ? $report : [];
    }

    public function get_log_tail( int $max_bytes = 12000 ): string {
        $file = $this->get_log_file_path();
        if ( ! file_exists( $file ) ) {
            return 'Aucun log disponible.';
        }

        $content = file_get_contents( $file );
        if ( false === $content || '' === $content ) {
            return 'Aucun log disponible.';
        }

        return substr( $content, -1 * max( 1000, $max_bytes ) );
    }

    public function cleanup_post_media( int $post_id ): void {
        if ( ! TRQ_Core::get_instance()->get( 'media_cleanup_enabled', false ) ) {
            return;
        }

        if ( 'attachment' === get_post_type( $post_id ) ) {
            return;
        }

        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            return;
        }

        $media_ids = [];

        $thumb_id = (int) get_post_thumbnail_id( $post_id );
        if ( $thumb_id > 0 ) {
            $media_ids[] = $thumb_id;
        }

        if ( ! empty( $post->post_content ) && preg_match_all( '/wp-image-([0-9]+)/', (string) $post->post_content, $matches ) ) {
            foreach ( (array) ( $matches[1] ?? [] ) as $candidate ) {
                $media_ids[] = (int) $candidate;
            }
        }

        $children = get_children(
            [
                'post_parent' => $post_id,
                'post_type'   => 'attachment',
                'fields'      => 'ids',
            ]
        );

        foreach ( (array) $children as $child_id ) {
            $media_ids[] = (int) $child_id;
        }

        $media_ids = array_values( array_filter( array_unique( $media_ids ) ) );

        foreach ( $media_ids as $media_id ) {
            if ( ! wp_attachment_is_image( $media_id ) ) {
                continue;
            }

            if ( $this->is_media_used_universally( $media_id, $post_id ) ) {
                continue;
            }

            if ( TRQ_Core::get_instance()->get( 'media_cleanup_dry_run', true ) ) {
                $this->log( sprintf( '[SIMUL] Post %d supprime : media %d serait supprimé.', $post_id, $media_id ) );
                continue;
            }

            $deleted = wp_delete_attachment( $media_id, true );
            if ( $deleted ) {
                $this->log( sprintf( '[REEL] Post %d supprime : media %d supprimé.', $post_id, $media_id ) );
            } else {
                $this->log( sprintf( '[ERREUR] Echec suppression media %d (post %d).', $media_id, $post_id ) );
            }
        }
    }

    private function scan_and_delete_orphans( bool $is_manual ): array {
        $dry_run = (bool) TRQ_Core::get_instance()->get( 'media_cleanup_dry_run', true );
        $min_age_days = max( 1, (int) TRQ_Core::get_instance()->get( 'media_cleanup_min_age_days', 30 ) );

        $ids = get_posts(
            [
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'post_mime_type' => 'image',
                'fields'         => 'ids',
                'posts_per_page' => -1,
            ]
        );

        $report = [
            'generated_at'        => current_time( 'mysql', true ),
            'is_manual'           => $is_manual,
            'dry_run'             => $dry_run,
            'total_checked'       => 0,
            'orphans_found'       => 0,
            'deleted'             => 0,
            'failed_deletes'      => 0,
            'skipped_recent'      => 0,
            'kept_as_referenced'  => 0,
            'samples'             => [],
        ];

        $this->log( $dry_run ? '--- SIMULATION CLEANUP MEDIAS ---' : '--- NETTOYAGE REEL MEDIAS ---' );

        foreach ( (array) $ids as $attachment_id ) {
            $attachment_id = (int) $attachment_id;
            $report['total_checked']++;

            $created = get_post_time( 'U', true, $attachment_id );
            if ( $created > 0 && $created > ( time() - ( $min_age_days * DAY_IN_SECONDS ) ) ) {
                $report['skipped_recent']++;
                continue;
            }

            if ( $this->is_media_used_universally( $attachment_id ) ) {
                $report['kept_as_referenced']++;
                continue;
            }

            $report['orphans_found']++;

            $file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
            $name = '' !== $file ? basename( $file ) : ( 'ID ' . $attachment_id );

            if ( count( $report['samples'] ) < 15 ) {
                $report['samples'][] = [ 'id' => $attachment_id, 'name' => $name ];
            }

            if ( $dry_run ) {
                $this->log( sprintf( '[SIMUL] Orphelin detecte: %s (ID: %d)', $name, $attachment_id ) );
                continue;
            }

            $deleted = wp_delete_attachment( $attachment_id, true );
            if ( $deleted ) {
                $report['deleted']++;
                $this->log( sprintf( '[REEL] Suppression: %s (ID: %d)', $name, $attachment_id ) );
            } else {
                $report['failed_deletes']++;
                $this->log( sprintf( '[ERREUR] Echec suppression: %s (ID: %d)', $name, $attachment_id ) );
            }
        }

        $this->log( '--- FIN CLEANUP MEDIAS ---' );

        update_option( 'trq_last_media_cleanup_report', $report, false );

        if ( $is_manual ) {
            $subject = $dry_run ? 'Rapport simulation nettoyage medias' : 'Rapport nettoyage medias';
            $message = sprintf(
                "Verifiés: %d\nOrphelins: %d\nSupprimés: %d\nEchecs: %d\nRecents ignorés: %d",
                (int) $report['total_checked'],
                (int) $report['orphans_found'],
                (int) $report['deleted'],
                (int) $report['failed_deletes'],
                (int) $report['skipped_recent']
            );
            TRQ_Core::notify( $subject, $message );
        }

        return $report;
    }

    private function is_media_used_universally( int $attachment_id, ?int $exclude_post_id = null ): bool {
        global $wpdb;

        $relative = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
        $url      = (string) wp_get_attachment_url( $attachment_id );
        $file     = '' !== $relative ? basename( $relative ) : '';

        if ( '' === $file ) {
            return true;
        }

        foreach ( $this->get_protected_keywords() as $word ) {
            if ( false !== stripos( $file, $word ) ) {
                return true;
            }
        }

        $thumbnail_count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} pm WHERE pm.meta_key = '_thumbnail_id' AND pm.meta_value = %d",
                $attachment_id
            )
        );
        if ( $thumbnail_count > 0 ) {
            return true;
        }

        $prefix = $wpdb->esc_like( $wpdb->prefix ) . '%';
        $tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prefix ) );

        $tokens = array_values( array_unique( array_filter( [ $file, $relative, $url ] ) ) );
        if ( empty( $tokens ) ) {
            return true;
        }

        foreach ( $tables as $table ) {
            $table_name = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $table );
            if ( '' === $table_name ) {
                continue;
            }

            $columns = (array) $wpdb->get_results( "DESCRIBE `{$table_name}`", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ( empty( $columns ) ) {
                continue;
            }

            $text_columns = [];
            foreach ( $columns as $column ) {
                $field = (string) ( $column['Field'] ?? '' );
                $type  = strtolower( (string) ( $column['Type'] ?? '' ) );
                if ( '' === $field ) {
                    continue;
                }
                if ( false !== strpos( $type, 'char' ) || false !== strpos( $type, 'text' ) ) {
                    $text_columns[] = $field;
                }
            }

            if ( empty( $text_columns ) ) {
                continue;
            }

            $where_parts = [];
            $params = [];

            foreach ( $text_columns as $column ) {
                $safe_col = preg_replace( '/[^a-zA-Z0-9_]/', '', $column );
                if ( '' === $safe_col ) {
                    continue;
                }

                foreach ( $tokens as $token ) {
                    $where_parts[] = "`{$safe_col}` LIKE %s";
                    $params[] = '%' . $wpdb->esc_like( $token ) . '%';
                }
            }

            if ( empty( $where_parts ) ) {
                continue;
            }

            $where_sql = implode( ' OR ', $where_parts );
            $query = "SELECT 1 FROM `{$table_name}` WHERE ({$where_sql})";

            if ( $table_name === $wpdb->posts && null !== $exclude_post_id ) {
                $query .= ' AND ID != %d';
                $params[] = $exclude_post_id;
            }

            $query .= ' LIMIT 1';

            $prepared = $wpdb->prepare( $query, $params );
            $exists = $wpdb->get_var( $prepared ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            if ( null !== $exists ) {
                return true;
            }
        }

        return false;
    }

    private function get_protected_keywords(): array {
        $raw = (string) TRQ_Core::get_instance()->get( 'media_cleanup_protected_keywords', 'logo,icon,favicon,placeholder,banner,default' );
        $parts = array_map( 'trim', explode( ',', strtolower( $raw ) ) );
        $parts = array_filter( $parts, static fn( $word ) => '' !== $word );
        return array_values( array_unique( $parts ) );
    }

    private function get_log_file_path(): string {
        $upload_dir = wp_upload_dir();
        return trailingslashit( $upload_dir['basedir'] ) . '360media-cleanup.log';
    }

    private function log( string $message ): void {
        $entry = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $message . "\n";
        file_put_contents( $this->get_log_file_path(), $entry, FILE_APPEND );
    }
}
