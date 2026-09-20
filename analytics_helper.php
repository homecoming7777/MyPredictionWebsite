<?php

/**
 * Shared user analytics from score_exact + matches.
 *
 * Points come from stored score_exact.points (final points after ships/doubles).
 * Never recalculate points here.
 */

if (!function_exists('analyticsMatchIsFinished')) {

    function analyticsMatchIsFinished(?int $homeScore, ?int $awayScore): bool
    {
        return $homeScore !== null && $awayScore !== null;
    }

    function analyticsPredictedResult(int $home, int $away): string
    {
        if ($home > $away) {
            return 'home';
        }

        if ($home < $away) {
            return 'away';
        }

        return 'draw';
    }

    /**
     * Core performance stats for one user.
     */
    function analyticsGetUserPerformance(mysqli $conn, int $userId): array
    {
        $defaults = [
            'total_predictions' => 0,
            'finished_predictions' => 0,
            'pending_predictions' => 0,
            'exact_scores' => 0,
            'correct_results' => 0,
            'wrong_predictions' => 0,
            'successful_predictions' => 0,
            'total_points' => 0,
            'result_accuracy_pct' => 0.0,
            'exact_accuracy_pct' => 0.0,
            'average_points_per_prediction' => 0.0,
            'average_points_per_finished' => 0.0,
            'average_points_per_gameweek' => 0.0,
            'gameweeks_played' => 0,
            'best_gameweek' => null,
            'best_gameweek_points' => 0,
            'worst_gameweek' => null,
            'worst_gameweek_points' => 0,
            'current_rank' => null,
            'prediction_rating' => 1500,
        ];

        if ($userId <= 0) {
            return $defaults;
        }

        $stmt = $conn->prepare("
            SELECT
                COUNT(*) AS total_predictions,
                COALESCE(SUM(
                    CASE
                        WHEN m.home_score IS NOT NULL AND m.away_score IS NOT NULL
                        THEN 1
                        ELSE 0
                    END
                ), 0) AS finished_predictions,
                COALESCE(SUM(
                    CASE
                        WHEN se.base_points = 3
                         AND m.home_score IS NOT NULL
                         AND m.away_score IS NOT NULL
                        THEN 1
                        ELSE 0
                    END
                ), 0) AS exact_scores,
                COALESCE(SUM(
                    CASE
                        WHEN se.base_points = 1
                         AND m.home_score IS NOT NULL
                         AND m.away_score IS NOT NULL
                        THEN 1
                        ELSE 0
                    END
                ), 0) AS correct_results,
                COALESCE(SUM(
                    CASE
                        WHEN se.base_points = 0
                         AND m.home_score IS NOT NULL
                         AND m.away_score IS NOT NULL
                        THEN 1
                        ELSE 0
                    END
                ), 0) AS wrong_predictions,
                COALESCE(SUM(COALESCE(se.points, 0)), 0) AS total_points
            FROM score_exact se
            INNER JOIN matches m ON m.id = se.match_id
            WHERE se.user_id = ?
        ");

        if (!$stmt) {
            return $defaults;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return $defaults;
        }

        $totalPredictions = (int)($row['total_predictions'] ?? 0);
        $finishedPredictions = (int)($row['finished_predictions'] ?? 0);
        $exactScores = (int)($row['exact_scores'] ?? 0);
        $correctResults = (int)($row['correct_results'] ?? 0);
        $wrongPredictions = (int)($row['wrong_predictions'] ?? 0);
        $totalPoints = (int)($row['total_points'] ?? 0);
        $successfulPredictions = $exactScores + $correctResults;

        $resultAccuracy = 0.0;
        $exactAccuracy = 0.0;

        if ($finishedPredictions > 0) {
            $resultAccuracy = round(
                ($successfulPredictions / $finishedPredictions) * 100,
                2
            );
            $exactAccuracy = round(
                ($exactScores / $finishedPredictions) * 100,
                2
            );
        }

        $averagePointsPerPrediction = 0.0;
        $averagePointsPerFinished = 0.0;

        if ($totalPredictions > 0) {
            $averagePointsPerPrediction = round(
                $totalPoints / $totalPredictions,
                2
            );
        }

        if ($finishedPredictions > 0) {
            $averagePointsPerFinished = round(
                $totalPoints / $finishedPredictions,
                2
            );
        }

        $gwStmt = $conn->prepare("
            SELECT
                m.gameweek,
                COALESCE(SUM(COALESCE(se.points, 0)), 0) AS gw_points
            FROM score_exact se
            INNER JOIN matches m ON m.id = se.match_id
            WHERE se.user_id = ?
            GROUP BY m.gameweek
            ORDER BY m.gameweek ASC
        ");

        $gameweeksPlayed = 0;
        $bestGameweek = null;
        $bestGameweekPoints = 0;
        $worstGameweek = null;
        $worstGameweekPoints = 0;
        $averagePointsPerGameweek = 0.0;

        if ($gwStmt) {
            $gwStmt->bind_param('i', $userId);
            $gwStmt->execute();
            $gwResult = $gwStmt->get_result();

            $gwPointsTotal = 0;

            while ($gwRow = $gwResult->fetch_assoc()) {
                $gameweeksPlayed++;
                $gw = (int)($gwRow['gameweek'] ?? 0);
                $gwPoints = (int)($gwRow['gw_points'] ?? 0);
                $gwPointsTotal += $gwPoints;

                if ($bestGameweek === null || $gwPoints > $bestGameweekPoints) {
                    $bestGameweek = $gw;
                    $bestGameweekPoints = $gwPoints;
                }

                if ($worstGameweek === null || $gwPoints < $worstGameweekPoints) {
                    $worstGameweek = $gw;
                    $worstGameweekPoints = $gwPoints;
                }
            }

            $gwStmt->close();

            if ($gameweeksPlayed > 0) {
                $averagePointsPerGameweek = round(
                    $gwPointsTotal / $gameweeksPlayed,
                    2
                );
            }
        }

        $rating = analyticsGetUserPredictionRating($conn, $userId);

        return [
            'total_predictions' => $totalPredictions,
            'finished_predictions' => $finishedPredictions,
            'pending_predictions' => max(0, $totalPredictions - $finishedPredictions),
            'exact_scores' => $exactScores,
            'correct_results' => $correctResults,
            'wrong_predictions' => $wrongPredictions,
            'successful_predictions' => $successfulPredictions,
            'total_points' => $totalPoints,
            'result_accuracy_pct' => $resultAccuracy,
            'exact_accuracy_pct' => $exactAccuracy,
            'average_points_per_prediction' => $averagePointsPerPrediction,
            'average_points_per_finished' => $averagePointsPerFinished,
            'average_points_per_gameweek' => $averagePointsPerGameweek,
            'gameweeks_played' => $gameweeksPlayed,
            'best_gameweek' => $bestGameweek,
            'best_gameweek_points' => $bestGameweekPoints,
            'worst_gameweek' => $worstGameweek,
            'worst_gameweek_points' => $worstGameweekPoints,
            'current_rank' => analyticsGetUserRank($conn, $userId),
            'prediction_rating' => $rating,
        ];
    }

    function analyticsRatingColumnExists(mysqli $conn): bool
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $result = $conn->query("SHOW COLUMNS FROM users LIKE 'prediction_rating'");

        $cached = ($result && $result->num_rows > 0);

        if ($result) {
            $result->free();
        }

        return $cached;
    }

    function analyticsGetUserPredictionRating(mysqli $conn, int $userId): int
    {
        if ($userId <= 0) {
            return 1500;
        }

        if (!analyticsRatingColumnExists($conn)) {
            return 1500;
        }

        $stmt = $conn->prepare('SELECT prediction_rating FROM users WHERE id = ? LIMIT 1');

        if (!$stmt) {
            return 1500;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $rating = (int)($row['prediction_rating'] ?? 1500);

        return $rating > 0 ? $rating : 1500;
    }

    /**
     * Season overall rank using the same ordering as leaderboard.php.
     */
    function analyticsGetUserRank(mysqli $conn, int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }

        $sql = "
            SELECT
                u.id,
                COALESCE(SUM(COALESCE(se.points, 0)), 0) AS total_points,
                COALESCE(SUM(
                    CASE
                        WHEN se.base_points = 3 THEN 1
                        ELSE 0
                    END
                ), 0) AS exact_scores,
                COALESCE(SUM(
                    CASE
                        WHEN se.base_points = 1 THEN 1
                        ELSE 0
                    END
                ), 0) AS correct_results
            FROM users u
            LEFT JOIN score_exact se ON se.user_id = u.id
            GROUP BY u.id
            ORDER BY
                total_points DESC,
                exact_scores DESC,
                correct_results DESC,
                u.id ASC
        ";

        $result = $conn->query($sql);

        if (!$result) {
            return null;
        }

        $rank = 0;

        while ($row = $result->fetch_assoc()) {
            $rank++;

            if ((int)$row['id'] === $userId) {
                $result->free();
                return $rank;
            }
        }

        $result->free();

        return null;
    }

    /**
     * Compact summary for profile pages and cards.
     */
    function analyticsGetProfileSummary(mysqli $conn, int $userId): array
    {
        $performance = analyticsGetUserPerformance($conn, $userId);

        return [
            'total_predictions' => $performance['total_predictions'],
            'exact_scores' => $performance['exact_scores'],
            'correct_results' => $performance['correct_results'],
            'successful_predictions' => $performance['successful_predictions'],
            'wrong_predictions' => $performance['wrong_predictions'],
            'total_points' => $performance['total_points'],
            'result_accuracy_pct' => $performance['result_accuracy_pct'],
            'exact_accuracy_pct' => $performance['exact_accuracy_pct'],
            'current_rank' => $performance['current_rank'],
            'prediction_rating' => $performance['prediction_rating'],
            'best_gameweek' => $performance['best_gameweek'],
            'best_gameweek_points' => $performance['best_gameweek_points'],
        ];
    }
}
