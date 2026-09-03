<?php

session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];

function teamLogo($teamName)
{
    $teamName = strtolower(trim($teamName));
    $teamName = preg_replace('/\s+/', ' ', $teamName);

    $logos = [
        'arsenal' => 'arsenal.png',
        'aston villa' => 'aston-villa.png',
        'bournemouth' => 'bournemouth.png',
        'brentford' => 'brentford.png',
        'brighton' => 'brighton.png',
        'brighton & hove albion' => 'brighton.png',
        'chelsea' => 'chelsea.png',
        'coventry' => 'coventry-city.png',
        'coventry city' => 'coventry-city.png',
        'crystal palace' => 'crystal-palace.png',
        'everton' => 'everton.png',
        'fulham' => 'fulham.png',
        'hull' => 'hull-city.png',
        'hull city' => 'hull-city.png',
        'ipswich' => 'ipswich-town.png',
        'ipswich town' => 'ipswich-town.png',
        'leeds' => 'leeds.png',
        'leeds united' => 'leeds.png',
        'liverpool' => 'liverpool.png',
        'manchester city' => 'manchester-city.png',
        'man city' => 'manchester-city.png',
        'manchester united' => 'manchester-united.png',
        'man united' => 'manchester-united.png',
        'man utd' => 'manchester-united.png',
        'newcastle' => 'newcastle.png',
        'newcastle united' => 'newcastle.png',
        'nottingham forest' => 'nottingham-forest.png',
        'nottingham' => 'nottingham-forest.png',
        'sunderland' => 'sunderland.png',
        'tottenham' => 'tottenham.png',
        'tottenham hotspur' => 'tottenham.png',
        'spurs' => 'tottenham.png',
    ];

    if (isset($logos[$teamName])) {
        $file = $logos[$teamName];
        $fullPath = __DIR__ . DIRECTORY_SEPARATOR . 'PL_Teams' . DIRECTORY_SEPARATOR . $file;

        if (file_exists($fullPath)) {
            return 'PL_Teams/' . $file;
        }
    }

    $safeName = preg_replace('/[^a-z0-9]+/', '-', $teamName);
    $safeName = trim($safeName, '-');

    $possibleFiles = [
        $safeName . '.png',
        $safeName . '.jpg',
        $safeName . '.jpeg',
    ];

    foreach ($possibleFiles as $file) {
        $fullPath = __DIR__ . DIRECTORY_SEPARATOR . 'PL_Teams' . DIRECTORY_SEPARATOR . $file;

        if (file_exists($fullPath)) {
            return 'PL_Teams/' . $file;
        }
    }

    return null;
}

$gw_sql = "
    SELECT DISTINCT gameweek
    FROM matches
    WHERE competition <> 'Premier League'
    ORDER BY gameweek ASC
";

$gw_result = $conn->query($gw_sql);

$last_gw_sql = "
    SELECT MAX(gameweek) AS last_gw
    FROM matches
    WHERE competition <> 'Premier League'
";

$last_gw_result = $conn->query($last_gw_sql);
$last_gw_row = $last_gw_result->fetch_assoc();
$last_gameweek = (int)($last_gw_row['last_gw'] ?? 1);

$selected_gw = isset($_GET['gameweek']) ? (int)$_GET['gameweek'] : $last_gameweek;

if ($selected_gw < $last_gameweek) {
    header("Location: other_matches.php?gameweek=" . $last_gameweek);
    exit();
}

$sql = "
    SELECT
        m.*,
        p.predicted_home,
        p.predicted_away
    FROM matches m
    LEFT JOIN score_exact p
        ON m.id = p.match_id
        AND p.user_id = ?
    WHERE
        m.competition <> 'Premier League'
        AND m.gameweek = ?
    ORDER BY
        m.competition,
        m.match_date ASC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Database error: " . htmlspecialchars($conn->error));
}

$stmt->bind_param("ii", $user_id, $selected_gw);
$stmt->execute();
$result = $stmt->get_result();

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Other Leagues Predictions</title>

<script src="https://cdn.tailwindcss.com"></script>

<style>

:root {
    --pl-dark: #06060a;
    --pl-purple: #1a0030;
    --pl-accent: #00ff9d;
    --card: #120014;
    --muted: #bfb7c6;
}

body {
    background: url('PL_img/22.jpg') center/cover no-repeat fixed;
}

body::before {
    content: "";
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(10, 0, 21, 0.65);
    z-index: -1;
    pointer-events: none;
}

