<?php

session_start();

header('Content-Type: application/json; charset=utf-8');

require_once 'connect.php';
require_once 'reactions_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required.']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$matchId = (int)($_POST['match_id'] ?? 0);
$reactionType = (string)($_POST['reaction_type'] ?? '');

$result = reactionsToggle($conn, $userId, $matchId, $reactionType);

if (!$result['success']) {
    http_response_code(400);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
