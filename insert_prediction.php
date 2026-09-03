<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

require_once 'connect.php';
require_once 'gameweek_deadline.php';

date_default_timezone_set('Africa/Casablanca');


/*
|--------------------------------------------------------------------------
| LOGIN CHECK
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {

    header('Location: login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];


/*
|--------------------------------------------------------------------------
| POST ONLY
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header('Location: predictions.php');
    exit();
}


/*
|--------------------------------------------------------------------------
| DETECT MULTIPLE OR SINGLE PREDICTION
|--------------------------------------------------------------------------
*/

$isMultiplePrediction =
    isset($_POST['match_id'])
    && is_array($_POST['match_id']);


/*
|--------------------------------------------------------------------------
| MULTIPLE PREDICTIONS
|--------------------------------------------------------------------------
|
| Used by predictions.php
|
*/

if ($isMultiplePrediction) {

    $matchIds =
        $_POST['match_id'] ?? [];

    $homeScores =
        $_POST['predicted_home'] ?? [];

    $awayScores =
        $_POST['predicted_away'] ?? [];


    if (
        !is_array($matchIds)
        ||
        !is_array($homeScores)
        ||
        !is_array($awayScores)
    ) {

        die('ERROR: Invalid prediction data.');
    }


    if (count($matchIds) === 0) {

        die('ERROR: No matches received.');
    }


    if (
        count($matchIds) !== count($homeScores)
        ||
        count($matchIds) !== count($awayScores)
    ) {

        die('ERROR: Prediction data does not match.');
    }


    /*
    |--------------------------------------------------------------------------
    | GET GAMEWEEK FROM FIRST MATCH
    |--------------------------------------------------------------------------
    */

    $firstMatchId =
        (int) $matchIds[0];


    $gameweek = 0;


    $gameweekStmt = $conn->prepare("
        SELECT gameweek
        FROM matches
        WHERE id = ?
        LIMIT 1
    ");


    if (!$gameweekStmt) {

        die(
            'DATABASE ERROR: ' .
            htmlspecialchars($conn->error)
        );
    }


    $gameweekStmt->bind_param(
        'i',
        $firstMatchId
    );

    $gameweekStmt->execute();

    $gameweekResult =
        $gameweekStmt
            ->get_result();

    $gameweekRow =
        $gameweekResult
            ->fetch_assoc();

    $gameweekStmt->close();


    if (!$gameweekRow) {

        die('ERROR: Gameweek not found.');
    }


    $gameweek =
        (int) $gameweekRow['gameweek'];


    /*
    |--------------------------------------------------------------------------
    | GLOBAL DEADLINE CHECK
    |--------------------------------------------------------------------------
    */

    if (
        isGameweekDeadlinePassed(
            $conn,
            $gameweek
        )
    ) {

        header(
            'Location: predictions.php?gameweek=' .
            $gameweek .
            '&error=deadline_passed'
        );

        exit();
    }


    /*
    |--------------------------------------------------------------------------
    | PREPARE STATEMENTS
    |--------------------------------------------------------------------------
    */

    $matchStmt = $conn->prepare("
        SELECT
            id,
            gameweek,
            competition
        FROM matches
        WHERE id = ?
        LIMIT 1
    ");


    $existingStmt = $conn->prepare("
        SELECT id
        FROM score_exact
        WHERE user_id = ?
        AND match_id = ?
        LIMIT 1
    ");


    $insertStmt = $conn->prepare("
        INSERT INTO score_exact
        (
            user_id,
            match_id,
            predicted_home,
            predicted_away
        )
        VALUES (?, ?, ?, ?)
    ");


    if (
        !$matchStmt
        ||
        !$existingStmt
        ||
        !$insertStmt
    ) {

        die(
            'DATABASE PREPARE ERROR: ' .
            htmlspecialchars($conn->error)
        );
    }


    /*
    |--------------------------------------------------------------------------
    | TRANSACTION
    |--------------------------------------------------------------------------
    */

    $conn->begin_transaction();


    try {

        foreach ($matchIds as $index => $rawMatchId) {

            $matchId =
                (int) $rawMatchId;


            $homeRaw =
                $homeScores[$index] ?? '';


            $awayRaw =
                $awayScores[$index] ?? '';


            if (is_array($homeRaw)) {
                throw new Exception(
                    'Invalid home score.'
                );
            }


            if (is_array($awayRaw)) {
                throw new Exception(
                    'Invalid away score.'
                );
            }


            $homeRaw =
                trim((string) $homeRaw);


            $awayRaw =
                trim((string) $awayRaw);


            /*
            |--------------------------------------------------------------------------
            | SKIP ALREADY EXISTING / READONLY EMPTY CASES
            |--------------------------------------------------------------------------
            */

            if (
                $homeRaw === ''
                ||
                $awayRaw === ''
            ) {

                throw new Exception(
                    'Please enter both scores.'
                );
            }


            if (
                !is_numeric($homeRaw)
                ||
                !is_numeric($awayRaw)
            ) {

                throw new Exception(
                    'Scores must be numbers.'
                );
            }


            $homeScore =
                (int) $homeRaw;


            $awayScore =
                (int) $awayRaw;


            if (
                $homeScore < 0
                ||
                $awayScore < 0
                ||
                $homeScore > 10
                ||
                $awayScore > 10
            ) {

                throw new Exception(
                    'Invalid score.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | VERIFY MATCH
            |--------------------------------------------------------------------------
            */

            $matchStmt->bind_param(
                'i',
                $matchId
            );

            $matchStmt->execute();

            $matchResult =
                $matchStmt
                    ->get_result();

            $match =
                $matchResult
                    ->fetch_assoc();


            if (!$match) {

                throw new Exception(
                    'Match not found.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | SECURITY:
            | EVERY MATCH MUST BELONG TO SAME GAMEWEEK
            |--------------------------------------------------------------------------
            */

            if (
                (int) $match['gameweek']
                !== $gameweek
            ) {

                throw new Exception(
                    'Invalid gameweek.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | CHECK DEADLINE AGAIN
            |--------------------------------------------------------------------------
            */

            if (
                isGameweekDeadlinePassed(
                    $conn,
                    $gameweek
                )
            ) {

                throw new Exception(
                    'The gameweek deadline has passed.'
                );
            }


            /*
            |--------------------------------------------------------------------------
            | CHECK EXISTING PREDICTION
            |--------------------------------------------------------------------------
            */

            $existingStmt->bind_param(
                'ii',
                $user_id,
                $matchId
            );

            $existingStmt->execute();

            $existingResult =
                $existingStmt
                    ->get_result();

            $existing =
                $existingResult
                    ->fetch_assoc();


            /*
            |--------------------------------------------------------------------------
            | DO NOT OVERWRITE EXISTING PREDICTION
            |--------------------------------------------------------------------------
            */

            if ($existing) {

                continue;
            }


            /*
            |--------------------------------------------------------------------------
            | INSERT
            |--------------------------------------------------------------------------
            */

            $insertStmt->bind_param(
                'iiii',
                $user_id,
                $matchId,
                $homeScore,
                $awayScore
            );

            if (!$insertStmt->execute()) {

                throw new Exception(
                    $insertStmt->error
                );
            }
        }


        $conn->commit();


        $matchStmt->close();

        $existingStmt->close();

        $insertStmt->close();


        header(
            'Location: predictions.php?gameweek=' .
            $gameweek .
            '&success=prediction_saved'
        );

        exit();


    } catch (Throwable $e) {

        $conn->rollback();


        if (isset($matchStmt)) {
            $matchStmt->close();
        }

        if (isset($existingStmt)) {
            $existingStmt->close();
        }

        if (isset($insertStmt)) {
            $insertStmt->close();
        }


        header(
            'Location: predictions.php?gameweek=' .
            $gameweek .
            '&error=' .
            urlencode(
                $e->getMessage()
            )
        );

        exit();
    }
}


/*
|--------------------------------------------------------------------------
| SINGLE PREDICTION
|--------------------------------------------------------------------------
|
| Used by other_matches.php
|
*/

$match_id =
    isset($_POST['match_id'])
        ? (int) $_POST['match_id']
        : 0;


$predicted_home =
    isset($_POST['predicted_home'])
        && !is_array($_POST['predicted_home'])
            ? trim(
                (string)
                $_POST['predicted_home']
            )
            : '';


$predicted_away =
    isset($_POST['predicted_away'])
        && !is_array($_POST['predicted_away'])
            ? trim(
                (string)
                $_POST['predicted_away']
            )
            : '';


if ($match_id <= 0) {

    die('ERROR: Invalid match ID.');
}


if (
    $predicted_home === ''
    ||
    $predicted_away === ''
) {

    die('ERROR: Please enter both scores.');
}


if (
    !is_numeric($predicted_home)
    ||
    !is_numeric($predicted_away)
) {

    die('ERROR: Scores must be numbers.');
}


$home_score =
    (int) $predicted_home;


$away_score =
    (int) $predicted_away;


if (
    $home_score < 0
    ||
    $away_score < 0
    ||
    $home_score > 10
    ||
    $away_score > 10
) {

    die('ERROR: Invalid score.');
}


/*
|--------------------------------------------------------------------------
| GET MATCH
|--------------------------------------------------------------------------
*/

$match_stmt = $conn->prepare("
    SELECT
        id,
        gameweek,
        competition
    FROM matches
    WHERE id = ?
    LIMIT 1
");


if (!$match_stmt) {

    die(
        'MATCH QUERY ERROR: ' .
        htmlspecialchars($conn->error)
    );
}


$match_stmt->bind_param(
    'i',
    $match_id
);


$match_stmt->execute();


$match_result =
    $match_stmt
        ->get_result();


$match =
    $match_result
        ->fetch_assoc();


$match_stmt->close();


if (!$match) {

    die('ERROR: Match not found.');
}


$gameweek =
    (int) $match['gameweek'];


/*
|--------------------------------------------------------------------------
| GLOBAL GAMEWEEK DEADLINE CHECK
|--------------------------------------------------------------------------
*/

if (
    isGameweekDeadlinePassed(
        $conn,
        $gameweek
    )
) {

    header(
        'Location: other_matches.php?gameweek=' .
        $gameweek .
        '&error=deadline_passed'
    );

    exit();
}


/*
|--------------------------------------------------------------------------
| CHECK EXISTING PREDICTION
|--------------------------------------------------------------------------
*/

$check_stmt = $conn->prepare("
    SELECT id
    FROM score_exact
    WHERE
        user_id = ?
        AND match_id = ?
    LIMIT 1
");


$check_stmt->bind_param(
    'ii',
    $user_id,
    $match_id
);


$check_stmt->execute();


$existing =
    $check_stmt
        ->get_result()
        ->fetch_assoc();


$check_stmt->close();


if ($existing) {

    header(
        'Location: other_matches.php?gameweek=' .
        $gameweek .
        '&error=already_submitted'
    );

    exit();
}


/*
|--------------------------------------------------------------------------
| INSERT SINGLE PREDICTION
|--------------------------------------------------------------------------
*/

$insert_stmt = $conn->prepare("
    INSERT INTO score_exact
    (
        user_id,
        match_id,
        predicted_home,
        predicted_away
    )
    VALUES (?, ?, ?, ?)
");


$insert_stmt->bind_param(
    'iiii',
    $user_id,
    $match_id,
    $home_score,
    $away_score
);


if (!$insert_stmt->execute()) {

    die(
        'PREDICTION INSERT ERROR: ' .
        htmlspecialchars(
            $insert_stmt->error
        )
    );
}


$insert_stmt->close();


header(
    'Location: other_matches.php?gameweek=' .
    $gameweek .
    '&success=prediction_saved'
);

exit();
?>