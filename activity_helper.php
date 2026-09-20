<?php

/*
|--------------------------------------------------------------------------
| LIGHTWEIGHT LOGIN + ACTIVITY TRACKING
|--------------------------------------------------------------------------
|
| Writes ONLY to the `user_activity` table (one row per user).
| Never writes to users / predictions / score_exact / matches.
|
| Design rules:
|
|   - Never throws. Never prints. Never redirects.
|   - If the `user_activity` table does not exist yet, every function
|     silently does nothing, so the site keeps working exactly as
|     before until monitor_install.sql has been run.
|   - At most ONE small UPDATE per user per ACTIVITY_MIN_WRITE_SECONDS,
|     so page views do not hammer the database.
|
| Session model (approximation, not Google Analytics):
|
|   user opens a page
|       -> last_activity is refreshed
|   gap since last_activity <= timeout
|       -> the gap is added to total_active_seconds (same session)
|   gap since last_activity > timeout
|       -> previous session is considered finished,
|          a new session is counted
|
*/

if (!defined('ACTIVITY_TIMEZONE')) {
    define('ACTIVITY_TIMEZONE', 'Africa/Casablanca');
}

if (!defined('ACTIVITY_SESSION_TIMEOUT_MINUTES')) {
    define('ACTIVITY_SESSION_TIMEOUT_MINUTES', 30);
}

if (!defined('ACTIVITY_MIN_WRITE_SECONDS')) {
    define('ACTIVITY_MIN_WRITE_SECONDS', 60);
}


