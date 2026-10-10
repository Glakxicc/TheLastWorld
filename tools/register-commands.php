<?php
// Enregistre (ou met à jour) les commandes slash du bot sur le serveur Discord.
// À lancer depuis votre ordinateur après chaque modification des commandes :
//   php tools/register-commands.php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit;
}

require __DIR__ . '/../public/api/lib/bootstrap.php';

const SUB_COMMAND = 1;
const STRING = 3;
const INTEGER = 4;
const USER = 6;
const PERMISSION_MANAGE_GUILD = '32';

$categories = [
    ['name' => 'Actus', 'value' => 'actus'],
    ['name' => 'DevLogs', 'value' => 'devlog'],
    ['name' => 'Annonces membres (tableau de bord)', 'value' => 'membres'],
];

$commands = [
    [
        'name' => 'whitelist',
        'description' => "Gérer l'accès des joueurs au site",
        'default_member_permissions' => PERMISSION_MANAGE_GUILD,
        'options' => [
            [
                'type' => SUB_COMMAND, 'name' => 'ajouter', 'description' => 'Donner accès au site à un joueur',
                'options' => [['type' => USER, 'name' => 'joueur', 'description' => 'Le joueur', 'required' => true]],
            ],
            [
                'type' => SUB_COMMAND, 'name' => 'retirer', 'description' => "Retirer l'accès au site à un joueur",
                'options' => [['type' => USER, 'name' => 'joueur', 'description' => 'Le joueur', 'required' => true]],
            ],
            ['type' => SUB_COMMAND, 'name' => 'liste', 'description' => 'Voir les joueurs whitelistés'],
        ],
    ],
    [
        'name' => 'post',
        'description' => 'Gérer les publications du site',
        'default_member_permissions' => PERMISSION_MANAGE_GUILD,
        'options' => [
            [
                'type' => SUB_COMMAND, 'name' => 'publier', 'description' => 'Publier une actu, un devlog ou une annonce',
                'options' => [
                    [
                        'type' => STRING, 'name' => 'categorie', 'description' => 'Où publier',
                        'required' => true, 'choices' => $categories,
                    ],
                    [
                        'type' => USER, 'name' => 'joueur',
                        'description' => 'Annonce membres : réservée à ce joueur (sinon pour tous)',
                    ],
                ],
            ],
            [
                'type' => SUB_COMMAND, 'name' => 'supprimer', 'description' => 'Supprimer une publication',
                'options' => [[
                    'type' => INTEGER, 'name' => 'id', 'description' => 'Numéro (voir /post liste)', 'required' => true,
                ]],
            ],
            [
                'type' => SUB_COMMAND, 'name' => 'liste', 'description' => 'Voir les publications',
                'options' => [[
                    'type' => STRING, 'name' => 'categorie', 'description' => 'Filtrer', 'choices' => $categories,
                ]],
            ],
        ],
    ],
    [
        'name' => 'statut',
        'description' => 'Changer le statut du serveur affiché sur le site',
        'default_member_permissions' => PERMISSION_MANAGE_GUILD,
    ],
];

$discord = config()['discord'] ?? [];
foreach (['client_id', 'bot_token', 'guild_id'] as $key) {
    if (empty($discord[$key])) {
        fwrite(STDERR, "Renseignez discord.$key dans la configuration.\n");
        exit(1);
    }
}

[$status, $response] = discordBot(
    'PUT',
    "/applications/{$discord['client_id']}/guilds/{$discord['guild_id']}/commands",
    $commands,
);

if ($status !== 200) {
    fwrite(STDERR, "Échec ($status) : " . json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

echo count($response) . " commandes enregistrées : /" . implode(', /', array_column($response, 'name')) . "\n";
