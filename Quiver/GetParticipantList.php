<?php
require_once(__DIR__ . '/_bootstrap.php');
quiver_bootstrap(true);
$input = quiver_input();
$toId = intval($input['toId'] ?? $_GET['toId'] ?? 0);
if (!$toId) quiver_error(400, 'Missing or invalid toId');

$q = safe_r_sql("
    SELECT e.*, c.CoCode, c.CoName, q.QuSession, q.QuTarget, q.QuLetter,
        q.QuScore, q.QuGold, q.QuXnine, q.QuHits
    FROM Entries e
    LEFT JOIN Countries c ON c.CoId=e.EnCountry
    LEFT JOIN Qualifications q ON q.QuId=e.EnId
    WHERE e.EnTournament=$toId AND e.EnAthlete=1
    ORDER BY e.EnCode, e.EnName, e.EnFirstName
");

$participants = [];
while ($r = safe_fetch($q)) {
    $target = intval($r->QuTarget ?? 0);
    $letter = trim($r->QuLetter ?? '');
    $raw = [];
    foreach ((array)$r as $key => $value) $raw[$key] = $value;
    $participants[] = [
        'enId' => intval($r->EnId),
        'bibCode' => $r->EnCode,
        'familyName' => $r->EnName,
        'givenName' => $r->EnFirstName,
        'division' => $r->EnDivision,
        'class' => $r->EnClass,
        'ageClass' => $r->EnAgeClass,
        'subClass' => $r->EnSubClass,
        'gender' => strval($r->EnSex),
        'clubCode' => $r->CoCode ?: $r->EnIocCode,
        'clubName' => $r->CoName ?: ($r->CoCode ?: $r->EnIocCode),
        'session' => intval($r->QuSession ?? 0),
        'target' => $target,
        'letter' => $letter,
        'targetFull' => $target > 0 ? str_pad($target, 3, '0', STR_PAD_LEFT) . $letter : '',
        'hasTarget' => $target > 0 && $letter !== '',
        'score' => intval($r->QuScore ?? 0),
        'gold' => intval($r->QuGold ?? 0),
        'xnine' => intval($r->QuXnine ?? 0),
        'hits' => intval($r->QuHits ?? 0),
        'rawEntry' => $raw,
    ];
}
echo json_encode(['success' => true, 'participants' => $participants]);
