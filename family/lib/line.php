<?php
declare(strict_types=1);

// 家族カレンダー × LINE
// - ボット（Messaging API）: webhook の署名を確かめ、返信（無料）で「予定を見る・登録する」ボタンや今日・明日・今週の予定を返す
// - LIFF: LINE の中で登録画面を開く。ID トークンを LINE に確かめてもらい、最初の 1 回だけ家族のパスワードで LINE アカウントを登録する

require_once __DIR__ . '/family.php';

function fam_line_enabled(): bool
{
    return FAMILY_LINE_CHANNEL_SECRET !== '' && FAMILY_LINE_ACCESS_TOKEN !== '';
}

function fam_liff_url(): string
{
    return FAMILY_LIFF_ID !== '' ? 'https://liff.line.me/' . FAMILY_LIFF_ID : '';
}

/** webhook が本当に LINE から来たか（X-Line-Signature＝本文の HMAC-SHA256 を Base64） */
function fam_line_signature_ok(string $body, string $signature): bool
{
    if (FAMILY_LINE_CHANNEL_SECRET === '' || $signature === '') {
        return false;
    }
    return hash_equals(base64_encode(hash_hmac('sha256', $body, FAMILY_LINE_CHANNEL_SECRET, true)), $signature);
}

/** LINE の API を呼ぶ（JSON を返す。失敗したら null） */
function fam_line_http(string $url, array $headers, string $body, string $contentType = 'application/json'): ?array
{
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", array_merge(["Content-Type: $contentType"], $headers)) . "\r\n",
        'content' => $body,
        'timeout' => 8,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) {
            $status = (int)$m[1];
        }
    }
    if ($raw === false || $status !== 200) {
        error_log("family line: $url HTTP $status " . substr((string)$raw, 0, 300));
        return null;
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : [];
}

function fam_line_reply(string $replyToken, array $messages): void
{
    fam_line_http('https://api.line.me/v2/bot/message/reply', ['Authorization: Bearer ' . FAMILY_LINE_ACCESS_TOKEN],
        json_encode(['replyToken' => $replyToken, 'messages' => array_slice($messages, 0, 5)], JSON_UNESCAPED_UNICODE));
}

/** LIFF の ID トークンを LINE に確かめてもらい、LINE のユーザー ID と名前を返す（だめなら null） */
function fam_line_verify_id_token(string $idToken): ?array
{
    if ($idToken === '' || FAMILY_LINE_LOGIN_CHANNEL_ID === '') {
        return null;
    }
    $r = fam_line_http('https://api.line.me/oauth2/v2.1/verify', [],
        http_build_query(['id_token' => $idToken, 'client_id' => FAMILY_LINE_LOGIN_CHANNEL_ID]), 'application/x-www-form-urlencoded');
    if (!$r || empty($r['sub'])) {
        return null;
    }
    return ['user_id' => (string)$r['sub'], 'name' => (string)($r['name'] ?? '')];
}

// ── LINE で使う人 ──

function fam_line_user(string $userId): ?array
{
    $st = fam_pdo()->prepare('SELECT user_id, display_name, member_id FROM fam_line_users WHERE user_id = ?');
    $st->execute([$userId]);
    $row = $st->fetch();
    return $row ?: null;
}

function fam_line_user_save(string $userId, string $name, ?int $memberId): void
{
    $pdo = fam_pdo();
    $pdo->prepare('DELETE FROM fam_line_users WHERE user_id = ?')->execute([$userId]);
    $pdo->prepare('INSERT INTO fam_line_users (user_id, display_name, member_id, created_at) VALUES (?, ?, ?, ?)')
        ->execute([$userId, mb_substr($name, 0, 100), $memberId ?: null, time()]);
}

// ── ボットの返事 ──

/** 「予定を見る・登録する」ボタン */
function fam_line_button_message(string $text): array
{
    $url = fam_liff_url();
    if ($url === '') {
        return ['type' => 'text', 'text' => $text];
    }
    return [
        'type' => 'template',
        'altText' => $text,
        'template' => [
            'type' => 'buttons',
            'text' => mb_substr($text, 0, 160),
            'actions' => [['type' => 'uri', 'label' => '予定を見る・登録する', 'uri' => $url]],
        ],
    ];
}

/** 期間の予定を文章にする（「今日」「明日」「今週」の返事） */
function fam_line_agenda_text(string $from, string $to, string $title): string
{
    $names = [];
    foreach (fam_members() as $m) {
        $names[(int)$m['id']] = $m['name'];
    }
    $holidays = fam_holidays_between($from, $to);
    $byDay = [];
    foreach (fam_occurrences($from, $to) as $ev) {
        // 複数日の予定は、期間内の各日に出す
        for ($d = max($ev['date'], $from); $d <= min($ev['end_date'], $to); $d = date('Y-m-d', strtotime("$d +1 day"))) {
            $byDay[$d][] = $ev;
        }
    }
    $lines = ["【{$title}の予定】"];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        $ts = strtotime($d);
        $head = date('n/j', $ts) . '（' . FAM_WEEKDAYS[(int)date('w', $ts)] . '）' . (isset($holidays[$d]) ? ' ' . $holidays[$d] : '');
        if ($from !== $to) {
            $lines[] = '';
            $lines[] = $head;
        }
        foreach ($byDay[$d] ?? [] as $ev) {
            $time = $ev['start_time'] && $ev['date'] === $d ? $ev['start_time'] . ($ev['end_time'] ? '〜' . $ev['end_time'] : '') : '終日';
            $who = $ev['member_ids'] ? implode('・', array_filter(array_map(fn($id) => $names[$id] ?? null, $ev['member_ids']))) : '家族';
            $lines[] = "・{$time} {$ev['title']}（{$who}）";
        }
        if (empty($byDay[$d])) {
            $lines[] = $from === $to ? '予定はありません' : '・なし';
        }
    }
    if ($from === $to) {
        array_splice($lines, 1, 0, [date('n/j', strtotime($from)) . '（' . FAM_WEEKDAYS[(int)date('w', strtotime($from))] . '）' .
            (isset($holidays[$from]) ? ' ' . $holidays[$from] : '')]);
    }
    return mb_substr(implode("\n", $lines), 0, 4900);
}

/** 届いたメッセージへの返事を決める */
function fam_line_answer(string $text): array
{
    $t = trim(mb_convert_kana($text, 'as'));
    $today = date('Y-m-d');
    // ボタンの文は 160 字までなので、予定の一覧は文章で返し、ボタンは別に付ける
    $agenda = null;
    if (preg_match('/^(今日|きょう)/u', $t)) {
        $agenda = fam_line_agenda_text($today, $today, '今日');
    } elseif (preg_match('/^(明日|あした|あす)/u', $t)) {
        $d = date('Y-m-d', strtotime('+1 day'));
        $agenda = fam_line_agenda_text($d, $d, '明日');
    } elseif (preg_match('/^(今週|こんしゅう|1週間|一週間)/u', $t)) {
        $agenda = fam_line_agenda_text($today, date('Y-m-d', strtotime('+6 day')), 'これから 1 週間');
    }
    if ($agenda !== null) {
        return [['type' => 'text', 'text' => $agenda], fam_line_button_message('予定の登録・修正はこちらから')];
    }
    return [fam_line_button_message("予定の登録・確認はボタンから。\n「今日」「明日」「今週」と送ると予定をお知らせします。")];
}
