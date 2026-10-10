<?php
// Reçoit les statistiques envoyées par le plugin TLWStats du serveur Minecraft.
// Authentification : en-tête « X-TLW-Token » (ou « Authorization: Bearer … ») = minecraft.stats_token.

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

allowMethods('POST');

$token = config()['minecraft']['stats_token'] ?? '';
// Certains hébergeurs retirent l'en-tête Authorization : X-TLW-Token passe toujours
$given = $_SERVER['HTTP_X_TLW_TOKEN']
    ?? preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($token === '' || !hash_equals($token, $given)) {
    fail(401, 'Clé invalide.');
}

$body = readJsonBody();
$uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

foreach (array_slice($body['players'] ?? [], 0, 500) as $stats) {
    $uuid = strtolower((string) ($stats['uuid'] ?? ''));
    $name = (string) ($stats['name'] ?? '');
    if (!preg_match($uuidPattern, $uuid) || !preg_match('/^[A-Za-z0-9_]{1,16}$/', $name)) {
        continue;
    }

    $lastSeen = isset($stats['lastSeen']) ? gmdate('c', intdiv((int) $stats['lastSeen'], 1000)) : null;
    $values = [
        $name,
        max(0, (int) ($stats['playtime'] ?? 0)),
        max(0, (int) ($stats['deaths'] ?? 0)),
        max(0, (int) ($stats['mobKills'] ?? 0)),
        max(0, (int) ($stats['playerKills'] ?? 0)),
        $lastSeen,
        now(),
        $uuid,
    ];

    $exists = db()->prepare('SELECT 1 FROM player_stats WHERE mc_uuid = ?');
    $exists->execute([$uuid]);
    db()->prepare($exists->fetchColumn()
        ? 'UPDATE player_stats SET mc_username = ?, playtime_seconds = ?, deaths = ?, mob_kills = ?, player_kills = ?,
           last_seen = COALESCE(?, last_seen), updated_at = ? WHERE mc_uuid = ?'
        : 'INSERT INTO player_stats (mc_username, playtime_seconds, deaths, mob_kills, player_kills, last_seen, updated_at, mc_uuid)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute($values);
}

// Liste complète des joueurs connectés : tous les autres passent hors ligne
if (isset($body['online']) && is_array($body['online'])) {
    $online = array_values(array_filter(
        array_map(fn ($uuid) => strtolower((string) $uuid), $body['online']),
        fn ($uuid) => preg_match($uuidPattern, $uuid),
    ));
    db()->exec('UPDATE player_stats SET online = 0');
    if ($online) {
        $placeholders = implode(',', array_fill(0, count($online), '?'));
        db()->prepare("UPDATE player_stats SET online = 1 WHERE mc_uuid IN ($placeholders)")->execute($online);
    }
}

respond(200, ['success' => true]);
