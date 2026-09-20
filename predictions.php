<?php

session_start();

include 'connect.php';
require_once 'gameweek_deadline.php';
require_once 'match_difficulty_helper.php';

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

function getPredictionTeamLast5($conn, $teamName, $fixtureDate)
{
    $matches = [];

    $sql = "
        SELECT
            home_team,
            away_team,
            home_score,
            away_score,
            match_date,
            competition
        FROM matches
        WHERE competition = 'Premier League'
          AND home_score IS NOT NULL
          AND away_score IS NOT NULL
          AND match_date < ?
          AND (home_team = ? OR away_team = ?)
        ORDER BY match_date DESC
        LIMIT 5
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return $matches;
    }

    $stmt->bind_param(
        "sss",
        $fixtureDate,
        $teamName,
        $teamName
    );

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $isHome = strcasecmp((string)$row['home_team'], (string)$teamName) === 0;

        $goalsFor = $isHome
            ? (int)$row['home_score']
            : (int)$row['away_score'];

        $goalsAgainst = $isHome
            ? (int)$row['away_score']
            : (int)$row['home_score'];

        if ($goalsFor > $goalsAgainst) {
            $letter = 'W';
        } elseif ($goalsFor < $goalsAgainst) {
            $letter = 'L';
        } else {
            $letter = 'D';
        }

        $matches[] = [
            'opponent' => $isHome ? $row['away_team'] : $row['home_team'],
            'goals_for' => $goalsFor,
            'goals_against' => $goalsAgainst,
            'date' => $row['match_date'],
            'competition' => $row['competition'],
            'is_home' => $isHome,
            'letter' => $letter,
        ];
    }

    $stmt->close();

    return $matches;
}

function predictionResultBadgeClass($letter)
{
    if ($letter === 'W') {
        return 'bg-[#00e07a]/20 text-[#00e07a] border-[#00e07a]/40';
    }

    if ($letter === 'L') {
        return 'bg-red-500/20 text-red-400 border-red-500/40';
    }

    return 'bg-gray-400/20 text-gray-300 border-gray-400/40';
}

