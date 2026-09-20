<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const BOT_API_TOKEN = 'jpLKn6QtsW9uTSEbCv_bPujs5tQA6vL0eNTmSc8gUYqzBqgfy9O_ct6P6mwKyl05';
const MAX_GAMEWEEK = 38;
const SITE_TIMEZONE = 'Africa/Casablanca';

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $status = 400): never
{
    respond([
        'success' => false,
        'error' => $message,
    ], $status);
}

$providedToken = (string)($_GET['token'] ?? '');

if ($providedToken === '' || BOT_API_TOKEN === 'CHANGE_THIS_TO_A_NEW_LONG_RANDOM_TOKEN') {
    fail('BOT_API_TOKEN is not configured on the server.', 500);
}

if (!hash_equals(BOT_API_TOKEN, $providedToken)) {
    fail('Unauthorized.', 401);
}

$connectFile = __DIR__ . '/connect.php';

if (!is_file($connectFile)) {
    fail('connect.php was not found beside bot_api.php.', 500);
}

require_once $connectFile;

function getDbConnection()
{
    global $conn, $pdo, $db;

    if (isset($conn) && $conn instanceof mysqli) {
        return $conn;
    }

    if (isset($pdo) && $pdo instanceof PDO) {
        return $pdo;
    }

    if (isset($db) && $db instanceof mysqli) {
        return $db;
    }

    if (isset($db) && $db instanceof PDO) {
        return $db;
    }

    fail('No supported database connection was found in connect.php. Expected $conn or $pdo.', 500);
}

$dbh = getDbConnection();

function getGameweek(): int
{
    $gw = filter_input(INPUT_GET, 'gameweek', FILTER_VALIDATE_INT);

    if ($gw === false || $gw === null || $gw < 1 || $gw > MAX_GAMEWEEK) {
        fail('Invalid gameweek. Expected an integer from 1 to 38.', 400);
    }

    return (int)$gw;
}

function mysqliQueryRows(mysqli $db, string $sql): array
{
    $result = $db->query($sql);

    if ($result === false) {
        fail('Database query failed: ' . $db->error, 500);
    }

    $rows = [];

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $result->free();

    return $rows;
}

function pdoQueryRows(PDO $db, string $sql): array
{
    try {
        $stmt = $db->query($sql);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        fail('Database query failed: ' . $e->getMessage(), 500);
    }
}

function queryRows($db, string $sql): array
{
    if ($db instanceof mysqli) {
        return mysqliQueryRows($db, $sql);
    }

    if ($db instanceof PDO) {
        return pdoQueryRows($db, $sql);
    }

    fail('Unsupported database connection.', 500);
}

function executeSql($db, string $sql): void
{
    if ($db instanceof mysqli) {
        if (!$db->query($sql)) {
            fail('Database import failed: ' . $db->error, 500);
        }
        return;
    }

    if ($db instanceof PDO) {
        try {
            $db->exec($sql);
            return;
        } catch (Throwable $e) {
            fail('Database import failed: ' . $e->getMessage(), 500);
        }
    }

    fail('Unsupported database connection.', 500);
}

function latestPremierLeagueGameweek($db): ?int
{
    $rows = queryRows(
        $db,
        "SELECT MAX(gameweek) AS latest_gameweek
         FROM matches
         WHERE competition = 'Premier League'"
    );

    $value = $rows[0]['latest_gameweek'] ?? null;

    return ($value === null || $value === '') ? null : (int)$value;
}

function gameweekCount($db, int $gw): int
{
    $rows = queryRows(
        $db,
        "SELECT COUNT(*) AS total
         FROM matches
         WHERE gameweek = " . $gw . "
           AND competition = 'Premier League'"
    );

    return (int)($rows[0]['total'] ?? 0);
}

