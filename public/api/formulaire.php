<?php
// Reçoit le formulaire de whitelist, l'enregistre et l'envoie au Discord du staff.

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

const RATE_LIMIT_WINDOW = 600; // secondes
const RATE_LIMIT_MAX = 5;

allowMethods('POST');
$body = readJsonBody();

if (!checkRateLimit($_SERVER['REMOTE_ADDR'] ?? 'unknown')) {
    fail(429, 'Trop de demandes, réessayez dans quelques minutes.');
}

// Honeypot : un humain ne remplit jamais ce champ caché
if (!empty($body['website'])) {
    respond(200, ['success' => true]);
}

[$values, $error] = validateFields($body, FORM_FIELDS);
if ($error !== null) {
    fail(400, $error);
}
foreach (FORM_TOGGLES as $toggle) {
    $values[$toggle['key']] = ($body[$toggle['key']] ?? false) === true;
}

// Si la base est indisponible, le formulaire part quand même (sans boutons)
try {
    db()->prepare('INSERT INTO applications (discord_username, data, created_at) VALUES (?, ?, ?)')
        ->execute([normalizeUsername($values['discord']), json_encode($values, JSON_UNESCAPED_UNICODE), now()]);
    $applicationId = (int) db()->lastInsertId();
} catch (PDOException $err) {
    error_log('formulaire.php : candidature non enregistrée : ' . $err->getMessage());
    $applicationId = null;
}

if (!sendApplication($applicationId, $values)) {
    fail(502, 'Impossible de transmettre le formulaire. Réessayez plus tard.');
}

respond(200, ['success' => true]);

// --- Helpers ---

/**
 * Avec le bot configuré : message avec boutons Accepter/Refuser dans le salon du staff.
 * Sinon : simple webhook (sans boutons).
 */
function sendApplication(?int $applicationId, array $values): bool
{
    $discord = config()['discord'] ?? [];
    $message = [
        'allowed_mentions' => ['parse' => []],
        'embeds' => [applicationEmbed($values)],
    ];

    if (!empty($discord['bot_token']) && !empty($discord['forms_channel_id'])) {
        if ($applicationId !== null) {
            $message['components'] = applicationButtons($applicationId);
        }
        [$status, $sent] = discordBot('POST', "/channels/{$discord['forms_channel_id']}/messages", $message);

        // Pour mettre à jour ce message quand la décision est prise depuis le site
        if ($applicationId !== null && !empty($sent['id'])) {
            db()->prepare('UPDATE applications SET discord_message_id = ? WHERE id = ?')
                ->execute([$sent['id'], $applicationId]);
        }
    } elseif (!empty(config()['discord_webhook_url'])) {
        $curl = curl_init(config()['discord_webhook_url']);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    } else {
        error_log('formulaire.php : ni bot ni webhook configuré dans config.php');
        return false;
    }

    if ($status < 200 || $status >= 300) {
        error_log("formulaire.php : Discord a répondu $status");
        return false;
    }
    return true;
}

// Limiteur par IP, stocké dans data/ratelimit.json
function checkRateLimit(string $ip): bool
{
    if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0755, true)) {
        return true;
    }

    $handle = fopen(DATA_DIR . '/ratelimit.json', 'c+');
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
