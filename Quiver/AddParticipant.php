<?php
/**
 * Quiver → Ianseo bridge: Add Participant (Production Ready)
 *
 * Maps Quiver registration data to Ianseo's Entries table.
 *
 * Key mapping:
 *   EnDivision = R, C, BB, LB, TR
 *   EnClass    = U21M, U18W, 50M etc.
 *   EnSex      = 1=Male, 2=Female
 *   EnWChair   = 1 if wheelchair user
 *
 * Only accepts requests from localhost.
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
header('Content-Type: application/json');

/* ───────────────────────── Security ───────────────────────── */

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '127.0.0.1' && $ip !== '::1') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');

/* ───────────────────────── Input ───────────────────────── */

$input = json_decode(file_get_contents('php://input'), true);

foreach (['toId', 'familyName', 'givenName', 'gender', 'division', 'countryCode'] as $f) {
    if (empty($input[$f])) {
        http_response_code(400);
        echo json_encode(['error' => "Missing required field: $f"]);
        exit;
    }
}

/* ───────────────────────── Basic fields ───────────────────────── */

$toId       = (int)$input['toId'];
$familyName = substr(strip_tags($input['familyName']), 0, 30);
$givenName  = substr(strip_tags($input['givenName']), 0, 30);

$genderRaw    = strtoupper(trim($input['gender']));
$genderInt    = ($genderRaw === 'F') ? 2 : 1;
$genderSuffix = ($genderRaw === 'F') ? 'W' : 'M';

/* ───────────────────────── Division mapping ───────────────────────── */

$divisionRaw = strtoupper(trim(substr(strip_tags($input['division']), 0, 10)));

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

$division = $divisionMap[$divisionRaw] ?? $divisionRaw;

/* ───────────────────────── Age class ───────────────────────── */

$ageCategory = trim($input['ageClass'] ?? $input['ageCategory'] ?? '50+');

function mapToIanseoClass(string $ageCategory, string $genderSuffix): string {
    $map = [
        '50+' => '50',
        '40+' => '50',
        '20-' => 'U21',
        'U21' => 'U21',
        '18-' => 'U18',
        'U18' => 'U18',
        '15-' => 'U15',
        'U15' => 'U15',
        'SENIOR' => '',
        'M' => '',
        'W' => '',
    ];

    $key = strtoupper(trim($ageCategory));

    if (isset($map[$key])) {
        return $map[$key] . $genderSuffix;
    }

    return $genderSuffix;
}

$ianseoClass = mapToIanseoClass($ageCategory, $genderSuffix);

/* ───────────────────────── Country (GLOBAL SAFE FIX) ───────────────────────── */

$countryCode = strtoupper(trim(substr(strip_tags($input['countryCode']), 0, 5)));

/*
 * No hardcoding of countries.
 * Fully global-safe:
 * - Code is authoritative (ISO-style)
 * - Name is optional input fallback only
 */
$countryName = isset($input['countryName']) && !empty($input['countryName'])
    ? substr(strip_tags($input['countryName']), 0, 30)
    : $countryCode;

/* ───────────────────────── Optional fields ───────────────────────── */

$bibCode       = substr(strip_tags($input['bibCode'] ?? ''), 0, 25);
$email         = substr(strip_tags($input['email'] ?? ''), 0, 100);
$dob           = $input['dateOfBirth'] ?? '';
$wheelchair    = !empty($input['wheelchair']) ? 1 : 0;
$distance      = isset($input['distance']) ? (int)$input['distance'] : 0;
$distanceLabel = substr(strip_tags($input['distanceLabel'] ?? ''), 0, 20);
$photoBase64   = trim($input['photoBase64'] ?? '');
$photoMimeType = strtolower(trim($input['photoMimeType'] ?? ''));

/* ───────────────────────── Tournament check ───────────────────────── */

$q = safe_r_sql("SELECT ToId FROM Tournament WHERE ToId=" . StrSafe_DB($toId));
if (safe_num_rows($q) === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Tournament not found']);
    exit;
}

