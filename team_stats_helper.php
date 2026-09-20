<?php

/*
|--------------------------------------------------------------------------
| TEAM STATISTICS HELPER
|--------------------------------------------------------------------------
|
| Pure, reusable functions that calculate team statistics from the
| existing `matches` table (and the existing `teams` table for display
| names / logos). No hard-coded numbers, no new API, no writes.
|
| Every public function is defensive: bad/unknown team names, empty
| result sets, and division by zero are all handled and simply return
| zeroed-out / "no data" structures instead of throwing.
|
*/

if (!function_exists('teamStatsNormalizeKey')) {

    function teamStatsNormalizeKey(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s+/', ' ', $name);

        return mb_strtolower($name ?? '');
    }

    /**
     * Look up a team by (loose, case-insensitive) name and build a safe
     * "profile" for it: canonical key, best display name, logo, and the
     * competition(s) it has matches in. Returns null when the team is
     * completely unknown (never seen in `teams` or `matches`).
     *
     * @return null|array{
     *   key:string,
     *   name:string,
     *   logo:?string,
     *   short_name:?string,
     *   competitions: array<string,int>,
     *   primary_competition: ?string,
     *   appearances:int
     * }
     */
    function teamStatsResolveTeam(mysqli $conn, string $rawTeam): ?array
    {
        $key = teamStatsNormalizeKey($rawTeam);

        if ($key === '') {
            return null;
        }

        $displayName = null;
        $logo = null;
        $competitions = [];
        $appearances = 0;

        $stmt = $conn->prepare("
            SELECT home_team AS team_name, home_team_pic AS pic, competition, match_date
            FROM matches
            WHERE LOWER(TRIM(home_team)) = ?
            UNION ALL
            SELECT away_team AS team_name, away_team_pic AS pic, competition, match_date
            FROM matches
            WHERE LOWER(TRIM(away_team)) = ?
            ORDER BY match_date DESC
        ");

        if ($stmt) {
            $stmt->bind_param('ss', $key, $key);

            if ($stmt->execute()) {
                $result = $stmt->get_result();

                $nameCounts = [];

                while ($row = $result->fetch_assoc()) {
                    $appearances++;

                    $name = trim((string)($row['team_name'] ?? ''));

                    if ($name !== '') {
                        $nameCounts[$name] = ($nameCounts[$name] ?? 0) + 1;

                        if ($displayName === null) {
                            // First row = most recent (ORDER BY match_date DESC).
                            $displayName = $name;
                        }
                    }

                    if ($logo === null && !empty($row['pic'])) {
                        $logo = (string)$row['pic'];
                    }

                    $comp = trim((string)($row['competition'] ?? ''));

                    if ($comp !== '') {
                        $competitions[$comp] = ($competitions[$comp] ?? 0) + 1;
                    }
                }

                if ($displayName === null && !empty($nameCounts)) {
                    arsort($nameCounts);
                    $displayName = array_key_first($nameCounts);
                }
            }

            $stmt->close();
        }

        arsort($competitions);
        $primaryCompetition = !empty($competitions) ? array_key_first($competitions) : null;

        // Cross-reference the existing `teams` table for a canonical logo /
        // short name, but never let it override real match data if that
        // data already gave us something.
        $teamRow = null;

        $teamStmt = $conn->prepare("
            SELECT name, short_name, logo
            FROM teams
            WHERE LOWER(TRIM(name)) = ?
            LIMIT 1
        ");

        if ($teamStmt) {
            $teamStmt->bind_param('s', $key);

            if ($teamStmt->execute()) {
                $teamRow = $teamStmt->get_result()->fetch_assoc() ?: null;
            }

            $teamStmt->close();
        }

        if ($teamRow) {
            if ($logo === null && !empty($teamRow['logo'])) {
                $logo = (string)$teamRow['logo'];
            }

            if ($displayName === null && !empty($teamRow['name'])) {
                $displayName = ucwords((string)$teamRow['name']);
            }
        }

        if ($displayName === null && $teamRow === null && $appearances === 0) {
            // Never seen anywhere: unknown team.
            return null;
        }

        if ($displayName === null) {
            $displayName = ucwords($key);
        }

        return [
            'key' => $key,
            'name' => $displayName,
            'logo' => $logo,
            'short_name' => $teamRow['short_name'] ?? null,
            'competitions' => $competitions,
            'primary_competition' => $primaryCompetition,
            'appearances' => $appearances,
        ];
    }

    /**
     * All teams from the existing `teams` table, for a picker/search UI.
     *
     * @return array<int, array{id:int,name:string,short_name:string,logo:?string}>
     */
    function teamStatsAllTeams(mysqli $conn): array
    {
        $teams = [];

        $result = $conn->query("
            SELECT id, name, short_name, logo
            FROM teams
            ORDER BY name ASC
        ");

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $teams[] = [
                    'id' => (int)$row['id'],
                    'name' => ucwords((string)$row['name']),
                    'short_name' => (string)($row['short_name'] ?? ''),
                    'logo' => $row['logo'] ?: null,
                ];
            }
        }

        return $teams;
    }

    /**
     * Fetch every *completed* match (both score columns present) involving
     * a team, oldest first, optionally restricted to one competition.
     *
     * @return array<int, array<string,mixed>>
     */
    function teamStatsFetchCompletedMatches(mysqli $conn, string $teamKey, ?string $competition = null): array
    {
        $matches = [];

        if ($teamKey === '') {
            return $matches;
        }

        $sql = "
            SELECT id, home_team, home_team_pic, away_team, away_team_pic,
                   home_score, away_score, match_date, gameweek, competition
            FROM matches
            WHERE (LOWER(TRIM(home_team)) = ? OR LOWER(TRIM(away_team)) = ?)
              AND home_score IS NOT NULL
              AND away_score IS NOT NULL
        ";

        $types = 'ss';
        $params = [$teamKey, $teamKey];

        if ($competition !== null && $competition !== '') {
            $sql .= " AND competition = ?";
            $types .= 's';
            $params[] = $competition;
        }

        $sql .= " ORDER BY match_date ASC, id ASC";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return $matches;
        }

        $stmt->bind_param($types, ...$params);

        if ($stmt->execute()) {
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $matches[] = $row;
            }
        }

        $stmt->close();

        return $matches;
    }

    /**
     * Upcoming (not yet played) matches for a team.
     *
     * @return array<int, array<string,mixed>>
     */
    function getUpcomingMatches(mysqli $conn, string $teamKey, ?string $competition = null, int $limit = 5): array
    {
        $upcoming = [];

        if ($teamKey === '' || $limit <= 0) {
            return $upcoming;
        }

        $sql = "
            SELECT id, home_team, home_team_pic, away_team, away_team_pic,
                   match_date, gameweek, competition
            FROM matches
            WHERE (LOWER(TRIM(home_team)) = ? OR LOWER(TRIM(away_team)) = ?)
              AND (home_score IS NULL OR away_score IS NULL)
        ";

        $types = 'ss';
        $params = [$teamKey, $teamKey];

        if ($competition !== null && $competition !== '') {
            $sql .= " AND competition = ?";
            $types .= 's';
            $params[] = $competition;
        }

        $sql .= " ORDER BY match_date ASC, id ASC LIMIT ?";
        $types .= 'i';
        $params[] = $limit;

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return $upcoming;
        }

        $stmt->bind_param($types, ...$params);

        if ($stmt->execute()) {
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $isHome = teamStatsNormalizeKey((string)$row['home_team']) === $teamKey;

                $upcoming[] = [
                    'id' => (int)$row['id'],
                    'date' => $row['match_date'],
                    'competition' => (string)$row['competition'],
                    'gameweek' => (int)$row['gameweek'],
                    'is_home' => $isHome,
                    'opponent' => $isHome ? $row['away_team'] : $row['home_team'],
                    'opponent_logo' => $isHome ? $row['away_team_pic'] : $row['home_team_pic'],
                ];
            }
        }

        $stmt->close();

        return $upcoming;
    }

    function teamStatsResultLetter(int $goalsFor, int $goalsAgainst): string
    {
        if ($goalsFor > $goalsAgainst) {
            return 'W';
        }

        if ($goalsFor < $goalsAgainst) {
            return 'L';
        }

        return 'D';
    }

    function teamStatsPointsForLetter(string $letter): int
    {
        if ($letter === 'W') {
            return 3;
        }

        if ($letter === 'D') {
            return 1;
        }

        return 0;
    }

    /**
     * Full statistics package for one team: overall / home / away / attack
     * / defence, all derived purely from completed matches.
     */
    function getTeamStatistics(mysqli $conn, string $teamKey, ?string $competition = null): array
    {
        $empty = [
            'has_data' => false,
            'played' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0,
            'win_pct' => 0.0, 'draw_pct' => 0.0, 'loss_pct' => 0.0,
            'points' => 0, 'points_per_match' => 0.0,
            'goals_for' => 0, 'goals_against' => 0, 'goal_difference' => 0,
            'home' => [
                'played' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0,
                'goals_for' => 0, 'goals_against' => 0, 'win_pct' => 0.0,
            ],
            'away' => [
                'played' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0,
                'goals_for' => 0, 'goals_against' => 0, 'win_pct' => 0.0,
            ],
            'attack' => [
                'avg_goals_scored' => 0.0, 'matches_scored_in' => 0,
                'failed_to_score' => 0, 'two_plus_goals' => 0, 'three_plus_goals' => 0,
            ],
            'defence' => [
                'clean_sheets' => 0, 'failed_clean_sheet' => 0,
                'avg_goals_conceded' => 0.0, 'conceding_two_plus' => 0,
            ],
        ];

        if ($teamKey === '') {
            return $empty;
        }

        $matches = teamStatsFetchCompletedMatches($conn, $teamKey, $competition);

        if (empty($matches)) {
            return $empty;
        }

        $stats = $empty;
        $stats['has_data'] = true;

        foreach ($matches as $m) {
            $isHome = teamStatsNormalizeKey((string)$m['home_team']) === $teamKey;

            $gf = $isHome ? (int)$m['home_score'] : (int)$m['away_score'];
            $ga = $isHome ? (int)$m['away_score'] : (int)$m['home_score'];

            $letter = teamStatsResultLetter($gf, $ga);

            $stats['played']++;
            $stats['goals_for'] += $gf;
            $stats['goals_against'] += $ga;

            if ($letter === 'W') {
                $stats['wins']++;
            } elseif ($letter === 'D') {
                $stats['draws']++;
            } else {
                $stats['losses']++;
            }

            $venue = $isHome ? 'home' : 'away';
            $stats[$venue]['played']++;
            $stats[$venue]['goals_for'] += $gf;
            $stats[$venue]['goals_against'] += $ga;

            if ($letter === 'W') {
                $stats[$venue]['wins']++;
            } elseif ($letter === 'D') {
                $stats[$venue]['draws']++;
            } else {
                $stats[$venue]['losses']++;
            }

            if ($gf > 0) {
                $stats['attack']['matches_scored_in']++;
            } else {
                $stats['attack']['failed_to_score']++;
            }

            if ($gf >= 2) {
                $stats['attack']['two_plus_goals']++;
            }

            if ($gf >= 3) {
                $stats['attack']['three_plus_goals']++;
            }

            if ($ga === 0) {
                $stats['defence']['clean_sheets']++;
            } else {
                $stats['defence']['failed_clean_sheet']++;
            }

            if ($ga >= 2) {
                $stats['defence']['conceding_two_plus']++;
            }
        }

        $stats['points'] = ($stats['wins'] * 3) + $stats['draws'];
        $stats['goal_difference'] = $stats['goals_for'] - $stats['goals_against'];

        if ($stats['played'] > 0) {
            $stats['win_pct'] = round(($stats['wins'] / $stats['played']) * 100, 1);
            $stats['draw_pct'] = round(($stats['draws'] / $stats['played']) * 100, 1);
            $stats['loss_pct'] = round(($stats['losses'] / $stats['played']) * 100, 1);
            $stats['points_per_match'] = round($stats['points'] / $stats['played'], 2);
            $stats['attack']['avg_goals_scored'] = round($stats['goals_for'] / $stats['played'], 2);
            $stats['defence']['avg_goals_conceded'] = round($stats['goals_against'] / $stats['played'], 2);
        }

        foreach (['home', 'away'] as $venue) {
            if ($stats[$venue]['played'] > 0) {
                $stats[$venue]['win_pct'] = round(($stats[$venue]['wins'] / $stats[$venue]['played']) * 100, 1);
            }
        }

        return $stats;
    }

    /**
     * Thin wrapper: home-only slice of getTeamStatistics(), for callers
     * that only want home form without re-reading the whole payload.
     */
    function getHomeStatistics(mysqli $conn, string $teamKey, ?string $competition = null): array
    {
        return getTeamStatistics($conn, $teamKey, $competition)['home'];
    }

    /**
     * Thin wrapper: away-only slice of getTeamStatistics().
     */
    function getAwayStatistics(mysqli $conn, string $teamKey, ?string $competition = null): array
    {
        return getTeamStatistics($conn, $teamKey, $competition)['away'];
    }

    /**
     * Recent form: last N completed matches, most recent first.
     *
     * @return array{
     *   has_data:bool,
     *   sequence:string[],
     *   recent_points:int,
     *   matches: array<int, array<string,mixed>>
     * }
     */
    function getTeamForm(mysqli $conn, string $teamKey, ?string $competition = null, int $limit = 10): array
    {
        $empty = ['has_data' => false, 'sequence' => [], 'recent_points' => 0, 'matches' => []];

        if ($teamKey === '' || $limit <= 0) {
            return $empty;
        }

        $all = teamStatsFetchCompletedMatches($conn, $teamKey, $competition);

        if (empty($all)) {
            return $empty;
        }

        // Most recent first.
        $all = array_reverse($all);
        $slice = array_slice($all, 0, $limit);

        $sequence = [];
        $matches = [];
        $points = 0;

        foreach ($slice as $m) {
            $isHome = teamStatsNormalizeKey((string)$m['home_team']) === $teamKey;

            $gf = $isHome ? (int)$m['home_score'] : (int)$m['away_score'];
            $ga = $isHome ? (int)$m['away_score'] : (int)$m['home_score'];

            $letter = teamStatsResultLetter($gf, $ga);
            $sequence[] = $letter;
            $points += teamStatsPointsForLetter($letter);

            $matches[] = [
                'id' => (int)$m['id'],
                'date' => $m['match_date'],
                'competition' => (string)$m['competition'],
                'is_home' => $isHome,
                'opponent' => $isHome ? $m['away_team'] : $m['home_team'],
                'opponent_logo' => $isHome ? $m['away_team_pic'] : $m['home_team_pic'],
                'goals_for' => $gf,
                'goals_against' => $ga,
                'letter' => $letter,
            ];
        }

        // Sequence is returned oldest -> newest (reads left to right ending
        // with the most recent result, e.g. "W W D W L").
        return [
            'has_data' => true,
            'sequence' => array_reverse($sequence),
            'recent_points' => $points,
            'matches' => $matches,
        ];
    }

    /**
     * Recent results list for display (date / competition / opponent /
     * venue / score / outcome), most recent first.
     *
     * @return array<int, array<string,mixed>>
     */
    function getRecentMatches(mysqli $conn, string $teamKey, ?string $competition = null, int $limit = 5): array
    {
        $form = getTeamForm($conn, $teamKey, $competition, $limit);

        if (!$form['has_data']) {
            return [];
        }

        // getTeamForm()['matches'] is most-recent-first already.
        return $form['matches'];
    }

    /**
     * Head-to-head record between two teams, from completed matches only.
     *
     * @return array{
     *   has_data:bool,
     *   meetings:int,
     *   team_a_wins:int,
     *   draws:int,
     *   team_b_wins:int,
     *   team_a_goals:int,
     *   team_b_goals:int,
     *   matches: array<int, array<string,mixed>>
     * }
     */
    function getHeadToHead(mysqli $conn, string $teamAKey, string $teamBKey, ?string $competition = null, int $limit = 10): array
    {
        $empty = [
            'has_data' => false,
            'meetings' => 0,
            'team_a_wins' => 0,
            'draws' => 0,
            'team_b_wins' => 0,
            'team_a_goals' => 0,
            'team_b_goals' => 0,
            'matches' => [],
        ];

        if ($teamAKey === '' || $teamBKey === '' || $teamAKey === $teamBKey) {
            return $empty;
        }

        $sql = "
            SELECT id, home_team, home_team_pic, away_team, away_team_pic,
                   home_score, away_score, match_date, competition
            FROM matches
            WHERE home_score IS NOT NULL
              AND away_score IS NOT NULL
              AND (
                    (LOWER(TRIM(home_team)) = ? AND LOWER(TRIM(away_team)) = ?)
                 OR (LOWER(TRIM(home_team)) = ? AND LOWER(TRIM(away_team)) = ?)
              )
        ";

        $types = 'ssss';
        $params = [$teamAKey, $teamBKey, $teamBKey, $teamAKey];

        if ($competition !== null && $competition !== '') {
            $sql .= " AND competition = ?";
            $types .= 's';
            $params[] = $competition;
        }

        $sql .= " ORDER BY match_date DESC, id DESC";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            return $empty;
        }

        $stmt->bind_param($types, ...$params);

        $result = null;

        if ($stmt->execute()) {
            $result = $stmt->get_result();
        }

        $stmt->close();

        if (!$result || $result->num_rows === 0) {
            return $empty;
        }

        $data = $empty;
        $data['has_data'] = true;

        $count = 0;

        while ($row = $result->fetch_assoc()) {
            $count++;

            $isAHome = teamStatsNormalizeKey((string)$row['home_team']) === $teamAKey;

            $aGoals = $isAHome ? (int)$row['home_score'] : (int)$row['away_score'];
            $bGoals = $isAHome ? (int)$row['away_score'] : (int)$row['home_score'];

            $data['team_a_goals'] += $aGoals;
            $data['team_b_goals'] += $bGoals;
            $data['meetings']++;

            if ($aGoals > $bGoals) {
                $data['team_a_wins']++;
            } elseif ($aGoals < $bGoals) {
                $data['team_b_wins']++;
            } else {
                $data['draws']++;
            }

            if ($count <= $limit) {
                $data['matches'][] = [
                    'id' => (int)$row['id'],
                    'date' => $row['match_date'],
                    'competition' => (string)$row['competition'],
                    'home_team' => $row['home_team'],
                    'away_team' => $row['away_team'],
                    'home_score' => (int)$row['home_score'],
                    'away_score' => (int)$row['away_score'],
                ];
            }
        }

        return $data;
    }

    /**
     * Simple standings table computed purely from completed matches in one
     * competition (points, then goal difference, then goals scored).
     *
     * @return array<string, array{
     *   key:string, name:string, played:int, points:int,
     *   goal_difference:int, goals_for:int, rank:int
     * }>
     */
    function teamStatsComputeStandings(mysqli $conn, string $competition): array
    {
        $table = [];

        if ($competition === '') {
            return $table;
        }

        $stmt = $conn->prepare("
            SELECT home_team, away_team, home_score, away_score
            FROM matches
            WHERE competition = ?
              AND home_score IS NOT NULL
              AND away_score IS NOT NULL
        ");

        if (!$stmt) {
            return $table;
        }

        $stmt->bind_param('s', $competition);

        if ($stmt->execute()) {
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $homeKey = teamStatsNormalizeKey((string)$row['home_team']);
                $awayKey = teamStatsNormalizeKey((string)$row['away_team']);
                $hs = (int)$row['home_score'];
                $as = (int)$row['away_score'];

                foreach ([$homeKey => $row['home_team'], $awayKey => $row['away_team']] as $key => $displayName) {
                    if (!isset($table[$key])) {
                        $table[$key] = [
                            'key' => $key,
                            'name' => $displayName,
                            'played' => 0,
                            'points' => 0,
                            'goal_difference' => 0,
                            'goals_for' => 0,
                            'rank' => 0,
                        ];
                    }
                }

                $table[$homeKey]['played']++;
                $table[$awayKey]['played']++;
                $table[$homeKey]['goals_for'] += $hs;
                $table[$awayKey]['goals_for'] += $as;
                $table[$homeKey]['goal_difference'] += ($hs - $as);
                $table[$awayKey]['goal_difference'] += ($as - $hs);

                if ($hs > $as) {
                    $table[$homeKey]['points'] += 3;
                } elseif ($hs < $as) {
                    $table[$awayKey]['points'] += 3;
                } else {
                    $table[$homeKey]['points'] += 1;
                    $table[$awayKey]['points'] += 1;
                }
            }
        }

        $stmt->close();

        $rows = array_values($table);

        usort($rows, static function ($a, $b) {
            return $b['points'] <=> $a['points']
                ?: $b['goal_difference'] <=> $a['goal_difference']
                ?: $b['goals_for'] <=> $a['goals_for'];
        });

        $ranked = [];

        foreach ($rows as $i => $row) {
            $row['rank'] = $i + 1;
            $ranked[$row['key']] = $row;
        }

        return $ranked;
    }

    /**
     * League position of a team within one competition, or null when there
     * isn't enough data (no completed matches / unknown competition).
     */
    function getTeamPosition(mysqli $conn, string $teamKey, ?string $competition): ?int
    {
        if ($teamKey === '' || $competition === null || $competition === '') {
            return null;
        }

        $standings = teamStatsComputeStandings($conn, $competition);

        return isset($standings[$teamKey]) ? (int)$standings[$teamKey]['rank'] : null;
    }
}