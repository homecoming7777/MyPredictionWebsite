<?php 
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}

$user_id = $_SESSION['user_id'];

$latest_sql = "SELECT MAX(gameweek) AS latest_gw FROM matches WHERE competition = 'Premier League'";
$latest_result = $conn->query($latest_sql);
$latest_row = $latest_result->fetch_assoc();
$latest_gameweek = intval($latest_row['latest_gw']);

if (isset($_GET['gameweek'])) {
  $gameweek = intval($_GET['gameweek']);
} else {
  $gameweek = $latest_gameweek;
}

if ($gameweek < $latest_gameweek) {
  header("Location: predictions.php?gameweek=" . $latest_gameweek);
  exit();
}

$sql = "SELECT m.*, p.predicted_home, p.predicted_away 
        FROM matches m
        LEFT JOIN score_exact p 
          ON m.id = p.match_id AND p.user_id = ?
        WHERE m.gameweek = ? AND m.competition = 'Premier League'
        ORDER BY m.match_date ASC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $user_id, $gameweek);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Predictions</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>

<style>
:root{
  --pl-dark:#06060a;
  --pl-purple:#37003c;
  --pl-pink:#e90052;
  --card:#120014;
  --muted:#bfb7c6;
}


.card {
  background: linear-gradient(180deg, rgba(255,255,255,0.04), rgba(255,255,255,0.015));
  border: 1.5px solid rgba(233,0,82,0.12);
  box-shadow: 0 10px 26px rgba(2,2,6,0.8), inset 0 0 12px rgba(233,0,82,0.05);
  backdrop-filter: blur(7px);
}

.accent-border {
  border: 2px solid rgba(233,0,82,0.25);
  box-shadow: 0 0 30px rgba(233,0,82,0.15), inset 0 0 20px rgba(55,0,60,0.1);
}

.input-pl {
  background: rgba(0, 0, 0, 0.35);
  border: 2px solid #e90052;
  color: #ffd86b;
  font-weight: 700;
  box-shadow: 0 0 12px rgba(233,0,82,0.2);
}

.input-pl:focus {
  outline: none;
  box-shadow: 0 0 20px rgba(233,0,82,0.35);
}

.match-card {
  background: rgba(255,255,255,0.05);
  border: 1.5px solid rgba(255,255,255,0.12);
  box-shadow: 0 10px 30px rgba(0,0,0,0.4);
  transition: 0.25s;
  backdrop-filter: blur(6px);
}

.match-card:hover {
  transform: translateY(-4px);
  border-color: var(--pl-pink);
  box-shadow: 0 0 25px rgba(233,0,82,0.25);
}

.text-glow {
  text-shadow: 0 0 12px rgba(255, 215, 80, 0.65);
}
</style>

<body class="min-h-screen bg-[#050406] text-white">

<nav class="w-full bg-black/30 backdrop-blur-lg text-white py-4 px-6 fixed top-0 left-0 flex justify-between items-center z-50 border-b border-white/10">
    
    <div class="flex items-center gap-2">
        <img src="/PL_img/PL_LOGO1.png" class="h-10 w-10" alt="">
        <h2 class="text-lg font-extrabold text-glow">Premier League</h2>
    </div>

    <button onclick="toggleMenu()" class="md:hidden text-white focus:outline-none">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 6h16M4 12h16M4 18h16" />
        </svg>
    </button>

    <ul class="hidden md:flex gap-6 text-sm font-semibold">
        <li><a href="dashboard.php" class="hover:text-[var(--pl-pink)]">Dashboard</a></li>
        <li><a href="predictions.php" class="text-[var(--pl-pink)] font-bold">Predictions</a></li>
        <li><a href="leaderboard.php" class="hover:text-[var(--pl-pink)]">Leaderboard</a></li>
        <li><a href="my_predictions.php" class="hover:text-[var(--pl-pink)]">My Predictions</a></li>
    </ul>
</nav>

<div id="mobileMenu"
     class="hidden flex-col gap-4 bg-black/40 backdrop-blur-xl text-white py-5 px-6 fixed top-16 left-0 w-full z-40 border-b border-white/10 md:hidden">

    <a href="dashboard.php" class="hover:text-[var(--pl-pink)]">Dashboard</a><br><br>
    <a href="predictions.php" class="text-[var(--pl-pink)] font-bold">Predictions</a><br><br>
    <a href="leaderboard.php" class="hover:text-[var(--pl-pink)]">Leaderboard</a><br><br>
    <a href="my_predictions.php" class="hover:text-[var(--pl-pink)]">My Predictions</a>
</div>

<script>
function toggleMenu() {
  document.getElementById("mobileMenu").classList.toggle("hidden");
}
</script>

<div class="h-20"></div>

