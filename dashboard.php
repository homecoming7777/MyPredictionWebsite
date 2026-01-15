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
<title>Dashboard | Fantasy Premier League</title>

<script src="https://cdn.tailwindcss.com"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.0/css/all.min.css" />
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
  --fpl-muted: #a0a0c0;
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

.nav-gradient {
  background: linear-gradient(90deg, var(--fpl-blue) 0%, #2a0d5e 100%);
  border-bottom: 3px solid var(--fpl-green);
}

.avatar-ring {
  border: 3px solid transparent;
  background: linear-gradient(135deg, var(--fpl-green), var(--fpl-light-blue)) border-box;
  box-shadow: 0 0 20px rgba(0, 255, 135, 0.3);
}

.avatar-lg {
  border: 4px solid transparent;
  background: linear-gradient(135deg, var(--fpl-green), var(--fpl-light-blue)) border-box;
  box-shadow: 0 0 30px rgba(0, 255, 135, 0.4);
}

.badge-glow {
  box-shadow: 0 0 25px rgba(0, 255, 135, 0.5);
  background: linear-gradient(135deg, var(--fpl-green), var(--fpl-light-blue));
}

.rank-1 { 
  background: linear-gradient(135deg, #ffd700 0%, #ffed4e 100%);
  color: #000;
  font-weight: 800;
}
.rank-2 { 
  background: linear-gradient(135deg, #c0c0c0 0%, #e8e8e8 100%);
  color: #000;
  font-weight: 800;
}
.rank-3 { 
  background: linear-gradient(135deg, #cd7f32 0%, #e6a95c 100%);
  color: #000;
  font-weight: 800;
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

td {
  border-bottom: 1px solid rgba(42, 26, 94, 0.5);
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
}

::-webkit-scrollbar-track {
  background: rgba(18, 9, 46, 0.5);
  border-radius: 5px;
}

::-webkit-scrollbar-thumb {
  background: linear-gradient(135deg, var(--fpl-green) 0%, var(--fpl-light-blue) 100%);
  border-radius: 5px;
}

.h1 { 
  font-size: 2rem; 
  font-weight: 900;
  background: linear-gradient(135deg, var(--fpl-green) 0%, var(--fpl-light-blue) 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
}

.h2 { 
  font-size: 1.4rem; 
  font-weight: 800;
  color: white;
}

.card-value {
  font-size: 2.5rem;
  font-weight: 900;
  background: linear-gradient(135deg, var(--fpl-green) 0%, var(--fpl-light-blue) 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  text-shadow: 0 0 20px rgba(0, 255, 135, 0.3);
}
</style>
</head>
<body class="antialiased min-h-screen">


<nav class="w-full nav-gradient py-5 px-6 flex items-center justify-between fixed top-0 left-0 right-0 z-50 shadow-2xl">
  <div class="flex items-center gap-4">
    <div class="relative">
      <div class="w-14 h-14 rounded-full bg-gradient-to-br from-[var(--fpl-green)] to-[var(--fpl-light-blue)] flex items-center justify-center shadow-lg">
        <span class="text-black font-extrabold text-xl">us</span>
      </div>
      <div class="absolute -top-1 -right-1 w-6 h-6 bg-[var(--fpl-green)] rounded-full border-2 border-[var(--fpl-blue)]"></div>
    </div>
    <div>
      <div class="text-white font-black text-lg">score-exact Premier <span class="text-[var(--fpl-green)]">League</span></div>
      <div class="text-[var(--fpl-muted)] text-xs font-semibold">Manager Dashboard</div>
    </div>
  </div>

  <div class="hidden md:flex items-center gap-8">
    <a href="predictions.php" class="text-sm font-semibold uppercase tracking-wide hover:text-[var(--fpl-green)] transition-colors">Predictions</a>
    <a href="leaderboard.php" class="text-sm font-semibold uppercase tracking-wide hover:text-[var(--fpl-green)] transition-colors">Leaderboard</a>
    <a href="my_predictions.php" class="text-sm font-semibold uppercase tracking-wide hover:text-[var(--fpl-green)] transition-colors">My Predictions</a>
  </div>

  <div class="flex items-center gap-4">
    <a href="profile.php" class="hidden md:flex items-center gap-3">
      <div class="relative">
        <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="avatar" class="w-12 h-12 rounded-full object-cover avatar-ring">
      </div>
      <div>
        <div class="text-sm font-bold text-white"><?= htmlspecialchars($user['username']) ?></div>
        <div class="text-xs text-[var(--fpl-muted)]">Manager</div>
      </div>
    </a>

    <div class="md:hidden">
      <button id="mobile-toggle" class="p-3 rounded-lg bg-[var(--fpl-blue)]/50 hover:bg-[var(--fpl-blue)] transition-colors">
        <i class="fa-solid fa-bars text-white text-lg"></i>
      </button>
    </div>
  </div>
</nav>

<div id="mobile-menu" class="hidden md:hidden px-6 pb-6 pt-24 nav-gradient shadow-xl">
  <div class="glass-box p-6 rounded-xl">
    <a href="predictions.php" class="block py-3 font-semibold hover:text-[var(--fpl-green)] transition-colors border-b border-[var(--fpl-border)]">Predictions</a>
    <a href="leaderboard.php" class="block py-3 font-semibold hover:text-[var(--fpl-green)] transition-colors border-b border-[var(--fpl-border)]">Leaderboard</a>
    <a href="my_predictions.php" class="block py-3 font-semibold hover:text-[var(--fpl-green)] transition-colors">My Predictions</a>
  </div>
</div>

<main class="max-w-7xl mx-auto p-6 pt-32 space-y-8 animate-fade-in">

<div class="card rounded-2xl p-8 mt-8">
  <div class="flex items-center justify-between mb-6">
    <div>
      <div class="h2">Latest Updates & Features</div>
      <div class="text-[var(--fpl-muted)] font-medium mt-2">Discover what's new in our fantasy platform</div>
    </div>
    <div class="px-4 py-2 rounded-full bg-gradient-to-r from-[var(--fpl-green)]/20 to-[var(--fpl-light-blue)]/20 border border-[var(--fpl-green)]/30 text-sm font-bold">
      <i class="fa-solid fa-bullhorn mr-2"></i>NEW RELEASE
    </div>
  </div>

  <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <!-- Feature 2 -->
    <div class="feature-card bg-gradient-to-br from-[var(--fpl-card-bg)] to-[#1a0b3a] p-6 rounded-xl border border-[var(--fpl-border)] hover:border-[var(--fpl-green)]/50 transition-all duration-300">
      <div class="flex items-start gap-4">
        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-[#ff6b6b] to-[#ffa36c] flex items-center justify-center flex-shrink-0">
          <i class="fa-solid fa-trophy text-white text-lg"></i>
        </div>
        <div>
          <h3 class="text-white font-bold text-lg mb-2">Ships System</h3>
          <p class="text-[var(--fpl-muted)] text-sm mb-3">
            Experience the ultimate tactical advantage with our exclusive 3-ship formation system!
          </p>
          <div class="flex gap-2">
            <span class="px-3 py-1 bg-[var(--fpl-blue)]/30 rounded-full text-xs font-semibold text-[#ff6b6b]">RANKING</span>
            <span class="px-3 py-1 bg-[var(--fpl-blue)]/30 rounded-full text-xs font-semibold text-[var(--fpl-light-blue)]">REAL-TIME</span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-8 pt-6 border-t border-[var(--fpl-border)]">
    <div class="flex items-center justify-between">
      <div class="text-sm text-[var(--fpl-muted)]">
        <i class="fa-solid fa-clock mr-2"></i>Published: <?php echo date('F j, Y'); ?>
      </div>
      <button id="toggle-features" class="px-4 py-2 rounded-full bg-[var(--fpl-blue)]/50 border border-[var(--fpl-border)] text-sm font-semibold hover:bg-[var(--fpl-blue)]/70 transition-colors">
        <i class="fa-solid fa-chevron-down mr-2"></i>View All Updates
      </button>
    </div>
  </div>
</div>

<script>
  $(document).ready(function() {
    $('.feature-card').each(function(i) {
      $(this).css({
        opacity: 0,
        transform: 'translateY(20px)'
      });
      
      setTimeout(() => {
        $(this).animate({
          opacity: 1,
          transform: 'translateY(0)'
        }, 400 + (i * 100));
      }, 300);
    });

    $('#toggle-features').on('click', function() {
      const $icon = $(this).find('i');
      if ($icon.hasClass('fa-chevron-down')) {
        $icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
        $(this).html('<i class="fa-solid fa-chevron-up mr-2"></i>Show Less');
        alert('More features coming soon! Stay tuned for future updates.');
      } else {
        $icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
        $(this).html('<i class="fa-solid fa-chevron-down mr-2"></i>View All Updates');
      }
    });

    $('.feature-card').hover(
      function() {
        $(this).css({
          transform: 'translateY(-5px)',
          boxShadow: '0 15px 30px rgba(0, 0, 0, 0.3), 0 0 20px rgba(0, 255, 135, 0.1)'
        });
      },
      function() {
        $(this).css({
          transform: 'translateY(0)',
          boxShadow: 'none'
        });
      }
    );
  });
</script>
    
  <section class="card rounded-2xl p-8">
    <div class="flex flex-col lg:flex-row gap-8 items-center">
      <div class="flex items-center gap-6 flex-1">
        <div class="relative">
          <a href="profile.php" class="hidden sm:block md:block lg:block">
            <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="avatar" class="rounded-full avatar-lg w-24 h-24 object-cover">
          </a>
          <div class="absolute -bottom-2 -right-2 rounded-full p-1 badge-glow">
            <div class="w-8 h-8 rounded-full bg-black flex items-center justify-center text-sm font-black text-white">
              <?= strtoupper(substr(htmlspecialchars($user['username']),0,1)) ?>
            </div>
          </div>
        </div>
        <div>
          <div class="h1"><?= htmlspecialchars($user['username']) ?></div>
          <div class="text-[var(--fpl-muted)] font-medium mt-2">
            <i class="fa-solid fa-users mr-2"></i>Supports: <span class="text-white font-semibold"><?= htmlspecialchars($user['favorite_team']) ?></span>
          </div>
          <div class="mt-4 flex gap-3 items-center">
            <div class="px-4 py-2 rounded-full text-sm font-bold bg-gradient-to-r from-[var(--fpl-green)]/20 to-[var(--fpl-light-blue)]/20 border border-[var(--fpl-green)]/30 text-[var(--fpl-green)]">
              Badge: <?= strtoupper($badge) ?>
            </div>
            <div class="px-4 py-2 rounded-full text-sm font-bold bg-[var(--fpl-blue)]/50 border border-[var(--fpl-border)] text-white">
              Rank #<?= $current_rank ?>
            </div>
          </div>
        </div>
      </div>

      <div class="flex gap-8 items-center">
        <div class="text-center">
          <div class="card-value"><?= $current_points ?></div>
          <div class="text-[var(--fpl-muted)] text-sm font-semibold mt-2">Total Points</div>
        </div>
        <div class="hidden lg:block h-16 w-px bg-[var(--fpl-border)]"></div>
        <div class="text-center">
          <div class="text-2xl font-black text-white">GW <?= $current_gw ?></div>
          <div class="text-[var(--fpl-muted)] text-sm font-semibold mt-2">Current</div>
        </div>
      </div>
    </div>
  </section>

  <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

    <div class="lg:col-span-2 space-y-8">

      <div class="card rounded-2xl p-8">
        <div class="flex flex-col md:flex-row items-start md:items-center justify-between mb-6">
          <div>
            <div class="h2">Weekly Performance</div>
            <div class="text-[var(--fpl-muted)] font-medium mt-2">
              Gameweeks <?= $first_gw ?> → <?= $last_gw ?> • Doubles included
            </div>
          </div>
          <div class="mt-4 md:mt-0 px-4 py-2 rounded-full bg-[var(--fpl-blue)]/30 border border-[var(--fpl-border)] text-sm font-semibold">
            <i class="fa-solid fa-chart-line mr-2 text-[var(--fpl-green)]"></i>Live Chart
          </div>
        </div>

        <div class="w-full">
          <canvas id="weeklyChart" height="180"></canvas>
        </div>
      </div>

      <div class="card rounded-2xl p-8">
        <div class="flex items-center justify-between mb-6">
          <div>
            <div class="h2">Recent Predictions</div>
            <div class="text-[var(--fpl-muted)] font-medium">Last 10 completed matches</div>
          </div>
          <div class="text-sm text-[var(--fpl-muted)] font-semibold">
            <i class="fa-solid fa-calendar-check mr-2"></i>Finished only
          </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-[var(--fpl-border)]">
          <table class="w-full">
            <thead>
              <tr class="bg-gradient-to-r from-[var(--fpl-blue)] to-[#2a1a5e]">
                <th class="py-4 px-6 text-left font-bold text-[var(--fpl-green)]">Match</th>
                <th class="py-4 px-6 text-left font-bold text-[var(--fpl-green)]">Prediction</th>
                <th class="py-4 px-6 text-left font-bold text-[var(--fpl-green)]">Result</th>
                <th class="py-4 px-6 text-left font-bold text-[var(--fpl-green)]">Points</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recent)): ?>
                <tr>
                  <td colspan="4" class="py-8 text-center text-[var(--fpl-muted)]">
                    <i class="fa-solid fa-inbox text-2xl mb-2 block"></i>
                    No completed matches yet
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($recent as $r): ?>
                  <tr class="table-row border-b border-[var(--fpl-border)] last:border-b-0">
                    <td class="py-4 px-6">
                      <div class="font-semibold text-white"><?= htmlspecialchars($r['teams']) ?></div>
                      <div class="text-xs text-[var(--fpl-muted)] mt-1">
                        <i class="fa-regular fa-calendar mr-1"></i><?= date('M j, Y', strtotime($r['date'])) ?>
                      </div>
                    </td>
                    <td class="py-4 px-6">
                      <span class="px-3 py-1 rounded-full bg-[var(--fpl-blue)]/30 border border-[var(--fpl-border)] text-[var(--fpl-muted)] font-bold">
                        <?= htmlspecialchars($r['pred']) ?>
                      </span>
                    </td>
                    <td class="py-4 px-6">
                      <span class="px-3 py-1 rounded-full bg-[var(--fpl-card-bg)] border border-[var(--fpl-border)] text-white font-bold">
                        <?= htmlspecialchars($r['result']) ?>
                      </span>
                    </td>
                    <td class="py-4 px-6">
                      <span class="inline-flex items-center justify-center w-10 h-10 rounded-full font-black text-lg
                        <?php
                          if ($r['points'] >= 3) echo 'bg-green-500/20 text-green-400 border border-green-500/30';
                          elseif ($r['points'] == 1) echo 'bg-yellow-500/20 text-yellow-300 border border-yellow-500/30';
                          else echo 'bg-red-500/20 text-red-400 border border-red-500/30';
                        ?>">
                        <?= $r['points'] ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>

    <aside class="space-y-8">

      <div class="card rounded-2xl p-6">
        <div class="flex items-center justify-between mb-4">
          <div>
            <div class="h2 text-white">Top Managers</div>
            <div class="text-sm text-[var(--fpl-muted)]">Global Ranking</div>
          </div>
          <a href="leaderboard.php" class="text-xs font-bold text-[var(--fpl-green)] hover:text-[var(--fpl-light-blue)] transition-colors">
            View All <i class="fa-solid fa-arrow-right ml-1"></i>
          </a>
        </div>

        <ol class="space-y-4">
          <?php foreach ($leaders_display as $pl): ?>
            <li class="flex items-center justify-between p-3 rounded-lg hover:bg-[var(--fpl-blue)]/20 transition-colors">
              <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm
                  <?php if ($pl['pos'] == 1): ?> rank-1
                  <?php elseif ($pl['pos'] == 2): ?> rank-2
                  <?php elseif ($pl['pos'] == 3): ?> rank-3
                  <?php else: ?> bg-[var(--fpl-card-bg)] border border-[var(--fpl-border)] text-[var(--fpl-muted)]
                  <?php endif; ?>">
                  <?= $pl['pos'] ?>
                </div>
                <div>
                    <?= htmlspecialchars($pl['username']) ?>
                </div>
              </div>
              <div class="font-black text-lg bg-gradient-to-r from-[var(--fpl-green)] to-[var(--fpl-light-blue)] bg-clip-text text-transparent">
                <?= $pl['points'] ?>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      </div>

      <div class="card rounded-2xl p-6 text-center" style="background: linear-gradient(180deg, #1b0716, #12092e);">
        <div class="text-sm text-[var(--fpl-muted)] font-semibold uppercase tracking-wider mb-3">Your Achievement</div>
        <div class="my-4">
          <?php if ($badge == 'gold'): ?>
            <div class="inline-flex items-center gap-3 px-6 py-3 rounded-full font-black text-lg shadow-lg" 
                 style="background:linear-gradient(135deg,#ffd700,#ffb400)">
              <i class="fa-solid fa-trophy"></i>
              <span>GOLD BADGE</span>
            </div>
          <?php elseif ($badge == 'silver'): ?>
            <div class="inline-flex items-center gap-3 px-6 py-3 rounded-full font-black text-lg shadow-lg" 
                 style="background:linear-gradient(135deg,#e6e7eb,#bdbec2)">
              <i class="fa-solid fa-medal"></i>
              <span>SILVER BADGE</span>
            </div>
          <?php else: ?>
            <div class="inline-flex items-center gap-3 px-6 py-3 rounded-full font-black text-lg shadow-lg" 
                 style="background:linear-gradient(135deg,#d9a441,#b0730a)">
              <i class="fa-solid fa-award"></i>
              <span>BRONZE BADGE</span>
            </div>
          <?php endif; ?>
        </div>
        <div class="text-2xl font-black text-white mt-4">Rank #<?= $current_rank ?></div>
        <div class="text-[var(--fpl-muted)] text-sm mt-2">Out of <?= count($leaders) ?> managers</div>
      </div>

      <div class="card rounded-2xl p-6">
        <div class="text-sm text-[var(--fpl-muted)] font-semibold uppercase tracking-wider">Rank Progress</div>
        <div class="mt-4">
          <div class="flex items-baseline gap-2">
            <div class="card-value"><?= $current_points ?></div>
            <div class="text-[var(--fpl-muted)] font-medium">points</div>
          </div>
          <div class="mt-6">
            <?php if ($rank_diff > 0): ?>
              <div class="flex items-center gap-2 text-green-400 font-bold">
                <i class="fa-solid fa-arrow-up"></i>
                <span>Gained <?= $rank_diff ?> places since GW <?= $prev_gw ?></span>
              </div>
            <?php elseif ($rank_diff < 0): ?>
              <div class="flex items-center gap-2 text-red-400 font-bold">
                <i class="fa-solid fa-arrow-down"></i>
                <span>Lost <?= abs($rank_diff) ?> places since GW <?= $prev_gw ?></span>
              </div>
            <?php else: ?>
              <div class="flex items-center gap-2 text-[var(--fpl-muted)] font-bold">
                <i class="fa-solid fa-minus"></i>
                <span>No change in ranking</span>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </aside>
  </div>

</main>

<script>
  $('#mobile-toggle').on('click', function(){
    $('#mobile-menu').slideToggle(200);
  });

  const labels = <?= $chart_labels_js ?>;
  const data = <?= $chart_data_js ?>;
  const ctx = document.getElementById('weeklyChart').getContext('2d');

  const gradient = ctx.createLinearGradient(0, 0, 0, 300);
  gradient.addColorStop(0, 'rgba(0, 255, 135, 0.9)');
  gradient.addColorStop(0.5, 'rgba(28, 155, 239, 0.7)');
  gradient.addColorStop(1, 'rgba(55, 0, 60, 0.9)');

  const chart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: 'Points (with doubles)',
        data: data,
        backgroundColor: gradient,
        borderRadius: 10,
        borderWidth: 2,
        borderColor: 'rgba(255, 255, 255, 0.1)',
        barThickness: 28
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      layout: { 
        padding: { 
          top: 20, 
          bottom: 20,
          left: 10,
          right: 10
        } 
      },
      scales: {
        y: {
          beginAtZero: true,
          ticks: { 
            color: '#a0a0c0',
            font: { family: 'Montserrat', weight: 600 },
            padding: 10
          },
          grid: { 
            color: 'rgba(42, 26, 94, 0.5)',
            drawBorder: false
          },
          border: { display: false }
        },
        x: {
          ticks: { 
            color: '#a0a0c0',
            font: { family: 'Montserrat', weight: 600 },
            padding: 10
          },
          grid: { display: false },
          border: { display: false }
        }
      },
      plugins: {
        legend: { 
          display: false 
        },
        tooltip: {
          backgroundColor: 'rgba(18, 9, 46, 0.95)',
          titleColor: '#00ff87',
          bodyColor: '#ffffff',
          borderColor: '#2a1a5e',
          borderWidth: 2,
          padding: 12,
          titleFont: { family: 'Montserrat', weight: 'bold' },
          bodyFont: { family: 'Montserrat', weight: '600' },
          cornerRadius: 8
        }
      },
      animation: {
        duration: 1000,
        easing: 'easeOutQuart'
      }
    }
  });

  $(function(){
    $('.table-row').css({opacity:0, transform:'translateY(10px)'}).each(function(i){
      $(this).delay(i*60).animate({opacity:1, transform:'translateY(0)'}, 400);
    });
  });
</script>

</body>
</html>