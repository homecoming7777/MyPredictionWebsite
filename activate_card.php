<?php
session_start();
include 'connect.php';

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    http_response_code(401);
    exit("NOT_LOGGED");
}

$card     = $_POST['card'] ?? null;
$match_id = intval($_POST['match_id'] ?? 0);
$gameweek = intval($_POST['gameweek'] ?? 0);

/* Ensure row exists */
$conn->query("INSERT IGNORE INTO user_cards (user_id) VALUES ($user_id)");

$cards = $conn->query("
    SELECT * FROM user_cards WHERE user_id = $user_id
")->fetch_assoc();

switch ($card) {

    /* CARD 1 — DOUBLE ALL POINTS */
    case 'double_all':
        if ($cards['double_all_used']) exit("USED");
        $conn->query("
            UPDATE user_cards
            SET double_all_used = 1
            WHERE user_id = $user_id
        ");
        break;

    /* CARD 2 — TRIPLE ONE MATCH */
    case 'triple_match':
        if ($cards['triple_match_used']) exit("USED");
        if (!$match_id || !$gameweek) exit("INVALID");

        $conn->query("
            INSERT INTO triple_match (user_id, match_id, gameweek)
            VALUES ($user_id, $match_id, $gameweek)
        ");
        $conn->query("
            UPDATE user_cards
            SET triple_match_used = 1
            WHERE user_id = $user_id
        ");
        break;

    /* CARD 3 — BEST OF TWO GAMEWEEKS */
    case 'best_gw':
        if ($cards['best_gw_used']) exit("USED");
        $conn->query("
            UPDATE user_cards
            SET best_gw_used = 1
            WHERE user_id = $user_id
        ");
        break;

    default:
        exit("UNKNOWN");
}

echo "OK";
