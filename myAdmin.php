<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    die("Access denied");
}



$settings = $conn->query("SELECT * FROM global_settings WHERE id=1")->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {

    $stmt = $conn->prepare("
        UPDATE global_settings SET
        site_enabled=?,
        predictions_enabled=?,
        other_leagues_enabled=?,
        maintenance_mode=?,
        double_points_enabled=?,
        whatsapp_enabled=?
        WHERE id=1
    ");

    $stmt->bind_param(
        "iiiiii",
        $_POST['site_enabled'],
        $_POST['predictions_enabled'],
        $_POST['other_leagues_enabled'],
        $_POST['maintenance_mode'],
        $_POST['double_points_enabled'],
        $_POST['whatsapp_enabled']
    );

    $stmt->execute();
    header("Location: my_admin.php");
    exit;
}



function sendWhatsApp($phone, $message, $apikey) {
    $url = "https://api.callmebot.com/whatsapp.php?phone=$phone&text=" . urlencode($message) . "&apikey=$apikey";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response ? true : false;
}

$gw_query = $conn->query("SELECT DISTINCT gameweek FROM matches ORDER BY gameweek ASC");
$gameweeks = [];
while ($g = $gw_query->fetch_assoc()) {
    $gameweeks[] = $g['gameweek'];
}

$selected_gw = isset($_GET['gw']) ? intval($_GET['gw']) : null;

$matches = [];
if ($selected_gw) {
    $stmt = $conn->prepare("
        SELECT *
        FROM matches
        WHERE gameweek = ?
        ORDER BY match_date ASC
    ");
    $stmt->bind_param("i", $selected_gw);
    $stmt->execute();
    $matches = $stmt->get_result();
    $stmt->close();
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_match'])) {

    $stmt = $conn->prepare("
        UPDATE matches 
        SET home_score = ?, away_score = ?
        WHERE id = ?
    ");
    $stmt->bind_param(
        "iii",
        $_POST['home_score'],
        $_POST['away_score'],
        $_POST['match_id']
    );
    $stmt->execute();
    $stmt->close();

    header("Location: my_admin.php?gw=" . $_POST['current_gw']);
    exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_gw'])) {

    $gw = intval($_POST['gw']);
    $status = intval($_POST['status']);

    $stmt = $conn->prepare("
        INSERT INTO gameweek_status (gameweek, is_open)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE is_open = ?
    ");
    $stmt->bind_param("iii", $gw, $status, $status);
    $stmt->execute();
    $stmt->close();

    header("Location: my_admin.php?gw=$gw");
    exit;
}


$gw_status = 1;
if ($selected_gw) {
    $res = $conn->query("SELECT is_open FROM gameweek_status WHERE gameweek=$selected_gw");
    if ($row = $res->fetch_assoc()) {
        $gw_status = (int)$row['is_open'];
    }
}


$wa_sent_count = 0;
$wa_debug = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_whatsapp'])) {

    $res = $conn->query("
        SELECT username, phone_number, wa_apikey 
        FROM users 
        WHERE phone_number IS NOT NULL 
          AND wa_apikey IS NOT NULL
    ");

    while ($row = $res->fetch_assoc()) {
        $message = "Hi {$row['username']} 👋, the next gameweek is ready 💪 
Submit your predictions now ✅";

        if (sendWhatsApp($row['phone_number'], $message, $row['wa_apikey'])) {
            $wa_sent_count++;
        }
    }

    $default_msg_sent = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_custom_whatsapp'])) {

    $custom = trim($_POST['custom_msg']);

    if ($custom !== "") {

        $res = $conn->query("
            SELECT username, phone_number, wa_apikey 
            FROM users 
            WHERE phone_number IS NOT NULL 
              AND wa_apikey IS NOT NULL
        ");

        while ($row = $res->fetch_assoc()) {
            $message = str_replace("{name}", $row['username'], $custom);
            if (sendWhatsApp($row['phone_number'], $message, $row['wa_apikey'])) {
                $wa_sent_count++;
            }
        }

        $custom_msg_sent = true;
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    $uid = intval($_POST['user_id']);
    $conn->query("DELETE FROM users WHERE id = $uid");
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recalc_points'])) {
    include 'calculate_points.php';
    $recalc_done = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Panel</title>
<script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-900 text-white p-6">

<div class="max-w-6xl mx-auto">

<h1 class="text-3xl font-bold text-yellow-400 mb-6 text-center">
⚙️ Admin Control Panel
</h1>

<form method="GET" class="text-center mb-6">
<select name="gw" onchange="this.form.submit()"
class="bg-gray-800 px-4 py-2 border border-purple-600 rounded">
<option value="">Select Gameweek</option>
<?php foreach ($gameweeks as $gw): ?>
<option value="<?= $gw ?>" <?= $gw == $selected_gw ? 'selected' : '' ?>>
Gameweek <?= $gw ?>
</option>
<?php endforeach; ?>
</select>
</form>

<?php if ($selected_gw): ?>
<div class="text-center mb-6">
<form method="POST">
<input type="hidden" name="gw" value="<?= $selected_gw ?>">
<input type="hidden" name="status" value="<?= $gw_status ? 0 : 1 ?>">

<?php if ($gw_status): ?>
<button name="toggle_gw" class="bg-red-500 px-6 py-2 rounded font-bold">
🔒 Close Predictions
</button>
<?php else: ?>
<button name="toggle_gw" class="bg-green-500 px-6 py-2 rounded font-bold">
🔓 Open Predictions
</button>
<?php endif; ?>
</form>
</div>
<?php endif; ?>

<?php if ($selected_gw && $matches->num_rows): ?>
<table class="w-full bg-gray-800 rounded mb-10">
<thead class="bg-purple-700">
<tr>
<th>ID</th><th>Match</th><th>Date</th><th>Home</th><th>Away</th><th>Save</th>
</tr>
</thead>
<tbody>
<?php while ($m = $matches->fetch_assoc()): ?>
<tr class="border-b border-gray-700">
<form method="POST">
<td><?= $m['id'] ?></td>
<td><?= $m['home_team'] ?> vs <?= $m['away_team'] ?></td>
<td><?= date("Y-m-d H:i", strtotime($m['match_date'])) ?></td>
<td><input type="number" name="home_score" value="<?= $m['home_score'] ?>" class="w-16 bg-gray-900 text-center"></td>
<td><input type="number" name="away_score" value="<?= $m['away_score'] ?>" class="w-16 bg-gray-900 text-center"></td>
<td>
<input type="hidden" name="match_id" value="<?= $m['id'] ?>">
<input type="hidden" name="current_gw" value="<?= $selected_gw ?>">
<button name="update_match" class="bg-green-500 px-3 py-1 rounded">Save</button>
</td>
</form>
</tr>
<?php endwhile; ?>
</tbody>
</table>
<?php endif; ?>

<form method="POST" class="text-center mb-4">
<button name="send_whatsapp" class="bg-green-500 px-6 py-2 rounded font-bold">
Send WhatsApp Reminder
</button>
</form>

<form method="POST" class="text-center mb-8">
<textarea name="custom_msg" rows="3" class="w-full max-w-xl bg-gray-800 p-3 rounded"
placeholder="Use {name} for username"></textarea>
<br>
<button name="send_custom_whatsapp" class="bg-pink-500 px-6 py-2 rounded font-bold mt-2">
Send Custom Message
</button>
</form>

<form method="POST" class="text-center mb-10">
<button name="recalc_points" class="bg-blue-500 px-6 py-2 rounded font-bold">
🔄 Recalculate Points
</button>
<?php if (isset($recalc_done)): ?>
<p class="text-green-400 mt-2">Points recalculated</p>
<?php endif; ?>
</form>

<div class="bg-gray-800 rounded p-6">
<h2 class="text-xl font-bold text-purple-400 mb-4 text-center">Users</h2>
<table class="w-full text-center">
<tr class="bg-purple-700"><th>ID</th><th>Username</th><th>Phone</th><th>Action</th></tr>
<?php
$users = $conn->query("SELECT id, username, phone_number FROM users");
while ($u = $users->fetch_assoc()):
?>
<tr class="border-b border-gray-700">
<td><?= $u['id'] ?></td>
<td><?= htmlspecialchars($u['username']) ?></td>
<td><?= $u['phone_number'] ?: '-' ?></td>
<td>
<form method="POST" onsubmit="return confirm('Delete user?')">
<input type="hidden" name="user_id" value="<?= $u['id'] ?>">
<button name="delete_user" class="bg-red-500 px-3 py-1 rounded text-sm">Delete</button>
</form>
</td>
</tr>
<?php endwhile; ?>
</table>
</div>

</div>
    
    <div class="bg-gray-900 p-6 rounded-xl mb-10 border border-gray-700">
<h2 class="text-xl font-bold text-green-400 mb-6 text-center">🌐 GLOBAL SYSTEM CONTROL</h2>

<form method="POST" class="grid md:grid-cols-2 gap-4">

<label class="text-white">Site Enabled
<select name="site_enabled" class="w-full bg-gray-800 p-2 rounded">
<option value="1" <?= $settings['site_enabled']?'selected':'' ?>>ON</option>
<option value="0" <?= !$settings['site_enabled']?'selected':'' ?>>OFF</option>
</select>
</label>

<label class="text-white">Predictions System
<select name="predictions_enabled" class="w-full bg-gray-800 p-2 rounded">
<option value="1" <?= $settings['predictions_enabled']?'selected':'' ?>>ON</option>
<option value="0" <?= !$settings['predictions_enabled']?'selected':'' ?>>OFF</option>
</select>
</label>

<label class="text-white">Other Leagues
<select name="other_leagues_enabled" class="w-full bg-gray-800 p-2 rounded">
<option value="1" <?= $settings['other_leagues_enabled']?'selected':'' ?>>ON</option>
<option value="0" <?= !$settings['other_leagues_enabled']?'selected':'' ?>>OFF</option>
</select>
</label>

<label class="text-white">Maintenance Mode
<select name="maintenance_mode" class="w-full bg-gray-800 p-2 rounded">
<option value="0" <?= !$settings['maintenance_mode']?'selected':'' ?>>OFF</option>
<option value="1" <?= $settings['maintenance_mode']?'selected':'' ?>>ON</option>
</select>
</label>

<label class="text-white">Double Points System
<select name="double_points_enabled" class="w-full bg-gray-800 p-2 rounded">
<option value="1" <?= $settings['double_points_enabled']?'selected':'' ?>>ON</option>
<option value="0" <?= !$settings['double_points_enabled']?'selected':'' ?>>OFF</option>
</select>
</label>

<label class="text-white">WhatsApp System
<select name="whatsapp_enabled" class="w-full bg-gray-800 p-2 rounded">
<option value="1" <?= $settings['whatsapp_enabled']?'selected':'' ?>>ON</option>
<option value="0" <?= !$settings['whatsapp_enabled']?'selected':'' ?>>OFF</option>
</select>
</label>

<div class="md:col-span-2 text-center mt-4">
<button name="update_settings" class="bg-green-500 hover:bg-green-600 px-8 py-2 rounded font-bold text-black">
SAVE SYSTEM SETTINGS
</button>
</div>

</form>
</div>

</body>
</html>
