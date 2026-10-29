<?php
declare(strict_types=1);

// 家族カレンダー：iPhone の「カレンダー」アプリで購読する用（見るだけ）
// 設定 → カレンダー → アカウント → アカウントを追加 → その他 → 照会するカレンダーを追加 に
// https://aitakt.gardenas.net/family/ics.php?key=（FAMILY_TV_KEY）を入れる

require_once __DIR__ . '/lib/family.php';

if (!fam_key_ok($_GET['key'] ?? null)) {
    http_response_code(403);
    exit('forbidden');
}

$members = [];
foreach (fam_members() as $m) {
    $members[(int)$m['id']] = $m['name'];
}

$esc = fn(string $s): string => str_replace(["\\", ';', ',', "\r\n", "\n"], ["\\\\", '\;', '\,', '\n', '\n'], $s);
// 1 行 75 バイトまで（超えたら折り返す）
$fold = function (string $line): string {
    $out = '';
    while (strlen($line) > 75) {
        $cut = 75;
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
            $cut--;
        }
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
    }
    return $out . $line . "\r\n";
};

$st = fam_pdo()->query('SELECT * FROM fam_events WHERE end_date >= ' . fam_pdo()->quote(date('Y-m-d', strtotime('-1 year'))) .
    " OR repeat_rule <> '' ORDER BY start_date");
$rrule = ['weekly' => 'WEEKLY', 'monthly' => 'MONTHLY', 'yearly' => 'YEARLY'];

$lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//family-calendar//JA', 'CALSCALE:GREGORIAN',
    'X-WR-CALNAME:家族カレンダー', 'X-WR-TIMEZONE:Asia/Tokyo', 'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
    'BEGIN:VTIMEZONE', 'TZID:Asia/Tokyo', 'BEGIN:STANDARD', 'DTSTART:19700101T000000', 'TZOFFSETFROM:+0900',
    'TZOFFSETTO:+0900', 'TZNAME:JST', 'END:STANDARD', 'END:VTIMEZONE'];
foreach ($st->fetchAll() as $ev) {
    $sd = str_replace('-', '', substr((string)$ev['start_date'], 0, 10));
    $ed = substr((string)$ev['end_date'], 0, 10);
    $who = $ev['member_ids'] === '' ? '家族'
        : implode('・', array_filter(array_map(fn($id) => $members[(int)$id] ?? null, explode(',', $ev['member_ids']))));
    $lines[] = 'BEGIN:VEVENT';
    $lines[] = 'UID:fam-' . $ev['id'] . '@aitakt.gardenas.net';
    $lines[] = 'DTSTAMP:' . gmdate('Ymd\THis\Z', strtotime((string)$ev['updated_at']));
    if ($ev['start_time']) {
        $st0 = str_replace(':', '', substr((string)$ev['start_time'], 0, 5)) . '00';
        $endTime = $ev['end_time'] ? substr((string)$ev['end_time'], 0, 5) : date('H:i', strtotime((string)$ev['start_time']) + 3600);
        $lines[] = "DTSTART;TZID=Asia/Tokyo:{$sd}T{$st0}";
        $lines[] = 'DTEND;TZID=Asia/Tokyo:' . str_replace('-', '', $ed) . 'T' . str_replace(':', '', $endTime) . '00';
    } else {
        $lines[] = "DTSTART;VALUE=DATE:$sd";
        $lines[] = 'DTEND;VALUE=DATE:' . date('Ymd', strtotime("$ed +1 day"));
    }
    if (isset($rrule[$ev['repeat_rule']])) {
        $r = 'RRULE:FREQ=' . $rrule[$ev['repeat_rule']];
        if ($ev['repeat_until']) {
            $r .= ';UNTIL=' . str_replace('-', '', substr((string)$ev['repeat_until'], 0, 10)) . 'T235959Z';
        }
        $lines[] = $r;
    }
    $lines[] = 'SUMMARY:' . $esc("[$who] " . $ev['title']);
    if ($ev['note'] !== '') {
        $lines[] = 'DESCRIPTION:' . $esc((string)$ev['note']);
    }
    $lines[] = 'END:VEVENT';
}
$lines[] = 'END:VCALENDAR';

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="family.ics"');
foreach ($lines as $l) {
    echo $fold($l);
}
