<?php
// Panneau staff du site : les mêmes actions que le bot Discord.
// GET : tout ce qu'affiche le panneau. POST {action, ...} : une action.

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

allowMethods('GET', 'POST');
$staff = requireStaff();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(200, staffOverview());
}

$body = readJsonBody();
$author = staffDisplayName($staff);

try {
    $message = match ($body['action'] ?? '') {
        'whitelist.add' => actionWhitelistAdd((string) ($body['player'] ?? ''), $author),
        'whitelist.remove' => actionWhitelistRemove((int) ($body['playerId'] ?? 0)),
        'post.create' => actionPostCreate($body, $author),
        'post.delete' => deletePost((int) ($body['id'] ?? 0))
            ? 'Publication supprimée.'
            : throw new InvalidArgumentException('Publication introuvable.'),
        'status.set' => actionStatusSet((string) ($body['status'] ?? '')),
        'application.review' => actionApplicationReview((int) ($body['id'] ?? 0), (string) ($body['decision'] ?? ''), $author),
        default => throw new InvalidArgumentException('Action inconnue.'),
    };
} catch (InvalidArgumentException $err) {
    fail(400, $err->getMessage());
}

respond(200, ['success' => true, 'message' => $message] + staffOverview());

// --- Actions ---

function actionWhitelistAdd(string $input, string $author): string
{
    [$discordId, $username] = findGuildMember($input);

    $application = findPendingApplication($discordId, $username);
    $character = $application ? json_decode($application['data'], true) : [];
    whitelistPlayer($discordId, $username, $author, $character);

    if ($application) {
        [$application, $text, $color] = reviewApplication((int) $application['id'], 'accept', $author);
        syncApplicationMessage($application, $text, $color);
    }
    return "$username ($discordId) est whitelisté.";
}

function actionWhitelistRemove(int $playerId): string
{
    $player = findPlayerById($playerId);
    if ($player === null || !unwhitelistPlayer($player['discord_id'], $player['discord_username'])) {
        throw new InvalidArgumentException("Ce joueur n'est pas whitelisté.");
    }
    return "{$player['discord_username']} n'a plus accès au site.";
}

function actionPostCreate(array $body, string $author): string
{
    $targetId = (int) ($body['targetPlayerId'] ?? 0);
    createPost(
        (string) ($body['category'] ?? ''),
        (string) ($body['title'] ?? ''),
        (string) ($body['content'] ?? ''),
        $author,
        $targetId > 0 ? $targetId : null,
    );
    return 'Publication en ligne.';
}

function actionStatusSet(string $status): string
{
    setServerStatus($status);
    return 'Statut mis à jour : ' . SERVER_STATUSES[$status];
}

function actionApplicationReview(int $id, string $decision, string $author): string
{
    [$application, $text, $color] = reviewApplication($id, $decision, $author);
    syncApplicationMessage($application, $text, $color);
    return $decision === 'accept'
        ? "Candidature acceptée : {$application['discord_username']} peut se connecter."
        : 'Candidature refusée.';
}

// --- Données du panneau ---

function staffOverview(): array
{
    return [
        'status' => getSetting('server_status', 'open'),
        'statuses' => SERVER_STATUSES,
        'categories' => POST_CATEGORIES,
        'applications' => array_map(fn ($application) => [
            'id' => (int) $application['id'],
            'username' => $application['discord_username'],
            'createdAt' => $application['created_at'],
            'data' => json_decode($application['data'], true),
        ], pendingApplications()),
        'players' => array_map(fn ($player) => [
            'id' => (int) $player['id'],
            'username' => $player['discord_username'],
            'displayName' => $player['display_name'],
            'character' => trim(($player['first_name'] ?? '') . ' ' . ($player['last_name'] ?? '')),
            'connected' => $player['discord_id'] !== null,
            'whitelistedAt' => $player['whitelisted_at'],
        ], whitelistedPlayers()),
        'posts' => array_map(fn ($post) => [
            'id' => (int) $post['id'],
            'category' => $post['category'],
            'title' => $post['title'],
            'target' => $post['target_username'],
            'createdAt' => $post['created_at'],
        ], listPosts(null, 50)),
    ];
}