$classCheck = safe_r_sql("SELECT ClId FROM Classes
    WHERE ClTournament=$toId
    AND ClAthlete=1
    AND ClId=" . StrSafe_DB($ianseoClass) . "
    AND (ClDivisionsAllowed='' OR FIND_IN_SET(" . StrSafe_DB($division) . ", ClDivisionsAllowed))");

if (safe_num_rows($classCheck) === 0) {
    http_response_code(422);
    echo json_encode(['error' => 'This age/gender category is not configured for the selected division']);
    exit;
}

/* ───────────────────────── Country ensure ───────────────────────── */

$qc = safe_r_sql("SELECT CoId FROM Countries
    WHERE CoTournament=$toId
    AND CoCode=" . StrSafe_DB($countryCode));

if (safe_num_rows($qc) === 0) {
    safe_w_sql("INSERT INTO Countries
        (CoTournament, CoCode, CoName, CoNameComplete, CoIocCode)
    VALUES (
        $toId,
        " . StrSafe_DB($countryCode) . ",
        " . StrSafe_DB($countryName) . ",
        " . StrSafe_DB($countryName) . ",
        " . StrSafe_DB($countryCode) . "
    )");
}

$qc2 = safe_r_sql("SELECT CoId FROM Countries
    WHERE CoTournament=$toId
    AND CoCode=" . StrSafe_DB($countryCode));

$coId = 0;
if ($rc = safe_fetch($qc2)) {
    $coId = (int)$rc->CoId;
}

/* ───────────────────────── Bib generation ───────────────────────── */

if (empty($bibCode)) {
    $qb = safe_r_sql("SELECT MAX(CAST(EnCode AS UNSIGNED)) as MaxCode
        FROM Entries WHERE EnTournament=$toId");

    $rb = safe_fetch($qb);
    $bibCode = str_pad(((int)($rb->MaxCode ?? 0)) + 1, 3, '0', STR_PAD_LEFT);
}

/* ───────────────────────── Insert Entry ───────────────────────── */

safe_w_sql("INSERT INTO Entries
    (EnTournament, EnCode, EnName, EnFirstName, EnSex,
     EnDivision, EnClass, EnAgeClass, EnIocCode, EnCountry,
     EnAthlete, EnWChair)
VALUES
    ($toId,
     " . StrSafe_DB($bibCode) . ",
     " . StrSafe_DB($familyName) . ",
     " . StrSafe_DB($givenName) . ",
     $genderInt,
     " . StrSafe_DB($division) . ",
     " . StrSafe_DB($ianseoClass) . ",
     " . StrSafe_DB($ageCategory) . ",
     " . StrSafe_DB($countryCode) . ",
     $coId,
     1,
     $wheelchair
)");

$enId = safe_w_last_id();

if (!$enId) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to insert entry']);
    exit;
}

/* ───────────────────────── Qualifications ───────────────────────── */

safe_w_sql("INSERT INTO Qualifications (QuId) VALUES ($enId)");

/* ───────────────────────── Individual event links ───────────────────────── */

safe_w_sql("INSERT IGNORE INTO Individuals (IndId, IndEvent, IndTournament)
    SELECT $enId, EcCode, $toId
    FROM EventClass
    WHERE EcTournament=$toId
    AND EcTeamEvent=0
    AND EcDivision=" . StrSafe_DB($division) . "
    AND EcClass=" . StrSafe_DB($ianseoClass));

/* ───────────────────────── DOB ───────────────────────── */

if ($dob && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
    safe_w_sql("UPDATE Entries SET EnDob=" . StrSafe_DB($dob) . " WHERE EnId=$enId");
}

/* ───────────────────────── Email ───────────────────────── */

if ($email) {
    safe_w_sql("INSERT INTO ExtraData (EdId, EdType, EdEvent, EdEmail, EdExtra)
        VALUES ($enId, 'E', '', '', " . StrSafe_DB($email) . ")
        ON DUPLICATE KEY UPDATE EdExtra=" . StrSafe_DB($email));
}

/* ───────────────────────── Quiver distance ───────────────────────── */

if ($distance > 0 || $distanceLabel) {
    $distanceExtra = $distanceLabel ?: ($distance . 'm');
    safe_w_sql("INSERT INTO ExtraData (EdId, EdType, EdEvent, EdEmail, EdExtra)
        VALUES ($enId, 'QD', '', '', " . StrSafe_DB($distanceExtra) . ")
        ON DUPLICATE KEY UPDATE EdExtra=" . StrSafe_DB($distanceExtra));
}

/* ───────────────────────── Photo ───────────────────────── */

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
    safe_w_sql("INSERT INTO Photos (PhEnId, PhPhoto, PhPhotoEntered, PhToRetake)
        VALUES ($enId, " . StrSafe_DB($photoBase64) . ", NOW(), 0)
        ON DUPLICATE KEY UPDATE PhPhoto=" . StrSafe_DB($photoBase64) . ", PhPhotoEntered=NOW(), PhToRetake=0");
}

/* ───────────────────────── Response ───────────────────────── */

safe_close();

echo json_encode([
    'success'    => true,
    'enId'       => $enId,
    'bibCode'    => $bibCode,
    'familyName' => $familyName,
    'givenName'  => $givenName,
    'division'   => $division,
    'class'      => $ianseoClass,
    'gender'     => $genderRaw,
    'wheelchair' => $wheelchair,
    'distance'   => $distance,
    'hasPhoto'   => (bool)$photoBase64,
    'toId'       => $toId,
]);