function verifyGameweek($db, int $gw): array
{
    $rows = queryRows(
        $db,
        "SELECT
            COUNT(*) AS total,
            COUNT(DISTINCT CONCAT(home_team, '|', away_team, '|', match_date)) AS unique_fixtures,
            SUM(CASE WHEN home_team_pic IS NULL OR home_team_pic = '' THEN 1 ELSE 0 END) AS missing_home_logos,
            SUM(CASE WHEN away_team_pic IS NULL OR away_team_pic = '' THEN 1 ELSE 0 END) AS missing_away_logos,
            SUM(CASE WHEN competition = 'Premier League' THEN 1 ELSE 0 END) AS premier_league_rows
         FROM matches
         WHERE gameweek = " . $gw
    );

    $row = $rows[0] ?? [];

    $total = (int)($row['total'] ?? 0);
    $unique = (int)($row['unique_fixtures'] ?? 0);
    $plRows = (int)($row['premier_league_rows'] ?? 0);

    $verified = ($total === 10 && $unique === 10 && $plRows === 10);

    return [
        'verified' => $verified,
        'gameweek' => $gw,
        'rows' => $total,
        'unique_fixtures' => $unique,
        'premier_league_rows' => $plRows,
        'missing_home_logos' => (int)($row['missing_home_logos'] ?? 0),
        'missing_away_logos' => (int)($row['missing_away_logos'] ?? 0),
    ];
}

function validateBotSql(string $sql, int $gw): void
{
    $sql = trim($sql);

    if ($sql === '') {
        fail('Missing SQL.', 400);
    }

    $withoutTrailingSemicolon = rtrim($sql, " \t\r\n;");

    if (strpos($withoutTrailingSemicolon, ';') !== false) {
        fail('Only one SQL statement is allowed.', 400);
    }

    if (preg_match('/\b(DROP|DELETE|UPDATE|ALTER|TRUNCATE|REPLACE|CREATE|GRANT|REVOKE|CALL|LOAD\s+DATA|INTO\s+OUTFILE|INTO\s+DUMPFILE|SET)\b/i', $sql)) {
        fail('Rejected SQL: destructive or administrative statements are not allowed.', 403);
    }

    if (!preg_match('/^INSERT\s+INTO\s+`?matches`?\s*\(/i', $withoutTrailingSemicolon)) {
        fail('Rejected SQL: only INSERT INTO matches is accepted.', 403);
    }

    $requiredColumns = [
        'home_team',
        'home_team_pic',
        'away_team',
        'away_team_pic',
        'match_date',
        'home_score',
        'away_score',
        'gameweek',
        'deadline',
        'competition',
    ];

    foreach ($requiredColumns as $column) {
        if (!preg_match('/\b' . preg_quote($column, '/') . '\b/i', $sql)) {
            fail('Rejected SQL: missing required column ' . $column . '.', 403);
        }
    }

    if (!preg_match('/\bVALUES\b/i', $sql)) {
        fail('Rejected SQL: VALUES section is missing.', 403);
    }

    $gwPattern = '/,\s*' . preg_quote((string)$gw, '/') . '\s*,\s*NULL\s*,\s*[\'"]Premier League[\'"]/i';

    if (!preg_match($gwPattern, $sql)) {
        fail('Rejected SQL: SQL gameweek does not match the requested gameweek.', 403);
    }

    $tupleCount = preg_match_all('/\(\s*[\'"]/m', $sql, $unused);

    if ($tupleCount !== 10) {
        fail('Rejected SQL: expected exactly 10 generated fixture rows.', 403);
    }
}


/*
|--------------------------------------------------------------------------
| MONITORING HELPERS (read-only + notification claim)
|--------------------------------------------------------------------------
|
| Added for the user-activity / prediction-deadline monitor.
| None of these functions modify matches, predictions, score_exact,
| users, gameweek_deadlines or any scoring data.
|
*/

function siteNowString(): string
{
    try {
        $now = new DateTime('now', new DateTimeZone(SITE_TIMEZONE));
        return $now->format('Y-m-d H:i:s');
    } catch (Throwable $e) {
        return date('Y-m-d H:i:s');
    }
}

