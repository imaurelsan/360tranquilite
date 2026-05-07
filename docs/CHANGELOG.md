# Changelog

Toutes les évolutions notables de 360 Tranquillité seront documentées dans ce fichier.

## 1.10.12 - 2026-05-07
- Bugfix sauvegardes: l'archive n'était pas conservée lorsque le stockage local était désactivé (Drive uniquement) et que WP_Filesystem() échouait en contexte cron — repli sur rename() natif + RuntimeException explicite si aucune destination n'est joignable
- Bugfix sauvegardes: suppression d'un appel filesize() sur un fichier déjà supprimé (PHP warning silencieux)
- Stabilité Dev Toolkit: le module n'initialise plus ses hooks lorsqu'il est désactivé dans les réglages (évite les erreurs fatales globales)

## 1.10.11 - 2026-05-04
- Bugfix: correction d'une erreur fatale sur la médiathèque (`/wp-admin/upload.php`) introduite par l'initialisation du module Dev Toolkit
- Stabilisation admin: le Dev Toolkit n'est plus initialisé sur l'écran Médias (`upload.php`) pour éviter l'échec de chargement
- UX admin: correctif JS/CSS ciblé sur la médiathèque pour éviter l'affichage intempestif de la notice liée à la vue grille

## 1.10.10 - 2026-04-05
- Pare-feu: correction d'un faux positif sur les formulaires de connexion (les caractères spéciaux légitimes dans les identifiants/mots de passe ne déclenchent plus un blocage SQLi)
- Pare-feu: bypass explicite du flux d'authentification WordPress (`wp-login.php`, slug de connexion personnalisé, mot de passe perdu/reset) pour éviter le blocage de connexions légitimes

## 1.10.9 - 2026-04-03
- Interface Admin: ajout d'un organisateur de menu admin (masquage par slugs + réordonnancement par ordre personnalisé)
- Interface Admin: ajout du tri global des termes de taxonomie (champ de tri + ordre ASC/DESC)
- Contenu: ajout de l'automatisation staging SEO (détection par patterns d'hôte + noindex/nofollow forcé)
- Contenu: option de synchronisation automatique du réglage WordPress `blog_public=0` en environnement staging détecté
- Interface Admin: ajout d'une notice explicite quand le mode staging noindex est appliqué

## 1.10.8 - 2026-04-03
- Onglet Médias: ajout d'une section d'optimisation image dédiée (activation, dimensions max, qualité, génération WebP)
- Pipeline upload: limitation configurable de la taille des grandes images via `big_image_size_threshold`
- Pipeline upload: qualité de compression configurable appliquée via `wp_editor_set_quality`
- Pipeline upload: génération automatique de variantes `.webp` (original + tailles dérivées) sans remplacer les originaux
- Action debug "Tout désactiver": inclut désormais aussi les nouvelles options d'optimisation médias

## 1.10.7 - 2026-04-02
- Organisation admin: ajout de deux onglets dédiés `Contenu` et `Interface Admin` pour séparer clairement les catégories de réglages
- Contenu: ajout des permaliens externes par contenu (metabox URL externe + redirection automatique sur single)
- Contenu: ajout des options globales sur les liens externes (`target=_blank` et `rel=nofollow`) dans le contenu et les widgets
- Contenu: ajout d'options globales de désactivation des commentaires et des flux RSS/Atom
- Interface Admin: ajout d'options de nettoyage de la barre admin (logo WP, commentaires, +Nouveau, mises à jour)
- Interface Admin: ajout d'options pour masquer la barre admin en front (non-admins), régler la largeur du menu admin, et personnaliser le footer admin
- Interface Admin: ajout d'une colonne `Derniere connexion` dans la liste des utilisateurs (triable)
- Interface Admin: ajout de filtres taxonomies dans les listes de contenus

## 1.10.6 - 2026-04-01
- Tableau de bord: ajout d’un bouton `Tout désactiver (debug)` pour couper rapidement les principaux modules en cas de diagnostic
- Notifications de sécurité: ajout d’une option explicite pour activer/désactiver l’envoi d’emails
- Notifications de sécurité: le champ email peut désormais être vidé (l’email admin est conservé en suggestion, sans réinjection automatique)

## 1.10.5 - 2026-03-31
- Mises à jour GitHub: normalisation de `TRQ_GITHUB_UPDATES_REPO` (accepte `owner/repo`, URL GitHub complète, et suffixe `.git`)
- Mises à jour GitHub: validation finale du repo après filtre `trq_github_updates_config` pour éviter les configurations invalides

