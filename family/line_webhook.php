<?php
declare(strict_types=1);

// 家族カレンダー：LINE ボットの Webhook（LINE Developers の Webhook URL に
// https://aitakt.gardenas.net/family/line_webhook.php を入れる）
// 友だち追加・グループへの招待・メッセージに、返信（無料）で答える

require_once __DIR__ . '/lib/line.php';

$body = (string)file_get_contents('php://input');
if (!fam_line_enabled()) {
    http_response_code(503);
    exit('LINE is not configured');
}
if (!fam_line_signature_ok($body, (string)($_SERVER['HTTP_X_LINE_SIGNATURE'] ?? ''))) {
    http_response_code(403);
    exit('bad signature');
}

$data = json_decode($body, true);
foreach ((array)($data['events'] ?? []) as $ev) {
    $token = (string)($ev['replyToken'] ?? '');
    if ($token === '' || ($ev['mode'] ?? 'active') !== 'active') {
        continue; // 接続確認（検証ボタン）や待機中のチャネル
    }
    try {
        switch ($ev['type'] ?? '') {
            case 'follow':
                fam_line_reply($token, [fam_line_button_message("家族カレンダーです。\n予定の登録・確認は下のボタンから。「今日」「明日」「今週」と送ると予定をお知らせします。")]);
                break;
            case 'join':
                fam_line_reply($token, [fam_line_button_message("家族カレンダーです。\n「今日」「明日」「今週」と送ると予定をお知らせします。")]);
                break;
            case 'message':
                if (($ev['message']['type'] ?? '') !== 'text') {
                    break;
                }
                $text = (string)$ev['message']['text'];
                // グループでは「今日」などの決まった言葉にだけ答える（普段の会話には口を出さない）
                $inGroup = in_array($ev['source']['type'] ?? 'user', ['group', 'room'], true);
                if ($inGroup && !preg_match('/^\s*(今日|きょう|明日|あした|あす|今週|こんしゅう|1週間|一週間|予定|カレンダー)\s*$/u', $text)) {
                    break;
                }
                fam_line_reply($token, fam_line_answer($text));
                break;
        }
    } catch (Throwable $e) {
        error_log('family line webhook: ' . $e->getMessage());
    }
}

http_response_code(200);
echo 'ok';
