<?php

session_start();

require_once 'connect.php';
require_once 'gameweek_deadline.php';

date_default_timezone_set('Africa/Casablanca');


/*
|--------------------------------------------------------------------------
| AUTHENTICATION
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$current_user_id = (int) $_SESSION['user_id'];


/*
|--------------------------------------------------------------------------
| PARAMETERS
|--------------------------------------------------------------------------
*/

$selected_gw = isset($_GET['gameweek'])
    ? (int) $_GET['gameweek']
    : 0;

$view_user_id = isset($_GET['user_id'])
    ? (int) $_GET['user_id']
    : 0;


/*
|--------------------------------------------------------------------------
| VALIDATE PARAMETERS
|--------------------------------------------------------------------------
*/

if ($selected_gw <= 0 || $view_user_id <= 0) {
    header("Location: other_matches.php");
    exit;
}


/*
|--------------------------------------------------------------------------
| DON'T ALLOW USER TO OPEN HIS OWN VIEW PAGE
|--------------------------------------------------------------------------
*/

if ($view_user_id === $current_user_id) {
    header(
        "Location: other_matches.php?gameweek="
        . $selected_gw
    );
    exit;
}


/*
|--------------------------------------------------------------------------
| DEADLINE
|--------------------------------------------------------------------------
*/

$gameweekDeadline = getGameweekDeadline(
    $conn,
    $selected_gw
);

$deadlinePassed = isGameweekDeadlinePassed(
    $conn,
    $selected_gw
);


/*
|--------------------------------------------------------------------------
| OTHER USERS' PREDICTIONS ARE ONLY AVAILABLE AFTER DEADLINE
|--------------------------------------------------------------------------
*/

if (!$deadlinePassed) {
    header(
        "Location: other_matches.php?gameweek="
        . $selected_gw
    );
    exit;
}


/*
|--------------------------------------------------------------------------
| ESCAPE FUNCTION
|--------------------------------------------------------------------------
*/

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}


/*
|--------------------------------------------------------------------------
| TEAM LOGO
|--------------------------------------------------------------------------
|
| THIS IS THE SAME LOGO SYSTEM USED BY YOUR WORKING
| other_matches.php FILE.
|
|--------------------------------------------------------------------------
*/

function teamLogo($teamName)
{
    $teamName = strtolower(trim((string) $teamName));

    $teamName = preg_replace(
        '/\s+/',
        ' ',
        $teamName
    );


    $logos = [

        'arsenal'
            => 'arsenal.png',

        'aston villa'
            => 'aston-villa.png',

        'bournemouth'
            => 'bournemouth.png',

        'brentford'
            => 'brentford.png',

        'brighton'
            => 'brighton.png',

        'brighton & hove albion'
            => 'brighton.png',

        'chelsea'
            => 'chelsea.png',

        'coventry'
            => 'coventry-city.png',

        'coventry city'
            => 'coventry-city.png',

        'crystal palace'
            => 'crystal-palace.png',

        'everton'
            => 'everton.png',

        'fulham'
            => 'fulham.png',

        'hull'
            => 'hull-city.png',

        'hull city'
            => 'hull-city.png',

        'ipswich'
            => 'ipswich-town.png',

        'ipswich town'
            => 'ipswich-town.png',

        'leeds'
            => 'leeds.png',

        'leeds united'
            => 'leeds.png',

        'liverpool'
            => 'liverpool.png',

        'manchester city'
            => 'manchester-city.png',

        'man city'
            => 'manchester-city.png',

        'manchester united'
            => 'manchester-united.png',

        'man united'
            => 'manchester-united.png',

        'man utd'
            => 'manchester-united.png',

        'newcastle'
            => 'newcastle.png',

        'newcastle united'
            => 'newcastle.png',

        'nottingham forest'
            => 'nottingham-forest.png',

        'nottingham'
            => 'nottingham-forest.png',

        'sunderland'
            => 'sunderland.png',

        'tottenham'
            => 'tottenham.png',

        'tottenham hotspur'
            => 'tottenham.png',

        'spurs'
            => 'tottenham.png',

    ];


    /*
    |--------------------------------------------------------------------------
    | MAPPED LOGO
    |--------------------------------------------------------------------------
    */

    if (isset($logos[$teamName])) {

        $file = $logos[$teamName];

        $fullPath =
            __DIR__
            . DIRECTORY_SEPARATOR
            . 'PL_Teams'
            . DIRECTORY_SEPARATOR
            . $file;

        if (file_exists($fullPath)) {

            return 'PL_Teams/' . $file;

        }
    }


    /*
    |--------------------------------------------------------------------------
    | AUTOMATIC FILENAME FALLBACK
    |--------------------------------------------------------------------------
    */

    $safeName = preg_replace(
        '/[^a-z0-9]+/',
        '-',
        $teamName
    );

    $safeName = trim(
        $safeName,
        '-'
    );


    $possibleFiles = [

        $safeName . '.png',
        $safeName . '.jpg',
        $safeName . '.jpeg',

    ];


    foreach ($possibleFiles as $file) {

        $fullPath =
            __DIR__
            . DIRECTORY_SEPARATOR
            . 'PL_Teams'
            . DIRECTORY_SEPARATOR
            . $file;


        if (file_exists($fullPath)) {

            return 'PL_Teams/' . $file;

        }

    }


    return null;
}