if (!function_exists('activityNowString')) {

    /*
    |----------------------------------------------------------------------
    | CURRENT SITE TIME
    |----------------------------------------------------------------------
    */

    function activityNowString(): string
    {
        try {

            $now = new DateTime(
                'now',
                new DateTimeZone(ACTIVITY_TIMEZONE)
            );

            return $now->format('Y-m-d H:i:s');

        } catch (Throwable $e) {

            return date('Y-m-d H:i:s');
        }
    }


    /*
    |----------------------------------------------------------------------
    | TABLE PRESENCE CHECK (cached per request)
    |----------------------------------------------------------------------
    */

    function activityTableExists(mysqli $conn): bool
    {
        static $exists = null;

        if ($exists !== null) {
            return $exists;
        }

        $exists = false;

        try {

            $result = $conn->query(
                "SHOW TABLES LIKE 'user_activity'"
            );

            if ($result instanceof mysqli_result) {

                $exists = ($result->num_rows > 0);

                $result->free();
            }

        } catch (Throwable $e) {

            $exists = false;
        }

        return $exists;
    }


    /*
    |----------------------------------------------------------------------
    | READ ONE ROW
    |----------------------------------------------------------------------
    */

    function activityGetRow(mysqli $conn, int $userId): ?array
    {
        try {

            $stmt = $conn->prepare("
                SELECT
                    user_id,
                    last_login,
                    login_count,
                    last_activity,
                    session_started_at,
                    session_count,
                    total_active_seconds
                FROM user_activity
                WHERE user_id = ?
                LIMIT 1
            ");

            if (!$stmt) {
                return null;
            }

            $stmt->bind_param('i', $userId);

            if (!$stmt->execute()) {

                $stmt->close();

                return null;
            }

            $row = $stmt
                ->get_result()
                ->fetch_assoc();

            $stmt->close();

            return $row ?: null;

        } catch (Throwable $e) {

            return null;
        }
    }


    /*
    |----------------------------------------------------------------------
    | MAKE SURE A ROW EXISTS
    |----------------------------------------------------------------------
    */

    function activityEnsureRow(mysqli $conn, int $userId): bool
    {
        try {

            $stmt = $conn->prepare("
                INSERT IGNORE INTO user_activity (user_id)
                VALUES (?)
            ");

            if (!$stmt) {
                return false;
            }

            $stmt->bind_param('i', $userId);

            $ok = $stmt->execute();

            $stmt->close();

            return (bool) $ok;

        } catch (Throwable $e) {

            return false;
        }
    }


    /*
    |----------------------------------------------------------------------
    | RECORD A SUCCESSFUL LOGIN
    |----------------------------------------------------------------------
    |
    | Called ONCE from login.php, only after the password was verified.
    | It does not read, change or influence authentication in any way.
    |
    */

    function activityRecordLogin(mysqli $conn, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        try {

            if (!activityTableExists($conn)) {
                return;
            }

            if (!activityEnsureRow($conn, $userId)) {
                return;
            }

            $now = activityNowString();

            $stmt = $conn->prepare("
                UPDATE user_activity
                SET
                    last_login = ?,
                    login_count = login_count + 1,
                    last_activity = ?,
                    session_started_at = ?,
                    session_count = session_count + 1
                WHERE user_id = ?
            ");

            if (!$stmt) {
                return;
            }

            $stmt->bind_param(
                'sssi',
                $now,
                $now,
                $now,
                $userId
            );

            $stmt->execute();

            $stmt->close();

            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['activity_last_ping'] = time();
            }

        } catch (Throwable $e) {

            return;
        }
    }


    /*
    |----------------------------------------------------------------------
    | RECORD ACTIVITY (approximate time spent)
    |----------------------------------------------------------------------
    */

    function activityTouch(mysqli $conn, int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        try {

            if (!activityTableExists($conn)) {
                return;
            }

            $row = activityGetRow($conn, $userId);

            if ($row === null) {

                if (!activityEnsureRow($conn, $userId)) {
                    return;
                }

                $row = activityGetRow($conn, $userId);

                if ($row === null) {
                    return;
                }
            }

            $nowString = activityNowString();
            $nowStamp = strtotime($nowString);

            if ($nowStamp === false) {
                return;
            }

            $lastActivity = $row['last_activity'] ?? null;

            $lastStamp = ($lastActivity === null || $lastActivity === '')
                ? null
                : strtotime((string) $lastActivity);

            if ($lastStamp === false) {
                $lastStamp = null;
            }

            $timeout = ACTIVITY_SESSION_TIMEOUT_MINUTES * 60;

            $newSession = false;
            $addSeconds = 0;

            if ($lastStamp === null) {

                $newSession = true;

            } else {

                $gap = $nowStamp - $lastStamp;

                if ($gap < 0) {

                    // Clock skew. Do nothing risky.
                    $gap = 0;
                }

                if ($gap > $timeout) {

                    $newSession = true;

                } else {

                    if ($gap < ACTIVITY_MIN_WRITE_SECONDS) {

                        // Too soon since the last write. Skip entirely
                        // so browsing does not spam the database.
                        return;
                    }

                    $addSeconds = $gap;
                }
            }

            if ($newSession) {

                $stmt = $conn->prepare("
                    UPDATE user_activity
                    SET
                        last_activity = ?,
                        session_started_at = ?,
                        session_count = session_count + 1
                    WHERE user_id = ?
                ");

                if (!$stmt) {
                    return;
                }

                $stmt->bind_param(
                    'ssi',
                    $nowString,
                    $nowString,
                    $userId
                );

            } else {

                $stmt = $conn->prepare("
                    UPDATE user_activity
                    SET
                        last_activity = ?,
                        total_active_seconds = total_active_seconds + ?
                    WHERE user_id = ?
                ");

                if (!$stmt) {
                    return;
                }

                $stmt->bind_param(
                    'sii',
                    $nowString,
                    $addSeconds,
                    $userId
                );
            }

            $stmt->execute();

            $stmt->close();

            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['activity_last_ping'] = time();
            }

        } catch (Throwable $e) {

            return;
        }
    }


    /*
    |----------------------------------------------------------------------
    | AUTOMATIC HOOK
    |----------------------------------------------------------------------
    |
    | Called from connect.php on every request.
    | Does nothing at all unless a user is already logged in.
    |
    */

    function activityTouchCurrentUser(mysqli $conn): void
    {
        try {

            if (session_status() !== PHP_SESSION_ACTIVE) {
                return;
            }

            if (empty($_SESSION['user_id'])) {
                return;
            }

            $userId = (int) $_SESSION['user_id'];

            if ($userId <= 0) {
                return;
            }

            $lastPing = isset($_SESSION['activity_last_ping'])
                ? (int) $_SESSION['activity_last_ping']
                : 0;

            if (
                $lastPing > 0
                && (time() - $lastPing) < ACTIVITY_MIN_WRITE_SECONDS
            ) {
                return;
            }

            activityTouch($conn, $userId);

        } catch (Throwable $e) {

            return;
        }
    }
}