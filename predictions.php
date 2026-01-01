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
  <title>Predictions | Fantasy Premier League</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.0/css/all.min.css" />
</head>

<style>
:root {
  --fpl-blue: #37003c;
  --fpl-green: #00ff87;
  --fpl-light-blue: #1c9bef;
  --fpl-white: #ffffff;
  --fpl-dark-bg: #0a0222;
  --fpl-card-bg: #12092e;
  --fpl-border: #2a1a5e;
  --fpl-muted: #a0a0c0;
}

body {
  background: 
    linear-gradient(135deg, var(--fpl-dark-bg) 0%, #1a0b3a 30%, #2a1a5e 100%),
    radial-gradient(circle at 20% 80%, rgba(0, 255, 135, 0.08) 0%, transparent 40%),
    radial-gradient(circle at 80% 20%, rgba(28, 155, 239, 0.06) 0%, transparent 40%);
  color: var(--fpl-white);
  font-family: 'Montserrat', sans-serif;
  min-height: 100vh;
}

.card {
  background: linear-gradient(145deg, var(--fpl-card-bg) 0%, rgba(42, 26, 94, 0.8) 100%);
  border: 2px solid var(--fpl-border);
  border-radius: 16px;
  box-shadow: 
    0 10px 30px rgba(0, 0, 0, 0.4),
    0 0 20px rgba(0, 255, 135, 0.1),
    inset 0 1px 0 rgba(255, 255, 255, 0.1);
  backdrop-filter: blur(7px);
  position: relative;
  overflow: hidden;
}

.card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 4px;
  background: linear-gradient(90deg, var(--fpl-green), var(--fpl-light-blue));
  border-radius: 16px 16px 0 0;
}

.accent-border {
  border: 2px solid var(--fpl-border);
  box-shadow: 0 0 30px rgba(0, 255, 135, 0.15), inset 0 0 20px rgba(55, 0, 60, 0.1);
}

.input-pl {
  background: rgba(18, 9, 46, 0.8);
  border: 2px solid var(--fpl-green);
  color: var(--fpl-white);
  font-weight: 700;
  box-shadow: 0 0 12px rgba(0, 255, 135, 0.2);
  transition: all 0.3s ease;
}

.input-pl:focus {
  outline: none;
  box-shadow: 0 0 20px rgba(0, 255, 135, 0.35);
  border-color: var(--fpl-light-blue);
}

.input-pl:disabled {
  opacity: 0.6;
  cursor: not-allowed;
  border-color: var(--fpl-muted);
}

.match-card {
  background: linear-gradient(145deg, rgba(18, 9, 46, 0.7) 0%, rgba(42, 26, 94, 0.5) 100%);
  border: 2px solid var(--fpl-border);
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
  transition: all 0.3s ease;
  backdrop-filter: blur(6px);
}

.match-card:hover {
  transform: translateY(-4px);
  border-color: var(--fpl-green);
  box-shadow: 0 0 25px rgba(0, 255, 135, 0.25);
}

.text-glow {
  text-shadow: 0 0 12px rgba(0, 255, 135, 0.65);
}

