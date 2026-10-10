<?php
// Retour de Discord après la connexion : on ouvre la session si le joueur est whitelisté.

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

const JOINUS_PAGE = '/pages/joinus.html';
const ON_ERROR_REDIRECT = JOINUS_PAGE . '?connexion=erreur-serveur';

startSession();
$expectedState = $_SESSION['oauth_state'] ?? '';
unset($_SESSION['oauth_state']);

if (isset($_GET['error'])) {
    redirect(JOINUS_PAGE . '?connexion=annulee');
}
// Session perdue entre le départ vers Discord et le retour (cookies bloqués…)
if ($expectedState === '' || !hash_equals($expectedState, (string) ($_GET['state'] ?? ''))) {
    error_log('callback.php : state OAuth absent ou différent');
    redirect(JOINUS_PAGE . '?connexion=erreur-session');
}

$discord = config()['discord'] ?? [];
[$status, $token] = discordRequest('POST', '/oauth2/token', http_build_query([
    'client_id' => $discord['client_id'] ?? '',
    'client_secret' => $discord['client_secret'] ?? '',
    'grant_type' => 'authorization_code',
    'code' => (string) ($_GET['code'] ?? ''),
    'redirect_uri' => siteUrl() . '/api/auth/callback.php',
]));
if ($status !== 200 || empty($token['access_token'])) {
    error_log("callback.php : échange du code refusé ($status) " . json_encode($token));
    redirect(JOINUS_PAGE . '?connexion=erreur-discord');
}

[$status, $user] = discordRequest('GET', '/users/@me', null, ["Authorization: Bearer {$token['access_token']}"]);
if ($status !== 200 || empty($user['id'])) {
    error_log("callback.php : lecture du profil Discord refusée ($status)");
    redirect(JOINUS_PAGE . '?connexion=erreur-discord');
}

$player = findPlayerByDiscord($user['id'], $user['username']);
if ($player === null || !$player['whitelisted']) {
    // Le staff du serveur Discord a toujours accès au site
    if (!discordUserIsStaff($user['id'])) {
        redirect(JOINUS_PAGE . '?connexion=non-whitelist');
    }
    $player = findPlayerById(whitelistPlayer($user['id'], $user['username'], 'staff (automatique)'));
}

db()->prepare(
    'UPDATE players SET discord_id = ?, display_name = ?, avatar = ?, last_login_at = ? WHERE id = ?',
)->execute([$user['id'], $user['global_name'] ?? $user['username'], $user['avatar'] ?? null, now(), $player['id']]);

// Le pseudo Discord peut avoir changé : on le met à jour s'il n'est pas déjà pris
try {
    db()->prepare('UPDATE players SET discord_username = ? WHERE id = ?')
        ->execute([normalizeUsername($user['username']), $player['id']]);
} catch (PDOException) {
}

login((int) $player['id']);
redirect('/pages/dashboard.html');
