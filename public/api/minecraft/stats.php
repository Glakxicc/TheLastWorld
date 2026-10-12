<?php
// Reçoit les statistiques envoyées par le plugin TLWStats du serveur Minecraft.
// Authentification : en-tête « X-TLW-Token » (ou « Authorization: Bearer … ») = minecraft.stats_token.

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

allowMethods('POST');

requireServerToken();

$body = readJsonBody();

foreach (array_slice($body['players'] ?? [], 0, 500) as $stats) {
    $uuid = strtolower((string) ($stats['uuid'] ?? ''));
    $name = (string) ($stats['name'] ?? '');
    if (!preg_match(MC_UUID_PATTERN, $uuid) || !preg_match(MC_NAME_PATTERN, $name)) {
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

    // Plugin 1.3.0+ : inventaire du joueur
    if (isset($stats['inventory']) && is_array($stats['inventory']) && $stats['inventory']) {
        saveInventory($uuid, $stats['inventory']);
    }
}

// Liste complète des joueurs connectés : tous les autres passent hors ligne
if (isset($body['online']) && is_array($body['online'])) {
    $online = array_values(array_filter(
        array_map(fn ($uuid) => strtolower((string) $uuid), $body['online']),
        fn ($uuid) => preg_match(MC_UUID_PATTERN, $uuid),
    ));
    db()->exec('UPDATE player_stats SET online = 0');
    if ($online) {
        $placeholders = implode(',', array_fill(0, count($online), '?'));
        db()->prepare("UPDATE player_stats SET online = 1 WHERE mc_uuid IN ($placeholders)")->execute($online);
    }
}

respond(200, ['success' => true]);
