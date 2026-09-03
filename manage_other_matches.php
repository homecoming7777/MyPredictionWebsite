<?php

session_start();
require_once 'connect.php';

/*
|--------------------------------------------------------------------------
| DEFAULT VARIABLES
|--------------------------------------------------------------------------
*/

$message = '';
$messageType = '';

$editMatch = null;

$competitions = [];
$matches = [];

$selectedCompetition = '';

/*
|--------------------------------------------------------------------------
| AUTH CHECK
|--------------------------------------------------------------------------
*/

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

/*
|--------------------------------------------------------------------------
| ESCAPE HTML
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

/*
|--------------------------------------------------------------------------
| NORMALIZE DATETIME
|--------------------------------------------------------------------------
*/

function normalizeDateTime($value): ?string
{
    $value = trim((string)$value);

    if ($value === '') {
        return null;
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return null;
    }

    return date(
        'Y-m-d H:i:s',
        $timestamp
    );
}

/*
|--------------------------------------------------------------------------
| CHECK IF LOCAL LOGO
|--------------------------------------------------------------------------
*/

function isLocalLogo($path): bool
{
    return strpos(
        (string)$path,
        'uploads/team_logos/'
    ) === 0;
}

/*
|--------------------------------------------------------------------------
| DELETE LOCAL LOGO
|--------------------------------------------------------------------------
*/

function deleteLocalLogo($logoPath): void
{
    if (empty($logoPath)) {
        return;
    }

    if (!isLocalLogo($logoPath)) {
        return;
    }

    $fullPath = __DIR__ . DIRECTORY_SEPARATOR .
        str_replace(
            '/',
            DIRECTORY_SEPARATOR,
            $logoPath
        );

    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

/*
|--------------------------------------------------------------------------
| HANDLE TEAM LOGO
|--------------------------------------------------------------------------
*/

function handleTeamLogo(
    string $fileInput,
    string $urlInput,
    string $currentLogo = ''
): array {

    $logoUrl = trim(
        $_POST[$urlInput] ?? ''
    );

    if ($logoUrl !== '') {

        if (
            filter_var(
                $logoUrl,
                FILTER_VALIDATE_URL
            )
        ) {
            return [
                'success' => true,
                'path' => $logoUrl,
                'error' => ''
            ];
        }

        return [
            'success' => false,
            'path' => $currentLogo,
            'error' => 'Invalid logo URL.'
        ];
    }

    if (
        !isset($_FILES[$fileInput]) ||
        $_FILES[$fileInput]['error'] === UPLOAD_ERR_NO_FILE
    ) {
        return [
            'success' => true,
            'path' => $currentLogo,
            'error' => ''
        ];
    }

    $file = $_FILES[$fileInput];

    if ($file['error'] !== UPLOAD_ERR_OK) {

        return [
            'success' => false,
            'path' => $currentLogo,
            'error' => 'Logo upload failed.'
        ];
    }

    $maxSize = 5 * 1024 * 1024;

    if ($file['size'] > $maxSize) {

        return [
            'success' => false,
            'path' => $currentLogo,
            'error' => 'Logo must be smaller than 5MB.'
        ];
    }

    $allowedTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    ];

    $finfo = finfo_open(
        FILEINFO_MIME_TYPE
    );

    if ($finfo === false) {

        return [
            'success' => false,
            'path' => $currentLogo,
            'error' => 'Could not validate uploaded image.'
        ];
    }

    $mimeType = finfo_file(
        $finfo,
        $file['tmp_name']
    );

    finfo_close($finfo);

    if (!isset($allowedTypes[$mimeType])) {

        return [
            'success' => false,
            'path' => $currentLogo,
            'error' => 'Only JPG, PNG, WEBP and GIF files are allowed.'
        ];
    }

    $uploadDirectory =
        __DIR__ .
        DIRECTORY_SEPARATOR .
        'uploads' .
        DIRECTORY_SEPARATOR .
        'team_logos' .
        DIRECTORY_SEPARATOR;

    if (!is_dir($uploadDirectory)) {

        if (
            !mkdir(
                $uploadDirectory,
                0755,
                true
            )
        ) {

            return [
                'success' => false,
                'path' => $currentLogo,
                'error' => 'Could not create upload directory.'
            ];
        }
    }

    try {

        $randomName = bin2hex(
            random_bytes(16)
        );

    } catch (Throwable $e) {

        $randomName = uniqid(
            'team_',
            true
        );
    }

    $extension =
        $allowedTypes[$mimeType];

    $fileName =
        'team_' .
        $randomName .
        '.' .
        $extension;

    $destination =
        $uploadDirectory .
        $fileName;

    if (
        !move_uploaded_file(
            $file['tmp_name'],
            $destination
        )
    ) {

        return [
            'success' => false,
            'path' => $currentLogo,
            'error' => 'Could not save uploaded logo.'
        ];
    }

    return [
        'success' => true,
        'path' =>
            'uploads/team_logos/' .
            $fileName,
        'error' => ''
    ];
}

