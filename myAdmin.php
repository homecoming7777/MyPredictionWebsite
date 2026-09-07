<?php

session_start();
include 'connect.php';
require_once 'points_helper.php';
require_once 'ships_helper.php';
/*
|--------------------------------------------------------------------------
| ADMIN CHECK
|--------------------------------------------------------------------------
|
| If your project already has an admin authentication system,
| replace this section with your existing admin check.
|
*/

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}


/*
|--------------------------------------------------------------------------
| HELPER FUNCTIONS
|--------------------------------------------------------------------------
*/

/**
 * Convert a score into a result:
 *
 * 1 = Home win
 * 0 = Draw
 * -1 = Away win
 */
function getResult($home, $away)
{
    if ($home > $away) {
        return 1;
    }

    if ($home < $away) {
        return -1;
    }

    return 0;
}


/**
 * Calculate prediction points.
 *
 * Exact score = 3
 * Correct result = 1
 * Wrong = 0
 */
function calculatePoints($predHome, $predAway, $realHome, $realAway)
{
    // Exact score
    if (
        (int)$predHome === (int)$realHome &&
        (int)$predAway === (int)$realAway
    ) {
        return 3;
    }

    // Correct result
    $predResult = getResult(
        (int)$predHome,
        (int)$predAway
    );

    $realResult = getResult(
        (int)$realHome,
        (int)$realAway
    );

    if ($predResult === $realResult) {
        return 1;
    }

    return 0;
}


/**
 * Send WhatsApp message.
 */
function sendWhatsApp($phone, $message, $apikey)
{
    $url =
        "https://api.callmebot.com/whatsapp.php" .
        "?phone=" . urlencode($phone) .
        "&text=" . urlencode($message) .
        "&apikey=" . urlencode($apikey);

    $ch = curl_init($url);

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);

    curl_close($ch);

    return $response !== false;
}


/*
|--------------------------------------------------------------------------
| VARIABLES
|--------------------------------------------------------------------------
*/

$message = '';
$message_type = '';

$calculated_count = 0;
$total_points_added = 0;


/*
|--------------------------------------------------------------------------
| GAMEWEEKS
|--------------------------------------------------------------------------
*/

