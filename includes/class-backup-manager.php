<?php
/**
 * Gestionnaire de sauvegardes locales, cloud et restauration.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRQ_Backup_Manager {

    private static ?TRQ_Backup_Manager $instance = null;

    private bool $booted = false;

    private const BACKUP_PREFIX = '360tranquilite-backup-';
    private const GOOGLE_AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const GOOGLE_TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const GOOGLE_REVOKE_ENDPOINT = 'https://oauth2.googleapis.com/revoke';
    private const GOOGLE_USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v2/userinfo';
    private const GOOGLE_FILES_ENDPOINT = 'https://www.googleapis.com/drive/v3/files';
    private const GOOGLE_UPLOAD_ENDPOINT = 'https://www.googleapis.com/upload/drive/v3/files';
    private const GOOGLE_DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.file https://www.googleapis.com/auth/drive.metadata.readonly https://www.googleapis.com/auth/userinfo.email https://www.googleapis.com/auth/userinfo.profile';
    private const DUMP_BATCH_SIZE = 200;
    private const RESTORE_BATCH_SIZE = 25;
    private const PROGRESS_OPTION = 'trq_backup_progress';
    private const MANUAL_ASYNC_HOOK = 'trq_run_backup_manual_async';

    private function __construct() {}

    public static function get_instance(): TRQ_Backup_Manager {
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
        add_action( 'trq_run_backup', [ $this, 'run_scheduled_backup' ] );
        add_action( self::MANUAL_ASYNC_HOOK, [ $this, 'run_manual_backup_async' ] );
        $this->ensure_schedule();
    }

    public function run_scheduled_backup(): void {
        $result = $this->run_backup( 'scheduled' );
        set_transient( 'trq_admin_notice', $result, 60 );
        $this->ensure_schedule();
    }

    public function run_manual_backup_async(): void {
        $this->run_backup( 'manual_async' );
    }

    public function start_manual_backup_async(): array {
        $progress = $this->get_backup_progress();
        if ( ! empty( $progress['in_progress'] ) ) {
            return [
                'success' => true,
                'message' => 'Une sauvegarde est déjà en cours.',
            ];
        }

        $this->update_backup_progress( 4, 'queued', 'Sauvegarde mise en file d’attente...', true );
        wp_clear_scheduled_hook( self::MANUAL_ASYNC_HOOK );
        $scheduled = wp_schedule_single_event( time() + 1, self::MANUAL_ASYNC_HOOK );

        if ( false === $scheduled || is_wp_error( $scheduled ) ) {
            $this->finish_backup_progress( false, 'Impossible de planifier la sauvegarde manuelle.' );

            return [
                'success' => false,
                'message' => 'Impossible de planifier la sauvegarde manuelle.',
            ];
        }

        if ( function_exists( 'spawn_cron' ) ) {
            spawn_cron();
        }

        return [
            'success' => true,
            'message' => 'Sauvegarde lancée en arrière-plan.',
        ];
    }

    public function ensure_schedule(): void {
        $core = TRQ_Core::get_instance();
        $enabled = (bool) $core->get( 'backup_enabled' );
        $signature = $this->get_schedule_signature();
        $stored_signature = get_option( 'trq_backup_schedule_signature', '' );
        $next_run = wp_next_scheduled( 'trq_run_backup' );

        if ( ! $enabled ) {
            if ( $next_run ) {
                wp_clear_scheduled_hook( 'trq_run_backup' );
            }
            delete_option( 'trq_backup_schedule_signature' );
            return;
        }

        if ( $next_run && $stored_signature === $signature ) {
            return;
        }

        wp_clear_scheduled_hook( 'trq_run_backup' );
        $timestamp = $this->get_next_run_timestamp();
        if ( $timestamp > time() ) {
            wp_schedule_single_event( $timestamp, 'trq_run_backup' );
            update_option( 'trq_backup_schedule_signature', $signature, false );
        }
    }

    public function run_backup( string $trigger = 'manual' ): array {
        $core = TRQ_Core::get_instance();
        $this->update_backup_progress( 2, 'init', 'Préparation de la sauvegarde...', true );

        if ( ! class_exists( 'ZipArchive' ) ) {
            $this->finish_backup_progress( false, 'ZipArchive est requis pour créer les sauvegardes.' );
            return [
                'success' => false,
                'message' => 'ZipArchive est requis pour créer les sauvegardes.',
            ];
        }

        if ( ! $core->get( 'backup_include_files' ) && ! $core->get( 'backup_include_database' ) ) {
            $this->finish_backup_progress( false, 'Activez au moins la sauvegarde des fichiers ou de la base de données.' );
            return [
                'success' => false,
                'message' => 'Activez au moins la sauvegarde des fichiers ou de la base de données.',
            ];
        }

        $backup_dir = $this->get_backup_base_dir();
        if ( ! wp_mkdir_p( $backup_dir ) ) {
            $this->finish_backup_progress( false, 'Impossible de créer le dossier local des sauvegardes.' );
            return [
                'success' => false,
                'message' => 'Impossible de créer le dossier local des sauvegardes.',
            ];
        }

        $keep_local = (bool) $core->get( 'backup_destination_local' );
        $use_google_drive = (bool) $core->get( 'backup_destination_google_drive' );
        $use_s3 = (bool) $core->get( 'backup_destination_s3' );

        if ( ! $keep_local && ! $use_google_drive && ! $use_s3 ) {
            $this->finish_backup_progress( false, 'Activez au moins une destination de sauvegarde: locale ou cloud.' );
            return [
                'success' => false,
                'message' => 'Activez au moins une destination de sauvegarde: locale ou cloud.',
            ];
        }

        $mode = $this->get_backup_mode();
        $set_id = gmdate( 'Ymd-His' );
        $archive_name = self::BACKUP_PREFIX . $mode . '-' . $set_id . '.zip';
        $work_dir = trailingslashit( $backup_dir ) . 'tmp-' . uniqid( 'trqb-', true );

        if ( ! wp_mkdir_p( $work_dir ) ) {
            $this->finish_backup_progress( false, 'Impossible de créer le dossier temporaire de sauvegarde.' );
            return [
                'success' => false,
                'message' => 'Impossible de créer le dossier temporaire de sauvegarde.',
            ];
        }

        $archive_path = trailingslashit( $keep_local ? $backup_dir : $work_dir ) . $archive_name;

        $manifest_data = [
            'files'        => [],
            'manifest'     => [],
            'deleted_files'=> [],
            'scanned_files'=> 0,
        ];
        $database_path = '';
        $database_tables = 0;
        $google_drive = [ 'enabled' => false, 'uploaded' => false, 'message' => 'Google Drive désactivé.' ];
        $s3 = [ 'enabled' => false, 'uploaded' => false, 'message' => 'S3 compatible désactivé.' ];
        $stored_locally = $keep_local;

        try {
            if ( $core->get( 'backup_include_files' ) ) {
                $this->update_backup_progress( 18, 'files', 'Collecte des fichiers WordPress...', true );
                $manifest_data = $this->collect_site_files( $mode, $backup_dir );
                $this->update_backup_progress( 42, 'files', 'Fichiers collectés : ' . count( $manifest_data['files'] ), true );
            }

            if ( $core->get( 'backup_include_database' ) ) {
                $this->update_backup_progress( 52, 'database', 'Export de la base de données...', true );
                $database_path = trailingslashit( $work_dir ) . 'database.sql';
                $database_tables = $this->dump_database_to_file( $database_path );
                $this->update_backup_progress( 60, 'database', 'Tables SQL exportées : ' . $database_tables, true );
            }

            $metadata = [
                'generated_at'   => current_time( 'mysql', true ),
                'trigger'        => $trigger,
                'mode'           => $mode,
                'site_url'       => home_url(),
                'wp_version'     => get_bloginfo( 'version' ),
                'plugin_version' => defined( 'TRQ_VERSION' ) ? TRQ_VERSION : 'unknown',
                'included_files' => count( $manifest_data['files'] ),
                'scanned_files'  => (int) $manifest_data['scanned_files'],
                'deleted_files'  => $manifest_data['deleted_files'],
                'database_tables'=> $database_tables,
            ];

            $zip = new ZipArchive();
            if ( true !== $zip->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
                throw new RuntimeException( 'Impossible d’ouvrir l’archive de sauvegarde.' );
            }

            $this->update_backup_progress( 68, 'archive', 'Création de l’archive ZIP...', true );

            if ( $database_path && file_exists( $database_path ) ) {
                $zip->addFile( $database_path, 'database.sql' );
            }

            foreach ( $manifest_data['files'] as $path ) {
                $relative = ltrim( str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $path ) ), '/' );
                $zip->addFile( $path, 'site/' . $relative );
            }

            $zip->addFromString(
                'backup-metadata.json',
                wp_json_encode( $metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES )
            );
            $zip->close();

            if ( ! file_exists( $archive_path ) ) {
                throw new RuntimeException( 'L’archive n’a pas été créée.' );
            }

            $this->update_backup_progress( 76, 'archive', 'Archive créée : ' . $archive_name, true );

            update_option( 'trq_backup_manifest', $manifest_data['manifest'], false );

            if ( $use_google_drive ) {
                $this->update_backup_progress( 84, 'google_drive', 'Envoi vers Google Drive...', true );
                $google_drive = $this->upload_to_google_drive( $archive_path, $archive_name );
            }

            if ( $use_s3 ) {
                $this->update_backup_progress( 90, 's3', 'Envoi vers le stockage S3...', true );
                $s3 = $this->upload_to_s3( $archive_path, $archive_name );
            }

            if ( ! $keep_local && empty( $google_drive['uploaded'] ) && empty( $s3['uploaded'] ) ) {
                $fallback_local_path = trailingslashit( $backup_dir ) . $archive_name;
                $filesystem = $this->get_filesystem();
                if ( $filesystem && $filesystem->move( $archive_path, $fallback_local_path, true ) ) {
                    $archive_path = $fallback_local_path;
                    $stored_locally = true;
                    if ( ! empty( $use_google_drive ) ) {
                        $google_drive['message'] .= ' Une copie locale de secours a été conservée.';
                    }
                    if ( ! empty( $use_s3 ) ) {
                        $s3['message'] .= ' Une copie locale de secours a été conservée.';
                    }
                }
            }

            if ( $stored_locally ) {
                $this->apply_local_retention();
            }
            if ( ! empty( $google_drive['uploaded'] ) ) {
                $this->apply_google_drive_retention();
            }
            if ( ! empty( $s3['uploaded'] ) ) {
                $this->apply_s3_retention();
            }

            if ( ! $stored_locally && file_exists( $archive_path ) ) {
                $filesystem = $this->get_filesystem();
                if ( $filesystem ) {
                    $filesystem->delete( $archive_path, false, 'f' );
                }
            }

            $this->update_backup_progress( 97, 'finalizing', 'Finalisation et nettoyage...', true );

            $report = [
                'generated_at'   => current_time( 'mysql', true ),
                'trigger'        => $trigger,
                'success'        => true,
                'mode'           => $mode,
                'archive_name'   => $archive_name,
                'archive_path'   => $stored_locally ? $archive_path : '',
                'stored_locally' => $stored_locally,
                'archive_size'   => filesize( $archive_path ),
                'included_files' => count( $manifest_data['files'] ),
                'scanned_files'  => (int) $manifest_data['scanned_files'],
                'deleted_files'  => count( $manifest_data['deleted_files'] ),
                'database_tables'=> $database_tables,
                'google_drive'   => $google_drive,
                's3'             => $s3,
                'next_run'       => wp_next_scheduled( 'trq_run_backup' ),
            ];

            update_option( 'trq_last_backup_report', $report, false );

            TRQ_Core::notify(
                'Sauvegarde ' . $mode . ' terminée',
                'Archive créée : ' . $archive_name . "\n" .
                'Fichiers inclus : ' . count( $manifest_data['files'] ) . "\n" .
                'Tables SQL exportées : ' . $database_tables . "\n" .
                'Google Drive : ' . ( ! empty( $google_drive['message'] ) ? $google_drive['message'] : 'non utilisé' ) . "\n" .
                'S3 : ' . ( ! empty( $s3['message'] ) ? $s3['message'] : 'non utilisé' )
            );

            $this->finish_backup_progress( true, 'Sauvegarde terminée : ' . $archive_name, $report );

            return [
                'success' => true,
                'message' => 'Sauvegarde ' . $mode . ' créée avec succès : ' . $archive_name,
            ];
        } catch ( Throwable $exception ) {
            update_option(
                'trq_last_backup_report',
                [
                    'generated_at' => current_time( 'mysql', true ),
                    'trigger'      => $trigger,
                    'success'      => false,
                    'mode'         => $mode,
                    'message'      => $exception->getMessage(),
                ],
                false
            );

            $this->finish_backup_progress( false, 'Échec de la sauvegarde : ' . $exception->getMessage() );

            return [
                'success' => false,
                'message' => 'Échec de la sauvegarde : ' . $exception->getMessage(),
            ];
        } finally {
            $this->cleanup_work_dir( $work_dir );
            if ( 'scheduled' === $trigger ) {
                $this->ensure_schedule();
            }
        }
    }

    public function get_last_report(): array {
        $report = get_option( 'trq_last_backup_report', [] );
        if ( ! is_array( $report ) ) {
            return [];
        }

        return $report;
    }

    public function get_last_restore_report(): array {
        $report = get_option( 'trq_last_restore_report', [] );
        if ( ! is_array( $report ) ) {
            return [];
        }

        return $report;
    }

    public function get_google_drive_connection_status(): array {
        $core = TRQ_Core::get_instance();
        $client = $this->get_google_drive_oauth_client();
        $connector = $this->get_google_drive_connector_config();

        return [
            'connector_enabled' => ! empty( $connector['enabled'] ),
            'configured'   => ! empty( $client['client_id'] ) && ! empty( $client['client_secret'] ),
            'connected'    => '' !== (string) $core->get( 'backup_google_drive_refresh_token' ),
            'email'        => (string) $core->get( 'backup_google_drive_account_email' ),
            'connected_at' => (string) $core->get( 'backup_google_drive_connected_at' ),
        ];
    }

    public function get_google_drive_setup_context(): array {
        $connector = $this->get_google_drive_connector_config();

        return [
            'redirect_uri' => $this->get_google_drive_redirect_uri(),
            'connector_enabled' => ! empty( $connector['enabled'] ),
            'wp_config_snippet' => "define( 'TRQ_GOOGLE_DRIVE_CLIENT_ID', 'votre-client-id-google' );\n" .
                "define( 'TRQ_GOOGLE_DRIVE_CLIENT_SECRET', 'votre-client-secret-google' );",
        ];
    }

    public function list_google_drive_folders( string $parent_id = 'root' ): array {
        $token = $this->get_google_drive_runtime_access_token();
        if ( empty( $token['success'] ) || empty( $token['access_token'] ) ) {
            return [
                'success' => false,
                'message' => $token['message'] ?? 'Impossible de joindre Google Drive.',
            ];
        }

        $parent_id = '' !== $parent_id ? $parent_id : 'root';
        $query = sprintf(
            "trashed = false and mimeType = 'application/vnd.google-apps.folder' and '%s' in parents",
            str_replace( "'", "\\'", $parent_id )
        );

        $response = wp_remote_get(
            add_query_arg(
                [
                    'q' => $query,
                    'fields' => 'files(id,name)',
                    'orderBy' => 'name_natural',
                    'pageSize' => 200,
                    'supportsAllDrives' => 'true',
                    'includeItemsFromAllDrives' => 'true',
                ],
                self::GOOGLE_FILES_ENDPOINT
            ),
            [
                'timeout' => 30,
                'headers' => [ 'Authorization' => 'Bearer ' . $token['access_token'] ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $payload = json_decode( wp_remote_retrieve_body( $response ), true );
        $folders = [];

        foreach ( (array) ( $payload['files'] ?? [] ) as $folder ) {
            if ( empty( $folder['id'] ) || empty( $folder['name'] ) ) {
                continue;
            }

            $folders[] = [
                'id' => (string) $folder['id'],
                'name' => (string) $folder['name'],
            ];
        }

        return [
            'success' => true,
            'parent_id' => $parent_id,
            'folders' => $folders,
        ];
    }

    public function get_google_drive_auth_url(): array {
        $connector = $this->get_google_drive_connector_config();
        if ( ! empty( $connector['enabled'] ) ) {
            return $this->build_google_drive_connector_auth_url();
        }

        $client = $this->get_google_drive_oauth_client();
        if ( empty( $client['client_id'] ) || empty( $client['client_secret'] ) ) {
            return [
                'success' => false,
                'message' => 'Le client OAuth Google Drive du plugin n’est pas encore configuré.',
            ];
        }

        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            return [
                'success' => false,
                'message' => 'Session administrateur invalide pour lancer la connexion Google Drive.',
            ];
        }

        $state = wp_generate_password( 48, false, false );
        set_transient( 'trq_google_drive_oauth_state_' . $user_id, $state, 10 * MINUTE_IN_SECONDS );

        $url = add_query_arg(
            [
                'client_id' => $client['client_id'],
                'redirect_uri' => $this->get_google_drive_redirect_uri(),
                'response_type' => 'code',
                'scope' => self::GOOGLE_DRIVE_SCOPE,
                'access_type' => 'offline',
                'prompt' => 'consent',
                'include_granted_scopes' => 'true',
                'state' => $state,
            ],
            self::GOOGLE_AUTH_ENDPOINT
        );

        return [
            'success' => true,
            'url' => $url,
        ];
    }

    public function complete_google_drive_auth( string $code, string $state, int $user_id ): array {
        if ( '' === $code || '' === $state || $user_id <= 0 ) {
            return [
                'success' => false,
                'message' => 'Réponse OAuth Google Drive incomplète.',
            ];
        }

        $expected_state = get_transient( 'trq_google_drive_oauth_state_' . $user_id );
        delete_transient( 'trq_google_drive_oauth_state_' . $user_id );

        if ( ! is_string( $expected_state ) || '' === $expected_state || ! hash_equals( $expected_state, $state ) ) {
            return [
                'success' => false,
                'message' => 'État OAuth invalide. Recommencez la connexion Google Drive.',
            ];
        }

        $client = $this->get_google_drive_oauth_client();
        if ( empty( $client['client_id'] ) || empty( $client['client_secret'] ) ) {
            return [
                'success' => false,
                'message' => 'Le client OAuth Google Drive du plugin n’est pas configuré.',
            ];
        }

        $response = wp_remote_post(
            self::GOOGLE_TOKEN_ENDPOINT,
            [
                'timeout' => 30,
                'body'    => [
                    'code'          => $code,
                    'client_id'     => $client['client_id'],
                    'client_secret' => $client['client_secret'],
                    'redirect_uri'  => $this->get_google_drive_redirect_uri(),
                    'grant_type'    => 'authorization_code',
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $payload = json_decode( wp_remote_retrieve_body( $response ), true );
        $refresh_token = (string) ( $payload['refresh_token'] ?? TRQ_Core::get_instance()->get( 'backup_google_drive_refresh_token', '' ) );
        $access_token = (string) ( $payload['access_token'] ?? '' );

        if ( '' === $refresh_token || '' === $access_token ) {
            return [
                'success' => false,
                'message' => 'Google n’a pas renvoyé les jetons attendus. Vérifiez le client OAuth et recommencez.',
            ];
        }

        $profile = $this->get_google_drive_user_profile( $access_token );
        TRQ_Core::get_instance()->update( [
            'backup_destination_google_drive' => true,
            'backup_google_drive_refresh_token' => $refresh_token,
            'backup_google_drive_account_email' => (string) ( $profile['email'] ?? '' ),
            'backup_google_drive_connected_at' => current_time( 'mysql', true ),
        ] );

        return [
            'success' => true,
            'message' => 'Connexion Google Drive établie' . ( ! empty( $profile['email'] ) ? ' pour ' . $profile['email'] : '' ) . '.',
        ];
    }

    public function complete_google_drive_connector_auth( string $code, string $state, int $user_id ): array {
        if ( '' === $code || '' === $state || $user_id <= 0 ) {
            return [
                'success' => false,
                'message' => 'Réponse du connecteur Google Drive incomplète.',
            ];
        }

        $expected_state = get_transient( 'trq_google_drive_oauth_state_' . $user_id );
        delete_transient( 'trq_google_drive_oauth_state_' . $user_id );

        if ( ! is_string( $expected_state ) || '' === $expected_state || ! hash_equals( $expected_state, $state ) ) {
            return [
                'success' => false,
                'message' => 'État invalide lors du retour du connecteur Google Drive.',
            ];
        }

        $connector = $this->get_google_drive_connector_config();
        if ( empty( $connector['enabled'] ) || empty( $connector['exchange_url'] ) ) {
            return [
                'success' => false,
                'message' => 'Le connecteur Google Drive n’est pas configuré côté plugin.',
            ];
        }

        $response = wp_remote_post(
            $connector['exchange_url'],
            [
                'timeout' => 30,
                'body'    => [
                    'connector_code' => $code,
                    'state'          => $state,
                    'site_url'       => home_url(),
                    'callback_url'   => $this->get_google_drive_connector_callback_uri(),
                ],
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $payload = json_decode( wp_remote_retrieve_body( $response ), true );
        $refresh_token = (string) ( $payload['refresh_token'] ?? '' );
        $email = (string) ( $payload['email'] ?? '' );

        if ( '' === $refresh_token ) {
            return [
                'success' => false,
                'message' => 'Le connecteur Google Drive n’a pas renvoyé de refresh token exploitable.',
            ];
        }

        TRQ_Core::get_instance()->update( [
            'backup_destination_google_drive' => true,
            'backup_google_drive_refresh_token' => $refresh_token,
            'backup_google_drive_account_email' => $email,
            'backup_google_drive_connected_at' => current_time( 'mysql', true ),
        ] );

        return [
            'success' => true,
            'message' => 'Connexion Google Drive établie via le connecteur central' . ( '' !== $email ? ' pour ' . $email : '' ) . '.',
        ];
    }

    public function disconnect_google_drive(): array {
        $refresh_token = (string) TRQ_Core::get_instance()->get( 'backup_google_drive_refresh_token', '' );

        if ( '' !== $refresh_token ) {
            wp_remote_post(
                self::GOOGLE_REVOKE_ENDPOINT,
                [
                    'timeout' => 20,
                    'headers' => [ 'Content-Type' => 'application/x-www-form-urlencoded' ],
                    'body'    => [ 'token' => $refresh_token ],
                ]
            );
        }

        TRQ_Core::get_instance()->update( [
            'backup_destination_google_drive' => false,
            'backup_google_drive_refresh_token' => '',
            'backup_google_drive_account_email' => '',
            'backup_google_drive_connected_at' => '',
        ] );

        return [
            'success' => true,
            'message' => 'Connexion Google Drive supprimée.',
        ];
    }

    public function get_summary(): array {
        $last_report = $this->get_last_report();
        $backups = $this->list_local_backups();

        return [
            'enabled'        => (bool) TRQ_Core::get_instance()->get( 'backup_enabled' ),
            'next_run'       => wp_next_scheduled( 'trq_run_backup' ),
            'last_success'   => ! empty( $last_report['success'] ),
            'last_generated' => $last_report['generated_at'] ?? '',
            'last_size'      => (int) ( $last_report['archive_size'] ?? 0 ),
            'local_count'    => count( $backups ),
            'progress'       => $this->get_backup_progress( false ),
        ];
    }

    public function get_backup_progress( bool $preserve_completed_state = true ): array {
        $progress = get_option( self::PROGRESS_OPTION, [] );

        if ( ! is_array( $progress ) ) {
            $progress = [];
        }

        $normalized = array_merge(
            [
                'in_progress' => false,
                'percent' => 0,
                'phase' => '',
                'message' => '',
                'success' => null,
                'updated_at' => '',
            ],
            $progress
        );

        if ( ! $preserve_completed_state && empty( $normalized['in_progress'] ) ) {
            $normalized['percent'] = 0;
            $normalized['phase'] = '';
            $normalized['message'] = '';
            $normalized['success'] = null;
        }

        return $normalized;
    }

    private function update_backup_progress( int $percent, string $phase, string $message, bool $in_progress, array $extra = [] ): void {
        update_option(
            self::PROGRESS_OPTION,
            array_merge(
                [
                    'in_progress' => $in_progress,
                    'percent' => max( 0, min( 100, $percent ) ),
                    'phase' => $phase,
                    'message' => $message,
                    'success' => null,
                    'updated_at' => current_time( 'mysql', true ),
                ],
                $extra
            ),
            false
        );
    }

    private function finish_backup_progress( bool $success, string $message, array $extra = [] ): void {
        $this->update_backup_progress(
            100,
            $success ? 'completed' : 'failed',
            $message,
            false,
            array_merge( [ 'success' => $success ], $extra )
        );
    }

    public function list_local_backups(): array {
        $backup_dir = $this->get_backup_base_dir();
        if ( ! is_dir( $backup_dir ) ) {
            return [];
        }

        $pattern = trailingslashit( $backup_dir ) . self::BACKUP_PREFIX . '*.zip';
        $files = glob( $pattern );
        if ( ! is_array( $files ) ) {
            return [];
        }

        usort(
            $files,
            static function ( string $left, string $right ): int {
                return filemtime( $right ) <=> filemtime( $left );
            }
        );

        $result = [];
        foreach ( $files as $path ) {
            $result[] = [
                'name'     => basename( $path ),
                'path'     => $path,
                'size'     => filesize( $path ),
                'modified' => filemtime( $path ),
            ];
        }

        return $result;
    }

    public function list_google_drive_backups(): array {
        $token = $this->get_google_drive_runtime_access_token();
        if ( empty( $token['success'] ) || empty( $token['access_token'] ) ) {
            return [];
        }

        $folder_id = (string) TRQ_Core::get_instance()->get( 'backup_google_drive_folder_id' );
        $query = "trashed = false and mimeType != 'application/vnd.google-apps.folder' and name contains '" . self::BACKUP_PREFIX . "'";
        if ( '' !== $folder_id ) {
            $query .= " and '" . str_replace( "'", "\\'", $folder_id ) . "' in parents";
        }

        $response = wp_remote_get(
            add_query_arg(
                [
                    'q' => $query,
                    'fields' => 'files(id,name,size,modifiedTime)',
                    'orderBy' => 'modifiedTime desc',
                    'pageSize' => 50,
                    'supportsAllDrives' => 'true',
                    'includeItemsFromAllDrives' => 'true',
                ],
                self::GOOGLE_FILES_ENDPOINT
            ),
            [
                'timeout' => 30,
                'headers' => [ 'Authorization' => 'Bearer ' . $token['access_token'] ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $payload = json_decode( wp_remote_retrieve_body( $response ), true );
        $files = [];

        foreach ( (array) ( $payload['files'] ?? [] ) as $file ) {
            if ( empty( $file['id'] ) || empty( $file['name'] ) ) {
                continue;
            }

            $files[] = [
                'id' => (string) $file['id'],
                'name' => (string) $file['name'],
                'size' => (int) ( $file['size'] ?? 0 ),
                'modified' => ! empty( $file['modifiedTime'] ) ? strtotime( (string) $file['modifiedTime'] ) : 0,
            ];
        }

        return $files;
    }

    public function get_backup_download( string $file ): array {
        if ( ! $this->is_valid_backup_filename( $file ) ) {
            return [ 'success' => false, 'message' => 'Archive invalide.' ];
        }

        $path = trailingslashit( $this->get_backup_base_dir() ) . $file;
        if ( ! file_exists( $path ) ) {
            return [ 'success' => false, 'message' => 'Archive introuvable.' ];
        }

        return [ 'success' => true, 'path' => $path ];
    }

    public function delete_local_backup( string $file ): array {
        if ( ! $this->is_valid_backup_filename( $file ) ) {
            return [ 'success' => false, 'message' => 'Archive invalide.' ];
        }

        $path = trailingslashit( $this->get_backup_base_dir() ) . $file;
        if ( ! file_exists( $path ) ) {
            return [ 'success' => false, 'message' => 'Archive introuvable.' ];
        }

        $filesystem = $this->get_filesystem();
        if ( ! $filesystem || ! $filesystem->delete( $path, false, 'f' ) ) {
            return [ 'success' => false, 'message' => 'Suppression impossible.' ];
        }

        $last_report = get_option( 'trq_last_backup_report', [] );
        if ( is_array( $last_report ) ) {
            $reported_archive = (string) ( $last_report['archive_name'] ?? '' );
            $reported_path = basename( (string) ( $last_report['archive_path'] ?? '' ) );

            if ( $reported_archive === $file || $reported_path === $file ) {
                delete_option( 'trq_last_backup_report' );
            }
        }

        return [ 'success' => true, 'message' => 'Archive supprimée : ' . $file ];
    }

    public function import_uploaded_backup( $file, bool $restore_immediately = false ): array {
        if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || empty( $file['name'] ) ) {
            return [ 'success' => false, 'message' => 'Aucune archive ZIP fournie.' ];
        }

        if ( ! empty( $file['error'] ) ) {
            return [ 'success' => false, 'message' => 'Échec de l’envoi de l’archive ZIP.' ];
        }

        $original_name = sanitize_file_name( wp_unslash( (string) $file['name'] ) );
        if ( '.zip' !== strtolower( substr( $original_name, -4 ) ) ) {
            return [ 'success' => false, 'message' => 'Seules les archives ZIP sont acceptées.' ];
        }

        $tmp_name = (string) $file['tmp_name'];
        if ( ! is_uploaded_file( $tmp_name ) ) {
            return [ 'success' => false, 'message' => 'Le fichier importé n’est pas valide.' ];
        }

        $backup_dir = $this->get_backup_base_dir();
        if ( ! wp_mkdir_p( $backup_dir ) ) {
            return [ 'success' => false, 'message' => 'Impossible de préparer le dossier local des sauvegardes.' ];
        }

        $archive_name = self::BACKUP_PREFIX . 'imported-' . gmdate( 'Ymd-His' ) . '.zip';
        $destination = trailingslashit( $backup_dir ) . $archive_name;

        if ( ! move_uploaded_file( $tmp_name, $destination ) ) {
            return [ 'success' => false, 'message' => 'Impossible d’enregistrer l’archive importée sur le serveur.' ];
        }

        if ( $restore_immediately ) {
            $result = $this->restore_local_backup( $archive_name );
            if ( ! empty( $result['success'] ) ) {
                $result['message'] = 'Archive importée puis restaurée : ' . $archive_name;
            }

            return $result;
        }

        return [
            'success' => true,
            'message' => 'Archive importée avec succès : ' . $archive_name,
        ];
    }

    public function import_google_drive_backup( string $file_id, string $original_name = '', bool $restore_immediately = false ): array {
        if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $file_id ) ) {
            return [ 'success' => false, 'message' => 'Fichier Google Drive invalide.' ];
        }

        $token = $this->get_google_drive_runtime_access_token();
        if ( empty( $token['success'] ) || empty( $token['access_token'] ) ) {
            return [ 'success' => false, 'message' => $token['message'] ?? 'Impossible de joindre Google Drive.' ];
        }

        $backup_dir = $this->get_backup_base_dir();
        if ( ! wp_mkdir_p( $backup_dir ) ) {
            return [ 'success' => false, 'message' => 'Impossible de préparer le dossier local des sauvegardes.' ];
        }

        $archive_name = $this->build_imported_backup_filename( $original_name );
        $destination = trailingslashit( $backup_dir ) . $archive_name;
        $response = wp_remote_get(
            add_query_arg(
                [
                    'alt' => 'media',
                    'supportsAllDrives' => 'true',
                ],
                trailingslashit( self::GOOGLE_FILES_ENDPOINT ) . rawurlencode( $file_id )
            ),
            [
                'timeout' => 300,
                'stream' => true,
                'filename' => $destination,
                'headers' => [ 'Authorization' => 'Bearer ' . $token['access_token'] ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 || ! file_exists( $destination ) ) {
            $filesystem = $this->get_filesystem();
            if ( $filesystem && file_exists( $destination ) ) {
                $filesystem->delete( $destination, false, 'f' );
            }

            return [ 'success' => false, 'message' => 'Téléchargement Google Drive refusé (HTTP ' . $code . ').' ];
        }

        if ( $restore_immediately ) {
            $result = $this->restore_local_backup( $archive_name );
            if ( ! empty( $result['success'] ) ) {
                $result['message'] = 'Archive Google Drive importée puis restaurée : ' . $archive_name;
            }

            return $result;
        }

        return [
            'success' => true,
            'message' => 'Archive Google Drive importée avec succès : ' . $archive_name,
        ];
    }

    public function delete_google_drive_backup( string $file_id ): array {
        if ( ! preg_match( '/^[a-zA-Z0-9_-]+$/', $file_id ) ) {
            return [ 'success' => false, 'message' => 'Fichier Google Drive invalide.' ];
        }

        $token = $this->get_google_drive_runtime_access_token();
        if ( empty( $token['success'] ) || empty( $token['access_token'] ) ) {
            return [ 'success' => false, 'message' => $token['message'] ?? 'Impossible de joindre Google Drive.' ];
        }

        $response = wp_remote_request(
            trailingslashit( self::GOOGLE_FILES_ENDPOINT ) . rawurlencode( $file_id ),
            [
                'method' => 'DELETE',
                'timeout' => 30,
                'headers' => [ 'Authorization' => 'Bearer ' . $token['access_token'] ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 204 !== $code && 200 !== $code ) {
            return [ 'success' => false, 'message' => 'Suppression Google Drive refusée (HTTP ' . $code . ').' ];
        }

        return [
            'success' => true,
            'message' => 'Archive Google Drive supprimée.',
        ];
    }

    public function restore_local_backup( string $file ): array {
        if ( ! $this->is_valid_backup_filename( $file ) ) {
            return [ 'success' => false, 'message' => 'Archive invalide.' ];
        }

        $path = trailingslashit( $this->get_backup_base_dir() ) . $file;
        if ( ! file_exists( $path ) ) {
            return [ 'success' => false, 'message' => 'Archive introuvable.' ];
        }

        if ( ! class_exists( 'ZipArchive' ) ) {
            return [ 'success' => false, 'message' => 'ZipArchive est requis pour restaurer une sauvegarde.' ];
        }

        $filesystem = $this->get_filesystem();
        if ( ! $filesystem ) {
            return [ 'success' => false, 'message' => 'Accès au système de fichiers WordPress impossible.' ];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';

        $work_dir = trailingslashit( $this->get_backup_base_dir() ) . 'restore-' . uniqid( 'trqr-', true );
        if ( ! wp_mkdir_p( $work_dir ) ) {
            return [ 'success' => false, 'message' => 'Impossible de créer le dossier temporaire de restauration.' ];
        }

        $maintenance_path = trailingslashit( ABSPATH ) . '.maintenance';
        $report = [
            'generated_at' => current_time( 'mysql', true ),
            'archive_name' => $file,
            'success'      => false,
            'restored_files' => 0,
            'database_restored' => false,
        ];

        try {
            $this->enable_maintenance_mode( $maintenance_path, $filesystem );

            $unzipped = unzip_file( $path, $work_dir );
            if ( is_wp_error( $unzipped ) ) {
                throw new RuntimeException( $unzipped->get_error_message() );
            }

            $site_dir = trailingslashit( $work_dir ) . 'site';
            $database_path = trailingslashit( $work_dir ) . 'database.sql';

            if ( ! is_dir( $site_dir ) && ! file_exists( $database_path ) ) {
                throw new RuntimeException( 'Archive de restauration invalide: contenu exploitable introuvable.' );
            }

            if ( is_dir( $site_dir ) ) {
                $report['restored_files'] = $this->restore_directory_contents( $site_dir, ABSPATH, $filesystem );
            }

            if ( file_exists( $database_path ) ) {
                $this->import_sql_dump( $database_path );
                $report['database_restored'] = true;
            }

            $report['success'] = true;
            update_option( 'trq_last_restore_report', $report, false );

            TRQ_Core::notify(
                'Restauration terminée',
                'Archive restaurée : ' . $file . "\n" .
                'Fichiers restaurés : ' . (int) $report['restored_files'] . "\n" .
                'Base de données : ' . ( $report['database_restored'] ? 'oui' : 'non' )
            );

            return [
                'success' => true,
                'message' => 'Restauration terminée : ' . $file,
            ];
        } catch ( Throwable $exception ) {
            $report['message'] = $exception->getMessage();
            update_option( 'trq_last_restore_report', $report, false );

            return [
                'success' => false,
                'message' => 'Échec de la restauration : ' . $exception->getMessage(),
            ];
        } finally {
            $this->disable_maintenance_mode( $maintenance_path, $filesystem );
            $this->cleanup_work_dir( $work_dir );
        }
    }

    private function collect_site_files( string $mode, string $backup_dir ): array {
        $previous_manifest = get_option( 'trq_backup_manifest', [] );
        if ( ! is_array( $previous_manifest ) ) {
            $previous_manifest = [];
        }

        $manifest = [];
        $files = [];
        $scanned_files = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( ABSPATH, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }

            $path = $file->getPathname();
            if ( $this->should_exclude_path( $path, $backup_dir ) ) {
                continue;
            }

            $normalized = wp_normalize_path( $path );
            $entry = [
                'size'  => $file->getSize(),
                'mtime' => $file->getMTime(),
            ];

            $manifest[ $normalized ] = $entry;
            $scanned_files++;

            $changed = ! isset( $previous_manifest[ $normalized ] )
                || (int) $previous_manifest[ $normalized ]['size'] !== (int) $entry['size']
                || (int) $previous_manifest[ $normalized ]['mtime'] !== (int) $entry['mtime'];

            if ( 'full' === $mode || $changed ) {
                $files[] = $path;
            }
        }

        $deleted_files = array_values( array_diff( array_keys( $previous_manifest ), array_keys( $manifest ) ) );

        return [
            'files'         => $files,
            'manifest'      => $manifest,
            'deleted_files' => $deleted_files,
            'scanned_files' => $scanned_files,
        ];
    }

    private function dump_database_to_file( string $path ): int {
        global $wpdb;

        $tables = $wpdb->get_col( 'SHOW TABLES' );
        if ( ! is_array( $tables ) ) {
            throw new RuntimeException( 'Impossible de lire les tables de la base de données.' );
        }

        $handle = fopen( $path, 'wb' );
        if ( false === $handle ) {
            throw new RuntimeException( 'Impossible d’écrire le dump SQL.' );
        }

        fwrite( $handle, "-- 360 Tranquillité SQL backup\n" );
        fwrite( $handle, '-- Generated at: ' . gmdate( 'c' ) . "\n\n" );

        foreach ( $tables as $table ) {
            $create = $wpdb->get_row( 'SHOW CREATE TABLE `' . esc_sql( $table ) . '`', ARRAY_N );
            fwrite( $handle, 'DROP TABLE IF EXISTS `' . $table . '`;' . "\n" );
            fwrite( $handle, (string) ( $create[1] ?? '' ) . ';' . "\n\n" );

            $total_rows = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . esc_sql( $table ) . '`' );
            for ( $offset = 0; $offset < $total_rows; $offset += self::DUMP_BATCH_SIZE ) {
                $rows = $wpdb->get_results(
                    'SELECT * FROM `' . esc_sql( $table ) . '` LIMIT ' . (int) $offset . ', ' . self::DUMP_BATCH_SIZE,
                    ARRAY_A
                );

                if ( ! is_array( $rows ) || empty( $rows ) ) {
                    continue;
                }

                foreach ( $rows as $row ) {
                    $columns = array_map(
                        static function ( string $column ): string {
                            return '`' . $column . '`';
                        },
                        array_keys( $row )
                    );

                    $values = array_map( [ $this, 'sql_escape_value' ], array_values( $row ) );
                    fwrite(
                        $handle,
                        'INSERT INTO `' . $table . '` (' . implode( ', ', $columns ) . ') VALUES (' . implode( ', ', $values ) . ');' . "\n"
                    );
                }
                fwrite( $handle, "\n" );
            }
            fwrite( $handle, "\n" );
        }

        fclose( $handle );

        return count( $tables );
    }

    private function sql_escape_value( $value ): string {
        if ( null === $value ) {
            return 'NULL';
        }

        if ( is_bool( $value ) ) {
            return $value ? '1' : '0';
        }

        if ( is_numeric( $value ) && ! preg_match( '/^0[0-9]+$/', (string) $value ) ) {
            return (string) $value;
        }

        $string = (string) $value;
        $string = str_replace( [ '\\', "\0", "\n", "\r", "\x1a", "'" ], [ '\\\\', '\\0', '\\n', '\\r', '\\Z', "\\'" ], $string );

        return "'" . $string . "'";
    }

    private function should_exclude_path( string $path, string $backup_dir ): bool {
        $normalized = wp_normalize_path( $path );
        $normalized_backup_dir = wp_normalize_path( $backup_dir );

        if ( 0 === strpos( $normalized, $normalized_backup_dir . '/' ) ) {
            return true;
        }

        if ( TRQ_Core::get_instance()->get( 'backup_exclude_cache_dirs' ) ) {
            foreach ( [ '/cache/', '/upgrade/', '/wflogs/', '/updraft/', '/ai1wm-backups/', '/litespeed/' ] as $fragment ) {
                if ( false !== strpos( $normalized, $fragment ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    private function get_backup_mode(): string {
        $mode = TRQ_Core::get_instance()->get( 'backup_mode', 'full' );
        return in_array( $mode, [ 'full', 'incremental' ], true ) ? $mode : 'full';
    }

    private function get_backup_base_dir(): string {
        $upload_dir = wp_get_upload_dir();
        $subdir = TRQ_Core::get_instance()->get( 'backup_local_dir', '360tranquilite-backups' );
        $subdir = sanitize_title_with_dashes( $subdir ) ?: '360tranquilite-backups';

        return trailingslashit( $upload_dir['basedir'] ) . $subdir;
    }

    private function get_schedule_signature(): string {
        $core = TRQ_Core::get_instance();

        return md5( wp_json_encode( [
            'enabled'   => (bool) $core->get( 'backup_enabled' ),
            'mode'      => $core->get( 'backup_mode' ),
            'frequency' => $core->get( 'backup_frequency' ),
            'time'      => $core->get( 'backup_time' ),
            'dow'       => (int) $core->get( 'backup_day_of_week' ),
            'dom'       => (int) $core->get( 'backup_day_of_month' ),
        ] ) );
    }

    private function get_next_run_timestamp(): int {
        $core = TRQ_Core::get_instance();
        $timezone = wp_timezone();
        $now = new DateTimeImmutable( 'now', $timezone );

        $time_string = (string) $core->get( 'backup_time', '02:00' );
        [ $hour, $minute ] = array_pad( array_map( 'intval', explode( ':', $time_string ) ), 2, 0 );

        $frequency = $core->get( 'backup_frequency', 'daily' );
        $candidate = $now->setTime( $hour, $minute, 0 );

        if ( 'weekly' === $frequency ) {
            $target_day = (int) $core->get( 'backup_day_of_week', 1 );
            $current_day = (int) $candidate->format( 'w' );
            $delta = $target_day - $current_day;
            if ( $delta < 0 ) {
                $delta += 7;
            }
            $candidate = $candidate->modify( '+' . $delta . ' days' );
        } elseif ( 'monthly' === $frequency ) {
            $target_day = max( 1, min( 28, (int) $core->get( 'backup_day_of_month', 1 ) ) );
            $candidate = $candidate->setDate(
                (int) $candidate->format( 'Y' ),
                (int) $candidate->format( 'm' ),
                $target_day
            );
        }

        if ( $candidate <= $now ) {
            if ( 'weekly' === $frequency ) {
                $candidate = $candidate->modify( '+7 days' );
            } elseif ( 'monthly' === $frequency ) {
                $candidate = $candidate->modify( 'first day of next month' )->setDate(
                    (int) $candidate->modify( 'first day of next month' )->format( 'Y' ),
                    (int) $candidate->modify( 'first day of next month' )->format( 'm' ),
                    max( 1, min( 28, (int) $core->get( 'backup_day_of_month', 1 ) ) )
                )->setTime( $hour, $minute, 0 );
            } else {
                $candidate = $candidate->modify( '+1 day' );
            }
        }

        return $candidate->getTimestamp();
    }

    private function upload_to_google_drive( string $path, string $filename ): array {
        $core = TRQ_Core::get_instance();
        $token = $this->get_google_drive_runtime_access_token();
        if ( empty( $token['success'] ) || empty( $token['access_token'] ) ) {
            return [
                'enabled'  => true,
                'uploaded' => false,
                'message'  => $token['message'] ?? 'Impossible d’obtenir un token Google Drive.',
            ];
        }

        $metadata = [ 'name' => $filename ];
        $folder_id = (string) $core->get( 'backup_google_drive_folder_id' );
        if ( '' !== $folder_id ) {
            $metadata['parents'] = [ $folder_id ];
        }

        $created = $this->create_google_drive_file_with_metadata( $token['access_token'], $metadata, $path );
        if ( empty( $created['success'] ) ) {
            return [
                'enabled'  => true,
                'uploaded' => false,
                'message'  => $created['message'] ?? 'Échec de l’upload Google Drive.',
            ];
        }

        return [
            'enabled'  => true,
            'uploaded' => true,
            'file_id'  => $created['file_id'],
            'message'  => 'Archive envoyée vers Google Drive.',
        ];
    }

    private function create_google_drive_file_with_metadata( string $access_token, array $metadata, string $path ): array {
        $boundary = 'trqbackup-' . wp_generate_password( 12, false );
        $body = '';
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= wp_json_encode( $metadata ) . "\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: application/zip\r\n\r\n";
        $body .= file_get_contents( $path ) . "\r\n";
        $body .= '--' . $boundary . '--';

        $response = wp_remote_post(
            add_query_arg( [ 'uploadType' => 'multipart', 'fields' => 'id,name' ], self::GOOGLE_UPLOAD_ENDPOINT ),
            [
                'timeout' => 90,
                'headers' => [
                    'Authorization' => 'Bearer ' . $access_token,
                    'Content-Type'  => 'multipart/related; boundary=' . $boundary,
                ],
                'body'    => $body,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $payload = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $payload['id'] ) ) {
            return [ 'success' => false, 'message' => 'Réponse Google Drive invalide.' ];
        }

        return [ 'success' => true, 'file_id' => $payload['id'] ];
    }

    private function get_google_drive_access_token( string $client_id, string $client_secret, string $refresh_token ): array {
        if ( '' === $refresh_token ) {
            return [ 'success' => false, 'message' => 'Refresh token Google Drive absent.' ];
        }

        if ( '' === $client_id || '' === $client_secret ) {
            $connector = $this->get_google_drive_connector_config();
            if ( ! empty( $connector['enabled'] ) ) {
                return $this->get_google_drive_connector_access_token( $refresh_token );
            }

            return [ 'success' => false, 'message' => 'Identifiants Google Drive incomplets.' ];
        }

        $response = wp_remote_post(
            self::GOOGLE_TOKEN_ENDPOINT,
            [
                'timeout' => 30,
                'body'    => [
                    'client_id'     => $client_id,
                    'client_secret' => $client_secret,
                    'refresh_token' => $refresh_token,
                    'grant_type'    => 'refresh_token',
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $payload = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $payload['access_token'] ) ) {
            return [ 'success' => false, 'message' => $payload['error_description'] ?? 'Token Google Drive introuvable.' ];
        }

        return [ 'success' => true, 'access_token' => $payload['access_token'] ];
    }

    private function get_google_drive_runtime_access_token(): array {
        $core = TRQ_Core::get_instance();
        $client = $this->get_google_drive_oauth_client();

        return $this->get_google_drive_access_token(
            (string) ( $client['client_id'] ?? '' ),
            (string) ( $client['client_secret'] ?? '' ),
            (string) $core->get( 'backup_google_drive_refresh_token' )
        );
    }

    private function get_google_drive_user_profile( string $access_token ): array {
        $response = wp_remote_get(
            self::GOOGLE_USERINFO_ENDPOINT,
            [
                'timeout' => 20,
                'headers' => [ 'Authorization' => 'Bearer ' . $access_token ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $payload = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $payload ) ? $payload : [];
    }

    private function build_google_drive_connector_auth_url(): array {
        $connector = $this->get_google_drive_connector_config();
        if ( empty( $connector['enabled'] ) || empty( $connector['auth_url'] ) ) {
            return [
                'success' => false,
                'message' => 'Le connecteur Google Drive n’est pas configuré.',
            ];
        }

        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            return [
                'success' => false,
                'message' => 'Session administrateur invalide pour lancer la connexion Google Drive.',
            ];
        }

        $state = wp_generate_password( 48, false, false );
        set_transient( 'trq_google_drive_oauth_state_' . $user_id, $state, 10 * MINUTE_IN_SECONDS );

        return [
            'success' => true,
            'url' => add_query_arg(
                [
                    'site_url' => home_url(),
                    'state' => $state,
                    'return_url' => $this->get_google_drive_connector_callback_uri(),
                    'plugin' => '360tranquilite',
                ],
                $connector['auth_url']
            ),
        ];
    }

    private function get_google_drive_oauth_client(): array {
        $core = TRQ_Core::get_instance();
        $client_id = defined( 'TRQ_GOOGLE_DRIVE_CLIENT_ID' ) ? (string) TRQ_GOOGLE_DRIVE_CLIENT_ID : (string) $core->get( 'backup_google_drive_client_id', '' );
        $client_secret = defined( 'TRQ_GOOGLE_DRIVE_CLIENT_SECRET' ) ? (string) TRQ_GOOGLE_DRIVE_CLIENT_SECRET : (string) $core->get( 'backup_google_drive_client_secret', '' );

        $client = [
            'client_id' => $client_id,
            'client_secret' => $client_secret,
        ];

        $filtered = apply_filters( 'trq_google_drive_oauth_client', $client );
        return is_array( $filtered ) ? array_merge( $client, $filtered ) : $client;
    }

    private function get_google_drive_connector_config(): array {
        $base_url = defined( 'TRQ_GOOGLE_DRIVE_CONNECTOR_URL' ) ? (string) TRQ_GOOGLE_DRIVE_CONNECTOR_URL : '';
        $base_url = untrailingslashit( $base_url );

        $config = [
            'enabled' => '' !== $base_url,
            'base_url' => $base_url,
            'auth_url' => '' !== $base_url ? $base_url . '/connect' : '',
            'exchange_url' => '' !== $base_url ? $base_url . '/exchange' : '',
            'access_token_url' => '' !== $base_url ? $base_url . '/access-token' : '',
        ];

        $filtered = apply_filters( 'trq_google_drive_connector_config', $config );
        return is_array( $filtered ) ? array_merge( $config, $filtered ) : $config;
    }

    private function get_google_drive_redirect_uri(): string {
        return admin_url( 'admin-post.php?action=trq_google_drive_oauth_callback' );
    }

    private function get_google_drive_connector_callback_uri(): string {
        return admin_url( 'admin-post.php?action=trq_google_drive_connector_callback' );
    }

    private function get_google_drive_connector_access_token( string $refresh_token ): array {
        $connector = $this->get_google_drive_connector_config();
        if ( empty( $connector['enabled'] ) || empty( $connector['access_token_url'] ) ) {
            return [ 'success' => false, 'message' => 'Le connecteur Google Drive n’est pas disponible.' ];
        }

        $response = wp_remote_post(
            $connector['access_token_url'],
            [
                'timeout' => 30,
                'body' => [
                    'refresh_token' => $refresh_token,
                    'site_url' => home_url(),
                ],
                'headers' => [ 'Accept' => 'application/json' ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'message' => $response->get_error_message() ];
        }

        $payload = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $payload['access_token'] ) ) {
            return [ 'success' => false, 'message' => (string) ( $payload['message'] ?? 'Token Google Drive introuvable via le connecteur.' ) ];
        }

        return [ 'success' => true, 'access_token' => (string) $payload['access_token'] ];
    }

    private function upload_to_s3( string $path, string $filename ): array {
        $core = TRQ_Core::get_instance();
        $config = $this->get_s3_config();

        if ( empty( $config['valid'] ) ) {
            return [
                'enabled'  => true,
                'uploaded' => false,
                'message'  => 'Paramètres S3 incomplets.',
            ];
        }

        $object_key = $this->build_s3_object_key( (string) $core->get( 'backup_s3_prefix' ), $filename );
        $body = file_get_contents( $path );
        if ( false === $body ) {
            return [
                'enabled'  => true,
                'uploaded' => false,
                'message'  => 'Lecture locale impossible avant upload S3.',
            ];
        }

        $response = $this->perform_s3_request( 'PUT', $object_key, [
            'body'         => $body,
            'content_type' => 'application/zip',
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'enabled'  => true,
                'uploaded' => false,
                'message'  => $response->get_error_message(),
            ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return [
                'enabled'  => true,
                'uploaded' => false,
                'message'  => 'Upload S3 refusé (HTTP ' . $code . ').',
            ];
        }

        return [
            'enabled'  => true,
            'uploaded' => true,
            'key'      => $object_key,
            'message'  => 'Archive envoyée vers le stockage S3 compatible.',
        ];
    }

    private function apply_local_retention(): void {
        $retention = max( 1, (int) TRQ_Core::get_instance()->get( 'backup_retention_count', 5 ) );
        $backups = $this->list_local_backups();

        if ( count( $backups ) <= $retention ) {
            return;
        }

        $filesystem = $this->get_filesystem();
        if ( ! $filesystem ) {
            return;
        }

        foreach ( array_slice( $backups, $retention ) as $backup ) {
            $filesystem->delete( $backup['path'], false, 'f' );
        }
    }

    private function apply_google_drive_retention(): void {
        $core = TRQ_Core::get_instance();
        if ( ! $core->get( 'backup_destination_google_drive' ) ) {
            return;
        }

        $client = $this->get_google_drive_oauth_client();

        $token = $this->get_google_drive_access_token(
            (string) ( $client['client_id'] ?? '' ),
            (string) ( $client['client_secret'] ?? '' ),
            (string) $core->get( 'backup_google_drive_refresh_token' )
        );
        if ( empty( $token['success'] ) ) {
            return;
        }

        $folder_id = (string) $core->get( 'backup_google_drive_folder_id' );
        $query = "trashed = false and name contains '" . self::BACKUP_PREFIX . "'";
        if ( '' !== $folder_id ) {
            $query .= " and '" . $folder_id . "' in parents";
        }

        $response = wp_remote_get(
            add_query_arg(
                [
                    'q' => $query,
                    'fields' => 'files(id,name,createdTime)',
                    'orderBy' => 'createdTime desc',
                    'pageSize' => 100,
                ],
                self::GOOGLE_FILES_ENDPOINT
            ),
            [
                'timeout' => 30,
                'headers' => [ 'Authorization' => 'Bearer ' . $token['access_token'] ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return;
        }

        $payload = json_decode( wp_remote_retrieve_body( $response ), true );
        $files = is_array( $payload['files'] ?? null ) ? $payload['files'] : [];
        $retention = max( 1, (int) $core->get( 'backup_retention_count', 5 ) );

        foreach ( array_slice( $files, $retention ) as $file ) {
            if ( empty( $file['id'] ) ) {
                continue;
            }

            wp_remote_request(
                trailingslashit( self::GOOGLE_FILES_ENDPOINT ) . rawurlencode( $file['id'] ),
                [
                    'method'  => 'DELETE',
                    'timeout' => 30,
                    'headers' => [ 'Authorization' => 'Bearer ' . $token['access_token'] ],
                ]
            );
        }
    }

    private function apply_s3_retention(): void {
        $config = $this->get_s3_config();
        if ( empty( $config['valid'] ) ) {
            return;
        }

        $prefix = $this->build_s3_object_key( (string) TRQ_Core::get_instance()->get( 'backup_s3_prefix' ), self::BACKUP_PREFIX );
        $response = $this->perform_s3_request( 'GET', '', [
            'query' => [
                'list-type' => '2',
                'prefix'    => $prefix,
                'max-keys'  => '1000',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return;
        }

        $body = wp_remote_retrieve_body( $response );
        if ( '' === $body ) {
            return;
        }

        $xml = simplexml_load_string( $body );
        if ( false === $xml || empty( $xml->Contents ) ) {
            return;
        }

        $objects = [];
        foreach ( $xml->Contents as $content ) {
            $key = (string) $content->Key;
            if ( '' === $key ) {
                continue;
            }

            $objects[] = [
                'key'          => $key,
                'last_modified'=> strtotime( (string) $content->LastModified ),
            ];
        }

        usort(
            $objects,
            static function ( array $left, array $right ): int {
                return ( $right['last_modified'] ?? 0 ) <=> ( $left['last_modified'] ?? 0 );
            }
        );

        $retention = max( 1, (int) TRQ_Core::get_instance()->get( 'backup_retention_count', 5 ) );
        foreach ( array_slice( $objects, $retention ) as $object ) {
            $this->perform_s3_request( 'DELETE', (string) $object['key'] );
        }
    }

    private function enable_maintenance_mode( string $path, $filesystem ): void {
        $content = '<?php $upgrading = ' . time() . ';';
        $filesystem->put_contents( $path, $content, FS_CHMOD_FILE );
    }

    private function disable_maintenance_mode( string $path, $filesystem ): void {
        if ( file_exists( $path ) ) {
            $filesystem->delete( $path, false, 'f' );
        }
    }

    private function restore_directory_contents( string $source, string $destination, $filesystem ): int {
        $restored_files = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ( $iterator as $item ) {
            $relative = ltrim( str_replace( wp_normalize_path( $source ), '', wp_normalize_path( $item->getPathname() ) ), '/' );
            if ( '' === $relative ) {
                continue;
            }

            $target_path = trailingslashit( $destination ) . str_replace( '/', DIRECTORY_SEPARATOR, $relative );

            if ( $item->isDir() ) {
                if ( ! $filesystem->is_dir( $target_path ) ) {
                    $filesystem->mkdir( $target_path, FS_CHMOD_DIR );
                }
                continue;
            }

            $target_dir = dirname( $target_path );
            if ( ! $filesystem->is_dir( $target_dir ) ) {
                wp_mkdir_p( $target_dir );
            }

            if ( ! $filesystem->copy( $item->getPathname(), $target_path, true, FS_CHMOD_FILE ) ) {
                throw new RuntimeException( 'Impossible de restaurer le fichier : ' . $relative );
            }

            $restored_files++;
        }

        return $restored_files;
    }

    private function import_sql_dump( string $path ): void {
        global $wpdb;

        $handle = fopen( $path, 'rb' );
        if ( false === $handle ) {
            throw new RuntimeException( 'Impossible de lire le dump SQL pour la restauration.' );
        }

        $statement = '';

        while ( false !== ( $line = fgets( $handle ) ) ) {
            $trimmed = trim( $line );
            if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) ) {
                continue;
            }

            $statement .= $line;
            if ( ';' !== substr( rtrim( $trimmed ), -1 ) ) {
                continue;
            }

            $result = $wpdb->query( $statement );
            if ( false === $result ) {
                fclose( $handle );
                throw new RuntimeException( 'Erreur SQL pendant la restauration.' );
            }

            $statement = '';
        }

        fclose( $handle );
    }

    private function get_s3_config(): array {
        $core = TRQ_Core::get_instance();
        $endpoint = untrailingslashit( (string) $core->get( 'backup_s3_endpoint' ) );
        $region = (string) $core->get( 'backup_s3_region', 'us-east-1' );
        $bucket = (string) $core->get( 'backup_s3_bucket' );
        $access_key = (string) $core->get( 'backup_s3_access_key' );
        $secret_key = (string) $core->get( 'backup_s3_secret_key' );
        $path_style = (bool) $core->get( 'backup_s3_path_style', true );

        if ( '' === $endpoint ) {
            $endpoint = 'https://s3.' . $region . '.amazonaws.com';
        }

        $host = wp_parse_url( $endpoint, PHP_URL_HOST );
        $scheme = wp_parse_url( $endpoint, PHP_URL_SCHEME ) ?: 'https';
        $base_path = trim( (string) wp_parse_url( $endpoint, PHP_URL_PATH ), '/' );

        return [
            'valid'      => '' !== $endpoint && '' !== $region && '' !== $bucket && '' !== $access_key && '' !== $secret_key && '' !== (string) $host,
            'endpoint'   => $endpoint,
            'region'     => $region,
            'bucket'     => $bucket,
            'access_key' => $access_key,
            'secret_key' => $secret_key,
            'path_style' => $path_style,
            'host'       => (string) $host,
            'scheme'     => (string) $scheme,
            'base_path'  => $base_path,
        ];
    }

    private function build_s3_object_key( string $prefix, string $filename ): string {
        $prefix = trim( trim( $prefix ), '/' );
        if ( '' === $prefix ) {
            return ltrim( $filename, '/' );
        }

        return $prefix . '/' . ltrim( $filename, '/' );
    }

    private function perform_s3_request( string $method, string $object_key = '', array $args = [] ) {
        $config = $this->get_s3_config();
        if ( empty( $config['valid'] ) ) {
            return new WP_Error( 'trq_s3_config', 'Configuration S3 incomplète.' );
        }

        $query = [];
        if ( ! empty( $args['query'] ) && is_array( $args['query'] ) ) {
            $query = $args['query'];
            ksort( $query );
        }

        $body = (string) ( $args['body'] ?? '' );
        $content_type = (string) ( $args['content_type'] ?? '' );
        $timestamp = gmdate( 'Ymd\THis\Z' );
        $date = substr( $timestamp, 0, 8 );

        $canonical_uri = $this->build_s3_canonical_uri( $config, $object_key );
        $canonical_query = $this->build_s3_canonical_query( $query );
        $host = $this->build_s3_host( $config );
        $payload_hash = hash( 'sha256', $body );

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date' => $timestamp,
        ];

        if ( '' !== $content_type ) {
            $headers['content-type'] = $content_type;
        }

        ksort( $headers );

        $canonical_headers = '';
        foreach ( $headers as $name => $value ) {
            $canonical_headers .= strtolower( $name ) . ':' . trim( (string) $value ) . "\n";
        }

        $signed_headers = implode( ';', array_keys( $headers ) );
        $canonical_request = implode( "\n", [
            strtoupper( $method ),
            $canonical_uri,
            $canonical_query,
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ] );

        $credential_scope = $date . '/' . $config['region'] . '/s3/aws4_request';
        $string_to_sign = implode( "\n", [
            'AWS4-HMAC-SHA256',
            $timestamp,
            $credential_scope,
            hash( 'sha256', $canonical_request ),
        ] );

        $signing_key = $this->build_s3_signing_key( $date, $config['region'], $config['secret_key'] );
        $signature = hash_hmac( 'sha256', $string_to_sign, $signing_key );

        $headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $config['access_key'] . '/' . $credential_scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;
        $headers['Host'] = $host;
        unset( $headers['host'] );

        $url = $config['scheme'] . '://' . $host . $canonical_uri;
        if ( '' !== $canonical_query ) {
            $url .= '?' . $canonical_query;
        }

        return wp_remote_request(
            $url,
            [
                'method'  => strtoupper( $method ),
                'timeout' => 90,
                'headers' => $headers,
                'body'    => $body,
            ]
        );
    }

    private function build_s3_host( array $config ): string {
        if ( $config['path_style'] ) {
            return $config['host'];
        }

        return $config['bucket'] . '.' . $config['host'];
    }

    private function build_s3_canonical_uri( array $config, string $object_key ): string {
        $segments = [];

        if ( '' !== $config['base_path'] ) {
            $segments[] = trim( $config['base_path'], '/' );
        }

        if ( $config['path_style'] ) {
            $segments[] = rawurlencode( $config['bucket'] );
        }

        if ( '' !== $object_key ) {
            $segments[] = implode( '/', array_map( 'rawurlencode', explode( '/', ltrim( $object_key, '/' ) ) ) );
        }

        return '/' . implode( '/', array_filter( $segments, static fn( string $segment ): bool => '' !== $segment ) );
    }

    private function build_s3_canonical_query( array $query ): string {
        $pairs = [];
        foreach ( $query as $key => $value ) {
            $pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
        }

        return implode( '&', $pairs );
    }

    private function build_s3_signing_key( string $date, string $region, string $secret_key ): string {
        $k_date = hash_hmac( 'sha256', $date, 'AWS4' . $secret_key, true );
        $k_region = hash_hmac( 'sha256', $region, $k_date, true );
        $k_service = hash_hmac( 'sha256', 's3', $k_region, true );

        return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
    }

    private function is_valid_backup_filename( string $file ): bool {
        return '' !== $file
            && false === strpos( $file, '/' )
            && false === strpos( $file, '\\' )
            && 0 === strpos( $file, self::BACKUP_PREFIX )
            && '.zip' === substr( $file, -4 );
    }

    private function build_imported_backup_filename( string $original_name ): string {
        $original_name = sanitize_file_name( $original_name );

        if ( $this->is_valid_backup_filename( $original_name ) ) {
            return $this->ensure_unique_backup_filename( $original_name );
        }

        return $this->ensure_unique_backup_filename(
            self::BACKUP_PREFIX . 'drive-imported-' . gmdate( 'Ymd-His' ) . '.zip'
        );
    }

    private function ensure_unique_backup_filename( string $filename ): string {
        $filename = sanitize_file_name( $filename );
        $backup_dir = $this->get_backup_base_dir();
        $candidate = $filename;
        $counter = 1;

        while ( file_exists( trailingslashit( $backup_dir ) . $candidate ) ) {
            $candidate = preg_replace( '/\\.zip$/i', '', $filename ) . '-' . $counter . '.zip';
            $counter++;
        }

        return $candidate;
    }

    private function cleanup_work_dir( string $work_dir ): void {
        if ( ! is_dir( $work_dir ) ) {
            return;
        }

        $filesystem = $this->get_filesystem();
        if ( $filesystem ) {
            $filesystem->delete( $work_dir, true, 'd' );
        }
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