/*
|--------------------------------------------------------------------------
| DELETE MATCH
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['delete_match'])
) {

    $matchId =
        (int)($_POST['match_id'] ?? 0);

    if ($matchId <= 0) {

        $message = 'Invalid match ID.';
        $messageType = 'error';

    } else {

        $getMatchStmt = $conn->prepare("
            SELECT
                id,
                home_team_pic,
                away_team_pic
            FROM matches
            WHERE id = ?
            AND LOWER(competition) <> 'premier league'
            LIMIT 1
        ");

        if (!$getMatchStmt) {

            $message =
                'Database error: ' .
                $conn->error;

            $messageType = 'error';

        } else {

            $getMatchStmt->bind_param(
                'i',
                $matchId
            );

            $getMatchStmt->execute();

            $result =
                $getMatchStmt
                    ->get_result();

            $matchData =
                $result->fetch_assoc();

            $getMatchStmt->close();

            if (!$matchData) {

                $message =
                    'Match not found or Premier League matches cannot be deleted here.';

                $messageType =
                    'error';

            } else {

                try {

                    $conn->begin_transaction();

                    $predictionStmt =
                        $conn->prepare("
                            DELETE FROM score_exact
                            WHERE match_id = ?
                        ");

                    if ($predictionStmt) {

                        $predictionStmt->bind_param(
                            'i',
                            $matchId
                        );

                        $predictionStmt->execute();

                        $predictionStmt->close();
                    }

                    $doubleStmt =
                        $conn->prepare("
                            DELETE FROM double_gameweek
                            WHERE match_id = ?
                        ");

                    if ($doubleStmt) {

                        $doubleStmt->bind_param(
                            'i',
                            $matchId
                        );

                        $doubleStmt->execute();

                        $doubleStmt->close();
                    }

                    $deleteStmt =
                        $conn->prepare("
                            DELETE FROM matches
                            WHERE id = ?
                            AND LOWER(competition) <> 'premier league'
                        ");

                    if (!$deleteStmt) {

                        throw new Exception(
                            $conn->error
                        );
                    }

                    $deleteStmt->bind_param(
                        'i',
                        $matchId
                    );

                    $deleteStmt->execute();

                    if (
                        $deleteStmt->affected_rows < 1
                    ) {

                        $deleteStmt->close();

                        throw new Exception(
                            'Match could not be deleted.'
                        );
                    }

                    $deleteStmt->close();

                    $conn->commit();

                    deleteLocalLogo(
                        $matchData['home_team_pic']
                    );

                    deleteLocalLogo(
                        $matchData['away_team_pic']
                    );

                    $message =
                        'Match deleted successfully.';

                    $messageType =
                        'success';

                } catch (Throwable $e) {

                    $conn->rollback();

                    $message =
                        'Failed to delete match: ' .
                        $e->getMessage();

                    $messageType =
                        'error';
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| ADD / UPDATE MATCH
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['save_match'])
) {

    $matchId =
        (int)($_POST['match_id'] ?? 0);

    $competition =
        trim($_POST['competition'] ?? '');

    $homeTeam =
        trim($_POST['home_team'] ?? '');

    $awayTeam =
        trim($_POST['away_team'] ?? '');

    $gameweek =
        (int)($_POST['gameweek'] ?? 0);

    $matchDate =
        normalizeDateTime(
            $_POST['match_date'] ?? ''
        );

    $deadline =
        normalizeDateTime(
            $_POST['deadline'] ?? ''
        );

    if ($competition === '') {

        $message =
            'Competition is required.';

        $messageType =
            'error';

    } elseif (
        strtolower($competition) ===
        'premier league'
    ) {

        $message =
            'Premier League matches cannot be managed from this page.';

        $messageType =
            'error';

    } elseif ($homeTeam === '') {

        $message =
            'Home team is required.';

        $messageType =
            'error';

    } elseif ($awayTeam === '') {

        $message =
            'Away team is required.';

        $messageType =
            'error';

    } elseif (
        strtolower($homeTeam) ===
        strtolower($awayTeam)
    ) {

        $message =
            'Home and away teams cannot be the same.';

        $messageType =
            'error';

    } elseif ($gameweek < 1) {

        $message =
            'Gameweek must be at least 1.';

        $messageType =
            'error';

    } elseif ($matchDate === null) {

        $message =
            'Invalid match date.';

        $messageType =
            'error';
    }

    if ($messageType !== 'error') {

        $currentHomeLogo = '';
        $currentAwayLogo = '';

        if ($matchId > 0) {

            $currentStmt =
                $conn->prepare("
                    SELECT
                        home_team_pic,
                        away_team_pic
                    FROM matches
                    WHERE id = ?
                    AND LOWER(competition) <> 'premier league'
                    LIMIT 1
                ");

            if (!$currentStmt) {

                $message =
                    'Database error: ' .
                    $conn->error;

                $messageType =
                    'error';

            } else {

                $currentStmt->bind_param(
                    'i',
                    $matchId
                );

                $currentStmt->execute();

                $currentResult =
                    $currentStmt
                        ->get_result();

                $currentMatch =
                    $currentResult
                        ->fetch_assoc();

                $currentStmt->close();

                if (!$currentMatch) {

                    $message =
                        'Match not found.';

                    $messageType =
                        'error';

                } else {

                    $currentHomeLogo =
                        $currentMatch['home_team_pic']
                        ?? '';

                    $currentAwayLogo =
                        $currentMatch['away_team_pic']
                        ?? '';
                }
            }
        }

        if ($messageType !== 'error') {

            $homeLogoResult =
                handleTeamLogo(
                    'home_logo_file',
                    'home_logo_url',
                    $currentHomeLogo
                );

            if (!$homeLogoResult['success']) {

                $message =
                    $homeLogoResult['error'];

                $messageType =
                    'error';
            }

            $awayLogoResult =
                handleTeamLogo(
                    'away_logo_file',
                    'away_logo_url',
                    $currentAwayLogo
                );

            if (
                $messageType !== 'error' &&
                !$awayLogoResult['success']
            ) {

                if (
                    $homeLogoResult['path'] !==
                    $currentHomeLogo
                ) {
                    deleteLocalLogo(
                        $homeLogoResult['path']
                    );
                }

                $message =
                    $awayLogoResult['error'];

                $messageType =
                    'error';
            }
        }

        if ($messageType !== 'error') {

            $homeTeamPic =
                $homeLogoResult['path'];

            $awayTeamPic =
                $awayLogoResult['path'];

            if ($matchId > 0) {

                $duplicateStmt =
                    $conn->prepare("
                        SELECT id
                        FROM matches
                        WHERE competition = ?
                        AND home_team = ?
                        AND away_team = ?
                        AND match_date = ?
                        AND id != ?
                        LIMIT 1
                    ");

                $duplicateStmt->bind_param(
                    'ssssi',
                    $competition,
                    $homeTeam,
                    $awayTeam,
                    $matchDate,
                    $matchId
                );

            } else {

                $duplicateStmt =
                    $conn->prepare("
                        SELECT id
                        FROM matches
                        WHERE competition = ?
                        AND home_team = ?
                        AND away_team = ?
                        AND match_date = ?
                        LIMIT 1
                    ");

                $duplicateStmt->bind_param(
                    'ssss',
                    $competition,
                    $homeTeam,
                    $awayTeam,
                    $matchDate
                );
            }

            $duplicateStmt->execute();

            $duplicateResult =
                $duplicateStmt
                    ->get_result();

            $duplicateExists =
                $duplicateResult->num_rows > 0;

            $duplicateStmt->close();

            if ($duplicateExists) {

                if (
                    $homeTeamPic !==
                    $currentHomeLogo
                ) {

                    deleteLocalLogo(
                        $homeTeamPic
                    );
                }

                if (
                    $awayTeamPic !==
                    $currentAwayLogo
                ) {

                    deleteLocalLogo(
                        $awayTeamPic
                    );
                }

                $message =
                    'This match already exists.';

                $messageType =
                    'error';

            } else {

                if ($matchId > 0) {

                    $updateStmt =
                        $conn->prepare("
                            UPDATE matches
                            SET
                                competition = ?,
                                home_team = ?,
                                home_team_pic = ?,
                                away_team = ?,
                                away_team_pic = ?,
                                gameweek = ?,
                                match_date = ?,
                                deadline = ?
                            WHERE id = ?
                            AND LOWER(competition) <> 'premier league'
                        ");

                    if (!$updateStmt) {

                        $message =
                            'Database error: ' .
                            $conn->error;

                        $messageType =
                            'error';

                    } else {

                        $updateStmt->bind_param(
                            'sssssissi',
                            $competition,
                            $homeTeam,
                            $homeTeamPic,
                            $awayTeam,
                            $awayTeamPic,
                            $gameweek,
                            $matchDate,
                            $deadline,
                            $matchId
                        );

                        if (
                            $updateStmt->execute()
                        ) {

                            $updateStmt->close();

                            if (
                                $currentHomeLogo !== '' &&
                                $currentHomeLogo !== $homeTeamPic
                            ) {

                                deleteLocalLogo(
                                    $currentHomeLogo
                                );
                            }

                            if (
                                $currentAwayLogo !== '' &&
                                $currentAwayLogo !== $awayTeamPic
                            ) {

                                deleteLocalLogo(
                                    $currentAwayLogo
                                );
                            }

                            $message =
                                'Match updated successfully.';

                            $messageType =
                                'success';

                        } else {

                            $updateStmt->close();

                            if (
                                $homeTeamPic !==
                                $currentHomeLogo
                            ) {

                                deleteLocalLogo(
                                    $homeTeamPic
                                );
                            }

                            if (
                                $awayTeamPic !==
                                $currentAwayLogo
                            ) {

                                deleteLocalLogo(
                                    $awayTeamPic
                                );
                            }

                            $message =
                                'Failed to update match.';

                            $messageType =
                                'error';
                        }
                    }

                } else {

                    $insertStmt =
                        $conn->prepare("
                            INSERT INTO matches
                            (
                                home_team,
                                home_team_pic,
                                away_team,
                                away_team_pic,
                                match_date,
                                home_score,
                                away_score,
                                gameweek,
                                deadline,
                                competition
                            )
                            VALUES
                            (
                                ?,
                                ?,
                                ?,
                                ?,
                                ?,
                                NULL,
                                NULL,
                                ?,
                                ?,
                                ?
                            )
                        ");

                    if (!$insertStmt) {

                        deleteLocalLogo(
                            $homeTeamPic
                        );

                        deleteLocalLogo(
                            $awayTeamPic
                        );

                        $message =
                            'Database error: ' .
                            $conn->error;

                        $messageType =
                            'error';

                    } else {

                        $insertStmt->bind_param(
                            'sssssiss',
                            $homeTeam,
                            $homeTeamPic,
                            $awayTeam,
                            $awayTeamPic,
                            $matchDate,
                            $gameweek,
                            $deadline,
                            $competition
                        );

                        if (
                            $insertStmt->execute()
                        ) {

                            $message =
                                'Match added successfully.';

                            $messageType =
                                'success';

                        } else {

                            deleteLocalLogo(
                                $homeTeamPic
                            );

                            deleteLocalLogo(
                                $awayTeamPic
                            );

                            $message =
                                'Failed to add match.';

                            $messageType =
                                'error';
                        }

                        $insertStmt->close();
                    }
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| LOAD MATCH FOR EDITING
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['edit']) &&
    (int)$_GET['edit'] > 0
) {

    $editId =
        (int)$_GET['edit'];

    $editStmt =
        $conn->prepare("
            SELECT *
            FROM matches
            WHERE id = ?
            AND LOWER(competition) <> 'premier league'
            LIMIT 1
        ");

    if ($editStmt) {

        $editStmt->bind_param(
            'i',
            $editId
        );

        $editStmt->execute();

        $editResult =
            $editStmt
                ->get_result();

        $editMatch =
            $editResult->fetch_assoc()
            ?: null;

        $editStmt->close();
    }
}

/*
|--------------------------------------------------------------------------
| FILTER
|--------------------------------------------------------------------------
*/

