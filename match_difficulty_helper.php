<?php

/**
 * Match difficulty from real community prediction distribution (score_exact).
 */

if (!function_exists('difficultyMinimumPredictors')) {

    function difficultyMinimumPredictors(): int
    {
        return 1;
    }

    function difficultyPredictedResult(int $home, int $away): string
    {
        if ($home > $away) {
            return 'home';
        }

        if ($home < $away) {
            return 'away';
        }

        return 'draw';
    }

    function difficultyScoreToLabel(int $score): string
    {
        if ($score <= 3) {
            return 'Easy';
        }

        if ($score <= 5) {
            return 'Medium';
        }

        if ($score <= 7) {
            return 'Hard';
        }

        return 'Very Hard';
    }

    /**
     * @return array{
     *   score:?int,
     *   label:string,
     *   predictor_count:int,
     *   home_pct:float,
     *   draw_pct:float,
     *   away_pct:float,
     *   display:string
     * }
     */
    function difficultyGetForMatch(mysqli $conn, int $matchId): array
    {
        $empty = [
            'score' => null,
            'label' => 'Not enough data',
            'predictor_count' => 0,
            'home_pct' => 0.0,
            'draw_pct' => 0.0,
            'away_pct' => 0.0,
            'display' => 'Not enough data',
        ];

        if ($matchId <= 0) {
            return $empty;
        }

        $stmt = $conn->prepare("
            SELECT predicted_home, predicted_away
            FROM score_exact
            WHERE match_id = ?
              AND predicted_home IS NOT NULL
              AND predicted_away IS NOT NULL
        ");

        if (!$stmt) {
            return $empty;
        }

        $stmt->bind_param('i', $matchId);
        $stmt->execute();
        $result = $stmt->get_result();

        $counts = [
            'home' => 0,
            'draw' => 0,
            'away' => 0,
        ];

        $total = 0;

        while ($row = $result->fetch_assoc()) {
            $outcome = difficultyPredictedResult(
                (int)$row['predicted_home'],
                (int)$row['predicted_away']
            );
            $counts[$outcome]++;
            $total++;
        }

        $stmt->close();

        if ($total < difficultyMinimumPredictors()) {
            return $empty;
        }

        $homePct = round(($counts['home'] / $total) * 100, 1);
        $drawPct = round(($counts['draw'] / $total) * 100, 1);
        $awayPct = round(($counts['away'] / $total) * 100, 1);

        $maxPct = max($homePct, $drawPct, $awayPct);

        // Even community split = harder match to call correctly.
        $score = (int)round(10 - (($maxPct - 33.33) / 66.67) * 9);
        $score = max(1, min(10, $score));

        $label = difficultyScoreToLabel($score);

        return [
            'score' => $score,
            'label' => $label,
            'predictor_count' => $total,
            'home_pct' => $homePct,
            'draw_pct' => $drawPct,
            'away_pct' => $awayPct,
            'display' => $score . '/10 — ' . $label,
        ];
    }

    /**
     * @param int[] $matchIds
     * @return array<int, array>
     */
    function difficultyGetBatchForMatches(mysqli $conn, array $matchIds): array
    {
        $batch = [];

        foreach ($matchIds as $matchId) {
            $matchId = (int)$matchId;

            if ($matchId > 0) {
                $batch[$matchId] = difficultyGetForMatch($conn, $matchId);
            }
        }

        return $batch;
    }

    function difficultyGetScoreValue(mysqli $conn, int $matchId): int
    {
        $data = difficultyGetForMatch($conn, $matchId);

        return (int)($data['score'] ?? 5) ?: 5;
    }
}
