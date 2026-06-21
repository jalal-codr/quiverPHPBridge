<?php
require_once(__DIR__ . '/_bootstrap.php');
quiver_bootstrap(true);
$input = quiver_input();
$toId = intval($input['toId'] ?? 0);
$onlineId = intval($input['onlineId'] ?? 0);
$onlineAuth = trim($input['onlineAuth'] ?? '');
if (!$toId || !$onlineId || !$onlineAuth) quiver_error(400, 'Missing toId, onlineId, or onlineAuth');

$q = safe_r_sql("SELECT ToCode FROM Tournament WHERE ToId=$toId");
if (!$q || !($t = safe_fetch($q))) quiver_error(404, 'Tournament not found');
$entries = quiver_count("SELECT COUNT(*) AS Cnt FROM Entries WHERE EnTournament=$toId AND EnAthlete=1");
$events = quiver_count("SELECT COUNT(*) AS Cnt FROM Events WHERE EvTournament=$toId");
$countries = quiver_count("SELECT COUNT(*) AS Cnt FROM Countries WHERE CoTournament=$toId");
safe_w_sql("UPDATE Tournament SET ToOnlineId=$onlineId WHERE ToId=$toId");
echo json_encode([
    'success'=>true,
    'published'=>['countries'=>$countries,'entries'=>$entries,'events'=>$events],
    'onlineId'=>$onlineId,
    'onlineUrl'=>"https://www.ianseo.net/Details.php?toid=$onlineId",
    'scoringSetupUrl'=>'https://www.ianseo.net/',
    'tourCode'=>$t->ToCode,
    'message'=>'Publish credentials saved locally. Use Ianseo native publishing for final public upload if needed.',
]);