$gw_query = $conn->query("
    SELECT DISTINCT gameweek
    FROM matches
    ORDER BY gameweek ASC
");

$gameweeks = [];

while ($g = $gw_query->fetch_assoc()) {
    $gameweeks[] = (int)$g['gameweek'];
}


/*
|--------------------------------------------------------------------------
| SELECTED GAMEWEEK
|--------------------------------------------------------------------------
*/

$selected_gw = isset($_GET['gw'])
    ? (int)$_GET['gw']
    : null;


/*
|--------------------------------------------------------------------------
| SAVE RESULT + CALCULATE POINTS
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['save_result'])
) {

    $match_id = (int)($_POST['match_id'] ?? 0);

    $home_score = isset($_POST['home_score'])
        ? (int)$_POST['home_score']
        : -1;

    $away_score = isset($_POST['away_score'])
        ? (int)$_POST['away_score']
        : -1;

    $current_gw = (int)($_POST['current_gw'] ?? 0);


    /*
    |--------------------------------------------------------------------------
    | Validate scores
    |--------------------------------------------------------------------------
    */

    if ($match_id <= 0) {

        $message = "Invalid match.";
        $message_type = "error";

    } elseif ($home_score < 0 || $away_score < 0) {

        $message = "Scores cannot be negative.";
        $message_type = "error";

    } else {

        try {

            /*
            |--------------------------------------------------------------------------
            | Start transaction
            |--------------------------------------------------------------------------
            */

            $conn->begin_transaction();


            /*
            |--------------------------------------------------------------------------
            | Get match
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                SELECT
                    id,
                    home_team,
                    away_team
                FROM matches
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->bind_param("i", $match_id);
            $stmt->execute();

            $match_result = $stmt->get_result();
            $match = $match_result->fetch_assoc();

            $stmt->close();


            if (!$match) {

                throw new Exception("Match not found.");

            }


            /*
            |--------------------------------------------------------------------------
            | Update REAL SCORE
            |--------------------------------------------------------------------------
            */

            $stmt = $conn->prepare("
                UPDATE matches
                SET
                    home_score = ?,
                    away_score = ?
                WHERE id = ?
            ");

            $stmt->bind_param(
                "iii",
                $home_score,
                $away_score,
                $match_id
            );

            $stmt->execute();

            $stmt->close();


            /*
            |--------------------------------------------------------------------------
            | Get ALL user predictions
            |--------------------------------------------------------------------------
            |
            | score_exact is the main prediction table.
            |
            */

            $stmt = $conn->prepare("
                SELECT
                    id,
                    user_id,
                    predicted_home,
                    predicted_away
                FROM score_exact
                WHERE match_id = ?
            ");

            $stmt->bind_param("i", $match_id);
            $stmt->execute();

            $predictions_result = $stmt->get_result();


            /*
            |--------------------------------------------------------------------------
            | Update each prediction
            |--------------------------------------------------------------------------
            */

            while ($prediction = $predictions_result->fetch_assoc()) {

    /*
     * --------------------------------------------------------
     * RECALCULATE EVERYTHING THROUGH CENTRAL POINTS HELPER
     * --------------------------------------------------------
     *
     * points_helper.php now computes base_points (0/1/3) itself
     * from the real score that was just saved above, then applies
     * the Double Pick multiplier and updates the secondary
     * predictions table. Nothing else needs to happen here.
     */

    syncPredictionPoints(
        $conn,
        (int)$prediction['user_id'],
        $match_id
    );


    /*
     * --------------------------------------------------------
     * GET THE FINAL POINTS FOR THE ADMIN MESSAGE
     * --------------------------------------------------------
     */

    $points_stmt = $conn->prepare("
        SELECT
            base_points,
            points
        FROM score_exact
        WHERE id = ?
        LIMIT 1
    ");

    if (!$points_stmt) {
        throw new Exception(
            "Unable to read calculated points: " .
            $conn->error
        );
    }

    $prediction_id = (int)$prediction['id'];

    $points_stmt->bind_param(
        "i",
        $prediction_id
    );

    $points_stmt->execute();

    $points_row =
        $points_stmt
            ->get_result()
            ->fetch_assoc();

    $points_stmt->close();


    $points = (int)(
        $points_row['points'] ?? 0
    );


    /*
     * --------------------------------------------------------
     * UPDATE SECONDARY predictions TABLE
     * --------------------------------------------------------
     *
     * Keep the existing actual result + correctness
     * information synchronized.
     */

    $predicted_result = getResult(
        (int)$prediction['predicted_home'],
        (int)$prediction['predicted_away']
    );

    $real_result = getResult(
        $home_score,
        $away_score
    );

    $is_correct =
        ($predicted_result === $real_result)
        ? 1
        : 0;


    $update_general = $conn->prepare("
        UPDATE predictions
        SET
            actual_home = ?,
            actual_away = ?,
            is_correct = ?,
            points = ?
        WHERE user_id = ?
          AND match_id = ?
    ");

    if (!$update_general) {
        throw new Exception(
            "Unable to update predictions table: " .
            $conn->error
        );
    }


    $update_general->bind_param(
        "iiiiii",
        $home_score,
        $away_score,
        $is_correct,
        $points,
        $prediction['user_id'],
        $match_id
    );


    $update_general->execute();

    $update_general->close();


    /*
     * Statistics.
     */
    $calculated_count++;

    $total_points_added += $points;
}

            $stmt->close();


            /*
            |--------------------------------------------------------------------------
            | Commit
            |--------------------------------------------------------------------------
            */

            $conn->commit();


            $message =
                "Result saved: " .
                htmlspecialchars($match['home_team']) .
                " " .
                $home_score .
                " - " .
                $away_score .
                " " .
                htmlspecialchars($match['away_team']) .
                ". " .
                $calculated_count .
                " prediction(s) calculated.";

            $message_type = "success";


        } catch (Exception $e) {

            /*
            |--------------------------------------------------------------------------
            | Rollback if anything fails
            |--------------------------------------------------------------------------
            */

            $conn->rollback();

            $message = "Error: " . $e->getMessage();
            $message_type = "error";
        }
    }
}


/*
|--------------------------------------------------------------------------
| WHATSAPP DEFAULT MESSAGE
|--------------------------------------------------------------------------
*/

$wa_sent_count = 0;
$wa_debug = [];

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['send_whatsapp'])
) {

    $sql = "
        SELECT
            username,
            phone_number,
            wa_apikey
        FROM users
        WHERE
            phone_number IS NOT NULL
            AND phone_number <> ''
            AND wa_apikey IS NOT NULL
            AND wa_apikey <> ''
    ";

    $res = $conn->query($sql);

    while ($row = $res->fetch_assoc()) {

        $phone = $row['phone_number'];
        $name = $row['username'];
        $apikey = $row['wa_apikey'];

        $message_text =
            "Hi " .
            $name .
            " 👋\n\n" .
            "The next gameweek is ready 💪\n" .
            "Don't forget to submit your predictions! ⚽🔥\n\n" .
            "https://plpredictions.42web.io/login.php";

        $sent = sendWhatsApp(
            $phone,
            $message_text,
            $apikey
        );

        $wa_debug[] = [
            'phone' => $phone,
            'sent' => $sent
        ];

        if ($sent) {
            $wa_sent_count++;
        }
    }

    $message =
        "WhatsApp reminder sent to " .
        $wa_sent_count .
        " user(s).";

    $message_type = "success";
}


/*
|--------------------------------------------------------------------------
| CUSTOM WHATSAPP
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['send_custom_whatsapp'])
) {

    $custom = trim($_POST['custom_msg'] ?? '');

    if ($custom === '') {

        $message = "Please write a message.";
        $message_type = "error";

    } else {

        $sql = "
            SELECT
                username,
                phone_number,
                wa_apikey
            FROM users
            WHERE
                phone_number IS NOT NULL
                AND phone_number <> ''
                AND wa_apikey IS NOT NULL
                AND wa_apikey <> ''
        ";

        $res = $conn->query($sql);

        $wa_sent_count = 0;
        $wa_debug = [];

        while ($row = $res->fetch_assoc()) {

            $phone = $row['phone_number'];
            $name = $row['username'];
            $apikey = $row['wa_apikey'];

            $message_text = str_replace(
                "{name}",
                $name,
                $custom
            );

            $sent = sendWhatsApp(
                $phone,
                $message_text,
                $apikey
            );

            $wa_debug[] = [
                'phone' => $phone,
                'sent' => $sent
            ];

            if ($sent) {
                $wa_sent_count++;
            }
        }

        $message =
            "Custom WhatsApp message sent to " .
            $wa_sent_count .
            " user(s).";

        $message_type = "success";
    }
}


/*
|--------------------------------------------------------------------------
| BULK PREMIER LEAGUE MATCH IMPORT
|--------------------------------------------------------------------------
|
| Allows the admin to paste one or more SQL INSERT statements for the
| matches table and execute them in one shot.
|
| Only INSERT statements targeting the matches table are accepted here.
| This prevents accidentally executing DELETE, UPDATE, DROP, etc.
|
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['bulk_import_matches'])
) {

    $bulk_sql = trim($_POST['bulk_matches_sql'] ?? '');
    $bulk_gw = (int)($_POST['bulk_gameweek'] ?? 0);

    if ($bulk_sql === '') {

        $message = "Please paste the SQL script for the gameweek matches.";
        $message_type = "error";

    } elseif ($bulk_gw < 1 || $bulk_gw > 38) {

        $message = "Please select a valid gameweek between 1 and 38.";
        $message_type = "error";

    } else {

        /*
        |--------------------------------------------------------------------------
        | Split SQL statements without breaking semicolons inside quoted strings.
        |--------------------------------------------------------------------------
        */

        $statements = [];
        $current = '';
        $quote = null;
        $length = strlen($bulk_sql);

        for ($i = 0; $i < $length; $i++) {

            $char = $bulk_sql[$i];
            $next = ($i + 1 < $length) ? $bulk_sql[$i + 1] : '';

            if ($quote !== null) {

                $current .= $char;

                if ($char === '\\' && $i + 1 < $length) {
                    $current .= $bulk_sql[++$i];
                    continue;
                }

                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
                continue;
            }

            if ($char === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
                continue;
            }

            $current .= $char;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }

        /* Remove SQL comments and validate every statement. */
        $safe_statements = [];
        $invalid_statement = false;

        foreach ($statements as $statement) {

            $clean = preg_replace('/^\s*(?:--[^\r\n]*\r?\n|#[^\r\n]*\r?\n|\/\*.*?\*\/\s*)+/s', '', $statement);
            $clean = trim($clean);

            if ($clean === '') {
                continue;
            }

            /* Only INSERT INTO matches is allowed. */
            if (!preg_match('/^INSERT\s+INTO\s+`?matches`?\s*(?:\(|VALUES)/i', $clean)) {
                $invalid_statement = true;
                break;
            }

            /* Prevent INSERT ... SELECT from importing arbitrary data. */
            if (preg_match('/\bINSERT\s+INTO\s+`?matches`?\s+SELECT\b/i', $clean)) {
                $invalid_statement = true;
                break;
            }

            $safe_statements[] = $clean;
        }

        if ($invalid_statement || empty($safe_statements)) {

            $message =
                "Invalid SQL script. Only INSERT INTO matches (...) VALUES (...) statements are allowed.";
            $message_type = "error";

        } else {

            try {

                $conn->begin_transaction();

                $inserted_rows = 0;

                foreach ($safe_statements as $statement) {

                    /*
                    |--------------------------------------------------------------------------
                    | Force the selected gameweek into the imported rows when the SQL uses
                    | the matches table but accidentally contains another gameweek value.
                    |
                    | We do not rewrite the SQL because that could corrupt a valid script.
                    | Instead, the admin-selected gameweek is informational/verification.
                    | The script itself remains the source of the match data.
                    |--------------------------------------------------------------------------
                    */

                    if (!$conn->query($statement)) {
                        throw new Exception($conn->error);
                    }

                    $inserted_rows += (int)$conn->affected_rows;
                }

                $conn->commit();

                $selected_gw = $bulk_gw;

                $message =
                    "Gameweek " .
                    $bulk_gw .
                    " imported successfully. " .
                    $inserted_rows .
                    " match(es) created in the database.";

                $message_type = "success";

            } catch (Throwable $e) {

                $conn->rollback();

                $message =
                    "Import failed. No changes were saved. Error: " .
                    $e->getMessage();

                $message_type = "error";
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| LOAD MATCHES
|--------------------------------------------------------------------------
*/

$matches = [];

if ($selected_gw) {

    $stmt = $conn->prepare("
        SELECT
            *
        FROM matches
        WHERE gameweek = ?
        ORDER BY match_date ASC
    ");

    $stmt->bind_param(
        "i",
        $selected_gw
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $matches[] = $row;
    }

    $stmt->close();
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Admin Dashboard | Premier League</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <style>
        body {
            background-image: url('PL_img/current.jpg');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            background-color: #1c003a;
            font-family: Arial, Helvetica, sans-serif;
        }
        
        body::before {
            content: "";
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(10, 0, 21, 0.85);
            z-index: -1;
            pointer-events: none;
        }
    </style>

</head>

<body class="text-white min-h-screen">


<!-- =========================================================
     HEADER
========================================================= -->

<header class="border-b border-[#ff0080]/30 bg-[#1c003a]/80 backdrop-blur-xl sticky top-0 z-50">

    <div class="max-w-7xl mx-auto px-6 py-5">

        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">

            <div>

                <h1 class="text-3xl font-black text-white">
                    Admin Dashboard
                </h1>

                <p class="text-gray-300 mt-1">
                    Manage match results and calculate prediction points.
                </p>

            </div>

            <div class="flex flex-col sm:flex-row gap-3">
                
                <a
                    href="manage_other_matches.php"
                    class="bg-[#ff0080] hover:bg-[#ff1a66] text-black px-5 py-2.5 rounded-xl font-black transition transform hover:-translate-y-0.5"
                >
                    Manage Other Leagues
                </a>

                <a
                    href="manage_deadlines.php"
                    class="bg-[#ff9900] hover:bg-[#ffb84d] text-black px-5 py-2.5 rounded-xl font-black transition transform hover:-translate-y-0.5"
                >
                    Manage Deadlines
                </a>

                <a
                    href="dashboard.php"
                    class="bg-white/10 hover:bg-white/20 border border-white/20 px-5 py-2.5 rounded-xl font-bold transition"
                >
                    Back to Website
                </a>

            </div>

        </div>

    </div>

</header>


<main class="max-w-7xl mx-auto px-6 py-8">


<!-- =========================================================
     FLASH MESSAGE
========================================================= -->

<?php if ($message !== ''): ?>

    <div
        class="mb-6 p-4 rounded-xl border backdrop-blur-md
        <?= $message_type === 'success'
            ? 'bg-green-500/10 border-green-500/30 text-green-300'
            : 'bg-red-500/10 border-red-500/30 text-red-300'
        ?>"
    >

        <div class="font-semibold">
            <?= $message ?>
        </div>

        <?php if ($message_type === 'success' && $calculated_count > 0): ?>

            <div class="text-sm mt-2 opacity-80">

                <?= $calculated_count ?>
                prediction(s) calculated.

                Total points awarded:
                <strong><?= $total_points_added ?></strong>

            </div>

        <?php endif; ?>

    </div>

<?php endif; ?>


<!-- =========================================================
     GAMEWEEK SELECTOR
========================================================= -->

<section class="bg-[#1c003a]/80 backdrop-blur-xl border border-[#ff0080]/30 rounded-2xl p-6 mb-8">

    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">

        <div>

            <h2 class="text-xl font-black">
                Match Results
            </h2>

            <p class="text-gray-300 text-sm mt-1">
                Select a gameweek to manage its matches.
            </p>

        </div>


        <form method="GET">

            <select
                name="gw"
                onchange="this.form.submit()"
                class="bg-black/40 border-2 border-[#ff0080]/50 text-white px-5 py-3 rounded-xl outline-none focus:border-[#ff9900] transition"
            >

                <option value="">
                    -- Select Gameweek --
                </option>

                <?php foreach ($gameweeks as $gw): ?>

                    <option
                        value="<?= $gw ?>"
                        <?= ($selected_gw == $gw) ? 'selected' : '' ?>
                    >
                        Gameweek <?= $gw ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </form>

    </div>

</section>


<!-- =========================================================
     BULK PREMIER LEAGUE MATCH IMPORT
========================================================= -->

<section class="bg-[#1c003a]/80 backdrop-blur-xl border border-[#ff9900]/30 rounded-2xl p-6 mb-8">

    <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-6">

        <div class="lg:max-w-md">

            <div class="flex items-center gap-3">

                <div class="text-3xl">⚽</div>

                <div>

                    <h2 class="text-xl font-black text-white">
                        Bulk Add Premier League Gameweek
                    </h2>

                    <p class="text-gray-300 text-sm mt-1">
                        Paste the complete SQL INSERT script for a gameweek and create all matches at once.
                    </p>

                </div>

            </div>

            <div class="mt-5 p-4 rounded-xl bg-[#ff9900]/10 border border-[#ff9900]/20 text-sm text-gray-300">

                <p class="font-bold text-[#ff9900] mb-2">
                    Important
                </p>

                <ul class="list-disc list-inside space-y-1">
                    <li>Only <code class="text-white">INSERT INTO matches ... VALUES ...</code> statements are accepted.</li>
                    <li>Use <code class="text-white">NULL</code> for match deadlines.</li>
                    <li>The centralized gameweek deadline is managed separately in <strong>Manage Deadlines</strong>.</li>
                    <li>You can paste one multi-row INSERT or several INSERT statements.</li>
                </ul>

            </div>

        </div>


        <form method="POST" class="w-full lg:flex-1">

            <label class="block text-sm font-bold text-gray-200 mb-2">
                Gameweek
            </label>

            <select
                name="bulk_gameweek"
                required
                class="w-full bg-black/40 border-2 border-[#ff9900]/40 focus:border-[#ff9900] text-white px-4 py-3 rounded-xl outline-none transition mb-4"
            >

                <option value="">
                    -- Select Gameweek --
                </option>

                <?php for ($bulkGwOption = 1; $bulkGwOption <= 38; $bulkGwOption++): ?>

                    <option
                        value="<?= $bulkGwOption ?>"
                        <?= ((int)($selected_gw ?? 0) === $bulkGwOption) ? 'selected' : '' ?>
                    >
                        Gameweek <?= $bulkGwOption ?>
                    </option>

                <?php endfor; ?>

            </select>


            <label class="block text-sm font-bold text-gray-200 mb-2">
                Gameweek SQL Script
            </label>

            <textarea
                name="bulk_matches_sql"
                rows="14"
                required
                spellcheck="false"
                placeholder="INSERT INTO matches (home_team, home_team_pic, away_team, away_team_pic, match_date, home_score, away_score, gameweek, deadline, competition) VALUES (...);"
                class="w-full bg-black/50 border-2 border-white/10 focus:border-[#ff9900] text-white px-4 py-3 rounded-xl outline-none transition font-mono text-sm resize-y"
            ></textarea>

            <div class="flex flex-col sm:flex-row gap-3 mt-4">

                <button
                    type="submit"
                    name="bulk_import_matches"
                    class="bg-[#ff9900] hover:bg-[#ffb84d] text-black px-6 py-3 rounded-xl font-black transition"
                >
                    Import Gameweek Matches
                </button>

                <button
                    type="button"
                    onclick="document.querySelector('[name=bulk_matches_sql]').value = `INSERT INTO matches (home_team, home_team_pic, away_team, away_team_pic, match_date, home_score, away_score, gameweek, deadline, competition)\nVALUES\n(\n    'Ipswich Town',\n    NULL,\n    'Liverpool',\n    NULL,\n    '2026-09-04 20:00:00',\n    NULL,\n    NULL,\n    4,\n    NULL,\n    'Premier League'\n),\n(\n    'Newcastle',\n    NULL,\n    'Bournemouth',\n    NULL,\n    '2026-09-05 12:30:00',\n    NULL,\n    NULL,\n    4,\n    NULL,\n    'Premier League'\n);`; document.querySelector('[name=bulk_gameweek]').value = '4';"
                    class="bg-white/10 hover:bg-white/20 border border-white/20 text-white px-6 py-3 rounded-xl font-bold transition"
                >
                    Load Example
                </button>

            </div>

        </form>

    </div>

</section>


<?php if ($selected_gw && count($matches) > 0): ?>


<!-- =========================================================
     MATCH STATISTICS
========================================================= -->

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">

    <div class="bg-[#1c003a]/80 backdrop-blur-xl border border-[#ff0080]/30 rounded-2xl p-5">

        <p class="text-gray-300 text-sm">
            Gameweek
        </p>

        <p class="text-3xl font-black text-[#ff0080] mt-1">
            <?= $selected_gw ?>
        </p>

    </div>


    <div class="bg-[#1c003a]/80 backdrop-blur-xl border border-[#ff0080]/30 rounded-2xl p-5">

        <p class="text-gray-300 text-sm">
            Matches
        </p>

        <p class="text-3xl font-black text-[#ff9900] mt-1">
            <?= count($matches) ?>
        </p>

    </div>


    <div class="bg-[#1c003a]/80 backdrop-blur-xl border border-[#ff0080]/30 rounded-2xl p-5">

        <p class="text-gray-300 text-sm">
            Scoring System
        </p>

        <p class="text-lg font-black text-white mt-2">
            3 Exact · 1 Result
        </p>

    </div>

</div>


<!-- =========================================================
     MATCH CARDS
========================================================= -->

<div class="space-y-5">

<?php foreach ($matches as $row): ?>

    <?php

        $is_finished =
            $row['home_score'] !== null &&
            $row['away_score'] !== null;

        $home_logo = ltrim(
            $row['home_team_pic'] ?? '',
            '/'
        );

        $away_logo = ltrim(
            $row['away_team_pic'] ?? '',
            '/'
        );

    ?>

    <div
        class="bg-[#1c003a]/80 backdrop-blur-xl border
        <?= $is_finished
            ? 'border-green-500/50'
            : 'border-[#ff0080]/30'
        ?>
        rounded-2xl overflow-hidden shadow-xl"
    >


        <!-- MATCH HEADER -->

        <div class="px-5 py-4 bg-black/20
                    flex flex-col md:flex-row
                    md:items-center md:justify-between gap-3">

            <div>

                <span class="text-xs uppercase tracking-wider text-gray-400">
                    Match #<?= (int)$row['id'] ?>
                </span>

                <p class="text-sm text-gray-300 mt-1">
                    <?= date(
                        "D, d M Y · H:i",
                        strtotime($row['match_date'])
                    ) ?>
                </p>

            </div>


            <?php if ($is_finished): ?>

                <span
                    class="inline-flex items-center gap-2
                           bg-green-500/10 text-green-300
                           border border-green-500/30
                           px-3 py-1 rounded-full
                           text-sm font-black"
                >
                    RESULT ENTERED
                </span>

            <?php else: ?>

                <span
                    class="inline-flex items-center gap-2
                           bg-yellow-500/10 text-yellow-300
                           border border-yellow-500/30
                           px-3 py-1 rounded-full
                           text-sm font-black"
                >
                    RESULT PENDING
                </span>

            <?php endif; ?>

        </div>


        <!-- MATCH BODY -->

        <div class="p-6">


            <form method="POST">

                <input
                    type="hidden"
                    name="match_id"
                    value="<?= (int)$row['id'] ?>"
                >

                <input
                    type="hidden"
                    name="current_gw"
                    value="<?= $selected_gw ?>"
                >


                <div
                    class="grid grid-cols-1 md:grid-cols-[1fr_auto_1fr]
                           items-center gap-6"
                >


                    <!-- HOME TEAM -->

                    <div class="flex flex-col items-center">

                        <div
                            class="w-24 h-24 bg-white rounded-full
                                   flex items-center justify-center
                                   p-3 shadow-lg mb-3"
                        >

                            <?php if ($home_logo): ?>

                                <img
                                    src="<?= htmlspecialchars($home_logo) ?>"
                                    alt="<?= htmlspecialchars($row['home_team']) ?>"
                                    class="w-full h-full object-contain"
                                    onerror="this.style.display='none'"
                                >

                            <?php endif; ?>

                        </div>

                        <h3 class="font-black text-lg text-center text-white">
                            <?= htmlspecialchars($row['home_team']) ?>
                        </h3>

                        <p class="text-xs text-gray-400 uppercase mt-1">
                            Home
                        </p>

                    </div>


                    <!-- SCORE -->

                    <div class="flex flex-col items-center">

                        <div class="flex items-center gap-3">

                            <input
                                type="number"
                                name="home_score"
                                min="0"
                                max="99"
                                required
                                value="<?= $row['home_score'] !== null
                                    ? (int)$row['home_score']
                                    : '' ?>"
                                placeholder="0"
                                class="w-20 h-16 text-3xl
                                       text-center font-black
                                       bg-black/40
                                       border-2 border-[#ff0080]/50
                                       rounded-xl
                                       focus:ring-4
                                       focus:ring-[#ff0080]/30
                                       outline-none text-white"
                            >

                            <span class="text-2xl font-black text-gray-400">
                                :
                            </span>

                            <input
                                type="number"
                                name="away_score"
                                min="0"
                                max="99"
                                required
                                value="<?= $row['away_score'] !== null
                                    ? (int)$row['away_score']
                                    : '' ?>"
                                placeholder="0"
                                class="w-20 h-16 text-3xl
                                       text-center font-black
                                       bg-black/40
                                       border-2 border-[#ff0080]/50
                                       rounded-xl
                                       focus:ring-4
                                       focus:ring-[#ff0080]/30
                                       outline-none text-white"
                            >

                        </div>


                        <p class="text-xs text-gray-400 mt-3">
                            Enter the official final score
                        </p>

                    </div>


                    <!-- AWAY TEAM -->

                    <div class="flex flex-col items-center">

                        <div
                            class="w-24 h-24 bg-white rounded-full
                                   flex items-center justify-center
                                   p-3 shadow-lg mb-3"
                        >

                            <?php if ($away_logo): ?>

                                <img
                                    src="<?= htmlspecialchars($away_logo) ?>"
                                    alt="<?= htmlspecialchars($row['away_team']) ?>"
                                    class="w-full h-full object-contain"
                                    onerror="this.style.display='none'"
                                >

                            <?php endif; ?>

                        </div>

                        <h3 class="font-black text-lg text-center text-white">
                            <?= htmlspecialchars($row['away_team']) ?>
                        </h3>

                        <p class="text-xs text-gray-400 uppercase mt-1">
                            Away
                        </p>

                    </div>

                </div>


                <!-- ACTION -->

                <div
                    class="border-t border-white/10
                           mt-6 pt-5
                           flex flex-col sm:flex-row
                           items-center justify-between gap-4"
                >

                    <div class="text-sm text-gray-300">

                        <span class="text-yellow-300 font-bold">
                            3 pts
                        </span>
                        exact score

                        <span class="mx-2">
                            ·
                        </span>

                        <span class="text-blue-400 font-bold">
                            1 pt
                        </span>
                        correct result

                    </div>


                    <button
                        type="submit"
                        name="save_result"
                        onclick="return confirm('Save this official result and calculate points for all users?');"
                        class="w-full sm:w-auto
                               bg-gradient-to-r
                               from-green-500 to-emerald-600
                               hover:from-green-400
                               hover:to-emerald-500
                               px-6 py-3
                               rounded-xl
                               font-black
                               shadow-lg
                               hover:shadow-green-500/20
                               transition
                               transform hover:scale-[1.02] text-black"
                    >
                        Save Result & Calculate Points
                    </button>

                </div>

            </form>

        </div>

    </div>

<?php endforeach; ?>

</div>


<?php elseif ($selected_gw): ?>


<!-- NO MATCHES -->

<div class="bg-[#1c003a]/80 backdrop-blur-xl border border-red-500/30
            rounded-2xl p-10 text-center">

    <div class="text-5xl mb-4">
        !
    </div>

    <h2 class="text-xl font-black text-red-400">
        No matches found
    </h2>

    <p class="text-gray-300 mt-2">
        There are no matches in Gameweek <?= $selected_gw ?>.
    </p>

</div>


<?php else: ?>


<!-- SELECT GAMEWEEK -->

<div class="bg-[#1c003a]/80 backdrop-blur-xl border border-[#ff0080]/30
            rounded-2xl p-12 text-center">

    <div class="text-6xl mb-5">
        !
    </div>

    <h2 class="text-2xl font-black">
        Select a Gameweek
    </h2>

    <p class="text-gray-300 mt-2">
        Choose a gameweek above to manage match results.
    </p>

</div>

<?php endif; ?>


<!-- =========================================================
     WHATSAPP MANAGEMENT
========================================================= -->

<section class="mt-10">

    <div class="mb-5">

        <h2 class="text-2xl font-black text-white">
            WhatsApp Notifications
        </h2>

        <p class="text-gray-300 text-sm mt-1">
            Send prediction reminders to registered users.
        </p>

    </div>


    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">


        <!-- DEFAULT MESSAGE -->

        <div class="bg-[#1c003a]/80 backdrop-blur-xl border border-[#ff0080]/30
                    rounded-2xl p-6">

            <h3 class="font-black text-lg mb-2 text-white">
                Default Reminder
            </h3>

            <p class="text-gray-300 text-sm mb-5">
                Notify users that the next gameweek is ready.
            </p>

            <form method="POST">

                <button
                    type="submit"
                    name="send_whatsapp"
                    class="w-full bg-green-600
                           hover:bg-green-500
                           px-5 py-3 rounded-xl
                           font-black transition text-black"
                >
                    Send Default Reminder
                </button>

            </form>

        </div>


        <!-- CUSTOM MESSAGE -->

        <div class="bg-[#1c003a]/80 backdrop-blur-xl border border-[#ff0080]/30
                    rounded-2xl p-6">

            <h3 class="font-black text-lg mb-2 text-white">
                Custom Message
            </h3>

            <p class="text-gray-300 text-sm mb-4">
                Use <code class="text-[#ff9900]">{name}</code>
                to insert the user's username.
            </p>

            <form method="POST">

                <textarea
                    name="custom_msg"
                    rows="4"
                    required
                    placeholder="Hello {name}, don't forget to submit your prediction!"
                    class="w-full bg-black/40
                           border border-white/10
                           focus:border-[#ff0080]
                           outline-none
                           rounded-xl p-4
                           resize-none mb-4 text-white"
                ></textarea>

                <button
                    type="submit"
                    name="send_custom_whatsapp"
                    class="w-full bg-pink-600
                           hover:bg-pink-500
                           px-5 py-3 rounded-xl
                           font-black transition text-black"
                >
                    Send Custom Message
                </button>

            </form>

        </div>

    </div>

</section>


</main>

</body>

</html>