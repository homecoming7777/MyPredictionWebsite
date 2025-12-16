<?php
session_start();
include 'connect.php';

$result = $conn->query("
  SELECT u.id, u.username, u.favorite_team, t.logo, 
         COALESCE(SUM(w.points), 0) AS total_points
  FROM users u
  LEFT JOIN teams t ON u.favorite_team = t.name
  LEFT JOIN score_exact w ON u.id = w.user_id
  GROUP BY u.id, u.username, u.favorite_team, t.logo
  ORDER BY total_points DESC
");

$total_users = $conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];

$total_points = $conn->query("SELECT COALESCE(SUM(points),0) AS s FROM score_exact")->fetch_assoc()['s'];

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
    <title>Leaderboard</title>

<style>
:root{
  --pl-dark:#06060a;
  --pl-purple:#37003c;
  --pl-pink:#e90052;
  --card:#120014;
  --muted:#bfb7c6;
}

/* BOLD OPTION A STYLE */
body{
  background: 
    radial-gradient(1200px 500px at 8% 8%, rgba(233,0,82,0.06), transparent),
    radial-gradient(900px 400px at 92% 90%, rgba(55,0,60,0.08), transparent),
    linear-gradient(180deg,#0a0118 0%, #20002c 50%, #4b0037 100%);
  color: #eee9f5;
  font-family: 'Inter', sans-serif;
}

.card {
  background: linear-gradient(180deg, rgba(255,255,255,0.05), rgba(255,255,255,0.015));
  border: 1px solid rgba(255,200,255,0.07);
  backdrop-filter: blur(8px);
  box-shadow: 0 6px 20px rgba(0,0,0,0.55);
  border-radius: 1.2rem;
}

.glass-box {
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(255,255,255,0.08);
  backdrop-filter: blur(6px);
  box-shadow: 0 8px 25px rgba(0,0,0,0.4);
}

.table-row:hover { 
  background: rgba(255,255,255,0.06); 
  transition: 0.2s;
}

.card-header {
  color: var(--muted);
  font-size: 14px;
  font-weight: 600;
  letter-spacing: .5px;
}

.card-value {
  font-size: 30px;
  font-weight: 900;
  color: white;
  text-shadow: 0 0 10px rgba(233,0,82,0.5);
}
</style>
</head>

<body class="pb-10">

<nav class="w-full bg-black/40 backdrop-blur-lg text-white py-4 px-6 fixed top-0 left-0 flex justify-between items-center z-50 border-b border-white/10">

    <div class="flex items-center gap-2">
        <img src="/PL_img/PL_LOGO1.png" class="h-10 w-10" alt="">
        <h2 class="text-lg font-extrabold tracking-wide">Premier League</h2>
    </div>

    <button onclick="toggleMenu()" class="md:hidden text-white">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 6h16M4 12h16M4 18h16" />
        </svg>
    </button>

    <ul class="hidden md:flex gap-6 text-sm font-semibold">
        <li><a href="dashboard.php" class="hover:text-[var(--pl-pink)]">Dashboard</a></li>
        <li><a href="predictions.php" class="hover:text-[var(--pl-pink)]">Predictions</a></li>
        <li><a href="leaderboard.php" class="text-[var(--pl-pink)] font-bold">Leaderboard</a></li>
        <li><a href="my_predictions.php" class="hover:text-[var(--pl-pink)]">My Predictions</a></li>
    </ul>
</nav>

<div id="mobileMenu"
     class="hidden flex-col gap-4 bg-black/40 backdrop-blur-xl text-white py-6 px-6 fixed top-16 left-0 w-full z-40 border-b border-white/10 md:hidden">

    <a href="dashboard.php" class="hover:text-[var(--pl-pink)]">Dashboard</a><br><br>
    <a href="predictions.php" class="hover:text-[var(--pl-pink)]">Predictions</a><br><br>
    <a href="leaderboard.php" class="text-[var(--pl-pink)] font-bold">Leaderboard</a><br><br>
    <a href="my_predictions.php" class="hover:text-[var(--pl-pink)]">My Predictions</a>
</div>

<script>
function toggleMenu() {
  document.getElementById("mobileMenu").classList.toggle("hidden");
}
</script>

<div class="max-w-5xl mt-28 mx-auto grid grid-cols-1 sm:grid-cols-3 gap-6 px-4">

    <div class="card p-5 text-center">
      <h3 class="card-header">Total users</h3>
      <p class="card-value"><?= $total_users ?></p>
    </div>

    <div class="card p-5 text-center">
      <h3 class="card-header">Total points</h3>
      <p class="card-value"><?= $total_points ?></p>
    </div>

    <div class="card p-5 text-center">
      <h3 class="card-header">Best Predictor</h3>
      <p class="text-xl font-extrabold text-pink-400 drop-shadow-lg">
        <?= htmlspecialchars($top_user['username']) ?> (<?= (int)$top_user['total_points'] ?>)
      </p>
    </div>

</div>

<div class="max-w-5xl mx-auto mt-10 glass-box rounded-2xl p-8 shadow-xl px-6 sm:px-10">

    <h2 class="text-4xl font-extrabold text-center text-white mb-8 tracking-wide drop-shadow-lg">
      Leaderboard
    </h2>

    <div class="overflow-x-auto">
      <table class="w-full border-collapse min-w-[600px] text-white">
        <thead>
          <tr class="bg-white/10 text-gray-300 text-sm uppercase font-bold tracking-wide">
            <th class="p-3 text-center">#</th>
            <th class="p-3 text-center">User</th>
            <th class="p-3 text-center">Team</th>
            <th class="p-3 text-center">Points</th>
          </tr>
        </thead>

        <tbody>
        <?php $rank = 1; while($row = $result->fetch_assoc()): ?>
          <tr class="border-b border-white/10 table-row font-semibold">
            
            <td class="p-3 text-center">
              <?php if ($rank == 1): ?> 🥇
              <?php elseif ($rank == 2): ?> 🥈
              <?php elseif ($rank == 3): ?> 🥉
              <?php else: ?> <?= $rank ?>
              <?php endif; ?>
            </td>

            <td class="p-3 text-center text-white font-bold">
              <?= htmlspecialchars($row['username']) ?>
            </td>

            <td class="p-3 text-center flex items-center justify-center gap-2">
              <?php if ($row['logo']): ?>
                <img src="<?= htmlspecialchars($row['logo']) ?>" class="w-7 h-7 rounded-full border border-white/20">
              <?php endif; ?>
              <span class="text-gray-200 font-medium"><?= htmlspecialchars($row['favorite_team']) ?></span>
            </td>

            <td class="p-3 text-center font-extrabold text-pink-400 text-lg drop-shadow-md">
              <?= (int)$row['total_points'] ?>
            </td>

          </tr>
        <?php $rank++; endwhile; ?>
        </tbody>
      </table>
    </div>
</div>

</body>
</html>
