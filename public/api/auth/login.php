<?php
// Redirige vers Discord pour la connexion.

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

$clientId = config()['discord']['client_id'] ?? '';
if ($clientId === '') {
    redirect('/pages/joinus.html?connexion=indisponible');
}

startSession();
$_SESSION['oauth_state'] = bin2hex(random_bytes(16));

redirect('https://discord.com/oauth2/authorize?' . http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => siteUrl() . '/api/auth/callback.php',
    'response_type' => 'code',
    'scope' => 'identify',
    'state' => $_SESSION['oauth_state'],
    'prompt' => 'none',
]));
