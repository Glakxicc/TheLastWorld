<?php
// Comptes Minecraft liés aux comptes du site, et échanges avec le plugin TLWStats.
//
// Liaison : un compte Minecraft non lié qui se connecte au serveur est refusé avec un code
// (généré ici à la demande du plugin) ; le joueur le saisit dans « Mon espace ». Seul le vrai
// propriétaire du compte Minecraft peut voir ce code, ce qui empêche de se faire passer pour un autre.

declare(strict_types=1);

const MC_UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
const MC_NAME_PATTERN = '/^[A-Za-z0-9_]{1,16}$/';

const LINK_CODE_LIFETIME = 600;
const LINK_CODE_LENGTH = 6;
// Sans 0/O ni 1/I/L : facile à recopier depuis l'écran du jeu
const LINK_CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

/** Requête du plugin : en-tête « X-TLW-Token » (ou « Authorization: Bearer … ») = minecraft.stats_token. */
function requireServerToken(): void
{
    $token = config()['minecraft']['stats_token'] ?? '';
    // Certains hébergeurs retirent l'en-tête Authorization : X-TLW-Token passe toujours
    $given = $_SERVER['HTTP_X_TLW_TOKEN']
        ?? preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($token === '' || !hash_equals($token, (string) $given)) {
        fail(401, 'Clé invalide.');
    }
}

function findPlayerByMinecraft(string $uuid): ?array
{
    $statement = db()->prepare('SELECT * FROM players WHERE mc_uuid = ?');
    $statement->execute([$uuid]);
    return $statement->fetch() ?: null;
}

/** UUID des comptes Minecraft autorisés sur le serveur : liés à un joueur whitelisté. */
function allowedMinecraftUuids(): array
{
    return db()->query('SELECT mc_uuid FROM players WHERE whitelisted = 1 AND mc_uuid IS NOT NULL')
        ->fetchAll(PDO::FETCH_COLUMN);
}

/** Code de liaison d'un compte Minecraft, réutilisé tant qu'il est valable. */
function linkCodeFor(string $uuid, string $name): string
{
    db()->prepare('DELETE FROM mc_link_codes WHERE expires_at < ?')->execute([now()]);

    $existing = db()->prepare('SELECT code FROM mc_link_codes WHERE mc_uuid = ?');
    $existing->execute([$uuid]);
    if ($code = $existing->fetchColumn()) {
        db()->prepare('UPDATE mc_link_codes SET mc_username = ? WHERE code = ?')->execute([$name, $code]);
        return (string) $code;
    }

    $taken = db()->prepare('SELECT 1 FROM mc_link_codes WHERE code = ?');
    do {
        $code = '';
        for ($i = 0; $i < LINK_CODE_LENGTH; $i++) {
            $code .= LINK_CODE_ALPHABET[random_int(0, strlen(LINK_CODE_ALPHABET) - 1)];
        }
        $taken->execute([$code]);
    } while ($taken->fetchColumn());

    db()->prepare('INSERT INTO mc_link_codes (code, mc_uuid, mc_username, expires_at, created_at) VALUES (?, ?, ?, ?, ?)')
        ->execute([$code, $uuid, $name, gmdate('c', time() + LINK_CODE_LIFETIME), now()]);
    return $code;
}

/** Lie au joueur le compte Minecraft qui a reçu ce code. */
function linkMinecraftAccount(array $player, string $code): void
{
    $code = strtoupper((string) preg_replace('/[\s-]+/', '', $code));
    if (!preg_match('/^[' . LINK_CODE_ALPHABET . ']{' . LINK_CODE_LENGTH . '}$/', $code)) {
        throw new InvalidArgumentException('Code invalide : 6 lettres et chiffres, affichés par le serveur Minecraft.');
    }

    $statement = db()->prepare('SELECT * FROM mc_link_codes WHERE code = ? AND expires_at >= ?');
    $statement->execute([$code, now()]);
    $link = $statement->fetch();
    if (!$link) {
        throw new InvalidArgumentException('Code invalide ou expiré : reconnectez-vous au serveur pour en obtenir un nouveau.');
    }

    $owner = findPlayerByMinecraft($link['mc_uuid']);
    if ($owner !== null && (int) $owner['id'] !== (int) $player['id']) {
        throw new InvalidArgumentException('Ce compte Minecraft est déjà lié à un autre joueur. Contactez le staff.');
    }

    db()->prepare('UPDATE players SET mc_uuid = ?, mc_username = ?, mc_linked_at = ? WHERE id = ?')
        ->execute([$link['mc_uuid'], $link['mc_username'], now(), $player['id']]);
    db()->prepare('DELETE FROM mc_link_codes WHERE code = ?')->execute([$code]);
}

/** Taille maximale d'un inventaire reçu (JSON), largement au-dessus d'un inventaire plein. */
const INVENTORY_MAX_BYTES = 262144;

/** Enregistre le dernier inventaire connu d'un compte Minecraft. */
function saveInventory(string $uuid, array $inventory): void
{
    $data = json_encode($inventory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($data === false || strlen($data) > INVENTORY_MAX_BYTES) {
        return;
    }

    $exists = db()->prepare('SELECT 1 FROM player_inventories WHERE mc_uuid = ?');
    $exists->execute([$uuid]);
    db()->prepare($exists->fetchColumn()
        ? 'UPDATE player_inventories SET data = ?, updated_at = ? WHERE mc_uuid = ?'
        : 'INSERT INTO player_inventories (data, updated_at, mc_uuid) VALUES (?, ?, ?)')->execute([$data, now(), $uuid]);
}

/** Dernier inventaire connu d'un compte Minecraft, ou null si le serveur ne l'a jamais envoyé. */
function findInventory(string $uuid): ?array
{
    $statement = db()->prepare('SELECT data, updated_at FROM player_inventories WHERE mc_uuid = ?');
    $statement->execute([$uuid]);
    $row = $statement->fetch();
    if (!$row) {
        return null;
    }
    return ['inventory' => json_decode($row['data'], true) ?: [], 'updatedAt' => $row['updated_at']];
}

function unlinkMinecraftAccount(int $playerId): void
{
    db()->prepare('UPDATE players SET mc_uuid = NULL, mc_username = NULL, mc_linked_at = NULL WHERE id = ?')
        ->execute([$playerId]);
}
