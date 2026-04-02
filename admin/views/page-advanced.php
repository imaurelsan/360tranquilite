<?php
/**
 * Vue : Avancé
 * En-têtes sécurité, surveillance fichiers, anti-spam, et divers.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$core       = TRQ_Core::get_instance();
$fm         = TRQ_File_Monitor::get_instance();
$file_stats = $fm->get_stats();
$last_report = $fm->get_last_report();
$system_scanner = TRQ_System_Scanner::get_instance();
$system_summary = $system_scanner->get_summary();
$system_report  = $system_scanner->get_last_report();
$definitions_status = TRQ_Threat_Definitions::get_instance()->get_status();
$uploads_status = $system_scanner->get_uploads_hardening_status();
$audit_stats = TRQ_Audit_Log::get_instance()->get_stats();
$audit_logs  = TRQ_Audit_Log::get_instance()->get_logs( 20 );
?>

<div class="trq-section trq-advanced-settings">
    <h2>🛡️ En-têtes de sécurité HTTP</h2>
    <p>Ces en-têtes sont envoyés avec chaque réponse HTTP pour réduire les risques d'attaques côté navigateur.</p>

    <?php TRQ_Admin::settings_form_open( 'advanced' ); ?>
    <table class="form-table">
        <tr>
            <th>En-têtes de sécurité</th>
            <td>
                <?php TRQ_Admin::toggle( 'security_headers_enabled', (bool) $core->get( 'security_headers_enabled' ), 'Activer les en-têtes de sécurité HTTP' ); ?>
                <p class="description">Active : <code>X-Frame-Options</code>, <code>X-Content-Type-Options</code>, <code>X-XSS-Protection</code>, <code>Referrer-Policy</code>, <code>HSTS</code>, <code>CSP de base</code>, <code>Permissions-Policy</code>.</p>
            </td>
        </tr>
    </table>

    <h2>📁 Surveillance de l'intégrité des fichiers</h2>
    <table class="form-table">
        <tr>
            <th>File Monitor</th>
            <td>
                <?php TRQ_Admin::toggle( 'file_monitor_enabled', (bool) $core->get( 'file_monitor_enabled' ), 'Activer la surveillance de l’intégrité des fichiers (scan quotidien)' ); ?>
                <p class="description">
                    Fichiers surveillés : <strong><?php echo esc_html( $file_stats['total_files'] ); ?></strong> &nbsp;|&nbsp;
                    Dernier scan : <strong><?php echo $file_stats['last_checked'] ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $file_stats['last_checked'] ) ) ) : 'Jamais'; ?></strong> &nbsp;|&nbsp;
                    Changements détectés : <strong><?php echo esc_html( $file_stats['last_changes'] ); ?></strong> &nbsp;|&nbsp;
                    Signaux suspects : <strong><?php echo esc_html( $file_stats['last_findings'] ); ?></strong>
                </p>
            </td>
        </tr>
        <tr>
            <th>Périmètre surveillé</th>
            <td>
                <?php TRQ_Admin::toggle( 'file_monitor_scan_plugins', (bool) $core->get( 'file_monitor_scan_plugins' ), 'Inclure wp-content/plugins' ); ?><br><br>
                <?php TRQ_Admin::toggle( 'file_monitor_scan_themes', (bool) $core->get( 'file_monitor_scan_themes' ), 'Inclure wp-content/themes' ); ?><br><br>
                <?php TRQ_Admin::toggle( 'file_monitor_scan_muplugins', (bool) $core->get( 'file_monitor_scan_muplugins' ), 'Inclure mu-plugins' ); ?><br><br>
                <?php TRQ_Admin::toggle( 'file_monitor_scan_uploads', (bool) $core->get( 'file_monitor_scan_uploads' ), 'Inclure uploads et signaler les fichiers exécutables' ); ?><br><br>
                <?php TRQ_Admin::toggle( 'file_monitor_quarantine_enabled', (bool) $core->get( 'file_monitor_quarantine_enabled' ), 'Autoriser la quarantaine manuelle des fichiers suspects dans wp-content' ); ?>
                <p class="description">Le scan ne désinfecte pas automatiquement. Il signale et permet une quarantaine prudente pour les fichiers suspects dans wp-content.</p>
            </td>
        </tr>
    </table>

    <h2>💬 Protection anti-spam des commentaires</h2>
    <table class="form-table">
        <tr>
            <th>Anti-spam</th>
            <td>
                <?php TRQ_Admin::toggle( 'antispam_enabled', (bool) $core->get( 'antispam_enabled' ), 'Activer la protection anti-spam (honeypot + rate-limiting + vérification token)' ); ?>
                <br><br>
                <?php TRQ_Admin::toggle( 'antispam_form_protection_enabled', (bool) $core->get( 'antispam_form_protection_enabled', true ), 'Activer la protection formulaires frontend hors commentaires (honeypot + token + délai minimal)' ); ?>
            </td>
        </tr>
    </table>

    <h2>🔧 Durcissement WordPress</h2>
    <table class="form-table">
        <tr>
            <th>Désactiver XML-RPC</th>
            <td>
                <?php TRQ_Admin::toggle( 'disable_xmlrpc', (bool) $core->get( 'disable_xmlrpc' ), 'Désactiver complètement XML-RPC (souvent vecteur d\'attaques brute-force)' ); ?>
                <p class="description">⚠️ Certains plugins mobiles et services tiers utilisent XML-RPC. Désactivez uniquement si vous n'en avez pas besoin.</p>
            </td>
        </tr>
        <tr>
            <th>Masquer la version WordPress</th>
            <td><?php TRQ_Admin::toggle( 'hide_wp_version', (bool) $core->get( 'hide_wp_version' ), 'Supprimer la version WordPress des métadonnées HTML et des flux RSS' ); ?></td>
        </tr>
        <tr>
            <th>Désactiver l'édition de fichiers</th>
            <td>
                <?php TRQ_Admin::toggle( 'disable_file_edit', (bool) $core->get( 'disable_file_edit' ), 'Désactiver l\'éditeur de fichiers dans wp-admin (définit DISALLOW_FILE_EDIT)' ); ?>
                <p class="description">Empêche les modifications de fichiers PHP directement depuis l'interface WordPress en cas de compromission du compte admin.</p>
            </td>
        </tr>
        <tr>
            <th>Durcissement admin avancé</th>
            <td>
                <?php TRQ_Admin::toggle( 'disable_file_mods', (bool) $core->get( 'disable_file_mods' ), 'Désactiver l’installation et la mise à jour de plugins/thèmes depuis wp-admin (DISALLOW_FILE_MODS)' ); ?><br><br>
                <?php TRQ_Admin::toggle( 'disable_application_passwords', (bool) $core->get( 'disable_application_passwords' ), 'Désactiver les Application Passwords WordPress' ); ?>
                <p class="description">Ces options réduisent la surface d’attaque des comptes administrateurs compromis, au prix d’un peu moins de confort d’administration.</p>
                <p class="description"><strong>Important :</strong> si l’option DISALLOW_FILE_MODS est activée, WordPress bloque l’installation, la mise à jour et la suppression des extensions et thèmes depuis wp-admin. Désactivez-la ici si vous voulez retrouver un accès admin complet.</p>
            </td>
        </tr>
        <tr>
            <th>Scan système</th>
            <td>
                <?php TRQ_Admin::toggle( 'core_checksum_enabled', (bool) $core->get( 'core_checksum_enabled' ), 'Vérifier le core WordPress via checksums officiels WordPress.org' ); ?><br><br>
                <?php TRQ_Admin::toggle( 'db_scan_enabled', (bool) $core->get( 'db_scan_enabled' ), 'Activer le scan base de données' ); ?><br><br>
                <?php TRQ_Admin::toggle( 'admin_review_enabled', (bool) $core->get( 'admin_review_enabled' ), 'Activer la revue des comptes administrateurs' ); ?><br><br>
                <?php TRQ_Admin::toggle( 'uploads_hardening_enabled', (bool) $core->get( 'uploads_hardening_enabled' ), 'Bloquer les uploads exécutables dangereux' ); ?>
                <p class="description">Résultats actuels : <strong><?php echo esc_html( $system_summary['core_findings'] ); ?></strong> signaux core, <strong><?php echo esc_html( $system_summary['db_findings'] ); ?></strong> signaux base de données, <strong><?php echo esc_html( $system_summary['admin_findings'] ); ?></strong> signaux comptes admin, <strong><?php echo esc_html( $system_summary['cron_findings'] ); ?></strong> signaux cron.</p>
            </td>
        </tr>
        <tr>
            <th>Mise à jour des définitions</th>
            <td>
                <?php TRQ_Admin::toggle( 'definitions_auto_update_enabled', (bool) $core->get( 'definitions_auto_update_enabled', false ), 'Activer la mise à jour automatique des définitions de détection' ); ?>
                <p class="description">Les définitions alimentent le File Monitor et le scan système. Sans URL distante, le plugin utilise les définitions embarquées.</p>

                <label for="definitions_update_url">URL JSON des définitions</label><br>
                <input type="url" id="definitions_update_url" name="definitions_update_url"
                       value="<?php echo esc_attr( (string) $core->get( 'definitions_update_url', '' ) ); ?>"
                       class="regular-text" placeholder="https://example.com/360tranquilite-definitions.json" />
                <p class="description">Dernière mise à jour : <strong><?php echo ! empty( $definitions_status['updated_at'] ) ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $definitions_status['updated_at'] ) ) ) : 'Jamais'; ?></strong> &nbsp;|&nbsp; Version : <strong><?php echo esc_html( (string) ( $definitions_status['version'] ?? TRQ_VERSION ) ); ?></strong> &nbsp;|&nbsp; Source : <strong><?php echo esc_html( (string) ( $definitions_status['source'] ?? 'bundled' ) ); ?></strong></p>
                <p class="description"><?php echo esc_html( (string) ( $definitions_status['message'] ?? '' ) ); ?></p>
            </td>
        </tr>
        <tr>
            <th><label for="db_scan_max_rows">Profondeur du scan DB</label></th>
            <td>
                <input type="number" id="db_scan_max_rows" name="db_scan_max_rows"
                       value="<?php echo esc_attr( $core->get( 'db_scan_max_rows', 200 ) ); ?>"
                       min="50" max="1000" class="small-text" />
                <p class="description">Nombre maximum de lignes inspectées par table sensible lors d’un scan manuel.</p>
            </td>
        </tr>
        <tr>
            <th>Journal d’audit</th>
            <td>
                <?php TRQ_Admin::toggle( 'audit_log_enabled', (bool) $core->get( 'audit_log_enabled' ), 'Tracer les actions sensibles : plugins, thèmes, options, comptes et connexions admin' ); ?>
                <p class="description">Événements aujourd’hui : <strong><?php echo esc_html( $audit_stats['total_today'] ); ?></strong> &nbsp;|&nbsp; Critiques : <strong><?php echo esc_html( $audit_stats['critical_today'] ); ?></strong></p>
            </td>
        </tr>
    </table>

    <h2>📧 Notifications de sécurité</h2>
    <table class="form-table">
        <tr>
            <th>Recevoir des notifications</th>
            <td>
                <?php TRQ_Admin::toggle( 'notify_enabled', (bool) $core->get( 'notify_enabled', true ), 'Activer l’envoi des notifications email de sécurité' ); ?>
            </td>
        </tr>
        <tr>
            <th><label for="notify_email">Email de notification</label></th>
            <td>
                <input type="email" id="notify_email" name="notify_email"
                       value="<?php echo esc_attr( (string) $core->get( 'notify_email', '' ) ); ?>"
                       placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"
                       class="regular-text" />
                <p class="description">Laissez vide pour ne pas recevoir d’email. L’adresse admin du site est affichée uniquement en suggestion.</p>
            </td>
        </tr>
        <tr>
            <th><label for="data_retention_days">Conservation des journaux</label></th>
            <td>
                <input type="number" id="data_retention_days" name="data_retention_days"
                       value="<?php echo esc_attr( $core->get( 'data_retention_days', 30 ) ); ?>"
                       min="7" max="365" class="small-text" />
                <p class="description">Nombre de jours de conservation des tentatives de connexion, des logs firewall et des blocages expirés avant nettoyage automatique.</p>
            </td>
        </tr>
    </table>
    <?php TRQ_Admin::settings_form_close(); ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
        <?php wp_nonce_field( 'trq_action' ); ?>
        <input type="hidden" name="action" value="trq_action" />
        <input type="hidden" name="trq_do" value="update_threat_definitions" />
        <input type="hidden" name="trq_tab" value="advanced" />
        <button type="submit" class="button button-secondary">Mettre à jour les définitions maintenant</button>
    </form>
</div>

<div class="trq-section trq-advanced-transfer">
    <h2>📦 Export / import des réglages</h2>
    <p>Exportez la configuration actuelle du plugin au format JSON pour la réutiliser sur un autre site, puis importez-la ici pour retrouver rapidement le même profil de durcissement et de sauvegarde.</p>

    <p>
        <?php TRQ_Admin::action_form( 'export_settings', [], 'advanced', 'Exporter les réglages', 'button-secondary' ); ?>
    </p>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
        <?php wp_nonce_field( 'trq_action' ); ?>
        <input type="hidden" name="action" value="trq_action" />
        <input type="hidden" name="trq_do" value="import_settings" />
        <input type="hidden" name="trq_tab" value="advanced" />

        <table class="form-table" role="presentation">
            <tr>
                <th><label for="trq-settings-file">Fichier JSON</label></th>
                <td>
                    <input type="file" id="trq-settings-file" name="settings_file" accept="application/json,.json" />
                    <p class="description">Le fichier doit provenir d’un export 360 Tranquillité. Les réglages importés remplacent les réglages actuels correspondants.</p>
                </td>
            </tr>
        </table>

        <p class="trq-submit">
            <button type="submit" class="button button-primary">Importer les réglages</button>
        </p>
    </form>
</div>

<div class="trq-section trq-advanced-file-report">
    <h2>🧪 Dernier rapport de scan</h2>
    <p>Date du rapport : <strong><?php echo ! empty( $last_report['generated_at'] ) ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $last_report['generated_at'] ) ) ) : 'Aucun rapport'; ?></strong></p>

    <h3>Changements détectés</h3>
    <?php if ( ! empty( $last_report['changes'] ) ) : ?>
        <table class="widefat striped trq-log-table">
            <thead><tr><th>Type</th><th>Fichier</th></tr></thead>
            <tbody>
            <?php foreach ( array_slice( $last_report['changes'], 0, 20 ) as $change ) : ?>
                <tr>
                    <td><span class="trq-threat-type"><?php echo esc_html( strtoupper( $change['type'] ) ); ?></span></td>
                    <td><code><?php echo esc_html( $change['path'] ); ?></code></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p class="trq-empty">✅ Aucun changement inattendu dans le dernier rapport.</p>
    <?php endif; ?>

    <h3>Fichiers suspects</h3>
    <?php if ( ! empty( $last_report['findings'] ) ) : ?>
        <table class="widefat striped trq-log-table">
            <thead><tr><th>Sévérité</th><th>Type</th><th>Fichier</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ( array_slice( $last_report['findings'], 0, 20 ) as $finding ) : ?>
                <tr>
                    <td><span class="trq-threat-type"><?php echo esc_html( strtoupper( $finding['severity'] ) ); ?></span></td>
                    <td><?php echo esc_html( $finding['type'] ); ?><br><span class="description"><?php echo esc_html( $finding['message'] ); ?></span></td>
                    <td><code><?php echo esc_html( $finding['path'] ); ?></code></td>
                    <td>
                        <?php if ( $core->get( 'file_monitor_quarantine_enabled' ) ) : ?>
                            <?php TRQ_Admin::action_form( 'quarantine_file', [ 'path' => $finding['path'] ], 'advanced', 'Mettre en quarantaine', 'button-secondary' ); ?>
                        <?php else : ?>
                            <em>Désactivée</em>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p class="trq-empty">✅ Aucun signal suspect dans le dernier rapport.</p>
    <?php endif; ?>
</div>

<div class="trq-section trq-advanced-system-report">
    <h2>🧬 Dernier scan système</h2>
    <p>Date du rapport : <strong><?php echo ! empty( $system_report['generated_at'] ) ? esc_html( date_i18n( 'd/m/Y H:i', strtotime( $system_report['generated_at'] ) ) ) : 'Aucun rapport'; ?></strong></p>

    <h3>Core WordPress</h3>
    <?php if ( ! empty( $system_report['core']['message'] ) ) : ?>
        <p class="description"><?php echo esc_html( $system_report['core']['message'] ); ?></p>
    <?php endif; ?>
    <?php if ( ! empty( $system_report['core']['findings'] ) ) : ?>
        <table class="widefat striped trq-log-table">
            <thead><tr><th>Type</th><th>Fichier</th><th>Détail</th></tr></thead>
            <tbody>
            <?php foreach ( array_slice( $system_report['core']['findings'], 0, 20 ) as $finding ) : ?>
                <tr>
                    <td><span class="trq-threat-type"><?php echo esc_html( strtoupper( $finding['type'] ) ); ?></span></td>
                    <td><code><?php echo esc_html( $finding['path'] ); ?></code></td>
                    <td><?php echo esc_html( $finding['message'] ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p class="trq-empty">✅ Aucun écart détecté sur le core dans le dernier scan.</p>
    <?php endif; ?>

    <h3>Base de données</h3>
    <?php if ( ! empty( $system_report['database']['findings'] ) ) : ?>
        <table class="widefat striped trq-log-table">
            <thead><tr><th>Zone</th><th>Type</th><th>Référence</th><th>Aperçu</th></tr></thead>
            <tbody>
            <?php foreach ( array_slice( $system_report['database']['findings'], 0, 20 ) as $finding ) : ?>
                <tr>
                    <td><?php echo esc_html( $finding['scope'] ); ?></td>
                    <td><span class="trq-threat-type"><?php echo esc_html( strtoupper( $finding['type'] ) ); ?></span></td>
                    <td><?php echo esc_html( $finding['item_key'] ); ?></td>
                    <td><?php echo esc_html( $finding['preview'] ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p class="trq-empty">✅ Aucun signal de base de données dans le dernier scan.</p>
    <?php endif; ?>

    <h3>Comptes administrateurs</h3>
    <?php if ( ! empty( $system_report['admins']['findings'] ) ) : ?>
        <table class="widefat striped trq-log-table">
            <thead><tr><th>Utilisateur</th><th>Type</th><th>Détail</th></tr></thead>
            <tbody>
            <?php foreach ( array_slice( $system_report['admins']['findings'], 0, 20 ) as $finding ) : ?>
                <tr>
                    <td><?php echo esc_html( $finding['user'] ); ?></td>
                    <td><span class="trq-threat-type"><?php echo esc_html( strtoupper( $finding['type'] ) ); ?></span></td>
                    <td><?php echo esc_html( $finding['message'] ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p class="trq-empty">✅ Aucun compte administrateur suspect dans le dernier scan.</p>
    <?php endif; ?>

    <h3>Tâches planifiées (CRON)</h3>
    <?php if ( ! empty( $system_report['cron']['findings'] ) ) : ?>
        <table class="widefat striped trq-log-table">
            <thead><tr><th>Hook</th><th>Type</th><th>Prochaine exécution</th><th>Détail</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ( array_slice( $system_report['cron']['findings'], 0, 20 ) as $finding ) : ?>
                <tr>
                    <td><?php echo esc_html( $finding['hook'] ); ?></td>
                    <td><span class="trq-threat-type"><?php echo esc_html( strtoupper( $finding['type'] ) ); ?></span></td>
                    <td><?php echo esc_html( $finding['next_run'] ); ?></td>
                    <td><?php echo esc_html( $finding['message'] ); ?></td>
                    <td>
                        <?php if ( 'suspicious_hook_name' === $finding['type'] ) : ?>
                            <?php TRQ_Admin::action_form( 'unschedule_cron_hook', [ 'hook' => $finding['hook'] ], 'advanced', 'Supprimer les occurrences', 'button-secondary' ); ?>
                        <?php else : ?>
                            <em>Observation</em>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p class="trq-empty">✅ Aucune tâche CRON suspecte dans le dernier scan.</p>
    <?php endif; ?>
</div>

<div class="trq-section trq-advanced-uploads">
    <h2>📦 Durcissement du dossier uploads</h2>
    <p class="description">Dossier uploads : <strong><?php echo ! empty( $uploads_status['upload_dir'] ) ? esc_html( $uploads_status['upload_dir'] ) : 'Non détecté'; ?></strong></p>
    <ul class="trq-feature-list">
        <li><?php echo ! empty( $uploads_status['htaccess'] ) ? '✅' : '⚠️'; ?> .htaccess de protection</li>
        <li><?php echo ! empty( $uploads_status['web_config'] ) ? '✅' : '⚠️'; ?> web.config de protection</li>
        <li><?php echo ! empty( $uploads_status['index_php'] ) ? '✅' : '⚠️'; ?> index.php anti listing</li>
        <li><?php echo ! empty( $uploads_status['writable'] ) ? '✅' : '⚠️'; ?> dossier writable</li>
    </ul>
    <div class="trq-action-buttons">
        <?php TRQ_Admin::action_form( 'apply_uploads_hardening', [], 'advanced', 'Appliquer / vérifier le durcissement uploads', 'button-secondary' ); ?>
    </div>
</div>

<div class="trq-section trq-advanced-audit">
    <h2>📝 Journal d’audit</h2>
    <?php if ( $audit_logs ) : ?>
        <table class="widefat striped trq-log-table">
            <thead><tr><th>Date (UTC)</th><th>Sévérité</th><th>Événement</th><th>IP</th><th>Détail</th></tr></thead>
            <tbody>
            <?php foreach ( $audit_logs as $log ) : ?>
                <tr>
                    <td><?php echo esc_html( $log->created_at ); ?></td>
                    <td><span class="trq-threat-type"><?php echo esc_html( strtoupper( $log->severity ) ); ?></span></td>
                    <td><?php echo esc_html( $log->event_type ); ?></td>
                    <td><code><?php echo esc_html( $log->ip_address ); ?></code></td>
                    <td><?php echo esc_html( $log->message ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p class="trq-empty">Aucun événement d’audit enregistré pour le moment.</p>
    <?php endif; ?>
</div>

<!-- Actions de maintenance -->
<div class="trq-section trq-advanced-actions">
    <h2>🔧 Actions manuelles</h2>
    <p>Ces actions peuvent être déclenchées manuellement en dehors de la planification automatique.</p>
    <div class="trq-action-buttons">
        <?php TRQ_Admin::action_form( 'build_baseline', [], 'advanced', '📸 Reconstruire la baseline des fichiers', 'button-secondary' ); ?>
        &nbsp;
        <?php TRQ_Admin::action_form( 'run_file_scan',  [], 'advanced', '🔍 Lancer un scan de fichiers maintenant', 'button-secondary' ); ?>
        &nbsp;
        <?php TRQ_Admin::action_form( 'run_system_scan', [], 'advanced', '🧬 Lancer un scan système', 'button-secondary' ); ?>
        &nbsp;
        <?php TRQ_Admin::action_form( 'download_incident_report', [], 'advanced', '⬇️ Exporter un rapport d’incident', 'button-secondary' ); ?>
        &nbsp;
        <?php TRQ_Admin::action_form( 'run_incident_cleanup', [], 'advanced', '🧹 Assainissement incident (quarantaine auto)', 'button-primary' ); ?>
    </div>
    <p class="description">
        La <strong>baseline</strong> enregistre les checksums de référence. À utiliser après une mise à jour WordPress pour réinitialiser la référence.<br/>
        Le <strong>scan</strong> compare l'état actuel aux checksums enregistrés.<br/>
        Le <strong>scan système</strong> inspecte une partie de la base, les comptes admin et les tâches planifiées.
    </p>
    <p class="description"><strong>Assainissement incident</strong> : lance un scan, met en quarantaine automatiquement les fichiers suspects détectés dans wp-content, supprime les hooks CRON suspects et renforce uploads. Les contenus de base de données suspects restent à valider manuellement.</p>
</div>

<div class="trq-section trq-help-box trq-advanced-help">
    <h2>💡 Autres recommandations (hors plugin)</h2>
    <ul class="trq-feature-list">
        <li>Gardez WordPress, les thèmes et plugins <strong>toujours à jour</strong>.</li>
        <li>Utilisez un hébergement avec <strong>PHP 8.1+</strong> et <strong>HTTPS forcé</strong>.</li>
        <li>Configurez des <strong>sauvegardes automatiques quotidiennes</strong> hors serveur.</li>
        <li>Vérifiez les <a href="https://wpscan.com/wordpresses" target="_blank" rel="noopener">vulnérabilités connues</a> de votre version WordPress.</li>
        <li>Limitez les comptes admin au strict nécessaire, utilisez des rôles appropriés.</li>
        <li>Utilisez un mot de passe fort + générateur (Bitwarden, 1Password).</li>
        <li>Conservez NinjaFirewall ou un WAF équivalent tant que vous n’avez pas validé le comportement du pare-feu maison en production.</li>
    </ul>
</div>
