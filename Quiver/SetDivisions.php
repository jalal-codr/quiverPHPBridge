<?php
/**
 * Quiver → Ianseo bridge: Set Tournament Divisions (Disciplines)
 *
 * Inserts discipline divisions into Ianseo's Divisions table.
 * Creates only the gender+age Classes explicitly selected in Quiver.
 *
 * POST body:
 * {
 *   "toId": 123,
 *   "disciplines": ["recurve", "compound", "barebow", "longbow", "traditional"],
 *   "categoryClasses": ["M", "W", "U18M", "U18W"],
 *   "disciplineCategories": [
 *     { "discipline": "recurve", "categories": ["M", "W"] },
 *     { "discipline": "barebow", "categories": ["U18M", "U18W"] }
 *   ]
 * }
 *
 * Ianseo division IDs used by WA rules:
 *   R  = Recurve
 *   C  = Compound
 *   BB = Barebow
 *   LB = Longbow (Traditional Longbow)
 *   TR = Traditional Recurve
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
if (empty($input['disciplines']) || !is_array($input['disciplines'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing disciplines array']);
    exit;
}

$toId = intval($input['toId']);

// Verify tournament
$q = safe_r_sql("SELECT ToId, ToType, ToLocRule FROM Tournament WHERE ToId=" . StrSafe_DB($toId));
if (safe_num_rows($q) === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Tournament not found']);
    exit;
}
$tour = safe_fetch($q);
$tourType  = $tour->ToType;
$tourRules = $tour->ToLocRule ?? 'default';

// ── Division definitions ──────────────────────────────────────────────────────
// Maps Quiver discipline name → Ianseo DivId + description
$DIVISION_MAP = [
    'recurve'     => ['id' => 'R',  'desc' => 'Recurve',     'order' => 1],
    'compound'    => ['id' => 'C',  'desc' => 'Compound',    'order' => 2],
    'barebow'     => ['id' => 'BB', 'desc' => 'Barebow',     'order' => 3],
    'longbow'     => ['id' => 'LB', 'desc' => 'Longbow',     'order' => 4],
    'traditional' => ['id' => 'TR', 'desc' => 'Traditional', 'order' => 5],
];

// ── Standard WA classes (gender + age) ───────────────────────────────────────
// ClSex: 0=Male, 1=Female
$STANDARD_CLASSES = [
    ['id' => 'M',    'desc' => 'Men',            'sex' => 0, 'from' => 21, 'to' => 49, 'order' => 1],
    ['id' => 'W',    'desc' => 'Women',          'sex' => 1, 'from' => 21, 'to' => 49, 'order' => 2],
    ['id' => 'U21M', 'desc' => 'Under 21 Men',   'sex' => 0, 'from' => 18, 'to' => 20, 'order' => 3],
    ['id' => 'U21W', 'desc' => 'Under 21 Women', 'sex' => 1, 'from' => 18, 'to' => 20, 'order' => 4],
    ['id' => 'U18M', 'desc' => 'Under 18 Men',   'sex' => 0, 'from' => 1,  'to' => 17, 'order' => 5],
    ['id' => 'U18W', 'desc' => 'Under 18 Women', 'sex' => 1, 'from' => 1,  'to' => 17, 'order' => 6],
    ['id' => '50M',  'desc' => '50+ Men',         'sex' => 0, 'from' => 50, 'to' => 100,'order' => 7],
    ['id' => '50W',  'desc' => '50+ Women',       'sex' => 1, 'from' => 50, 'to' => 100,'order' => 8],
];

$tourRuleStr = $tourRules . '|Type_FITA|SetAllClass';

$selectedDivisionIds = [];
foreach ($input['disciplines'] as $discipline) {
    $key = strtolower(trim($discipline));
    if (isset($DIVISION_MAP[$key])) {
        $selectedDivisionIds[] = $DIVISION_MAP[$key]['id'];
    }
}
$selectedDivisionIds = array_values(array_unique($selectedDivisionIds));
if (empty($selectedDivisionIds)) {
    http_response_code(400);
    echo json_encode(['error' => 'No valid disciplines selected']);
    exit;
}

$allowedClasses = array_column($STANDARD_CLASSES, 'id');
$classDivisionMap = [];

if (!empty($input['disciplineCategories']) && is_array($input['disciplineCategories'])) {
    foreach ($input['disciplineCategories'] as $row) {
        $disciplineKey = strtolower(trim($row['discipline'] ?? ''));
        if (!isset($DIVISION_MAP[$disciplineKey]) || empty($row['categories']) || !is_array($row['categories'])) {
            continue;
        }

        $divId = $DIVISION_MAP[$disciplineKey]['id'];
        if (!in_array($divId, $selectedDivisionIds)) {
            continue;
        }

        foreach ($row['categories'] as $classId) {
            $classId = strtoupper(trim($classId));
            if (!in_array($classId, $allowedClasses)) {
                continue;
            }
            if (empty($classDivisionMap[$classId])) {
                $classDivisionMap[$classId] = [];
            }
            $classDivisionMap[$classId][] = $divId;
        }
    }
}

$requestedClasses = array_keys($classDivisionMap);
if (empty($requestedClasses) && !empty($input['categoryClasses']) && is_array($input['categoryClasses'])) {
    foreach ($input['categoryClasses'] as $classId) {
        $classId = strtoupper(trim($classId));
        if (in_array($classId, $allowedClasses)) {
            $requestedClasses[] = $classId;
            $classDivisionMap[$classId] = $selectedDivisionIds;
        }
    }
    $requestedClasses = array_values(array_unique($requestedClasses));
}
if (empty($requestedClasses)) {
    http_response_code(400);
    echo json_encode(['error' => 'No valid categoryClasses selected']);
    exit;
}

foreach ($classDivisionMap as $classId => $divisionIds) {
    $divisionIds = array_values(array_intersect($selectedDivisionIds, array_unique($divisionIds)));
    sort($divisionIds);
    $classDivisionMap[$classId] = $divisionIds;
}

// Remove setup defaults that were not explicitly selected in Quiver.
safe_w_sql("DELETE FROM Divisions WHERE DivTournament=$toId AND DivAthlete=1 AND DivId NOT IN (" . implode(',', array_map('StrSafe_DB', $selectedDivisionIds)) . ")");
safe_w_sql("DELETE FROM Classes WHERE ClTournament=$toId AND ClAthlete=1 AND ClId NOT IN (" . implode(',', array_map('StrSafe_DB', $requestedClasses)) . ")");
safe_w_sql("DELETE FROM Classes WHERE ClTournament=$toId AND ClAthlete=1 AND ClId IN (" . implode(',', array_map('StrSafe_DB', $requestedClasses)) . ")");

$inserted = [];
$skipped  = [];

foreach ($input['disciplines'] as $discipline) {
    $key = strtolower(trim($discipline));
    if (!isset($DIVISION_MAP[$key])) {
        $skipped[] = $discipline . ' (unknown)';
        continue;
    }

    $div = $DIVISION_MAP[$key];
    $divId   = $div['id'];
    $divDesc = $div['desc'];
    $divOrder = $div['order'];

    // Check if division already exists
    $qCheck = safe_r_sql("SELECT DivId FROM Divisions WHERE DivTournament=$toId AND DivId=" . StrSafe_DB($divId));
    if (safe_num_rows($qCheck) > 0) {
        $skipped[] = $divId . ' (already exists)';
        continue;
    }

    // Insert division
    safe_w_sql("INSERT INTO Divisions
        (DivId, DivTournament, DivDescription, DivIsPara, DivAthlete, DivViewOrder, DivRecDivision, DivWaDivision, DivTourRules)
        VALUES (
            " . StrSafe_DB($divId) . ",
            $toId,
            " . StrSafe_DB($divDesc) . ",
            0, 1,
            $divOrder,
            " . StrSafe_DB($divId) . ",
            " . StrSafe_DB($divId) . ",
            " . StrSafe_DB($tourRuleStr) . "
        )");

    $inserted[] = $divId;
}

// ── Ensure all standard classes exist ────────────────────────────────────────
$classesInserted = [];
foreach ($STANDARD_CLASSES as $cls) {
    if (!in_array($cls['id'], $requestedClasses)) continue;

    // Build valid class string (e.g. "M" validates as "M", "U21M" validates as "U21M,M")
    $validClass = $cls['id'];
    // Add fallback valid classes
    if (strpos($cls['id'], 'U21') === 0) {
        $validClass = $cls['id'] . ',' . ($cls['sex'] == 0 ? 'M' : 'W');
    } elseif (strpos($cls['id'], 'U18') === 0) {
        $validClass = $cls['id'] . ',' . ($cls['sex'] == 0 ? 'U21M,M' : 'U21W,W');
    } elseif (strpos($cls['id'], '50') === 0) {
        $validClass = $cls['id'] . ',' . ($cls['sex'] == 0 ? 'M' : 'W');
    }

    $allowedDivisions = implode(',', $classDivisionMap[$cls['id']] ?? $selectedDivisionIds);

    safe_w_sql("INSERT INTO Classes
        (ClId, ClTournament, ClDescription, ClViewOrder, ClAgeFrom, ClAgeTo,
         ClValidClass, ClSex, ClAthlete, ClDivisionsAllowed,
         ClRecClass, ClWaClass, ClTourRules, ClIsPara)
        VALUES (
            " . StrSafe_DB($cls['id']) . ",
            $toId,
            " . StrSafe_DB($cls['desc']) . ",
            {$cls['order']},
            {$cls['from']},
            {$cls['to']},
            " . StrSafe_DB($validClass) . ",
            {$cls['sex']},
            1, " . StrSafe_DB($allowedDivisions) . ",
            " . StrSafe_DB($cls['id']) . ",
            " . StrSafe_DB($cls['id']) . ",
            " . StrSafe_DB($tourRuleStr) . ",
            0
        )");
    $classesInserted[] = $cls['id'];
}

safe_close();

echo json_encode([
    'success'          => true,
    'toId'             => $toId,
    'divisionsInserted' => $inserted,
    'divisionsSkipped'  => $skipped,
    'classesInserted'   => $classesInserted,
    'categoryClasses'    => $requestedClasses,
    'classDivisions'     => $classDivisionMap,
]);
