<?php
/**
 * Quiver → Ianseo bridge: Update Participant
 *
 * Updates an existing entry in Ianseo's Entries table.
 * Can be called before or after initial sync.
 *
 * POST body:
 * {
 *   "toId": 123,
 *   "enId": 21521,
 *   "familyName": "Mohammed",
 *   "givenName": "Jalal",
 *   "gender": "M",
 *   "division": "BB",
 *   "ageCategory": "20-",
 *   "countryCode": "NGR",
 *   "countryName": "Nigeria",
 *   "dateOfBirth": "1999-06-19",
 *   "wheelchair": false
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

if (empty($input['toId']) || empty($input['enId'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing toId or enId']);
    exit;
}

$toId = intval($input['toId']);
$enId = intval($input['enId']);
$photoBase64 = trim($input['photoBase64'] ?? '');
$photoMimeType = strtolower(trim($input['photoMimeType'] ?? ''));

// Verify entry belongs to tournament
$q = safe_r_sql("SELECT EnId, EnDivision FROM Entries WHERE EnId=$enId AND EnTournament=$toId");
if (safe_num_rows($q) === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Entry not found in this tournament']);
    exit;
}
$entry = safe_fetch($q);

function quiverDivisionToIanseo(string $division): string {
    $divisionRaw = strtoupper(trim(substr(strip_tags($division), 0, 10)));
    $divisionMap = [
        'REC' => 'R',
        'RECURVE' => 'R',
        'COM' => 'C',
        'COMPOUND' => 'C',
        'BB' => 'BB',
        'BAREBOW' => 'BB',
        'LB' => 'LB',
        'LONGBOW' => 'LB',
        'TR' => 'TR',
        'TRAD' => 'TR',
        'TRADITIONAL' => 'TR',
    ];

    return $divisionMap[$divisionRaw] ?? $divisionRaw;
}

// Build update fields
$updates = [];

if (isset($input['familyName'])) {
    $updates[] = "EnName=" . StrSafe_DB(substr(strip_tags($input['familyName']), 0, 30));
}
if (isset($input['givenName'])) {
    $updates[] = "EnFirstName=" . StrSafe_DB(substr(strip_tags($input['givenName']), 0, 30));
}
if (isset($input['gender'])) {
    $genderInt = strtoupper($input['gender']) === 'F' ? 2 : 1;
    $updates[] = "EnSex=$genderInt";
}
if (isset($input['division'])) {
    $updates[] = "EnDivision=" . StrSafe_DB(quiverDivisionToIanseo($input['division']));
}
if (isset($input['ageCategory']) && isset($input['gender'])) {
    $genderSuffix = strtoupper($input['gender']) === 'F' ? 'W' : 'M';
    $ageMap = [
        '50+' => '50', '40+' => '50', '20-' => 'U21', 'U21' => 'U21',
        '18-' => 'U18', 'U18' => 'U18', '15-' => 'U15', 'U15' => 'U15',
    ];
    $ageKey = trim($input['ageCategory']);
    $prefix = $ageMap[$ageKey] ?? '';
    $ianseoClass = $prefix . $genderSuffix;
    $divisionForClass = isset($input['division']) ? quiverDivisionToIanseo($input['division']) : $entry->EnDivision;
    $classCheck = safe_r_sql("SELECT ClId FROM Classes
        WHERE ClTournament=$toId
        AND ClAthlete=1
        AND ClId=" . StrSafe_DB($ianseoClass) . "
        AND (ClDivisionsAllowed='' OR FIND_IN_SET(" . StrSafe_DB($divisionForClass) . ", ClDivisionsAllowed))");

    if (safe_num_rows($classCheck) === 0) {
        http_response_code(422);
        echo json_encode(['error' => 'This age/gender category is not configured for the selected division']);
        exit;
    }
    $updates[] = "EnClass=" . StrSafe_DB($ianseoClass);
    $updates[] = "EnAgeClass=" . StrSafe_DB(substr(strip_tags($ageKey), 0, 6));
}
if (isset($input['countryCode'])) {
    $countryCode = strtoupper(substr(strip_tags($input['countryCode']), 0, 5));
    $countryName = substr(strip_tags($input['countryName'] ?? $countryCode), 0, 30);

    // Ensure country exists
    $qc = safe_r_sql("SELECT CoId FROM Countries WHERE CoTournament=$toId AND CoCode=" . StrSafe_DB($countryCode));
    if (safe_num_rows($qc) === 0) {
        safe_w_sql("INSERT INTO Countries (CoTournament, CoCode, CoName, CoNameComplete, CoIocCode)
            VALUES ($toId, " . StrSafe_DB($countryCode) . ", " . StrSafe_DB($countryName) . ",
            " . StrSafe_DB($countryName) . ", " . StrSafe_DB($countryCode) . ")");
    }
    $qc2 = safe_r_sql("SELECT CoId FROM Countries WHERE CoTournament=$toId AND CoCode=" . StrSafe_DB($countryCode));
    if ($rc = safe_fetch($qc2)) {
        $updates[] = "EnCountry=" . intval($rc->CoId);
        $updates[] = "EnIocCode=" . StrSafe_DB($countryCode);
    }
}
if (isset($input['dateOfBirth']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['dateOfBirth'])) {
    $updates[] = "EnDob=" . StrSafe_DB($input['dateOfBirth']);
}
if (isset($input['wheelchair'])) {
    $updates[] = "EnWChair=" . ($input['wheelchair'] ? 1 : 0);
}

$hasPhotoUpdate = false;
if ($photoBase64) {
    $photoBase64 = preg_replace('/^data:image\\/[a-zA-Z0-9.+-]+;base64,/', '', $photoBase64);
    if (!in_array($photoMimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid photo type']);
        exit;
    }
    if (base64_decode($photoBase64, true) === false) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid photo data']);
        exit;
    }
    $hasPhotoUpdate = true;
}

if (empty($updates) && !$hasPhotoUpdate) {
    echo json_encode(['success' => true, 'message' => 'Nothing to update']);
    exit;
}

if (!empty($updates)) {
    // Always update timestamp when entry fields change
    $updates[] = "EnTimestamp='" . date('Y-m-d H:i:s') . "'";
    $updates[] = "EnMainInfoUpdate='" . date('Y-m-d H:i:s') . "'";
    safe_w_sql("UPDATE Entries SET " . implode(', ', $updates) . " WHERE EnId=$enId AND EnTournament=$toId");
}

if ($hasPhotoUpdate) {
    safe_w_sql("INSERT INTO Photos (PhEnId, PhPhoto, PhPhotoEntered, PhToRetake)
        VALUES ($enId, " . StrSafe_DB($photoBase64) . ", NOW(), 0)
        ON DUPLICATE KEY UPDATE PhPhoto=" . StrSafe_DB($photoBase64) . ", PhPhotoEntered=NOW(), PhToRetake=0");
}

// Return updated entry
$qResult = safe_r_sql("SELECT EnId, EnName, EnFirstName, EnSex, EnDivision, EnClass, EnAgeClass, EnIocCode, EnWChair, EnDob FROM Entries WHERE EnId=$enId");
$entry = safe_fetch($qResult);

safe_close();

echo json_encode([
    'success'      => true,
    'enId'         => $enId,
    'photoUpdated' => $hasPhotoUpdate,
    'entry'        => (array)$entry,
]);
