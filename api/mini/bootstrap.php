<?php

declare(strict_types=1);
include __DIR__ . '/../../connect.php';

/*
|--------------------------------------------------------------------------
| MINI APP API BOOTSTRAP
|--------------------------------------------------------------------------
|
| Shared by every file in api/mini/.
|
| The React app is hosted on a DIFFERENT domain (Vercel) than this PHP
| backend, so it can't rely on the browser sending the PHP session
| cookie automatically the way the main site's own pages do -
| cross-site cookies get blocked by Safari and increasingly Chrome.
|
| Instead, this keeps ordinary PHP sessions but identifies them by a
| token instead of a cookie:
|
|   1. login.php authenticates exactly like before, creates a normal
|      PHP session, and hands the session ID back as "token".
|   2. The client stores that token and sends it back on every request
|      as `Authorization: Bearer <token>`.
|   3. This file sees that header and resumes that EXACT session with
|      session_id() before starting it, so $_SESSION works exactly like
|      it always has. No new tables, no JWTs, no separate auth system.
|
*/

// ---------------------------------------------------------------------
// CORS - must run before anything else, including for OPTIONS preflight
// ---------------------------------------------------------------------

$miniAllowedOrigins = [
    'https://predictions.infinityfreeapp.com',            // local Vite dev server
    'https://ruffamous-predcitions.vercel.app/',      // <-- replace with your real Vercel URL
    // 'https://your-custom-domain.com', // add this too once you attach one
];

$miniOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($miniOrigin, $miniAllowedOrigins, true)) {
    header("Access-Control-Allow-Origin: {$miniOrigin}");
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------------------------------------------------------------------
// Resume the right session using the bearer token, if one was sent.
// Apache sometimes strips the Authorization header - see the .htaccess
// shipped alongside this file, which restores it.
// ---------------------------------------------------------------------

$miniAuthHeader = $_SERVER['HTTP_AUTHORIZATION']
    ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
    ?? '';

if ($miniAuthHeader !== '' && stripos($miniAuthHeader, 'Bearer ') === 0) {
    $miniToken = trim(substr($miniAuthHeader, 7));

    // Only accept strings shaped like a real PHP session ID - blocks
    // anything crafted to interfere with session storage on disk.
    if (preg_match('/^[a-zA-Z0-9,-]{22,250}$/', $miniToken) === 1) {
        session_id($miniToken);
    }
}

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function miniRespond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function miniFail(string $message, int $status = 400): never
{
    miniRespond([
        'success' => false,
        'message' => $message,
    ], $status);
}

$miniConnectFile = __DIR__ . '/../../connect.php';

if (!is_file($miniConnectFile)) {
    miniFail('Server misconfigured: connect.php not found.', 500);
}

require_once $miniConnectFile;

$miniDeadlineFile = __DIR__ . '/../../gameweek_deadline.php';

if (is_file($miniDeadlineFile)) {
    require_once $miniDeadlineFile;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    miniFail('Server misconfigured: no database connection.', 500);
}

/**
 * Resolves the current authenticated user from the resumed session, or
 * ends the request with 401. Never trusts a client-supplied user_id.
 */
function miniRequireUser(mysqli $conn): array
{
    if (empty($_SESSION['user_id'])) {
        miniFail('Not authenticated.', 401);
    }

    $userId = (int) $_SESSION['user_id'];

    $stmt = $conn->prepare("
        SELECT id, username, email
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        miniFail('Server error.', 500);
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();

    $user = $stmt->get_result()->fetch_assoc();

    $stmt->close();

    if (!$user) {
        session_destroy();
        miniFail('Session expired. Please log in again.', 401);
    }

    return [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
    ];
}

/**
 * The current Premier League gameweek, using the EXACT SAME rule as
 * predictions.php on the main site: the highest gameweek number that
 * has Premier League matches.
 */
function miniCurrentGameweek(mysqli $conn): int
{
    $result = $conn->query("
        SELECT MAX(gameweek) AS latest_gw
        FROM matches
        WHERE competition = 'Premier League'
    ");

    $row = $result ? $result->fetch_assoc() : null;

    return (int) ($row['latest_gw'] ?? 0);
}
