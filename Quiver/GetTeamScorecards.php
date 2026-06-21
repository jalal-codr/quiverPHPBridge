<?php
/**
 * Quiver -> Ianseo bridge: Generate team scorecard PDF with native QR codes.
 *
 * POST body:
 * {
 *   "toId": 123,
 *   "events": ["RMT", "CWT"],
 *   "includeEmpty": true,
 *   "scoreBarcode": true,
 *   "scoreKeeperQr": true
 * }
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

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['toId']) || !is_numeric($input['toId'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing or invalid toId']);
    exit;
}

$toId = intval($input['toId']);
$events = $input['events'] ?? [];
if (!is_array($events) || count($events) === 0) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Select at least one team event']);
    exit;
}

$q = safe_r_sql("SELECT ToId FROM Tournament WHERE ToId=" . StrSafe_DB($toId));
if (safe_num_rows($q) !== 1) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Tournament not found']);
    exit;
}

CreateTourSession($toId);

$cleanEvents = [];
foreach ($events as $event) {
    $eventCode = trim(strval($event));
    if ($eventCode === '') continue;
    $eventQ = safe_r_sql("SELECT EvCode FROM Events WHERE EvTournament=" . StrSafe_DB($toId) . " AND EvTeamEvent=1 AND EvCode=" . StrSafe_DB($eventCode));
    if (safe_num_rows($eventQ) === 1) {
        $cleanEvents[] = $eventCode;
    }
}

if (count($cleanEvents) === 0) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No selected team events were found in Ianseo']);
    exit;
}

$_REQUEST = [];
$_REQUEST['Event'] = $cleanEvents;
if (!empty($input['includeEmpty'])) {
    $_REQUEST['IncEmpty'] = 1;
}
if (!isset($input['scoreBarcode']) || !empty($input['scoreBarcode'])) {
    $_REQUEST['Barcode'] = 1;
}
if (!isset($input['scoreKeeperQr']) || !empty($input['scoreKeeperQr'])) {
    $_REQUEST['QRCode'] = ['ISK-NG'];
}

ob_start();
try {
    include($CFG->DOCUMENT_PATH . 'Final/Team/PDFScore.php');
} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Failed to generate team scorecards: ' . $e->getMessage()]);
    exit;
}
$pdf = ob_get_clean();

if ($pdf === '' || $pdf === false) {
    http_response_code(422);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No team scorecards were generated. Make sure team finalists have been created in Ianseo.']);
    exit;
}

$filename = 'team_scorecards_' . ($_SESSION['TourCode'] ?? $toId) . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo $pdf;
exit;
?>
