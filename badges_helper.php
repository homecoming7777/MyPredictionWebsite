<?php

require_once __DIR__ . '/ships_helper.php';

/**
 * "Best Manager" badge helper.
 *
 * A user gets this badge on the gameweek right AFTER the one they
 * finished #1 in - using the exact same ranking rule as the
 * gameweek leaderboard (total points, then exact predictions, then
 * username as a tiebreaker).
 */

if (!function_exists('getGameweekWinner')) {

    function getGameweekWinner(mysqli $conn, int $gameweek): ?array
    {
        $sql = "
            SELECT
                u.id,
                u.username,
                COALESCE(SUM(COALESCE(p.points, 0)), 0) AS total_points,
                COALESCE(SUM(CASE
                    WHEN p.predicted_home = m.home_score
                    AND p.predicted_away = m.away_score
                    AND m.home_score IS NOT NULL
                    AND m.away_score IS NOT NULL
                    THEN 1
                    ELSE 0
                END), 0) AS exact_predictions
            FROM users u
            INNER JOIN score_exact p ON p.user_id = u.id
            INNER JOIN matches m ON m.id = p.match_id
            WHERE m.gameweek = ?
            GROUP BY u.id, u.username
            ORDER BY total_points DESC, exact_predictions DESC, u.username ASC
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) return null;

        $stmt->bind_param('i', $gameweek);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * The closest gameweek number that comes before $current in a
     * sorted (ascending) list of gameweek numbers, or null if $current
     * is the first one on record.
     */
    function getPreviousGameweekFromList(array $gameweeksSorted, int $current): ?int
    {
        $previous = null;

        foreach ($gameweeksSorted as $gw) {
            if ($gw < $current) {
                $previous = $gw;
            } else {
                break;
            }
        }

        return $previous;
    }
}