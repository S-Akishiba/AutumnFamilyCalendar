<?php
declare(strict_types=1);

// 家族カレンダーの共通処理（DB・ログイン・予定の展開・祝日・天気）

require_once dirname(__DIR__) . '/config.php';

date_default_timezone_set('Asia/Tokyo');

const FAM_COOKIE = 'fam_auth';
const FAM_COOKIE_DAYS = 400;
const FAM_REPEATS = ['' => 'なし', 'weekly' => '毎週', 'monthly' => '毎月', 'yearly' => '毎年'];
const FAM_COLORS = ['#e8505b', '#2f80ed', '#27ae60', '#f2994a', '#9b51e0', '#00a8a8', '#d4a017', '#8d6e63'];
const FAM_WEEKDAYS = ['日', '月', '火', '水', '木', '金', '土'];

// ── DB ────────────────────────────────────────────────────

function fam_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(FAMILY_DB_DSN, FAMILY_DB_USER, FAMILY_DB_PASSWORD, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        fam_ensure_tables($pdo);
    }
    return $pdo;
}

function fam_ensure_tables(PDO $pdo): void
{
    // LINE で使う人（最初に家族のパスワードを入れた LINE アカウント。member_id は「だれの予定」の既定）
    $pdo->exec('CREATE TABLE IF NOT EXISTS fam_line_users (
        user_id VARCHAR(64) NOT NULL PRIMARY KEY, display_name VARCHAR(100) NOT NULL DEFAULT \'\',
        member_id INT NULL, created_at INT NOT NULL)');
    // テレビをつなぐときの 6 桁の番号（SQLite・MySQL 共通の書き方）
    $pdo->exec('CREATE TABLE IF NOT EXISTS fam_pairs (
        code VARCHAR(6) NOT NULL PRIMARY KEY, token VARCHAR(64) NOT NULL,
        approved INT NOT NULL DEFAULT 0, created_at INT NOT NULL)');
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('CREATE TABLE IF NOT EXISTS fam_members (
            id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, color TEXT NOT NULL,
            line_user_id TEXT NULL, sort INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS fam_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, member_ids TEXT NOT NULL DEFAULT \'\',
            start_date TEXT NOT NULL, end_date TEXT NOT NULL, start_time TEXT NULL, end_time TEXT NULL,
            repeat_rule TEXT NOT NULL DEFAULT \'\', repeat_until TEXT NULL, note TEXT NOT NULL DEFAULT \'\',
            source TEXT NOT NULL DEFAULT \'web\', created_at TEXT NOT NULL, updated_at TEXT NOT NULL)');
        return;
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS fam_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL,
        color VARCHAR(7) NOT NULL,
        line_user_id VARCHAR(64) NULL,
        sort INT NOT NULL DEFAULT 0
    ) DEFAULT CHARSET=utf8mb4');
    $pdo->exec('CREATE TABLE IF NOT EXISTS fam_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(200) NOT NULL,
        member_ids VARCHAR(200) NOT NULL DEFAULT \'\' COMMENT \'カンマ区切りの fam_members.id。空＝家族全員\',
        start_date DATE NOT NULL,
        end_date DATE NOT NULL COMMENT \'複数日の予定の最終日（1日だけなら start_date と同じ）\',
        start_time TIME NULL COMMENT \'NULL＝終日\',
        end_time TIME NULL,
        repeat_rule VARCHAR(10) NOT NULL DEFAULT \'\' COMMENT \'空＝なし / weekly / monthly / yearly\',
        repeat_until DATE NULL,
        note TEXT NOT NULL,
        source VARCHAR(10) NOT NULL DEFAULT \'web\' COMMENT \'web / line\',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        KEY idx_dates (start_date, end_date)
    ) DEFAULT CHARSET=utf8mb4');
}

// ── ログイン（家族共通のパスワード。署名入りの Cookie で 400 日保つ）──

function fam_sign(string $data): string
{
    return hash_hmac('sha256', $data, FAMILY_SECRET);
}

function fam_is_configured(): bool
{
    return FAMILY_PASSWORD_HASH !== '' && FAMILY_SECRET !== '' && FAMILY_TV_KEY !== '';
}

