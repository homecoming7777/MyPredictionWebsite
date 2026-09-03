<?php

session_start();
require_once 'connect.php';
require_once 'gameweek_deadline.php';

date_default_timezone_set('Africa/Casablanca');

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

/*
|--------------------------------------------------------------------------
| ADMIN CHECK
|--------------------------------------------------------------------------
*/
$isAdmin = false;

if (isset($_SESSION['role'])) {
    $isAdmin = in_array(
        strtolower((string)$_SESSION['role']),
        ['admin', 'super-admin', 'super_admin'],
        true
    );
}

if (
    isset($_SESSION['role']) &&
    !$isAdmin
) {
    http_response_code(403);
    exit('Access denied.');
}

$message = '';
$messageType = '';

/*
|--------------------------------------------------------------------------
| SAVE / UPDATE GAMEWEEK DEADLINE
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['save_deadline'])
) {
    $gameweek = (int)($_POST['gameweek'] ?? 0);
    $deadlineInput = trim((string)($_POST['deadline'] ?? ''));

    if ($gameweek < 1) {
        $message = 'Invalid gameweek.';
        $messageType = 'error';

    } elseif ($deadlineInput === '') {
        $message = 'Please select a deadline.';
        $messageType = 'error';

    } else {

        $timestamp = strtotime($deadlineInput);

        if ($timestamp === false) {
            $message = 'Invalid deadline date/time.';
            $messageType = 'error';

        } else {

            $deadline = date(
                'Y-m-d H:i:s',
                $timestamp
            );

            $stmt = $conn->prepare("
                INSERT INTO gameweek_deadlines
                (
                    gameweek,
                    deadline
                )
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE
                    deadline = VALUES(deadline)
            ");

            if (!$stmt) {
                $message =
                    'Database error: ' .
                    $conn->error;
                $messageType = 'error';

            } else {

                $stmt->bind_param(
                    'is',
                    $gameweek,
                    $deadline
                );

                if ($stmt->execute()) {
                    $message =
                        'Deadline saved successfully for Gameweek ' .
                        $gameweek .
                        '.';

                    $messageType = 'success';
                } else {
                    $message =
                        'Could not save deadline: ' .
                        $stmt->error;

                    $messageType = 'error';
                }

                $stmt->close();
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| DELETE GAMEWEEK DEADLINE
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['delete_deadline'])
) {
    $gameweek = (int)($_POST['gameweek'] ?? 0);

    if ($gameweek > 0) {

        $stmt = $conn->prepare("
            DELETE FROM gameweek_deadlines
            WHERE gameweek = ?
        ");

        if ($stmt) {
            $stmt->bind_param(
                'i',
                $gameweek
            );

            if ($stmt->execute()) {
                $message =
                    'Custom deadline removed for Gameweek ' .
                    $gameweek .
                    '. The compatibility fallback will now be used.';

                $messageType = 'success';
            } else {
                $message =
                    'Could not remove deadline: ' .
                    $stmt->error;

                $messageType = 'error';
            }

            $stmt->close();
        }
    }
}

/*
|--------------------------------------------------------------------------
| LOAD GAMEWEEKS
|--------------------------------------------------------------------------
*/
$gameweeks = [];

$result = $conn->query("
    SELECT DISTINCT gameweek
    FROM matches
    WHERE gameweek IS NOT NULL
      AND gameweek > 0
    ORDER BY gameweek ASC
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $gameweeks[] = (int)$row['gameweek'];
    }
}