$selectedCompetition =
    trim(
        $_GET['competition'] ?? ''
    );

/*
|--------------------------------------------------------------------------
| GET COMPETITIONS
|--------------------------------------------------------------------------
*/

$competitions = [];

$competitionQuery = "
    SELECT DISTINCT competition
    FROM matches
    WHERE competition IS NOT NULL
    AND competition <> ''
    AND LOWER(competition) <> 'premier league'
    ORDER BY competition ASC
";

$competitionResult =
    $conn->query(
        $competitionQuery
    );

if ($competitionResult) {

    while (
        $competitionRow =
            $competitionResult
                ->fetch_assoc()
    ) {

        $competitions[] =
            $competitionRow['competition'];
    }
}

/*
|--------------------------------------------------------------------------
| GET MATCHES
|--------------------------------------------------------------------------
*/

$matches = [];

$matchesSql = "
    SELECT *
    FROM matches
    WHERE LOWER(competition) <> 'premier league'
";

if ($selectedCompetition !== '') {

    $matchesSql .= "
        AND competition = ?
    ";
}

$matchesSql .= "
    ORDER BY
        competition ASC,
        gameweek ASC,
        match_date ASC
";

$matchesStmt =
    $conn->prepare(
        $matchesSql
    );

if ($matchesStmt) {

    if ($selectedCompetition !== '') {

        $matchesStmt->bind_param(
            's',
            $selectedCompetition
        );
    }

    $matchesStmt->execute();

    $matchesResult =
        $matchesStmt
            ->get_result();

    while (
        $matchRow =
            $matchesResult
                ->fetch_assoc()
    ) {

        $matches[] =
            $matchRow;
    }

    $matchesStmt->close();
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
        Manage Other Matches
    </title>

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

    <div class="mx-auto flex max-w-7xl flex-col gap-4 px-6 py-6 md:flex-row md:items-center md:justify-between">

        <div>

            <h1 class="text-3xl font-black text-white">
                Manage Other Leagues
            </h1>

            <p class="mt-1 text-sm text-gray-300">
                Add, edit and manage matches from all competitions.
            </p>

        </div>

        <div class="flex flex-col sm:flex-row gap-3">

            <a
                href="myAdmin.php"
                class="rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 px-5 py-3 font-bold transition"
            >
                Dashboard
            </a>

            <a
                href="manage_deadlines.php"
                class="rounded-xl bg-[#ff9900] hover:bg-[#ffb84d] text-black px-5 py-3 font-black transition transform hover:-translate-y-0.5"
            >
                Manage Deadlines
            </a>

            <a
                href="other_matches.php"
                class="rounded-xl bg-[#ff0080] hover:bg-[#ff1a66] text-black px-5 py-3 font-black transition"
            >
                View Matches
            </a>

        </div>

    </div>

</header>

<main class="mx-auto max-w-7xl px-6 py-8">

    <?php if ($message !== ''): ?>

        <div
            class="
                mb-6
                rounded-xl
                border
                p-4
                backdrop-blur-md
                <?= $messageType === 'success'
                    ? 'border-green-500/30 bg-green-500/10 text-green-300'
                    : 'border-red-500/30 bg-red-500/10 text-red-300'
                ?>
            "
        >

            <?= e($message) ?>

        </div>

    <?php endif; ?>

    <!-- ADD / EDIT MATCH -->

    <section class="mb-8 rounded-2xl border border-[#ff0080]/30 bg-[#1c003a]/80 backdrop-blur-xl p-6">

        <div class="mb-6 flex items-center justify-between">

            <div>

                <h2 class="text-2xl font-black text-white">

                    <?= $editMatch
                        ? 'Edit Match'
                        : 'Add New Match'
                    ?>

                </h2>

                <p class="mt-1 text-sm text-gray-300">

                    Add matches from any competition except Premier League.

                </p>

            </div>

            <?php if ($editMatch): ?>

                <a
                    href="manage_other_matches.php"
                    class="rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 px-5 py-3 font-bold transition"
                >
                    Cancel
                </a>

            <?php endif; ?>

        </div>

        <form
            method="POST"
            enctype="multipart/form-data"
        >

            <input
                type="hidden"
                name="match_id"
                value="<?= $editMatch
                    ? (int)$editMatch['id']
                    : 0
                ?>"
            >

            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">

                <!-- COMPETITION -->

                <div>

                    <label class="mb-2 block font-bold">
                        Competition
                    </label>

                    <input
                        type="text"
                        name="competition"
                        list="competition-list"
                        required
                        value="<?= e(
                            $editMatch['competition'] ?? ''
                        ) ?>"
                        placeholder="La Liga"
                        class="w-full rounded-xl border-2 border-[#ff0080]/50 bg-black/40 px-4 py-3 outline-none focus:border-[#ff9900] transition"
                    >

                    <datalist id="competition-list">

                        <?php foreach ($competitions as $competition): ?>

                            <option
                                value="<?= e($competition) ?>"
                            >

                        <?php endforeach; ?>

                    </datalist>

                </div>

                <!-- GAMEWEEK -->

                <div>

                    <label class="mb-2 block font-bold">
                        Gameweek
                    </label>

                    <input
                        type="number"
                        name="gameweek"
                        min="1"
                        required
                        value="<?= e(
                            $editMatch['gameweek'] ?? 1
                        ) ?>"
                        class="w-full rounded-xl border-2 border-[#ff0080]/50 bg-black/40 px-4 py-3"
                    >

                </div>

                <!-- HOME TEAM -->

                <div>

                    <label class="mb-2 block font-bold">
                        Home Team
                    </label>

                    <input
                        type="text"
                        name="home_team"
                        required
                        value="<?= e(
                            $editMatch['home_team'] ?? ''
                        ) ?>"
                        placeholder="Real Madrid"
                        class="w-full rounded-xl border-2 border-[#ff0080]/50 bg-black/40 px-4 py-3"
                    >

                </div>

                <!-- AWAY TEAM -->

                <div>

                    <label class="mb-2 block font-bold">
                        Away Team
                    </label>

                    <input
                        type="text"
                        name="away_team"
                        required
                        value="<?= e(
                            $editMatch['away_team'] ?? ''
                        ) ?>"
                        placeholder="Barcelona"
                        class="w-full rounded-xl border-2 border-[#ff0080]/50 bg-black/40 px-4 py-3"
                    >

                </div>

                <!-- HOME LOGO -->

                <div class="rounded-xl border border-white/10 p-5">

                    <h3 class="mb-4 font-black">
                        Home Team Logo
                    </h3>

                    <?php if (
                        !empty(
                            $editMatch['home_team_pic']
                        )
                    ): ?>

                        <img
                            src="<?= e(
                                $editMatch['home_team_pic']
                            ) ?>"
                            class="mb-4 h-20 w-20 rounded-xl bg-white object-contain p-2"
                            alt="Home Logo"
                        >

                    <?php endif; ?>

                    <label class="mb-2 block text-sm text-gray-300">
                        Online URL
                    </label>

                    <input
                        type="url"
                        name="home_logo_url"
                        id="home_logo_url"
                        placeholder="https://example.com/logo.png"
                        class="mb-4 w-full rounded-xl border-2 border-[#ff0080]/50 bg-black/40 px-4 py-3"
                    >

                    <div class="mb-4 text-center text-gray-400">
                        OR
                    </div>

                    <label class="mb-2 block text-sm text-gray-300">
                        Upload From Device
                    </label>

                    <input
                        type="file"
                        name="home_logo_file"
                        id="home_logo_file"
                        accept="image/jpeg,image/png,image/webp,image/gif"
                        class="w-full rounded-xl border-2 border-[#ff0080]/50 bg-black/40 px-4 py-3"
                    >

                    <img
                        id="home_logo_preview"
                        class="mt-4 hidden h-24 w-24 rounded-xl bg-white object-contain p-2"
                        alt="Preview"
                    >

                </div>

                <!-- AWAY LOGO -->

                <div class="rounded-xl border border-white/10 p-5">

                    <h3 class="mb-4 font-black">
                        Away Team Logo
                    </h3>

                    <?php if (
                        !empty(
                            $editMatch['away_team_pic']
                        )
                    ): ?>

                        <img
                            src="<?= e(
                                $editMatch['away_team_pic']
                            ) ?>"
                            class="mb-4 h-20 w-20 rounded-xl bg-white object-contain p-2"
                            alt="Away Logo"
                        >

                    <?php endif; ?>

                    <label class="mb-2 block text-sm text-gray-300">
                        Online URL
                    </label>

                    <input
                        type="url"
                        name="away_logo_url"
                        id="away_logo_url"
                        placeholder="https://example.com/logo.png"
                        class="mb-4 w-full rounded-xl border-2 border-[#ff0080]/50 bg-black/40 px-4 py-3"
                    >

                    <div class="mb-4 text-center text-gray-400">
                        OR
                    </div>

                    <label class="mb-2 block text-sm text-gray-300">
                        Upload From Device
                    </label>

                    <input
                        type="file"
                        name="away_logo_file"
                        id="away_logo_file"
                        accept="image/jpeg,image/png,image/webp,image/gif"
                        class="w-full rounded-xl border-2 border-[#ff0080]/50 bg-black/40 px-4 py-3"
                    >

                    <img
                        id="away_logo_preview"
                        class="mt-4 hidden h-24 w-24 rounded-xl bg-white object-contain p-2"
                        alt="Preview"
                    >

                </div>

                <!-- MATCH DATE -->

                <div>

                    <label class="mb-2 block font-bold">
                        Match Date & Time
                    </label>

                    <input
                        type="datetime-local"
                        name="match_date"
                        required
                        value="<?= $editMatch
                            ? date(
                                'Y-m-d\TH:i',
                                strtotime(
                                    $editMatch['match_date']
                                )
                            )
                            : ''
                        ?>"
                        class="w-full rounded-xl border-2 border-[#ff0080]/50 bg-black/40 px-4 py-3"
                    >

                </div>

                <!-- DEADLINE -->

                <div>

                    <label class="mb-2 block font-bold">
                        Prediction Deadline
                    </label>

                    <input
                        type="datetime-local"
                        name="deadline"
                        value="<?= !empty(
                            $editMatch['deadline'] ?? ''
                        )
                            ? date(
                                'Y-m-d\TH:i',
                                strtotime(
                                    $editMatch['deadline']
                                )
                            )
                            : ''
                        ?>"
                        class="w-full rounded-xl border-2 border-[#ff0080]/50 bg-black/40 px-4 py-3"
                    >

                </div>

            </div>

            <button
                type="submit"
                name="save_match"
                class="mt-6 rounded-xl bg-gradient-to-r from-[#e90052] to-[#ff9900] hover:from-[#ff9900] hover:to-[#e90052] px-8 py-3 font-black transition transform hover:-translate-y-0.5"
            >

                <?= $editMatch
                    ? 'Update Match'
                    : 'Add Match'
                ?>

            </button>

        </form>

    </section>

    <!-- FILTER -->

    <section class="mb-6 rounded-2xl border border-[#ff0080]/30 bg-[#1c003a]/80 backdrop-blur-xl p-5">

        <form
            method="GET"
            class="flex flex-col gap-4 md:flex-row md:items-end"
        >

            <div class="flex-1">

                <label class="mb-2 block font-bold">
                    Filter Competition
                </label>

                <select
                    name="competition"
                    class="w-full rounded-xl border-2 border-[#ff0080]/50 bg-black/40 px-4 py-3"
                >

                    <option value="">
                        All Competitions
                    </option>

                    <?php foreach ($competitions as $competition): ?>

                        <option
                            value="<?= e($competition) ?>"
                            <?= $selectedCompetition === $competition
                                ? 'selected'
                                : ''
                            ?>
                        >

                            <?= e($competition) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <button
                type="submit"
                class="rounded-xl bg-[#ff0080] hover:bg-[#ff1a66] px-6 py-3 font-black transition"
            >
                Filter
            </button>

            <a
                href="manage_other_matches.php"
                class="rounded-xl bg-white/10 hover:bg-white/20 border border-white/20 px-6 py-3 text-center font-bold transition"
            >
                Clear
            </a>

        </form>

    </section>

    <!-- MATCH LIST -->

    <section class="overflow-hidden rounded-2xl border border-[#ff0080]/30 bg-[#1c003a]/80 backdrop-blur-xl">

        <div class="border-b border-white/10 p-6">

            <h2 class="text-2xl font-black text-white">
                Matches
            </h2>

            <p class="mt-1 text-sm text-gray-300">

                <?= count($matches) ?> match(es) found.

            </p>

        </div>

        <?php if (empty($matches)): ?>

            <div class="p-10 text-center text-gray-400">

                No matches found.

            </div>

        <?php else: ?>

            <div class="overflow-x-auto">

                <table class="w-full">

                    <thead class="bg-black/20 text-left text-sm text-gray-300">

                        <tr>

                            <th class="p-4 text-center">
                                Competition
                            </th>

                            <th class="p-4 text-center">
                                GW
                            </th>

                            <th class="p-4 text-center">
                                Match
                            </th>

                            <th class="p-4 text-center">
                                Date
                            </th>

                            <th class="p-4 text-center">
                                Deadline
                            </th>

                            <th class="p-4 text-center">
                                Result
                            </th>

                            <th class="p-4 text-center">
                                Actions
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($matches as $match): ?>

                            <tr class="border-t border-white/10 hover:bg-pink-500/10 transition">

                                <td class="p-4 font-bold text-[#ff0080]">

                                    <?= e(
                                        $match['competition']
                                    ) ?>

                                </td>

                                <td class="p-4">

                                    <?= (int)$match['gameweek'] ?>

                                </td>

                                <td class="p-4">

                                    <div class="flex min-w-[300px] items-center gap-3">

                                        <div class="flex flex-1 items-center justify-end gap-2">

                                            <span class="font-bold text-white">

                                                <?= e(
                                                    $match['home_team']
                                                ) ?>

                                            </span>

                                            <?php if (
                                                !empty(
                                                    $match['home_team_pic']
                                                )
                                            ): ?>

                                                <img
                                                    src="<?= e(
                                                        $match['home_team_pic']
                                                    ) ?>"
                                                    class="h-10 w-10 object-contain"
                                                    alt="<?= e(
                                                        $match['home_team']
                                                    ) ?>"
                                                    onerror="this.style.display='none'"
                                                >

                                            <?php endif; ?>

                                        </div>

                                        <span class="rounded-lg bg-black/40 border border-white/10 px-3 py-1 text-xs">
                                            VS
                                        </span>

                                        <div class="flex flex-1 items-center gap-2">

                                            <?php if (
                                                !empty(
                                                    $match['away_team_pic']
                                                )
                                            ): ?>

                                                <img
                                                    src="<?= e(
                                                        $match['away_team_pic']
                                                    ) ?>"
                                                    class="h-10 w-10 object-contain"
                                                    alt="<?= e(
                                                        $match['away_team']
                                                    ) ?>"
                                                    onerror="this.style.display='none'"
                                                >

                                            <?php endif; ?>

                                            <span class="font-bold text-white">

                                                <?= e(
                                                    $match['away_team']
                                                ) ?>

                                            </span>

                                        </div>

                                    </div>

                                </td>

                                <td class="p-4 text-sm text-gray-300">

                                    <?= date(
                                        'd M Y H:i',
                                        strtotime(
                                            $match['match_date']
                                        )
                                    ) ?>

                                </td>

                                <td class="p-4 text-sm text-gray-300">

                                    <?= !empty(
                                        $match['deadline']
                                    )
                                        ? date(
                                            'd M Y H:i',
                                            strtotime(
                                                $match['deadline']
                                            )
                                        )
                                        : 'Not set'
                                    ?>

                                </td>

                                <td class="p-4">

                                    <?php if (
                                        $match['home_score'] !== null &&
                                        $match['away_score'] !== null
                                    ): ?>

                                        <span class="rounded-lg bg-green-500/10 border border-green-500/30 px-3 py-1 font-bold text-green-300">

                                            <?= (int)$match['home_score'] ?>
                                            -
                                            <?= (int)$match['away_score'] ?>

                                        </span>

                                    <?php else: ?>

                                        <span class="text-yellow-300">
                                            Pending
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td class="p-4">

                                    <div class="flex gap-2">

                                        <a
                                            href="manage_other_matches.php?edit=<?= (int)$match['id'] ?>"
                                            class="rounded-lg bg-[#ff9900] hover:bg-[#ffb84d] px-4 py-2 text-sm font-black text-black transition"
                                        >
                                            Edit
                                        </a>

                                        <form
                                            method="POST"
                                            onsubmit="return confirm('Delete this match and its related predictions?');"
                                        >

                                            <input
                                                type="hidden"
                                                name="match_id"
                                                value="<?= (int)$match['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                name="delete_match"
                                                class="rounded-lg bg-red-600 hover:bg-red-500 px-4 py-2 text-sm font-bold text-black transition"
                                            >
                                                Delete
                                            </button>

                                        </form>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </section>

</main>

<script>

/*
|--------------------------------------------------------------------------
| LOGO PREVIEW
|--------------------------------------------------------------------------
*/

function setupLogoPreview(
    inputId,
    previewId
) {

    const input =
        document.getElementById(
            inputId
        );

    const preview =
        document.getElementById(
            previewId
        );

    if (!input || !preview) {
        return;
    }

    input.addEventListener(
        'change',
        function () {

            const file =
                input.files[0];

            if (!file) {

                preview.src = '';

                preview.classList.add(
                    'hidden'
                );

                return;
            }

            if (
                !file.type.startsWith(
                    'image/'
                )
            ) {
                return;
            }

            const reader =
                new FileReader();

            reader.onload =
                function (event) {

                    preview.src =
                        event.target.result;

                    preview.classList.remove(
                        'hidden'
                    );
                };

            reader.readAsDataURL(
                file
            );
        }
    );
}

setupLogoPreview(
    'home_logo_file',
    'home_logo_preview'
);

setupLogoPreview(
    'away_logo_file',
    'away_logo_preview'
);

</script>

</body>
</html>