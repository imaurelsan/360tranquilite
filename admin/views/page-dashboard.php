<?php
/**
 * Vue : Tableau de bord
 * Résumé de l'état de sécurité + statistiques rapides.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$fw_stats    = TRQ_Firewall::get_instance()->get_stats();
$login_stats = TRQ_Login_Protection::get_instance()->get_stats();
$file_stats  = TRQ_File_Monitor::get_instance()->get_stats();
$system_summary = TRQ_System_Scanner::get_instance()->get_summary();
$audit_stats = TRQ_Audit_Log::get_instance()->get_stats();
$backup_summary = TRQ_Backup_Manager::get_instance()->get_summary();
$last_report = TRQ_File_Monitor::get_instance()->get_last_report();
$core        = TRQ_Core::get_instance();
$alerts      = [];

if ( '' === (string) $core->get( 'login_slug', '' ) ) {
    $alerts[] = 'Le slug de connexion personnalisé est vide. Vous utilisez actuellement l’URL de connexion WordPress standard (wp-login.php).';
}

if ( $core->get( 'two_factor_enabled' ) && 0 === count( get_users( [ 'meta_key' => 'trq_2fa_enabled', 'meta_value' => '1', 'fields' => 'ids' ] ) ) ) {
    $alerts[] = 'La 2FA est activée globalement, mais aucun utilisateur ne l’a encore configurée.';
}

if ( $core->get( 'file_monitor_enabled' ) && 0 === (int) $file_stats['total_files'] ) {
    $alerts[] = 'Le moniteur de fichiers est actif, mais aucune baseline n’a encore été construite.';
}

if ( $core->get( 'cloudflare_enabled' ) && ! ( $core->get( 'cloudflare_api_token' ) || $core->get( 'cloudflare_api_key' ) ) ) {
    $alerts[] = 'Cloudflare est activé, mais aucune authentification API n’est encore configurée.';
}

if ( (int) $file_stats['last_findings'] > 0 ) {
    $alerts[] = 'Le dernier scan a remonté des fichiers suspects. Vérifiez immédiatement l’onglet Avancé.';
}

if ( ! $core->get( 'audit_log_enabled' ) ) {
    $alerts[] = 'Le journal d’audit est désactivé. Vous perdez la traçabilité des actions sensibles.';
}

if ( ! $core->get( 'disable_application_passwords' ) ) {
    $alerts[] = 'Les Application Passwords WordPress sont actives. Désactivez-les si vous ne vous en servez pas.';
}

if ( $core->get( 'disable_file_mods' ) ) {
    $alerts[] = 'L’option DISALLOW_FILE_MODS est active. Les installations et mises à jour de plugins/thèmes sont bloquées dans wp-admin.';
}

if ( $core->get( 'backup_enabled' ) && empty( $backup_summary['next_run'] ) ) {
    $alerts[] = 'Les sauvegardes sont activées, mais aucune prochaine exécution n’est actuellement planifiée.';
}

if ( $core->get( 'backup_enabled' ) && ! empty( $backup_summary['last_generated'] ) && empty( $backup_summary['last_success'] ) ) {
    $alerts[] = 'La dernière sauvegarde a échoué. Vérifiez l’onglet Sauvegardes.';
}

if ( (int) $system_summary['core_findings'] > 0 || (int) $system_summary['db_findings'] > 0 || (int) $system_summary['admin_findings'] > 0 || (int) $system_summary['cron_findings'] > 0 ) {
    $alerts[] = 'Le dernier scan système a remonté des signaux sur la base, les comptes admin ou les tâches cron. Vérifiez l’onglet Avancé.';
}

if ( (int) $system_summary['core_findings'] > 0 ) {
    $alerts[] = 'Le contrôle des checksums officiels a détecté des écarts sur le core WordPress.';
}

// Calcul d'un score de sécurité simple (sur 100)
$score = 0;
$checks = [
    'firewall_enabled'         => 20,
    'two_factor_enabled'       => 20,
    'security_headers_enabled' => 15,
    'disable_xmlrpc'           => 10,
    'file_monitor_enabled'     => 10,
    'antispam_enabled'         => 10,
    'hide_wp_version'          => 5,
    'disable_file_edit'        => 5,
    'disable_user_enum'        => 5,
];
foreach ( $checks as $key => $points ) {
    if ( $core->get( $key ) ) {
        $score += $points;
    }
}
// Bonus slug personnalisé
if ( '' !== (string) $core->get( 'login_slug', '' ) ) {
    $score = min( 100, $score + 5 );
}

$score_class = $score >= 80 ? 'good' : ( $score >= 50 ? 'medium' : 'low' );
$score_emoji = $score >= 80 ? '🟢' : ( $score >= 50 ? '🟡' : '🔴' );
?>

<div class="trq-dashboard">

    <!-- Score de sécurité -->
    <div class="trq-score-card trq-score-<?php echo esc_attr( $score_class ); ?> trq-dashboard-score">
        <div class="trq-score-number"><?php echo esc_html( $score_emoji . ' ' . $score ); ?><span>/100</span></div>
        <div class="trq-score-label">Score de sécurité</div>
        <div class="trq-score-bar"><div class="trq-score-fill" style="width:<?php echo esc_attr( $score ); ?>%"></div></div>
    </div>

    <div class="trq-section trq-dashboard-recommended">
        <h2>Démarrage rapide</h2>
        <p>Vous pouvez appliquer en un clic une configuration recommandée (sécurisée et non destructive), puis ajuster chaque module selon vos besoins.</p>
        <div class="trq-dashboard-quick-actions">
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="Appliquer la configuration recommandée ? Vous pourrez ensuite tout ajuster manuellement.">
                <?php wp_nonce_field( 'trq_action' ); ?>
                <input type="hidden" name="action" value="trq_action" />
                <input type="hidden" name="trq_do" value="apply_recommended_profile" />
                <input type="hidden" name="trq_tab" value="dashboard" />
                <button type="submit" class="button button-primary">Activer la configuration recommandée</button>
            </form>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-confirm="Désactiver tous les modules de sécurité et d'automatisation ? Cette action est utile pour du debug temporaire.">
                <?php wp_nonce_field( 'trq_action' ); ?>
                <input type="hidden" name="action" value="trq_action" />
                <input type="hidden" name="trq_do" value="disable_all_profile" />
                <input type="hidden" name="trq_tab" value="dashboard" />
                <button type="submit" class="button button-secondary">Tout désactiver (debug)</button>
            </form>
        </div>
    </div>

    <?php if ( ! empty( $alerts ) ) : ?>
    <div class="trq-section trq-help-box trq-dashboard-alerts">
        <h2>Points d'attention</h2>
        <ul class="trq-feature-list">
            <?php foreach ( $alerts as $alert ) : ?>
                <li>⚠️ <?php echo esc_html( $alert ); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Cartes de statistiques -->
    <div class="trq-stats-grid trq-dashboard-stats">
        <div class="trq-stat-card">
            <div class="trq-stat-icon">🔥</div>
            <div class="trq-stat-value"><?php echo esc_html( $fw_stats['total_today'] ); ?></div>
            <div class="trq-stat-label">Attaques bloquées aujourd'hui</div>
        </div>
        <div class="trq-stat-card">
            <div class="trq-stat-icon">📅</div>
            <div class="trq-stat-value"><?php echo esc_html( $fw_stats['total_week'] ); ?></div>
            <div class="trq-stat-label">Attaques — 7 derniers jours</div>
        </div>
        <div class="trq-stat-card">
            <div class="trq-stat-icon">🚫</div>
            <div class="trq-stat-value"><?php echo esc_html( $fw_stats['blocked_ips'] ); ?></div>
            <div class="trq-stat-label">IPs bloquées</div>
        </div>
        <div class="trq-stat-card">
            <div class="trq-stat-icon">🔑</div>
            <div class="trq-stat-value"><?php echo esc_html( $login_stats['failed_today'] ); ?></div>
            <div class="trq-stat-label">Tentatives de connexion échouées</div>
        </div>
        <div class="trq-stat-card">
            <div class="trq-stat-icon">🗂️</div>
            <div class="trq-stat-value"><?php echo esc_html( $file_stats['total_files'] ); ?></div>
            <div class="trq-stat-label">Fichiers surveillés</div>
        </div>
        <div class="trq-stat-card">
            <div class="trq-stat-icon">🧪</div>
            <div class="trq-stat-value"><?php echo esc_html( $file_stats['last_findings'] ); ?></div>
            <div class="trq-stat-label">Signaux suspects au dernier scan</div>
        </div>
        <div class="trq-stat-card">
            <div class="trq-stat-icon">📝</div>
            <div class="trq-stat-value"><?php echo esc_html( $audit_stats['total_today'] ); ?></div>
            <div class="trq-stat-label">Événements d’audit aujourd’hui</div>
        </div>
        <div class="trq-stat-card">
            <div class="trq-stat-icon">🧬</div>
            <div class="trq-stat-value"><?php echo esc_html( $system_summary['core_findings'] + $system_summary['db_findings'] + $system_summary['admin_findings'] + $system_summary['cron_findings'] ); ?></div>
            <div class="trq-stat-label">Signaux scan système</div>
        </div>
        <div class="trq-stat-card">
            <div class="trq-stat-icon">💾</div>
            <div class="trq-stat-value"><?php echo esc_html( $backup_summary['local_count'] ); ?></div>
            <div class="trq-stat-label">Archives locales</div>
        </div>
    </div>

    <?php if ( ! empty( $last_report['findings'] ) ) : ?>
    <div class="trq-section trq-help-box trq-dashboard-suspects">
        <h2>Fichiers suspects à examiner</h2>
        <ul class="trq-feature-list">
            <?php foreach ( array_slice( $last_report['findings'], 0, 5 ) as $finding ) : ?>
                <li>⚠️ <?php echo esc_html( $finding['type'] . ' : ' . $finding['path'] ); ?></li>
            <?php endforeach; ?>
        </ul>
        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=trq-security&tab=advanced' ) ); ?>">Ouvrir le rapport complet →</a></p>
    </div>
    <?php endif; ?>

    <!-- Checklist des modules -->
    <div class="trq-modules-status trq-section trq-dashboard-modules">
        <h2>État des modules</h2>
        <table class="trq-status-table">
            <thead><tr><th>Module</th><th>Statut</th><th>Action</th></tr></thead>
            <tbody>
            <?php
            $modules = [
                [ 'firewall_enabled',         '🔥 Pare-feu (WAF)',              'firewall'   ],
                [ 'login_slug',                '🔒 URL de connexion personnalisée', 'login'   ],
                [ 'two_factor_enabled',        '📱 Double authentification (2FA)', 'twofactor' ],
                [ 'security_headers_enabled',  '🛡️ En-têtes de sécurité HTTP',   'advanced'  ],
                [ 'file_monitor_enabled',      '📁 Surveillance des fichiers',    'advanced'  ],
                [ 'core_checksum_enabled',     '🧬 Vérification du core officiel', 'advanced'  ],
                [ 'backup_enabled',            '💾 Sauvegardes planifiées',         'backups'   ],
                [ 'antispam_enabled',          '💬 Anti-spam commentaires',       'advanced'  ],
                [ 'disable_xmlrpc',            '🚫 XML-RPC désactivé',            'advanced'  ],
                [ 'hide_wp_version',           '🕵️ Version WordPress masquée',   'advanced'  ],
                [ 'disable_file_edit',         '✏️ Édition fichiers désactivée', 'advanced'  ],
                [ 'disable_application_passwords', '🔑 Application Passwords désactivées', 'advanced'  ],
            ];
            foreach ( $modules as [ $key, $label, $tab ] ) :
                $active = $core->get( $key );
                // Pour login_slug, actif si différent du slug par défaut "login"
                if ( $key === 'login_slug' ) {
                    $active = ! empty( $core->get( 'login_slug' ) );
                }
                ?>
                <tr>
                    <td><?php echo esc_html( $label ); ?></td>
                    <td>
                        <span class="trq-badge <?php echo $active ? 'trq-badge-ok' : 'trq-badge-warn'; ?>">
                            <?php echo $active ? '✅ Actif' : '⚠️ Inactif'; ?>
                        </span>
                    </td>
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=trq-security&tab=' . $tab ) ); ?>" class="button button-small">Configurer</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Dernières menaces -->
    <div class="trq-recent-threats trq-section trq-dashboard-threats">
        <h2>Dernières menaces bloquées</h2>
        <?php
        $logs = TRQ_Firewall::get_instance()->get_logs( 10 );
        if ( $logs ) :
        ?>
        <table class="trq-log-table widefat striped">
            <thead><tr><th>Date (UTC)</th><th>IP</th><th>Type</th><th>URI</th></tr></thead>
            <tbody>
            <?php foreach ( $logs as $log ) : ?>
                <tr>
                    <td><?php echo esc_html( $log->blocked_at ); ?></td>
                    <td><code><?php echo esc_html( $log->ip_address ); ?></code></td>
                    <td><span class="trq-threat-type"><?php echo esc_html( $log->threat_type ); ?></span></td>
                    <td class="trq-uri"><code><?php echo esc_html( substr( $log->request_uri, 0, 80 ) ); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else : ?>
            <p class="trq-empty">✅ Aucune menace détectée récemment.</p>
        <?php endif; ?>
        <p><a href="<?php echo esc_url( admin_url( 'admin.php?page=trq-security&tab=firewall' ) ); ?>">Voir tous les logs →</a></p>
    </div>

</div>
