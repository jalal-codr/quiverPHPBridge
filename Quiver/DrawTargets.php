<?php
/**
 * Quiver → Ianseo bridge: Auto Draw Targets
 *
 * Automatically assigns target numbers to all participants in a tournament
 * who don't yet have a target assigned.
 *
 * Groups athletes by division+class, assigns sequential targets A/B/C/D.
 * Returns the full target list so Quiver can display it.
 *
 * POST body:
 * {
 *   "toId": 123,
 *   "session": 1,          // qualification session (default 1)
 *   "archersPerTarget": 3, // A/B/C/D per target, max 4 (default 3)
 *   "startTarget": 1       // first target number (default 1)
 * }
 *
 * Only accepts requests from localhost.
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
header('Content-Type: application/json');

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '127.0.0.1' && $ip !== '::1') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['toId']) || !is_numeric($input['toId'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid toId']);
    exit;
}

$toId            = intval($input['toId']);
$session         = intval($input['session'] ?? 1);
$archersPerTarget = intval($input['archersPerTarget'] ?? 3);
$startTarget     = intval($input['startTarget'] ?? 1);

// Clamp archersPerTarget to valid values
if (!in_array($archersPerTarget, [1, 2, 3, 4])) {
    $archersPerTarget = 3;
}

// Build letter array: A, B, C, ... up to archersPerTarget
$letters = array_slice(['A','B','C','D'], 0, $archersPerTarget);

// Verify tournament exists
$q = safe_r_sql("SELECT ToId, ToType FROM Tournament WHERE ToId=" . StrSafe_DB($toId));
if (safe_num_rows($q) === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Tournament not found']);
    exit;
}

// Ensure session exists, create if not
$qSes = safe_r_sql("SELECT SesOrder FROM Session
    WHERE SesTournament=$toId AND SesType='Q' AND SesOrder=$session");
if (safe_num_rows($qSes) === 0) {
    safe_w_sql("INSERT INTO Session (SesTournament, SesOrder, SesType, SesName, SesAth4Target)
        VALUES ($toId, $session, 'Q', 'Qualification', $archersPerTarget)
        ON DUPLICATE KEY UPDATE SesAth4Target=$archersPerTarget");
} else {
    // Update archers per target
    safe_w_sql("UPDATE Session SET SesAth4Target=$archersPerTarget
        WHERE SesTournament=$toId AND SesType='Q' AND SesOrder=$session");
}

// Get all entries that need target assignment, grouped by division+class
$q = safe_r_sql("
    SELECT e.EnId, e.EnDivision, e.EnClass, e.EnName, e.EnFirstName,
           COALESCE(c.CoCode, 'UNK') as CoCode,
           q.QuTarget, q.QuLetter, q.QuSession
    FROM Entries e
    INNER JOIN Qualifications q ON q.QuId = e.EnId
    LEFT JOIN Countries c ON c.CoId = e.EnCountry
    WHERE e.EnTournament = $toId
    ORDER BY e.EnDivision, e.EnClass, e.EnId
");

if (safe_num_rows($q) === 0) {
    echo json_encode(['success' => true, 'assigned' => 0, 'targets' => [], 'message' => 'No participants found']);
    exit;
}

$entries = [];
while ($r = safe_fetch($q)) {
    $entries[] = $r;
}

// Assign targets sequentially
$currentTarget = $startTarget;
$currentLetterIdx = 0;
$assigned = 0;
$results = [];

foreach ($entries as $entry) {
    // Set session if not set
    if (!$entry->QuSession) {
        safe_w_sql("UPDATE Qualifications SET QuSession=$session WHERE QuId={$entry->EnId}");
    }

    // Assign target
    $letter = $letters[$currentLetterIdx];
    $targetNo = $currentTarget;

    safe_w_sql("UPDATE Qualifications
        SET QuTarget=$targetNo,
            QuLetter=" . StrSafe_DB($letter) . ",
            QuSession=$session,
            QuBacknoPrinted=0
        WHERE QuId={$entry->EnId}");

    safe_w_sql("UPDATE Entries
        SET EnTimestamp='" . date('Y-m-d H:i:s') . "',
            EnMainInfoUpdate='" . date('Y-m-d H:i:s') . "'
        WHERE EnId={$entry->EnId}");

    $results[] = [
        'enId'       => intval($entry->EnId),
        'name'       => trim($entry->EnName . ' ' . $entry->EnFirstName),
        'division'   => trim($entry->EnDivision),
        'class'      => trim($entry->EnClass),
        'country'    => $entry->CoCode,
        'target'     => $targetNo,
        'letter'     => $letter,
        'targetFull' => str_pad($targetNo, 3, '0', STR_PAD_LEFT) . $letter,
    ];

    $assigned++;

    // Advance to next position
    $currentLetterIdx++;
    if ($currentLetterIdx >= $archersPerTarget) {
        $currentLetterIdx = 0;
        $currentTarget++;
    }
}

// Get final target list for display
$qFinal = safe_r_sql("
    SELECT e.EnId, e.EnName, e.EnFirstName, e.EnDivision, e.EnClass,
           COALESCE(c.CoCode, 'UNK') as CoCode,
           q.QuTarget, q.QuLetter, q.QuSession
    FROM Entries e
    INNER JOIN Qualifications q ON q.QuId = e.EnId
    LEFT JOIN Countries c ON c.CoId = e.EnCountry
    WHERE e.EnTournament = $toId AND q.QuTarget > 0
    ORDER BY q.QuTarget, q.QuLetter
");

$targetList = [];
while ($r = safe_fetch($qFinal)) {
    $targetList[] = [
        'enId'       => intval($r->EnId),
        'name'       => trim($r->EnName . ' ' . $r->EnFirstName),
        'division'   => trim($r->EnDivision),
        'class'      => trim($r->EnClass),
        'country'    => $r->CoCode,
        'target'     => intval($r->QuTarget),
        'letter'     => $r->QuLetter,
        'targetFull' => str_pad($r->QuTarget, 3, '0', STR_PAD_LEFT) . $r->QuLetter,
        'session'    => intval($r->QuSession),
    ];
}

safe_close();

echo json_encode([
    'success'          => true,
    'toId'             => $toId,
    'assigned'         => $assigned,
    'archersPerTarget' => $archersPerTarget,
    'totalTargets'     => $currentTarget - $startTarget + ($currentLetterIdx > 0 ? 1 : 0),
    'targets'          => $targetList,
]);
