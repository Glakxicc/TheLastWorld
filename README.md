# TheLastWorld-Site

Site du serveur Minecraft roleplay **TheLastWorld**, hébergé chez IONOS (hébergement
mutualisé Apache + PHP 8 + MySQL).

- Formulaire de whitelist envoyé au staff sur Discord (boutons Accepter / Refuser)
- Connexion des joueurs whitelistés avec leur compte Discord
- Espace joueur : statut de whitelist, fiche personnage, infos serveur, annonces membres
- Bot Discord du staff : whitelist, publications, statut du serveur

## Structure

```
public/                         Tout ce qui est mis en ligne
  index.html, pages/            Pages du site (dashboard.html = espace joueur)
  assets/                       CSS, JS, images
  api/
    config.php                  Secrets (non versionné, voir config.example.php)
    formulaire.php              Formulaire de whitelist
    me.php, character.php       Joueur connecté, fiche personnage
    posts.php, status.php       Publications, statut du serveur
    auth/                       Connexion Discord (login, callback, logout)
    discord/interactions.php    Bot Discord (commandes slash et boutons)
    lib/                        Code partagé (inaccessible depuis le web)
    data/                       SQLite local, sessions, limiteur (inaccessible)
tools/register-commands.php     Enregistre les commandes slash du bot
minecraft-plugin/               Plugin TLWStats (stats des joueurs → site)
config.local.php                Config de développement (non versionnée)
```

## Commandes du bot

Réservées aux administrateurs du serveur Discord et aux rôles `staff_role_ids`.

| Commande | Effet |
| --- | --- |
| `/whitelist ajouter @joueur` | Donne accès au site (reprend sa fiche s'il a envoyé le formulaire) |
| `/whitelist retirer @joueur` | Retire l'accès (le joueur est déconnecté) |
| `/whitelist liste` | Joueurs whitelistés |
| `/post publier <catégorie>` | Ouvre un formulaire : Actus, DevLogs ou Annonces membres |
| `/post supprimer <id>` / `/post liste` | Gérer les publications |
| `/statut` | Boutons Ouvrir / Fermer : statut affiché sur le site |

Chaque formulaire envoyé depuis le site arrive dans le salon `forms_channel_id` avec
des boutons **Accepter** / **Refuser**. Accepter whitelist le pseudo Discord indiqué
dans le formulaire : le joueur peut ensuite se connecter avec ce compte.

## Mise en place

### 1. Application Discord

Sur https://discord.com/developers/applications → **New Application** :

1. **General Information** : copier `Application ID` (`client_id`) et `Public Key` (`public_key`).
2. **Bot** → Reset Token : copier le token (`bot_token`).
3. **OAuth2** → copier le `Client Secret` (`client_secret`) et ajouter les Redirects :
   - `https://www.thelastworld.fr/api/auth/callback.php`
   - `http://localhost:8000/api/auth/callback.php` (développement)
4. **Installation** → Guild Install, scopes `bot` + `applications.commands`,
   permissions `Send Messages` + `Embed Links`. Ouvrir le lien d'installation et
   ajouter le bot au serveur TheLastWorld.
5. Dans Discord (mode développeur activé) : clic droit → Copier l'identifiant pour le
   serveur (`guild_id`), le salon des formulaires (`forms_channel_id`) et les rôles
   staff (`staff_role_ids`).

### 2. Base de données IONOS

Espace client IONOS → Hébergement → **Bases de données** → créer une base MySQL, puis
renseigner `db.dsn`, `db.user` et `db.password` dans `config.php`. Les tables sont
créées automatiquement à la première visite. Sans MySQL, une base SQLite est utilisée.

### 3. Configuration et mise en ligne

```bash
cp public/api/config.example.php public/api/config.php   # puis tout remplir
```

Envoyer le contenu de `public/` à la racine de l'espace web IONOS (FTP/SFTP), avec
`api/config.php` et les fichiers `.htaccess`. **Ne pas envoyer** le contenu de
`api/data/` (base et sessions locales), sauf son `.htaccess`.

### 4. Activer le bot

1. Portail Discord → General Information → **Interactions Endpoint URL** :
   `https://www.thelastworld.fr/api/discord/interactions.php` → Save (Discord vérifie
   l'adresse immédiatement : le site doit déjà être en ligne avec la bonne `public_key`).
2. Enregistrer les commandes (depuis votre ordinateur, avec la config remplie) :

   ```bash
   php tools/register-commands.php
   ```

### 5. Plugin Minecraft (accès au serveur, whitelist, statistiques)

Le plugin `TLWStats` relie le serveur Minecraft au site. Prévu pour un serveur Mohist 1.12.2
(Java 8, 8u101 minimum pour le HTTPS) ; marche aussi sur Spigot/Paper 1.12.2+.

- **Compte Minecraft lié au compte Discord** : un compte Minecraft non lié qui rejoint le
  serveur est refusé avec un code à 6 caractères ; le joueur le saisit dans « Mon espace ».
  Seul le vrai propriétaire du compte voit ce code : impossible de prendre le pseudo d'un autre.
- **Whitelist automatique** : seuls les comptes liés à un joueur whitelisté sur le site entrent.
  La whitelist du serveur (`/whitelist`) est tenue à jour toutes les minutes, et un joueur retiré
  sur le site (ou par le bot) est expulsé. Les opérateurs (`/op`) entrent toujours.
- **Statistiques** : temps de jeu, morts, monstres et joueurs tués, dernière connexion, joueurs
  en ligne (à chaque connexion / déconnexion et toutes les 5 minutes).
- **Inventaires** : inventaire, armure, seconde main et coffre de l'Ender de chaque joueur, envoyés
  en même temps que les statistiques. Le staff les consulte dans « Mon espace » → Joueurs →
  fiche du joueur → « Voir l'inventaire » (dernier état connu, même hors ligne).

1. Copier `minecraft-plugin/build/TLWStats.jar` dans le dossier `plugins/` du serveur
   et redémarrer : `plugins/TLWStats/config.yml` est créé.
2. Y coller la clé `minecraft.stats_token` de `api/config.php` dans `token`, puis redémarrer.
3. Le serveur doit être en `online-mode=true` (sinon les comptes ne sont pas vérifiés par Mojang).

Recompiler : `mvn package` dans `minecraft-plugin/`.

## Développement local

```bash
php -S localhost:8000 -t public
```

Puis ouvrir http://localhost:8000. `config.local.php` remplace en local les valeurs de
`config.php` (adresse du site, base SQLite). Live Server fonctionne aussi pour les
pages, tant que la commande ci-dessus tourne en parallèle.

Le bot ne peut pas être testé en local : Discord doit pouvoir joindre le site en HTTPS.
