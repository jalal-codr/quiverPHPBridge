<?php
require_once(__DIR__ . '/_bootstrap.php');
quiver_bootstrap(true);
$input = quiver_input();
$toId = intval($input['toId'] ?? 0);
if (!$toId) quiver_error(400, 'Missing or invalid toId');

$counts = [
    'tournament' => quiver_count("SELECT COUNT(*) AS Cnt FROM Tournament WHERE ToId=$toId"),
    'divisions' => quiver_count("SELECT COUNT(*) AS Cnt FROM Divisions WHERE DivTournament=$toId"),
    'classes' => quiver_count("SELECT COUNT(*) AS Cnt FROM Classes WHERE ClTournament=$toId"),
    'distances' => quiver_count("SELECT COUNT(*) AS Cnt FROM TournamentDistances WHERE TdTournament=$toId"),
    'events' => quiver_count("SELECT COUNT(*) AS Cnt FROM Events WHERE EvTournament=$toId"),
    'entries' => quiver_count("SELECT COUNT(*) AS Cnt FROM Entries WHERE EnTournament=$toId AND EnAthlete=1"),
    'targets' => quiver_count("SELECT COUNT(*) AS Cnt FROM Qualifications INNER JOIN Entries ON EnId=QuId WHERE EnTournament=$toId AND QuTarget>0 AND QuLetter<>''"),
    'scores' => quiver_count("SELECT COUNT(*) AS Cnt FROM Qualifications INNER JOIN Entries ON EnId=QuId WHERE EnTournament=$toId AND QuScore>0"),
    'individualLinks' => quiver_count("SELECT COUNT(*) AS Cnt FROM Individuals WHERE IndTournament=$toId"),
];

$checks = [
    ['key'=>'tournament','label'=>'Tournament','ok'=>$counts['tournament']>0,'count'=>$counts['tournament'],'message'=>$counts['tournament']>0?'Tournament exists.':'Tournament missing.'],
    ['key'=>'structure','label'=>'Competition structure','ok'=>$counts['divisions']>0 && $counts['classes']>0 && $counts['events']>0,'count'=>$counts['events'],'message'=>$counts['events']>0?'Events are configured.':'Sync competition structure.'],
    ['key'=>'entries','label'=>'Participants','ok'=>$counts['entries']>0,'count'=>$counts['entries'],'message'=>$counts['entries']>0?'Participants exist.':'Add participants.'],
    ['key'=>'targets','label'=>'Targets','ok'=>$counts['targets']>0,'count'=>$counts['targets'],'message'=>$counts['targets']>0?'Targets assigned.':'Draw targets.'],
    ['key'=>'individualLinks','label'=>'Event links','ok'=>$counts['individualLinks']>0,'count'=>$counts['individualLinks'],'message'=>$counts['individualLinks']>0?'Entries are linked to events.':'Sync competition structure after participants are added.'],
];
$ready = true;
foreach ($checks as $check) if (!$check['ok']) $ready = false;
echo json_encode(['success'=>true,'ready'=>$ready,'checks'=>$checks,'counts'=>$counts]);
