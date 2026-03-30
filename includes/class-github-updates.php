<?php
/**
 * Mises a jour du plugin depuis GitHub Releases.
 *
 * Active uniquement si un repo GitHub est configure.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRQ_Github_Updates {

    private static ?TRQ_Github_Updates $instance = null;

    private bool $booted = false;

    private const CACHE_TRANSIENT_KEY = 'trq_github_release_cache';
    private const CACHE_TTL_SECONDS = 1800;

    private function __construct() {}

    public static function get_instance(): TRQ_Github_Updates {
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

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_plugin_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugins_api_details' ], 20, 3 );
    }

    /**
     * @param mixed $transient
     * @return mixed
     */
    public function inject_plugin_update( $transient ) {
        $config = $this->get_config();
        if ( empty( $config['enabled'] ) ) {
            return $transient;
        }

        if ( ! is_object( $transient ) ) {
            $transient = new stdClass();
        }

        if ( empty( $transient->checked ) || ! is_array( $transient->checked ) ) {
            return $transient;
        }

        $plugin_file = plugin_basename( TRQ_PLUGIN_FILE );
        $current_version = (string) TRQ_VERSION;

        $remote = $this->get_latest_release();
        if ( empty( $remote['ok'] ) || empty( $remote['version'] ) || empty( $remote['package_url'] ) ) {
            return $transient;
        }

        $remote_version = (string) $remote['version'];

        if ( version_compare( $remote_version, $current_version, '<=' ) ) {
            if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
                $transient->no_update = [];
            }

            $transient->no_update[ $plugin_file ] = (object) [
                'id'          => $plugin_file,
                'slug'        => dirname( $plugin_file ),
                'plugin'      => $plugin_file,
                'new_version' => $current_version,
                'url'         => (string) $remote['html_url'],
                'package'     => '',
            ];

            return $transient;
        }

        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = [];
        }

        $transient->response[ $plugin_file ] = (object) [
            'id'            => $plugin_file,
            'slug'          => dirname( $plugin_file ),
            'plugin'        => $plugin_file,
            'new_version'   => $remote_version,
            'url'           => (string) $remote['html_url'],
            'package'       => (string) $remote['package_url'],
            'tested'        => (string) $config['tested_up_to'],
            'requires_php'  => '7.4',
        ];

        return $transient;
    }

    /**
     * @param mixed $result
     * @param mixed $action
     * @param mixed $args
     * @return mixed
     */
    public function plugins_api_details( $result, $action, $args ) {
        $config = $this->get_config();
        if ( empty( $config['enabled'] ) ) {
            return $result;
        }

        if ( 'plugin_information' !== (string) $action || ! is_object( $args ) ) {
            return $result;
        }

        $plugin_slug = dirname( plugin_basename( TRQ_PLUGIN_FILE ) );
        if ( $plugin_slug !== (string) ( $args->slug ?? '' ) ) {
            return $result;
        }

        $release = $this->get_latest_release();
        if ( empty( $release['ok'] ) || empty( $release['version'] ) ) {
            return $result;
        }

        $changelog = (string) ( $release['body'] ?? '' );
        if ( '' === trim( $changelog ) ) {
            $changelog = 'No changelog provided in GitHub release notes.';
        }

        return (object) [
            'name'          => '360 Tranquillité',
            'slug'          => $plugin_slug,
            'version'       => (string) $release['version'],
            'author'        => '<a href="https://yaurel.com">Aurel Yahouedeou</a>',
            'author_profile'=> 'https://yaurel.com',
            'homepage'      => (string) ( $release['html_url'] ?? '' ),
            'requires'      => '5.8',
            'requires_php'  => '7.4',
            'tested'        => (string) $config['tested_up_to'],
            'last_updated'  => (string) ( $release['published_at'] ?? '' ),
            'sections'      => [
                'description' => 'Mises à jour distribuées depuis GitHub Releases.',
                'changelog'   => nl2br( esc_html( $changelog ) ),
            ],
            'download_link' => (string) ( $release['package_url'] ?? '' ),
        ];
    }

    /**
     * @return array{enabled: bool, repo: string, branch: string, asset_name: string, tested_up_to: string}
     */
    private function get_config(): array {
        $repo = defined( 'TRQ_GITHUB_UPDATES_REPO' ) ? (string) TRQ_GITHUB_UPDATES_REPO : '';

        $config = [
            'enabled' => '' !== $repo,
            'repo' => $repo,
            'branch' => defined( 'TRQ_GITHUB_UPDATES_BRANCH' ) ? (string) TRQ_GITHUB_UPDATES_BRANCH : 'main',
            'asset_name' => defined( 'TRQ_GITHUB_UPDATES_ASSET' ) ? (string) TRQ_GITHUB_UPDATES_ASSET : '360tranquilite.zip',
            'tested_up_to' => defined( 'TRQ_GITHUB_UPDATES_TESTED' ) ? (string) TRQ_GITHUB_UPDATES_TESTED : '',
        ];

        /**
         * Permet de surcharger la configuration via code.
         *
         * @param array<string, mixed> $config
         */
        $config = (array) apply_filters( 'trq_github_updates_config', $config );

        $config['enabled'] = ! empty( $config['enabled'] ) && ! empty( $config['repo'] );
        $config['repo'] = (string) ( $config['repo'] ?? '' );
        $config['branch'] = (string) ( $config['branch'] ?? 'main' );
        $config['asset_name'] = (string) ( $config['asset_name'] ?? '360tranquilite.zip' );
        $config['tested_up_to'] = (string) ( $config['tested_up_to'] ?? '' );

        return $config;
    }

    /**
     * @return array{ok: bool, version?: string, package_url?: string, html_url?: string, body?: string, published_at?: string}
     */
    private function get_latest_release(): array {
        $config = $this->get_config();
        if ( empty( $config['enabled'] ) ) {
            return [ 'ok' => false ];
        }

        $cached = get_transient( self::CACHE_TRANSIENT_KEY );
        if ( is_array( $cached ) && ( $cached['repo'] ?? '' ) === $config['repo'] ) {
            return $cached['data'] ?? [ 'ok' => false ];
        }

        $api_url = sprintf( 'https://api.github.com/repos/%s/releases/latest', rawurlencode( $config['repo'] ) );
        $api_url = str_replace( '%2F', '/', $api_url );

        $response = wp_remote_get(
            $api_url,
            [
                'timeout' => 20,
                'headers' => [
                    'Accept' => 'application/vnd.github+json',
                    'User-Agent' => '360tranquilite/' . TRQ_VERSION,
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [ 'ok' => false ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return [ 'ok' => false ];
        }

        $payload = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $payload ) ) {
            return [ 'ok' => false ];
        }

        $tag = (string) ( $payload['tag_name'] ?? '' );
        $version = ltrim( $tag, "vV \t\n\r\0\x0B" );
        if ( '' === $version ) {
            return [ 'ok' => false ];
        }

        $package_url = '';
        $assets = is_array( $payload['assets'] ?? null ) ? $payload['assets'] : [];
        foreach ( $assets as $asset ) {
            if ( ! is_array( $asset ) ) {
                continue;
            }

            if ( (string) ( $asset['name'] ?? '' ) === $config['asset_name'] ) {
                $package_url = (string) ( $asset['browser_download_url'] ?? '' );
                break;
            }
        }

        // Fallback: accepte n'importe quel .zip de release.
        if ( '' === $package_url ) {
            foreach ( $assets as $asset ) {
                if ( ! is_array( $asset ) ) {
                    continue;
                }

                $name = (string) ( $asset['name'] ?? '' );
                if ( '.zip' === substr( strtolower( $name ), -4 ) ) {
                    $package_url = (string) ( $asset['browser_download_url'] ?? '' );
                    break;
                }
            }
        }

        if ( '' === $package_url ) {
            return [ 'ok' => false ];
        }

        $data = [
            'ok' => true,
            'version' => $version,
            'package_url' => $package_url,
            'html_url' => (string) ( $payload['html_url'] ?? '' ),
            'body' => (string) ( $payload['body'] ?? '' ),
            'published_at' => (string) ( $payload['published_at'] ?? '' ),
        ];

        set_transient(
            self::CACHE_TRANSIENT_KEY,
            [
                'repo' => $config['repo'],
                'data' => $data,
            ],
            self::CACHE_TTL_SECONDS
        );

        return $data;
    }
}
