<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

const BOT_API_TOKEN = 'jpLKn6QtsW9uTSEbCv_bPujs5tQA6vL0eNTmSc8gUYqzBqgfy9O_ct6P6mwKyl05';
const MAX_GAMEWEEK = 38;

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

        default:
            fail('Unknown action. Use status, exists, verify, matches, or import.', 400);
    }
} catch (Throwable $e) {
    fail('Server error: ' . $e->getMessage(), 500);
}