# AutumnFamilyCalendar — 家族カレンダー

自宅の Google TV（Chromecast with Google TV）に時計・年月日（曜日）・天気・雨雲レーダー・家族の予定を表示し、
家族それぞれの iPhone（Safari、のちに LINE の LIFF）から予定を登録する個人用のシステム。ガーデナスの業務とは無関係。

## ローカル作業ディレクトリ

`C:\Users\sky_a\AutumnFamliyCarlender`

## 公開 URL

- `https://aitakt.gardenas.net/family/`（サブドメインに空きがないので仮にここ）
- テレビ: `https://aitakt.gardenas.net/family/tv.php?key=…`

## 構成

- `family/` — サーバーに置く PHP 一式（PHP 8 / PDO / MySQL。手元では SQLite でも動く）
  - `index.php` + `app.js` / `app.css` — iPhone 画面（登録フォーム・月表示・メンバー設定）
  - `tv.php` + `tv.js` / `tv.css` — テレビ表示（16:9、vw/vh で大きさを決める）
  - `api.php` — 読む＝ログインかテレビの鍵、書く＝ログインのみ
  - `ics.php` — iPhone のカレンダー購読用（見るだけ）
  - `lib/family.php` — DB（`fam_members` / `fam_events`、初回に自動作成）・ログイン・繰り返しの展開・祝日計算・天気・雨雲レーダー
  - `config.php`（コミットする）が `config.local.php`（コミットしない）を読む
- `tv-app/` — Android TV アプリ（Java・外部ライブラリなし。tv.php を全画面 WebView で表示し続ける。開く URL は `app/build.gradle` の `TV_URL`）
  - 自分で更新する: `familytv.json`（`publishApk` が書く）の versionCode が大きければ聞いて、APK を SHA-256 で確かめて PackageInstaller に渡す（`Updater.java`）
  - 署名の鍵 `tv-app/familytv-release.p12` ＋ `tv-app/keystore.properties` はコミットしない。鍵が変わると上書き更新できない
- テレビのつなぎ方: 鍵なしの tv.php は 6 桁の番号を出す（`fam_pairs`、10 分で失効）→ iPhone の ⚙ で番号を入れる（`pair_approve`）→ テレビに鍵を渡し localStorage に保存。アプリにもリポジトリにも鍵は入れない

## デプロイ

```powershell
.\deploy.ps1                        # family フォルダの全部
.\deploy.ps1 -Files "family/tv.js"  # 指定したものだけ
```

- `deploy.example.ps1` を `deploy.ps1` にコピーし、BackYard のデプロイ用トークンを入れる
- BackYard の `api/deploy_receiver.php` が `aitakt/` で始まるパスを aitakt のフォルダへ書き込む

## 注意

- **このリポジトリは公開**。パスワード・鍵・トークン（`config.local.php`、`deploy.ps1`）は絶対にコミットしない
- 天気は気象庁の予報 JSON（1 時間キャッシュ）、雨雲は気象庁の降水ナウキャストのタイル（時刻一覧はサーバー経由、画像はブラウザが直接読む）、地図は国土地理院の淡色地図。画面に出典「気象庁・国土地理院」を出す
- 祝日は `fam_holidays()` で計算（2020 年以降の法律。特例の年は対応していない）
