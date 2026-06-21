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

if (
    (empty($input['distances']) || !is_array($input['distances'])) &&
    (empty($input['divisionDistances']) || !is_array($input['divisionDistances']))
) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing distances or divisionDistances array']);
    exit;
}

$toId          = intval($input['toId']);
$distanceRows = [];
if (!empty($input['divisionDistances']) && is_array($input['divisionDistances'])) {
    foreach ($input['divisionDistances'] as $row) {
        $filter = trim(strval($row['filter'] ?? '%%'));
        $labels = [];
        foreach (($row['distances'] ?? []) as $label) {
            $label = normalize_distance_label($label);
            if ($label !== '') $labels[] = $label;
        }
        if ($filter !== '' && count($labels) > 0) {
            $distanceRows[] = ['filter' => $filter, 'distances' => $labels];
        }
    }
} else {
    $labels = [];
    foreach ($input['distances'] as $label) {
        $label = normalize_distance_label($label);
        if ($label !== '') $labels[] = $label;
    }
    if (count($labels) > 0) {
        $distanceRows[] = ['filter' => '%%', 'distances' => array_values(array_unique($labels))];
    }
}

if (count($distanceRows) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'No usable distance rows were provided']);
    exit;
}

$numDist = 0;
foreach ($distanceRows as $row) {
    $numDist = max($numDist, count($row['distances']));
}
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

// ── Step 2: Get or create qualification sessions ─────────────────────────────
$sessionConfig = [];
if (!empty($input['sessionConfig']) && is_array($input['sessionConfig'])) {
    foreach ($input['sessionConfig'] as $row) {
        $session = max(1, intval($row['session'] ?? 1));
        $sessionConfig[$session] = $row;
    }
}
if (empty($sessionConfig)) {
    $sessionConfig[1] = ['session' => 1];
}
$sessions = array_keys($sessionConfig);
sort($sessions);

foreach ($sessions as $sessionOrder) {
    safe_w_sql("INSERT INTO Session (SesTournament, SesOrder, SesType, SesName)
        VALUES ($toId, $sessionOrder, 'Q', 'Qualification')
        ON DUPLICATE KEY UPDATE SesName=SesName");
}

// ── Step 3: Insert DistanceInformation for each distance ─────────────────────
// DiDistance is the distance index (1, 2, 3...) not the metres value
// The actual metres value goes into TournamentDistances as the label
foreach ($sessions as $sessionOrder) {
    $cfg = $sessionConfig[$sessionOrder] ?? [];
    $ends = intval($cfg['ends'] ?? $endsPerDist);
    $arrows = intval($cfg['arrows'] ?? $arrowsPerEnd);
    $scoringEnds = intval($cfg['scoringEnds'] ?? $ends);
    for ($distIndex = 1; $distIndex <= $numDist; $distIndex++) {
        safe_w_sql("INSERT INTO DistanceInformation
            (DiTournament, DiSession, DiDistance, DiType, DiEnds, DiArrows, DiScoringEnds)
            VALUES ($toId, $sessionOrder, $distIndex, 'Q', $ends, $arrows, $scoringEnds)
            ON DUPLICATE KEY UPDATE
                DiEnds=$ends,
                DiArrows=$arrows,
                DiScoringEnds=$scoringEnds");
    }
}

// Update ToNumDist to match actual number of distances
safe_w_sql("UPDATE Tournament SET ToNumDist=$numDist WHERE ToId=$toId");

// Update all ISK devices for this tournament to use correct schedule key
// Format: Q + numDist + session
$schedKey = 'Q' . $numDist . $sessions[0];
safe_w_sql("UPDATE IskDevices SET IskDvSchedKey=" . StrSafe_DB($schedKey) . " WHERE IskDvTournament=$toId AND IskDvProActive=1");

// ── Step 4: Set TournamentDistances rows per division/class filter ───────────
// First clear existing distance entries for this tournament
safe_w_sql("DELETE FROM TournamentDistances WHERE TdTournament=$toId AND TdType=" . StrSafe_DB($tour->ToType));

foreach ($distanceRows as $row) {
    $tdCols = [];
    for ($idx = 0; $idx < $numDist; $idx++) {
        $distIndex = $idx + 1;
        $tdCols[] = "Td{$distIndex}=" . StrSafe_DB($row['distances'][$idx] ?? '');
    }
    safe_w_sql("INSERT INTO TournamentDistances
        SET TdTournament=$toId,
            TdType=" . StrSafe_DB($tour->ToType) . ",
            TdClasses=" . StrSafe_DB($row['filter']) . ",
            " . implode(', ', $tdCols) . "
        ON DUPLICATE KEY UPDATE
            " . implode(', ', $tdCols));
}

safe_close();

echo json_encode([
    'success'      => true,
    'toId'         => $toId,
    'numDistances' => $numDist,
    'rows'         => count($distanceRows),
    'filters'      => array_map(function($row) { return $row['filter']; }, $distanceRows),
    'sessions'     => $sessions,
]);

function normalize_distance_label($value) {
    if (is_numeric($value)) {
        $m = intval($value);
        return $m > 0 ? "{$m}m" : '';
    }
    $value = trim(strval($value));
    if ($value === '') return '';
    if (preg_match('/^(\d+)(?:m)?(?:-\d+)?$/i', $value, $m)) {
        return intval($m[1]) . 'm';
    }
    return substr(strip_tags($value), 0, 20);
}
