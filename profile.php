<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

if (isset($_GET['id'])) {
    $user_id = intval($_GET['id']);  
} else {
    $user_id = $_SESSION['user_id']; 
}

$sql = "SELECT * FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

if (!$user) {
    echo "User not found.";
    exit();
}

$isOwner = ($user_id === $_SESSION['user_id']);

$teams = [];
$result = $conn->query("SELECT name FROM teams ORDER BY name ASC");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $teams[] = $row['name'];
    }
}

if ($isOwner && $_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['avatar'])) {
    $targetDir = "uploads/";
    if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

    $fileName = time() . "_" . basename($_FILES["avatar"]["name"]);
    $targetFile = $targetDir . $fileName;

    if (move_uploaded_file($_FILES["avatar"]["tmp_name"], $targetFile)) {
        $sql2 = "UPDATE users SET avatar=? WHERE id=?";
        $stmt2 = $conn->prepare($sql2);
        $stmt2->bind_param("si", $targetFile, $user_id);
        $stmt2->execute();
        header("Location: profile.php?id=$user_id");
        exit;
    }
}

if ($isOwner && $_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['new_username'])) {
    $new_username = trim($_POST['new_username']);

    if (!empty($new_username)) {
        $check = $conn->prepare("SELECT id FROM users WHERE username=? AND id!=?");
        $check->bind_param("si", $new_username, $user_id);
        $check->execute();
        $check_result = $check->get_result();

        if ($check_result->num_rows > 0) {
            $error = "Username already taken!";
        } else {
            $update = $conn->prepare("UPDATE users SET username=? WHERE id=?");
            $update->bind_param("si", $new_username, $user_id);
            $update->execute();
            $_SESSION['username'] = $new_username;
            header("Location: profile.php?id=$user_id");
            exit;
        }
    } else {
        $error = "Username cannot be empty.";
    }
}

if ($isOwner && $_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['favorite_team'])) {
    $new_team = trim($_POST['favorite_team']);

    if (!empty($new_team)) {
        $update_team = $conn->prepare("UPDATE users SET favorite_team=? WHERE id=?");
        $update_team->bind_param("si", $new_team, $user_id);
        $update_team->execute();
        header("Location: profile.php?id=$user_id");
        exit;
    }
}

$sql2 = "SELECT COUNT(*) as total, SUM(is_correct) as correct 
         FROM predictions WHERE user_id = ?";
$stmt2 = $conn->prepare($sql2);
$stmt2->bind_param("i", $user_id);
$stmt2->execute();
$stats = $stmt2->get_result()->fetch_assoc();

$total = $stats['total'] ?? 0;
$correct = $stats['correct'] ?? 0;
$success_rate = $total > 0 ? round(($correct / $total) * 100, 2) : 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Profile | Premier League</title>

<script src="https://cdn.tailwindcss.com"></script>
<link rel="icon" type="image/jpg" href="PL_img/hadi.jpg">
<style>
    body {
        background-image: url('PL_img/current.jpg');
        background-size: cover;
        background-position: center;
        background-attachment: fixed;
        background-color: #1c003a;
        font-family: Arial, Helvetica, sans-serif;
    }
    
    body::before {
        content: "";
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(10, 0, 21, 0.75);
        z-index: -1;
        pointer-events: none;
    }
</style>

</head>

<body class="min-h-screen pb-16 text-white">

<nav class="fixed top-0 left-0 right-0 z-50 bg-[#1c003a]/80 backdrop-blur-xl border-b border-[#ff0080]/30 px-5 md:px-8 py-4 flex justify-between items-center">

    <a href="dashboard.php" class="flex items-center gap-3">
        <div class="w-11 h-11 rounded-full flex items-center justify-center overflow-hidden">
            <img src="PL_img/PL_LOGO1.png" class="w-full h-full object-contain" alt="Premier League">
        </div>
        <span class="font-black text-lg text-white">Premier League</span>
    </a>

    <div class="hidden md:flex items-center gap-7 text-sm font-bold">
        <a href="dashboard.php" class="hover:text-[#ff9900] transition-colors">Dashboard</a>
        <a href="predictions.php" class="hover:text-[#ff9900] transition-colors">Predictions</a>
        <a href="leaderboard.php" class="hover:text-[#ff9900] transition-colors">Leaderboard</a>
        <a href="my_predictions.php" class="hover:text-[#ff9900] transition-colors">My Predictions</a>
    </div>

    <div class="flex items-center gap-4">
        <a href="profile.php" class="hidden md:flex items-center gap-3">
            <img src="<?= e($user['avatar']) ?>" alt="avatar"
                 class="w-10 h-10 rounded-full object-cover border-2 border-[#ff0080]/50 shadow-[0_0_25px_rgba(255,0,128,0.25)]">
            <span class="text-sm font-bold text-gray-300"><?= e($user['username']) ?></span>
        </a>

        <button onclick="toggleMenu()" class="md:hidden text-lg px-2 font-bold text-white">Menu</button>
    </div>

