<?php
/**
 * Module Double Authentification (2FA) - TOTP (RFC 6238)
 * Compatible Google Authenticator, Authy, Bitwarden, etc.
 *
 * Flux :
 * 1. L'utilisateur saisit identifiant + mot de passe.
 * 2. Si ses identifiants sont valides et que la 2FA est activée pour son compte,
 *    il est redirigé vers un formulaire pour saisir son code à 6 chiffres.
 * 3. Si le code est correct, la session est ouverte normalement.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class TRQ_Two_Factor {

    private static ?TRQ_Two_Factor $instance = null;

    // Durée de validité du token intermédiaire (secondes)
    private const TOKEN_TTL = 300;

    private const TOKEN_META_KEY = 'trq_2fa_';

    private function __construct() {}

    public static function get_instance(): TRQ_Two_Factor {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(): void {
        // Intercepte après validation des identifiants
        add_filter( 'authenticate', [ $this, 'intercept_login'  ], 100, 3 );
        // Affiche le formulaire de saisie du code
        add_action( 'login_init',   [ $this, 'handle_2fa_page'  ] );
        // Page profil pour activer/désactiver la 2FA
        add_action( 'show_user_profile',    [ $this, 'profile_fields' ] );
        add_action( 'edit_user_profile',    [ $this, 'profile_fields' ] );
        add_action( 'personal_options_update',  [ $this, 'save_profile' ] );
        add_action( 'edit_user_profile_update', [ $this, 'save_profile' ] );
    }

    // =========================================================================
    // INTERCEPTION DU LOGIN
    // =========================================================================

    /**
     * Si les identifiants sont valides et que la 2FA est activée pour cet utilisateur,
     * on crée un token temporaire et on redirige vers le formulaire 2FA.
     */
    public function intercept_login( $user, string $username, string $password ) {
        if ( isset( $_POST['trq_token'], $_POST['trq_2fa_code'] ) ) {
            return $user;
        }

        if ( ! $user instanceof WP_User ) {
            return $user; // Erreur d'auth classique, on laisse passer
        }

        if ( ! $this->user_has_2fa( $user->ID ) ) {
            return $user; // Pas de 2FA pour cet utilisateur
        }

        // Première étape : identifiants OK, on crée un token et on redirige
        $token = wp_generate_password( 40, false );
        set_transient( self::TOKEN_META_KEY . $token, $user->ID, self::TOKEN_TTL );

        $redirect = isset( $_POST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_POST['redirect_to'] ) ) : admin_url();

        wp_safe_redirect( add_query_arg(
            [
                'action'      => 'trq_2fa',
                'trq_token'   => rawurlencode( $token ),
                'redirect_to' => rawurlencode( $redirect ),
            ],
            wp_login_url()
        ) );
        exit;
    }

    // =========================================================================
    // PAGE DU FORMULAIRE 2FA
    // =========================================================================

    public function handle_2fa_page(): void {
        $action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
        if ( $action !== 'trq_2fa' ) {
            return;
        }

        $token = isset( $_REQUEST['trq_token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['trq_token'] ) ) : '';
        $user_id = $this->get_pending_user_id( $token );

        if ( ! $token || ! $user_id ) {
            wp_safe_redirect( wp_login_url() );
            exit;
        }

        $redirect_to = isset( $_REQUEST['redirect_to'] )
            ? esc_url_raw( urldecode( wp_unslash( $_REQUEST['redirect_to'] ) ) )
            : admin_url();

        $error = '';
        if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
            $nonce = isset( $_POST['trq_2fa_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['trq_2fa_nonce'] ) ) : '';
            $code  = preg_replace( '/\D/', '', wp_unslash( $_POST['trq_2fa_code'] ?? '' ) );

            if ( ! wp_verify_nonce( $nonce, 'trq_2fa_verify_' . $token ) ) {
                $error = esc_html__( 'Session 2FA invalide. Veuillez vous reconnecter.', '360tranquilite' );
            } elseif ( ! $this->verify_totp( $user_id, $code ) ) {
                $error = esc_html__( 'Code incorrect ou expiré. Réessayez.', '360tranquilite' );
            } else {
                $user = get_user_by( 'id', $user_id );
                if ( $user instanceof WP_User ) {
                    delete_transient( self::TOKEN_META_KEY . $token );
                    wp_set_current_user( $user->ID );
                    wp_set_auth_cookie( $user->ID, ! empty( $_POST['rememberme'] ) );
                    do_action( 'wp_login', $user->user_login, $user );
                    wp_safe_redirect( $redirect_to ?: admin_url() );
                    exit;
                }

                $error = esc_html__( 'Compte utilisateur introuvable. Veuillez vous reconnecter.', '360tranquilite' );
            }
        }

        // Affichage de la page de saisie du code
        login_header( __( 'Vérification en deux étapes', '360tranquilite' ) );
        ?>
        <div id="trq-2fa-wrap">
            <p class="message" style="text-align:center;">
                <?php esc_html_e( 'Saisissez le code à 6 chiffres affiché dans votre application d\'authentification.', '360tranquilite' ); ?>
            </p>
            <?php if ( $error ) : ?>
                <div id="login_error"><?php echo esc_html( $error ); ?></div>
            <?php endif; ?>
            <form name="trq_2fa_form" method="post" action="<?php echo esc_url( add_query_arg( [ 'action' => 'trq_2fa', 'trq_token' => rawurlencode( $token ) ], wp_login_url() ) ); ?>">
                <?php wp_nonce_field( 'trq_2fa_verify_' . $token, 'trq_2fa_nonce' ); ?>
                <input type="hidden" name="trq_token" value="<?php echo esc_attr( $token ); ?>" />
                <input type="hidden" name="redirect_to"  value="<?php echo esc_attr( $redirect_to ); ?>" />
                <input type="hidden" name="rememberme" value="1" />
                <p>
                    <label for="trq_2fa_code"><?php esc_html_e( 'Code d\'authentification', '360tranquilite' ); ?></label>
                    <input
                        type="text"
                        name="trq_2fa_code"
                        id="trq_2fa_code"
                        class="input"
                        inputmode="numeric"
                        pattern="[0-9]{6}"
                        maxlength="6"
                        autocomplete="one-time-code"
                        autofocus
                        required
                        style="letter-spacing:0.3em;font-size:1.4em;text-align:center;"
                    />
                </p>
                <p class="submit">
                    <input type="submit" class="button-primary button-large" value="<?php esc_attr_e( 'Vérifier', '360tranquilite' ); ?>" />
                </p>
                <p style="text-align:center;margin-top:1em;">
                    <a href="<?php echo esc_url( wp_login_url() ); ?>"><?php esc_html_e( '← Retour à la connexion', '360tranquilite' ); ?></a>
                </p>
            </form>
        </div>
        <?php
        login_footer();
        exit;
    }

    // =========================================================================
    // CHAMPS PROFIL (activer / désactiver / configurer la 2FA)
    // =========================================================================

    public function profile_fields( WP_User $user ): void {
        $enabled = $this->user_has_2fa( $user->ID );
        $secret  = get_user_meta( $user->ID, 'trq_2fa_secret', true );

        // Pour la configuration initiale, on génère un secret temporaire
        if ( ! $secret ) {
            $secret = $this->generate_secret();
        }

        $qr_uri = $this->build_otpauth_uri( $user, $secret );
        $qr_js  = TRQ_PLUGIN_URL . 'assets/js/qrcode.min.js';
        wp_nonce_field( 'trq_2fa_profile_' . $user->ID, 'trq_2fa_nonce' );
        ?>
        <h2><?php esc_html_e( '🔐 Double Authentification (2FA)', '360tranquilite' ); ?></h2>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Statut 2FA', '360tranquilite' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="trq_2fa_enabled" value="1" <?php checked( $enabled ); ?> />
                        <?php esc_html_e( 'Activer la double authentification pour ce compte', '360tranquilite' ); ?>
                    </label>
                </td>
            </tr>
            <?php if ( ! $enabled ) : ?>
            <tr>
                <th><?php esc_html_e( 'Configuration', '360tranquilite' ); ?></th>
                <td>
                    <p><?php esc_html_e( 'Scannez le QR code ci-dessous avec Google Authenticator, Authy ou toute application TOTP compatible, puis cochez la case ci-dessus et sauvegardez.', '360tranquilite' ); ?></p>
                    <div id="trq-qrcode" style="margin:12px 0;"></div>
                    <p>
                        <strong><?php esc_html_e( 'Ou saisissez cette clé manuellement :', '360tranquilite' ); ?></strong><br/>
                        <code style="font-size:1.1em;letter-spacing:0.15em;"><?php echo esc_html( chunk_split( $secret, 4, ' ' ) ); ?></code>
                    </p>
                    <input type="hidden" name="trq_2fa_secret_new" value="<?php echo esc_attr( $secret ); ?>" />
                    <script src="<?php echo esc_url( $qr_js ); ?>"></script>
                    <script>
                    (function(){
                        var uri = <?php echo wp_json_encode( $qr_uri ); ?>;
                        var el  = document.getElementById('trq-qrcode');
                        if (!el) return;
                        // Utiliser qrcodejs si disponible, sinon lien cliquable
                        if (typeof QRCode !== 'undefined') {
                            new QRCode(el, { text: uri, width: 180, height: 180 });
                        } else {
                            el.innerHTML = '<a href="' + uri + '" target="_blank" style="text-decoration:none;">📱 Ouvrir dans l\'application</a>';
                        }
                    })();
                    </script>
                </td>
            </tr>
            <?php else : ?>
            <tr>
                <th><?php esc_html_e( 'Réinitialiser', '360tranquilite' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="trq_2fa_reset" value="1" />
                        <?php esc_html_e( 'Générer un nouveau secret (nécessite de reconfigurer l\'application)', '360tranquilite' ); ?>
                    </label>
                </td>
            </tr>
            <?php endif; ?>
        </table>
        <?php
    }

    public function save_profile( int $user_id ): void {
        if (
            ! isset( $_POST['trq_2fa_nonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['trq_2fa_nonce'] ) ), 'trq_2fa_profile_' . $user_id )
        ) {
            return;
        }
        if ( ! current_user_can( 'edit_user', $user_id ) ) {
            return;
        }

        $enabled = ! empty( $_POST['trq_2fa_enabled'] );

        if ( isset( $_POST['trq_2fa_reset'] ) && $_POST['trq_2fa_reset'] === '1' ) {
            // Regénérer le secret
            update_user_meta( $user_id, 'trq_2fa_secret', $this->generate_secret() );
            update_user_meta( $user_id, 'trq_2fa_enabled', 0 );
            return;
        }

        if ( $enabled ) {
            // Enregistrer le nouveau secret si fourni (lors de l'activation)
            if ( ! empty( $_POST['trq_2fa_secret_new'] ) ) {
                $new_secret = preg_replace( '/[^A-Z2-7]/i', '', strtoupper( sanitize_text_field( wp_unslash( $_POST['trq_2fa_secret_new'] ) ) ) );
                update_user_meta( $user_id, 'trq_2fa_secret', $new_secret );
            }
            update_user_meta( $user_id, 'trq_2fa_enabled', 1 );
        } else {
            update_user_meta( $user_id, 'trq_2fa_enabled', 0 );
        }
    }

    // =========================================================================
    // TOTP — ALGORITHME (RFC 6238 / RFC 4226)
    // =========================================================================

    public function generate_secret( int $length = 20 ): string {
        return $this->base32_encode( random_bytes( $length ) );
    }

    public function verify_totp( int $user_id, string $code ): bool {
        $secret = get_user_meta( $user_id, 'trq_2fa_secret', true );
        if ( ! $secret || strlen( $code ) !== 6 ) {
            return false;
        }

        // Accepte ±1 intervalle (30s) pour compenser les décalages d'horloge
        $time_step = (int) floor( time() / 30 );
        for ( $offset = -1; $offset <= 1; $offset++ ) {
            if ( hash_equals( $this->compute_totp( $secret, $time_step + $offset ), $code ) ) {
                return true;
            }
        }
        return false;
    }

    private function compute_totp( string $secret, int $counter ): string {
        $key  = $this->base32_decode( $secret );
        $time = pack( 'N*', 0 ) . pack( 'N*', $counter );
        $hash = hash_hmac( 'sha1', $time, $key, true );

        $offset = ord( $hash[19] ) & 0x0f;
        $code   = (
            ( ( ord( $hash[ $offset     ] ) & 0x7f ) << 24 ) |
            ( ( ord( $hash[ $offset + 1 ] ) & 0xff ) << 16 ) |
            ( ( ord( $hash[ $offset + 2 ] ) & 0xff ) << 8  ) |
            ( ( ord( $hash[ $offset + 3 ] ) & 0xff )       )
        ) % 1_000_000;

        return str_pad( (string) $code, 6, '0', STR_PAD_LEFT );
    }

    // =========================================================================
    // BASE32 (RFC 4648)
    // =========================================================================

    private function base32_encode( string $data ): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $encoded  = '';
        $buffer   = 0;
        $bits_left = 0;

        for ( $i = 0; $i < strlen( $data ); $i++ ) {
            $buffer     = ( $buffer << 8 ) | ord( $data[ $i ] );
            $bits_left += 8;
            while ( $bits_left >= 5 ) {
                $bits_left -= 5;
                $encoded   .= $alphabet[ ( $buffer >> $bits_left ) & 31 ];
            }
        }
        if ( $bits_left > 0 ) {
            $encoded .= $alphabet[ ( $buffer << ( 5 - $bits_left ) ) & 31 ];
        }
        return $encoded;
    }

    private function base32_decode( string $encoded ): string {
        $alphabet  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $encoded   = strtoupper( preg_replace( '/[^A-Z2-7]/', '', $encoded ) );
        $output    = '';
        $buffer    = 0;
        $bits_left = 0;

        for ( $i = 0; $i < strlen( $encoded ); $i++ ) {
            $val = strpos( $alphabet, $encoded[ $i ] );
            if ( $val === false ) {
                continue;
            }
            $buffer     = ( $buffer << 5 ) | $val;
            $bits_left += 5;
            if ( $bits_left >= 8 ) {
                $bits_left -= 8;
                $output    .= chr( ( $buffer >> $bits_left ) & 0xff );
            }
        }
        return $output;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    public function user_has_2fa( int $user_id ): bool {
        return (bool) get_user_meta( $user_id, 'trq_2fa_enabled', true );
    }

    private function get_pending_user_id( string $token ): int {
        return (int) get_transient( self::TOKEN_META_KEY . $token );
    }

    private function build_otpauth_uri( WP_User $user, string $secret ): string {
        $issuer = rawurlencode( get_bloginfo( 'name' ) ?: 'WordPress' );
        $account = rawurlencode( $user->user_email );
        return "otpauth://totp/{$issuer}:{$account}?secret={$secret}&issuer={$issuer}&algorithm=SHA1&digits=6&period=30";
    }
}
