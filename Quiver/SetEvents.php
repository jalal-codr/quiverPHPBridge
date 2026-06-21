<?php
require_once(__DIR__ . '/_bootstrap.php');
quiver_bootstrap(true);
$input = quiver_input();
$toId = intval($input['toId'] ?? 0);
if (!$toId) quiver_error(400, 'Missing or invalid toId');

$individual = is_array($input['individualEvents'] ?? null) ? $input['individualEvents'] : [];
$teams = is_array($input['teamEvents'] ?? null) ? $input['teamEvents'] : [];

safe_w_sql("DELETE FROM EventClass WHERE EcTournament=$toId");
safe_w_sql("DELETE FROM Events WHERE EvTournament=$toId");
safe_w_sql("DELETE FROM Individuals WHERE IndTournament=$toId");

$eventCount = 0; $teamCount = 0; $eventClasses = 0; $finalsRows = 0; $teamFinalsRows = 0;

foreach ($individual as $event) {
    if (empty($event['enabled'])) continue;
    $code = clean_code($event['code'] ?? '');
    if (!$code) continue;
    $name = substr(strip_tags($event['name'] ?? $code), 0, 60);
    $division = clean_code($event['division'] ?? '');
    $cats = is_array($event['categories'] ?? null) ? $event['categories'] : [($event['category'] ?? '')];
    $qualified = max(0, intval($event['qualified'] ?? 0));
    $firstPhase = max(0, intval($event['firstPhase'] ?? 0));
    $matchMode = ($event['matchMode'] ?? 'set') === 'cumulative' ? 0 : 1;
    safe_w_sql("INSERT INTO Events (EvTournament, EvCode, EvEventName, EvTeamEvent, EvFinalFirstPhase, EvMatchMode, EvNumQualified)
        VALUES ($toId, " . StrSafe_DB($code) . ", " . StrSafe_DB($name) . ", 0, $firstPhase, $matchMode, $qualified)");
    $eventCount++;
    foreach ($cats as $cat) {
        $cat = clean_code($cat);
        if (!$division || !$cat) continue;
        safe_w_sql("INSERT INTO EventClass (EcTournament, EcCode, EcTeamEvent, EcDivision, EcClass)
            VALUES ($toId, " . StrSafe_DB($code) . ", 0, " . StrSafe_DB($division) . ", " . StrSafe_DB($cat) . ")");
        $eventClasses++;
    }
}

foreach ($teams as $event) {
    if (empty($event['enabled'])) continue;
    $code = clean_code($event['code'] ?? '');
    if (!$code) continue;
    $name = substr(strip_tags($event['name'] ?? $code), 0, 60);
    $division = clean_code($event['division'] ?? '');
    $cats = is_array($event['categories'] ?? null) ? $event['categories'] : [($event['category'] ?? '')];
    $qualified = max(0, intval($event['qualified'] ?? 0));
    $firstPhase = max(0, intval($event['firstPhase'] ?? 0));
    $teamSize = max(1, intval($event['teamSize'] ?? 3));
    $mixed = !empty($event['mixedTeam']) ? 1 : 0;
    $matchMode = ($event['matchMode'] ?? 'set') === 'cumulative' ? 0 : 1;
    safe_w_sql("INSERT INTO Events (EvTournament, EvCode, EvEventName, EvTeamEvent, EvFinalFirstPhase, EvMatchMode, EvNumQualified, EvMaxTeamPerson, EvMixedTeam)
        VALUES ($toId, " . StrSafe_DB($code) . ", " . StrSafe_DB($name) . ", 1, $firstPhase, $matchMode, $qualified, $teamSize, $mixed)");
    $teamCount++;
    foreach ($cats as $cat) {
        $cat = clean_code($cat);
        if (!$division || !$cat) continue;
        safe_w_sql("INSERT INTO EventClass (EcTournament, EcCode, EcTeamEvent, EcDivision, EcClass)
            VALUES ($toId, " . StrSafe_DB($code) . ", 1, " . StrSafe_DB($division) . ", " . StrSafe_DB($cat) . ")");
        $eventClasses++;
    }
}

safe_w_sql("INSERT IGNORE INTO Individuals (IndId, IndEvent, IndTournament)
    SELECT EnId, EcCode, $toId
    FROM Entries
    INNER JOIN EventClass ON EcTournament=EnTournament AND EcTeamEvent=0 AND EcDivision=EnDivision AND EcClass=EnClass
    WHERE EnTournament=$toId AND EnAthlete=1");
$linked = quiver_count("SELECT COUNT(*) AS Cnt FROM Individuals WHERE IndTournament=$toId");

echo json_encode([
    'success'=>true,
    'individualEvents'=>$eventCount,
    'teamEvents'=>$teamCount,
    'eventClasses'=>$eventClasses,
    'finalsRows'=>$finalsRows,
    'teamFinalsRows'=>$teamFinalsRows,
    'individualsLinked'=>$linked,
]);

function clean_code($value) {
    return substr(preg_replace('/[^A-Z0-9_]/', '', strtoupper(trim(strval($value)))), 0, 20);
}
