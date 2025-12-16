<?php 
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

$gw_sql = "SELECT DISTINCT gameweek FROM matches WHERE competition <> 'Premier League' ORDER BY gameweek ASC";
$gw_result = $conn->query($gw_sql);

$last_gw_sql = "SELECT MAX(gameweek) AS last_gw FROM matches WHERE competition <> 'Premier League'";
$last_gw_result = $conn->query($last_gw_sql);
$last_gw_row = $last_gw_result->fetch_assoc();
$last_gameweek = (int)$last_gw_row['last_gw'];

$selected_gw = isset($_GET['gameweek']) ? (int)$_GET['gameweek'] : $last_gameweek;

if ($selected_gw < $last_gameweek) {
    header("Location: other_matches.php?gameweek=" . $last_gameweek);
    exit();
}

$sql = "SELECT m.*, p.predicted_home, p.predicted_away 
        FROM matches m 
        LEFT JOIN score_exact p ON m.id = p.match_id AND p.user_id = ? 
        WHERE m.competition <> 'Premier League' 
        AND m.gameweek = ? 
        ORDER BY m.competition, m.match_date ASC";

$stmt = $conn->prepare($sql);
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
:root{
    --pl-dark:#06060a;
    --pl-purple:#37003c;
    --pl-pink:#e90052;
    --card:#120014;
    --muted:#bfb7c6;
}

body { background: var(--pl-dark); }
.bg-main { background: var(--pl-purple); }
.text-accent { color: var(--pl-pink); }
.border-accent { border-color: var(--pl-pink); }
.bg-card { background: var(--card); }
.text-muted { color: var(--muted); }
</style>

</head>

<body class="min-h-screen text-white flex items-center justify-center p-6">

<nav class="w-full bg-main/50 backdrop-blur-md text-white py-4 px-6 fixed top-0 left-0 flex justify-between items-center z-50 border-b border-accent/20">

    <div class="flex items-center gap-2">
        <img src="/PL_img/PL_LOGO1.png" class="h-10 w-10" alt="">
        <h2 class="text-lg font-bold">Premier League</h2>
    </div>

    <button onclick="toggleMenu()" class="md:hidden text-white focus:outline-none">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
    </button>

    <ul class="hidden md:flex gap-6 text-sm font-semibold">
        <li><a href="dashboard.php" class="hover:text-accent">Dashboard</a></li>
        <li><a href="predictions.php" class="hover:text-accent">Predictions</a></li>
        <li><a href="leaderboard.php" class="hover:text-accent">Leaderboard</a></li>
        <li><a href="my_predictions.php" class="hover:text-accent">My Predictions</a></li>
    </ul>
</nav>

<div id="mobileMenu" class="hidden bg-main flex-col gap-4 text-white py-5 px-6 fixed top-16 left-0 w-full z-40 md:hidden">
    <a href="dashboard.php" class="hover:text-accent">Dashboard</a><br><br>
    <a href="predictions.php" class="hover:text-accent">Predictions</a><br><br>
    <a href="leaderboard.php" class="hover:text-accent">Leaderboard</a><br><br>
    <a href="my_predictions.php" class="hover:text-accent">My Predictions</a>
</div>

<script>
function toggleMenu() {
    document.getElementById("mobileMenu").classList.toggle("hidden");
}
</script>

<div class="w-full mt-20 max-w-6xl bg-card backdrop-blur-md rounded-2xl shadow-xl p-6">

<h1 class="text-3xl font-extrabold text-accent text-center mb-6">
    Other Leagues Predictions
</h1>

<div id="countdown-container" class="text-center mb-6">
    <p class="text-lg">⏰ Deadline in: <span id="countdown" class="font-bold text-accent"></span></p>
</div>

<div id="deadline-message" class="hidden text-center mt-10">
    <h2 class="text-2xl font-bold text-red-500 mb-4">⚠️ The prediction deadline has passed!</h2>

    <a href="my_predictions.php" class="bg-accent hover:bg-pink-600 text-black px-6 py-3 rounded-lg font-semibold inline-block mb-3">
        Go to My Predictions
    </a><br>

    <a href="predictions.php" class="text-accent underline text-sm sm:text-base">
        ← Back to Premier League
    </a>
