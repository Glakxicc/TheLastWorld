<?php
// Session du joueur connecté.

declare(strict_types=1);

const SESSION_LIFETIME = 30 * 24 * 3600;

function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Dossier propre au site : sur un hébergement mutualisé, le dossier partagé
    // est nettoyé avec une durée bien plus courte.
    $path = DATA_DIR . '/sessions';
    if (!is_dir($path)) {
        mkdir($path, 0700, true);
    }
    session_save_path($path);
    ini_set('session.gc_maxlifetime', (string) SESSION_LIFETIME);
    ini_set('session.use_strict_mode', '1');

    session_name('tlw_session');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path' => '/',
        'secure' => isHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Joueur connecté, ou null. Un joueur retiré de la whitelist est déconnecté. */
function currentPlayer(): ?array
{
    startSession();
    $playerId = $_SESSION['player_id'] ?? null;
    if ($playerId === null) {
        return null;
    }

    $player = findPlayerById((int) $playerId);
    if ($player === null || !$player['whitelisted']) {
        logout();
        return null;
    }
    return $player;
}

function requirePlayer(): array
{
    $player = currentPlayer();
    if ($player === null) {
        fail(401, 'Vous devez être connecté.');
    }
    return $player;
}

function login(int $playerId): void
{
    startSession();
    session_regenerate_id(true);
    $_SESSION['player_id'] = $playerId;
}

function logout(): void
{
    startSession();
    $_SESSION = [];
    session_destroy();
    setcookie('tlw_session', '', ['expires' => 1, 'path' => '/', 'secure' => isHttps(), 'httponly' => true, 'samesite' => 'Lax']);
}