function fam_logged_in(): bool
{
    if (!fam_is_configured()) {
        return false;
    }
    $c = $_COOKIE[FAM_COOKIE] ?? '';
    if (!preg_match('/^(\d+)\.([0-9a-f]{64})$/', $c, $m)) {
        return false;
    }
    return (int)$m[1] > time() && hash_equals(fam_sign('auth|' . $m[1]), $m[2]);
}

function fam_login(string $password): bool
{
    if (!fam_is_configured() || !fam_password_ok($password)) {
        return false;
    }
    fam_set_auth_cookie();
    return true;
}

/** ログインした印の Cookie を付ける（パスワード・LINE のどちらでログインしても同じ） */
function fam_set_auth_cookie(): void
{
    $exp = time() + FAM_COOKIE_DAYS * 86400;
    setcookie(FAM_COOKIE, $exp . '.' . fam_sign('auth|' . $exp), [
        'expires' => $exp, 'path' => '/family/', 'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => true, 'samesite' => 'Lax',
    ]);
}

/** FAMILY_PASSWORD_HASH は password_hash() の値か、PowerShell で作れる「sha256:16進」 */
function fam_password_ok(string $password): bool
{
    if (str_starts_with(FAMILY_PASSWORD_HASH, 'sha256:')) {
        return hash_equals(strtolower(substr(FAMILY_PASSWORD_HASH, 7)), hash('sha256', $password));
    }
    return password_verify($password, FAMILY_PASSWORD_HASH);
}

function fam_logout(): void
{
    setcookie(FAM_COOKIE, '', ['expires' => 1, 'path' => '/family/']);
}

function fam_key_ok(?string $key): bool
{
    return FAMILY_TV_KEY !== '' && $key !== null && hash_equals(FAMILY_TV_KEY, $key);
}

// ── テレビをつなぐ（テレビに 6 桁の番号を出し、ログインした iPhone でその番号を入れると鍵を渡す）──

const FAM_PAIR_TTL = 600;

function fam_pair_cleanup(): void
{
    fam_pdo()->prepare('DELETE FROM fam_pairs WHERE created_at < ?')->execute([time() - FAM_PAIR_TTL]);
}

/** 番号と、テレビだけが知る合言葉（token）を作る。使われていない番号が多すぎるときは null */
function fam_pair_start(): ?array
{
    fam_pair_cleanup();
    $pdo = fam_pdo();
    if ((int)$pdo->query('SELECT COUNT(*) FROM fam_pairs')->fetchColumn() >= 20) {
        return null;
    }
    $token = bin2hex(random_bytes(32));
    for ($i = 0; $i < 10; $i++) {
        $code = (string)random_int(100000, 999999);
        try {
            $pdo->prepare('INSERT INTO fam_pairs (code, token, approved, created_at) VALUES (?, ?, 0, ?)')
                ->execute([$code, $token, time()]);
            return ['code' => $code, 'token' => $token, 'expires_in' => FAM_PAIR_TTL];
        } catch (PDOException $e) {
            // 同じ番号がたまたま使われていたら作り直す
        }
    }
    return null;
}

/** 承認されていればテレビの鍵を返す（番号は使い捨て） */
function fam_pair_poll(string $code, string $token): array
{
    fam_pair_cleanup();
    $st = fam_pdo()->prepare('SELECT token, approved FROM fam_pairs WHERE code = ?');
    $st->execute([$code]);
    $row = $st->fetch();
    if (!$row || !hash_equals((string)$row['token'], $token)) {
        return ['status' => 'expired'];
    }
    if (!(int)$row['approved']) {
        return ['status' => 'waiting'];
    }
    fam_pdo()->prepare('DELETE FROM fam_pairs WHERE code = ?')->execute([$code]);
    return ['status' => 'ok', 'key' => FAMILY_TV_KEY];
}

function fam_pair_approve(string $code): bool
{
    fam_pair_cleanup();
    $st = fam_pdo()->prepare('UPDATE fam_pairs SET approved = 1 WHERE code = ?');
    $st->execute([$code]);
    return $st->rowCount() > 0;
}

