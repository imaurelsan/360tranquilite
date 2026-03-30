<?php
/**
 * Vue : Boite a Outils Dev.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$core = TRQ_Core::get_instance();

$default_cpt_json = "[\n  {\n    \"post_type\": \"resource\",\n    \"singular\": \"Resource\",\n    \"plural\": \"Resources\",\n    \"public\": true,\n    \"show_in_rest\": true,\n    \"has_archive\": true,\n    \"supports\": [\"title\", \"editor\", \"thumbnail\"]\n  }\n]";

$default_tax_json = "[\n  {\n    \"taxonomy\": \"resource_topic\",\n    \"singular\": \"Topic\",\n    \"plural\": \"Topics\",\n    \"post_types\": [\"resource\"],\n    \"hierarchical\": true,\n    \"show_in_rest\": true\n  }\n]";
?>

<div class="trq-section">
    <h2><?php esc_html_e( 'Boite a Outils Dev', '360tranquilite' ); ?></h2>
    <p><?php esc_html_e( 'Fonctions utiles pour limiter les plugins additionnels. Activez seulement ce dont vous avez besoin.', '360tranquilite' ); ?></p>

    <?php TRQ_Admin::settings_form_open( 'toolkit' ); ?>
    <table class="form-table" role="presentation">
        <tr>
            <th><?php esc_html_e( 'Activation globale', '360tranquilite' ); ?></th>
            <td>
                <?php TRQ_Admin::toggle( 'toolkit_enabled', (bool) $core->get( 'toolkit_enabled', false ), __( 'Activer la boite a outils dev', '360tranquilite' ) ); ?>
            </td>
        </tr>

        <tr><th colspan="2"><h3><?php esc_html_e( 'Contenu', '360tranquilite' ); ?></h3></th></tr>
        <tr>
            <th><?php esc_html_e( 'Duplication de contenu', '360tranquilite' ); ?></th>
            <td><?php TRQ_Admin::toggle( 'toolkit_duplicate_content', (bool) $core->get( 'toolkit_duplicate_content', true ), __( 'Ajouter un lien Dupliquer dans les listes de contenus', '360tranquilite' ) ); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Televersements', '360tranquilite' ); ?></th>
            <td>
                <?php TRQ_Admin::toggle( 'toolkit_allow_svg', (bool) $core->get( 'toolkit_allow_svg', true ), __( 'Autoriser SVG', '360tranquilite' ) ); ?><br><br>
                <?php TRQ_Admin::toggle( 'toolkit_allow_avif', (bool) $core->get( 'toolkit_allow_avif', true ), __( 'Autoriser AVIF', '360tranquilite' ) ); ?>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Media Replacer mediatheque', '360tranquilite' ); ?></th>
            <td>
                <?php TRQ_Admin::toggle( 'toolkit_media_replacer_enabled', (bool) $core->get( 'toolkit_media_replacer_enabled', false ), __( 'Ajouter l action Remplacer fichier pour chaque media', '360tranquilite' ) ); ?>
                <p class="description"><?php esc_html_e( 'Le remplacement se fait ensuite dans la fiche de la piece jointe (et conserve ID/URLs si extension identique).', '360tranquilite' ); ?></p>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Constructeur CPT/Taxonomies', '360tranquilite' ); ?></th>
            <td>
                <?php TRQ_Admin::toggle( 'toolkit_cpt_builder_enabled', (bool) $core->get( 'toolkit_cpt_builder_enabled', false ), __( 'Activer l enregistrement dynamique de CPT et taxonomies', '360tranquilite' ) ); ?>
                <p class="description"><?php esc_html_e( 'Format JSON: un objet par element. Les erreurs JSON sont ignorees en silence.', '360tranquilite' ); ?></p>

                <label for="trq_toolkit_cpts_json"><strong><?php esc_html_e( 'CPT (JSON)', '360tranquilite' ); ?></strong></label>
                <textarea id="trq_toolkit_cpts_json" class="large-text code" rows="8" name="toolkit_cpts_json"><?php echo esc_textarea( (string) $core->get( 'toolkit_cpts_json', $default_cpt_json ) ); ?></textarea>

                <label for="trq_toolkit_taxonomies_json"><strong><?php esc_html_e( 'Taxonomies (JSON)', '360tranquilite' ); ?></strong></label>
                <textarea id="trq_toolkit_taxonomies_json" class="large-text code" rows="8" name="toolkit_taxonomies_json"><?php echo esc_textarea( (string) $core->get( 'toolkit_taxonomies_json', $default_tax_json ) ); ?></textarea>
            </td>
        </tr>

        <tr><th colspan="2"><h3><?php esc_html_e( 'Interface admin', '360tranquilite' ); ?></h3></th></tr>
        <tr>
            <th><?php esc_html_e( 'Nettoyage admin', '360tranquilite' ); ?></th>
            <td>
                <?php TRQ_Admin::toggle( 'toolkit_hide_admin_notices', (bool) $core->get( 'toolkit_hide_admin_notices', false ), __( 'Masquer la plupart des notices admin', '360tranquilite' ) ); ?><br><br>
                <?php TRQ_Admin::toggle( 'toolkit_disable_dashboard_widgets', (bool) $core->get( 'toolkit_disable_dashboard_widgets', false ), __( 'Masquer les widgets natifs du tableau de bord', '360tranquilite' ) ); ?>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Colonnes admin', '360tranquilite' ); ?></th>
            <td>
                <?php TRQ_Admin::toggle( 'toolkit_admin_columns_enabled', (bool) $core->get( 'toolkit_admin_columns_enabled', false ), __( 'Activer les colonnes personnalisees', '360tranquilite' ) ); ?><br><br>
                <label for="trq_toolkit_admin_columns_post_types"><?php esc_html_e( 'Types de contenus (CSV)', '360tranquilite' ); ?></label><br>
                <input id="trq_toolkit_admin_columns_post_types" type="text" class="regular-text" name="toolkit_admin_columns_post_types" value="<?php echo esc_attr( (string) $core->get( 'toolkit_admin_columns_post_types', 'post,page' ) ); ?>" placeholder="post,page,resource" />
                <p class="description"><?php esc_html_e( 'Exemple: post,page,resource', '360tranquilite' ); ?></p>

                <?php TRQ_Admin::toggle( 'toolkit_admin_column_id', (bool) $core->get( 'toolkit_admin_column_id', true ), __( 'Afficher la colonne ID', '360tranquilite' ) ); ?><br><br>
                <?php TRQ_Admin::toggle( 'toolkit_admin_column_thumbnail', (bool) $core->get( 'toolkit_admin_column_thumbnail', true ), __( 'Afficher la colonne image', '360tranquilite' ) ); ?><br><br>
                <?php TRQ_Admin::toggle( 'toolkit_admin_column_slug', (bool) $core->get( 'toolkit_admin_column_slug', true ), __( 'Afficher la colonne slug', '360tranquilite' ) ); ?><br><br>
                <?php TRQ_Admin::toggle( 'toolkit_admin_column_modified', (bool) $core->get( 'toolkit_admin_column_modified', true ), __( 'Afficher la colonne date de modification', '360tranquilite' ) ); ?>
            </td>
        </tr>

        <tr><th colspan="2"><h3><?php esc_html_e( 'Redirections de session', '360tranquilite' ); ?></h3></th></tr>
        <tr>
            <th><?php esc_html_e( 'Redirection apres connexion', '360tranquilite' ); ?></th>
            <td>
                <?php TRQ_Admin::toggle( 'toolkit_login_redirect_enabled', (bool) $core->get( 'toolkit_login_redirect_enabled', false ), __( 'Activer une redirection apres authentification reussie', '360tranquilite' ) ); ?><br><br>
                <input type="url" class="regular-text" name="toolkit_login_redirect_url" value="<?php echo esc_attr( (string) $core->get( 'toolkit_login_redirect_url', '' ) ); ?>" placeholder="https://example.com/mon-espace" />
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Redirection apres deconnexion', '360tranquilite' ); ?></th>
            <td>
                <?php TRQ_Admin::toggle( 'toolkit_logout_redirect_enabled', (bool) $core->get( 'toolkit_logout_redirect_enabled', false ), __( 'Activer une redirection apres deconnexion', '360tranquilite' ) ); ?><br><br>
                <input type="url" class="regular-text" name="toolkit_logout_redirect_url" value="<?php echo esc_attr( (string) $core->get( 'toolkit_logout_redirect_url', '' ) ); ?>" placeholder="https://example.com/" />
            </td>
        </tr>

        <tr><th colspan="2"><h3><?php esc_html_e( 'SMTP', '360tranquilite' ); ?></h3></th></tr>
        <tr>
            <th><?php esc_html_e( 'Configuration SMTP', '360tranquilite' ); ?></th>
            <td>
                <?php TRQ_Admin::toggle( 'toolkit_smtp_enabled', (bool) $core->get( 'toolkit_smtp_enabled', false ), __( 'Activer SMTP', '360tranquilite' ) ); ?><br><br>
                <?php TRQ_Admin::toggle( 'toolkit_smtp_auth', (bool) $core->get( 'toolkit_smtp_auth', true ), __( 'Authentification SMTP', '360tranquilite' ) ); ?><br><br>

                <label for="trq_toolkit_smtp_host"><?php esc_html_e( 'Serveur SMTP', '360tranquilite' ); ?></label><br>
                <input id="trq_toolkit_smtp_host" type="text" class="regular-text" name="toolkit_smtp_host" value="<?php echo esc_attr( (string) $core->get( 'toolkit_smtp_host', '' ) ); ?>" placeholder="smtp.example.com" />
                <br><br>

                <label for="trq_toolkit_smtp_port"><?php esc_html_e( 'Port', '360tranquilite' ); ?></label><br>
                <input id="trq_toolkit_smtp_port" type="number" min="1" max="65535" class="small-text" name="toolkit_smtp_port" value="<?php echo esc_attr( (string) (int) $core->get( 'toolkit_smtp_port', 587 ) ); ?>" />
                <br><br>

                <label for="trq_toolkit_smtp_secure"><?php esc_html_e( 'Chiffrement', '360tranquilite' ); ?></label><br>
                <select id="trq_toolkit_smtp_secure" name="toolkit_smtp_secure">
                    <option value="none" <?php selected( $core->get( 'toolkit_smtp_secure', 'tls' ), 'none' ); ?>><?php esc_html_e( 'Aucun', '360tranquilite' ); ?></option>
                    <option value="ssl" <?php selected( $core->get( 'toolkit_smtp_secure', 'tls' ), 'ssl' ); ?>>SSL</option>
                    <option value="tls" <?php selected( $core->get( 'toolkit_smtp_secure', 'tls' ), 'tls' ); ?>>TLS</option>
                </select>
                <br><br>

                <label for="trq_toolkit_smtp_user"><?php esc_html_e( 'Utilisateur SMTP', '360tranquilite' ); ?></label><br>
                <input id="trq_toolkit_smtp_user" type="text" class="regular-text" name="toolkit_smtp_user" value="<?php echo esc_attr( (string) $core->get( 'toolkit_smtp_user', '' ) ); ?>" />
                <br><br>

                <label for="trq_toolkit_smtp_pass"><?php esc_html_e( 'Mot de passe SMTP', '360tranquilite' ); ?></label><br>
                <input id="trq_toolkit_smtp_pass" type="password" class="regular-text" name="toolkit_smtp_pass" value="" autocomplete="new-password" placeholder="********" />
                <p class="description"><?php esc_html_e( 'Laissez vide pour conserver le mot de passe existant.', '360tranquilite' ); ?></p>

                <label for="trq_toolkit_smtp_from_email"><?php esc_html_e( 'Email expediteur', '360tranquilite' ); ?></label><br>
                <input id="trq_toolkit_smtp_from_email" type="email" class="regular-text" name="toolkit_smtp_from_email" value="<?php echo esc_attr( (string) $core->get( 'toolkit_smtp_from_email', '' ) ); ?>" placeholder="no-reply@example.com" />
                <br><br>

                <label for="trq_toolkit_smtp_from_name"><?php esc_html_e( 'Nom expediteur', '360tranquilite' ); ?></label><br>
                <input id="trq_toolkit_smtp_from_name" type="text" class="regular-text" name="toolkit_smtp_from_name" value="<?php echo esc_attr( (string) $core->get( 'toolkit_smtp_from_name', '' ) ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
            </td>
        </tr>

        <tr><th colspan="2"><h3><?php esc_html_e( 'Code personnalise', '360tranquilite' ); ?></h3></th></tr>
        <tr>
            <th><?php esc_html_e( 'CSS Admin', '360tranquilite' ); ?></th>
            <td><textarea class="large-text code" rows="5" name="toolkit_admin_css"><?php echo esc_textarea( (string) $core->get( 'toolkit_admin_css', '' ) ); ?></textarea></td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'CSS Frontend', '360tranquilite' ); ?></th>
            <td><textarea class="large-text code" rows="5" name="toolkit_front_css"><?php echo esc_textarea( (string) $core->get( 'toolkit_front_css', '' ) ); ?></textarea></td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Insertion HEAD', '360tranquilite' ); ?></th>
            <td><textarea class="large-text code" rows="5" name="toolkit_head_code"><?php echo esc_textarea( (string) $core->get( 'toolkit_head_code', '' ) ); ?></textarea></td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Insertion FOOTER', '360tranquilite' ); ?></th>
            <td><textarea class="large-text code" rows="5" name="toolkit_footer_code"><?php echo esc_textarea( (string) $core->get( 'toolkit_footer_code', '' ) ); ?></textarea></td>
        </tr>
        <tr>
            <th>robots.txt</th>
            <td><textarea class="large-text code" rows="5" name="toolkit_robots_txt"><?php echo esc_textarea( (string) $core->get( 'toolkit_robots_txt', '' ) ); ?></textarea></td>
        </tr>
        <tr>
            <th>ads.txt / app-ads.txt</th>
            <td>
                <label for="toolkit_ads_txt">ads.txt</label>
                <textarea class="large-text code" rows="4" name="toolkit_ads_txt"><?php echo esc_textarea( (string) $core->get( 'toolkit_ads_txt', '' ) ); ?></textarea>
                <br><br>
                <label for="toolkit_app_ads_txt">app-ads.txt</label>
                <textarea class="large-text code" rows="4" name="toolkit_app_ads_txt"><?php echo esc_textarea( (string) $core->get( 'toolkit_app_ads_txt', '' ) ); ?></textarea>
            </td>
        </tr>

        <tr><th colspan="2"><h3><?php esc_html_e( 'Securite / Optimisation', '360tranquilite' ); ?></h3></th></tr>
        <tr>
            <th><?php esc_html_e( 'Obfuscation email', '360tranquilite' ); ?></th>
            <td><?php TRQ_Admin::toggle( 'toolkit_email_obfuscation', (bool) $core->get( 'toolkit_email_obfuscation', false ), __( 'Obfusquer les emails trouves dans le contenu', '360tranquilite' ) ); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Revisions', '360tranquilite' ); ?></th>
            <td>
                <input type="number" min="0" max="100" class="small-text" name="toolkit_revisions_limit" value="<?php echo esc_attr( (string) (int) $core->get( 'toolkit_revisions_limit', 10 ) ); ?>" />
                <p class="description"><?php esc_html_e( '0 desactive les revisions.', '360tranquilite' ); ?></p>
            </td>
        </tr>
        <tr>
            <th>Heartbeat</th>
            <td>
                <label><input type="radio" name="toolkit_heartbeat_mode" value="default" <?php checked( $core->get( 'toolkit_heartbeat_mode', 'default' ), 'default' ); ?> /> <?php esc_html_e( 'Normal', '360tranquilite' ); ?></label><br>
                <label><input type="radio" name="toolkit_heartbeat_mode" value="reduced" <?php checked( $core->get( 'toolkit_heartbeat_mode', 'default' ), 'reduced' ); ?> /> <?php esc_html_e( 'Reduit', '360tranquilite' ); ?></label><br>
                <label><input type="radio" name="toolkit_heartbeat_mode" value="disabled" <?php checked( $core->get( 'toolkit_heartbeat_mode', 'default' ), 'disabled' ); ?> /> <?php esc_html_e( 'Minimal (forte reduction)', '360tranquilite' ); ?></label>
            </td>
        </tr>

        <tr><th colspan="2"><h3><?php esc_html_e( 'Maintenance', '360tranquilite' ); ?></h3></th></tr>
        <tr>
            <th><?php esc_html_e( 'Mode maintenance', '360tranquilite' ); ?></th>
            <td>
                <?php TRQ_Admin::toggle( 'toolkit_maintenance_mode', (bool) $core->get( 'toolkit_maintenance_mode', false ), __( 'Activer le mode maintenance', '360tranquilite' ) ); ?><br><br>
                <input type="text" class="regular-text" name="toolkit_maintenance_message" value="<?php echo esc_attr( (string) $core->get( 'toolkit_maintenance_message', __( 'Site en maintenance, revenez bientot.', '360tranquilite' ) ) ); ?>" />
            </td>
        </tr>

        <tr><th colspan="2"><h3><?php esc_html_e( 'Langue du plugin', '360tranquilite' ); ?></h3></th></tr>
        <tr>
            <th><?php esc_html_e( 'Langue', '360tranquilite' ); ?></th>
            <td>
                <select name="plugin_language">
                    <option value="auto" <?php selected( $core->get( 'plugin_language', 'auto' ), 'auto' ); ?>><?php esc_html_e( 'Automatique (langue WordPress)', '360tranquilite' ); ?></option>
                    <option value="fr_FR" <?php selected( $core->get( 'plugin_language', 'auto' ), 'fr_FR' ); ?>><?php esc_html_e( 'Francais', '360tranquilite' ); ?></option>
                    <option value="en_US" <?php selected( $core->get( 'plugin_language', 'auto' ), 'en_US' ); ?>>English</option>
                </select>
                <p class="description"><?php esc_html_e( 'Selectionnez la langue forcee du plugin ou laissez automatique.', '360tranquilite' ); ?></p>
            </td>
        </tr>
    </table>
    <?php TRQ_Admin::settings_form_close(); ?>
</div>

