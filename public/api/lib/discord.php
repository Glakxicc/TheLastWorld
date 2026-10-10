<?php
// Appels à l'API Discord (bot, OAuth) et vérification des interactions.

declare(strict_types=1);

const DISCORD_API = 'https://discord.com/api/v10';

/**
 * Requête HTTP vers Discord. $body : tableau (JSON) ou chaîne déjà encodée (formulaire).
 * Retourne [code HTTP, réponse décodée].
 */
function discordRequest(string $method, string $path, array|string|null $body = null, array $headers = []): array
{
    $curl = curl_init(DISCORD_API . $path);
    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if (is_array($body)) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    } elseif (is_string($body)) {
        $options[CURLOPT_POSTFIELDS] = $body;
        $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
    }

    curl_setopt_array($curl, $options);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

    if ($response === false) {
        error_log("Discord $method $path : " . curl_error($curl));
        return [0, null];
    }
    return [$status, json_decode((string) $response, true)];
}

function discordBot(string $method, string $path, ?array $body = null): array
{
    $token = config()['discord']['bot_token'] ?? '';
    return discordRequest($method, $path, $body, ["Authorization: Bot $token"]);
}

/** Les pseudos Discord sont uniques et en minuscules ; on tolère « @pseudo » et « pseudo#0 ». */
function normalizeUsername(string $username): string
{
    $username = strtolower(trim($username));
    $username = ltrim($username, '@');
    return preg_replace('/#0$/', '', $username);
}

/** Vérifie la signature Ed25519 envoyée par Discord sur chaque interaction. */
function verifyDiscordSignature(string $body, string $signature, string $timestamp, string $publicKey): bool
{
    if (!ctype_xdigit($signature) || !ctype_xdigit($publicKey) || strlen($publicKey) !== 64) {
        return false;
    }
    try {
        return sodium_crypto_sign_verify_detached(hex2bin($signature), $timestamp . $body, hex2bin($publicKey));
    } catch (SodiumException) {
        return false;
    }
}

/**
 * Membre du serveur Discord TheLastWorld, cherché par identifiant via le bot.
 * Retourne [id, pseudo] ou lève une InvalidArgumentException.
 */
function findGuildMember(string $discordId): array
{
    $discordId = trim($discordId);
    if (!preg_match(DISCORD_ID_PATTERN, $discordId)) {
        throw new InvalidArgumentException("Identifiant Discord invalide (17 à 20 chiffres).");
    }

    $guildId = config()['discord']['guild_id'] ?? '';
    [$status, $member] = discordBot('GET', "/guilds/$guildId/members/$discordId");
    if ($status === 404) {
        throw new InvalidArgumentException("Le compte $discordId n'est pas membre du serveur Discord TheLastWorld.");
    }
    if ($status !== 200 || empty($member['user']['id'])) {
        throw new RuntimeException("Discord a répondu $status à la recherche du membre $discordId");
    }
    return [$member['user']['id'], $member['user']['username']];
}

// --- Messages ---

const COLOR_RED = 0xff4242;
const COLOR_GREEN = 0x25d940;
const COLOR_GREY = 0x808080;

/** Embed d'une candidature, tel qu'affiché dans le salon du staff. */
function applicationEmbed(array $values, int $color = COLOR_RED): array
{
    $fields = [];
    foreach (FORM_FIELDS as $field) {
        $value = $values[$field['key']] ?? '—';
        if ($field['key'] === 'discord_id' && $value !== '—') {
            $value = "<@$value> (`$value`)";
        }
        $fields[] = [
            'name' => $field['label'],
            'value' => $value,
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

    return [
        'title' => 'Formulaire TLW',
        'description' => 'Formulaire du site',
        'color' => $color,
        'fields' => $fields,
        'timestamp' => now(),
    ];
}

function applicationButtons(int $applicationId): array
{
    return [[
        'type' => 1,
        'components' => [
            ['type' => 2, 'style' => 3, 'label' => 'Accepter', 'custom_id' => "app:accept:$applicationId"],
            ['type' => 2, 'style' => 4, 'label' => 'Refuser', 'custom_id' => "app:refuse:$applicationId"],
        ],
    ]];
}

function statusMessage(): array
{
    $status = getSetting('server_status', 'open');
    $buttons = [];
    foreach (SERVER_STATUSES as $key => $label) {
        $buttons[] = [
            'type' => 2,
            'style' => $key === 'open' ? 3 : 4,
            'label' => $key === 'open' ? 'Ouvrir' : 'Fermer',
            'custom_id' => "status:$key",
            'disabled' => $key === $status,
        ];
    }

    return [
        'embeds' => [[
            'title' => 'Statut du serveur',
            'description' => 'Affiché sur le site : **' . SERVER_STATUSES[$status] . '**',
            'color' => $status === 'open' ? COLOR_GREEN : COLOR_RED,
        ]],
        'components' => [['type' => 1, 'components' => $buttons]],
        'allowed_mentions' => ['parse' => []],
    ];
}
