<?php

session_start();

require_once 'connect.php';
require_once 'points_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}


/* ============================================================
   ADMIN CHECK
   ============================================================ */

$isAdmin = false;

if (isset($_SESSION['role'])) {

    $isAdmin =
        in_array(
            strtolower(
                (string)$_SESSION['role']
            ),
            [
                'admin',
                'super-admin',
                'super_admin'
            ],
            true
        );
}

if (!$isAdmin) {

    http_response_code(403);
    exit("Access denied.");
}


/* ============================================================
   INPUT
   ============================================================ */

$matchId =
    (int)($_POST['match_id'] ?? 0);

$homeScore =
    isset($_POST['home_score'])
        ? (int)$_POST['home_score']
        : null;

$awayScore =
    isset($_POST['away_score'])
        ? (int)$_POST['away_score']
        : null;


if (
    $matchId <= 0 ||
    $homeScore === null ||
    $awayScore === null ||
    $homeScore < 0 ||
    $awayScore < 0
) {

    exit("Invalid match or score.");
}


try {

    $transactionStarted = false;
    $conn->begin_transaction();
    $transactionStarted = true;


    /* ========================================================
       SAVE REAL RESULT
       ======================================================== */

    $stmt = $conn->prepare("
        UPDATE matches
        SET
            home_score = ?,
            away_score = ?
        WHERE id = ?
    ");

    if (!$stmt) {
        throw new Exception($conn->error);
    }

    $stmt->bind_param(
        "iii",
        $homeScore,
        $awayScore,
        $matchId
    );

    $stmt->execute();
    $stmt->close();


    /* ========================================================
       LOAD PREDICTIONS
       ======================================================== */

    $stmt = $conn->prepare("
        SELECT
            id,
            user_id,
            predicted_home,
            predicted_away
        FROM score_exact
        WHERE match_id = ?
    ");

    $stmt->bind_param(
        "i",
        $matchId
    );

    $stmt->execute();

    $result =
        $stmt->get_result();

    $predictions = [];

    while (
        $row =
        $result->fetch_assoc()
    ) {

        $predictions[] =
            $row;
    }

    $stmt->close();


    /* ========================================================
       CALCULATE BASE POINTS
       ======================================================== */

    foreach (
        $predictions
        as $prediction
    ) {

        $predHome =
            (int)$prediction['predicted_home'];

        $predAway =
            (int)$prediction['predicted_away'];


        $basePoints = 0;


        /* EXACT SCORE */

        if (
            $predHome === $homeScore &&
            $predAway === $awayScore
        ) {

            $basePoints = 3;

        } else {

            /*
             * Actual result
             */

            if ($homeScore > $awayScore) {

                $actual = 'home';

            } elseif ($homeScore < $awayScore) {

                $actual = 'away';

            } else {

                $actual = 'draw';
            }


            /*
             * Predicted result
             */

            if ($predHome > $predAway) {

                $predicted = 'home';

            } elseif ($predHome < $predAway) {

                $predicted = 'away';

            } else {

                $predicted = 'draw';
            }


            if (
                $actual === $predicted
            ) {

                $basePoints = 1;
            }
        }


        /* ====================================================
           SAVE ORIGINAL POINTS
           ==================================================== */

        $stmt = $conn->prepare("
            UPDATE score_exact
            SET base_points = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            "ii",
            $basePoints,
            $prediction['id']
        );

        $stmt->execute();
        $stmt->close();


        /* ====================================================
           APPLY BASE POINTS
           ==================================================== */

        syncPredictionPoints(
            $conn,
            (int)$prediction['user_id'],
            $matchId
        );


    }


    $conn->commit();
    $transactionStarted = false;


    $redirect =
        $_SERVER['HTTP_REFERER']
        ?? 'myAdmin.php';

    header(
        "Location: "
        . $redirect
        . (
            strpos(
                $redirect,
                '?'
            ) !== false
                ? '&'
                : '?'
        )
        . "score_saved=1"
    );

    exit;


} catch (Throwable $e) {

    if ($transactionStarted) {
        $conn->rollback();
    }

    http_response_code(500);

    echo "<h2>Could not save score</h2>";

    echo "<pre>";
    echo htmlspecialchars(
        $e->getMessage(),
        ENT_QUOTES,
        'UTF-8'
    );
    echo "</pre>";
}