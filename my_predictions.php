<?php
session_start();
include 'connect.php';

/* ===== ADMIN FEATURE FLAG ===== */
$settings = $conn->query("SELECT * FROM global_settings WHERE id=1")->fetch_assoc();
$DOUBLE_POINTS_ACTIVE = (bool)$settings['double_points_enabled'];

/* SECURITY: block hacked requests */
if(!$DOUBLE_POINTS_ACTIVE){
    unset($_POST['double_match']);
}


$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) die("You must be logged in.");




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


if ($_SERVER['REQUEST_METHOD'] === 'POST'  && isset($_POST['double_match'], $_POST['gameweek'])) {
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

$triple_match_id = null;
$tm = $conn->prepare("SELECT match_id FROM triple_match WHERE user_id=?");
$tm->bind_param("i", $user_id);
$tm->execute();
$triple_match_id = $tm->get_result()->fetch_assoc()['match_id'] ?? null;


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

$card_status = [
  'double_all_used' => 0,
  'triple_match_used' => 0,
  'best_gw_used' => 0
];

$card_q = $conn->prepare("
  SELECT double_all_used, triple_match_used, best_gw_used
  FROM user_cards WHERE user_id = ?
");
$card_q->bind_param("i", $user_id);
$card_q->execute();

$res = $card_q->get_result();
if ($res->num_rows) {
    $card_status = $res->fetch_assoc();
}


function render_points_badge($points, $is_double = false) {
    if ($points === null) return '<span class="text-[var(--fpl-muted)] font-semibold">-</span>';

    switch ((int)$points) {
        case 3:  
            $bg='bg-green-500/90 text-white shadow-[0_0_15px_rgba(0,255,135,0.5)] border border-green-400/50'; 
            break;
        case 1:  
            $bg='bg-yellow-500/90 text-white shadow-[0_0_15px_rgba(255,215,0,0.5)] border border-yellow-400/50'; 
            break;
        default: 
            $bg='bg-red-500/90 text-white shadow-[0_0_15px_rgba(239,68,68,0.5)] border border-red-400/50'; 
            break;
    }

    $val = $is_double ? $points*2 : $points;

    return "
    <span class='inline-flex items-center justify-center px-3 py-1.5 rounded-full font-bold text-sm $bg'>
        ".($is_double ? "⭐ $val" : $val)."
    </span>";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>My Predictions | Fantasy Premier League</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.0/css/all.min.css" />

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
  overflow-x: hidden;
}

.card {
  background: linear-gradient(145deg, rgba(18, 9, 46, 0.95) 0%, rgba(42, 26, 94, 0.8) 100%);
  border: 2px solid var(--fpl-border);
  border-radius: 16px;
  box-shadow: 
    0 10px 30px rgba(0, 0, 0, 0.4),
    0 0 20px rgba(0, 255, 135, 0.1),
    inset 0 1px 0 rgba(255, 255, 255, 0.1);
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

input[type="radio"] {
  appearance: none;
  width: 20px;
  height: 20px;
  border: 2px solid var(--fpl-border);
  border-radius: 50%;
  background: var(--fpl-card-bg);
  cursor: pointer;
  transition: all 0.2s ease;
  position: relative;
}

input[type="radio"]:checked {
  background: var(--fpl-green);
  border-color: var(--fpl-green);
  box-shadow: 0 0 15px rgba(0, 255, 135, 0.6);
}

input[type="radio"]:checked::after {
  content: '✓';
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  color: #000;
  font-weight: 900;
  font-size: 12px;
}

input[type="radio"]:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.table-container {
  border: 2px solid var(--fpl-border);
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
  margin: 0 -0.5rem;
  padding: 0 0.5rem;
}

.table-wrapper {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  padding-bottom: 0.5rem;
}

.table-wrapper table {
  width: 100%;
  min-width: 800px;
  border-collapse: separate;
  border-spacing: 0;
}

.table-wrapper thead {
  background: linear-gradient(90deg, var(--fpl-blue) 0%, #2a1a5e 100%);
}

.table-wrapper th {
  color: var(--fpl-green);
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  font-size: 0.75rem;
  white-space: nowrap;
  padding: 0.75rem 0.5rem;
  text-align: center;
  border-bottom: 2px solid var(--fpl-border);
}

.table-wrapper td {
  padding: 0.75rem 0.5rem;
  text-align: center;
  vertical-align: middle;
  border-bottom: 1px solid rgba(42, 26, 94, 0.3);
  font-size: 0.875rem;
}

.table-wrapper tbody tr:hover {
  background: rgba(0, 255, 135, 0.05);
}

.compact-cell {
  white-space: nowrap;
  padding: 0.5rem !important;
}

.match-cell {
  min-width: 150px;
}

.date-cell {
  min-width: 120px;
}

.prediction-cell, .result-cell {
  min-width: 100px;
}

.points-cell {
  min-width: 90px;
}

.double-cell {
  min-width: 80px;
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

@media (max-width: 640px) {
  .table-wrapper th,
  .table-wrapper td {
    font-size: 0.75rem;
    padding: 0.5rem 0.25rem !important;
  }
  
  .table-wrapper table {
    min-width: 700px;
  }
  
  h1 {
    font-size: 1.5rem !important;
  }
  
  .points-box, .double-timer {
    font-size: 0.8rem !important;
    padding: 0.5rem !important;
  }
}
</style>
</head>

<body class="p-0">

<nav class="w-full nav-gradient text-white py-4 px-4 mb-20 fixed top-0 left-0 flex justify-between items-center z-50 shadow-2xl">
    <div class="flex items-center gap-2">
        <div class="relative">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-[var(--fpl-green)] to-[var(--fpl-light-blue)] flex items-center justify-center shadow-lg">
                <span class="text-black font-extrabold text-base">us</span>
            </div>
            <div class="absolute -top-1 -right-1 w-4 h-4 bg-[var(--fpl-green)] rounded-full border-2 border-[var(--fpl-blue)]"></div>
        </div>
        <h2 class="text-base font-black">score-exact Premier <span class="text-[var(--fpl-green)]">League</span></h2>
    </div>
    
    <button onclick="toggleMenu()" class="lg:hidden text-white hover:text-[var(--fpl-green)] transition-colors">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                d="M4 6h16M4 12h16M4 18h16" />
        </svg>
    </button>
    
    <ul class="hidden lg:flex gap-4 text-sm font-semibold">
        <li><a href="dashboard.php" class="hover:text-[var(--fpl-green)] transition-colors">Dashboard</a></li>
        <li><a href="predictions.php" class="hover:text-[var(--fpl-green)] transition-colors">Predictions</a></li>
        <li><a href="leaderboard.php" class="hover:text-[var(--fpl-green)] transition-colors">Leaderboard</a></li>
        <li><a href="my_predictions.php" class="text-[var(--fpl-green)] font-bold border-b-2 border-[var(--fpl-green)]">My Predictions</a></li>
    </ul>
</nav>

<div id="mobileMenu" class="hidden flex-col gap-4 text-white py-6 px-6 fixed top-16 left-0 w-full z-40 nav-gradient shadow-xl">
    <a href="dashboard.php" class="hover:text-[var(--fpl-green)] transition-colors font-semibold py-2">Dashboard</a><br><br>
    <a href="predictions.php" class="hover:text-[var(--fpl-green)] transition-colors font-semibold py-2">Predictions</a><br><br>
    <a href="leaderboard.php" class="hover:text-[var(--fpl-green)] transition-colors font-semibold py-2">Leaderboard</a><br><br>
    <a href="my_predictions.php" class="text-[var(--fpl-green)] font-bold py-2">My Predictions</a><br><br>
</div>
    

<div class="max-w-6xl lg:pt-40 mx-auto p-3 md:p-6 pt-44 animate-fade-in">

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">

  <!--<div class="card p-5 text-center" id="double_all_card">
    <h3 class="font-black text-lg mb-2">🔥 Double All Points</h3>
    <p class="text-sm text-[var(--fpl-muted)] mb-3">Once ever</p>
    <p class="text-sm text-[var(--fpl-muted)] mb-3">this ship makes all your match points doubled</p>
    <button id="double_all_btn"
      class="w-full py-2 rounded-lg font-bold bg-gradient-to-r from-[var(--fpl-green)] to-[var(--fpl-light-blue)] text-black">
      Activate
    </button>
    <div id="double_all_deadline" class="text-red-400 font-bold mt-2"></div>
  </div>

  <div class="card p-5 text-center" id="triple_match_card">
    <h3 class="font-black text-lg mb-2">⭐⭐⭐ Triple One Match</h3>
    <p class="text-sm text-[var(--fpl-muted)] mb-3">Once ever</p>
    <p class="text-sm text-[var(--fpl-muted)] mb-3">this ship make the match you select tripled</p>
    <button id="triple_match_btn"
      class="w-full py-2 rounded-lg font-bold bg-gradient-to-r from-yellow-400 to-orange-500 text-black">
      Activate
    </button>
    <div id="triple_match_deadline" class="text-red-400 font-bold mt-2"></div>
  </div>

  <div class="card p-5 text-center" id="best_gw_card">
    <h3 class="font-black text-lg mb-2">🛡 Best of Two GWs</h3>
    <p class="text-sm text-[var(--fpl-muted)] mb-3">Once ever</p>
    <p class="text-sm text-[var(--fpl-muted)] mb-3">this ship gives you the highest points between the last and the current gameweek</p>
    <button id="best_gw_btn"
      class="w-full py-2 rounded-lg font-bold bg-gradient-to-r from-purple-400 to-purple-600 text-black">
      Activate
    </button>
    <div id="best_gw_deadline" class="text-red-400 font-bold mt-2"></div>
  </div>-->

</div>

<script>
  const deadline = new Date('2026-01-17T13:00:00');

  const cards = [
    { btn: 'double_all_btn', msg: 'double_all_deadline' },
    { btn: 'triple_match_btn', msg: 'triple_match_deadline' },
    { btn: 'best_gw_btn', msg: 'best_gw_deadline' },
  ];

  function checkDeadline() {
    const now = new Date();
    const passed = now > deadline;

    cards.forEach(card => {
      const btn = document.getElementById(card.btn);
      const msg = document.getElementById(card.msg);

      if (passed) {
        btn.disabled = true;
        btn.classList.add('opacity-50', 'cursor-not-allowed');
        msg.textContent = 'Deadline passed';
      } else {
        msg.textContent = `Deadline: ${deadline.toLocaleString()}`;
      }
    });
  }

  checkDeadline();

  setInterval(checkDeadline, 60000);
</script>

</div>

    
  <div class="flex flex-col md:flex-row justify-between items-center mb-6 gap-3">
    <div class="text-center md:text-left">
      <h1 class="text-2xl md:text-4xl font-black bg-gradient-to-r from-[var(--fpl-green)] to-[var(--fpl-light-blue)] bg-clip-text text-transparent drop-shadow-lg">
        My Predictions
      </h1>
      <p class="text-[var(--fpl-muted)] font-medium mt-1 text-sm">
        <i class="fa-solid fa-calendar-week mr-1"></i>Gameweek <?= $selected_gw ?> • Season 2025/26
      </p>
    </div>
    
    <div class="flex flex-col sm:flex-row items-center gap-2 w-full sm:w-auto">
      <div id="doubleTimer" class="double-timer px-3 py-2 rounded-lg font-extrabold text-xs sm:text-sm flex items-center justify-center gap-1 w-full sm:w-auto">
        <i class="fa-solid fa-clock text-xs"></i>
        <span class="hidden sm:inline">Double: </span><span id="countdown" class="font-mono">00:00:00</span>
      </div>
      
      <div class="points-box px-3 py-2 rounded-lg font-black text-sm flex items-center justify-center gap-1 w-full sm:w-auto">
        <i class="fa-solid fa-star text-xs"></i>
        <span>Total: <?= $total_points ?> pts</span>
      </div>
      
      <form method="GET" class="relative w-full sm:w-auto">
        <select name="gameweek" onchange="this.form.submit()" 
                class="card px-3 py-2 rounded-lg font-bold text-white cursor-pointer appearance-none pr-8 w-full hover:border-[var(--fpl-green)] transition-colors text-sm">
          <option value="" disabled>Select Gameweek</option>
          <?php foreach ($gameweeks as $gw): ?>
            <option class="text-black" value="<?= $gw ?>" <?= ($gw == $selected_gw) ? 'selected' : '' ?>>
              GW <?= $gw ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div class="absolute right-2 top-1/2 transform -translate-y-1/2 pointer-events-none">
          <i class="fa-solid fa-chevron-down text-[var(--fpl-green)] text-xs"></i>
        </div>
      </form>
    </div>
  </div>

  <form method="POST" class="mb-8">
    <input type="hidden" name="gameweek" value="<?= $selected_gw ?>">
    
    <div class="mb-4">
      <div class="flex flex-col md:flex-row md:items-center justify-between mb-3 gap-2">
        <div>
          <h2 class="text-lg md:text-xl font-bold text-white">Your Predictions</h2>
          <p class="text-[var(--fpl-muted)] font-medium text-xs md:text-sm">
            <?php if($current_double): ?>
              <span class="text-[var(--fpl-green)] font-bold">
                <i class="fa-solid fa-star mr-1"></i>Double points selected
              </span>
            <?php else: ?>
              Select one match for double points
            <?php endif; ?>
          </p>
        </div>
        <div class="text-[var(--fpl-muted)] text-xs font-semibold">
          <i class="fa-solid fa-info-circle mr-1"></i>Points: 3=Correct, 1=Outcome, 0=Wrong
        </div>
      </div>
    </div>

    <div class="table-container mb-4">
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th class="match-cell">Match Details</th>
              <th class="date-cell">Date & Time</th>
              <th class="prediction-cell">Your Prediction</th>
              <th class="result-cell">Final Result</th>
              <th class="points-cell">Points</th>
              <th class="double-cell">Double</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($predictions->num_rows === 0): ?>
              <tr>
                <td colspan="6" class="py-8 text-center">
                    
                  <div class="text-[var(--fpl-muted)] font-medium">
                    <i class="fa-solid fa-inbox fa-lg mb-2 block"></i>
                    No predictions for Gameweek <?= $selected_gw ?>
                  </div>
                      
                </td>
                  <?php else: ?>
                
              </tr>
              <?php while ($row = $predictions->fetch_assoc()): ?>
                <?php $is_double = ($row['match_id'] == $current_double); ?>
                <tr>
                    <td>
                    <div class="font-bold text-white text-sm">
                      <?= $row['home_team'] ?> <span class="text-[var(--fpl-green)] mx-1">vs</span> <?= $row['away_team'] ?>
                    </div>
                  </td>
                  <td class="date-cell">
                    <div class="text-[var(--fpl-muted)] text-xs">
                      <?= date("M j", strtotime($row['match_date'])) ?>
                    </div>
                    <div class="text-[var(--fpl-muted)] text-xs">
                      <?= date("H:i", strtotime($row['match_date'])) ?>
                    </div>
                  </td>
                  <td class="prediction-cell">
                    <span class="inline-block px-2 py-1 rounded-full bg-[var(--fpl-blue)]/30 border border-[var(--fpl-border)] font-bold text-white text-xs">
                      <?= $row['predicted_home'] ?> - <?= $row['predicted_away'] ?>
                    </span>
                  </td>
                  <td class="result-cell">
                    <?php if (is_numeric($row['home_score'])): ?>
                      <span class="inline-block px-2 py-1 rounded-full bg-[var(--fpl-card-bg)] border border-[var(--fpl-border)] font-bold text-white text-xs">
                        <?= $row['home_score'] ?> - <?= $row['away_score'] ?>
                      </span>
                    <?php else: ?>
                      <span class="text-[var(--fpl-muted)] text-xs">
                        <i class="fa-solid fa-clock mr-1"></i>Not played
                      </span>
                    <?php endif; ?>
                  </td>
                  <td class="points-cell">
                    <?= render_points_badge($row['points'], $is_double) ?>
                  </td>
                  <td class="double-cell">
<?php if($DOUBLE_POINTS_ACTIVE): ?>
    <?php if($is_double): ?>
      <span class="inline-flex items-center gap-1 text-[var(--fpl-green)] font-bold text-xs">
        <i class="fa-solid fa-star"></i> 
        <span class="hidden sm:inline">DOUBLE</span>
      </span>
    <?php else: ?>
      <label class="inline-flex items-center gap-1 cursor-pointer">
        <input type="radio" name="double_match" value="<?= $row['match_id'] ?>" 
               <?= $current_double ? 'disabled' : '' ?>
               class="scale-90">
        <span class="text-[var(--fpl-muted)] text-xs font-semibold hidden sm:inline">Select</span>
      </label>
    <?php endif; ?>
<?php else: ?>
    <span class="text-gray-400 text-xs font-semibold">⛔ Closed</span>
<?php endif; ?>
</td>

                   
                </tr>
              <?php endwhile; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    
    <?php if($DOUBLE_POINTS_ACTIVE && !$current_double && $predictions->num_rows > 0): ?>
<div class="flex justify-center md:justify-end mt-4">
  <button type="submit"class="px-4 py-2 bg-gradient-to-r from-[var(--fpl-green)] to-[var(--fpl-light-blue)] font-bold text-black rounded-lg hover:opacity-90 transition-all shadow-lg w-full sm:w-auto text-sm">
        <i class="fa-solid fa-save mr-1"></i>Save Double Points Selection</button>
</div>
<?php endif; ?>

  </form>

  <div class="mb-8">
    <div class="flex flex-col md:flex-row md:items-center justify-between mb-3 gap-2">
      <div>
        <h2 class="text-lg md:text-xl font-bold text-white">Gameweek <?= $selected_gw ?> Leaderboard</h2>
        <p class="text-[var(--fpl-muted)] font-medium text-xs md:text-sm">Top 15 managers this gameweek</p>
      </div>
      <div class="text-xs text-[var(--fpl-muted)] font-semibold">
        <i class="fa-solid fa-trophy mr-1 text-[var(--fpl-green)]"></i>Double points included
      </div>
    </div>
    
    <div class="table-container">
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th class="w-16">Rank</th>
              <th>Manager</th>
              <th class="w-24">Points</th>
            </tr>
          </thead>
          <tbody>
            <?php $rank=1; while ($r=$leaderboard->fetch_assoc()): ?>
            <tr>
              <td>
                <div class="inline-flex items-center justify-center w-8 h-8 rounded-full font-bold text-sm
                  <?php if ($rank == 1): ?> rank-1
                  <?php elseif ($rank == 2): ?> rank-2
                  <?php elseif ($rank == 3): ?> rank-3
                  <?php else: ?> bg-[var(--fpl-card-bg)] border border-[var(--fpl-border)] text-[var(--fpl-muted)]
                  <?php endif; ?>">
                  <?= $rank ?>
                </div>
              </td>
              <td class="text-left"><?= htmlspecialchars($r['username']) ?>
                <a href="other_user.php?user_id=<?= $r['id'] ?>&gameweek=<?= $selected_gw ?>" 
                   class="font-semibold text-white hover:text-[var(--fpl-green)] transition-colors text-sm">
                </a>
              </td>
              <td>
                <span class="inline-flex items-center gap-1 font-bold text-sm bg-gradient-to-r from-[var(--fpl-green)] to-[var(--fpl-light-blue)] bg-clip-text text-transparent">
                  <i class="fa-solid fa-star text-xs"></i>
                  <?= $r['gw_points'] ?>
                </span>
              </td>
            </tr>
            <?php $rank++; endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

</div>

<script>
function toggleMenu() { 
    document.getElementById("mobileMenu").classList.toggle("hidden"); 
}

document.addEventListener("DOMContentLoaded", function () {
    const DEADLINE = new Date("2026-01-17T13:00:00"); 

    const countdown = document.getElementById("countdown");
    const timerBox = document.getElementById("doubleTimer");
    const radios = document.querySelectorAll('input[name="double_match"]');
    const saveBtn = document.querySelector('button[type="submit"]');

    function closeDouble() {
        radios.forEach(radio => {
            const td = radio.closest("label");
            if (td) {
                td.innerHTML = `<span class="text-gray-400 text-xs font-semibold">⛔ Closed</span>`;
            }
        });

        if (saveBtn) saveBtn.style.display = "none";

        timerBox.innerHTML = '<i class="fa-solid fa-lock"></i> Closed';
        timerBox.classList.remove("double-timer");
        timerBox.classList.add("bg-gray-600", "text-gray-300", "border-gray-500");
    }

    function updateTimer() {
        
        if (!<?= $DOUBLE_POINTS_ACTIVE ? 'true' : 'false' ?>) {
    closeDouble();
    clearInterval(interval);
    return;
}

        
        const now = new Date();
        const diff = DEADLINE - now;

        radios.forEach(radio => {
    td.innerHTML = `<span class="text-gray-400 text-xs font-semibold">⛔ Closed</span>`;
});


        const h = Math.floor(diff / (1000 * 60 * 60));
        const m = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
        const s = Math.floor((diff % (1000 * 60)) / 1000);

        countdown.textContent =
            h.toString().padStart(2, "0") + ":" +
            m.toString().padStart(2, "0") + ":" +
            s.toString().padStart(2, "0");
            
        if (h === 0 && m < 60) {
            timerBox.classList.add("animate-pulse");
        }
    }

    updateTimer();
    const interval = setInterval(updateTimer, 1000);
});
    
    function useCard(card){
  fetch('activate_card.php',{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:'card='+card+'&gameweek=<?= $selected_gw ?>'
  }).then(res => res.text())
    .then(() => location.reload());
}
    
function useTriple(matchId) {

  if (!confirm('Use TRIPLE on this match? (Only once ever)')) return;

  fetch('activate_card.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'card=triple_match&match_id=' + matchId + '&gameweek=<?= $selected_gw ?>'
  })
  .then(r => r.text())
  .then(res => {
    if (res === 'USED') {
      alert('You already used this card');
    } else if (res === 'OK') {
      location.reload();
    } else {
      alert('Error: ' + res);
    }
  });
}
</script>

    

</body>
</html>