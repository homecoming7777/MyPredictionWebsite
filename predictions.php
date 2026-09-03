<?php

session_start();

include 'connect.php';
require_once 'gameweek_deadline.php';

date_default_timezone_set('Africa/Casablanca');

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['user_id'];

function e($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

$latest_sql = "
    SELECT MAX(gameweek) AS latest_gw
    FROM matches
    WHERE competition = 'Premier League'
";

$latest_result = $conn->query($latest_sql);

$latest_row = $latest_result
    ? $latest_result->fetch_assoc()
    : null;

$latest_gameweek = intval(
    $latest_row['latest_gw'] ?? 0
);

if (isset($_GET['gameweek'])) {
    $gameweek = (int) $_GET['gameweek'];
} else {
    $gameweek = $latest_gameweek;
}

if ($gameweek <= 0) {
    $gameweek = $latest_gameweek;
}

$gameweekDeadline = getGameweekDeadline(
    $conn,
    $gameweek
);

$deadlinePassed = isGameweekDeadlinePassed(
    $conn,
    $gameweek
);

$deadlineTimestamp = gameweekDeadlineTimestamp(
    $conn,
    $gameweek
);

$deadlineText = $gameweekDeadline
    ? $gameweekDeadline->format('D, d M Y • H:i')
    : null;

$sql = "
    SELECT
        m.*,
        p.predicted_home,
        p.predicted_away
    FROM matches m

    LEFT JOIN score_exact p
        ON m.id = p.match_id
        AND p.user_id = ?

    WHERE
        m.gameweek = ?
        AND m.competition = 'Premier League'

    ORDER BY m.match_date ASC
";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "ii",
    $user_id,
    $gameweek
);

$stmt->execute();

$result = $stmt->get_result();

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
    Predictions | Premier League
</title>
    <link rel="icon" type="image/jpg" href="PL_img/hadi.jpg">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.3.1/css/all.min.css" integrity="sha512-QeR2VH+lsBE5LSAe1Q5EnTBbe7XTBubt8dG93Y7gidSgdMCr8nVqKcfKAMyN96SV8KDbZVTDXChatu5G2KQGzg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<script src="https://cdn.tailwindcss.com"></script>

<style>

body {

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

    color:
        #f7f2fa;

    font-family:
        Arial,
        Helvetica,
        sans-serif;
}

