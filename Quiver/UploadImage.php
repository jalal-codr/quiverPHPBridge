<?php
/**
 * Quiver → Ianseo bridge: Upload Tournament Image (Logo / Sponsor)
 *
 * Stores a base64-encoded image into the Images table.
 * Only accepts requests from localhost.
 *
 * POST body (JSON):
 * {
 *   "toId": 123,
 *   "type": "logo",          // "logo" | "sponsor"
 *   "name": "Main Sponsor",  // display name
 *   "imageBase64": "data:image/png;base64,..."
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

if (empty($input['toId']) || empty($input['imageBase64'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing toId or imageBase64']);
    exit;
}

$toId  = intval($input['toId']);
$type  = ($input['type'] ?? 'logo') === 'sponsor' ? 'S' : 'L'; // L=Logo, S=Sponsor
$name  = substr(strip_tags($input['name'] ?? ''), 0, 100);

// Verify tournament
$q = safe_r_sql("SELECT ToId FROM Tournament WHERE ToId=" . StrSafe_DB($toId));
if (safe_num_rows($q) === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Tournament not found']);
    exit;
}

// Decode base64 image
$imageData = $input['imageBase64'];
if (strpos($imageData, 'base64,') !== false) {
    $imageData = substr($imageData, strpos($imageData, 'base64,') + 7);
}
$binary = base64_decode($imageData);
if (!$binary) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid base64 image data']);
    exit;
}

// Detect mime type
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->buffer($binary);
$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($mime, $allowedMimes)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid image type: ' . $mime]);
    exit;
}

// Save to Images table
// ImType: L=Logo, S=Sponsor
safe_w_sql("INSERT INTO Images (ImTournament, ImType, ImName, ImData, ImMime)
    VALUES ($toId, " . StrSafe_DB($type) . ", " . StrSafe_DB($name) . ",
    '" . mysqli_real_escape_string($WRIT_CON ?? safe_w_con(), $binary) . "',
    " . StrSafe_DB($mime) . ")
    ON DUPLICATE KEY UPDATE
        ImName=" . StrSafe_DB($name) . ",
        ImData=VALUES(ImData),
        ImMime=" . StrSafe_DB($mime));

$imId = safe_w_last_id();

safe_close();
echo json_encode(['success' => true, 'imId' => $imId, 'type' => $type, 'name' => $name]);
