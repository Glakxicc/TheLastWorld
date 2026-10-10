<?php
// Bot Discord du staff. Discord envoie ici chaque commande / bouton / formulaire
// (« Interactions Endpoint URL » dans le portail développeur) : pas de bot à garder allumé.

declare(strict_types=1);

require __DIR__ . '/../lib/bootstrap.php';

// Types d'interaction et de réponse (https://discord.com/developers/docs/interactions)
const PING = 1;
const APPLICATION_COMMAND = 2;
const MESSAGE_COMPONENT = 3;
const MODAL_SUBMIT = 5;

const REPLY_PONG = 1;
const REPLY_MESSAGE = 4;
const REPLY_UPDATE_MESSAGE = 7;
const REPLY_MODAL = 9;

const EPHEMERAL = 64;

// --- Vérification de la signature ---

$raw = (string) file_get_contents('php://input');
$verified = verifyDiscordSignature(
    $raw,
    $_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? '',
    $_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? '',
    config()['discord']['public_key'] ?? '',
);
if (!$verified) {
    http_response_code(401);
    exit('invalid request signature');
}

$interaction = json_decode($raw, true) ?? [];

if (($interaction['type'] ?? 0) === PING) {
    respond(200, ['type' => REPLY_PONG]);
}

if (!isStaff($interaction)) {
    reply('⛔ Réservé au staff de TheLastWorld.', ephemeral: true);
}

try {
    match ($interaction['type'] ?? 0) {
        APPLICATION_COMMAND => handleCommand($interaction),
        MESSAGE_COMPONENT => handleButton($interaction),
        MODAL_SUBMIT => handleModal($interaction),
        default => reply('Interaction inconnue.', ephemeral: true),
    };
} catch (InvalidArgumentException $err) {
    reply('⚠️ ' . $err->getMessage(), ephemeral: true);
} catch (Throwable $err) {
    error_log('interactions.php : ' . $err->getMessage());
    reply('❌ Une erreur est survenue, réessayez.', ephemeral: true);
}

// --- Commandes ---

function handleCommand(array $interaction): void
{
    $data = $interaction['data'];
    $subcommand = $data['options'][0] ?? [];
    $options = array_column($subcommand['options'] ?? [], 'value', 'name');

    match ($data['name'] . ' ' . ($subcommand['name'] ?? '')) {
        'whitelist ajouter' => commandWhitelistAdd($interaction, $options),
        'whitelist retirer' => commandWhitelistRemove($interaction, $options),
        'whitelist liste' => commandWhitelistList(),
        'post publier' => commandPostCreate($interaction, $options),
        'post supprimer' => commandPostDelete($options),
        'post liste' => commandPostList($options),
        'statut ' => respond(200, ['type' => REPLY_MESSAGE, 'data' => statusMessage()]),
        default => reply('Commande inconnue.', ephemeral: true),
    };
}

function commandWhitelistAdd(array $interaction, array $options): void
{
    $user = $interaction['data']['resolved']['users'][$options['joueur']];

    // Si le joueur a envoyé le formulaire, sa fiche personnage est reprise
    $application = findPendingApplication($user['id'], $user['username']);
    $character = $application ? json_decode($application['data'], true) : [];

    whitelistPlayer($user['id'], $user['username'], staffName($interaction), $character);
    if ($application) {
        [$application, $text, $color] = reviewApplication((int) $application['id'], 'accept', staffName($interaction));
        syncApplicationMessage($application, $text, $color);
    }

    reply("✅ <@{$user['id']}> est whitelisté : il peut se connecter sur le site.");
}

function commandWhitelistRemove(array $interaction, array $options): void
{
    $user = $interaction['data']['resolved']['users'][$options['joueur']];

    if (!unwhitelistPlayer($user['id'], $user['username'])) {
        reply("<@{$user['id']}> n'est pas whitelisté.", ephemeral: true);
    }
    reply("🚫 <@{$user['id']}> n'a plus accès au site.");
}

function commandWhitelistList(): void
{
    $players = db()
        ->query('SELECT * FROM players WHERE whitelisted = 1 ORDER BY whitelisted_at')
        ->fetchAll();

    if (!$players) {
        reply('Aucun joueur whitelisté pour le moment.', ephemeral: true);
    }

    $lines = array_map(function (array $player) {
        $who = $player['discord_id'] ? "<@{$player['discord_id']}>" : "`{$player['discord_username']}` (jamais connecté)";
        $character = trim(($player['first_name'] ?? '') . ' ' . ($player['last_name'] ?? ''));
        return "• $who" . ($character !== '' ? " — $character" : '');
    }, $players);

    reply('**Joueurs whitelistés (' . count($players) . ")**\n" . truncateLines($lines), ephemeral: true);
}

