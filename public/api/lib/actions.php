<?php
// Actions du staff, partagées entre le bot Discord et le panneau staff du site.
// En cas de saisie invalide, elles lèvent une InvalidArgumentException au message affichable.

declare(strict_types=1);

const POST_CATEGORIES = [
    'actus' => 'Actus',
    'devlog' => 'DevLogs',
    'membres' => 'Annonces membres',
];

// --- Publications ---

function createPost(string $category, string $title, string $content, string $author, ?int $targetPlayerId = null): int
{
    $title = trim($title);
    $content = trim($content);

    if (!isset(POST_CATEGORIES[$category])) {
        throw new InvalidArgumentException('Catégorie inconnue.');
    }
    if ($title === '' || textLength($title) > 256) {
        throw new InvalidArgumentException('Le titre est requis (256 caractères maximum).');
    }
    if ($content === '' || textLength($content) > 4000) {
        throw new InvalidArgumentException('Le contenu est requis (4000 caractères maximum).');
    }
    if ($targetPlayerId !== null) {
        if ($category !== 'membres') {
            throw new InvalidArgumentException('Seules les annonces membres peuvent viser un joueur.');
        }
        $target = findPlayerById($targetPlayerId);
        if ($target === null || !$target['whitelisted']) {
            throw new InvalidArgumentException("Ce joueur n'est pas whitelisté.");
        }
    }

    db()->prepare(
        'INSERT INTO posts (category, title, content, author, target_player_id, created_at) VALUES (?, ?, ?, ?, ?, ?)',
    )->execute([$category, $title, $content, $author, $targetPlayerId, now()]);

    return (int) db()->lastInsertId();
}

function deletePost(int $id): bool
{
    $statement = db()->prepare('DELETE FROM posts WHERE id = ?');
    $statement->execute([$id]);
    return $statement->rowCount() > 0;
}

/** Dernières publications, avec le joueur visé pour les annonces personnelles. */
function listPosts(?string $category = null, int $limit = 25): array
{
    $statement = db()->prepare(
        'SELECT posts.*, players.discord_username AS target_username FROM posts
         LEFT JOIN players ON players.id = posts.target_player_id'
        . ($category ? ' WHERE posts.category = ?' : '')
        . ' ORDER BY posts.created_at DESC, posts.id DESC LIMIT ' . (int) $limit,
    );
    $statement->execute($category ? [$category] : []);
    return $statement->fetchAll();
}

// --- Statut du serveur ---

function setServerStatus(string $status): void
{
    if (!isset(SERVER_STATUSES[$status])) {
        throw new InvalidArgumentException('Statut inconnu.');
    }
    setSetting('server_status', $status);
}

// --- Candidatures ---

function pendingApplications(): array
{
    return db()
        ->query("SELECT * FROM applications WHERE status = 'pending' ORDER BY id DESC LIMIT 50")
        ->fetchAll();
}

/** Candidature en attente de ce compte : par identifiant, sinon par pseudo (anciennes candidatures). */
function findPendingApplication(string $discordId, string $username): ?array
{
    $byUsername = null;
    foreach (pendingApplications() as $application) {
        $applicationId = json_decode($application['data'], true)['discord_id'] ?? '';
        if ($applicationId === $discordId) {
            return $application;
        }
        if ($applicationId === '' && $byUsername === null
            && $application['discord_username'] === normalizeUsername($username)) {
            $byUsername = $application;
        }
    }
    return $byUsername;
}

/**
 * Accepte (whitelist le joueur avec sa fiche) ou refuse une candidature.
 * Retourne [candidature, texte de la décision, couleur de l'embed].
 */
function reviewApplication(int $id, string $decision, string $staff): array
{
    $statement = db()->prepare('SELECT * FROM applications WHERE id = ?');
    $statement->execute([$id]);
    $application = $statement->fetch();

    if (!$application) {
        throw new InvalidArgumentException('Candidature introuvable.');
    }
    if ($application['status'] !== 'pending') {
        throw new InvalidArgumentException('Cette candidature a déjà été traitée.');
    }

    if ($decision === 'accept') {
        $values = json_decode($application['data'], true);

        // Le compte indiqué dans le formulaire doit être sur le serveur Discord
        if (!empty($values['discord_id'])) {
            [$discordId, $username] = findGuildMember($values['discord_id']);
        } else {
            // Ancienne candidature, envoyée avant le champ identifiant
            [$discordId, $username] = [null, $application['discord_username']];
        }

        whitelistPlayer($discordId, $username, $staff, $values);
        $who = $discordId ? "<@$discordId> (`$username`)" : "`$username`";
        $text = "✅ Acceptée par $staff — $who peut se connecter au site.";
        $color = COLOR_GREEN;
        $status = 'accepted';
    } elseif ($decision === 'refuse') {
        $text = "❌ Refusée par $staff";
        $color = COLOR_GREY;
        $status = 'refused';
    } else {
        throw new InvalidArgumentException('Décision inconnue.');
    }

    db()->prepare('UPDATE applications SET status = ?, reviewed_by = ?, reviewed_at = ? WHERE id = ?')
        ->execute([$status, $staff, now(), $id]);

    return [$application, $text, $color];
}

/** Embed de la candidature avec la décision ajoutée (et sans boutons). */
function reviewedApplicationEmbed(array $application, string $text, int $color, ?array $embed = null): array
{
    $embed ??= applicationEmbed(json_decode($application['data'], true));
    $embed['color'] = $color;
    $embed['fields'][] = ['name' => 'Décision', 'value' => $text, 'inline' => false];
    return $embed;
}

/** Après une décision prise sur le site : met à jour le message dans le salon du staff. */
function syncApplicationMessage(array $application, string $text, int $color): void
{
    $channelId = config()['discord']['forms_channel_id'] ?? '';
    if (empty($application['discord_message_id']) || $channelId === '') {
        return;
    }
    discordBot('PATCH', "/channels/$channelId/messages/{$application['discord_message_id']}", [
        'embeds' => [reviewedApplicationEmbed($application, $text, $color)],
        'components' => [],
    ]);
}

// --- Whitelist ---
