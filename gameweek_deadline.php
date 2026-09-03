<?php

/*
|--------------------------------------------------------------------------
| GAMEWEEK DEADLINE HELPER
|--------------------------------------------------------------------------
|
| One global deadline controls all matches in the same gameweek.
|
| Priority:
|
| 1. gameweek_deadlines.deadline
| 2. matches.deadline
| 3. first match_date
|
*/

if (!function_exists('getGameweekDeadline')) {

    function getGameweekDeadline(mysqli $conn, int $gameweek): ?DateTime
    {
        if ($gameweek <= 0) {
            return null;
        }

        $timezone = new DateTimeZone('Africa/Casablanca');

        /*
        |--------------------------------------------------------------------------
        | 1. GLOBAL GAMEWEEK DEADLINE
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT deadline
            FROM gameweek_deadlines
            WHERE gameweek = ?
            LIMIT 1
        ");

        if ($stmt) {

            $stmt->bind_param('i', $gameweek);

            if ($stmt->execute()) {

                $result = $stmt->get_result();
                $row = $result->fetch_assoc();

                $stmt->close();

                if ($row && !empty($row['deadline'])) {

                    try {

                        return new DateTime(
                            $row['deadline'],
                            $timezone
                        );

                    } catch (Throwable $e) {

                        return null;

                    }
                }

            } else {

                $stmt->close();

            }
        }

        /*
        |--------------------------------------------------------------------------
        | 2. OLD matches.deadline FALLBACK
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT MIN(deadline) AS deadline
            FROM matches
            WHERE gameweek = ?
            AND deadline IS NOT NULL
        ");

        if ($stmt) {

            $stmt->bind_param('i', $gameweek);

            if ($stmt->execute()) {

                $result = $stmt->get_result();
                $row = $result->fetch_assoc();

                $stmt->close();

                if ($row && !empty($row['deadline'])) {

                    try {

                        return new DateTime(
                            $row['deadline'],
                            $timezone
                        );

                    } catch (Throwable $e) {

                        return null;

                    }
                }

            } else {

                $stmt->close();

            }
        }

        /*
        |--------------------------------------------------------------------------
        | 3. FIRST MATCH DATE FALLBACK
        |--------------------------------------------------------------------------
        */

        $stmt = $conn->prepare("
            SELECT MIN(match_date) AS first_match
            FROM matches
            WHERE gameweek = ?
        ");

        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $gameweek);

        if (!$stmt->execute()) {

            $stmt->close();

            return null;
        }

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        if (!$row || empty($row['first_match'])) {
            return null;
        }

        try {

            return new DateTime(
                $row['first_match'],
                $timezone
            );

        } catch (Throwable $e) {

            return null;

        }
    }


    /*
    |--------------------------------------------------------------------------
    | CHECK IF GAMEWEEK IS LOCKED
    |--------------------------------------------------------------------------
    */

    function isGameweekDeadlinePassed(
        mysqli $conn,
        int $gameweek
    ): bool {

        $deadline = getGameweekDeadline(
            $conn,
            $gameweek
        );

        if (!$deadline) {
            return false;
        }

        $now = new DateTime(
            'now',
            new DateTimeZone('Africa/Casablanca')
        );

        return $now >= $deadline;
    }


    /*
    |--------------------------------------------------------------------------
    | GET DEADLINE TIMESTAMP
    |--------------------------------------------------------------------------
    */

    function gameweekDeadlineTimestamp(
        mysqli $conn,
        int $gameweek
    ): ?int {

        $deadline = getGameweekDeadline(
            $conn,
            $gameweek
        );

        if (!$deadline) {
            return null;
        }

        return $deadline->getTimestamp();
    }


    /*
    |--------------------------------------------------------------------------
    | FORMAT DEADLINE
    |--------------------------------------------------------------------------
    */

    function formatGameweekDeadline(
        mysqli $conn,
        int $gameweek
    ): ?string {

        $deadline = getGameweekDeadline(
            $conn,
            $gameweek
        );

        if (!$deadline) {
            return null;
        }

        return $deadline->format(
            'D, d M Y • H:i'
        );
    }
}
?>