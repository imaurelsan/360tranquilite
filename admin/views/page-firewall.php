<?php
/**
 * Vue : Pare-feu (WAF)
 * Réglages + logs + gestion des IPs bloquées.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$core = TRQ_Core::get_instance();
$fw   = TRQ_Firewall::get_instance();
?>

<div class="trq-section trq-firewall-config">
    <h2>⚙️ Configuration du pare-feu</h2>
    <?php TRQ_Admin::settings_form_open( 'firewall' ); ?>
    <table class="form-table">
        <tr>
            <th>Pare-feu actif</th>
            <td><?php TRQ_Admin::toggle( 'firewall_enabled', (bool) $core->get( 'firewall_enabled' ), 'Activer le pare-feu applicatif (WAF)' ); ?></td>
        </tr>
        <tr>
            <th>Protections</th>
            <td>
                <?php TRQ_Admin::toggle( 'firewall_block_sqli',      (bool) $core->get( 'firewall_block_sqli' ),      'Bloquer les injections SQL' ); ?><br><br>
                <?php TRQ_Admin::toggle( 'firewall_block_xss',       (bool) $core->get( 'firewall_block_xss' ),       'Bloquer les attaques XSS' ); ?><br><br>
                <?php TRQ_Admin::toggle( 'firewall_block_traversal', (bool) $core->get( 'firewall_block_traversal' ), 'Bloquer le path traversal (../)' ); ?><br><br>
                <?php TRQ_Admin::toggle( 'firewall_block_bad_bots',  (bool) $core->get( 'firewall_block_bad_bots' ),  'Bloquer les bots malveillants connus (sqlmap, nikto, nmap…)' ); ?>
                <p class="description">Les protections RCE (Remote Code Execution) et LFI (Local File Inclusion) sont toujours actives.</p>
            </td>
        </tr>
    </table>
    <?php TRQ_Admin::settings_form_close(); ?>
</div>

<!-- IPs bloquées -->
<div class="trq-section trq-firewall-ips">
    <h2>🚫 IPs bloquées</h2>

    <!-- Bloquer une IP manuellement -->
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="trq-inline-form">
        <?php wp_nonce_field( 'trq_action' ); ?>
        <input type="hidden" name="action"  value="trq_action" />
        <input type="hidden" name="trq_do"  value="block_ip" />
        <input type="hidden" name="trq_tab" value="firewall" />
        <input type="text"   name="ip"      placeholder="Adresse IP (ex: 1.2.3.4)" class="regular-text" pattern="^(\d{1,3}\.){3}\d{1,3}$" required />
        <input type="text"   name="reason"  placeholder="Raison (optionnel)" class="regular-text" />
        <button type="submit" class="button button-secondary">🔒 Bloquer cette IP</button>
    </form>

    <?php
    $blocked = $fw->get_blocked_ips();
    if ( $blocked ) :
    ?>
    <table class="widefat striped trq-log-table">
        <thead><tr><th>IP</th><th>Raison</th><th>Bloquée le</th><th>Expire le</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ( $blocked as $row ) : ?>
            <tr>
                <td><code><?php echo esc_html( $row->ip_address ); ?></code></td>
                <td><?php echo esc_html( $row->reason ); ?></td>
                <td><?php echo esc_html( date_i18n( 'd/m/Y H:i', strtotime( $row->blocked_at ) ) ); ?></td>
                <td><?php echo $row->expires_at ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $row->expires_at ) ) ) : '<em>Permanente</em>'; ?></td>
                <td>
                    <?php TRQ_Admin::action_form(
                        'unblock_ip',
                        [ 'ip' => $row->ip_address ],
                        'firewall',
                        '🔓 Débloquer',
                        'button-link-delete'
                    ); ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
        <p class="trq-empty">✅ Aucune IP bloquée pour le moment.</p>
    <?php endif; ?>
</div>

<!-- Logs du pare-feu -->
<div class="trq-section trq-firewall-logs">
    <h2>📋 Journal du pare-feu (50 derniers événements)</h2>
    <?php
    $logs = $fw->get_logs( 50 );
    if ( $logs ) :
    ?>
    <table class="widefat striped trq-log-table">
        <thead><tr><th>Date (UTC)</th><th>IP</th><th>Type de menace</th><th>URI</th><th>Bloquer IP</th></tr></thead>
        <tbody>
        <?php foreach ( $logs as $log ) : ?>
            <tr>
                <td><?php echo esc_html( $log->blocked_at ); ?></td>
                <td><code><?php echo esc_html( $log->ip_address ); ?></code></td>
                <td><span class="trq-threat-type"><?php echo esc_html( $log->threat_type ); ?></span></td>
                <td><code class="trq-uri"><?php echo esc_html( substr( $log->request_uri, 0, 100 ) ); ?></code></td>
                <td>
                    <?php if ( ! $fw->is_ip_blocked( $log->ip_address ) ) :
                        TRQ_Admin::action_form( 'block_ip', [ 'ip' => $log->ip_address, 'reason' => 'Blocage depuis les logs' ], 'firewall', '🔒', 'button-small' );
                    else: ?>
                        <em>Déjà bloquée</em>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else : ?>
        <p class="trq-empty">✅ Aucune attaque dans les logs.</p>
    <?php endif; ?>
</div>
