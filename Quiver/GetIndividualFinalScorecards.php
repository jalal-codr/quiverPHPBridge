<?php
/**
 * Quiver -> Ianseo bridge: individual finals single-match scorecards.
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '127.0.0.1' && $ip !== '::1') {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->DOCUMENT_PATH . 'Common/Fun_Sessions.inc.php');
require_once($CFG->DOCUMENT_PATH . 'Common/Lib/Fun_Phases.inc.php');

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['toId']) || !is_numeric($input['toId'])) {
    json_fail('Missing or invalid toId', 400);
}

$toId = intval($input['toId']);
$action = trim($input['action'] ?? 'load');

$q = safe_r_sql("SELECT ToId FROM Tournament WHERE ToId=" . StrSafe_DB($toId));
if (safe_num_rows($q) !== 1) {
    json_fail('Tournament not found', 404);
}

CreateTourSession($toId);

if ($action === 'load') {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'events' => load_final_events($toId),
    ]);
    exit;
}

if ($action !== 'print') {
    json_fail('Unknown action', 400);
}

$events = clean_list($input['events'] ?? []);
$phases = clean_phase_list($input['phases'] ?? []);
if (count($events) === 0) {
    json_fail('Select at least one individual finals event', 400);
}
if (count($phases) === 0) {
    json_fail('Select at least one finals phase', 400);
}

$_REQUEST = [];
$_REQUEST['Event'] = $events;
$_REQUEST['Phase'] = $phases;
if (!empty($input['scoreFilled'])) $_REQUEST['ScoreFilled'] = 1;
if (!empty($input['includeEmpty'])) $_REQUEST['IncEmpty'] = 1;
if (!empty($input['includeAllNames'])) $_REQUEST['IncAllNames'] = 1;
if (!empty($input['scoreFlags'])) $_REQUEST['ScoreFlags'] = 1;
if (!isset($input['scoreBarcode']) || !empty($input['scoreBarcode'])) $_REQUEST['Barcode'] = 1;
if (!isset($input['scoreKeeperQr']) || !empty($input['scoreKeeperQr'])) $_REQUEST['QRCode'] = ['ISK-NG'];
if (!empty($input['scoreQrPersonal'])) $_REQUEST['ScoreQrPersonal'] = 1;

ob_start();
try {
    include($CFG->DOCUMENT_PATH . 'Final/Individual/PDFScoreMatch.php');
} catch (Throwable $e) {
    ob_end_clean();
    json_fail('Failed to generate finals scorecards: ' . $e->getMessage(), 500);
}
$pdf = ob_get_clean();

if ($pdf === '' || $pdf === false) {
    json_fail('No finals scorecards were generated for the selected events/phases', 422);
}

$filename = 'individual_final_scorecards_' . ($_SESSION['TourCode'] ?? $toId) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo $pdf;
exit;

function json_fail($message, $status = 500) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

function clean_list($items) {
    if (!is_array($items)) $items = [$items];
    $clean = [];
    foreach ($items as $item) {
        $value = substr(preg_replace('/[^A-Z0-9_]/', '', strtoupper(trim(strval($item)))), 0, 10);
        if ($value !== '') $clean[$value] = $value;
    }
    return array_values($clean);
}

function clean_phase_list($items) {
    if (!is_array($items)) $items = [$items];
    $clean = [];
    foreach ($items as $item) {
        $phase = intval($item);
        if (in_array($phase, [0, 1, 2, 4, 8, 16, 24, 32, 48, 64], true)) {
            if ($phase === 24) $phase = 32;
            if ($phase === 48) $phase = 64;
            $clean[$phase] = $phase;
        }
    }
    krsort($clean, SORT_NUMERIC);
    return array_values($clean);
}

function load_final_events($toId) {
    $events = [];
    $query = "SELECT EvCode, EvEventName, GrPhase, MAX(IF(FinAthlete=0,0,1)) AS Printable
        FROM Events
        INNER JOIN Phases ON PhId=EvFinalFirstPhase AND (PhIndTeam & 1)=1
        INNER JOIN Finals ON EvCode=FinEvent AND EvTournament=FinTournament
        INNER JOIN Grids ON FinMatchNo=GrMatchNo AND IF(EvElimType=3, TRUE, GrPhase<=GREATEST(PhId, PhLevel))
        WHERE EvTournament=" . StrSafe_DB($toId) . " AND EvTeamEvent=0 AND EvFinalFirstPhase!=0
        GROUP BY EvCode, EvEventName, EvFinalFirstPhase, GrPhase
        ORDER BY EvCode, GrPhase DESC";
    $rs = safe_r_sql($query);
    while ($row = safe_fetch($rs)) {
        if (!isset($events[$row->EvCode])) {
            $events[$row->EvCode] = [
                'code' => $row->EvCode,
                'name' => $row->EvEventName,
                'phases' => [],
            ];
        }
        $phase = intval($row->GrPhase);
        $events[$row->EvCode]['phases'][] = [
            'value' => $phase,
            'label' => phase_label($phase),
            'printable' => intval($row->Printable) === 1,
        ];
    }
    return array_values($events);
}

function phase_label($phase) {
    $phase = intval($phase);
    if ($phase === 0) return 'Gold';
    if ($phase === 1) return 'Bronze / Gold';
    if ($phase === 2) return 'Semi-final';
    if ($phase === 4) return 'Quarter-final';
    if ($phase === 8) return '1/8';
    if ($phase === 16) return '1/16';
    if ($phase === 32) return '1/32 - 1/24';
    if ($phase === 64) return '1/64 - 1/48';
    return 'Phase ' . $phase;
}
?>
