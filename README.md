# 家族カレンダー（AutumnFamilyCalendar）

Google TV に時計・年月日（曜日）・天気・雨雲レーダー・家族の予定を表示し、家族それぞれの iPhone から予定を登録する。
置き場所は `https://aitakt.gardenas.net/family/`。

## ページ

| URL | 使う人・使い方 |
|---|---|
| `/family/` | iPhone で予定の登録・修正・削除、家族メンバー（名前・色）の設定。Safari の共有ボタン →「ホーム画面に追加」でアプリのように使える |
| `/family/tv.php?key=…` | テレビ表示。時計は毎秒、予定は 1 分ごと、天気は 30 分ごと、雨雲レーダーは 5 分ごとに更新。23〜6 時は暗く、5 分ごとに少しずらして焼き付きを防ぐ |
| `/family/ics.php?key=…` | iPhone の「カレンダー」で購読する用（見るだけ）。設定 → カレンダー → アカウント → アカウントを追加 → その他 → 照会するカレンダーを追加 |
| `/family/api.php` | 画面が使う API。読むのはログインかテレビの鍵、書くのはログインしたときだけ |

## はじめて置くとき

1. BackYard 側で、新しい受け取り口を送っておく（BackYard のフォルダで `.\deploy.ps1 -Files "api/deploy_receiver.php"`）
2. このフォルダで `deploy.example.ps1` を `deploy.ps1` にコピーし、`$TOKEN` に BackYard のデプロイ用トークンを入れる
3. `family/config.example.php` を `family/config.local.php` にコピーして値を入れる
   - DB（MySQL）。テーブル `fam_members` / `fam_events` は初回アクセスで自動作成
   - 家族のパスワード・`FAMILY_SECRET`（ログインの署名用）・`FAMILY_TV_KEY`（テレビ・購読 URL の鍵）は、`.\tools\make-keys.ps1` を実行して出た 3 行で置き換える（パスワードはそのままは書かない）
   - 天気の地域 `FAMILY_WEATHER_AREA`（気象庁の府県予報区コード。岡山県=330000）
   - 雨雲レーダーの中心 `FAMILY_RADAR_LAT` / `FAMILY_RADAR_LON`（自宅のだいたいの位置）と縮尺 `FAMILY_RADAR_ZOOM`（7〜10）
4. `.\deploy.ps1` で family フォルダを全部送る
   - `site not found: aitakt (same level as BackYard: …)` と出たら、aitakt のフォルダが BackYard と同じ階層にない。BackYard の `config.local.php` に `define('BACKYARD_DEPLOY_AITAKT_ROOT', '（aitakt のフォルダの絶対パス）');` を足す
5. iPhone で `/family/` を開いてパスワードを入れ、⚙ から家族のメンバーを登録する（一度ログインすると 400 日保つ）
6. テレビで `/family/tv.php?key=（FAMILY_TV_KEY）` を開く

## 手元で動かして確かめる

`family/config.local.php` の `FAMILY_DB_DSN` を `sqlite:/どこか/family.db` にすると MySQL なしで動く。
このフォルダで `php -S 127.0.0.1:8000` を実行し、`http://127.0.0.1:8000/family/` を開く。

## 予定の決まり

- 時刻なし＝終日。最終日を入れると複数日の予定（旅行など）
- 「だれの予定」を選ばなければ家族全員
- 繰り返しは 毎週 / 毎月（同じ日。31 日がない月は飛ばす）/ 毎年。修正・削除はすべての回にかかる
- 祝日（振替休日・国民の休日を含む）はプログラムで計算している

## 出典

- 天気・雨雲：気象庁（天気予報、降水ナウキャスト）
- 地図：国土地理院（淡色地図）

## テレビをつなぐ

- テレビ（アプリやブラウザ）で鍵なしの `/family/tv.php` を開くと 6 桁の番号が出る
- iPhone の ⚙ →「テレビをつなぐ」にその番号を入れると、テレビに家族カレンダーが出る（鍵はテレビに保存され、次からはそのまま開く）
- 番号は 10 分で変わる。鍵（`FAMILY_TV_KEY`）を変えると、テレビはまた番号の画面になる

## Google TV アプリ（`tv-app/`）

`tv.php` を全画面で出し続けるだけの Android TV アプリ（Java、外部ライブラリなし）。画面は消さない（スクリーンセーバーにならない）、つながらなければ 30 秒ごとにやり直す。
リモコンの決定ボタン＝読み直し、戻るボタン 2 回＝終了。

- 組み立て: Android Studio で `tv-app` を開く、または `cd tv-app; .\gradlew.bat assembleDebug`（できるのは `app/build/outputs/apk/debug/app-debug.apk`）
- Chromecast with Google TV に入れる:
  1. 設定 → システム → デバイス情報 →「Android TV OS ビルド」を 7 回押す（開発者向けオプションが出る）
  2. Google Play で「Downloader」（AFTVnews）を入れる
  3. 設定 → アプリ → セキュリティと制限 → 提供元不明のアプリ で Downloader を ON
  4. Downloader で APK の URL（例: `https://aitakt.gardenas.net/family/familytv.apk`）を開いてインストール
  5. アプリ一覧から「家族カレンダー」を開き、ホーム画面のお気に入りに追加する
- 電源を入れたときに自動で開く（任意）: パソコンから `adb connect テレビのIP:5555` のあと
  `adb shell appops set net.gardenas.aitakt.familytv SYSTEM_ALERT_WINDOW allow`
- 作り直した APK が「インストールできません」となったら、署名が違うので一度アンインストールしてから入れる（つなぎ直しが必要）

## これから

- LINE：公式アカウント＋LIFF で、LINE の中からこの登録フォームを開く
