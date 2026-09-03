<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

$sql = "SELECT favorite_team FROM users WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$stmt->bind_result($favorite_team);
$stmt->fetch();
$stmt->close();

if (!empty($favorite_team)) {
    header("Location: dashboard.php");
    exit();
}

$teams = [];

$result = $conn->query("
    SELECT id, name, short_name, logo
    FROM teams
    ORDER BY name ASC
");

while ($row = $result->fetch_assoc()) {
    $teams[] = $row;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['team_id'])) {

    $team_id = intval($_POST['team_id']);

    $stmt = $conn->prepare("
        SELECT name
        FROM teams
        WHERE id = ?
    ");

    $stmt->bind_param("i", $team_id);
    $stmt->execute();
    $stmt->bind_result($team_name);
    $stmt->fetch();
    $stmt->close();

    if (!empty($team_name)) {

        $stmt = $conn->prepare("
            UPDATE users
            SET favorite_team = ?,
                favorite_team_id = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            "sii",
            $team_name,
            $team_id,
            $user_id
        );

        $stmt->execute();
        $stmt->close();

        header("Location: dashboard.php");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Choose Favorite Team | Premier League</title>
    <script src="https://cdn.tailwindcss.com"></script>
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

    <main class="max-w-7xl mx-auto px-4 pt-8">

        <div class="flex flex-col lg:flex-row justify-between items-center gap-6 mb-8">
            <div class="flex items-center gap-4">
                <div class="w-16 h-16 rounded-full p-2 flex items-center justify-center">
                    <img src="PL_img/PL_LOGO1.png" class="w-full h-full object-contain" alt="Premier League">
                </div>
                <div>
                    <div class="text-[#ff0080] text-sm font-black uppercase tracking-widest">Welcome</div>
                    <h1 class="text-3xl md:text-5xl font-black text-white">Choose Your Team</h1>
                    <p class="text-gray-300 mt-1">Select your favorite Premier League club</p>
                </div>
            </div>
        </div>

        <div class="bg-white/5 backdrop-blur-xl border border-white/10 rounded-2xl p-6 md:p-8">

            <form method="POST" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-6">

                <?php foreach ($teams as $team): ?>

                    <?php
                        $logo = ltrim($team['logo'] ?? '', '/');
                        $logo_url = $logo;
                    ?>

                    <button
                        type="submit"
                        name="team_id"
                        value="<?= (int)$team['id'] ?>"
                        class="bg-white/5 border border-white/10 rounded-xl p-5 flex flex-col items-center justify-center cursor-pointer text-center transition-all hover:border-[#ff0080]/60 hover:bg-[#e90052]/10 hover:shadow-lg hover:-translate-y-1"
                    >

                        <div class="w-20 h-20 md:w-24 md:h-24 flex items-center justify-center mb-4">
                            <img
                                src="<?= e($logo_url) ?>"
                                alt="<?= e($team['name']) ?>"
                                class="w-full h-full object-contain drop-shadow-lg"
                                onerror="this.style.display='none';"
                            >
                        </div>

                        <h3 class="text-sm md:text-base font-bold text-white transition-colors">
                            <?= e($team['name']) ?>
                        </h3>

                        <p class="text-xs text-gray-400 mt-1">
                            <?= e($team['short_name']) ?>
                        </p>

                    </button>

                <?php endforeach; ?>

            </form>

        </div>

        <div class="text-center text-gray-500 text-sm mt-10">
            Premier League • Pick your favorite team to get started
        </div>

    </main>

</body>

</html>