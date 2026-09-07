<?php

    session_start();
    include 'connect.php';
    require_once 'points_helper.php';
    require_once 'ships_helper.php';

    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }

    $user_id = (int) $_SESSION['user_id'];

    function e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Same logo-resolution logic as my_predictions.php, duplicated here
     * so this page can render team badges too.
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

    function resolveLocalLogoPath($logoPath)
    {
        $logoPath = trim((string) $logoPath);

        if ($logoPath === '') {
            return null;
        }

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

        $inputKey = normalizeTeamKey($normalizedInput);

        foreach ($teamsCache as $team) {
            if ($team['key'] === $inputKey) {
                $resolved = resolveLocalLogoPath($team['logo']);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

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

    $gameweek = isset($_GET['gameweek']) ? (int)$_GET['gameweek'] : 0;

    if ($gameweek <= 0) {
        header("Location: my_predictions.php");
        exit();
    }

    $errorMessage = null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gameweek'])) {
        $posted_gameweek = (int)$_POST['gameweek'];
        $selected = isset($_POST['match_ids']) && is_array($_POST['match_ids']) ? $_POST['match_ids'] : [];

        if ($posted_gameweek === $gameweek) {
            [$ok, $reason] = shipsActivatePerfectFive($conn, $user_id, $gameweek, $selected);

            if ($ok) {
                header("Location: my_predictions.php?gameweek=" . $gameweek . "&ships_message=" . urlencode('Perfect Five activated for this gameweek!'));
                exit();
            }

            $errorMessage = $reason;
        }
    }

    $existingPick = shipsGetPerfectFiveForGameweek($conn, $user_id, $gameweek);

    [$canUse, $cantReason] = shipsCanActivate($conn, $user_id, 'PERFECT_FIVE', $gameweek);

    $matches = [];

    $sql = "
        SELECT
            m.id,
            m.home_team,
            m.away_team,
            m.match_date,
            m.competition,
            p.predicted_home,
            p.predicted_away
        FROM matches m
        INNER JOIN score_exact p
            ON p.match_id = m.id
            AND p.user_id = ?
        WHERE m.gameweek = ?
        ORDER BY m.competition ASC, m.match_date ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $user_id, $gameweek);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $matches[] = $row;
    }

    $stmt->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Perfect Five - Gameweek <?= (int)$gameweek ?></title>
