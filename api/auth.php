<?php
// api/auth.php - 認証関連API

// エラー表示を有効化（デバッグ用）
error_reporting(E_ALL);
ini_set('display_errors', 1);

// config.phpの正しいパスを指定
require_once __DIR__ . '/../config.php';

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// デバッグ情報
error_log("Method: $method, Action: $action");

// ユーザー登録
if ($method === 'POST' && $action === 'register') {
    $input = file_get_contents('php://input');
    error_log("Register Input: " . $input);
    
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        Response::error('Invalid JSON: ' . json_last_error_msg());
    }
    
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';
    $name = $data['name'] ?? '';
    
    // バリデーション
    if (empty($email) || empty($password) || empty($name)) {
        Response::error('すべてのフィールドを入力してください');
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        Response::error('有効なメールアドレスを入力してください');
    }
    
    if (strlen($password) < 6) {
        Response::error('パスワードは6文字以上にしてください');
    }
    
    // メール重複チェック
    $existing = $db->queryOne("SELECT id FROM users WHERE email = ?", [$email]);
    if ($existing) {
        Response::error('このメールアドレスは既に登録されています');
    }
    
    // ユーザー作成
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    
    $sql = "INSERT INTO users (email, password_hash, name) VALUES (?, ?, ?)";
    $result = $db->execute($sql, [$email, $passwordHash, $name]);
    
    if ($result) {
        $userId = $db->lastInsertId();
        
        // ランキングレコード初期化
        $db->execute("INSERT INTO rankings (user_id) VALUES (?)", [$userId]);
        
        // セッション開始
        Session::set('user_id', $userId);
        
        Response::success([
            'user_id' => $userId,
            'email' => $email,
            'name' => $name
        ], '登録が完了しました');
    } else {
        Response::error('登録に失敗しました');
    }
}

// ログイン
if ($method === 'POST' && $action === 'login') {
    $input = file_get_contents('php://input');
    error_log("Login Input: " . $input);
    
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        Response::error('Invalid JSON: ' . json_last_error_msg());
    }
    
    $email = $data['email'] ?? '';
    $password = $data['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        Response::error('メールアドレスとパスワードを入力してください');
    }
    
    // ユーザー検索
    $user = $db->queryOne("SELECT * FROM users WHERE email = ?", [$email]);
    
    if (!$user || !password_verify($password, $user['password_hash'])) {
        Response::error('メールアドレスまたはパスワードが正しくありません');
    }
    
    // セッション開始
    Session::set('user_id', $user['id']);
    
    Response::success([
        'user_id' => $user['id'],
        'email' => $user['email'],
        'name' => $user['name'],
        'icon_url' => $user['icon_url'],
        'learning_goal' => $user['learning_goal']
    ], 'ログインしました');
}

// ログアウト
if ($method === 'POST' && $action === 'logout') {
    Session::destroy();
    Response::success([], 'ログアウトしました');
}

// 現在のユーザー情報取得
if ($method === 'GET' && $action === 'me') {
    // セッションチェック
    if (!Session::isLoggedIn()) {
        Response::error('認証が必要です', 401);
    }
    
    $userId = Session::getUserId();
    
    $user = $db->queryOne("SELECT id, email, name, icon_url, learning_goal, created_at FROM users WHERE id = ?", [$userId]);
    
    if ($user) {
        // ランキング情報も取得
        $ranking = $db->queryOne("SELECT * FROM rankings WHERE user_id = ?", [$userId]);
        
        $user['ranking'] = $ranking;
        
        Response::success($user);
    } else {
        Response::error('ユーザーが見つかりません', 404);
    }
}

// プロフィール更新
if ($method === 'PUT' && $action === 'profile') {
    $userId = requireAuth();
    
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        Response::error('Invalid JSON: ' . json_last_error_msg());
    }
    
    $name = $data['name'] ?? null;
    $iconUrl = $data['icon_url'] ?? null;
    $learningGoal = $data['learning_goal'] ?? null;
    
    $updates = [];
    $params = [];
    
    if ($name !== null) {
        $updates[] = "name = ?";
        $params[] = $name;
    }
    
    if ($iconUrl !== null) {
        $updates[] = "icon_url = ?";
        $params[] = $iconUrl;
    }
    
    if ($learningGoal !== null) {
        $updates[] = "learning_goal = ?";
        $params[] = $learningGoal;
    }
    
    if (empty($updates)) {
        Response::error('更新する項目がありません');
    }
    
    $params[] = $userId;
    $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
    
    if ($db->execute($sql, $params)) {
        Response::success([], 'プロフィールを更新しました');
    } else {
        Response::error('更新に失敗しました');
    }
}

// どのアクションにも該当しない場合
Response::error('無効なリクエストです: Method=' . $method . ', Action=' . $action, 400);
