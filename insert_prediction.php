<?php
session_start();
include 'connect.php';

if (!isset($_SESSION['user_id'])) {
  header("Location: login.php");
  exit;
}

$user_id = (int)$_SESSION['user_id'];

function normalize_input($key) {
    if (!isset($_POST[$key])) return [];
    return is_array($_POST[$key]) ? $_POST[$key] : [$_POST[$key]];
}

$match_ids = normalize_input('match_id');
$predicted_homes = normalize_input('predicted_home');
$predicted_aways = normalize_input('predicted_away');

if (count($match_ids) === 0) {
    header("Location: other_matches.php?error=no_matches");
    exit;
}

$conn->begin_transaction();

try {
    $check_stmt = $conn->prepare("SELECT id, predicted_home, predicted_away FROM score_exact WHERE user_id = ? AND match_id = ?");
    if (!$check_stmt) throw new Exception("Prepare check_stmt failed: " . $conn->error);

    $update_stmt = $conn->prepare("UPDATE score_exact SET predicted_home = ?, predicted_away = ? WHERE user_id = ? AND match_id = ?");
    if (!$update_stmt) throw new Exception("Prepare update_stmt failed: " . $conn->error);

    $insert_stmt = $conn->prepare("INSERT INTO score_exact (user_id, match_id, predicted_home, predicted_away) VALUES (?, ?, ?, ?)");
    if (!$insert_stmt) throw new Exception("Prepare insert_stmt failed: " . $conn->error);

    $match_time_stmt = $conn->prepare("SELECT match_date FROM matches WHERE id = ?");
    if (!$match_time_stmt) throw new Exception("Prepare match_time_stmt failed: " . $conn->error);


    for ($i = 0; $i < count($match_ids); $i++) {
        $mid_raw = $match_ids[$i];
        if ($mid_raw === '' || $mid_raw === null) continue;
        $match_id = (int)$mid_raw;

        $home_raw = $predicted_homes[$i] ?? null;
        $away_raw = $predicted_aways[$i] ?? null;

        if ($home_raw === null || $away_raw === null || $home_raw === '' || $away_raw === '') {
            continue;
        }

        $home = (int)$home_raw;
        $away = (int)$away_raw;

        $match_time_stmt->bind_param("i", $match_id);
        $match_time_stmt->execute();
        $match_time_stmt->store_result();
        if ($match_time_stmt->num_rows === 0) {
            continue;
        }
        $match_time_stmt->bind_result($match_date_str);
        $match_time_stmt->fetch();

        $match_dt = new DateTime($match_date_str, new DateTimeZone("UTC"));
        if ($now >= $match_dt) {
            continue;
        }

        $check_stmt->bind_param("ii", $user_id, $match_id);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $update_stmt->bind_param("iiii", $home, $away, $user_id, $match_id);
            if (!$update_stmt->execute()) {
                throw new Exception("Update failed for match_id {$match_id}: " . $update_stmt->error);
            }
        } else {
            $insert_stmt->bind_param("iiii", $user_id, $match_id, $home, $away);
            if (!$insert_stmt->execute()) {
                throw new Exception("Insert failed for match_id {$match_id}: " . $insert_stmt->error);
            }
        }

    }

    $conn->commit();

    $check_stmt->close();
    $update_stmt->close();
    $insert_stmt->close();
    $match_time_stmt->close();

    header("Location: other_matches.php?success=1");
    exit;

} catch (Exception $e) {
    $conn->rollback();
    error_log("insert_prediction error: " . $e->getMessage());
    echo "An error occurred while saving predictions: " . htmlspecialchars($e->getMessage());
    exit;
}