// ── 家族メンバー ──────────────────────────────────────────

function fam_members(): array
{
    return fam_pdo()->query('SELECT id, name, color, sort FROM fam_members ORDER BY sort, id')->fetchAll();
}

/** 画面から受け取った一覧（id があれば更新、なければ追加、載っていないものは削除）で入れ替える */
function fam_members_save(array $rows): void
{
    $pdo = fam_pdo();
    $pdo->beginTransaction();
    $keep = [];
    foreach (array_values($rows) as $i => $r) {
        $name = trim((string)($r['name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $name = mb_substr($name, 0, 50);
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($r['color'] ?? '')) ? $r['color'] : FAM_COLORS[$i % count(FAM_COLORS)];
        $id = (int)($r['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare('UPDATE fam_members SET name = ?, color = ?, sort = ? WHERE id = ?')->execute([$name, $color, $i, $id]);
        } else {
            $pdo->prepare('INSERT INTO fam_members (name, color, sort) VALUES (?, ?, ?)')->execute([$name, $color, $i]);
            $id = (int)$pdo->lastInsertId();
        }
        $keep[] = $id;
    }
    if ($keep) {
        $in = implode(',', array_map('intval', $keep));
        $pdo->exec("DELETE FROM fam_members WHERE id NOT IN ($in)");
    } else {
        $pdo->exec('DELETE FROM fam_members');
    }
    $pdo->commit();
}

// ── 予定 ──────────────────────────────────────────────────

function fam_valid_date(?string $d): bool
{
    if ($d === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        return false;
    }
    [$y, $m, $dd] = array_map('intval', explode('-', $d));
    return checkdate($m, $dd, $y);
}

function fam_norm_time(?string $t): ?string
{
    $t = trim((string)$t);
    if ($t === '' || !preg_match('/^(\d{1,2}):(\d{2})/', $t, $m) || (int)$m[1] > 23 || (int)$m[2] > 59) {
        return null;
    }
    return sprintf('%02d:%02d', $m[1], $m[2]);
}

/** 入力をそろえて検査する。おかしければ例外 */
function fam_event_clean(array $in): array
{
    $title = trim((string)($in['title'] ?? ''));
    if ($title === '') {
        throw new InvalidArgumentException('予定の内容を入れてください');
    }
    $start = (string)($in['start_date'] ?? '');
    if (!fam_valid_date($start)) {
        throw new InvalidArgumentException('日付が正しくありません');
    }
    $end = (string)($in['end_date'] ?? '');
    if (!fam_valid_date($end) || $end < $start) {
        $end = $start;
    }
    $allDay = !empty($in['all_day']);
    $st = $allDay ? null : fam_norm_time($in['start_time'] ?? null);
    $et = $st === null ? null : fam_norm_time($in['end_time'] ?? null);
    $repeat = (string)($in['repeat_rule'] ?? '');
    if (!array_key_exists($repeat, FAM_REPEATS)) {
        $repeat = '';
    }
    $until = (string)($in['repeat_until'] ?? '');
    $until = ($repeat !== '' && fam_valid_date($until) && $until >= $start) ? $until : null;
    $ids = $in['member_ids'] ?? [];
    if (is_string($ids)) {
        $ids = explode(',', $ids);
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)$ids))));
    sort($ids);
    return [
        'title' => mb_substr($title, 0, 200),
        'member_ids' => implode(',', $ids),
        'start_date' => $start,
        'end_date' => $end,
        'start_time' => $st,
        'end_time' => $et,
        'repeat_rule' => $repeat,
        'repeat_until' => $until,
        'note' => mb_substr(trim((string)($in['note'] ?? '')), 0, 2000),
    ];
}

