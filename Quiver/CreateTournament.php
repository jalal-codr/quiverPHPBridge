<?php
/**
 * Quiver → Ianseo bridge: Create Tournament
 * Only accepts requests from localhost.
 */

// Suppress notices so JSON output stays clean
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

header('Content-Type: application/json');

// Only allow localhost calls
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '127.0.0.1' && $ip !== '::1') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Start session only if one isn't already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['TourId'] = -1;

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');

if (function_exists('get_text') === false) {
    require_once($CFG->DOCUMENT_PATH . 'Common/Fun_FormatText.inc.php');
}
require_once($CFG->DOCUMENT_PATH . 'Common/Fun_Various.inc.php');
require_once($CFG->DOCUMENT_PATH . 'Tournament/Fun_Tournament.local.inc.php');
require_once($CFG->DOCUMENT_PATH . 'Common/Fun_ScriptsOnNewTour.inc.php');

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON body']);
    exit;
}

// ── Required fields ──────────────────────────────────────────────────────────
$required = ['name', 'code', 'startDate', 'endDate', 'country', 'where'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: $field"]);
        exit;
    }
}

// ── Sanitize inputs ───────────────────────────────────────────────────────────
$ToCode     = preg_replace('/[^0-9a-z._-]+/sim', '_', strtoupper($input['code']));
$ToName     = substr(strip_tags($input['name']), 0, 100);
$ToShort    = substr(strip_tags($input['shortName'] ?? $input['name']), 0, 60);
$ToWhere    = substr(strip_tags($input['where']), 0, 100);
$ToVenue    = substr(strip_tags($input['venue'] ?? ''), 0, 100);
$ToCountry  = substr(strip_tags($input['country']), 0, 3);
$ToCommitee = substr(strip_tags($input['committee'] ?? 'QVR'), 0, 10);
$ToComDescr = substr(strip_tags($input['committeeDesc'] ?? 'Created via Quiver'), 0, 100);
$ToTimeZone = '+00:00';
$ToType     = $input['toType'] ?? '1';   // 1 = WA Outdoor (default)
$ToRule     = $input['rule'] ?? 'WA';    // WA = World Archery rules
$ToCurrency = $input['currency'] ?? 'GHS';

// Parse dates
$startDate = date_create($input['startDate']);
$endDate   = date_create($input['endDate']);

if (!$startDate || !$endDate) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD']);
    exit;
}

$fromYear  = date_format($startDate, 'Y');
$fromMonth = date_format($startDate, 'm');
$fromDay   = date_format($startDate, 'd');
$toYear    = date_format($endDate, 'Y');
$toMonth   = date_format($endDate, 'm');
$toDay     = date_format($endDate, 'd');

// ── Check code is unique ──────────────────────────────────────────────────────
$existing = safe_r_sql("SELECT ToId FROM Tournament WHERE ToCode=" . StrSafe_DB($ToCode));
if (safe_num_rows($existing) > 0) {
    // Append timestamp to make it unique
    $ToCode = $ToCode . '_' . date('ymd');
}

// ── Get DBUpdate version ──────────────────────────────────────────────────────
$dbVersion = GetParameter('DBUpdate');

// ── Insert tournament ─────────────────────────────────────────────────────────
$sql = "INSERT INTO Tournament (
    ToType, ToCode, ToName, ToNameShort, ToIocCode,
    ToCommitee, ToComDescr, ToWhere, ToTimeZone,
    ToWhenFrom, ToWhenTo, ToCurrency, ToPrintLang,
    ToPrintChars, ToPrintPaper, ToUseHHT, ToDbVersion,
    ToTypeSubRule, ToLocRule, ToIsORIS, ToVenue, ToCountry
) VALUES (
    " . StrSafe_DB($ToType) . ",
    " . StrSafe_DB($ToCode) . ",
    " . StrSafe_DB($ToName) . ",
    " . StrSafe_DB($ToShort) . ",
    '',
    " . StrSafe_DB($ToCommitee) . ",
    " . StrSafe_DB($ToComDescr) . ",
    " . StrSafe_DB($ToWhere) . ",
    " . StrSafe_DB($ToTimeZone) . ",
    " . StrSafe_DB(sprintf('%04d-%02d-%02d', $fromYear, $fromMonth, $fromDay)) . ",
    " . StrSafe_DB(sprintf('%04d-%02d-%02d', $toYear, $toMonth, $toDay)) . ",
    " . StrSafe_DB($ToCurrency) . ",
    'en',
    0, 0, 0,
    " . StrSafe_DB($dbVersion) . ",
    '',
    " . StrSafe_DB($ToRule) . ",
    0,
    " . StrSafe_DB($ToVenue) . ",
    " . StrSafe_DB($ToCountry) . "
)";

$result = safe_w_sql($sql);

if (!$result) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to insert tournament into database']);
    exit;
}

$ToId = safe_w_last_id();

if (!$ToId) {
    http_response_code(500);
    echo json_encode(['error' => 'Tournament inserted but could not retrieve ID']);
    exit;
}

// ── Run Ianseo setup scripts (creates events, targets, etc.) ──────────────────
$_SESSION['TourId'] = $ToId;
$_SESSION['TourRealWhenFrom'] = sprintf('%04d-%02d-%02d', $fromYear, $fromMonth, $fromDay);
$_SESSION['TourRealWhenTo']   = sprintf('%04d-%02d-%02d', $toYear, $toMonth, $toDay);

try {
    GetSetupFile($ToId, $ToType, $ToRule, '1', '');
    calcMaxTeamPerson([], true, $ToId);
} catch (Throwable $e) {
    // Setup scripts failed — tournament still created, log the error
    error_log('Quiver CreateTournament setup error: ' . $e->getMessage());
}

safe_close();

echo json_encode([
    'success' => true,
    'toId'    => $ToId,
    'toCode'  => $ToCode,
    'toName'  => $ToName,
]);
