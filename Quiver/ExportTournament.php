<?php
/**
 * Quiver -> Ianseo bridge: Export native .ianseo tournament file.
 */
require_once(__DIR__ . '/_bootstrap.php');
quiver_bootstrap(false);
require_once($CFG->DOCUMENT_PATH . 'Common/Lib/Fun_Export.php');

$input = quiver_input();
$toId = intval($_GET['toId'] ?? $input['toId'] ?? 0);
$complete = intval($_GET['complete'] ?? $input['complete'] ?? 0);
if (!$toId) {
    header('Content-Type: application/json');
    quiver_error(400, 'Missing or invalid toId');
}

$q = safe_r_sql("SELECT ToCode FROM Tournament WHERE ToId=" . StrSafe_DB($toId));
if (!$q || !($row = safe_fetch($q))) {
    header('Content-Type: application/json');
    quiver_error(404, 'Tournament not found');
}

if (!function_exists('export_tournament')) {
    header('Content-Type: application/json');
    quiver_error(500, 'Ianseo export function is unavailable');
}

$gara = export_tournament($toId, (bool)$complete);
if (!$gara || !is_array($gara)) {
    header('Content-Type: application/json');
    quiver_error(500, 'Ianseo could not export this tournament');
}

$payload = gzcompress(serialize($gara), 9);
if ($payload === false) {
    header('Content-Type: application/json');
    quiver_error(500, 'Ianseo could not package this tournament export');
}

$filename = preg_replace('/[^A-Za-z0-9_.-]+/', '_', $row->ToCode) . '.ianseo';
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($payload));
echo $payload;
