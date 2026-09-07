<?php

session_start();
include 'connect.php';
require_once 'gameweek_deadline.php';

date_default_timezone_set('Africa/Casablanca');

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$viewer_id = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| TEAM LOGO HELPER (identical to other_matches.php, kept in sync)
|--------------------------------------------------------------------------
*/

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


/*
|--------------------------------------------------------------------------
| TARGET USER
|--------------------------------------------------------------------------
*/

$target_user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;

if ($target_user_id <= 0) {
    header("Location: other_matches.php");
    exit;
}

$user_stmt = $conn->prepare("SELECT id, username FROM users WHERE id = ? LIMIT 1");
$user_stmt->bind_param("i", $target_user_id);
$user_stmt->execute();
$target_user = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

if (!$target_user) {
    header("Location: other_matches.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| GAMEWEEKS (Other Leagues only, same scope as other_matches.php)
|--------------------------------------------------------------------------
*/

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


/*
|--------------------------------------------------------------------------
| DEADLINE CHECK
|--------------------------------------------------------------------------
|
| Other users' predictions only ever become visible once the deadline for
| that gameweek has passed - enforced here too (not just on the page that
| links here) so a direct URL can't be used to peek early.
|
*/

$deadlinePassed = isGameweekDeadlinePassed(
    $conn,
    $selected_gw
);

if (!$deadlinePassed) {
    header("Location: other_matches.php?gameweek=" . $selected_gw);
    exit;
}


/*
|--------------------------------------------------------------------------
| THAT USER'S PREDICTIONS (read-only)
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        m.*,
        se.predicted_home,
        se.predicted_away,
        se.points
    FROM matches m
    LEFT JOIN score_exact se
        ON m.id = se.match_id
        AND se.user_id = ?
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

$stmt->bind_param("ii", $target_user_id, $selected_gw);
$stmt->execute();
$result = $stmt->get_result();

?>

<!DOCTYPE html>

<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title><?= htmlspecialchars($target_user['username']) ?>'s Predictions</title>

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

.pred-box {
    background: rgba(0,0,0,.35);
    border: 2px solid rgba(0,255,157,.35);
    color: #ffd86b;
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

.badge-exact {
    background: rgba(250,204,21,.12);
    color: #facc15;
    border: 1px solid rgba(250,204,21,.35);
}

.badge-correct {
    background: rgba(96,165,250,.12);
    color: #60a5fa;
    border: 1px solid rgba(96,165,250,.35);
}

.badge-wrong {
    background: rgba(248,113,113,.12);
    color: #f87171;
    border: 1px solid rgba(248,113,113,.35);
}

.badge-pending {
    background: rgba(255,255,255,.06);
    color: #bfb7c6;
    border: 1px solid rgba(255,255,255,.15);
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
    <h1 class="text-3xl sm:text-4xl font-black text-accent">
        <?= htmlspecialchars($target_user['username']) ?>'s Predictions
    </h1>
    <p class="text-gray-400 mt-2">Gameweek <?= (int)$selected_gw ?> · Read-only</p>
</div>

<form method="GET" class="flex flex-col sm:flex-row items-center justify-center gap-3 mb-8">
    <input type="hidden" name="user_id" value="<?= (int)$target_user_id ?>">
    <label for="gameweek" class="text-muted text-sm font-semibold">Select Gameweek:</label>
    <select name="gameweek" id="gameweek" class="bg-[#120014] border border-accent text-white px-4 py-2.5 rounded-xl outline-none font-bold cursor-pointer" onchange="this.form.submit()">
        <?php while ($gw = $gw_result->fetch_assoc()):
            $gw_num = (int)$gw['gameweek'];
            $selected = ($selected_gw == $gw_num) ? 'selected' : '';
        ?>
            <option value="<?= $gw_num ?>" <?= $selected ?>>
                Gameweek <?= $gw_num ?>
            </option>
        <?php endwhile; ?>
    </select>
</form>

<?php if ($result->num_rows === 0): ?>

    <div class="text-center text-muted py-12 bg-white/5 rounded-2xl border border-white/10">
        <div class="text-5xl mb-4">⚽</div>
        <p class="text-lg">No matches available for this gameweek.</p>
    </div>

<?php else: ?>

    <?php $current_league = ""; ?>

    <div class="space-y-6">

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

        $is_finished = $match['home_score'] !== null && $match['away_score'] !== null;

        $badge_class = 'badge-pending';
        $badge_text = 'Not played yet';

        if (!$has_prediction) {
            $badge_class = 'badge-pending';
            $badge_text = 'No prediction made';
        } elseif ($is_finished) {
            if (
                (int)$match['predicted_home'] === (int)$match['home_score'] &&
                (int)$match['predicted_away'] === (int)$match['away_score']
            ) {
                $badge_class = 'badge-exact';
                $badge_text = 'Exact score · +' . (int)($match['points'] ?? 3);
            } elseif ((int)($match['points'] ?? 0) > 0) {
                $badge_class = 'badge-correct';
                $badge_text = 'Correct result · +' . (int)$match['points'];
            } else {
                $badge_class = 'badge-wrong';
                $badge_text = 'Wrong · +0';
            }
        }
        ?>

        <div class="match-card rounded-2xl overflow-hidden p-5 sm:p-6">

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
                        <div class="pred-box w-12 sm:w-14 h-12 rounded-lg flex items-center justify-center font-black text-lg">
                            <?= $has_prediction ? (int)$match['predicted_home'] : '–' ?>
                        </div>
                        <span class="text-accent font-black text-xl">-</span>
                        <div class="pred-box w-12 sm:w-14 h-12 rounded-lg flex items-center justify-center font-black text-lg">
                            <?= $has_prediction ? (int)$match['predicted_away'] : '–' ?>
                        </div>
                    </div>
                    <span class="text-[10px] text-gray-500 mt-2">
                        <?= htmlspecialchars($target_user['username']) ?>'S PREDICTION
                    </span>

                    <?php if ($is_finished): ?>
                        <span class="text-xs text-gray-400 mt-1">
                            Final score: <?= (int)$match['home_score'] ?> - <?= (int)$match['away_score'] ?>
                        </span>
                    <?php endif; ?>

                    <span class="mt-3 inline-block text-xs font-black px-3 py-1 rounded-full <?= $badge_class ?>">
                        <?= $badge_text ?>
                    </span>
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

    </div>

<?php endif; ?>

<div class="text-center mt-8 pb-4">
    <a href="other_matches.php?gameweek=<?= (int)$selected_gw ?>" class="text-accent underline text-sm sm:text-base font-semibold hover:text-pink-300">← Back to Other Leagues Predictions</a>
</div>

</div>

</body>

</html>