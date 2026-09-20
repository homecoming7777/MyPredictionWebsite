<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    miniFail('Method not allowed.', 405);
}

$user = miniRequireUser($conn);

$gameweek = miniCurrentGameweek($conn);

if ($gameweek <= 0) {
    miniRespond([
        'success' => true,
        'data' => [
            'gameweek' => null,
            'deadline' => null,
            'deadline_timestamp' => null,
            'deadline_passed' => true,
            'matches' => [],
        ],
    ]);
}

$deadline = function_exists('getGameweekDeadline')
    ? getGameweekDeadline($conn, $gameweek)
    : null;

$deadlinePassed = function_exists('isGameweekDeadlinePassed')
    ? isGameweekDeadlinePassed($conn, $gameweek)
    : false;

$deadlineTimestamp = function_exists('gameweekDeadlineTimestamp')
    ? gameweekDeadlineTimestamp($conn, $gameweek)
    : null;

/*
 * Same query shape as predictions.php on the main site: Premier League
 * matches for this gameweek, left-joined to this user's own prediction
 * so already-submitted scores come back pre-filled.
 */
$stmt = $conn->prepare("
    SELECT
        m.id,
        m.home_team,
        m.home_team_pic,
        m.away_team,
        m.away_team_pic,
        m.match_date,
        m.home_score,
        m.away_score,
        se.predicted_home,
        se.predicted_away,
        se.points
    FROM matches m
    LEFT JOIN score_exact se
        ON se.match_id = m.id
        AND se.user_id = ?
    WHERE m.gameweek = ?
      AND m.competition = 'Premier League'
    ORDER BY m.match_date ASC
");

if (!$stmt) {
    miniFail('Server error.', 500);
}

$stmt->bind_param('ii', $user['id'], $gameweek);
$stmt->execute();

$result = $stmt->get_result();

$matches = [];

while ($row = $result->fetch_assoc()) {
    $hasPrediction = ($row['predicted_home'] !== null && $row['predicted_away'] !== null);
    $isFinished = ($row['home_score'] !== null && $row['away_score'] !== null);

    $matches[] = [
        'id' => (int) $row['id'],
        'home_team' => $row['home_team'],
        'home_team_pic' => $row['home_team_pic'],
        'away_team' => $row['away_team'],
        'away_team_pic' => $row['away_team_pic'],
        'match_date' => $row['match_date'],
        'home_score' => $isFinished ? (int) $row['home_score'] : null,
        'away_score' => $isFinished ? (int) $row['away_score'] : null,
        'predicted_home' => $hasPrediction ? (int) $row['predicted_home'] : null,
        'predicted_away' => $hasPrediction ? (int) $row['predicted_away'] : null,
        'has_prediction' => $hasPrediction,
        'is_finished' => $isFinished,
        'points' => $hasPrediction ? (int) ($row['points'] ?? 0) : null,
    ];
}

$stmt->close();

miniRespond([
    'success' => true,
    'data' => [
        'gameweek' => $gameweek,
        'deadline' => $deadline ? $deadline->format('D, d M Y \a\t H:i') : null,
        'deadline_timestamp' => $deadlineTimestamp,
        'deadline_passed' => (bool) $deadlinePassed,
        'matches' => $matches,
    ],
]);