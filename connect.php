<?php
$servername = "localhost";   // XAMPP runs MySQL locally
$username   = "root";        // default user in XAMPP
$password   = "";            // default password is empty
$dbname     = "mypredictions";   // replace with your database name
$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("❌ Connection failed: " . $conn->connect_error);
}

/*
|--------------------------------------------------------------------------
| ACTIVITY TRACKING HOOK (monitoring only)
|--------------------------------------------------------------------------
|
| Every page on this site calls session_start() BEFORE including
| connect.php, so this is the single safest place to record activity.
|
| It does nothing unless a user is already logged in, and it silently
| does nothing if monitor_install.sql has not been run yet.
|
*/

$activityHelperFile = __DIR__ . '/activity_helper.php';

if (is_file($activityHelperFile)) {

    require_once $activityHelperFile;

    if (function_exists('activityTouchCurrentUser')) {
        activityTouchCurrentUser($conn);
    }
}
?>