.nav-gradient {
  background: linear-gradient(90deg, var(--fpl-blue) 0%, #2a0d5e 100%);
  border-bottom: 3px solid var(--fpl-green);
}

.points-box {
  background: linear-gradient(135deg, var(--fpl-green) 0%, var(--fpl-light-blue) 100%);
  box-shadow: 0 0 25px rgba(0, 255, 135, 0.4);
  border: 2px solid rgba(255, 255, 255, 0.1);
  color: #000;
  font-weight: 900;
}

.double-timer {
  background: linear-gradient(135deg, #ffd700 0%, #ffb400 100%);
  border: 2px solid #ffd700;
  color: #000;
  font-weight: 800;
  box-shadow: 0 0 20px rgba(255, 215, 0, 0.4);
}

.submit-btn {
  background: linear-gradient(135deg, var(--fpl-green) 0%, var(--fpl-light-blue) 100%);
  color: #000;
  font-weight: 900;
  padding: 1rem 2rem;
  border-radius: 12px;
  border: none;
  font-size: 1.1rem;
  cursor: pointer;
  transition: all 0.3s ease;
}

.submit-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 25px rgba(0, 255, 135, 0.4);
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}

.animate-fade-in {
  animation: fadeIn 0.6s ease-out;
}

::-webkit-scrollbar {
  width: 8px;
  height: 8px;
}

::-webkit-scrollbar-track {
  background: rgba(18, 9, 46, 0.5);
  border-radius: 5px;
}

::-webkit-scrollbar-thumb {
  background: linear-gradient(135deg, var(--fpl-green) 0%, var(--fpl-light-blue) 100%);
  border-radius: 5px;
}
</style>

<body class="min-h-screen">

<nav class="w-full nav-gradient text-white py-4 px-6 fixed top-0 left-0 flex justify-between items-center z-50 shadow-2xl">
    
    <div class="flex items-center gap-2">
        <div class="relative">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-[var(--fpl-green)] to-[var(--fpl-light-blue)] flex items-center justify-center shadow-lg">
                <span class="text-black font-extrabold text-base">FPL</span>
            </div>
            <div class="absolute -top-1 -right-1 w-4 h-4 bg-[var(--fpl-green)] rounded-full border-2 border-[var(--fpl-blue)]"></div>
        </div>
        <h2 class="text-lg font-black">score-exact Premier <span class="text-[var(--fpl-green)]">League</span></h2>
    </div>

    <button onclick="toggleMenu()" class="md:hidden text-white hover:text-[var(--fpl-green)] transition-colors">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                d="M4 6h16M4 12h16M4 18h16" />
        </svg>
    </button>

    <ul class="hidden md:flex gap-6 text-sm font-semibold">
        <li><a href="dashboard.php" class="hover:text-[var(--fpl-green)] transition-colors">Dashboard</a></li>
        <li><a href="predictions.php" class="text-[var(--fpl-green)] font-bold border-b-2 border-[var(--fpl-green)]">Predictions</a></li>
        <li><a href="leaderboard.php" class="hover:text-[var(--fpl-green)] transition-colors">Leaderboard</a></li>
        <li><a href="my_predictions.php" class="hover:text-[var(--fpl-green)] transition-colors">My Predictions</a></li>
    </ul>
</nav>

<div id="mobileMenu"
     class="hidden flex-col gap-4 text-white py-6 px-6 fixed top-16 left-0 w-full z-40 nav-gradient shadow-xl">

    <a href="dashboard.php" class="hover:text-[var(--fpl-green)] transition-colors font-semibold py-2">Dashboard</a><br><br>
    <a href="predictions.php" class="text-[var(--fpl-green)] font-bold py-2">Predictions</a><br><br>
    <a href="leaderboard.php" class="hover:text-[var(--fpl-green)] transition-colors font-semibold py-2">Leaderboard</a><br><br>
    <a href="my_predictions.php" class="hover:text-[var(--fpl-green)] transition-colors font-semibold py-2">My Predictions</a><br><br>
</div>

<script>
function toggleMenu() {
  document.getElementById("mobileMenu").classList.toggle("hidden");
}
</script>

<div class="h-20"></div>

<div class="w-full max-w-6xl mx-auto mt-10 mb-10 card animate-fade-in p-6 md:p-8">

  <h1 class="text-3xl md:text-4xl font-black text-center bg-gradient-to-r from-[var(--fpl-green)] to-[var(--fpl-light-blue)] bg-clip-text text-transparent drop-shadow-xl mb-4">
    Premier League Predictions
  </h1>

  <div class="flex justify-center mb-6">
    <div class="relative">
      <div class="w-16 h-16 md:w-20 md:h-20 rounded-full bg-gradient-to-br from-[var(--fpl-green)] to-[var(--fpl-light-blue)] flex items-center justify-center shadow-lg">
        <span class="text-black font-extrabold text-xl md:text-2xl">FPL</span>
      </div>
      <div class="absolute -top-1 -right-1 w-5 h-5 bg-[var(--fpl-green)] rounded-full border-2 border-[var(--fpl-blue)]"></div>
    </div>
  </div>

  <form method="GET" class="text-center mb-8">
    <label for="gameweek" class="font-semibold text-[var(--fpl-green)] text-lg">Select Gameweek</label><br>
    <select id="gameweek" name="gameweek" 
            class="mt-3 input-pl rounded-lg px-6 py-2 text-sm transition appearance-none pr-10 cursor-pointer"
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
    <div class="inline-block -ml-8 pointer-events-none">
      <i class="fa-solid fa-chevron-down text-[var(--fpl-green)]"></i>
    </div>
  </form>

  <div id="countdown" class="text-center text-lg font-bold bg-gradient-to-r from-[var(--fpl-green)] to-[var(--fpl-light-blue)] bg-clip-text text-transparent mb-8"></div>

  <?php if ($result->num_rows === 0): ?>
    <p class="text-center text-[var(--fpl-muted)] font-medium">✔️ No matches for this gameweek.</p>

  <?php else: ?>
    <form id="predictionsForm" action="insert_prediction.php" method="POST" class="space-y-7">

      <?php while ($match = $result->fetch_assoc()): ?>

        <div class="text-xs text-[var(--fpl-muted)] mb-1 text-center">
          <i class="fa-regular fa-calendar mr-1"></i><?= date('Y/m/d H:i', strtotime($match['match_date'])) ?>
        </div>

        <div class="match-card rounded-xl p-4 flex items-center justify-between gap-4 md:gap-10">

          <div class="flex items-center gap-3 md:gap-5 w-1/3 justify-end">
            <span class="text-xs text-[var(--fpl-muted)] hidden md:inline">(A)</span>
            <span class="font-bold text-sm md:text-lg text-right"><?= htmlspecialchars($match['away_team']) ?></span>
            <img src="/PL_Teams/<?= htmlspecialchars($match['away_team_pic']) ?>" class="w-12 h-12 md:w-16 md:h-16 rounded shadow-md border border-[var(--fpl-border)]">
          </div>

          <div class="flex items-center justify-center gap-3 w-1/3">

            <input type="number" 
                   name="predicted_away[]" 
                   value="<?= $match['predicted_away'] ?>" 
                   class="w-12 h-12 md:w-14 md:h-14 input-pl rounded-lg text-center text-lg font-bold"
                   min="0" max="10"
                   <?= $match['predicted_away'] !== null ? 'readonly' : 'required' ?>>

            <span class="text-[var(--fpl-green)] font-bold text-xl">-</span>

            <input type="number" 
                   name="predicted_home[]" 
                   value="<?= $match['predicted_home'] ?>" 
                   class="w-12 h-12 md:w-14 md:h-14 input-pl rounded-lg text-center text-lg font-bold"
                   min="0" max="10"
                   <?= $match['predicted_home'] !== null ? 'readonly' : 'required' ?>>
          </div>

          <div class="flex items-center gap-3 md:gap-5 w-1/3">
            <img src="/PL_Teams/<?= htmlspecialchars($match['home_team_pic']) ?>" class="w-12 h-12 md:w-16 md:h-16 rounded shadow-md border border-[var(--fpl-border)]">
            <span class="font-bold text-sm md:text-lg"><?= htmlspecialchars($match['home_team']) ?></span>
            <span class="text-xs text-[var(--fpl-muted)] hidden md:inline">(H)</span>
          </div>

          <input type="hidden" name="match_id[]" value="<?= $match['id'] ?>">

        </div>

      <?php endwhile; ?>

      <div class="text-center mt-10">
        <button type="submit" 
                class="submit-btn px-8 py-3 md:px-12 md:py-3">
          <i class="fa-solid fa-paper-plane mr-2"></i> Submit Predictions
        </button>
      </div>

      <input type="hidden" name="user_id" value="<?= $user_id ?>">
    </form>

    <div class="text-center mt-10">
      <a href="other_matches.php"
         class="inline-block bg-gradient-to-r from-yellow-500 to-yellow-600 hover:opacity-90 text-black font-bold px-5 py-2 rounded-xl shadow-lg transition-all transform hover:scale-105">
         <i class="fa-solid fa-futbol mr-2"></i> See Other Leagues
      </a>
    </div>

    <div class="text-center mt-4">
      <a href="my_predictions.php" class="text-[var(--fpl-green)] font-semibold hover:underline">
        <i class="fa-solid fa-eye mr-1"></i> View Your Predictions
      </a>
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
    document.getElementById("countdown").innerHTML = "⏰ Predictions time has ended!";
    const form = document.getElementById("predictionsForm");
    if (form) {
      form.style.display = "none";
    }
    return;
  }

  const d = Math.floor(distance / (1000 * 60 * 60 * 24));
  const h = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
  const m = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
  const s = Math.floor((distance % (1000 * 60)) / 1000);

  let timeString = "";
  if (d > 0) timeString += `${d}d `;
  if (h > 0 || d > 0) timeString += `${h}h `;
  timeString += `${m}m ${s}s`;
  
  document.getElementById("countdown").innerHTML = `⏳ Time remaining: ${timeString}`;
}, 1000);
</script>

</body>
</html>