## 1.10.4 - 2026-03-31
- Mises à jour GitHub: activation par défaut sur le repo public `imaurelsan/360tranquilite` si aucune constante n'est définie
- Mises à jour GitHub: ajout de la constante optionnelle `TRQ_GITHUB_UPDATES_ENABLED` pour forcer l'activation ou la désactivation

## 1.10.3 - 2026-03-31
- Sauvegardes: ajout d'un bouton Annuler pendant une sauvegarde en cours
- Sauvegardes: annulation coopérative côté moteur avec arrêt au prochain point de contrôle
- Sauvegardes: progression enrichie pendant le scan fichiers, l'export SQL et la création de l'archive ZIP
- Sauvegardes: meilleure robustesse en cas d'interruption serveur (détection d'état bloqué, nettoyage des artefacts temporaires)

## 1.10.2 - 2026-03-30
- Uniformisation visuelle : la carte du score reprend désormais le même fond turquoise que la section d’introduction de l’onglet À propos

## 1.10.1 - 2026-03-30
- Harmonisation visuelle complémentaire sur les onglets Sauvegardes, Cloudflare, Mises à jour et Avancé (cartes homogènes avec hauteurs et espacement cohérents)
- Tableau de bord : ajout d’un bloc Démarrage rapide avec bouton "Activer la configuration recommandée"
- Ajout d’un profil recommandé côté admin pour pré-activer les protections principales en un clic, tout en conservant le mode opt-in par défaut à l’installation

## 1.10.0 - 2026-03-30
- Uniformisation des onglets Sauvegardes, Cloudflare, Mises à jour et Avancé avec un bloc principal en pleine largeur puis des sections homogènes en 2 colonnes
- Onglet À propos : espacement corrigé dans le bloc logo / coordonnées, fond turquoise et textes/liens en blanc sur la section d’introduction
- Installation neuve en mode opt-in : les réglages fonctionnels ne sont plus activés par défaut

## 1.9.0 - 2026-03-30
- Dashboard : stats en pleine largeur sous le score, sections intermédiaires en 2 colonnes

## 1.8.9 - 2026-03-30

- Onglet Tableau de bord : compactage des cartes de statistiques en grille 3 colonnes x 3 lignes sur desktop
- Suppression de la carte la moins prioritaire (`Dernier scan de fichiers`) pour éviter l'empilement vertical et améliorer la lisibilité
- Ajustement responsive : la grille des stats dashboard repasse en 2 colonnes sur mobile

## 1.8.8 - 2026-03-30

- Correctif de mise en page de l'onglet Tableau de bord : suppression de l'imbrication dans la grille parente qui limitait visuellement le contenu à une demi-largeur
- Passage de la grille desktop du dashboard en colonnes strictement équilibrées (2/4 - 2/4)

## 1.8.7 - 2026-03-29

- Onglet Connexion : ajout d'une option explicite `Activer la personnalisation visuelle` (désactivée par défaut) pour éviter de modifier l'apparence native de la page de connexion dès l'installation
- Personnalisation login (logo/couleurs/CSS) appliquée uniquement quand cette option est activée
- Réorganisation du layout desktop de plusieurs onglets (`Pare-feu`, `2FA`, `Cloudflare`, `Mises a jour`, `Avance`) avec des grilles dédiées et des zones stables
- Ajustement visuel de la zone de réglages visuels login avec un bloc clairement séparé

## 1.8.6 - 2026-03-29

- Purge automatique de l'option `trq_last_boot_error` après un démarrage plugin réussi, pour éviter l'affichage persistant d'un incident ancien
- Le mécanisme de capture fatal reste actif : si une nouvelle erreur survient, elle est immédiatement réenregistrée par le shutdown handler

## 1.8.5 - 2026-03-29

- Ajout d'un filet de sécurité bootstrap sur `login_redirect` qui retire les callbacks legacy de `TRQ_Dev_Toolkit::filter_login_redirect` encore enregistrés
- Installation d'un callback de redirection robuste et non typé côté bootstrap pour absorber sans fatal les cas `WP_Error`
- Correctif conçu pour les environnements de production où une ancienne signature peut persister malgré un déploiement de fichiers récent

## 1.8.4 - 2026-03-29

- Correctif multi-couche pour hébergements avec OPcache très agressif gardant des opcodes périmés
- Ajout d'un wrapper proxy au niveau du hook `login_redirect` pour intercepter et filtrer les appels problématiques
- Vérifications de type runtime ultimes dans `filter_login_redirect` pour neutraliser les signatures anciennes
- Désactivation du hook direct au profit d'une enveloppe défensive en cas de collision avec ancienne version

