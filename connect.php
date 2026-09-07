<?php
$servername = "localhost";   // XAMPP runs MySQL locally
$username   = "root";        // default user in XAMPP
$password   = "";            // default password is empty
$dbname     = "mypredictions";   // replace with your database name
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("❌ Connection failed: " . $conn->connect_error);
}
?>
