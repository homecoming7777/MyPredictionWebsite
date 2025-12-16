<?php
session_start();
include "connect.php";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        if (password_verify($password, $user["password"])) {
            $_SESSION["user_id"] = $user["id"];
            $_SESSION["username"] = $user["username"];
            $_SESSION["email"] = $email;
            header("Location: choose_team.php");
            exit();
        } else {
            $error = "❌ Invalid password.";
        }
    } else {
        $error = "❌ No user found with that email.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Login | Premier League Predictions</title>
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
      <h1 class="text-3xl font-extrabold text-[#06EFFD] mb-6 tracking-wide uppercase">Welcome Back</h1>

      <?php if (!empty($error)): ?>
        <p class="text-red-400 font-semibold mb-4"><?= htmlspecialchars($error) ?></p>
      <?php endif; ?>

      <form action="login.php" method="POST" class="space-y-6">
        
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
            type="password" name="password" placeholder="********" required>
        </div>

        <button type="submit"
          class="w-full mt-4 bg-gradient-to-r from-[#8c0096] to-[#06EFFD] text-white py-3 rounded-xl font-bold text-lg uppercase tracking-wide shadow-lg transition transform hover:scale-105 hover:shadow-cyan-400/40">
          Login
        </button>

        <p class="mt-4 text-gray-300 text-sm">
          Don’t have an account? 
          <a href="register.php" class="text-[#06EFFD] font-semibold hover:underline">Create one</a>
        </p>
      </form>
    </div>
  </div>

</body>
</html>
