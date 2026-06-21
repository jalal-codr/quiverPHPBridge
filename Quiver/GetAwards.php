<?php
require_once(__DIR__ . '/_bootstrap.php');
quiver_bootstrap(false);

$input = quiver_input();
$toId = intval($input['toId'] ?? 0);
if (!$toId) {
    header('Content-Type: application/json');
    quiver_error(400, 'Missing or invalid toId');
}

$type = $input['type'] ?? 'ceremony';
$allowed = array(
    'ceremony' => 'Tournament/PDFAward.php',
    'positions' => 'Tournament/PDFAward-Positions.php',
    'check' => 'Tournament/PDFAward-check.php',
);

if (!isset($allowed[$type])) {
    header('Content-Type: application/json');
    quiver_error(400, 'Unsupported awards print type');
}

$_SESSION['TourId'] = $toId;
CreateTourSession($toId);

require($CFG->DOCUMENT_PATH . $allowed[$type]);
