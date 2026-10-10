<?php
// Copier ce fichier en config.php et le remplir. config.php ne doit jamais être commité.
// En local, config.local.php (à la racine du dépôt) remplace ces valeurs.

return [
    // Adresse du serveur Minecraft affichée sur le tableau de bord
    'minecraft_address' => '45.140.164.183:25645',

    // Base de données. Vide = fichier SQLite dans api/data/.
    // IONOS : Hébergement > Bases de données > MySQL, puis par exemple
    // 'dsn' => 'mysql:host=db5000000000.hosting-data.io;dbname=dbs0000000;charset=utf8mb4'
    'db' => [
        'dsn' => '',
        'user' => '',
        'password' => '',
    ],

    // Application Discord (https://discord.com/developers/applications)
    'discord' => [
        'client_id' => '',          // General Information > Application ID
        'public_key' => '',         // General Information > Public Key
        'client_secret' => '',      // OAuth2 > Client Secret
        'bot_token' => '',          // Bot > Reset Token
        'guild_id' => '',           // ID du serveur Discord TheLastWorld
        'forms_channel_id' => '',   // Salon où arrivent les formulaires
        'staff_role_ids' => [],     // Rôles staff (les administrateurs ont toujours accès)
    ],

    // Serveur Minecraft : clé partagée avec le plugin TLWStats (plugins/TLWStats/config.yml)
    'minecraft' => [
        'stats_token' => '',
    ],

    // Ancien webhook : utilisé pour les formulaires tant que le bot n'est pas configuré
    'discord_webhook_url' => '',

    // Sites autorisés à appeler l'API depuis une autre adresse (Live Server en local)
    'allowed_origins' => [
        'http://127.0.0.1:5500',
        'http://localhost:5500',
    ],
];
