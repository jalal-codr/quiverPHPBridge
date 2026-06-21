<?php
require_once(__DIR__ . '/_bootstrap.php');
quiver_bootstrap(true);
$input = quiver_input();
$toId = intval($input['toId'] ?? 0);
if (!$toId) quiver_error(400, 'Missing or invalid toId');
$rows = is_array($input['rows'] ?? null) ? $input['rows'] : [];
safe_w_sql("DELETE FROM Scheduler WHERE SchTournament=$toId");
$order = 1;
foreach ($rows as $row) {
    $date = trim($row['date'] ?? '');
    $time = trim($row['startTime'] ?? '');
    if (!$date || !$time) continue;
    $duration = intval($row['duration'] ?? 0);
    $shift = isset($row['delay']) ? intval($row['delay']) : 0;
    $title = substr(strip_tags($row['title'] ?? ''), 0, 160);
    $sub = substr(strip_tags($row['subTitle'] ?? ''), 0, 240);
    $text = substr(strip_tags($row['text'] ?? ''), 0, 255);
    $location = substr(strip_tags($row['location'] ?? ''), 0, 120);
    $startDt = "$date $time:00";
    $endDt = date('Y-m-d H:i:s', strtotime($startDt) + ($duration * 60));
    $uid = md5($toId . '-' . $order . '-' . $startDt . '-' . $title);
    safe_w_sql("INSERT INTO Scheduler (
            SchTournament, SchOrder, SchDateStart, SchDateEnd, SchSesOrder, SchSesType,
            SchDescr, SchDay, SchStart, SchDuration, SchTitle, SchSubTitle, SchText,
            SchShift, SchTargets, SchLink, SchLocation, SchUID
        )
        VALUES (
            $toId, $order, " . StrSafe_DB($startDt) . ", " . StrSafe_DB($endDt) . ", 0, 'Z',
            " . StrSafe_DB($title) . ", " . StrSafe_DB($date) . ", " . StrSafe_DB($time . ':00') . ", $duration,
            " . StrSafe_DB($title) . ", " . StrSafe_DB($sub) . ", " . StrSafe_DB($text) . ",
            $shift, '', '', " . StrSafe_DB($location) . ", " . StrSafe_DB($uid) . "
        )");
    $order++;
}
echo json_encode(['success'=>true,'rows'=>count($rows),'synced'=>max(0,$order-1)]);
