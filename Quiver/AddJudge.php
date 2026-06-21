<?php
/**
 * Quiver → Ianseo bridge: Add Judge / Staff
 *
 * Adds a judge or official to the TournamentInvolved table.
 * Only accepts requests from localhost.
 *
 * POST body:
 * {
 *   "toId": 123,
 *   "familyName": "Smith",
 *   "givenName": "John",
 *   "gender": 1,          // 1=Male, 2=Female
 *   "countryCode": "GHA",
 *   "countryName": "Ghana",
 *   "type": 1,            // InvolvedType.ItId — 1=Director of Shooting, 2=Judge, etc.
 *   "code": "J001",       // optional badge code
 *   "email": "",          // optional
 *   "photoBase64": "...",  // optional raw base64 image
 *   "photoMimeType": "image/jpeg"
 * }
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

foreach (['toId', 'familyName', 'givenName', 'countryCode'] as $f) {
    if (empty($input[$f])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing: $f"]);
        exit;
    }
}

$toId        = intval($input['toId']);
$familyName  = substr(strip_tags($input['familyName']), 0, 50);
$givenName   = substr(strip_tags($input['givenName']), 0, 50);
$gender      = intval($input['gender'] ?? 1); // 1=M, 2=F
$countryCode = strtoupper(substr(strip_tags($input['countryCode']), 0, 3));
$countryName = substr(strip_tags($input['countryName'] ?? $countryCode), 0, 50);
$type        = intval($input['type'] ?? 2); // default 2 = Judge
$code        = substr(strip_tags($input['code'] ?? ''), 0, 10);
$email       = substr(strip_tags($input['email'] ?? ''), 0, 100);
$photoBase64 = trim($input['photoBase64'] ?? '');
$photoMimeType = strtolower(trim($input['photoMimeType'] ?? ''));

if ($photoBase64 !== '') {
    if (!in_array($photoMimeType, ['image/jpeg', 'image/png', 'image/webp'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Unsupported judge photo type']);
        exit;
    }

    $decodedPhoto = base64_decode($photoBase64, true);
    if ($decodedPhoto === false || strlen($decodedPhoto) === 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid judge photo data']);
        exit;
    }
}

// Verify tournament
$q = safe_r_sql("SELECT ToId FROM Tournament WHERE ToId=" . StrSafe_DB($toId));
if (safe_num_rows($q) === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Tournament not found']);
    exit;
}

// Ensure country exists
$qc = safe_r_sql("SELECT CoId FROM Countries WHERE CoTournament=$toId AND CoCode=" . StrSafe_DB($countryCode));
if (safe_num_rows($qc) === 0) {
    safe_w_sql("INSERT INTO Countries (CoTournament, CoCode, CoName, CoNameComplete, CoIocCode)
        VALUES ($toId, " . StrSafe_DB($countryCode) . ", " . StrSafe_DB($countryName) . ",
        " . StrSafe_DB($countryName) . ", " . StrSafe_DB($countryCode) . ")");
}
$qc2 = safe_r_sql("SELECT CoId FROM Countries WHERE CoTournament=$toId AND CoCode=" . StrSafe_DB($countryCode));
$coId = 0;
if ($rc = safe_fetch($qc2)) $coId = $rc->CoId;

// Insert into TournamentInvolved
safe_w_sql("INSERT INTO TournamentInvolved SET
    TiTournament=$toId,
    TiType=$type,
    TiCode=" . StrSafe_DB($code ?: 'J' . str_pad(rand(1,999), 3, '0', STR_PAD_LEFT)) . ",
    TiCodeLocal='',
    TiName=" . StrSafe_DB($familyName) . ",
    TiGivenName=" . StrSafe_DB($givenName) . ",
    TiCountry=$coId,
    TiGender=$gender");

$tiId = safe_w_last_id();

if (!$tiId) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to insert judge']);
    exit;
}

// Store email in ExtraData if provided
if ($email) {
    safe_w_sql("INSERT INTO ExtraData (EdId, EdType, EdExtra)
        VALUES ($tiId, 'E', " . StrSafe_DB($email) . ")
        ON DUPLICATE KEY UPDATE EdExtra=" . StrSafe_DB($email));
}

if ($photoBase64 !== '') {
    safe_w_sql("INSERT INTO Photos (PhEnId, PhPhoto, PhPhotoEntered, PhToRetake)
        VALUES ($tiId, " . StrSafe_DB($photoBase64) . ", NOW(), 0)
        ON DUPLICATE KEY UPDATE PhPhoto=" . StrSafe_DB($photoBase64) . ", PhPhotoEntered=NOW(), PhToRetake=0");
}

safe_close();
echo json_encode(['success' => true, 'tiId' => $tiId, 'type' => $type]);