.team-logo {
    width: 80px;
    height: 80px;
    object-fit: contain;
    background: transparent;
    border-radius: 50%;
    padding: 6px;
    border: 3px solid rgba(0,255,157,.35);
    box-shadow: 0 8px 30px rgba(0,0,0,.5), inset 0 0 15px rgba(0,255,157,.08);
    transition: all .3s ease;
}

.team-logo:hover {
    transform: scale(1.12);
    border-color: rgba(0,255,157,.9);
    box-shadow: 0 0 40px rgba(0,255,157,.4), inset 0 0 20px rgba(0,255,157,.15);
}

.team-logo-fallback {
    width: 80px;
    height: 80px;
    background: rgba(0,0,0,.3);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 3px solid rgba(0,255,157,.4);
    font-size: 0.7rem;
    font-weight: 900;
    color: #f7f2fa;
    text-align: center;
    padding: 6px;
    box-shadow: 0 8px 30px rgba(0,0,0,.5);
    transition: all .3s ease;
}

.team-logo-fallback:hover {
    transform: scale(1.08);
    border-color: rgba(0,255,157,.85);
    box-shadow: 0 0 35px rgba(0,255,157,.35);
}

@media(max-width:640px) {
    .team-logo,
    .team-logo-fallback {
        width: 64px;
        height: 64px;
        padding: 4px;
    }
}

.match-card {
    background: linear-gradient(180deg, rgba(255,255,255,.055), rgba(255,255,255,.018));
    border: 1px solid rgba(255,255,255,.09);
    box-shadow: 0 18px 50px rgba(0,0,0,.40);
    backdrop-filter: blur(14px);
    transition: all .25s ease;
}

.match-card:hover {
    transform: translateY(-3px);
    border-color: rgba(0,255,157,.40);
}

.nav-link {
    transition: .2s ease;
}

.nav-link:hover {
    color: #00ff9d;
}

.text-accent {
    color: var(--pl-accent);
}

.border-accent {
    border-color: var(--pl-accent);
}

.bg-accent {
    background: var(--pl-accent);
}

.bg-card {
    background: var(--card);
}

.text-muted {
    color: var(--muted);
}

.score-input {
    background: rgba(0,0,0,.35);
    border: 2px solid rgba(0,255,157,.65);
    color: #ffd86b;
    transition: all .2s ease;
}

.score-input:focus {
    border-color: #06effd;
    box-shadow: 0 0 18px rgba(6,239,253,.15);
}

.league-title {
    background: linear-gradient(90deg, rgba(0,255,157,.15), rgba(26,0,48,.25), rgba(0,255,157,.15));
    border: 1px solid rgba(0,255,157,.20);
}

.team-name {
    max-width: 150px;
}

@media(max-width:640px) {
    .team-name {
        max-width: 110px;
        font-size: .9rem;
    }
}

</style>

</head>

<body class="min-h-screen text-white">

<nav class="fixed top-0 left-0 right-0 z-50 bg-black/65 backdrop-blur-2xl border-b border-white/10 px-5 md:px-8 py-4 flex justify-between items-center">

    <a href="dashboard.php" class="flex items-center gap-3">
        <div class="w-11 h-11 rounded-full p-1 flex items-center justify-center">
            <img src="PL_img/PL_LOGO1.png" class="w-full h-full object-contain" alt="Premier League">
        </div>
        <span class="hidden sm:block text-lg font-black">Premier League</span>
    </a>

    <div class="hidden md:flex items-center gap-7 text-sm font-semibold">
        <a href="dashboard.php" class="nav-link">Dashboard</a>
        <a href="predictions.php" class="nav-link">Predictions</a>
        <a href="leaderboard.php" class="nav-link">Leaderboard</a>
        <a href="my_predictions.php" class="nav-link">My Predictions</a>
    </div>

    <button onclick="toggleMenu()" class="md:hidden text-white focus:outline-none">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
        </svg>
    </button>

</nav>

<!-- Mobile menu: each link now takes full width and has a bottom border for clear vertical separation -->
<div id="mobileMenu" class="hidden bg-[#1a0030]/95 backdrop-blur-xl flex-col text-white py-2 px-0 fixed top-16 left-0 w-full z-40 md:hidden border-b border-white/10">
    <a href="dashboard.php" class="nav-link w-full block py-3 px-6 border-b border-white/10 text-center hover:bg-white/5" onclick="toggleMenu()">Dashboard</a>
    <a href="predictions.php" class="nav-link w-full block py-3 px-6 border-b border-white/10 text-center hover:bg-white/5" onclick="toggleMenu()">Predictions</a>
    <a href="leaderboard.php" class="nav-link w-full block py-3 px-6 border-b border-white/10 text-center hover:bg-white/5" onclick="toggleMenu()">Leaderboard</a>
    <a href="my_predictions.php" class="nav-link w-full block py-3 px-6 border-b border-white/10 text-center hover:bg-white/5" onclick="toggleMenu()">My Predictions</a>
