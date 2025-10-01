<?php
// config.php - Linglue 設定ファイル

// データベース設定
define('DB_HOST', 'localhost');
define('DB_NAME', 'xs163907_linglue');
define('DB_USER', 'xs163907_kojima');
define('DB_PASS', 'Keito0805');
define('DB_CHARSET', 'utf8mb4');

// アプリケーション設定
define('APP_NAME', 'Linglue');
define('APP_VERSION', '1.0.0');
define('MAIN_COLOR', '#83db41ff');

// セッション設定
define('SESSION_LIFETIME', 86400); // 24時間

// 復習スケジュール設定（日数）
define('REVIEW_SCHEDULE', [1, 3, 7, 14, 30]);

// 出題最適化設定
define('LOW_ACCURACY_THRESHOLD', 50);
define('HIGH_ACCURACY_THRESHOLD', 80);
define('LOW_ACCURACY_MULTIPLIER', 3);
define('MEDIUM_ACCURACY_MULTIPLIER', 1.5);
define('HIGH_ACCURACY_DIVISOR', 2);
define('MASTERY_THRESHOLD', 5); // 連続正解回数

// テスト設定
define('TEST_QUESTION_MULTIPLIER', 1.2); // 学習単語数の120%

// タイムゾーン
date_default_timezone_set('Asia/Tokyo');

// エラー報告（開発環境）
error_reporting(E_ALL);
ini_set('display_errors', 1);

// データベース接続クラス
class Database {
    private static $instance = null;
    private $conn;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $this->conn = new PDO($dsn, DB_USER, DB_PASS);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            die("データベース接続エラー: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->conn;
    }
    
    // 複数行取得
    public function query($sql, $params = []) {
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch(PDOException $e) {
            error_log("Query error: " . $e->getMessage());
            return false;
        }
    }
    
    // 単一行取得
    public function queryOne($sql, $params = []) {
        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch(PDOException $e) {
            error_log("Query error: " . $e->getMessage());
            return false;
        }
    }
    
    // 実行のみ
    public function execute($sql, $params = []) {
        try {
            $stmt = $this->conn->prepare($sql);
            return $stmt->execute($params);
        } catch(PDOException $e) {
            error_log("Execute error: " . $e->getMessage());
            return false;
        }
    }
    
    // 最後に挿入されたID
    public function lastInsertId() {
        return $this->conn->lastInsertId();
    }
}

// レスポンスヘルパー
class Response {
    public static function json($data, $status = 200) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    public static function success($data = [], $message = 'Success') {
        self::json([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
    }
    
    public static function error($message = 'Error', $status = 400) {
        self::json([
            'success' => false,
            'message' => $message
        ], $status);
    }
}

// セッション管理
class Session {
    public static function start() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    public static function set($key, $value) {
        self::start();
        $_SESSION[$key] = $value;
    }
    
    public static function get($key, $default = null) {
        self::start();
        return $_SESSION[$key] ?? $default;
    }
    
    public static function has($key) {
        self::start();
        return isset($_SESSION[$key]);
    }
    
    public static function destroy() {
        self::start();
        session_destroy();
    }
    
    public static function getUserId() {
        return self::get('user_id');
    }
    
    public static function isLoggedIn() {
        return self::has('user_id');
    }
}

// 認証ミドルウェア
function requireAuth() {
    if (!Session::isLoggedIn()) {
        Response::error('認証が必要です', 401);
    }
    return Session::getUserId();
}

// セッション開始（すべてのリクエストで必要）
Session::start();

// CORS設定（必要に応じて）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}
