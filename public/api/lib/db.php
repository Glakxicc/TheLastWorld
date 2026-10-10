<?php
// Base de données : MySQL en production (IONOS), SQLite en local par défaut.

declare(strict_types=1);

const SCHEMA_VERSION = 3;

const SERVER_STATUSES = [
    'open' => '🟢 Ouvert',
    'closed' => '🔴 Fermé',
];

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $config = config()['db'] ?? [];
    // IONOS affiche « MariaDB » : son pilote PHP s'appelle pourtant « mysql »
    $dsn = preg_replace('/^mariadb:/i', 'mysql:', trim($config['dsn'] ?? ''));
    if ($dsn === '') {
        if (!is_dir(DATA_DIR)) {
            mkdir(DATA_DIR, 0755, true);
        }
        $dsn = 'sqlite:' . DATA_DIR . '/site.sqlite';
    }

    $pdo = new PDO($dsn, $config['user'] ?? null, $config['password'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    if (isSqlite($pdo)) {
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }

    migrate($pdo);
    return $pdo;
}

function isSqlite(PDO $pdo): bool
{
    return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

/** Crée les tables au premier appel (aucune commande à lancer sur IONOS). */
function migrate(PDO $pdo): void
{
    try {
        $version = (int) $pdo
            ->query("SELECT value FROM settings WHERE name = 'schema_version'")
            ->fetchColumn();
    } catch (PDOException) {
        $version = 0;
    }
    if ($version >= SCHEMA_VERSION) {
        return;
    }

    $sqlite = isSqlite($pdo);
    $id = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $engine = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        name VARCHAR(64) PRIMARY KEY,
        value TEXT
    )$engine");

    // Joueurs ayant accès au site. discord_id est rempli à la première connexion
    // quand le joueur a été accepté via le formulaire (on ne connaît que son pseudo).
    $pdo->exec("CREATE TABLE IF NOT EXISTS players (
        id $id,
        discord_id VARCHAR(32) NULL UNIQUE,
        discord_username VARCHAR(64) NOT NULL UNIQUE,
        display_name VARCHAR(64) NULL,
        avatar VARCHAR(128) NULL,
        first_name VARCHAR(64) NULL,
        last_name VARCHAR(64) NULL,
        character_age VARCHAR(8) NULL,
        born VARCHAR(128) NULL,
        experience TEXT NULL,
        story TEXT NULL,
        whitelisted SMALLINT NOT NULL DEFAULT 1,
        whitelisted_at VARCHAR(32) NULL,
        whitelisted_by VARCHAR(64) NULL,
        last_login_at VARCHAR(32) NULL,
        created_at VARCHAR(32) NOT NULL
    )$engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS applications (
        id $id,
        discord_username VARCHAR(64) NOT NULL,
        data TEXT NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        reviewed_by VARCHAR(64) NULL,
        reviewed_at VARCHAR(32) NULL,
        created_at VARCHAR(32) NOT NULL
    )$engine");

    $pdo->exec("CREATE TABLE IF NOT EXISTS posts (
        id $id,
        category VARCHAR(16) NOT NULL,
        title VARCHAR(256) NOT NULL,
        content TEXT NOT NULL,
        author VARCHAR(64) NOT NULL,
        created_at VARCHAR(32) NOT NULL
    )$engine");

    // v2 : annonces destinées à un seul joueur, message Discord des candidatures
    if ($version < 2) {
        addColumn($pdo, 'posts', 'target_player_id INTEGER NULL');
        addColumn($pdo, 'applications', 'discord_message_id VARCHAR(32) NULL');
    }

    // v3 : compte Minecraft du joueur, skin du personnage, statistiques envoyées par le serveur
    if ($version < 3) {
        addColumn($pdo, 'players', 'mc_username VARCHAR(16) NULL');
        addColumn($pdo, 'players', 'mc_uuid VARCHAR(36) NULL');
        addColumn($pdo, 'players', 'skin_url VARCHAR(256) NULL');
        $pdo->exec("CREATE TABLE IF NOT EXISTS player_stats (
            mc_uuid VARCHAR(36) PRIMARY KEY,
            mc_username VARCHAR(16) NOT NULL,
            playtime_seconds INTEGER NOT NULL DEFAULT 0,
            deaths INTEGER NOT NULL DEFAULT 0,
            mob_kills INTEGER NOT NULL DEFAULT 0,
            player_kills INTEGER NOT NULL DEFAULT 0,
            last_seen VARCHAR(32) NULL,
            online SMALLINT NOT NULL DEFAULT 0,
            updated_at VARCHAR(32) NOT NULL
        )$engine");
    }

    setSetting('schema_version', (string) SCHEMA_VERSION, $pdo);
    if (getSetting('server_status', null, $pdo) === null) {
        setSetting('server_status', 'open', $pdo);
    }
}

/** Ignore l'erreur si la colonne existe déjà (deux visiteurs en même temps). */
function addColumn(PDO $pdo, string $table, string $definition): void
{
    try {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $definition");
    } catch (PDOException $err) {
        if (!preg_match('/duplicate column/i', $err->getMessage())) {
            throw $err;
        }
    }
}

function getSetting(string $name, ?string $default = null, ?PDO $pdo = null): ?string
{
    $statement = ($pdo ?? db())->prepare('SELECT value FROM settings WHERE name = ?');
    $statement->execute([$name]);
    $value = $statement->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function setSetting(string $name, string $value, ?PDO $pdo = null): void
{
    $pdo ??= db();
    $exists = $pdo->prepare('SELECT 1 FROM settings WHERE name = ?');
    $exists->execute([$name]);
    $sql = $exists->fetchColumn()
        ? 'UPDATE settings SET value = ? WHERE name = ?'
        : 'INSERT INTO settings (value, name) VALUES (?, ?)';
    $pdo->prepare($sql)->execute([$value, $name]);
}

// --- Joueurs ---

function findPlayerById(int $id): ?array
{
    $statement = db()->prepare('SELECT * FROM players WHERE id = ?');
    $statement->execute([$id]);
    return $statement->fetch() ?: null;
}

/** Retrouve un joueur par ID Discord, sinon par pseudo (joueur pas encore connecté). */
function findPlayerByDiscord(?string $discordId, string $username): ?array
{
    if ($discordId !== null) {
        $statement = db()->prepare('SELECT * FROM players WHERE discord_id = ?');
        $statement->execute([$discordId]);
        if ($player = $statement->fetch()) {
            return $player;
        }
    }

    // Avec un ID connu, on ne rattache qu'une fiche encore jamais connectée
    $statement = db()->prepare(
        $discordId === null
            ? 'SELECT * FROM players WHERE discord_username = ?'
            : 'SELECT * FROM players WHERE discord_username = ? AND discord_id IS NULL',
    );
    $statement->execute([normalizeUsername($username)]);
    return $statement->fetch() ?: null;
}

/** Ajoute (ou réactive) un joueur dans la whitelist. Retourne son id. */
function whitelistPlayer(?string $discordId, string $username, string $staff, array $character = []): int
{
    $username = normalizeUsername($username);
    $player = findPlayerByDiscord($discordId, $username);

    if ($player === null) {
        db()->prepare(
            'INSERT INTO players (discord_id, discord_username, whitelisted, whitelisted_at, whitelisted_by, created_at)
             VALUES (?, ?, 1, ?, ?, ?)',
        )->execute([$discordId, $username, now(), $staff, now()]);
        $playerId = (int) db()->lastInsertId();
    } else {
        $playerId = (int) $player['id'];
        db()->prepare(
            'UPDATE players SET discord_id = COALESCE(discord_id, ?), whitelisted = 1, whitelisted_at = ?, whitelisted_by = ?
             WHERE id = ?',
        )->execute([$discordId, now(), $staff, $playerId]);
    }

    if ($character) {
        updateCharacter($playerId, $character, onlyIfEmpty: true);
    }
    return $playerId;
}

function unwhitelistPlayer(?string $discordId, string $username): bool
{
    $player = findPlayerByDiscord($discordId, $username);
    if ($player === null || !$player['whitelisted']) {
        return false;
    }
    db()->prepare('UPDATE players SET whitelisted = 0 WHERE id = ?')->execute([$player['id']]);
    return true;
}

function updateCharacter(int $playerId, array $character, bool $onlyIfEmpty = false): void
{
    if ($onlyIfEmpty) {
        $player = findPlayerById($playerId);
        if ($player && ($player['first_name'] ?? '') !== '') {
            return;
        }
    }
    db()->prepare(
        'UPDATE players SET first_name = ?, last_name = ?, character_age = ?, born = ?, experience = ?, story = ?
         WHERE id = ?',
    )->execute([
        $character['first_name'] ?? null,
        $character['last_name'] ?? null,
        $character['age_character'] ?? null,
        $character['rp_born'] ?? null,
        $character['rp_experience'] ?? null,
        $character['rp_story'] ?? null,
        $playerId,
    ]);
}

/** Joueurs whitelistés, avec leurs statistiques Minecraft quand le plugin en a envoyé. */
function whitelistedPlayers(): array
{
    return db()->query(
        'SELECT players.*, stats.playtime_seconds, stats.deaths, stats.mob_kills, stats.player_kills,
                stats.last_seen AS mc_last_seen, stats.online AS mc_online, stats.updated_at AS stats_updated_at
         FROM players LEFT JOIN player_stats stats ON stats.mc_uuid = players.mc_uuid
         WHERE players.whitelisted = 1 ORDER BY players.discord_username',
    )->fetchAll();
}

/** Fiche personnage, avec les mêmes clés que le formulaire. */
function characterSheet(array $player): array
{
    return [
        'first_name' => $player['first_name'] ?? '',
        'last_name' => $player['last_name'] ?? '',
        'age_character' => $player['character_age'] ?? '',
        'rp_born' => $player['born'] ?? '',
        'rp_experience' => $player['experience'] ?? '',
        'rp_story' => $player['story'] ?? '',
    ];
}

/** Données d'un joueur renvoyées au navigateur. */
function publicPlayer(array $player): array
{
    $avatar = $player['avatar'] && $player['discord_id']
        ? "https://cdn.discordapp.com/avatars/{$player['discord_id']}/{$player['avatar']}.png?size=128"
        : null;

    $stats = null;
    if (!empty($player['mc_uuid'])) {
        $statement = db()->prepare('SELECT * FROM player_stats WHERE mc_uuid = ?');
        $statement->execute([$player['mc_uuid']]);
        $stats = $statement->fetch() ?: null;
    }

    return [
        'username' => $player['discord_username'],
        'displayName' => $player['display_name'] ?: $player['discord_username'],
        'avatarUrl' => $avatar,
        'whitelistedAt' => $player['whitelisted_at'],
        'character' => characterSheet($player),
        'minecraft' => [
            'username' => $player['mc_username'] ?? '',
            'uuid' => $player['mc_uuid'] ?? '',
            'skinUrl' => $player['skin_url'] ?? '',
        ],
        'stats' => $stats ? [
            'playtimeSeconds' => (int) $stats['playtime_seconds'],
            'deaths' => (int) $stats['deaths'],
            'mobKills' => (int) $stats['mob_kills'],
            'playerKills' => (int) $stats['player_kills'],
            'lastSeen' => $stats['last_seen'],
            'online' => (bool) $stats['online'],
            'updatedAt' => $stats['updated_at'],
        ] : null,
    ];
}
