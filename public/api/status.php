<?php
// Statut du serveur affiché sur le site (modifiable depuis Discord avec /statut).

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

allowMethods('GET');

$status = getSetting('server_status', 'open');
respond(200, [
    'status' => $status,
    'label' => SERVER_STATUSES[$status] ?? $status,
    'address' => config()['minecraft_address'] ?? '',
]);