</nav>

<div id="mobileMenu" class="hidden fixed top-[73px] left-0 right-0 z-40 bg-[#1c003a]/95 backdrop-blur-xl border-b border-[#ff0080]/30 p-6">
    <div class="flex flex-col gap-5 font-bold">
        <a href="dashboard.php" class="hover:text-[#ff9900] transition-colors">Dashboard</a>
        <a href="predictions.php" class="hover:text-[#ff9900] transition-colors">Predictions</a>
        <a href="leaderboard.php" class="hover:text-[#ff9900] transition-colors">Leaderboard</a>
        <a href="my_predictions.php" class="hover:text-[#ff9900] transition-colors">My Predictions</a>
    </div>
</div>

<script>
function toggleMenu() {
    document.getElementById('mobileMenu').classList.toggle('hidden');
}
</script>

<div class="h-24"></div>

<main class="max-w-4xl mx-auto px-4">

    <div class="flex flex-col md:flex-row justify-between items-center gap-6 mb-8">
        <div class="flex items-center gap-4">
            <div class="w-16 h-16 rounded-full p-2 flex items-center justify-center bg-white">
                <img src="PL_img/PL_LOGO1.png" class="w-full h-full object-contain" alt="Premier League">
            </div>
            <div>
                <div class="text-[#ff0080] text-sm font-black uppercase tracking-widest">User</div>
                <h1 class="text-3xl md:text-5xl font-black text-white">Profile</h1>
                <p class="text-gray-300 mt-1">View and manage your profile</p>
            </div>
        </div>

        <a href="users.php" class="text-sm text-gray-400 hover:text-white transition">
            Back to Users
        </a>
    </div>

    <div class="bg-[#1c003a]/80 backdrop-blur-xl border border-[#ff0080]/30 rounded-3xl p-6 md:p-8 mb-8 shadow-[0_10px_30px_rgba(0,0,0,0.3)]">

        <div class="flex flex-col md:flex-row items-center gap-6">

            <div class="relative flex-shrink-0">
                <img src="<?= e($user['avatar']) ?>" alt="avatar"
                     class="w-28 h-28 md:w-32 md:h-32 rounded-full object-cover border-2 border-[#ff0080]/50 shadow-[0_0_25px_rgba(255,0,128,0.25)]">
                <?php if ($isOwner): ?>
                    <div class="absolute -bottom-1 -right-1 rounded-full p-1 bg-pink-500">
                        <div class="w-8 h-8 rounded-full bg-black flex items-center justify-center text-xs font-black text-white">
                            Edit
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="flex-1 text-center md:text-left">
                <h2 class="text-3xl font-black text-white"><?= e($user['username']) ?></h2>
                <p class="text-gray-300 mt-1">
                    Favorite team: <span class="text-white font-bold"><?= e($user['favorite_team']) ?></span>
                </p>
                <?php if (!$isOwner): ?>
                    <div class="mt-2 text-sm text-gray-500">Viewing another user's profile</div>
                <?php endif; ?>
            </div>

            <?php if ($isOwner): ?>
                <div class="flex-shrink-0 bg-gradient-to-r from-[#e90052] to-[#ff9900] text-white px-6 py-3 rounded-2xl font-black shadow-lg shadow-pink-500/20">
                    <div class="text-sm uppercase tracking-wider">You</div>
                    <div class="text-lg">Owner</div>
                </div>
            <?php endif; ?>

        </div>

        <?php if ($isOwner): ?>

            <div class="mt-8 pt-6 border-t border-white/10">
                <h3 class="text-lg font-black mb-4 text-white">Update Avatar</h3>
                <form method="POST" enctype="multipart/form-data" class="flex flex-col sm:flex-row items-center gap-4">
                    <input type="file" name="avatar" accept="image/*"
                           class="bg-black/35 border-2 border-[#e90052]/40 text-white rounded-xl p-2 w-full text-sm" required>
                    <button type="submit" class="bg-[#e90052] hover:bg-[#ff1a66] text-black font-black transition hover:-translate-y-0.5 hover:shadow-[0_8px_25px_rgba(233,0,82,0.3)] px-6 py-2 rounded-xl text-sm">
                        Upload
                    </button>
                </form>
            </div>

            <div class="mt-6 pt-6 border-t border-white/10">
                <h3 class="text-lg font-black mb-4 text-white">Change Username</h3>
                <form method="POST" class="flex flex-col sm:flex-row items-center gap-4">
                    <input type="text" name="new_username" placeholder="Enter new username"
                           class="bg-black/45 border-2 border-[#e90052]/50 text-[#ffd86b] font-bold shadow-[0_0_20px_rgba(233,0,82,0.1)] focus:border-[#e90052] focus:outline-none focus:shadow-[0_0_30px_rgba(233,0,82,0.25)] w-full sm:w-64 rounded-xl px-4 py-3 text-center"
                           required>
                    <button type="submit" class="bg-[#22c55e] hover:bg-[#2ddb6e] text-black font-black transition hover:-translate-y-0.5 hover:shadow-[0_8px_25px_rgba(34,197,94,0.3)] px-6 py-3 rounded-xl text-sm">
                        Update
                    </button>
                </form>
                <?php if (!empty($error)): ?>
                    <p class="text-red-400 text-sm mt-2"><?= e($error) ?></p>
                <?php endif; ?>
            </div>

            <div class="mt-6 pt-6 border-t border-white/10">
                <h3 class="text-lg font-black mb-4 text-white">Change Favorite Team</h3>
                <form method="POST" class="flex flex-col sm:flex-row items-center gap-4">
                    <select name="favorite_team"
                            class="bg-black/45 border-2 border-[#e90052]/50 text-[#ffd86b] font-bold shadow-[0_0_20px_rgba(233,0,82,0.1)] focus:border-[#e90052] focus:outline-none focus:shadow-[0_0_30px_rgba(233,0,82,0.25)] w-full sm:w-64 rounded-xl px-4 py-3 text-center"
                            required>
                        <option value="">-- Select your team --</option>
                        <?php foreach ($teams as $team): ?>
                            <option value="<?= e($team) ?>"
                                <?php if ($user['favorite_team'] == $team) echo 'selected'; ?>>
                                <?= e($team) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="bg-[#8b5cf6] hover:bg-[#a78bfa] text-white font-black transition hover:-translate-y-0.5 hover:shadow-[0_8px_25px_rgba(139,92,246,0.3)] px-6 py-3 rounded-xl text-sm">
                        Update Team
                    </button>
                </form>
            </div>

        <?php endif; ?>

    </div>

    <div class="bg-[#1c003a]/80 backdrop-blur-xl border border-[#ff0080]/30 rounded-3xl p-6 md:p-8 mb-8 shadow-[0_10px_30px_rgba(0,0,0,0.3)]">
        <h3 class="text-xl font-black mb-6 text-white">Statistics</h3>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="bg-black/30 rounded-2xl p-5 text-center">
                <div class="text-gray-500 text-xs uppercase font-black">Total Predictions</div>
                <div class="text-3xl font-black text-white"><?= (int)$total ?></div>
            </div>
            <div class="bg-black/30 rounded-2xl p-5 text-center">
                <div class="text-gray-500 text-xs uppercase font-black">Correct</div>
                <div class="text-3xl font-black text-green-400"><?= (int)$correct ?></div>
            </div>
            <div class="bg-black/30 rounded-2xl p-5 text-center">
                <div class="text-gray-500 text-xs uppercase font-black">Success Rate</div>
                <div class="text-3xl font-black text-[#ff9900]"><?= $success_rate ?>%</div>
            </div>
        </div>
    </div>

    <div class="text-center">
        <a href="dashboard.php" class="inline-block bg-[#e90052] hover:bg-[#ff1a66] text-black px-8 py-4 rounded-xl text-lg font-black shadow-lg shadow-pink-500/20 transition hover:-translate-y-0.5">
            Back to Dashboard
        </a>
    </div>

    <div class="text-center text-gray-500 text-sm mt-10">
        Premier League Profile
        <br>
        Manage your account and track your performance.
    </div>

</main>

</body>
</html>