# 360 Tranquillité — Plugin de sécurité WordPress tout-en-un

Propriétaire : Aurel Yahouedeou

Site : https://yaurel.com

Support : aurelandyou@gmail.com

## Installation

1. **Copiez** ce dossier dans `wp-content/plugins/360tranquilite/`
2. Dans wp-admin, allez dans **Extensions → Extensions installées**
3. Activez **360 Tranquillité**
4. Allez dans **🛡️ 360 Tranquillité** dans le menu latéral

### Mises à jour automatiques via GitHub Releases

Le plugin peut récupérer ses nouvelles versions depuis GitHub Releases (utile avant une publication WordPress.org).

Configuration côté site WordPress (dans `wp-config.php`) :

```php
define( 'TRQ_GITHUB_UPDATES_REPO', 'votre-org-ou-user/360tranquilite' );
define( 'TRQ_GITHUB_UPDATES_BRANCH', 'main' );
define( 'TRQ_GITHUB_UPDATES_ASSET', '360tranquilite.zip' );
define( 'TRQ_GITHUB_UPDATES_TESTED', '6.8' );
```

Notes importantes :

1. Le repo doit publier des Releases avec un asset ZIP installable.
2. Le ZIP doit contenir le dossier racine `360tranquilite/` et le fichier `360tranquilite.php` à la racine de ce dossier.
3. Les testeurs devront faire une dernière mise à jour manuelle pour embarquer ce système, ensuite les updates pourront apparaître automatiquement dans wp-admin.

Workflow de release recommandé :

1. Incrémenter la version du plugin (`360tranquilite.php` + `CHANGELOG.md`)
2. Commit et push sur GitHub
3. Créer un tag (ex: `v1.8.2`)
4. Publier une GitHub Release sur ce tag
5. Joindre l’asset `360tranquilite.zip`
6. Demander aux testeurs de lancer la vérification des mises à jour dans WordPress

Script local pour générer le ZIP release (Windows PowerShell) :

```powershell
pwsh -File .\scripts\build-release-zip.ps1
```

Le script produit `dist/360tranquilite.zip` avec une structure WordPress correcte.

### Langue du plugin

- En mode **Auto**, le plugin suit la langue WordPress du site.
- La priorité de traduction actuelle est **français** et **anglais**.
- Les autres locales WordPress retombent provisoirement sur les chaînes intégrées tant que leur catalogue n’a pas encore été ajouté.

---

## Modules inclus

| Module | Description |
|--------|-------------|
| **Pare-feu (WAF)** | Bloque SQLi, XSS, path traversal, RCE, LFI, bots malveillants |
| **Login URL custom** | Masque `wp-login.php` avec un slug secret configurable + personnalisation visuelle complète de la page de connexion (logo, couleurs, CSS) |
| **Anti-brute-force** | Lockout IP après N tentatives, durée configurable |
| **2FA TOTP** | Double authentification compatible Google Authenticator / Authy |
| **Cloudflare** | IP réelle, API pour blocage et purge cache |
| **Cloudflare Token** | Support des API Tokens sécurisés, recommandé à la place de la Global API Key |
| **Sauvegardes** | Sauvegardes complètes ou incrémentales, locales ou cloud, avec planification, rétention et restauration locale |
| **Headers sécurité** | HSTS, CSP, X-Frame-Options, nosniff, etc. |
| **File Monitor** | Checksums SHA-256, scan quotidien, baseline, signaux suspects, quarantaine manuelle |
| **System Scan** | Checksums officiels du core, analyse base de données, comptes admin, tâches CRON, uploads dangereux |
| **Audit Log** | Trace les actions sensibles : connexions admin, plugins, thèmes, options, comptes |
| **Anti-spam** | Honeypot, rate-limiting, token csrf sur les commentaires + protection des formulaires frontend |
| **Mises à jour** | Pilotage sécurisé des mises à jour automatiques core/plugins/thèmes/traductions avec fenêtre horaire, backup pré-update et rollback automatique optionnel |
| **Définitions de détection** | Définitions locales + URL JSON distante optionnelle avec mise à jour automatique quotidienne |
| **Boîte à Outils Dev** | Fonctions all-in-one pour limiter les plugins additionnels : duplication, SVG/AVIF, Media Replacer (conserve ID/URLs), snippets code, robots/ads, maintenance, heartbeat, révisions |
| **Localisation** | Suivi automatique de la langue WordPress en mode Auto, priorité FR/EN avec couche runtime intégrée |
| **Nettoyage auto** | Purge quotidienne des logs et blocages expirés |

---

## ⚠️ Avant d'activer

