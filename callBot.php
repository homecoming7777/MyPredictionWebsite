<?php
function sendWhatsApp($phone, $message) {
    $apikey = "8623234"; 
    $url = "https://api.callmebot.com/whatsapp.php?phone=$phone&text=".urlencode($message)."&apikey=$apikey";

    $response = file_get_contents($url);
    return $response ? true : false;
}
?>
