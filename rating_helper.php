<?php

/**
 * Prediction rating (separate from competition points).
 * Updates when match results are scored via points_helper.php.
 */

if (!function_exists('ratingGetTier')) {

    function ratingGetTier(int $rating): array
    {
        if ($rating >= 2000) {
            return [
                'label' => 'Legend',
                'slug' => 'legend',
                'badge_class' => 'bg-purple-600/30 text-purple-200 border-purple-400/40',
            ];
        }

        if ($rating >= 1800) {
            return [
                'label' => 'Elite',
                'slug' => 'elite',
                'badge_class' => 'bg-fuchsia-600/30 text-fuchsia-200 border-fuchsia-400/40',
            ];
        }

        if ($rating >= 1650) {
            return [
                'label' => 'Platinum',
                'slug' => 'platinum',
                'badge_class' => 'bg-cyan-600/30 text-cyan-200 border-cyan-400/40',
            ];
        }

        if ($rating >= 1500) {
            return [
                'label' => 'Gold',
                'slug' => 'gold',
                'badge_class' => 'bg-yellow-500/20 text-yellow-300 border-yellow-400/40',
            ];
        }

        if ($rating >= 1350) {
            return [
                'label' => 'Silver',
                'slug' => 'silver',
                'badge_class' => 'bg-gray-400/20 text-gray-200 border-gray-400/40',
            ];
        }

        if ($rating >= 1200) {
            return [
                'label' => 'Bronze',
                'slug' => 'bronze',
                'badge_class' => 'bg-orange-700/30 text-orange-200 border-orange-500/40',
            ];
        }

        return [
            'label' => 'Beginner',
            'slug' => 'beginner',
            'badge_class' => 'bg-white/10 text-gray-300 border-white/20',
        ];
    }

    function ratingFormatBadge(int $rating): string
    {
        return ratingGetTier($rating)['label'];
    }

    function ratingDefaultValue(): int
    {
        return 1500;
    }

    function ratingMinimumValue(): int
    {
        return 800;
    }

    function ratingMaximumValue(): int
    {
        return 3000;
    }

    function ratingColumnExists(mysqli $conn): bool
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

    function ratingHistoryTableExists(mysqli $conn): bool
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $result = $conn->query("SHOW TABLES LIKE 'user_rating_history'");

        $cached = ($result && $result->num_rows > 0);

        if ($result) {
            $result->free();
        }

        return $cached;
    }

    function ratingGetCurrent(mysqli $conn, int $userId): int
    {
        if ($userId <= 0 || !ratingColumnExists($conn)) {
            return ratingDefaultValue();
        }

        $stmt = $conn->prepare('SELECT prediction_rating FROM users WHERE id = ? LIMIT 1');

        if (!$stmt) {
            return ratingDefaultValue();
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $rating = (int)($row['prediction_rating'] ?? ratingDefaultValue());

        if ($rating <= 0) {
            return ratingDefaultValue();
        }

        return $rating;
    }

    function ratingCalculateChange(int $basePoints, int $difficultyScore): int
    {
        $actual = max(0, min(3, $basePoints)) / 3.0;
        $expected = 0.42;
        $difficultyScore = max(1, min(10, $difficultyScore));
        $kFactor = 10 + ($difficultyScore * 2);

        return (int)round($kFactor * ($actual - $expected));
    }

    function ratingMatchIsFinished(mysqli $conn, int $matchId): bool
    {
        $stmt = $conn->prepare('SELECT home_score, away_score FROM matches WHERE id = ? LIMIT 1');

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $matchId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row
            && $row['home_score'] !== null
            && $row['away_score'] !== null;
    }

    /**
     * Idempotent rating update for one finished match prediction.
     * Called from points_helper after base/final points are saved.
     */
    function ratingUpdateForPrediction(
        mysqli $conn,
        int $userId,
        int $matchId,
        int $basePoints
    ): void {
        if (
            $userId <= 0
            || $matchId <= 0
            || !ratingColumnExists($conn)
            || !ratingHistoryTableExists($conn)
            || !ratingMatchIsFinished($conn, $matchId)
        ) {
            return;
        }

        if (!function_exists('difficultyGetScoreValue')) {
            require_once __DIR__ . '/match_difficulty_helper.php';
        }

        $existingStmt = $conn->prepare("
            SELECT id, rating_change
            FROM user_rating_history
            WHERE user_id = ? AND match_id = ?
            LIMIT 1
        ");

        if (!$existingStmt) {
            return;
        }

        $existingStmt->bind_param('ii', $userId, $matchId);
        $existingStmt->execute();
        $existing = $existingStmt->get_result()->fetch_assoc();
        $existingStmt->close();

        $currentRating = ratingGetCurrent($conn, $userId);

        if ($existing) {
            $currentRating -= (int)($existing['rating_change'] ?? 0);

            $deleteStmt = $conn->prepare('DELETE FROM user_rating_history WHERE id = ? LIMIT 1');

            if ($deleteStmt) {
                $existingId = (int)$existing['id'];
                $deleteStmt->bind_param('i', $existingId);
                $deleteStmt->execute();
                $deleteStmt->close();
            }
        }

        $difficultyScore = difficultyGetScoreValue($conn, $matchId);
        $ratingChange = ratingCalculateChange($basePoints, $difficultyScore);
        $ratingBefore = $currentRating;
        $ratingAfter = $ratingBefore + $ratingChange;
        $ratingAfter = max(ratingMinimumValue(), min(ratingMaximumValue(), $ratingAfter));
        $ratingChange = $ratingAfter - $ratingBefore;

        $updateUser = $conn->prepare('UPDATE users SET prediction_rating = ? WHERE id = ?');

        if (!$updateUser) {
            return;
        }

        $updateUser->bind_param('ii', $ratingAfter, $userId);
        $updateUser->execute();
        $updateUser->close();

        $reason = 'match_result';

        $insertHistory = $conn->prepare("
            INSERT INTO user_rating_history
                (user_id, match_id, rating_before, rating_after, rating_change, reason)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        if ($insertHistory) {
            $insertHistory->bind_param(
                'iiiiis',
                $userId,
                $matchId,
                $ratingBefore,
                $ratingAfter,
                $ratingChange,
                $reason
            );
            $insertHistory->execute();
            $insertHistory->close();
        }
    }

    /**
     * Rebuild one user's rating from all finished predictions.
     * Useful after enabling the system on existing data.
     */
    function ratingRecalculateUser(mysqli $conn, int $userId): void
    {
        if ($userId <= 0 || !ratingColumnExists($conn) || !ratingHistoryTableExists($conn)) {
            return;
        }

        $reset = $conn->prepare('UPDATE users SET prediction_rating = ? WHERE id = ?');

        if ($reset) {
            $default = ratingDefaultValue();
            $reset->bind_param('ii', $default, $userId);
            $reset->execute();
            $reset->close();
        }

        $deleteHistory = $conn->prepare('DELETE FROM user_rating_history WHERE user_id = ?');

        if ($deleteHistory) {
            $deleteHistory->bind_param('i', $userId);
            $deleteHistory->execute();
            $deleteHistory->close();
        }

        $stmt = $conn->prepare("
            SELECT se.match_id, se.base_points
            FROM score_exact se
            INNER JOIN matches m ON m.id = se.match_id
            WHERE se.user_id = ?
              AND m.home_score IS NOT NULL
              AND m.away_score IS NOT NULL
            ORDER BY m.match_date ASC, se.match_id ASC
        ");

        if (!$stmt) {
            return;
        }

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            ratingUpdateForPrediction(
                $conn,
                $userId,
                (int)$row['match_id'],
                (int)($row['base_points'] ?? 0)
            );
        }

        $stmt->close();
    }
}
