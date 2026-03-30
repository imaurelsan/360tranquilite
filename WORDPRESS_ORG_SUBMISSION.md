# Soumission WordPress.org

## Ce qui est déjà prêt

- Le plugin principal déclare une licence GPL compatible.
- Un `readme.txt` WordPress.org est présent.
- Un fichier `LICENSE` est présent.
- Les dépendances tierces sont documentées dans `THIRD_PARTY_NOTICES.md`.
- La bibliothèque QR embarquée contient une mention de licence MIT.
- Les opérations sensibles admin utilisent des nonces et des contrôles de capacité.
- Les accès Cloudflare sont stockés de manière plus sûre et migrés automatiquement.
- Les opérations fichiers sensibles passent par l’API WordPress filesystem.
- Un module de sauvegardes planifiées local/Google Drive est intégré.

## Ce qu’il reste à faire manuellement

### 1. Identifiant WordPress.org

Dans `readme.txt`, la ligne suivante doit correspondre exactement à votre vrai nom d’utilisateur WordPress.org :

- `Contributors: aurel-yahouedeou`

Si cet identifiant n’est pas le bon, remplacez-le avant soumission.

### 2. Tests minimums avant soumission

Sur un WordPress propre :

- activer le plugin
- ouvrir chaque onglet admin
- changer le slug de connexion
- lancer un scan de fichiers
- lancer un scan système
- lancer une sauvegarde manuelle
- vérifier une archive locale générée
- tester l’export du rapport d’incident
- tester l’activation puis la désactivation de `DISALLOW_FILE_MODS`
- tester la désinstallation

### 3. Assets WordPress.org

Si vous voulez une page plugin plus propre sur le directory, préparez ensuite :

- icône plugin
- bannière
- captures d’écran

Ces assets ne sont pas obligatoires pour la soumission initiale, mais ils améliorent la présentation.

## Procédure de soumission

1. Créer ou utiliser un compte WordPress.org.
2. Aller sur la page Add Your Plugin.
3. Fournir le nom du plugin, la description et le zip initial.
4. Attendre la revue email.
5. Une fois approuvé, pousser le plugin via SVN sur WordPress.org.

## Point de vigilance principal

Le plugin est maintenant bien mieux préparé, mais la revue WordPress.org reste humaine. Il faut donc considérer cette base comme sérieusement pré-conforme, pas comme automatiquement garantie.
