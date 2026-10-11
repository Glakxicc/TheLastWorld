<?php
// Accès au serveur Minecraft, interrogé par le plugin TLWStats.
// GET : UUID de tous les comptes autorisés (synchronisation régulière du plugin).
// POST {uuid, name} : un joueur se connecte ; s'il n'est pas lié, on lui donne un code de liaison.

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

allowMethods('GET', 'POST');
requireServerToken();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(200, ['allowed' => allowedMinecraftUuids()]);
}

$body = readJsonBody();
$uuid = strtolower((string) ($body['uuid'] ?? ''));
$name = (string) ($body['name'] ?? '');
if (!preg_match(MC_UUID_PATTERN, $uuid) || !preg_match(MC_NAME_PATTERN, $name)) {
    fail(400, 'Joueur invalide.');
}

$player = findPlayerByMinecraft($uuid);

if ($player !== null && $player['whitelisted']) {
    // Le joueur a pu changer de pseudo Minecraft depuis la liaison
    if ($player['mc_username'] !== $name) {
        db()->prepare('UPDATE players SET mc_username = ? WHERE id = ?')->execute([$name, $player['id']]);
    }
    respond(200, ['allowed' => true, 'player' => $player['display_name'] ?: $player['discord_username']]);
}

if ($player !== null) {
    respond(200, [
        'allowed' => false,
        'reason' => 'removed',
        'message' => "Votre accès au serveur a été retiré.\nContactez le staff sur Discord.",
    ]);
}

respond(200, [
    'allowed' => false,
    'reason' => 'unlinked',
    'code' => linkCodeFor($uuid, $name),
    'expiresInMinutes' => intdiv(LINK_CODE_LIFETIME, 60),
    'site' => preg_replace('#^https?://#', '', siteUrl()),
]);
