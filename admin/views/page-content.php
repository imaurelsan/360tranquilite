<?php
/**
 * Vue : Contenu.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$core = TRQ_Core::get_instance();

$default_cpt_json = "[\n  {\n    \"post_type\": \"resource\",\n    \"singular\": \"Resource\",\n    \"plural\": \"Resources\",\n    \"public\": true,\n    \"show_in_rest\": true,\n    \"has_archive\": true,\n    \"supports\": [\"title\", \"editor\", \"thumbnail\"]\n  }\n]";

$default_tax_json = "[\n  {\n    \"taxonomy\": \"resource_topic\",\n    \"singular\": \"Topic\",\n    \"plural\": \"Topics\",\n    \"post_types\": [\"resource\"],\n    \"hierarchical\": true,\n    \"show_in_rest\": true\n  }\n]";
?>

<div class="trq-section">
    <h2><?php esc_html_e( 'Contenu', '360tranquilite' ); ?></h2>
    <p><?php esc_html_e( 'Fonctions liees aux contenus, taxonomies, liens et publication.', '360tranquilite' ); ?></p>

    <?php TRQ_Admin::settings_form_open( 'content' ); ?>
    <table class="form-table" role="presentation">
        <tr>
            <th><?php esc_html_e( 'Activation globale', '360tranquilite' ); ?></th>
            <td><?php TRQ_Admin::toggle( 'toolkit_enabled', (bool) $core->get( 'toolkit_enabled', false ), __( 'Activer la boite a outils dev', '360tranquilite' ) ); ?></td>
        </tr>

        <tr><th colspan="2"><h3><?php esc_html_e( 'Edition de contenu', '360tranquilite' ); ?></h3></th></tr>
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
                <p class="description"><?php esc_html_e( 'Le remplacement se fait ensuite dans la fiche de la piece jointe (ID et URLs conserves si extension identique).', '360tranquilite' ); ?></p>
            </td>
        </tr>

        <tr><th colspan="2"><h3><?php esc_html_e( 'Structure de contenu', '360tranquilite' ); ?></h3></th></tr>
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

        <tr><th colspan="2"><h3><?php esc_html_e( 'Liens et permaliens', '360tranquilite' ); ?></h3></th></tr>
        <tr>
            <th><?php esc_html_e( 'Permaliens externes', '360tranquilite' ); ?></th>
            <td>
                <?php TRQ_Admin::toggle( 'toolkit_external_permalink_enabled', (bool) $core->get( 'toolkit_external_permalink_enabled', false ), __( 'Activer le champ URL externe sur les contenus', '360tranquilite' ) ); ?><br><br>
                <?php TRQ_Admin::toggle( 'toolkit_external_permalink_new_tab', (bool) $core->get( 'toolkit_external_permalink_new_tab', false ), __( 'Ouvrir les permaliens externes dans un nouvel onglet (menus/listes)', '360tranquilite' ) ); ?>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Liens externes dans le contenu', '360tranquilite' ); ?></th>
            <td>
                <?php TRQ_Admin::toggle( 'toolkit_external_links_new_tab', (bool) $core->get( 'toolkit_external_links_new_tab', false ), __( 'Forcer target=_blank', '360tranquilite' ) ); ?><br><br>
                <?php TRQ_Admin::toggle( 'toolkit_external_links_nofollow', (bool) $core->get( 'toolkit_external_links_nofollow', false ), __( 'Ajouter rel=nofollow', '360tranquilite' ) ); ?>
            </td>
        </tr>

        <tr><th colspan="2"><h3><?php esc_html_e( 'Publication', '360tranquilite' ); ?></h3></th></tr>
        <tr>
            <th><?php esc_html_e( 'Commentaires et flux', '360tranquilite' ); ?></th>
            <td>
                <?php TRQ_Admin::toggle( 'toolkit_disable_comments', (bool) $core->get( 'toolkit_disable_comments', false ), __( 'Desactiver les commentaires globalement', '360tranquilite' ) ); ?><br><br>
                <?php TRQ_Admin::toggle( 'toolkit_disable_feeds', (bool) $core->get( 'toolkit_disable_feeds', false ), __( 'Desactiver les flux RSS/Atom', '360tranquilite' ) ); ?>
            </td>
        </tr>

        <tr><th colspan="2"><h3><?php esc_html_e( 'SEO et staging', '360tranquilite' ); ?></h3></th></tr>
        <tr>
            <th><?php esc_html_e( 'Noindex automatique en staging', '360tranquilite' ); ?></th>
            <td>
                <?php TRQ_Admin::toggle( 'toolkit_staging_noindex_enabled', (bool) $core->get( 'toolkit_staging_noindex_enabled', false ), __( 'Forcer noindex/nofollow si le site ressemble a un environnement staging/dev', '360tranquilite' ) ); ?><br><br>
                <label for="trq_toolkit_staging_patterns"><?php esc_html_e( 'Patterns hote (CSV)', '360tranquilite' ); ?></label><br>
                <input id="trq_toolkit_staging_patterns" type="text" class="regular-text" name="toolkit_staging_patterns" value="<?php echo esc_attr( (string) $core->get( 'toolkit_staging_patterns', 'staging.,dev.,localhost,.local,.test' ) ); ?>" placeholder="staging.,dev.,localhost,.local,.test" />
                <p class="description"><?php esc_html_e( 'Detection sur le nom d hote. Exemple: staging.,dev.,localhost,.local,.test', '360tranquilite' ); ?></p>
                <?php TRQ_Admin::toggle( 'toolkit_staging_set_blog_public_zero', (bool) $core->get( 'toolkit_staging_set_blog_public_zero', false ), __( 'En staging detecte, passer automatiquement le réglage WordPress "Demander aux moteurs de recherche de ne pas indexer"', '360tranquilite' ) ); ?>
            </td>
        </tr>
    </table>
    <?php TRQ_Admin::settings_form_close(); ?>
</div>
