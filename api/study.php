<?php
// api/study.php - 学習関連API

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// 単語リスト取得
if ($method === 'GET' && $action === 'words') {
    $userId = requireAuth();
    
    $level = $_GET['level'] ?? null;
    $category = $_GET['category'] ?? null;
    $limit = $_GET['limit'] ?? 20;
    
    $sql = "SELECT * FROM words WHERE 1=1";
    $params = [];
    
    if ($level) {
        $sql .= " AND level = ?";
        $params[] = $level;
    }
    
    if ($category) {
        $sql .= " AND category = ?";
        $params[] = $category;
    }
    
    $sql .= " ORDER BY RAND() LIMIT ?";
    $params[] = (int)$limit;
    
    $words = $db->query($sql, $params);
    
    Response::success($words);
}

// 復習リスト取得
if ($method === 'GET' && $action === 'review') {
    $userId = requireAuth();
    
    $sql = "
        SELECT w.*, uw.correct_count, uw.wrong_count, uw.next_review_at, uw.consecutive_correct
        FROM user_words uw
        JOIN words w ON uw.word_id = w.id
        WHERE uw.user_id = ? 
        AND uw.mastered_flag = 0
        AND uw.next_review_at <= NOW()
        ORDER BY uw.next_review_at ASC
        LIMIT 50
    ";
    
    $reviewWords = $db->query($sql, [$userId]);
    
    Response::success([
        'count' => count($reviewWords),
        'words' => $reviewWords
    ]);
}

// 学習セッション開始
if ($method === 'POST' && $action === 'start_session') {
    $userId = requireAuth();
    
    $sql = "INSERT INTO study_sessions (user_id) VALUES (?)";
    $db->execute($sql, [$userId]);
    $sessionId = $db->lastInsertId();
    
    Response::success(['session_id' => $sessionId], 'セッションを開始しました');
}

// 学習セッション終了
if ($method === 'POST' && $action === 'end_session') {
    $userId = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    
    $sessionId = $data['session_id'] ?? null;
    $wordsStudied = $data['words_studied'] ?? 0;
    $duration = $data['duration'] ?? 0;
    
    if (!$sessionId) {
        Response::error('セッションIDが必要です');
    }
    
    $sql = "
        UPDATE study_sessions 
        SET session_end = NOW(), words_studied = ?, session_duration = ?
        WHERE id = ? AND user_id = ?
    ";
    
    $db->execute($sql, [$wordsStudied, $duration, $sessionId, $userId]);
    
    // ランキング更新
    $sql = "
        UPDATE rankings 
        SET total_study_time = total_study_time + ?,
            last_study_date = CURDATE(),
            total_words_learned = total_words_learned + ?
        WHERE user_id = ?
    ";
    
    $db->execute($sql, [$duration, $wordsStudied, $userId]);
    
    // 連続学習日数更新
    updateStreakDays($userId);
    
    Response::success([], 'セッションを終了しました');
}

