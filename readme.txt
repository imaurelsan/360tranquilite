=== 360 Tranquillité ===
Contributors: imaurelsan
Tags: security, firewall, 2fa, login security, hardening, malware scan
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.10.14
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Sécurité et continuité WordPress tout-en-un: WAF, protection de connexion, 2FA TOTP, sauvegardes locales/cloud avec restauration locale, scan d’intégrité, audit et contrôles système.

== Description ==

360 Tranquillité centralise plusieurs couches de protection WordPress dans un plugin unique:

* Pare-feu applicatif avec blocage de signatures courantes
* URL de connexion personnalisée et limitation des tentatives
* Double authentification TOTP
* Sauvegardes complètes ou incrémentales avec planification
* Stockage local, connexion Google Drive par consentement OAuth et S3 compatible
* Restauration locale depuis archive ZIP et import d’archive depuis votre ordinateur
* Export et import des réglages du plugin au format JSON
* Intégration Cloudflare optionnelle
* En-têtes HTTP de sécurité
* Surveillance de l’intégrité des fichiers
* Scan système: core, base, comptes admin, tâches cron, uploads
* Journal d’audit des actions sensibles
* Anti-spam pour les commentaires

Le plugin privilégie le durcissement, la détection et l’aide à l’investigation. Il n’effectue pas de nettoyage destructif automatique.

== Installation ==

1. Téléversez le dossier 360tranquilite dans wp-content/plugins/.
2. Activez le plugin depuis Extensions.
3. Ouvrez 360 Tranquillité dans l’administration.
4. Configurez d’abord votre URL de connexion personnalisée, puis les options avancées.

== Frequently Asked Questions ==

= Le plugin bloque l’installation ou la mise à jour d’extensions =

Si l’option de durcissement DISALLOW_FILE_MODS est activée, WordPress bloque l’installation, la mise à jour et la suppression des extensions et thèmes depuis wp-admin. Désactivez cette option dans l’onglet Avancé pour retrouver un accès admin complet.

= Est-ce que le plugin supprime automatiquement les malwares =

Non. Il détecte, journalise, met certains fichiers en quarantaine manuelle et aide à l’analyse, mais ne supprime pas automatiquement des éléments potentiellement légitimes.

= Est-ce que Cloudflare est obligatoire =

Non. L’intégration Cloudflare est optionnelle.


= Est-ce que le plugin sait faire des sauvegardes et des restaurations =

Oui. Il sait produire des sauvegardes complètes ou incrémentales, inclure la base de données, conserver des archives localement, les envoyer vers Google Drive via un bouton de connexion avec consentement Google ou vers un stockage S3 compatible, puis restaurer une archive locale présente sur le serveur.

Le flux Google Drive utilise soit un client OAuth configuré une fois côté plugin ou infrastructure applicative, soit un connecteur central externe de type UpdraftPlus pour éviter de configurer Google Cloud sur chaque site.

== Changelog ==

= 1.7.0 =
* Flux de connexion Google Drive avec bouton Connecter et page de consentement Google
* Support d’un connecteur Google Drive centralisé type UpdraftPlus côté plugin
* Bloc d’aide wp-config.php et callback pour configurer Google Drive côté application
* Export et import des réglages du plugin au format JSON
* Ajout d’un provider S3 compatible pour les sauvegardes cloud
* Ajout d’une restauration locale depuis les archives stockées sur le serveur
* Rétention automatique sur la destination S3 compatible
*** Add File: c:\Users\imaur\Desktop\360tranquilite\GOOGLE_DRIVE_CONNECTOR.md
# Connecteur Google Drive central

Ce document décrit le contrat attendu pour brancher 360 Tranquillité à un connecteur central Google Drive de type UpdraftPlus.

## Objectif

Permettre aux sites WordPress d’ouvrir un consentement Google Drive sans configurer un client OAuth Google sur chaque site.

Le plugin doit seulement connaître l’URL du connecteur central via la constante suivante :

```php
define( 'TRQ_GOOGLE_DRIVE_CONNECTOR_URL', 'https://votre-service.example.com/trq-google-drive' );
```

## Endpoints attendus

Le plugin considère deux endpoints :

1. `GET {base}/connect`
2. `POST {base}/exchange`

avec `{base}` = valeur de `TRQ_GOOGLE_DRIVE_CONNECTOR_URL`.

## 1. Endpoint de démarrage

Le plugin ouvre l’URL suivante dans un nouvel onglet :

`GET {base}/connect?site_url=...&state=...&return_url=...&plugin=360tranquilite`

Paramètres reçus :

- `site_url` : URL du site WordPress demandeur
- `state` : nonce de session généré par le plugin
- `return_url` : URL de callback du plugin à rappeler après consentement
- `plugin` : identifiant du plugin demandeur

Le connecteur doit alors :

1. lancer son propre flux OAuth Google avec son propre client Google Cloud
2. récupérer un refresh token Google Drive
3. générer un code temporaire à usage unique côté serveur
4. rediriger le navigateur vers :

`{return_url}&state=...&connector_code=...`

ou `{return_url}?state=...&connector_code=...` selon la présence ou non d’une query string existante.

En cas d’erreur, le connecteur peut renvoyer :

`{return_url}?error=access_denied`

## 2. Endpoint d’échange

Après retour navigateur, le plugin appelle :

`POST {base}/exchange`

Champs envoyés :

- `connector_code`
- `state`
- `site_url`
- `callback_url`

Réponse JSON attendue :

```json
{
	"refresh_token": "...",
	"email": "user@example.com"
}
```

Le `connector_code` doit être :

- à usage unique
- courtement expiré
- lié au `state`
- invalidé après échange

## Sécurité minimale recommandée

- n’autoriser que HTTPS
- stocker le refresh token côté connecteur jusqu’à l’échange final
- ne jamais renvoyer le refresh token directement dans l’URL du navigateur
- expirer rapidement les codes temporaires
- journaliser le site demandeur et la date de création du code

## Coût

- Google Cloud OAuth n’est pas forcément payant pour ce scénario
- l’essentiel du coût est l’hébergement de votre connecteur central si vous choisissez ce mode
- un seul projet Google Cloud peut suffire pour tous vos sites si vous centralisez ce connecteur

= 1.6.0 =
* Ajout d’un module de sauvegardes complètes et incrémentales
* Planification à heure précise avec rétention configurable
* Stockage local et upload optionnel vers Google Drive
* Gestion des archives locales depuis l’admin

= 1.5.0 =
* Ajout des alertes de connexion admin depuis une nouvelle IP
* Ajout du durcissement DISALLOW_FILE_MODS et de la désactivation des Application Passwords
* Détection des options autoload trop volumineuses
* Export d’un rapport d’incident JSON

= 1.4.0 =
* Vérification du core WordPress via checksums officiels
* Planification quotidienne du scan système
* Durcissement automatique du dossier uploads quand possible

= 1.3.0 =
* Nouveau scan système base de données, admins, cron et uploads

= 1.2.0 =
* Extension du monitoring de fichiers
* Détection de fichiers suspects et quarantaine manuelle
* Ajout du journal d’audit

= 1.1.0 =
* Amélioration de l’intégration Cloudflare
* Nettoyage automatique et améliorations d’administration

= 1.0.0 =
* Première version publique

== Upgrade Notice ==

= 1.7.0 =
Cette version ajoute la restauration locale et un second provider cloud compatible S3.
