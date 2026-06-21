<?php
error_reporting(0); ini_set('display_errors',0);
header('Content-Type: application/json');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');

$toId = 126;

// Fix 1: Set ToNumDist=1 (single distance tournament)
safe_w_sql("UPDATE Tournament SET ToNumDist=1 WHERE ToId=$toId");

// Fix 2: Delete extra distance info, keep only distance 1
safe_w_sql("DELETE FROM DistanceInformation WHERE DiTournament=$toId AND DiDistance > 1");

// Fix 3: Set DiScoringEnds=10, DiArrows=3 for distance 1 (10 ends of 3 arrows)
safe_w_sql("UPDATE DistanceInformation SET DiEnds=10, DiArrows=3, DiScoringEnds=10, DiScoringOffset=0 WHERE DiTournament=$toId AND DiDistance=1 AND DiSession=1");

// Fix 4: Update IskDvSchedKey for all devices on this tournament to Q11 (1 dist, session 1)
safe_w_sql("UPDATE IskDevices SET IskDvSchedKey='Q11' WHERE IskDvTournament=$toId AND IskDvProActive=1");

// Verify
$q = safe_r_sql("SELECT DiDistance, DiEnds, DiArrows, DiScoringEnds FROM DistanceInformation WHERE DiTournament=$toId");
$di=[];
while($r=safe_fetch($q)) $di[]=(array)$r;
$q2 = safe_r_sql("SELECT ToNumDist FROM Tournament WHERE ToId=$toId");
$t=safe_fetch($q2);

safe_close();
echo json_encode(['success'=>true,'numDist'=>$t->ToNumDist,'distInfo'=>$di]);
