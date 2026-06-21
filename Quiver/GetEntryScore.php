<?php
require_once(__DIR__ . '/_bootstrap.php');
quiver_bootstrap(true);
$input = quiver_input();
$toId = intval($input['toId'] ?? 0);
$entryId = intval($input['entryId'] ?? 0);
$distance = intval($input['distance'] ?? 1);
if (!$toId || !$entryId || $distance < 1 || $distance > 8) {
    quiver_error(400, 'Missing or invalid toId, entryId, or distance');
}

$q = safe_r_sql("SELECT e.EnId, e.EnCode, e.EnName, e.EnFirstName, e.EnDivision, e.EnClass,
    q.QuSession, q.QuTarget, q.QuLetter,
    q.QuD{$distance}ArrowString AS ArrowString,
    q.QuD{$distance}Score AS DScore,
    q.QuD{$distance}Hits AS DHits,
    q.QuD{$distance}Gold AS DGold,
    q.QuD{$distance}Xnine AS DXnine,
    q.QuScore, q.QuGold, q.QuXnine, q.QuHits
    FROM Entries e
    INNER JOIN Qualifications q ON q.QuId=e.EnId
    WHERE e.EnTournament=$toId AND e.EnId=$entryId");
if (!$q || !($r = safe_fetch($q))) quiver_error(404, 'Entry score not found');

$target = intval($r->QuTarget ?? 0);
$letter = trim($r->QuLetter ?? '');
echo json_encode([
    'success' => true,
    'entry' => [
        'enId' => intval($r->EnId),
        'bibCode' => $r->EnCode,
        'familyName' => $r->EnName,
        'givenName' => $r->EnFirstName,
        'division' => $r->EnDivision,
        'class' => $r->EnClass,
        'session' => intval($r->QuSession ?? 0),
        'target' => $target,
        'letter' => $letter,
        'targetFull' => $target > 0 ? str_pad($target, 3, '0', STR_PAD_LEFT) . $letter : '',
    ],
    'distance' => $distance,
    'arrowstring' => $r->ArrowString ?? '',
    'score' => intval($r->DScore ?? 0),
    'hits' => intval($r->DHits ?? 0),
    'gold' => intval($r->DGold ?? 0),
    'xnine' => intval($r->DXnine ?? 0),
    'totalScore' => intval($r->QuScore ?? 0),
    'totalGold' => intval($r->QuGold ?? 0),
    'totalXNine' => intval($r->QuXnine ?? 0),
    'totalHits' => intval($r->QuHits ?? 0),
]);
