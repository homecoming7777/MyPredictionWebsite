<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
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
    echo "❌ User not found.";
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
            $error = "⚠️ Username already taken!";
        } else {
            $update = $conn->prepare("UPDATE users SET username=? WHERE id=?");
            $update->bind_param("si", $new_username, $user_id);
            $update->execute();
            $_SESSION['username'] = $new_username;
            header("Location: profile.php?id=$user_id");
            exit;
        }
    } else {
        $error = "⚠️ Username cannot be empty.";
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta charset="UTF-8">
  <title><?php echo htmlspecialchars($user['username']); ?> - Profile</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<style>
  body {
    background: linear-gradient(to right, #00055cff, #470040ff);
  }
  #div {
    box-shadow: 5px 5px 50px gray;
  }
</style>
<body class="flex items-center justify-center min-h-screen">
  <div class="bg-white shadow-lg rounded-2xl p-6 w-full max-w-md" id="div">

    <div class="flex flex-col items-center">
      <img src="<?php echo htmlspecialchars($user['avatar']); ?>" 
           alt="Avatar" 
           class="w-24 h-24 rounded-full shadow-md object-cover">
      <h1 class="text-2xl font-bold mt-4"><?php echo htmlspecialchars($user['username']); ?></h1>
      <p class="mt-2 text-indigo-600 font-semibold">
         Favorite Team: <?php echo htmlspecialchars($user['favorite_team']); ?>
      </p>
    </div>

    <?php if ($isOwner): ?>
    <div class="mt-6">
      <form method="POST" enctype="multipart/form-data" class="flex flex-col items-center space-y-3">
        <input type="file" name="avatar" accept="image/*" 
               class="block w-full text-sm text-gray-600 file:mr-4 file:py-2 file:px-4
                      file:rounded-full file:border-0 file:text-sm file:font-semibold
                      file:bg-indigo-50 file:text-indigo-700 hover:file:bg-indigo-100" required>
        <button type="submit" 
                class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
          Update Avatar
        </button>
      </form>
    </div>

    <div class="mt-6">
      <form method="POST" class="flex flex-col items-center space-y-3">
        <input type="text" name="new_username" placeholder="Enter new username"
               class="border rounded-lg px-3 py-2 w-full text-center focus:ring-2 focus:ring-indigo-500"
               required>
        <button type="submit"
                class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
          Update Username
        </button>
      </form>
      <?php if (!empty($error)): ?>
        <p class="text-red-500 text-center mt-2"><?php echo $error; ?></p>
      <?php endif; ?>
    </div>

    <div class="mt-6">
      <form method="POST" class="flex flex-col items-center space-y-3">
        <select name="favorite_team" 
                class="border rounded-lg px-3 py-2 w-full text-center focus:ring-2 focus:ring-indigo-500" required>
          <option value="">-- Select your team --</option>
          <?php foreach ($teams as $team): ?>
            <option value="<?php echo htmlspecialchars($team); ?>"
              <?php if ($user['favorite_team'] == $team) echo 'selected'; ?>>
              <?php echo htmlspecialchars($team); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button type="submit"
                class="px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700">
          Update Favorite Team
        </button>
      </form>
    </div>
    <?php endif; ?>

    <div class="mt-6">
      <h2 class="text-lg font-semibold text-gray-700 mb-2">📊 Statistics</h2>
      <div class="bg-gray-50 p-4 rounded-lg shadow">
        <p>Total Predictions: <span class="font-bold"><?php echo $total; ?></span></p>
        <p>Correct Predictions: <span class="font-bold text-green-600"><?php echo $correct; ?></span></p>
        <p>Success Rate: 
          <span class="font-bold text-blue-600"><?php echo $success_rate; ?>%</span>
        </p>
      </div>
    </div>

    <div class="mt-6 text-center">
      <a href="users.php" class="text-blue-500 hover:underline">⬅ Back to Users</a>
    </div>

    <div class="flex justify-center mt-10">
      <a href="dashboard.php">
        <button class="relative h-12 overflow-hidden rounded bg-neutral-150 px-5 py-2.5 transition-all duration-300 hover:bg-neutral-100 hover:ring-2 hover:ring-neutral-800 hover:ring-offset-2">
          <span class="relative">Back to Dashboard</span>
        </button>
      </a>
    </div>
  </div>
</body>
</html>