function fam_event_save(array $in, string $source = 'web'): int
{
    $e = fam_event_clean($in);
    $pdo = fam_pdo();
    $now = date('Y-m-d H:i:s');
    $id = (int)($in['id'] ?? 0);
    if ($id > 0) {
        $st = $pdo->prepare('UPDATE fam_events SET title = ?, member_ids = ?, start_date = ?, end_date = ?, start_time = ?,
            end_time = ?, repeat_rule = ?, repeat_until = ?, note = ?, updated_at = ? WHERE id = ?');
        $st->execute([...array_values($e), $now, $id]);
        return $id;
    }
    $st = $pdo->prepare('INSERT INTO fam_events (title, member_ids, start_date, end_date, start_time, end_time, repeat_rule,
        repeat_until, note, source, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([...array_values($e), $source, $now, $now]);
    return (int)$pdo->lastInsertId();
}

function fam_event_delete(int $id): void
{
    fam_pdo()->prepare('DELETE FROM fam_events WHERE id = ?')->execute([$id]);
}

/** 期間内の予定を、繰り返しを日ごとに展開して返す（日付・時刻順） */
function fam_occurrences(string $from, string $to): array
{
    $st = fam_pdo()->prepare("SELECT * FROM fam_events
        WHERE start_date <= ? AND (end_date >= ? OR repeat_rule <> '')
        ORDER BY start_date, start_time, id");
    $st->execute([$to, $from]);
    $out = [];
    foreach ($st->fetchAll() as $ev) {
        $start = new DateTimeImmutable(substr((string)$ev['start_date'], 0, 10));
        $len = (int)$start->diff(new DateTimeImmutable(substr((string)$ev['end_date'], 0, 10)))->days;
        $until = $ev['repeat_until'] ? substr((string)$ev['repeat_until'], 0, 10) : $to;
        foreach (fam_repeat_dates($start, (string)$ev['repeat_rule'], $from, min($to, $until), $len) as $d) {
            $out[] = fam_event_out($ev, $d, $d->modify("+$len days"));
        }
    }
    usort($out, fn($a, $b) => [$a['date'], $a['start_time'] ?? '', $a['id']] <=> [$b['date'], $b['start_time'] ?? '', $b['id']]);
    return $out;
}

/** 繰り返しの開始日のうち、[from, to] にかかる（複数日なら一部でもかかる）ものを返す */
function fam_repeat_dates(DateTimeImmutable $start, string $rule, string $from, string $to, int $len): array
{
    $dates = [];
    $fromD = (new DateTimeImmutable($from))->modify("-$len days")->format('Y-m-d');
    if ($rule === '') {
        $s = $start->format('Y-m-d');
        return ($s <= $to && $s >= $fromD) ? [$start] : [];
    }
    for ($n = 0; $n < 5000; $n++) {
        $d = match ($rule) {
            'weekly' => $start->modify('+' . (7 * $n) . ' days'),
            'monthly' => fam_add_months($start, $n),
            'yearly' => fam_add_months($start, 12 * $n),
            default => null,
        };
        if ($d === null) {
            break;
        }
        if ($d === false) {
            continue; // 31日がない月・2/29 がない年は飛ばす
        }
        $s = $d->format('Y-m-d');
        if ($s > $to) {
            break;
        }
        if ($s >= $fromD) {
            $dates[] = $d;
        }
    }
    return $dates;
}

function fam_add_months(DateTimeImmutable $d, int $n): DateTimeImmutable|false
{
    $y = (int)$d->format('Y');
    $m = (int)$d->format('n') + $n;
    $y += intdiv($m - 1, 12);
    $m = ($m - 1) % 12 + 1;
    $day = (int)$d->format('j');
    return checkdate($m, $day, $y) ? $d->setDate($y, $m, $day) : false;
}

function fam_event_out(array $ev, DateTimeImmutable $d, DateTimeImmutable $end): array
{
    return [
        'id' => (int)$ev['id'],
        'title' => $ev['title'],
        'member_ids' => $ev['member_ids'] === '' ? [] : array_map('intval', explode(',', $ev['member_ids'])),
        'date' => $d->format('Y-m-d'),
        'end_date' => $end->format('Y-m-d'),
        'start_time' => $ev['start_time'] ? substr((string)$ev['start_time'], 0, 5) : null,
        'end_time' => $ev['end_time'] ? substr((string)$ev['end_time'], 0, 5) : null,
        'repeat_rule' => $ev['repeat_rule'],
        'repeat_until' => $ev['repeat_until'] ? substr((string)$ev['repeat_until'], 0, 10) : null,
        'base_start_date' => substr((string)$ev['start_date'], 0, 10),
        'base_end_date' => substr((string)$ev['end_date'], 0, 10),
        'note' => $ev['note'],
    ];
}

// ── 日本の祝日（2020 年以降の法律で計算）────────────────────

function fam_holidays(int $year): array
{
    static $cache = [];
    if (isset($cache[$year])) {
        return $cache[$year];
    }
    $h = [];
    $fixed = ['01-01' => '元日', '02-11' => '建国記念の日', '02-23' => '天皇誕生日', '04-29' => '昭和の日',
        '05-03' => '憲法記念日', '05-04' => 'みどりの日', '05-05' => 'こどもの日', '08-11' => '山の日',
        '11-03' => '文化の日', '11-23' => '勤労感謝の日'];
    foreach ($fixed as $md => $name) {
        $h["$year-$md"] = $name;
    }
    $monday = function (int $month, int $nth) use ($year): string {
        $first = (int)date('w', mktime(0, 0, 0, $month, 1, $year));
        $day = 1 + (8 - $first) % 7 + 7 * ($nth - 1);
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    };
    $h[$monday(1, 2)] = '成人の日';
    $h[$monday(7, 3)] = '海の日';
    $h[$monday(9, 3)] = '敬老の日';
    $h[$monday(10, 2)] = 'スポーツの日';
    $k = $year - 1980;
    $h[sprintf('%04d-03-%02d', $year, (int)floor(20.8431 + 0.242194 * $k - floor($k / 4)))] = '春分の日';
    $h[sprintf('%04d-09-%02d', $year, (int)floor(23.2488 + 0.242194 * $k - floor($k / 4)))] = '秋分の日';
    ksort($h);

    // 国民の休日（祝日にはさまれた平日）
    foreach (array_keys($h) as $d) {
        $next = date('Y-m-d', strtotime("$d +2 days"));
        $mid = date('Y-m-d', strtotime("$d +1 day"));
        if (isset($h[$next]) && !isset($h[$mid]) && date('w', strtotime($mid)) !== '0') {
            $h[$mid] = '国民の休日';
        }
    }
    // 振替休日（日曜の祝日の次の、祝日でない日）
    foreach (array_keys($h) as $d) {
        if (date('w', strtotime($d)) === '0') {
            $sub = date('Y-m-d', strtotime("$d +1 day"));
            while (isset($h[$sub])) {
                $sub = date('Y-m-d', strtotime("$sub +1 day"));
            }
            $h[$sub] = '振替休日';
        }
    }
    ksort($h);
    return $cache[$year] = $h;
}

function fam_holidays_between(string $from, string $to): array
{
    $out = [];
    for ($y = (int)substr($from, 0, 4); $y <= (int)substr($to, 0, 4); $y++) {
        foreach (fam_holidays($y) as $d => $name) {
            if ($d >= $from && $d <= $to) {
                $out[$d] = $name;
            }
        }
    }
    return $out;
}

// ── 天気（気象庁の予報 JSON を 1 時間キャッシュ）─────────────

function fam_weather(): ?array
{
    $area = preg_replace('/\D/', '', (string)FAMILY_WEATHER_AREA);
    if ($area === '') {
        return null;
    }
    $cacheFile = sys_get_temp_dir() . "/fam_weather_$area.json";
    if (is_file($cacheFile) && filemtime($cacheFile) > time() - 3600) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "User-Agent: family-calendar\r\n"]]);
    $raw = @file_get_contents("https://www.jma.go.jp/bosai/forecast/data/forecast/$area.json", false, $ctx);
    $data = $raw === false ? null : json_decode($raw, true);
    $w = is_array($data) ? fam_weather_parse($data) : null;
    if ($w === null) {
        // 取れなければ古いキャッシュでもよい
        return is_file($cacheFile) ? json_decode((string)file_get_contents($cacheFile), true) : null;
    }
    @file_put_contents($cacheFile, json_encode($w, JSON_UNESCAPED_UNICODE));
    return $w;
}