/*
|--------------------------------------------------------------------------
| GET VIEWED USER
|--------------------------------------------------------------------------
*/

$user_stmt = $conn->prepare("
    SELECT
        id,
        username
    FROM users
    WHERE id = ?
    LIMIT 1
");


if (!$user_stmt) {

    die(
        "Database error: "
        . e($conn->error)
    );

}


$user_stmt->bind_param(
    "i",
    $view_user_id
);


$user_stmt->execute();


$user_result =
    $user_stmt->get_result();


$view_user =
    $user_result->fetch_assoc();


$user_stmt->close();


if (!$view_user) {

    header(
        "Location: other_matches.php?gameweek="
        . $selected_gw
    );

    exit;

}


/*
|--------------------------------------------------------------------------
| GET PREDICTIONS
|--------------------------------------------------------------------------
|
| We also retrieve home_team_pic and away_team_pic so that
| the exact same fallback mechanism as other_matches.php
| can be used.
|
|--------------------------------------------------------------------------
*/

$predictions = [];


$prediction_sql = "

    SELECT

        se.id AS prediction_id,

        se.match_id,

        se.predicted_home,

        se.predicted_away,

        m.id AS real_match_id,

        m.home_team,

        m.away_team,

        m.home_score,

        m.away_score,

        m.match_date,

        m.competition,

        m.home_team_pic,

        m.away_team_pic

    FROM score_exact se

    INNER JOIN matches m
        ON m.id = se.match_id

    WHERE
        se.user_id = ?
        AND m.gameweek = ?

    ORDER BY

        m.competition ASC,
        m.match_date ASC,
        m.id ASC

";


$prediction_stmt =
    $conn->prepare(
        $prediction_sql
    );


if (!$prediction_stmt) {

    die(
        "Database error: "
        . e($conn->error)
    );

}


$prediction_stmt->bind_param(
    "ii",
    $view_user_id,
    $selected_gw
);


$prediction_stmt->execute();


$prediction_result =
    $prediction_stmt->get_result();


while (
    $row =
    $prediction_result->fetch_assoc()
) {


    /*
    |--------------------------------------------------------------------------
    | PREDICTION VALUES
    |--------------------------------------------------------------------------
    */

    $predicted_home =
        (int) $row['predicted_home'];

    $predicted_away =
        (int) $row['predicted_away'];


    /*
    |--------------------------------------------------------------------------
    | POINT CALCULATION
    |--------------------------------------------------------------------------
    |
    | Exact score = 3
    | Correct result = 1
    | Wrong result = 0
    |
    |--------------------------------------------------------------------------
    */

    $points = null;


    $match_finished =
        $row['home_score'] !== null
        &&
        $row['away_score'] !== null;


    if ($match_finished) {

        $actual_home =
            (int) $row['home_score'];

        $actual_away =
            (int) $row['away_score'];


        /*
        |--------------------------------------------------------------------------
        | EXACT SCORE
        |--------------------------------------------------------------------------
        */

        if (
            $predicted_home === $actual_home
            &&
            $predicted_away === $actual_away
        ) {

            $points = 3;

        }

        /*
        |--------------------------------------------------------------------------
        | RESULT CALCULATION
        |--------------------------------------------------------------------------
        */

        else {

            $predicted_result = 0;

            $actual_result = 0;


            /*
            |--------------------------------------------------------------------------
            | USER PREDICTED HOME WIN
            |--------------------------------------------------------------------------
            */

            if (
                $predicted_home >
                $predicted_away
            ) {

                $predicted_result = 1;

            }


            /*
            |--------------------------------------------------------------------------
            | USER PREDICTED DRAW
            |--------------------------------------------------------------------------
            */

            elseif (
                $predicted_home ===
                $predicted_away
            ) {

                $predicted_result = 0;

            }


            /*
            |--------------------------------------------------------------------------
            | USER PREDICTED AWAY WIN
            |--------------------------------------------------------------------------
            */

            else {

                $predicted_result = -1;

            }


            /*
            |--------------------------------------------------------------------------
            | ACTUAL HOME WIN
            |--------------------------------------------------------------------------
            */

            if (
                $actual_home >
                $actual_away
            ) {

                $actual_result = 1;

            }


            /*
            |--------------------------------------------------------------------------
            | ACTUAL DRAW
            |--------------------------------------------------------------------------
            */

            elseif (
                $actual_home ===
                $actual_away
            ) {

                $actual_result = 0;

            }


            /*
            |--------------------------------------------------------------------------
            | ACTUAL AWAY WIN
            |--------------------------------------------------------------------------
            */

            else {

                $actual_result = -1;

            }


            /*
            |--------------------------------------------------------------------------
            | CORRECT RESULT
            |--------------------------------------------------------------------------
            */

            if (
                $predicted_result ===
                $actual_result
            ) {

                $points = 1;

            }

            else {

                $points = 0;

            }

        }

    }


    /*
    |--------------------------------------------------------------------------
    | GET LOGOS USING THE SAME SYSTEM AS OTHER_MATCHES.PHP
    |--------------------------------------------------------------------------
    */

    $home_logo =
        teamLogo(
            $row['home_team']
        );


    $away_logo =
        teamLogo(
            $row['away_team']
        );


    /*
    |--------------------------------------------------------------------------
    | DATABASE LOGO FALLBACK
    |--------------------------------------------------------------------------
    */

    if (
        !$home_logo
        &&
        !empty(
            $row['home_team_pic']
        )
    ) {

        $home_logo =
            $row['home_team_pic'];

    }


    if (
        !$away_logo
        &&
        !empty(
            $row['away_team_pic']
        )
    ) {

        $away_logo =
            $row['away_team_pic'];

    }


    /*
    |--------------------------------------------------------------------------
    | STORE DATA
    |--------------------------------------------------------------------------
    */

    $row['calculated_points'] =
        $points;

    $row['home_logo'] =
        $home_logo;

    $row['away_logo'] =
        $away_logo;


    $predictions[] =
        $row;

}


$prediction_stmt->close();


/*
|--------------------------------------------------------------------------
| STATISTICS
|--------------------------------------------------------------------------
*/

$total_predictions =
    count($predictions);


$total_points = 0;

$finished_matches = 0;


foreach (
    $predictions
    as $prediction
) {

    if (
        $prediction['calculated_points']
        !== null
    ) {

        $finished_matches++;

        $total_points +=
            (int)
            $prediction['calculated_points'];

    }

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

    <title>
        <?= e($view_user['username']) ?>
        -
        Gameweek <?= (int) $selected_gw ?>
        Predictions
    </title>


    <script src="https://cdn.tailwindcss.com"></script>


    <style>

        :root {

            --accent:
                #ff0080;

        }


        body {

            min-height: 100vh;

            background-image:
                url('PL_img/current.jpg');

            background-size:
                cover;

            background-position:
                center;

            background-attachment:
                fixed;

            background-color:
                #1c003a;

            font-family:
                Arial,
                Helvetica,
                sans-serif;

        }


        body::before {

            content: "";

            position: fixed;

            top: 0;

            left: 0;

            width: 100%;

            height: 100%;

            background:
                rgba(
                    10,
                    0,
                    21,
                    0.75
                );

            z-index: -1;

            pointer-events: none;

        }


        .text-accent {

            color:
                var(--accent);

        }


        .bg-accent {

            background:
                var(--accent);

        }


        .team-logo {

            width:
                80px;

            height:
                80px;

            object-fit:
                contain;

            background:
                transparent;

            border-radius:
                50%;

            padding:
                6px;

            border:
                3px solid
                rgba(
                    233,
                    0,
                    82,
                    .35
                );

            box-shadow:
                0 8px 30px
                rgba(
                    0,
                    0,
                    0,
                    .5
                ),
                inset 0 0 15px
                rgba(
                    233,
                    0,
                    82,
                    .08
                );

            transition:
                all .3s ease;

        }


        .team-logo:hover {

            transform:
                scale(1.08);

            border-color:
                rgba(
                    233,
                    0,
                    82,
                    .9
                );

            box-shadow:
                0 0 40px
                rgba(
                    233,
                    0,
                    82,
                    .4
                ),
                inset 0 0 20px
                rgba(
                    233,
                    0,
                    82,
                    .15
                );

        }


        .team-logo-fallback {

            width:
                80px;

            height:
                80px;

            background:
                rgba(
                    0,
                    0,
                    0,
                    .3
                );

            border-radius:
                50%;

            display:
                flex;

            align-items:
                center;

            justify-content:
                center;

            border:
                3px solid
                rgba(
                    255,
                    255,
                    255,
                    .1
                );

            color:
                #9ca3af;

            font-size:
                11px;

            text-align:
                center;

            padding:
                5px;

        }


        .match-card {

            background:
                linear-gradient(
                    180deg,
                    rgba(
                        255,
                        255,
                        255,
                        .06
                    ),
                    rgba(
                        255,
                        255,
                        255,
                        .025
                    )
                );

            border:
                1px solid
                rgba(
                    255,
                    255,
                    255,
                    .10
                );

            backdrop-filter:
                blur(15px);

            box-shadow:
                0 18px 50px
                rgba(
                    0,
                    0,
                    0,
                    .35
                );

        }


        .league-title {

            background:
                linear-gradient(
                    90deg,
                    rgba(
                        233,
                        0,
                        82,
                        .18
                    ),
                    rgba(
                        55,
                        0,
                        60,
                        .30
                    ),
                    rgba(
                        233,
                        0,
                        82,
                        .18
                    )
                );

            border:
                1px solid
                rgba(
                    233,
                    0,
                    82,
                    .25
                );

        }


        @media (
            max-width: 640px
        ) {

            .team-logo,
            .team-logo-fallback {

                width:
                    60px;

                height:
                    60px;

            }

        }

    </style>

</head>


<body class="text-white">


<!-- ========================================================= -->
<!-- NAVBAR -->
<!-- ========================================================= -->

<nav
    class="
        fixed
        top-0
        left-0
        right-0
        z-50
        bg-black/60
        backdrop-blur-xl
        border-b
        border-white/10
        px-5
        py-4
    "
>

    <div
        class="
            max-w-6xl
            mx-auto
            flex
            items-center
            justify-between
        "
    >

        <a
            href="dashboard.php"
            class="
                flex
                items-center
                gap-3
                font-black
            "
        >

            <div
                class="
                    w-10
                    h-10
                    rounded-full
                    bg-white
                    p-1
                "
            >

                <img
                    src="PL_img/PL_LOGO1.png"
                    alt="Premier League"
                    class="
                        w-full
                        h-full
                        object-contain
                    "
                >

            </div>


            <span
                class="
                    hidden
                    sm:block
                "
            >
                Premier League
            </span>

        </a>


        <a
            href="
                other_matches.php?gameweek=<?= (int)
                    $selected_gw ?>
            "
            class="
                bg-white/10
                hover:bg-white/20
                border
                border-white/10
                px-4
                py-2
                rounded-lg
                font-bold
                transition
            "
        >
            ← Back
        </a>

    </div>

</nav>


<!-- ========================================================= -->
<!-- MAIN -->
<!-- ========================================================= -->

<main
    class="
        max-w-6xl
        mx-auto
        px-4
        sm:px-6
        pt-28
        pb-12
    "
>


    <!-- ===================================================== -->
    <!-- HEADER -->
    <!-- ===================================================== -->

    <div
        class="
            text-center
            mb-8
        "
    >

        <div
            class="
                w-20
                h-20
                mx-auto
                rounded-full
                bg-purple-700
                border
                border-white/10
                flex
                items-center
                justify-center
                text-3xl
                font-black
                mb-4
            "
        >

            <?= e(
                strtoupper(
                    substr(
                        $view_user['username'],
                        0,
                        1
                    )
                )
            ) ?>

        </div>


        <h1
            class="
                text-3xl
                sm:text-4xl
                font-black
                text-accent
            "
        >

            <?= e(
                $view_user['username']
            ) ?>

        </h1>


        <p
            class="
                text-gray-300
                mt-2
                text-lg
            "
        >

            Gameweek
            <?= (int) $selected_gw ?>
            Predictions

        </p>


        <div
            class="
                inline-flex
                items-center
                gap-2
                mt-4
                px-4
                py-2
                rounded-full
                bg-red-500/10
                border
                border-red-500/30
                text-red-300
                text-sm
                font-bold
            "
        >

            🔒 Deadline Passed

        </div>


        <?php if ($gameweekDeadline): ?>

            <p
                class="
                    text-gray-500
                    text-sm
                    mt-3
                "
            >

                Deadline:

                <span
                    class="
                        text-gray-300
                        font-semibold
                    "
                >

                    <?= e(
                        $gameweekDeadline->format(
                            'D, d M Y • H:i'
                        )
                    ) ?>

                </span>

            </p>

        <?php endif; ?>

    </div>


    <!-- ===================================================== -->
    <!-- STATISTICS -->
    <!-- ===================================================== -->

    <div
        class="
            grid
            grid-cols-2
            md:grid-cols-3
            gap-4
            max-w-3xl
            mx-auto
            mb-10
        "
    >

        <div
            class="
                bg-white/5
                border
                border-white/10
                rounded-2xl
                p-5
                text-center
            "
        >

            <div
                class="
                    text-3xl
                    font-black
                "
            >

                <?= $total_predictions ?>

            </div>


            <div
                class="
                    text-xs
                    text-gray-500
                    uppercase
                    font-bold
                    mt-1
                "
            >

                Predictions

            </div>

        </div>


        <div
            class="
                bg-white/5
                border
                border-white/10
                rounded-2xl
                p-5
                text-center
            "
        >

            <div
                class="
                    text-3xl
                    font-black
                    text-accent
                "
            >

                <?= $total_points ?>

            </div>


            <div
                class="
                    text-xs
                    text-gray-500
                    uppercase
                    font-bold
                    mt-1
                "
            >

                Points

            </div>

        </div>


        <div
            class="
                col-span-2
                md:col-span-1
                bg-white/5
                border
                border-white/10
                rounded-2xl
                p-5
                text-center
            "
        >

            <div
                class="
                    text-3xl
                    font-black
                "
            >

                <?= $finished_matches ?>

            </div>


            <div
                class="
                    text-xs
                    text-gray-500
                    uppercase
                    font-bold
                    mt-1
                "
            >

                Finished

            </div>

        </div>

    </div>


    <!-- ===================================================== -->
    <!-- PREDICTIONS -->
    <!-- ===================================================== -->

    <?php if (empty($predictions)): ?>


        <div
            class="
                bg-white/5
                border
                border-white/10
                rounded-2xl
                p-12
                text-center
            "
        >

            <div class="text-5xl mb-4">
                ⚽
            </div>


            <h2
                class="
                    text-xl
                    font-black
                "
            >

                No Predictions Found

            </h2>


            <p
                class="
                    text-gray-500
                    mt-2
                "
            >

                <?= e(
                    $view_user['username']
                ) ?>

                did not submit any predictions
                for Gameweek
                <?= (int) $selected_gw ?>.

            </p>

        </div>


    <?php else: ?>


        <?php

        $current_competition = '';

        ?>


        <?php foreach (
            $predictions
            as $prediction
        ): ?>


            <!-- ============================================= -->
            <!-- COMPETITION -->
            <!-- ============================================= -->

            <?php if (
                $prediction['competition']
                !==
                $current_competition
            ): ?>


                <?php

                $current_competition =
                    $prediction['competition'];

                ?>


                <div
                    class="
                        league-title
                        rounded-xl
                        px-5
                        py-4
                        mt-8
                        mb-5
                        text-center
                    "
                >

                    <h2
                        class="
                            text-xl
                            sm:text-2xl
                            font-black
                        "
                    >

                        <?= e(
                            $current_competition
                        ) ?>

                    </h2>

                </div>


            <?php endif; ?>


            <!-- ============================================= -->
            <!-- MATCH -->
            <!-- ============================================= -->

            <div
                class="
                    match-card
                    rounded-2xl
                    overflow-hidden
                    mb-5
                "
            >


                <!-- MATCH INFO -->

                <div
                    class="
                        bg-black/30
                        border-b
                        border-white/10
                        text-center
                        py-3
                        px-4
                    "
                >

                    <div
                        class="
                            text-gray-400
                            text-xs
                            sm:text-sm
                        "
                    >

                        <?= e(
                            date(
                                'D, d M Y • H:i',
                                strtotime(
                                    $prediction[
                                        'match_date'
                                    ]
                                )
                            )
                        ) ?>

                    </div>

                </div>


                <div class="p-5 sm:p-7">


                    <!-- TEAMS -->

                    <div
                        class="
                            flex
                            flex-col
                            sm:flex-row
                            items-center
                            justify-center
                            gap-6
                            sm:gap-10
                        "
                    >


                        <!-- HOME -->

                        <div
                            class="
                                flex
                                flex-col
                                items-center
                                text-center
                                w-full
                                sm:w-1/3
                            "
                        >

                            <span
                                class="
                                    text-[10px]
                                    font-black
                                    uppercase
                                    tracking-wider
                                    text-blue-400
                                    mb-2
                                "
                            >

                                HOME

                            </span>


                            <?php if (
                                $prediction['home_logo']
                            ): ?>


                                <img
                                    src="<?= e(
                                        $prediction[
                                            'home_logo'
                                        ]
                                    ) ?>"
                                    alt="<?= e(
                                        $prediction[
                                            'home_team'
                                        ]
                                    ) ?>"
                                    class="
                                        team-logo
                                        mb-3
                                    "
                                >


                            <?php else: ?>


                                <div
                                    class="
                                        team-logo-fallback
                                        mb-3
                                    "
                                >

                                    <?= e(
                                        substr(
                                            $prediction[
                                                'home_team'
                                            ],
                                            0,
                                            12
                                        )
                                    ) ?>

                                </div>


                            <?php endif; ?>


                            <span
                                class="
                                    font-black
                                    text-sm
                                    sm:text-base
                                "
                            >

                                <?= e(
                                    $prediction[
                                        'home_team'
                                    ]
                                ) ?>

                            </span>

                        </div>


                        <!-- PREDICTION -->

                        <div
                            class="
                                flex
                                flex-col
                                items-center
                                justify-center
                                min-w-[120px]
                            "
                        >

                            <span
                                class="
                                    text-[10px]
                                    font-black
                                    uppercase
                                    tracking-wider
                                    text-gray-500
                                    mb-2
                                "
                            >

                                PREDICTION

                            </span>


                            <div
                                class="
                                    flex
                                    items-center
                                    gap-3
                                "
                            >

                                <span
                                    class="
                                        text-3xl
                                        sm:text-4xl
                                        font-black
                                        text-accent
                                    "
                                >

                                    <?= (int)
                                        $prediction[
                                            'predicted_home'
                                        ] ?>

                                </span>


                                <span
                                    class="
                                        text-xl
                                        font-black
                                        text-gray-500
                                    "
                                >

                                    -

                                </span>


                                <span
                                    class="
                                        text-3xl
                                        sm:text-4xl
                                        font-black
                                        text-accent
                                    "
                                >

                                    <?= (int)
                                        $prediction[
                                            'predicted_away'
                                        ] ?>

                                </span>

                            </div>

                        </div>


                        <!-- AWAY -->

                        <div
                            class="
                                flex
                                flex-col
                                items-center
                                text-center
                                w-full
                                sm:w-1/3
                            "
                        >

                            <span
                                class="
                                    text-[10px]
                                    font-black
                                    uppercase
                                    tracking-wider
                                    text-red-400
                                    mb-2
                                "
                            >

                                AWAY

                            </span>


                            <?php if (
                                $prediction['away_logo']
                            ): ?>


                                <img
                                    src="<?= e(
                                        $prediction[
                                            'away_logo'
                                        ]
                                    ) ?>"
                                    alt="<?= e(
                                        $prediction[
                                            'away_team'
                                        ]
                                    ) ?>"
                                    class="
                                        team-logo
                                        mb-3
                                    "
                                >


                            <?php else: ?>


                                <div
                                    class="
                                        team-logo-fallback
                                        mb-3
                                    "
                                >

                                    <?= e(
                                        substr(
                                            $prediction[
                                                'away_team'
                                            ],
                                            0,
                                            12
                                        )
                                    ) ?>

                                </div>


                            <?php endif; ?>


                            <span
                                class="
                                    font-black
                                    text-sm
                                    sm:text-base
                                "
                            >

                                <?= e(
                                    $prediction[
                                        'away_team'
                                    ]
                                ) ?>

                            </span>

                        </div>

                    </div>


                    <!-- ===================================== -->
                    <!-- RESULT -->
                    <!-- ===================================== -->

                    <?php if (
                        $prediction[
                            'home_score'
                        ] !== null
                        &&
                        $prediction[
                            'away_score'
                        ] !== null
                    ): ?>


                        <div
                            class="
                                mt-7
                                pt-5
                                border-t
                                border-white/10
                            "
                        >

                            <div
                                class="
                                    flex
                                    flex-col
                                    sm:flex-row
                                    items-center
                                    justify-center
                                    gap-3
                                    sm:gap-5
                                "
                            >

                                <span
                                    class="
                                        text-xs
                                        text-gray-500
                                        uppercase
                                        font-black
                                    "
                                >

                                    Final Result

                                </span>


                                <span
                                    class="
                                        text-2xl
                                        font-black
                                    "
                                >

                                    <?= (int)
                                        $prediction[
                                            'home_score'
                                        ] ?>

                                    -

                                    <?= (int)
                                        $prediction[
                                            'away_score'
                                        ] ?>

                                </span>


                                <?php if (
                                    $prediction[
                                        'calculated_points'
                                    ] === 3
                                ): ?>


                                    <span
                                        class="
                                            px-4
                                            py-2
                                            rounded-full
                                            bg-green-500/15
                                            border
                                            border-green-500/30
                                            text-green-400
                                            font-black
                                            text-sm
                                        "
                                    >

                                        ✓ Exact Score
                                        +3 pts

                                    </span>


                                <?php elseif (
                                    $prediction[
                                        'calculated_points'
                                    ] === 1
                                ): ?>


                                    <span
                                        class="
                                            px-4
                                            py-2
                                            rounded-full
                                            bg-yellow-500/15
                                            border
                                            border-yellow-500/30
                                            text-yellow-400
                                            font-black
                                            text-sm
                                        "
                                    >

                                        ✓ Correct Result
                                        +1 pt

                                    </span>


                                <?php else: ?>


                                    <span
                                        class="
                                            px-4
                                            py-2
                                            rounded-full
                                            bg-red-500/15
                                            border
                                            border-red-500/30
                                            text-red-400
                                            font-black
                                            text-sm
                                        "
                                    >

                                        ✕ Wrong Prediction
                                        +0 pts

                                    </span>


                                <?php endif; ?>


                            </div>

                        </div>


                    <?php else: ?>


                        <div
                            class="
                                mt-7
                                pt-5
                                border-t
                                border-white/10
                                text-center
                            "
                        >

                            <span
                                class="
                                    inline-flex
                                    px-4
                                    py-2
                                    rounded-full
                                    bg-white/5
                                    border
                                    border-white/10
                                    text-gray-500
                                    text-sm
                                    font-bold
                                "
                            >

                                Match Not Finished Yet

                            </span>

                        </div>


                    <?php endif; ?>


                </div>

            </div>


        <?php endforeach; ?>


    <?php endif; ?>


    <!-- ===================================================== -->
    <!-- BACK -->
    <!-- ===================================================== -->

    <div
        class="
            text-center
            mt-10
        "
    >

        <a
            href="
                other_matches.php?gameweek=<?= (int)
                    $selected_gw ?>
            "
            class="
                inline-flex
                items-center
                gap-2
                bg-accent
                hover:bg-pink-600
                text-black
                px-6
                py-3
                rounded-xl
                font-black
                transition
            "
        >

            ← Back to Gameweek
            <?= (int) $selected_gw ?>

        </a>

    </div>


</main>


</body>

</html>
