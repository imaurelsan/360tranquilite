<?php
/**
 * Module Anti-spam :
 * - Champ honeypot invisible dans le formulaire de commentaire
 * - Blocage des commentaires avec trop de liens
 * - Blocage des user-agents suspects
 * - Rate-limiting des soumissions de commentaires par IP
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRQ_Antispam {

    private static ?TRQ_Antispam $instance = null;

    private function __construct() {}

    public static function get_instance(): TRQ_Antispam {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        $core = TRQ_Core::get_instance();

        // Ajouter le champ honeypot au formulaire de commentaire
        add_action( 'comment_form_after_fields', [ $this, 'add_honeypot_field' ] );
        add_action( 'comment_form_logged_in_after', [ $this, 'add_honeypot_field' ] );

        // Vérifier le commentaire avant insertion
        add_filter( 'preprocess_comment', [ $this, 'check_comment' ] );

        // Désactiver l'API REST pour les commentaires anonymes
        add_filter( 'rest_pre_insert_comment', [ $this, 'check_rest_comment' ], 10, 2 );

        // Protection générique des formulaires frontend (hors commentaires)
        if ( $core->get( 'antispam_form_protection_enabled', true ) ) {
            add_action( 'template_redirect', [ $this, 'start_form_protection_buffer' ], 1 );
            add_action( 'init', [ $this, 'check_generic_form_submission' ], 1 );
        }
    }

    // =========================================================================
    // CHAMP HONEYPOT
    // =========================================================================

    public function add_honeypot_field(): void {
        // Champ caché par CSS : les bots le remplissent, les humains non
        $nonce = wp_create_nonce( 'trq_comment_' . ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' ) );
        ?>
        <div style="position:absolute;left:-9999px;top:-9999px;overflow:hidden;" aria-hidden="true">
            <label for="trq_hp_field">Ne pas remplir ce champ</label>
            <input
                type="text"
                id="trq_hp_field"
                name="trq_hp"
                value=""
                tabindex="-1"
                autocomplete="off"
            />
        </div>
        <input type="hidden" name="trq_comment_token" value="<?php echo esc_attr( $nonce ); ?>" />
        <?php
    }

    // =========================================================================
    // VÉRIFICATION AVANT INSERTION
    // =========================================================================

    public function check_comment( array $commentdata ): array {
        // 1. Vérifier le honeypot
        if ( ! empty( $_POST['trq_hp'] ) ) {
            wp_die(
                esc_html__( 'Votre commentaire a été refusé.', '360tranquilite' ),
                esc_html__( 'Commentaire rejeté', '360tranquilite' ),
                [ 'response' => 403, 'back_link' => true ]
            );
        }

        // 2. Vérifier le token de formulaire
        $ip    = TRQ_Core::get_client_ip();
        $token = sanitize_text_field( wp_unslash( $_POST['trq_comment_token'] ?? '' ) );
        if ( empty( $token ) || ! wp_verify_nonce( $token, 'trq_comment_' . $ip ) ) {
            wp_die(
                esc_html__( 'Requête invalide. Veuillez recharger la page et réessayer.', '360tranquilite' ),
                esc_html__( 'Commentaire rejeté', '360tranquilite' ),
                [ 'response' => 403, 'back_link' => true ]
            );
        }

        // 3. Trop de liens dans le commentaire
        $content    = $commentdata['comment_content'] ?? '';
        $link_count = preg_match_all( '/https?:\/\//i', $content );
        if ( $link_count > 3 ) {
            wp_die(
                esc_html__( 'Votre commentaire contient trop de liens et a été rejeté.', '360tranquilite' ),
                '',
                [ 'response' => 403, 'back_link' => true ]
            );
        }

        // 4. Rate-limiting : max 3 commentaires par IP en 10 minutes
        $rate_key   = 'trq_comment_rate_' . md5( $ip );
        $rate_count = (int) get_transient( $rate_key );
        if ( $rate_count >= 3 ) {
            wp_die(
                esc_html__( 'Vous postez trop de commentaires trop rapidement. Veuillez patienter quelques minutes.', '360tranquilite' ),
                '',
                [ 'response' => 429, 'back_link' => true ]
            );
        }
        set_transient( $rate_key, $rate_count + 1, 10 * MINUTE_IN_SECONDS );

        // 5. User-agent inexistant = suspect
        $ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
        if ( empty( $ua ) ) {
            wp_die(
                esc_html__( 'Votre commentaire a été rejeté.', '360tranquilite' ),
                '',
                [ 'response' => 403, 'back_link' => true ]
            );
        }

        return $commentdata;
    }

    public function check_rest_comment( $prepared_comment, $request ) {
        // Bloquer les commentaires REST sans authentification
        if ( ! is_user_logged_in() ) {
            return new WP_Error(
                'trq_comment_denied',
                __( 'Les commentaires via l\'API REST sont désactivés pour les visiteurs non connectés.', '360tranquilite' ),
                [ 'status' => 403 ]
            );
        }
        return $prepared_comment;
    }

    public function start_form_protection_buffer(): void {
        if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return;
        }

        if ( isset( $_SERVER['REQUEST_METHOD'] ) && strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) !== 'GET' ) {
            return;
        }

        ob_start( [ $this, 'inject_form_protection_fields' ] );
    }

    public function inject_form_protection_fields( string $html ): string {
        if ( '' === $html || false === stripos( $html, '<form' ) ) {
            return $html;
        }

        $ip = TRQ_Core::get_client_ip();
        $token = wp_create_nonce( 'trq_form_' . $ip );
        $ts = (string) time();

        return preg_replace_callback(
            '#<form\b[^>]*>.*?</form>#is',
            static function ( array $matches ) use ( $token, $ts ): string {
                $form = $matches[0];

                if ( false !== stripos( $form, 'name="trq_form_token"' ) || false !== stripos( $form, "name='trq_form_token'" ) ) {
                    return $form;
                }

                if ( preg_match( '#<form\b[^>]*method\s*=\s*(["\'])?get\1#i', $form ) ) {
                    return $form;
                }

                $fields = '<div style="position:absolute;left:-9999px;top:-9999px;overflow:hidden;" aria-hidden="true">';
                $fields .= '<label for="trq_hp_global">Ne pas remplir ce champ</label>';
                $fields .= '<input type="text" id="trq_hp_global" name="trq_hp_global" value="" tabindex="-1" autocomplete="off" />';
                $fields .= '</div>';
                $fields .= '<input type="hidden" name="trq_form_token" value="' . esc_attr( $token ) . '" />';
                $fields .= '<input type="hidden" name="trq_form_ts" value="' . esc_attr( $ts ) . '" />';

                return preg_replace( '#</form>#i', $fields . '</form>', $form, 1 ) ?? $form;
            },
            $html
        ) ?? $html;
    }

    public function check_generic_form_submission(): void {
        if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) !== 'POST' ) {
            return;
        }

        if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
            return;
        }

        // Les commentaires ont déjà leur propre pipeline de vérification.
        if ( isset( $_POST['comment_post_ID'] ) ) {
            return;
        }

        $has_form_shield = isset( $_POST['trq_form_token'] ) || isset( $_POST['trq_form_ts'] ) || isset( $_POST['trq_hp_global'] );
        if ( ! $has_form_shield ) {
            return;
        }

        $honeypot = sanitize_text_field( wp_unslash( $_POST['trq_hp_global'] ?? '' ) );
        if ( '' !== $honeypot ) {
            $this->deny_generic_form_submission();
        }

        $token = sanitize_text_field( wp_unslash( $_POST['trq_form_token'] ?? '' ) );
        $ts = (int) wp_unslash( $_POST['trq_form_ts'] ?? 0 );
        $ip = TRQ_Core::get_client_ip();

        if ( '' === $token || ! wp_verify_nonce( $token, 'trq_form_' . $ip ) ) {
            $this->deny_generic_form_submission();
        }

        $now = time();
        if ( $ts <= 0 || ( $now - $ts ) < 2 || ( $now - $ts ) > DAY_IN_SECONDS * 2 ) {
            $this->deny_generic_form_submission();
        }
    }

    private function deny_generic_form_submission(): void {
        wp_die(
            esc_html__( 'Votre envoi de formulaire a été refusé.', '360tranquilite' ),
            esc_html__( 'Formulaire rejeté', '360tranquilite' ),
            [ 'response' => 403, 'back_link' => true ]
        );
    }
}
