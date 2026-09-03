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
            $error = "Invalid password.";
        }
    } else {
        $error = "No user found with that email.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <title>Login | Premier League Predictions</title>
   <link rel="icon" type="image/jpg" href="PL_img/hadi.jpg">
   <script src="https://cdn.tailwindcss.com"></script>
   <style>
     /* Background image and overlay to match the rest of the site */
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
<body class="flex items-center justify-center min-h-screen text-white">

  <div class="bg-[#1c003a]/80 backdrop-blur-xl border border-[#ff0080]/30 shadow-2xl rounded-2xl p-8 sm:p-10 w-[90%] sm:w-[420px] text-center relative overflow-hidden">
    
    <div class="absolute inset-0 rounded-2xl border-2 border-transparent bg-gradient-to-r from-[#e90052] via-[#ff0080] to-[#ff9900] opacity-20 blur-xl"></div>

    <div class="relative z-10">
      <img src="PL_img/PL_LOGO1.png" alt="PL Logo" class="w-20 h-20 mx-auto mb-5">
      <h1 class="text-3xl font-black text-white mb-6 tracking-wide uppercase">Welcome Back</h1>

      <?php if (!empty($error)): ?>
        <p class="text-red-400 font-semibold mb-4"><?= htmlspecialchars($error) ?></p>
      <?php endif; ?>

      <form action="login.php" method="POST" class="space-y-6">
        
        <div class="text-left">
          <label class="font-bold text-[#ff0080] uppercase text-sm">Email</label>
          <input 
            class="mt-2 w-full px-4 py-2 bg-transparent border-b-2 border-[#e90052]/50 text-white outline-none focus:border-[#ff9900] transition-all duration-300 placeholder-gray-500"
            type="email" name="email" placeholder="example@email.com" required>
        </div>

        <div class="text-left">
          <label class="font-bold text-[#ff0080] uppercase text-sm">Password</label>
          <div class="relative mt-2">
            <input 
              id="passwordInput"
              class="w-full px-4 py-2 bg-transparent border-b-2 border-[#e90052]/50 text-white outline-none focus:border-[#ff9900] transition-all duration-300 placeholder-gray-500 pr-10"
              type="password" name="password" placeholder="********" required>
            
            <!-- Show/Hide Password Button -->
            <button type="button" onclick="togglePasswordVisibility()" class="absolute right-0 top-2 text-gray-400 hover:text-[#ff0080] focus:outline-none transition-colors">
              <!-- Eye Icon (Show Password) -->
              <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
              </svg>
              <!-- Eye Off Icon (Hide Password) -->
              <svg id="eyeOffIcon" xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 hidden" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
              </svg>
            </button>
          </div>
        </div>

        <button type="submit"
          class="w-full mt-4 bg-gradient-to-r from-[#e90052] to-[#ff9900] text-white py-3 rounded-xl font-black text-lg uppercase tracking-wide shadow-lg transition transform hover:scale-105 hover:shadow-[0_8px_25px_rgba(233,0,82,0.30)]">
          Login
        </button>

        <p class="mt-4 text-gray-300 text-sm">
          Don't have an account? 
          <a href="register.php" class="text-[#ff9900] font-bold hover:underline">Create one</a>
        </p>
      </form>
    </div>
  </div>

  <!-- JavaScript to toggle password visibility -->
  <script>
    function togglePasswordVisibility() {
      const passwordInput = document.getElementById('passwordInput');
      const eyeIcon = document.getElementById('eyeIcon');
      const eyeOffIcon = document.getElementById('eyeOffIcon');

      if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        eyeIcon.classList.add('hidden');
        eyeOffIcon.classList.remove('hidden');
      } else {
        passwordInput.type = 'password';
        eyeIcon.classList.remove('hidden');
        eyeOffIcon.classList.add('hidden');
      }
    }
  </script>

</body>
</html>