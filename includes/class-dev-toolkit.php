<?php
/**
 * Boite a outils dev (all-in-one) : fonctions pratiques pour limiter les plugins additionnels.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRQ_Dev_Toolkit {

    private static ?TRQ_Dev_Toolkit $instance = null;

    private bool $booted = false;

    /**
     * @var string[]
     */
    private array $columns_post_types = [];

    private function __construct() {}

    public static function get_instance(): TRQ_Dev_Toolkit {
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

        add_filter( 'upload_mimes', [ $this, 'filter_upload_mimes' ] );
        add_filter( 'post_row_actions', [ $this, 'add_duplicate_action' ], 10, 2 );
        add_filter( 'page_row_actions', [ $this, 'add_duplicate_action' ], 10, 2 );
        add_filter( 'media_row_actions', [ $this, 'add_media_replace_row_action' ], 10, 2 );
        add_action( 'add_meta_boxes_attachment', [ $this, 'register_attachment_media_replace_metabox' ] );
        add_action( 'add_meta_boxes', [ $this, 'register_external_permalink_metaboxes' ] );
        add_action( 'admin_post_trq_duplicate_content', [ $this, 'handle_duplicate_content' ] );
        add_action( 'admin_post_trq_replace_media', [ $this, 'handle_replace_media' ] );
        add_action( 'save_post', [ $this, 'save_external_permalink_meta' ], 10, 2 );

        add_action( 'wp_dashboard_setup', [ $this, 'maybe_disable_default_dashboard_widgets' ], 99 );
        add_action( 'admin_head', [ $this, 'inject_admin_css' ], 99 );
        add_action( 'admin_bar_menu', [ $this, 'cleanup_admin_bar_nodes' ], 99 );
        add_action( 'admin_menu', [ $this, 'maybe_hide_comments_menu' ], 99 );
        add_action( 'admin_menu', [ $this, 'apply_admin_menu_cleanup' ], 999 );
        add_filter( 'custom_menu_order', [ $this, 'enable_custom_menu_order' ] );
        add_filter( 'menu_order', [ $this, 'filter_admin_menu_order' ] );
        add_action( 'wp_head', [ $this, 'inject_front_assets' ], 99 );
        add_action( 'wp_footer', [ $this, 'inject_footer_code' ], 99 );
        add_action( 'init', [ $this, 'register_dynamic_content_types' ], 9 );
        add_action( 'init', [ $this, 'maybe_apply_staging_search_visibility' ], 11 );
        add_action( 'init', [ $this, 'maybe_disable_comments_features' ], 12 );
        add_action( 'init', [ $this, 'register_admin_columns_hooks' ], 20 );
        add_action( 'pre_get_posts', [ $this, 'apply_admin_columns_sorting' ] );
        add_action( 'restrict_manage_posts', [ $this, 'render_taxonomy_filters' ] );
        add_filter( 'parse_query', [ $this, 'apply_taxonomy_filters' ] );
        add_filter( 'get_terms_args', [ $this, 'filter_terms_ordering' ], 20, 2 );
        add_filter( 'show_admin_bar', [ $this, 'filter_show_admin_bar' ] );
        add_filter( 'admin_footer_text', [ $this, 'filter_admin_footer_text' ], 20 );
        add_filter( 'update_footer', [ $this, 'filter_admin_footer_version' ], 20 );
        add_filter( 'wp_robots', [ $this, 'filter_wp_robots_staging' ], 20 );
        add_action( 'admin_notices', [ $this, 'print_staging_noindex_notice' ] );
        add_filter( 'manage_users_columns', [ $this, 'filter_users_columns' ] );
        add_filter( 'manage_users_custom_column', [ $this, 'render_users_custom_column' ], 10, 3 );
        add_filter( 'manage_users_sortable_columns', [ $this, 'filter_users_sortable_columns' ] );
        add_action( 'pre_get_users', [ $this, 'sort_users_by_last_login' ] );
        add_filter( 'post_type_link', [ $this, 'filter_post_type_permalink' ], 10, 2 );
        add_filter( 'post_link', [ $this, 'filter_post_permalink' ], 10, 3 );
        add_filter( 'page_link', [ $this, 'filter_page_permalink' ], 10, 2 );
        add_action( 'template_redirect', [ $this, 'maybe_redirect_external_permalink' ], 1 );

        // Wrapper protective pour contourner les opcodes périmés sur serveurs agressifs
        add_filter(
            'login_redirect',
            function ( $redirect_to, $requested_redirect_to, $user ) {
                // Cast défensif : si WP_Error/autre, retour safe
                if ( ! is_string( $redirect_to ) ) {
                    $redirect_to = (string) admin_url();
                }
                if ( is_wp_error( $user ) ) {
                    return $redirect_to;
                }
                // Appel à la vraie fonction
                return $this->filter_login_redirect( $redirect_to, $requested_redirect_to, $user );
            },
            10,
            3
        );

        add_filter( 'logout_redirect', [ $this, 'filter_logout_redirect' ], 10, 3 );
        add_action( 'phpmailer_init', [ $this, 'configure_phpmailer' ] );
        add_filter( 'wp_mail_from', [ $this, 'filter_mail_from' ] );
        add_filter( 'wp_mail_from_name', [ $this, 'filter_mail_from_name' ] );

        add_action( 'template_redirect', [ $this, 'handle_maintenance_mode' ], 0 );

        add_filter( 'heartbeat_settings', [ $this, 'filter_heartbeat_settings' ] );
        add_filter( 'wp_revisions_to_keep', [ $this, 'filter_revisions_to_keep' ], 10, 2 );
        add_filter( 'robots_txt', [ $this, 'filter_robots_txt' ], 99, 2 );
        add_filter( 'comments_open', [ $this, 'filter_comments_open' ], 20, 2 );
        add_filter( 'pings_open', [ $this, 'filter_comments_open' ], 20, 2 );

        add_action( 'parse_request', [ $this, 'serve_ads_files' ] );
        add_filter( 'the_content', [ $this, 'obfuscate_emails' ], 20 );
        add_filter( 'the_content', [ $this, 'rewrite_external_links_in_html' ], 25 );
        add_filter( 'widget_text_content', [ $this, 'rewrite_external_links_in_html' ], 25 );
        add_filter( 'wp_nav_menu_objects', [ $this, 'adjust_external_menu_item_targets' ], 20, 2 );
        add_action( 'template_redirect', [ $this, 'maybe_block_feed_requests' ], 1 );
    }

    private function is_enabled(): bool {
        return (bool) TRQ_Core::get_instance()->get( 'toolkit_enabled', false );
    }

    private function is_smtp_enabled(): bool {
        return $this->is_enabled() && (bool) TRQ_Core::get_instance()->get( 'toolkit_smtp_enabled', false );
    }

    private function is_media_replacer_enabled(): bool {
        return $this->is_enabled() && (bool) TRQ_Core::get_instance()->get( 'toolkit_media_replacer_enabled', false );
    }

    private function is_external_permalink_enabled(): bool {
        return $this->is_enabled() && (bool) TRQ_Core::get_instance()->get( 'toolkit_external_permalink_enabled', false );
    }

    private function is_external_link_rewrite_enabled(): bool {
        return $this->is_enabled() && (
            (bool) TRQ_Core::get_instance()->get( 'toolkit_external_links_new_tab', false ) ||
            (bool) TRQ_Core::get_instance()->get( 'toolkit_external_links_nofollow', false )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parse_json_list_setting( string $option_key ): array {
        $raw = trim( (string) TRQ_Core::get_instance()->get( $option_key, '' ) );
        if ( '' === $raw ) {
            return [];
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return [];
        }

        return array_values(
            array_filter(
                $decoded,
                static function ( $item ): bool {
                    return is_array( $item );
                }
            )
        );
    }

    public function register_dynamic_content_types(): void {
        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_cpt_builder_enabled', false ) ) {
            return;
        }

        $cpts = $this->parse_json_list_setting( 'toolkit_cpts_json' );
        foreach ( $cpts as $item ) {
            $post_type = sanitize_key( (string) ( $item['post_type'] ?? '' ) );
            if ( '' === $post_type || post_type_exists( $post_type ) ) {
                continue;
            }

            $singular = sanitize_text_field( (string) ( $item['singular'] ?? $post_type ) );
            $plural   = sanitize_text_field( (string) ( $item['plural'] ?? $singular . 's' ) );

            $supports = (array) ( $item['supports'] ?? [ 'title', 'editor' ] );
            $supports = array_values(
                array_filter(
                    array_map( 'sanitize_key', $supports ),
                    static function ( string $support ): bool {
                        return '' !== $support;
                    }
                )
            );

            register_post_type(
                $post_type,
                [
                    'labels' => [
                        'name'          => $plural,
                        'singular_name' => $singular,
                        'add_new_item'  => sprintf( __( 'Ajouter %s', '360tranquilite' ), $singular ),
                        'edit_item'     => sprintf( __( 'Modifier %s', '360tranquilite' ), $singular ),
                        'new_item'      => sprintf( __( 'Nouveau %s', '360tranquilite' ), $singular ),
                        'view_item'     => sprintf( __( 'Voir %s', '360tranquilite' ), $singular ),
                        'all_items'     => sprintf( __( 'Tous les %s', '360tranquilite' ), $plural ),
                    ],
                    'public'             => isset( $item['public'] ) ? (bool) $item['public'] : true,
                    'show_in_rest'       => isset( $item['show_in_rest'] ) ? (bool) $item['show_in_rest'] : true,
                    'has_archive'        => isset( $item['has_archive'] ) ? (bool) $item['has_archive'] : true,
                    'menu_icon'          => sanitize_text_field( (string) ( $item['menu_icon'] ?? 'dashicons-admin-post' ) ),
                    'rewrite'            => [
                        'slug' => sanitize_title( (string) ( $item['rewrite_slug'] ?? $post_type ) ),
                    ],
                    'supports'           => ! empty( $supports ) ? $supports : [ 'title', 'editor' ],
                    'exclude_from_search' => ! empty( $item['exclude_from_search'] ),
                ]
            );
        }

        $taxonomies = $this->parse_json_list_setting( 'toolkit_taxonomies_json' );
        foreach ( $taxonomies as $item ) {
            $taxonomy = sanitize_key( (string) ( $item['taxonomy'] ?? '' ) );
            if ( '' === $taxonomy || taxonomy_exists( $taxonomy ) ) {
                continue;
            }

            $singular = sanitize_text_field( (string) ( $item['singular'] ?? $taxonomy ) );
            $plural   = sanitize_text_field( (string) ( $item['plural'] ?? $singular . 's' ) );
            $post_types = array_values(
                array_filter(
                    array_map( 'sanitize_key', (array) ( $item['post_types'] ?? [ 'post' ] ) )
                )
            );

            if ( empty( $post_types ) ) {
                $post_types = [ 'post' ];
            }

            register_taxonomy(
                $taxonomy,
                $post_types,
                [
                    'labels' => [
                        'name'          => $plural,
                        'singular_name' => $singular,
                        'search_items'  => sprintf( __( 'Rechercher %s', '360tranquilite' ), $plural ),
                        'all_items'     => sprintf( __( 'Tous les %s', '360tranquilite' ), $plural ),
                        'edit_item'     => sprintf( __( 'Modifier %s', '360tranquilite' ), $singular ),
                        'add_new_item'  => sprintf( __( 'Ajouter %s', '360tranquilite' ), $singular ),
                    ],
                    'public'       => isset( $item['public'] ) ? (bool) $item['public'] : true,
                    'show_in_rest' => isset( $item['show_in_rest'] ) ? (bool) $item['show_in_rest'] : true,
                    'hierarchical' => isset( $item['hierarchical'] ) ? (bool) $item['hierarchical'] : true,
                    'rewrite'      => [
                        'slug' => sanitize_title( (string) ( $item['rewrite_slug'] ?? $taxonomy ) ),
                    ],
                ]
            );
        }
    }

    public function register_admin_columns_hooks(): void {
        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_admin_columns_enabled', false ) ) {
            return;
        }

        $post_types = array_values(
            array_filter(
                array_map( 'sanitize_key', explode( ',', (string) TRQ_Core::get_instance()->get( 'toolkit_admin_columns_post_types', 'post,page' ) ) )
            )
        );

        if ( empty( $post_types ) ) {
            $post_types = [ 'post', 'page' ];
        }

        $this->columns_post_types = $post_types;

        foreach ( $this->columns_post_types as $post_type ) {
            add_filter(
                'manage_' . $post_type . '_posts_columns',
                function ( array $columns ) use ( $post_type ): array {
                    return $this->filter_admin_columns( $columns, $post_type );
                }
            );

            add_action(
                'manage_' . $post_type . '_posts_custom_column',
                function ( string $column, int $post_id ) use ( $post_type ): void {
                    $this->render_admin_column( $column, $post_id, $post_type );
                },
                10,
                2
            );

            add_filter(
                'manage_edit-' . $post_type . '_sortable_columns',
                function ( array $sortable ): array {
                    $sortable['trq_col_id'] = 'trq_id';
                    $sortable['trq_col_modified'] = 'trq_modified';
                    return $sortable;
                }
            );
        }
    }

    public function filter_admin_columns( array $columns, string $post_type ): array {
        $result = [];

        if ( isset( $columns['cb'] ) ) {
            $result['cb'] = $columns['cb'];
            unset( $columns['cb'] );
        }

        if ( (bool) TRQ_Core::get_instance()->get( 'toolkit_admin_column_id', true ) ) {
            $result['trq_col_id'] = __( 'ID', '360tranquilite' );
        }

        if ( (bool) TRQ_Core::get_instance()->get( 'toolkit_admin_column_thumbnail', true ) && post_type_supports( $post_type, 'thumbnail' ) ) {
            $result['trq_col_thumbnail'] = __( 'Image', '360tranquilite' );
        }

        if ( isset( $columns['title'] ) ) {
            $result['title'] = $columns['title'];
            unset( $columns['title'] );
        }

        if ( (bool) TRQ_Core::get_instance()->get( 'toolkit_admin_column_slug', true ) ) {
            $result['trq_col_slug'] = __( 'Slug', '360tranquilite' );
        }

        if ( (bool) TRQ_Core::get_instance()->get( 'toolkit_admin_column_modified', true ) ) {
            $result['trq_col_modified'] = __( 'Modifie', '360tranquilite' );
        }

        return array_merge( $result, $columns );
    }

    public function render_admin_column( string $column, int $post_id, string $post_type ): void {
        unset( $post_type );

        if ( 'trq_col_id' === $column ) {
            echo (int) $post_id;
            return;
        }

        if ( 'trq_col_thumbnail' === $column ) {
            $thumb = get_the_post_thumbnail( $post_id, [ 42, 42 ] );
            echo $thumb ? $thumb : '&mdash;';
            return;
        }

        if ( 'trq_col_slug' === $column ) {
            $post = get_post( $post_id );
            echo $post instanceof WP_Post ? esc_html( (string) $post->post_name ) : '&mdash;';
            return;
        }

        if ( 'trq_col_modified' === $column ) {
            echo esc_html( get_the_modified_date( 'Y-m-d H:i', $post_id ) );
        }
    }

    public function apply_admin_columns_sorting( WP_Query $query ): void {
        if ( ! is_admin() || ! $query->is_main_query() ) {
            return;
        }

        $orderby = (string) $query->get( 'orderby' );
        if ( 'trq_id' === $orderby ) {
            $query->set( 'orderby', 'ID' );
            return;
        }

        if ( 'trq_modified' === $orderby ) {
            $query->set( 'orderby', 'modified' );
        }
    }

    public function filter_users_columns( array $columns ): array {
        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_users_last_login_column', false ) ) {
            return $columns;
        }

        $result = [];
        foreach ( $columns as $key => $label ) {
            $result[ $key ] = $label;
            if ( 'email' === $key ) {
                $result['trq_last_login'] = __( 'Derniere connexion', '360tranquilite' );
            }
        }

        if ( ! isset( $result['trq_last_login'] ) ) {
            $result['trq_last_login'] = __( 'Derniere connexion', '360tranquilite' );
        }

        return $result;
    }

    public function render_users_custom_column( string $value, string $column_name, int $user_id ): string {
        if ( 'trq_last_login' !== $column_name ) {
            return $value;
        }

        $last_login = (string) get_user_meta( $user_id, 'trq_last_login_at', true );
        if ( '' === $last_login ) {
            return '&mdash;';
        }

        return esc_html( get_date_from_gmt( $last_login, 'Y-m-d H:i' ) );
    }

    public function filter_users_sortable_columns( array $sortable ): array {
        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_users_last_login_column', false ) ) {
            return $sortable;
        }

        $sortable['trq_last_login'] = 'trq_last_login';
        return $sortable;
    }

    public function sort_users_by_last_login( WP_User_Query $query ): void {
        if ( ! is_admin() || ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_users_last_login_column', false ) ) {
            return;
        }

        $orderby = (string) $query->get( 'orderby' );
        if ( 'trq_last_login' !== $orderby ) {
            return;
        }

        $query->set( 'meta_key', 'trq_last_login_at' );
        $query->set( 'orderby', 'meta_value' );
    }

    public function render_taxonomy_filters(): void {
        if ( ! is_admin() || ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_taxonomy_filters_enabled', false ) ) {
            return;
        }

        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'edit' !== $screen->base ) {
            return;
        }

        $post_type = (string) ( $screen->post_type ?? '' );
        if ( '' === $post_type || ! $this->is_tax_filter_post_type_allowed( $post_type ) ) {
            return;
        }

        $taxonomies = get_object_taxonomies( $post_type, 'objects' );
        if ( empty( $taxonomies ) ) {
            return;
        }

        foreach ( $taxonomies as $taxonomy => $taxonomy_obj ) {
            if ( ! isset( $taxonomy_obj->show_admin_column ) || ! $taxonomy_obj->show_admin_column ) {
                continue;
            }

            $current = isset( $_GET[ $taxonomy ] ) ? sanitize_text_field( wp_unslash( $_GET[ $taxonomy ] ) ) : '';
            wp_dropdown_categories(
                [
                    'show_option_all' => sprintf( __( 'Tous: %s', '360tranquilite' ), $taxonomy_obj->labels->name ),
                    'taxonomy'        => $taxonomy,
                    'name'            => $taxonomy,
                    'orderby'         => 'name',
                    'selected'        => $current,
                    'hierarchical'    => true,
                    'depth'           => 3,
                    'show_count'      => false,
                    'hide_empty'      => false,
                    'value_field'     => 'slug',
                ]
            );
        }
    }

    public function apply_taxonomy_filters( $query ) {
        if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
            return $query;
        }

        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_taxonomy_filters_enabled', false ) ) {
            return $query;
        }

        global $pagenow;
        if ( 'edit.php' !== $pagenow ) {
            return $query;
        }

        $post_type = isset( $_GET['post_type'] ) ? sanitize_key( (string) wp_unslash( $_GET['post_type'] ) ) : 'post';
        if ( ! $this->is_tax_filter_post_type_allowed( $post_type ) ) {
            return $query;
        }

        $tax_query = [];
        $taxonomies = get_object_taxonomies( $post_type );
        foreach ( $taxonomies as $taxonomy ) {
            $value = isset( $_GET[ $taxonomy ] ) ? sanitize_text_field( wp_unslash( $_GET[ $taxonomy ] ) ) : '';
            if ( '' === $value || '0' === $value ) {
                continue;
            }

            $tax_query[] = [
                'taxonomy' => $taxonomy,
                'field'    => 'slug',
                'terms'    => [ $value ],
            ];
        }

        if ( ! empty( $tax_query ) ) {
            if ( count( $tax_query ) > 1 ) {
                $tax_query['relation'] = 'AND';
            }
            $query->set( 'tax_query', $tax_query );
        }

        return $query;
    }

    private function is_tax_filter_post_type_allowed( string $post_type ): bool {
        $raw = (string) TRQ_Core::get_instance()->get( 'toolkit_taxonomy_filters_post_types', 'post,page' );
        $allowed = array_values(
            array_filter(
                array_map( 'sanitize_key', explode( ',', $raw ) )
            )
        );

        if ( empty( $allowed ) ) {
            $allowed = [ 'post', 'page' ];
        }

        return in_array( $post_type, $allowed, true );
    }

    public function filter_terms_ordering( $args, $taxonomies ) {
        if ( ! is_array( $args ) ) {
            return $args;
        }

        if ( ! is_array( $taxonomies ) ) {
            $taxonomies = [];
        }

        unset( $taxonomies );

        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_taxonomy_terms_order_enabled', false ) ) {
            return $args;
        }

        if ( isset( $args['orderby'] ) && ! in_array( (string) $args['orderby'], [ '', 'name', 'none' ], true ) ) {
            return $args;
        }

        $orderby = (string) TRQ_Core::get_instance()->get( 'toolkit_taxonomy_terms_orderby', 'name' );
        if ( ! in_array( $orderby, [ 'name', 'slug', 'count', 'term_id' ], true ) ) {
            $orderby = 'name';
        }

        $order = strtoupper( (string) TRQ_Core::get_instance()->get( 'toolkit_taxonomy_terms_order', 'ASC' ) );
        if ( ! in_array( $order, [ 'ASC', 'DESC' ], true ) ) {
            $order = 'ASC';
        }

        $args['orderby'] = $orderby;
        $args['order'] = $order;

        return $args;
    }

    private function parse_csv_slugs( string $raw ): array {
        return array_values(
            array_filter(
                array_map( 'sanitize_key', array_map( 'trim', explode( ',', $raw ) ) )
            )
        );
    }

    public function apply_admin_menu_cleanup(): void {
        if ( ! is_admin() || ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_admin_menu_cleanup_enabled', false ) ) {
            return;
        }

        $slugs = $this->parse_csv_slugs( (string) TRQ_Core::get_instance()->get( 'toolkit_admin_menu_hidden_slugs', '' ) );
        foreach ( $slugs as $slug ) {
            remove_menu_page( $slug );
        }
    }

    public function enable_custom_menu_order( $enabled ) {
        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_admin_menu_reorder_enabled', false ) ) {
            return $enabled;
        }

        return true;
    }

    public function filter_admin_menu_order( $menu_order ) {
        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_admin_menu_reorder_enabled', false ) ) {
            return $menu_order;
        }

        if ( ! is_array( $menu_order ) ) {
            return $menu_order;
        }

        $desired = $this->parse_csv_slugs( (string) TRQ_Core::get_instance()->get( 'toolkit_admin_menu_order', '' ) );
        if ( empty( $desired ) ) {
            return $menu_order;
        }

        $result = [];
        foreach ( $desired as $slug ) {
            if ( in_array( $slug, $menu_order, true ) ) {
                $result[] = $slug;
            }
        }

        foreach ( $menu_order as $slug ) {
            if ( ! in_array( $slug, $result, true ) ) {
                $result[] = $slug;
            }
        }

        return $result;
    }

    private function is_staging_environment_detected(): bool {
        $host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        if ( '' === $host ) {
            return false;
        }

        $patterns = array_values(
            array_filter(
                array_map( 'trim', explode( ',', (string) TRQ_Core::get_instance()->get( 'toolkit_staging_patterns', 'staging.,dev.,localhost,.local,.test' ) ) )
            )
        );

        foreach ( $patterns as $pattern ) {
            if ( '' !== $pattern && false !== strpos( $host, strtolower( $pattern ) ) ) {
                return true;
            }
        }

        return false;
    }

    public function maybe_apply_staging_search_visibility(): void {
        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_staging_noindex_enabled', false ) ) {
            return;
        }

        if ( ! $this->is_staging_environment_detected() ) {
            return;
        }

        if ( TRQ_Core::get_instance()->get( 'toolkit_staging_set_blog_public_zero', false ) && (int) get_option( 'blog_public', 1 ) !== 0 ) {
            update_option( 'blog_public', 0 );
            update_option( 'trq_staging_noindex_applied_at', current_time( 'mysql', true ), false );
        }
    }

    public function filter_wp_robots_staging( array $robots ): array {
        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_staging_noindex_enabled', false ) ) {
            return $robots;
        }

        if ( ! $this->is_staging_environment_detected() ) {
            return $robots;
        }

        $robots['noindex'] = true;
        $robots['nofollow'] = true;
        $robots['noarchive'] = true;

        return $robots;
    }

    public function print_staging_noindex_notice(): void {
        if ( ! is_admin() || ! current_user_can( 'manage_options' ) || ! $this->is_enabled() ) {
            return;
        }

        if ( ! TRQ_Core::get_instance()->get( 'toolkit_staging_noindex_enabled', false ) || ! $this->is_staging_environment_detected() ) {
            return;
        }

        echo '<div class="notice notice-warning is-dismissible"><p>'
            . esc_html__( '360 Tranquillite: environnement staging detecte, directives noindex/nofollow actives.', '360tranquilite' )
            . '</p></div>';
    }

    public function configure_phpmailer( $phpmailer ): void {
        if ( ! $this->is_smtp_enabled() || ! is_object( $phpmailer ) ) {
            return;
        }

        $core = TRQ_Core::get_instance();
        $host = sanitize_text_field( (string) $core->get( 'toolkit_smtp_host', '' ) );
        $port = max( 1, min( 65535, (int) $core->get( 'toolkit_smtp_port', 587 ) ) );

        if ( '' === $host ) {
            return;
        }

        $secure = (string) $core->get( 'toolkit_smtp_secure', 'tls' );
        $secure = in_array( $secure, [ 'none', 'ssl', 'tls' ], true ) ? $secure : 'tls';

        $phpmailer->isSMTP();
        $phpmailer->Host       = $host;
        $phpmailer->Port       = $port;
        $phpmailer->SMTPAuth   = (bool) $core->get( 'toolkit_smtp_auth', true );
        $phpmailer->SMTPSecure = 'none' === $secure ? '' : $secure;

        if ( $phpmailer->SMTPAuth ) {
            $phpmailer->Username = (string) $core->get( 'toolkit_smtp_user', '' );
            $phpmailer->Password = (string) $core->get( 'toolkit_smtp_pass', '' );
        }

        $from_email = sanitize_email( (string) $core->get( 'toolkit_smtp_from_email', '' ) );
        $from_name  = sanitize_text_field( (string) $core->get( 'toolkit_smtp_from_name', '' ) );
        if ( '' !== $from_email ) {
            $phpmailer->setFrom( $from_email, $from_name ?: get_bloginfo( 'name' ), false );
        }
    }

    public function filter_mail_from( string $email ): string {
        if ( ! $this->is_smtp_enabled() ) {
            return $email;
        }

        $from = sanitize_email( (string) TRQ_Core::get_instance()->get( 'toolkit_smtp_from_email', '' ) );
        return '' !== $from ? $from : $email;
    }

    public function filter_mail_from_name( string $name ): string {
        if ( ! $this->is_smtp_enabled() ) {
            return $name;
        }

        $from_name = sanitize_text_field( (string) TRQ_Core::get_instance()->get( 'toolkit_smtp_from_name', '' ) );
        return '' !== $from_name ? $from_name : $name;
    }

    public function filter_upload_mimes( array $mimes ): array {
        if ( ! $this->is_enabled() ) {
            return $mimes;
        }

        $core = TRQ_Core::get_instance();
        if ( $core->get( 'toolkit_allow_svg', true ) ) {
            $mimes['svg'] = 'image/svg+xml';
        }
        if ( $core->get( 'toolkit_allow_avif', true ) ) {
            $mimes['avif'] = 'image/avif';
        }

        return $mimes;
    }

    public function add_duplicate_action( array $actions, WP_Post $post ): array {
        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_duplicate_content', true ) ) {
            return $actions;
        }

        if ( ! current_user_can( 'edit_posts' ) ) {
            return $actions;
        }

        $url = wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'trq_duplicate_content',
                    'post_id' => $post->ID,
                ],
                admin_url( 'admin-post.php' )
            ),
            'trq_duplicate_content_' . $post->ID
        );

        $actions['trq_duplicate'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Dupliquer', '360tranquilite' ) . '</a>';
        return $actions;
    }

    public function add_media_replace_row_action( array $actions, WP_Post $post ): array {
        if ( 'attachment' !== $post->post_type || ! $this->is_media_replacer_enabled() ) {
            return $actions;
        }

        if ( ! current_user_can( 'edit_post', $post->ID ) || ! current_user_can( 'upload_files' ) ) {
            return $actions;
        }

        $url = admin_url( 'post.php?post=' . (int) $post->ID . '&action=edit#trq-media-replacer-box' );
        $actions['trq_replace_media'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Remplacer fichier', '360tranquilite' ) . '</a>';

        return $actions;
    }

    public function register_attachment_media_replace_metabox(): void {
        if ( ! $this->is_media_replacer_enabled() || ! current_user_can( 'upload_files' ) ) {
            return;
        }

        add_meta_box(
            'trq-media-replacer-box',
            __( 'Media Replacer (conserver ID et URLs)', '360tranquilite' ),
            [ $this, 'render_attachment_media_replace_metabox' ],
            'attachment',
            'side',
            'high'
        );
    }

    public function render_attachment_media_replace_metabox( WP_Post $post ): void {
        if ( ! current_user_can( 'edit_post', $post->ID ) || ! current_user_can( 'upload_files' ) ) {
            echo '<p>' . esc_html__( 'Acces refuse.', '360tranquilite' ) . '</p>';
            return;
        }

        ?>
        <p><?php esc_html_e( 'Remplacez ce fichier media sans changer son ID WordPress.', '360tranquilite' ); ?></p>
        <p class="description"><?php esc_html_e( 'Utilisez la meme extension de fichier pour conserver les URLs publiques.', '360tranquilite' ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
            <?php wp_nonce_field( 'trq_replace_media' ); ?>
            <input type="hidden" name="action" value="trq_replace_media" />
            <input type="hidden" name="toolkit_replace_media_id" value="<?php echo esc_attr( (string) (int) $post->ID ); ?>" />
            <input type="hidden" name="return_url" value="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $post->ID . '&action=edit' ) ); ?>" />
            <p><input type="file" name="toolkit_replace_media_file" accept="image/*" required /></p>
            <p><button type="submit" class="button button-primary button-small"><?php esc_html_e( 'Remplacer ce media', '360tranquilite' ); ?></button></p>
        </form>
        <?php
    }

    public function handle_duplicate_content(): void {
        $post_id = (int) ( $_GET['post_id'] ?? 0 );

        if ( $post_id <= 0 || ! current_user_can( 'edit_posts' ) ) {
            wp_die( esc_html__( 'Acces refuse.', '360tranquilite' ) );
        }

        check_admin_referer( 'trq_duplicate_content_' . $post_id );

        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            wp_die( esc_html__( 'Contenu introuvable.', '360tranquilite' ) );
        }

        $new_id = wp_insert_post(
            [
                'post_type'    => $post->post_type,
                'post_status'  => 'draft',
                'post_title'   => $post->post_title . ' (copie)',
                'post_content' => $post->post_content,
                'post_excerpt' => $post->post_excerpt,
                'post_author'  => get_current_user_id(),
                'post_parent'  => $post->post_parent,
                'menu_order'   => $post->menu_order,
            ]
        );

        if ( is_wp_error( $new_id ) || ! $new_id ) {
            wp_die( esc_html__( 'Duplication impossible.', '360tranquilite' ) );
        }

        $meta = get_post_meta( $post_id );
        foreach ( $meta as $key => $values ) {
            if ( in_array( $key, [ '_edit_lock', '_edit_last' ], true ) ) {
                continue;
            }
            foreach ( (array) $values as $value ) {
                add_post_meta( $new_id, $key, maybe_unserialize( $value ) );
            }
        }

        $taxonomies = get_object_taxonomies( $post->post_type );
        foreach ( $taxonomies as $taxonomy ) {
            $terms = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );
            if ( ! is_wp_error( $terms ) ) {
                wp_set_object_terms( $new_id, $terms, $taxonomy );
            }
        }

        wp_safe_redirect( admin_url( 'post.php?post=' . $new_id . '&action=edit' ) );
        exit;
    }

    public function handle_replace_media(): void {
        if ( ! current_user_can( 'upload_files' ) ) {
            wp_die( esc_html__( 'Acces refuse.', '360tranquilite' ) );
        }

        check_admin_referer( 'trq_replace_media' );

        $result = $this->replace_media_attachment( $_POST, $_FILES );
        set_transient( 'trq_admin_notice', $result, 90 );

        $return_url = esc_url_raw( (string) ( $_POST['return_url'] ?? '' ) );
        if ( '' !== $return_url && 0 === strpos( $return_url, admin_url() ) ) {
            wp_safe_redirect( $return_url );
            exit;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=trq-security&tab=toolkit' ) );
        exit;
    }

    private function replace_media_attachment( array $post, array $files ): array {
        if ( ! $this->is_media_replacer_enabled() ) {
            return [
                'success' => false,
                'message' => 'Activez Media Replacer dans la Boite a Outils Dev avant de remplacer un media.',
            ];
        }

        $attachment_id = (int) ( $post['toolkit_replace_media_id'] ?? ( $post['attachment_id'] ?? 0 ) );
        if ( $attachment_id <= 0 ) {
            return [
                'success' => false,
                'message' => 'ID media invalide.',
            ];
        }

        if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
            return [
                'success' => false,
                'message' => 'Vous ne pouvez pas modifier ce media.',
            ];
        }

        $attachment = get_post( $attachment_id );
        if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
            return [
                'success' => false,
                'message' => 'Le media cible est introuvable.',
            ];
        }

        $old_file = get_attached_file( $attachment_id );
        if ( ! is_string( $old_file ) || '' === $old_file || ! file_exists( $old_file ) ) {
            return [
                'success' => false,
                'message' => 'Le fichier source du media est introuvable sur le disque.',
            ];
        }

        if ( empty( $files['toolkit_replace_media_file'] ) || ! is_array( $files['toolkit_replace_media_file'] ) ) {
            return [
                'success' => false,
                'message' => 'Aucun fichier de remplacement fourni.',
            ];
        }

        $uploaded = $files['toolkit_replace_media_file'];
        if ( ! empty( $uploaded['error'] ) ) {
            return [
                'success' => false,
                'message' => 'Le televersement du fichier de remplacement a echoue.',
            ];
        }

        $tmp_name = $uploaded['tmp_name'] ?? '';
        if ( ! is_string( $tmp_name ) || '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
            return [
                'success' => false,
                'message' => 'Fichier de remplacement invalide.',
            ];
        }

        $old_ext = strtolower( (string) pathinfo( $old_file, PATHINFO_EXTENSION ) );
        $new_ext = strtolower( (string) pathinfo( (string) ( $uploaded['name'] ?? '' ), PATHINFO_EXTENSION ) );
        if ( '' === $old_ext || '' === $new_ext || $old_ext !== $new_ext ) {
            return [
                'success' => false,
                'message' => 'Le fichier de remplacement doit avoir la meme extension que le media existant pour conserver les URLs.',
            ];
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        $move = wp_handle_upload(
            $uploaded,
            [
                'test_form' => false,
                'mimes'     => $this->get_allowed_replacement_mimes(),
            ]
        );

        if ( ! is_array( $move ) || ! empty( $move['error'] ) || empty( $move['file'] ) ) {
            return [
                'success' => false,
                'message' => ! empty( $move['error'] ) ? (string) $move['error'] : 'Impossible de preparer le media de remplacement.',
            ];
        }

        $new_uploaded_file = (string) $move['file'];
        $old_meta          = wp_get_attachment_metadata( $attachment_id );

        $copied = @copy( $new_uploaded_file, $old_file );
        @unlink( $new_uploaded_file );

        if ( ! $copied ) {
            return [
                'success' => false,
                'message' => 'Impossible de remplacer le fichier sur le disque.',
            ];
        }

        $this->delete_intermediate_sizes( $old_file, is_array( $old_meta ) ? $old_meta : [] );

        require_once ABSPATH . 'wp-admin/includes/image.php';
        update_attached_file( $attachment_id, $old_file );

        $new_meta = wp_generate_attachment_metadata( $attachment_id, $old_file );
        if ( is_wp_error( $new_meta ) ) {
            return [
                'success' => false,
                'message' => 'Le fichier principal a ete remplace mais la regeneration des miniatures a echoue: ' . $new_meta->get_error_message(),
            ];
        }

        if ( is_array( $new_meta ) ) {
            wp_update_attachment_metadata( $attachment_id, $new_meta );
        }

        $new_mime = sanitize_mime_type( (string) ( $move['type'] ?? '' ) );
        if ( '' !== $new_mime && $new_mime !== (string) $attachment->post_mime_type ) {
            wp_update_post(
                [
                    'ID'             => $attachment_id,
                    'post_mime_type' => $new_mime,
                ]
            );
        }

        return [
            'success' => true,
            'message' => sprintf( 'Media #%d remplace avec succes. ID et URLs conserves.', $attachment_id ),
        ];
    }

    private function get_allowed_replacement_mimes(): array {
        $allowed = get_allowed_mime_types();
        $result  = [];

        foreach ( $allowed as $ext => $mime ) {
            if ( 0 === strpos( (string) $mime, 'image/' ) || 'image/svg+xml' === $mime ) {
                $result[ $ext ] = $mime;
            }
        }

        return $result;
    }

    private function delete_intermediate_sizes( string $original_file, array $metadata ): void {
        if ( empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
            return;
        }

        $dir = trailingslashit( dirname( $original_file ) );
        foreach ( $metadata['sizes'] as $size ) {
            if ( ! is_array( $size ) || empty( $size['file'] ) ) {
                continue;
            }

            $candidate = $dir . ltrim( (string) $size['file'], '/\\' );
            if ( file_exists( $candidate ) ) {
                @unlink( $candidate );
            }
        }
    }

    public function maybe_disable_default_dashboard_widgets(): void {
        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_disable_dashboard_widgets', false ) ) {
            return;
        }

        remove_meta_box( 'dashboard_primary', 'dashboard', 'side' );
        remove_meta_box( 'dashboard_quick_press', 'dashboard', 'side' );
        remove_meta_box( 'dashboard_right_now', 'dashboard', 'normal' );
        remove_meta_box( 'dashboard_activity', 'dashboard', 'normal' );
        remove_meta_box( 'dashboard_site_health', 'dashboard', 'normal' );
    }

    public function inject_admin_css(): void {
        if ( ! is_admin() || ! $this->is_enabled() ) {
            return;
        }

        $custom_css = (string) TRQ_Core::get_instance()->get( 'toolkit_admin_css', '' );
        $hide_notices = (bool) TRQ_Core::get_instance()->get( 'toolkit_hide_admin_notices', false );

        if ( ! $hide_notices && '' === trim( $custom_css ) ) {
            $custom_css = '';
        }

        $menu_width = max( 160, min( 360, (int) TRQ_Core::get_instance()->get( 'toolkit_admin_menu_width', 160 ) ) );
        $menu_css = '';
        if ( $menu_width !== 160 ) {
            $menu_folded_width = max( 56, (int) floor( $menu_width * 0.22 ) );
            $menu_css = '#adminmenuwrap,#adminmenu,.folded #adminmenu,.folded #adminmenu li.menu-top{width:' . $menu_width . 'px;}'
                . '#adminmenu .wp-submenu{left:' . $menu_width . 'px;}'
                . '#wpcontent,#wpfooter{margin-left:' . $menu_width . 'px;}'
                . '.auto-fold #adminmenu,.auto-fold #adminmenu li.menu-top,.auto-fold #adminmenuback,.auto-fold #adminmenuwrap{width:' . $menu_folded_width . 'px;}'
                . '.auto-fold #wpcontent,.auto-fold #wpfooter{margin-left:' . $menu_folded_width . 'px;}';
        }

        if ( '' === $menu_css && ! $hide_notices && '' === trim( $custom_css ) ) {
            return;
        }

        echo '<style id="trq-toolkit-admin">';
        if ( $hide_notices ) {
            echo '.notice:not(.notice-error):not(.notice-warning):not(.update-nag), .update-nag{display:none !important;}';
        }
        if ( '' !== $menu_css ) {
            echo $menu_css;
        }
        if ( '' !== trim( $custom_css ) ) {
            echo wp_strip_all_tags( $custom_css );
        }
        echo '</style>';
    }

    public function filter_show_admin_bar( bool $show ): bool {
        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_hide_front_admin_bar', false ) ) {
            return $show;
        }

        if ( is_admin() || current_user_can( 'manage_options' ) ) {
            return $show;
        }

        return false;
    }

    public function cleanup_admin_bar_nodes( WP_Admin_Bar $wp_admin_bar ): void {
        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_admin_bar_cleanup_enabled', false ) ) {
            return;
        }

        if ( TRQ_Core::get_instance()->get( 'toolkit_admin_bar_remove_wp_logo', false ) ) {
            $wp_admin_bar->remove_node( 'wp-logo' );
        }
        if ( TRQ_Core::get_instance()->get( 'toolkit_admin_bar_remove_comments', false ) ) {
            $wp_admin_bar->remove_node( 'comments' );
        }
        if ( TRQ_Core::get_instance()->get( 'toolkit_admin_bar_remove_new_content', false ) ) {
            $wp_admin_bar->remove_node( 'new-content' );
        }
        if ( TRQ_Core::get_instance()->get( 'toolkit_admin_bar_remove_updates', false ) ) {
            $wp_admin_bar->remove_node( 'updates' );
        }
    }

    public function maybe_hide_comments_menu(): void {
        if ( ! $this->is_enabled() ) {
            return;
        }

        if ( TRQ_Core::get_instance()->get( 'toolkit_disable_comments', false ) || in_array( 'edit-comments.php', $this->parse_csv_slugs( (string) TRQ_Core::get_instance()->get( 'toolkit_admin_menu_hidden_slugs', '' ) ), true ) ) {
            remove_menu_page( 'edit-comments.php' );
        }
    }

    public function filter_admin_footer_text( string $text ): string {
        if ( ! is_admin() || ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_admin_footer_text_enabled', false ) ) {
            return $text;
        }

        $custom_text = trim( (string) TRQ_Core::get_instance()->get( 'toolkit_admin_footer_text', '' ) );
        return '' !== $custom_text ? esc_html( $custom_text ) : $text;
    }

    public function filter_admin_footer_version( string $text ): string {
        if ( ! is_admin() || ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_admin_footer_text_enabled', false ) ) {
            return $text;
        }

        return '';
    }

    public function inject_front_assets(): void {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $front_css = (string) TRQ_Core::get_instance()->get( 'toolkit_front_css', '' );
        $head_code = (string) TRQ_Core::get_instance()->get( 'toolkit_head_code', '' );

        if ( '' !== trim( $front_css ) ) {
            echo '<style id="trq-toolkit-front">' . wp_strip_all_tags( $front_css ) . '</style>';
        }

        if ( '' !== trim( $head_code ) ) {
            echo $head_code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    public function inject_footer_code(): void {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $footer_code = (string) TRQ_Core::get_instance()->get( 'toolkit_footer_code', '' );
        if ( '' !== trim( $footer_code ) ) {
            echo $footer_code; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    public function filter_login_redirect( string $redirect_to, string $requested_redirect_to, $user ): string {
        unset( $requested_redirect_to );

        // Défense ultime contre opcodes périmés : vérification RUNTIME du type
        if ( ! is_string( $redirect_to ) ) {
            $redirect_to = (string) admin_url();
        }
        if ( ! $user instanceof WP_User && is_wp_error( $user ) ) {
            return $redirect_to;
        }
        if ( ! $user instanceof WP_User ) {
            return $redirect_to;
        }

        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_login_redirect_enabled', false ) ) {
            return $redirect_to;
        }

        $target = (string) TRQ_Core::get_instance()->get( 'toolkit_login_redirect_url', '' );
        if ( '' !== $target ) {
            return esc_url_raw( $target );
        }

        return $redirect_to;
    }

    public function filter_logout_redirect( string $redirect_to, string $requested_redirect_to, $user ): string {
        unset( $requested_redirect_to, $user );

        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_logout_redirect_enabled', false ) ) {
            return $redirect_to;
        }

        $target = (string) TRQ_Core::get_instance()->get( 'toolkit_logout_redirect_url', '' );
        return '' !== $target ? esc_url_raw( $target ) : $redirect_to;
    }

    public function handle_maintenance_mode(): void {
        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_maintenance_mode', false ) ) {
            return;
        }

        if ( is_admin() || ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
            return;
        }

        if ( current_user_can( 'manage_options' ) ) {
            return;
        }

        status_header( 503 );
        nocache_headers();

        $msg = (string) TRQ_Core::get_instance()->get( 'toolkit_maintenance_message', __( 'Site en maintenance, revenez bientot.', '360tranquilite' ) );
        wp_die( esc_html( $msg ), esc_html__( 'Maintenance', '360tranquilite' ), [ 'response' => 503 ] );
    }

    public function filter_heartbeat_settings( array $settings ): array {
        if ( ! $this->is_enabled() ) {
            return $settings;
        }

        $mode = (string) TRQ_Core::get_instance()->get( 'toolkit_heartbeat_mode', 'default' );
        if ( 'disabled' === $mode ) {
            $settings['interval'] = 120;
        } elseif ( 'reduced' === $mode ) {
            $settings['interval'] = 60;
        }

        return $settings;
    }

    public function filter_revisions_to_keep( int $num, WP_Post $post ): int {
        unset( $post );

        if ( ! $this->is_enabled() ) {
            return $num;
        }

        $limit = (int) TRQ_Core::get_instance()->get( 'toolkit_revisions_limit', 10 );
        return max( 0, min( 100, $limit ) );
    }

    public function filter_robots_txt( string $output, bool $public ): string {
        unset( $public );

        if ( ! $this->is_enabled() ) {
            return $output;
        }

        $custom = (string) TRQ_Core::get_instance()->get( 'toolkit_robots_txt', '' );
        return '' !== trim( $custom ) ? trim( $custom ) . "\n" : $output;
    }

    public function maybe_disable_comments_features(): void {
        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_disable_comments', false ) ) {
            return;
        }

        foreach ( get_post_types( [ 'public' => true ], 'names' ) as $post_type ) {
            if ( post_type_supports( $post_type, 'comments' ) ) {
                remove_post_type_support( $post_type, 'comments' );
            }
            if ( post_type_supports( $post_type, 'trackbacks' ) ) {
                remove_post_type_support( $post_type, 'trackbacks' );
            }
        }
    }

    public function filter_comments_open( bool $open, int $post_id ): bool {
        unset( $post_id );

        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_disable_comments', false ) ) {
            return $open;
        }

        return false;
    }

    public function maybe_block_feed_requests(): void {
        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_disable_feeds', false ) ) {
            return;
        }

        if ( is_feed() ) {
            wp_die( esc_html__( 'Les flux RSS sont désactivés.', '360tranquilite' ), '', [ 'response' => 403 ] );
        }
    }

    public function serve_ads_files( WP $wp ): void {
        if ( ! $this->is_enabled() ) {
            return;
        }

        $request = trim( (string) ( $wp->request ?? '' ), '/' );

        if ( 'ads.txt' === $request ) {
            $this->serve_plain_text_option( 'toolkit_ads_txt' );
        }

        if ( 'app-ads.txt' === $request ) {
            $this->serve_plain_text_option( 'toolkit_app_ads_txt' );
        }
    }

    private function serve_plain_text_option( string $option_key ): void {
        $content = (string) TRQ_Core::get_instance()->get( $option_key, '' );
        if ( '' === trim( $content ) ) {
            return;
        }

        nocache_headers();
        header( 'Content-Type: text/plain; charset=utf-8' );
        echo trim( $content );
        exit;
    }

    public function obfuscate_emails( string $content ): string {
        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_email_obfuscation', false ) ) {
            return $content;
        }

        return preg_replace_callback(
            '/([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/',
            static function ( array $matches ): string {
                return antispambot( $matches[1] );
            },
            $content
        ) ?: $content;
    }

    public function rewrite_external_links_in_html( string $content ): string {
        if ( ! $this->is_external_link_rewrite_enabled() || '' === trim( $content ) ) {
            return $content;
        }

        if ( false === stripos( $content, '<a ' ) ) {
            return $content;
        }

        $home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        $open_new_tab = (bool) TRQ_Core::get_instance()->get( 'toolkit_external_links_new_tab', false );
        $nofollow = (bool) TRQ_Core::get_instance()->get( 'toolkit_external_links_nofollow', false );

        $pattern = '/<a\b([^>]*?)href=("|\')(https?:\/\/[^\"\']+)\2([^>]*)>/i';

        return preg_replace_callback(
            $pattern,
            static function ( array $matches ) use ( $home_host, $open_new_tab, $nofollow ): string {
                $full = $matches[0];
                $url = (string) $matches[3];
                $host = (string) wp_parse_url( $url, PHP_URL_HOST );

                if ( '' === $host || ( '' !== $home_host && strtolower( $host ) === strtolower( $home_host ) ) ) {
                    return $full;
                }

                $attrs = $matches[1] . 'href=' . $matches[2] . $url . $matches[2] . $matches[4];

                if ( $open_new_tab && ! preg_match( '/\btarget\s*=\s*("|\')_blank\1/i', $attrs ) ) {
                    $attrs .= ' target="_blank"';
                }

                if ( $open_new_tab || $nofollow ) {
                    $rels = [];
                    if ( preg_match( '/\brel\s*=\s*("|\')(.*?)\1/i', $attrs, $rel_match ) ) {
                        $rels = preg_split( '/\s+/', strtolower( trim( (string) $rel_match[2] ) ) ) ?: [];
                        $rels = array_values( array_filter( $rels ) );
                        $attrs = str_replace( $rel_match[0], '', $attrs );
                    }

                    if ( $open_new_tab ) {
                        $rels[] = 'noopener';
                        $rels[] = 'noreferrer';
                    }
                    if ( $nofollow ) {
                        $rels[] = 'nofollow';
                    }

                    $rels = array_values( array_unique( $rels ) );
                    if ( ! empty( $rels ) ) {
                        $attrs .= ' rel="' . esc_attr( implode( ' ', $rels ) ) . '"';
                    }
                }

                return '<a ' . trim( $attrs ) . '>';
            },
            $content
        ) ?: $content;
    }

    public function adjust_external_menu_item_targets( array $items, stdClass $args ): array {
        unset( $args );

        if ( ! $this->is_enabled() || ! TRQ_Core::get_instance()->get( 'toolkit_external_permalink_new_tab', false ) ) {
            return $items;
        }

        $home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
        foreach ( $items as $item ) {
            if ( ! $item instanceof WP_Post ) {
                continue;
            }

            $url = (string) ( $item->url ?? '' );
            $host = (string) wp_parse_url( $url, PHP_URL_HOST );
            if ( '' === $host || ( '' !== $home_host && strtolower( $host ) === strtolower( $home_host ) ) ) {
                continue;
            }

            $item->target = '_blank';
            $rels = is_array( $item->xfn ) ? $item->xfn : preg_split( '/\s+/', (string) $item->xfn );
            $rels = is_array( $rels ) ? $rels : [];
            $rels[] = 'noopener';
            $rels[] = 'noreferrer';
            $item->xfn = implode( ' ', array_values( array_unique( array_filter( $rels ) ) ) );
        }

        return $items;
    }

    public function register_external_permalink_metaboxes(): void {
        if ( ! is_admin() || ! $this->is_external_permalink_enabled() ) {
            return;
        }

        foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $post_type => $object ) {
            if ( 'attachment' === $post_type || empty( $object->show_ui ) ) {
                continue;
            }

            add_meta_box(
                'trq-external-permalink',
                __( 'Permalien externe', '360tranquilite' ),
                [ $this, 'render_external_permalink_metabox' ],
                $post_type,
                'side',
                'default'
            );
        }
    }

    public function render_external_permalink_metabox( WP_Post $post ): void {
        $url = (string) get_post_meta( $post->ID, '_trq_external_url', true );
        wp_nonce_field( 'trq_external_permalink_save_' . $post->ID, 'trq_external_permalink_nonce' );

        echo '<p>' . esc_html__( 'Si renseigné, ce contenu utilisera une URL externe.', '360tranquilite' ) . '</p>';
        echo '<input type="url" class="widefat" name="trq_external_url" value="' . esc_attr( $url ) . '" placeholder="https://example.com/page" />';
    }

    public function save_external_permalink_meta( int $post_id, WP_Post $post ): void {
        if ( ! $this->is_external_permalink_enabled() || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }

        if ( 'attachment' === $post->post_type ) {
            return;
        }

        $nonce = sanitize_text_field( (string) ( $_POST['trq_external_permalink_nonce'] ?? '' ) );
        if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'trq_external_permalink_save_' . $post_id ) ) {
            return;
        }

        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $url = isset( $_POST['trq_external_url'] ) ? esc_url_raw( (string) wp_unslash( $_POST['trq_external_url'] ) ) : '';
        if ( '' === $url ) {
            delete_post_meta( $post_id, '_trq_external_url' );
            return;
        }

        update_post_meta( $post_id, '_trq_external_url', $url );
    }

    public function filter_post_type_permalink( string $permalink, WP_Post $post ): string {
        return $this->get_external_permalink_or_default( $permalink, $post );
    }

    public function filter_post_permalink( string $permalink, WP_Post $post, bool $leavename ): string {
        unset( $leavename );
        return $this->get_external_permalink_or_default( $permalink, $post );
    }

    public function filter_page_permalink( string $link, int $post_id ): string {
        $post = get_post( $post_id );
        if ( ! $post instanceof WP_Post ) {
            return $link;
        }

        return $this->get_external_permalink_or_default( $link, $post );
    }

    private function get_external_permalink_or_default( string $fallback, WP_Post $post ): string {
        if ( ! $this->is_external_permalink_enabled() ) {
            return $fallback;
        }

        $external = esc_url_raw( (string) get_post_meta( $post->ID, '_trq_external_url', true ) );
        return '' !== $external ? $external : $fallback;
    }

    public function maybe_redirect_external_permalink(): void {
        if ( ! $this->is_external_permalink_enabled() || ! is_singular() ) {
            return;
        }

        $post = get_queried_object();
        if ( ! $post instanceof WP_Post ) {
            return;
        }

        $external = esc_url_raw( (string) get_post_meta( $post->ID, '_trq_external_url', true ) );
        if ( '' === $external ) {
            return;
        }

        wp_redirect( $external, 301 );
        exit;
    }
}
