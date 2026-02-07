<?php
session_start();
include 'connect.php';

// Fetch users + total points
$result = $conn->query("
  SELECT u.id, u.username, u.favorite_team, t.logo, 
         COALESCE(SUM(w.points), 0) AS total_points
  FROM users u
  LEFT JOIN teams t ON u.favorite_team = t.name
  LEFT JOIN score_exact w ON u.id = w.user_id
  GROUP BY u.id, u.username, u.favorite_team, t.logo
  ORDER BY total_points DESC
");

$settings = $conn->query("SELECT * FROM global_settings WHERE id=1")->fetch_assoc();

/* GLOBAL LOCKS */
if (!$settings['site_enabled']) {
    die("<h2 style='color:red;text-align:center;margin-top:100px'>🚫 SITE DISABLED BY ADMIN</h2>");
}

if ($settings['maintenance_mode']) {
    die("<h2 style='color:orange;text-align:center;margin-top:100px'>🛠️ SYSTEM UNDER MAINTENANCE</h2>");
}

/* PAGE SYSTEMS */
$current_page = basename($_SERVER['PHP_SELF']);

if ($current_page == 'predictions.php' && !$settings['predictions_enabled']) {
    die("<h2 style='color:red;text-align:center;margin-top:100px'>⛔ PREDICTIONS CLOSED</h2>");
}

if ($current_page == 'other_matches.php' && !$settings['other_leagues_enabled']) {
    die("<h2 style='color:red;text-align:center;margin-top:100px'>⛔ OTHER LEAGUES DISABLED</h2>");
}

/* DOUBLE POINTS SYSTEM */
$DOUBLE_POINTS_ACTIVE = (bool)$settings['double_points_enabled'];

/* WHATSAPP SYSTEM */
$WHATSAPP_ACTIVE = (bool)$settings['whatsapp_enabled'];


// Total users
$total_users = $conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];

// Total points
$total_points = $conn->query("SELECT COALESCE(SUM(points),0) AS s FROM score_exact")->fetch_assoc()['s'];

