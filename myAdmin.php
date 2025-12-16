<?php
session_start();
include 'connect.php';

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
    $match_id = $_POST['match_id'];
    $home_score = $_POST['home_score'];
    $away_score = $_POST['away_score'];

    $stmt = $conn->prepare("
        UPDATE matches 
        SET home_score = ?, away_score = ?
        WHERE id = ?
    ");
    $stmt->bind_param("iii", $home_score, $away_score, $match_id);
    $stmt->execute();
    $stmt->close();

    header("Location: my_admin.php?gw=" . $_POST['current_gw']);
    exit;
}

$wa_sent_count = 0;
$wa_debug = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_whatsapp'])) {

    $sql = "SELECT username, phone_number, wa_apikey FROM users 
            WHERE phone_number IS NOT NULL AND wa_apikey IS NOT NULL"; 
    $res = $conn->query($sql);

    while ($row = $res->fetch_assoc()) {
        $phone = $row['phone_number'];
        $name  = $row['username'];
        $apikey = $row['wa_apikey'];

        $message = "Hi $name 👋, the next gameweek is ready 💪 Don't forget to submit your predictions! ✅ https://plpredictions.42web.io/login.php";

        $sent = sendWhatsApp($phone, $message, $apikey);
        $wa_debug[] = ['phone' => $phone, 'sent' => $sent];

        if ($sent) $wa_sent_count++;
    }

    $default_msg_sent = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_custom_whatsapp'])) {

    $custom = trim($_POST['custom_msg']);

    if ($custom !== "") {

        $sql = "SELECT username, phone_number, wa_apikey FROM users 
                WHERE phone_number IS NOT NULL AND wa_apikey IS NOT NULL";
        $res = $conn->query($sql);

        $wa_sent_count = 0;
        $wa_debug = [];

        while ($row = $res->fetch_assoc()) {
            $phone = $row['phone_number'];
            $name  = $row['username'];
            $apikey = $row['wa_apikey'];

            $message = str_replace("{name}", $name, $custom);

            $sent = sendWhatsApp($phone, $message, $apikey);
            $wa_debug[] = ['phone' => $phone, 'sent' => $sent];

            if ($sent) $wa_sent_count++;
        }

        $custom_msg_sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin – Manage Matches & WhatsApp</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-900 text-white p-6 min-h-screen">

<div class="max-w-5xl mx-auto">

    <h1 class="text-3xl font-bold text-yellow-400 mb-6 text-center">
        ⚽ Manage Match Results
    </h1>

    <form method="GET" class="mb-6 text-center">
        <label class="text-lg font-semibold">Select Gameweek:</label>
        <select name="gw" onchange="this.form.submit()" 
                class="bg-gray-800 text-white px-4 py-2 rounded-lg border border-purple-600 ml-2">
            <option value="">-- choose --</option>
            <?php foreach ($gameweeks as $gw): ?>
                <option value="<?= $gw ?>" <?= ($selected_gw == $gw ? 'selected' : '') ?>>
                    Gameweek <?= $gw ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>

    <form method="POST" class="mb-6 text-center">
        <button name="send_whatsapp" type="submit" 
                class="bg-green-500 hover:bg-green-600 px-6 py-2 rounded-lg font-semibold">
            Send Default WhatsApp Reminder
        </button>
    </form>

    <form method="POST" class="mb-6 text-center">
        <textarea name="custom_msg" rows="3"
              placeholder="Write your message... Use {name} to insert username"
              class="w-full max-w-xl bg-gray-800 border border-purple-600 text-white p-3 rounded-lg mb-2"></textarea>

        <button name="send_custom_whatsapp" type="submit"
            class="bg-pink-500 hover:bg-pink-600 px-6 py-2 rounded-lg font-semibold">
            Send Custom WhatsApp Message
        </button>
    </form>

    <?php if(isset($default_msg_sent) || isset($custom_msg_sent)): ?>
        <p class="text-green-400 text-center mb-4">
            ✅ WhatsApp sent to <?= $wa_sent_count ?> users!
        </p>
        <div class="text-gray-300 text-sm mb-6 overflow-x-auto">
            <pre><?php print_r($wa_debug); ?></pre>
        </div>
    <?php endif; ?>

    <?php if ($selected_gw && $matches->num_rows > 0): ?>
        <div class="overflow-x-auto">
            <table class="min-w-full text-center bg-gray-800 rounded-xl shadow-lg border border-gray-700">
                <thead class="bg-purple-700 text-white text-sm uppercase">
                    <tr>
                        <th class="p-3">ID</th>
                        <th class="p-3">Match</th>
                        <th class="p-3">Date</th>
                        <th class="p-3">Home Score</th>
                        <th class="p-3">Away Score</th>
                        <th class="p-3">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $matches->fetch_assoc()): ?>
                    <tr class="border-b border-gray-700 hover:bg-gray-700 transition">
                        <form method="POST">
                            <td class="p-3"><?= $row['id'] ?></td>
                            <td class="p-3">
                                <?= htmlspecialchars($row['home_team']) ?>
                                <span class="text-pink-400">vs</span>
                                <?= htmlspecialchars($row['away_team']) ?>
                            </td>
                            <td class="p-3 text-gray-300">
                                <?= date("Y-m-d H:i", strtotime($row['match_date'])) ?>
                            </td>
                            <td class="p-3">
                                <input type="number" name="home_score"
                                       value="<?= $row['home_score'] ?>"
                                       class="w-16 p-1 text-center bg-gray-900 border border-purple-500 rounded">
                            </td>
                            <td class="p-3">
                                <input type="number" name="away_score"
                                       value="<?= $row['away_score'] ?>"
                                       class="w-16 p-1 text-center bg-gray-900 border border-purple-500 rounded">
                            </td>
                            <td class="p-3">
                                <input type="hidden" name="match_id" value="<?= $row['id'] ?>">
                                <input type="hidden" name="current_gw" value="<?= $selected_gw ?>">
                                <button name="update_match" class="bg-green-500 hover:bg-green-600 px-4 py-1 rounded-lg font-semibold">
                                    Save
                                </button>
                            </td>
                        </form>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($selected_gw): ?>
        <p class="text-red-400 text-center mt-6 text-lg">
            No matches found for this Gameweek.
        </p>
    <?php endif; ?>

</div>
</body>
</html>
