<?php
// Reçoit le formulaire de whitelist, le valide et l'envoie au Discord du staff.

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// --- Config ---

const RATE_LIMIT_WINDOW = 600; // secondes
const RATE_LIMIT_MAX = 5;
const MAX_BODY_SIZE = 16384;

// Champs du formulaire whitelist (clé envoyée par le client => libellé Discord)
const FORM_FIELDS = [
    ['key' => 'discord', 'label' => 'Discord', 'maxLength' => 64],
    ['key' => 'age_irl', 'label' => 'Âge IRL', 'maxLength' => 3, 'numeric' => true],
    ['key' => 'first_name', 'label' => 'Prénom Personnage', 'maxLength' => 64],
    ['key' => 'last_name', 'label' => 'Nom Personnage', 'maxLength' => 64],
    ['key' => 'age_character', 'label' => 'Âge RP', 'maxLength' => 4, 'numeric' => true],
    ['key' => 'rp_born', 'label' => 'Lieu de naissance', 'maxLength' => 128],
    ['key' => 'rp_experience', 'label' => 'Expérience RP', 'maxLength' => 1024],
    ['key' => 'rp_story', 'label' => 'Histoire du Personnage', 'maxLength' => 1024],
];

const FORM_TOGGLES = [
    ['key' => 'illegal', 'label' => 'RP Rebelle ?'],
    ['key' => 'staff', 'label' => 'Demande à être staff ?'],
];

$config = is_file(__DIR__ . '/config.php') ? require __DIR__ . '/config.php' : [];
$webhookUrl = $config['discord_webhook_url'] ?? '';
$allowedOrigins = $config['allowed_origins'] ?? [];

// --- CORS ---
// Inutile en production (même domaine), sert pour tester depuis Live Server

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Vary: Origin');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- Requête ---

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['success' => false, 'error' => 'Méthode non autorisée.']);
}

if ($webhookUrl === '') {
    error_log('formulaire.php : discord_webhook_url manquant dans config.php');
    respond(503, ['success' => false, 'error' => 'Formulaire indisponible.']);
}

$raw = file_get_contents('php://input', false, null, 0, MAX_BODY_SIZE + 1);
$body = strlen((string) $raw) <= MAX_BODY_SIZE ? json_decode((string) $raw, true) : null;
if (!is_array($body)) {
    respond(400, ['success' => false, 'error' => 'Requête invalide.']);
}

if (!checkRateLimit($_SERVER['REMOTE_ADDR'] ?? 'unknown')) {
    respond(429, ['success' => false, 'error' => 'Trop de demandes, réessayez dans quelques minutes.']);
}

// Honeypot : un humain ne remplit jamais ce champ caché
if (!empty($body['website'])) {
    respond(200, ['success' => true]);
}

[$values, $error] = validateForm($body);
if ($error !== null) {
    respond(400, ['success' => false, 'error' => $error]);
}

if (!sendToDiscord($webhookUrl, $values)) {
    respond(502, [
        'success' => false,
        'error' => 'Impossible de transmettre le formulaire. Réessayez plus tard.',
    ]);
}

respond(200, ['success' => true]);

// --- Helpers ---

function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

function textLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function validateForm(array $body): array
{
    $values = [];

    foreach (FORM_FIELDS as $field) {
        $raw = $body[$field['key']] ?? '';
        $value = is_scalar($raw) ? trim((string) $raw) : '';
        $label = $field['label'];

        if ($value === '') {
            return [null, "Le champ « $label » est requis."];
        }
        if (textLength($value) > $field['maxLength']) {
            return [null, "Le champ « $label » est trop long."];
        }
        if (!empty($field['numeric']) && !ctype_digit($value)) {
            return [null, "Le champ « $label » doit être un nombre."];
        }
        $values[$field['key']] = $value;
    }

    foreach (FORM_TOGGLES as $toggle) {
        $values[$toggle['key']] = ($body[$toggle['key']] ?? false) === true;
    }

    return [$values, null];
}

function sendToDiscord(string $webhookUrl, array $values): bool
{
    $fields = [];
    foreach (FORM_FIELDS as $field) {
        $fields[] = [
            'name' => $field['label'],
            'value' => $values[$field['key']],
            'inline' => $field['maxLength'] < 1024,
        ];
    }
    foreach (FORM_TOGGLES as $toggle) {
        $fields[] = [
            'name' => $toggle['label'],
            'value' => $values[$toggle['key']] ? 'Oui' : 'Non',
            'inline' => true,
        ];
    }

    $payload = json_encode([
        'allowed_mentions' => ['parse' => []],
        'embeds' => [[
            'title' => 'Formulaire TLW',
            'description' => 'Formulaire du site',
            'color' => 0xff4242,
            'fields' => $fields,
            'timestamp' => gmdate('c'),
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $curl = curl_init($webhookUrl);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ($response === false || $status < 200 || $status >= 300) {
        error_log("formulaire.php : Discord a répondu $status " . curl_error($curl));
        return false;
    }
    return true;
}

// Limiteur par IP, stocké dans data/ratelimit.json
function checkRateLimit(string $ip): bool
{
    $dir = __DIR__ . '/data';
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return true;
    }

    $handle = fopen("$dir/ratelimit.json", 'c+');
    if ($handle === false) {
        return true;
    }

    flock($handle, LOCK_EX);
    $now = time();
    $data = json_decode((string) stream_get_contents($handle), true) ?: [];

    foreach ($data as $key => $times) {
        $data[$key] = array_values(array_filter(
            $times,
            fn ($time) => $now - $time < RATE_LIMIT_WINDOW,
        ));
        if (!$data[$key]) {
            unset($data[$key]);
        }
    }

    $allowed = count($data[$ip] ?? []) < RATE_LIMIT_MAX;
    if ($allowed) {
        $data[$ip][] = $now;
    }

    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($data));
    flock($handle, LOCK_UN);
    fclose($handle);

    return $allowed;
}
