<?php
/**
 * Quiver → Ianseo bridge: Set Tournament Distances
 *
 * Sets the shooting distances for a tournament in Ianseo.
 * Updates:
 *   - Tournament.ToNumDist  (number of distances)
 *   - DistanceInformation   (distance details per session)
 *   - TournamentDistances   (distance names per division/class — wildcard %%)
 *
 * Accepts JSON POST:
 * {
 *   "toId": 123,
 *   "distances": [15, 18, 30],   // in metres, ordered
 *   "endsPerDistance": 10,        // default 10
 *   "arrowsPerEnd": 3             // default 3
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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['toId']) || !is_numeric($input['toId'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid toId']);
    exit;
}

if (empty($input['distances']) || !is_array($input['distances'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing distances array']);
    exit;
}

$toId          = intval($input['toId']);
$distances     = array_values(array_unique(array_map('intval', $input['distances'])));
sort($distances); // ascending order
$numDist       = count($distances);
$endsPerDist   = intval($input['endsPerDistance'] ?? 10);
$arrowsPerEnd  = intval($input['arrowsPerEnd'] ?? 3);

// ── Verify tournament exists ──────────────────────────────────────────────────
$q = safe_r_sql("SELECT ToId, ToType FROM Tournament WHERE ToId=" . StrSafe_DB($toId));
if (safe_num_rows($q) === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Tournament not found']);
    exit;
}
$tour = safe_fetch($q);

// ── Step 1: Update ToNumDist on the Tournament ────────────────────────────────
safe_w_sql("UPDATE Tournament SET ToNumDist=$numDist WHERE ToId=$toId");

// ── Step 2: Get or create session 1 (default qualification session) ───────────
$qSes = safe_r_sql("SELECT SesOrder FROM Session WHERE SesTournament=$toId AND SesType='Q' ORDER BY SesOrder LIMIT 1");
if (safe_num_rows($qSes) > 0) {
    $sesRow = safe_fetch($qSes);
    $sessionOrder = intval($sesRow->SesOrder);
} else {
    // Create a default qualification session
    safe_w_sql("INSERT INTO Session (SesTournament, SesOrder, SesType, SesName)
        VALUES ($toId, 1, 'Q', 'Qualification')
        ON DUPLICATE KEY UPDATE SesName='Qualification'");
    $sessionOrder = 1;
}

// ── Step 3: Insert DistanceInformation for each distance ─────────────────────
// DiDistance is the distance index (1, 2, 3...) not the metres value
// The actual metres value goes into TournamentDistances as the label
foreach ($distances as $idx => $metres) {
    $distIndex = $idx + 1; // 1-based
    safe_w_sql("INSERT INTO DistanceInformation
        (DiTournament, DiSession, DiDistance, DiType, DiEnds, DiArrows, DiScoringEnds)
        VALUES (
            $toId,
            $sessionOrder,
            $distIndex,
            'Q',
            $endsPerDist,
            $arrowsPerEnd,
            $endsPerDist
        )
        ON DUPLICATE KEY UPDATE
            DiEnds=$endsPerDist,
            DiArrows=$arrowsPerEnd,
            DiScoringEnds=$endsPerDist");
}

// Update ToNumDist to match actual number of distances
safe_w_sql("UPDATE Tournament SET ToNumDist=$numDist WHERE ToId=$toId");

// Update all ISK devices for this tournament to use correct schedule key
// Format: Q + numDist + session
$schedKey = 'Q' . $numDist . $sessionOrder;
safe_w_sql("UPDATE IskDevices SET IskDvSchedKey=" . StrSafe_DB($schedKey) . " WHERE IskDvTournament=$toId AND IskDvProActive=1");

// ── Step 4: Set TournamentDistances with wildcard %% (applies to all classes) ─
// First clear existing distance entries for this tournament
safe_w_sql("DELETE FROM TournamentDistances WHERE TdTournament=$toId AND TdType=" . StrSafe_DB($tour->ToType));

// Build the column assignments: Td1='15m', Td2='18m', etc.
$tdCols = [];
foreach ($distances as $idx => $metres) {
    $distIndex = $idx + 1;
    $tdCols[] = "Td{$distIndex}=" . StrSafe_DB("{$metres}m");
}

if (!empty($tdCols)) {
    safe_w_sql("INSERT INTO TournamentDistances
        SET TdTournament=$toId,
            TdType=" . StrSafe_DB($tour->ToType) . ",
            TdClasses='%%',
            " . implode(', ', $tdCols) . "
        ON DUPLICATE KEY UPDATE
            " . implode(', ', $tdCols));
}

safe_close();

echo json_encode([
    'success'      => true,
    'toId'         => $toId,
    'numDistances' => $numDist,
    'distances'    => $distances,
    'session'      => $sessionOrder,
]);
