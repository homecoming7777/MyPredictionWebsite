<?php 
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
$uid = (int)$_SESSION['user_id'];

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

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
    COALESCE(SUM(COALESCE(se.points,0)),0) AS pts
  FROM score_exact se
  JOIN matches m ON se.match_id = m.id
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
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Dashboard | Premier League</title>
<link rel="icon" type="image/jpg" href="PL_img/hadi.jpg">
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
:root {
    --pl-dark: #0a0015;
    --pl-purple: #16002b;
    --pl-pink: #ff0080;
    --pl-orange: #ff9900;
    --pl-yellow: #ffd700;
    --card: rgba(255,255,255,.05);
}

body {
    background-image: url('PL_img/current.jpg');
    background-size: cover;
    background-position: center;
    background-attachment: fixed;
    color: #f7f2fa;
    font-family: Arial, Helvetica, sans-serif;
    min-height: 100vh;
}

body::before {
    content: "";
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(10, 0, 21, 0.72); 
    z-index: -1;
    pointer-events: none;
}

.card {
    background: linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.02));
    border: 1px solid rgba(255,255,255,.10);
    backdrop-filter: blur(12px);
    box-shadow: 0 12px 35px rgba(0,0,0,.50);
}

.accent-border {
    border: 1px solid rgba(255,0,128,.40);
    box-shadow: 0 0 35px rgba(255,0,128,.15);
}

.table-row {
    transition: all .2s ease;
}
.table-row:hover {
    background: rgba(255,0,128,.07);
    transform: translateX(-2px);
}

.avatar-ring {
    border: 3px solid rgba(255,0,128,.55);
    box-shadow: 0 0 30px rgba(255,0,128,.25);
}

.badge-glow {
    box-shadow: 0 0 22px rgba(255,0,128,.35);
}

.text-glow {
    text-shadow: 0 0 18px rgba(255,215,0,.60);
}

.nav-link {
    transition: .2s ease;
}
.nav-link:hover {
    color: #ff0080;
}

.chart-wrapper {
    height: 200px;
}
@media(max-width:640px) {
    .chart-wrapper {
        height: 160px;
    }
}

.standings-wrap {
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 0 30px rgba(0,0,0,.3);
}

