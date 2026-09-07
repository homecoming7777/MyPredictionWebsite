<?php

    session_start();
    include 'connect.php';

    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }

        $user_id = (int) $_SESSION['user_id'];

    require_once 'points_helper.php';
    require_once 'ships_helper.php';

    function e($value)
    {
        return htmlspecialchars(
            (string)$value,
            ENT_QUOTES,
            'UTF-8'
        );
    }

    /**
     * Lowercase + strip accents/punctuation so "Atlético Madrid",
     * "Atletico Madrid" and "ATLETICO MADRID" all compare equal.
     */
    function normalizeTeamName($name)
    {
        $name = mb_strtolower(trim((string) $name), 'UTF-8');

        $accents = [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y',
            'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe',
        ];
        $name = strtr($name, $accents);

        $name = preg_replace('/[^a-z0-9]+/', ' ', $name);
        $name = trim(preg_replace('/\s+/', ' ', $name));

        return $name;
    }

    /**
     * A looser version of normalizeTeamName() that also drops common
     * club words (fc, cf, united, etc.) so e.g. "Barcelona" and
     * "FC Barcelona" match without needing a manual alias.
     */
    function normalizeTeamKey($name)
    {
        $name = normalizeTeamName($name);

        $stripWords = [
            'fc', 'cf', 'afc', 'ac', 'cd', 'ca', 'sc', 'ssc', 'ud', 'sd',
            'rc', 'rcd', 'cfc', 'calcio', 'club', 'de', 'football', 'united',
        ];

        $parts = array_values(array_filter(
            explode(' ', $name),
            function ($word) use ($stripWords) {
                return $word !== '' && !in_array($word, $stripWords, true);
            }
        ));

        $key = implode(' ', $parts);

        return $key !== '' ? $key : $name;
    }

    /**
     * Confirms a logo path stored in the DB actually exists on disk.
     * If the exact case doesn't exist (e.g. DB says "Barcelona.png" but
     * the real file is "barcelona.PNG"), it looks for a case-insensitive
     * match in the same folder and corrects the path automatically.
     * Returns null if nothing usable is found, so callers can keep
     * trying other candidates instead of rendering a broken <img>.
     */
    function resolveLocalLogoPath($logoPath)
    {
        $logoPath = trim((string) $logoPath);

        if ($logoPath === '') {
            return null;
        }

        // Remote URLs are trusted as-is.
        if (preg_match('#^(https?:)?//#i', $logoPath)) {
            return $logoPath;
        }

        $relative = ltrim(str_replace('\\', '/', $logoPath), '/');
        $fullPath = __DIR__ . DIRECTORY_SEPARATOR . $relative;

        if (file_exists($fullPath)) {
            return $relative;
        }

        $dir = dirname($fullPath);
        $base = basename($relative);

        if (is_dir($dir)) {
            $entries = @scandir($dir);

            if ($entries) {
                foreach ($entries as $entry) {
                    if ($entry !== '.' && $entry !== '..' && strcasecmp($entry, $base) === 0) {
                        $relativeDir = dirname($relative);

                        return ($relativeDir === '.' ? '' : $relativeDir . '/') . $entry;
                    }
                }
            }
        }

        return null;
    }

    function teamLogo($teamName, $conn)
    {
        static $teamsCache = null;

        $normalizedInput = normalizeTeamName($teamName);

        if ($normalizedInput === '') {
            return null;
        }

        // Manual aliases for clubs whose common name differs a lot from
        // however they're stored in the `teams` table. Add more pairs
        // here whenever a specific club's logo still doesn't show up.
        $aliases = [
            'barcelona' => ['fc barcelona', 'barca'],
            'real madrid' => ['real madrid cf'],
            'atletico madrid' => ['atletico de madrid', 'atl madrid'],
            'manchester united' => ['man utd', 'man united', 'manchester utd'],
            'manchester city' => ['man city'],
            'tottenham' => ['tottenham hotspur', 'spurs'],
            'wolves' => ['wolverhampton', 'wolverhampton wanderers'],
            'newcastle' => ['newcastle united'],
            'leicester' => ['leicester city'],
            'west ham' => ['west ham united'],
            'psg' => ['paris saint germain', 'paris sg'],
            'bayern munich' => ['bayern munchen', 'fc bayern munchen', 'fc bayern'],
            'inter milan' => ['inter', 'internazionale', 'fc internazionale'],
            'ac milan' => ['milan'],
            'juventus' => ['juve'],
            'borussia dortmund' => ['dortmund', 'bvb'],
        ];

        // Load every team once per request instead of querying per lookup.
        if ($teamsCache === null) {
            $teamsCache = [];
            $res = $conn->query("SELECT name, logo FROM teams WHERE logo IS NOT NULL AND logo != ''");

            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $teamsCache[] = [
                        'normalized' => normalizeTeamName($row['name']),
                        'key' => normalizeTeamKey($row['name']),
                        'logo' => $row['logo'],
                    ];
                }
            }
        }

        // Collect every name variant worth trying, most confident first.
        $candidates = [$normalizedInput];

        foreach ($aliases as $canonical => $variants) {
            if ($normalizedInput === $canonical || in_array($normalizedInput, $variants, true)) {
                $candidates[] = $canonical;
                foreach ($variants as $variant) {
                    $candidates[] = $variant;
                }
            }
        }

        $candidates = array_unique($candidates);

        // 1) Exact match (accent/case/punctuation-insensitive).
        foreach ($candidates as $candidate) {
            foreach ($teamsCache as $team) {
                if ($team['normalized'] === $candidate) {
                    $resolved = resolveLocalLogoPath($team['logo']);

                    if ($resolved !== null) {
                        return $resolved;
                    }
                }
            }
        }

        // 2) Loose match ignoring words like "FC", "CF", "United".
        $inputKey = normalizeTeamKey($normalizedInput);

        foreach ($teamsCache as $team) {
            if ($team['key'] === $inputKey) {
                $resolved = resolveLocalLogoPath($team['logo']);

                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        // 3) Local image files in PL_Teams/ named after the team.
        foreach (array_merge($candidates, [$inputKey]) as $candidate) {
            $safeName = trim(preg_replace('/[^a-z0-9]+/', '_', $candidate), '_');

            if ($safeName === '') {
                continue;
            }

            foreach (['png', 'jpg', 'jpeg', 'webp'] as $ext) {
                $fullPath = __DIR__ . DIRECTORY_SEPARATOR . 'PL_Teams' . DIRECTORY_SEPARATOR . $safeName . '.' . $ext;

                if (file_exists($fullPath)) {
                    return 'PL_Teams/' . $safeName . '.' . $ext;
                }
            }
        }

        // 4) Last resort: partial match either direction (e.g. "Real Madrid"
        // inside "Real Madrid CF", or vice versa).
        foreach ($teamsCache as $team) {
            if ($team['normalized'] !== '' && (
                strpos($team['normalized'], $normalizedInput) !== false ||
                strpos($normalizedInput, $team['normalized']) !== false
            )) {
                $resolved = resolveLocalLogoPath($team['logo']);

                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    function pointBadge($points, $isDouble = false)
    {
        if ($points === null || $points === '') {
            return '<span class="inline-flex items-center justify-center min-w-[48px] px-3 py-1.5 rounded-full bg-white/10 text-gray-400 font-black">-</span>';
        }

        $points = (int)$points;

        if ($points >= 3) {
            $class = 'bg-green-400 text-black';
        } elseif ($points > 0) {
            $class = 'bg-yellow-400 text-black';
        } else {
            $class = 'bg-red-500 text-white';
        }

        return '<span class="inline-flex items-center justify-center min-w-[48px] px-3 py-1.5 rounded-full font-black ' . $class . '">' . $points . '</span>';
    }

    $gameweeks = [];

    $weeks_result = $conn->query("
        SELECT DISTINCT gameweek
        FROM matches
        ORDER BY gameweek ASC
    ");

    if ($weeks_result) {
        while ($row = $weeks_result->fetch_assoc()) {
            $gameweeks[] = (int)$row['gameweek'];
        }
    }

    $latest_gameweek = 1;

    $latest_result = $conn->query("
        SELECT MAX(gameweek) AS latest_gw
        FROM matches
    ");

    if ($latest_result) {
        $latest_row = $latest_result->fetch_assoc();
        $latest_gameweek = (int)($latest_row['latest_gw'] ?? 1);
    }

    if (isset($_GET['gameweek'])) {
        $gameweek = (int)$_GET['gameweek'];
    } else {
        $gameweek = $latest_gameweek;
    }

    if (!empty($gameweeks) && !in_array($gameweek, $gameweeks, true)) {
        $gameweek = $latest_gameweek;
    }

    $deadline = null;

    $deadline_stmt = $conn->prepare("
        SELECT MIN(match_date) AS first_match
        FROM matches
        WHERE gameweek = ?
    ");

    if ($deadline_stmt) {
        $deadline_stmt->bind_param("i", $gameweek);
        $deadline_stmt->execute();
        $deadline_row = $deadline_stmt->get_result()->fetch_assoc();

        if ($deadline_row && !empty($deadline_row['first_match'])) {
            $deadline = strtotime($deadline_row['first_match']);
        }

        $deadline_stmt->close();
    }

    $is_locked = false;

    if ($deadline !== null && time() >= $deadline) {
        $is_locked = true;
    }

    $view_user_id = $user_id;
    $view_username = null;
    $is_viewing_other_user = false;

    if ($is_locked && isset($_GET['view_user'])) {
        $requested_user_id = (int)$_GET['view_user'];

        if ($requested_user_id > 0) {
            $user_stmt = $conn->prepare("
                SELECT id, username
                FROM users
                WHERE id = ?
                LIMIT 1
            ");

            if ($user_stmt) {
                $user_stmt->bind_param("i", $requested_user_id);
                $user_stmt->execute();
                $user_result = $user_stmt->get_result();
                $view_user = $user_result->fetch_assoc();

                if ($view_user) {
                    $view_user_id = (int)$view_user['id'];
                    $view_username = $view_user['username'];
                    $is_viewing_other_user = $view_user_id !== $user_id;
                }

                $user_stmt->close();
            }
        }
    }

        if (!$is_viewing_other_user) {
        $view_user_id = $user_id;
        $view_username = null;
    }

    /* ------------------------------------------------------------------
       SHIPS: check status + handle "activate ship" POST action
       ------------------------------------------------------------------ */

    $shipsOwnerDoubleAllActive = shipsDoubleAllActive($conn, $user_id, $gameweek);

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        isset($_POST['activate_ship']) &&
        isset($_POST['gameweek'])
    ) {
        $posted_gameweek = (int)$_POST['gameweek'];
        $ship_code = (string)$_POST['activate_ship'];

        if ($posted_gameweek === $gameweek && !$is_viewing_other_user && $ship_code === 'DOUBLE_ALL') {
            [$ok, $reason] = shipsActivateDoubleAll($conn, $user_id, $gameweek);
            $redirect = "my_predictions.php?gameweek=" . $gameweek;
            $redirect .= $ok
                ? "&ships_message=" . urlencode('Double Up activated for this gameweek!')
                : "&ships_error=" . urlencode($reason);
            header("Location: " . $redirect);
            exit();
        }

        header("Location: my_predictions.php?gameweek=" . $gameweek);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['double_match']) && isset($_POST['gameweek'])) {
        $double_match = (int)$_POST['double_match'];
        $posted_gameweek = (int)$_POST['gameweek'];

        if ($posted_gameweek !== $gameweek) {
            header("Location: my_predictions.php?gameweek=" . $gameweek);
            exit();
        }

                if (!$is_locked && !$is_viewing_other_user && $double_match > 0 && !$shipsOwnerDoubleAllActive) {
            $verify_stmt = $conn->prepare("
                SELECT id
                FROM matches
                WHERE id = ? AND gameweek = ?
                LIMIT 1
            ");

            if ($verify_stmt) {
                $verify_stmt->bind_param("ii", $double_match, $gameweek);
                $verify_stmt->execute();
                $verify_result = $verify_stmt->get_result();

                if ($verify_result->num_rows > 0) {
                    $check_stmt = $conn->prepare("
                        SELECT id
                        FROM double_gameweek
                        WHERE user_id = ? AND gameweek = ?
                        LIMIT 1
                    ");

                    if ($check_stmt) {
                        $check_stmt->bind_param("ii", $user_id, $gameweek);
                        $check_stmt->execute();
                        $check_result = $check_stmt->get_result();

                        if ($check_result->num_rows === 0) {
                            $insert_stmt = $conn->prepare("
                                INSERT INTO double_gameweek (user_id, match_id, gameweek)
                                VALUES (?, ?, ?)
                            ");

                            if ($insert_stmt) {
                                $insert_stmt->bind_param("iii", $user_id, $double_match, $gameweek);
                                $insert_stmt->execute();
                                $insert_stmt->close();
                            }
                        }

                        $check_stmt->close();
                    }
                }

                $verify_stmt->close();
            }
        }

        header("Location: my_predictions.php?gameweek=" . $gameweek);
        exit();
    }

    $current_double = null;

    $double_stmt = $conn->prepare("
        SELECT match_id
        FROM double_gameweek
        WHERE user_id = ? AND gameweek = ?
        LIMIT 1
    ");

    if ($double_stmt) {
        $double_stmt->bind_param("ii", $view_user_id, $gameweek);
        $double_stmt->execute();
        $double_result = $double_stmt->get_result();
        $double_row = $double_result->fetch_assoc();

        if ($double_row) {
            $current_double = (int)$double_row['match_id'];
        }

                $double_stmt->close();
    }

    $doubleAllActiveThisGw = shipsDoubleAllActive($conn, $view_user_id, $gameweek);
    $perfectFiveThisGw = shipsGetPerfectFiveForGameweek($conn, $view_user_id, $gameweek);
    $shipsCatalog = shipsCatalog();
    $shipsUsage = shipsGetUserUsage($conn, $view_user_id);
    $shipsMessage = $_GET['ships_message'] ?? null;
    $shipsError = $_GET['ships_error'] ?? null;

    $matches = [];

    $sql = "
        SELECT
            m.id,
            m.home_team,
            m.away_team,
            m.match_date,
            m.home_score,
            m.away_score,
            m.gameweek,
            m.competition,
            p.predicted_home,
            p.predicted_away,
            p.points
        FROM matches m
        INNER JOIN score_exact p
            ON p.match_id = m.id
            AND p.user_id = ?
        WHERE m.gameweek = ?
        ORDER BY m.competition ASC, m.match_date ASC
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        die("Database error: " . htmlspecialchars($conn->error));
    }

    $stmt->bind_param("ii", $view_user_id, $gameweek);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $matches[] = $row;
    }

    $stmt->close();

    $total_points = 0;

    /*
     * score_exact.points already stores the FINAL points (base_points,
     * doubled once by points_helper.php when a Double Pick is active).
     * Do NOT multiply it again here - just sum it.
     */
    $total_sql = "
        SELECT COALESCE(SUM(COALESCE(p.points, 0)), 0) AS total_points
        FROM score_exact p
        INNER JOIN matches m ON m.id = p.match_id
        WHERE p.user_id = ? AND m.gameweek = ?
    ";

    $total_stmt = $conn->prepare($total_sql);

    if ($total_stmt) {
        $total_stmt->bind_param("ii", $view_user_id, $gameweek);
        $total_stmt->execute();
        $total_row = $total_stmt->get_result()->fetch_assoc();
        $total_points = (int)($total_row['total_points'] ?? 0);
        $total_stmt->close();
    }

    $predictors = [];

    if ($is_locked) {
        $predictors_sql = "
            SELECT
                u.id,
                u.username,
                u.favorite_team,
                COUNT(DISTINCT p.match_id) AS predictions_count,
                COALESCE(SUM(COALESCE(p.points,0)),0) AS total_points
            FROM users u
            INNER JOIN score_exact p ON p.user_id = u.id
            INNER JOIN matches m ON m.id = p.match_id
            WHERE m.gameweek = ?
            GROUP BY u.id, u.username, u.favorite_team
            ORDER BY total_points DESC, u.username ASC
        ";

        $predictors_stmt = $conn->prepare($predictors_sql);

        if ($predictors_stmt) {
            $predictors_stmt->bind_param("i", $gameweek);
            $predictors_stmt->execute();
            $predictors_result = $predictors_stmt->get_result();

            while ($predictor = $predictors_result->fetch_assoc()) {
                $predictors[] = $predictor;
            }

            $predictors_stmt->close();
        }
    }

    $gameweekLeaderboard = [];

    $gameweekStats = [
        'participants' => 0,
        'total_predictions' => 0,
        'total_matches' => 0,
        'finished_matches' => 0,
        'remaining_matches' => 0,
        'highest_score' => 0,
        'average_points' => 0,
        'exact_predictions' => 0,
        'your_rank' => null,
        'your_points' => 0
    ];

    $leaderboard_sql = "
        SELECT
            u.id,
            u.username,
            u.favorite_team,
            COUNT(DISTINCT p.match_id) AS predictions_count,
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
        GROUP BY u.id, u.username, u.favorite_team
        ORDER BY total_points DESC, exact_predictions DESC, u.username ASC
    ";

    $leaderboard_stmt = $conn->prepare($leaderboard_sql);

    if ($leaderboard_stmt) {
        $leaderboard_stmt->bind_param("i", $gameweek);
        $leaderboard_stmt->execute();
        $leaderboard_result = $leaderboard_stmt->get_result();

        while ($leaderboard_row = $leaderboard_result->fetch_assoc()) {
            $gameweekLeaderboard[] = $leaderboard_row;
        }

        $leaderboard_stmt->close();
    }

    $gameweekStats['participants'] = count($gameweekLeaderboard);

    $predictions_count_sql = "
        SELECT COUNT(*) AS total_predictions
        FROM score_exact p
        INNER JOIN matches m ON m.id = p.match_id
        WHERE m.gameweek = ?
    ";

    $predictions_count_stmt = $conn->prepare($predictions_count_sql);

    if ($predictions_count_stmt) {
        $predictions_count_stmt->bind_param("i", $gameweek);
        $predictions_count_stmt->execute();
        $predictions_count_row = $predictions_count_stmt->get_result()->fetch_assoc();
        $gameweekStats['total_predictions'] = (int)($predictions_count_row['total_predictions'] ?? 0);
        $predictions_count_stmt->close();
    }

    $matches_stats_sql = "
        SELECT
            COUNT(*) AS total_matches,
            COALESCE(SUM(CASE
                WHEN home_score IS NOT NULL AND away_score IS NOT NULL
                THEN 1
                ELSE 0
            END), 0) AS finished_matches
        FROM matches
        WHERE gameweek = ?
    ";

    $matches_stats_stmt = $conn->prepare($matches_stats_sql);

    if ($matches_stats_stmt) {
        $matches_stats_stmt->bind_param("i", $gameweek);
        $matches_stats_stmt->execute();
        $matches_stats_row = $matches_stats_stmt->get_result()->fetch_assoc();

        $gameweekStats['total_matches'] = (int)($matches_stats_row['total_matches'] ?? 0);
        $gameweekStats['finished_matches'] = (int)($matches_stats_row['finished_matches'] ?? 0);
        $gameweekStats['remaining_matches'] = max(0, $gameweekStats['total_matches'] - $gameweekStats['finished_matches']);

        $matches_stats_stmt->close();
    }

    if (!empty($gameweekLeaderboard)) {
        $totalLeaderboardPoints = 0;

        foreach ($gameweekLeaderboard as $leaderboardUser) {
            $userPoints = (int)($leaderboardUser['total_points'] ?? 0);
            $totalLeaderboardPoints += $userPoints;

            if ($userPoints > $gameweekStats['highest_score']) {
                $gameweekStats['highest_score'] = $userPoints;
            }

            $gameweekStats['exact_predictions'] += (int)($leaderboardUser['exact_predictions'] ?? 0);
        }

        $gameweekStats['average_points'] = round($totalLeaderboardPoints / count($gameweekLeaderboard), 1);
    }

    $leaderboardRank = 1;

    foreach ($gameweekLeaderboard as $leaderboardUser) {
        if ((int)$leaderboardUser['id'] === (int)$view_user_id) {
            $gameweekStats['your_rank'] = $leaderboardRank;
            $gameweekStats['your_points'] = (int)$leaderboardUser['total_points'];
            break;
        }

        $leaderboardRank++;
    }

    ?>
    <!DOCTYPE html>

    <html lang="en">

    <head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>

        <?php if ($is_viewing_other_user): ?>

            <?= e($view_username) ?>'s Predictions

        <?php else: ?>

            My Predictions

        <?php endif; ?>

    </title>
    <link rel="icon" type="image/jpg" href="PL_img/hadi.jpg">
    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body {
            background-image: url('PL_img/current.jpg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            background-color: #1c003a;
            font-family: Arial, Helvetica, sans-serif;
        }
        
        body::before {
            content: "";
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(10, 0, 21, 0.75);
            z-index: -1;
            pointer-events: none;
        }
    </style>

    </head>

    <body class="min-h-screen pb-16 text-white">
    <nav class="fixed top-0 left-0 right-0 z-50 bg-[#1c003a]/80 backdrop-blur-xl border-b border-[#ff0080]/30 px-5 md:px-8 py-4 flex justify-between items-center">

        <a href="dashboard.php" class="flex items-center gap-3">
            <div class="w-11 h-11 rounded-full flex items-center justify-center overflow-hidden">
                <img src="PL_img/PL_LOGO1.png" class="w-full h-full object-contain" alt="Premier League">
            </div>
            <span class="font-black text-lg text-white">Premier League</span>
        </a>

        <div class="hidden md:flex items-center gap-7 text-sm font-bold">
            <a href="dashboard.php" class="hover:text-[#ff9900] transition-colors">Dashboard</a>
            <a href="predictions.php" class="hover:text-[#ff9900] transition-colors">Predictions</a>
            <a href="leaderboard.php" class="hover:text-[#ff9900] transition-colors">Leaderboard</a>
            <a href="my_predictions.php" class="text-[#ff0080]">My Predictions</a>
        </div>

        <button onclick="toggleMenu()" class="md:hidden text-lg px-2 font-bold text-white">Menu</button>

    </nav>

    <div id="mobileMenu" class="hidden fixed top-[73px] left-0 right-0 z-40 bg-[#1c003a]/95 backdrop-blur-xl border-b border-[#ff0080]/30 p-6">
        <div class="flex flex-col gap-5 font-bold">
            <a href="dashboard.php" class="hover:text-[#ff9900] transition-colors">Dashboard</a>
            <a href="predictions.php" class="hover:text-[#ff9900] transition-colors">Predictions</a>
            <a href="leaderboard.php" class="hover:text-[#ff9900] transition-colors">Leaderboard</a>
            <a href="my_predictions.php" class="text-[#ff0080]">My Predictions</a>
        </div>
    </div>

    <script>
    function toggleMenu()
    {
        document.getElementById('mobileMenu').classList.toggle('hidden');
    }
    </script>

    <div class="h-24"></div>

    <main class="max-w-7xl mx-auto px-4">

    <div class="flex flex-col lg:flex-row justify-between items-center gap-6 mb-8">

        <div class="flex items-center gap-4">
            <div class="w-16 h-16 rounded-full p-2 flex items-center justify-center">
                <img src="PL_img/PL_LOGO1.png" class="w-full h-full object-contain" alt="Premier League">
            </div>

            <div>
                <div class="text-[#ff0080] text-sm font-black uppercase tracking-widest">
                    <?php if ($is_viewing_other_user): ?>
                        Viewing
                    <?php else: ?>
                        My
                    <?php endif; ?>
                </div>
                <h1 class="text-3xl md:text-5xl font-black text-white">
                    <?php if ($is_viewing_other_user): ?>
                        <?= e($view_username) ?>'s Predictions
                    <?php else: ?>
                        My Predictions
                    <?php endif; ?>
                </h1>
                <p class="text-gray-300 mt-1">
                    Premier League <span class="text-gray-500">•</span> Gameweek <?= $gameweek ?>
                    <?php if ($is_viewing_other_user): ?>
                        <span class="text-gray-500">•</span> <span class="text-yellow-300 font-bold">READ ONLY</span>
                    <?php endif; ?>
                </p>
            </div>
        </div>

        <div class="flex flex-col sm:flex-row items-center gap-3">
            <div class="bg-gradient-to-r from-[#e90052] to-[#ff9900] text-white px-5 py-3 rounded-xl font-black shadow-lg shadow-pink-500/20">
                <?= $total_points ?> Points
            </div>

            <?php if (!empty($gameweeks)): ?>
                <form method="GET">
                    <select name="gameweek" onchange="this.form.submit()" class="bg-[#1c003a] border border-[#ff0080]/60 text-white rounded-xl px-4 py-3 font-bold outline-none cursor-pointer">
                        <?php foreach ($gameweeks as $gw): ?>
                            <option value="<?= $gw ?>" <?= $gw == $gameweek ? 'selected' : '' ?>>Gameweek <?= $gw ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
        </div>

    </div>

    <?php if ($is_viewing_other_user): ?>
        <div class="mb-7 flex justify-center">
            <a href="my_predictions.php?gameweek=<?= $gameweek ?>" class="inline-flex items-center gap-2 bg-white/10 hover:bg-white/15 border border-white/10 px-5 py-3 rounded-xl font-black transition">Back to My Predictions</a>
        </div>
    <?php endif; ?>

    <?php if ($current_double !== null): ?>
        <?php
        $double_match_name = null;

        foreach ($matches as $dm) {
            if ((int)$dm['id'] === $current_double) {
                $double_match_name = $dm['away_team'] . ' vs ' . $dm['home_team'];
                break;
            }
        }
        ?>
        <div class="mb-7 rounded-2xl bg-gradient-to-r from-yellow-300/20 via-pink-500/10 to-yellow-300/20 border-b border-yellow-300/20 p-5 md:p-6">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <div class="text-yellow-300 font-black text-lg">
                        <?php if ($is_viewing_other_user): ?>
                            <?= e($view_username) ?>'S DOUBLE PICK
                        <?php else: ?>
                            DOUBLE PICK SELECTED
                        <?php endif; ?>
                    </div>
                    <p class="text-gray-300 mt-1"><?= e($double_match_name ?? 'Selected match') ?></p>
                </div>
                                <div class="inline-flex items-center justify-center bg-yellow-400 text-black px-5 py-2.5 rounded-full font-black self-start md:self-auto">2× POINTS</div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($doubleAllActiveThisGw): ?>
        <div class="mb-7 rounded-2xl bg-gradient-to-r from-yellow-300/20 via-pink-500/10 to-yellow-300/20 border border-yellow-300/30 p-5 md:p-6">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <div class="text-yellow-300 font-black text-lg">
                        <?php if ($is_viewing_other_user): ?><?= e($view_username) ?>'S DOUBLE UP<?php else: ?>DOUBLE UP ACTIVE<?php endif; ?>
                    </div>
                    <p class="text-gray-300 mt-1 text-sm">Every match this gameweek is worth double points.</p>
                </div>
                <div class="inline-flex items-center justify-center bg-yellow-400 text-black px-5 py-2.5 rounded-full font-black self-start md:self-auto">2× ALL MATCHES</div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($perfectFiveThisGw !== null): ?>
        <?php
            $pfMatchNames = [];
            foreach ($matches as $pfm) {
                if (in_array((int)$pfm['id'], $perfectFiveThisGw['match_ids'], true)) {
                    $pfMatchNames[] = $pfm['home_team'] . ' vs ' . $pfm['away_team'];
                }
            }
        ?>
        <div class="mb-7 rounded-2xl bg-gradient-to-r from-purple-500/20 via-pink-500/10 to-purple-500/20 border border-purple-400/30 p-5 md:p-6">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <div class="text-purple-300 font-black text-lg">
                        <?php if ($is_viewing_other_user): ?><?= e($view_username) ?>'S PERFECT FIVE<?php else: ?>PERFECT FIVE ACTIVE<?php endif; ?>
                    </div>
                    <p class="text-gray-300 mt-1 text-sm"><?= e(implode(' • ', $pfMatchNames)) ?></p>
                </div>
                <div class="inline-flex items-center justify-center px-5 py-2.5 rounded-full font-black self-start md:self-auto <?= $perfectFiveThisGw['status'] === 'active' ? 'bg-white/10 text-gray-300' : ($perfectFiveThisGw['result'] === 'doubled' ? 'bg-green-400 text-black' : 'bg-red-500 text-white') ?>">
                    <?php if ($perfectFiveThisGw['status'] === 'active'): ?>
                        Pending result
                    <?php elseif ($perfectFiveThisGw['result'] === 'doubled'): ?>
                        Doubled! +<?= (int)$perfectFiveThisGw['points_awarded'] ?> pts
                    <?php else: ?>
                        Busted - 0 pts
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$is_viewing_other_user): ?>
        <div class="max-w-6xl mx-auto mb-10">
            <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-5 md:p-7">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-xl sm:text-2xl font-black text-white">Ships</h2>
                    <span class="text-xs text-gray-400 font-bold">2 uses each per season - 1 per half</span>
                </div>

                <?php if ($shipsMessage): ?>
                    <div class="mb-4 rounded-xl bg-green-500/10 border border-green-500/30 text-green-300 px-4 py-3 text-sm font-bold"><?= e($shipsMessage) ?></div>
                <?php endif; ?>
                <?php if ($shipsError): ?>
                    <div class="mb-4 rounded-xl bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 text-sm font-bold"><?= e($shipsError) ?></div>
                <?php endif; ?>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($shipsCatalog as $code => $info): ?>
                        <?php
                            $half1 = $shipsUsage[$code][1] ?? null;
                            $half2 = $shipsUsage[$code][2] ?? null;
                            [$canUse, $cantReason] = shipsCanActivate($conn, $view_user_id, $code, $gameweek);
                        ?>
                        <div class="bg-black/30 border border-white/10 rounded-xl p-4">
                            <div class="font-black text-white"><?= e($info['name']) ?></div>
                            <p class="text-gray-400 text-xs mt-1"><?= e($info['description']) ?></p>

                            <div class="flex gap-2 mt-3 text-[10px] font-bold">
                                <span class="px-2 py-1 rounded-lg <?= $half1 ? 'bg-white/10 text-gray-400' : 'bg-green-500/10 text-green-300' ?>">
                                    1st Half: <?= $half1 ? ('Used GW' . (int)$half1['gameweek']) : 'Available' ?>
                                </span>
                                <span class="px-2 py-1 rounded-lg <?= $half2 ? 'bg-white/10 text-gray-400' : 'bg-green-500/10 text-green-300' ?>">
                                    2nd Half: <?= $half2 ? ('Used GW' . (int)$half2['gameweek']) : 'Available' ?>
                                </span>
                            </div>

                            <div class="mt-3">
                                <?php if ($code === 'DOUBLE_ALL'): ?>
                                    <form method="POST" onsubmit="return confirm('Activate Double Up for Gameweek <?= $gameweek ?>? Every match will be worth double points and your normal Double Pick will be cleared.');">
                                        <input type="hidden" name="gameweek" value="<?= $gameweek ?>">
                                        <input type="hidden" name="activate_ship" value="DOUBLE_ALL">
                                        <button type="submit" <?= $canUse ? '' : 'disabled title="' . e($cantReason) . '"' ?> class="w-full px-4 py-2 rounded-lg font-black text-xs transition <?= $canUse ? 'bg-gradient-to-br from-yellow-300 to-yellow-400 text-[#160018] hover:-translate-y-0.5 hover:shadow-lg' : 'bg-white/5 text-gray-500 border border-white/10 opacity-60 cursor-not-allowed' ?>">
                                            <?= $canUse ? 'Activate Double Up' : 'Not Available' ?>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <?php if ($canUse): ?>
                                        <a href="ships_five_picks.php?gameweek=<?= $gameweek ?>" class="block text-center w-full px-4 py-2 rounded-lg font-black text-xs bg-gradient-to-br from-purple-400 to-pink-500 text-white transition hover:-translate-y-0.5 hover:shadow-lg">Pick Your Five</a>
                                    <?php else: ?>
                                        <button type="button" disabled title="<?= e($cantReason) ?>" class="w-full px-4 py-2 rounded-lg font-black text-xs bg-white/5 text-gray-500 border border-white/10 opacity-60 cursor-not-allowed">Not Available</button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="max-w-6xl mx-auto mb-10">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-5">
            <div>
                <h2 class="text-2xl sm:text-3xl font-black text-white">Gameweek <?= (int)$gameweek ?> Statistics</h2>
                <p class="text-gray-400 text-sm mt-1">Live statistics for this gameweek</p>
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div class="bg-white/5 border border-white/10 rounded-2xl p-5">
                <div class="text-gray-400 text-xs font-bold uppercase tracking-wider">Participants</div>
                <div class="text-3xl font-black text-white mt-2"><?= (int)$gameweekStats['participants'] ?></div>
            </div>
            <div class="bg-white/5 border border-white/10 rounded-2xl p-5">
                <div class="text-gray-400 text-xs font-bold uppercase tracking-wider">Predictions</div>
                <div class="text-3xl font-black text-pink-400 mt-2"><?= (int)$gameweekStats['total_predictions'] ?></div>
            </div>
            <div class="bg-white/5 border border-white/10 rounded-2xl p-5">
                <div class="text-gray-400 text-xs font-bold uppercase tracking-wider">Highest Score</div>
                <div class="text-3xl font-black text-yellow-300 mt-2"><?= (int)$gameweekStats['highest_score'] ?></div>
            </div>
            <div class="bg-white/5 border border-white/10 rounded-2xl p-5">
                <div class="text-gray-400 text-xs font-bold uppercase tracking-wider">Average Points</div>
                <div class="text-3xl font-black text-blue-400 mt-2"><?= htmlspecialchars((string)$gameweekStats['average_points']) ?></div>
            </div>
            <div class="bg-white/5 border border-white/10 rounded-2xl p-5">
                <div class="text-gray-400 text-xs font-bold uppercase tracking-wider">Finished Matches</div>
                <div class="text-3xl font-black text-green-400 mt-2"><?= (int)$gameweekStats['finished_matches'] ?><span class="text-base text-gray-500">/<?= (int)$gameweekStats['total_matches'] ?></span></div>
            </div>
            <div class="bg-white/5 border border-white/10 rounded-2xl p-5">
                <div class="text-gray-400 text-xs font-bold uppercase tracking-wider">Remaining</div>
                <div class="text-3xl font-black text-orange-400 mt-2"><?= (int)$gameweekStats['remaining_matches'] ?></div>
            </div>
            <div class="bg-white/5 border border-white/10 rounded-2xl p-5">
                <div class="text-gray-400 text-xs font-bold uppercase tracking-wider">Exact Predictions</div>
                <div class="text-3xl font-black text-purple-400 mt-2"><?= (int)$gameweekStats['exact_predictions'] ?></div>
            </div>
            <div class="bg-gradient-to-br from-pink-500/20 to-purple-500/10 border border-pink-500/30 rounded-2xl p-5">
                <div class="text-pink-300 text-xs font-bold uppercase tracking-wider">Your Rank</div>
                <div class="text-3xl font-black text-white mt-2">
                    <?php if ($gameweekStats['your_rank'] !== null): ?>#<?= (int)$gameweekStats['your_rank'] ?><?php else: ?>—<?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($matches)): ?>
        <div class="bg-gradient-to-b from-white/10 to-white/5 border border-white/10 backdrop-blur-xl shadow-xl rounded-2xl p-12 text-center">
            <p class="text-gray-400 text-lg">No matches for this gameweek.</p>
        </div>
    <?php else: ?>

        <!-- Matches Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">

            <?php foreach ($matches as $match): ?>

                <?php

    $predictionStatus = null;
    $predictionMessage = null;

    if (
        $match['predicted_home'] !== null &&
        $match['predicted_away'] !== null &&
        $match['home_score'] !== null &&
        $match['away_score'] !== null
    ) {
        $predictedHome = (int) $match['predicted_home'];
        $predictedAway = (int) $match['predicted_away'];

        $actualHome = (int) $match['home_score'];
        $actualAway = (int) $match['away_score'];

        if (
            $predictedHome === $actualHome &&
            $predictedAway === $actualAway
        ) {
            $predictionStatus = 'exact';
            $predictionMessage = 'Perfect Prediction!';
        }

        elseif (
            ($predictedHome > $predictedAway && $actualHome > $actualAway) ||
            ($predictedHome < $predictedAway && $actualHome < $actualAway) ||
            ($predictedHome === $predictedAway && $actualHome === $actualAway)
        ) {
            $predictionStatus = 'correct';
            $predictionMessage = 'Correct Result!';
        }

        else {
            $predictionStatus = 'wrong';
            $predictionMessage = 'Wrong Prediction';
        }
    }
    ?>

                <?php
                $match_id = (int)$match['id'];

                $is_double = $current_double === $match_id;

                $is_perfect_five_pick = $perfectFiveThisGw !== null
                    && in_array($match_id, $perfectFiveThisGw['match_ids'], true);

                $home_logo = teamLogo($match['home_team'], $conn);
                $away_logo = teamLogo($match['away_team'], $conn);

                $predicted_home = $match['predicted_home'];
                $predicted_away = $match['predicted_away'];

                $has_prediction = $predicted_home !== null && $predicted_away !== null;

                // score_exact.points is already the FINAL points for this
                // match (points_helper.php doubles it once when this match
                // is the user's Double Pick). Never multiply it again here.
                $display_points = $match['points'];

                ?>

                               <div class="bg-white/5 backdrop-blur-xl rounded-xl overflow-hidden border border-white/10 shadow-[0_4px_15px_rgba(0,0,0,0.3)] <?= $is_double ? 'border-2 border-yellow-300/60 shadow-[0_0_0_1px_rgba(255,216,107,0.08),0_0_15px_rgba(255,216,107,0.14)]' : ($is_perfect_five_pick ? 'border-2 border-purple-400/60 shadow-[0_0_0_1px_rgba(192,132,252,0.08),0_0_15px_rgba(192,132,252,0.14)]' : '') ?>">

                    <?php if ($is_double): ?>
                        <div class="bg-yellow-400 text-black px-4 py-1 flex flex-col sm:flex-row justify-between items-center gap-1 font-black text-xs">
                            <span>DOUBLE PICK</span>
                            <span class="text-[10px] opacity-80">2× points</span>
                        </div>
                    <?php elseif ($is_perfect_five_pick): ?>
                        <div class="bg-purple-400 text-black px-4 py-1 flex flex-col sm:flex-row justify-between items-center gap-1 font-black text-xs">
                            <span>PERFECT FIVE</span>
                            <span class="text-[10px] opacity-80">
                                <?php if ($perfectFiveThisGw['status'] === 'active'): ?>
                                    pending
                                <?php elseif ($perfectFiveThisGw['result'] === 'doubled'): ?>
                                    2× points
                                <?php else: ?>
                                    0 points
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endif; ?>

                    <div class="bg-black/30 border-b border-white/10 text-center py-2 px-4">
                        <span class="inline-flex items-center px-3 py-0.5 rounded-full bg-pink-500/10 border border-pink-500/30 text-pink-300 text-[10px] font-black uppercase tracking-wider"><?= e($match['competition'] ?? 'Unknown Competition') ?></span>
                        <div class="text-gray-400 text-xs mt-1"><?= date('D, d M Y • H:i', strtotime($match['match_date'])) ?></div>
                    </div>

                    <div class="p-4">
                        <div class="flex flex-row items-center justify-center gap-6">
                            <div class="flex flex-col items-center gap-1 text-center w-1/3">
                                <span class="text-[9px] font-black uppercase tracking-wider text-blue-400">HOME</span>
                                <?php if ($home_logo): ?>
                                    <img src="<?= e($home_logo) ?>" alt="<?= e($match['home_team']) ?>" class="w-14 h-14 object-contain drop-shadow" onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                                    <div class="w-14 h-14 hidden items-center justify-center bg-white/10 rounded-full text-gray-400 font-black text-lg"><?= e(mb_strtoupper(mb_substr((string)$match['home_team'], 0, 1))) ?></div>
                                <?php else: ?>
                                    <div class="w-14 h-14 flex items-center justify-center bg-white/10 rounded-full text-gray-400 font-black text-lg">H</div>
                                <?php endif; ?>
                                <span class="font-extrabold text-white text-sm capitalize"><?= e($match['home_team']) ?></span>
                            </div>
                            <div class="flex flex-col items-center justify-center min-w-[90px]">
                                <span class="text-[9px] font-bold uppercase opacity-70 mb-1"><?php if ($is_locked): ?>Final<?php else: ?>Prediction<?php endif; ?></span>
                                <?php if ($is_locked): ?>
                                    <?php if ($match['home_score'] !== null && $match['away_score'] !== null): ?>
                                        <span class="text-2xl font-black text-white"><?= (int)$match['home_score'] ?> - <?= (int)$match['away_score'] ?></span>
                                    <?php else: ?><span class="text-2xl font-black text-white">-</span><?php endif; ?>
                                <?php else: ?>
                                    <?php if ($has_prediction): ?><span class="text-2xl font-black text-white"><?= (int)$predicted_home ?> - <?= (int)$predicted_away ?></span>
                                    <?php else: ?><span class="text-2xl font-black text-white">-</span><?php endif; ?>
                                <?php endif; ?>
                                <?php if ($predictionStatus === 'exact'): ?>
                                    <div class="mt-1 flex items-center justify-center gap-1 rounded-lg bg-green-500/15 border border-green-500/30 px-2 py-0.5">
                                        <span class="text-xs font-bold text-green-400">Perfect</span>
                                    </div>
                                <?php elseif ($predictionStatus === 'correct'): ?>
                                    <div class="mt-1 flex items-center justify-center gap-1 rounded-lg bg-blue-500/15 border border-green-500/30 px-2 py-0.5">
                                        <span class="text-xs font-bold text-green-400">Correct</span>
                                    </div>
                                <?php elseif ($predictionStatus === 'wrong'): ?>
                                    <div class="mt-1 flex items-center justify-center gap-1 rounded-lg bg-red-500/15 border border-red-500/30 px-2 py-0.5">
                                        <span class="text-xs font-bold text-red-400">Wrong</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="flex flex-col items-center gap-1 text-center w-1/3">
                                <span class="text-[9px] font-black uppercase tracking-wider text-pink-400">AWAY</span>
                                <?php if ($away_logo): ?>
                                    <img src="<?= e($away_logo) ?>" alt="<?= e($match['away_team']) ?>" class="w-14 h-14 object-contain drop-shadow" onerror="this.onerror=null;this.style.display='none';this.nextElementSibling.style.display='flex';">
                                    <div class="w-14 h-14 hidden items-center justify-center bg-white/10 rounded-full text-gray-400 font-black text-lg"><?= e(mb_strtoupper(mb_substr((string)$match['away_team'], 0, 1))) ?></div>
                                <?php else: ?>
                                    <div class="w-14 h-14 flex items-center justify-center bg-white/10 rounded-full text-gray-400 font-black text-lg">A</div>
                                <?php endif; ?>
                                <span class="font-extrabold text-white text-sm capitalize"><?= e($match['away_team']) ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="bg-black/40 border-t border-white/10 p-2">

                        <!-- Details toggle button -->
                        <div class="mt-1 text-center">
                            <button onclick="toggleMatchDetails(this)" data-target="match-details-<?= $match_id ?>" class="bg-white/10 hover:bg-white/20 border border-white/10 text-white px-3 py-1 rounded-lg font-bold text-xs transition">
                                Details
                            </button>
                        </div>

                        <!-- Hidden details section -->
                        <div id="match-details-<?= $match_id ?>" class="hidden mt-2 rounded-xl bg-black/40 border border-white/10 p-3 text-sm">
                            <div class="grid grid-cols-2 gap-2 text-center">
                                <div class="bg-white/5 rounded-lg p-2">
                                    <div class="text-[10px] text-gray-400 uppercase font-bold">Prediction</div>
                                    <div class="text-base font-black mt-1">
                                        <?php if ($has_prediction): ?>
                                            <?= (int)$predicted_home ?> - <?= (int)$predicted_away ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="bg-white/5 rounded-lg p-2">
                                    <div class="text-[10px] text-gray-400 uppercase font-bold">Actual</div>
                                    <div class="text-base font-black mt-1">
                                        <?php if ($match['home_score'] !== null && $match['away_score'] !== null): ?>
                                            <?= (int)$match['home_score'] ?> - <?= (int)$match['away_score'] ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="bg-white/5 rounded-lg p-2 col-span-2">
                                    <div class="text-[10px] text-gray-400 uppercase font-bold">Status</div>
                                    <div class="mt-1">
                                        <?php if ($match['home_score'] !== null && $match['away_score'] !== null): ?>
                                            <?php if ($predictionStatus === 'exact'): ?>
                                                <span class="inline-block px-2 py-0.5 rounded-full bg-green-500/20 text-green-400 font-bold">Perfect</span>
                                            <?php elseif ($predictionStatus === 'correct'): ?>
                                                <span class="inline-block px-2 py-0.5 rounded-full bg-yellow-500/20 text-yellow-300 font-bold">Correct</span>
                                            <?php elseif ($predictionStatus === 'wrong'): ?>
                                                <span class="inline-block px-2 py-0.5 rounded-full bg-red-500/20 text-red-400 font-bold">Wrong</span>
                                            <?php else: ?>
                                                <span class="text-gray-400">-</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-gray-400">Not yet determined</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="bg-white/5 rounded-lg p-2">
                                    <div class="text-[10px] text-gray-400 uppercase font-bold">Double</div>
                                    <div class="mt-1">
                                        <?php if ($is_double): ?>
                                            <span class="text-yellow-300 font-bold">Yes (2×)</span>
                                        <?php else: ?>
                                            <span class="text-gray-400">No</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="bg-white/5 rounded-lg p-2">
                                    <div class="text-[10px] text-gray-400 uppercase font-bold">Points</div>
                                    <div class="mt-1">
                                        <?php if ($display_points !== null): ?>
                                            <span class="font-black text-base"><?= (int)$display_points ?></span>
                                        <?php else: ?>
                                            <span class="text-gray-400">-</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if (!$is_viewing_other_user): ?>
                            <div class="mt-2 flex justify-center">
                                                                <?php if ($doubleAllActiveThisGw): ?>
                                    <div class="text-[10px] text-yellow-300 text-center font-bold">Double Up ship active — this whole gameweek is already doubled</div>
                                <?php elseif ($is_double): ?>
                                    <div class="inline-flex items-center gap-1 bg-yellow-400 text-black px-3 py-1 rounded-lg font-black text-xs">This is your Double Pick • 2× Points</div>
                                <?php elseif ($is_locked): ?>
                                    <button type="button" disabled title="Deadline passed" class="inline-flex items-center gap-1 bg-white/5 text-gray-500 px-3 py-1 rounded-lg font-black text-xs border border-white/10 opacity-60 cursor-not-allowed">
                                        Double Pick (Deadline Passed)
                                    </button>
                                <?php elseif ($current_double === null): ?>
                                    <form method="POST" onsubmit="return confirm('Choose this match as your Double Pick? You can only select one Double Pick for this gameweek.');">
                                        <input type="hidden" name="gameweek" value="<?= $gameweek ?>">
                                        <input type="hidden" name="double_match" value="<?= $match_id ?>">
                                        <button type="submit" class="bg-gradient-to-br from-yellow-300 to-yellow-400 text-[#160018] px-3 py-1 rounded-lg font-black text-xs transition hover:-translate-y-0.5 hover:shadow-lg">Double Pick</button>
                                    </form>
                                <?php else: ?>
                                    <div class="text-[10px] text-gray-400 text-center">Double Pick already selected</div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

    <!-- Gameweek Leaderboard (moved to bottom) -->
    <div class="max-w-6xl mx-auto mb-12">
        <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl overflow-hidden">
            <div class="px-5 sm:px-7 py-5 border-b border-white/10 flex items-center justify-between">
                <div>
                    <h2 class="text-xl sm:text-2xl font-black text-white">Gameweek <?= (int)$gameweek ?> Leaderboard</h2>
                    <p class="text-gray-400 text-sm mt-1">Rankings based on points earned in this gameweek</p>
                </div>
                <div class="bg-yellow-400/10 border border-yellow-400/20 px-4 py-2 rounded-xl">
                    <span class="text-yellow-300 font-black text-sm"><?= (int)$gameweekStats['participants'] ?> Players</span>
                </div>
            </div>
            <?php if (empty($gameweekLeaderboard)): ?>
                <div class="py-12 text-center"><p class="text-gray-400">No predictions have been submitted for this gameweek yet.</p></div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[650px]">
                        <thead>
                            <tr class="bg-black/20 text-gray-400 text-xs uppercase tracking-wider">
                                <th class="text-left px-5 py-4">Rank</th>
                                <th class="text-left px-5 py-4">Player</th>
                                <th class="text-center px-5 py-4">Predictions</th>
                                <th class="text-center px-5 py-4">Exact</th>
                                <th class="text-right px-5 py-4">Points</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($gameweekLeaderboard as $index => $leaderboardUser): ?>
                                <?php $rank = $index + 1; $isCurrentUser = (int)$leaderboardUser['id'] === (int)$view_user_id; ?>
                                <tr class="border-t border-white/5 transition <?= $isCurrentUser ? 'bg-pink-500/10' : 'hover:bg-white/5' ?>">
                                    <td class="px-5 py-4">
                                        <?php if ($rank === 1): ?><span class="text-xl">1</span>
                                        <?php elseif ($rank === 2): ?><span class="text-xl">2</span>
                                        <?php elseif ($rank === 3): ?><span class="text-xl">3</span>
                                        <?php else: ?><span class="text-gray-400 font-bold">#<?= $rank ?></span><?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-pink-500/30 to-purple-500/30 flex items-center justify-center font-black text-white">
                                                <?= htmlspecialchars(strtoupper(substr((string)$leaderboardUser['username'], 0, 1))) ?>
                                            </div>
                                            <div>
                                                <div class="font-bold text-white flex items-center gap-2">
                                                    <?= htmlspecialchars($leaderboardUser['username']) ?>
                                                    <?php if ($isCurrentUser): ?><span class="text-[10px] px-2 py-0.5 rounded-full bg-pink-500 text-black font-black">YOU</span><?php endif; ?>
                                                </div>
                                                <?php if (!empty($leaderboardUser['favorite_team'])): ?><div class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars($leaderboardUser['favorite_team']) ?></div><?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-center"><span class="text-gray-300 font-bold"><?= (int)$leaderboardUser['predictions_count'] ?></span></td>
                                    <td class="px-5 py-4 text-center">
                                        <span class="inline-flex items-center justify-center min-w-[32px] h-8 px-2 rounded-lg bg-purple-500/10 border border-purple-500/20 text-purple-300 font-black text-sm"><?= (int)$leaderboardUser['exact_predictions'] ?></span>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <span class="text-xl font-black text-yellow-300"><?= (int)$leaderboardUser['total_points'] ?></span>
                                        <span class="text-xs text-gray-500">pts</span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="text-center text-gray-500 text-sm mt-10">
        <?php if ($is_locked): ?>
            Gameweek locked.<br>
            <?php if ($is_viewing_other_user): ?>
                <?= e($view_username) ?>'s predictions are read-only.<br>Click a player in the table above to view their predictions.
            <?php else: ?>
                Your predictions are read-only.<br>Click a player in the table above to view their predictions.
            <?php endif; ?>
        <?php else: ?>
            Predictions are private until the deadline.<br>You can select one Double Pick before the deadline.
        <?php endif; ?>
    </div>

    </main>

    <script>
    function toggleMatchDetails(btn) {
        var targetId = btn.getAttribute('data-target');
        var details = document.getElementById(targetId);
        if (details) {
            details.classList.toggle('hidden');
            if (details.classList.contains('hidden')) {
                btn.textContent = 'Details';
            } else {
                btn.textContent = 'Hide Details';
            }
        }
    }
    </script>

    </body>

    </html>