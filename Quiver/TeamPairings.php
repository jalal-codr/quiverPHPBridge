<?php
/**
 * Quiver -> Ianseo bridge: read and save team first-round pairings.
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
require_once($CFG->DOCUMENT_PATH . 'Common/Fun_Sessions.inc.php');
require_once($CFG->DOCUMENT_PATH . 'Common/Lib/Obj_RankFactory.php');

$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['toId']) || !is_numeric($input['toId'])) {
    fail_json('Missing or invalid toId', 400);
}

$toId = intval($input['toId']);
$action = trim($input['action'] ?? 'load');

$q = safe_r_sql("SELECT ToId FROM Tournament WHERE ToId=" . StrSafe_DB($toId));
if (safe_num_rows($q) !== 1) {
    fail_json('Tournament not found', 404);
}

CreateTourSession($toId);

if ($action === 'save') {
    save_pairings($toId, $input['pairings'] ?? []);
}

if ($action === 'saveGroups') {
    save_groups($toId, $input['groups'] ?? []);
}

echo json_encode([
    'success' => true,
    'events' => load_events($toId),
]);
exit;

function fail_json($message, $status = 500) {
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

function clean_event_code($code) {
    return substr(preg_replace('/[^A-Z0-9_]/', '', strtoupper(trim(strval($code)))), 0, 10);
}

function team_key($countryId, $subTeam) {
    $countryId = intval($countryId);
    $subTeam = intval($subTeam);
    if ($countryId <= 0) return '';
    if ($subTeam <= 0) $subTeam = 1;
    return $countryId . '_' . $subTeam;
}

function parse_team_key($value) {
    $value = trim(strval($value));
    if ($value === '') return [0, 0];
    $parts = explode('_', $value);
    $countryId = intval($parts[0] ?? 0);
    $subTeam = intval($parts[1] ?? 1);
    if ($countryId <= 0) return [0, 0];
    if ($subTeam <= 0) $subTeam = 1;
    return [$countryId, $subTeam];
}

function refresh_team_rankings($toId, $eventCodes) {
    foreach ($eventCodes as $eventCode) {
        try {
            Obj_RankFactory::create('AbsTeam', [
                'tournament' => $toId,
                'events' => $eventCode,
                'components' => false,
            ])->calculate();
        } catch (Throwable $e) {
            // Existing ranking rows are still usable if recalculation is not possible.
        }
    }
}

function load_events($toId) {
    $events = [];
    $eventCodes = [];
    $eventQ = safe_r_sql("SELECT EvCode, EvEventName, EvFinalFirstPhase, EvNumQualified, EvMaxTeamPerson
        FROM Events
        WHERE EvTournament=" . StrSafe_DB($toId) . " AND EvTeamEvent=1
        ORDER BY EvProgr, EvCode");
    while ($event = safe_fetch($eventQ)) {
        $eventCodes[] = $event->EvCode;
        $events[$event->EvCode] = [
            'code' => $event->EvCode,
            'name' => $event->EvEventName,
            'firstPhase' => intval($event->EvFinalFirstPhase),
            'qualified' => intval($event->EvNumQualified),
            'teamSize' => max(1, intval($event->EvMaxTeamPerson)),
            'athletes' => [],
            'groups' => [],
            'teams' => [],
            'matches' => [],
        ];
    }

    if (count($eventCodes) === 0) {
        return [];
    }

    refresh_team_rankings($toId, $eventCodes);

    $quotedEvents = implode(',', array_map('StrSafe_DB', $eventCodes));
    $teamQ = safe_r_sql("SELECT TeEvent, TeCoId, TeSubTeam, TeRank, CoCode, CoName
        FROM Teams
        LEFT JOIN Countries ON CoId=TeCoId AND CoTournament=TeTournament
        WHERE TeTournament=" . StrSafe_DB($toId) . " AND TeFinEvent=1 AND TeEvent IN ($quotedEvents)
        ORDER BY TeEvent, TeRank ASC, CoName ASC");
    while ($team = safe_fetch($teamQ)) {
        if (!isset($events[$team->TeEvent])) continue;
        $subTeam = intval($team->TeSubTeam) ?: 1;
        $events[$team->TeEvent]['teams'][] = [
            'key' => team_key($team->TeCoId, $subTeam),
            'countryId' => intval($team->TeCoId),
            'subTeam' => $subTeam,
            'rank' => intval($team->TeRank),
            'code' => $team->CoCode ?? '',
            'name' => ($team->CoName ?? '') . ($subTeam > 1 ? ' (' . $subTeam . ')' : ''),
        ];
    }

    $athleteQ = safe_r_sql("SELECT DISTINCT EcCode, EnId, EnCode, EnName, EnFirstName, EnSex, EnDivision, EnClass,
            EnCountry, CoCode, CoName, QuScore, QuGold, QuXnine, QuHits
        FROM EventClass
        INNER JOIN Entries ON EnTournament=EcTournament AND EnDivision=EcDivision AND EnClass=EcClass
        LEFT JOIN Countries ON CoId=EnCountry AND CoTournament=EnTournament
        LEFT JOIN Qualifications ON QuId=EnId
        WHERE EcTournament=" . StrSafe_DB($toId) . " AND EcTeamEvent=1 AND EcCode IN ($quotedEvents) AND EnAthlete=1
        ORDER BY EcCode, CoName ASC, CoCode ASC, QuScore DESC, QuGold DESC, QuXnine DESC, EnName ASC, EnFirstName ASC");
    while ($athlete = safe_fetch($athleteQ)) {
        if (!isset($events[$athlete->EcCode])) continue;
        $events[$athlete->EcCode]['athletes'][] = [
            'id' => intval($athlete->EnId),
            'bibCode' => $athlete->EnCode ?? '',
            'familyName' => $athlete->EnName ?? '',
            'givenName' => $athlete->EnFirstName ?? '',
            'gender' => intval($athlete->EnSex) === 2 ? 'F' : 'M',
            'division' => $athlete->EnDivision ?? '',
            'class' => $athlete->EnClass ?? '',
            'countryId' => intval($athlete->EnCountry),
            'countryCode' => $athlete->CoCode ?? '',
            'countryName' => $athlete->CoName ?? '',
            'score' => intval($athlete->QuScore ?? 0),
            'gold' => intval($athlete->QuGold ?? 0),
            'xnine' => intval($athlete->QuXnine ?? 0),
            'hits' => intval($athlete->QuHits ?? 0),
        ];
    }

    $componentQ = safe_r_sql("SELECT TeEvent, TeCoId, TeSubTeam, TeRank, TeScore, TeGold, TeXnine, TeHits,
            CoCode, CoName, TcId, TcOrder
        FROM Teams
        LEFT JOIN Countries ON CoId=TeCoId AND CoTournament=TeTournament
        LEFT JOIN TeamComponent ON TcCoId=TeCoId AND TcSubTeam=TeSubTeam AND TcEvent=TeEvent AND TcTournament=TeTournament AND TcFinEvent=TeFinEvent
        WHERE TeTournament=" . StrSafe_DB($toId) . " AND TeFinEvent=1 AND TeEvent IN ($quotedEvents)
        ORDER BY TeEvent, TeRank ASC, TeCoId ASC, TeSubTeam ASC, TcOrder ASC");
    $groups = [];
    while ($component = safe_fetch($componentQ)) {
        if (!isset($events[$component->TeEvent])) continue;
        $subTeam = intval($component->TeSubTeam) ?: 1;
        $key = team_key($component->TeCoId, $subTeam);
        $groupKey = $component->TeEvent . '|' . $key;
        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'key' => $key,
                'countryId' => intval($component->TeCoId),
                'subTeam' => $subTeam,
                'rank' => intval($component->TeRank),
                'score' => intval($component->TeScore),
                'gold' => intval($component->TeGold),
                'xnine' => intval($component->TeXnine),
                'hits' => intval($component->TeHits),
                'code' => $component->CoCode ?? '',
                'name' => ($component->CoName ?? '') . ($subTeam > 1 ? ' (' . $subTeam . ')' : ''),
                'athleteIds' => [],
            ];
        }
        if (intval($component->TcId) > 0) {
            $groups[$groupKey]['athleteIds'][] = intval($component->TcId);
        }
    }
    foreach ($groups as $groupKey => $group) {
        [$eventCode] = explode('|', $groupKey, 2);
        if (isset($events[$eventCode])) {
            $events[$eventCode]['groups'][] = $group;
        }
    }

    $slotQ = safe_r_sql("SELECT EvCode, EvFinalFirstPhase, GrMatchNo, GrPhase, GrPosition, TfTeam, TfSubTeam, FSLetter
        FROM Events
        INNER JOIN Phases ON PhId=EvFinalFirstPhase AND (PhIndTeam & 2)>0
        INNER JOIN Grids ON GrPhase=GREATEST(PhId, PhLevel)
        LEFT JOIN TeamFinals ON TfEvent=EvCode AND TfTournament=EvTournament AND TfMatchNo=GrMatchNo
        LEFT JOIN FinSchedule ON FSEvent=EvCode AND FSTournament=EvTournament AND FSTeamEvent=1 AND FSMatchNo=GrMatchNo
        WHERE EvTournament=" . StrSafe_DB($toId) . " AND EvTeamEvent=1 AND EvCode IN ($quotedEvents)
        ORDER BY EvCode, GrMatchNo ASC");
    $matchesByEvent = [];
    while ($slot = safe_fetch($slotQ)) {
        if (!isset($events[$slot->EvCode])) continue;
        $matchBase = intval($slot->GrMatchNo);
        if ($matchBase % 2 !== 0) $matchBase -= 1;
        if (!isset($matchesByEvent[$slot->EvCode][$matchBase])) {
            $matchesByEvent[$slot->EvCode][$matchBase] = [
                'matchNo' => $matchBase,
                'phase' => intval($slot->GrPhase),
                'target' => normalize_target($slot->FSLetter ?? ''),
                'sideA' => '',
                'sideB' => '',
            ];
        }

        $side = (intval($slot->GrMatchNo) % 2 === 0) ? 'sideA' : 'sideB';
        $matchesByEvent[$slot->EvCode][$matchBase][$side] = team_key($slot->TfTeam, $slot->TfSubTeam);
        if ($matchesByEvent[$slot->EvCode][$matchBase]['target'] === '') {
            $matchesByEvent[$slot->EvCode][$matchBase]['target'] = normalize_target($slot->FSLetter ?? '');
        }
    }

    foreach ($matchesByEvent as $eventCode => $matches) {
        $events[$eventCode]['matches'] = array_values($matches);
    }

    return array_values($events);
}

function athlete_rows_by_id($toId, $eventCode, $countryId, $athleteIds) {
    if (count($athleteIds) === 0) return [];
    $ids = implode(',', array_map('intval', $athleteIds));
    $rows = [];
    $q = safe_r_sql("SELECT DISTINCT EnId, QuScore, QuGold, QuXnine, QuHits
        FROM EventClass
        INNER JOIN Entries ON EnTournament=EcTournament AND EnDivision=EcDivision AND EnClass=EcClass
        LEFT JOIN Qualifications ON QuId=EnId
        WHERE EcTournament=" . StrSafe_DB($toId) . "
          AND EcTeamEvent=1
          AND EcCode=" . StrSafe_DB($eventCode) . "
          AND EnAthlete=1
          AND EnCountry=" . StrSafe_DB($countryId) . "
          AND EnId IN ($ids)");
    while ($row = safe_fetch($q)) {
        $rows[intval($row->EnId)] = [
            'id' => intval($row->EnId),
            'score' => intval($row->QuScore ?? 0),
            'gold' => intval($row->QuGold ?? 0),
            'xnine' => intval($row->QuXnine ?? 0),
            'hits' => intval($row->QuHits ?? 0),
        ];
    }
    return $rows;
}

function save_groups($toId, $groups) {
    if (!is_array($groups)) {
        fail_json('groups must be an array', 400);
    }

    $byEvent = [];
    foreach ($groups as $group) {
        $eventCode = clean_event_code($group['eventCode'] ?? '');
        $countryId = intval($group['countryId'] ?? 0);
        $subTeam = intval($group['subTeam'] ?? 1);
        $athleteIds = is_array($group['athleteIds'] ?? null) ? array_values(array_unique(array_map('intval', $group['athleteIds']))) : [];
        $athleteIds = array_values(array_filter($athleteIds, fn($id) => $id > 0));

        if ($eventCode === '' || $countryId <= 0) {
            fail_json('Each team group needs eventCode and countryId', 400);
        }
        if ($subTeam <= 0) $subTeam = 1;
        if (count($athleteIds) === 0) continue;

        $eventQ = safe_r_sql("SELECT EvCode, EvMaxTeamPerson FROM Events WHERE EvTournament=" . StrSafe_DB($toId) . " AND EvTeamEvent=1 AND EvCode=" . StrSafe_DB($eventCode));
        if (!$event = safe_fetch($eventQ)) {
            fail_json("Team event $eventCode not found", 422);
        }
        $teamSize = max(1, intval($event->EvMaxTeamPerson));
        if (count($athleteIds) > $teamSize) {
            fail_json("Team $eventCode has more athletes than allowed", 422);
        }

        $rowsById = athlete_rows_by_id($toId, $eventCode, $countryId, $athleteIds);
        if (count($rowsById) !== count($athleteIds)) {
            fail_json("One or more athletes are not eligible for $eventCode", 422);
        }

        $byEvent[$eventCode][] = [
            'eventCode' => $eventCode,
            'countryId' => $countryId,
            'subTeam' => $subTeam,
            'teamSize' => $teamSize,
            'athleteIds' => $athleteIds,
            'rowsById' => $rowsById,
        ];
    }

    foreach ($byEvent as $eventCode => $eventGroups) {
        safe_w_sql("UPDATE Events SET
                EvFinEnds=IF(EvFinEnds=0, 4, EvFinEnds),
                EvFinArrows=IF(EvFinArrows=0, IF(EvMixedTeam=1, 4, 6), EvFinArrows),
                EvFinSO=IF(EvFinSO=0, IF(EvMixedTeam=1, 2, 3), EvFinSO)
            WHERE EvTournament=" . StrSafe_DB($toId) . " AND EvTeamEvent=1 AND EvCode=" . StrSafe_DB($eventCode));
        safe_w_sql("DELETE FROM TeamFinals WHERE TfTournament=" . StrSafe_DB($toId) . " AND TfEvent=" . StrSafe_DB($eventCode));
        safe_w_sql("DELETE FROM TeamFinComponent WHERE TfcTournament=" . StrSafe_DB($toId) . " AND TfcEvent=" . StrSafe_DB($eventCode));
        safe_w_sql("DELETE FROM TeamComponent WHERE TcTournament=" . StrSafe_DB($toId) . " AND TcEvent=" . StrSafe_DB($eventCode) . " AND TcFinEvent=1");
        safe_w_sql("DELETE FROM Teams WHERE TeTournament=" . StrSafe_DB($toId) . " AND TeEvent=" . StrSafe_DB($eventCode) . " AND TeFinEvent=1");

        $rankRows = [];
        foreach ($eventGroups as $group) {
            $score = 0; $gold = 0; $xnine = 0; $hits = 0;
            foreach ($group['athleteIds'] as $athleteId) {
                $row = $group['rowsById'][$athleteId];
                $score += $row['score'];
                $gold += $row['gold'];
                $xnine += $row['xnine'];
                $hits += $row['hits'];
            }
            $group['score'] = $score;
            $group['gold'] = $gold;
            $group['xnine'] = $xnine;
            $group['hits'] = $hits;
            $rankRows[] = $group;
        }

        usort($rankRows, function ($a, $b) {
            return [$b['score'], $b['gold'], $b['xnine'], $b['hits'], -$a['countryId'], -$a['subTeam']]
                <=> [$a['score'], $a['gold'], $a['xnine'], $a['hits'], -$b['countryId'], -$b['subTeam']];
        });

        $rank = 1;
        foreach ($rankRows as $group) {
            safe_w_sql("REPLACE INTO Teams (TeCoId, TeSubTeam, TeEvent, TeTournament, TeFinEvent, TeScore, TeGold, TeXnine, TeFinal, TeHits, TeRank, TeIsValidTeam, TeTimeStamp)
                VALUES (" . StrSafe_DB($group['countryId']) . ", " . StrSafe_DB($group['subTeam']) . ", " . StrSafe_DB($eventCode) . ", " . StrSafe_DB($toId) . ", 1, " . StrSafe_DB($group['score']) . ", " . StrSafe_DB($group['gold']) . ", " . StrSafe_DB($group['xnine']) . ", 0, " . StrSafe_DB($group['hits']) . ", " . StrSafe_DB($rank) . ", " . (count($group['athleteIds']) >= $group['teamSize'] ? 1 : 0) . ", NOW())");

            $values = [];
            foreach ($group['athleteIds'] as $index => $athleteId) {
                $values[] = "(" . StrSafe_DB($group['countryId']) . ", " . StrSafe_DB($group['subTeam']) . ", " . StrSafe_DB($toId) . ", " . StrSafe_DB($eventCode) . ", 1, " . StrSafe_DB($athleteId) . ", " . StrSafe_DB($index + 1) . ")";
            }
            if (count($values) > 0) {
                safe_w_sql("REPLACE INTO TeamComponent (TcCoId, TcSubTeam, TcTournament, TcEvent, TcFinEvent, TcId, TcOrder) VALUES " . implode(',', $values));
                safe_w_sql("REPLACE INTO TeamFinComponent (TfcCoId, TfcSubTeam, TfcTournament, TfcEvent, TfcId, TfcOrder, TfcTimeStamp)
                    SELECT TcCoId, TcSubTeam, TcTournament, TcEvent, TcId, TcOrder, NOW()
                    FROM TeamComponent
                    WHERE TcTournament=" . StrSafe_DB($toId) . " AND TcEvent=" . StrSafe_DB($eventCode) . " AND TcCoId=" . StrSafe_DB($group['countryId']) . " AND TcSubTeam=" . StrSafe_DB($group['subTeam']) . " AND TcFinEvent=1");
            }
            $rank++;
        }
    }
}

function normalize_target($letter) {
    $letter = trim(strval($letter));
    return $letter;
}

function save_pairings($toId, $pairings) {
    if (!is_array($pairings)) {
        fail_json('pairings must be an array', 400);
    }

    $eventsToRefresh = [];
    foreach ($pairings as $row) {
        $eventCode = clean_event_code($row['eventCode'] ?? '');
        $matchNo = intval($row['matchNo'] ?? -1);
        if ($eventCode === '' || $matchNo < 0) {
            fail_json('Each pairing needs eventCode and matchNo', 400);
        }
        if ($matchNo % 2 !== 0) $matchNo -= 1;

        $eventQ = safe_r_sql("SELECT EvCode FROM Events WHERE EvTournament=" . StrSafe_DB($toId) . " AND EvTeamEvent=1 AND EvCode=" . StrSafe_DB($eventCode));
        if (safe_num_rows($eventQ) !== 1) {
            fail_json("Team event $eventCode not found", 422);
        }

        write_pairing_side($toId, $eventCode, $matchNo, $row['sideA'] ?? '');
        write_pairing_side($toId, $eventCode, $matchNo + 1, $row['sideB'] ?? '');
        $eventsToRefresh[$eventCode] = $eventCode;
    }

    foreach ($eventsToRefresh as $eventCode) {
        safe_w_sql("DELETE FROM TeamFinComponent WHERE TfcTournament=" . StrSafe_DB($toId) . " AND TfcEvent=" . StrSafe_DB($eventCode));
        safe_w_sql("INSERT INTO TeamFinComponent (TfcCoId, TfcSubTeam, TfcTournament, TfcEvent, TfcId, TfcOrder, TfcTimeStamp)
            SELECT TcCoId, TcSubTeam, TcTournament, TcEvent, TcId, TcOrder, NOW()
            FROM TeamComponent
            INNER JOIN TeamFinals ON TfTeam=TcCoId AND TfSubTeam=TcSubTeam AND TfEvent=TcEvent AND TfTournament=TcTournament
            WHERE TcFinEvent=1 AND TcTournament=" . StrSafe_DB($toId) . " AND TcEvent=" . StrSafe_DB($eventCode));
        safe_w_sql("UPDATE Events SET EvShootOff=1 WHERE EvTournament=" . StrSafe_DB($toId) . " AND EvTeamEvent=1 AND EvCode=" . StrSafe_DB($eventCode));
    }
}

function write_pairing_side($toId, $eventCode, $matchNo, $teamKey) {
    [$countryId, $subTeam] = parse_team_key($teamKey);
    safe_w_sql("INSERT INTO TeamFinals (TfEvent, TfMatchNo, TfTournament, TfTeam, TfSubTeam, TfDateTime)
        VALUES (" . StrSafe_DB($eventCode) . ", " . StrSafe_DB($matchNo) . ", " . StrSafe_DB($toId) . ", " . StrSafe_DB($countryId) . ", " . StrSafe_DB($subTeam) . ", NOW())
        ON DUPLICATE KEY UPDATE
            TfTeam=VALUES(TfTeam),
            TfSubTeam=VALUES(TfSubTeam),
            TfDateTime=VALUES(TfDateTime)");
}
?>
