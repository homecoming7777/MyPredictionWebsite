<?php

session_start();

require_once 'connect.php';
require_once 'points_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}


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


$gameweek =
    isset($_POST['gameweek'])
        ? (int)$_POST['gameweek']
        : null;


try {

    if (
        $gameweek !== null &&
        $gameweek > 0
    ) {

        $stmt = $conn->prepare("
            SELECT DISTINCT se.user_id
            FROM score_exact se
            INNER JOIN matches m
                ON m.id = se.match_id
            WHERE m.gameweek = ?
        ");

        $stmt->bind_param(
            "i",
            $gameweek
        );

        $stmt->execute();

        $result =
            $stmt->get_result();

        $users = [];

        while (
            $row =
            $result->fetch_assoc()
        ) {

            $users[] =
                (int)$row['user_id'];
        }

        $stmt->close();


        foreach ($users as $userId) {

            syncUserPoints(
                $conn,
                $userId,
                $gameweek
            );
        }

    } else {

        syncAllPoints(
            $conn
        );
    }


    header(
        "Location: myAdmin.php?points_updated=1"
    );

    exit;


} catch (Throwable $e) {

    http_response_code(500);

    echo "<h2>Point calculation error</h2>";

    echo "<pre>";
    echo htmlspecialchars(
        $e->getMessage(),
        ENT_QUOTES,
        'UTF-8'
    );
    echo "</pre>";
}