### 1. Notez votre URL de connexion actuelle
Par défaut, l'URL personnalisée est `https://votre-site.com/mon-espace`.
**Notez-la avant d'activer**, sinon vous ne pourrez plus vous connecter directement à `wp-admin`.

Configuration : **360 Tranquillité → Connexion → Slug personnalisé**

### 1.b Personnaliser la page de connexion WordPress

Dans **360 Tranquillité → Connexion**, vous pouvez maintenant personnaliser entièrement l’interface de connexion :

1. remplacer le logo WordPress par un logo personnalisé
2. définir l’URL de redirection et le titre du logo
3. choisir les couleurs du background, formulaire, champs, boutons, liens et messages
4. ajuster l’arrondi et l’ombre du formulaire
5. ajouter du CSS personnalisé pour un design totalement sur mesure

### 2. Configuration de la 2FA
1. Activez la 2FA dans **360 Tranquillité → 2FA**
2. Allez dans **Utilisateurs → Votre profil**
3. Dans la section **Double Authentification**, cochez et configurez
4. Installez [Google Authenticator](https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2) ou [Authy](https://authy.com/)

### 3. QR Code pour la 2FA
Le fichier est déjà intégré dans `assets/js/qrcode.min.js`.
Il correspond à QRCode.js (licence MIT). Voir `THIRD_PARTY_NOTICES.md`.

### 4. Pourquoi l’installation d’extensions peut être bloquée
Si l’option de durcissement `DISALLOW_FILE_MODS` est activée dans **360 Tranquillité → Avancé**, WordPress bloque l’installation, la mise à jour et la suppression des extensions et thèmes depuis l’admin.

Pour retrouver un accès admin complet sur ces actions, désactivez cette option.

### 5. Sauvegardes locales, restauration et cloud
Le plugin inclut maintenant un module de sauvegardes dans **360 Tranquillité → Sauvegardes**.

Il permet :

1. des sauvegardes complètes du site WordPress et de la base de données
2. des sauvegardes incrémentales côté fichiers avec dump SQL complet
3. une planification quotidienne, hebdomadaire ou mensuelle à heure précise
4. une rétention configurable
5. un stockage local dans le dossier uploads
6. un upload optionnel vers Google Drive via un bouton Connecter Google Drive et la page de consentement Google
7. un upload optionnel vers un stockage S3 compatible: AWS S3, Cloudflare R2, Wasabi, Backblaze B2 S3, MinIO, etc.
8. une restauration locale d’une archive sauvegardée sur le serveur

Note importante :

- l’admin utilise désormais le flux de consentement Google habituel
- techniquement, un client OAuth Google doit toujours être configuré une fois côté plugin ou infrastructure applicative
- ce client peut être fourni via les constantes TRQ_GOOGLE_DRIVE_CLIENT_ID et TRQ_GOOGLE_DRIVE_CLIENT_SECRET, ou via le filtre trq_google_drive_oauth_client
- pour une expérience type UpdraftPlus sans configuration Google Cloud sur chaque site, le plugin sait désormais déléguer la connexion à un connecteur central externe via TRQ_GOOGLE_DRIVE_CONNECTOR_URL
- la restauration est volontairement limitée aux archives locales présentes sur le serveur
- avant restauration en production, une fenêtre de maintenance ou une préproduction reste préférable
- l’onglet Sauvegardes permet aussi d’importer une archive ZIP depuis votre poste, puis de la restaurer immédiatement ou plus tard

---

## Structure des fichiers

```
360tranquilite/
├── 360tranquilite.php              ← Entrée du plugin (header WP)
├── includes/
│   ├── class-core.php              ← Gestion réglages + BDD + activation
│   ├── class-firewall.php          ← WAF (Web Application Firewall)
│   ├── class-login-protection.php  ← Login URL + brute-force + personnalisation visuelle login
│   ├── class-two-factor.php        ← TOTP 2FA (RFC 6238)
│   ├── class-cloudflare.php        ← Intégration Cloudflare
│   ├── class-backup-manager.php    ← Sauvegardes locales/cloud et restauration locale
│   ├── class-auto-updates.php      ← Gestion automatisée des mises à jour WordPress
│   ├── class-github-updates.php    ← Checker des mises à jour depuis GitHub Releases
│   ├── class-localization.php      ← Localisation runtime FR/EN et adaptation à la locale WP
│   ├── class-threat-definitions.php← Définitions de détection, cache local et source distante
│   ├── class-dev-toolkit.php       ← Outils all-in-one développeurs
│   ├── class-security-headers.php  ← En-têtes HTTP sécurité
│   ├── class-file-monitor.php      ← Surveillance intégrité fichiers
│   ├── class-system-scanner.php    ← Scan système et checksums du core
│   └── class-antispam.php          ← Protection commentaires + formulaires frontend
├── admin/
│   ├── class-admin.php             ← Interface admin unifiée
│   ├── assets/
│   │   ├── css/admin.css
│   │   └── js/admin.js
│   └── views/
│       ├── page-dashboard.php      ← Tableau de bord + score
│       ├── page-firewall.php       ← WAF + logs + IPs bloquées
│       ├── page-login.php          ← URL login + anti-brute-force + options de design
│       ├── page-twofactor.php      ← Configuration 2FA
│       ├── page-cloudflare.php     ← Paramètres Cloudflare
│       ├── page-backups.php        ← Sauvegardes et planification
│       ├── page-updates.php        ← Mises à jour automatiques sécurisées
│       ├── page-toolkit.php        ← Boîte à outils développeurs
│       ├── page-advanced.php       ← Headers, file monitor, divers
│       └── page-about.php          ← Présentation, support, changelog
├── CHANGELOG.md                    ← Historique public des versions
├── uninstall.php                   ← Désinstallation propre
└── assets/
    ├── js/qrcode.min.js            ← Bibliothèque QR Code déjà incluse
    ├── logo-360tranquilite.svg     ← Logo du plugin
    └── logo-360tranquilite.png     ← Variante PNG du logo
```

---

## Tables de base de données créées

| Table | Contenu |
|-------|---------|
| `{prefix}trq_login_attempts` | Tentatives de connexion (succès + échecs) |
| `{prefix}trq_blocked_ips` | IPs bloquées (avec expiration) |
| `{prefix}trq_firewall_log` | Journal des attaques WAF |
| `{prefix}trq_file_checksums` | Checksums SHA-256 des fichiers surveillés |
| `{prefix}trq_audit_log` | Journal d'audit des actions sensibles |

---

## Désinstallation propre

La désactivation puis suppression du plugin via wp-admin **supprime automatiquement** toutes les tables et options créées.

## Ce que le plugin sait faire, et ne sait pas faire

- Il peut durcir WordPress, bloquer une partie des attaques et signaler des fichiers suspects.
- Il peut mettre en quarantaine manuellement un fichier de wp-content détecté comme suspect.
- Il peut vérifier le core WordPress via les checksums officiels WordPress.org.
- Il peut analyser un échantillon de la base de données, repérer des comptes admin à vérifier et signaler des hooks CRON douteux.
- Il peut durcir automatiquement le dossier uploads avec des fichiers de protection quand l’environnement le permet.
- Il peut exporter un rapport d’incident JSON pour archivage ou analyse.
- Il peut créer des sauvegardes complètes ou incrémentales et les envoyer localement, vers Google Drive ou vers un stockage S3 compatible.
- Il peut restaurer une archive locale en réappliquant les fichiers sauvegardés et le dump SQL s’il est présent.
- Il permet donc un rollback manuel via restauration d’une sauvegarde locale importée ou déjà présente sur le serveur.
- Il peut créer une sauvegarde locale pré-update (core/plugins/thèmes) et lancer un rollback automatique optionnel si les health-checks post-update détectent un crash.
- Il peut exporter et réimporter l’ensemble de ses réglages au format JSON pour répliquer une configuration sur plusieurs sites.
- Il peut remplacer un média existant de la médiathèque tout en conservant l’ID WordPress et les URLs (si l’extension reste identique), avec régénération des miniatures.
- Il ne supprime pas automatiquement des fichiers ni ne désinfecte seul une base de données compromise.
- Il ne propose pas encore de staging intégré (clonage complet en préproduction depuis l’interface).
- Il ne fait pas encore de rollback automatique après une mise à jour cassée via health-check et restauration auto.
- Il ne remplace pas une stratégie complète de sauvegarde, de supervision serveur et de réponse à incident.

## Signature du plugin

- Auteur : Aurel Yahouedeou
- Site : https://yaurel.com
- Plugin URI : https://yaurel.com
- Support : aurelandyou@gmail.com

## Licence

- Plugin : GPL v2 or later
- Bibliothèque tierce embarquée : QRCode.js sous licence MIT
- Détails : `LICENSE` et `THIRD_PARTY_NOTICES.md`

Vous pouvez aussi ajouter, si vous le souhaitez plus tard :

- un logo ou une icône du plugin
- une page de documentation dédiée sur votre site
- un numéro de version public dans une page changelog

---

## Exigences

- WordPress 5.8+
- PHP 7.4+ (PHP 8.0+ recommandé)
- Extension PHP : `hash`, `openssl` (pour la 2FA)
- MySQL 5.7+ ou MariaDB 10.2+
