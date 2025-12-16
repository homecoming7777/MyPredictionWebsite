<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
  die("Access denied.");
}

$user_id = $_SESSION['user_id'];
$gameweek = $_POST['gameweek'];
$match_id = $_POST['match_id'];

$check = $conn->prepare("SELECT id FROM double_gameweek WHERE user_id=? AND gameweek=?");
$check->bind_param("ii", $user_id, $gameweek);
$check->execute();
$res = $check->get_result();

if ($res->num_rows > 0) {
  header("Location: my_predictions.php?gameweek=$gameweek&locked=1");
  exit;
}

$insert = $conn->prepare("INSERT INTO double_gameweek (user_id, gameweek, match_id) VALUES (?, ?, ?)");
$insert->bind_param("iii", $user_id, $gameweek, $match_id);
$insert->execute();

header("Location: my_predictions.php?gameweek=$gameweek&saved=1");
exit;
?>
