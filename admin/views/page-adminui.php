<?php
/**
 * Vue : Interface Admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$core = TRQ_Core::get_instance();
?>

<div class="trq-section">
    <h2><?php esc_html_e( 'Interface Admin', '360tranquilite' ); ?></h2>
    <p><?php esc_html_e( 'Reglages de confort et d organisation de l administration WordPress.', '360tranquilite' ); ?></p>

    <?php TRQ_Admin::settings_form_open( 'adminui' ); ?>
    <table class="form-table" role="presentation">
        <tr>
            <th><?php esc_html_e( 'Activation globale', '360tranquilite' ); ?></th>
            <td><?php TRQ_Admin::toggle( 'toolkit_enabled', (bool) $core->get( 'toolkit_enabled', false ), __( 'Activer la boite a outils dev', '360tranquilite' ) ); ?></td>
        </tr>

        <tr><th colspan="2"><h3><?php esc_html_e( 'Nettoyage et ergonomie', '360tranquilite' ); ?></h3></th></tr>
        <tr>
            <th><?php esc_html_e( 'Notices et widgets', '360tranquilite' ); ?></th>
            <td>
                <?php TRQ_Admin::toggle( 'toolkit_hide_admin_notices', (bool) $core->get( 'toolkit_hide_admin_notices', false ), __( 'Masquer la plupart des notices admin', '360tranquilite' ) ); ?><br><br>
                <?php TRQ_Admin::toggle( 'toolkit_disable_dashboard_widgets', (bool) $core->get( 'toolkit_disable_dashboard_widgets', false ), __( 'Masquer les widgets natifs du tableau de bord', '360tranquilite' ) ); ?>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Barre admin (front)', '360tranquilite' ); ?></th>
            <td><?php TRQ_Admin::toggle( 'toolkit_hide_front_admin_bar', (bool) $core->get( 'toolkit_hide_front_admin_bar', false ), __( 'Masquer la barre admin cote front pour les non-admins', '360tranquilite' ) ); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Largeur menu admin', '360tranquilite' ); ?></th>
            <td>
                <input type="number" min="160" max="360" class="small-text" name="toolkit_admin_menu_width" value="<?php echo esc_attr( (string) (int) $core->get( 'toolkit_admin_menu_width', 160 ) ); ?>" /> px
                <p class="description"><?php esc_html_e( '160 = largeur WordPress par defaut.', '360tranquilite' ); ?></p>
            </td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Organisateur menu admin', '360tranquilite' ); ?></th>
            <td>
                <?php TRQ_Admin::toggle( 'toolkit_admin_menu_cleanup_enabled', (bool) $core->get( 'toolkit_admin_menu_cleanup_enabled', false ), __( 'Activer le masquage de menus admin', '360tranquilite' ) ); ?><br><br>
                <label for="trq_toolkit_admin_menu_hidden_slugs"><?php esc_html_e( 'Menus a masquer (CSV de slugs)', '360tranquilite' ); ?></label><br>
                <input id="trq_toolkit_admin_menu_hidden_slugs" type="text" class="regular-text" name="toolkit_admin_menu_hidden_slugs" value="<?php echo esc_attr( (string) $core->get( 'toolkit_admin_menu_hidden_slugs', '' ) ); ?>" placeholder="edit.php,upload.php,tools.php,plugins.php" />
                <p class="description"><?php esc_html_e( 'Exemple: edit.php,upload.php,edit-comments.php,tools.php,plugins.php,users.php', '360tranquilite' ); ?></p>

                <?php TRQ_Admin::toggle( 'toolkit_admin_menu_reorder_enabled', (bool) $core->get( 'toolkit_admin_menu_reorder_enabled', false ), __( 'Activer le reordonnancement du menu admin', '360tranquilite' ) ); ?><br><br>
                <label for="trq_toolkit_admin_menu_order"><?php esc_html_e( 'Ordre menu (CSV de slugs)', '360tranquilite' ); ?></label><br>
                <input id="trq_toolkit_admin_menu_order" type="text" class="regular-text" name="toolkit_admin_menu_order" value="<?php echo esc_attr( (string) $core->get( 'toolkit_admin_menu_order', '' ) ); ?>" placeholder="index.php,edit.php,upload.php,pages.php,themes.php,plugins.php" />
                <p class="description"><?php esc_html_e( 'Les slugs non listés restent à la fin dans leur ordre natif.', '360tranquilite' ); ?></p>
            </td>
        </tr>

        <tr><th colspan="2"><h3><?php esc_html_e( 'Barre admin (haut)', '360tranquilite' ); ?></h3></th></tr>
        <tr>
            <th><?php esc_html_e( 'Nettoyage barre admin', '360tranquilite' ); ?></th>
            <td>
                <?php TRQ_Admin::toggle( 'toolkit_admin_bar_cleanup_enabled', (bool) $core->get( 'toolkit_admin_bar_cleanup_enabled', false ), __( 'Activer le nettoyage personnalise', '360tranquilite' ) ); ?><br><br>
                <?php TRQ_Admin::toggle( 'toolkit_admin_bar_remove_wp_logo', (bool) $core->get( 'toolkit_admin_bar_remove_wp_logo', false ), __( 'Retirer le logo WordPress', '360tranquilite' ) ); ?><br><br>
                <?php TRQ_Admin::toggle( 'toolkit_admin_bar_remove_comments', (bool) $core->get( 'toolkit_admin_bar_remove_comments', false ), __( 'Retirer le menu Commentaires', '360tranquilite' ) ); ?><br><br>
                <?php TRQ_Admin::toggle( 'toolkit_admin_bar_remove_new_content', (bool) $core->get( 'toolkit_admin_bar_remove_new_content', false ), __( 'Retirer le menu + Nouveau', '360tranquilite' ) ); ?><br><br>
                <?php TRQ_Admin::toggle( 'toolkit_admin_bar_remove_updates', (bool) $core->get( 'toolkit_admin_bar_remove_updates', false ), __( 'Retirer le compteur de mises a jour', '360tranquilite' ) ); ?>
            </td>
        </tr>

        <tr><th colspan="2"><h3><?php esc_html_e( 'Footer admin', '360tranquilite' ); ?></h3></th></tr>
        <tr>
            <th><?php esc_html_e( 'Texte personalisable', '360tranquilite' ); ?></th>
            <td>
                <?php TRQ_Admin::toggle( 'toolkit_admin_footer_text_enabled', (bool) $core->get( 'toolkit_admin_footer_text_enabled', false ), __( 'Activer un texte footer personnalise', '360tranquilite' ) ); ?><br><br>
                <input type="text" class="regular-text" name="toolkit_admin_footer_text" value="<?php echo esc_attr( (string) $core->get( 'toolkit_admin_footer_text', '' ) ); ?>" placeholder="Equipe Editoriale" />
            </td>
        </tr>

        <tr><th colspan="2"><h3><?php esc_html_e( 'Colonnes et filtres', '360tranquilite' ); ?></h3></th></tr>
        <tr>
            <th><?php esc_html_e( 'Colonnes de contenus', '360tranquilite' ); ?></th>
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
        <tr>
            <th><?php esc_html_e( 'Liste des utilisateurs', '360tranquilite' ); ?></th>
            <td><?php TRQ_Admin::toggle( 'toolkit_users_last_login_column', (bool) $core->get( 'toolkit_users_last_login_column', false ), __( 'Ajouter la colonne Derniere connexion', '360tranquilite' ) ); ?></td>
        </tr>
        <tr>
            <th><?php esc_html_e( 'Filtres taxonomies', '360tranquilite' ); ?></th>
            <td>
                <?php TRQ_Admin::toggle( 'toolkit_taxonomy_filters_enabled', (bool) $core->get( 'toolkit_taxonomy_filters_enabled', false ), __( 'Ajouter des filtres taxonomies dans les listes de contenus', '360tranquilite' ) ); ?><br><br>
                <label for="trq_toolkit_taxonomy_filters_post_types"><?php esc_html_e( 'Types de contenus cibles (CSV)', '360tranquilite' ); ?></label><br>
                <input id="trq_toolkit_taxonomy_filters_post_types" type="text" class="regular-text" name="toolkit_taxonomy_filters_post_types" value="<?php echo esc_attr( (string) $core->get( 'toolkit_taxonomy_filters_post_types', 'post,page' ) ); ?>" placeholder="post,page,resource" />
                <br><br>
                <?php TRQ_Admin::toggle( 'toolkit_taxonomy_terms_order_enabled', (bool) $core->get( 'toolkit_taxonomy_terms_order_enabled', false ), __( 'Activer le tri global des termes de taxonomie', '360tranquilite' ) ); ?><br><br>
                <label><?php esc_html_e( 'Trier les termes par', '360tranquilite' ); ?></label>
                <select name="toolkit_taxonomy_terms_orderby">
                    <option value="name" <?php selected( $core->get( 'toolkit_taxonomy_terms_orderby', 'name' ), 'name' ); ?>><?php esc_html_e( 'Nom', '360tranquilite' ); ?></option>
                    <option value="slug" <?php selected( $core->get( 'toolkit_taxonomy_terms_orderby', 'name' ), 'slug' ); ?>>Slug</option>
                    <option value="count" <?php selected( $core->get( 'toolkit_taxonomy_terms_orderby', 'name' ), 'count' ); ?>><?php esc_html_e( 'Nombre', '360tranquilite' ); ?></option>
                    <option value="term_id" <?php selected( $core->get( 'toolkit_taxonomy_terms_orderby', 'name' ), 'term_id' ); ?>>ID</option>
                </select>
                <select name="toolkit_taxonomy_terms_order">
                    <option value="ASC" <?php selected( $core->get( 'toolkit_taxonomy_terms_order', 'ASC' ), 'ASC' ); ?>>ASC</option>
                    <option value="DESC" <?php selected( $core->get( 'toolkit_taxonomy_terms_order', 'ASC' ), 'DESC' ); ?>>DESC</option>
                </select>
            </td>
        </tr>
    </table>
    <?php TRQ_Admin::settings_form_close(); ?>
</div>
