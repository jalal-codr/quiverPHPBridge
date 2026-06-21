<?php
require_once(__DIR__ . '/_bootstrap.php');
quiver_bootstrap(true);
$input = quiver_input();
$toId = intval($input['toId'] ?? $_GET['toId'] ?? 0);
if (!$toId) quiver_error(400, 'Missing or invalid toId');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($input['action'] ?? '') === 'set') {
    $sessions = is_array($input['sessions'] ?? null) ? $input['sessions'] : [];
    safe_w_sql("DELETE FROM Session WHERE SesTournament=$toId AND SesType='Q'");
    foreach ($sessions as $s) {
        $order = max(1, intval($s['order'] ?? 1));
        $name = trim($s['name'] ?? ("Session $order"));
        $targets = max(1, intval($s['targets'] ?? 20));
        $apt = min(4, max(1, intval($s['archersPerTarget'] ?? 3)));
        $date = trim($s['date'] ?? '');
        $start = trim($s['startTime'] ?? '');
        safe_w_sql("INSERT INTO Session (SesTournament, SesOrder, SesType, SesName, SesTar4Session, SesAth4Target, SesDtStart, SesDtEnd)
            VALUES ($toId, $order, 'Q', " . StrSafe_DB($name) . ", $targets, $apt, " . StrSafe_DB($date && $start ? "$date $start:00" : null) . ", NULL)
            ON DUPLICATE KEY UPDATE SesName=VALUES(SesName), SesTar4Session=VALUES(SesTar4Session), SesAth4Target=VALUES(SesAth4Target), SesDtStart=VALUES(SesDtStart)");
    }
}

$q = safe_r_sql("SELECT SesOrder, SesName, SesTar4Session, SesAth4Target, SesDtStart FROM Session WHERE SesTournament=$toId AND SesType='Q' ORDER BY SesOrder");
$sessions = [];
while ($r = safe_fetch($q)) {
    $dt = $r->SesDtStart ? explode(' ', $r->SesDtStart) : ['', ''];
    $sessions[] = [
        'order'=>intval($r->SesOrder),
        'name'=>$r->SesName,
        'targets'=>intval($r->SesTar4Session ?: 20),
        'archersPerTarget'=>intval($r->SesAth4Target ?: 3),
        'firstTarget'=>1,
        'date'=>$dt[0] ?: '',
        'startTime'=>isset($dt[1]) ? substr($dt[1], 0, 5) : '',
    ];
}
echo json_encode(['success'=>true,'sessions'=>$sessions]);
