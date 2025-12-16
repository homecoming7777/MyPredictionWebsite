<?php
include 'connect.php';

$sql = "SELECT id, username, email, favorite_team, avatar FROM users";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<style>
   body{
      background: linear-gradient(to bottom,  #000000, #400D64,  #7B13FF);
   }
</style>
<body class="bg-gray-100 min-h-screen p-6">
  <h1 class="text-3xl font-bold mb-6 text-center text-white">Users Dashboard</h1>

  <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
    <?php while ($row = $result->fetch_assoc()) { ?>
      <div class="bg-white shadow-lg rounded-xl p-4 flex items-center space-x-4">
        <img src="<?php echo $row['avatar']; ?>" 
             class="w-16 h-16 rounded-full object-cover shadow">

        <div>
          <h2 class="text-xl font-bold"><?php echo $row['username']; ?></h2>
          <p class="text-gray-600 text-sm"><?php echo $row['email']; ?></p>
          <p class="text-indigo-600 text-sm"> <?php echo $row['favorite_team']; ?></p>

          <a href="profile.php?id=<?php echo $row['id']; ?>" 
             class="text-blue-500 text-sm hover:underline">View Profile</a>
        </div>
      </div>
    <?php } ?>
  </div>
</body>
</html>
