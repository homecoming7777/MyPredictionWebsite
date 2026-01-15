<?php
include 'connect.php';

$sql = "
SELECT p.id, p.user_id, p.match_id,
       p.predicted_home, p.predicted_away,
       m.home_score, m.away_score
FROM score_exact p
JOIN matches m ON p.match_id = m.id
";

$result = $conn->query($sql);

while ($row = $result->fetch_assoc()) {

    $user_id  = $row['user_id'];
    $match_id = $row['match_id'];
    $points   = 0;

    if ($row['home_score'] !== null && $row['away_score'] !== null) {

        if (
            $row['predicted_home'] == $row['home_score'] &&
            $row['predicted_away'] == $row['away_score']
        ) {
            $points = 3;
        } else {
            $pred = $row['predicted_home'] - $row['predicted_away'];
            $real = $row['home_score'] - $row['away_score'];

            if (
                ($pred > 0 && $real > 0) ||
                ($pred < 0 && $real < 0) ||
                ($pred == 0 && $real == 0)
            ) {
                $points = 1;
            }
        }
    }

    $double_stmt = $conn->prepare("
        SELECT 1 FROM double_gameweek
        WHERE user_id = ? AND match_id = ?
    ");
    $double_stmt->bind_param("ii", $user_id, $match_id);
    $double_stmt->execute();
    $is_double = $double_stmt->get_result()->num_rows > 0;

    if ($is_double) {
        $points *= 2;
    }

    $triple_stmt = $conn->prepare("
        SELECT 1 FROM triple_match
        WHERE user_id = ? AND match_id = ?
    ");
    $triple_stmt->bind_param("ii", $user_id, $match_id);
    $triple_stmt->execute();
    $is_triple = $triple_stmt->get_result()->num_rows > 0;

    if ($is_triple) {
        $points *= 3;
    }

    $card_stmt = $conn->prepare("
        SELECT double_all_used
        FROM user_cards
        WHERE user_id = ?
    ");
    $card_stmt->bind_param("i", $user_id);
    $card_stmt->execute();
    $card = $card_stmt->get_result()->fetch_assoc();

    if ($card && $card['double_all_used']) {
        $points *= 2;
    }

    $update = $conn->prepare("
        UPDATE score_exact SET points = ? WHERE id = ?
    ");
    $update->bind_param("ii", $points, $row['id']);
    $update->execute();
}

echo "✅ Points calculated successfully";
