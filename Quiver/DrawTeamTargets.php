<?php
/**
 * Quiver -> Ianseo bridge: assign target butts for team final events.
 *
 * POST body:
 * {
 *   "toId": 123,
 *   "targetPlan": [
 *     { "eventCode": "RMT", "targets": [1,2,3], "session": 1 }
 *   ]
 * }
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
header('Content-Type: application/json');

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '127.0.0.1' && $ip !== '::1') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['toId']) || !is_numeric($input['toId'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid toId']);
    exit;
}

$toId = intval($input['toId']);
$targetPlan = $input['targetPlan'] ?? [];
if (!is_array($targetPlan) || count($targetPlan) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing targetPlan']);
    exit;
}

$q = safe_r_sql("SELECT ToId FROM Tournament WHERE ToId=" . StrSafe_DB($toId));
if (safe_num_rows($q) === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Tournament not found']);
    exit;
}

$events = [];
$totalAssigned = 0;

foreach ($targetPlan as $row) {
    $eventCode = trim($row['eventCode'] ?? '');
    $targets = cleanTeamTargets($row['targets'] ?? []);
    if ($eventCode === '' || count($targets) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Each team target row needs eventCode and targets']);
        exit;
    }

    $eventSql = StrSafe_DB($eventCode);
    $eventQ = safe_r_sql("SELECT EvCode FROM Events WHERE EvTournament=" . StrSafe_DB($toId) . " AND EvTeamEvent=1 AND EvCode=$eventSql");
    if (safe_num_rows($eventQ) === 0) {
        http_response_code(422);
        echo json_encode(['error' => "Team event $eventCode not found in Ianseo"]);
        exit;
    }

    $matches = [];
    $matchQ = safe_r_sql("SELECT DISTINCT IF(TfMatchNo % 2 = 1, TfMatchNo - 1, TfMatchNo) AS MatchBase
        FROM TeamFinals
        WHERE TfTournament=" . StrSafe_DB($toId) . " AND TfEvent=$eventSql
        ORDER BY MatchBase ASC");
    while ($match = safe_fetch($matchQ)) {
        $base = intval($match->MatchBase);
        if ($base >= 0 && !in_array($base, $matches, true)) {
            $matches[] = $base;
        }
    }

    if (count($matches) === 0) {
        $events[] = [
            'eventCode' => $eventCode,
            'targets' => $targets,
            'assigned' => 0,
            'message' => 'No team final rows found for this event',
        ];
        continue;
    }

    $assignedForEvent = 0;
    foreach ($matches as $index => $matchBase) {
        if (!isset($targets[$index])) break;
        $target = intval($targets[$index]);
        writeTeamTarget($toId, $eventCode, $matchBase, $target, 'A');
        writeTeamTarget($toId, $eventCode, $matchBase + 1, $target, 'B');
        $assignedForEvent += 2;
    }

    $totalAssigned += $assignedForEvent;
    $events[] = [
        'eventCode' => $eventCode,
        'targets' => $targets,
        'assigned' => $assignedForEvent,
    ];
}

echo json_encode([
    'success' => true,
    'assigned' => $totalAssigned,
    'events' => $events,
]);
exit;

function cleanTeamTargets($targets) {
    if (!is_array($targets)) return [];
    $clean = [];
    foreach ($targets as $target) {
        $targetNo = intval($target);
        if ($targetNo > 0) $clean[$targetNo] = $targetNo;
    }
    sort($clean, SORT_NUMERIC);
    return array_values($clean);
}

function writeTeamTarget($toId, $eventCode, $matchNo, $target, $letter) {
    $targetText = str_pad(strval($target), TargetNoPadding, '0', STR_PAD_LEFT);
    $fsLetter = $targetText . $letter;
    safe_w_sql("INSERT INTO FinSchedule (FSEvent, FSTeamEvent, FSMatchNo, FSTournament, FSTarget, FSLetter)
        VALUES (" . StrSafe_DB($eventCode) . ", 1, " . StrSafe_DB($matchNo) . ", " . StrSafe_DB($toId) . ", " . StrSafe_DB($targetText) . ", " . StrSafe_DB($fsLetter) . ")
        ON DUPLICATE KEY UPDATE
            FSTarget=" . StrSafe_DB($targetText) . ",
            FSLetter=" . StrSafe_DB($fsLetter) . ",
            FSGroup=FSGroup,
            FSScheduledTime=FSScheduledTime");
}
?>
