<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    miniFail('Method not allowed.', 405);
}

$user = miniRequireUser($conn);

$gameweek = miniCurrentGameweek($conn);

$totalStmt = $conn->prepare("
    SELECT COALESCE(SUM(points), 0) AS total
    FROM score_exact
    WHERE user_id = ?
");

$totalStmt->bind_param('i', $user['id']);
$totalStmt->execute();
$totalRow = $totalStmt->get_result()->fetch_assoc();
$totalStmt->close();

$gwStmt = $conn->prepare("
    SELECT COALESCE(SUM(se.points), 0) AS gw_total
    FROM score_exact se
    INNER JOIN matches m ON m.id = se.match_id
    WHERE se.user_id = ?
      AND m.gameweek = ?
");

$gwStmt->bind_param('ii', $user['id'], $gameweek);
$gwStmt->execute();
$gwRow = $gwStmt->get_result()->fetch_assoc();
$gwStmt->close();

miniRespond([
    'success' => true,
    'data' => [
        'id' => $user['id'],
        'username' => $user['username'],
        'current_gameweek' => $gameweek,
        'current_gameweek_points' => (int) ($gwRow['gw_total'] ?? 0),
        'total_points' => (int) ($totalRow['total'] ?? 0),
    ],
]);