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
        $stmt = $conn->prepare("\n            SELECT base_points, points\n            FROM score_exact\n            WHERE user_id = ?\n              AND match_id = ?\n            LIMIT 1\n        ");

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

        $basePoints = max(0, (int)$prediction['base_points']);
        $finalPoints = calculateFinalPoints(
            $conn,
            $userId,
            $matchId,
            $basePoints
        );

        $conn->query('SET @double_recalc = 1');

        $stmt = $conn->prepare("\n            UPDATE score_exact\n            SET points = ?\n            WHERE user_id = ?\n              AND match_id = ?\n        ");

        if (!$stmt) {
            $conn->query('SET @double_recalc = 0');
            throw new RuntimeException('Prediction update failed: ' . $conn->error);
        }

        $stmt->bind_param('iii', $finalPoints, $userId, $matchId);
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
}