## 1.8.3 - 2026-03-29

- Correctif de déploiement sur hébergements avec OPcache agressif : invalidation explicite du cache opcode du module Toolkit avant chargement
- Résolution des cas où une ancienne signature de `filter_login_redirect` restait exécutée en mémoire malgré un fichier plugin déjà corrigé

## 1.8.2 - 2026-03-29

- Correctif de robustesse sur la redirection de connexion pour accepter les cas où WordPress transmet un `WP_Error` au lieu d un utilisateur
- Affinage de la mise en page responsive admin avec activation du mode colonnes dès 1100px de large
- Rééquilibrage de l onglet Tableau de bord pour réduire l effet de cartes compressées sur la colonne de gauche
- Réorganisation de l onglet Sauvegardes pour placer Importer une archive externe et Archives locales juste sous Actions manuelles
- Passage de l onglet Avancé en vraie grille 2 colonnes sur desktop pour limiter la hauteur de scroll
- Suppression des deux blocs d information de fin dans l onglet Boîte à Outils Dev

## 1.8.1 - 2026-03-29

- Suppression des libellés ou mentions faisant référence à des plugins concurrents dans l’interface et la documentation
- Ajout des sous-menus WordPress natifs pour chaque onglet du plugin sous le menu 360 Tranquillité
- Amélioration de l’ergonomie admin avec une mise en page en colonnes sur grand écran pour réduire le scroll vertical
- Enrichissement de la page À propos avec une section expliquant le fonctionnement interne du plugin
- Correctif critique des callbacks de mises à jour automatiques pour gérer correctement les valeurs null passées par WordPress
- Correctif du firewall pour éviter les warnings preg_match liés à des motifs regex invalides
- Ajout d’une sauvegarde locale automatique avant chaque mise à jour core/plugin/thème
- Ajout d’un health-check post-update configurable avec tentatives périodiques
- Ajout d’un rollback automatique optionnel sur la dernière sauvegarde pré-update en cas d’indisponibilité persistante
- Ajout d’un checker de mises à jour GitHub Releases pour distribuer automatiquement les nouvelles versions aux sites testeurs (configuration via constantes wp-config)

## 1.7.1 - 2026-03-27

- Ajout d’un onglet dédié Mises à jour pour piloter les mises à jour automatiques sécurisées du core, des plugins, des thèmes et des traductions
- Ajout d’une fenêtre horaire quotidienne optionnelle pour limiter l’exécution des mises à jour automatiques
- Ajout d’un rapport de mises à jour automatiques avec notification email dédiée
- Ajout d’un réglage dédié pour activer/désactiver la protection des formulaires frontend hors commentaires

## 1.8.0 - 2026-03-28

- Ajout d’un onglet Boîte à Outils Dev (all-in-one orienté développeur) avec duplication de contenu, upload SVG/AVIF, snippets CSS/code, robots.txt, ads.txt, app-ads.txt, mode maintenance, heartbeat et contrôle des révisions
- Ajout d’un mode d’assainissement incident depuis l’onglet Avancé: scan, quarantaine automatique des fichiers suspects, suppression des hooks cron suspects et durcissement uploads
- Ajout d’une option de langue du plugin (Auto / fr_FR / en_US) avec socle i18n pour basculer la locale du domaine 360tranquilite
- Ajout d’une couche de localisation runtime pour appliquer réellement l’anglais dans l’interface du plugin sans dépendre des fichiers .mo, avec suivi automatique de la langue WordPress en mode Auto
- Ajout d’un gestionnaire de définitions de détection avec cache local, URL JSON distante optionnelle, mise à jour manuelle et planification automatique quotidienne
- Ajout d’une personnalisation complète de la page de connexion WordPress depuis l’onglet Connexion: logo personnalisé, URL/titre du logo, palette de couleurs complète, réglages visuels du formulaire et CSS additionnel
- Ajout d’une fonctionnalité Media Replacer dans la Boîte à Outils Dev pour remplacer un média existant en conservant l’ID WordPress et les URLs (si extension identique), avec régénération automatique des miniatures

## 1.7.0 - 2026-03-25

