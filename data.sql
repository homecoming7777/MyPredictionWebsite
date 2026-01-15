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