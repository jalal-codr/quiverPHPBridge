<?php
/**
 * Quiver -> Ianseo bridge: read and save team first-round pairings.
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
require_once($CFG->DOCUMENT_PATH . 'Common/Fun_Sessions.inc.php');
require_once($CFG->DOCUMENT_PATH . 'Common/Lib/Obj_RankFactory.php');

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['toId']) || !is_numeric($input['toId'])) {
    fail_json('Missing or invalid toId', 400);
}

$toId = intval($input['toId']);
$action = trim($input['action'] ?? 'load');

$q = safe_r_sql("SELECT ToId FROM Tournament WHERE ToId=" . StrSafe_DB($toId));
if (safe_num_rows($q) !== 1) {
    fail_json('Tournament not found', 404);
}

CreateTourSession($toId);

if ($action === 'save') {
    save_pairings($toId, $input['pairings'] ?? []);
}

echo json_encode([
    'success' => true,
    'events' => load_events($toId),
]);
exit;

function fail_json($message, $status = 500) {
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

function clean_event_code($code) {
    return substr(preg_replace('/[^A-Z0-9_]/', '', strtoupper(trim(strval($code)))), 0, 10);
}

function team_key($countryId, $subTeam) {
    $countryId = intval($countryId);
    $subTeam = intval($subTeam);
    if ($countryId <= 0) return '';
    if ($subTeam <= 0) $subTeam = 1;
    return $countryId . '_' . $subTeam;
}

function parse_team_key($value) {
    $value = trim(strval($value));
    if ($value === '') return [0, 0];
    $parts = explode('_', $value);
    $countryId = intval($parts[0] ?? 0);
    $subTeam = intval($parts[1] ?? 1);
    if ($countryId <= 0) return [0, 0];
    if ($subTeam <= 0) $subTeam = 1;
    return [$countryId, $subTeam];
}

function refresh_team_rankings($toId, $eventCodes) {
    foreach ($eventCodes as $eventCode) {
        try {
            Obj_RankFactory::create('AbsTeam', [
                'tournament' => $toId,
                'events' => $eventCode,
                'components' => false,
            ])->calculate();
        } catch (Throwable $e) {
            // Existing ranking rows are still usable if recalculation is not possible.
        }
    }
}

function load_events($toId) {
    $events = [];
    $eventCodes = [];
    $eventQ = safe_r_sql("SELECT EvCode, EvEventName, EvFinalFirstPhase, EvNumQualified
        FROM Events
        WHERE EvTournament=" . StrSafe_DB($toId) . " AND EvTeamEvent=1
        ORDER BY EvProgr, EvCode");
    while ($event = safe_fetch($eventQ)) {
        $eventCodes[] = $event->EvCode;
        $events[$event->EvCode] = [
            'code' => $event->EvCode,
            'name' => $event->EvEventName,
            'firstPhase' => intval($event->EvFinalFirstPhase),
            'qualified' => intval($event->EvNumQualified),
            'teams' => [],
            'matches' => [],
        ];
    }

    if (count($eventCodes) === 0) {
        return [];
    }

    refresh_team_rankings($toId, $eventCodes);

    $quotedEvents = implode(',', array_map('StrSafe_DB', $eventCodes));
    $teamQ = safe_r_sql("SELECT TeEvent, TeCoId, TeSubTeam, TeRank, CoCode, CoName
        FROM Teams
        LEFT JOIN Countries ON CoId=TeCoId AND CoTournament=TeTournament
        WHERE TeTournament=" . StrSafe_DB($toId) . " AND TeFinEvent=1 AND TeEvent IN ($quotedEvents)
        ORDER BY TeEvent, TeRank ASC, CoName ASC");
    while ($team = safe_fetch($teamQ)) {
        if (!isset($events[$team->TeEvent])) continue;
        $subTeam = intval($team->TeSubTeam) ?: 1;
        $events[$team->TeEvent]['teams'][] = [
            'key' => team_key($team->TeCoId, $subTeam),
            'countryId' => intval($team->TeCoId),
            'subTeam' => $subTeam,
            'rank' => intval($team->TeRank),
            'code' => $team->CoCode ?? '',
            'name' => ($team->CoName ?? '') . ($subTeam > 1 ? ' (' . $subTeam . ')' : ''),
        ];
    }

    $slotQ = safe_r_sql("SELECT EvCode, EvFinalFirstPhase, GrMatchNo, GrPhase, GrPosition, TfTeam, TfSubTeam, FSLetter
        FROM Events
        INNER JOIN Phases ON PhId=EvFinalFirstPhase AND (PhIndTeam & 2)>0
        INNER JOIN Grids ON GrPhase=GREATEST(PhId, PhLevel)
        LEFT JOIN TeamFinals ON TfEvent=EvCode AND TfTournament=EvTournament AND TfMatchNo=GrMatchNo
        LEFT JOIN FinSchedule ON FSEvent=EvCode AND FSTournament=EvTournament AND FSTeamEvent=1 AND FSMatchNo=GrMatchNo
        WHERE EvTournament=" . StrSafe_DB($toId) . " AND EvTeamEvent=1 AND EvCode IN ($quotedEvents)
        ORDER BY EvCode, GrMatchNo ASC");
    $matchesByEvent = [];
    while ($slot = safe_fetch($slotQ)) {
        if (!isset($events[$slot->EvCode])) continue;
        $matchBase = intval($slot->GrMatchNo);
        if ($matchBase % 2 !== 0) $matchBase -= 1;
        if (!isset($matchesByEvent[$slot->EvCode][$matchBase])) {
            $matchesByEvent[$slot->EvCode][$matchBase] = [
                'matchNo' => $matchBase,
                'phase' => intval($slot->GrPhase),
                'target' => normalize_target($slot->FSLetter ?? ''),
                'sideA' => '',
                'sideB' => '',
            ];
        }

        $side = (intval($slot->GrMatchNo) % 2 === 0) ? 'sideA' : 'sideB';
        $matchesByEvent[$slot->EvCode][$matchBase][$side] = team_key($slot->TfTeam, $slot->TfSubTeam);
        if ($matchesByEvent[$slot->EvCode][$matchBase]['target'] === '') {
            $matchesByEvent[$slot->EvCode][$matchBase]['target'] = normalize_target($slot->FSLetter ?? '');
        }
    }

    foreach ($matchesByEvent as $eventCode => $matches) {
        $events[$eventCode]['matches'] = array_values($matches);
    }

    return array_values($events);
}

function normalize_target($letter) {
    $letter = trim(strval($letter));
    return $letter;
}

function save_pairings($toId, $pairings) {
    if (!is_array($pairings)) {
        fail_json('pairings must be an array', 400);
    }

    $eventsToRefresh = [];
    foreach ($pairings as $row) {
        $eventCode = clean_event_code($row['eventCode'] ?? '');
        $matchNo = intval($row['matchNo'] ?? -1);
        if ($eventCode === '' || $matchNo < 0) {
            fail_json('Each pairing needs eventCode and matchNo', 400);
        }
        if ($matchNo % 2 !== 0) $matchNo -= 1;

        $eventQ = safe_r_sql("SELECT EvCode FROM Events WHERE EvTournament=" . StrSafe_DB($toId) . " AND EvTeamEvent=1 AND EvCode=" . StrSafe_DB($eventCode));
        if (safe_num_rows($eventQ) !== 1) {
            fail_json("Team event $eventCode not found", 422);
        }

        write_pairing_side($toId, $eventCode, $matchNo, $row['sideA'] ?? '');
        write_pairing_side($toId, $eventCode, $matchNo + 1, $row['sideB'] ?? '');
        $eventsToRefresh[$eventCode] = $eventCode;
    }

    foreach ($eventsToRefresh as $eventCode) {
        safe_w_sql("DELETE FROM TeamFinComponent WHERE TfcTournament=" . StrSafe_DB($toId) . " AND TfcEvent=" . StrSafe_DB($eventCode));
        safe_w_sql("INSERT INTO TeamFinComponent (TfcCoId, TfcSubTeam, TfcTournament, TfcEvent, TfcId, TfcOrder, TfcTimeStamp)
            SELECT TcCoId, TcSubTeam, TcTournament, TcEvent, TcId, TcOrder, NOW()
            FROM TeamComponent
            INNER JOIN TeamFinals ON TfTeam=TcCoId AND TfSubTeam=TcSubTeam AND TfEvent=TcEvent AND TfTournament=TcTournament
            WHERE TcFinEvent=1 AND TcTournament=" . StrSafe_DB($toId) . " AND TcEvent=" . StrSafe_DB($eventCode));
        safe_w_sql("UPDATE Events SET EvShootOff=1 WHERE EvTournament=" . StrSafe_DB($toId) . " AND EvTeamEvent=1 AND EvCode=" . StrSafe_DB($eventCode));
    }
}

function write_pairing_side($toId, $eventCode, $matchNo, $teamKey) {
    [$countryId, $subTeam] = parse_team_key($teamKey);
    safe_w_sql("INSERT INTO TeamFinals (TfEvent, TfMatchNo, TfTournament, TfTeam, TfSubTeam, TfDateTime)
        VALUES (" . StrSafe_DB($eventCode) . ", " . StrSafe_DB($matchNo) . ", " . StrSafe_DB($toId) . ", " . StrSafe_DB($countryId) . ", " . StrSafe_DB($subTeam) . ", NOW())
        ON DUPLICATE KEY UPDATE
            TfTeam=VALUES(TfTeam),
            TfSubTeam=VALUES(TfSubTeam),
            TfDateTime=VALUES(TfDateTime)");
}
?>
