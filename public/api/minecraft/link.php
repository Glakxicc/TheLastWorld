<?php
// Liaison du compte Minecraft du joueur connecté.
// POST {code} : code affiché par le serveur Minecraft. DELETE : délie le compte.

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

const LINK_MAX_ATTEMPTS = 10;
const LINK_ATTEMPTS_WINDOW = 900;

allowMethods('POST', 'DELETE');
$player = requirePlayer();

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    unlinkMinecraftAccount((int) $player['id']);
    respond(200, ['success' => true, 'player' => publicPlayer(findPlayerById((int) $player['id']))]);
}

// Limite les essais pour empêcher de deviner le code d'un autre joueur
$attempts = array_filter($_SESSION['link_attempts'] ?? [], fn ($time) => $time > time() - LINK_ATTEMPTS_WINDOW);
if (count($attempts) >= LINK_MAX_ATTEMPTS) {
    fail(429, 'Trop d\'essais : réessayez dans quelques minutes.');
}

$body = readJsonBody();
try {
    linkMinecraftAccount($player, (string) ($body['code'] ?? ''));
} catch (InvalidArgumentException $err) {
    $attempts[] = time();
    $_SESSION['link_attempts'] = array_values($attempts);
    fail(400, $err->getMessage());
}

unset($_SESSION['link_attempts']);
respond(200, ['success' => true, 'player' => publicPlayer(findPlayerById((int) $player['id']))]);
