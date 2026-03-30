<?php
/**
 * Vue : Double Authentification (2FA)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$core = TRQ_Core::get_instance();
$tf   = TRQ_Two_Factor::get_instance();

// Vérifier quels utilisateurs ont la 2FA activée
$users_with_2fa = get_users( [
    'meta_key'   => 'trq_2fa_enabled',
    'meta_value' => '1',
    'fields'     => [ 'ID', 'user_login', 'user_email' ],
] );
?>

<div class="trq-section trq-twofactor-settings">
    <h2>📱 Double Authentification (2FA — TOTP)</h2>
    <p>La 2FA ajoute une couche de sécurité critique : même si votre mot de passe est compromis, un attaquant ne pourra pas se connecter sans le code temporaire généré par votre application.</p>
    <p>✅ Compatible avec : <strong>Google Authenticator, Authy, Bitwarden, 1Password, Microsoft Authenticator</strong>, et tout client TOTP (RFC 6238).</p>

    <?php TRQ_Admin::settings_form_open( 'twofactor' ); ?>
    <table class="form-table">
        <tr>
            <th>Activation globale</th>
            <td>
                <?php TRQ_Admin::toggle( 'two_factor_enabled', (bool) $core->get( 'two_factor_enabled' ), 'Activer la 2FA (les utilisateurs devront la configurer dans leur profil)' ); ?>
                <p class="description">Lorsqu'elle est activée, chaque utilisateur peut l'activer via sa page <strong>Profil → Double Authentification</strong>.</p>
            </td>
        </tr>
    </table>
    <?php TRQ_Admin::settings_form_close(); ?>
</div>

<?php if ( ! $core->get( 'two_factor_enabled' ) ) : ?>
<div class="trq-notice trq-notice-warn">
    ⚠️ La 2FA est actuellement désactivée. Activez-la ci-dessus pour la rendre disponible.
</div>
<?php endif; ?>

<div class="trq-section trq-twofactor-users">
    <h2>👥 Utilisateurs avec la 2FA activée</h2>
    <?php if ( $users_with_2fa ) : ?>
    <table class="widefat striped">
        <thead><tr><th>Login</th><th>Email</th><th>Profil</th></tr></thead>
        <tbody>
        <?php foreach ( $users_with_2fa as $u ) : ?>
            <tr>
                <td><?php echo esc_html( $u->user_login ); ?></td>
                <td><?php echo esc_html( $u->user_email ); ?></td>
                <td><a href="<?php echo esc_url( get_edit_user_link( $u->ID ) ); ?>">Voir le profil</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
        <p class="trq-empty">Aucun utilisateur n'a encore activé la 2FA.</p>
        <?php if ( $core->get( 'two_factor_enabled' ) ) : ?>
        <p>👉 Pour configurer la 2FA, allez dans <strong>Utilisateurs → Votre profil</strong> et descendez jusqu'à la section <strong>Double Authentification</strong>.</p>
        <?php endif; ?>
    <?php endif; ?>
</div>

<div class="trq-section trq-help-box trq-twofactor-help">
    <h2>📖 Comment configurer la 2FA</h2>
    <ol>
        <li>Activez la 2FA globalement ci-dessus.</li>
        <li>Allez dans <strong>Utilisateurs → Votre profil</strong>.</li>
        <li>Dans la section <strong>Double Authentification</strong>, cochez la case d'activation.</li>
        <li>Scannez le QR code avec votre application (ou saisissez la clé manuellement).</li>
        <li>Sauvegardez le profil.</li>
        <li>À la prochaine connexion, un code à 6 chiffres vous sera demandé.</li>
    </ol>
    <p><strong>⚠️ Important :</strong> Notez votre clé secrète en lieu sûr. En cas de perte de votre téléphone, vous en aurez besoin pour désactiver la 2FA.</p>
</div>
