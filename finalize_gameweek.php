<?php
include 'connect.php';

$gw = intval($_GET['gameweek']);

$users = $conn->query("SELECT id FROM users");

while ($u = $users->fetch_assoc()) {

    $uid = $u['id'];

    $card = $conn->query("
        SELECT best_gw_used
        FROM user_cards
        WHERE user_id = $uid
    ")->fetch_assoc();

    if (!$card || !$card['best_gw_used']) continue;

    $current = $conn->query("
        SELECT SUM(p.points) s
        FROM score_exact p
        JOIN matches m ON p.match_id = m.id
        WHERE p.user_id = $uid AND m.gameweek = $gw
    ")->fetch_assoc()['s'] ?? 0;

    $previous = $conn->query("
        SELECT SUM(p.points) s
        FROM score_exact p
        JOIN matches m ON p.match_id = m.id
        WHERE p.user_id = $uid AND m.gameweek = ($gw - 1)
    ")->fetch_assoc()['s'] ?? 0;

    if ($previous > $current) {
        $conn->query("
            UPDATE score_exact p
            JOIN matches m ON p.match_id = m.id
            SET p.points = 0
            WHERE p.user_id = $uid AND m.gameweek = $gw
        ");
    }
}

echo "Gameweek finalized";
