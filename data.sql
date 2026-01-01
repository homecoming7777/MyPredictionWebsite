/*DELETE FROM your_table;
-- Reset auto increment to start at 1
ALTER TABLE your_table AUTO_INCREMENT = 1;
*/

/*
CREATE TABLE score_exact (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    match_id INT NOT NULL,
    predicted_home INT NOT NULL,
    predicted_away INT NOT NULL,
    points INT DEFAULT NULL,
    UNIQUE KEY unique_prediction (user_id, match_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
);

CREATE TABLE matches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    home_team VARCHAR(100) NOT NULL,
    home_team_pic VARCHAR(100) NOT NULL,
    away_team VARCHAR(100) NOT NULL,
    away_team_pic VARCHAR(100) NOT NULL,
    match_date DATETIME NOT NULL,
    home_score INT NULL,
    away_score INT NULL,
    gameweek INT NOT NULL,
    deadline DATETIME NULL,
    competition  VARCHAR(50)
);

CREATE TABLE users(
  id INT(11) NOT NULL AUTO_INCREMENT,
  username VARCHAR(100) NOT NULL,
  password VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NULL,
  favorite_team_id VARCHAR(100) NULL,
  favorite_team VARCHAR(100) NULL,
  avatar VARCHAR(255) NULL,
  phone_number VARCHAR(20) NULL,
  wa_apikey VARCHAR(50) NULL
);


CREATE TABLE double_gameweek (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  gameweek INT NOT NULL,
  match_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_user_gw (user_id, gameweek),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE
);*/






INSERT INTO double_gameweek (user_id, gameweek, match_id, created_at)
VALUES
(10, 17, 304, "2025-12-20 00:44:09"),
(30, 17, 298, "2025-12-20 00:44:09"),


INSERT INTO score_exact (user_id, match_id, predicted_home, predicted_away, points, competition) 
VALUES
(10, 293, 2, 1, null, "Premier League"),
(10, 295, 2, 0, null, "Premier League"),
(10, 294, 1, 0, null, "Premier League"),
(10, 296, 3, 0, null, "Premier League"),
(10, 297, 1, 0, null, "Premier League"),
(10, 298, 1, 2, null, "Premier League"),
(10, 300, 1, 0, null, "Premier League"),
(10, 299, 1, 3, null, "Premier League"),
(10, 301, 2, 2, null, "Premier League"),
(10, 304, 2, 0, null, "Premier League"),
(10, 310, 2, 0, null, "Premier League"),
(10, 313, 1, 0, null, "Premier League"),
(10, 312, 3, 1, null, "Premier League"),
(10, 311, 1, 0, null, "Premier League"),
(10, 315, 2, 1, null, "Premier League"),
(10, 314, 3, 0, null, "Premier League"),
(10, 317, 3, 2, null, "Premier League"),
(10, 316, 1, 0, null, "Premier League"),
(10, 321, 1, 1, null, "Premier League"),
(10, 320, 2, 0, null, "Premier League"),
(10, 319, 1, 1, null, "Premier League"),
(10, 318, 0, 1, null, "Premier League"),
(10, 306, 1, 2, null, "Premier League"),
(10, 309, 1, 2, null, "Premier League"),
(10, 308, 1, 3, null, "Premier League"),
(10, 305, 2, 0, null, "Premier League"),
(10, 303, 2, 2, null, "Premier League"),
(10, 307, 2, 3, null, "Premier League");



