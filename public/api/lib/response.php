<?php
// Réponses JSON, lecture du corps de requête et CORS.

declare(strict_types=1);

const MAX_BODY_SIZE = 16384;

function respond(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $status, string $error): void
{
    respond($status, ['success' => false, 'error' => $error]);
}

function redirect(string $url): void
{
    header('Location: ' . $url, true, 302);
    exit;
}

/** Méthodes autorisées ; répond aussi aux requêtes de pré-vérification CORS. */
function allowMethods(string ...$methods): void
{
    applyCors($methods);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    if (!in_array($method, $methods, true)) {
        fail(405, 'Méthode non autorisée.');
    }
}

/**
 * Inutile en production (pages et API sur le même domaine) : sert pour les pages
 * ouvertes avec Live Server pendant le développement.
 */
function applyCors(array $methods): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '' || !in_array($origin, config()['allowed_origins'] ?? [], true)) {
        return;
    }
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: ' . implode(', ', [...$methods, 'OPTIONS']));
    header('Access-Control-Allow-Headers: Content-Type');
    header('Vary: Origin');
}

/**
 * Corps JSON de la requête. Exiger application/json protège aussi contre le CSRF :
 * un autre site ne peut pas l'envoyer sans passer par CORS.
 */
function readJsonBody(): array
{
    if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        fail(415, 'Requête invalide.');
    }
    $raw = (string) file_get_contents('php://input', false, null, 0, MAX_BODY_SIZE + 1);
    $body = strlen($raw) <= MAX_BODY_SIZE ? json_decode($raw, true) : null;
    if (!is_array($body)) {
        fail(400, 'Requête invalide.');
    }
    return $body;
}