/** 気象庁の予報 JSON から、日ごとの天気・天気コード・最高/最低気温・降水確率を取り出す */
function fam_weather_parse(array $data): ?array
{
    $ts = $data[0]['timeSeries'] ?? null;
    if (!$ts || empty($ts[0]['areas'][0])) {
        return null;
    }
    $days = [];
    foreach ($ts[0]['timeDefines'] as $i => $t) {
        $d = substr($t, 0, 10);
        $days[$d] = [
            'date' => $d,
            'text' => preg_replace('/\s+/u', ' ', (string)($ts[0]['areas'][0]['weathers'][$i] ?? '')),
            'code' => (string)($ts[0]['areas'][0]['weatherCodes'][$i] ?? ''),
            'max' => null, 'min' => null, 'pop' => null,
        ];
    }
    // 降水確率（6 時間ごと）→ その日の最大
    foreach (($ts[1]['timeDefines'] ?? []) as $i => $t) {
        $d = substr($t, 0, 10);
        $p = $ts[1]['areas'][0]['pops'][$i] ?? '';
        if (isset($days[$d]) && $p !== '') {
            $days[$d]['pop'] = max((int)$days[$d]['pop'], (int)$p);
        }
    }
    // 気温（0 時＝朝の最低、9 時＝日中の最高）
    foreach (($ts[2]['timeDefines'] ?? []) as $i => $t) {
        $d = substr($t, 0, 10);
        $v = $ts[2]['areas'][0]['temps'][$i] ?? '';
        if (!isset($days[$d]) || $v === '') {
            continue;
        }
        if (str_contains($t, 'T00:00')) {
            $days[$d]['min'] = (int)$v;
        } else {
            $days[$d]['max'] = (int)$v;
        }
    }
    // 週間予報で足りない最高・最低を補う
    $wk = $data[1]['timeSeries'][1] ?? null;
    foreach (($wk['timeDefines'] ?? []) as $i => $t) {
        $d = substr($t, 0, 10);
        if (!isset($days[$d])) {
            continue;
        }
        foreach (['max' => 'tempsMax', 'min' => 'tempsMin'] as $k => $src) {
            $v = $wk['areas'][0][$src][$i] ?? '';
            if ($days[$d][$k] === null && $v !== '') {
                $days[$d][$k] = (int)$v;
            }
        }
    }
    return [
        'area' => (string)($ts[0]['areas'][0]['area']['name'] ?? ''),
        'office' => (string)($data[0]['publishingOffice'] ?? ''),
        'days' => array_values($days),
    ];
}

