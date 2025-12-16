<?php
include 'connect.php';
include 'wa_send.php';

$sql = "SELECT username, phone_number FROM users WHERE phone_number IS NOT NULL";
$res = $conn->query($sql);

while ($row = $res->fetch_assoc()) {
    $phone = $row['phone_number'];
    $name  = $row['username'];

    $message = "Hi $name, don't forget to submit your predictions!";

    sendWhatsApp($phone, $message);
}

echo "Notifications sent!";
?>
