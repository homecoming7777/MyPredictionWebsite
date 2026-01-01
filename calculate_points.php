<?php
include 'connect.php';

// دوز على جميع التوقعات
$sql = "SELECT p.id, p.user_id, p.match_id, p.predicted_home, p.predicted_away, 
               m.home_score, m.away_score
        FROM score_exact p
        JOIN matches m ON p.match_id = m.id";
$result = $conn->query($sql);

while ($row = $result->fetch_assoc()) {
    $points = 0;

    // غير حيت الماتش خاصو يكون مسالي (scores مشي NULL)
    if ($row['home_score'] !== null && $row['away_score'] !== null) {
        if ($row['predicted_home'] == $row['home_score'] &&
            $row['predicted_away'] == $row['away_score']) {
            $points = 3; // توقع صحيح كامل
        } else {
            $pred_res = $row['predicted_home'] - $row['predicted_away'];
            $real_res = $row['home_score'] - $row['away_score'];

            if (($pred_res > 0 && $real_res > 0) ||
                ($pred_res < 0 && $real_res < 0) ||
                ($pred_res == 0 && $real_res == 0)) {
                $points = 1; // نفس النتيجة (فوز/تعادل/هزيمة)
            }
        }
    }

    // Example inside your points update logic
// after you calculate $points for user $user_id and match $match_id:

// Check if this is user’s Double Gameweek pick
$double_stmt = $conn->prepare("
  SELECT 1 FROM double_gameweek WHERE user_id=? AND match_id=?
");
$double_stmt->bind_param("ii", $user_id, $match_id);
$double_stmt->execute();
$is_double = $double_stmt->get_result()->num_rows > 0;

// ✅ Double the points if it’s their chosen match
if ($is_double) {
  $points *= 2;
}

// Then update or insert into your predictions/points table as usual

    // Update النقط
    $update = $conn->prepare("UPDATE score_exact SET points=? WHERE id=?");
    $update->bind_param("ii", $points, $row['id']);
    $update->execute();
}

echo "✅ Points calculated successfully!";
?>