<link rel="icon" type="image/jpg" href="PL_img/hadi.jpg">
<script src="https://cdn.tailwindcss.com"></script>
<style>
    body {
        background-color: #1c003a;
        font-family: Arial, Helvetica, sans-serif;
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
    <a href="my_predictions.php?gameweek=<?= (int)$gameweek ?>" class="text-sm font-bold hover:text-[#ff9900] transition-colors">Back to My Predictions</a>
</nav>

<div class="h-24"></div>

<main class="max-w-4xl mx-auto px-4">

    <div class="mb-8 text-center">
        <div class="text-purple-300 text-sm font-black uppercase tracking-widest">Ship</div>
        <h1 class="text-3xl md:text-5xl font-black text-white">Perfect Five</h1>
        <p class="text-gray-300 mt-2">Gameweek <?= (int)$gameweek ?> — pick 5 matches. All correct doubles them, one wrong zeroes them all.</p>
    </div>

    <?php if ($errorMessage): ?>
        <div class="mb-6 rounded-xl bg-red-500/10 border border-red-500/30 text-red-300 px-4 py-3 text-sm font-bold text-center"><?= e($errorMessage) ?></div>
    <?php endif; ?>

    <?php if ($existingPick !== null): ?>

        <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6">
            <div class="text-center mb-4">
                <span class="inline-flex items-center justify-center px-5 py-2.5 rounded-full font-black <?= $existingPick['status'] === 'active' ? 'bg-white/10 text-gray-300' : ($existingPick['result'] === 'doubled' ? 'bg-green-400 text-black' : 'bg-red-500 text-white') ?>">
                    <?php if ($existingPick['status'] === 'active'): ?>
                        Locked in — pending result
                    <?php elseif ($existingPick['result'] === 'doubled'): ?>
                        Doubled! +<?= (int)$existingPick['points_awarded'] ?> pts
                    <?php else: ?>
                        Busted — 0 pts
                    <?php endif; ?>
                </span>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <?php foreach ($matches as $m): ?>
                    <?php if (in_array((int)$m['id'], $existingPick['match_ids'], true)): ?>
                        <?php
                            $home_logo = teamLogo($m['home_team'], $conn);
                            $away_logo = teamLogo($m['away_team'], $conn);
                        ?>
                        <div class="bg-black/30 border border-purple-400/30 rounded-xl p-3 text-center">
                            <div class="text-[10px] text-gray-400 uppercase font-bold"><?= e($m['competition']) ?></div>
                            <div class="flex items-center justify-center gap-3 mt-2">
                                <?php if ($home_logo): ?>
                                    <img src="<?= e($home_logo) ?>" alt="<?= e($m['home_team']) ?>" class="w-9 h-9 object-contain" onerror="this.style.display='none';">
                                <?php endif; ?>
                                <span class="font-bold text-white text-sm"><?= e($m['home_team']) ?> vs <?= e($m['away_team']) ?></span>
                                <?php if ($away_logo): ?>
                                    <img src="<?= e($away_logo) ?>" alt="<?= e($m['away_team']) ?>" class="w-9 h-9 object-contain" onerror="this.style.display='none';">
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-gray-400 mt-2">Your pick: <?= (int)$m['predicted_home'] ?> - <?= (int)$m['predicted_away'] ?></div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>

    <?php elseif (!$canUse): ?>

        <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-8 text-center">
            <p class="text-gray-300"><?= e($cantReason) ?></p>
            <a href="my_predictions.php?gameweek=<?= (int)$gameweek ?>" class="inline-block mt-5 bg-white/10 hover:bg-white/15 border border-white/10 px-5 py-3 rounded-xl font-black transition">Back to My Predictions</a>
        </div>

    <?php elseif (count($matches) < 5): ?>

        <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-8 text-center">
            <p class="text-gray-300">You need at least 5 predicted matches this gameweek to use Perfect Five.</p>
            <a href="my_predictions.php?gameweek=<?= (int)$gameweek ?>" class="inline-block mt-5 bg-white/10 hover:bg-white/15 border border-white/10 px-5 py-3 rounded-xl font-black transition">Back to My Predictions</a>
        </div>

    <?php else: ?>

        <form method="POST" id="fiveForm">
            <input type="hidden" name="gameweek" value="<?= (int)$gameweek ?>">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-6">
                <?php foreach ($matches as $m): ?>
                    <?php
                        $home_logo = teamLogo($m['home_team'], $conn);
                        $away_logo = teamLogo($m['away_team'], $conn);
                    ?>
                    <label class="cursor-pointer">
                        <input type="checkbox" name="match_ids[]" value="<?= (int)$m['id'] ?>" class="five-checkbox hidden peer">
                        <div class="bg-black/30 border border-white/10 rounded-xl p-3 text-center peer-checked:border-purple-400 peer-checked:bg-purple-500/10 transition">
                            <div class="text-[10px] text-gray-400 uppercase font-bold"><?= e($m['competition']) ?></div>
                            <div class="flex items-center justify-center gap-3 mt-2">
                                <?php if ($home_logo): ?>
                                    <img src="<?= e($home_logo) ?>" alt="<?= e($m['home_team']) ?>" class="w-9 h-9 object-contain" onerror="this.style.display='none';">
                                <?php endif; ?>
                                <span class="font-bold text-white text-sm"><?= e($m['home_team']) ?> vs <?= e($m['away_team']) ?></span>
                                <?php if ($away_logo): ?>
                                    <img src="<?= e($away_logo) ?>" alt="<?= e($m['away_team']) ?>" class="w-9 h-9 object-contain" onerror="this.style.display='none';">
                                <?php endif; ?>
                            </div>
                            <div class="text-xs text-gray-400 mt-2">Your pick: <?= (int)$m['predicted_home'] ?> - <?= (int)$m['predicted_away'] ?></div>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="text-center">
                <div id="pickCount" class="text-sm text-gray-400 font-bold mb-3">0 / 5 selected</div>
                <button type="submit" id="submitBtn" disabled class="bg-white/5 text-gray-500 border border-white/10 opacity-60 cursor-not-allowed px-8 py-3 rounded-xl font-black transition">
                    Lock In Perfect Five
                </button>
            </div>
        </form>

        <script>
            const boxes = document.querySelectorAll('.five-checkbox');
            const countEl = document.getElementById('pickCount');
            const submitBtn = document.getElementById('submitBtn');

            function refresh() {
                const checked = document.querySelectorAll('.five-checkbox:checked').length;
                countEl.textContent = checked + ' / 5 selected';

                boxes.forEach(function (box) {
                    box.disabled = !box.checked && checked >= 5;
                });

                if (checked === 5) {
                    submitBtn.disabled = false;
                    submitBtn.classList.remove('bg-white/5', 'text-gray-500', 'border', 'border-white/10', 'opacity-60', 'cursor-not-allowed');
                    submitBtn.classList.add('bg-gradient-to-br', 'from-purple-400', 'to-pink-500', 'text-white', 'hover:-translate-y-0.5', 'hover:shadow-lg');
                } else {
                    submitBtn.disabled = true;
                    submitBtn.classList.add('bg-white/5', 'text-gray-500', 'border', 'border-white/10', 'opacity-60', 'cursor-not-allowed');
                    submitBtn.classList.remove('bg-gradient-to-br', 'from-purple-400', 'to-pink-500', 'text-white', 'hover:-translate-y-0.5', 'hover:shadow-lg');
                }
            }

            boxes.forEach(function (box) {
                box.addEventListener('change', refresh);
            });

            document.getElementById('fiveForm').addEventListener('submit', function (e) {
                const checked = document.querySelectorAll('.five-checkbox:checked').length;
                if (checked !== 5) {
                    e.preventDefault();
                    alert('Pick exactly 5 matches.');
                } else if (!confirm('Lock in these 5 matches for Perfect Five? This cannot be undone.')) {
                    e.preventDefault();
                }
            });
        </script>

    <?php endif; ?>

</main>
</body>
</html>