</div>

<script>
function toggleMenu() {
    document.getElementById("mobileMenu").classList.toggle("hidden");
}
</script>

<div class="w-full mt-24 max-w-6xl mx-auto bg-card backdrop-blur-md rounded-2xl shadow-2xl p-5 sm:p-6 mb-10 border border-white/10">

<div class="text-center mb-8">
    <h1 class="text-3xl sm:text-4xl font-black text-accent">Other Leagues Predictions</h1>
    <p class="text-gray-400 mt-2">Gameweek <?= $selected_gw ?></p>
</div>

<div id="countdown-container" class="text-center mb-7 bg-white/5 border border-white/10 rounded-xl p-4">
    <p class="text-lg font-semibold">⏰ Deadline in: <span id="countdown" class="font-black text-accent"></span></p>
</div>

<div id="deadline-message" class="hidden text-center mt-10 mb-10">
    <div class="bg-red-500/10 border border-red-500/30 rounded-2xl p-8">
        <div class="text-5xl mb-4">🔒</div>
        <h2 class="text-2xl font-black text-red-400 mb-4">Prediction Deadline Has Passed!</h2>
        <p class="text-gray-400 mb-6">You can no longer submit predictions for these matches.</p>
        <a href="my_predictions.php" class="bg-accent hover:bg-green-600 text-black px-6 py-3 rounded-lg font-black inline-block mb-3 transition">Go to My Predictions</a>
        <br>
        <a href="predictions.php" class="text-accent underline text-sm sm:text-base">← Back to Premier League</a>
    </div>
</div>

<div id="matches-container">

<form method="GET" class="flex flex-col sm:flex-row items-center justify-center gap-3 mb-8">
    <label for="gameweek" class="text-muted text-sm font-semibold">Select Gameweek:</label>
    <select name="gameweek" id="gameweek" class="bg-[#120014] border border-accent text-white px-4 py-2.5 rounded-xl outline-none font-bold cursor-pointer" onchange="this.form.submit()">
        <?php while ($gw = $gw_result->fetch_assoc()):
            $gw_num = (int)$gw['gameweek'];
            $selected = ($selected_gw == $gw_num) ? 'selected' : '';
            $disabled = ($gw_num < $last_gameweek) ? 'disabled' : '';
        ?>
            <option value="<?= $gw_num ?>" <?= $selected ?> <?= $disabled ?>>
                Gameweek <?= $gw_num ?>
            </option>
        <?php endwhile; ?>
    </select>
</form>

<?php if ($result->num_rows === 0): ?>

    <div class="text-center text-muted py-12 bg-white/5 rounded-2xl border border-white/10">
        <div class="text-5xl mb-4">⚽</div>
        <p class="text-lg">No matches available right now for this gameweek.</p>
    </div>

