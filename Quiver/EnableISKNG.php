<?php
error_reporting(E_ERROR | E_PARSE); ini_set('display_errors', 0);
header('Content-Type: application/json');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '127.0.0.1' && $ip !== '::1') { http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit; }
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['toId'])) { http_response_code(400); echo json_encode(['error'=>'Missing toId']); exit; }
$toId = intval($input['toId']);
// ISK_NG_LITE_CODE = 11
$q = safe_r_sql("SELECT ToId, ToOptions FROM Tournament WHERE ToId=$toId");
if (safe_num_rows($q) === 0) { http_response_code(404); echo json_encode(['error'=>'Not found']); exit; }
$r = safe_fetch($q);
$opts = ($r->ToOptions ? unserialize($r->ToOptions) : []);
$opts['UseApi'] = 11; // ISK_NG_LITE_CODE
safe_w_sql("UPDATE Tournament SET ToOptions=" . StrSafe_DB(serialize($opts)) . " WHERE ToId=$toId");
safe_close();
echo json_encode(['success'=>true,'toId'=>$toId,'UseApi'=>11,'message'=>'ISK-NG Lite enabled']);
