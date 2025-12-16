<?php 
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$uid = (int)$_SESSION['user_id'];


function fetch_one($conn, $sql) {
    $r = $conn->query($sql);
    if (!$r) return null;
    $row = $r->fetch_row();
    return $row ? $row[0] : null;
}

$stmt = $conn->prepare("SELECT username, favorite_team, avatar FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $uid);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc() ?: ['username'=>'User','favorite_team'=>'—','avatar'=>'/PL_img/default-avatar.png'];
$stmt->close();


$first_gw = (int) fetch_one($conn, "SELECT MIN(gameweek) FROM matches WHERE home_score IS NOT NULL OR away_score IS NOT NULL");
$last_gw  = (int) fetch_one($conn, "SELECT MAX(gameweek) FROM matches");

$current_gw = $last_gw;
$prev_gw = max($first_gw, $current_gw - 1);

$gw_sql = "
  SELECT 
    m.gameweek AS gw,
    COALESCE(SUM(
      CASE WHEN dg.id IS NOT NULL THEN COALESCE(se.points,0)*2 ELSE COALESCE(se.points,0) END
    ),0) AS pts
  FROM score_exact se
  JOIN matches m ON se.match_id = m.id
  LEFT JOIN double_gameweek dg 
    ON dg.user_id = se.user_id AND dg.match_id = se.match_id
  WHERE se.user_id = $uid AND m.gameweek BETWEEN $first_gw AND $last_gw
  GROUP BY m.gameweek
  ORDER BY m.gameweek ASC
";
$res = $conn->query($gw_sql);
$gw_points = [];
while ($r = $res->fetch_assoc()) {
    $gw_points[intval($r['gw'])] = intval($r['pts']);
}

$gw_labels = [];
$gw_data = [];
for ($g = $first_gw; $g <= $last_gw; $g++) {
    $gw_labels[] = "GW ".$g;
    $gw_data[] = isset($gw_points[$g]) ? $gw_points[$g] : 0;
}
$chart_labels_js = json_encode($gw_labels);
$chart_data_js = json_encode($gw_data);


$current_points = (int) fetch_one($conn, "
    SELECT COALESCE(SUM(COALESCE(points,0)),0) FROM score_exact WHERE user_id = $uid
");


$prev_points = 0;
if ($prev_gw >= $first_gw) {
    $prev_points = (int) fetch_one($conn, "
        SELECT COALESCE(SUM(COALESCE(se.points,0)),0)
        FROM score_exact se
        JOIN matches m ON se.match_id = m.id
        WHERE se.user_id = $uid AND m.gameweek <= $prev_gw
    ");
}
$cur_points_by_gw = (int) fetch_one($conn, "
    SELECT COALESCE(SUM(COALESCE(se.points,0)),0)
    FROM score_exact se
    JOIN matches m ON se.match_id = m.id
    WHERE se.user_id = $uid AND m.gameweek <= $current_gw
");

$leader_sql = "
  SELECT u.id, u.username, COALESCE(SUM(COALESCE(se.points,0)),0) AS total_points
  FROM users u
  LEFT JOIN score_exact se ON se.user_id = u.id
  GROUP BY u.id, u.username
  ORDER BY total_points DESC, u.username ASC
  LIMIT 50
";
$leader_res = $conn->query($leader_sql);
$leaders = [];
$pos = 0;
while ($r = $leader_res->fetch_assoc()) {
    $pos++;
    $leaders[] = [
        'pos' => $pos,
        'id' => $r['id'],
        'username' => $r['username'],
        'points' => (int)$r['total_points']
    ];
}

$current_rank = null;
foreach ($leaders as $l) {
    if ((int)$l['id'] === $uid) { $current_rank = $l['pos']; break; }
}


if ($prev_gw >= $first_gw) {
    $prev_rank = (int) fetch_one($conn, "
      SELECT COUNT(*)+1 FROM (
        SELECT COALESCE(SUM(COALESCE(se.points,0)),0) AS pts
        FROM users u
        JOIN score_exact se ON se.user_id = u.id
        JOIN matches m ON se.match_id = m.id
        WHERE m.gameweek <= $prev_gw
        GROUP BY u.id
      ) t
      WHERE t.pts > (
        SELECT COALESCE(SUM(COALESCE(se.points,0)),0)
        FROM score_exact se
        JOIN matches m ON se.match_id = m.id
        WHERE se.user_id = $uid AND m.gameweek <= $prev_gw
      )
    ");
} else { $prev_rank = $current_rank; }

$rank_diff = $prev_rank - $current_rank;


$badge = 'bronze';
if ($current_rank <= 3) $badge = 'gold';
elseif ($current_rank <= 10) $badge = 'silver';

$recent_sql = "
  SELECT se.match_id, se.predicted_home, se.predicted_away, se.points AS db_points,
         m.home_team, m.away_team, m.home_score, m.away_score, m.match_date
  FROM score_exact se
  JOIN matches m ON se.match_id = m.id
  WHERE se.user_id = $uid AND m.home_score IS NOT NULL AND m.away_score IS NOT NULL
  ORDER BY m.match_date DESC
  LIMIT 10
";
$recent_res = $conn->query($recent_sql);
$recent = [];
while ($r = $recent_res->fetch_assoc()) {
    $recent[] = [
        'teams' => $r['home_team'].' vs '.$r['away_team'],
        'pred' => $r['predicted_home'].' - '.$r['predicted_away'],
        'result' => $r['home_score'].' - '.$r['away_score'],
        'points' => intval($r['db_points']),
        'date' => $r['match_date']
    ];
}

$leaders_display = array_slice($leaders, 0, 5);
?>

<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Dashboard — PL Predictor</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.0/css/all.min.css" />
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
:root{
  --pl-dark:#06060a;
  --pl-purple:#37003c;
  --pl-pink:#e90052;
  --card:#120014;
  --muted:#bfb7c6;
}

body{
  background: #050406;
  color: #eae8ee;
  font-family: Inter;
}

/* -------------------------------------------------------------
   🔥 OPTION A — BOLDER PL THEME
   Stronger borders, deeper shadows, more premium contrast
------------------------------------------------------------- */

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

.h1 { font-size: 1.85rem; font-weight: 800; }
.h2 { font-size: 1.2rem; font-weight: 700; }

.table-row:hover { 
  background: rgba(255,255,255,0.04); 
  transform: translateY(-3px); 
}

.avatar-ring {
  box-shadow: 0 0 22px rgba(233,0,82,0.4);
  border: 4px solid rgba(233,0,82,0.25);
}

.badge-glow {
  box-shadow: 0 0 22px rgba(233,0,82,0.45);
}
</style>
</head>
<body class="antialiased min-h-screen">


<nav class="w-full py-4 px-4 md:px-8 flex items-center justify-between">
  <div class="flex items-center gap-4">
    <img src="/PL_img/PL_LOGO1.png" alt="PL" class="h-14 lg:h-16 drop-shadow-md">
    <div>
      <div class="text-white font-extrabold text-lg">PL Predictor</div>
      <div class="text-[var(--muted)] text-xs">Dashboard</div>
    </div>
  </div>

  <div class="hidden md:flex items-center gap-6">
    <a href="predictions.php" class="text-sm font-semibold uppercase tracking-wide hover:text-[var(--pl-pink)]">Predictions</a>
    <a href="leaderboard.php" class="text-sm font-semibold uppercase tracking-wide hover:text-[var(--pl-pink)]">Leaderboard</a>
    <a href="my_predictions.php" class="text-sm font-semibold uppercase tracking-wide hover:text-[var(--pl-pink)]">My Predictions</a>
  </div>

  <div class="flex items-center gap-4">
    <a href="profile.php" class="hidden md:flex items-center gap-3">
      <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="avatar" class="w-10 h-10 rounded-full object-cover avatar-ring">
      <div class="text-xs text-[var(--muted)]"><?= htmlspecialchars($user['username']) ?></div>
    </a>

    <div class="md:hidden">
      <button id="mobile-toggle" class="p-2 rounded-md card">
        <i class="fa-solid fa-bars text-[var(--muted)]"></i>
      </button>
    </div>
  </div>
</nav>

<div id="mobile-menu" class="md:hidden px-4 pb-4">
  <div class="card p-3 rounded-lg">
    <a href="predictions.php" class="block py-2 font-medium hover:text-[var(--pl-pink)]">Predictions</a>
    <a href="leaderboard.php" class="block py-2 font-medium hover:text-[var(--pl-pink)]">Leaderboard</a>
    <a href="my_predictions.php" class="block py-2 font-medium hover:text-[var(--pl-pink)]">My Predictions</a>
  </div>
</div>

<main class="max-w-dashboard mx-auto p-5 md:p-8 space-y-6">

  <section class="card accent-border rounded-2xl p-6 flex flex-col lg:flex-row gap-6 items-center">
    <div class="flex items-center gap-4 flex-1">
      <div class="relative"><a href="profile.php">
        <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="avatar" class="rounded-full avatar-lg w-20 h-20 object-cover"></a>
        <div class="absolute -bottom-2 -right-2 rounded-full p-1 badge-glow" style="background: linear-gradient(90deg,var(--pl-pink),var(--pl-purple));">
          <div class="w-6 h-6 rounded-full bg-white/90 flex items-center justify-center text-xs font-bold text-black"><?= strtoupper(substr(htmlspecialchars($user['username']),0,1)) ?></div>
        </div>
      </div>
      <div>
        <div class="h1 text-white"><?= htmlspecialchars($user['username']) ?></div>
        <div class="text-sm text-[var(--muted)]">Favorite team: <span class="text-white font-semibold"><?= htmlspecialchars($user['favorite_team']) ?></span></div>
        <div class="mt-3 flex gap-2 items-center">
          <div class="px-3 py-1 rounded-full text-xs font-semibold bg-[var(--pl-pink)]/10 text-[var(--pl-pink)]">Badge: <?= strtoupper($badge) ?></div>
          <div class="px-3 py-1 rounded-full text-xs font-semibold bg-white/5 text-white">Rank #<?= $current_rank ?></div>
        </div>
      </div>
    </div>

    <div class="flex gap-6 items-center">
      <div class="text-center">
        <div class="text-[1.6rem] font-extrabold text-white"><?= $current_points ?></div>
        <div class="text-xs text-[var(--muted)]">Total Points</div>
      </div>
    </div>
  </section>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <div class="lg:col-span-2 space-y-6">

      <div class="card accent-border rounded-2xl p-6">
        <div class="flex items-center justify-between mb-4">
          <div>
            <div class="h2 text-white">Weekly Points <span class="text-[var(--muted)] text-sm ml-2">(Using doubles)</span></div>
            <div class="text-xs text-[var(--muted)]">Gameweeks <?= $first_gw ?> → <?= $last_gw ?></div>
          </div>
          <div class="text-sm text-[var(--muted)]">Tip: doubles are applied only on chart data</div>
        </div>

        <div class="w-full">
          <canvas id="weeklyChart" height="170"></canvas>
        </div>
      </div>

      <div class="card rounded-2xl p-6">
        <div class="flex items-center justify-between mb-3">
          <div class="h2 text-white">Recent Performance</div>
          <div class="text-xs text-[var(--muted)]">Finished matches only</div>
        </div>

        <div class="overflow-x-auto">
          <table class="w-full text-left">
            <thead class="text-[var(--muted)] text-xs border-b border-gray-700">
              <tr>
                <th class="py-3">Match</th>
                <th class="py-3">Prediction</th>
                <th class="py-3">Result</th>
                <th class="py-3">Points</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recent)): ?>
                <tr><td colspan="4" class="py-6 text-[var(--muted)]">No finished matches yet.</td></tr>
              <?php else: ?>
                <?php foreach ($recent as $r): ?>
                  <tr class="table-row border-b border-gray-800">
                    <td class="py-3"><div class="text-sm text-white font-medium"><?= htmlspecialchars($r['teams']) ?></div>
                      <div class="text-xs text-[var(--muted)]"><?= date('M j, Y', strtotime($r['date'])) ?></div>
                    </td>
                    <td class="py-3 text-[var(--muted)]"><?= htmlspecialchars($r['pred']) ?></td>
                    <td class="py-3 text-[var(--muted)]"><?= htmlspecialchars($r['result']) ?></td>
                    <td class="py-3 font-bold <?php
                          if ($r['points'] >= 3) echo 'text-green-400';
                          elseif ($r['points'] == 1) echo 'text-yellow-300';
                          else echo 'text-red-400';
                        ?>">
                      <?= $r['points'] ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>

    <aside class="space-y-6">

      <div class="card rounded-2xl p-4">
        <div class="flex items-center justify-between mb-3">
          <div class="text-sm font-semibold text-white">Top Players</div>
          <a href="leaderboard.php" class="text-xs text-[var(--muted)] hover:text-white">See all</a>
        </div>

        <ol class="space-y-3">
          <?php foreach ($leaders_display as $pl): ?>
            <li class="flex items-center justify-between">
              <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-full bg-white/3 flex items-center justify-center text-xs font-bold text-[var(--muted)]"><?= $pl['pos'] ?></div>
                <a href="profile.php?id=<?= $pl['id'] ?>" class="text-sm font-semibold hover:text-[var(--pl-pink)]"><?= htmlspecialchars($pl['username']) ?></a>
              </div>
              <div class="text-sm font-bold text-white"><?= $pl['points'] ?></div>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>

      <div class="rounded-2xl p-4" style="background: linear-gradient(180deg,#1b0716, #120014); border:1px solid rgba(233,0,82,0.08);">
        <div class="text-xs text-[var(--muted)]">Your Badge</div>
        <div class="mt-3 text-center">
          <?php if ($badge == 'gold'): ?>
            <div class="inline-block px-5 py-2 rounded-full text-black font-bold" style="background:linear-gradient(90deg,#ffd54a,#ffb400)">GOLD</div>
          <?php elseif ($badge == 'silver'): ?>
            <div class="inline-block px-5 py-2 rounded-full text-black font-bold" style="background:linear-gradient(90deg,#e6e7eb,#bdbec2)">SILVER</div>
          <?php else: ?>
            <div class="inline-block px-5 py-2 rounded-full text-black font-bold" style="background:linear-gradient(90deg,#d9a441,#b0730a)">BRONZE</div>
          <?php endif; ?>
        </div>
        <div class="text-sm text-[var(--muted)] mt-3 text-center">Rank #<?= $current_rank ?></div>
      </div>

      <div class="card rounded-2xl p-4">
        <div class="text-xs text-[var(--muted)]">Ranking Progress</div>
        <div class="mt-3">
          <div class="text-2xl font-extrabold text-white"><?= $current_points ?> pts</div>
          <div class="mt-3">
            <?php if ($rank_diff > 0): ?>
              <div class="text-sm text-green-400 font-semibold">▲ Gained <?= $rank_diff ?> places since prev GW</div>
            <?php elseif ($rank_diff < 0): ?>
              <div class="text-sm text-red-500 font-semibold">▼ Lost <?= abs($rank_diff) ?> places since prev GW</div>
            <?php else: ?>
              <div class="text-sm text-[var(--muted)] font-semibold">— No change</div>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </aside>
  </div>

</main>

<script>
  $('#mobile-toggle').on('click', function(){
    $('#mobile-menu').slideToggle(180);
  });

  const labels = <?= $chart_labels_js ?>;
  const data = <?= $chart_data_js ?>;
  const ctx = document.getElementById('weeklyChart').getContext('2d');

  const gradient = ctx.createLinearGradient(0, 0, 0, 300);
  gradient.addColorStop(0, 'rgba(233,0,82,0.95)');
  gradient.addColorStop(1, 'rgba(55,0,60,0.95)');

  const chart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: 'Points (chart uses doubles)',
        data: data,
        backgroundColor: gradient,
        borderRadius: 8,
        barThickness: 22
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      layout: { padding: { top: 6, bottom: 6 } },
      scales: {
        y: {
          beginAtZero: true,
          ticks: { color: '#dcd9e6', precision: 0 },
          grid: { color: 'rgba(255,255,255,0.03)' }
        },
        x: {
          ticks: { color: '#dcd9e6' },
          grid: { display: false }
        }
      },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#120014',
          titleColor: '#fff',
          bodyColor: '#fff',
          padding: 10
        }
      }
    }
  });

  $(function(){
    $('.table-row').css({opacity:0, transform:'translateY(6px)'}).each(function(i){
      $(this).delay(i*40).animate({opacity:1, transform:'translateY(0)'}, 350);
    });
  });
</script>

</body>
</html>

