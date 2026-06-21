<?php
/**
 * Get all archers assigned to targets for a tournament session.
 * Returns target list with archer details and the refKey needed for ISK-NG scoring.
 * refKey format for qualification: EnCode (bib number)
 */
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
header('Content-Type: application/json');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '127.0.0.1' && $ip !== '::1') { http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit; }
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['toId'])) { http_response_code(400); echo json_encode(['error'=>'Missing toId']); exit; }
$toId = intval($input['toId']);
$session = intval($input['session'] ?? 1);

$q = safe_r_sql("
    SELECT 
        e.EnId, e.EnCode as bibCode, e.EnName as familyName, e.EnFirstName as givenName,
        e.EnDivision as division, e.EnClass as class, e.EnSex as sex,
        COALESCE(c.CoCode, 'UNK') as countryCode,
        q.QuTarget as target, q.QuLetter as letter, q.QuSession as session,
        CONCAT(LPAD(q.QuTarget, 3, '0'), q.QuLetter) as targetFull
    FROM Entries e
    INNER JOIN Qualifications q ON q.QuId = e.EnId
    LEFT JOIN Countries c ON c.CoId = e.EnCountry
    WHERE e.EnTournament = $toId
      AND q.QuTarget > 0
      AND q.QuSession = $session
    ORDER BY q.QuTarget, q.QuLetter
");

$targets = [];
while ($r = safe_fetch($q)) {
    $targetNo = intval($r->target);
    if (!isset($targets[$targetNo])) {
        $targets[$targetNo] = ['target' => $targetNo, 'archers' => []];
    }
    $targets[$targetNo]['archers'][] = [
        'enId'       => intval($r->EnId),
        'bibCode'    => $r->bibCode,
        'familyName' => $r->familyName,
        'givenName'  => $r->givenName,
        'division'   => $r->division,
        'class'      => $r->class,
        'countryCode'=> $r->countryCode,
        'letter'     => $r->letter,
        'targetFull' => $r->targetFull,
        // refKey for ISK-NG sendall = bib code
        'refKey'     => $r->bibCode,
    ];
}

safe_close();
echo json_encode(['success' => true, 'toId' => $toId, 'session' => $session, 'targets' => array_values($targets)]);