function predictionFormatDate($rawDate)
{
    $ts = strtotime((string)$rawDate);

    return $ts ? date('D, d M Y • H:i', $ts) : 'Date unknown';
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

$matches = [];

while ($row = $result->fetch_assoc()) {
    $matches[] = $row;
}

$matchIds = array_map(static function ($match) {
    return (int)($match['id'] ?? 0);
}, $matches);

$difficultyBatch = difficultyGetBatchForMatches($conn, $matchIds);

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
        #0d0620;

    color:
        #e4f2ec;

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
        linear-gradient(
            135deg,
            rgba(13, 6, 32, 0.96),
            rgba(0, 60, 45, 0.92),
            rgba(0, 90, 50, 0.90)
        );

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
        bg-black/80
        backdrop-blur-xl
        border-b
        border-[#00e07a]/20
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
    class="text-gray-400 hover:text-[#00e07a] transition-colors"
>
    Dashboard
</a>

<a
    href="predictions.php"
    class="text-[#00e07a]"
>
    Predictions
</a>

<a
    href="leaderboard.php"
    class="text-gray-400 hover:text-[#00e07a] transition-colors"
>
    Leaderboard
</a>

<a
    href="my_predictions.php"
    class="text-gray-400 hover:text-[#00e07a] transition-colors"
>
    My Predictions
</a>
<a href="team_stats.php"
    class="text-gray-400 hover:text-[#00e07a] transition-colors"
>
    Team Stats
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
        bg-black/95
        backdrop-blur-xl
        border-b
        border-[#00e07a]/20
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
    class="text-[#00e07a]"
>
    Predictions
</a>

<a href="leaderboard.php">
    Leaderboard
</a>

<a href="my_predictions.php">
    My Predictions
</a>

<a href="team_stats.php">
    Team Stats
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
        text-[#00e07a]
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
        text-gray-500
        mt-1
    "
>

Premier League

<span
    class="text-gray-600"
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
        text-gray-400
    "
>
    Gameweek
</label>

<select
    id="gameweek"
    name="gameweek"
    onchange="this.form.submit()"
    class="
        bg-[#0d0620]
        border
        border-[#00e07a]
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
        bg-[#0d0620]/80
        backdrop-blur-md
        border
        border-[#00e07a]/20
        shadow-[0_0_40px_rgba(0,224,122,0.15)]
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
        text-gray-400
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
        text-gray-500
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
        bg-[#00e07a]
        hover:bg-[#00b862]
        text-[#0d0620]
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
        bg-[#00e07a]/10
        border
        border-[#00e07a]/30
        rounded-2xl
        p-4
        text-center
        text-[#00e07a]
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

<?php if (count($matches) === 0): ?>

<p
    class="
        text-center
        text-gray-400
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

<?php foreach ($matches as $match): ?>

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

$home_last5 = getPredictionTeamLast5(
    $conn,
    $match['home_team'],
    $match['match_date']
);

$away_last5 = getPredictionTeamLast5(
    $conn,
    $match['away_team'],
    $match['match_date']
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
        border-[#00e07a]/20
        max-w-[650px]
        mx-auto
        mb-5
    "
>

<div
    class="
        bg-gradient-to-br
        from-[#0d0620]
        to-[#1a0836]
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
        text-[#00e07a]
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
        border-[#008a66]
        text-[#008a66]
        text-4xl
        font-black
        w-[70px]
        text-center
        outline-none
        focus:border-[#00e07a]
        focus:text-[#00e07a]
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
        text-[#008a66]
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
        border-[#008a66]
        text-[#008a66]
        text-4xl
        font-black
        w-[70px]
        text-center
        outline-none
        focus:border-[#00e07a]
        focus:text-[#00e07a]
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
        from-[#005c44]
        to-[#008a66]
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
        text-[#0d0620]
    "
>
    AWAY
</div>

</div>

</div>

</div>

<?php
$widget_match_id = (int)$match['id'];
$widget_difficulty = $difficultyBatch[$widget_match_id] ?? difficultyGetForMatch($conn, $widget_match_id);
require __DIR__ . '/difficulty_widget.php';
?>

<div class="max-w-[650px] mx-auto mt-3 text-center">
    <button
        type="button"
        onclick="toggleLast5('last5_<?= (int)$match['id'] ?>', this)"
        class="
            inline-flex
            items-center
            gap-2
            text-[#00e07a]
            hover:text-[#00b862]
            border
            border-[#00e07a]/40
            hover:border-[#00e07a]/60
            rounded-full
            px-5
            py-1.5
            text-sm
            font-black
            uppercase
            tracking-wider
            transition
        "
    >
        <span class="icon">▼</span> Last 5
    </button>
</div>

<div id="last5_<?= (int)$match['id'] ?>" class="hidden max-w-[650px] mx-auto mt-3">
    <div class="bg-white/5 backdrop-blur-sm rounded-2xl p-5 border border-[#00e07a]/20">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <div class="text-center text-white font-bold text-sm mb-3">
                    <?= e($match['home_team']) ?>
                </div>
                <?php if (empty($home_last5)): ?>
                    <div class="text-center text-gray-400 text-sm">No completed matches</div>
                <?php else: ?>
                    <div class="flex items-center justify-center gap-2 flex-wrap">
                        <?php foreach ($home_last5 as $last):
                            $letter = $last['letter'];
                            $opponent_abbr = substr($last['opponent'], 0, 3);
                            $is_home = $last['is_home'];
                            $score = (int)$last['goals_for'] . '–' . (int)$last['goals_against'];
                            $full_title = ($is_home ? '🏠 Home vs ' : '✈️ Away vs ')
                                          . $last['opponent']
                                          . ' • ' . $score
                                          . ' • ' . predictionFormatDate($last['date']);
                        ?>
                            <div class="flex flex-col items-center" title="<?= e($full_title) ?>">
                                <span class="form-pill <?= predictionResultBadgeClass($letter) ?> !w-9 !h-9 !text-sm font-black flex items-center justify-center rounded-full shadow-md">
                                    <?= e($letter) ?>
                                </span>
                                <span class="text-[10px] font-bold text-white/90 mt-1">
                                    <?= $score ?>
                                </span>
                                <span class="text-[8px] font-semibold text-white/60 mt-0.5 flex items-center gap-1">
                                    <span class="inline-block w-1.5 h-1.5 rounded-full <?= $is_home ? 'bg-[#00e07a]' : 'bg-yellow-400' ?>"></span>
                                    <?= e($opponent_abbr) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div>
                <div class="text-center text-white font-bold text-sm mb-3">
                    <?= e($match['away_team']) ?>
                </div>
                <?php if (empty($away_last5)): ?>
                    <div class="text-center text-gray-400 text-sm">No completed matches</div>
                <?php else: ?>
                    <div class="flex items-center justify-center gap-2 flex-wrap">
                        <?php foreach ($away_last5 as $last):
                            $letter = $last['letter'];
                            $opponent_abbr = substr($last['opponent'], 0, 3);
                            $is_home = $last['is_home'];
                            $score = (int)$last['goals_for'] . '–' . (int)$last['goals_against'];
                            $full_title = ($is_home ? '🏠 Home vs ' : '✈️ Away vs ')
                                          . $last['opponent']
                                          . ' • ' . $score
                                          . ' • ' . predictionFormatDate($last['date']);
                        ?>
                            <div class="flex flex-col items-center" title="<?= e($full_title) ?>">
                                <span class="form-pill <?= predictionResultBadgeClass($letter) ?> !w-9 !h-9 !text-sm font-black flex items-center justify-center rounded-full shadow-md">
                                    <?= e($letter) ?>
                                </span>
                                <span class="text-[10px] font-bold text-white/90 mt-1">
                                    <?= $score ?>
                                </span>
                                <span class="text-[8px] font-semibold text-white/60 mt-0.5 flex items-center gap-1">
                                    <span class="inline-block w-1.5 h-1.5 rounded-full <?= $is_home ? 'bg-[#00e07a]' : 'bg-yellow-400' ?>"></span>
                                    <?= e($opponent_abbr) ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<input
    type="hidden"
    name="match_id[]"
    value="<?= (int) $match['id'] ?>"
>

<?php endforeach; ?>

<div class="text-center mt-8">

<button
    type="submit"
    class="
        bg-gradient-to-r
        from-[#005c44]
        to-[#00e07a]
        hover:from-[#00e07a]
        hover:to-[#005c44]
        text-[#0d0620]
        px-12
        py-4
        rounded-xl
        font-black
        text-lg
        shadow-lg
        shadow-[#00e07a]/30
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
        bg-[#008a66]
        hover:bg-[#00e07a]
        text-white
        hover:text-[#0d0620]
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
        bg-white/5
        hover:bg-white/10
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
        text-gray-500
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

<script>
function toggleLast5(id, btn) {
    var container = document.getElementById(id);
    if (!container) return;

    var isHidden = container.classList.contains('hidden');
    if (isHidden) {
        container.classList.remove('hidden');
        btn.innerHTML = '<span class="icon">▲</span> Hide Last 5';
    } else {
        container.classList.add('hidden');
        btn.innerHTML = '<span class="icon">▼</span> Last 5';
    }
}
</script>

</body>

</html>