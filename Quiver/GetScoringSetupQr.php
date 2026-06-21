<?php
require_once(__DIR__ . '/_bootstrap.php');
quiver_bootstrap(true);
$input = quiver_input();
$toId = intval($input['toId'] ?? 0);
$serverUrl = trim($input['serverUrl'] ?? '');
if (!$toId) quiver_error(400, 'Missing or invalid toId');
$q = safe_r_sql("SELECT ToCode, ToName FROM Tournament WHERE ToId=$toId");
if (!$q || !($r = safe_fetch($q))) quiver_error(404, 'Tournament not found');
$pin = str_pad(strval($toId % 10000), 4, '0', STR_PAD_LEFT);
if ($serverUrl) {
    $serverUrl = rtrim($serverUrl, '/');
    safe_w_sql("REPLACE INTO ModulesParameters (MpTournament, MpModule, MpParameter, MpValue)
        VALUES ($toId, 'ISK-NG', 'ServerUrl', " . StrSafe_DB($serverUrl) . ")");
}
if (!$serverUrl) $serverUrl = rtrim((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname(dirname($_SERVER['REQUEST_URI'] ?? '/ianseo')), '/');
$qrPayload = ['u'=>$serverUrl, 'c'=>$r->ToCode, 'p'=>$pin];
echo json_encode([
    'success'=>true,
    'toId'=>$toId,
    'tournamentCode'=>$r->ToCode,
    'tournamentName'=>$r->ToName,
    'serverUrl'=>$serverUrl,
    'pin'=>$pin,
    'qrPayload'=>$qrPayload,
    'qrData'=>json_encode($qrPayload),
    'mode'=>'ISK-NG Lite',
    'message'=>'Open Tournament',
]);
