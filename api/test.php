<?php
// api/test.php - テスト関連API

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// テスト問題生成
if ($method === 'POST' && $action === 'generate') {
    $userId = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    
    $wordIds = $data['word_ids'] ?? [];
    
    if (empty($wordIds)) {
        Response::error('単語IDが必要です');
    }
    
    // 問題数計算（学習単語数 × 120%）
    $questionCount = (int)(count($wordIds) * TEST_QUESTION_MULTIPLIER);
    
    // 単語を取得
    $placeholders = implode(',', array_fill(0, count($wordIds), '?'));
    $words = $db->query("SELECT * FROM words WHERE id IN ($placeholders)", $wordIds);
    
    if (empty($words)) {
        Response::error('単語が見つかりません');
    }
    
    // 問題生成
    $questions = [];
    $allWords = $db->query("SELECT * FROM words ORDER BY RAND() LIMIT 100"); // 選択肢用
    
    for ($i = 0; $i < $questionCount; $i++) {
        $targetWord = $words[array_rand($words)];
        $questionType = rand(0, 1); // 0: 英→日, 1: 日→英
        
        // 選択肢生成
        $choices = [$targetWord];
        $wrongChoices = array_filter($allWords, function($w) use ($targetWord) {
            return $w['id'] !== $targetWord['id'];
        });
        $wrongChoices = array_values($wrongChoices);
        shuffle($wrongChoices);
        
        for ($j = 0; $j < 3 && $j < count($wrongChoices); $j++) {
            $choices[] = $wrongChoices[$j];
        }
        
        shuffle($choices);
        
        $questions[] = [
            'id' => $i + 1,
            'word_id' => $targetWord['id'],
            'type' => $questionType === 0 ? 'en_to_jp' : 'jp_to_en',
            'question' => $questionType === 0 ? $targetWord['word_en'] : $targetWord['word_jp'],
            'choices' => array_map(function($choice) use ($questionType) {
                return $questionType === 0 ? $choice['word_jp'] : $choice['word_en'];
            }, $choices),
            'correct_answer' => $questionType === 0 ? $targetWord['word_jp'] : $targetWord['word_en']
        ];
    }
    
    Response::success([
        'questions' => $questions,
        'total_questions' => count($questions)
    ]);
}

// テスト結果保存
if ($method === 'POST' && $action === 'submit') {
    $userId = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    
    $answers = $data['answers'] ?? [];
    $duration = $data['duration'] ?? 0;
    
    if (empty($answers)) {
        Response::error('回答データが必要です');
    }
    
    $totalQuestions = count($answers);
    $correctAnswers = 0;
    $wrongAnswers = 0;
    $wrongWords = [];
    
    foreach ($answers as $answer) {
        $wordId = $answer['word_id'];
        $isCorrect = $answer['is_correct'];
        $answerTime = $answer['answer_time'] ?? 0;
        
        if ($isCorrect) {
            $correctAnswers++;
        } else {
            $wrongAnswers++;
            
            // 間違えた単語情報取得
            $word = $db->queryOne("SELECT * FROM words WHERE id = ?", [$wordId]);
            if ($word) {
                $wrongWords[] = $word;
            }
        }
        
        // user_wordsに記録
        recordAnswer($userId, $wordId, $isCorrect, $answerTime);
    }
    
    // テスト履歴保存
    $sql = "INSERT INTO test_history (user_id, total_questions, correct_answers, wrong_answers, test_duration) VALUES (?, ?, ?, ?, ?)";
    $db->execute($sql, [$userId, $totalQuestions, $correctAnswers, $wrongAnswers, $duration]);
    
    // ランキング更新
    $correctRate = $totalQuestions > 0 ? round(($correctAnswers / $totalQuestions) * 100, 2) : 0;
    
    $sql = "
        UPDATE rankings 
        SET total_tests_taken = total_tests_taken + 1,
            correct_rate = (
                SELECT AVG(correct_answers * 100.0 / total_questions)
                FROM test_history
                WHERE user_id = ?
            )
        WHERE user_id = ?
    ";
    
    $db->execute($sql, [$userId, $userId]);
    
    Response::success([
        'total_questions' => $totalQuestions,
        'correct_answers' => $correctAnswers,
        'wrong_answers' => $wrongAnswers,
        'correct_rate' => $correctRate,
        'wrong_words' => $wrongWords,
        'duration' => $duration
    ], 'テスト結果を保存しました');
}

// テスト履歴取得
if ($method === 'GET' && $action === 'history') {
    $userId = requireAuth();
    $limit = $_GET['limit'] ?? 10;
    
    $sql = "
        SELECT * FROM test_history 
        WHERE user_id = ? 
        ORDER BY test_date DESC 
        LIMIT ?
    ";
    
    $history = $db->query($sql, [$userId, (int)$limit]);
    
    Response::success($history);
}

// 回答記録関数
function recordAnswer($userId, $wordId, $isCorrect, $answerTime) {
    global $db;
    
    // user_wordsレコード取得または作成
    $userWord = $db->queryOne("SELECT * FROM user_words WHERE user_id = ? AND word_id = ?", [$userId, $wordId]);
    
    if (!$userWord) {
        $sql = "INSERT INTO user_words (user_id, word_id, last_study_at) VALUES (?, ?, NOW())";
        $db->execute($sql, [$userId, $wordId]);
        $userWord = $db->queryOne("SELECT * FROM user_words WHERE user_id = ? AND word_id = ?", [$userId, $wordId]);
    }
    
    $consecutiveCorrect = $userWord['consecutive_correct'];
    
    if ($isCorrect) {
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
}

Response::error('無効なリクエストです', 400);