body::before {

    content:
        "";

    position:
        fixed;

    top:
        0;

    left:
        0;

    width:
        100%;

    height:
        100%;

    background:
        rgba(28, 0, 58, 0.65);

    z-index:
        -1;

    pointer-events:
        none;
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
    class="flex items-center gap-3"
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
    src="PL_img/PL_LOGO1.png"
    class="w-full h-full object-contain"
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
    class="hover:text-[#ff9900] transition-colors"
>
    Dashboard
</a>

<a
    href="predictions.php"
    class="text-[#ff0080]"
>
    Predictions
</a>

<a
    href="leaderboard.php"
    class="hover:text-[#ff9900] transition-colors"
>
    Leaderboard
</a>

<a
    href="my_predictions.php"
    class="hover:text-[#ff9900] transition-colors"
>
    My Predictions
</a>

</div>

<button
    onclick="toggleMenu()"
    class="
        md:hidden
        text-2xl
        px-2
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

<a href="dashboard.php">
    Dashboard
</a>

<a
    href="predictions.php"
    class="text-[#ff0080]"
>
    Predictions
</a>

<a href="leaderboard.php">
    Leaderboard
</a>

<a href="my_predictions.php">
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
        max-w-6xl
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
    src="PL_img/PL_LOGO1.png"
    class="w-full h-full object-contain"
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
    Make Your Picks
</div>

<h1
    class="
        text-3xl
        md:text-5xl
        font-black
        text-white
    "
>
    Predictions
</h1>

<p
    class="
        text-gray-300
        mt-1
    "
>

Premier League

<span
    class="text-gray-500"
>
    •
</span>

Gameweek <?= e($gameweek) ?>

</p>

</div>

</div>

<form
    method="GET"
    class="
        flex
        items-center
        gap-3
    "
>

<label
    for="gameweek"
    class="
        text-sm
        font-bold
        text-gray-300
    "
>
    Gameweek
</label>

<select
    id="gameweek"
    name="gameweek"
    onchange="this.form.submit()"
    class="
        bg-[#1c003a]
        border
        border-[#ff0080]
        text-white
        rounded-xl
        px-4
        py-3
        font-bold
        outline-none
        cursor-pointer
    "
>

<?php

$weeks = $conn->query("
    SELECT DISTINCT gameweek
    FROM matches
    WHERE competition = 'Premier League'
    ORDER BY gameweek ASC
");

while ($w = $weeks->fetch_assoc()):

    $gw = (int) $w['gameweek'];

    $selected =
        ($gw === $gameweek)
            ? 'selected'
            : '';

?>

<option
    value="<?= $gw ?>"
    <?= $selected ?>
>
    Gameweek <?= $gw ?>
</option>

<?php endwhile; ?>

</select>

</form>

</div>

<div
    class="
        bg-[#1c003a]/85
        backdrop-blur-md
        border
        border-[#ff0080]/30
        shadow-[0_0_40px_rgba(233,0,82,0.25)]
        rounded-3xl
        p-6
        md:p-8
        mb-8
    "
>

<?php if ($deadlinePassed): ?>

<div
    class="
        max-w-2xl
        mx-auto
        text-center
        py-16
    "
>

<div
    class="
        w-24
        h-24
        mx-auto
        mb-6
        rounded-full
        bg-red-500/20
        border
        border-red-500/40
        flex
        items-center
        justify-center
        text-5xl
    "
>
    
</div>

<h2
    class="
        text-3xl
        md:text-4xl
        font-black
        text-red-400
        mb-4
    "
>
    Prediction Deadline Has Passed
</h2>

<p
    class="
        text-gray-300
        text-lg
        leading-relaxed
    "
>

The prediction deadline for

<strong class="text-white">
    Gameweek <?= e($gameweek) ?>
</strong>

has passed.

</p>

<?php if ($deadlineText): ?>

<p
    class="
        text-gray-400
        mt-4
    "
>

Deadline:

<strong class="text-white">
    <?= e($deadlineText) ?>
</strong>

</p>

<?php endif; ?>

<p
    class="
        text-gray-500
        mt-6
    "
>

You can no longer submit predictions for this gameweek.

</p>

<a
    href="my_predictions.php"
    class="
        inline-flex
        mt-8
        bg-[#ff0080]
        hover:bg-[#ff9900]
        text-white
        px-7
        py-3
        rounded-xl
        font-black
        transition
    "
>
    View My Predictions
</a>

</div>

<?php else: ?>

<div
    id="countdown"
    class="
        bg-[#ff9900]/20
        border
        border-[#ff9900]/30
        rounded-2xl
        p-4
        text-center
        text-[#ff9900]
        font-bold
        text-lg
        mb-8
    "
>

<?php if ($deadlineText): ?>

Deadline:

<?= e($deadlineText) ?>

<?php else: ?>

Predictions are currently open.

<?php endif; ?>

</div>

<?php if ($result->num_rows === 0): ?>

<p
    class="
        text-center
        text-gray-300
        text-lg
    "
>
    No matches for this gameweek.
</p>

<?php else: ?>

<form
    id="predictionsForm"
    action="insert_prediction.php"
    method="POST"
    class="space-y-7"
>

<input
    type="hidden"
    name="prediction_type"
    value="multiple"
>

<input
    type="hidden"
    name="gameweek"
    value="<?= (int) $gameweek ?>"
>

<?php while ($match = $result->fetch_assoc()): ?>

<?php

$home_logo =
    ltrim(
        $match['home_team_pic'] ?? '',
        '/'
    );

$away_logo =
    ltrim(
        $match['away_team_pic'] ?? '',
        '/'
    );

?>

<div
    class="
        flex
        flex-col
        md:grid
        md:grid-cols-[1fr_1.2fr_1fr]
        rounded-2xl
        overflow-hidden
        shadow-2xl
        border
        border-[#ff0080]/20
        max-w-[650px]
        mx-auto
        mb-5
    "
>

<div
    class="
        bg-gradient-to-br
        from-[#1c003a]
        to-[#4a0060]
        flex
        flex-row
        items-center
        justify-center
        gap-4
        md:flex-col
        md:gap-0
        p-4
        md:p-5
        text-white
    "
>

<img
    src="<?= e($home_logo) ?>"
    alt="<?= e($match['home_team']) ?>"
    class="
        w-[60px]
        h-[60px]
        md:w-[60px]
        md:h-[60px]
        md:mb-2.5
    "
    onerror="this.src='PL_img/default-team.png';"
>

<div class="text-center">

<div
    class="
        font-extrabold
        text-lg
    "
>
    <?= e($match['home_team']) ?>
</div>

<div
    class="
        text-[10px]
        font-black
        uppercase
        tracking-wider
        opacity-90
        mt-0.5
        md:mt-1.5
        text-[#ff9900]
    "
>
    HOME
</div>

</div>

</div>

<div
    class="
        bg-[#ffffff]
        flex
        flex-col
        items-center
        justify-center
        gap-2
        p-5
        border-y
        border-gray-200
        md:border-x
    "
>

<div
    class="
        text-xs
        font-bold
        text-black
    "
>

<?= date(
    'D, d M Y • H:i',
    strtotime($match['match_date'])
) ?>

</div>

<div
    class="
        flex
        items-center
        justify-center
        gap-2.5
    "
>

<input
    type="number"
    name="predicted_home[]"
    value="<?= e($match['predicted_home'] ?? '') ?>"
    class="
        bg-transparent
        border-b-4
        border-[#e90052]
        text-[#e90052]
        text-4xl
        font-black
        w-[70px]
        text-center
        outline-none
        focus:border-[#ff9900]
        focus:text-[#ff9900]
        read-only:border-gray-300
        read-only:text-gray-400
        appearance-none
    "
    min="0"
    max="10"
    <?= $match['predicted_home'] !== null ? 'readonly' : 'required' ?>
>

<span
    class="
        text-4xl
        font-black
        text-[#e90052]
    "
>
    -
</span>

<input
    type="number"
    name="predicted_away[]"
    value="<?= e($match['predicted_away'] ?? '') ?>"
    class="
        bg-transparent
        border-b-4
        border-[#e90052]
        text-[#e90052]
        text-4xl
        font-black
        w-[70px]
        text-center
        outline-none
        focus:border-[#ff9900]
        focus:text-[#ff9900]
        read-only:border-gray-300
        read-only:text-gray-400
        appearance-none
    "
    min="0"
    max="10"
    <?= $match['predicted_away'] !== null ? 'readonly' : 'required' ?>
>

</div>

</div>

<div
    class="
        bg-gradient-to-br
        from-[#e90052]
        to-[#ff4b2b]
        flex
        flex-row
        items-center
        justify-center
        gap-4
        md:flex-col
        md:gap-0
        p-4
        md:p-5
        text-white
    "
>

<img
    src="<?= e($away_logo) ?>"
    alt="<?= e($match['away_team']) ?>"
    class="
        w-[60px]
        h-[60px]
        md:w-[60px]
        md:h-[60px]
        md:mb-2.5
    "
    onerror="this.src='PL_img/default-team.png';"
>

<div class="text-center">

<div
    class="
        font-extrabold
        text-lg
    "
>
    <?= e($match['away_team']) ?>
</div>

<div
    class="
        text-[10px]
        font-black
        uppercase
        tracking-wider
        opacity-90
        mt-0.5
        md:mt-1.5
        text-[#1c003a]
    "
>
    AWAY
</div>

</div>

</div>

</div>

<input
    type="hidden"
    name="match_id[]"
    value="<?= (int) $match['id'] ?>"
>

<?php endwhile; ?>

<div class="text-center mt-8">

<button
    type="submit"
    class="
        bg-gradient-to-r
        from-[#e90052]
        to-[#ff9900]
        hover:from-[#ff9900]
        hover:to-[#e90052]
        text-white
        px-12
        py-4
        rounded-xl
        font-black
        text-lg
        shadow-lg
        shadow-[#e90052]/50
        transition
        transform
        hover:scale-105
    "
>
    Submit Predictions
</button>

</div>

</form>

<?php endif; ?>

<?php endif; ?>

<div
    class="
        text-center
        mt-8
        flex
        flex-col
        sm:flex-row
        justify-center
        gap-4
    "
>

<a
    href="other_matches.php?gameweek=<?= (int) $gameweek ?>"
    class="
        inline-flex
        items-center
        justify-center
        gap-2
        bg-[#ff9900]
        hover:bg-[#e90052]
        text-white
        font-black
        px-6
        py-3
        rounded-xl
        transition
    "
>
    Other Leagues
</a>

<a
    href="my_predictions.php"
    class="
        inline-flex
        items-center
        justify-center
        gap-2
        bg-white/10
        hover:bg-white/20
        border
        border-white/20
        text-white
        px-6
        py-3
        rounded-xl
        font-black
        transition
    "
>
    My Predictions
</a>

</div>

</div>

<div
    class="
        text-center
        text-gray-400
        text-sm
        mt-10
    "
>

Premier League Prediction

•

Gameweek <?= e($gameweek) ?>

<br>

<?php if ($deadlineText): ?>

Prediction deadline:

<?= e($deadlineText) ?>

<?php else: ?>

Make your picks before the deadline.

<?php endif; ?>

</div>

</main>

<?php if (!$deadlinePassed && $deadlineTimestamp): ?>

<script>

const deadline =
    <?= (int) $deadlineTimestamp ?> * 1000;

const countdownElement =
    document.getElementById('countdown');

const timer =
    setInterval(() =>
    {

        const now =
            new Date().getTime();

        const distance =
            deadline - now;

        if (distance <= 0)
        {

            clearInterval(timer);

            window.location.reload();

            return;
        }

        const d =
            Math.floor(
                distance /
                (1000 * 60 * 60 * 24)
            );

        const h =
            Math.floor(
                (
                    distance %
                    (1000 * 60 * 60 * 24)
                ) /
                (1000 * 60 * 60)
            );

        const m =
            Math.floor(
                (
                    distance %
                    (1000 * 60 * 60)
                ) /
                (1000 * 60)
            );

        const s =
            Math.floor(
                (
                    distance %
                    (1000 * 60)
                ) /
                1000
            );

        countdownElement.innerHTML =
            `${d}d ${h}h ${m}m ${s}s left until deadline`;

    }, 1000);

</script>

<?php endif; ?>

</body>

</html>