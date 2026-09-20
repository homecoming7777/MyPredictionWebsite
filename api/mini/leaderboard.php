<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    miniFail('Method not allowed.', 405);
}

$user = miniRequireUser($conn);

/*
 * Same ranking rule as leaderboard.php on the main site:
 * total points across ALL of score_exact, tie-broken by exact scores
 * then correct results then username. Only public fields are returned.
 */
$sql = "
    SELECT
        u.id,
        u.username,
        COALESCE(SUM(se.points), 0) AS total_points,
        SUM(CASE WHEN se.base_points = 3 THEN 1 ELSE 0 END) AS exact_scores,
        SUM(CASE WHEN se.base_points = 1 THEN 1 ELSE 0 END) AS correct_results
    FROM users u
    LEFT JOIN score_exact se ON se.user_id = u.id
    GROUP BY u.id, u.username
    ORDER BY
        total_points DESC,
        exact_scores DESC,
        correct_results DESC,
        u.username ASC
";

$result = $conn->query($sql);

if (!$result) {
    miniFail('Server error.', 500);
}

$rows = [];

while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}

$leaderboard = [];

foreach ($rows as $index => $row) {
    $leaderboard[] = [
        'rank' => $index + 1,
        'username' => $row['username'],
        'points' => (int) $row['total_points'],
        'is_me' => ((int) $row['id'] === $user['id']),
    ];
}

miniRespond([
    'success' => true,
    'data' => $leaderboard,
]);