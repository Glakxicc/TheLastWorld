<?php
// Joueur connecté (pour le tableau de bord et le bouton de connexion).

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

allowMethods('GET');

$player = currentPlayer();
respond(200, [
    'loggedIn' => $player !== null,
    'player' => $player ? publicPlayer($player) : null,
    'isStaff' => $player !== null && playerIsStaff($player),
    'rank' => $player ? playerRank($player) : null,
]);