<?php else: ?>

    <?php $current_league = ""; ?>

    <form action="insert_prediction.php" method="POST" id="otherLeaguesPredictionsForm" class="space-y-6">
        <input type="hidden" name="prediction_type" value="multiple">
        <input type="hidden" name="gameweek" value="<?= (int)$selected_gw ?>">

    <?php while ($match = $result->fetch_assoc()): ?>

        <?php if ($match['competition'] !== $current_league): ?>
            <?php $current_league = $match['competition']; ?>
            <div class="league-title rounded-xl px-5 py-4 mt-8 mb-5 text-center">
                <h2 class="text-xl sm:text-2xl font-black text-white"><?= htmlspecialchars($current_league) ?></h2>
            </div>
        <?php endif; ?>

        <?php
        $home_logo = teamLogo($match['home_team']);
        $away_logo = teamLogo($match['away_team']);

        if (!$home_logo && !empty($match['home_team_pic'])) {
            $home_logo = $match['home_team_pic'];
        }

        if (!$away_logo && !empty($match['away_team_pic'])) {
            $away_logo = $match['away_team_pic'];
        }

        $has_prediction = $match['predicted_home'] !== null && $match['predicted_away'] !== null;
        ?>

        <div class="match-card rounded-2xl overflow-hidden p-5 sm:p-6 mb-6">
            <input type="hidden" name="match_id[]" value="<?= (int)$match['id'] ?>">

            <div class="text-center text-xs sm:text-sm text-gray-500 mb-5">
                <?= date('D, d M Y • H:i', strtotime($match['match_date'])) ?>
            </div>

            <div class="flex flex-col sm:flex-row items-center justify-between gap-6">

                <div class="flex items-center gap-3 w-full sm:w-1/3 justify-center sm:justify-start text-center sm:text-left">
                    <div class="flex flex-col items-center sm:items-start">
                        <span class="text-xs text-gray-500 mb-1">HOME</span>
                        <span class="team-name truncate text-base sm:text-lg font-black"><?= htmlspecialchars($match['home_team']) ?></span>
                    </div>
                    <?php if ($home_logo): ?>
                        <img src="<?= htmlspecialchars($home_logo) ?>" alt="<?= htmlspecialchars($match['home_team']) ?>" class="team-logo shrink-0">
                    <?php else: ?>
                        <div class="team-logo-fallback shrink-0"><?= htmlspecialchars(substr($match['home_team'], 0, 12)) ?></div>
                    <?php endif; ?>
                </div>

                <div class="flex flex-col items-center justify-center w-full sm:w-1/3">
                    <div class="flex items-center justify-center gap-2">
                        <input type="number" name="predicted_home[]" value="<?= htmlspecialchars($match['predicted_home'] ?? '') ?>" class="score-input w-12 sm:w-14 h-12 bg-transparent outline-none p-2 rounded-lg text-center font-black text-lg" min="0" max="10" required <?= $has_prediction ? 'readonly' : '' ?>>
                        <span class="text-accent font-black text-xl">-</span>
                        <input type="number" name="predicted_away[]" value="<?= htmlspecialchars($match['predicted_away'] ?? '') ?>" class="score-input w-12 sm:w-14 h-12 bg-transparent outline-none p-2 rounded-lg text-center font-black text-lg" min="0" max="10" required <?= $has_prediction ? 'readonly' : '' ?>>
                    </div>
                    <span class="text-[10px] text-gray-500 mt-2">YOUR PREDICTION</span>
                </div>

                <div class="flex items-center gap-3 w-full sm:w-1/3 justify-center sm:justify-end text-center sm:text-right">
                    <?php if ($away_logo): ?>
                        <img src="<?= htmlspecialchars($away_logo) ?>" alt="<?= htmlspecialchars($match['away_team']) ?>" class="team-logo shrink-0">
                    <?php else: ?>
                        <div class="team-logo-fallback shrink-0"><?= htmlspecialchars(substr($match['away_team'], 0, 12)) ?></div>
                    <?php endif; ?>
                    <div class="flex flex-col items-center sm:items-end">
                        <span class="text-xs text-gray-500 mb-1">AWAY</span>
                        <span class="team-name truncate text-base sm:text-lg font-black"><?= htmlspecialchars($match['away_team']) ?></span>
                    </div>
                </div>

            </div>

        </div>

    <?php endwhile; ?>

        <div class="flex justify-center mt-8 pt-2 pb-4">
            <button type="submit" class="bg-accent border-2 border-green-800 hover:bg-green-600 hover:scale-105 transition px-10 py-3 rounded-xl text-base font-black shadow-lg shadow-green-500/20">
                ✓ Submit All Predictions
            </button>
        </div>

    </form>

<?php endif; ?>

<div class="text-center mt-8 pb-4">
    <a href="predictions.php" class="text-accent underline text-sm sm:text-base font-semibold hover:text-pink-300">← Back to Premier League</a>
</div>

</div>

</div>

<script>
const deadline = new Date("2026-12-13T13:30:00").getTime();
const countdown = document.getElementById("countdown");
const matchesContainer = document.getElementById("matches-container");
const deadlineMsg = document.getElementById("deadline-message");

function updateCountdown() {
    const now = new Date().getTime();
    const diff = deadline - now;

    if (diff <= 0) {
        countdown.textContent = "Deadline Passed";
        matchesContainer.classList.add("hidden");
        deadlineMsg.classList.remove("hidden");
        clearInterval(timer);
    } else {
        const hours = Math.floor((diff / (1000 * 60 * 60)) % 24);
        const minutes = Math.floor((diff / (1000 * 60)) % 60);
        const seconds = Math.floor((diff / 1000) % 60);
        countdown.textContent = `${hours}h ${minutes}m ${seconds}s`;
    }
}

const timer = setInterval(updateCountdown, 1000);
updateCountdown();
</script>

</body>

</html>