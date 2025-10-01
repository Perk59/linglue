-- Linglue データベーススキーマ

CREATE DATABASE IF NOT EXISTS linglue CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE linglue;

-- ユーザーテーブル
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(100) NOT NULL,
    icon_url VARCHAR(500) DEFAULT NULL,
    learning_goal VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 単語マスターテーブル
CREATE TABLE words (
    id INT AUTO_INCREMENT PRIMARY KEY,
    word_en VARCHAR(100) NOT NULL,
    word_jp VARCHAR(200) NOT NULL,
    level INT DEFAULT 1,
    category VARCHAR(50) DEFAULT 'general',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_level (level),
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ユーザー学習単語テーブル
CREATE TABLE user_words (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    word_id INT NOT NULL,
    correct_count INT DEFAULT 0,
    wrong_count INT DEFAULT 0,
    last_study_at TIMESTAMP NULL,
    next_review_at TIMESTAMP NULL,
    mastered_flag TINYINT DEFAULT 0,
    consecutive_correct INT DEFAULT 0,
    total_answer_time DECIMAL(10,2) DEFAULT 0.00,
    answer_count INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (word_id) REFERENCES words(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_word (user_id, word_id),
    INDEX idx_user_review (user_id, next_review_at),
    INDEX idx_mastered (mastered_flag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- テスト履歴テーブル
CREATE TABLE test_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    total_questions INT NOT NULL,
    correct_answers INT NOT NULL,
    wrong_answers INT NOT NULL,
    test_duration INT NOT NULL COMMENT '秒単位',
    test_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_date (user_id, test_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ランキングテーブル
CREATE TABLE rankings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    total_study_time INT DEFAULT 0 COMMENT '秒単位',
    correct_rate DECIMAL(5,2) DEFAULT 0.00,
    streak_days INT DEFAULT 0,
    last_study_date DATE NULL,
    total_words_learned INT DEFAULT 0,
    total_tests_taken INT DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_ranking (user_id),
    INDEX idx_study_time (total_study_time DESC),
    INDEX idx_correct_rate (correct_rate DESC),
    INDEX idx_streak (streak_days DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- フレンドテーブル
CREATE TABLE friends (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    friend_id INT NOT NULL,
    status ENUM('pending', 'accepted', 'blocked') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (friend_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_friendship (user_id, friend_id),
    INDEX idx_user_status (user_id, status),
    INDEX idx_friend_status (friend_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 学習セッションテーブル
CREATE TABLE study_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    session_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    session_end TIMESTAMP NULL,
    words_studied INT DEFAULT 0,
    session_duration INT DEFAULT 0 COMMENT '秒単位',
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_date (user_id, session_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- サンプルデータ挿入

-- サンプル単語データ（レベル1: 基礎）
INSERT INTO words (word_en, word_jp, level, category) VALUES
('apple', 'りんご', 1, 'food'),
('book', '本', 1, 'education'),
('cat', '猫', 1, 'animal'),
('dog', '犬', 1, 'animal'),
('house', '家', 1, 'daily'),
('water', '水', 1, 'daily'),
('school', '学校', 1, 'education'),
('friend', '友達', 1, 'daily'),
('happy', '幸せな', 1, 'emotion'),
('love', '愛', 1, 'emotion'),
('time', '時間', 1, 'abstract'),
('day', '日', 1, 'time'),
('year', '年', 1, 'time'),
('person', '人', 1, 'daily'),
('child', '子供', 1, 'daily');

-- サンプル単語データ（レベル2: 中級）
INSERT INTO words (word_en, word_jp, level, category) VALUES
('achieve', '達成する', 2, 'action'),
('opportunity', '機会', 2, 'abstract'),
('environment', '環境', 2, 'abstract'),
('significant', '重要な', 2, 'adjective'),
('demonstrate', '実証する', 2, 'action'),
('particular', '特定の', 2, 'adjective'),
('various', '様々な', 2, 'adjective'),
('approach', '接近する、方法', 2, 'action'),
('benefit', '利益、恩恵', 2, 'abstract'),
('challenge', '挑戦', 2, 'abstract'),
('communicate', 'コミュニケーションする', 2, 'action'),
('develop', '発展させる', 2, 'action'),
('establish', '確立する', 2, 'action'),
('experience', '経験', 2, 'abstract'),
('individual', '個人', 2, 'daily');

-- サンプル単語データ（レベル3: 上級）
INSERT INTO words (word_en, word_jp, level, category) VALUES
('ambiguous', '曖昧な', 3, 'adjective'),
('arbitrary', '恣意的な', 3, 'adjective'),
('comprehensive', '包括的な', 3, 'adjective'),
('deteriorate', '悪化する', 3, 'action'),
('elaborate', '詳しく述べる', 3, 'action'),
('fluctuate', '変動する', 3, 'action'),
('facilitate', '促進する', 3, 'action'),
('inevitable', '避けられない', 3, 'adjective'),
('manipulate', '操作する', 3, 'action'),
('obsolete', '時代遅れの', 3, 'adjective'),
('persistent', '持続的な', 3, 'adjective'),
('redundant', '冗長な', 3, 'adjective'),
('substantial', '実質的な', 3, 'adjective'),
('trivial', 'ささいな', 3, 'adjective'),
('versatile', '多才な', 3, 'adjective');
