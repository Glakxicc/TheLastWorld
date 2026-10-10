<?php
// Point d'entrée commun à tous les scripts de l'API.

declare(strict_types=1);

require_once __DIR__ . '/response.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/discord.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/form.php';
require_once __DIR__ . '/actions.php';
require_once __DIR__ . '/staff.php';

const DATA_DIR = __DIR__ . '/../data';

// Erreur imprévue (base injoignable…) : on la journalise et on répond proprement
// au lieu d'une page blanche.
set_exception_handler(function (Throwable $err): void {
    error_log('TLW : ' . get_class($err) . ' : ' . $err->getMessage() . ' (' . $err->getFile() . ':' . $err->getLine() . ')');
    if (defined('ON_ERROR_REDIRECT')) {
        redirect(ON_ERROR_REDIRECT);
    }
    fail(500, 'Erreur serveur, réessayez plus tard.');
});

/**
 * Configuration : api/config.php (mis en ligne), surchargée en local par
 * config.local.php à la racine du dépôt (jamais mis en ligne).
 */
function config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = is_file(__DIR__ . '/../config.php') ? require __DIR__ . '/../config.php' : [];

    // Uniquement avec `php -S` ou en ligne de commande : jamais sur IONOS
    $localFile = dirname(__DIR__, 3) . '/config.local.php';
    if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && is_file($localFile)) {
        $config = array_replace_recursive($config, require $localFile);
    }

    return $config;
}

/**
 * Adresse du site, déduite du domaine visité (ex. https://www.thelastworld.fr).
 * Sert d'adresse de retour pour la connexion Discord : elle doit correspondre
 * exactement à un « Redirect » enregistré dans le portail Discord.
 */
function siteUrl(): string
{
    $host = strtolower($_SERVER['HTTP_HOST'] ?? 'localhost');
    $isLocal = (bool) preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host);

    // En ligne, le site est toujours en HTTPS (même si l'hébergeur ne le signale pas à PHP)
    $scheme = $isLocal && !isHttps() ? 'http' : 'https';
    return "$scheme://$host";
}

function isHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function now(): string
{
    return gmdate('c');
}

function textLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}
