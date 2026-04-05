<?php
/**
 * Vue : Nettoyage medias.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$core = TRQ_Core::get_instance();
$cleanup = TRQ_Media_Cleanup::get_instance();
$report = $cleanup->get_last_report();
$log_tail = $cleanup->get_log_tail();
?>

<div class="trq-section trq-media-settings">
    <h2>Nettoyage des medias orphelins</h2>
    <p>Supprime uniquement des pieces jointes WordPress (attachments) quand aucune reference n'est trouvee. Le module ne touche pas aux fichiers non enregistres dans la bibliotheque media.</p>

    <?php TRQ_Admin::settings_form_open( 'media' ); ?>
    <table class="form-table" role="presentation">
        <tr>
            <th>Activer le module</th>
            <td>
                <?php TRQ_Admin::toggle( 'media_cleanup_enabled', (bool) $core->get( 'media_cleanup_enabled', false ), 'Activer la detection et le nettoyage des medias orphelins' ); ?>
            </td>
        </tr>
        <tr>
            <th>Mode simulation</th>
            <td>
                <?php TRQ_Admin::toggle( 'media_cleanup_dry_run', (bool) $core->get( 'media_cleanup_dry_run', true ), 'Ne rien supprimer, seulement lister ce qui serait supprime' ); ?>
            </td>
        </tr>
        <tr>
            <th>Execution auto</th>
            <td>
                <?php TRQ_Admin::toggle( 'media_cleanup_auto_enabled', (bool) $core->get( 'media_cleanup_auto_enabled', false ), 'Lancer un scan hebdomadaire automatique' ); ?>
            </td>
        </tr>
        <tr>
            <th>Age minimal</th>
            <td>
                <input type="number" min="1" max="365" class="small-text" name="media_cleanup_min_age_days" value="<?php echo esc_attr( (string) (int) $core->get( 'media_cleanup_min_age_days', 14 ) ); ?>" /> jours
                <p class="description">Ignore les medias trop recents pour eviter les faux positifs.</p>
            </td>
        </tr>
        <tr>
            <th>Mots-clés proteges</th>
            <td>
                <input type="text" class="regular-text" name="media_cleanup_protected_keywords" value="<?php echo esc_attr( (string) $core->get( 'media_cleanup_protected_keywords', 'logo,icon,favicon,placeholder,banner,default' ) ); ?>" />
                <p class="description">Ces fichiers ne seront jamais supprimes si leur nom contient un de ces mots.</p>
            </td>
        </tr>

        <tr><th colspan="2"><h3>Optimisation des images</h3></th></tr>
        <tr>
            <th>Activer l'optimisation</th>
            <td>
                <?php TRQ_Admin::toggle( 'media_optimization_enabled', (bool) $core->get( 'media_optimization_enabled', false ), 'Optimiser automatiquement les images lors du televersement' ); ?>
            </td>
        </tr>
        <tr>
            <th>Dimensions max</th>
            <td>
                <label>Largeur: <input type="number" min="640" max="6000" class="small-text" name="media_optimization_max_width" value="<?php echo esc_attr( (string) (int) $core->get( 'media_optimization_max_width', 2560 ) ); ?>" /> px</label>
                &nbsp;&nbsp;
                <label>Hauteur: <input type="number" min="640" max="6000" class="small-text" name="media_optimization_max_height" value="<?php echo esc_attr( (string) (int) $core->get( 'media_optimization_max_height', 2560 ) ); ?>" /> px</label>
                <p class="description">Les images plus grandes sont redimensionnees pour rester dans cette limite.</p>
            </td>
        </tr>
        <tr>
            <th>Qualite compression</th>
            <td>
                <input type="number" min="30" max="100" class="small-text" name="media_optimization_quality" value="<?php echo esc_attr( (string) (int) $core->get( 'media_optimization_quality', 82 ) ); ?>" /> / 100
                <p class="description">Applique une qualite uniforme aux nouvelles images generees.</p>
            </td>
        </tr>
        <tr>
            <th>Generation WebP</th>
            <td>
                <?php TRQ_Admin::toggle( 'media_optimization_generate_webp', (bool) $core->get( 'media_optimization_generate_webp', false ), 'Generer des variantes .webp (sans remplacer les originaux)' ); ?>
            </td>
        </tr>
    </table>
    <?php TRQ_Admin::settings_form_close(); ?>
</div>

<div class="trq-section trq-media-run">
    <h2>Lancement manuel</h2>
    <p>Conseil: gardez le mode simulation actif pour les premiers runs.</p>
    <?php TRQ_Admin::action_form( 'run_media_cleanup', [], 'media', 'Lancer l\'analyse maintenant', 'button-primary button-large' ); ?>
</div>

<div class="trq-section trq-media-report">
    <h2>Dernier rapport</h2>
    <?php if ( ! empty( $report ) ) : ?>
        <ul class="trq-feature-list">
            <li>Date: <?php echo esc_html( (string) ( $report['generated_at'] ?? 'N/A' ) ); ?></li>
            <li>Mode: <?php echo ! empty( $report['dry_run'] ) ? 'Simulation' : 'Reel'; ?></li>
            <li>Medias verifiés: <?php echo esc_html( (string) (int) ( $report['total_checked'] ?? 0 ) ); ?></li>
            <li>Orphelins detectes: <?php echo esc_html( (string) (int) ( $report['orphans_found'] ?? 0 ) ); ?></li>
            <li>Supprimes: <?php echo esc_html( (string) (int) ( $report['deleted'] ?? 0 ) ); ?></li>
            <li>Echecs suppression: <?php echo esc_html( (string) (int) ( $report['failed_deletes'] ?? 0 ) ); ?></li>
            <li>Recents ignores: <?php echo esc_html( (string) (int) ( $report['skipped_recent'] ?? 0 ) ); ?></li>
        </ul>

        <?php if ( ! empty( $report['samples'] ) ) : ?>
            <h4>Exemples detectes</h4>
            <ul class="trq-feature-list">
                <?php foreach ( (array) $report['samples'] as $sample ) : ?>
                    <li><?php echo esc_html( (string) ( $sample['name'] ?? '' ) ); ?> (ID: <?php echo esc_html( (string) (int) ( $sample['id'] ?? 0 ) ); ?>)</li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php else : ?>
        <p>Aucun rapport disponible.</p>
    <?php endif; ?>
</div>

<div class="trq-section trq-media-log">
    <h2>Journal</h2>
    <textarea readonly style="width:100%;height:320px;font-family:monospace;"><?php echo esc_textarea( $log_tail ); ?></textarea>
    <p class="description">Fichier: wp-content/uploads/360media-cleanup.log</p>
</div>
