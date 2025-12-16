<?php
include "connect.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = trim($_POST["username"]);
    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    if (strlen($password) < 8) {
        $error = "❌ Password must be at least 8 characters long.";
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE username=? OR email=?");
        $check->bind_param("ss", $username, $email);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $error = "❌ Username or Email already exists. Try another one.";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $conn->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $username, $email, $hashedPassword);

            if ($stmt->execute()) {
                $new_user_id = $conn->insert_id;

                $last_user = $conn->query("
                    SELECT u.id, COALESCE(SUM(s.points), 0) AS total_points
                    FROM users u
                    LEFT JOIN score_exact s ON u.id = s.user_id
                    WHERE u.id != $new_user_id
                    GROUP BY u.id
                    ORDER BY total_points ASC
                    LIMIT 1
                ")->fetch_assoc();

                $starting_points = $last_user ? (int)$last_user['total_points'] : 0;

                if ($starting_points > 0) {
                    $stmt2 = $conn->prepare("INSERT INTO score_exact (user_id, points) VALUES (?, ?)");
                    $stmt2->bind_param("ii", $new_user_id, $starting_points);
                    $stmt2->execute();
                    $stmt2->close();
                }

                header("Location: login.php");
                exit();
            } else {
                $error = "❌ Error: " . $stmt->error;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Register | Premier League Predictions</title>
   <script src="https://cdn.tailwindcss.com"></script>
   <style>
     body {
       background-image: url('/PL_img/test.jpg');
       background-size: cover;
       background-position: center;
       background-repeat: no-repeat;
     }
   </style>
</head>
<body class="flex items-center justify-center min-h-screen bg-[#38003D]/90 backdrop-blur-md">

  <div class="bg-white/10 border border-purple-500/30 shadow-2xl backdrop-blur-lg rounded-2xl p-8 sm:p-10 w-[90%] sm:w-[420px] text-center relative overflow-hidden">
    
    <div class="absolute inset-0 rounded-2xl border-2 border-transparent bg-gradient-to-r from-purple-600 via-pink-500 to-cyan-400 opacity-40 blur-xl animate-pulse"></div>

    <div class="relative z-10">
      <img src="/PL_img/PL_LOGO1.png" alt="PL Logo" class="w-20 h-20 mx-auto mb-5 animate-bounce">
      <h1 class="text-3xl font-extrabold text-[#06EFFD] mb-6 tracking-wide uppercase">Create Account</h1>

      <?php if (!empty($error)): ?>
        <p class="text-red-400 font-semibold mb-4"><?= htmlspecialchars($error) ?></p>
      <?php endif; ?>

      <form action="register.php" method="POST" class="space-y-6">
        
        <div class="text-left">
          <label class="font-semibold text-[#06EFFD] uppercase text-sm">Username</label>
          <input 
            class="mt-2 w-full px-4 py-2 bg-transparent border-b-2 border-[#8c0096] text-white outline-none focus:border-[#06EFFD] transition-all duration-300 placeholder-gray-400"
            type="text" name="username" placeholder="Enter username" required>
        </div>

        <div class="text-left">
          <label class="font-semibold text-[#06EFFD] uppercase text-sm">Email</label>
          <input 
            class="mt-2 w-full px-4 py-2 bg-transparent border-b-2 border-[#8c0096] text-white outline-none focus:border-[#06EFFD] transition-all duration-300 placeholder-gray-400"
            type="email" name="email" placeholder="example@email.com" required>
        </div>

        <div class="text-left">
          <label class="font-semibold text-[#06EFFD] uppercase text-sm">Password</label>
          <input 
            class="mt-2 w-full px-4 py-2 bg-transparent border-b-2 border-[#8c0096] text-white outline-none focus:border-[#06EFFD] transition-all duration-300 placeholder-gray-400"
            type="password" name="password" placeholder="At least 8 characters" required>
          <p class="text-xs text-gray-400 mt-1">Minimum 8 characters.</p>
        </div>

        <button type="submit"
          class="w-full mt-4 bg-gradient-to-r from-[#8c0096] to-[#06EFFD] text-white py-3 rounded-xl font-bold text-lg uppercase tracking-wide shadow-lg transition transform hover:scale-105 hover:shadow-cyan-400/40">
          Register
        </button>

        <p class="mt-4 text-gray-300 text-sm">
          Already have an account? 
          <a href="login.php" class="text-[#06EFFD] font-semibold hover:underline">Login</a>
        </p>
      </form>
    </div>
  </div>

</body>
</html>
