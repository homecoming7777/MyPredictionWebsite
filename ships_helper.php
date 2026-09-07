<?php

require_once __DIR__ . '/points_helper.php';
require_once __DIR__ . '/gameweek_deadline.php';

/**
 * Ships (chips) system.
 *
 * Two ships, each usable twice per season (once per half):
 *  - DOUBLE_ALL   : doubles every match in the gameweek.
 *  - PERFECT_FIVE : pick 5 matches; all correct = doubled, one wrong = zero.
 *
 * Only one ship (of either kind) can be active per user per gameweek.
 */

if (!function_exists('shipsGetSeasonHalf')) {

    /**
     * Gameweek at which the second half of the season begins.
     * A 38-gameweek season splits 19/19, so this defaults to 20.
     * Change this constant if your season has a different length.
     */
    if (!defined('SHIPS_SECOND_HALF_START_GW')) {
        define('SHIPS_SECOND_HALF_START_GW', 20);
    }

    function shipsGetSeasonHalf(int $gameweek): int
    {
        return $gameweek >= SHIPS_SECOND_HALF_START_GW ? 2 : 1;
    }

    function shipsCatalog(): array
    {
        return [
            'DOUBLE_ALL' => [
                'name' => 'Double Up',
                'description' => 'Doubles the points from every match you predicted this gameweek instead of just your usual single Double Pick match.',
            ],
            'PERFECT_FIVE' => [
                'name' => 'Perfect Five',
                'description' => 'Pick 5 matches from this gameweek. Get every one right (correct result or exact score) and their combined points double. Get even one wrong and you score zero from all 5.',
            ],
        ];
    }

    function shipsIsGameweekLocked(mysqli $conn, int $gameweek): bool
    {
        return isGameweekDeadlinePassed($conn, $gameweek);
    }

    /**
     * All usage rows for a user, keyed by ship_code then half (1/2).
     */
    function shipsGetUserUsage(mysqli $conn, int $userId): array
    {
        $usage = [];
        foreach (array_keys(shipsCatalog()) as $code) {
            $usage[$code] = [1 => null, 2 => null];
        }

        $stmt = $conn->prepare("
            SELECT id, ship_code, season_half, gameweek, status, result, points_awarded, activated_at
            FROM ship_usage
            WHERE user_id = ?
        ");
        if (!$stmt) return $usage;

        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $usage[$row['ship_code']][(int)$row['season_half']] = $row;
        }

        $stmt->close();

        return $usage;
    }

    /**
     * The ship (if any) a user has active/settled for one specific gameweek.
     */
    function shipsGetUsageForGameweek(mysqli $conn, int $userId, int $gameweek): ?array
    {
        $stmt = $conn->prepare("
            SELECT id, ship_code, season_half, gameweek, status, result, points_awarded, activated_at
            FROM ship_usage
            WHERE user_id = ? AND gameweek = ?
            LIMIT 1
        ");
        if (!$stmt) return null;

        $stmt->bind_param('ii', $userId, $gameweek);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    /**
     * Whether a user can activate a given ship for a given gameweek
     * right now. Returns [true, null] or [false, reasonString].
     */
    function shipsCanActivate(mysqli $conn, int $userId, string $shipCode, int $gameweek): array
    {
        $catalog = shipsCatalog();

        if (!isset($catalog[$shipCode])) {
            return [false, 'Unknown ship.'];
        }

        if (shipsIsGameweekLocked($conn, $gameweek)) {
            return [false, 'This gameweek has already started - ships can only be used before the deadline.'];
        }

        $half = shipsGetSeasonHalf($gameweek);

        $existingForGw = shipsGetUsageForGameweek($conn, $userId, $gameweek);
        if ($existingForGw !== null) {
            if ($existingForGw['ship_code'] === $shipCode) {
                return [false, 'You already used this ship for this gameweek.'];
            }
            return [false, 'You can only use one ship per gameweek.'];
        }

        $usage = shipsGetUserUsage($conn, $userId);
        if ($usage[$shipCode][$half] !== null) {
            return [false, 'You already used your ' . $catalog[$shipCode]['name'] . ' for this half of the season.'];
        }

        return [true, null];
    }

    /**
     * Activates Double Up: doubles every match in the gameweek and
     * clears any classic single-match Double Pick already chosen
     * for that gameweek (the two can't stack).
     */
    function shipsActivateDoubleAll(mysqli $conn, int $userId, int $gameweek): array
    {
        [$ok, $reason] = shipsCanActivate($conn, $userId, 'DOUBLE_ALL', $gameweek);
        if (!$ok) {
            return [false, $reason];
        }

        $half = shipsGetSeasonHalf($gameweek);

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                INSERT INTO ship_usage (user_id, ship_code, season_half, gameweek, status)
                VALUES (?, 'DOUBLE_ALL', ?, ?, 'active')
            ");
            $stmt->bind_param('iii', $userId, $half, $gameweek);
            $stmt->execute();
            $stmt->close();

            $del = $conn->prepare("DELETE FROM double_gameweek WHERE user_id = ? AND gameweek = ?");
            $del->bind_param('ii', $userId, $gameweek);
            $del->execute();
            $del->close();

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            return [false, 'Could not activate Double Up: ' . $e->getMessage()];
        }

        // Recalculate points in case results for this gameweek already exist.
        syncUserPoints($conn, $userId, $gameweek);

        return [true, null];
    }

    /**
     * Activates Perfect Five with exactly 5 chosen matches from one
     * (still unlocked) gameweek. The user must already have a
     * prediction saved for each of the 5.
     */
    function shipsActivatePerfectFive(mysqli $conn, int $userId, int $gameweek, array $matchIds): array
    {
        [$ok, $reason] = shipsCanActivate($conn, $userId, 'PERFECT_FIVE', $gameweek);
        if (!$ok) {
            return [false, $reason];
        }

        $matchIds = array_values(array_unique(array_map('intval', $matchIds)));

        if (count($matchIds) !== 5) {
            return [false, 'Pick exactly 5 matches.'];
        }

        $placeholders = implode(',', array_fill(0, count($matchIds), '?'));
        $types = str_repeat('i', count($matchIds));

        $stmt = $conn->prepare("SELECT id FROM matches WHERE gameweek = ? AND id IN ($placeholders)");
        $stmt->bind_param('i' . $types, $gameweek, ...$matchIds);
        $stmt->execute();
        $foundMatches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (count($foundMatches) !== 5) {
            return [false, 'One or more selected matches are not part of this gameweek.'];
        }

        $stmt = $conn->prepare("SELECT match_id FROM score_exact WHERE user_id = ? AND match_id IN ($placeholders)");
        $stmt->bind_param('i' . $types, $userId, ...$matchIds);
        $stmt->execute();
        $predicted = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (count($predicted) !== 5) {
            return [false, 'You need to submit predictions for all 5 matches before picking them.'];
        }

        $half = shipsGetSeasonHalf($gameweek);

        $conn->begin_transaction();

        try {
            $stmt = $conn->prepare("
                INSERT INTO ship_usage (user_id, ship_code, season_half, gameweek, status)
                VALUES (?, 'PERFECT_FIVE', ?, ?, 'active')
            ");
            $stmt->bind_param('iii', $userId, $half, $gameweek);
            $stmt->execute();
            $usageId = $stmt->insert_id;
            $stmt->close();

            $pickStmt = $conn->prepare("
                INSERT INTO ship_five_picks (usage_id, user_id, gameweek, match_id)
                VALUES (?, ?, ?, ?)
            ");

            foreach ($matchIds as $matchId) {
                $pickStmt->bind_param('iiii', $usageId, $userId, $gameweek, $matchId);
                $pickStmt->execute();
            }
            $pickStmt->close();

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            return [false, 'Could not activate Perfect Five: ' . $e->getMessage()];
        }

        // In case some of the 5 already have results.
        settlePerfectFiveByUsageId($conn, (int)$usageId);

        return [true, null];
    }

    /**
     * The active/settled Perfect Five pick for a user + gameweek
     * (with its 5 match ids attached), or null if none.
     */
    function shipsGetPerfectFiveForGameweek(mysqli $conn, int $userId, int $gameweek): ?array
    {
        $stmt = $conn->prepare("
            SELECT id, status, result, points_awarded
            FROM ship_usage
            WHERE user_id = ? AND gameweek = ? AND ship_code = 'PERFECT_FIVE'
            LIMIT 1
        ");
        $stmt->bind_param('ii', $userId, $gameweek);
        $stmt->execute();
        $usage = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$usage) {
            return null;
        }

        $usageId = (int)$usage['id'];

        $stmt = $conn->prepare("SELECT match_id FROM ship_five_picks WHERE usage_id = ?");
        $stmt->bind_param('i', $usageId);
        $stmt->execute();
        $result = $stmt->get_result();

        $matchIds = [];
        while ($row = $result->fetch_assoc()) {
            $matchIds[] = (int)$row['match_id'];
        }
        $stmt->close();

        $usage['match_ids'] = $matchIds;

        return $usage;
    }

    /**
     * Whether Double Up is active for this user + gameweek.
     * points_helper.php calls this to decide whether to double
     * every match instead of just the classic Double Pick match.
     */
    function shipsDoubleAllActive(mysqli $conn, int $userId, int $gameweek): bool
    {
        $stmt = $conn->prepare("
            SELECT id FROM ship_usage
            WHERE user_id = ? AND gameweek = ? AND ship_code = 'DOUBLE_ALL'
            LIMIT 1
        ");
        if (!$stmt) return false;

        $stmt->bind_param('ii', $userId, $gameweek);
        $stmt->execute();
        $active = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        return $active;
    }

    /**
     * Called after a single match's prediction points get resynced.
     * If that match belongs to an active Perfect Five pick and all 5
     * of that pick's matches now have results, settle the whole pick
     * (all-or-nothing).
     */
    function settlePerfectFiveForMatch(mysqli $conn, int $userId, int $matchId): void
    {
        $stmt = $conn->prepare("
            SELECT su.id
            FROM ship_five_picks sfp
            INNER JOIN ship_usage su ON su.id = sfp.usage_id
            WHERE sfp.user_id = ? AND sfp.match_id = ? AND su.status = 'active'
            LIMIT 1
        ");
        if (!$stmt) return;

        $stmt->bind_param('ii', $userId, $matchId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) return;

        settlePerfectFiveByUsageId($conn, (int)$row['id']);
    }

    function settlePerfectFiveByUsageId(mysqli $conn, int $usageId): void
    {
        $stmt = $conn->prepare("SELECT user_id, status FROM ship_usage WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $usageId);
        $stmt->execute();
        $usage = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$usage || $usage['status'] !== 'active') return;

        $userId = (int)$usage['user_id'];

        $stmt = $conn->prepare("
            SELECT m.id AS match_id, m.home_score, m.away_score
            FROM ship_five_picks sfp
            INNER JOIN matches m ON m.id = sfp.match_id
            WHERE sfp.usage_id = ?
        ");
        $stmt->bind_param('i', $usageId);
        $stmt->execute();
        $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (count($matches) !== 5) return;

        foreach ($matches as $m) {
            if ($m['home_score'] === null || $m['away_score'] === null) {
                return; // Not all 5 played yet.
            }
        }

        $matchIds = array_map(function ($m) { return (int)$m['match_id']; }, $matches);

        $placeholders = implode(',', array_fill(0, count($matchIds), '?'));
        $types = str_repeat('i', count($matchIds));

        $stmt = $conn->prepare("
            SELECT match_id, base_points
            FROM score_exact
            WHERE user_id = ? AND match_id IN ($placeholders)
        ");
        $stmt->bind_param('i' . $types, $userId, ...$matchIds);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $basePointsByMatch = [];
        foreach ($matchIds as $id) {
            $basePointsByMatch[$id] = 0;
        }
        foreach ($rows as $r) {
            $basePointsByMatch[(int)$r['match_id']] = (int)$r['base_points'];
        }

        $anyWrong = false;
        foreach ($basePointsByMatch as $bp) {
            if ($bp <= 0) {
                $anyWrong = true;
                break;
            }
        }

        $totalAwarded = 0;

        $conn->begin_transaction();

        try {
            foreach ($matchIds as $matchId) {
                $basePoints = $basePointsByMatch[$matchId];
                $finalPoints = $anyWrong ? 0 : ($basePoints * 2);
                $totalAwarded += $finalPoints;

                $upd = $conn->prepare("UPDATE score_exact SET points = ? WHERE user_id = ? AND match_id = ?");
                $upd->bind_param('iii', $finalPoints, $userId, $matchId);
                $upd->execute();
                $upd->close();

                $upd2 = $conn->prepare("UPDATE predictions SET points = ? WHERE user_id = ? AND match_id = ?");
                if ($upd2) {
                    $upd2->bind_param('iii', $finalPoints, $userId, $matchId);
                    $upd2->execute();
                    $upd2->close();
                }
            }

            $result = $anyWrong ? 'busted' : 'doubled';

            $upd3 = $conn->prepare("UPDATE ship_usage SET status = 'settled', result = ?, points_awarded = ? WHERE id = ?");
            $upd3->bind_param('sii', $result, $totalAwarded, $usageId);
            $upd3->execute();
            $upd3->close();

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
        }
    }
}