// ── 雨雲レーダー（気象庁。時刻一覧を 2 分キャッシュ）──
// 過去 1 時間（10 分ごと）＝降水ナウキャストの実況、1 時間先まで（10 分ごと）＝降水ナウキャストの予報、
// 3 時間先まで（1 時間ごと）＝降水短時間予報（「今後の雨」）。画像は画面が気象庁から直接読む

const FAM_RADAR_AHEAD = 3 * 3600;

/** コマの一覧（kind: nowc / rasrf、base・valid は UTC の YmdHis、time は UNIX 時刻、forecast は予報か） */
function fam_radar_frames(): array
{
    $cacheFile = sys_get_temp_dir() . '/fam_radar_times2.json';
    if (is_file($cacheFile) && filemtime($cacheFile) > time() - 120) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'header' => "User-Agent: family-calendar\r\n"]]);
    $get = fn(string $path) => json_decode((string)@file_get_contents('https://www.jma.go.jp/bosai/jmatile/data/' . $path, false, $ctx), true);
    $past = $get('nowc/targetTimes_N1.json');
    if (!is_array($past) || !$past) {
        return is_file($cacheFile) ? (array)json_decode((string)file_get_contents($cacheFile), true) : [];
    }
    $ts = fn(string $ymdhis) => (int)strtotime($ymdhis . ' UTC');
    $frames = [];
    $latestTs = max(array_map(fn($t) => $ts($t['validtime']), $past));
    foreach ($past as $t) {
        $v = $ts($t['validtime']);
        if ($v >= $latestTs - 3600 && intdiv($latestTs - $v, 60) % 10 === 0) {
            $frames[$v] = ['kind' => 'nowc', 'base' => $t['basetime'], 'valid' => $t['validtime'], 'time' => $v, 'forecast' => false];
        }
    }
    // ナウキャストの予報：予報そのものの発表時刻（実況より少し遅れることがある）で、いちばん新しいもの
    $fc = (array)$get('nowc/targetTimes_N2.json');
    $fcBase = $fc ? max(array_column($fc, 'basetime')) : '';
    foreach ($fc as $t) {
        $v = $ts($t['validtime']);
        if ($t['basetime'] === $fcBase && $v > $latestTs && intdiv($v - $latestTs, 60) % 10 === 0) {
            $frames[$v] = ['kind' => 'nowc', 'base' => $t['basetime'], 'valid' => $t['validtime'], 'time' => $v, 'forecast' => true];
        }
    }
    // 降水短時間予報：1 時間ごとの予報が出そろっている、いちばん新しい発表
    $sr = (array)$get('rasrf/targetTimes.json');
    $byBase = [];
    foreach ($sr as $t) {
        if (($t['member'] ?? '') === 'immed') {
            $byBase[$t['basetime']][] = $t['validtime'];
        }
    }
    krsort($byBase);
    foreach ($byBase as $base => $valids) {
        if (count($valids) < 4) {
            continue; // まだ作っている途中の発表
        }
        $nowcEnd = $frames ? max(array_keys($frames)) : $latestTs;
        foreach ($valids as $valid) {
            $v = $ts($valid);
            if ($v > $nowcEnd + 5 * 60 && $v <= $latestTs + FAM_RADAR_AHEAD + 10 * 60) {
                $frames[$v] = ['kind' => 'rasrf', 'base' => $base, 'valid' => $valid, 'time' => $v, 'forecast' => true];
            }
        }
        break;
    }
    ksort($frames);
    $frames = array_values($frames);
    @file_put_contents($cacheFile, json_encode($frames));
    return $frames;
}

