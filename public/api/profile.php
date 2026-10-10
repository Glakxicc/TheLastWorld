<?php
// Informations à compléter par le joueur : pseudo Minecraft et skin du personnage.

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

allowMethods('POST');
$player = requirePlayer();
$body = readJsonBody();

$mcUsername = trim((string) ($body['mc_username'] ?? ''));
$skinUrl = trim((string) ($body['skin_url'] ?? ''));
$mcUuid = null;

if ($mcUsername !== '') {
    if (!preg_match('/^[A-Za-z0-9_]{3,16}$/', $mcUsername)) {
        fail(400, 'Pseudo Minecraft invalide (3 à 16 lettres, chiffres ou _).');
    }

    // On vérifie que le compte existe et on récupère son pseudo exact + UUID
    $curl = curl_init('https://api.mojang.com/users/profiles/minecraft/' . rawurlencode($mcUsername));
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $profile = json_decode((string) $response, true);

    if ($status === 404 || $status === 204) {
        fail(400, "Aucun compte Minecraft nommé « $mcUsername ».");
    }
    if ($status !== 200 || empty($profile['id'])) {
        fail(502, 'Impossible de vérifier le pseudo auprès de Mojang, réessayez.');
    }

    $mcUsername = $profile['name'];
    $mcUuid = preg_replace('/^(.{8})(.{4})(.{4})(.{4})(.{12})$/', '$1-$2-$3-$4-$5', $profile['id']);

    $taken = db()->prepare('SELECT 1 FROM players WHERE mc_uuid = ? AND id <> ?');
    $taken->execute([$mcUuid, $player['id']]);
    if ($taken->fetchColumn()) {
        fail(400, 'Ce compte Minecraft est déjà lié à un autre joueur.');
    }
}

if ($skinUrl !== '') {
    $scheme = parse_url($skinUrl, PHP_URL_SCHEME);
    if (!in_array($scheme, ['http', 'https'], true) || !filter_var($skinUrl, FILTER_VALIDATE_URL) || strlen($skinUrl) > 256) {
        fail(400, 'Lien du skin invalide (adresse commençant par https://).');
    }
}

db()->prepare('UPDATE players SET mc_username = ?, mc_uuid = ?, skin_url = ? WHERE id = ?')
    ->execute([$mcUsername ?: null, $mcUuid, $skinUrl ?: null, $player['id']]);

respond(200, ['success' => true, 'player' => publicPlayer(findPlayerById((int) $player['id']))]);
