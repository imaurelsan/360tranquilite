<?php
/**
 * Vue : À propos
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$logo_url = TRQ_Admin::get_logo_url();
?>

<div class="trq-section trq-about-hero-section">
    <div class="trq-about-hero">
        <div>
            <?php if ( $logo_url ) : ?>
                <img src="<?php echo esc_url( $logo_url ); ?>" alt="360 Tranquillité" class="trq-about-logo" />
            <?php else : ?>
                <div class="trq-about-logo" style="display:flex;align-items:center;justify-content:center;font-size:60px;">🛡️</div>
            <?php endif; ?>
        </div>
        <div class="trq-about-meta">
            <h2>360 Tranquillité</h2>
            <p>Plugin de sécurité WordPress tout-en-un conçu pour réduire la surface d'attaque d'un site, centraliser les protections critiques et simplifier l'exploitation quotidienne.</p>
            <p><strong>Version :</strong> <?php echo esc_html( TRQ_VERSION ); ?></p>
            <p><strong>Auteur :</strong> Aurel Yahouedeou</p>
            <p><strong>Site :</strong> <a href="https://yaurel.com" target="_blank" rel="noopener">https://yaurel.com</a></p>
            <p><strong>Support :</strong> <a href="mailto:aurelandyou@gmail.com">aurelandyou@gmail.com</a></p>
        </div>
    </div>
</div>

<div class="trq-about-grid">
    <div class="trq-about-card">
        <h3>Ce que protège le plugin</h3>
        <ul class="trq-feature-list">
            <li>Firewall applicatif contre plusieurs patterns d'attaque</li>
            <li>Protection de la connexion et anti-brute-force</li>
            <li>Double authentification TOTP</li>
            <li>Intégration Cloudflare et synchronisation des blocages</li>
            <li>Surveillance de l'intégrité des fichiers</li>
            <li>Durcissement WordPress et anti-spam</li>
        </ul>
    </div>
    <div class="trq-about-card">
        <h3>Comment le plugin fonctionne</h3>
        <ul class="trq-feature-list">
            <li>Il initialise ses modules selon vos réglages enregistrés.</li>
            <li>Il applique les protections en amont sur les points sensibles: requêtes, login, headers, uploads.</li>
            <li>Il journalise les événements critiques (firewall, audit, scans) pour faciliter l'analyse.</li>
            <li>Il planifie les tâches récurrentes via WP-Cron: scans, sauvegardes et maintenance.</li>
            <li>Il centralise la configuration dans des onglets dédiés avec une logique progressive: activer seulement l'utile.</li>
        </ul>
        <p>Objectif: améliorer nettement la résilience du site sans alourdir l'administration quotidienne.</p>
    </div>
    <div class="trq-about-card">
        <h3>Support</h3>
        <p>Pour les demandes de support, incidents, retours ou demandes d'évolution :</p>
        <p><a href="mailto:aurelandyou@gmail.com">aurelandyou@gmail.com</a></p>
        <p>Quand le plugin sera distribué plus largement, ce contact permettra aussi de centraliser les retours utilisateurs.</p>
    </div>
    <div class="trq-about-card">
        <h3>Changelog</h3>
        <p>Le changelog sert à documenter publiquement les versions du plugin, les corrections, les nouvelles fonctionnalités et les changements potentiellement sensibles.</p>
        <p>Il est utile pour vous, mais aussi pour toute personne qui installe le plugin, afin de savoir ce qui a changé d'une version à l'autre.</p>
        <p><a href="<?php echo esc_url( TRQ_PLUGIN_URL . 'CHANGELOG.md' ); ?>" target="_blank" rel="noopener">Consulter le changelog public</a></p>
    </div>
</div>