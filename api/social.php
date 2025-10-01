<?php
// api/social.php - ソーシャル関連API（ランキング・フレンド）

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ランキング取得
if ($method === 'GET' && $action === 'ranking') {
    $userId = requireAuth();
    $type = $_GET['type'] ?? 'study_time'; // study_time, correct_rate, streak
    $limit = $_GET['limit'] ?? 50;
    
    $orderBy = 'total_study_time DESC';
    
    switch ($type) {
        case 'correct_rate':
            $orderBy = 'correct_rate DESC';
            break;
        case 'streak':
            $orderBy = 'streak_days DESC';
            break;
        case 'words':
            $orderBy = 'total_words_learned DESC';
            break;
    }
    
    $sql = "
        SELECT r.*, u.name, u.icon_url
        FROM rankings r
        JOIN users u ON r.user_id = u.id
        ORDER BY $orderBy
        LIMIT ?
    ";
    
    $rankings = $db->query($sql, [(int)$limit]);
    
    // 自分のランキング取得
    $myRanking = $db->queryOne("
        SELECT 
            r.*,
            u.name,
            u.icon_url,
            (SELECT COUNT(*) + 1 FROM rankings r2 WHERE $orderBy > (SELECT $orderBy FROM rankings WHERE user_id = ?)) as rank
        FROM rankings r
        JOIN users u ON r.user_id = u.id
        WHERE r.user_id = ?
    ", [$userId, $userId]);
    
    Response::success([
        'rankings' => $rankings,
        'my_ranking' => $myRanking
    ]);
}

// フレンド一覧取得
if ($method === 'GET' && $action === 'friends') {
    $userId = requireAuth();
    
    $sql = "
        SELECT u.id, u.name, u.icon_url, u.email, f.status, f.created_at,
               r.total_study_time, r.correct_rate, r.streak_days
        FROM friends f
        JOIN users u ON (f.friend_id = u.id)
        LEFT JOIN rankings r ON u.id = r.user_id
        WHERE f.user_id = ? AND f.status = 'accepted'
        ORDER BY u.name
    ";
    
    $friends = $db->query($sql, [$userId]);
    
    Response::success($friends);
}

// フレンドリクエスト送信
if ($method === 'POST' && $action === 'friend_request') {
    $userId = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    
    $friendEmail = $data['friend_email'] ?? '';
    
    if (empty($friendEmail)) {
        Response::error('フレンドのメールアドレスが必要です');
    }
    
    // フレンド検索
    $friend = $db->queryOne("SELECT id FROM users WHERE email = ?", [$friendEmail]);
    
    if (!$friend) {
        Response::error('ユーザーが見つかりません');
    }
    
    if ($friend['id'] == $userId) {
        Response::error('自分自身をフレンドに追加できません');
    }
    
    // 既存のフレンド関係チェック
    $existing = $db->queryOne("
        SELECT * FROM friends 
        WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)
    ", [$userId, $friend['id'], $friend['id'], $userId]);
    
    if ($existing) {
        Response::error('既にフレンドリクエストが存在します');
    }
    
    // フレンドリクエスト作成
    $sql = "INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, 'pending')";
    $db->execute($sql, [$userId, $friend['id']]);
    
    Response::success([], 'フレンドリクエストを送信しました');
}

// フレンドリクエスト承認
if ($method === 'POST' && $action === 'accept_friend') {
    $userId = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    
    $friendId = $data['friend_id'] ?? null;
    
    if (!$friendId) {
        Response::error('friend_idが必要です');
    }
    
    // リクエスト更新
    $sql = "UPDATE friends SET status = 'accepted' WHERE user_id = ? AND friend_id = ?";
    $result = $db->execute($sql, [$friendId, $userId]);
    
    if ($result) {
        // 逆方向のフレンド関係も作成
        $db->execute("INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, 'accepted')", [$userId, $friendId]);
        
        Response::success([], 'フレンドリクエストを承認しました');
    } else {
        Response::error('フレンドリクエストが見つかりません');
    }
}

// フレンドリクエスト拒否
if ($method === 'POST' && $action === 'reject_friend') {
    $userId = requireAuth();
    $data = json_decode(file_get_contents('php://input'), true);
    
    $friendId = $data['friend_id'] ?? null;
    
    if (!$friendId) {
        Response::error('friend_idが必要です');
    }
    
    $sql = "DELETE FROM friends WHERE user_id = ? AND friend_id = ?";
    $db->execute($sql, [$friendId, $userId]);
    
    Response::success([], 'フレンドリクエストを拒否しました');
}

// フレンド削除
if ($method === 'DELETE' && $action === 'friend') {
    $userId = requireAuth();
    $friendId = $_GET['friend_id'] ?? null;
    
    if (!$friendId) {
        Response::error('friend_idが必要です');
    }
    
    // 双方向削除
    $db->execute("DELETE FROM friends WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)", 
        [$userId, $friendId, $friendId, $userId]);
    
    Response::success([], 'フレンドを削除しました');
}

// 保留中のフレンドリクエスト取得
if ($method === 'GET' && $action === 'friend_requests') {
    $userId = requireAuth();
    
    $sql = "
        SELECT u.id, u.name, u.icon_url, u.email, f.created_at
        FROM friends f
        JOIN users u ON f.user_id = u.id
        WHERE f.friend_id = ? AND f.status = 'pending'
        ORDER BY f.created_at DESC
    ";
    
    $requests = $db->query($sql, [$userId]);
    
    Response::success($requests);
}

// フレンドの学習進捗比較
if ($method === 'GET' && $action === 'compare') {
    $userId = requireAuth();
    $friendId = $_GET['friend_id'] ?? null;
    
    if (!$friendId) {
        Response::error('friend_idが必要です');
    }
    
    // フレンド関係確認
    $friendship = $db->queryOne("
        SELECT * FROM friends 
        WHERE user_id = ? AND friend_id = ? AND status = 'accepted'
    ", [$userId, $friendId]);
    
    if (!$friendship) {
        Response::error('フレンドではありません', 403);
    }
    
    // 自分の統計
    $myStats = getUserStats($userId);
    
    // フレンドの統計
    $friendStats = getUserStats($friendId);
    
    Response::success([
        'my_stats' => $myStats,
        'friend_stats' => $friendStats
    ]);
}

// ユーザー統計取得関数
function getUserStats($userId) {
    global $db;
    
    $user = $db->queryOne("SELECT id, name, icon_url FROM users WHERE id = ?", [$userId]);
    $ranking = $db->queryOne("SELECT * FROM rankings WHERE user_id = ?", [$userId]);
    
    $totalWords = $db->queryOne("SELECT COUNT(*) as count FROM words")['count'];
    $masteredWords = $db->queryOne("
        SELECT COUNT(*) as count FROM user_words 
        WHERE user_id = ? AND mastered_flag = 1
    ", [$userId])['count'];
    
    $studyingWords = $db->queryOne("
        SELECT COUNT(*) as count FROM user_words 
        WHERE user_id = ? AND mastered_flag = 0
    ", [$userId])['count'];
    
    return [
        'user' => $user,
        'ranking' => $ranking,
        'total_words' => $totalWords,
        'mastered_words' => $masteredWords,
        'studying_words' => $studyingWords,
        'progress_rate' => $totalWords > 0 ? round(($masteredWords / $totalWords) * 100, 2) : 0
    ];
}

Response::error('無効なリクエストです', 400);