// Top user
$top_user = $conn->query("
  SELECT u.username, COALESCE(SUM(w.points),0) AS total_points
  FROM users u
  LEFT JOIN score_exact w ON u.id = w.user_id
  GROUP BY u.id, u.username
  ORDER BY total_points DESC
  LIMIT 1
")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta charset="UTF-8">
    <title>Leaderboard | Fantasy Premier League</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

<style>
:root {
  --fpl-blue: #37003c;
  --fpl-green: #00ff87;
  --fpl-light-blue: #1c9bef;
  --fpl-white: #ffffff;
  --fpl-dark-bg: #0a0222;
  --fpl-card-bg: #12092e;
  --fpl-border: #2a1a5e;
}

body {
  background: 
    linear-gradient(135deg, #0a0222 0%, #1a0b3a 30%, #2a1a5e 100%),
    radial-gradient(circle at 20% 80%, rgba(0, 255, 135, 0.08) 0%, transparent 40%),
    radial-gradient(circle at 80% 20%, rgba(28, 155, 239, 0.06) 0%, transparent 40%);
  color: #ffffff;
  font-family: 'Montserrat', sans-serif;
  min-height: 100vh;
}

.card {
  background: linear-gradient(145deg, rgba(18, 9, 46, 0.95) 0%, rgba(42, 26, 94, 0.8) 100%);
  border: 2px solid var(--fpl-border);
  border-radius: 16px;
  box-shadow: 
    0 10px 30px rgba(0, 0, 0, 0.4),
    0 0 20px rgba(0, 255, 135, 0.1),
    inset 0 1px 0 rgba(255, 255, 255, 0.1);
  transition: all 0.3s ease;
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

.card:hover {
  transform: translateY(-5px);
  box-shadow: 
    0 15px 35px rgba(0, 0, 0, 0.5),
    0 0 30px rgba(0, 255, 135, 0.15),
    inset 0 1px 0 rgba(255, 255, 255, 0.1);
}

.glass-box {
  background: linear-gradient(135deg, rgba(18, 9, 46, 0.9) 0%, rgba(42, 26, 94, 0.85) 100%);
  border: 2px solid var(--fpl-border);
  backdrop-filter: blur(10px);
  border-radius: 20px;
  box-shadow: 
    0 20px 40px rgba(0, 0, 0, 0.5),
    0 0 40px rgba(55, 0, 60, 0.3);
}

.table-row:hover { 
  background: linear-gradient(90deg, rgba(0, 255, 135, 0.05) 0%, rgba(28, 155, 239, 0.05) 100%);
  transform: scale(1.01);
  transition: all 0.2s ease;
}

.card-header {
  color: #a0a0c0;
  font-size: 14px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 1px;
  margin-bottom: 8px;
}

.card-value {
  font-size: 36px;
  font-weight: 900;
  background: linear-gradient(135deg, var(--fpl-green) 0%, var(--fpl-light-blue) 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  text-shadow: 0 0 20px rgba(0, 255, 135, 0.3);
}

.rank-1 { background: linear-gradient(135deg, #ffd700 0%, #ffed4e 100%); color: #000; }
.rank-2 { background: linear-gradient(135deg, #c0c0c0 0%, #e8e8e8 100%); color: #000; }
.rank-3 { background: linear-gradient(135deg, #cd7f32 0%, #e6a95c 100%); color: #000; }

.nav-gradient {
  background: linear-gradient(90deg, var(--fpl-blue) 0%, #2a0d5e 100%);
  border-bottom: 3px solid var(--fpl-green);
}

table {
  border-collapse: separate;
  border-spacing: 0;
}

th {
  background: linear-gradient(90deg, var(--fpl-blue) 0%, #2a1a5e 100%);
  color: var(--fpl-green);
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1px;
  border: none;
}

th:first-child {
  border-radius: 12px 0 0 0;
}

th:last-child {
  border-radius: 0 12px 0 0;
}

td {
  border-bottom: 1px solid rgba(42, 26, 94, 0.5);
}

tr:last-child td {
  border-bottom: none;
}

#mobileMenu {
  background: linear-gradient(135deg, var(--fpl-blue) 0%, #2a0d5e 100%);
  border-bottom: 3px solid var(--fpl-green);
}

@keyframes fadeIn {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}

.animate-fade-in {
  animation: fadeIn 0.6s ease-out;
}

::-webkit-scrollbar {
  width: 10px;
}

::-webkit-scrollbar-track {
  background: rgba(18, 9, 46, 0.5);
  border-radius: 5px;
}

::-webkit-scrollbar-thumb {
  background: linear-gradient(135deg, var(--fpl-green) 0%, var(--fpl-light-blue) 100%);
  border-radius: 5px;
}

::-webkit-scrollbar-thumb:hover {
  background: linear-gradient(135deg, #00cc6a 0%, #1a8bdb 100%);
}
</style>
</head>

<body class="pb-10">

<nav class="w-full font-bold uppercase tracking-wide nav-gradient text-white py-5 px-6 fixed top-0 left-0 flex justify-between items-center z-50 shadow-2xl">
    
    <div class="flex items-center gap-3">
        <div class="relative">
            <div class="w-12 h-12 rounded-full bg-gradient-to-br from-[var(--fpl-green)] to-[var(--fpl-light-blue)] flex items-center justify-center shadow-lg">
                <span class="text-black font-extrabold text-xl">us</span>
            </div>
            <div class="absolute -top-1 -right-1 w-5 h-5 bg-[var(--fpl-green)] rounded-full border-2 border-[var(--fpl-blue)]"></div>
        </div>
        <h2 class="text-xl font-black tracking-wider">
            score-exact Premier <span class="text-[var(--fpl-green)]">League</span>
        </h2>
    </div>

    <button onclick="toggleMenu()" class="md:hidden text-white hover:text-[var(--fpl-green)] transition-colors">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-9 w-9" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                d="M4 6h16M4 12h16M4 18h16" />
        </svg>
    </button>

    <ul class="hidden md:flex gap-8 text-sm font-semibold">
        <li><a href="dashboard.php" class="hover:text-[var(--fpl-green)] transition-colors py-2 px-1 border-b-2 border-transparent hover:border-[var(--fpl-green)]">Dashboard</a></li>
        <li><a href="predictions.php" class="hover:text-[var(--fpl-green)] transition-colors py-2 px-1 border-b-2 border-transparent hover:border-[var(--fpl-green)]">Predictions</a></li>
        <li><a href="leaderboard.php" class="text-[var(--fpl-green)] font-bold py-2 px-1 border-b-2 border-[var(--fpl-green)]">Leaderboard</a></li>
        <li><a href="my_predictions.php" class="hover:text-[var(--fpl-green)] transition-colors py-2 px-1 border-b-2 border-transparent hover:border-[var(--fpl-green)]">My Predictions</a></li>
    </ul>
</nav>

<div id="mobileMenu"
     class="hidden flex-col gap-6 text-white py-8 px-8 fixed top-20 left-0 w-full z-40 nav-gradient shadow-xl">
    <a href="dashboard.php" class="hover:text-[var(--fpl-green)] transition-colors font-semibold text-lg py-2">Dashboard</a><br><br>
    <a href="predictions.php" class="hover:text-[var(--fpl-green)] transition-colors font-semibold text-lg py-2">Predictions</a><br><br>
    <a href="leaderboard.php" class="text-[var(--fpl-green)] font-bold text-lg py-2">Leaderboard</a><br><br>
    <a href="my_predictions.php" class="hover:text-[var(--fpl-green)] transition-colors font-semibold text-lg py-2">My Predictions</a><br><br>
</div>

<script>
function toggleMenu() {
  document.getElementById("mobileMenu").classList.toggle("hidden");
}
</script>

<div class="max-w-5xl mt-32 mx-auto grid grid-cols-1 sm:grid-cols-3 gap-8 px-6 animate-fade-in">

    <div class="card p-6 text-center">
      <h3 class="card-header">Total Managers</h3>
      <p class="card-value"><?= $total_users ?></p>
      <p class="text-sm text-gray-400 mt-2">Active players</p>
    </div>

    <div class="card p-6 text-center">
      <h3 class="card-header">Total Points</h3>
      <p class="card-value"><?= $total_points ?></p>
      <p class="text-sm text-gray-400 mt-2">Overall score</p>
    </div>

    <div class="card p-6 text-center">
      <h3 class="card-header">Top Manager</h3>
      <p class="text-2xl font-black bg-gradient-to-r from-[var(--fpl-green)] to-[var(--fpl-light-blue)] bg-clip-text text-transparent mt-2">
        <?= htmlspecialchars($top_user['username']) ?>
      </p>
      <p class="text-lg font-bold text-white mt-1">
        <?= (int)$top_user['total_points'] ?> points
      </p>
    </div>

</div>

<div class="max-w-5xl mx-auto mt-12 glass-box p-8 animate-fade-in" style="animation-delay: 0.2s;">

    <div class="text-center mb-10">
        <h2 class="text-4xl font-black bg-gradient-to-r from-[var(--fpl-green)] to-[var(--fpl-light-blue)] bg-clip-text text-transparent inline-block">
          Global Leaderboard
        </h2>
        <p class="text-gray-400 mt-2 font-medium">Season 2023/24 • Live Rankings</p>
    </div>

    <div class="overflow-x-auto rounded-xl border-2 border-[var(--fpl-border)] shadow-2xl">
      <table class="w-full min-w-[600px] text-white">
        <thead>
          <tr class="h-16">
            <th class="p-4 text-center font-black">Rank</th>
            <th class="p-4 text-center font-black">Manager</th>
            <th class="p-4 text-center font-black">Club</th>
            <th class="p-4 text-center font-black">Points</th>
          </tr>
        </thead>

        <tbody class="divide-y divide-[var(--fpl-border)]">
        <?php $rank = 1; while($row = $result->fetch_assoc()): ?>
          <tr class="h-16 transition-all duration-200 hover:bg-gradient-to-r hover:from-[var(--fpl-blue)]/20 hover:to-[#2a1a5e]/20">
            
            <td class="p-4 text-center font-bold">
              <div class="inline-flex items-center justify-center w-10 h-10 rounded-full 
                <?php if ($rank == 1): ?> rank-1 shadow-lg
                <?php elseif ($rank == 2): ?> rank-2 shadow-md
                <?php elseif ($rank == 3): ?> rank-3 shadow-md
                <?php else: ?> bg-[var(--fpl-card-bg)] border border-[var(--fpl-border)]
                <?php endif; ?>">
                <?= $rank ?>
              </div>
            </td>

            <td class="p-4 text-center font-bold text-lg">
              <?= htmlspecialchars($row['username']) ?>
            </td>

            <td class="p-4 text-center">
              <div class="flex items-center justify-center gap-3">
                <?php if ($row['logo']): ?>
                  <div class="relative">
                    <img src="<?= htmlspecialchars($row['logo']) ?>" 
                         class="w-10 h-10 rounded-full border-2 border-white/20 shadow-md">
                  </div>
                <?php endif; ?>
                <span class="text-gray-200 font-semibold"><?= htmlspecialchars($row['favorite_team']) ?></span>
              </div>
            </td>

            <td class="p-4 text-center">
              <span class="inline-block px-4 py-2 rounded-full bg-gradient-to-r from-[var(--fpl-green)]/20 to-[var(--fpl-light-blue)]/20 border border-[var(--fpl-green)]/30 font-black text-lg text-[var(--fpl-green)] min-w-[80px]">
                <?= (int)$row['total_points'] ?>
              </span>
            </td>

          </tr>
        <?php $rank++; endwhile; ?>
        </tbody>
      </table>
    </div>
    
    <div class="mt-8 text-center text-gray-400 text-sm font-medium">
        <p>Last updated: <?= date('H:i') ?> • Updates automatically</p>
    </div>
</div>

</body>
</html>