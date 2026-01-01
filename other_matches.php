<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];

/* Gameweeks */
$gw_sql = "SELECT DISTINCT gameweek FROM matches
           WHERE competition <> 'Premier League'
           ORDER BY gameweek ASC";
$gw_result = $conn->query($gw_sql);

$last_gw_sql = "SELECT MAX(gameweek) AS last_gw
                FROM matches
                WHERE competition <> 'Premier League'";
$last_gameweek = (int)$conn->query($last_gw_sql)->fetch_assoc()['last_gw'];

$selected_gw = isset($_GET['gameweek']) ? (int)$_GET['gameweek'] : $last_gameweek;

if ($selected_gw < $last_gameweek) {
    header("Location: other_matches.php?gameweek=$last_gameweek");
    exit;
}

/* 🔒 Check if predictions already submitted */
$lock_sql = "SELECT COUNT(*) AS total
             FROM score_exact se
             JOIN matches m ON se.match_id = m.id
             WHERE se.user_id = ?
               AND m.gameweek = ?
               AND m.competition <> 'Premier League'";
$lock_stmt = $conn->prepare($lock_sql);
$lock_stmt->bind_param("ii", $user_id, $selected_gw);
$lock_stmt->execute();
$predictions_locked = $lock_stmt->get_result()->fetch_assoc()['total'] > 0;

/* Matches */
$sql = "SELECT m.*, p.predicted_home, p.predicted_away
        FROM matches m
        LEFT JOIN score_exact p
          ON m.id = p.match_id AND p.user_id = ?
        WHERE m.competition <> 'Premier League'
          AND m.gameweek = ?
        ORDER BY m.competition, m.match_date ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $user_id, $selected_gw);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Other Leagues Predictions | Fantasy Premier League</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
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

.match-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem;
  margin-bottom: 0.5rem;
}

