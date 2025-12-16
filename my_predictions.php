<?php
session_start();
include 'connect.php';

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) die("You must be logged in.");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['double_match'], $_POST['gameweek'])) {
    $match_id = intval($_POST['double_match']);
    $gameweek = intval($_POST['gameweek']);
    
    if($match_id && $gameweek){
        $check = $conn->prepare("SELECT * FROM double_gameweek WHERE user_id=? AND gameweek=?");
        $check->bind_param("ii",$user_id,$gameweek);
        $check->execute();
        $res = $check->get_result();
        if($res->num_rows === 0){
            $stmt = $conn->prepare("INSERT INTO double_gameweek (user_id, match_id, gameweek) VALUES (?,?,?)");
            $stmt->bind_param("iii",$user_id,$match_id,$gameweek);
            $stmt->execute();
        }
    }
    header("Location: my_predictions.php?gameweek=$gameweek");
    exit;
}

$gw_result = $conn->query("SELECT DISTINCT gameweek FROM matches ORDER BY gameweek ASC");
$gameweeks = [];
while ($g = $gw_result->fetch_assoc()) $gameweeks[] = $g['gameweek'];
$selected_gw = $_GET['gameweek'] ?? (count($gameweeks) ? end($gameweeks) : 1);

$query = $conn->prepare("
    SELECT m.id AS match_id, m.home_team, m.away_team, m.match_date, m.home_score, m.away_score,
           p.predicted_home, p.predicted_away, p.points
    FROM score_exact p
    JOIN matches m ON p.match_id = m.id
    WHERE p.user_id = ? AND m.gameweek = ?
    ORDER BY m.match_date ASC
");
$query->bind_param("ii", $user_id, $selected_gw);
$query->execute();
$predictions = $query->get_result();

$double_stmt = $conn->prepare("SELECT match_id FROM double_gameweek WHERE user_id=? AND gameweek=?");
$double_stmt->bind_param("ii", $user_id, $selected_gw);
$double_stmt->execute();
$current_double = $double_stmt->get_result()->fetch_assoc()['match_id'] ?? null;

$total_stmt = $conn->prepare("
    SELECT SUM(CASE WHEN m.id = dg.match_id THEN p.points * 2 ELSE p.points END) AS total_points
    FROM score_exact p
    JOIN matches m ON p.match_id = m.id
    LEFT JOIN double_gameweek dg ON dg.user_id = p.user_id AND dg.match_id = p.match_id
    WHERE p.user_id = ? AND m.gameweek = ?
");
$total_stmt->bind_param("ii", $user_id, $selected_gw);
$total_stmt->execute();
$total_points = $total_stmt->get_result()->fetch_assoc()['total_points'] ?? 0;

$leaderboard = $conn->query("
SELECT u.id, u.username,
SUM(CASE WHEN m.id = dg.match_id THEN p.points * 2 ELSE p.points END) AS gw_points
FROM users u
LEFT JOIN score_exact p ON u.id = p.user_id
LEFT JOIN matches m ON p.match_id = m.id
LEFT JOIN double_gameweek dg ON dg.user_id = u.id AND dg.match_id = m.id
WHERE m.gameweek = $selected_gw
GROUP BY u.id
ORDER BY gw_points DESC, u.username ASC
LIMIT 15
");

function render_points_badge($points, $is_double = false) {
    if ($points === null) return '<span class="text-[var(--muted)] font-semibold">-</span>';

    switch ((int)$points) {
        case 3:  $bg='bg-green-300 text-black shadow-[0_0_10px_rgba(0,255,0,0.45)]'; break;
        case 1:  $bg='bg-yellow-400 text-black shadow-[0_0_10px_rgba(255,255,0,0.35)]'; break;
        default: $bg='bg-red-500 text-white shadow-[0_0_12px_rgba(255,0,0,0.4)]'; break;
    }

    $val = $is_double ? $points*2 : $points;

    return "
    <span class='inline-flex items-center gap-1 px-4 py-1.5 rounded-full text-sm font-extrabold uppercase tracking-wide $bg'>
        ".($is_double ? "⭐ $val" : $val)."
    </span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>My Predictions</title>
<script src="https://cdn.tailwindcss.com"></script>

<style>
:root {
  --pl-dark:#050507;
  --pl-purple:#2b0030;
  --pl-pink:#e90052;
  --card:#110012;
  --muted:#c8bcd3;
}
body { background: var(--pl-dark); color: white; font-weight: 500; }
.bg-card { background: var(--card); border: 1px solid rgba(233,0,82,0.15); box-shadow: 0 0 15px rgba(233,0,82,0.15); }
.table-strong { border: 2px solid rgba(233,0,82,0.25); box-shadow: 0 0 18px rgba(233,0,82,0.2); }
thead { letter-spacing: 1px; font-weight: 800; text-transform: uppercase; background: linear-gradient(90deg, var(--pl-purple), #4e0058); }
.points-box { background: linear-gradient(90deg, var(--pl-pink), #ff1a74); box-shadow: 0 0 15px rgba(233,0,82,0.45); }
</style>
</head>

<body class="p-6">

<nav class="w-full bg-black/30 backdrop-blur-md text-white py-4 px-6 fixed top-0 left-0 flex justify-between items-center z-50 border-b border-white/10">
    <div class="flex items-center gap-2">
        <img src="/PL_img/PL_LOGO1.png" class="h-10 w-10" alt="">
        <h2 class="text-lg font-bold">Premier League</h2>
    </div>
    <button onclick="toggleMenu()" class="md:hidden text-white focus:outline-none">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M4 6h16M4 12h16M4 18h16" />
        </svg>
    </button>
    <ul class="hidden md:flex gap-6 text-sm font-semibold">
        <li><a href="dashboard.php" class="hover:text-[var(--pl-pink)]">Dashboard</a></li>
        <li><a href="predictions.php" class="hover:text-[var(--pl-pink)]">Predictions</a></li>
        <li><a href="leaderboard.php" class="hover:text-[var(--pl-pink)]">Leaderboard</a></li>
        <li><a href="my_predictions.php" class="text-[var(--pl-pink)] font-bold">My Predictions</a></li>
    </ul>
</nav>

<div id="mobileMenu" class="hidden flex-col gap-4 bg-black/40 backdrop-blur-lg text-white py-5 px-6 fixed top-16 left-0 w-full z-40 border-b border-white/10 md:hidden">
    <a href="dashboard.php" class="hover:text-[var(--pl-pink)]">Dashboard</a>
    <a href="predictions.php" class="hover:text-[var(--pl-pink)]">Predictions</a>
    <a href="leaderboard.php" class="hover:text-[var(--pl-pink)]">Leaderboard</a>
    <a href="my_predictions.php" class="text-[var(--pl-pink)] font-bold">My Predictions</a>
</div>

<script>
function toggleMenu() { document.getElementById("mobileMenu").classList.toggle("hidden"); }
</script>

<div class="max-w-5xl mx-auto mt-24">
  <div class="flex flex-col sm:flex-row justify-between items-center mb-8 gap-4">
    <h1 class="text-4xl font-extrabold text-[var(--pl-pink)] drop-shadow-[0_0_8px_rgba(233,0,82,0.55)]">My Predictions</h1>
    <div class="flex items-center gap-4">
      <div class="points-box px-5 py-3 rounded-lg font-extrabold text-lg text-black">Points: <?= $total_points ?></div>
      <form method="GET">
        <select name="gameweek" onchange="this.form.submit()" class="bg-card px-3 py-2 rounded-lg border border-[var(--pl-pink)]/40 shadow-md">
          <?php foreach ($gameweeks as $gw): ?>
            <option value="<?= $gw ?>" <?= ($gw == $selected_gw) ? 'selected' : '' ?>>Gameweek <?= $gw ?></option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>
  </div>

  <form method="POST">
    <input type="hidden" name="gameweek" value="<?= $selected_gw ?>">
    <div class="overflow-x-auto rounded-lg table-strong mb-4">
      <table class="min-w-full text-center bg-card">
        <thead>
          <tr>
            <th class="p-3">Match</th>
            <th class="p-3">Date</th>
            <th class="p-3">Prediction</th>
            <th class="p-3">Result</th>
            <th class="p-3">Points</th>
            <th class="p-3">Double</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($predictions->num_rows === 0): ?>
            <tr><td colspan="6" class="p-6 text-[var(--muted)]">No predictions this gameweek.</td></tr>
          <?php else: ?>
            <?php while ($row = $predictions->fetch_assoc()): ?>
              <?php $is_double = ($row['match_id'] == $current_double); ?>
              <tr class="border-b border-[var(--pl-purple)]/40 hover:bg-[var(--pl-purple)]/20 transition">
                <td class="p-3 font-semibold"><?= $row['home_team'] ?> <span class="text-[var(--pl-pink)] font-bold">vs</span> <?= $row['away_team'] ?></td>
                <td class="p-3 text-[var(--muted)]"><?= date("Y-m-d H:i", strtotime($row['match_date'])) ?></td>
                <td class="p-3"><?= $row['predicted_home'] ?> - <?= $row['predicted_away'] ?></td>
                <td class="p-3"><?= is_numeric($row['home_score']) ? "{$row['home_score']} - {$row['away_score']}" : "<span class='text-[var(--muted)]'>Not played</span>" ?></td>
                <td class="p-3"><?= render_points_badge($row['points'], $is_double) ?></td>
                <td class="p-3 text-xl font-bold">
                  <?php if($is_double): ?>
                    ⭐
                  <?php else: ?>
                    <input type="radio" name="double_match" value="<?= $row['match_id'] ?>" <?= $current_double ? 'disabled' : '' ?>>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endwhile; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php if(!$current_double): ?>
    <div class="flex justify-end mb-10">
      <button type="submit" class="px-6 py-2 bg-yellow-400 font-bold text-black rounded-lg hover:bg-yellow-500 transition">
        Save Double
      </button>
    </div>
    <?php endif; ?>
  </form>

  
  <div class="overflow-x-auto rounded-lg table-strong mb-10">
    <table class="min-w-full text-center bg-card">
      <thead>
        <tr>
          <th class="p-3">Rank</th>
          <th class="p-3">User</th>
          <th class="p-3">Points</th>
        </tr>
      </thead>
      <tbody>
        <?php $rank=1; while ($r=$leaderboard->fetch_assoc()): ?>
        <tr class="border-b border-[var(--pl-purple)]/40 hover:bg-[var(--pl-purple)]/20 transition">
          <td class="p-3"><?= $rank++ ?></td>
          <td class="p-3"><!--
            <a href="other_user.php?user_id=<?= $r['id'] ?>&gameweek=<?= $selected_gw ?>" 
               class="text-[var(--pl-pink)] hover:underline">--><?= htmlspecialchars($r['username']) ?> <!--</a>-->
          </td>
          <td class="p-3 font-bold text-green-400"><?= $r['gw_points'] ?></td>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
  </div>

</div>
</body>
</html>
