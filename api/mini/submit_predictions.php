<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    miniFail('Method not allowed.', 405);
}

$user = miniRequireUser($conn);

$raw = file_get_contents('php://input');
$body = json_decode((string) $raw, true);

if (!is_array($body)) {
    miniFail('Invalid request body.', 400);
}

$gameweek = (int) ($body['gameweek'] ?? 0);
$predictions = $body['predictions'] ?? null;

if ($gameweek <= 0) {
    miniFail('Invalid gameweek.', 400);
}

if (!is_array($predictions) || count($predictions) === 0) {
    miniFail('No predictions received.', 400);
}

/*
|--------------------------------------------------------------------------
| SAME RULE AS THE MAIN SITE (gameweek_deadline.php)
|--------------------------------------------------------------------------
*/

if (
    function_exists('isGameweekDeadlinePassed')
    && isGameweekDeadlinePassed($conn, $gameweek)
) {
    miniFail('Predictions are locked.', 423);
}

/*
|--------------------------------------------------------------------------
| VALIDATE EVERY SCORE BEFORE TOUCHING THE DATABASE
|--------------------------------------------------------------------------
*/

$clean = [];

foreach ($predictions as $entry) {
    if (!is_array($entry)) {
        miniFail('Invalid prediction data.', 400);
    }

    $matchId = (int) ($entry['match_id'] ?? 0);
    $homeRaw = $entry['home'] ?? null;
    $awayRaw = $entry['away'] ?? null;

    if ($matchId <= 0) {
        miniFail('Invalid match.', 400);
    }

    if (
        !is_numeric($homeRaw)
        || !is_numeric($awayRaw)
    ) {
        miniFail('Please enter a valid score.', 400);
    }

    $home = (int) $homeRaw;
    $away = (int) $awayRaw;

    if ($home < 0 || $away < 0 || $home > 10 || $away > 10) {
        miniFail('Please enter a valid score.', 400);
    }

    $clean[] = [
        'match_id' => $matchId,
        'home' => $home,
        'away' => $away,
    ];
}

/*
|--------------------------------------------------------------------------
| INSERT (score_exact is the single existing prediction table)
|--------------------------------------------------------------------------
|
| Matches the exact rule the main site's insert_prediction.php uses:
| every match must belong to this gameweek, and an EXISTING prediction
| is never overwritten - it is simply skipped.
|
*/

$matchStmt = $conn->prepare("
    SELECT id, gameweek, competition
    FROM matches
    WHERE id = ?
    LIMIT 1
");

$existingStmt = $conn->prepare("
    SELECT id
    FROM score_exact
    WHERE user_id = ?
      AND match_id = ?
    LIMIT 1
");

$insertStmt = $conn->prepare("
    INSERT INTO score_exact
        (user_id, match_id, predicted_home, predicted_away)
    VALUES (?, ?, ?, ?)
");

if (!$matchStmt || !$existingStmt || !$insertStmt) {
    miniFail('Server error.', 500);
}

$conn->begin_transaction();

$saved = 0;
$skipped = 0;

try {
    foreach ($clean as $prediction) {
        $matchId = $prediction['match_id'];

        $matchStmt->bind_param('i', $matchId);
        $matchStmt->execute();
        $match = $matchStmt->get_result()->fetch_assoc();

        if (!$match) {
            throw new RuntimeException('Match not found.');
        }

        if ((int) $match['gameweek'] !== $gameweek) {
            throw new RuntimeException('Invalid gameweek.');
        }

        if ((string) $match['competition'] !== 'Premier League') {
            throw new RuntimeException('Invalid match.');
        }

        $existingStmt->bind_param('ii', $user['id'], $matchId);
        $existingStmt->execute();
        $existing = $existingStmt->get_result()->fetch_assoc();

        if ($existing) {
            $skipped++;
            continue;
        }

        $insertStmt->bind_param(
            'iiii',
            $user['id'],
            $matchId,
            $prediction['home'],
            $prediction['away']
        );

        if (!$insertStmt->execute()) {
            throw new RuntimeException('Could not save prediction.');
        }

        $saved++;
    }

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();

    $matchStmt->close();
    $existingStmt->close();
    $insertStmt->close();

    miniFail('Could not save predictions. ' . $e->getMessage(), 400);
}

$matchStmt->close();
$existingStmt->close();
$insertStmt->close();

miniRespond([
    'success' => true,
    'data' => [
        'saved' => $saved,
        'already_submitted' => $skipped,
    ],
]);