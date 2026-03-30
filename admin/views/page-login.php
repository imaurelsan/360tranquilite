<?php
/**
 * Vue : Protection connexion
 * URL personnalisée + anti-brute-force + énumération.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$core        = TRQ_Core::get_instance();
$login       = TRQ_Login_Protection::get_instance();
$login_slug  = (string) $core->get( 'login_slug', '' );
$default_url = home_url( '/wp-login.php' );
$custom_url  = '' !== $login_slug ? home_url( $login_slug ) : $default_url;
?>

<div class="trq-section trq-login-main">
    <h2>🔗 URL de connexion personnalisée</h2>
    <p>Votre URL de connexion actuelle : <strong><a href="<?php echo esc_url( $custom_url ); ?>" target="_blank"><?php echo esc_url( $custom_url ); ?></a></strong></p>
    <p class="description">Laissez vide pour conserver la connexion WordPress standard (<code>wp-login.php</code>). Renseignez un slug uniquement si vous voulez masquer l'URL de connexion.</p>

    <?php TRQ_Admin::settings_form_open( 'login' ); ?>
    <table class="form-table">
        <tr>
            <th><label for="login_slug">Slug personnalisé</label></th>
            <td>
                <input type="text" id="login_slug" name="login_slug"
                       value="<?php echo esc_attr( $login_slug ); ?>"
                       class="regular-text"
                       pattern="[a-z0-9\-]*" />
                <p class="description">Champ optionnel. Uniquement des lettres minuscules, chiffres et tirets. Exemple : <code>mon-espace-prive</code></p>
                <p class="description">URL résultante : <code id="trq-preview-url"><?php echo esc_html( '' !== $login_slug ? home_url( '/' ) . $login_slug : $default_url ); ?></code></p>
            </td>
        </tr>
    </table>

    <h2>🛡️ Protection anti-brute-force</h2>
    <table class="form-table">
        <tr>
            <th><label for="login_max_attempts">Tentatives max. avant blocage</label></th>
            <td>
                <input type="number" id="login_max_attempts" name="login_max_attempts"
                       value="<?php echo esc_attr( $core->get( 'login_max_attempts', 5 ) ); ?>"
                       min="1" max="20" class="small-text" />
                <p class="description">Nombre de tentatives échouées avant de bloquer l'IP.</p>
            </td>
        </tr>
        <tr>
            <th><label for="login_lockout_minutes">Durée du blocage (minutes)</label></th>
            <td>
                <input type="number" id="login_lockout_minutes" name="login_lockout_minutes"
                       value="<?php echo esc_attr( $core->get( 'login_lockout_minutes', 30 ) ); ?>"
                       min="5" max="1440" class="small-text" />
                <p class="description">Durée pendant laquelle l'IP est bloquée après dépassement.</p>
            </td>
        </tr>
        <tr>
            <th>Énumération des utilisateurs</th>
            <td><?php TRQ_Admin::toggle( 'disable_user_enum', (bool) $core->get( 'disable_user_enum' ), 'Bloquer l\'énumération des auteurs (/?author=1) et l\'API REST /wp/v2/users' ); ?></td>
        </tr>
    </table>

    <h2>🎨 Personnalisation visuelle de la page de connexion</h2>
    <p class="description">La personnalisation visuelle est optionnelle. Activez-la seulement si vous souhaitez remplacer le style natif WordPress.</p>
    <table class="form-table">
        <tr>
            <th>Activer la personnalisation visuelle</th>
            <td>
                <?php TRQ_Admin::toggle( 'login_visual_customization_enabled', (bool) $core->get( 'login_visual_customization_enabled', false ), 'Appliquer le logo, les couleurs, les champs et le CSS personnalisé sur la page de connexion' ); ?>
                <p class="description">Désactivé par défaut pour conserver l’interface WordPress standard après installation.</p>
            </td>
        </tr>
    </table>

    <div class="trq-login-visual-settings" <?php echo ! $core->get( 'login_visual_customization_enabled', false ) ? 'hidden' : ''; ?>>
    <table class="form-table">
        <tr>
            <th><label for="login_custom_logo_url">Logo personnalisé (URL)</label></th>
            <td>
                <input type="url" id="login_custom_logo_url" name="login_custom_logo_url"
                       value="<?php echo esc_attr( $core->get( 'login_custom_logo_url', '' ) ); ?>"
                       class="regular-text" placeholder="https://exemple.com/mon-logo.png" />
                <p class="description">Remplace le logo WordPress sur la page de connexion.</p>
            </td>
        </tr>
        <tr>
            <th><label for="login_logo_link_url">URL au clic du logo</label></th>
            <td>
                <input type="url" id="login_logo_link_url" name="login_logo_link_url"
                       value="<?php echo esc_attr( $core->get( 'login_logo_link_url', '' ) ); ?>"
                       class="regular-text" placeholder="https://votre-site.com" />
                <p class="description">Laissez vide pour utiliser la page d’accueil du site.</p>
            </td>
        </tr>
        <tr>
            <th><label for="login_logo_title">Titre/alt du logo</label></th>
            <td>
                <input type="text" id="login_logo_title" name="login_logo_title"
                       value="<?php echo esc_attr( $core->get( 'login_logo_title', '' ) ); ?>"
                       class="regular-text" placeholder="Nom du site" />
            </td>
        </tr>
        <tr>
            <th>Taille du logo</th>
            <td>
                <label for="login_logo_width">Largeur (px)</label>
                <input type="number" id="login_logo_width" name="login_logo_width"
                       value="<?php echo esc_attr( (int) $core->get( 'login_logo_width', 120 ) ); ?>"
                       min="60" max="420" class="small-text" />
                &nbsp;&nbsp;
                <label for="login_logo_height">Hauteur (px)</label>
                <input type="number" id="login_logo_height" name="login_logo_height"
                       value="<?php echo esc_attr( (int) $core->get( 'login_logo_height', 120 ) ); ?>"
                       min="40" max="240" class="small-text" />
            </td>
        </tr>
    </table>

    <h3>Couleurs principales</h3>
    <table class="form-table">
        <tr>
            <th><label for="login_bg_color">Background page</label></th>
            <td><input type="color" id="login_bg_color" name="login_bg_color" value="<?php echo esc_attr( $core->get( 'login_bg_color', '#f0f2f5' ) ); ?>" /></td>
        </tr>
        <tr>
            <th><label for="login_form_bg_color">Background formulaire</label></th>
            <td><input type="color" id="login_form_bg_color" name="login_form_bg_color" value="<?php echo esc_attr( $core->get( 'login_form_bg_color', '#ffffff' ) ); ?>" /></td>
        </tr>
        <tr>
            <th><label for="login_form_border_color">Bordure formulaire</label></th>
            <td><input type="color" id="login_form_border_color" name="login_form_border_color" value="<?php echo esc_attr( $core->get( 'login_form_border_color', '#dcdcde' ) ); ?>" /></td>
        </tr>
        <tr>
            <th><label for="login_form_text_color">Texte formulaire</label></th>
            <td><input type="color" id="login_form_text_color" name="login_form_text_color" value="<?php echo esc_attr( $core->get( 'login_form_text_color', '#1d2327' ) ); ?>" /></td>
        </tr>
        <tr>
            <th><label for="login_message_bg_color">Background messages/erreurs</label></th>
            <td><input type="color" id="login_message_bg_color" name="login_message_bg_color" value="<?php echo esc_attr( $core->get( 'login_message_bg_color', '#ffffff' ) ); ?>" /></td>
        </tr>
        <tr>
            <th><label for="login_message_text_color">Texte messages/erreurs</label></th>
            <td><input type="color" id="login_message_text_color" name="login_message_text_color" value="<?php echo esc_attr( $core->get( 'login_message_text_color', '#1d2327' ) ); ?>" /></td>
        </tr>
    </table>

    <h3>Champs et bouton</h3>
    <table class="form-table">
        <tr>
            <th><label for="login_input_bg_color">Background champs</label></th>
            <td><input type="color" id="login_input_bg_color" name="login_input_bg_color" value="<?php echo esc_attr( $core->get( 'login_input_bg_color', '#ffffff' ) ); ?>" /></td>
        </tr>
        <tr>
            <th><label for="login_input_text_color">Texte champs</label></th>
            <td><input type="color" id="login_input_text_color" name="login_input_text_color" value="<?php echo esc_attr( $core->get( 'login_input_text_color', '#1d2327' ) ); ?>" /></td>
        </tr>
        <tr>
            <th><label for="login_input_border_color">Bordure champs</label></th>
            <td><input type="color" id="login_input_border_color" name="login_input_border_color" value="<?php echo esc_attr( $core->get( 'login_input_border_color', '#8c8f94' ) ); ?>" /></td>
        </tr>
        <tr>
            <th><label for="login_button_bg_color">Bouton connexion</label></th>
            <td><input type="color" id="login_button_bg_color" name="login_button_bg_color" value="<?php echo esc_attr( $core->get( 'login_button_bg_color', '#2271b1' ) ); ?>" /></td>
        </tr>
        <tr>
            <th><label for="login_button_text_color">Texte bouton connexion</label></th>
            <td><input type="color" id="login_button_text_color" name="login_button_text_color" value="<?php echo esc_attr( $core->get( 'login_button_text_color', '#ffffff' ) ); ?>" /></td>
        </tr>
        <tr>
            <th><label for="login_button_hover_bg_color">Bouton connexion (hover)</label></th>
            <td><input type="color" id="login_button_hover_bg_color" name="login_button_hover_bg_color" value="<?php echo esc_attr( $core->get( 'login_button_hover_bg_color', '#135e96' ) ); ?>" /></td>
        </tr>
        <tr>
            <th><label for="login_link_color">Couleur des liens</label></th>
            <td><input type="color" id="login_link_color" name="login_link_color" value="<?php echo esc_attr( $core->get( 'login_link_color', '#2271b1' ) ); ?>" /></td>
        </tr>
        <tr>
            <th><label for="login_link_hover_color">Couleur des liens (hover)</label></th>
            <td><input type="color" id="login_link_hover_color" name="login_link_hover_color" value="<?php echo esc_attr( $core->get( 'login_link_hover_color', '#135e96' ) ); ?>" /></td>
        </tr>
    </table>

    <h3>Finitions</h3>
    <table class="form-table">
        <tr>
            <th><label for="login_form_border_radius">Arrondi du formulaire (px)</label></th>
            <td>
                <input type="number" id="login_form_border_radius" name="login_form_border_radius"
                       value="<?php echo esc_attr( (int) $core->get( 'login_form_border_radius', 8 ) ); ?>"
                       min="0" max="48" class="small-text" />
            </td>
        </tr>
        <tr>
            <th>Ombre du formulaire</th>
            <td><?php TRQ_Admin::toggle( 'login_form_shadow', (bool) $core->get( 'login_form_shadow', true ), 'Activer une ombre portée sur le formulaire de connexion' ); ?></td>
        </tr>
        <tr>
            <th><label for="login_custom_css">CSS personnalisé</label></th>
            <td>
                <textarea id="login_custom_css" name="login_custom_css" rows="8" class="large-text code"><?php echo esc_textarea( (string) $core->get( 'login_custom_css', '' ) ); ?></textarea>
                <p class="description">Ajoutez vos règles CSS supplémentaires (optionnel). Elles seront injectées après les styles générés.</p>
            </td>
        </tr>
    </table>
    </div>
    <?php TRQ_Admin::settings_form_close(); ?>
</div>

<!-- Stats des connexions -->
<div class="trq-section trq-login-stats">
    <h2>📊 Statistiques de connexion</h2>
    <?php
    $stats = $login->get_stats();
    ?>
    <div class="trq-stats-grid trq-stats-small">
        <div class="trq-stat-card"><div class="trq-stat-value"><?php echo esc_html( $stats['failed_today'] ); ?></div><div class="trq-stat-label">Échecs aujourd'hui</div></div>
        <div class="trq-stat-card"><div class="trq-stat-value"><?php echo esc_html( $stats['failed_week'] ); ?></div><div class="trq-stat-label">Échecs (7 jours)</div></div>
        <div class="trq-stat-card"><div class="trq-stat-value"><?php echo esc_html( $stats['success_today'] ); ?></div><div class="trq-stat-label">Connexions réussies</div></div>
    </div>
</div>

<!-- Dernières tentatives -->
<div class="trq-section trq-login-attempts">
    <h2>📋 Dernières tentatives de connexion</h2>
    <?php
    $attempts = $login->get_recent_attempts( 20 );
    if ( $attempts ) :
    ?>
    <table class="widefat striped trq-log-table">
        <thead><tr><th>Date (UTC)</th><th>IP</th><th>Identifiant tenté</th><th>Résultat</th></tr></thead>
        <tbody>
        <?php foreach ( $attempts as $a ) :
            $fw = TRQ_Firewall::get_instance();
        ?>
            <tr>
                <td><?php echo esc_html( $a->attempted_at ); ?></td>
                <td><code><?php echo esc_html( $a->ip_address ); ?></code></td>
                <td><?php echo esc_html( $a->username ); ?></td>
                <td>
                    <?php if ( $a->success ) : ?>
                        <span class="trq-badge trq-badge-ok">✅ Succès</span>
                    <?php else : ?>
                        <span class="trq-badge trq-badge-warn">❌ Échec</span>
                        <?php if ( ! $fw->is_ip_blocked( $a->ip_address ) ) : ?>
                            &nbsp;<?php TRQ_Admin::action_form( 'block_ip', [ 'ip' => $a->ip_address, 'reason' => 'Blocage depuis les tentatives de connexion' ], 'login', '🔒 Bloquer', 'button-small' ); ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
        <p class="trq-empty">Aucune tentative enregistrée.</p>
    <?php endif; ?>
</div>

<script>
document.getElementById('login_slug').addEventListener('input', function() {
    var slug = this.value.trim();
    var base = <?php echo wp_json_encode( home_url( '/' ) ); ?>;
    var fallback = <?php echo wp_json_encode( $default_url ); ?>;
    document.getElementById('trq-preview-url').textContent = slug ? (base + slug) : fallback;
});

(function() {
    var toggle = document.querySelector('input[name="login_visual_customization_enabled"]');
    var settings = document.querySelector('.trq-login-visual-settings');
    if (!toggle || !settings) {
        return;
    }

    var sync = function() {
        settings.hidden = !toggle.checked;
    };

    toggle.addEventListener('change', sync);
    sync();
})();
</script>
