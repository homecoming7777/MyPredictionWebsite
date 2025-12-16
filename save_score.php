<?php
include 'connect.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $match_id   = $_POST['match_id'];
    $home_score = $_POST['home_score'];
    $away_score = $_POST['away_score'];

    $stmt = $conn->prepare("UPDATE matches SET home_score=?, away_score=? WHERE id=?");
    $stmt->bind_param("iii", $home_score, $away_score, $match_id);
    $stmt->execute();

    $stmt2 = $conn->prepare("SELECT id, predicted_home, predicted_away FROM score_exact WHERE match_id=?");
    $stmt2->bind_param("i", $match_id);
    $stmt2->execute();
    $preds = $stmt2->get_result();

    while ($row = $preds->fetch_assoc()) {
        $points = 0;
        $ph = $row['predicted_home'];
        $pa = $row['predicted_away'];

        if ($ph == $home_score && $pa == $away_score) {
            $points = 3; 
        } elseif (
            ($ph > $pa && $home_score > $away_score) ||
            ($ph < $pa && $home_score < $away_score) ||
            ($ph == $pa && $home_score == $away_score)
        ) {
            $points = 1; 
        } else {
            $points = 0; 
        }

        $update = $conn->prepare("UPDATE score_exact SET points=? WHERE id=?");
        $update->bind_param("ii", $points, $row['id']);
        $update->execute();
    }

    echo "Score updated and points calculated for all users!";
}
?>
