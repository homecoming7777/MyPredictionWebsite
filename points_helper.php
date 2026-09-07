<?php

/**
 * Central points helper - DOUBLE PICK ONLY.
 *
 * score_exact.base_points = normal football points (0/1/3)
 * score_exact.points      = final stored points after Double Pick
 *
 * IMPORTANT:
 * Never multiply score_exact.points in a page.
 */

if (!function_exists('calculateFinalPoints')) {

    /**
     * Result of a scoreline: 1 = home win, -1 = away win, 0 = draw.
     */
    function pointsHelperResult(int $home, int $away): int
    {
        if ($home > $away) {
            return 1;
        }

        if ($home < $away) {
            return -1;
        }

        return 0;
    }

    /**
     * Normal football points for one prediction vs the real score.
     * Exact score = 3, correct result (win/draw/loss) = 1, else 0.
     */
    function pointsHelperCalculateBasePoints(
        int $predHome,
        int $predAway,
        int $realHome,
        int $realAway
    ): int {
        if ($predHome === $realHome && $predAway === $realAway) {
            return 3;
        }

        $predicted = pointsHelperResult($predHome, $predAway);
        $actual    = pointsHelperResult($realHome, $realAway);

        return ($predicted === $actual) ? 1 : 0;
    }

    function calculateFinalPoints(
        mysqli $conn,
        int $userId,
        int $matchId,
        int $basePoints
    ): int {
        $basePoints = max(0, (int)$basePoints);

        $stmt = $conn->prepare("\n            SELECT gameweek\n            FROM matches\n            WHERE id = ?\n            LIMIT 1\n        ");

        if (!$stmt) {
            throw new RuntimeException('Match lookup failed: ' . $conn->error);
        }

        $stmt->bind_param('i', $matchId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return $basePoints;
        }

        $gameweek = (int)$row['gameweek'];
                if (function_exists('shipsDoubleAllActive') && shipsDoubleAllActive($conn, $userId, $gameweek)) {
            return $basePoints * 2;
        }

        $stmt = $conn->prepare("\n            SELECT id\n            FROM double_gameweek\n            WHERE user_id = ?\n              AND gameweek = ?\n              AND match_id = ?\n            LIMIT 1\n        ");

        if (!$stmt) {
            throw new RuntimeException('Double Pick lookup failed: ' . $conn->error);
        }

        $stmt->bind_param('iii', $userId, $gameweek, $matchId);
        $stmt->execute();
        $active = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $active ? $basePoints * 2 : $basePoints;
    }

    function syncPredictionPoints(
        mysqli $conn,
        int $userId,
        int $matchId
    ): void {
        $stmt = $conn->prepare("\n            SELECT\n                se.predicted_home,\n                se.predicted_away,\n                m.home_score,\n                m.away_score\n            FROM score_exact se\n            INNER JOIN matches m ON m.id = se.match_id\n            WHERE se.user_id = ?\n              AND se.match_id = ?\n            LIMIT 1\n        ");

        if (!$stmt) {
            throw new RuntimeException('Prediction lookup failed: ' . $conn->error);
        }

        $stmt->bind_param('ii', $userId, $matchId);
        $stmt->execute();
        $prediction = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$prediction) {
            return;
        }

        /*
         * ALWAYS (re)calculate base_points from the real match score
         * here - this is the single source of truth for it. Trusting
         * a stored base_points value that nothing had calculated yet
         * was the bug that left points stuck at 0 after saving a
         * real score. If the match hasn't been played yet, there is
         * no real score to compare against, so base_points is 0.
         */
        if ($prediction['home_score'] !== null && $prediction['away_score'] !== null) {
            $basePoints = pointsHelperCalculateBasePoints(
                (int)$prediction['predicted_home'],
                (int)$prediction['predicted_away'],
                (int)$prediction['home_score'],
                (int)$prediction['away_score']
            );
        } else {
            $basePoints = 0;
        }

        $finalPoints = calculateFinalPoints(
            $conn,
            $userId,
            $matchId,
            $basePoints
        );

        $conn->query('SET @double_recalc = 1');

        $stmt = $conn->prepare("\n            UPDATE score_exact\n            SET base_points = ?,\n                points = ?\n            WHERE user_id = ?\n              AND match_id = ?\n        ");

        if (!$stmt) {
            $conn->query('SET @double_recalc = 0');
            throw new RuntimeException('Prediction update failed: ' . $conn->error);
        }

        $stmt->bind_param('iiii', $basePoints, $finalPoints, $userId, $matchId);
        $stmt->execute();
        $stmt->close();

        $conn->query('SET @double_recalc = 0');

        $stmt = $conn->prepare("\n            UPDATE predictions\n            SET base_points = ?,\n                points = ?\n            WHERE user_id = ?\n              AND match_id = ?\n        ");

        if ($stmt) {
            $stmt->bind_param(
                'iiii',
                $basePoints,
                $finalPoints,
                $userId,
                $matchId
            );
            $stmt->execute();
            $stmt->close();
            
        }
        
        
    
                if (function_exists('settlePerfectFiveForMatch')) {
            settlePerfectFiveForMatch($conn, $userId, $matchId);
        }
    }
        }

    function syncUserPoints(
        mysqli $conn,
        int $userId,
        ?int $gameweek = null
    ): void {
        $sql = "\n            SELECT se.user_id, se.match_id\n            FROM score_exact se\n            INNER JOIN matches m ON m.id = se.match_id\n            WHERE se.user_id = ?\n        ";

        if ($gameweek !== null) {
            $sql .= ' AND m.gameweek = ?';
        }

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Prediction list failed: ' . $conn->error);
        }

        if ($gameweek !== null) {
            $stmt->bind_param('ii', $userId, $gameweek);
        } else {
            $stmt->bind_param('i', $userId);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];

        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }

        $stmt->close();

        foreach ($rows as $row) {
            syncPredictionPoints(
                $conn,
                (int)$row['user_id'],
                (int)$row['match_id']
            );
        }
    }

    function syncAllPoints(mysqli $conn): void {
        $result = $conn->query('SELECT id FROM users');

        if (!$result) {
            throw new RuntimeException('User list failed: ' . $conn->error);
        }

        while ($user = $result->fetch_assoc()) {
            syncUserPoints($conn, (int)$user['id']);
        }
    }
