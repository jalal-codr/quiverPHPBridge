<?php
require_once(__DIR__ . '/_bootstrap.php');
quiver_bootstrap(false);
$input = quiver_input();
$toId = intval($input['toId'] ?? 0);
if (!$toId) { header('Content-Type: application/json'); quiver_error(400, 'Missing or invalid toId'); }
$_SESSION['TourId'] = $toId;
CreateTourSession($toId);
if (!empty($input['daily'])) $_REQUEST['Daily'] = 1;
if (!empty($input['fromDay'])) $_REQUEST['FromDay'] = $input['fromDay'];
require($CFG->DOCUMENT_PATH . 'Scheduler/PrnScheduler.php');
