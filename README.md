# linglue
Linglue — ハンズフリー英単語学習アプリ
概要

Linglueはスマートフォン最適化された、ハンズフリー英単語学習システムです。フロントはHTML/CSS/JavaScript、バックエンドはPHP、データ保存はMySQLで実装します。主な特徴は自動音声読み上げ、学習制御（再生速度、出力モード）、終了時の自動テスト移行、学習状況の永続化など。

目次

すばやく始める（セットアップ）

ファイル構成

データベース設計（SQL）

主要機能ごとのAPIと処理フロー

フロントエンド（HTML/JS）: 音声制御、UI例

バックエンド（PHP）: セッション／API／テスト生成

配備と注意点

拡張案

1) すばやく始める

リポジトリをクローン

MySQL にデータベース linglue を作成

下の schema.sql を実行してテーブルを作成

config.php にDB情報とベースURLを設定

ウェブサーバー（XAMPP / LAMP / Xserver）で公開ディレクトリに配置

ブラウザ（スマホ推奨）で index.php にアクセス

2) ファイル構成（推奨）
linglue/
├─ public/
│  ├─ index.php
│  ├─ app.js
│  ├─ app.css
│  ├─ tts.js
│  ├─ assets/
│  └─ test.html
├─ api/
│  ├─ auth.php
│  ├─ api.php
│  └─ words.php
├─ includes/
│  ├─ config.php
│  └─ db.php
├─ migrations/
│  └─ schema.sql
└─ README.md
3) データベース設計（schema.sql）
CREATE DATABASE IF NOT EXISTS linglue DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE linglue;


-- 単語テーブル
CREATE TABLE words (
  id INT AUTO_INCREMENT PRIMARY KEY,
  word_en VARCHAR(200) NOT NULL,
  word_jp VARCHAR(200) NOT NULL,
  level INT NOT NULL DEFAULT 1,
  category VARCHAR(100) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- ユーザー／学習セッション
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(100) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


CREATE TABLE sessions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  current_index INT NOT NULL DEFAULT 0,
  settings JSON DEFAULT NULL,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;


-- 学習履歴（間違えた単語や通し回数）
CREATE TABLE study_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  word_id INT NOT NULL,
  result TINYINT(1) NOT NULL, -- 1: correct, 0: wrong
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (word_id) REFERENCES words(id) ON DELETE CASCADE
) ENGINE=InnoDB;
4) 主要機能ごとのAPIと処理フロー
4.1 単語取得 /api/words.php?action=get&count=20&level=2

入力: count, level, category

出力: JSON 配列 (id, word_en, word_jp)

ロジック: 指定条件でランダム抽出 or 順次取得（復習モードあり）

4.2 学習進捗保存 /api/api.php?action=save_progress

POST: user_id, session_id, current_index, settings(JSON)

セッションテーブルを更新

4.3 テスト生成 /api/api.php?action=generate_test

学習済み単語からテスト（正解 + 3つのダミー）を作成

ダミーは同じlevelからランダム選択

出題数: ceil(learned_count * 1.2)

5) フロントエンド（核心ファイル）
index.php (public)

シンプルなコントロールUI:

開始 / 一時停止 / スキップ

再生速度スライダー (0.5〜2.0)

出力モード (左耳/右耳/両耳)

単語数・レベル選択

学習中、音声は tts.js を通じて再生

他プレイヤーが回答中の表示（将来：WebSocket）

tts.js — Web Speech API のラッパー

機能:

単語を英語で、訳を日本語で読み上げ

再生速度指定

ステレオ・片耳の切替（AudioContext + panner）

一時停止 / 再開 / スキップ

サンプル（主要関数の概念）:

// speak(text, lang, rate, ear)
// ear: 'left' | 'right' | 'both'
6) バックエンド（PHP：includes/db.php, includes/config.php）

db.php は PDO 接続ラッパーを提供

auth.php で簡単なログイン / ユーザ管理

api.php は action パラメータでルーティング

サンプル（PDO接続の概念）:

function getDB(){
  static $pdo = null;
  if($pdo) return $pdo;
  $cfg = require __DIR__ . '/config.php';
  $dsn = "mysql:host={$cfg['host']};dbname={$cfg['db']};charset=utf8mb4";
  $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
  return $pdo;
}
7) 配備と注意点

Web Speech API はブラウザ依存（Chrome / Edge 推奨）。モバイルブラウザでの検証を必須に。

ステレオ出力は AudioContext と PannerNode を使う実装を推奨（Web Speech API の直接ステレオ制御は限界がある）

Xserver 等の共有ホスティングでは長時間の音声処理はクライアント側（ブラウザ）で完結させる。

8) 拡張案（短め）

WebSocket（リアルタイムで他のプレイヤーの回答を見る）

カスタム音声ファイル（SSML）やTTSクラウドサービス連携

スペースド・リピティション（SRS）アルゴリズム

モバイル向けPWA化、オフライン学習

付録：コア実装例（フロント：tts.js の具体例）
// tts.js - 単純版
class LinglueTTS {
  constructor(){
    this.synth = window.speechSynthesis;
    this.queue = [];
    this.rate = 1.0;
    this.current = null;
  }


  speakText(text, lang='en-US', rate=1.0){
    if(!('speechSynthesis' in window)) return Promise.reject('no tts');
    return new Promise((resolve)=>{
      const u = new SpeechSynthesisUtterance(text);
      u.lang = lang;
      u.rate = rate;
      u.onend = ()=> resolve();
      this.synth.speak(u);
    });
  }


  async speakPair(wordEn, wordJp){
    await this.speakText(wordEn, 'en-US', this.rate);
    await new Promise(r=>setTimeout(r, 250));
    await this.speakText(wordJp, 'ja-JP', this.rate);
  }
}
付録：簡易テスト生成ロジック（PHP）
function generate_test($pdo, $user_id, $learned_ids){
  // learned_ids: array of word ids user just learned
  $count = max(1, ceil(count($learned_ids) * 1.2));
  $questions = [];
  // fetch words for pool of same level
  foreach($learned_ids as $id){
    $stmt = $pdo->prepare('SELECT id, word_en, word_jp, level FROM words WHERE id = ?');
    $stmt->execute([$id]);
    $w = $stmt->fetch(PDO::FETCH_ASSOC);
    if(!$w) continue;
    // pick 3 random wrongs from same level
    $stmt2 = $pdo->prepare('SELECT id, word_en, word_jp FROM words WHERE level = ? AND id != ? ORDER BY RAND() LIMIT 3');
    $stmt2->execute([$w['level'], $w['id']]);
    $wrongs = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    $choices = array_merge([['id'=>$w['id'],'word_jp'=>$w['word_jp']]], $wrongs);
    shuffle($choices);
    $questions[] = ['question_word'=>$w['word_en'],'choices'=>$choices,'answer_id'=>$w['id']];
    if(count($questions) >= $count) break;
  }
  return $questions;
}
終わりに — 配慮

このドキュメントは最小限のMVP（実装例）を提供しています。より完成度を上げるために、UIデザイン、アクセシビリティ、音声品質の確認、ブラウザごとの互換性テストが必要です。

次のステップの提案（このドキュメントを見た上で行うこと）

希望のUIイメージ（色使い・フォント・スクリーン例）を1つ指定してください。

優先実装機能の順序（例: 1.ハンズフリー再生 2.終了テスト 3.SRS）を決めてください。

実装用の具体的ファイルを1つずつ出力できます（例: 完成度の高い index.php と tts.js）。
