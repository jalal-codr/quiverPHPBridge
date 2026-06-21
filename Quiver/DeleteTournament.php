<?php
/**
 * Quiver → Ianseo bridge: Delete Tournament
 *
 * Accepts JSON POST: { "toId": 123 }
 * Calls Ianseo's tour_delete() which cascades across all 60+ tables.
 * Only accepts requests from localhost.
 */

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);

header('Content-Type: application/json');

// Localhost only
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '127.0.0.1' && $ip !== '::1') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['TourId'] = -1;

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['toId']) || !is_numeric($input['toId'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid toId']);
    exit;
}

$toId = intval($input['toId']);

// Verify the tournament exists
$check = safe_r_sql("SELECT ToId, ToCode, ToName FROM Tournament WHERE ToId=" . StrSafe_DB($toId));
if (safe_num_rows($check) === 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Tournament not found in Ianseo']);
    exit;
}

$row = safe_fetch($check);
$toCode = $row->ToCode;
$toName = $row->ToName;

// Run the full cascade delete
require_once($CFG->DOCUMENT_PATH . 'Common/Fun_TourDelete.php');

try {
    tour_delete($toId);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Delete failed: ' . $e->getMessage()]);
    exit;
}

safe_close();

echo json_encode([
    'success' => true,
    'toId'    => $toId,
    'toCode'  => $toCode,
    'toName'  => $toName,
]);