</div>

<div id="matches-container">

<form method="GET" class="flex items-center justify-center gap-2 mb-6">
    <label for="gameweek" class="text-muted text-sm">Select Gameweek:</label>

    <select name="gameweek" id="gameweek"
        class="bg-card border border-accent text-white px-3 py-2 rounded-lg"
        onchange="this.form.submit()">

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

<p class="text-center text-muted">No matches available right now for this gameweek.</p>

<?php else: ?>

<?php 
$current_league = "";
while ($match = $result->fetch_assoc()):

    if ($match['competition'] !== $current_league):
        $current_league = $match['competition'];
        echo "<h2 class='text-2xl font-bold text-center text-white mt-10 mb-4'>{$current_league}</h2>";
    endif;
?>

<form action="insert_prediction.php" method="POST"
    class="bg-white/5 rounded-xl shadow-lg p-4 mb-6 flex flex-col sm:flex-row items-center justify-between gap-4">

    <input type="hidden" name="match_id" value="<?= $match['id'] ?>">
    <input type="hidden" name="competition" value="Other Leagues">

    <div class="flex items-center gap-3 w-full sm:w-1/3 justify-start text-left">
        <p class="text-xs text-muted">(H)</p>
        <span class="truncate max-w-[120px] sm:max-w-[150px] text-lg font-semibold">
            <?= htmlspecialchars($match['home_team']) ?>
        </span>

        <?php if (!empty($match['home_team_pic'])): ?>
        <img src="<?= htmlspecialchars($match['home_team_pic']) ?>" class="w-14 h-14 sm:w-16 sm:h-16 rounded object-cover">
        <?php endif; ?>
    </div>

    <div class="flex flex-col items-center justify-center w-full sm:w-1/3 text-center">
        <div class="flex items-center justify-center gap-2">

            <input type="number" name="predicted_home"
                value="<?= $match['predicted_home'] ?>"
                class="w-12 sm:w-14 bg-transparent border-2 border-accent outline-0 p-2 rounded-lg text-center text-accent font-bold"
                min="0" max="10" <?= $match['predicted_home'] !== null ? 'readonly' : '' ?>>

            <span class="text-accent font-bold text-lg sm:text-xl">-</span>

            <input type="number" name="predicted_away"
                value="<?= $match['predicted_away'] ?>"
                class="w-12 sm:w-14 bg-transparent border-2 border-accent outline-0 p-2 rounded-lg text-center text-accent font-bold"
                min="0" max="10" <?= $match['predicted_away'] !== null ? 'readonly' : '' ?>>

        </div>

        <p class="text-xs text-muted mt-1">
            <?= date('D, d M Y • H:i', strtotime($match['match_date'])) ?>
        </p>
    </div>

    <div class="flex items-center gap-3 w-full sm:w-1/3 justify-end text-right">
        <?php if (!empty($match['away_team_pic'])): ?>
        <img src="<?= htmlspecialchars($match['away_team_pic']) ?>" class="w-14 h-14 sm:w-16 sm:h-16 rounded object-cover">
        <?php endif; ?>

        <span class="truncate max-w-[120px] sm:max-w-[150px] text-lg font-semibold">
            <?= htmlspecialchars($match['away_team']) ?>
        </span>

        <p class="text-xs text-muted">(A)</p>
    </div>

    <div class="text-center w-full sm:w-auto">

        <?php if ($match['predicted_home'] !== null && $match['predicted_away'] !== null): ?>

            <button disabled class="bg-gray-600 cursor-not-allowed px-5 py-2 rounded-lg text-sm font-bold shadow-inner">
                Submitted
            </button>

        <?php else: ?>

            <button type="submit"
                class="bg-accent border-2 border-pink-800 hover:bg-pink-600 transition px-5 py-2 rounded-lg text-sm font-bold shadow-lg">
                Submit
            </button>

        <?php endif; ?>

    </div>
</form>

<?php endwhile; ?>
<?php endif; ?>

<div class="text-center mt-6">
    <a href="predictions.php" class="text-accent underline text-sm sm:text-base">
        ← Back to Premier League
    </a>
</div>

</div>
</div>

<script>
const deadline = new Date("2025-12-13T13:30:00").getTime();
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