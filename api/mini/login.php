<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    miniFail('Login must use POST.', 405);
}

$raw = file_get_contents('php://input');
$body = json_decode((string) $raw, true);

if (!is_array($body)) {
    $body = $_POST;
}

$identifier = trim((string) ($body['identifier'] ?? $body['email'] ?? $body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');

if ($identifier === '' || $password === '') {
    miniFail('Please enter your email/username and password.', 400);
}

/*
 * Accept either the account's email (same as the main site's login.php)
 * or its username, so a friend who only remembers his username can
 * still log in. This does not change how the main site's login.php
 * itself works.
 */
if (strpos($identifier, '@') !== false) {
    $stmt = $conn->prepare("
        SELECT id, username, password
        FROM users
        WHERE email = ?
        LIMIT 1
    ");
} else {
    $stmt = $conn->prepare("
        SELECT id, username, password
        FROM users
        WHERE username = ?
        LIMIT 1
    ");
}

if (!$stmt) {
    miniFail('Server error.', 500);
}

$stmt->bind_param('s', $identifier);
$stmt->execute();

$user = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$user || !password_verify($password, $user['password'])) {
    miniFail('Invalid email/username or password.', 401);
}

/*
 * Same session variables login.php sets on the main site. Nothing new
 * is introduced - this is a normal login, just triggered from the
 * mini app instead of the login.php form.
 *
 * session_regenerate_id(true) throws away whatever anonymous session
 * id the client may have shown up with and issues a brand new one -
 * that new id is what we hand back as the "token".
 */
session_regenerate_id(true);

$_SESSION['user_id'] = (int) $user['id'];
$_SESSION['username'] = $user['username'];

miniRespond([
    'success' => true,
    'data' => [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'token' => session_id(),
    ],
]);
