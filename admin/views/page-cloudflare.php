<?php
/**
 * Vue : Cloudflare
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$core   = TRQ_Core::get_instance();
$cf_key = $core->get( 'cloudflare_api_key' );
$cf_token = $core->get( 'cloudflare_api_token' );
// Résultat du dernier test de connexion
$cf_test = get_transient( 'trq_cf_test_result' );
if ( $cf_test ) {
    delete_transient( 'trq_cf_test_result' );
}
?>

<div class="trq-section trq-cloudflare-settings">
    <h2>☁️ Intégration Cloudflare</h2>
    <p>Cloudflare agit comme un proxy/pare-feu devant votre serveur. Ce module permet :</p>
    <ul class="trq-feature-list">
        <li>✅ Détection de la <strong>vraie IP</strong> du visiteur (via l'en-tête <code>CF-Connecting-IP</code>), essentielle pour le blocage correct des attaquants.</li>
        <li>✅ Validation que l'en-tête <code>CF-Connecting-IP</code> provient bien des serveurs Cloudflare (protection anti-spoofing).</li>
        <li>✅ Blocage d'IPs directement dans Cloudflare via l'API (WAF Cloudflare).</li>
        <li>✅ Purge du cache Cloudflare depuis WordPress.</li>
    </ul>

    <?php if ( $cf_test ) : ?>
    <div class="trq-notice <?php echo $cf_test['success'] ? 'trq-notice-success' : 'trq-notice-error'; ?>">
        <?php echo $cf_test['success'] ? '✅' : '❌'; ?>
        <?php echo esc_html( $cf_test['message'] ); ?>
    </div>
    <?php endif; ?>

    <?php TRQ_Admin::settings_form_open( 'cloudflare' ); ?>
    <table class="form-table">
        <tr>
            <th>Activer Cloudflare</th>
            <td>
                <?php TRQ_Admin::toggle( 'cloudflare_enabled', (bool) $core->get( 'cloudflare_enabled' ), 'Activer l\'intégration Cloudflare (détection IP + API)' ); ?>
            </td>
        </tr>
        <tr>
            <th>Mode d'authentification API</th>
            <td>
                <label><input type="radio" name="cloudflare_auth_mode" value="token" <?php checked( $core->get( 'cloudflare_auth_mode', 'token' ), 'token' ); ?> /> API Token</label><br>
                <label><input type="radio" name="cloudflare_auth_mode" value="global_key" <?php checked( $core->get( 'cloudflare_auth_mode', 'token' ), 'global_key' ); ?> /> Global API Key</label>
                <p class="description">Recommandé : utilisez un <strong>API Token</strong> avec permissions minimales sur la zone.</p>
            </td>
        </tr>
        <tr>
            <th><label for="cf_api_token">API Token Cloudflare</label></th>
            <td>
                <input type="password" id="cf_api_token" name="cloudflare_api_token"
                       value=""
                       placeholder="<?php echo $cf_token ? 'Token enregistré — laissez vide pour ne pas modifier' : 'Votre API Token Cloudflare'; ?>"
                       class="regular-text"
                       autocomplete="new-password" />
                <p class="description">Permissions minimales recommandées : <strong>Zone.Zone Read</strong>, <strong>Zone.Cache Purge</strong> et <strong>Zone.WAF Write</strong> si vous synchronisez les blocages.</p>
            </td>
        </tr>
        <tr>
            <th><label for="cf_email">Email du compte Cloudflare</label></th>
            <td>
                <input type="email" id="cf_email" name="cloudflare_email"
                       value="<?php echo esc_attr( $core->get( 'cloudflare_email' ) ); ?>"
                       class="regular-text" />
            </td>
        </tr>
        <tr>
            <th><label for="cf_api_key">Clé API Cloudflare (Global API Key)</label></th>
            <td>
                <input type="password" id="cf_api_key" name="cloudflare_api_key"
                       value=""
                       placeholder="<?php echo $cf_key ? 'Clé enregistrée — laissez vide pour ne pas modifier' : 'Votre Global API Key'; ?>"
                       class="regular-text"
                       autocomplete="new-password" />
                <p class="description">
                    Disponible dans votre profil Cloudflare : <strong>Mon profil → Clés API → Global API Key</strong>.<br/>
                    À utiliser uniquement si vous choisissez le mode <strong>Global API Key</strong>.
                </p>
            </td>
        </tr>
        <tr>
            <th><label for="cf_zone_id">Zone ID Cloudflare</label></th>
            <td>
                <input type="text" id="cf_zone_id" name="cloudflare_zone_id"
                       value="<?php echo esc_attr( $core->get( 'cloudflare_zone_id' ) ); ?>"
                       class="regular-text"
                       placeholder="Votre Zone ID (vue d'ensemble du domaine)" />
                <p class="description">Visible dans le <strong>tableau de bord Cloudflare → Zone → Aperçu → Zone ID</strong>.</p>
            </td>
        </tr>
        <tr>
            <th>Synchronisation des blocages</th>
            <td>
                <?php TRQ_Admin::toggle( 'cloudflare_sync_blocks', (bool) $core->get( 'cloudflare_sync_blocks' ), 'Synchroniser automatiquement les IPs bloquées vers Cloudflare' ); ?>
                <p class="description">Quand une IP est bloquée dans 360 Tranquillité, le plugin tente aussi de la bloquer au niveau Cloudflare.</p>
            </td>
        </tr>
    </table>
    <?php TRQ_Admin::settings_form_close(); ?>
</div>

<!-- Actions Cloudflare -->
<?php if ( $core->get( 'cloudflare_enabled' ) && ( $core->get( 'cloudflare_api_token' ) || $core->get( 'cloudflare_api_key' ) ) ) : ?>
<div class="trq-section trq-cloudflare-actions">
    <h2>⚡ Actions rapides</h2>
    <div class="trq-action-buttons">
        <?php TRQ_Admin::action_form( 'cf_test',  [], 'cloudflare', '🔌 Tester la connexion', 'button-secondary' ); ?>
        &nbsp;
        <?php TRQ_Admin::action_form( 'cf_purge', [], 'cloudflare', '🗑️ Purger le cache Cloudflare', 'button-secondary' ); ?>
    </div>
</div>
<?php endif; ?>

<div class="trq-section trq-help-box trq-cloudflare-help">
    <h2>📖 Configuration recommandée Cloudflare</h2>
    <ul class="trq-feature-list">
        <li>Mode SSL : <strong>Full (strict)</strong></li>
        <li>Niveau de sécurité : <strong>Medium</strong> ou <strong>High</strong></li>
        <li>Activer <strong>Bot Fight Mode</strong></li>
        <li>Activer <strong>Browser Integrity Check</strong></li>
        <li>Règles WAF : bloquer les pays sans trafic légitime</li>
        <li>Rate Limiting sur <code>/wp-login.php</code> et <code>/xmlrpc.php</code></li>
    </ul>
</div>
