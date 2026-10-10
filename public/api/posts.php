<?php
// Actus, devlogs et annonces réservées aux membres, publiées depuis Discord ou le site.

declare(strict_types=1);

require __DIR__ . '/lib/bootstrap.php';

allowMethods('GET');

$requested = array_values(array_intersect(
    explode(',', (string) ($_GET['categories'] ?? 'actus,devlog')),
    array_keys(POST_CATEGORIES),
));
if (!$requested) {
    respond(200, ['posts' => []]);
}

// Annonces membres : celles pour tout le monde + celles destinées au joueur connecté
$player = in_array('membres', $requested, true) ? requirePlayer() : null;

$placeholders = implode(',', array_fill(0, count($requested), '?'));
$statement = db()->prepare(
    "SELECT id, category, title, content, created_at, target_player_id IS NOT NULL AS personal FROM posts
     WHERE category IN ($placeholders) AND (target_player_id IS NULL OR target_player_id = ?)
     ORDER BY created_at DESC, id DESC LIMIT 50",
);
$statement->execute([...$requested, $player ? (int) $player['id'] : 0]);

$posts = array_map(function (array $post) {
    $post['personal'] = (bool) $post['personal'];
    return $post;
}, $statement->fetchAll());

respond(200, ['posts' => $posts]);
