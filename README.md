# 家族カレンダー（AutumnFamilyCalendar）

Google TV に時計・年月日（曜日）・天気・雨雲レーダー・家族の予定を表示し、家族それぞれの iPhone から予定を登録する。
置き場所は `https://aitakt.gardenas.net/family/`。

## ページ

| URL | 使う人・使い方 |
|---|---|
| `/family/` | iPhone で予定の登録・修正・削除、家族メンバー（名前・色）の設定。Safari の共有ボタン →「ホーム画面に追加」でアプリのように使える |
| `/family/tv.php?key=…` | テレビ表示（左上に「制作：秋祭り実行委員会」と流れるニュース、時計・天気・雨雲・14 日分の予定。色は朝＝空色・昼＝白・夕方〜夜＝生成り）。時計は毎秒、予定は 1 分ごと、天気は 30 分ごと、雨雲レーダーは 5 分ごとに更新。23〜6 時は暗く、5 分ごとに少しずらして焼き付きを防ぐ |
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

- 天気・雨雲：気象庁（天気予報、降水ナウキャスト、降水短時間予報）
- 地図：国土地理院（淡色地図）
- ニュース：NHK ニュースの RSS（取れないときは Yahoo!ニュース）

## テレビをつなぐ

- テレビ（アプリやブラウザ）で鍵なしの `/family/tv.php` を開くと 6 桁の番号が出る
- iPhone の ⚙ →「テレビをつなぐ」にその番号を入れると、テレビに家族カレンダーが出る（鍵はテレビに保存され、次からはそのまま開く）
- 番号は 10 分で変わる。鍵（`FAMILY_TV_KEY`）を変えると、テレビはまた番号の画面になる

## Google TV アプリ（`tv-app/`）

`tv.php` を全画面で出し続けるだけの Android TV アプリ（Java、外部ライブラリなし）。画面は消さない（スクリーンセーバーにならない）、つながらなければ 30 秒ごとにやり直す。
リモコンの決定ボタン＝読み直し、決定ボタン長押し＝新しい版を確かめる、戻るボタン 2 回＝終了。

画面の見た目や中身の変更は `family/` を送るだけでよい（アプリの入れ直しはいらない）。アプリ自体を変えたときだけ下の「更新」をする。

### 署名の鍵（なくさないこと）

- `tv-app/familytv-release.p12` と `tv-app/keystore.properties`（パスワード入り）。どちらもコミットしない
- 同じ鍵で作った版でないと上書き更新できない。なくしたらアンインストールして入れ直す（テレビのつなぎ直しも必要）ので、別の場所にも控えておく

### 初めて入れる（Chromecast with Google TV）

1. 設定 → システム → デバイス情報 →「Android TV OS ビルド」を 7 回押す（開発者向けオプションが出る）
2. Google Play で「Downloader」（AFTVnews）を入れる
3. Downloader で `https://aitakt.gardenas.net/family/familytv.apk` を開いてインストール（許可を聞かれたら「設定」から Downloader を ON）
4. アプリ一覧から「家族カレンダー」を開き、ホーム画面のお気に入りに追加する

### 更新（2 回目から）

1. `tv-app/app/build.gradle` の `versionCode` を 1 つ上げ、`versionName` も変える
2. `cd tv-app; .\gradlew.bat publishApk`（`family/familytv.apk` と `family/familytv.json` ができる）
3. `.\deploy.ps1 -Files "family/familytv.apk","family/familytv.json"`
4. テレビに「新しい版があります」と出る（起動時と 6 時間ごとに確かめる。すぐなら決定ボタンを長押し）→「更新する」
   - 初回だけ「家族カレンダーからのインストール」を許可する画面が出るので ON にして戻る

### 電源を入れたときに自動で開く（任意）

パソコンから `adb connect テレビのIP:5555` のあと `adb shell appops set net.gardenas.aitakt.familytv SYSTEM_ALERT_WINDOW allow`

## LINE から登録

LINE 公式アカウント（ボット）を家族が友だち追加し、トークのメニューから LINE の中で登録画面を開く。
最初の 1 回だけ家族のパスワードと「自分はだれか」を入れる（`fam_line_users`）。次からは LINE のアカウントでそのまま開く。
ボットに「今日」「明日」「今週」と送ると予定を返事する（返信なので無料。グループではその言葉にだけ答える）。

### つくりかた

1. **LINE 公式アカウント**: https://entry.line.biz/ で作る（無料のコミュニケーションプラン）
2. **Messaging API**: LINE Official Account Manager → 設定 → Messaging API →「Messaging API を利用する」（プロバイダーは新しく「家族カレンダー」などで作る）
3. **LINE Developers**（https://developers.line.biz/console/）でそのチャネルを開く
   - チャネル基本設定 → **チャネルシークレット** → `FAMILY_LINE_CHANNEL_SECRET`
   - Messaging API 設定 → **チャネルアクセストークン（長期）**を発行 → `FAMILY_LINE_ACCESS_TOKEN`
   - Messaging API 設定 → Webhook URL に `https://aitakt.gardenas.net/family/line_webhook.php`、「Webhook の利用」を ON
   - 家族のグループでも使うなら「グループトーク・複数人トークへの参加を許可する」を ON
4. Official Account Manager → 設定 → 応答設定 で「応答メッセージ」を OFF、「Webhook」を ON（自動の決まり文句を止める）
5. **LINE ログインチャネル**: LINE Developers の同じプロバイダーで「新規チャネル作成」→ LINE ログイン（アプリタイプ＝ウェブアプリ）
   - チャネル基本設定 → **チャネル ID** → `FAMILY_LINE_LOGIN_CHANNEL_ID`
   - LIFF → 追加: サイズ＝Full、エンドポイント URL＝`https://aitakt.gardenas.net/family/liff.php`、Scope＝`openid` と `profile` → **LIFF ID** → `FAMILY_LIFF_ID`
   - チャネルを「**公開済み**」にする（開発中のままだと作った人しか使えない）
6. `family/config.local.php` に 4 つの値を書いて送る。Messaging API 設定の Webhook URL の「検証」で「成功」と出れば OK
7. **リッチメニュー**（トークの下のボタン）: Official Account Manager → リッチメニュー → 作成
   - テンプレート＝「小」の横 3 分割（2500×843）、画像＝`tools/line_richmenu.png`
   - A＝リンク `https://liff.line.me/（LIFF ID）`、B＝テキスト「今日」、C＝テキスト「今週」、表示期間を長く、メニューの初期状態＝表示
8. 家族に公式アカウントの QR コード（Official Account Manager → ホーム → 友だちを増やす）で友だち追加してもらう

## これから

- LINE で予定の前日にお知らせ（プッシュは月 200 通まで無料）
