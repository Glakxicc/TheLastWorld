<?php
// Skin du personnage, à compléter par le joueur.
// Le compte Minecraft se lie avec un code donné par le serveur : voir minecraft/link.php.

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

allowMethods('POST');
$player = requirePlayer();
$body = readJsonBody();

$skinUrl = trim((string) ($body['skin_url'] ?? ''));

if ($skinUrl !== '') {
    $scheme = parse_url($skinUrl, PHP_URL_SCHEME);
    if (!in_array($scheme, ['http', 'https'], true) || !filter_var($skinUrl, FILTER_VALIDATE_URL) || strlen($skinUrl) > 256) {
        fail(400, 'Lien du skin invalide (adresse commençant par https://).');
    }
}

db()->prepare('UPDATE players SET skin_url = ? WHERE id = ?')->execute([$skinUrl ?: null, $player['id']]);

respond(200, ['success' => true, 'player' => publicPlayer(findPlayerById((int) $player['id']))]);