<div class="w-full max-w-6xl mx-auto mt-10 mb-10 card accent-border rounded-2xl p-8">

  <h1 class="text-4xl font-extrabold text-center text-yellow-400 drop-shadow-xl text-glow">
    Premier League Predictions
  </h1>

  <div class="flex justify-center mt-3 mb-8">
    <img src="/PL_img/PL_LOGO1.png" class="h-20 w-20 drop-shadow-xl">
  </div>

  <form method="GET" class="text-center mb-8">
    <label for="gameweek" class="font-semibold text-yellow-300 text-lg">Select Gameweek</label><br>
    <select id="gameweek" name="gameweek" 
            class="mt-3 input-pl rounded-lg px-6 py-2 text-sm transition"
            onchange="this.form.submit()">
      <?php
      $weeks = $conn->query("SELECT DISTINCT gameweek FROM matches WHERE competition = 'Premier League' ORDER BY gameweek ASC");
      while ($w = $weeks->fetch_assoc()):
        $gw = intval($w['gameweek']);
        $selected = ($gw == $gameweek) ? 'selected' : '';
        $disabled = ($gw < $latest_gameweek) ? 'disabled' : '';
        echo "<option value='{$gw}' $selected $disabled>Gameweek {$gw}</option>";
      endwhile;
      ?>
    </select>
  </form>

  <div id="countdown" class="text-center text-lg font-bold text-yellow-300 mb-8"></div>

  <?php if ($result->num_rows === 0): ?>
    <p class="text-center text-gray-300">✔️ No matches for this gameweek.</p>

  <?php else: ?>
    <form id="predictionsForm" action="insert_prediction.php" method="POST" class="space-y-7">

      <?php while ($match = $result->fetch_assoc()): ?>

        <div class="text-xs text-gray-400 mb-1 text-center">
          <?= date('Y/m/d H:i', strtotime($match['match_date'])) ?>
        </div>

        <div class="match-card rounded-xl p-2 flex items-center justify-between gap-10">

          <div class="flex items-center gap-5 w-1/3 justify-end">
            <span class="text-xs text-gray-400">(A)</span>
            <span class="hidden md:flex font-bold text-lg"><?= htmlspecialchars($match['away_team']) ?></span>
            <img src="/PL_Teams/<?= htmlspecialchars($match['away_team_pic']) ?>" class="w-16 h-16 rounded shadow-md">
          </div>

          <div class="flex items-center justify-center gap-3 w-1/3">

            <input type="number" 
                   name="predicted_away[]" 
                   value="<?= $match['predicted_away'] ?>" 
                   class="w-14 input-pl rounded-lg text-center"
                   min="0" max="10"
                   <?= $match['predicted_away'] !== null ? 'readonly' : 'required' ?>>

            <span class="text-pink-400 font-bold text-xl">-</span>

            <input type="number" 
                   name="predicted_home[]" 
                   value="<?= $match['predicted_home'] ?>" 
                   class="w-14 input-pl rounded-lg text-center"
                   min="0" max="10"
                   <?= $match['predicted_home'] !== null ? 'readonly' : 'required' ?>>
          </div>

          <div class="flex items-center gap-5 w-1/3">
            <img src="/PL_Teams/<?= htmlspecialchars($match['home_team_pic']) ?>" class="w-16 h-16 rounded shadow-md">
            <span class="hidden md:flex font-bold text-lg"><?= htmlspecialchars($match['home_team']) ?></span>
            <span class="text-xs text-gray-400">(H)</span>
          </div>

          <input type="hidden" name="match_id[]" value="<?= $match['id'] ?>">

        </div>

      <?php endwhile; ?>

      <div class="text-center mt-10">
        <button type="submit" 
                class="bg-pink-500 hover:bg-pink-600 px-12 py-3 rounded-xl font-extrabold text-lg shadow-lg shadow-pink-500/40 transition">
          Submit Predictions
        </button>
      </div>

      <input type="hidden" name="user_id" value="<?= $user_id ?>">
    </form>

    <div class="text-center mt-10">
      <a href="other_matches.php"
         class="inline-block bg-yellow-500 hover:bg-yellow-600 text-black font-semibold px-5 py-2 rounded-xl shadow-lg transition">
         ⚽ See Other Leagues
      </a>
    </div>

    <div class="text-center mt-4">
      <a href="my_predictions.php" class="text-yellow-300 underline">Your Predictions</a>
    </div>

  <?php endif; ?>
</div>

<script>
const deadline = new Date("2025-12-13T15:00:00").getTime();
const x = setInterval(() => {
  const now = new Date().getTime();
  const distance = deadline - now;

  if (distance <= 0) {
    clearInterval(x);
    document.getElementById("countdown").innerHTML = "⏰ انتهى وقت التوقعات!";
    document.getElementById("predictionsForm").style.display = "none";
    return;
  }

  const d = Math.floor(distance / (1000 * 60 * 60 * 24));
  const h = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
  const m = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
  const s = Math.floor((distance % (1000 * 60)) / 1000);

  document.getElementById("countdown").innerHTML =
    `${d}d ${h}h ${m}m ${s}s left`;
}, 1000);
</script>

</body>
</html>
