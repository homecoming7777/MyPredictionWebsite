<?php
$servername = "sql111.infinityfree.com";   // XAMPP runs MySQL locally
$username   = "if0_39901045";        // default user in XAMPP
$password   = "m6GUofJvKjMbx";            // default password is empty
$dbname     = "if0_39901045_fantasy";   // replace with your database name
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("❌ Connection failed: " . $conn->connect_error);
}
?>
