    <?php
    /*
    * FPL Fixture Bot API
    *
    * Upload this file to the same directory as connect.php.
    * IMPORTANT: replace BOT_API_TOKEN with a long random secret and put the
    * exact same value in GitHub Secret BOT_API_TOKEN.
    */

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    require_once 'connect.php';

    const BOT_API_TOKEN = '/mRFqxl+J4ulwajn0mB6q2B5wWtOcHd32ORyeBvZIT8=';

    function respond(array $data, int $status = 200): never
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $provided = (string)($_GET['token'] ?? '');
    if ($provided === '' || !hash_equals(BOT_API_TOKEN, $provided)) {
        respond(['success' => false, 'error' => 'Unauthorized.'], 401);
    }

    $action = (string)($_GET['action'] ?? 'status');

    if ($action === 'status') {
        $sql = "SELECT MAX(gameweek) AS latest_gameweek
                FROM matches
                WHERE competition = 'Premier League'
                AND gameweek BETWEEN 1 AND 38";

        $result = $conn->query($sql);
        if (!$result) {
            respond(['success' => false, 'error' => $conn->error], 500);
        }

        $row = $result->fetch_assoc();
        $latest = isset($row['latest_gameweek']) && $row['latest_gameweek'] !== null
            ? (int)$row['latest_gameweek']
            : null;

        respond([
            'success' => true,
            'competition' => 'Premier League',
            'latest_gameweek' => $latest,
            'next_gameweek' => $latest === null ? 1 : ($latest < 38 ? $latest + 1 : null),
        ]);
    }

    if ($action === 'exists') {
        $gw = (int)($_GET['gameweek'] ?? 0);
        if ($gw < 1 || $gw > 38) {
            respond(['success' => false, 'error' => 'Invalid gameweek.'], 400);
        }

        $stmt = $conn->prepare("SELECT COUNT(*) AS total
                                FROM matches
                                WHERE gameweek = ?
                                AND competition = 'Premier League'");
        if (!$stmt) {
            respond(['success' => false, 'error' => $conn->error], 500);
        }
        $stmt->bind_param('i', $gw);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $count = (int)($row['total'] ?? 0);
        respond([
            'success' => true,
            'gameweek' => $gw,
            'exists' => $count > 0,
            'match_count' => $count,
        ]);
    }

    if ($action === 'verify') {
        $gw = (int)($_GET['gameweek'] ?? 0);
        if ($gw < 1 || $gw > 38) {
            respond(['success' => false, 'error' => 'Invalid gameweek.'], 400);
        }

        $stmt = $conn->prepare("SELECT id, home_team, away_team, match_date, home_score, away_score,
                                    gameweek, deadline, competition
                                FROM matches
                                WHERE gameweek = ?
                                AND competition = 'Premier League'
                                ORDER BY match_date ASC, id ASC");
        if (!$stmt) {
            respond(['success' => false, 'error' => $conn->error], 500);
        }
        $stmt->bind_param('i', $gw);
        $stmt->execute();
        $rs = $stmt->get_result();
        $matches = [];
        while ($r = $rs->fetch_assoc()) {
            $matches[] = $r;
        }
        $stmt->close();

        $valid = count($matches) === 10;
        foreach ($matches as $m) {
            if ($m['competition'] !== 'Premier League' || (int)$m['gameweek'] !== $gw) $valid = false;
            if ($m['home_score'] !== null || $m['away_score'] !== null) $valid = false;
            if ($m['deadline'] !== null) $valid = false;
            if (empty($m['home_team']) || empty($m['away_team']) || empty($m['match_date'])) $valid = false;
        }

        respond([
            'success' => true,
            'verified' => $valid,
            'gameweek' => $gw,
            'match_count' => count($matches),
            'matches' => $matches,
        ]);
    }

    respond(['success' => false, 'error' => 'Unknown action.'], 400);