// ── ニュース（RSS の見出しを 10 分キャッシュ。テレビの上の帯に流す）──

/** 見出しの一覧 [['title' => …, 'time' => UNIX 時刻], …] と出どころ。取れなければ前回の分 */
function fam_news(): array
{
    $cacheFile = sys_get_temp_dir() . '/fam_news.json';
    if (is_file($cacheFile) && filemtime($cacheFile) > time() - 600) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            return $cached;
        }
    }
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'header' => "User-Agent: family-calendar\r\n"]]);
    foreach (array_filter(array_map('trim', explode(',', (string)FAMILY_NEWS_RSS))) as $url) {
        $raw = @file_get_contents($url, false, $ctx);
        $xml = $raw === false ? false : @simplexml_load_string($raw, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        if (!$xml || !isset($xml->channel->item)) {
            continue;
        }
        $items = [];
        foreach ($xml->channel->item as $it) {
            $title = trim(preg_replace('/\s+/u', ' ', (string)$it->title));
            if ($title !== '') {
                $items[] = ['title' => mb_substr($title, 0, 80), 'time' => strtotime((string)$it->pubDate) ?: 0];
            }
        }
        // 古いまま止まった RSS（2 日以上前のものしかない）は使わない
        if (!$items || max(array_column($items, 'time')) < time() - 2 * 86400) {
            continue;
        }
        usort($items, fn($a, $b) => $b['time'] <=> $a['time']);
        $source = trim((string)$xml->channel->title);
        $source = str_contains($source, 'NHK') ? 'NHKニュース' : (str_starts_with($source, 'Yahoo!') ? 'Yahoo!ニュース' : $source);
        $news = ['source' => $source, 'items' => array_slice($items, 0, 12)];
        @file_put_contents($cacheFile, json_encode($news, JSON_UNESCAPED_UNICODE));
        return $news;
    }
    $old = is_file($cacheFile) ? json_decode((string)file_get_contents($cacheFile), true) : null;
    return is_array($old) ? $old : ['source' => '', 'items' => []];
}

// ── 出力 ──────────────────────────────────────────────────

function fam_json(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function h(mixed $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