function tableExists($db, string $name): bool
{
    $safe = preg_replace('/[^A-Za-z0-9_]/', '', $name);

    if ($safe === '') {
        return false;
    }

    try {
        $rows = queryRows($db, "SHOW TABLES LIKE '" . $safe . "'");
        return count($rows) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function executeSqlAffected($db, string $sql): int
{
    if ($db instanceof mysqli) {
        if (!$db->query($sql)) {
            fail('Database write failed: ' . $db->error, 500);
        }
        return (int)$db->affected_rows;
    }

    if ($db instanceof PDO) {
        try {
            return (int)$db->exec($sql);
        } catch (Throwable $e) {
            fail('Database write failed: ' . $e->getMessage(), 500);
        }
    }

    fail('Unsupported database connection.', 500);
}

/**
 * Deadline for every gameweek, using EXACTLY the same priority as
 * gameweek_deadline.php:
 *
 *   1. gameweek_deadlines.deadline
 *   2. MIN(matches.deadline)
 *   3. MIN(matches.match_date)
 */
function allGameweekDeadlines($db): array
{
    $map = [];

    $rows = queryRows(
        $db,
        "SELECT
            gameweek,
            MIN(match_date) AS first_match,
            MIN(deadline) AS match_deadline,
            COUNT(*) AS total_matches,
            SUM(CASE WHEN competition = 'Premier League' THEN 1 ELSE 0 END) AS premier_league_matches
         FROM matches
         GROUP BY gameweek"
    );

    foreach ($rows as $row) {
        $gw = (int)$row['gameweek'];

        $map[$gw] = [
            'gameweek' => $gw,
            'first_match' => $row['first_match'],
            'match_deadline' => $row['match_deadline'],
            'gameweek_deadline' => null,
            'total_matches' => (int)$row['total_matches'],
            'premier_league_matches' => (int)$row['premier_league_matches'],
        ];
    }

    if (tableExists($db, 'gameweek_deadlines')) {
        foreach (queryRows($db, "SELECT gameweek, deadline FROM gameweek_deadlines") as $row) {
            $gw = (int)$row['gameweek'];

            if (!isset($map[$gw])) {
                continue;
            }

            $map[$gw]['gameweek_deadline'] = $row['deadline'];
        }
    }

    foreach ($map as $gw => $info) {
        if (!empty($info['gameweek_deadline'])) {
            $map[$gw]['deadline'] = $info['gameweek_deadline'];
            $map[$gw]['deadline_source'] = 'gameweek_deadlines';
        } elseif (!empty($info['match_deadline'])) {
            $map[$gw]['deadline'] = $info['match_deadline'];
            $map[$gw]['deadline_source'] = 'matches.deadline';
        } elseif (!empty($info['first_match'])) {
            $map[$gw]['deadline'] = $info['first_match'];
            $map[$gw]['deadline_source'] = 'first_match_date';
        } else {
            $map[$gw]['deadline'] = null;
            $map[$gw]['deadline_source'] = null;
        }

        $map[$gw]['total_matches'] = (int)$map[$gw]['total_matches'];
        $map[$gw]['premier_league_matches'] = (int)$map[$gw]['premier_league_matches'];
        $map[$gw]['other_matches'] =
            $map[$gw]['total_matches'] - $map[$gw]['premier_league_matches'];
    }

    ksort($map);

    return $map;
}

/**
 * The gameweek users are currently predicting for:
 * the EARLIEST gameweek whose deadline has not passed yet.
 * If every deadline has passed, the latest gameweek is used.
 */
function activeGameweek(array $deadlines): ?int
{
    if (count($deadlines) === 0) {
        return null;
    }

    $nowStamp = strtotime(siteNowString());

    foreach ($deadlines as $gw => $info) {
        if (empty($info['deadline'])) {
            continue;
        }

        $stamp = strtotime((string)$info['deadline']);

        if ($stamp !== false && $stamp > $nowStamp) {
            return (int)$gw;
        }
    }

    $keys = array_keys($deadlines);

    return (int)max($keys);
}

$action = strtolower(trim((string)($_GET['action'] ?? '')));

try {
    switch ($action) {
        case 'status':
            $latest = latestPremierLeagueGameweek($dbh);
            $next = ($latest === null) ? 1 : $latest + 1;

            if ($next > MAX_GAMEWEEK) {
                $next = null;
            }

            respond([
                'success' => true,
                'latest_gameweek' => $latest,
                'next_gameweek' => $next,
                'max_gameweek' => MAX_GAMEWEEK,
            ]);

        case 'exists':
            $gw = getGameweek();
            $count = gameweekCount($dbh, $gw);

            respond([
                'success' => true,
                'gameweek' => $gw,
                'exists' => $count > 0,
                'count' => $count,
            ]);

        case 'verify':
            $gw = getGameweek();
            respond([
                'success' => true,
                ...verifyGameweek($dbh, $gw),
            ]);

        case 'matches':
            $gw = getGameweek();

            $rows = queryRows(
                $dbh,
                "SELECT
                    id,
                    home_team,
                    away_team,
                    home_score,
                    away_score,
                    match_date,
                    gameweek,
                    competition
                 FROM matches
                 WHERE gameweek = " . $gw . "
                 ORDER BY match_date ASC"
            );

            $matches = array_map(static function (array $row): array {
                return [
                    'id' => (int)$row['id'],
                    'home_team' => $row['home_team'],
                    'away_team' => $row['away_team'],
                    'home_score' => $row['home_score'] === null ? null : (int)$row['home_score'],
                    'away_score' => $row['away_score'] === null ? null : (int)$row['away_score'],
                    'match_date' => $row['match_date'],
                    'gameweek' => (int)$row['gameweek'],
                    'competition' => $row['competition'],
                ];
            }, $rows);

            respond([
                'success' => true,
                'gameweek' => $gw,
                'count' => count($matches),
                'matches' => $matches,
            ]);

        case 'import':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                fail('Import must use POST.', 405);
            }

            $gw = getGameweek();
            $sql = (string)($_POST['sql'] ?? '');

            if (gameweekCount($dbh, $gw) > 0) {
                fail("GW{$gw} already exists. Import cancelled.", 409);
            }

            validateBotSql($sql, $gw);

            executeSql($dbh, $sql);

            $verification = verifyGameweek($dbh, $gw);

            if (!$verification['verified']) {
                fail('Import completed, but post-import verification failed.', 500);
            }

            respond([
                'success' => true,
                'message' => "GW{$gw} imported successfully.",
                'gameweek' => $gw,
                'verification' => $verification,
            ]);

        case 'activity':
            $hasActivity = tableExists($dbh, 'user_activity');

            if ($hasActivity) {
                $rows = queryRows(
                    $dbh,
                    "SELECT
                        u.id AS user_id,
                        u.username,
                        u.email,
                        u.created_at AS registered_at,
                        a.last_login,
                        a.login_count,
                        a.last_activity,
                        a.session_count,
                        a.total_active_seconds
                     FROM users u
                     LEFT JOIN user_activity a ON a.user_id = u.id
                     ORDER BY u.id ASC"
                );
            } else {
                $rows = queryRows(
                    $dbh,
                    "SELECT
                        u.id AS user_id,
                        u.username,
                        u.email,
                        u.created_at AS registered_at
                     FROM users u
                     ORDER BY u.id ASC"
                );
            }

            $users = array_map(static function (array $row): array {
                return [
                    'user_id' => (int)$row['user_id'],
                    'username' => $row['username'],
                    'email' => $row['email'],
                    'registered_at' => $row['registered_at'] ?? null,
                    'last_login' => $row['last_login'] ?? null,
                    'login_count' => isset($row['login_count'])
                        ? (int)$row['login_count'] : 0,
                    'last_activity' => $row['last_activity'] ?? null,
                    'session_count' => isset($row['session_count'])
                        ? (int)$row['session_count'] : 0,
                    'total_active_seconds' => isset($row['total_active_seconds'])
                        ? (int)$row['total_active_seconds'] : 0,
                ];
            }, $rows);

            respond([
                'success' => true,
                'activity_tracking' => $hasActivity,
                'server_time' => siteNowString(),
                'timezone' => SITE_TIMEZONE,
                'total_users' => count($users),
                'users' => $users,
            ]);

        case 'gameweek_report':
            $deadlines = allGameweekDeadlines($dbh);

            $requested = filter_input(INPUT_GET, 'gameweek', FILTER_VALIDATE_INT);

            if ($requested !== false && $requested !== null) {
                $gw = getGameweek();
            } else {
                $gw = activeGameweek($deadlines);
            }

            if ($gw === null) {
                fail('No gameweeks found in the matches table.', 404);
            }

            $info = $deadlines[$gw] ?? null;

            if ($info === null) {
                fail("GW{$gw} has no matches.", 404);
            }

            $required = (int)$info['total_matches'];

            $userRows = queryRows(
                $dbh,
                "SELECT
                    u.id AS user_id,
                    u.username,
                    u.email,
                    (SELECT COUNT(*)
                       FROM score_exact se
                       JOIN matches m ON m.id = se.match_id
                      WHERE se.user_id = u.id
                        AND m.gameweek = " . $gw . ") AS submitted,
                    (SELECT COUNT(*)
                       FROM score_exact se
                       JOIN matches m ON m.id = se.match_id
                      WHERE se.user_id = u.id
                        AND m.gameweek = " . $gw . "
                        AND m.competition = 'Premier League') AS submitted_premier_league,
                    (SELECT MAX(se.created_at)
                       FROM score_exact se
                       JOIN matches m ON m.id = se.match_id
                      WHERE se.user_id = u.id
                        AND m.gameweek = " . $gw . ") AS last_prediction_at
                 FROM users u
                 ORDER BY u.id ASC"
            );

            $users = [];
            $completed = 0;

            foreach ($userRows as $row) {
                $submitted = (int)$row['submitted'];
                $isComplete = ($required > 0 && $submitted >= $required);

                if ($isComplete) {
                    $completed++;
                }

                $users[] = [
                    'user_id' => (int)$row['user_id'],
                    'username' => $row['username'],
                    'email' => $row['email'],
                    'submitted' => $submitted,
                    'submitted_premier_league' => (int)$row['submitted_premier_league'],
                    'missing' => max(0, $required - $submitted),
                    'completed' => $isComplete,
                    'last_prediction_at' => $row['last_prediction_at'],
                ];
            }

            $totalUsers = count($users);

            respond([
                'success' => true,
                'gameweek' => $gw,
                'server_time' => siteNowString(),
                'timezone' => SITE_TIMEZONE,
                'deadline' => $info['deadline'],
                'deadline_source' => $info['deadline_source'],
                'required_predictions' => $required,
                'total_matches' => (int)$info['total_matches'],
                'premier_league_matches' => (int)$info['premier_league_matches'],
                'other_matches' => (int)$info['other_matches'],
                'total_users' => $totalUsers,
                'completed_users' => $completed,
                'incomplete_users' => $totalUsers - $completed,
                'users' => $users,
            ]);

        case 'notify_claim':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                fail('notify_claim must use POST.', 405);
            }

            if (!tableExists($dbh, 'bot_notifications')) {
                respond([
                    'success' => true,
                    'available' => false,
                    'claimed' => false,
                    'reason' => 'bot_notifications table not installed.',
                ]);
            }

            $key = (string)($_POST['key'] ?? '');

            if (!preg_match('/^[A-Za-z0-9:_\-\.]{1,150}$/', $key)) {
                fail('Invalid notification key.', 400);
            }

            $affected = executeSqlAffected(
                $dbh,
                "INSERT IGNORE INTO bot_notifications (notification_key)
                 VALUES ('" . $key . "')"
            );

            respond([
                'success' => true,
                'available' => true,
                'key' => $key,
                'claimed' => $affected > 0,
                'server_time' => siteNowString(),
            ]);

        default:
            fail('Unknown action. Use status, exists, verify, matches, import, activity, gameweek_report, or notify_claim.', 400);
    }
} catch (Throwable $e) {
    fail('Server error: ' . $e->getMessage(), 500);
}