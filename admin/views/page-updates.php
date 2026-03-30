<?php
/**
 * Vue : Mises à jour automatiques.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$core = TRQ_Core::get_instance();
$updates = TRQ_Auto_Updates::get_instance();
$status = $updates->get_status_summary();
$last_report = $updates->get_last_report();
?>

<div class="trq-section trq-updates-settings">
    <h2>Mises à jour automatiques sécurisées</h2>
    <p>Ce module permet de piloter les mises à jour automatiques WordPress (core, plugins, thèmes, traductions) avec une fenêtre horaire maîtrisée.</p>

    <?php if ( ! empty( $status['blocked_by_file_mods'] ) ) : ?>
        <div class="notice inline notice-warning">
            <p><strong>DISALLOW_FILE_MODS est actif.</strong> WordPress bloque les mises à jour automatiques tant que cette option de durcissement reste activée dans l’onglet Avancé.</p>
        </div>
    <?php endif; ?>

    <?php TRQ_Admin::settings_form_open( 'updates' ); ?>
    <table class="form-table" role="presentation">
        <tr>
            <th>Activation globale</th>
            <td>
                <?php TRQ_Admin::toggle( 'updates_auto_enabled', (bool) $core->get( 'updates_auto_enabled', false ), 'Activer la gestion automatisée des mises à jour' ); ?>
            </td>
        </tr>
        <tr>
            <th>Mises à jour du core</th>
            <td>
                <label><input type="radio" name="updates_core_mode" value="disabled" <?php checked( $core->get( 'updates_core_mode', 'minor' ), 'disabled' ); ?> /> Désactivées</label><br>
                <label><input type="radio" name="updates_core_mode" value="minor" <?php checked( $core->get( 'updates_core_mode', 'minor' ), 'minor' ); ?> /> Versions mineures et sécurité (recommandé)</label><br>
                <label><input type="radio" name="updates_core_mode" value="all" <?php checked( $core->get( 'updates_core_mode', 'minor' ), 'all' ); ?> /> Toutes les versions (y compris majeures)</label>
            </td>
        </tr>
        <tr>
            <th>Périmètre</th>
            <td>
                <?php TRQ_Admin::toggle( 'updates_plugins_auto', (bool) $core->get( 'updates_plugins_auto', true ), 'Mettre à jour automatiquement les plugins' ); ?><br><br>
                <?php TRQ_Admin::toggle( 'updates_themes_auto', (bool) $core->get( 'updates_themes_auto', true ), 'Mettre à jour automatiquement les thèmes' ); ?><br><br>
                <?php TRQ_Admin::toggle( 'updates_translations_auto', (bool) $core->get( 'updates_translations_auto', true ), 'Mettre à jour automatiquement les traductions' ); ?>
            </td>
        </tr>
        <tr>
            <th>Fenêtre de maintenance</th>
            <td>
                <?php TRQ_Admin::toggle( 'updates_window_enabled', (bool) $core->get( 'updates_window_enabled', false ), 'Limiter les mises à jour à une plage horaire quotidienne' ); ?><br><br>
                Début : <input type="time" name="updates_window_start" value="<?php echo esc_attr( $core->get( 'updates_window_start', '02:00' ) ); ?>" />
                &nbsp; Durée (heures) : <input type="number" min="1" max="12" name="updates_window_duration_hours" value="<?php echo esc_attr( (string) (int) $core->get( 'updates_window_duration_hours', 3 ) ); ?>" class="small-text" />
                <p class="description">Important : WordPress déclenche les mises à jour automatiques via WP-Cron. Même si la fenêtre est ouverte, l'exécution n'est pas instantanée et dépend du prochain passage du cron.</p>
            </td>
        </tr>
        <tr>
            <th>Notifications</th>
            <td>
                <fieldset>
                    <label><input type="radio" name="updates_notify_mode" value="none" <?php checked( $core->get( 'updates_notify_mode', 'all' ), 'none' ); ?> /> Désactivées</label><br>
                    <label><input type="radio" name="updates_notify_mode" value="all" <?php checked( $core->get( 'updates_notify_mode', 'all' ), 'all' ); ?> /> Me notifier à chaque mise à jour effectuée</label><br>
                    <label><input type="radio" name="updates_notify_mode" value="failures" <?php checked( $core->get( 'updates_notify_mode', 'all' ), 'failures' ); ?> /> Me notifier uniquement en cas d'échec</label><br>
                    <label><input type="radio" name="updates_notify_mode" value="problems" <?php checked( $core->get( 'updates_notify_mode', 'all' ), 'problems' ); ?> /> Me notifier uniquement en cas de problème (incompatibilités ou échecs)</label>
                </fieldset>
                <p class="description">Les notifications sont envoyées à l'adresse configurée dans l'onglet <strong>Avancé</strong>.</p>
            </td>
        </tr>
        <tr>
            <th>Vérification des compatibilités</th>
            <td>
                <?php TRQ_Admin::toggle( 'updates_check_compat', (bool) $core->get( 'updates_check_compat', true ), 'Bloquer les mises à jour incompatibles avec WordPress ou PHP' ); ?>
                <p class="description">Avant chaque mise à jour automatique, le plugin vérifie la compatibilité avec votre version de WordPress et PHP. Si une incompatibilité est détectée, la mise à jour est bloquée et vous êtes notifié (selon le mode de notification choisi ci-dessus).</p>
            </td>
        </tr>
        <tr>
            <th>Résilience et rollback</th>
            <td>
                <?php TRQ_Admin::toggle( 'updates_pre_update_backup_enabled', (bool) $core->get( 'updates_pre_update_backup_enabled', true ), 'Créer une sauvegarde locale avant chaque mise à jour core/plugin/thème' ); ?><br><br>
                <?php TRQ_Admin::toggle( 'updates_auto_rollback_enabled', (bool) $core->get( 'updates_auto_rollback_enabled', false ), 'Activer le rollback automatique si le site devient indisponible après update' ); ?><br><br>
                <?php TRQ_Admin::toggle( 'updates_post_update_healthcheck_enabled', (bool) $core->get( 'updates_post_update_healthcheck_enabled', true ), 'Activer le health-check périodique après update' ); ?><br><br>

                URL de health-check :
                <input type="url" class="regular-text" name="updates_healthcheck_url" value="<?php echo esc_attr( (string) $core->get( 'updates_healthcheck_url', home_url( '/' ) ) ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" />
                <br><br>

                Timeout HTTP (secondes) :
                <input type="number" min="3" max="30" name="updates_healthcheck_timeout" class="small-text" value="<?php echo esc_attr( (string) (int) $core->get( 'updates_healthcheck_timeout', 10 ) ); ?>" />
                &nbsp; Tentatives max :
                <input type="number" min="1" max="20" name="updates_healthcheck_retries" class="small-text" value="<?php echo esc_attr( (string) (int) $core->get( 'updates_healthcheck_retries', 5 ) ); ?>" />
                &nbsp; Intervalle (minutes) :
                <input type="number" min="1" max="30" name="updates_healthcheck_interval_minutes" class="small-text" value="<?php echo esc_attr( (string) (int) $core->get( 'updates_healthcheck_interval_minutes', 3 ) ); ?>" />

                <p class="description">Le rollback automatique restaure la dernière sauvegarde pré-update si plusieurs health-checks consécutifs échouent (erreur HTTP ou réponse contenant un marqueur de crash).</p>
            </td>
        </tr>
    </table>
    <?php TRQ_Admin::settings_form_close(); ?>
</div>

<div class="trq-section trq-updates-last-report">
    <h2>Dernier rapport de mises à jour</h2>
    <p>Date du rapport : <strong><?php echo ! empty( $last_report['generated_at'] ) ? esc_html( $last_report['generated_at'] ) : 'Aucun rapport'; ?></strong></p>

    <?php if ( ! empty( $last_report['counts'] ) && array_sum( $last_report['counts'] ) > 0 ) : ?>
        <h4>Mises à jour appliquées :</h4>
        <ul class="trq-feature-list">
            <?php if ( ! empty( $last_report['counts']['core'] ) ) : ?><li>Core : <?php echo esc_html( (string) (int) $last_report['counts']['core'] ); ?></li><?php endif; ?>
            <?php if ( ! empty( $last_report['counts']['plugins'] ) ) : ?><li>Plugins : <?php echo esc_html( (string) (int) $last_report['counts']['plugins'] ); ?></li><?php endif; ?>
            <?php if ( ! empty( $last_report['counts']['themes'] ) ) : ?><li>Thèmes : <?php echo esc_html( (string) (int) $last_report['counts']['themes'] ); ?></li><?php endif; ?>
            <?php if ( ! empty( $last_report['counts']['translations'] ) ) : ?><li>Traductions : <?php echo esc_html( (string) (int) $last_report['counts']['translations'] ); ?></li><?php endif; ?>
        </ul>
    <?php endif; ?>

    <?php $total_failures = isset( $last_report['failures'] ) ? array_sum( $last_report['failures'] ) : 0; ?>
    <?php if ( $total_failures > 0 ) : ?>
        <h4 style="color:#d63638;">Échecs :</h4>
        <ul class="trq-feature-list">
            <?php if ( ! empty( $last_report['failures']['core'] ) ) : ?><li>Core : <?php echo esc_html( (string) (int) $last_report['failures']['core'] ); ?></li><?php endif; ?>
            <?php if ( ! empty( $last_report['failures']['plugins'] ) ) : ?><li>Plugins : <?php echo esc_html( (string) (int) $last_report['failures']['plugins'] ); ?></li><?php endif; ?>
            <?php if ( ! empty( $last_report['failures']['themes'] ) ) : ?><li>Thèmes : <?php echo esc_html( (string) (int) $last_report['failures']['themes'] ); ?></li><?php endif; ?>
        </ul>
    <?php endif; ?>

    <?php if ( ! empty( $last_report['compat_skipped'] ) ) : ?>
        <h4 style="color:#d63638;">Bloquées pour incompatibilité :</h4>
        <ul class="trq-feature-list">
            <?php foreach ( $last_report['compat_skipped'] as $skipped ) : ?>
                <li>
                    <strong><?php echo esc_html( $skipped['name'] ?? '' ); ?></strong>
                    (v<?php echo esc_html( $skipped['new_version'] ?? '?' ); ?>) —
                    <?php echo esc_html( $skipped['reason'] ?? '' ); ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>

    <?php if ( empty( $last_report['counts'] ) && 0 === $total_failures && empty( $last_report['compat_skipped'] ) ) : ?>
        <p>Aucune mise à jour automatique enregistrée pour le moment.</p>
    <?php endif; ?>
</div>

<?php $compat_issues = $updates->get_pending_compat_issues(); ?>
<?php if ( ! empty( $compat_issues ) ) : ?>
<div class="trq-section trq-updates-compat-issues">
    <h2>Mises à jour disponibles avec incompatibilités détectées</h2>
    <p class="description">Les mises à jour ci-dessous sont actuellement disponibles mais présentent un problème de compatibilité. Elles seront bloquées automatiquement si l'option de vérification est active.</p>
    <table class="wp-list-table widefat striped" style="margin-top:1em;">
        <thead>
            <tr>
                <th>Type</th>
                <th>Nom</th>
                <th>Version actuelle</th>
                <th>Nouvelle version</th>
                <th>Problème détecté</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $compat_issues as $issue ) : ?>
                <tr>
                    <td><?php echo esc_html( ucfirst( $issue['type'] ) ); ?></td>
                    <td><strong><?php echo esc_html( $issue['name'] ); ?></strong></td>
                    <td><?php echo esc_html( $issue['current_version'] ); ?></td>
                    <td><?php echo esc_html( $issue['new_version'] ); ?></td>
                    <td style="color:#d63638;"><?php echo esc_html( $issue['reason'] ); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
