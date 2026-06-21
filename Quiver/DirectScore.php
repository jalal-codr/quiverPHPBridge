<?php
/**
 * Direct score insertion — bypasses ISK-NG entirely.
 * Writes directly to Qualifications table using the same logic as applyScore.
 */
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', 0);
header('Content-Type: application/json');
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if ($ip !== '127.0.0.1' && $ip !== '::1') { http_response_code(403); echo json_encode(['error'=>'Forbidden']); exit; }
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');

$input = json_decode(file_get_contents('php://input'), true);
$toId       = intval($input['toId'] ?? 0);
$session    = intval($input['session'] ?? 1);
$distance   = intval($input['distance'] ?? 1);
$targetNo   = intval($input['targetNo'] ?? 0);
$letter     = strtoupper(trim($input['letter'] ?? ''));
$arrowstring = trim($input['arrowstring'] ?? '');

if (!$toId || !$targetNo || !$letter || !$arrowstring) {
    http_response_code(400);
    echo json_encode(['error'=>'Missing required fields']);
    exit;
}

// Find the entry by session+target+letter
$q = safe_r_sql("SELECT q.QuId, q.QuSession, q.QuTarget, q.QuLetter,
    di.DiEnds, di.DiArrows, di.DiScoringEnds
    FROM Qualifications q
    INNER JOIN Entries e ON e.EnId = q.QuId
    INNER JOIN DistanceInformation di ON di.DiTournament = e.EnTournament
        AND di.DiSession = q.QuSession AND di.DiDistance = $distance AND di.DiType='Q'
    WHERE e.EnTournament = $toId
      AND q.QuSession = $session
      AND q.QuTarget = $targetNo
      AND q.QuLetter = " . StrSafe_DB($letter));

if (safe_num_rows($q) === 0) {
    echo json_encode(['error'=>"No entry found for session=$session target=$targetNo letter=$letter"]);
    exit;
}

$r = safe_fetch($q);
$quId      = intval($r->QuId);
$diEnds    = intval($r->DiEnds);
$diArrows  = intval($r->DiArrows);

// Calculate score from arrowstring
$arrowMap = ['X'=>10,'T'=>10,'9'=>9,'8'=>8,'7'=>7,'6'=>6,'5'=>5,'4'=>4,'3'=>3,'2'=>2,'1'=>1,'M'=>0];
$score = 0; $gold = 0; $xnine = 0; $hits = 0;
for ($i = 0; $i < strlen($arrowstring); $i++) {
    $ch = strtoupper($arrowstring[$i]);
    $val = $arrowMap[$ch] ?? 0;
    $score += $val;
    if ($val >= 10) { $gold++; $xnine++; }
    elseif ($val === 9) { $xnine++; }
    if ($val > 0) $hits++;
}

// Write to Qualifications
safe_w_sql("UPDATE Qualifications SET
    QuD{$distance}Score=$score,
    QuD{$distance}Gold=$gold,
    QuD{$distance}Xnine=$xnine,
    QuD{$distance}Hits=$hits,
    QuD{$distance}ArrowString=" . StrSafe_DB($arrowstring) . ",
    QuScore=QuD1Score+QuD2Score+QuD3Score+QuD4Score+QuD5Score+QuD6Score+QuD7Score+QuD8Score,
    QuGold=QuD1Gold+QuD2Gold+QuD3Gold+QuD4Gold+QuD5Gold+QuD6Gold+QuD7Gold+QuD8Gold,
    QuXnine=QuD1Xnine+QuD2Xnine+QuD3Xnine+QuD4Xnine+QuD5Xnine+QuD6Xnine+QuD7Xnine+QuD8Xnine,
    QuHits=QuD1Hits+QuD2Hits+QuD3Hits+QuD4Hits+QuD5Hits+QuD6Hits+QuD7Hits+QuD8Hits
    WHERE QuId=$quId");

safe_close();
echo json_encode(['success'=>true,'quId'=>$quId,'score'=>$score,'gold'=>$gold,'arrowstring'=>$arrowstring]);