- Remplacement de la saisie manuelle Google Drive par un flux OAuth avec bouton Connecter et consentement Google
- Ajout du support d’un connecteur Google Drive centralisé type UpdraftPlus côté plugin
- Ajout d’un bloc d’aide wp-config.php et de l’URI de callback pour finaliser le client OAuth Google
- Ajout d’un export/import JSON des réglages du plugin depuis l’onglet Avancé
- Ajout d’un second provider cloud compatible S3 pour les sauvegardes
- Support des endpoints S3 custom avec mode path-style pour R2, Wasabi, B2 S3 ou MinIO
- Ajout d’une rétention automatique sur le stockage S3 compatible
- Ajout de l’import d’archive ZIP avec restauration immédiate optionnelle depuis l’onglet Sauvegardes
- Ajout d’une restauration locale depuis une archive ZIP stockée sur le serveur
- Mise en maintenance temporaire pendant la restauration et import du dump SQL si présent

## 1.6.0 - 2026-03-25

- Ajout d’un module de sauvegardes complet dans l’admin
- Support des sauvegardes complètes et incrémentales côté fichiers
- Export SQL intégré de la base de données dans l’archive
- Planification quotidienne, hebdomadaire ou mensuelle à heure précise
- Rétention configurable des archives
- Stockage local des sauvegardes dans uploads
- Upload optionnel vers Google Drive avec OAuth refresh token et dossier cible
- Téléchargement et suppression des archives locales depuis l’admin
- Tableau de bord enrichi avec l’état des sauvegardes

## 1.5.0 - 2026-03-25

- Alerte e-mail et journal d’audit en cas de connexion administrateur depuis une nouvelle IP
- Ajout des durcissements optionnels DISALLOW_FILE_MODS et désactivation des Application Passwords
- Détection des options autoload anormalement volumineuses dans le scan base de données
- Ajout d’un export JSON du rapport d’incident depuis l’admin
- Tableau de bord et onglet Avancé enrichis avec les nouveaux durcissements et indicateurs

## 1.4.0 - 2026-03-25

- Vérification du core WordPress via les checksums officiels WordPress.org
- Planification quotidienne du scan système en plus du déclenchement manuel
- Ajout d’une action manuelle pour supprimer les occurrences des hooks CRON suspects
- Ajout d’un durcissement concret du dossier uploads avec .htaccess, web.config et index.php quand possible
- Tableau de bord et onglet Avancé enrichis avec le statut du core et des protections uploads

## 1.3.0 - 2026-03-24

- Ajout d’un scan système manuel : base de données, comptes administrateurs et tâches CRON
- Détection de motifs suspects dans options, contenus, meta et commentaires
- Détection des comptes admin à vérifier : comptes récents, sans 2FA, noms trop prévisibles
- Détection de hooks CRON suspects ou dupliqués de façon anormale
- Durcissement des uploads avec blocage des extensions exécutables dangereuses
- Tableau de bord et onglet Avancé enrichis avec les résultats du scan système

## 1.2.0 - 2026-03-24

- Extension du moniteur de fichiers à wp-content/plugins, themes, mu-plugins et uploads
- Détection de signaux suspects : PHP dans uploads, noms de fichiers douteux, motifs d’obfuscation courants
- Conservation d’un rapport de scan avec changements et findings exploitables dans l’admin
- Ajout d’une mise en quarantaine manuelle des fichiers suspects dans wp-content
- Ajout d’un journal d’audit des actions sensibles : connexions admin, comptes, rôles, plugins, thèmes, options
- Tableau de bord enrichi avec métriques de scan et d’audit
- Onglet Avancé enrichi avec rapport de scan, audit log et réglages de périmètre

## 1.1.0 - 2026-03-24

- Signature du plugin au nom de Aurel Yahouedeou
- Ajout des URI publiques du plugin et de l'auteur
- Ajout du support par e-mail : aurelandyou@gmail.com
- Intégration d'une page À propos dans l'admin
- Support du logo du plugin depuis assets/logo-360tranquilite.svg ou .png
- Support des API Tokens Cloudflare en plus de la Global API Key
- Synchronisation optionnelle des IPs bloquées vers Cloudflare
- Ajout du nettoyage automatique quotidien des données anciennes
- Ajout d'une désinstallation propre via uninstall.php
- Ajout d'alertes de configuration dans le tableau de bord
- Documentation enrichie

## 1.0.0 - 2026-03-24

- Première version du plugin
- Firewall applicatif
- Protection de la connexion et anti-brute-force
- Double authentification TOTP
- Intégration Cloudflare
- En-têtes de sécurité HTTP
- Surveillance de l'intégrité des fichiers
- Anti-spam commentaires
- Interface d'administration unifiée