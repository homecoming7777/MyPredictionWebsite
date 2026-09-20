<?php

session_start();

include 'connect.php';
require_once 'team_stats_helper.php';

date_default_timezone_set('Africa/Casablanca');

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

if (!function_exists('e')) {
    function e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function teamStatsLogoSrc($logo)
{
    $logo = trim((string)($logo ?? ''));

    if ($logo === '') {
        return 'PL_img/default-team.png';
    }

    if (preg_match('#^https?://#i', $logo)) {
        return $logo;
    }

    return ltrim($logo, '/');
}

function teamStatsFormatDate($rawDate)
{
    $ts = strtotime((string)$rawDate);

    return $ts ? date('D, d M Y • H:i', $ts) : 'Date unknown';
}

function teamStatsResultBadgeClass($letter)
{
    if ($letter === 'W') {
        return 'bg-green-500/20 text-green-400 border-green-500/40';
    }

    if ($letter === 'L') {
        return 'bg-red-500/20 text-red-400 border-red-500/40';
    }

    return 'bg-gray-400/20 text-gray-300 border-gray-400/40';
}

$teamParam = trim((string)($_GET['team'] ?? ''));
$compareParam = trim((string)($_GET['compare'] ?? ''));

$profile = null;
$notFound = false;

if ($teamParam !== '') {
    $profile = teamStatsResolveTeam($conn, $teamParam);

    if ($profile === null) {
        $notFound = true;
    }
}

$selectedCompetition = null;
$availableCompetitions = [];
$stats = null;
$form = null;
$formLast5 = [];
$recentMatches = [];
$upcomingMatches = [];
$position = null;

$compareProfile = null;
$compareNotFound = false;
$compareStats = null;
$compareForm = null;
$headToHead = null;

if ($profile !== null) {
    $availableCompetitions = array_keys($profile['competitions']);

    if (isset($_GET['competition'])) {
        $requested = trim((string)$_GET['competition']);

        if ($requested === '' || strtolower($requested) === 'all') {
            $selectedCompetition = null;
        } else {
            foreach ($availableCompetitions as $comp) {
                if (strcasecmp($comp, $requested) === 0) {
                    $selectedCompetition = $comp;
                    break;
                }
            }

            if ($selectedCompetition === null) {
                $selectedCompetition = $profile['primary_competition'];
            }
        }
    } else {
        $selectedCompetition = $profile['primary_competition'];
    }

    $stats = getTeamStatistics($conn, $profile['key'], $selectedCompetition);
    $form = getTeamForm($conn, $profile['key'], $selectedCompetition, 10);
    $formLast5 = array_slice($form['sequence'], -5);
    $recentMatches = getRecentMatches($conn, $profile['key'], $selectedCompetition, 5);
    $upcomingMatches = getUpcomingMatches($conn, $profile['key'], $selectedCompetition, 5);

    $positionCompetition = $selectedCompetition ?? $profile['primary_competition'];
    $position = getTeamPosition($conn, $profile['key'], $positionCompetition);

    if ($compareParam !== '') {
        $compareProfile = teamStatsResolveTeam($conn, $compareParam);

        if ($compareProfile === null) {
            $compareNotFound = true;
        } elseif ($compareProfile['key'] === $profile['key']) {
            $compareProfile = null;
            $compareNotFound = true;
        } else {
            $compareStats = getTeamStatistics($conn, $compareProfile['key'], $selectedCompetition);
            $compareForm = getTeamForm($conn, $compareProfile['key'], $selectedCompetition, 10);
            $headToHead = getHeadToHead($conn, $profile['key'], $compareProfile['key'], null, 8);
        }
    }
}

$allTeams = ($profile === null) ? teamStatsAllTeams($conn) : [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Team Statistics<?= $profile ? ' | ' . e($profile['name']) : '' ?></title>
<link rel="icon" type="image/jpg" href="PL_img/hadi.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.3.1/css/all.min.css" integrity="sha512-QeR2VH+lsBE5LSAe1Q5EnTBbe7XTBubt8dG93Y7gidSgdMCr8nVqKcfKAMyN96SV8KDbZVTDXChatu5G2KQGzg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<script src="https://cdn.tailwindcss.com"></script>
<style>
body {
    background-image: url('PL_img/current.jpg');
    background-size: cover;
    background-position: center;
    background-attachment: fixed;
    background-color: #1c003a;
    color: #f7f2fa;
    font-family: Arial, Helvetica, sans-serif;
}
body::before {
    content: "";
    position: fixed;
    top: 0; left: 0;
    width: 100%; height: 100%;
    background: rgba(28, 0, 58, 0.72);
    z-index: -1;
    pointer-events: none;
}
.stat-card {
    background: rgba(28, 0, 58, 0.85);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 0, 128, 0.25);
    border-radius: 1.25rem;
}
.form-pill {
    width: 34px;
    height: 34px;
    border-radius: 9999px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 900;
    font-size: 0.85rem;
    border: 1px solid;
}
</style>
</head>
<body class="min-h-screen pb-16">

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
        <a href="my_predictions.php" class="hover:text-[#ff9900] transition-colors">My Predictions</a>
        <a href="team_stats.php" class="text-[#ff0080]">Team Stats</a>
    </div>

    <button onclick="toggleMenu()" class="md:hidden text-2xl px-2 text-white">Menu</button>
</nav>

<div id="mobileMenu" class="hidden fixed top-[73px] left-0 right-0 z-40 bg-[#1c003a]/95 backdrop-blur-xl border-b border-[#ff0080]/30 p-6">
    <div class="flex flex-col gap-5 font-bold">
        <a href="dashboard.php">Dashboard</a>
        <a href="predictions.php">Predictions</a>
        <a href="leaderboard.php">Leaderboard</a>
        <a href="my_predictions.php">My Predictions</a>
        <a href="team_stats.php" class="text-[#ff0080]">Team Stats</a>
    </div>
</div>

<script>
function toggleMenu() {
    document.getElementById('mobileMenu').classList.toggle('hidden');
}
</script>

<div class="h-24"></div>

<main class="max-w-6xl mx-auto px-4">

<?php if ($profile === null): ?>

    <div class="text-center mb-8">
        <div class="text-[#ff0080] text-sm font-black uppercase tracking-widest">Scouting Report</div>
        <h1 class="text-3xl md:text-5xl font-black text-white mt-1">Team Statistics</h1>
        <p class="text-gray-300 mt-2">Pick a team to see full statistics, form, and head-to-head records.</p>
    </div>

    <?php if ($notFound): ?>
        <div class="stat-card p-6 mb-8 max-w-xl mx-auto text-center">
            <p class="text-[#ff9900] font-bold">No data available for "<?= e($teamParam) ?>".</p>
            <p class="text-gray-400 text-sm mt-2">Check the spelling, or pick a team below.</p>
        </div>
    <?php endif; ?>

    <div class="stat-card p-6 mb-10 max-w-xl mx-auto">
        <input
            type="text"
            id="teamSearch"
            onkeyup="teamStatsFilterTeams()"
            placeholder="Search for a team..."
            class="w-full bg-black/30 border border-[#ff0080]/30 rounded-xl px-4 py-3 text-white outline-none focus:border-[#ff9900]"
        >
    </div>

    <?php if (empty($allTeams)): ?>
        <p class="text-center text-gray-300">No teams available yet.</p>
    <?php else: ?>
        <div id="teamGrid" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4 mb-12">
            <?php foreach ($allTeams as $t): ?>
                <a href="team_stats.php?team=<?= urlencode($t['name']) ?>"
                    class="team-card stat-card p-4 flex flex-col items-center gap-2 hover:border-[#ff9900] transition"
                    data-name="<?= e(mb_strtolower($t['name'])) ?>"
                >
                    <img
                        src="<?= e(teamStatsLogoSrc($t['logo'])) ?>"
                        alt="<?= e($t['name']) ?>"
                        class="w-14 h-14 object-contain"
                        onerror="this.src='PL_img/default-team.png';"
                    >
                    <span class="text-sm font-bold text-center"><?= e($t['name']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <script>
    function teamStatsFilterTeams() {
        const q = document.getElementById('teamSearch').value.trim().toLowerCase();
        document.querySelectorAll('#teamGrid .team-card').forEach(function (card) {
            const name = card.getAttribute('data-name') || '';
            card.style.display = name.includes(q) ? '' : 'none';
        });
    }
    </script>

<?php else: ?>

    <?php
    $barWidth = static function ($pct) {
        return max(0, min(100, (float)$pct));
    };
    ?>

    <div class="stat-card p-6 md:p-8 mb-8 text-center">
        <img
            src="<?= e(teamStatsLogoSrc($profile['logo'])) ?>"
            alt="<?= e($profile['name']) ?>"
            class="w-24 h-24 object-contain mx-auto mb-3"
            onerror="this.src='PL_img/default-team.png';"
        >
        <h1 class="text-3xl md:text-4xl font-black text-white"><?= e($profile['name']) ?></h1>
        <p class="text-[#ff9900] font-bold mt-1">
            <?= $selectedCompetition ? e($selectedCompetition) : 'All Competitions' ?>
        </p>

        <?php if ($position !== null): ?>
            <div class="inline-flex items-center gap-2 mt-4 bg-black/30 border border-[#ff0080]/30 rounded-xl px-5 py-2">
                <span class="text-xs uppercase tracking-wider text-gray-400 font-bold">Position</span>
                <span class="text-2xl font-black text-white"><?= (int)$position ?></span>
            </div>
        <?php endif; ?>

        <?php if (count($availableCompetitions) > 1): ?>
            <form method="GET" class="mt-5 flex items-center justify-center gap-2">
                <input type="hidden" name="team" value="<?= e($teamParam) ?>">
                <label for="competition" class="text-xs font-bold text-gray-300">Competition</label>
                <select
                    id="competition"
                    name="competition"
                    onchange="this.form.submit()"
                    class="bg-[#1c003a] border border-[#ff0080] text-white rounded-xl px-3 py-2 text-sm font-bold outline-none"
                >
                    <option value="all" <?= $selectedCompetition === null ? 'selected' : '' ?>>All Competitions</option>
                    <?php foreach ($availableCompetitions as $comp): ?>
                        <option value="<?= e($comp) ?>" <?= $selectedCompetition === $comp ? 'selected' : '' ?>>
                            <?= e($comp) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>

        <div class="mt-5">
            <a href="team_stats.php" class="text-sm text-gray-400 hover:text-[#ff9900] underline">← Back to all teams</a>
        </div>
    </div>

    <?php if (!$stats['has_data']): ?>

        <div class="stat-card p-8 mb-8 text-center">
            <p class="text-lg font-bold text-gray-300">No statistics available yet.</p>
            <p class="text-gray-500 text-sm mt-2">This team has no completed matches on record for this filter.</p>
        </div>

    <?php else: ?>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="stat-card p-4 text-center">
            <div class="text-2xl font-black text-white"><?= (int)$stats['played'] ?></div>
            <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mt-1">Played</div>
        </div>
        <div class="stat-card p-4 text-center">
            <div class="text-2xl font-black text-green-400"><?= (int)$stats['wins'] ?></div>
            <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mt-1">Won</div>
        </div>
        <div class="stat-card p-4 text-center">
            <div class="text-2xl font-black text-gray-300"><?= (int)$stats['draws'] ?></div>
            <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mt-1">Drawn</div>
        </div>
        <div class="stat-card p-4 text-center">
            <div class="text-2xl font-black text-red-400"><?= (int)$stats['losses'] ?></div>
            <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mt-1">Lost</div>
        </div>
    </div>

    <div class="grid grid-cols-3 gap-4 mb-8">
        <div class="stat-card p-4 text-center">
            <div class="text-xl font-black text-white"><?= (int)$stats['goals_for'] ?></div>
            <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mt-1">Goals</div>
        </div>
        <div class="stat-card p-4 text-center">
            <div class="text-xl font-black text-white"><?= (int)$stats['goals_against'] ?></div>
            <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mt-1">Conceded</div>
        </div>
        <div class="stat-card p-4 text-center">
            <div class="text-xl font-black <?= $stats['goal_difference'] >= 0 ? 'text-green-400' : 'text-red-400' ?>">
                <?= $stats['goal_difference'] > 0 ? '+' : '' ?><?= (int)$stats['goal_difference'] ?>
            </div>
            <div class="text-[10px] uppercase tracking-wider text-gray-400 font-bold mt-1">GD</div>
        </div>
    </div>

    <div class="stat-card p-6 mb-8">
        <div class="flex justify-between items-center mb-4">
            <h2 class="text-lg font-black text-white">Form</h2>
            <span class="text-xs text-gray-400"><?= (int)($form['recent_points'] ?? 0) ?> pts from last <?= count($form['sequence'] ?? []) ?></span>
        </div>
        <?php if (empty($form['sequence'])): ?>
            <p class="text-gray-400 text-sm">No data available</p>
        <?php else: ?>
            <div class="flex gap-2 flex-wrap">
                <?php foreach ($form['sequence'] as $letter): ?>
                    <div class="form-pill <?= teamStatsResultBadgeClass($letter) ?>"><?= e($letter) ?></div>
                <?php endforeach; ?>
            </div>
            <p class="text-xs text-gray-500 mt-3">Last 5: <?= empty($formLast5) ? 'No data available' : e(implode(' ', $formLast5)) ?></p>
        <?php endif; ?>
    </div>

    <div class="grid md:grid-cols-2 gap-6 mb-8">
        <div class="stat-card p-6">
            <h2 class="text-lg font-black text-white mb-4">Home</h2>
            <?php if ($stats['home']['played'] === 0): ?>
                <p class="text-gray-400 text-sm">No data available</p>
            <?php else: ?>
                <ul class="space-y-2 text-sm">
                    <li class="flex justify-between"><span class="text-gray-400">Matches</span><span class="font-bold"><?= (int)$stats['home']['played'] ?></span></li>
                    <li class="flex justify-between"><span class="text-gray-400">Won</span><span class="font-bold text-green-400"><?= (int)$stats['home']['wins'] ?></span></li>
                    <li class="flex justify-between"><span class="text-gray-400">Drawn</span><span class="font-bold text-gray-300"><?= (int)$stats['home']['draws'] ?></span></li>
                    <li class="flex justify-between"><span class="text-gray-400">Lost</span><span class="font-bold text-red-400"><?= (int)$stats['home']['losses'] ?></span></li>
                    <li class="flex justify-between"><span class="text-gray-400">Goals Scored</span><span class="font-bold"><?= (int)$stats['home']['goals_for'] ?></span></li>
                    <li class="flex justify-between"><span class="text-gray-400">Goals Conceded</span><span class="font-bold"><?= (int)$stats['home']['goals_against'] ?></span></li>
                    <li class="flex justify-between"><span class="text-gray-400">Win %</span><span class="font-bold text-[#ff9900]"><?= number_format((float)$stats['home']['win_pct'], 1) ?>%</span></li>
                </ul>
            <?php endif; ?>
        </div>

        <div class="stat-card p-6">
            <h2 class="text-lg font-black text-white mb-4">Away</h2>
            <?php if ($stats['away']['played'] === 0): ?>
                <p class="text-gray-400 text-sm">No data available</p>
            <?php else: ?>
                <ul class="space-y-2 text-sm">
                    <li class="flex justify-between"><span class="text-gray-400">Matches</span><span class="font-bold"><?= (int)$stats['away']['played'] ?></span></li>
                    <li class="flex justify-between"><span class="text-gray-400">Won</span><span class="font-bold text-green-400"><?= (int)$stats['away']['wins'] ?></span></li>
                    <li class="flex justify-between"><span class="text-gray-400">Drawn</span><span class="font-bold text-gray-300"><?= (int)$stats['away']['draws'] ?></span></li>
                    <li class="flex justify-between"><span class="text-gray-400">Lost</span><span class="font-bold text-red-400"><?= (int)$stats['away']['losses'] ?></span></li>
                    <li class="flex justify-between"><span class="text-gray-400">Goals Scored</span><span class="font-bold"><?= (int)$stats['away']['goals_for'] ?></span></li>
                    <li class="flex justify-between"><span class="text-gray-400">Goals Conceded</span><span class="font-bold"><?= (int)$stats['away']['goals_against'] ?></span></li>
                    <li class="flex justify-between"><span class="text-gray-400">Win %</span><span class="font-bold text-[#ff9900]"><?= number_format((float)$stats['away']['win_pct'], 1) ?>%</span></li>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <div class="grid md:grid-cols-2 gap-6 mb-8">
        <div class="stat-card p-6">
            <h2 class="text-lg font-black text-white mb-4">Attack</h2>
            <ul class="space-y-2 text-sm">
                <li class="flex justify-between"><span class="text-gray-400">Avg. Goals Scored</span><span class="font-bold"><?= number_format((float)$stats['attack']['avg_goals_scored'], 2) ?></span></li>
                <li class="flex justify-between"><span class="text-gray-400">Matches Scored In</span><span class="font-bold"><?= (int)$stats['attack']['matches_scored_in'] ?></span></li>
                <li class="flex justify-between"><span class="text-gray-400">Failed to Score</span><span class="font-bold"><?= (int)$stats['attack']['failed_to_score'] ?></span></li>
                <li class="flex justify-between"><span class="text-gray-400">Matches with 2+ Goals</span><span class="font-bold"><?= (int)$stats['attack']['two_plus_goals'] ?></span></li>
                <li class="flex justify-between"><span class="text-gray-400">Matches with 3+ Goals</span><span class="font-bold"><?= (int)$stats['attack']['three_plus_goals'] ?></span></li>
            </ul>
        </div>

        <div class="stat-card p-6">
            <h2 class="text-lg font-black text-white mb-4">Defence</h2>
            <ul class="space-y-2 text-sm">
                <li class="flex justify-between"><span class="text-gray-400">Clean Sheets</span><span class="font-bold"><?= (int)$stats['defence']['clean_sheets'] ?></span></li>
                <li class="flex justify-between"><span class="text-gray-400">Failed to Keep Clean Sheet</span><span class="font-bold"><?= (int)$stats['defence']['failed_clean_sheet'] ?></span></li>
                <li class="flex justify-between"><span class="text-gray-400">Avg. Goals Conceded</span><span class="font-bold"><?= number_format((float)$stats['defence']['avg_goals_conceded'], 2) ?></span></li>
                <li class="flex justify-between"><span class="text-gray-400">Matches Conceding 2+</span><span class="font-bold"><?= (int)$stats['defence']['conceding_two_plus'] ?></span></li>
            </ul>
        </div>
    </div>

    <div class="grid md:grid-cols-2 gap-6 mb-8">
        <div class="stat-card p-6">
            <h2 class="text-lg font-black text-white mb-4">Recent Matches</h2>
            <?php if (empty($recentMatches)): ?>
                <p class="text-gray-400 text-sm">No data available</p>
            <?php else: ?>
                <ul class="space-y-3">
                    <?php foreach ($recentMatches as $rm): ?>
                        <li class="flex items-center justify-between text-sm border-b border-white/10 pb-2 last:border-0 last:pb-0">
                            <div>
                                <div class="font-bold"><?= $rm['is_home'] ? 'vs' : '@' ?> <?= e($rm['opponent']) ?></div>
                                <div class="text-[11px] text-gray-500"><?= e(teamStatsFormatDate($rm['date'])) ?> • <?= e($rm['competition']) ?></div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="font-black"><?= (int)$rm['goals_for'] ?> - <?= (int)$rm['goals_against'] ?></span>
                                <span class="form-pill <?= teamStatsResultBadgeClass($rm['letter']) ?> !w-7 !h-7 !text-xs"><?= e($rm['letter']) ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="stat-card p-6">
            <h2 class="text-lg font-black text-white mb-4">Upcoming Matches</h2>
            <?php if (empty($upcomingMatches)): ?>
                <p class="text-gray-400 text-sm">No data available</p>
            <?php else: ?>
                <ul class="space-y-3">
                    <?php foreach ($upcomingMatches as $um): ?>
                        <li class="flex items-center justify-between text-sm border-b border-white/10 pb-2 last:border-0 last:pb-0">
                            <div>
                                <div class="font-bold"><?= $um['is_home'] ? 'vs' : '@' ?> <?= e($um['opponent']) ?></div>
                                <div class="text-[11px] text-gray-500"><?= e(teamStatsFormatDate($um['date'])) ?> • <?= e($um['competition']) ?></div>
                            </div>
                            <span class="text-[10px] uppercase font-black text-[#ff9900]"><?= $um['is_home'] ? 'Home' : 'Away' ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>

    <?php endif; ?>

    <div class="stat-card p-6 mb-8">
        <h2 class="text-lg font-black text-white mb-4">Compare Teams</h2>

        <form method="GET" class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 mb-2">
            <input type="hidden" name="team" value="<?= e($teamParam) ?>">
            <?php if ($selectedCompetition !== null): ?>
                <input type="hidden" name="competition" value="<?= e($selectedCompetition) ?>">
            <?php endif; ?>
            <input
                type="text"
                name="compare"
                list="teamStatsDatalist"
                value="<?= e($compareParam) ?>"
                placeholder="Compare with another team..."
                class="flex-1 bg-black/30 border border-[#ff0080]/30 rounded-xl px-4 py-3 text-white outline-none focus:border-[#ff9900]"
            >
            <datalist id="teamStatsDatalist">
                <?php foreach (teamStatsAllTeams($conn) as $t): ?>
                    <option value="<?= e($t['name']) ?>"></option>
                <?php endforeach; ?>
            </datalist>
            <button type="submit" class="bg-[#ff0080] hover:bg-[#ff9900] text-white font-black px-6 py-3 rounded-xl transition">Compare</button>
        </form>

        <?php if ($compareNotFound): ?>
            <p class="text-[#ff9900] text-sm mt-2">No data available for that team.</p>
        <?php endif; ?>

        <?php if ($compareProfile !== null && $compareStats !== null): ?>

            <div class="overflow-x-auto mt-6">
                <table class="w-full text-sm min-w-[420px]">
                    <thead>
                        <tr class="text-gray-400 text-xs uppercase tracking-wider">
                            <th class="text-left py-2">Stat</th>
                            <th class="text-center py-2"><?= e($profile['name']) ?></th>
                            <th class="text-center py-2"><?= e($compareProfile['name']) ?></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/10">
                        <?php
                        $rows = [
                            'Played' => [$stats['played'], $compareStats['played']],
                            'Wins' => [$stats['wins'], $compareStats['wins']],
                            'Draws' => [$stats['draws'], $compareStats['draws']],
                            'Losses' => [$stats['losses'], $compareStats['losses']],
                            'Goals For' => [$stats['goals_for'], $compareStats['goals_for']],
                            'Goals Against' => [$stats['goals_against'], $compareStats['goals_against']],
                            'Goal Difference' => [$stats['goal_difference'], $compareStats['goal_difference']],
                            'Clean Sheets' => [$stats['defence']['clean_sheets'], $compareStats['defence']['clean_sheets']],
                            'Form' => [
                                empty($form['sequence']) ? 'No data available' : implode(' ', array_slice($form['sequence'], -5)),
                                empty($compareForm['sequence']) ? 'No data available' : implode(' ', array_slice($compareForm['sequence'], -5)),
                            ],
                        ];
                        ?>
                        <?php foreach ($rows as $label => $pair): ?>
                            <tr>
                                <td class="py-2 text-gray-400 font-bold"><?= e($label) ?></td>
                                <td class="py-2 text-center font-black"><?= e((string)$pair[0]) ?></td>
                                <td class="py-2 text-center font-black"><?= e((string)$pair[1]) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="mt-8">
                <h3 class="text-md font-black text-white mb-3">Head-to-Head</h3>
                <?php if (!$headToHead['has_data']): ?>
                    <p class="text-gray-400 text-sm">No data available</p>
                <?php else: ?>
                    <div class="grid grid-cols-3 gap-4 mb-5 text-center">
                        <div class="stat-card p-4">
                            <div class="text-xl font-black text-green-400"><?= (int)$headToHead['team_a_wins'] ?></div>
                            <div class="text-[10px] uppercase text-gray-400 font-bold mt-1"><?= e($profile['name']) ?> Wins</div>
                        </div>
                        <div class="stat-card p-4">
                            <div class="text-xl font-black text-gray-300"><?= (int)$headToHead['draws'] ?></div>
                            <div class="text-[10px] uppercase text-gray-400 font-bold mt-1">Draws</div>
                        </div>
                        <div class="stat-card p-4">
                            <div class="text-xl font-black text-green-400"><?= (int)$headToHead['team_b_wins'] ?></div>
                            <div class="text-[10px] uppercase text-gray-400 font-bold mt-1"><?= e($compareProfile['name']) ?> Wins</div>
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mb-4">
                        <?= (int)$headToHead['meetings'] ?> meetings •
                        Goals <?= e($profile['name']) ?> <?= (int)$headToHead['team_a_goals'] ?> - <?= (int)$headToHead['team_b_goals'] ?> <?= e($compareProfile['name']) ?>
                    </p>
                    <ul class="space-y-2">
                        <?php foreach ($headToHead['matches'] as $hm): ?>
                            <li class="flex items-center justify-between text-sm border-b border-white/10 pb-2 last:border-0 last:pb-0">
                                <span class="text-gray-400 text-[11px]"><?= e(teamStatsFormatDate($hm['date'])) ?> • <?= e($hm['competition']) ?></span>
                                <span class="font-bold"><?= e($hm['home_team']) ?> <?= (int)$hm['home_score'] ?> - <?= (int)$hm['away_score'] ?> <?= e($hm['away_team']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

        <?php endif; ?>
    </div>

<?php endif; ?>

</main>

</body>
</html>