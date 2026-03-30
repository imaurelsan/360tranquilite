<?php
/**
 * Module Firewall (WAF) :
 * - Bloque les injections SQL, XSS, path traversal
 * - Bloque les bots malveillants
 * - Gère la liste noire d'IPs
 * - Journalise toutes les menaces
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRQ_Firewall {

    private static ?TRQ_Firewall $instance = null;
    /** @var array<string, bool> */
    private array $pattern_validity_cache = [];

    // Patterns de détection (ordre = priorité)
    private const PATTERNS = [
        'sql_injection' => [
            '/(\%27)|(\')|(\-\-)|(\%23)|(#)/i',
            '/\b(union|select|insert|update|delete|drop|alter|create|exec|execute|declare|cast|convert|char|nchar|varchar|nvarchar|waitfor|delay|sleep|benchmark|load_file|into\s+outfile)\b/ix',
            '/\b(information_schema|sysobjects|syscolumns|sys\.tables)\b/ix',
        ],
        'xss' => [
            '/<script[\s\S]*?>[\s\S]*?<\/script>/ix',
            '/on(load|click|mouseover|focus|blur|change|submit|error|keyup|keydown|keypress|dblclick)\s*=/ix',
            '/javascript\s*:/ix',
            '/document\.(cookie|write|location)/ix',
            '/eval\s*\(/ix',
            '/base64_decode\s*\(/ix',
        ],
        'path_traversal' => [
            '/\.\.\//x',
            '/\.\.\\\\/x',
            '/%2e%2e%2f/ix',
            '/%252e%252e%252f/ix',
            '/etc\/passwd/ix',
            '/proc\/self\/environ/ix',
        ],
        'rce' => [
            '/\b(passthru|shell_exec|exec|system|popen|proc_open|pcntl_exec)\s*\(/ix',
            '/\b(phpinfo|php_uname|phpversion)\s*\(/ix',
        ],
        'lfi' => [
            '/php\:\/\/filter/ix',
            '/php\:\/\/input/ix',
            '/data\:\/\//ix',
        ],
    ];

    private const BAD_BOTS = [
        'masscan', 'sqlmap', 'nikto', 'nmap', 'nessus',
        'acunetix', 'dirbuster', 'havij', 'curl/7.', 'python-requests',
        'zgrab', 'go-http-client', 'libwww-perl', 'wget/',
    ];

    private function __construct() {}

    public static function get_instance(): TRQ_Firewall {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        // Exécution la plus tôt possible, avant wp_loaded
        add_action( 'init', [ $this, 'run' ], 1 );
    }

    // -----------------------------------------------------------------------
    // Analyse de la requête entrante
    // -----------------------------------------------------------------------

    public function run(): void {
        if ( $this->should_bypass_for_trusted_admin_session() ) {
            return;
        }

        if ( $this->should_bypass_for_wordpress_upgrade_flow() ) {
            return;
        }

        $ip = TRQ_Core::get_client_ip();

        // 1. Vérifier si l'IP est bloquée
        if ( $this->is_ip_blocked( $ip ) ) {
            $this->deny( $ip, 'ip_blocked', 'IP bloquée' );
        }

        $core = TRQ_Core::get_instance();

        // 2. Bloquer les mauvais bots
        if ( $core->get( 'firewall_block_bad_bots' ) ) {
            $ua = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ) );
            foreach ( self::BAD_BOTS as $bot ) {
                if ( false !== strpos( $ua, $bot ) ) {
                    $this->log_and_block( $ip, 'bad_bot', $bot );
                    $this->deny( $ip, 'bad_bot', 'User-Agent bloqué : ' . $bot );
                }
            }
        }

        // 3. Analyser les données de la requête
        $data_to_check = $this->collect_request_data();

        $checks = [
            'firewall_block_sqli'      => 'sql_injection',
            'firewall_block_xss'       => 'xss',
            'firewall_block_traversal' => 'path_traversal',
        ];

        foreach ( $checks as $setting => $threat_type ) {
            if ( ! $core->get( $setting ) ) {
                continue;
            }
            $patterns = self::PATTERNS[ $threat_type ] ?? [];
            foreach ( $patterns as $pattern ) {
                foreach ( $data_to_check as $value ) {
                    if ( $this->matches_pattern( $pattern, $value ) ) {
                        $this->log_and_block( $ip, $threat_type, substr( $value, 0, 200 ) );
                        $this->deny( $ip, $threat_type, 'Requête bloquée : ' . $threat_type );
                    }
                }
            }
        }

        // 4. Toujours vérifier RCE et LFI (non désactivables)
        foreach ( [ 'rce', 'lfi' ] as $threat ) {
            foreach ( ( self::PATTERNS[ $threat ] ?? [] ) as $pattern ) {
                foreach ( $data_to_check as $value ) {
                    if ( $this->matches_pattern( $pattern, $value ) ) {
                        $this->log_and_block( $ip, $threat, substr( $value, 0, 200 ) );
                        $this->deny( $ip, $threat, 'Requête bloquée : ' . $threat );
                    }
                }
            }
        }
    }

    // -----------------------------------------------------------------------
    // Collecte des données à analyser
    // -----------------------------------------------------------------------

    private function collect_request_data(): array {
        $values = [];

        // GET / POST / COOKIE — récursif
        $sources = [
            wp_unslash( $_GET    ?? [] ),
            wp_unslash( $_POST   ?? [] ),
            wp_unslash( $_COOKIE ?? [] ),
        ];
        foreach ( $sources as $source ) {
            $values = array_merge( $values, $this->flatten( $source ) );
        }

        // Request URI
        $values[] = rawurldecode( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ) );

        return $values;
    }

    private function flatten( $data ): array {
        $out = [];
        if ( is_array( $data ) ) {
            foreach ( $data as $v ) {
                $out = array_merge( $out, $this->flatten( $v ) );
            }
        } elseif ( is_string( $data ) ) {
            $out[] = $data;
        }
        return $out;
    }

    private function matches_pattern( string $pattern, string $value ): bool {
        if ( ! array_key_exists( $pattern, $this->pattern_validity_cache ) ) {
            $this->pattern_validity_cache[ $pattern ] = false !== @preg_match( $pattern, '' );
        }

        if ( ! $this->pattern_validity_cache[ $pattern ] ) {
            return false;
        }

        return 1 === preg_match( $pattern, $value );
    }

    private function should_bypass_for_wordpress_upgrade_flow(): bool {
        if ( ! is_admin() || ! is_user_logged_in() ) {
            return false;
        }

        if ( ! current_user_can( 'install_plugins' ) && ! current_user_can( 'update_plugins' ) && ! current_user_can( 'install_themes' ) && ! current_user_can( 'update_themes' ) ) {
            return false;
        }

        $script = sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_NAME'] ?? '' ) );
        $page = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) );
        $action = sanitize_text_field( wp_unslash( $_REQUEST['action'] ?? '' ) );
        $action2 = sanitize_text_field( wp_unslash( $_REQUEST['action2'] ?? '' ) );

        if ( 'plugin-install.php' === basename( $script ) || 'themes.php' === basename( $script ) || 'update-core.php' === basename( $script ) ) {
            return true;
        }

        if ( 'update.php' === basename( $script ) && in_array( $action, [ 'upload-plugin', 'upload-theme', 'install-plugin', 'install-theme', 'upgrade-plugin', 'upgrade-theme', 'update-selected', 'do-plugin-upgrade', 'do-theme-upgrade' ], true ) ) {
            return true;
        }

        if ( 'plugins.php' === basename( $script ) && ( in_array( $action, [ 'delete-selected', 'delete-plugin', 'activate', 'activate-selected', 'deactivate', 'deactivate-selected', 'update-selected' ], true ) || in_array( $action2, [ 'delete-selected', 'activate-selected', 'deactivate-selected', 'update-selected' ], true ) ) ) {
            return true;
        }

        if ( 'themes.php' === basename( $script ) && in_array( $action, [ 'delete', 'activate', 'update' ], true ) ) {
            return true;
        }

        return 'plugin-install' === $page;
    }

    private function should_bypass_for_trusted_admin_session(): bool {
        return is_admin() && is_user_logged_in() && current_user_can( 'manage_options' );
    }

    // -----------------------------------------------------------------------
    // Gestion des IPs bloquées
    // -----------------------------------------------------------------------

    public function is_ip_blocked( string $ip ): bool {
        if ( ! TRQ_Core::table_exists( 'trq_blocked_ips' ) ) {
            return false;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'trq_blocked_ips';

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT expires_at FROM `{$table}` WHERE ip_address = %s",
            $ip
        ) );

        if ( ! $row ) {
            return false;
        }
        // Expiration nulle = blocage permanent
        if ( null === $row->expires_at ) {
            return true;
        }
        if ( strtotime( $row->expires_at ) > time() ) {
            return true;
        }

        // Blocage expiré : suppression
        $wpdb->delete( $table, [ 'ip_address' => $ip ], [ '%s' ] );
        return false;
    }

    public function block_ip( string $ip, string $reason, ?int $duration_minutes = null ): void {
        if ( ! TRQ_Core::table_exists( 'trq_blocked_ips' ) ) {
            return;
        }

        global $wpdb;
        $table     = $wpdb->prefix . 'trq_blocked_ips';
        $expires   = $duration_minutes
            ? gmdate( 'Y-m-d H:i:s', time() + $duration_minutes * 60 )
            : null;

        $wpdb->replace(
            $table,
            [
                'ip_address' => $ip,
                'reason'     => substr( $reason, 0, 255 ),
                'blocked_at' => current_time( 'mysql', true ),
                'expires_at' => $expires,
            ],
            [ '%s', '%s', '%s', $expires ? '%s' : null ]
        );

        if (
            TRQ_Core::get_instance()->get( 'cloudflare_enabled' ) &&
            TRQ_Core::get_instance()->get( 'cloudflare_sync_blocks' )
        ) {
            TRQ_Cloudflare::get_instance()->api_block_ip( $ip, $reason );
        }
    }

    public function unblock_ip( string $ip ): void {
        if ( ! TRQ_Core::table_exists( 'trq_blocked_ips' ) ) {
            return;
        }

        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'trq_blocked_ips', [ 'ip_address' => $ip ], [ '%s' ] );
    }

    // -----------------------------------------------------------------------
    // Journalisation
    // -----------------------------------------------------------------------

    private function log_and_block( string $ip, string $threat, string $detail ): void {
        if ( ! TRQ_Core::table_exists( 'trq_firewall_log' ) ) {
            return;
        }

        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'trq_firewall_log',
            [
                'ip_address'  => $ip,
                'request_uri' => substr( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ), 0, 2000 ),
                'threat_type' => $threat,
                'blocked_at'  => current_time( 'mysql', true ),
            ],
            [ '%s', '%s', '%s', '%s' ]
        );

        // Auto-blocage après dépassement du seuil
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->prefix}trq_firewall_log`
             WHERE ip_address = %s AND blocked_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)",
            $ip
        ) );

        if ( $count >= 10 ) {
            $this->block_ip( $ip, 'Firewall auto-blocage (' . $threat . ')', 60 * 24 );
        }
    }

    private function deny( string $ip, string $type, string $reason ): void {
        status_header( 403 );
        nocache_headers();
        wp_die(
            esc_html__( 'Accès refusé. Votre requête a été bloquée par le pare-feu.', '360tranquilite' ),
            esc_html__( 'Accès interdit', '360tranquilite' ),
            [ 'response' => 403 ]
        );
    }

    // -----------------------------------------------------------------------
    // Récupération des logs (pour l'admin)
    // -----------------------------------------------------------------------

    public function get_logs( int $limit = 50 ): array {
        if ( ! TRQ_Core::table_exists( 'trq_firewall_log' ) ) {
            return [];
        }

        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM `{$wpdb->prefix}trq_firewall_log` ORDER BY blocked_at DESC LIMIT %d",
            $limit
        ) );
    }

    public function get_blocked_ips(): array {
        if ( ! TRQ_Core::table_exists( 'trq_blocked_ips' ) ) {
            return [];
        }

        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM `{$wpdb->prefix}trq_blocked_ips` ORDER BY blocked_at DESC"
        );
    }

    public function get_stats(): array {
        if ( ! TRQ_Core::table_exists( 'trq_firewall_log' ) || ! TRQ_Core::table_exists( 'trq_blocked_ips' ) ) {
            return [
                'total_today' => 0,
                'total_week'  => 0,
                'total_all'   => 0,
                'blocked_ips' => 0,
            ];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'trq_firewall_log';
        return [
            'total_today'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE DATE(blocked_at) = CURDATE()" ),
            'total_week'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE blocked_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)" ),
            'total_all'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ),
            'blocked_ips'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}trq_blocked_ips`" ),
        ];
    }
}
