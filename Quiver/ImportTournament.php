<?php
/**
 * Quiver -> Ianseo bridge: Import native .ianseo tournament export.
 */
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '240');
require_once(__DIR__ . '/_bootstrap.php');
quiver_bootstrap(true);
require_once($CFG->DOCUMENT_PATH . 'Common/Fun_FormatText.inc.php');
require_once($CFG->DOCUMENT_PATH . 'Common/Fun_TourDelete.php');

$input = quiver_input();
if (empty($input['fileBase64'])) quiver_error(400, 'Missing fileBase64');

$binary = base64_decode($input['fileBase64'], true);
if ($binary === false || strlen($binary) === 0) quiver_error(400, 'Invalid .ianseo file payload');

$gara = @unserialize(@gzuncompress($binary));
if (!is_array($gara) || empty($gara['Tournament']) || empty($gara['Tournament']['ToCode'])) {
    quiver_error(400, 'The uploaded file is not a valid Ianseo tournament export');
}

$replaceExisting = !empty($input['replaceExisting']);
$code = $gara['Tournament']['ToCode'];
$exportDbVersion = $gara['Tournament']['ToDbVersion'] ?? null;
$localDbVersion = GetParameter('DBUpdate');

if ($exportDbVersion && $localDbVersion && $exportDbVersion > $localDbVersion) {
    quiver_error(422, 'This .ianseo export was created by a newer Ianseo database than this server can import.', [
        'toCode' => $code,
        'exportDbVersion' => $exportDbVersion,
        'localDbVersion' => $localDbVersion,
    ]);
}

$existingId = getIdFromCode($code);
if ($existingId && !$replaceExisting) {
    quiver_error(409, "Tournament code $code already exists in this Ianseo server. Enable replace to import it again.", [
        'toCode' => $code,
        'toId' => intval($existingId),
    ]);
}

$toId = tour_import($binary, true);
if (!$toId) {
    $lastError = error_get_last();
    quiver_error(422, 'Ianseo could not import this tournament export', [
        'toCode' => $code,
        'exportDbVersion' => $exportDbVersion,
        'localDbVersion' => $localDbVersion,
        'entriesInExport' => isset($gara['Entries']) && is_array($gara['Entries']) ? count($gara['Entries']) : 0,
        'tablesInExport' => count($gara),
        'phpError' => $lastError ? $lastError['message'] : null,
    ]);
}

$row = null;
$q = safe_r_sql("SELECT ToId, ToCode, ToName FROM Tournament WHERE ToId=" . intval($toId));
if ($q) $row = safe_fetch($q);

$toIdInt = intval($toId);
echo json_encode([
    'success' => true,
    'toId' => $toIdInt,
    'toCode' => $row ? $row->ToCode : $code,
    'toName' => $row ? $row->ToName : '',
    'replacedExisting' => (bool) $existingId,
    'counts' => [
        'entries' => quiver_count("SELECT COUNT(*) AS Cnt FROM Entries WHERE EnTournament=$toIdInt AND EnAthlete=1"),
        'sessions' => quiver_count("SELECT COUNT(*) AS Cnt FROM Session WHERE SesTournament=$toIdInt"),
        'events' => quiver_count("SELECT COUNT(*) AS Cnt FROM Events WHERE EvTournament=$toIdInt"),
        'schedules' => quiver_count("SELECT COUNT(*) AS Cnt FROM Scheduler WHERE SchTournament=$toIdInt"),
    ],
]);