function commandPostCreate(array $interaction, array $options): void
{
    $category = $options['categorie'];
    $targetId = '';

    // Annonce membres réservée à un seul joueur
    if (isset($options['joueur'])) {
        if ($category !== 'membres') {
            reply('⚠️ Seules les annonces membres peuvent viser un joueur.', ephemeral: true);
        }
        $user = $interaction['data']['resolved']['users'][$options['joueur']];
        $target = findPlayerByDiscord($user['id'], $user['username']);
        if ($target === null || !$target['whitelisted']) {
            reply("⚠️ <@{$user['id']}> n'est pas whitelisté.", ephemeral: true);
        }
        $targetId = (string) $target['id'];
    }

    respond(200, [
        'type' => REPLY_MODAL,
        'data' => [
            'custom_id' => "post:create:$category:$targetId",
            'title' => 'Publier : ' . POST_CATEGORIES[$category] . ($targetId !== '' ? ' (personnelle)' : ''),
            'components' => [
                ['type' => 1, 'components' => [[
                    'type' => 4, 'custom_id' => 'title', 'label' => 'Titre',
                    'style' => 1, 'max_length' => 256, 'required' => true,
                ]]],
                ['type' => 1, 'components' => [[
                    'type' => 4, 'custom_id' => 'content', 'label' => 'Contenu',
                    'style' => 2, 'max_length' => 4000, 'required' => true,
                ]]],
            ],
        ],
    ]);
}

function commandPostDelete(array $options): void
{
    if (!deletePost((int) $options['id'])) {
        reply("Aucune publication n°{$options['id']}.", ephemeral: true);
    }
    reply("🗑️ Publication n°{$options['id']} supprimée du site.");
}

function commandPostList(array $options): void
{
    $posts = listPosts($options['categorie'] ?? null);
    if (!$posts) {
        reply('Aucune publication.', ephemeral: true);
    }

    $lines = array_map(
        fn ($post) => "`#{$post['id']}` [" . POST_CATEGORIES[$post['category']] . "] {$post['title']}"
            . ($post['target_username'] ? " → `{$post['target_username']}`" : '')
            . ' — <t:' . strtotime($post['created_at']) . ':d>',
        $posts,
    );
    reply("**Publications**\n" . truncateLines($lines), ephemeral: true);
}

// --- Boutons ---

function handleButton(array $interaction): void
{
    $parts = explode(':', $interaction['data']['custom_id']);

    match ($parts[0]) {
        'status' => buttonStatus($parts[1]),
        'app' => buttonApplication($interaction, $parts[1], (int) $parts[2]),
        default => reply('Bouton inconnu.', ephemeral: true),
    };
}

function buttonStatus(string $status): void
{
    setServerStatus($status);
    respond(200, ['type' => REPLY_UPDATE_MESSAGE, 'data' => statusMessage()]);
}

function buttonApplication(array $interaction, string $decision, int $applicationId): void
{
    [$application, $text, $color] = reviewApplication($applicationId, $decision, staffName($interaction));
    $embed = reviewedApplicationEmbed($application, $text, $color, $interaction['message']['embeds'][0] ?? null);

    respond(200, [
        'type' => REPLY_UPDATE_MESSAGE,
        'data' => ['embeds' => [$embed], 'components' => []],
    ]);
}

// --- Formulaires (modals) ---

function handleModal(array $interaction): void
{
    [$kind, $action, $category, $targetId] = array_pad(explode(':', $interaction['data']['custom_id']), 4, '');
    if ($kind !== 'post' || $action !== 'create') {
        reply('Formulaire inconnu.', ephemeral: true);
    }

    $fields = [];
    foreach ($interaction['data']['components'] as $row) {
        foreach ($row['components'] as $input) {
            $fields[$input['custom_id']] = (string) $input['value'];
        }
    }

    $target = $targetId !== '' ? findPlayerById((int) $targetId) : null;
    $postId = createPost(
        $category,
        $fields['title'] ?? '',
        $fields['content'] ?? '',
        staffName($interaction),
        $target ? (int) $target['id'] : null,
    );

    $audience = $target ? " pour `{$target['discord_username']}` uniquement" : '';
    reply('📰 Publié dans **' . POST_CATEGORIES[$category] . "**$audience (n°$postId) : " . trim($fields['title']));
}

// --- Helpers ---

function reply(string $content, bool $ephemeral = false): void
{
    respond(200, [
        'type' => REPLY_MESSAGE,
        'data' => [
            'content' => $content,
            'flags' => $ephemeral ? EPHEMERAL : 0,
            'allowed_mentions' => ['parse' => []],
        ],
    ]);
}

/** Staff = administrateur du serveur Discord ou membre d'un des rôles staff configurés. */
function isStaff(array $interaction): bool
{
    $member = $interaction['member'] ?? null;
    $guildId = config()['discord']['guild_id'] ?? '';
    if ($member === null || ($guildId !== '' && ($interaction['guild_id'] ?? '') !== $guildId)) {
        return false;
    }

    if (((int) ($member['permissions'] ?? 0)) & PERMISSION_ADMINISTRATOR) {
        return true;
    }
    $staffRoles = config()['discord']['staff_role_ids'] ?? [];
    return (bool) array_intersect($member['roles'] ?? [], $staffRoles);
}

function staffName(array $interaction): string
{
    $user = $interaction['member']['user'] ?? [];
    return $user['global_name'] ?? $user['username'] ?? 'staff';
}

/** Discord limite un message à 2000 caractères. */
function truncateLines(array $lines, int $max = 1800): string
{
    $text = '';
    foreach ($lines as $index => $line) {
        if (textLength($text . $line) > $max) {
            return $text . '… et ' . (count($lines) - $index) . ' de plus';
        }
        $text .= $line . "\n";
    }
    return $text;
}
