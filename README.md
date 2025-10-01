Linglue - スマート英単語学習アプリ
📁 ファイル構成
Linglue/
├── index.html          # メインアプリケーション
├── config.php          # 設定ファイル
├── api/
│   ├── auth.php       # 認証API
│   ├── study.php      # 学習API
│   ├── test.php       # テストAPI
│   └── social.php     # ランキング・フレンドAPI
└── database/
    └── schema.sql     # データベーススキーマ
🚀 セットアップ手順
1. データベース作成
MySQLにログインして、データベースとテーブルを作成します：
bashmysql -u root -p < database/schema.sql
または、phpMyAdminから schema.sql をインポートしてください。
2. データベース設定
config.php を開き、データベース接続情報を編集します：
phpdefine('DB_HOST', 'localhost');      // データベースホスト
define('DB_NAME', 'linglue');        // データベース名
define('DB_USER', 'root');           // ユーザー名
define('DB_PASS', '');               // パスワード
3. ファイルのアップロード
すべてのファイルをサーバーにアップロードします：
/public_html/Linglue/
├── index.html
├── config.php
└── api/
    ├── auth.php
    ├── study.php
    ├── test.php
    └── social.php
4. パーミッション設定
セッションを保存するため、適切なパーミッションを設定：
bashchmod 755 api/
chmod 644 api/*.php
chmod 644 config.php
chmod 644 index.html
5. .htaccess 設定（オプション）
セキュリティ向上のため、.htaccess を作成：
apache# config.phpへの直接アクセスを禁止
<Files "config.php">
    Order Allow,Deny
    Deny from all
</Files>

# セッション設定
php_value session.cookie_httponly 1
php_value session.cookie_secure 0
php_value session.use_strict_mode 1

# エラー表示（本番環境では無効化）
php_flag display_errors off
php_flag display_startup_errors off
🔧 トラブルシューティング
エラー: "401 Unauthorized"
原因: セッションが正しく動作していない
解決方法:

config.php でセッションが開始されているか確認
サーバーのセッション保存ディレクトリに書き込み権限があるか確認
ブラウザのコンソールで Cookie が保存されているか確認

エラー: "400 Bad Request" (登録時)
原因: JSONデータが正しく送信されていない
解決方法:

ブラウザの開発者ツールで Network タブを確認
auth.php のエラーログを確認（デバッグモードを有効化）
Content-Type: application/json ヘッダーが送信されているか確認

エラー: "データベース接続エラー"
原因: データベース設定が間違っている
解決方法:

config.php の DB_* 定数を確認
MySQLが起動しているか確認
ユーザーに適切な権限があるか確認

sqlGRANT ALL PRIVILEGES ON linglue.* TO 'root'@'localhost';
FLUSH PRIVILEGES;
セッションが保持されない
原因: クロスドメインまたはCookie設定の問題
解決方法:

HTTPSを使用している場合、session.cookie_secure を1に設定
index.html のAPIパスが正しいか確認
credentials: 'include' がすべてのfetchリクエストに含まれているか確認

🎯 動作確認
1. アクセステスト
ブラウザで以下にアクセス：
https://your-domain.com/Linglue/
2. API動作確認
ブラウザの開発者ツール（F12）のコンソールで以下を実行：
javascript// 認証チェック
fetch('./api/auth.php?action=me', {
    credentials: 'include'
})
.then(r => r.json())
.then(console.log);

// 単語取得テスト
fetch('./api/study.php?action=words&limit=5', {
    credentials: 'include'
})
.then(r => r.json())
.then(console.log);
3. 新規登録テスト

新規登録画面で情報を入力
登録ボタンをクリック
自動的にホーム画面に遷移することを確認

4. 学習フローテスト

「新しい単語を学習」をクリック
音声が再生されることを確認（ブラウザの音声許可が必要）
「学習を終了してテストへ」をクリック
テスト問題が表示されることを確認
回答後、結果画面が表示されることを確認

📊 機能一覧
✅ 実装済み機能

 ユーザー登録・ログイン
 単語学習（音声再生）
 ハンズフリー操作
 テスト機能（4択問題）
 復習システム（忘却曲線）
 学習進捗管理
 ランキング機能
 連続学習日数
 レスポンシブデザイン

🔄 今後の拡張可能性

 フレンド機能の実装
 SNSログイン（Google, Facebook）
 プッシュ通知
 オフライン対応（PWA）
 単語帳のカスタマイズ
 学習統計グラフ
 ダークモード

🎨 カスタマイズ
メインカラー変更
index.html の CSS 変数を編集：
css:root {
    --main-color: #83db41;  /* メインカラー */
    --main-dark: #6bb831;   /* ホバー時の色 */
}
単語データの追加
schema.sql に単語を追加するか、データベースに直接INSERT：
sqlINSERT INTO words (word_en, word_jp, level, category) VALUES
('example', '例', 1, 'general'),
('beautiful', '美しい', 2, 'adjective');
復習スケジュールのカスタマイズ
config.php で復習間隔を変更：
phpdefine('REVIEW_SCHEDULE', [1, 3, 7, 14, 30]); // 日数の配列
🔒 セキュリティ対策
本番環境での推奨設定

エラー表示を無効化

php// config.php
error_reporting(0);
ini_set('display_errors', 0);

HTTPS を使用
パスワードの強度チェックを追加
レート制限の実装
SQLインジェクション対策（準備済みステートメント使用済み）
XSS対策（HTMLエスケープ）

📱 対応ブラウザ

✅ Chrome 90+
✅ Firefox 88+
✅ Safari 14+
✅ Edge 90+
✅ iOS Safari 14+
✅ Android Chrome 90+

💡 使用技術

フロントエンド: HTML5, CSS3, JavaScript (ES6+)
音声合成: Web Speech API
バックエンド: PHP 7.4+
データベース: MySQL 5.7+ / MariaDB 10.3+
セッション管理: PHP Sessions

📄 ライセンス
このプロジェクトは教育目的で作成されました。
🤝 サポート
問題が発生した場合は、以下を確認してください：

ブラウザのコンソールでエラーを確認
PHPのエラーログを確認
データベース接続情報が正しいか確認


開発者: Claude (Anthropic)
バージョン: 1.0.0
作成日: 2025年10月
