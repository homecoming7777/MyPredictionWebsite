<?php
session_start();
include 'connect.php';
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

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
$result = $conn->query("SELECT * FROM teams");
while ($row = $result->fetch_assoc()) {
    $teams[] = $row;
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['team_id'])) {
    $team_id = intval($_POST['team_id']);

    $stmt = $conn->prepare("SELECT name FROM teams WHERE id=?");
    $stmt->bind_param("i", $team_id);
    $stmt->execute();
    $stmt->bind_result($team_name);
    $stmt->fetch();
    $stmt->close();

    if (!empty($team_name)) {
        $stmt = $conn->prepare("UPDATE users SET favorite_team=? WHERE id=?");
        $stmt->bind_param("si", $team_name, $user_id);
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>FAV Team</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gradient-to-br from-[#7b0073] to-[#000000] min-h-screen flex items-center justify-center">
  <div class="w-full max-w-6xl mx-auto p-6">
    <h2 class="text-4xl font-extrabold text-center mb-10 text-white">⚽ Choose Your Favorite Team</h2>
    <form method="POST" class="grid grid-cols-2 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
      <?php foreach ($teams as $team): ?>
        <button 
          type="submit" 
          name="team_id" 
          value="<?= $team['id'] ?>" 
          class="group bg-white rounded-2xl shadow-lg p-6 flex flex-col items-center justify-center hover:shadow-2xl hover:scale-105 transition transform duration-200"
        >
          <div class="w-20 h-20 mb-4 rounded-full overflow-hidden border-2 border-gray-300 group-hover:border-indigo-500 transition">
            <img src="<?= $team['logo'] ?>" alt="<?= $team['name'] ?>" class="w-full h-full">
          </div>
          <h3 class="text-sm sm:text-2xl font-bold text-center text-gray-800 group-hover:text-indigo-600"><?= $team['name'] ?></h3>
          <p class="text-sm text-gray-500"><?= $team['short_name'] ?></p>
        </button>
      <?php endforeach; ?>
    </form>
  </div>
</body>
</html>