function e($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gameweek Deadlines | Admin</title>
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

<body class="min-h-screen text-white">

<header class="border-b border-[#ff0080]/30 bg-[#1c003a]/80 backdrop-blur-xl sticky top-0 z-50">
    <div class="max-w-6xl mx-auto px-6 py-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-black text-white">
                Gameweek Deadlines
            </h1>
            <p class="text-gray-300 mt-1">
                Set one prediction deadline for every match in a gameweek.
            </p>
        </div>

        <div class="flex flex-col sm:flex-row gap-3">
            <a
                href="myAdmin.php"
                class="bg-[#ff0080] hover:bg-[#ff1a66] text-black px-5 py-3 rounded-xl font-black transition transform hover:-translate-y-0.5"
            >
                Back to Admin
            </a>
            <a
                href="manage_other_matches.php"
                class="bg-[#ff9900] hover:bg-[#ffb84d] text-black px-5 py-3 rounded-xl font-black transition"
            >
                Manage Other Matches
            </a>
        </div>
    </div>
</header>

<main class="max-w-6xl mx-auto px-6 py-8">

    <?php if ($message !== ''): ?>
        <div class="mb-6 rounded-xl border px-5 py-4 backdrop-blur-md
            <?= $messageType === 'success'
                ? 'bg-green-500/10 border-green-500/30 text-green-300'
                : 'bg-red-500/10 border-red-500/30 text-red-300'
            ?>">
            <div class="font-bold">
                <?= e($message) ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="bg-blue-500/10 border border-blue-500/30 rounded-2xl p-5 mb-8">
        <h2 class="font-black text-blue-300 text-lg">
            How this works
        </h2>

        <p class="text-gray-300 mt-2 leading-7">
            The deadline below is global for the selected gameweek.
            When it passes, users cannot submit predictions for
            <strong>any match</strong> in that gameweek, including
            Premier League and Other Leagues matches.
        </p>
    </div>

    <?php if (empty($gameweeks)): ?>

        <div class="bg-[#1c003a]/80 border border-[#ff0080]/30 rounded-2xl p-10 text-center backdrop-blur-xl">
            <div class="text-5xl mb-4">!</div>
            <h2 class="text-xl font-black text-white">No gameweeks found</h2>
            <p class="text-gray-300 mt-2">
                Add matches with a gameweek first.
            </p>
        </div>

    <?php else: ?>

        <div class="space-y-5">

            <?php foreach ($gameweeks as $gameweek): ?>

                <?php
                $deadline = getGameweekDeadline(
                    $conn,
                    $gameweek
                );

                $deadlineTimestamp =
                    $deadline
                        ? $deadline->getTimestamp()
                        : null;

                $isPassed =
                    $deadlineTimestamp !== null &&
                    time() >= $deadlineTimestamp;
                ?>

                <div class="bg-[#1c003a]/80 border border-[#ff0080]/30 rounded-2xl p-6 backdrop-blur-xl">

                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">

                        <div>
                            <div class="text-xs uppercase tracking-widest text-gray-400">
                                Gameweek
                            </div>

                            <div class="text-3xl font-black text-[#ff0080]">
                                <?= $gameweek ?>
                            </div>

                            <?php if ($deadline): ?>

                                <div class="mt-2 text-sm
                                    <?= $isPassed
                                        ? 'text-red-400'
                                        : 'text-green-400'
                                    ?> font-bold"
                                >
                                    <?= $isPassed
                                        ? 'Deadline Passed'
                                        : 'Predictions Open'
                                    ?>
                                </div>

                                <div class="text-sm text-gray-300 mt-1">
                                    Current deadline:
                                    <strong class="text-white">
                                        <?= e(
                                            $deadline->format(
                                                'D, d M Y • H:i'
                                            )
                                        ) ?>
                                    </strong>
                                </div>

                            <?php else: ?>

                                <div class="mt-2 text-sm text-yellow-300 font-bold">
                                    No custom deadline configured
                                </div>

                            <?php endif; ?>
                        </div>

                        <form method="POST" class="w-full lg:max-w-xl">

                            <input
                                type="hidden"
                                name="gameweek"
                                value="<?= $gameweek ?>"
                            >

                            <label class="block text-sm font-bold text-gray-200 mb-2">
                                Set one deadline for Gameweek <?= $gameweek ?>
                            </label>

                            <div class="flex flex-col sm:flex-row gap-3">

                                <input
                                    type="datetime-local"
                                    name="deadline"
                                    required
                                    value="<?= $deadline
                                        ? e(
                                            $deadline->format(
                                                'Y-m-d\TH:i'
                                            )
                                        )
                                        : ''
                                    ?>"
                                    class="flex-1 bg-black/40 border-2 border-[#ff0080]/50 rounded-xl px-4 py-3 outline-none focus:border-[#ff9900] transition"
                                >

                                <button
                                    type="submit"
                                    name="save_deadline"
                                    class="bg-green-600 hover:bg-green-500 px-6 py-3 rounded-xl font-black transition text-black"
                                >
                                    Save Deadline
                                </button>

                            </div>

                        </form>

                        <?php if ($deadline): ?>

                            <form
                                method="POST"
                                onsubmit="return confirm('Remove the custom deadline for this gameweek?');"
                            >
                                <input
                                    type="hidden"
                                    name="gameweek"
                                    value="<?= $gameweek ?>"
                                >

                                <button
                                    type="submit"
                                    name="delete_deadline"
                                    class="bg-red-900/40 hover:bg-red-800/60 border border-red-700 text-red-300 px-5 py-3 rounded-xl font-bold transition"
                                >
                                    Remove
                                </button>
                            </form>

                        <?php endif; ?>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</main>

</body>
</html>