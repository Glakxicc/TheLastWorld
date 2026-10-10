# TheLastWorld-Site

Site du serveur Minecraft roleplay **TheLastWorld**, hébergé chez IONOS (hébergement
mutualisé Apache + PHP). Pages statiques + un script PHP qui transmet le formulaire de
whitelist au Discord du staff.

## Structure

```
public/                    Tout ce qui est mis en ligne
  index.html
  pages/                   actus, lore, joinus
  assets/css/              styles.css (commun), lore.css, joinus.css
  assets/js/               main.js (commun), actus.js, joinus.js, modules/
  api/formulaire.php       Reçoit le formulaire et l'envoie au webhook Discord
  api/config.example.php   Modèle de configuration (copier en config.php)
```

## Configuration

```bash
cp public/api/config.example.php public/api/config.php
```

Puis renseigner `discord_webhook_url` dans `config.php`. Ce fichier est ignoré par git
et protégé par `public/api/.htaccess`.

## Lancer en local

```bash
php -S localhost:8000 -t public
```

Puis ouvrir http://localhost:8000. Live Server fonctionne aussi pour les pages ; le
formulaire passe alors par le serveur PHP ci-dessus (il doit tourner en parallèle).

## Mise en ligne (IONOS)

Envoyer **le contenu** du dossier `public/` à la racine de l'espace web (FTP/SFTP),
y compris `api/config.php` et les fichiers `.htaccess`.

Le formulaire est limité à 5 envois par IP toutes les 10 minutes (stocké dans
`api/data/`, qui doit être accessible en écriture).
