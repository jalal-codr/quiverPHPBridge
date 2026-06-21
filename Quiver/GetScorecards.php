<?php
require_once(__DIR__ . '/_bootstrap.php');
quiver_bootstrap(false);
$input = quiver_input();
$toId = intval($input['toId'] ?? 0);
if (!$toId) { header('Content-Type: application/json'); quiver_error(400, 'Missing or invalid toId'); }
$_SESSION['TourId'] = $toId;
CreateTourSession($toId);

$_REQUEST = [
    'x_Session' => intval($input['session'] ?? 1),
    'x_From' => intval($input['fromTarget'] ?? 1),
    'x_To' => intval($input['toTarget'] ?? 999),
    'ScoreDraw' => $input['scoreDraw'] ?? 'Complete',
    'ScoreDist' => is_array($input['scoreDist'] ?? null) ? $input['scoreDist'] : [1],
    'ScoreHeader' => !empty($input['scoreHeader']) ? 1 : 0,
    'ScoreFlags' => !empty($input['scoreFlags']) ? 1 : 0,
    'ScoreLogos' => !empty($input['scoreLogos']) ? 1 : 0,
    'QRCode' => !empty($input['scoreKeeperQr']) ? ['ISK-NG'] : [],
];
if (!empty($input['scoreFilled'])) $_REQUEST['ScoreFilled'] = 1;
if (!empty($input['personalScore'])) $_REQUEST['PersonalScore'] = 1;
if (!empty($input['noEmpty'])) $_REQUEST['noEmpty'] = 1;
if (!empty($input['scoreCollector'])) {
    $_GET['arr'] = intval($input['collectorArrows'] ?? 6);
    require($CFG->DOCUMENT_PATH . 'Qualification/PDFScoreCollect.php');
} else {
    require($CFG->DOCUMENT_PATH . 'Qualification/PDFScore.php');
}