.btn-pink {
    background: linear-gradient(135deg, #ff0080, #e90052);
    color: #fff;
    box-shadow: 0 6px 20px rgba(233,0,82,.35);
}
.btn-pink:hover {
    box-shadow: 0 8px 25px rgba(233,0,82,.45);
    transform: translateY(-1px);
}

.text-pink-glow {
    text-shadow: 0 0 12px rgba(233,0,82,.6);
}
</style>

</head>

<body class="min-h-screen pb-16">
<a href="https://chat.whatsapp.com/LNHtFf9puEbLLbFOxL9cPb?s=cl&p=a&mlu=4" 
     target="_blank" 
     rel="noopener noreferrer"
     class="fixed right-0 top-1/2 -translate-y-1/2 z-50 bg-[#25D366] text-white rounded-l-full px-3 py-5 shadow-2xl hover:bg-[#1faf54] flex flex-col items-center gap-3 transition-all duration-300 hover:pr-5 group">
     
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" class="w-6 h-6">
      <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z"/>
    </svg>

    <span class="text-xs font-black uppercase tracking-widest [writing-mode:vertical-rl] rotate-180">
      Join Group
    </span>
  </a>
<nav class="fixed top-0 left-0 right-0 z-50 bg-black/60 backdrop-blur-xl border-b border-white/10 px-5 md:px-8 py-4 flex justify-between items-center">

    <a href="dashboard.php" class="flex items-center gap-3">
        <div class="w-11 h-11 rounded-full flex items-center justify-center overflow-hidden">
            <img src="PL_img/PL_LOGO1.png" class="w-full h-full object-contain" alt="Premier League">
        </div>
        <span class="font-black text-lg text-white">Premier League</span>
    </a>

    <div class="hidden md:flex items-center gap-7 text-sm font-bold">
        <a href="dashboard.php" class="text-pink-400 text-pink-glow">Dashboard</a>
        <a href="predictions.php" class="nav-link text-gray-300">Predictions</a>
        <a href="leaderboard.php" class="nav-link text-gray-300">Leaderboard</a>
        <a href="my_predictions.php" class="nav-link text-gray-300">My Predictions</a>
    </div>

    <div class="flex items-center gap-4">
        <a href="profile.php" class="hidden md:flex items-center gap-3">
            <img src="<?= e($user['avatar']) ?>" alt="avatar"
                 class="w-10 h-10 rounded-full object-cover avatar-ring">
            <span class="text-sm font-bold text-gray-300"><?= e($user['username']) ?></span>
        </a>

        <button onclick="toggleMenu()" class="md:hidden text-lg px-2 font-bold text-white">Menu</button>
    </div>

</nav>

<div id="mobileMenu" class="hidden fixed top-[73px] left-0 right-0 z-40 bg-black/90 backdrop-blur-xl border-b border-white/10 p-6">
    <div class="flex flex-col gap-5 font-bold">
        <a href="dashboard.php" class="text-pink-400">Dashboard</a>
        <a href="predictions.php">Predictions</a>
        <a href="leaderboard.php">Leaderboard</a>
        <a href="my_predictions.php">My Predictions</a>
    </div>
</div>

<script>
function toggleMenu() {
    document.getElementById('mobileMenu').classList.toggle('hidden');
}
</script>

<div class="h-24"></div>

<main class="max-w-7xl mx-auto px-4">

    <div class="flex flex-col md:flex-row justify-between items-center gap-6 mb-8">
        <div class="flex items-center gap-4">
            <div class="w-16 h-16 rounded-full p-2 flex items-center justify-center bg-white/5 border border-white/10">
                <img src="PL_img/PL_LOGO1.png" class="w-full h-full object-contain" alt="Premier League">
            </div>
            <div>
                <div class="text-pink-400 text-sm font-black uppercase tracking-widest text-pink-glow">Overview</div>
                <h1 class="text-3xl md:text-5xl font-black text-white">Dashboard</h1>
                <p class="text-gray-400 mt-1">Your Premier League prediction hub</p>
            </div>
        </div>

        <div class="flex gap-4">
            <div class="card rounded-2xl px-6 py-4 text-center">
                <div class="text-xs text-gray-500 font-black uppercase">Points</div>
                <div class="text-2xl font-black text-pink-400 text-pink-glow"><?= $current_points ?></div>
            </div>
            <div class="card rounded-2xl px-6 py-4 text-center">
                <div class="text-xs text-gray-500 font-black uppercase">Rank</div>
                <div class="text-2xl font-black text-yellow-300 text-glow">#<?= $current_rank ?? '—' ?></div>
            </div>
        </div>
    </div>

    <div class="card accent-border rounded-3xl p-6 md:p-8 mb-8">
        <div class="flex flex-col md:flex-row items-center gap-6">

            <div class="relative flex-shrink-0">
                <img src="<?= e($user['avatar']) ?>" alt="avatar"
                     class="w-24 h-24 md:w-28 md:h-28 rounded-full object-cover avatar-ring">
                <div class="absolute -bottom-1 -right-1 rounded-full p-1 badge-glow bg-pink-500">
                    <div class="w-7 h-7 rounded-full bg-black flex items-center justify-center text-xs font-black text-white">
                        <?= strtoupper(substr(e($user['username']), 0, 1)) ?>
                    </div>
                </div>
            </div>

            <div class="flex-1 text-center md:text-left">
                <h2 class="text-3xl font-black text-white"><?= e($user['username']) ?></h2>
                <p class="text-gray-400 mt-1">
                    Favorite team: <span class="text-yellow-300 font-bold"><?= e($user['favorite_team']) ?></span>
                </p>
                <div class="flex flex-wrap gap-3 mt-3 justify-center md:justify-start">
                    <span class="px-4 py-1.5 rounded-full text-xs font-black bg-pink-500/20 text-pink-400 border border-pink-500/30">
                        <?= strtoupper($badge) ?>
                    </span>
                    <span class="px-4 py-1.5 rounded-full text-xs font-black bg-white/10 text-white border border-white/10">
                        Rank #<?= $current_rank ?? '—' ?>
                    </span>
                </div>
            </div>

            <div class="flex-shrink-0 bg-gradient-to-r from-pink-500 to-orange-400 text-black px-8 py-4 rounded-2xl font-black text-center shadow-lg shadow-pink-500/20">
                <div class="text-3xl text-white"><?= $current_points ?></div>
                <div class="text-xs uppercase tracking-wider text-white/90">Total Points</div>
            </div>

        </div>
    </div>

    <div class="card rounded-3xl p-6 mb-8">
        <div class="flex items-center justify-center mb-4">
            <h3 class="text-xl font-black text-white">Premier League Standings</h3>
        </div>
        <div class="flex justify-center">
            <div class="w-full max-w-[768px] standings-wrap">
                <iframe id="sofa-standings-embed-1-96668" 
                        src="https://widgets.sofascore.com/en/embed/tournament/1/season/96668/standings/Premier%20League%2026%2F27?widgetTitle=Premier%20League%2026%2F27&showCompetitionLogo=true" 
                        style="height:1123px!important;width:100%!important; max-width:768px;" 
                        frameborder="0" scrolling="no">
                </iframe>
                <div style="font-size:12px;font-family:Arial,sans-serif;text-align:left;color:gray;padding:10px;">
                    Standings provided by <a target="_blank" href="https://www.sofascore.com/football/tournament/england/premier-league/17#id:96668" style="color:gray;text-decoration:underline;">Sofascore</a>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <div class="lg:col-span-2 space-y-6">

            <div class="card rounded-3xl p-6">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-xl font-black text-white">Weekly Points</h3>
                        <p class="text-xs text-gray-500 mt-1">Gameweeks <?= $first_gw ?> → <?= $last_gw ?></p>
                    </div>
                    <span class="text-xs text-gray-500">Double picks included</span>
                </div>
                <div class="chart-wrapper">
                    <canvas id="weeklyChart"></canvas>
                </div>
            </div>

            <div class="card rounded-3xl p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-black text-white">Recent Performance</h3>
                    <span class="text-xs text-gray-500">Finished matches only</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead class="text-gray-500 text-xs uppercase font-black tracking-wider border-b border-white/10">
                            <tr>
                                <th class="py-3">Match</th>
                                <th class="py-3">Prediction</th>
                                <th class="py-3">Result</th>
                                <th class="py-3 text-center">Points</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent)): ?>
                                <tr>
                                    <td colspan="4" class="py-6 text-gray-500 text-center">No finished matches yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recent as $r): ?>
                                    <tr class="table-row border-b border-white/5">
                                        <td class="py-3">
                                            <div class="font-bold text-sm text-white"><?= e($r['teams']) ?></div>
                                            <div class="text-xs text-gray-500"><?= date('M j, Y', strtotime($r['date'])) ?></div>
                                        </td>
                                        <td class="py-3 text-gray-400"><?= e($r['pred']) ?></td>
                                        <td class="py-3 text-gray-400"><?= e($r['result']) ?></td>
                                        <td class="py-3 text-center font-black
                                            <?php if ($r['points'] >= 3): ?>text-green-400
                                            <?php elseif ($r['points'] == 1): ?>text-yellow-300
                                            <?php else: ?>text-red-400<?php endif; ?>">
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

            <div class="card rounded-3xl p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-xl font-black text-white">Top Players</h3>
                    <a href="leaderboard.php" class="text-xs text-pink-400 hover:text-pink-300 font-black">See all</a>
                </div>

                <ol class="space-y-3">
                    <?php foreach ($leaders_display as $pl): ?>
                        <li class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <span class="w-8 h-8 rounded-full bg-white/5 flex items-center justify-center text-sm font-black text-gray-400">
                                    <?= $pl['pos'] ?>
                                </span>
                                <a href="profile.php?id=<?= $pl['id'] ?>" class="font-bold text-white hover:text-pink-400 transition">
                                    <?= e($pl['username']) ?>
                                </a>
                            </div>
                            <span class="font-black text-pink-400 text-pink-glow"><?= $pl['points'] ?></span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>

            <div class="card rounded-3xl p-6 text-center">
                <h3 class="text-sm text-gray-500 font-black uppercase tracking-wider">Your Badge</h3>
                <div class="mt-4">
                    <?php if ($badge == 'gold'): ?>
                        <div class="inline-block px-8 py-3 rounded-full font-black text-black text-lg"
                             style="background:linear-gradient(135deg,#ffd700,#ff8c00);box-shadow:0 0 25px rgba(255,215,0,.4);">GOLD</div>
                    <?php elseif ($badge == 'silver'): ?>
                        <div class="inline-block px-8 py-3 rounded-full font-black text-black text-lg"
                             style="background:linear-gradient(135deg,#f0f0f0,#b0b0b0);box-shadow:0 0 25px rgba(192,192,192,.4);">SILVER</div>
                    <?php else: ?>
                        <div class="inline-block px-8 py-3 rounded-full font-black text-black text-lg"
                             style="background:linear-gradient(135deg,#d9a441,#b0730a);box-shadow:0 0 25px rgba(184,115,51,.4);">BRONZE</div>
                    <?php endif; ?>
                </div>
                <div class="text-sm text-gray-400 mt-3">Rank #<?= $current_rank ?? '—' ?></div>
            </div>

            <div class="card rounded-3xl p-6">
                <h3 class="text-sm text-gray-500 font-black uppercase tracking-wider">Ranking Progress</h3>
                <div class="mt-4">
                    <div class="text-3xl font-black text-white text-glow"><?= $current_points ?> pts</div>
                    <div class="mt-3">
                        <?php if ($rank_diff > 0): ?>
                            <div class="text-green-400 font-black">Gained <?= $rank_diff ?> places</div>
                            <div class="text-xs text-gray-500">since previous gameweek</div>
                        <?php elseif ($rank_diff < 0): ?>
                            <div class="text-red-400 font-black">Lost <?= abs($rank_diff) ?> places</div>
                            <div class="text-xs text-gray-500">since previous gameweek</div>
                        <?php else: ?>
                            <div class="text-gray-400 font-black">No change</div>
                            <div class="text-xs text-gray-500">since previous gameweek</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </aside>

    </div>

    <div class="text-center text-gray-600 text-sm mt-10">
        Premier League Prediction Dashboard
        <br>
        Keep predicting. Keep climbing.
    </div>

</main>

<script>
    const labels = <?= $chart_labels_js ?>;
    const data = <?= $chart_data_js ?>;
    const ctx = document.getElementById('weeklyChart').getContext('2d');

    const gradient = ctx.createLinearGradient(0, 0, 0, 200);
    gradient.addColorStop(0, 'rgba(255,0,128,0.95)');
    gradient.addColorStop(0.6, 'rgba(255,102,0,0.85)');
    gradient.addColorStop(1, 'rgba(255,204,0,0.75)');

    const chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Points',
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