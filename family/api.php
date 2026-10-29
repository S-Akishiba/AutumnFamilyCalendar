<?php
declare(strict_types=1);

// 家族カレンダーの API
//   GET  ?action=calendar&from=YYYY-MM-DD&to=YYYY-MM-DD  … 予定・メンバー・祝日（テレビは &key= で読める）
//   GET  ?action=weather / radar / news                   … 天気・雨雲レーダーのコマ・ニュースの見出し（同上）
//   POST ?action=save / delete / members_save / pair_approve / logout（JSON。ログインが必要）
//   POST ?action=login / pair_start / pair_poll（ログインなし。テレビをつなぐ）
//   POST ?action=line_login / line_link（LINE の中から。ID トークンで確かめる）

require_once __DIR__ . '/lib/family.php';

$action = (string)($_GET['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$body = [];
if ($isPost) {
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body)) {
        fam_json(['error' => 'JSON で送ってください'], 400);
    }
}

if (!fam_is_configured()) {
    fam_json(['error' => 'config.local.php が未設定です'], 500);
}

if ($action === 'login' && $isPost) {
    if (!fam_login((string)($body['password'] ?? ''))) {
        usleep(800000);
        fam_json(['error' => 'パスワードが違います'], 403);
    }
    fam_json(['ok' => true]);
}
if ($action === 'logout' && $isPost) {
    fam_logout();
    fam_json(['ok' => true]);
}

// LINE の中（LIFF）からのログイン。ID トークンを LINE に確かめてもらう
//   line_login: 登録済みの LINE アカウントならログイン。まだなら need_link を返す
//   line_link : 最初の 1 回だけ、家族のパスワードと「自分はだれか」で LINE アカウントを登録してログイン
if (($action === 'line_login' || $action === 'line_link') && $isPost) {
    require_once __DIR__ . '/lib/line.php';
    $who = fam_line_verify_id_token((string)($body['id_token'] ?? ''));
    if (!$who) {
        fam_json(['error' => 'LINE のログインを確かめられませんでした。LINE を開き直してください'], 401);
    }
    if ($action === 'line_login') {
        $u = fam_line_user($who['user_id']);
        if (!$u) {
            fam_json(['need_link' => true, 'name' => $who['name'], 'members' => fam_members()]);
        }
        fam_set_auth_cookie();
        fam_json(['ok' => true, 'member_id' => $u['member_id'] !== null ? (int)$u['member_id'] : null]);
    }
    if (!fam_password_ok((string)($body['password'] ?? ''))) {
        usleep(800000);
        fam_json(['error' => 'パスワードが違います'], 403);
    }
    $memberId = (int)($body['member_id'] ?? 0);
    $ids = array_map(fn($m) => (int)$m['id'], fam_members());
    fam_line_user_save($who['user_id'], $who['name'], in_array($memberId, $ids, true) ? $memberId : null);
    fam_set_auth_cookie();
    fam_json(['ok' => true, 'member_id' => in_array($memberId, $ids, true) ? $memberId : null]);
}

// テレビをつなぐ（番号を出す・承認を待つ）はログインなしで呼べる
if ($action === 'pair_start' && $isPost) {
    $p = fam_pair_start();
    $p ? fam_json($p) : fam_json(['error' => 'しばらくしてからやり直してください'], 429);
}
if ($action === 'pair_poll' && $isPost) {
    fam_json(fam_pair_poll((string)($body['code'] ?? ''), (string)($body['token'] ?? '')));
}

$canRead = fam_logged_in() || fam_key_ok($_GET['key'] ?? null);
if (!$canRead) {
    fam_json(['error' => 'ログインしてください'], 401);
}

try {
    switch ($action) {
        case 'calendar':
            $from = (string)($_GET['from'] ?? date('Y-m-d'));
            $to = (string)($_GET['to'] ?? date('Y-m-d', strtotime('+6 weeks')));
            if (!fam_valid_date($from) || !fam_valid_date($to) || $to < $from || strtotime($to) - strtotime($from) > 400 * 86400) {
                fam_json(['error' => '期間が正しくありません'], 400);
            }
            fam_json([
                'now' => date('c'),
                'members' => fam_members(),
                'events' => fam_occurrences($from, $to),
                'holidays' => (object)fam_holidays_between($from, $to),
            ]);

        case 'weather':
            fam_json(['weather' => fam_weather()]);

        case 'news':
            fam_json(['news' => fam_news()]);

        case 'radar':
            fam_json(['frames' => fam_radar_frames(), 'lat' => FAMILY_RADAR_LAT, 'lon' => FAMILY_RADAR_LON, 'zoom' => FAMILY_RADAR_ZOOM]);
    }

    // ここから下は書き込み（家族のログインが必要。テレビの鍵では書けない）
    if (!$isPost || !fam_logged_in()) {
        fam_json(['error' => 'ログインしてください'], 401);
    }
    switch ($action) {
        case 'save':
            fam_json(['ok' => true, 'id' => fam_event_save($body)]);
        case 'delete':
            fam_event_delete((int)($body['id'] ?? 0));
            fam_json(['ok' => true]);
        case 'pair_approve':
            $code = preg_replace('/\D/', '', (string)($body['code'] ?? ''));
            if (!fam_pair_approve($code)) {
                fam_json(['error' => 'その番号は見つかりません（テレビに出ている 6 桁の番号を確かめてください）'], 404);
            }
            fam_json(['ok' => true]);
        case 'members_save':
            fam_members_save((array)($body['members'] ?? []));
            fam_json(['ok' => true, 'members' => fam_members()]);
    }
    fam_json(['error' => 'unknown action'], 400);
} catch (InvalidArgumentException $e) {
    fam_json(['error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('family api: ' . $e->getMessage());
    fam_json(['error' => 'サーバーでエラーが起きました'], 500);
}
