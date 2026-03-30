<?php
/**
 * Vue : Sauvegardes.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$core = TRQ_Core::get_instance();
$manager = TRQ_Backup_Manager::get_instance();
$summary = $manager->get_summary();
$last_report = $manager->get_last_report();
$last_restore_report = $manager->get_last_restore_report();
$google_drive_status = $manager->get_google_drive_connection_status();
$google_drive_setup = $manager->get_google_drive_setup_context();
$google_drive_auth = $manager->get_google_drive_auth_url();
$local_backups = $manager->list_local_backups();
$google_drive_backups = ! empty( $google_drive_status['connected'] ) ? $manager->list_google_drive_backups() : [];
$upload_dir = wp_get_upload_dir();
$backup_base_dir = trailingslashit( $upload_dir['basedir'] ) . $core->get( 'backup_local_dir', '360tranquilite-backups' );
?>

<?php TRQ_Admin::settings_form_open( 'backups' ); ?>

<div class="trq-section trq-backups-plan">
    <h2>Planification des sauvegardes</h2>
    <table class="form-table" role="presentation">
        <tr>
            <th>Activer les sauvegardes</th>
            <td>
                <?php TRQ_Admin::toggle( 'backup_enabled', (bool) $core->get( 'backup_enabled' ), 'Activer les sauvegardes automatiques planifiées' ); ?>
                <p class="description">Prochaine exécution : <strong><?php echo ! empty( $summary['next_run'] ) ? esc_html( wp_date( 'd/m/Y H:i', (int) $summary['next_run'] ) ) : 'Non planifiée'; ?></strong></p>
            </td>
        </tr>
        <tr>
            <th>Type de sauvegarde</th>
            <td>
                <label><input type="radio" name="backup_mode" value="full" <?php checked( $core->get( 'backup_mode', 'full' ), 'full' ); ?> /> Complète</label><br>
                <label><input type="radio" name="backup_mode" value="incremental" <?php checked( $core->get( 'backup_mode', 'full' ), 'incremental' ); ?> /> Incrémentale fichiers + dump SQL complet</label>
            </td>
        </tr>
        <tr>
            <th>Fréquence</th>
            <td>
                <select name="backup_frequency">
                    <option value="daily" <?php selected( $core->get( 'backup_frequency', 'daily' ), 'daily' ); ?>>Quotidienne</option>
                    <option value="weekly" <?php selected( $core->get( 'backup_frequency', 'daily' ), 'weekly' ); ?>>Hebdomadaire</option>
                    <option value="monthly" <?php selected( $core->get( 'backup_frequency', 'daily' ), 'monthly' ); ?>>Mensuelle</option>
                </select>
                <input type="time" name="backup_time" value="<?php echo esc_attr( $core->get( 'backup_time', '02:00' ) ); ?>" />
                <p class="description">Jour semaine: <input type="number" min="0" max="6" name="backup_day_of_week" value="<?php echo esc_attr( $core->get( 'backup_day_of_week', 1 ) ); ?>" /> (0 = dimanche) | Jour du mois: <input type="number" min="1" max="28" name="backup_day_of_month" value="<?php echo esc_attr( $core->get( 'backup_day_of_month', 1 ) ); ?>" /></p>
            </td>
        </tr>
        <tr>
            <th>Rétention</th>
            <td>
                <input type="number" min="1" max="50" name="backup_retention_count" value="<?php echo esc_attr( $core->get( 'backup_retention_count', 5 ) ); ?>" />
                <p class="description">Nombre d’archives à conserver localement et sur les destinations cloud activées.</p>
            </td>
        </tr>
        <tr>
            <th>Contenu</th>
            <td>
                <?php TRQ_Admin::toggle( 'backup_include_files', (bool) $core->get( 'backup_include_files' ), 'Inclure tous les fichiers WordPress' ); ?><br><br>
                <?php TRQ_Admin::toggle( 'backup_include_database', (bool) $core->get( 'backup_include_database' ), 'Inclure la base de données' ); ?><br><br>
                <?php TRQ_Admin::toggle( 'backup_exclude_cache_dirs', (bool) $core->get( 'backup_exclude_cache_dirs' ), 'Exclure les dossiers de cache et de backups tiers les plus courants' ); ?>
            </td>
        </tr>
    </table>
</div>

<div class="trq-section trq-backups-destinations">
    <h2>Destinations</h2>
    <table class="form-table" role="presentation">
        <tr>
            <th>Stockage local</th>
            <td>
                <?php TRQ_Admin::toggle( 'backup_destination_local', (bool) $core->get( 'backup_destination_local' ), 'Conserver les archives sur le serveur WordPress' ); ?>
                <p class="description">Vous pouvez désactiver ce stockage local si vous voulez conserver les sauvegardes uniquement sur Google Drive ou S3. Dossier relatif : <input type="text" name="backup_local_dir" value="<?php echo esc_attr( $core->get( 'backup_local_dir', '360tranquilite-backups' ) ); ?>" /> <br>Chemin actuel : <code><?php echo esc_html( $backup_base_dir ); ?></code><br>Si les envois cloud échouent alors que le local est désactivé, le plugin essaie quand même de garder une copie locale de secours.</p>
            </td>
        </tr>
        <tr>
            <th>Google Drive</th>
            <td>
                <?php TRQ_Admin::toggle( 'backup_destination_google_drive', (bool) $core->get( 'backup_destination_google_drive' ), 'Uploader aussi les archives vers Google Drive' ); ?><br><br>
                <p class="description">
                    <?php if ( ! empty( $google_drive_status['connected'] ) ) : ?>
                        Compte connecté : <strong><?php echo esc_html( $google_drive_status['email'] ?: 'Compte Google Drive autorisé' ); ?></strong>
                        <?php if ( ! empty( $google_drive_status['connected_at'] ) ) : ?>
                            <br>Connecté le <?php echo esc_html( $google_drive_status['connected_at'] ); ?>
                        <?php endif; ?>
                    <?php elseif ( ! empty( $google_drive_status['connector_enabled'] ) ) : ?>
                        Connexion centralisée active. Les utilisateurs de ce site peuvent connecter Google Drive sans configuration supplémentaire dans cet écran.
                    <?php elseif ( ! empty( $google_drive_status['configured'] ) ) : ?>
                        Aucun compte connecté pour le moment.
                    <?php else : ?>
                        Le bouton de connexion sera opérationnel dès que le client OAuth Google du plugin sera configuré côté application.
                    <?php endif; ?>
                </p>
                <p>
                    <?php if ( ! empty( $google_drive_auth['success'] ) && ! empty( $google_drive_auth['url'] ) ) : ?>
                        <a href="<?php echo esc_url( $google_drive_auth['url'] ); ?>" class="button button-secondary">Connecter Google Drive</a>
                    <?php else : ?>
                        <button type="button" class="button button-secondary" disabled>Connecter Google Drive</button>
                    <?php endif; ?>
                    <?php if ( ! empty( $google_drive_status['connected'] ) ) : ?>
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=trq_google_drive_disconnect' ), 'trq_google_drive_disconnect' ) ); ?>" class="button button-link-delete">Déconnecter Google Drive</a>
                    <?php endif; ?>
                </p>
                <p class="description">Le consentement Google vous redirige automatiquement vers WordPress après autorisation. Une fois de retour, le compte connecté reste disponible dans cet écran.</p>
                <?php if ( empty( $google_drive_status['connector_enabled'] ) ) : ?>
                    <label>Client ID Google<br><input type="text" class="regular-text" name="backup_google_drive_client_id" value="<?php echo esc_attr( $core->get( 'backup_google_drive_client_id' ) ); ?>" placeholder="Votre client ID OAuth Google" /></label><br><br>
                    <label>Client Secret Google<br><input type="password" class="regular-text" name="backup_google_drive_client_secret" placeholder="<?php echo $core->get( 'backup_google_drive_client_secret' ) ? 'Secret enregistré — laissez vide pour ne pas modifier' : 'Votre client secret OAuth Google'; ?>" autocomplete="new-password" /></label>
                    <p class="description">Si vous ne voulez pas modifier wp-config.php, renseignez le Client ID et le Client Secret ici puis cliquez sur Sauvegarder. Le bouton Connecter Google Drive deviendra alors actif.</p>
                <?php endif; ?>
                <label>ID du dossier Google Drive<br><input type="text" class="regular-text" name="backup_google_drive_folder_id" value="<?php echo esc_attr( $core->get( 'backup_google_drive_folder_id' ) ); ?>" placeholder="ID du dossier ou URL complète du dossier Google Drive" /></label>
                <p class="description">Vous pouvez coller soit l’ID brut du dossier, soit l’URL complète du dossier Google Drive. Le plugin extraira automatiquement l’identifiant. Si aucun dossier n’est indiqué, l’archive est envoyée à la racine du Drive.</p>
                <?php if ( ! empty( $google_drive_status['connected'] ) ) : ?>
                    <p>
                        <button type="button" class="button button-secondary trq-drive-folder-picker">Choisir visuellement un dossier</button>
                        <button type="button" class="button button-link trq-drive-folder-root">Utiliser la racine du Drive</button>
                    </p>
                    <div class="trq-drive-browser" hidden>
                        <div class="trq-drive-browser-card">
                            <div class="trq-drive-browser-header">
                                <strong class="trq-drive-browser-title">Choisir un dossier Google Drive</strong>
                                <button type="button" class="button-link trq-drive-browser-close">Fermer</button>
                            </div>
                            <div class="trq-drive-browser-breadcrumbs"></div>
                            <div class="trq-drive-browser-actions">
                                <button type="button" class="button button-secondary trq-drive-browser-back" disabled>Retour</button>
                                <button type="button" class="button button-primary trq-drive-browser-select-current">Utiliser ce dossier</button>
                            </div>
                            <div class="trq-drive-browser-feedback description"></div>
                            <div class="trq-drive-browser-list"></div>
                        </div>
                    </div>
                <?php elseif ( empty( $google_drive_status['configured'] ) ) : ?>
                    <div class="notice inline notice-warning">
                        <p><strong>Configuration OAuth requise côté Google</strong></p>
                        <p>URI de redirection autorisée à déclarer dans votre projet Google :</p>
                        <p><code><?php echo esc_html( $google_drive_setup['redirect_uri'] ); ?></code></p>
                        <p>Vous pouvez soit ajouter les constantes ci-dessous dans wp-config.php, soit renseigner le Client ID et le Client Secret juste au-dessus puis enregistrer :</p>
                        <pre style="white-space:pre-wrap;"><?php echo esc_html( $google_drive_setup['wp_config_snippet'] ); ?></pre>
                    </div>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>S3 compatible</th>
            <td>
                <?php TRQ_Admin::toggle( 'backup_destination_s3', (bool) $core->get( 'backup_destination_s3' ), 'Uploader aussi les archives vers un stockage S3 compatible' ); ?><br><br>
                <label>Endpoint S3<br><input type="url" class="regular-text" name="backup_s3_endpoint" value="<?php echo esc_attr( $core->get( 'backup_s3_endpoint' ) ); ?>" placeholder="https://s3.eu-west-3.amazonaws.com ou endpoint R2/Wasabi/B2" /></label><br><br>
                <label>Région<br><input type="text" class="regular-text" name="backup_s3_region" value="<?php echo esc_attr( $core->get( 'backup_s3_region', 'us-east-1' ) ); ?>" /></label><br><br>
                <label>Bucket<br><input type="text" class="regular-text" name="backup_s3_bucket" value="<?php echo esc_attr( $core->get( 'backup_s3_bucket' ) ); ?>" /></label><br><br>
                <label>Access Key<br><input type="text" class="regular-text" name="backup_s3_access_key" value="<?php echo esc_attr( $core->get( 'backup_s3_access_key' ) ); ?>" /></label><br><br>
                <label>Secret Key<br><input type="password" class="regular-text" name="backup_s3_secret_key" placeholder="<?php echo $core->get( 'backup_s3_secret_key' ) ? 'Secret enregistré — laissez vide pour ne pas modifier' : 'Votre secret key S3'; ?>" autocomplete="new-password" /></label><br><br>
                <label>Préfixe / dossier distant<br><input type="text" class="regular-text" name="backup_s3_prefix" value="<?php echo esc_attr( $core->get( 'backup_s3_prefix' ) ); ?>" placeholder="sauvegardes/wordpress" /></label><br><br>
                <?php TRQ_Admin::toggle( 'backup_s3_path_style', (bool) $core->get( 'backup_s3_path_style', true ), 'Utiliser le mode path-style (recommandé pour R2, Wasabi, MinIO, B2 S3, endpoints personnalisés)' ); ?>
                <p class="description">Compatible AWS S3, Cloudflare R2, Wasabi, Backblaze B2 S3, MinIO et autres endpoints S3. Renseignez l’endpoint, la région, le bucket et les clés d’accès.</p>
            </td>
        </tr>
    </table>
    <?php TRQ_Admin::settings_form_close(); ?>
</div>

<div class="trq-section trq-backups-actions">
    <h2>Actions manuelles</h2>
    <p>Dernière sauvegarde : <strong><?php echo ! empty( $last_report['generated_at'] ) ? esc_html( $last_report['generated_at'] ) : 'Aucune'; ?></strong></p>
    <p>Dernière restauration : <strong><?php echo ! empty( $last_restore_report['generated_at'] ) ? esc_html( $last_restore_report['generated_at'] ) : 'Aucune'; ?></strong></p>
    <p class="description">Le lancement manuel part maintenant en arrière-plan pour laisser la page active et afficher la progression. La restauration est disponible depuis les archives locales listées plus bas.</p>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="trq-manual-backup-form">
        <?php wp_nonce_field( 'trq_action' ); ?>
        <input type="hidden" name="action" value="trq_action" />
        <input type="hidden" name="trq_tab" value="backups" />
        <input type="hidden" name="trq_do" value="run_backup_now" />
        <p>
            <button type="submit" class="button button-primary" id="trq-run-backup-now">Lancer une sauvegarde maintenant</button>
        </p>
    </form>
    <div class="trq-backup-progress" data-running="<?php echo ! empty( $summary['progress']['in_progress'] ) ? '1' : '0'; ?>"<?php echo empty( $summary['progress']['in_progress'] ) ? ' hidden' : ''; ?>>
        <div class="trq-backup-progress-meta">
            <strong>Progression de la sauvegarde</strong>
            <span class="trq-backup-progress-percent"><?php echo esc_html( (string) (int) ( $summary['progress']['percent'] ?? 0 ) ); ?>%</span>
        </div>
        <div class="trq-backup-progress-bar"><span class="trq-backup-progress-fill" style="width: <?php echo esc_attr( (string) (int) ( $summary['progress']['percent'] ?? 0 ) ); ?>%;"></span></div>
        <p class="trq-backup-progress-text"><?php echo esc_html( $summary['progress']['message'] ?? '' ); ?></p>
    </div>
    <?php if ( ! empty( $last_report ) ) : ?>
        <ul class="trq-feature-list">
            <li>Mode : <?php echo esc_html( $last_report['mode'] ?? 'n/a' ); ?></li>
            <li>Fichiers inclus : <?php echo esc_html( (string) ( $last_report['included_files'] ?? 0 ) ); ?></li>
            <li>Fichiers scannés : <?php echo esc_html( (string) ( $last_report['scanned_files'] ?? 0 ) ); ?></li>
            <li>Tables SQL : <?php echo esc_html( (string) ( $last_report['database_tables'] ?? 0 ) ); ?></li>
            <li>Taille archive : <?php echo esc_html( ! empty( $last_report['archive_size'] ) ? size_format( (int) $last_report['archive_size'] ) : 'n/a' ); ?></li>
            <li>Google Drive : <?php echo esc_html( $last_report['google_drive']['message'] ?? 'Non utilisé' ); ?></li>
            <li>S3 : <?php echo esc_html( $last_report['s3']['message'] ?? 'Non utilisé' ); ?></li>
        </ul>
    <?php endif; ?>
    <?php if ( ! empty( $last_restore_report ) ) : ?>
        <ul class="trq-feature-list">
            <li>Archive restaurée : <?php echo esc_html( $last_restore_report['archive_name'] ?? 'n/a' ); ?></li>
            <li>Statut : <?php echo esc_html( ! empty( $last_restore_report['success'] ) ? 'Succès' : 'Échec' ); ?></li>
            <li>Fichiers restaurés : <?php echo esc_html( (string) ( $last_restore_report['restored_files'] ?? 0 ) ); ?></li>
            <li>Base de données : <?php echo esc_html( ! empty( $last_restore_report['database_restored'] ) ? 'Restaurée' : 'Ignorée ou absente' ); ?></li>
        </ul>
    <?php endif; ?>
</div>

<div class="trq-section trq-backups-import">
    <h2>Importer une archive externe</h2>
    <p class="description">Importer seulement copie l’archive ZIP dans les archives locales. Importer puis restaurer copie l’archive puis remplace immédiatement les fichiers et la base présents par son contenu.</p>
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
        <?php wp_nonce_field( 'trq_action' ); ?>
        <input type="hidden" name="action" value="trq_action" />
        <input type="hidden" name="trq_tab" value="backups" />

        <table class="form-table" role="presentation">
            <tr>
                <th><label for="trq-backup-archive">Archive ZIP</label></th>
                <td>
                    <input type="file" id="trq-backup-archive" name="backup_archive" accept="application/zip,.zip" required />
                    <p class="description">Format attendu : archive ZIP 360 Tranquillité contenant site/ et/ou database.sql.</p>
                </td>
            </tr>
        </table>

        <p class="trq-submit">
            <button type="submit" name="trq_do" value="import_backup_archive" class="button button-secondary">Importer seulement</button>
            <button type="submit" name="trq_do" value="import_restore_backup_archive" class="button button-primary">Importer puis restaurer</button>
        </p>
    </form>
</div>

<?php if ( ! empty( $google_drive_status['connected'] ) ) : ?>
<div class="trq-section trq-backups-drive">
    <h2>Archives Google Drive</h2>
    <p class="description">Ces archives sont lues directement dans le dossier Google Drive actuellement connecté. Vous pouvez les importer sur le serveur sans téléchargement manuel, puis éventuellement les restaurer.</p>
    <?php if ( empty( $google_drive_backups ) ) : ?>
        <p>Aucune archive 360 Tranquillité détectée dans le dossier Google Drive sélectionné.</p>
    <?php else : ?>
        <table class="widefat striped trq-log-table">
            <thead>
                <tr><th>Archive Drive</th><th>Date</th><th>Taille</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ( $google_drive_backups as $backup ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $backup['name'] ); ?></code></td>
                        <td><?php echo ! empty( $backup['modified'] ) ? esc_html( wp_date( 'd/m/Y H:i', (int) $backup['modified'] ) ) : 'n/a'; ?></td>
                        <td><?php echo ! empty( $backup['size'] ) ? esc_html( size_format( (int) $backup['size'] ) ) : 'n/a'; ?></td>
                        <td>
                            <?php TRQ_Admin::action_form( 'import_google_drive_backup', [ 'file_id' => $backup['id'], 'file_name' => $backup['name'] ], 'backups', 'Importer seulement', 'button-secondary' ); ?>
                            <?php TRQ_Admin::action_form( 'import_restore_google_drive_backup', [ 'file_id' => $backup['id'], 'file_name' => $backup['name'] ], 'backups', 'Importer puis restaurer', 'button-primary' ); ?>
                            <?php TRQ_Admin::action_form( 'delete_google_drive_backup', [ 'file_id' => $backup['id'] ], 'backups', 'Supprimer de Drive', 'button-link-delete' ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="trq-section trq-backups-local">
    <h2>Archives locales</h2>
    <p class="description">La restauration remplace les fichiers présents dans WordPress par ceux de l’archive et réimporte database.sql si le dump est présent. Effectuez-la de préférence sur une préproduction ou pendant une fenêtre de maintenance.</p>
    <?php if ( empty( $local_backups ) ) : ?>
        <p>Aucune archive locale disponible. Tant qu’aucune archive locale n’existe ici, aucun bouton Restaurer ne peut apparaître.</p>
    <?php else : ?>
        <table class="widefat striped trq-log-table">
            <thead>
                <tr><th>Archive</th><th>Date</th><th>Taille</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ( $local_backups as $backup ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $backup['name'] ); ?></code></td>
                        <td><?php echo esc_html( wp_date( 'd/m/Y H:i', (int) $backup['modified'] ) ); ?></td>
                        <td><?php echo esc_html( size_format( (int) $backup['size'] ) ); ?></td>
                        <td>
                            <?php TRQ_Admin::action_form( 'download_backup', [ 'file' => $backup['name'] ], 'backups', 'Télécharger', 'button-secondary' ); ?>
                            <?php TRQ_Admin::action_form( 'restore_backup', [ 'file' => $backup['name'] ], 'backups', 'Restaurer', 'button-secondary' ); ?>
                            <?php TRQ_Admin::action_form( 'delete_backup', [ 'file' => $backup['name'] ], 'backups', 'Supprimer', 'button-link-delete' ); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>