<?php
/**
 * Interface d'administration du plugin 360 Tranquillité.
 * Menu unifié avec onglets : Dashboard, Firewall, Connexion, 2FA, Cloudflare, Sauvegardes, Avancé, À propos.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRQ_Admin {

    private static ?TRQ_Admin $instance = null;

    private const TAB_SLUGS = [
        'dashboard',
        'firewall',
        'login',
        'twofactor',
        'cloudflare',
        'backups',
        'updates',
        'media',
        'content',
        'adminui',
        'toolkit',
        'advanced',
        'about',
    ];

    private function __construct() {}

    public static function get_instance(): TRQ_Admin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * @return array<string, string>
     */
    private function get_tabs(): array {
        return [
            'dashboard'  => __( 'Tableau de bord', '360tranquilite' ),
            'firewall'   => __( 'Pare-feu', '360tranquilite' ),
            'login'      => __( 'Connexion', '360tranquilite' ),
            'twofactor'  => __( '2FA', '360tranquilite' ),
            'cloudflare' => __( 'Cloudflare', '360tranquilite' ),
            'backups'    => __( 'Sauvegardes', '360tranquilite' ),
            'updates'    => __( 'Mises à jour', '360tranquilite' ),
            'media'      => __( 'Purge Medias', '360tranquilite' ),
            'content'    => __( 'Contenu', '360tranquilite' ),
            'adminui'    => __( 'Interface Admin', '360tranquilite' ),
            'toolkit'    => __( 'Boîte à Outils Dev', '360tranquilite' ),
            'advanced'   => __( 'Avancé', '360tranquilite' ),
            'about'      => __( 'A propos', '360tranquilite' ),
        ];
    }

    public function init(): void {
        add_action( 'admin_menu',            [ $this, 'add_menu'          ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets'    ] );
        add_action( 'admin_post_trq_save',   [ $this, 'handle_save'       ] );
        add_action( 'admin_post_trq_action', [ $this, 'handle_action'     ] );
        add_action( 'wp_ajax_trq_google_drive_list_folders', [ $this, 'ajax_google_drive_list_folders' ] );
        add_action( 'wp_ajax_trq_save_backup_settings', [ $this, 'ajax_save_backup_settings' ] );
        add_action( 'wp_ajax_trq_run_backup_now', [ $this, 'ajax_run_backup_now' ] );
        add_action( 'wp_ajax_trq_get_backup_progress', [ $this, 'ajax_get_backup_progress' ] );
        add_action( 'wp_ajax_trq_cancel_backup', [ $this, 'ajax_cancel_backup' ] );
        add_action( 'admin_post_trq_google_drive_oauth_callback', [ $this, 'handle_google_drive_oauth_callback' ] );
        add_action( 'admin_post_trq_google_drive_connector_callback', [ $this, 'handle_google_drive_connector_callback' ] );
        add_action( 'admin_post_trq_google_drive_disconnect', [ $this, 'handle_google_drive_disconnect' ] );
        add_action( 'wp_dashboard_setup', [ $this, 'add_dashboard_widgets' ] );
        // Notification dans le menu si des alertes sont en attente
        add_action( 'admin_bar_menu',        [ $this, 'admin_bar_shortcut' ], 100 );
    }

    // =========================================================================
    // MENU
    // =========================================================================

    public function add_menu(): void {
        add_menu_page(
            '360 Tranquillité',
            '360 Tranquillité',
            'manage_options',
            'trq-security',
            [ $this, 'render_page' ],
            'dashicons-shield',
            80
        );

        $tabs = $this->get_tabs();

        add_submenu_page(
            'trq-security',
            '360 Tranquillité - ' . $tabs['dashboard'],
            $tabs['dashboard'],
            'manage_options',
            'trq-security',
            [ $this, 'render_page' ]
        );

        foreach ( $tabs as $slug => $label ) {
            if ( 'dashboard' === $slug ) {
                continue;
            }

            add_submenu_page(
                'trq-security',
                '360 Tranquillité - ' . $label,
                $label,
                'manage_options',
                'trq-security&tab=' . $slug,
                [ $this, 'render_page' ]
            );
        }
    }

    public function enqueue_assets( string $hook ): void {
        unset( $hook );

        $page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';
        if ( 'trq-security' !== $page ) {
            return;
        }

        $admin_css_path = TRQ_PLUGIN_DIR . 'admin/assets/css/admin.css';
        $admin_js_path = TRQ_PLUGIN_DIR . 'admin/assets/js/admin.js';
        $asset_version = [
            'css' => file_exists( $admin_css_path ) ? (string) filemtime( $admin_css_path ) : TRQ_VERSION,
            'js' => file_exists( $admin_js_path ) ? (string) filemtime( $admin_js_path ) : TRQ_VERSION,
        ];

        wp_enqueue_style(
            'trq-admin',
            TRQ_PLUGIN_URL . 'admin/assets/css/admin.css',
            [],
            $asset_version['css']
        );
        wp_enqueue_script(
            'trq-admin',
            TRQ_PLUGIN_URL . 'admin/assets/js/admin.js',
            [ 'jquery' ],
            $asset_version['js'],
            true
        );
        wp_localize_script( 'trq-admin', 'TRQ', [
            'nonce'   => wp_create_nonce( 'trq_admin' ),
            'ajaxurl' => admin_url( 'admin-ajax.php' ),
            'strings' => [
                'driveBrowserTitle' => 'Choisir un dossier Google Drive',
                'driveBrowserLoading' => 'Chargement des dossiers...',
                'driveBrowserRoot' => 'Mon Drive',
                'driveBrowserUseCurrent' => 'Utiliser ce dossier',
                'driveBrowserUseRoot' => 'Utiliser la racine du Drive',
                'driveBrowserClose' => 'Fermer',
                'driveBrowserBack' => 'Retour',
                'driveBrowserEmpty' => 'Aucun sous-dossier disponible ici.',
                'driveBrowserError' => 'Impossible de charger les dossiers Google Drive.',
                'driveBrowserSelectedRoot' => 'Les sauvegardes seront envoyées à la racine du Drive.',
                'driveBrowserSelectedFolder' => 'Dossier sélectionné : ',
                'backupSaving' => 'Enregistrement des réglages de sauvegarde...',
                'backupStarting' => 'Lancement de la sauvegarde...',
                'backupRunning' => 'Sauvegarde en cours...',
                'backupSaveError' => 'Impossible d’enregistrer les réglages avant la sauvegarde.',
                'backupCancelling' => 'Demande d’annulation en cours...',
                'backupCancelRequested' => 'Annulation demandée. La sauvegarde va s’arrêter dès que possible.',
                'backupCancelError' => 'Impossible de demander l’annulation de la sauvegarde.',
            ],
        ] );
    }

    // =========================================================================
    // RENDU DE LA PAGE
    // =========================================================================

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès refusé.', '360tranquilite' ) );
        }

        ob_start();

        $tabs = $this->get_tabs();
        $current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard';
        if ( ! in_array( $current_tab, self::TAB_SLUGS, true ) ) {
            $current_tab = 'dashboard';
        }

        // Afficher les messages
        $notice = '';
        $action_notice = get_transient( 'trq_admin_notice' );
        if ( is_array( $action_notice ) && ! empty( $action_notice['message'] ) ) {
            $class = ! empty( $action_notice['success'] ) ? 'trq-notice-success' : 'trq-notice-error';
            $notice = '<div class="trq-notice ' . esc_attr( $class ) . '">' . esc_html( $action_notice['message'] ) . '</div>';
            delete_transient( 'trq_admin_notice' );
        } elseif ( isset( $_GET['trq_drive_message'] ) && '' !== $_GET['trq_drive_message'] ) {
            // Fallback quand le transient n'a pas pu être lu (cache objet externe, etc.).
            $drive_msg   = sanitize_text_field( wp_unslash( rawurldecode( (string) $_GET['trq_drive_message'] ) ) );
            $drive_ok    = isset( $_GET['trq_drive_result'] ) && '1' === (string) $_GET['trq_drive_result'];
            $notice      = '<div class="trq-notice ' . ( $drive_ok ? 'trq-notice-success' : 'trq-notice-error' ) . '">' . esc_html( $drive_msg ) . '</div>';
        } elseif ( isset( $_GET['trq_saved'] ) && $_GET['trq_saved'] === '1' ) {
            $notice = '<div class="trq-notice trq-notice-success">✅ Réglages sauvegardés.</div>';
        } elseif ( isset( $_GET['trq_saved'] ) && $_GET['trq_saved'] === '0' ) {
            $notice = '<div class="trq-notice trq-notice-error">❌ Erreur lors de la sauvegarde. Essayez à nouveau.</div>';
        } elseif ( isset( $_GET['trq_action_done'] ) ) {
            $notice = '<div class="trq-notice trq-notice-success">✅ Action effectuée avec succès.</div>';
        }

        ?>
        <div class="wrap trq-wrap">
            <h1 class="trq-title">
                <?php $logo_url = self::get_logo_url(); ?>
                <?php if ( $logo_url ) : ?>
                    <img src="<?php echo esc_url( $logo_url ); ?>" alt="360 Tranquillité" class="trq-title-logo" />
                <?php else : ?>
                    <span class="trq-title-icon">🛡️</span>
                <?php endif; ?>
                <span>360 Tranquillité</span>
                <span class="trq-version">v<?php echo esc_html( TRQ_VERSION ); ?></span>
            </h1>

            <?php echo wp_kses_post( $notice ); ?>

            <nav class="trq-tabs">
                <?php foreach ( $tabs as $slug => $label ) : ?>
                    <a
                        href="<?php echo esc_url( admin_url( 'admin.php?page=trq-security&tab=' . $slug ) ); ?>"
                        data-trq-tab="<?php echo esc_attr( $slug ); ?>"
                        class="trq-tab <?php echo $current_tab === $slug ? 'trq-tab-active' : ''; ?>"
                    ><?php echo esc_html( $label ); ?></a>
                <?php endforeach; ?>
            </nav>

            <div class="trq-content">
                <?php foreach ( $tabs as $slug => $label ) : ?>
                    <section
                        class="trq-tab-panel"
                        data-trq-panel="<?php echo esc_attr( $slug ); ?>"
                        <?php echo $current_tab === $slug ? '' : 'hidden'; ?>
                    >
                        <?php $this->render_tab( $slug ); ?>
                    </section>
                <?php endforeach; ?>
            </div>
        </div>
        <?php

        $output = ob_get_clean();
        echo TRQ_Localization::get_instance()->translate_admin_markup( $output );
    }

    private function render_tab( string $tab ): void {
        $view_file = TRQ_PLUGIN_DIR . 'admin/views/page-' . $tab . '.php';
        if ( file_exists( $view_file ) ) {
            include_once $view_file;
        } else {
            echo '<p>' . esc_html__( 'Vue introuvable.', '360tranquilite' ) . '</p>';
        }
    }

    // =========================================================================
    // SAUVEGARDE DES RÉGLAGES
    // =========================================================================

    public function handle_save(): void {
        if (
            ! current_user_can( 'manage_options' ) ||
            ! isset( $_POST['_wpnonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'trq_save_settings' )
        ) {
            wp_die( esc_html__( 'Accès non autorisé.', '360tranquilite' ) );
        }

        $tab = isset( $_POST['trq_tab'] ) ? sanitize_key( $_POST['trq_tab'] ) : 'dashboard';

        $new_settings = $this->sanitize_settings( $_POST, $tab );
        TRQ_Core::get_instance()->update( $new_settings );

        if ( 'advanced' === $tab ) {
            TRQ_Threat_Definitions::get_instance()->ensure_schedule();
        }

        // Forcer le flush des rewrite rules si le slug de login a changé
        if ( isset( $new_settings['login_slug'] ) ) {
            set_transient( 'trq_flush_rewrite', true, 60 );
        }

        wp_safe_redirect( add_query_arg(
            [ 'page' => 'trq-security', 'tab' => $tab, 'trq_saved' => '1' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    private function sanitize_settings( array $post, string $tab ): array {
        $settings = [];

        switch ( $tab ) {
            case 'firewall':
                $settings['firewall_enabled']         = ! empty( $post['firewall_enabled'] );
                $settings['firewall_block_sqli']      = ! empty( $post['firewall_block_sqli'] );
                $settings['firewall_block_xss']       = ! empty( $post['firewall_block_xss'] );
                $settings['firewall_block_traversal'] = ! empty( $post['firewall_block_traversal'] );
                $settings['firewall_block_bad_bots']  = ! empty( $post['firewall_block_bad_bots'] );
                break;

            case 'login':
                $slug = sanitize_title( (string) ( $post['login_slug'] ?? '' ) );
                $settings['login_slug']          = $slug;
                $settings['login_max_attempts']  = max( 1, min( 20, (int) ( $post['login_max_attempts'] ?? 5 ) ) );
                $settings['login_lockout_minutes'] = max( 5, min( 1440, (int) ( $post['login_lockout_minutes'] ?? 30 ) ) );
                $settings['disable_user_enum']   = ! empty( $post['disable_user_enum'] );
                $settings['login_visual_customization_enabled'] = ! empty( $post['login_visual_customization_enabled'] );
                $settings['login_custom_logo_url'] = esc_url_raw( (string) ( $post['login_custom_logo_url'] ?? '' ) );
                $settings['login_logo_width']      = max( 60, min( 420, (int) ( $post['login_logo_width'] ?? 120 ) ) );
                $settings['login_logo_height']     = max( 40, min( 240, (int) ( $post['login_logo_height'] ?? 120 ) ) );
                $settings['login_logo_link_url']   = esc_url_raw( (string) ( $post['login_logo_link_url'] ?? '' ) );
                $settings['login_logo_title']      = sanitize_text_field( (string) ( $post['login_logo_title'] ?? '' ) );

                $settings['login_bg_color']            = $this->sanitize_hex_setting( $post, 'login_bg_color', '#f0f2f5' );
                $settings['login_form_bg_color']       = $this->sanitize_hex_setting( $post, 'login_form_bg_color', '#ffffff' );
                $settings['login_form_border_color']   = $this->sanitize_hex_setting( $post, 'login_form_border_color', '#dcdcde' );
                $settings['login_form_text_color']     = $this->sanitize_hex_setting( $post, 'login_form_text_color', '#1d2327' );
                $settings['login_input_bg_color']      = $this->sanitize_hex_setting( $post, 'login_input_bg_color', '#ffffff' );
                $settings['login_input_text_color']    = $this->sanitize_hex_setting( $post, 'login_input_text_color', '#1d2327' );
                $settings['login_input_border_color']  = $this->sanitize_hex_setting( $post, 'login_input_border_color', '#8c8f94' );
                $settings['login_button_bg_color']     = $this->sanitize_hex_setting( $post, 'login_button_bg_color', '#2271b1' );
                $settings['login_button_text_color']   = $this->sanitize_hex_setting( $post, 'login_button_text_color', '#ffffff' );
                $settings['login_button_hover_bg_color'] = $this->sanitize_hex_setting( $post, 'login_button_hover_bg_color', '#135e96' );
                $settings['login_link_color']          = $this->sanitize_hex_setting( $post, 'login_link_color', '#2271b1' );
                $settings['login_link_hover_color']    = $this->sanitize_hex_setting( $post, 'login_link_hover_color', '#135e96' );
                $settings['login_message_bg_color']    = $this->sanitize_hex_setting( $post, 'login_message_bg_color', '#ffffff' );
                $settings['login_message_text_color']  = $this->sanitize_hex_setting( $post, 'login_message_text_color', '#1d2327' );
                $settings['login_form_border_radius']  = max( 0, min( 48, (int) ( $post['login_form_border_radius'] ?? 8 ) ) );
                $settings['login_form_shadow']         = ! empty( $post['login_form_shadow'] );
                $settings['login_custom_css']          = (string) wp_strip_all_tags( wp_unslash( $post['login_custom_css'] ?? '' ) );
                break;

            case 'twofactor':
                $settings['two_factor_enabled'] = ! empty( $post['two_factor_enabled'] );
                break;

            case 'cloudflare':
                $settings['cloudflare_enabled']  = ! empty( $post['cloudflare_enabled'] );
                $settings['cloudflare_auth_mode'] = in_array( $post['cloudflare_auth_mode'] ?? 'token', [ 'token', 'global_key' ], true )
                    ? sanitize_key( $post['cloudflare_auth_mode'] )
                    : 'token';
                $settings['cloudflare_email']    = sanitize_email( $post['cloudflare_email'] ?? '' );
                $settings['cloudflare_zone_id']  = sanitize_text_field( $post['cloudflare_zone_id'] ?? '' );
                $cloudflare_api_key = isset( $post['cloudflare_api_key'] )
                    ? trim( sanitize_text_field( wp_unslash( $post['cloudflare_api_key'] ) ) )
                    : '';
                $cloudflare_api_token = isset( $post['cloudflare_api_token'] )
                    ? trim( sanitize_text_field( wp_unslash( $post['cloudflare_api_token'] ) ) )
                    : '';
                if ( $cloudflare_api_key !== '' ) {
                    $settings['cloudflare_api_key'] = $cloudflare_api_key;
                }
                if ( $cloudflare_api_token !== '' ) {
                    $settings['cloudflare_api_token'] = $cloudflare_api_token;
                }
                $settings['cloudflare_sync_blocks'] = ! empty( $post['cloudflare_sync_blocks'] );
                break;

            case 'advanced':
                $settings['security_headers_enabled'] = ! empty( $post['security_headers_enabled'] );
                $settings['disable_xmlrpc']           = ! empty( $post['disable_xmlrpc'] );
                $settings['hide_wp_version']          = ! empty( $post['hide_wp_version'] );
                $settings['disable_file_edit']        = ! empty( $post['disable_file_edit'] );
                $settings['disable_file_mods']        = ! empty( $post['disable_file_mods'] );
                $settings['disable_application_passwords'] = ! empty( $post['disable_application_passwords'] );
                $settings['file_monitor_enabled']     = ! empty( $post['file_monitor_enabled'] );
                $settings['file_monitor_scan_plugins'] = ! empty( $post['file_monitor_scan_plugins'] );
                $settings['file_monitor_scan_themes'] = ! empty( $post['file_monitor_scan_themes'] );
                $settings['file_monitor_scan_muplugins'] = ! empty( $post['file_monitor_scan_muplugins'] );
                $settings['file_monitor_scan_uploads'] = ! empty( $post['file_monitor_scan_uploads'] );
                $settings['file_monitor_quarantine_enabled'] = ! empty( $post['file_monitor_quarantine_enabled'] );
                $settings['db_scan_enabled']         = ! empty( $post['db_scan_enabled'] );
                $settings['db_scan_max_rows']        = max( 50, min( 1000, (int) ( $post['db_scan_max_rows'] ?? 200 ) ) );
                $settings['admin_review_enabled']    = ! empty( $post['admin_review_enabled'] );
                $settings['uploads_hardening_enabled'] = ! empty( $post['uploads_hardening_enabled'] );
                $settings['core_checksum_enabled']   = ! empty( $post['core_checksum_enabled'] );
                $settings['definitions_auto_update_enabled'] = ! empty( $post['definitions_auto_update_enabled'] );
                $settings['definitions_update_url'] = esc_url_raw( (string) ( $post['definitions_update_url'] ?? '' ) );
                $settings['audit_log_enabled']        = ! empty( $post['audit_log_enabled'] );
                $settings['antispam_enabled']         = ! empty( $post['antispam_enabled'] );
                $settings['antispam_form_protection_enabled'] = ! empty( $post['antispam_form_protection_enabled'] );
                $settings['notify_enabled']           = ! empty( $post['notify_enabled'] );
                $settings['notify_email']             = sanitize_email( $post['notify_email'] ?? '' );
                $settings['data_retention_days']      = max( 7, min( 365, (int) ( $post['data_retention_days'] ?? 30 ) ) );
                break;

            case 'updates':
                $settings['updates_auto_enabled'] = ! empty( $post['updates_auto_enabled'] );
                $settings['updates_core_mode'] = in_array( $post['updates_core_mode'] ?? 'minor', [ 'disabled', 'minor', 'all' ], true )
                    ? sanitize_key( $post['updates_core_mode'] )
                    : 'minor';
                $settings['updates_plugins_auto'] = ! empty( $post['updates_plugins_auto'] );
                $settings['updates_themes_auto'] = ! empty( $post['updates_themes_auto'] );
                $settings['updates_translations_auto'] = ! empty( $post['updates_translations_auto'] );
                $settings['updates_window_enabled'] = ! empty( $post['updates_window_enabled'] );

                $window_start = sanitize_text_field( $post['updates_window_start'] ?? '02:00' );
                $settings['updates_window_start'] = preg_match( '/^(2[0-3]|[01][0-9]):[0-5][0-9]$/', $window_start ) ? $window_start : '02:00';
                $settings['updates_window_duration_hours'] = max( 1, min( 12, (int) ( $post['updates_window_duration_hours'] ?? 3 ) ) );
                $settings['updates_notify_mode'] = in_array( $post['updates_notify_mode'] ?? 'all', [ 'none', 'all', 'failures', 'problems' ], true )
                    ? sanitize_key( $post['updates_notify_mode'] )
                    : 'all';
                $settings['updates_check_compat'] = ! empty( $post['updates_check_compat'] );
                $settings['updates_pre_update_backup_enabled'] = ! empty( $post['updates_pre_update_backup_enabled'] );
                $settings['updates_auto_rollback_enabled'] = ! empty( $post['updates_auto_rollback_enabled'] );
                $settings['updates_post_update_healthcheck_enabled'] = ! empty( $post['updates_post_update_healthcheck_enabled'] );
                $healthcheck_url = esc_url_raw( (string) ( $post['updates_healthcheck_url'] ?? '' ) );
                $settings['updates_healthcheck_url'] = '' !== $healthcheck_url ? $healthcheck_url : home_url( '/' );
                $settings['updates_healthcheck_timeout'] = max( 3, min( 30, (int) ( $post['updates_healthcheck_timeout'] ?? 10 ) ) );
                $settings['updates_healthcheck_retries'] = max( 1, min( 20, (int) ( $post['updates_healthcheck_retries'] ?? 5 ) ) );
                $settings['updates_healthcheck_interval_minutes'] = max( 1, min( 30, (int) ( $post['updates_healthcheck_interval_minutes'] ?? 3 ) ) );
                break;

            case 'media':
                $settings['media_cleanup_enabled'] = ! empty( $post['media_cleanup_enabled'] );
                $settings['media_cleanup_auto_enabled'] = ! empty( $post['media_cleanup_auto_enabled'] );
                $settings['media_cleanup_dry_run'] = ! empty( $post['media_cleanup_dry_run'] );
                $settings['media_cleanup_min_age_days'] = max( 1, min( 365, (int) ( $post['media_cleanup_min_age_days'] ?? 14 ) ) );
                $keywords = sanitize_text_field( (string) ( $post['media_cleanup_protected_keywords'] ?? '' ) );
                $settings['media_cleanup_protected_keywords'] = '' !== $keywords ? $keywords : 'logo,icon,favicon,placeholder,banner,default';
                $settings['media_optimization_enabled'] = ! empty( $post['media_optimization_enabled'] );
                $settings['media_optimization_max_width'] = max( 640, min( 6000, (int) ( $post['media_optimization_max_width'] ?? 2560 ) ) );
                $settings['media_optimization_max_height'] = max( 640, min( 6000, (int) ( $post['media_optimization_max_height'] ?? 2560 ) ) );
                $settings['media_optimization_quality'] = max( 30, min( 100, (int) ( $post['media_optimization_quality'] ?? 82 ) ) );
                $settings['media_optimization_generate_webp'] = ! empty( $post['media_optimization_generate_webp'] );
                break;

            case 'toolkit':
                $settings['toolkit_enabled'] = ! empty( $post['toolkit_enabled'] );
                $settings['toolkit_login_redirect_enabled'] = ! empty( $post['toolkit_login_redirect_enabled'] );
                $settings['toolkit_login_redirect_url'] = esc_url_raw( $post['toolkit_login_redirect_url'] ?? '' );
                $settings['toolkit_logout_redirect_enabled'] = ! empty( $post['toolkit_logout_redirect_enabled'] );
                $settings['toolkit_logout_redirect_url'] = esc_url_raw( $post['toolkit_logout_redirect_url'] ?? '' );
                $settings['toolkit_smtp_enabled'] = ! empty( $post['toolkit_smtp_enabled'] );
                $settings['toolkit_smtp_host'] = sanitize_text_field( (string) ( $post['toolkit_smtp_host'] ?? '' ) );
                $settings['toolkit_smtp_port'] = max( 1, min( 65535, (int) ( $post['toolkit_smtp_port'] ?? 587 ) ) );
                $settings['toolkit_smtp_secure'] = in_array( $post['toolkit_smtp_secure'] ?? 'tls', [ 'none', 'ssl', 'tls' ], true )
                    ? sanitize_key( (string) $post['toolkit_smtp_secure'] )
                    : 'tls';
                $settings['toolkit_smtp_auth'] = ! empty( $post['toolkit_smtp_auth'] );
                $settings['toolkit_smtp_user'] = sanitize_text_field( (string) ( $post['toolkit_smtp_user'] ?? '' ) );
                $smtp_password = isset( $post['toolkit_smtp_pass'] )
                    ? trim( sanitize_text_field( wp_unslash( $post['toolkit_smtp_pass'] ) ) )
                    : '';
                if ( '' !== $smtp_password ) {
                    $settings['toolkit_smtp_pass'] = $smtp_password;
                }
                $settings['toolkit_smtp_from_email'] = sanitize_email( (string) ( $post['toolkit_smtp_from_email'] ?? '' ) );
                $settings['toolkit_smtp_from_name'] = sanitize_text_field( (string) ( $post['toolkit_smtp_from_name'] ?? '' ) );
                $settings['toolkit_maintenance_mode'] = ! empty( $post['toolkit_maintenance_mode'] );
                $settings['toolkit_maintenance_message'] = sanitize_text_field( $post['toolkit_maintenance_message'] ?? 'Site en maintenance, revenez bientot.' );
                $settings['toolkit_admin_css'] = wp_strip_all_tags( (string) ( $post['toolkit_admin_css'] ?? '' ) );
                $settings['toolkit_front_css'] = wp_strip_all_tags( (string) ( $post['toolkit_front_css'] ?? '' ) );
                $settings['toolkit_head_code'] = (string) wp_unslash( $post['toolkit_head_code'] ?? '' );
                $settings['toolkit_footer_code'] = (string) wp_unslash( $post['toolkit_footer_code'] ?? '' );
                $settings['toolkit_robots_txt'] = (string) wp_unslash( $post['toolkit_robots_txt'] ?? '' );
                $settings['toolkit_ads_txt'] = (string) wp_unslash( $post['toolkit_ads_txt'] ?? '' );
                $settings['toolkit_app_ads_txt'] = (string) wp_unslash( $post['toolkit_app_ads_txt'] ?? '' );
                $settings['toolkit_revisions_limit'] = max( 0, min( 100, (int) ( $post['toolkit_revisions_limit'] ?? 10 ) ) );
                $settings['toolkit_heartbeat_mode'] = in_array( $post['toolkit_heartbeat_mode'] ?? 'default', [ 'default', 'reduced', 'disabled' ], true ) ? sanitize_key( $post['toolkit_heartbeat_mode'] ) : 'default';
                $settings['toolkit_email_obfuscation'] = ! empty( $post['toolkit_email_obfuscation'] );
                $settings['plugin_language'] = in_array( $post['plugin_language'] ?? 'auto', [ 'auto', 'fr_FR', 'en_US' ], true ) ? sanitize_text_field( $post['plugin_language'] ) : 'auto';
                break;

            case 'content':
                $settings['toolkit_enabled'] = ! empty( $post['toolkit_enabled'] );
                $settings['toolkit_duplicate_content'] = ! empty( $post['toolkit_duplicate_content'] );
                $settings['toolkit_allow_svg'] = ! empty( $post['toolkit_allow_svg'] );
                $settings['toolkit_allow_avif'] = ! empty( $post['toolkit_allow_avif'] );
                $settings['toolkit_media_replacer_enabled'] = ! empty( $post['toolkit_media_replacer_enabled'] );
                $settings['toolkit_cpt_builder_enabled'] = ! empty( $post['toolkit_cpt_builder_enabled'] );
                $settings['toolkit_cpts_json'] = (string) wp_unslash( $post['toolkit_cpts_json'] ?? '' );
                $settings['toolkit_taxonomies_json'] = (string) wp_unslash( $post['toolkit_taxonomies_json'] ?? '' );
                $settings['toolkit_external_permalink_enabled'] = ! empty( $post['toolkit_external_permalink_enabled'] );
                $settings['toolkit_external_permalink_new_tab'] = ! empty( $post['toolkit_external_permalink_new_tab'] );
                $settings['toolkit_external_links_new_tab'] = ! empty( $post['toolkit_external_links_new_tab'] );
                $settings['toolkit_external_links_nofollow'] = ! empty( $post['toolkit_external_links_nofollow'] );
                $settings['toolkit_disable_comments'] = ! empty( $post['toolkit_disable_comments'] );
                $settings['toolkit_disable_feeds'] = ! empty( $post['toolkit_disable_feeds'] );
                $settings['toolkit_staging_noindex_enabled'] = ! empty( $post['toolkit_staging_noindex_enabled'] );
                $patterns = sanitize_text_field( (string) ( $post['toolkit_staging_patterns'] ?? '' ) );
                $settings['toolkit_staging_patterns'] = '' !== $patterns ? $patterns : 'staging.,dev.,localhost,.local,.test';
                $settings['toolkit_staging_set_blog_public_zero'] = ! empty( $post['toolkit_staging_set_blog_public_zero'] );
                break;

            case 'adminui':
                $settings['toolkit_enabled'] = ! empty( $post['toolkit_enabled'] );
                $settings['toolkit_hide_admin_notices'] = ! empty( $post['toolkit_hide_admin_notices'] );
                $settings['toolkit_disable_dashboard_widgets'] = ! empty( $post['toolkit_disable_dashboard_widgets'] );
                $settings['toolkit_hide_front_admin_bar'] = ! empty( $post['toolkit_hide_front_admin_bar'] );
                $settings['toolkit_admin_menu_width'] = max( 160, min( 360, (int) ( $post['toolkit_admin_menu_width'] ?? 160 ) ) );
                $settings['toolkit_admin_menu_cleanup_enabled'] = ! empty( $post['toolkit_admin_menu_cleanup_enabled'] );
                $menu_hidden = sanitize_text_field( (string) ( $post['toolkit_admin_menu_hidden_slugs'] ?? '' ) );
                $settings['toolkit_admin_menu_hidden_slugs'] = $menu_hidden;
                $settings['toolkit_admin_menu_reorder_enabled'] = ! empty( $post['toolkit_admin_menu_reorder_enabled'] );
                $menu_order = sanitize_text_field( (string) ( $post['toolkit_admin_menu_order'] ?? '' ) );
                $settings['toolkit_admin_menu_order'] = $menu_order;
                $settings['toolkit_admin_footer_text_enabled'] = ! empty( $post['toolkit_admin_footer_text_enabled'] );
                $settings['toolkit_admin_footer_text'] = sanitize_text_field( (string) ( $post['toolkit_admin_footer_text'] ?? '' ) );
                $settings['toolkit_admin_bar_cleanup_enabled'] = ! empty( $post['toolkit_admin_bar_cleanup_enabled'] );
                $settings['toolkit_admin_bar_remove_wp_logo'] = ! empty( $post['toolkit_admin_bar_remove_wp_logo'] );
                $settings['toolkit_admin_bar_remove_comments'] = ! empty( $post['toolkit_admin_bar_remove_comments'] );
                $settings['toolkit_admin_bar_remove_new_content'] = ! empty( $post['toolkit_admin_bar_remove_new_content'] );
                $settings['toolkit_admin_bar_remove_updates'] = ! empty( $post['toolkit_admin_bar_remove_updates'] );
                $settings['toolkit_admin_columns_enabled'] = ! empty( $post['toolkit_admin_columns_enabled'] );
                $post_types_raw = sanitize_text_field( (string) ( $post['toolkit_admin_columns_post_types'] ?? 'post,page' ) );
                $post_types = array_values(
                    array_filter(
                        array_map( 'sanitize_key', explode( ',', $post_types_raw ) )
                    )
                );
                $settings['toolkit_admin_columns_post_types'] = ! empty( $post_types ) ? implode( ',', $post_types ) : 'post,page';
                $settings['toolkit_admin_column_id'] = ! empty( $post['toolkit_admin_column_id'] );
                $settings['toolkit_admin_column_thumbnail'] = ! empty( $post['toolkit_admin_column_thumbnail'] );
                $settings['toolkit_admin_column_slug'] = ! empty( $post['toolkit_admin_column_slug'] );
                $settings['toolkit_admin_column_modified'] = ! empty( $post['toolkit_admin_column_modified'] );
                $settings['toolkit_users_last_login_column'] = ! empty( $post['toolkit_users_last_login_column'] );
                $settings['toolkit_taxonomy_filters_enabled'] = ! empty( $post['toolkit_taxonomy_filters_enabled'] );
                $tax_filter_post_types_raw = sanitize_text_field( (string) ( $post['toolkit_taxonomy_filters_post_types'] ?? 'post,page' ) );
                $tax_filter_post_types = array_values(
                    array_filter(
                        array_map( 'sanitize_key', explode( ',', $tax_filter_post_types_raw ) )
                    )
                );
                $settings['toolkit_taxonomy_filters_post_types'] = ! empty( $tax_filter_post_types ) ? implode( ',', $tax_filter_post_types ) : 'post,page';
                $settings['toolkit_taxonomy_terms_order_enabled'] = ! empty( $post['toolkit_taxonomy_terms_order_enabled'] );
                $settings['toolkit_taxonomy_terms_orderby'] = in_array( $post['toolkit_taxonomy_terms_orderby'] ?? 'name', [ 'name', 'slug', 'count', 'term_id' ], true )
                    ? sanitize_key( (string) $post['toolkit_taxonomy_terms_orderby'] )
                    : 'name';
                $settings['toolkit_taxonomy_terms_order'] = in_array( strtoupper( (string) ( $post['toolkit_taxonomy_terms_order'] ?? 'ASC' ) ), [ 'ASC', 'DESC' ], true )
                    ? strtoupper( sanitize_text_field( (string) $post['toolkit_taxonomy_terms_order'] ) )
                    : 'ASC';
                break;

            case 'backups':
                $core = TRQ_Core::get_instance();
                $settings['backup_enabled']            = ! empty( $post['backup_enabled'] );
                $settings['backup_mode']               = in_array( $post['backup_mode'] ?? 'full', [ 'full', 'incremental' ], true )
                    ? sanitize_key( $post['backup_mode'] )
                    : 'full';
                $settings['backup_frequency']          = in_array( $post['backup_frequency'] ?? 'daily', [ 'daily', 'weekly', 'monthly' ], true )
                    ? sanitize_key( $post['backup_frequency'] )
                    : 'daily';
                $backup_time = sanitize_text_field( $post['backup_time'] ?? '02:00' );
                $settings['backup_time']               = preg_match( '/^(2[0-3]|[01][0-9]):[0-5][0-9]$/', $backup_time ) ? $backup_time : '02:00';
                $settings['backup_day_of_week']        = max( 0, min( 6, (int) ( $post['backup_day_of_week'] ?? 1 ) ) );
                $settings['backup_day_of_month']       = max( 1, min( 28, (int) ( $post['backup_day_of_month'] ?? 1 ) ) );
                $settings['backup_retention_count']    = max( 1, min( 50, (int) ( $post['backup_retention_count'] ?? 5 ) ) );
                $settings['backup_destination_local']  = ! empty( $post['backup_destination_local'] );
                $settings['backup_local_dir']          = sanitize_title_with_dashes( $post['backup_local_dir'] ?? '360tranquilite-backups' ) ?: '360tranquilite-backups';
                $settings['backup_destination_google_drive'] = ! empty( $post['backup_destination_google_drive'] );
                $settings['backup_google_drive_client_id'] = sanitize_text_field( $post['backup_google_drive_client_id'] ?? '' );
                $backup_google_drive_client_secret = isset( $post['backup_google_drive_client_secret'] )
                    ? trim( sanitize_text_field( wp_unslash( $post['backup_google_drive_client_secret'] ) ) )
                    : '';
                if ( '' !== $backup_google_drive_client_secret ) {
                    $settings['backup_google_drive_client_secret'] = $backup_google_drive_client_secret;
                }
                $settings['backup_google_drive_folder_id'] = $this->sanitize_google_drive_folder_id( $post['backup_google_drive_folder_id'] ?? '' );
                $settings['backup_destination_s3']      = ! empty( $post['backup_destination_s3'] );
                $settings['backup_s3_endpoint']         = esc_url_raw( $post['backup_s3_endpoint'] ?? '' );
                $settings['backup_s3_region']           = sanitize_text_field( $post['backup_s3_region'] ?? 'us-east-1' );
                $settings['backup_s3_bucket']           = sanitize_text_field( $post['backup_s3_bucket'] ?? '' );
                $settings['backup_s3_access_key']       = sanitize_text_field( $post['backup_s3_access_key'] ?? '' );
                $backup_s3_secret_key = isset( $post['backup_s3_secret_key'] )
                    ? trim( sanitize_text_field( wp_unslash( $post['backup_s3_secret_key'] ) ) )
                    : '';
                if ( '' !== $backup_s3_secret_key ) {
                    $settings['backup_s3_secret_key'] = $backup_s3_secret_key;
                }
                $settings['backup_s3_prefix']           = sanitize_text_field( $post['backup_s3_prefix'] ?? '' );
                $settings['backup_s3_path_style']       = ! empty( $post['backup_s3_path_style'] );
                $settings['backup_include_files']      = ! empty( $post['backup_include_files'] );
                $settings['backup_include_database']   = ! empty( $post['backup_include_database'] );
                $settings['backup_exclude_cache_dirs'] = ! empty( $post['backup_exclude_cache_dirs'] );

                $existing_refresh_token = (string) $core->get( 'backup_google_drive_refresh_token', '' );
                if ( '' !== $existing_refresh_token ) {
                    $settings['backup_google_drive_refresh_token'] = $existing_refresh_token;
                    $settings['backup_google_drive_account_email'] = (string) $core->get( 'backup_google_drive_account_email', '' );
                    $settings['backup_google_drive_connected_at'] = (string) $core->get( 'backup_google_drive_connected_at', '' );
                }
                break;

            default:
                break;
        }

        return $settings;
    }

    private function sanitize_hex_setting( array $post, string $key, string $default ): string {
        $value = sanitize_hex_color( (string) ( $post[ $key ] ?? '' ) );
        if ( is_string( $value ) && '' !== $value ) {
            return $value;
        }

        return $default;
    }

    private function sanitize_google_drive_folder_id( $value ): string {
        $folder = trim( sanitize_text_field( wp_unslash( (string) $value ) ) );
        if ( '' === $folder ) {
            return '';
        }

        if ( preg_match( '#/folders/([a-zA-Z0-9_-]+)#', $folder, $matches ) ) {
            return $matches[1];
        }

        if ( preg_match( '#[?&]id=([a-zA-Z0-9_-]+)#', $folder, $matches ) ) {
            return $matches[1];
        }

        return preg_replace( '/[^a-zA-Z0-9_-]/', '', $folder ) ?? '';
    }

    public function ajax_google_drive_list_folders(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Accès non autorisé.' ], 403 );
        }

        check_ajax_referer( 'trq_admin', 'nonce' );

        $parent_id = sanitize_text_field( wp_unslash( $_POST['parent_id'] ?? 'root' ) );
        if ( '' === $parent_id ) {
            $parent_id = 'root';
        }

        $result = TRQ_Backup_Manager::get_instance()->list_google_drive_folders( $parent_id );
        if ( empty( $result['success'] ) ) {
            wp_send_json_error( [ 'message' => $result['message'] ?? 'Impossible de charger les dossiers.' ], 500 );
        }

        wp_send_json_success( [
            'parent_id' => $result['parent_id'],
            'folders' => $result['folders'],
        ] );
    }

    public function ajax_save_backup_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Accès non autorisé.' ], 403 );
        }

        check_ajax_referer( 'trq_admin', 'nonce' );

        $settings = $this->sanitize_settings( $_POST, 'backups' );
        TRQ_Core::get_instance()->update( $settings );
        TRQ_Backup_Manager::get_instance()->ensure_schedule();

        wp_send_json_success( [ 'message' => 'Réglages de sauvegarde enregistrés.' ] );
    }

    public function ajax_run_backup_now(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Accès non autorisé.' ], 403 );
        }

        check_ajax_referer( 'trq_admin', 'nonce' );

        $result = TRQ_Backup_Manager::get_instance()->start_manual_backup_async();
        if ( empty( $result['success'] ) ) {
            wp_send_json_error( $result, 500 );
        }

        wp_send_json_success( $result );
    }

    public function ajax_get_backup_progress(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Accès non autorisé.' ], 403 );
        }

        check_ajax_referer( 'trq_admin', 'nonce' );

        wp_send_json_success( TRQ_Backup_Manager::get_instance()->get_backup_progress( true ) );
    }

    public function ajax_cancel_backup(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Accès non autorisé.' ], 403 );
        }

        check_ajax_referer( 'trq_admin', 'nonce' );

        $result = TRQ_Backup_Manager::get_instance()->cancel_manual_backup();
        if ( empty( $result['success'] ) ) {
            wp_send_json_error( $result, 400 );
        }

        wp_send_json_success( $result );
    }

    // =========================================================================
    // ACTIONS PONCTUELLES (déblocage IP, scan, etc.)
    // =========================================================================

    public function handle_action(): void {
        if (
            ! current_user_can( 'manage_options' ) ||
            ! isset( $_POST['_wpnonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'trq_action' )
        ) {
            wp_die( esc_html__( 'Accès non autorisé.', '360tranquilite' ) );
        }

        $action  = sanitize_key( $_POST['trq_do'] ?? '' );
        $tab     = sanitize_key( $_POST['trq_tab'] ?? 'dashboard' );

        switch ( $action ) {
            case 'unblock_ip':
                $ip = sanitize_text_field( $_POST['ip'] ?? '' );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    TRQ_Firewall::get_instance()->unblock_ip( $ip );
                }
                break;

            case 'block_ip':
                $ip     = sanitize_text_field( $_POST['ip'] ?? '' );
                $reason = sanitize_text_field( $_POST['reason'] ?? 'Blocage manuel' );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    TRQ_Firewall::get_instance()->block_ip( $ip, $reason );
                }
                break;

            case 'run_file_scan':
                TRQ_File_Monitor::get_instance()->run_scan();
                break;

            case 'build_baseline':
                TRQ_File_Monitor::get_instance()->build_baseline();
                break;

            case 'quarantine_file':
                $path   = isset( $_POST['path'] ) ? wp_unslash( $_POST['path'] ) : '';
                $result = TRQ_File_Monitor::get_instance()->quarantine_file( $path );
                set_transient( 'trq_admin_notice', $result, 60 );
                break;

            case 'run_system_scan':
                TRQ_System_Scanner::get_instance()->run_full_scan();
                break;

            case 'update_threat_definitions':
                $result = TRQ_Threat_Definitions::get_instance()->refresh_definitions( true );
                set_transient( 'trq_admin_notice', $result, 60 );
                break;

            case 'run_incident_cleanup':
                $file_report = TRQ_File_Monitor::get_instance()->run_scan();
                $system_report = TRQ_System_Scanner::get_instance()->run_full_scan();
                $quarantine = TRQ_File_Monitor::get_instance()->quarantine_findings_from_last_report( 300 );
                $uploads = TRQ_System_Scanner::get_instance()->ensure_uploads_hardening_files();

                $cron_removed = 0;
                foreach ( (array) ( $system_report['cron']['findings'] ?? [] ) as $finding ) {
                    if ( 'suspicious_hook_name' !== (string) ( $finding['type'] ?? '' ) ) {
                        continue;
                    }

                    $hook = (string) ( $finding['hook'] ?? '' );
                    if ( '' === $hook ) {
                        continue;
                    }

                    $removed = TRQ_System_Scanner::get_instance()->unschedule_hook( $hook );
                    if ( ! empty( $removed['success'] ) ) {
                        $cron_removed++;
                    }
                }

                $summary = sprintf(
                    'Assainissement terminé. Fichiers suspects: %d, quarantaine: %d, échecs quarantaine: %d, hooks cron supprimés: %d, durcissement uploads: %s.',
                    count( (array) ( $file_report['findings'] ?? [] ) ),
                    (int) ( $quarantine['quarantined'] ?? 0 ),
                    (int) ( $quarantine['failed'] ?? 0 ),
                    $cron_removed,
                    ( ! empty( $uploads['htaccess'] ) && ! empty( $uploads['index_php'] ) ) ? 'ok' : 'à vérifier'
                );

                set_transient(
                    'trq_admin_notice',
                    [
                        'success' => true,
                        'message' => $summary,
                    ],
                    90
                );
                break;

            case 'apply_uploads_hardening':
                $result = TRQ_System_Scanner::get_instance()->ensure_uploads_hardening_files();
                set_transient(
                    'trq_admin_notice',
                    [
                        'success' => ! empty( $result['htaccess'] ) || ! empty( $result['web_config'] ),
                        'message' => ! empty( $result['upload_dir'] ) ? 'Durcissement uploads vérifié pour ' . $result['upload_dir'] : 'Impossible d’appliquer le durcissement uploads.',
                    ],
                    60
                );
                break;

            case 'unschedule_cron_hook':
                $hook   = isset( $_POST['hook'] ) ? wp_unslash( $_POST['hook'] ) : '';
                $result = TRQ_System_Scanner::get_instance()->unschedule_hook( $hook );
                set_transient( 'trq_admin_notice', $result, 60 );
                break;

            case 'download_incident_report':
                $report = TRQ_System_Scanner::get_instance()->build_incident_report();
                nocache_headers();
                header( 'Content-Type: application/json; charset=utf-8' );
                header( 'Content-Disposition: attachment; filename="360tranquilite-incident-report-' . gmdate( 'Ymd-His' ) . '.json"' );
                echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                exit;

            case 'run_backup_now':
                $result = TRQ_Backup_Manager::get_instance()->start_manual_backup_async();
                set_transient( 'trq_admin_notice', $result, 60 );
                break;

            case 'run_media_cleanup':
                $result = TRQ_Media_Cleanup::get_instance()->run_manual_cleanup();
                set_transient( 'trq_admin_notice', $result, 60 );
                break;

            case 'import_google_drive_backup':
                $file_id = sanitize_text_field( wp_unslash( $_POST['file_id'] ?? '' ) );
                $file_name = sanitize_file_name( wp_unslash( $_POST['file_name'] ?? '' ) );
                $result = TRQ_Backup_Manager::get_instance()->import_google_drive_backup( $file_id, $file_name, false );
                set_transient( 'trq_admin_notice', $result, 60 );
                break;

            case 'import_restore_google_drive_backup':
                $file_id = sanitize_text_field( wp_unslash( $_POST['file_id'] ?? '' ) );
                $file_name = sanitize_file_name( wp_unslash( $_POST['file_name'] ?? '' ) );
                $result = TRQ_Backup_Manager::get_instance()->import_google_drive_backup( $file_id, $file_name, true );
                set_transient( 'trq_admin_notice', $result, 60 );
                break;

            case 'delete_google_drive_backup':
                $file_id = sanitize_text_field( wp_unslash( $_POST['file_id'] ?? '' ) );
                $result = TRQ_Backup_Manager::get_instance()->delete_google_drive_backup( $file_id );
                set_transient( 'trq_admin_notice', $result, 60 );
                break;

            case 'download_backup':
                $file = sanitize_file_name( wp_unslash( $_POST['file'] ?? '' ) );
                $download = TRQ_Backup_Manager::get_instance()->get_backup_download( $file );
                if ( ! empty( $download['success'] ) && ! empty( $download['path'] ) ) {
                    nocache_headers();
                    header( 'Content-Type: application/zip' );
                    header( 'Content-Disposition: attachment; filename="' . basename( $download['path'] ) . '"' );
                    header( 'Content-Length: ' . filesize( $download['path'] ) );
                    readfile( $download['path'] );
                    exit;
                }
                set_transient( 'trq_admin_notice', $download, 60 );
                break;

            case 'delete_backup':
                $file = sanitize_file_name( wp_unslash( $_POST['file'] ?? '' ) );
                $result = TRQ_Backup_Manager::get_instance()->delete_local_backup( $file );
                set_transient( 'trq_admin_notice', $result, 60 );
                break;

            case 'restore_backup':
                $file = sanitize_file_name( wp_unslash( $_POST['file'] ?? '' ) );
                $result = TRQ_Backup_Manager::get_instance()->restore_local_backup( $file );
                set_transient( 'trq_admin_notice', $result, 60 );
                break;

            case 'import_backup_archive':
                $result = TRQ_Backup_Manager::get_instance()->import_uploaded_backup( $_FILES['backup_archive'] ?? [], false );
                set_transient( 'trq_admin_notice', $result, 60 );
                break;

            case 'import_restore_backup_archive':
                $result = TRQ_Backup_Manager::get_instance()->import_uploaded_backup( $_FILES['backup_archive'] ?? [], true );
                set_transient( 'trq_admin_notice', $result, 60 );
                break;

            case 'connect_google_drive':
                $result = TRQ_Backup_Manager::get_instance()->get_google_drive_auth_url();
                if ( ! empty( $result['success'] ) && ! empty( $result['url'] ) ) {
                    wp_safe_redirect( $result['url'] );
                    exit;
                }
                set_transient( 'trq_admin_notice', $result, 60 );
                break;

            case 'disconnect_google_drive':
                $result = TRQ_Backup_Manager::get_instance()->disconnect_google_drive();
                set_transient( 'trq_admin_notice', $result, 60 );
                break;

            case 'export_settings':
                $payload = [
                    'plugin'       => '360 Tranquillite',
                    'version'      => defined( 'TRQ_VERSION' ) ? TRQ_VERSION : 'unknown',
                    'exported_at'  => current_time( 'mysql', true ),
                    'site_url'     => home_url(),
                    'settings'     => TRQ_Core::get_instance()->get_all(),
                ];

                nocache_headers();
                header( 'Content-Type: application/json; charset=utf-8' );
                header( 'Content-Disposition: attachment; filename="360tranquilite-settings-' . gmdate( 'Ymd-His' ) . '.json"' );
                echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                exit;

            case 'import_settings':
                $result = $this->import_settings_from_upload();
                set_transient( 'trq_admin_notice', $result, 60 );
                break;

            case 'cf_test':
                // Test connexion Cloudflare — réponse stockée en transient pour affichage
                $result = TRQ_Cloudflare::get_instance()->api_test_connection();
                set_transient( 'trq_cf_test_result', $result, 60 );
                break;

            case 'cf_purge':
                TRQ_Cloudflare::get_instance()->api_purge_cache();
                break;

            case 'apply_recommended_profile':
                $recommended = $this->get_recommended_profile_settings();
                TRQ_Core::get_instance()->update( $recommended );
                TRQ_Threat_Definitions::get_instance()->ensure_schedule();
                set_transient(
                    'trq_admin_notice',
                    [
                        'success' => true,
                        'message' => sprintf( 'Configuration recommandée appliquée (%d options mises à jour).', count( $recommended ) ),
                    ],
                    60
                );
                break;

            case 'disable_all_profile':
                $disabled = $this->get_disabled_profile_settings();
                TRQ_Core::get_instance()->update( $disabled );
                TRQ_Threat_Definitions::get_instance()->ensure_schedule();
                set_transient(
                    'trq_admin_notice',
                    [
                        'success' => true,
                        'message' => sprintf( 'Tous les modules ont été désactivés (%d options mises à jour).', count( $disabled ) ),
                    ],
                    60
                );
                break;

            default:
                break;
        }

        wp_safe_redirect( add_query_arg(
            [ 'page' => 'trq-security', 'tab' => $tab, 'trq_action_done' => '1' ],
            admin_url( 'admin.php' )
        ) );
        exit;
    }

    private function import_settings_from_upload(): array {
        if ( empty( $_FILES['settings_file'] ) || ! is_array( $_FILES['settings_file'] ) ) {
            return [
                'success' => false,
                'message' => 'Aucun fichier de réglages fourni.',
            ];
        }

        $file = $_FILES['settings_file'];
        if ( ! empty( $file['error'] ) ) {
            return [
                'success' => false,
                'message' => 'Échec de l’envoi du fichier de réglages.',
            ];
        }

        $tmp_name = $file['tmp_name'] ?? '';
        if ( ! is_string( $tmp_name ) || '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
            return [
                'success' => false,
                'message' => 'Fichier importé invalide.',
            ];
        }

        $raw = file_get_contents( $tmp_name );
        if ( false === $raw || '' === $raw ) {
            return [
                'success' => false,
                'message' => 'Impossible de lire le fichier de réglages.',
            ];
        }

        $payload = json_decode( $raw, true );
        if ( ! is_array( $payload ) || ! isset( $payload['settings'] ) || ! is_array( $payload['settings'] ) ) {
            return [
                'success' => false,
                'message' => 'Le fichier JSON ne contient pas de bloc settings valide.',
            ];
        }

        $allowed_settings = TRQ_Core::get_default_settings();
        $imported_settings = [];

        foreach ( $payload['settings'] as $key => $value ) {
            if ( ! is_string( $key ) || ! array_key_exists( $key, $allowed_settings ) ) {
                continue;
            }

            $imported_settings[ $key ] = $value;
        }

        if ( empty( $imported_settings ) ) {
            return [
                'success' => false,
                'message' => 'Aucun réglage exploitable trouvé dans le fichier.',
            ];
        }

        $sanitized = [];
        foreach ( [ 'firewall', 'login', 'twofactor', 'cloudflare', 'backups', 'updates', 'media', 'content', 'adminui', 'toolkit', 'advanced' ] as $tab ) {
            $sanitized = array_merge( $sanitized, $this->sanitize_settings( $imported_settings, $tab ) );
        }

        TRQ_Core::get_instance()->update( $sanitized );

        if ( isset( $sanitized['login_slug'] ) ) {
            set_transient( 'trq_flush_rewrite', true, 60 );
        }

        return [
            'success' => true,
            'message' => 'Réglages importés avec succès (' . count( $sanitized ) . ' options).',
        ];
    }

    private function get_recommended_profile_settings(): array {
        return [
            'firewall_enabled' => true,
            'firewall_block_sqli' => true,
            'firewall_block_xss' => true,
            'firewall_block_traversal' => true,
            'firewall_block_bad_bots' => true,
            'disable_user_enum' => true,
            'two_factor_enabled' => false,
            'cloudflare_enabled' => false,
            'cloudflare_sync_blocks' => false,
            'backup_enabled' => false,
            'backup_destination_local' => true,
            'backup_include_files' => true,
            'backup_include_database' => true,
            'backup_exclude_cache_dirs' => true,
            'security_headers_enabled' => true,
            'disable_xmlrpc' => true,
            'hide_wp_version' => true,
            'disable_file_edit' => true,
            'disable_file_mods' => false,
            'disable_application_passwords' => true,
            'file_monitor_enabled' => true,
            'file_monitor_scan_plugins' => true,
            'file_monitor_scan_themes' => true,
            'file_monitor_scan_muplugins' => true,
            'file_monitor_scan_uploads' => true,
            'file_monitor_quarantine_enabled' => true,
            'db_scan_enabled' => true,
            'admin_review_enabled' => true,
            'uploads_hardening_enabled' => true,
            'core_checksum_enabled' => true,
            'audit_log_enabled' => true,
            'antispam_enabled' => true,
            'antispam_form_protection_enabled' => true,
            'notify_enabled' => true,
            'updates_auto_enabled' => false,
            'updates_plugins_auto' => false,
            'updates_themes_auto' => false,
            'updates_translations_auto' => false,
            'updates_check_compat' => true,
            'updates_pre_update_backup_enabled' => true,
            'updates_auto_rollback_enabled' => false,
            'updates_post_update_healthcheck_enabled' => true,
            'media_cleanup_enabled' => false,
            'media_cleanup_auto_enabled' => false,
            'media_optimization_enabled' => false,
            'media_optimization_generate_webp' => false,
            'toolkit_enabled' => false,
            'toolkit_allow_svg' => false,
            'toolkit_allow_avif' => false,
            'toolkit_duplicate_content' => false,
            'toolkit_external_permalink_enabled' => false,
            'toolkit_external_permalink_new_tab' => false,
            'toolkit_external_links_new_tab' => false,
            'toolkit_external_links_nofollow' => false,
            'toolkit_disable_comments' => false,
            'toolkit_disable_feeds' => false,
            'toolkit_hide_front_admin_bar' => false,
            'toolkit_admin_menu_cleanup_enabled' => false,
            'toolkit_admin_menu_reorder_enabled' => false,
            'toolkit_admin_footer_text_enabled' => false,
            'toolkit_admin_bar_cleanup_enabled' => false,
            'toolkit_admin_columns_enabled' => false,
            'toolkit_users_last_login_column' => false,
            'toolkit_taxonomy_filters_enabled' => false,
            'toolkit_taxonomy_terms_order_enabled' => false,
            'toolkit_smtp_enabled' => false,
            'toolkit_smtp_auth' => false,
            'toolkit_staging_noindex_enabled' => false,
            'toolkit_staging_set_blog_public_zero' => false,
            'login_visual_customization_enabled' => false,
        ];
    }

    private function get_disabled_profile_settings(): array {
        return [
            'firewall_enabled' => false,
            'firewall_block_sqli' => false,
            'firewall_block_xss' => false,
            'firewall_block_traversal' => false,
            'firewall_block_bad_bots' => false,
            'disable_user_enum' => false,
            'two_factor_enabled' => false,
            'cloudflare_enabled' => false,
            'cloudflare_sync_blocks' => false,
            'backup_enabled' => false,
            'security_headers_enabled' => false,
            'disable_xmlrpc' => false,
            'hide_wp_version' => false,
            'disable_file_edit' => false,
            'disable_file_mods' => false,
            'disable_application_passwords' => false,
            'file_monitor_enabled' => false,
            'db_scan_enabled' => false,
            'admin_review_enabled' => false,
            'uploads_hardening_enabled' => false,
            'core_checksum_enabled' => false,
            'definitions_auto_update_enabled' => false,
            'audit_log_enabled' => false,
            'antispam_enabled' => false,
            'antispam_form_protection_enabled' => false,
            'notify_enabled' => false,
            'updates_auto_enabled' => false,
            'updates_plugins_auto' => false,
            'updates_themes_auto' => false,
            'updates_translations_auto' => false,
            'updates_window_enabled' => false,
            'updates_pre_update_backup_enabled' => false,
            'updates_auto_rollback_enabled' => false,
            'updates_post_update_healthcheck_enabled' => false,
            'media_cleanup_enabled' => false,
            'media_cleanup_auto_enabled' => false,
            'media_optimization_enabled' => false,
            'media_optimization_generate_webp' => false,
            'toolkit_enabled' => false,
            'toolkit_allow_svg' => false,
            'toolkit_allow_avif' => false,
            'toolkit_duplicate_content' => false,
            'toolkit_external_permalink_enabled' => false,
            'toolkit_external_permalink_new_tab' => false,
            'toolkit_external_links_new_tab' => false,
            'toolkit_external_links_nofollow' => false,
            'toolkit_disable_comments' => false,
            'toolkit_disable_feeds' => false,
            'toolkit_hide_front_admin_bar' => false,
            'toolkit_admin_menu_cleanup_enabled' => false,
            'toolkit_admin_menu_reorder_enabled' => false,
            'toolkit_admin_footer_text_enabled' => false,
            'toolkit_admin_bar_cleanup_enabled' => false,
            'toolkit_admin_columns_enabled' => false,
            'toolkit_users_last_login_column' => false,
            'toolkit_taxonomy_filters_enabled' => false,
            'toolkit_taxonomy_terms_order_enabled' => false,
            'toolkit_smtp_enabled' => false,
            'toolkit_smtp_auth' => false,
            'toolkit_staging_noindex_enabled' => false,
            'toolkit_staging_set_blog_public_zero' => false,
            'login_visual_customization_enabled' => false,
        ];
    }

    public function handle_google_drive_oauth_callback(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès non autorisé.', '360tranquilite' ) );
        }

        $error = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) );
        if ( '' !== $error ) {
            set_transient(
                'trq_admin_notice',
                [
                    'success' => false,
                    'message' => 'Connexion Google Drive annulée ou refusée : ' . $error,
                ],
                60
            );
            wp_safe_redirect( admin_url( 'admin.php?page=trq-security&tab=backups' ) );
            exit;
        }

        $code = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
        $state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );

        $result = TRQ_Backup_Manager::get_instance()->complete_google_drive_auth( $code, $state, get_current_user_id() );
        set_transient( 'trq_admin_notice', $result, 60 );

        wp_safe_redirect( admin_url( 'admin.php?page=trq-security&tab=backups' ) );
        exit;
    }

    public function handle_google_drive_connector_callback(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès non autorisé.', '360tranquilite' ) );
        }

        $error = sanitize_text_field( wp_unslash( $_GET['error'] ?? '' ) );
        if ( '' !== $error ) {
            set_transient(
                'trq_admin_notice',
                [
                    'success' => false,
                    'message' => 'Connexion Google Drive via connecteur interrompue : ' . $error,
                ],
                60
            );
            wp_safe_redirect( admin_url( 'admin.php?page=trq-security&tab=backups' ) );
            exit;
        }

        $code = sanitize_text_field( wp_unslash( $_GET['connector_code'] ?? '' ) );
        $state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );

        $result = TRQ_Backup_Manager::get_instance()->complete_google_drive_connector_auth( $code, $state, get_current_user_id() );
        set_transient( 'trq_admin_notice', $result, 60 );

        // Fallback : passe aussi le résultat en paramètre URL au cas où le transient
        // ne serait pas lisible (cache objet externe, environnement multisite, etc.).
        $redirect = add_query_arg(
            [
                'trq_drive_result'  => empty( $result['success'] ) ? '0' : '1',
                'trq_drive_message' => rawurlencode( (string) ( $result['message'] ?? '' ) ),
            ],
            admin_url( 'admin.php?page=trq-security&tab=backups' )
        );

        wp_safe_redirect( $redirect );
        exit;
    }

    public function handle_google_drive_disconnect(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Accès non autorisé.', '360tranquilite' ) );
        }

        check_admin_referer( 'trq_google_drive_disconnect' );

        $result = TRQ_Backup_Manager::get_instance()->disconnect_google_drive();
        set_transient( 'trq_admin_notice', $result, 60 );

        wp_safe_redirect( admin_url( 'admin.php?page=trq-security&tab=backups' ) );
        exit;
    }

    // =========================================================================
    // BARRE D'ADMIN
    // =========================================================================

    public function admin_bar_shortcut( WP_Admin_Bar $bar ): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $stats   = TRQ_Firewall::get_instance()->get_stats();
        $blocked = $stats['total_today'];
        $bar->add_node( [
            'id'    => 'trq-shortcut',
            'title' => '🛡️' . ( $blocked > 0 ? " <span style='color:#f66'>+{$blocked}</span>" : '' ),
            'href'  => admin_url( 'admin.php?page=trq-security' ),
            'meta'  => [ 'title' => '360 Tranquillité — ' . $blocked . ' menaces bloquées aujourd\'hui' ],
        ] );
    }

    public function add_dashboard_widgets(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        wp_add_dashboard_widget(
            'trq_dashboard_widget',
            '360 Tranquillité',
            [ $this, 'render_dashboard_widget' ]
        );
    }

    public function render_dashboard_widget(): void {
        global $wpdb;

        $core = TRQ_Core::get_instance();
        $backup_summary = TRQ_Backup_Manager::get_instance()->get_summary();
        $firewall_stats = TRQ_Firewall::get_instance()->get_stats();
        $last_updates = TRQ_Auto_Updates::get_instance()->get_last_report();
        $last_media = class_exists( 'TRQ_Media_Cleanup' ) ? TRQ_Media_Cleanup::get_instance()->get_last_report() : [];
        $audit_stats = class_exists( 'TRQ_Audit_Log' ) ? TRQ_Audit_Log::get_instance()->get_stats() : [];

        $firewall_table = $wpdb->prefix . 'trq_firewall_log';
        $threats_24h = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$firewall_table}` WHERE blocked_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)" );
        $threats_7d = (int) ( $firewall_stats['total_week'] ?? 0 );
        $blocked_ips = (int) ( $firewall_stats['blocked_ips'] ?? 0 );
        $critical_today = (int) ( $audit_stats['critical_today'] ?? 0 );

        $active_modules = 0;
        foreach ( [ 'firewall_enabled', 'security_headers_enabled', 'file_monitor_enabled', 'antispam_enabled', 'disable_xmlrpc', 'disable_file_edit' ] as $flag ) {
            if ( $core->get( $flag, false ) ) {
                $active_modules++;
            }
        }

        $protection = 'OK';
        if ( $critical_today > 0 || $threats_24h > 20 || $active_modules <= 2 ) {
            $protection = 'Critique';
        } elseif ( $threats_24h > 5 || $active_modules <= 4 ) {
            $protection = 'Moyen';
        }

        if ( ! function_exists( 'wp_get_update_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        $update_data = function_exists( 'wp_get_update_data' ) ? wp_get_update_data() : [];
        $updates_pending = 0;
        if ( ! empty( $update_data['counts'] ) && is_array( $update_data['counts'] ) ) {
            foreach ( $update_data['counts'] as $count ) {
                $updates_pending += (int) $count;
            }
        }

        $updates_success = isset( $last_updates['counts'] ) && is_array( $last_updates['counts'] ) ? (int) array_sum( $last_updates['counts'] ) : 0;
        $updates_failures = isset( $last_updates['failures'] ) && is_array( $last_updates['failures'] ) ? (int) array_sum( $last_updates['failures'] ) : 0;
        $updates_incompat = isset( $last_updates['compat_skipped'] ) && is_array( $last_updates['compat_skipped'] ) ? count( $last_updates['compat_skipped'] ) : 0;

        $media_orphans = (int) ( $last_media['orphans_found'] ?? 0 );
        $media_deleted = (int) ( $last_media['deleted'] ?? 0 );
        $media_dry_run = ! empty( $last_media['dry_run'] );

        $backup_status = 'N/A';
        if ( '' !== (string) ( $backup_summary['last_generated'] ?? '' ) ) {
            $backup_status = ! empty( $backup_summary['last_success'] ) ? 'Succès' : 'Échec';
        }

        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">';

        echo '<div><strong>1) État global:</strong><br>';
        echo 'Score: <strong>' . esc_html( $protection ) . '</strong> · Modules actifs: ' . esc_html( (string) $active_modules ) . '/6 · Alertes critiques: ' . esc_html( (string) $critical_today ) . '</div>';

        echo '<div><strong>2) Dernière sauvegarde:</strong><br>';
        echo 'Date: ' . esc_html( (string) ( $backup_summary['last_generated'] ?: 'Aucune' ) ) . ' · Statut: ' . esc_html( $backup_status );
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:6px;">';
        wp_nonce_field( 'trq_action' );
        echo '<input type="hidden" name="action" value="trq_action">';
        echo '<input type="hidden" name="trq_do" value="run_backup_now">';
        echo '<input type="hidden" name="trq_tab" value="backups">';
        echo '<button type="submit" class="button button-small">Lancer maintenant</button>';
        echo '</form></div>';

        echo '<div><strong>3) Pare-feu:</strong><br>';
        echo 'Menaces 24h: ' . esc_html( (string) $threats_24h ) . ' · 7j: ' . esc_html( (string) $threats_7d ) . ' · IP bloquées: ' . esc_html( (string) $blocked_ips ) . '</div>';

        echo '<div><strong>4) Mises à jour:</strong><br>';
        echo 'En attente: ' . esc_html( (string) $updates_pending ) . ' · Dernier auto-update: +' . esc_html( (string) $updates_success ) . ' / échecs ' . esc_html( (string) $updates_failures ) . ' / incompat ' . esc_html( (string) $updates_incompat ) . '</div>';

        echo '<div style="grid-column:1 / -1;"><strong>5) Purge Médias:</strong><br>';
        echo 'Dernier scan: orphelins ' . esc_html( (string) $media_orphans ) . ' · supprimés ' . esc_html( (string) $media_deleted ) . ' · mode ' . esc_html( $media_dry_run ? 'Simulation' : 'Réel' ) . '</div>';

        echo '</div>';
        echo '<p style="margin-top:10px;">';
        echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=trq-security' ) ) . '">Ouvrir 360 Tranquillité</a> ';
        echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=trq-security&tab=media' ) ) . '">Purge Médias</a>';
        echo '</p>';
    }

    // =========================================================================
    // HELPERS UTILISÉS DANS LES VUES
    // =========================================================================

    public static function settings_form_open( string $tab ): void {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="trq-settings-form" data-trq-tab="' . esc_attr( $tab ) . '">';
        wp_nonce_field( 'trq_save_settings' );
        echo '<input type="hidden" name="action" value="trq_save" />';
        echo '<input type="hidden" name="trq_tab" value="' . esc_attr( $tab ) . '" />';
    }

    public static function settings_form_close(): void {
        echo '<p class="trq-submit"><button type="submit" class="button button-primary button-large">💾 Sauvegarder</button></p>';
        echo '</form>';
    }

    public static function action_form( string $action, array $extra_fields, string $tab, string $button_label, string $class = '' ): void {
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
        wp_nonce_field( 'trq_action' );
        echo '<input type="hidden" name="action"  value="trq_action" />';
        echo '<input type="hidden" name="trq_do"  value="' . esc_attr( $action ) . '" />';
        echo '<input type="hidden" name="trq_tab" value="' . esc_attr( $tab ) . '" />';
        foreach ( $extra_fields as $name => $value ) {
            echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '" />';
        }
        echo '<button type="submit" class="button ' . esc_attr( $class ) . '">' . esc_html( $button_label ) . '</button>';
        echo '</form>';
    }

    public static function toggle( string $name, bool $checked, string $label ): void {
        $id = 'trq_toggle_' . esc_attr( $name );
        ?>
        <label class="trq-toggle">
            <input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?> />
            <span class="trq-slider"></span>
            <?php echo esc_html( $label ); ?>
        </label>
        <?php
    }

    public static function get_logo_url(): string {
        $candidates = [
            TRQ_PLUGIN_DIR . 'assets/logo-360tranquilite.svg' => TRQ_PLUGIN_URL . 'assets/logo-360tranquilite.svg',
            TRQ_PLUGIN_DIR . 'assets/logo-360tranquilite.png' => TRQ_PLUGIN_URL . 'assets/logo-360tranquilite.png',
        ];

        foreach ( $candidates as $path => $url ) {
            if ( file_exists( $path ) ) {
                return $url;
            }
        }

        return '';
    }
}
