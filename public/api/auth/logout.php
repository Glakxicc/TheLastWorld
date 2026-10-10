<?php
// Déconnexion depuis le tableau de bord.

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

allowMethods('POST');
readJsonBody();
logout();
respond(200, ['success' => true]);
