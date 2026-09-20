<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    miniFail('Method not allowed.', 405);
}

/*
 * Idempotent on purpose: works whether or not the token was still
 * valid, so the client can always safely "log out" and drop its
 * stored token.
 */
$_SESSION = [];

if (session_id() !== '') {
    session_destroy();
}

miniRespond([
    'success' => true,
    'data' => ['logged_out' => true],
]);
