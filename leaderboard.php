<?php

session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int) $_SESSION['user_id'];

function e($value)
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function teamLogo($logo)
{
    if (empty($logo)) {
        return null;
    }

    $logo = trim($logo);
    $logo = str_replace('\\', '/', $logo);
    $logo = ltrim($logo, '/');

    // Keep the logo path stored in the teams table.
    // This supports PL_Teams, LaLiga_Teams, SerieA_Teams,
    // Bundesliga_Teams, Botola_Teams, and any future league folder.
    if (strpos($logo, 'MyPredictionWebsite/') === 0) {
        $logo = substr($logo, strlen('MyPredictionWebsite/'));
    }

    return $logo;
}

$pl_logo = 'PL_img/PL_LOGO1.png';

$current_user = null;

$user_stmt = $conn->prepare("
    SELECT
        id,
        username,
        favorite_team
    FROM users
    WHERE id = ?
    LIMIT 1
");

if ($user_stmt) {

    $user_stmt->bind_param(
        "i",
        $user_id
    );

    $user_stmt->execute();

    $current_user =
        $user_stmt
            ->get_result()
            ->fetch_assoc();

    $user_stmt->close();
}

$leaderboard_sql = "

    SELECT

        u.id,

        u.username,

        u.favorite_team,

        t.logo AS team_logo,

        COUNT(DISTINCT p.id) AS predictions_count,

        COALESCE(SUM(COALESCE(p.points,0)),0) AS total_points,

        SUM(
            CASE
                WHEN p.base_points = 3
                THEN 1
                ELSE 0
            END
        ) AS exact_scores,

        SUM(
            CASE
                WHEN p.base_points = 1
                THEN 1
                ELSE 0
            END
        ) AS correct_results,

        SUM(
            CASE
                WHEN p.base_points = 0
                THEN 1
                ELSE 0
            END
        ) AS wrong_predictions

    FROM users u

    LEFT JOIN teams t
        ON LOWER(TRIM(u.favorite_team))
        =
        LOWER(TRIM(t.name))

    LEFT JOIN score_exact p
        ON u.id = p.user_id

    LEFT JOIN matches m
        ON m.id = p.match_id

    LEFT JOIN double_gameweek dg
        ON dg.user_id = p.user_id
        AND dg.match_id = p.match_id
        AND dg.gameweek = m.gameweek

    GROUP BY
        u.id,
        u.username,
        u.favorite_team,
        t.logo

    ORDER BY
        total_points DESC,
        exact_scores DESC,
        correct_results DESC,
        u.username ASC
";

$result = $conn->query($leaderboard_sql);

if (!$result) {

    die(
        "Leaderboard database error: "
        . e($conn->error)
    );
}

$players = [];

while ($row = $result->fetch_assoc()) {

    $row['total_points'] =
        (int)$row['total_points'];

    $row['predictions_count'] =
        (int)$row['predictions_count'];

    $row['exact_scores'] =
        (int)$row['exact_scores'];

    $row['correct_results'] =
        (int)$row['correct_results'];

    $row['wrong_predictions'] =
        (int)$row['wrong_predictions'];

    $players[] = $row;
}

$total_users = 0;

$count_result = $conn->query("
    SELECT COUNT(*) AS total
    FROM users
");

if ($count_result) {

    $count_row =
        $count_result->fetch_assoc();

    $total_users =
        (int)$count_row['total'];
}

$total_predictions = 0;

$prediction_count_result = $conn->query("
    SELECT COUNT(*) AS total
    FROM score_exact
");

if ($prediction_count_result) {

    $prediction_count_row =
        $prediction_count_result->fetch_assoc();

    $total_predictions =
        (int)$prediction_count_row['total'];
}

$total_points = 0;

$total_points_result = $conn->query("

    SELECT
        COALESCE(SUM(COALESCE(p.points,0)),0) AS total_points

    FROM score_exact p

    INNER JOIN matches m
        ON m.id = p.match_id

    LEFT JOIN double_gameweek dg
        ON dg.user_id = p.user_id
        AND dg.match_id = p.match_id
        AND dg.gameweek = m.gameweek

");

if ($total_points_result) {

    $total_points_row =
        $total_points_result->fetch_assoc();

    $total_points =
        (int)$total_points_row['total_points'];
}

$total_exact = 0;

$exact_result = $conn->query("
    SELECT COUNT(*) AS total
    FROM score_exact
    WHERE base_points = 3
");

if ($exact_result) {

    $exact_row =
        $exact_result->fetch_assoc();

    $total_exact =
        (int)$exact_row['total'];
}

$total_correct = 0;

$correct_result = $conn->query("
    SELECT COUNT(*) AS total
    FROM score_exact
    WHERE base_points = 1
");

if ($correct_result) {

    $correct_row =
        $correct_result->fetch_assoc();

    $total_correct =
        (int)$correct_row['total'];
}

$top_user =
    $players[0]
    ?? null;

$my_stats = [

    'points' => 0,

    'predictions' => 0,

    'exact' => 0,

    'correct' => 0,

    'wrong' => 0
];

foreach ($players as $index => $player) {

    if (
        (int)$player['id']
        === $user_id
    ) {

        $my_stats = [

            'points' =>
                (int)$player['total_points'],

            'predictions' =>
                (int)$player['predictions_count'],

            'exact' =>
                (int)$player['exact_scores'],

            'correct' =>
                (int)$player['correct_results'],

            'wrong' =>
                (int)$player['wrong_predictions']
        ];

        break;
    }
}

$my_rank = null;

foreach ($players as $index => $player) {

    if (
        (int)$player['id']
        === $user_id
    ) {

        $my_rank = $index + 1;

        break;
    }
}

$my_accuracy = 0;

if ($my_stats['predictions'] > 0) {

    $successful =
        $my_stats['exact'] +
        $my_stats['correct'];

    $my_accuracy =
        round(
            ($successful /
                $my_stats['predictions']) *
            100
        );
}

$global_accuracy = 0;

if ($total_predictions > 0) {

    $successful_total =
        $total_exact +
        $total_correct;

    $global_accuracy =
        round(
            ($successful_total /
                $total_predictions) *
            100
        );
}

$average_points = 0;

if ($total_predictions > 0) {

    $average_points =
        round(
            $total_points /
            $total_predictions,
            2
        );
}

$max_points =
    !empty($players)
    ? max(
        array_column(
            $players,
            'total_points'
        )
    )
    : 1;

if ($max_points <= 0) {
    $max_points = 1;
}

function rankBadge($rank)
{
    if ($rank === 1) {
        return '#1';
    }

    if ($rank === 2) {
        return '#2';
    }

    if ($rank === 3) {
        return '#3';
    }

    return '#' . $rank;
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
    Leaderboard | Premier League
</title>

<script src="https://cdn.tailwindcss.com"></script>

<style>

body {
    background-image: url('PL_img/current.jpg');
    background-size: cover;
    background-position: center;
    background-attachment: fixed;
    background-color: #1c003a;
    color: #f7f2fa;
    font-family: Arial, Helvetica, sans-serif;
}

body::before {
    content: "";
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(28, 0, 58, 0.65);
    z-index: -1;
    pointer-events: none;
}

.card {
    background: rgba(28, 0, 58, 0.85);
    border: 1px solid rgba(255, 0, 128, 0.30);
    backdrop-filter: blur(12px);
    box-shadow: 0 0 40px rgba(233, 0, 82, 0.25);
}

.stat-card {
    position: relative;
    overflow: hidden;
    transition: .25s ease;
}

.stat-card::after {
    content: "";
    position: absolute;
    width: 100px;
    height: 100px;
    right: -40px;
    top: -40px;
    background: rgba(255, 153, 0, 0.15);
    border-radius: 50%;
}

.stat-card:hover {
    transform: translateY(-4px);
    border-color: rgba(255, 0, 128, 0.60);
}

.player-row {
    background: rgba(255,255,255,.025);
    border: 1px solid rgba(255,255,255,.06);
    transition: .2s ease;
}

.player-row:hover {
    background: rgba(233,0,82,.075);
    border-color: rgba(233,0,82,.30);
    transform: translateX(-2px);
}

.my-row {
    border: 1px solid rgba(255, 0, 128, 0.65);
    background: linear-gradient(90deg, rgba(233,0,82,.15), rgba(28,0,58,.18));
    box-shadow: 0 0 25px rgba(233,0,82,.10);
}

.team-logo {
    width: 54px;
    height: 54px;
}

.pl-logo {
    object-fit: contain;
}

.rank-number {
    min-width: 55px;
}

.progress-bg {
    height: 6px;
    background: rgba(255,255,255,.08);
    border-radius: 999px;
    overflow: hidden;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #ff9900, #e90052);
    border-radius: 999px;
}

@media(max-width:640px) {

    .team-logo {
        width: 68px;
        height: 68px;
    }

    .rank-number {
        min-width: 38px;
    }

}

</style>

</head>

<body class="min-h-screen pb-16">

<nav
    class="
        fixed
        top-0
        left-0
        right-0
        z-50
        bg-[#1c003a]/80
        backdrop-blur-xl
        border-b
        border-[#ff0080]/30
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
        class="
            flex
            items-center
            gap-3
        "
    >

        <div
            class="
                w-11
                h-11
                rounded-full
                flex
                items-center
                justify-center
                overflow-hidden
            "
        >

            <img
                src="<?= e($pl_logo) ?>"
                class="
                    w-full
                    h-full
                    object-contain
                "
                alt="Premier League"
            >

        </div>


        <span
            class="
                font-black
                text-lg
                text-white
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
            font-bold
        "
    >

        <a
            href="dashboard.php"
            class="
                hover:text-[#ff9900]
                transition
            "
        >
            Dashboard
        </a>

        <a
            href="predictions.php"
            class="
                hover:text-[#ff9900]
                transition
            "
        >
            Predictions
        </a>

        <a
            href="leaderboard.php"
            class="
                text-[#ff0080]
            "
        >
            Leaderboard
        </a>

        <a
            href="my_predictions.php"
            class="
                hover:text-[#ff9900]
                transition
            "
        >
            My Predictions
        </a>

    </div>


    <button
        onclick="toggleMenu()"
        class="
            md:hidden
            text-lg
            px-2
            font-bold
            text-white
        "
    >
        Menu
    </button>

</nav>

<div
    id="mobileMenu"
    class="
        hidden
        fixed
        top-[73px]
        left-0
        right-0
        z-40
        bg-[#1c003a]/95
        backdrop-blur-xl
        border-b
        border-[#ff0080]/30
        p-6
    "
>

    <div
        class="
            flex
            flex-col
            gap-5
            font-bold
        "
    >

        <a href="dashboard.php" class="hover:text-[#ff9900] transition">
            Dashboard
        </a>

        <a href="predictions.php" class="hover:text-[#ff9900] transition">
            Predictions
        </a>

        <a
            href="leaderboard.php"
            class="text-[#ff0080]"
        >
            Leaderboard
        </a>

        <a href="my_predictions.php" class="hover:text-[#ff9900] transition">
            My Predictions
        </a>

    </div>

</div>

<script>

function toggleMenu()
{
    document
        .getElementById('mobileMenu')
        .classList
        .toggle('hidden');
}

</script>

<div class="h-24"></div>

<main
    class="
        max-w-7xl
        mx-auto
        px-4
    "
>

<div
    class="
        flex
        flex-col
        md:flex-row
        justify-between
        items-center
        gap-6
        mb-8
    "
>

    <div
        class="
            flex
            items-center
            gap-4
        "
    >

        <div
            class="
                w-16
                h-16
                rounded-full
                p-2
                flex
                items-center
                justify-center
            "
        >

            <img
                src="<?= e($pl_logo) ?>"
                class="
                    w-full
                    h-full
                    object-contain
                "
                alt="Premier League"
            >

        </div>


        <div>

            <div
                class="
                    text-[#ff0080]
                    text-sm
                    font-black
                    uppercase
                    tracking-widest
                "
            >
                Competition
            </div>

            <h1
                class="
                    text-3xl
                    md:text-5xl
                    font-black
                    text-white
                "
            >
                Leaderboard
            </h1>

            <p
                class="
                    text-gray-300
                    mt-1
                "
            >
                See who is dominating the predictions.
            </p>

        </div>

    </div>

    <?php if ($my_rank !== null): ?>

        <div
            class="
                card
                rounded-2xl
                px-6
                py-4
                text-center
            "
        >

            <div
                class="
                    text-xs
                    text-gray-400
                    uppercase
                    font-bold
                "
            >
                Your Rank
            </div>

            <div
                class="
                    text-3xl
                    font-black
                    text-[#ff9900]
                "
            >
                #<?= $my_rank ?>
            </div>

        </div>

    <?php endif; ?>

</div>

<div
    class="
        grid
        grid-cols-2
        lg:grid-cols-4
        gap-4
        mb-8
    "
>

    <div
        class="
            card
            stat-card
            rounded-2xl
            p-5
        "
    >

        <div
            class="
                text-xs
                uppercase
                font-bold
                text-gray-400
            "
        >
            Total Users
        </div>

        <div
            class="
                text-3xl
                font-black
                mt-1
                text-white
            "
        >
            <?= $total_users ?>
        </div>

    </div>

    <div
        class="
            card
            stat-card
            rounded-2xl
            p-5
        "
    >

        <div
            class="
                text-xs
                uppercase
                font-bold
                text-gray-400
            "
        >
            Predictions
        </div>

        <div
            class="
                text-3xl
                font-black
                mt-1
                text-white
            "
        >
            <?= $total_predictions ?>
        </div>

    </div>

    <div
        class="
            card
            stat-card
            rounded-2xl
            p-5
        "
    >

        <div
            class="
                text-xs
                uppercase
                font-bold
                text-gray-400
            "
        >
            Total Points
        </div>

        <div
            class="
                text-3xl
                font-black
                text-[#ff0080]
                mt-1
            "
        >
            <?= $total_points ?>
        </div>

    </div>

    <div
        class="
            card
            stat-card
            rounded-2xl
            p-5
        "
    >

        <div
            class="
                text-xs
                uppercase
                font-bold
                text-gray-400
            "
        >
            Global Accuracy
        </div>

        <div
            class="
                text-3xl
                font-black
                text-[#ff9900]
                mt-1
            "
        >
            <?= $global_accuracy ?>%
        </div>

    </div>

</div>

<div
    class="
        grid
        grid-cols-2
        md:grid-cols-4
        gap-4
        mb-8
    "
>

    <div
        class="
            card
            rounded-2xl
            p-5
        "
    >

        <div
            class="
                text-xs
                text-gray-400
                uppercase
                font-bold
            "
        >
            Exact Scores
        </div>

        <div
            class="
                text-2xl
                font-black
                text-green-400
                mt-2
            "
        >
            <?= $total_exact ?>
        </div>

        <div
            class="
                text-xs
                text-gray-500
                mt-1
            "
        >
            3 points each
        </div>

    </div>

    <div
        class="
            card
            rounded-2xl
            p-5
        "
    >

        <div
            class="
                text-xs
                text-gray-400
                uppercase
                font-bold
            "
        >
            Correct Results
        </div>

        <div
            class="
                text-2xl
                font-black
                text-[#ff9900]
                mt-2
            "
        >
            <?= $total_correct ?>
        </div>

        <div
            class="
                text-xs
                text-gray-500
                mt-1
            "
        >
            1 point each
        </div>

    </div>

    <div
        class="
            card
            rounded-2xl
            p-5
        "
    >

        <div
            class="
                text-xs
                text-gray-400
                uppercase
                font-bold
            "
        >
            Avg Points
        </div>

        <div
            class="
                text-2xl
                font-black
                text-[#ff0080]
                mt-2
            "
        >
            <?= number_format(
                $average_points,
                2
            ) ?>
        </div>

        <div
            class="
                text-xs
                text-gray-500
                mt-1
            "
        >
            per prediction
        </div>

    </div>

    <div
        class="
            card
            rounded-2xl
            p-5
        "
    >

        <div
            class="
                text-xs
                text-gray-400
                uppercase
                font-bold
            "
        >
            Best Predictor
        </div>

        <?php if ($top_user): ?>

            <div
                class="
                    text-lg
                    font-black
                    text-white
                    mt-2
                    truncate
                "
            >
                <?= e($top_user['username']) ?>
            </div>

            <div
                class="
                    text-xs
                    text-[#ff0080]
                    font-bold
                "
            >
                <?= $top_user['total_points'] ?>
                points
            </div>

        <?php else: ?>

            <div
                class="text-gray-500 mt-2"
            >
                No data
            </div>

        <?php endif; ?>

    </div>

</div>

<?php if ($my_rank !== null): ?>

<div
    class="
        card
        rounded-3xl
        p-6
        mb-8
    "
>

    <div
        class="
            flex
            flex-col
            md:flex-row
            justify-between
            md:items-center
            gap-5
            mb-6
        "
    >

        <div>

            <div
                class="
                    text-xs
                    uppercase
                    tracking-widest
                    text-[#ff0080]
                    font-black
                "
            >
                Your Performance
            </div>

            <h2
                class="
                    text-2xl
                    font-black
                    mt-1
                    text-white
                "
            >
                <?= e(
                    $current_user['username']
                    ?? 'You'
                ) ?>
            </h2>

        </div>


        <div
            class="
                bg-gradient-to-r from-[#e90052] to-[#ff9900]
                text-white
                px-5
                py-3
                rounded-xl
                font-black
                text-center
            "
        >
            Rank #<?= $my_rank ?>
        </div>

    </div>

    <div
        class="
            grid
            grid-cols-2
            md:grid-cols-5
            gap-3
        "
    >

        <div
            class="
                bg-black/25
                rounded-xl
                p-4
                text-center
            "
        >

            <div
                class="
                    text-xs
                    text-gray-400
                "
            >
                POINTS
            </div>

            <div
                class="
                    text-2xl
                    font-black
                    text-[#ff0080]
                "
            >
                <?= $my_stats['points'] ?>
            </div>

        </div>

        <div
            class="
                bg-black/25
                rounded-xl
                p-4
                text-center
            "
        >

            <div
                class="
                    text-xs
                    text-gray-400
                "
            >
                PREDICTIONS
            </div>

            <div
                class="
                    text-2xl
                    font-black
                    text-white
                "
            >
                <?= $my_stats['predictions'] ?>
            </div>

        </div>

        <div
            class="
                bg-black/25
                rounded-xl
                p-4
                text-center
            "
        >

            <div
                class="
                    text-xs
                    text-gray-400
                "
            >
                EXACT
            </div>

            <div
                class="
                    text-2xl
                    font-black
                    text-green-400
                "
            >
                <?= $my_stats['exact'] ?>
            </div>

        </div>

        <div
            class="
                bg-black/25
                rounded-xl
                p-4
                text-center
            "
        >

            <div
                class="
                    text-xs
                    text-gray-400
                "
            >
                CORRECT
            </div>

            <div
                class="
                    text-2xl
                    font-black
                    text-[#ff9900]
                "
            >
                <?= $my_stats['correct'] ?>
            </div>

        </div>

        <div
            class="
                bg-black/25
                rounded-xl
                p-4
                text-center
            "
        >

            <div
                class="
                    text-xs
                    text-gray-400
                "
            >
                ACCURACY
            </div>

            <div
                class="
                    text-2xl
                    font-black
                    text-[#ff0080]
                "
            >
                <?= $my_accuracy ?>%
            </div>

        </div>

    </div>

</div>

<?php endif; ?>

<div
    class="
        flex
        flex-col
        md:flex-row
        justify-between
        items-center
        gap-4
        mb-5
    "
>

    <div>

        <h2
            class="
                text-3xl
                font-black
                text-white
            "
        >
            Rankings
        </h2>

        <p
            class="
                text-gray-400
                text-sm
                mt-1
            "
        >
            Players ranked by total points
        </p>

    </div>

</div>

<div
    class="
        card
        rounded-3xl
        p-4
        md:p-6
    "
>

    <div
        class="
            hidden
            md:grid
            grid-cols-[70px_1.8fr_1.5fr_100px_110px_110px_110px]
            gap-4
            px-5
            py-4
            text-xs
            text-gray-400
            uppercase
            font-black
            tracking-wider
        "
    >

        <div>
            Rank
        </div>

        <div>
            Player
        </div>

        <div>
            Favorite Team
        </div>

        <div class="text-center">
            Predictions
        </div>

        <div class="text-center">
            Exact
        </div>

        <div class="text-center">
            Correct
        </div>

        <div class="text-center">
            Points
        </div>

    </div>

    <div
        id="playersList"
        class="space-y-2"
    >

        <?php

        $rank = 1;

        foreach ($players as $player):

            $logo =
                teamLogo(
                    $player['team_logo']
                    ?? ''
                );

            $is_me =
                (int)$player['id']
                === $user_id;

            $percentage =
                $max_points > 0
                ? round(
                    (
                        $player['total_points']
                        /
                        $max_points
                    ) * 100
                )
                : 0;

        ?>

        <div
            class="
                player-row
                <?= $is_me
                    ? 'my-row'
                    : ''
                ?>
                rounded-2xl
                p-4
                md:px-5
                md:py-4
                player-item
            "
            data-username="<?= e(
                strtolower(
                    $player['username']
                )
            ) ?>"
        >

            <div
                class="
                    hidden
                    md:grid
                    grid-cols-[70px_1.8fr_1.5fr_100px_110px_110px_110px]
                    gap-4
                    items-center
                "
            >

                <div
                    class="
                        rank-number
                        text-xl
                        font-black
                        text-[#ff9900]
                    "
                >

                    <?= rankBadge($rank) ?>

                </div>

                <div
                    class="
                        flex
                        items-center
                        gap-3
                        min-w-0
                    "
                >

                    <div
                        class="
                            w-11
                            h-11
                            rounded-full
                            bg-[#1c003a]
                            flex
                            items-center
                            justify-center
                            font-black
                            flex-shrink-0
                            text-white
                        "
                    >

                        <?= e(
                            strtoupper(
                                substr(
                                    $player['username'],
                                    0,
                                    1
                                )
                            )
                        ) ?>

                    </div>

                    <div
                        class="
                            min-w-0
                        "
                    >

                        <div
                            class="
                                font-black
                                truncate
                                text-white
                            "
                        >

                            <?= e(
                                $player['username']
                            ) ?>

                        </div>

                        <?php if ($is_me): ?>

                            <span
                                class="
                                    text-[10px]
                                    text-[#ff0080]
                                    font-black
                                "
                            >
                                YOU
                            </span>

                        <?php endif; ?>

                    </div>

                </div>

                <div
                    class="
                        flex
                        items-center
                        gap-3
                        min-w-0
                    "
                >

                    <?php if ($logo): ?>

                        <img
                            src="<?= e($logo) ?>"
                            class="
                                team-logo
                                flex-shrink-0
                            "
                            alt=""
                            onerror="
                                this.style.display='none';
                            "
                        >

                    <?php else: ?>

                        <div
                            class="
                                team-logo
                                flex
                                items-center
                                justify-center
                                text-black
                                text-[9px]
                                font-black
                            "
                        >
                            -
                        </div>

                    <?php endif; ?>

                    <span
                        class="
                            text-gray-300
                            font-semibold
                            truncate
                        "
                    >
                        <?= e(
                            $player['favorite_team']
                            ?: 'No team'
                        ) ?>
                    </span>

                </div>

                <div
                    class="
                        text-center
                        font-bold
                        text-white
                    "
                >

                    <?= $player[
                        'predictions_count'
                    ] ?>

                </div>

                <div
                    class="
                        text-center
                        font-black
                        text-green-400
                    "
                >

                    <?= $player[
                        'exact_scores'
                    ] ?>

                </div>

                <div
                    class="
                        text-center
                        font-black
                        text-[#ff9900]
                    "
                >

                    <?= $player[
                        'correct_results'
                    ] ?>

                </div>

                <div
                    class="
                        text-center
                    "
                >

                    <div
                        class="
                            text-xl
                            font-black
                            text-[#ff0080]
                        "
                    >

                        <?= $player[
                            'total_points'
                        ] ?>

                    </div>

                    <div
                        class="
                            progress-bg
                            mt-2
                        "
                    >

                        <div
                            class="progress-fill"
                            style="
                                width:
                                <?= $percentage ?>%;"
                        ></div>

                    </div>

                </div>

            </div>

            <div
                class="
                    md:hidden
                "
            >

                <div
                    class="
                        flex
                        items-center
                        justify-between
                        gap-3
                    "
                >

                    <div
                        class="
                            flex
                            items-center
                            gap-3
                            min-w-0
                        "
                    >

                        <div
                            class="
                                text-lg
                                font-black
                                w-8
                                text-[#ff9900]
                            "
                        >
                            <?= rankBadge(
                                $rank
                            ) ?>
                        </div>

                        <div
                            class="
                                w-10
                                h-10
                                rounded-full
                                bg-[#1c003a]
                                flex
                                items-center
                                justify-center
                                font-black
                                flex-shrink-0
                                text-white
                            "
                        >

                            <?= e(
                                strtoupper(
                                    substr(
                                        $player['username'],
                                        0,
                                        1
                                    )
                                )
                            ) ?>

                        </div>

                        <div
                            class="
                                min-w-0
                            "
                        >

                            <div
                                class="
                                    font-black
                                    truncate
                                    text-white
                                "
                            >
                                <?= e(
                                    $player['username']
                                ) ?>
                            </div>

                            <?php if ($is_me): ?>

                                <span
                                    class="
                                        text-[10px]
                                        text-[#ff0080]
                                        font-black
                                    "
                                >
                                    YOU
                                </span>

                            <?php endif; ?>

                        </div>

                    </div>

                    <div
                        class="
                            text-right
                            flex-shrink-0
                        "
                    >

                        <div
                            class="
                                text-xl
                                font-black
                                text-[#ff0080]
                            "
                        >
                            <?= $player[
                                'total_points'
                            ] ?>
                        </div>

                        <div
                            class="
                                text-[9px]
                                text-gray-400
                            "
                        >
                            POINTS
                        </div>

                    </div>

                </div>

                <div
                    class="
                        mt-4
                        flex
                        items-center
                        justify-between
                        gap-3
                    "
                >

                    <div
                        class="
                            flex
                            items-center
                            gap-2
                            min-w-0
                        "
                    >

                        <?php if ($logo): ?>

                            <img
                                src="<?= e($logo) ?>"
                                class="
                                    team-logo
                                "
                                alt=""
                                onerror="
                                    this.style.display='none';
                                "
                            >

                        <?php endif; ?>

                        <span
                            class="
                                text-xs
                                text-gray-300
                                truncate
                            "
                        >
                            <?= e(
                                $player['favorite_team']
                                ?: 'No team'
                            ) ?>
                        </span>

                    </div>

                    <div
                        class="
                            flex
                            gap-3
                            text-center
                            text-xs
                        "
                    >

                        <div>

                            <div
                                class="
                                    font-black
                                    text-white
                                "
                            >
                                <?= $player[
                                    'predictions_count'
                                ] ?>
                            </div>

                            <div
                                class="
                                    text-gray-500
                                "
                            >
                                PICKS
                            </div>

                        </div>

                        <div>

                            <div
                                class="
                                    font-black
                                    text-green-400
                                "
                            >
                                <?= $player[
                                    'exact_scores'
                                ] ?>
                            </div>

                            <div
                                class="
                                    text-gray-500
                                "
                            >
                                EXACT
                            </div>

                        </div>

                        <div>

                            <div
                                class="
                                    font-black
                                    text-[#ff9900]
                                "
                            >
                                <?= $player[
                                    'correct_results'
                                ] ?>
                            </div>

                            <div
                                class="
                                    text-gray-500
                                "
                            >
                                CORRECT
                            </div>

                        </div>

                    </div>

                </div>

                <div
                    class="
                        progress-bg
                        mt-4
                    "
                >

                    <div
                        class="progress-fill"
                        style="
                            width:
                            <?= $percentage ?>%;"
                    ></div>

                </div>

            </div>

        </div>

        <?php

        $rank++;

        endforeach;

        ?>

        <?php if (empty($players)): ?>

            <div
                class="
                    text-center
                    py-16
                    text-gray-500
                "
            >

                <div
                    class="
                        text-5xl
                        mb-4
                    "
                >
                    -
                </div>

                No players found.

            </div>

        <?php endif; ?>

    </div>

</div>

<div
    class="
        mt-8
        card
        rounded-2xl
        p-6
    "
>

    <h3
        class="
            text-xl
            font-black
            mb-4
            text-white
        "
    >
        Prediction Scoring
    </h3>

    <div
        class="
            grid
            grid-cols-1
            md:grid-cols-3
            gap-4
        "
    >

        <div
            class="
                bg-green-500/10
                border
                border-green-500/20
                rounded-xl
                p-4
            "
        >

            <div
                class="
                    text-green-400
                    font-black
                "
            >
                Exact Score
            </div>

            <div
                class="
                    text-2xl
                    font-black
                    mt-1
                    text-white
                "
            >
                3 Points
            </div>

        </div>

        <div
            class="
                bg-yellow-400/10
                border
                border-yellow-400/20
                rounded-xl
                p-4
            "
        >

            <div
                class="
                    text-[#ff9900]
                    font-black
                "
            >
                Correct Result
            </div>

            <div
                class="
                    text-2xl
                    font-black
                    mt-1
                    text-white
                "
            >
                1 Point
            </div>

        </div>

        <div
            class="
                bg-pink-500/10
                border
                border-pink-500/20
                rounded-xl
                p-4
            "
        >

            <div
                class="
                    text-[#ff0080]
                    font-black
                "
            >
                Double Pick
            </div>

            <div
                class="
                    text-2xl
                    font-black
                    mt-1
                    text-white
                "
            >
                x2 Points
            </div>

        </div>

    </div>

</div>

<div
    class="
        text-center
        text-gray-500
        text-sm
        mt-10
    "
>

    Premier League Prediction Leaderboard

    <br>

    Keep predicting. Keep climbing.

</div>

</main>

</body>

</html>