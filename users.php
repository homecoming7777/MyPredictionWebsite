<?php
session_start();
include 'connect.php';

/*
|--------------------------------------------------------------------------
| HELPER: SAFE OUTPUT
|--------------------------------------------------------------------------
*/
function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

$sql = "SELECT id, username, email, favorite_team, avatar FROM users";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Users | Premier League</title>

<script src="https://cdn.tailwindcss.com"></script>

<style>
    /* Only keeping essential CSS for background image and overlay */
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
        background: rgba(10, 0, 21, 0.75); /* Deep Purple Tint */
        z-index: -1;
        pointer-events: none;
    }
</style>

</head>

<body class="min-h-screen pb-16 text-white">

<!-- =========================================================
     NAVBAR (same as all pages)
========================================================= -->
<nav class="fixed top-0 left-0 right-0 z-50 bg-[#1c003a]/80 backdrop-blur-xl border-b border-[#ff0080]/30 px-5 md:px-8 py-4 flex justify-between items-center">

    <!-- LOGO -->
    <a href="dashboard.php" class="flex items-center gap-3">
        <div class="w-11 h-11 rounded-full flex items-center justify-center overflow-hidden">
            <img src="PL_img/PL_LOGO1.png" class="w-full h-full object-contain" alt="Premier League">
        </div>
        <span class="font-black text-lg text-white">Premier League</span>
    </a>

    <!-- DESKTOP NAV -->
    <div class="hidden md:flex items-center gap-7 text-sm font-bold">
        <a href="dashboard.php" class="hover:text-[#ff9900] transition-colors">Dashboard</a>
        <a href="predictions.php" class="hover:text-[#ff9900] transition-colors">Predictions</a>
        <a href="leaderboard.php" class="hover:text-[#ff9900] transition-colors">Leaderboard</a>
        <a href="my_predictions.php" class="hover:text-[#ff9900] transition-colors">My Predictions</a>
    </div>

    <!-- RIGHT: Avatar link (if logged in) -->
    <?php if (isset($_SESSION['user_id'])): ?>
        <?php
            // Fetch current user's avatar for nav
            $uid = $_SESSION['user_id'];
            $av_sql = "SELECT avatar FROM users WHERE id = ?";
            $av_stmt = $conn->prepare($av_sql);
            $av_stmt->bind_param("i", $uid);
            $av_stmt->execute();
            $av_res = $av_stmt->get_result();
            $av_row = $av_res->fetch_assoc();
            $avatar = $av_row['avatar'] ?? 'PL_img/default-avatar.png';
        ?>
        <div class="flex items-center gap-4">
            <a href="profile.php" class="hidden md:flex items-center gap-3">
                <img src="<?= e($avatar) ?>" alt="avatar"
                     class="w-10 h-10 rounded-full object-cover border-2 border-[#ff0080]/50 shadow-[0_0_25px_rgba(255,0,128,0.25)]">
                <span class="text-sm font-bold text-gray-300"><?= e($_SESSION['username'] ?? 'User') ?></span>
            </a>
            <button onclick="toggleMenu()" class="md:hidden text-lg px-2 font-bold text-white">Menu</button>
        </div>
    <?php else: ?>
        <button onclick="toggleMenu()" class="md:hidden text-lg px-2 font-bold text-white">Menu</button>
    <?php endif; ?>

</nav>

<!-- MOBILE MENU -->
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

<!-- =========================================================
     MAIN
========================================================= -->
<main class="max-w-7xl mx-auto px-4">

    <!-- PAGE HEADER -->
    <div class="flex flex-col md:flex-row justify-between items-center gap-6 mb-8">
        <div class="flex items-center gap-4">
            <div class="w-16 h-16 rounded-full p-2 flex items-center justify-center bg-white">
                <img src="PL_img/PL_LOGO1.png" class="w-full h-full object-contain" alt="Premier League">
            </div>
            <div>
                <div class="text-[#ff0080] text-sm font-black uppercase tracking-widest">Community</div>
                <h1 class="text-3xl md:text-5xl font-black text-white">Users</h1>
                <p class="text-gray-300 mt-1">Browse all Premier League predictors</p>
            </div>
        </div>

        <!-- Optional: link to dashboard -->
        <a href="dashboard.php" class="text-sm text-gray-400 hover:text-white transition">
            Back to Dashboard
        </a>
    </div>

    <!-- =========================================================
         USERS GRID
    ========================================================= -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">

        <?php while ($row = $result->fetch_assoc()): ?>

            <div class="bg-[#1c003a]/80 backdrop-blur-xl border border-[#ff0080]/30 rounded-3xl p-6 flex flex-col items-center text-center shadow-[0_10px_30px_rgba(0,0,0,0.3)] transition-all duration-300 hover:translate-y-[-4px] hover:border-[#e90052]/60 hover:shadow-[0_0_30px_rgba(233,0,82,0.2)]">

                <!-- Avatar -->
                <img src="<?= e($row['avatar']) ?>"
                     class="w-24 h-24 rounded-full object-cover border-2 border-[#ff0080]/50 shadow-[0_0_20px_rgba(255,0,128,0.15)] mb-4"
                     alt="<?= e($row['username']) ?>">

                <!-- Username -->
                <h2 class="text-xl font-black text-white"><?= e($row['username']) ?></h2>

                <!-- Email -->
                <p class="text-gray-300 text-sm mt-1"><?= e($row['email']) ?></p>

                <!-- Favorite Team -->
                <p class="text-[#ff9900] font-bold text-sm mt-1">
                    <?= e($row['favorite_team'] ?: 'No team selected') ?>
                </p>

                <!-- View Profile -->
                <a href="profile.php?id=<?= (int)$row['id'] ?>"
                   class="bg-[#e90052] hover:bg-[#ff1a66] text-black font-black transition hover:-translate-y-0.5 hover:shadow-[0_8px_25px_rgba(233,0,82,0.3)] inline-block mt-4 px-6 py-2 rounded-xl text-sm">
                    View Profile
                </a>

            </div>

        <?php endwhile; ?>

        <?php if ($result->num_rows === 0): ?>
            <div class="col-span-full text-center text-gray-400 py-12">
                No users found.
            </div>
        <?php endif; ?>

    </div>

    <!-- FOOTER -->
    <div class="text-center text-gray-500 text-sm mt-10">
        Premier League Users
        <br>
        Connect with fellow predictors.
    </div>

</main>

</body>
</html>