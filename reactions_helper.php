<?php

/**
 * Lightweight match reactions (one toggle per user per type per match).
 * Visible only after enough community predictions exist.
 */

if (!function_exists('reactionsCatalog')) {

    function reactionsTableExists(mysqli $conn): bool
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $result = $conn->query("SHOW TABLES LIKE 'match_reactions'");

        $cached = ($result && $result->num_rows > 0);

        if ($result) {
            $result->free();
        }

        return $cached;
    }

    function reactionsMinimumPredictors(): int
    {
        return 3;
    }

    function reactionsCatalog(): array
    {
        return [
            'fire' => ['emoji' => '🔥', 'label' => 'Fire'],
            'laugh' => ['emoji' => '😂', 'label' => 'Laugh'],
            'shock' => ['emoji' => '😱', 'label' => 'Shock'],
            'clap' => ['emoji' => '👏', 'label' => 'Clap'],
        ];
    }

    function reactionsIsValidType(string $type): bool
    {
        return isset(reactionsCatalog()[strtolower(trim($type))]);
    }

    function reactionsNormalizeType(string $type): string
    {
        return strtolower(trim($type));
    }

    function reactionsGetPredictionCount(mysqli $conn, int $matchId): int
    {
        if ($matchId <= 0) {
            return 0;
        }

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS total
            FROM score_exact
            WHERE match_id = ?
              AND predicted_home IS NOT NULL
              AND predicted_away IS NOT NULL
        ");

        if (!$stmt) {
            return 0;
        }

        $stmt->bind_param('i', $matchId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int)($row['total'] ?? 0);
    }

    function reactionsCanShow(mysqli $conn, int $matchId): bool
    {
        return reactionsGetPredictionCount($conn, $matchId) >= reactionsMinimumPredictors();
    }

    /**
     * @return array<string, array{count:int, active:bool, emoji:string, label:string, users:array<int, array{id:int, username:string}>}>
     */
    function reactionsGetMatchSummary(mysqli $conn, int $matchId, int $userId = 0, bool $includeUsers = true): array
    {
        $summary = [];

        foreach (reactionsCatalog() as $type => $meta) {
            $summary[$type] = [
                'emoji' => $meta['emoji'],
                'label' => $meta['label'],
                'count' => 0,
                'active' => false,
                'users' => [],
            ];
        }

        if ($matchId <= 0 || !reactionsTableExists($conn)) {
            return $summary;
        }

        if ($includeUsers) {
            $stmt = $conn->prepare("
                SELECT mr.reaction_type, u.id, u.username
                FROM match_reactions mr
                INNER JOIN users u ON u.id = mr.user_id
                WHERE mr.match_id = ?
                ORDER BY mr.created_at ASC, u.username ASC
            ");
        } else {
            $stmt = $conn->prepare("
                SELECT reaction_type, COUNT(*) AS total
                FROM match_reactions
                WHERE match_id = ?
                GROUP BY reaction_type
            ");
        }

        if (!$stmt) {
            return $summary;
        }

        $stmt->bind_param('i', $matchId);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($includeUsers) {
            while ($row = $result->fetch_assoc()) {
                $type = reactionsNormalizeType($row['reaction_type'] ?? '');

                if (!isset($summary[$type])) {
                    continue;
                }

                $summary[$type]['count']++;
                $summary[$type]['users'][] = [
                    'id' => (int)$row['id'],
                    'username' => (string)$row['username'],
                ];

                if ($userId > 0 && (int)$row['id'] === $userId) {
                    $summary[$type]['active'] = true;
                }
            }
        } else {
            while ($row = $result->fetch_assoc()) {
                $type = reactionsNormalizeType($row['reaction_type'] ?? '');

                if (isset($summary[$type])) {
                    $summary[$type]['count'] = (int)($row['total'] ?? 0);
                }
            }
        }

        $stmt->close();

        if (!$includeUsers && $userId > 0) {
            $userStmt = $conn->prepare("
                SELECT reaction_type
                FROM match_reactions
                WHERE match_id = ? AND user_id = ?
            ");

            if ($userStmt) {
                $userStmt->bind_param('ii', $matchId, $userId);
                $userStmt->execute();
                $userResult = $userStmt->get_result();

                while ($row = $userResult->fetch_assoc()) {
                    $type = reactionsNormalizeType($row['reaction_type'] ?? '');

                    if (isset($summary[$type])) {
                        $summary[$type]['active'] = true;
                    }
                }

                $userStmt->close();
            }
        }

        return $summary;
    }

    /**
     * @param int[] $matchIds
     * @return array<int, array>
     */
    function reactionsGetBatchSummary(mysqli $conn, array $matchIds, int $userId = 0, bool $includeUsers = true): array
    {
        $batch = [];

        foreach ($matchIds as $matchId) {
            $matchId = (int)$matchId;

            if ($matchId > 0) {
                $batch[$matchId] = reactionsGetMatchSummary($conn, $matchId, $userId, $includeUsers);
            }
        }

        return $batch;
    }

    function reactionsMatchExists(mysqli $conn, int $matchId): bool
    {
        if ($matchId <= 0) {
            return false;
        }

        $stmt = $conn->prepare('SELECT id FROM matches WHERE id = ? LIMIT 1');

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $matchId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $exists;
    }

    /**
     * @return array{success:bool, message?:string, summary?:array, enabled?:bool, predictor_count?:int}
     */
    function reactionsToggle(mysqli $conn, int $userId, int $matchId, string $reactionType): array
    {
        if ($userId <= 0) {
            return ['success' => false, 'message' => 'Login required.'];
        }

        if (!reactionsTableExists($conn)) {
            return ['success' => false, 'message' => 'Reactions are not enabled yet. Run migration_foundation.sql.'];
        }

        if (!reactionsCanShow($conn, $matchId)) {
            $needed = reactionsMinimumPredictors();
            $count = reactionsGetPredictionCount($conn, $matchId);

            return [
                'success' => false,
                'message' => "Reactions unlock after {$needed} players predict (currently {$count}).",
            ];
        }

        $reactionType = reactionsNormalizeType($reactionType);

        if (!reactionsIsValidType($reactionType)) {
            return ['success' => false, 'message' => 'Invalid reaction.'];
        }

        if (!reactionsMatchExists($conn, $matchId)) {
            return ['success' => false, 'message' => 'Match not found.'];
        }

        $check = $conn->prepare("
            SELECT id
            FROM match_reactions
            WHERE user_id = ? AND match_id = ? AND reaction_type = ?
            LIMIT 1
        ");

        if (!$check) {
            return ['success' => false, 'message' => 'Database error.'];
        }

        $check->bind_param('iis', $userId, $matchId, $reactionType);
        $check->execute();
        $existing = $check->get_result()->fetch_assoc();
        $check->close();

        if ($existing) {
            $delete = $conn->prepare('DELETE FROM match_reactions WHERE id = ? LIMIT 1');

            if (!$delete) {
                return ['success' => false, 'message' => 'Database error.'];
            }

            $existingId = (int)$existing['id'];
            $delete->bind_param('i', $existingId);

            if (!$delete->execute()) {
                $message = $delete->error ?: 'Could not remove reaction.';
                $delete->close();

                return ['success' => false, 'message' => $message];
            }

            $delete->close();
        } else {
            $insert = $conn->prepare("
                INSERT INTO match_reactions (user_id, match_id, reaction_type)
                VALUES (?, ?, ?)
            ");

            if (!$insert) {
                return ['success' => false, 'message' => 'Database error.'];
            }

            $insert->bind_param('iis', $userId, $matchId, $reactionType);

            if (!$insert->execute()) {
                $message = $insert->error ?: 'Could not save reaction.';
                $insert->close();

                return ['success' => false, 'message' => $message];
            }

            $insert->close();
        }

        return [
            'success' => true,
            'enabled' => true,
            'predictor_count' => reactionsGetPredictionCount($conn, $matchId),
            'summary' => reactionsGetMatchSummary($conn, $matchId, $userId, true),
        ];
    }

    function reactionsRenderBlock(
        mysqli $conn,
        int $matchId,
        int $userId,
        ?array $batch = null
    ): void {
        if (!function_exists('e')) {
            function e($value)
            {
                return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
            }
        }

        $widget_match_id = $matchId;
        $widget_predictor_count = reactionsGetPredictionCount($conn, $matchId);
        $widget_reactions_enabled = reactionsCanShow($conn, $matchId);
        $widget_reaction_summary = $batch[$matchId]
            ?? reactionsGetMatchSummary($conn, $matchId, $userId, true);

        require __DIR__ . '/reactions_widget.php';
    }
}
