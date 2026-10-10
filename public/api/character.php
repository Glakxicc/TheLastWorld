<?php
// Mise à jour de la fiche personnage depuis le tableau de bord.

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

allowMethods('POST');
$player = requirePlayer();
$body = readJsonBody();

[$values, $error] = validateFields($body, characterFields());
if ($error !== null) {
    fail(400, $error);
}

updateCharacter((int) $player['id'], $values);
respond(200, ['success' => true, 'player' => publicPlayer(findPlayerById((int) $player['id']))]);
