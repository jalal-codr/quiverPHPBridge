<?php
require_once(__DIR__ . '/_bootstrap.php');
quiver_bootstrap(false);
$input = quiver_input();
$toId = intval($input['toId'] ?? 0);
if (!$toId) { header('Content-Type: application/json'); quiver_error(400, 'Missing or invalid toId'); }
$_SESSION['TourId'] = $toId;
CreateTourSession($toId);
$type = $input['listType'] ?? 'alphabetical';
if (($input['operationType'] ?? '') === 'Accreditation') {
    if ($type === 'country') require($CFG->DOCUMENT_PATH . 'Accreditation/PrnCountry.php');
    elseif ($type === 'session') require($CFG->DOCUMENT_PATH . 'Accreditation/PrnSession.php');
    else require($CFG->DOCUMENT_PATH . 'Accreditation/PrnAlphabetical.php');
} else {
    if ($type === 'country') require($CFG->DOCUMENT_PATH . 'Partecipants/PrnCountry.php');
    elseif ($type === 'session') require($CFG->DOCUMENT_PATH . 'Partecipants/PrnSession.php');
    else require($CFG->DOCUMENT_PATH . 'Partecipants/PrnAlphabetical.php');
}
