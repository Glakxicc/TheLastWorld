<?php
// Dernier inventaire connu d'un joueur whitelisté (envoyé par le plugin TLWStats). Réservé au staff.
// GET ?playerId=…

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

allowMethods('GET');
requireStaff();

$player = findPlayerById((int) ($_GET['playerId'] ?? 0));
if ($player === null) {
    fail(404, 'Joueur introuvable.');
}
if (empty($player['mc_uuid'])) {
    fail(404, "Ce joueur n'a pas de compte Minecraft lié.");
}

$inventory = findInventory($player['mc_uuid']);
if ($inventory === null) {
    fail(404, "Le serveur n'a pas encore envoyé l'inventaire de ce joueur : il apparaîtra à sa prochaine connexion.");
}

$online = db()->prepare('SELECT online FROM player_stats WHERE mc_uuid = ?');
$online->execute([$player['mc_uuid']]);

respond(200, [
    'username' => $player['mc_username'],
    'online' => (bool) $online->fetchColumn(),
] + $inventory);
