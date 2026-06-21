<?php
require_once(__DIR__ . '/_bootstrap.php');
quiver_bootstrap(true);
$input = quiver_input();
$toId = intval($input['toId'] ?? $_GET['toId'] ?? 0);
if (!$toId) quiver_error(400, 'Missing or invalid toId');

$q = safe_r_sql("SELECT e.EnId, e.EnCode, e.EnName, e.EnFirstName, e.EnDivision, e.EnClass,
    COALESCE(c.CoCode, '') AS CoCode, q.QuTarget, q.QuLetter, q.QuSession
    FROM Entries e
    INNER JOIN Qualifications q ON q.QuId=e.EnId
    LEFT JOIN Countries c ON c.CoId=e.EnCountry
    WHERE e.EnTournament=$toId
    ORDER BY q.QuSession, q.QuTarget, q.QuLetter, e.EnCode");
$targets = [];
while ($r = safe_fetch($q)) {
    $target = intval($r->QuTarget ?? 0);
    $letter = trim($r->QuLetter ?? '');
    $targets[intval($r->EnId)] = [
        'enId' => intval($r->EnId),
        'bibCode' => $r->EnCode,
        'name' => trim($r->EnName . ' ' . $r->EnFirstName),
        'division' => $r->EnDivision,
        'class' => $r->EnClass,
        'country' => $r->CoCode,
        'target' => $target,
        'letter' => $letter,
        'targetFull' => $target > 0 ? str_pad($target, 3, '0', STR_PAD_LEFT) . $letter : '',
        'session' => intval($r->QuSession ?? 0),
    ];
}
echo json_encode(['success' => true, 'targets' => $targets]);
