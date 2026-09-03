<?php

session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$current_user_id = (int) $_SESSION['user_id'];


/*
|--------------------------------------------------------------------------
| CHECK TARGET USER
|--------------------------------------------------------------------------
*/

if (!isset($_GET['user_id'])) {
    header("Location: my_predictions.php");
    exit();
}

$target_user_id = (int) $_GET['user_id'];

if ($target_user_id <= 0) {
    header("Location: my_predictions.php");
    exit();
}


/*
|--------------------------------------------------------------------------
| GET TARGET USER
|--------------------------------------------------------------------------
*/

$user_stmt = $conn->prepare("
    SELECT id, username, avatar
    FROM users
    WHERE id = ?
    LIMIT 1
");

if (!$user_stmt) {
    die("Database error: " . htmlspecialchars($conn->error));
}

$user_stmt->bind_param(
    "i",
    $target_user_id
);

$user_stmt->execute();

$user_result = $user_stmt->get_result();

$target_user = $user_result->fetch_assoc();

$user_stmt->close();


if (!$target_user) {
    header("Location: my_predictions.php");
    exit();
}


/*
|--------------------------------------------------------------------------
| GET GAMEWEEKS
|--------------------------------------------------------------------------
*/

$gameweeks = [];

$weeks_result = $conn->query("
    SELECT DISTINCT gameweek
    FROM matches
    WHERE competition = 'Premier League'
    ORDER BY gameweek ASC
");

if ($weeks_result) {

    while ($row = $weeks_result->fetch_assoc()) {

        $gameweeks[] = (int) $row['gameweek'];

    }

}


/*
|--------------------------------------------------------------------------
| GET LATEST GAMEWEEK
|--------------------------------------------------------------------------
*/

$latest_gameweek = 1;

$latest_result = $conn->query("
    SELECT MAX(gameweek) AS latest_gw
    FROM matches
    WHERE competition = 'Premier League'
");

if ($latest_result) {

    $latest_row = $latest_result->fetch_assoc();

    $latest_gameweek = (int) (
        $latest_row['latest_gw'] ?? 1
    );

}


/*
|--------------------------------------------------------------------------
| SELECTED GAMEWEEK
|--------------------------------------------------------------------------
*/

if (isset($_GET['gameweek'])) {

    $gameweek = (int) $_GET['gameweek'];

} else {

    $gameweek = $latest_gameweek;

}


/*
|--------------------------------------------------------------------------
| VERIFY GAMEWEEK
|--------------------------------------------------------------------------
*/

if (
    !empty($gameweeks)
    &&
    !in_array(
        $gameweek,
        $gameweeks,
        true
    )
) {

    $gameweek = $latest_gameweek;

}


/*
|--------------------------------------------------------------------------
| GET MATCHES FOR THIS GAMEWEEK
|--------------------------------------------------------------------------
|
| IMPORTANT:
|
| We first get the matches because the first match
| determines the deadline.
|
|--------------------------------------------------------------------------
*/

$matches = [];

$matches_stmt = $conn->prepare("
    SELECT
        m.id,
        m.home_team,
        m.away_team,
        m.match_date,
        m.home_score,
        m.away_score,
        m.gameweek,
        m.competition,

        p.predicted_home,
        p.predicted_away,
        p.points

    FROM matches m

    LEFT JOIN score_exact p
        ON p.match_id = m.id
        AND p.user_id = ?

    WHERE
        m.gameweek = ?
        AND m.competition = 'Premier League'

    ORDER BY
        m.match_date ASC
");

if (!$matches_stmt) {
    die("Database error: " . htmlspecialchars($conn->error));
}

$matches_stmt->bind_param(
    "ii",
    $target_user_id,
    $gameweek
);

$matches_stmt->execute();

$matches_result = $matches_stmt->get_result();

while ($row = $matches_result->fetch_assoc()) {

    $matches[] = $row;

}

$matches_stmt->close();


/*
|--------------------------------------------------------------------------
| DEADLINE
|--------------------------------------------------------------------------
|
| First match of the gameweek.
|
|--------------------------------------------------------------------------
*/

$deadline = null;

if (!empty($matches)) {

    $deadline = strtotime(
        $matches[0]['match_date']
    );

}


/*
|--------------------------------------------------------------------------
| SERVER-SIDE SECURITY
|--------------------------------------------------------------------------
|
| Nobody can see another user's predictions
| before the deadline.
|
|--------------------------------------------------------------------------
*/

$is_locked = false;

if (
    $deadline !== null
    &&
    time() >= $deadline
) {

    $is_locked = true;

}


/*
|--------------------------------------------------------------------------
| IMPORTANT SECURITY CHECK
|--------------------------------------------------------------------------
*/

if (!$is_locked) {

    header(
        "Location: my_predictions.php?gameweek="
        . $gameweek
    );

    exit();

}


/*
|--------------------------------------------------------------------------
| GET DOUBLE PICK
|--------------------------------------------------------------------------
|
| We only use this to correctly calculate/display
| the points of the selected user's predictions.
|
|--------------------------------------------------------------------------
*/

$double_match_id = null;

$double_stmt = $conn->prepare("
    SELECT match_id
    FROM double_gameweek
    WHERE
        user_id = ?
        AND gameweek = ?
    LIMIT 1
");

if ($double_stmt) {

    $double_stmt->bind_param(
        "ii",
        $target_user_id,
        $gameweek
    );

    $double_stmt->execute();

    $double_result =
        $double_stmt->get_result();

    $double_row =
        $double_result->fetch_assoc();

    if ($double_row) {

        $double_match_id =
            (int) $double_row['match_id'];

    }

    $double_stmt->close();

}


/*
|--------------------------------------------------------------------------
| TOTAL POINTS
|--------------------------------------------------------------------------
*/

$total_points = 0;

$total_stmt = $conn->prepare("
    SELECT
        COALESCE(
            SUM(
                CASE
                    WHEN dg.match_id IS NOT NULL
                    THEN COALESCE(p.points, 0) * 2
                    ELSE COALESCE(p.points, 0)
                END
            ),
            0
        ) AS total_points

    FROM score_exact p

    INNER JOIN matches m
        ON m.id = p.match_id

    LEFT JOIN double_gameweek dg
        ON dg.user_id = p.user_id
        AND dg.match_id = p.match_id
        AND dg.gameweek = m.gameweek

    WHERE
        p.user_id = ?
        AND m.gameweek = ?
        AND m.competition = 'Premier League'
");

if ($total_stmt) {

    $total_stmt->bind_param(
        "ii",
        $target_user_id,
        $gameweek
    );

    $total_stmt->execute();

    $total_row =
        $total_stmt
        ->get_result()
        ->fetch_assoc();

    $total_points =
        (int) (
            $total_row['total_points']
            ?? 0
        );

    $total_stmt->close();

}


/*
|--------------------------------------------------------------------------
| TEAM LOGOS
|--------------------------------------------------------------------------
*/

function teamLogo($teamName)
{
    $teamName = strtolower(trim($teamName));

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
| POINT BADGE
|--------------------------------------------------------------------------
*/

function pointBadge($points, $isDouble = false)
{
    if (
        $points === null
        ||
        $points === ''
    ) {

        return '
            <span class="
                inline-flex
                items-center
                justify-center
                min-w-[48px]
                px-3
                py-1.5
                rounded-full
                bg-gray-700
                text-gray-300
                font-black
            ">
                -
            </span>
        ';

    }


    $points = (int) $points;

    $displayPoints =
        $isDouble
        ? $points * 2
        : $points;


    if ($points >= 3) {

        $class =
            'bg-green-400 text-black';

    } elseif ($points === 1) {

        $class =
            'bg-yellow-400 text-black';

    } else {

        $class =
            'bg-red-500 text-white';

    }


    return '
        <span class="
            inline-flex
            items-center
            justify-center
            min-w-[48px]
            px-3
            py-1.5
            rounded-full
            font-black
            ' . $class . '
        ">
            '
            . ($isDouble ? '⭐ ' : '')
            . $displayPoints .
        '
        </span>
    ';
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
    <?= htmlspecialchars($target_user['username']) ?>
    - Predictions
</title>

<script src="https://cdn.tailwindcss.com"></script>

<style>

:root {

    --pl-dark: #050406;
    --pl-purple: #37003c;
    --pl-pink: #e90052;
    --pl-yellow: #ffd86b;

}

body {

    background:

        radial-gradient(
            circle at 50% -10%,
            #5b0064 0%,
            #37003c 18%,
            #100014 45%,
            #050406 80%
        );

}

.team-logo {

    width: 72px;
    height: 72px;

    object-fit: contain;

    background: white;

    border-radius: 50%;

    padding: 7px;

    border:
        2px solid
        rgba(255,255,255,.15);

    box-shadow:
        0 10px 30px
        rgba(0,0,0,.55);

}

.prediction-card {

    background:
        linear-gradient(
            180deg,
            rgba(255,255,255,.065),
            rgba(255,255,255,.018)
        );

    border:
        1px solid
        rgba(255,255,255,.10);

    box-shadow:
        0 18px 50px
        rgba(0,0,0,.45);

    backdrop-filter:
        blur(14px);

}

.score-box {

    background:
        rgba(0,0,0,.45);

    border:
        2px solid
        rgba(233,0,82,.50);

    color:
        #ffd86b;

}

</style>

</head>


<body class="min-h-screen text-white">


<!-- =========================================================
     NAVBAR
========================================================= -->

<nav
    class="
        fixed
        top-0
        left-0
        right-0
        z-50
        bg-black/65
        backdrop-blur-2xl
        border-b
        border-white/10
        px-5
        md:px-8
        py-4
        flex
        justify-between
        items-center
    "
>

    <a
        href="dashboard.php"
        class="flex items-center gap-3"
    >

        <div
            class="
                w-11
                h-11
                rounded-full
                bg-white
                p-1
            "
        >

            <img
                src="PL_img/PL_LOGO1.png"
                class="w-full h-full object-contain"
                alt="Premier League"
            >

        </div>


        <span
            class="
                hidden
                sm:block
                text-lg
                font-black
            "
        >
            Premier League
        </span>

    </a>


    <div
        class="
            hidden
            md:flex
            items-center
            gap-7
            text-sm
            font-semibold
        "
    >

        <a
            href="dashboard.php"
            class="hover:text-pink-400"
        >
            Dashboard
        </a>

        <a
            href="predictions.php"
            class="hover:text-pink-400"
        >
            Predictions
        </a>

        <a
            href="leaderboard.php"
            class="hover:text-pink-400"
        >
            Leaderboard
        </a>

        <a
            href="my_predictions.php"
            class="hover:text-pink-400"
        >
            My Predictions
        </a>

    </div>

</nav>


<div class="h-24"></div>


<!-- =========================================================
     MAIN
========================================================= -->

<main
    class="
        max-w-5xl
        mx-auto
        px-4
        pb-16
    "
>


<!-- =========================================================
     HEADER
========================================================= -->

<div
    class="
        prediction-card
        rounded-2xl
        p-6
        md:p-8
        mb-8
        text-center
    "
>

    <div
        class="
            w-20
            h-20
            mx-auto
            rounded-full
            bg-gradient-to-br
            from-purple-700
            to-pink-600
            flex
            items-center
            justify-center
            text-3xl
            font-black
            mb-4
        "
    >

        <?= htmlspecialchars(
            strtoupper(
                substr(
                    $target_user['username'],
                    0,
                    1
                )
            )
        ) ?>

    </div>


    <h1
        class="
            text-3xl
            md:text-4xl
            font-black
            text-pink-400
        "
    >

        <?= htmlspecialchars(
            $target_user['username']
        ) ?>

    </h1>


    <p
        class="
            text-gray-400
            mt-2
        "
    >

        Gameweek <?= $gameweek ?>
        • Predictions

    </p>


    <div
        class="
            mt-5
            inline-flex
            items-center
            gap-2
            bg-pink-500
            text-black
            px-5
            py-3
            rounded-xl
            font-black
        "
    >

        ⭐
        <?= $total_points ?>
        Points

    </div>

</div>


<!-- =========================================================
     GAMEWEEK SELECTOR
========================================================= -->

<?php if (!empty($gameweeks)): ?>

<div
    class="
        flex
        justify-center
        mb-8
    "
>

    <form method="GET">

        <input
            type="hidden"
            name="user_id"
            value="<?= $target_user_id ?>"
        >

        <select
            name="gameweek"
            onchange="this.form.submit()"
            class="
                bg-[#120014]
                border
                border-pink-500/60
                text-white
                rounded-xl
                px-5
                py-3
                font-bold
                outline-none
                cursor-pointer
            "
        >

            <?php foreach (
                $gameweeks
                as $gw
            ): ?>

                <option
                    value="<?= $gw ?>"
                    <?= $gw == $gameweek
                        ? 'selected'
                        : '' ?>
                >

                    Gameweek <?= $gw ?>

                </option>

            <?php endforeach; ?>

        </select>

    </form>

</div>

<?php endif; ?>


<!-- =========================================================
     READ ONLY NOTICE
========================================================= -->

<div
    class="
        mb-8
        rounded-2xl
        p-5
        bg-green-500/10
        border
        border-green-500/25
        text-center
    "
>

    <div
        class="
            text-2xl
            mb-2
        "
    >
        🔒
    </div>


    <h2
        class="
            font-black
            text-green-400
        "
    >

        Read Only

    </h2>


    <p
        class="
            text-gray-400
            text-sm
            mt-1
        "
    >

        These are
        <?= htmlspecialchars(
            $target_user['username']
        ) ?>'s
        predictions for Gameweek
        <?= $gameweek ?>.

    </p>

</div>


<!-- =========================================================
     PREDICTIONS
========================================================= -->

<?php if (empty($matches)): ?>

    <div
        class="
            prediction-card
            rounded-2xl
            p-12
            text-center
        "
    >

        <div class="text-5xl mb-4">
            ⚽
        </div>

        <p class="text-gray-400">

            No matches available for this gameweek.

        </p>

    </div>

<?php else: ?>


    <?php foreach ($matches as $match): ?>

        <?php

        $match_id =
            (int) $match['id'];

        $home_logo =
            teamLogo(
                $match['home_team']
            );

        $away_logo =
            teamLogo(
                $match['away_team']
            );

        $has_prediction =
            $match['predicted_home'] !== null
            &&
            $match['predicted_away'] !== null;

        $is_double =
            $double_match_id === $match_id;

        ?>


        <div
            class="
                prediction-card
                rounded-2xl
                overflow-hidden
                mb-7
            "
        >


            <?php if ($is_double): ?>

                <div
                    class="
                        bg-yellow-400
                        text-black
                        px-5
                        py-3
                        text-center
                        font-black
                    "
                >

                    ⭐ DOUBLE PICK
                    • 2× POINTS

                </div>

            <?php endif; ?>


            <!-- DATE -->

            <div
                class="
                    bg-black/30
                    border-b
                    border-white/10
                    text-center
                    py-3
                    text-gray-400
                    text-sm
                "
            >

                <?= date(
                    'D, d M Y • H:i',
                    strtotime(
                        $match['match_date']
                    )
                ) ?>

            </div>


            <!-- MATCH -->

            <div class="p-6 md:p-8">


                <div
                    class="
                        flex
                        items-center
                        justify-center
                        gap-5
                        sm:gap-10
                        md:gap-16
                    "
                >


                    <!-- AWAY -->

                    <div
                        class="
                            flex
                            flex-col
                            items-center
                            w-1/3
                        "
                    >

                        <?php if ($away_logo): ?>

                            <img
                                src="<?= htmlspecialchars(
                                    $away_logo
                                ) ?>"
                                class="team-logo"
                                alt="<?= htmlspecialchars(
                                    $match['away_team']
                                ) ?>"
                            >

                        <?php else: ?>

                            <div
                                class="
                                    team-logo
                                    flex
                                    items-center
                                    justify-center
                                    text-black
                                    text-xs
                                    font-black
                                    text-center
                                "
                            >

                                <?= htmlspecialchars(
                                    $match['away_team']
                                ) ?>

                            </div>

                        <?php endif; ?>


                        <h3
                            class="
                                mt-3
                                font-black
                                text-center
                                text-xs
                                sm:text-sm
                                md:text-lg
                            "
                        >

                            <?= htmlspecialchars(
                                $match['away_team']
                            ) ?>

                        </h3>


                        <span
                            class="
                                text-[10px]
                                text-gray-500
                                mt-1
                            "
                        >

                            AWAY

                        </span>

                    </div>


                    <!-- CENTER -->

                    <div
                        class="
                            flex
                            flex-col
                            items-center
                            justify-center
                            min-w-[100px]
                        "
                    >

                        <span
                            class="
                                text-[10px]
                                sm:text-xs
                                text-gray-500
                                uppercase
                                font-bold
                            "
                        >

                            Prediction

                        </span>


                        <?php if ($has_prediction): ?>

                            <div
                                class="
                                    score-box
                                    rounded-xl
                                    px-5
                                    py-3
                                    text-xl
                                    sm:text-2xl
                                    font-black
                                    mt-2
                                "
                            >

                                <?= (int)
                                    $match['predicted_away']
                                ?>

                                <span
                                    class="
                                        text-pink-400
                                        mx-1
                                    "
                                >
                                    -
                                </span>

                                <?= (int)
                                    $match['predicted_home']
                                ?>

                            </div>

                        <?php else: ?>

                            <div
                                class="
                                    mt-2
                                    text-gray-500
                                    font-bold
                                "
                            >

                                Not submitted

                            </div>

                        <?php endif; ?>

                    </div>


                    <!-- HOME -->

                    <div
                        class="
                            flex
                            flex-col
                            items-center
                            w-1/3
                        "
                    >

                        <?php if ($home_logo): ?>

                            <img
                                src="<?= htmlspecialchars(
                                    $home_logo
                                ) ?>"
                                class="team-logo"
                                alt="<?= htmlspecialchars(
                                    $match['home_team']
                                ) ?>"
                            >

                        <?php else: ?>

                            <div
                                class="
                                    team-logo
                                    flex
                                    items-center
                                    justify-center
                                    text-black
                                    text-xs
                                    font-black
                                    text-center
                                "
                            >

                                <?= htmlspecialchars(
                                    $match['home_team']
                                ) ?>

                            </div>

                        <?php endif; ?>


                        <h3
                            class="
                                mt-3
                                font-black
                                text-center
                                text-xs
                                sm:text-sm
                                md:text-lg
                            "
                        >

                            <?= htmlspecialchars(
                                $match['home_team']
                            ) ?>

                        </h3>


                        <span
                            class="
                                text-[10px]
                                text-gray-500
                                mt-1
                            "
                        >

                            HOME

                        </span>

                    </div>

                </div>


                <!-- POINTS -->

                <?php if ($has_prediction): ?>

                    <div
                        class="
                            mt-7
                            flex
                            justify-center
                        "
                    >

                        <div
                            class="
                                bg-black/30
                                border
                                border-white/10
                                rounded-xl
                                px-7
                                py-3
                                text-center
                            "
                        >

                            <div
                                class="
                                    text-xs
                                    text-gray-500
                                    mb-2
                                "
                            >

                                POINTS

                            </div>


                            <?= pointBadge(
                                $match['points'],
                                $is_double
                            ) ?>

                        </div>

                    </div>

                <?php endif; ?>


            </div>

        </div>

    <?php endforeach; ?>


<?php endif; ?>


<!-- =========================================================
     BACK
========================================================= -->

<div
    class="
        text-center
        mt-8
    "
>

    <a
        href="my_predictions.php?gameweek=<?= $gameweek ?>"
        class="
            inline-flex
            items-center
            gap-2
            bg-pink-500
            hover:bg-pink-600
            text-black
            px-6
            py-3
            rounded-xl
            font-black
            transition
        "
    >

        ← Back to My Predictions

    </a>

</div>


</main>

</body>

</html>