.team-home, .team-away {
  flex: 1;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.team-home {
  justify-content: flex-start;
}

.team-away {
  justify-content: flex-end;
}

.team-logo {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  border: 2px solid var(--fpl-border);
}

.score-inputs {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.score-input {
  width: 50px;
  height: 50px;
  text-align: center;
  font-size: 1.25rem;
  font-weight: 800;
  border: 2px solid var(--fpl-green);
  border-radius: 8px;
  background: var(--fpl-card-bg);
  color: white;
}

.score-input:focus {
  outline: none;
  box-shadow: 0 0 15px rgba(0, 255, 135, 0.5);
}

.score-input:disabled {
  opacity: 0.6;
  cursor: not-allowed;
  border-color: var(--fpl-muted);
}

.score-separator {
  font-size: 1.5rem;
  font-weight: 800;
  color: var(--fpl-green);
}

.competition-header {
  background: linear-gradient(90deg, var(--fpl-blue), #2a1a5e);
  padding: 0.75rem 1rem;
  border-radius: 8px;
  margin: 1.5rem 0 0.75rem 0;
  font-weight: 700;
  font-size: 1.1rem;
  color: var(--fpl-green);
  text-align: center;
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
  width: 100%;
  margin-top: 1.5rem;
}

.submit-btn:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 10px 25px rgba(0, 255, 135, 0.4);
}

.submit-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.locked-message {
  text-align: center;
  padding: 2rem;
  color: var(--fpl-muted);
  font-size: 1.1rem;
  background: rgba(42, 26, 94, 0.3);
  border-radius: 12px;
  margin-top: 1.5rem;
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
  
  .team-logo {
    width: 32px;
    height: 32px;
  }
  
  .score-input {
    width: 40px;
    height: 40px;
    font-size: 1rem;
  }
}
</style>
</head>

<body class="p-0">

<nav class="w-full nav-gradient text-white py-4 px-4 fixed top-0 left-0 flex justify-between items-center z-50 shadow-2xl">
    <div class="flex items-center gap-2">
        <div class="relative">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-[var(--fpl-green)] to-[var(--fpl-light-blue)] flex items-center justify-center shadow-lg">
                <span class="text-black font-extrabold text-base">FPL</span>
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
        <li><a href="my_predictions.php" class="text-[var(--fpl-green)] font-bold border-b-2 border-[var(--fpl-green)]">other leagues</a></li>
    </ul>
</nav>

<div id="mobileMenu" class="hidden flex-col gap-4 text-white py-6 px-6 fixed top-16 left-0 w-full z-40 nav-gradient shadow-xl">
    <a href="dashboard.php" class="hover:text-[var(--fpl-green)] transition-colors font-semibold py-2">Dashboard</a><br><br>
    <a href="predictions.php" class="hover:text-[var(--fpl-green)] transition-colors font-semibold py-2">Predictions</a><br><br>
    <a href="leaderboard.php" class="hover:text-[var(--fpl-green)] transition-colors font-semibold py-2">Leaderboard</a><br><br>
    <a href="my_predictions.php" class="text-[var(--fpl-green)] font-bold py-2">other leagues</a><br><br>
</div>

<div class="max-w-6xl mx-auto p-3 md:p-6 pt-24 animate-fade-in">

  <div class="card p-4 md:p-6 mb-6">
    <div class="flex flex-col md:flex-row justify-between items-center lg:mt-20 mb-6 gap-3">
      <div class="text-center md:text-left">
        <h1 class="text-2xl md:text-4xl font-black bg-gradient-to-r from-[var(--fpl-green)] to-[var(--fpl-light-blue)] bg-clip-text text-transparent drop-shadow-lg">
          Other Leagues Predictions
        </h1>
        <p class="text-[var(--fpl-muted)] font-medium mt-1 text-sm">
          <i class="fa-solid fa-trophy mr-1"></i>Gameweek <?= $selected_gw ?> • Non-Premier League Competitions
        </p>
      </div>
      
      <form method="GET" class="relative w-full sm:w-auto">
        <select name="gameweek" onchange="this.form.submit()" 
                class="card px-3 py-2 rounded-lg font-bold text-white cursor-pointer appearance-none pr-8 w-full hover:border-[var(--fpl-green)] transition-colors text-sm">
          <option value="" disabled>Select Gameweek</option>
          <?php 
          $gw_result->data_seek(0); // Reset result pointer
          while ($gw = $gw_result->fetch_assoc()):
            $gw_num = (int)$gw['gameweek'];
          ?>
          <option value="<?= $gw_num ?>" <?= $gw_num === $selected_gw ? 'selected' : '' ?>>
            Gameweek <?= $gw_num ?>
          </option>
          <?php endwhile; ?>
        </select>
        <div class="absolute right-2 top-1/2 transform -translate-y-1/2 pointer-events-none">
          <i class="fa-solid fa-chevron-down text-[var(--fpl-green)] text-xs"></i>
        </div>
      </form>
    </div>

    <div id="deadlineTimer" class="double-timer px-4 py-2 rounded-lg font-bold text-sm text-center mb-6">
      <i class="fa-solid fa-clock mr-2"></i>
      Predictions close in: <span id="countdown" class="font-mono">00:00:00</span>
    </div>
  </div>

  <form action="insert_all_predictions.php" method="POST">
    
    <div class="table-container mb-4">
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th class="w-48">Home Team</th>
              <th class="w-32">Prediction</th>
              <th class="w-48">Away Team</th>
              <th class="w-32">Competition</th>
            </tr>
          </thead>
          <tbody>
            <?php
            $result->data_seek(0); 
            $current_league = "";
            while ($match = $result->fetch_assoc()):
              if ($match['competition'] !== $current_league):
                $current_league = $match['competition'];
            ?>
            <tr>
              <td colspan="4" class="competition-header">
                <?= htmlspecialchars($current_league) ?>
              </td>
            </tr>
            <?php endif; ?>
            
            <tr>
              <td>
                <div class="flex items-center justify-end gap-2">
                  <span class="font-semibold text-right"><?= htmlspecialchars($match['home_team']) ?></span>
                  <?php if ($match['home_team_pic']): ?>
                    <img src="<?= htmlspecialchars($match['home_team_pic']) ?>" 
                         class="team-logo w-20 h-20" 
                         alt="<?= htmlspecialchars($match['home_team']) ?>">
                  <?php endif; ?>
                </div>
              </td>
              
              <td>
                <input type="hidden" name="match_id[]" value="<?= $match['id'] ?>">
                <div class="score-inputs">
                  <input type="number" name="predicted_home[]"
                         value="<?= $match['predicted_home'] ?? '' ?>"
                         min="0" max="10"
                         class="score-input"
                         <?= $predictions_locked ? 'disabled' : 'required' ?>
                         oninput="this.value = this.value.replace(/[^0-9]/g, ''); if(this.value > 10) this.value = 10;">
                  
                  <span class="score-separator">-</span>
                  
                  <input type="number" name="predicted_away[]"
                         value="<?= $match['predicted_away'] ?? '' ?>"
                         min="0" max="10"
                         class="score-input"
                         <?= $predictions_locked ? 'disabled' : 'required' ?>
                         oninput="this.value = this.value.replace(/[^0-9]/g, ''); if(this.value > 10) this.value = 10;">
                </div>
              </td>
              
              <td>
                <div class="flex items-center gap-2">
                  <?php if ($match['away_team_pic']): ?>
                    <img src="<?= htmlspecialchars($match['away_team_pic']) ?>" 
                         class="team-logo w-20 h-20" 
                         alt="<?= htmlspecialchars($match['away_team']) ?>">
                  <?php endif; ?>
                  <span class="font-semibold"><?= htmlspecialchars($match['away_team']) ?></span>
                </div>
              </td>
              
              <td>
                <span class="text-[var(--fpl-muted)] text-xs font-semibold px-2 py-1 rounded-full bg-[var(--fpl-card-bg)]">
                  <?= htmlspecialchars($match['competition']) ?>
                </span>
              </td>
            </tr>
            <?php endwhile; ?>
          </tbody>
        </table>
      </div>
    </div>
    
    <?php if (!$predictions_locked): ?>
    <button type="submit" class="submit-btn">
      <i class="fa-solid fa-paper-plane mr-2"></i> Submit ALL Predictions
    </button>
    <?php else: ?>
    <div class="locked-message">
      <i class="fa-solid fa-lock fa-2x mb-3"></i>
      <p class="font-bold">Predictions Locked</p>
      <p class="text-sm">You have already submitted predictions for this gameweek</p>
    </div>
    <?php endif; ?>
  </form>

</div>

<script>
function toggleMenu() { 
    document.getElementById("mobileMenu").classList.toggle("hidden"); 
}

document.addEventListener("DOMContentLoaded", function () {
    const DEADLINE = new Date("2025-12-20T18:00:00");

    const countdown = document.getElementById("countdown");
    const timerBox = document.getElementById("deadlineTimer");
    const inputs = document.querySelectorAll('.score-input');
    const submitBtn = document.querySelector('.submit-btn');

    function closePredictions() {
        inputs.forEach(input => {
            input.disabled = true;
            input.style.borderColor = 'var(--fpl-muted)';
        });

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fa-solid fa-lock mr-2"></i> Predictions Closed';
            submitBtn.style.opacity = '0.6';
        }

        timerBox.innerHTML = '<i class="fa-solid fa-lock"></i> Predictions closed';
        timerBox.classList.remove("double-timer");
        timerBox.classList.add("bg-gray-600", "text-gray-300", "border-gray-500");
    }

    function updateTimer() {
        const now = new Date();
        const diff = DEADLINE - now;

        if (diff <= 0) {
            closePredictions();
            clearInterval(interval);
            return;
        }

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
    
    inputs.forEach(input => {
        input.addEventListener('input', function(e) {
            let value = e.target.value;
            value = value.replace(/[^0-9]/g, '');
            
            if (value !== '') {
                const num = parseInt(value);
                if (num > 10) {
                    value = '10';
                }
            }
            
            e.target.value = value;
        });
    });
});
</script>

</body>
</html>