// 単語学習記録
if ($method === 'POST' && $action === 'record') {
    $userId = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    
    $wordId = $data['word_id'] ?? null;
    $isCorrect = $data['is_correct'] ?? false;
    $answerTime = $data['answer_time'] ?? 0;
    
    if (!$wordId) {
        Response::error('word_idが必要です');
    }
    
    // user_wordsレコード取得または作成
    $userWord = $db->queryOne("SELECT * FROM user_words WHERE user_id = ? AND word_id = ?", [$userId, $wordId]);
    
    if (!$userWord) {
        // 新規作成
        $sql = "INSERT INTO user_words (user_id, word_id, last_study_at) VALUES (?, ?, NOW())";
        $db->execute($sql, [$userId, $wordId]);
        $userWord = $db->queryOne("SELECT * FROM user_words WHERE user_id = ? AND word_id = ?", [$userId, $wordId]);
    }
    
    // 回答記録更新
    $consecutiveCorrect = $userWord['consecutive_correct'];
    
    if ($isCorrect) {
        $correctCount = $userWord['correct_count'] + 1;
        $consecutiveCorrect++;
        
        $sql = "
            UPDATE user_words 
            SET correct_count = correct_count + 1,
                consecutive_correct = ?,
                total_answer_time = total_answer_time + ?,
                answer_count = answer_count + 1,
                last_study_at = NOW()
            WHERE user_id = ? AND word_id = ?
        ";
        
        $db->execute($sql, [$consecutiveCorrect, $answerTime, $userId, $wordId]);
        
        // 習得判定
        if ($consecutiveCorrect >= MASTERY_THRESHOLD) {
            $db->execute("UPDATE user_words SET mastered_flag = 1 WHERE user_id = ? AND word_id = ?", [$userId, $wordId]);
        }
    } else {
        $sql = "
            UPDATE user_words 
            SET wrong_count = wrong_count + 1,
                consecutive_correct = 0,
                total_answer_time = total_answer_time + ?,
                answer_count = answer_count + 1,
                last_study_at = NOW()
            WHERE user_id = ? AND word_id = ?
        ";
        
        $db->execute($sql, [$answerTime, $userId, $wordId]);
        $consecutiveCorrect = 0;
    }
    
    // 次回復習日計算
    $reviewSchedule = REVIEW_SCHEDULE;
    $reviewIndex = min($consecutiveCorrect, count($reviewSchedule) - 1);
    $nextReviewDays = $reviewSchedule[$reviewIndex];
    
    $sql = "
        UPDATE user_words 
        SET next_review_at = DATE_ADD(NOW(), INTERVAL ? DAY)
        WHERE user_id = ? AND word_id = ?
    ";
    
    $db->execute($sql, [$nextReviewDays, $userId, $wordId]);
    
    Response::success([
        'consecutive_correct' => $consecutiveCorrect,
        'next_review_days' => $nextReviewDays
    ], '記録しました');
}

// 学習進捗取得
if ($method === 'GET' && $action === 'progress') {
    $userId = requireAuth();
    
    // 総単語数
    $totalWords = $db->queryOne("SELECT COUNT(*) as count FROM words")['count'];
    
    // 学習中の単語数
    $studyingWords = $db->queryOne("
        SELECT COUNT(*) as count FROM user_words 
        WHERE user_id = ? AND mastered_flag = 0
    ", [$userId])['count'];
    
    // 習得済み単語数
    $masteredWords = $db->queryOne("
        SELECT COUNT(*) as count FROM user_words 
        WHERE user_id = ? AND mastered_flag = 1
    ", [$userId])['count'];
    
    // 復習待ち単語数
    $reviewWords = $db->queryOne("
        SELECT COUNT(*) as count FROM user_words 
        WHERE user_id = ? AND mastered_flag = 0 AND next_review_at <= NOW()
    ", [$userId])['count'];
    
    // 正答率
    $stats = $db->queryOne("
        SELECT 
            SUM(correct_count) as total_correct,
            SUM(wrong_count) as total_wrong
        FROM user_words
        WHERE user_id = ?
    ", [$userId]);
    
    $totalCorrect = $stats['total_correct'] ?? 0;
    $totalWrong = $stats['total_wrong'] ?? 0;
    $totalAnswers = $totalCorrect + $totalWrong;
    $correctRate = $totalAnswers > 0 ? round(($totalCorrect / $totalAnswers) * 100, 2) : 0;
    
    Response::success([
        'total_words' => $totalWords,
        'studying_words' => $studyingWords,
        'mastered_words' => $masteredWords,
        'review_words' => $reviewWords,
        'correct_rate' => $correctRate,
        'total_answers' => $totalAnswers
    ]);
}

// 連続学習日数更新関数
function updateStreakDays($userId) {
    global $db;
    
    $ranking = $db->queryOne("SELECT last_study_date, streak_days FROM rankings WHERE user_id = ?", [$userId]);
    
    if (!$ranking) {
        return;
    }
    
    $lastStudyDate = $ranking['last_study_date'];
    $currentStreak = $ranking['streak_days'];
    $today = date('Y-m-d');
    
    if ($lastStudyDate === null) {
        // 初回学習
        $newStreak = 1;
    } elseif ($lastStudyDate === $today) {
        // 今日既に学習済み
        return;
    } elseif (strtotime($lastStudyDate) === strtotime($today) - 86400) {
        // 昨日学習していた場合、連続記録更新
        $newStreak = $currentStreak + 1;
    } else {
        // 連続記録リセット
        $newStreak = 1;
    }
    
    $db->execute("UPDATE rankings SET streak_days = ? WHERE user_id = ?", [$newStreak, $userId]);
}

Response::error('無効なリクエストです', 400);
