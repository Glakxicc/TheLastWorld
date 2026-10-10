<?php
// Droits staff d'un joueur connecté, vérifiés auprès du serveur Discord via le bot.

declare(strict_types=1);

const PERMISSION_ADMINISTRATOR = 0x8;
const STAFF_CACHE_SECONDS = 300;

/** Administrateur, propriétaire ou membre d'un rôle staff du serveur Discord. */
function discordUserIsStaff(string $discordId): bool
{
    $discord = config()['discord'] ?? [];
    $guildId = $discord['guild_id'] ?? '';
    if ($guildId === '' || empty($discord['bot_token'])) {
        return false;
    }

    [$status, $member] = discordBot('GET', "/guilds/$guildId/members/$discordId");
    if ($status !== 200) {
        return false;
    }
    if (array_intersect($member['roles'] ?? [], $discord['staff_role_ids'] ?? [])) {
        return true;
    }

    [$status, $guild] = discordBot('GET', "/guilds/$guildId");
    if ($status !== 200) {
        return false;
    }
    if (($guild['owner_id'] ?? '') === $discordId) {
        return true;
    }

    // Permissions = rôle @everyone (même id que le serveur) + rôles du membre
    $memberRoles = [...($member['roles'] ?? []), $guildId];
    $permissions = 0;
    foreach ($guild['roles'] ?? [] as $role) {
        if (in_array($role['id'], $memberRoles, true)) {
            $permissions |= (int) $role['permissions'];
        }
    }
    return (bool) ($permissions & PERMISSION_ADMINISTRATOR);
}

/** Résultat mis en cache dans la session pour ne pas interroger Discord à chaque clic. */
function playerIsStaff(array $player): bool
{
    if (empty($player['discord_id'])) {
        return false;
    }

    startSession();
    $cache = $_SESSION['staff'] ?? null;
    if (is_array($cache) && $cache['until'] > time()) {
        return $cache['value'];
    }

    $value = discordUserIsStaff($player['discord_id']);
    $_SESSION['staff'] = ['value' => $value, 'until' => time() + STAFF_CACHE_SECONDS];
    return $value;
}

/**
 * Rang du joueur = son rôle le plus haut sur le serveur Discord (nom + couleur).
 * Mis en cache dans la session comme les droits staff.
 */
function playerRank(array $player): ?array
{
    if (empty($player['discord_id'])) {
        return null;
    }

    startSession();
    $cache = $_SESSION['rank'] ?? null;
    if (is_array($cache) && $cache['until'] > time()) {
        return $cache['value'];
    }

    $value = discordUserRank($player['discord_id']);
    $_SESSION['rank'] = ['value' => $value, 'until' => time() + STAFF_CACHE_SECONDS];
    return $value;
}

function discordUserRank(string $discordId): ?array
{
    $guildId = config()['discord']['guild_id'] ?? '';
    if ($guildId === '' || empty(config()['discord']['bot_token'])) {
        return null;
    }

    [$status, $member] = discordBot('GET', "/guilds/$guildId/members/$discordId");
    if ($status !== 200 || empty($member['roles'])) {
        return null;
    }
    [$status, $roles] = discordBot('GET', "/guilds/$guildId/roles");
    if ($status !== 200) {
        return null;
    }

    $best = null;
    foreach ($roles as $role) {
        if (in_array($role['id'], $member['roles'], true) && ($best === null || $role['position'] > $best['position'])) {
            $best = $role;
        }
    }
    if ($best === null) {
        return null;
    }

    return [
        'name' => $best['name'],
        // 0 = rôle sans couleur sur Discord
        'color' => $best['color'] ? sprintf('#%06x', $best['color']) : null,
    ];
}

function requireStaff(): array
{
    $player = requirePlayer();
    if (!playerIsStaff($player)) {
        fail(403, 'Réservé au staff.');
    }
    return $player;
}

function staffDisplayName(array $player): string
{
    return $player['display_name'] ?: $player['discord_username'];
}
