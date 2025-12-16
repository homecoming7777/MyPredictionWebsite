<?php
session_start();
include 'connect.php';

$other_id = $_GET['user_id'] ?? null;
$selected_gw = $_GET['gameweek'] ?? null;

if (!$other_id) die("No user selected");

$user_stmt = $conn->prepare("SELECT username FROM users WHERE id=?");
$user_stmt->bind_param("i", $other_id);
$user_stmt->execute();
$username = $user_stmt->get_result()->fetch_assoc()['username'] ?? 'Unknown User';

$gw_result = $conn->query("SELECT DISTINCT gameweek FROM matches ORDER BY gameweek ASC");
$gameweeks = [];
while ($g = $gw_result->fetch_assoc()) $gameweeks[] = $g['gameweek'];
if (!$selected_gw) $selected_gw = count($gameweeks) ? end($gameweeks) : 1;

$query = $conn->prepare("
  SELECT m.home_team, m.away_team, m.match_date, 
         p.predicted_home, p.predicted_away, 
         m.home_score, m.away_score, 
         p.points,
         (CASE WHEN dg.match_id = m.id THEN 1 ELSE 0 END) AS is_double
  FROM matches m
  LEFT JOIN score_exact p ON m.id = p.match_id AND p.user_id = ?
  LEFT JOIN double_gameweek dg ON dg.user_id = ? AND dg.match_id = m.id
  WHERE m.gameweek = ?
  ORDER BY m.match_date ASC
");
$query->bind_param("iii", $other_id, $other_id, $selected_gw);
$query->execute();
$result = $query->get_result();

$total_stmt = $conn->prepare("
  SELECT SUM(
    CASE WHEN m.id = dg.match_id THEN p.points * 2 ELSE p.points END
  ) AS total_points
  FROM score_exact p
  JOIN matches m ON p.match_id = m.id
  LEFT JOIN double_gameweek dg ON dg.user_id = p.user_id AND dg.match_id = p.match_id
  WHERE p.user_id = ? AND m.gameweek = ?
");
$total_stmt->bind_param("ii", $other_id, $selected_gw);
$total_stmt->execute();
$total_points = $total_stmt->get_result()->fetch_assoc()['total_points'] ?? 0;

function render_points_badge($points, $is_double = false) {
    if ($points === null) {
        return '<span class="text-[var(--muted)] font-semibold tracking-wide">-</span>';
    }

    switch ((int)$points) {
        case 3:  $bg = 'bg-green-300 text-black shadow-[0_0_10px_rgba(0,255,0,0.45)]'; break;
        case 1:  $bg = 'bg-yellow-400 text-black shadow-[0_0_10px_rgba(255,255,0,0.35)]'; break;
        default: $bg = 'bg-red-500 text-white shadow-[0_0_12px_rgba(255,0,0,0.4)]'; break;
    }

    $value = $is_double ? $points * 2 : $points;

    return '
      <span class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full text-sm font-extrabold uppercase tracking-wide '.$bg.'">
        '.($is_double ? "⭐ $value" : $value).'
      </span>
    ';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($username) ?>’s Predictions</title>
<script src="https://cdn.tailwindcss.com"></script>

<style>
:root {
  --pl-dark:#050507;
  --pl-purple:#2b0030;
  --pl-pink:#e90052;
  --card:#110012;
  --muted:#c8bcd3;
}

body {
  background: var(--pl-dark);
  color: white;
  font-weight: 500;
}

.bg-card {
  background: var(--card);
  border: 1px solid rgba(233,0,82,0.15);
  box-shadow: 0 0 15px rgba(233,0,82,0.15);
}

.table-strong {
  border: 2px solid rgba(233,0,82,0.25);
  box-shadow: 0 0 18px rgba(233,0,82,0.2);
}

thead {
  letter-spacing: 1px;
  font-weight: 800;
  text-transform: uppercase;
  background: linear-gradient(90deg, var(--pl-purple), #4e0058);
}

select {
  font-weight: bold;
  letter-spacing: 0.5px;
}

.points-box {
  background: linear-gradient(90deg, var(--pl-pink), #ff1a74);
  box-shadow: 0 0 15px rgba(233,0,82,0.45);
}
</style>
</head>

<body class="p-6">

<div class="max-w-5xl mx-auto">

  <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between mb-8 gap-4">
    <h1 class="text-4xl font-extrabold text-[var(--pl-pink)] drop-shadow-[0_0_8px_rgba(233,0,82,0.55)]">
      <?= htmlspecialchars($username) ?>’s Predictions
    </h1>

    <form method="GET" class="flex gap-2 items-center">
      <input type="hidden" name="user_id" value="<?= $other_id ?>">
      <select name="gameweek" onchange="this.form.submit()"
        class="bg-card px-3 py-2 rounded-lg border border-[var(--pl-pink)]/40 shadow-md">
        <?php foreach ($gameweeks as $gw): ?>
          <option value="<?= $gw ?>" <?= ($gw == $selected_gw) ? 'selected' : '' ?>>
            Gameweek <?= $gw ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>

    <div class="points-box px-5 py-3 rounded-lg font-extrabold text-lg text-black">
      Points: <?= $total_points ?>
    </div>
  </div>

  <div class="overflow-x-auto rounded-lg table-strong mb-10">
    <table class="min-w-full text-center bg-card">
      <thead class="text-white text-sm">
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
      <?php if ($result->num_rows === 0): ?>
        <tr>
          <td colspan="6" class="p-6 text-[var(--muted)]">No predictions for this gameweek.</td>
        </tr>
      <?php else: ?>
        <?php while ($row = $result->fetch_assoc()): ?>
          <tr class="border-b border-[var(--pl-purple)]/40 hover:bg-[var(--pl-purple)]/20 transition">
            <td class="p-3 font-semibold"><?= htmlspecialchars($row['home_team']) ?> 
              <span class="text-[var(--pl-pink)] font-bold">vs</span> 
              <?= htmlspecialchars($row['away_team']) ?>
            </td>

            <td class="p-3 text-[var(--muted)]">
              <?= date('Y-m-d H:i', strtotime($row['match_date'])) ?>
            </td>

            <td class="p-3">
              <?= $row['predicted_home'] ?> - <?= $row['predicted_away'] ?>
            </td>

            <td class="p-3">
              <?= is_numeric($row['home_score']) 
                  ? "{$row['home_score']} - {$row['away_score']}" 
                  : "<span class='text-[var(--muted)]'>Not played</span>" ?>
            </td>

            <td class="p-3 font-bold">
              <?= render_points_badge($row['points'], $row['is_double']) ?>
            </td>

            <td class="p-3 text-yellow-400 text-xl font-bold">
              <?= $row['is_double'] ? '⭐' : '' ?>
            </td>
          </tr>
        <?php endwhile; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <a href="my_predictions.php?gameweek=<?= $selected_gw ?>"
    class="bg-[var(--pl-pink)] hover:bg-pink-600 text-black px-5 py-2 rounded-lg font-bold shadow-lg">
    ← Back
  </a>

</div>
</body>
</html>
