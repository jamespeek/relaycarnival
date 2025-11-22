<?php
/**
 * Relay Carnival JSON API 
 * - GET    /races                                    -> list races (table order by races.id)
 * - GET    /races/{raceId}                           -> get race by id
 * - POST   /results                                  -> add/replace a heat's results; responds with race + heats + final (if >=2 heats)
 * - GET    /results/{raceId}                         -> get results for a race (heats + optional final)
 * - DELETE /results/{raceId}/{heat}                  -> delete a heat (and its club links)
 * - GET    /results                                  -> all results grouped by races (table order), with heats and optional final
 *
 * JSON formats:
 * POST /results body:
 *   {
 *     "raceId": 12,
 *     "heat": 1,
 *     "results": [
 *       {"place":1, "time":59.21, "clubs":[5,5,5,8]},
 *       {"place":2, "time":60.05, "clubs":[3]},
 *       ...
 *     ]
 *   }
 *
 * Responses (POST + GET single + GET all):
 *   {
 *     "race": { id, age, gender, eventId, event },
 *     "heats": [ { "heat": 1, "results":[ {resultId, place, time, clubs:[{id,name}...]} ] }, ... ],
 *     "final": { "results":[ {place, time, heat, resultId, clubs:[{id,name}...]}, ... ] }   // only when >= 2 heats
 *   }
 *
 * CORS enabled; all inputs/outputs JSON.
 */

declare(strict_types=1);

require_once __DIR__ . '/../utils.php';

// ===================== BOOTSTRAP =====================
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function send_json($payload, int $code = 200): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function read_json_body(): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        send_json(['error' => 'Invalid JSON body: ' . json_last_error_msg()], 400);
    }
    return is_array($data) ? $data : [];
}

$pdo = init_db();

// ===================== HELPERS =====================
/** Table/meet order: rely on races.id ASC everywhere */
function raceOrderingSql(string $r = 'r'): string {
    return " ORDER BY {$r}.id ASC ";
}

function mapRaceRow(array $row): array {
    return [
        'id'      => (int)$row['raceId'],
        'age'     => $row['age'],
        'gender'  => $row['gender'],
        'eventId' => (int)$row['eventId'],
        'event'   => $row['event'],
    ];
}

function fetchRaces(PDO $pdo): array {
    $sql = "SELECT r.id AS raceId, a.age, r.gender, r.eventId, e.event
            FROM races r
            JOIN ages a   ON a.id = r.ageId
            JOIN events e ON e.id = r.eventId" . raceOrderingSql('r');
    $stmt = $pdo->query($sql);
    $out = [];
    while ($row = $stmt->fetch()) $out[] = mapRaceRow($row);
    return $out;
}

function fetchRace(PDO $pdo, int $raceId): ?array {
    $sql = "SELECT r.id AS raceId, a.age, r.gender, r.eventId, e.event
            FROM races r
            JOIN ages a   ON a.id = r.ageId
            JOIN events e ON e.id = r.eventId
            WHERE r.id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $raceId]);
    $row = $stmt->fetch();
    return $row ? mapRaceRow($row) : null;
}

function fetchClubs(PDO $pdo, ?string $q = null): array {
    if ($q !== null && $q !== '') {
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare("SELECT id, name FROM clubs WHERE name LIKE :q ORDER BY name ASC");
        $stmt->execute([':q' => $like]);
    } else {
        $stmt = $pdo->query("SELECT id, name FROM clubs ORDER BY name ASC");
    }
    $out = [];
    while ($row = $stmt->fetch()) {
        $out[] = ['id' => (int)$row['id'], 'name' => $row['name']];
    }
    return $out;
}

/** Finals helpers (shared) */
// Build a final from ALL heats (assoc form). Tied times share same place; untimed last with place=null.
function buildFinalFromHeats(array $heatsAssoc, int $tiePrecisionDp = 2): ?array {
    // if (count($heatsAssoc) < 2) return null;

    if (count($heatsAssoc) == 1 && isset($heatsAssoc['1'])) {
	    return ['results' => $heatsAssoc['1']['items']];
    }

    // flatten
    $flat = [];
    $heatNums = array_keys($heatsAssoc);
    sort($heatNums, SORT_NUMERIC);
    foreach ($heatNums as $h) {
        $items = $heatsAssoc[$h]['items'] ?? [];
        foreach ($items as $it) {
            $flat[] = [
                'heat'       => (int)$h,
                'resultId'   => (int)($it['resultId'] ?? 0),
                'time'       => array_key_exists('time', $it) ? $it['time'] : null,
                'clubs'      => $it['clubs'] ?? [],
                'lane'       => $it['lane'] ?? null,
                '_heatPlace' => $it['place'] ?? null,
            ];
        }
    }
    if (empty($flat)) return ['results' => []];

    // sort: time asc (null last) → original heat place → heat# → resultId
    usort($flat, function($a, $b) {
        $ta = $a['time']; $tb = $b['time'];
        $aNull = $ta === null; $bNull = $tb === null;
        if ($aNull !== $bNull) return $aNull ? 1 : -1;
        if (!$aNull && !$bNull) {
            if ($ta < $tb) return -1;
            if ($ta > $tb) return 1;
        }
        $pa = $a['_heatPlace']; $pb = $b['_heatPlace'];
        $paNull = $pa === null; $pbNull = $pb === null;
        if ($paNull !== $pbNull) return $paNull ? 1 : -1;
        if (!$paNull && !$pbNull) {
            if ($pa < $pb) return -1;
            if ($pa > $pb) return 1;
        }
        if ($a['heat'] !== $b['heat']) return $a['heat'] <=> $b['heat'];
        return $a['resultId'] <=> $b['resultId'];
    });

    // === PLACE ASSIGNMENT (standard competition ranking: 1,2,3,3,5) ===
    $position = 0;          // 1-based index across timed rows
    $currentPlace = 0;      // place value assigned to the current tie group
    $lastTimeKey = null;    // rounded time for tie detection

    foreach ($flat as &$row) {
        if ($row['time'] === null) {     // untimed go last with place:null
            $row['place'] = null;
            continue;
        }
        $position++; // increment for every timed row (drives the gap after ties)

        $timeKey = sprintf('%.' . $tiePrecisionDp . 'f', (float)$row['time']);
        if ($lastTimeKey === null) {
            // first timed row
            $currentPlace = $position;
            $row['place'] = $currentPlace;
            $lastTimeKey  = $timeKey;
        } else {
            if ($timeKey === $lastTimeKey) {
                // same time -> same place as the start of this tie group
                $row['place'] = $currentPlace;
            } else {
                // new (slower) time -> new group; place equals current position
                $currentPlace = $position;
                $row['place'] = $currentPlace;
                $lastTimeKey  = $timeKey;
            }
        }
        unset($row['_heatPlace']); // cleanup internal field early
    }
    unset($row);

    usort($flat, function ($a, $b) {
        $pa = $a['place']; $pb = $b['place'];
        if ($pa === null && $pb === null) return 0;
        if ($pa === null) return 1;      // nulls last
        if ($pb === null) return -1;
        return $pa <=> $pb;
    });

    return ['results' => $flat];
}

// Adapter: take heats array like [ {heat, results:[...]}, ... ] and make a final
function finalFromHeatsArray(array $heats, int $tiePrecisionDp = 2): ?array {
    // if (count($heats) < 2) return null;
    $assoc = [];
    foreach ($heats as $h) {
        $assoc[(int)$h['heat']] = ['items' => $h['results'] ?? []];
    }
    return buildFinalFromHeats($assoc, $tiePrecisionDp);
}

/** Readers (preserve duplicate clubs in slot order) */
function fetchResultsByRace(PDO $pdo, int $raceId): array {
    $sql = "SELECT rt.id AS resultId, rt.heat, rt.place, rt.time,
                   rc.slot, rc.clubId, rc.lane, rc.lane, c.name AS club
            FROM results rt
            LEFT JOIN results_clubs rc ON rc.resultId = rt.id
            LEFT JOIN clubs c          ON c.id = rc.clubId
            WHERE rt.raceId = :raceId
            ORDER BY rt.heat ASC,
                     rt.id ASC,
                     rc.slot ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':raceId' => $raceId]);

    $byHeat = [];
    while ($row = $stmt->fetch()) {
        $heat = (int)$row['heat'];
        $rid  = (int)$row['resultId'];
        $byHeat[$heat] ??= ['_idx' => [], 'items' => []];

        if (!isset($byHeat[$heat]['_idx'][$rid])) {
            $byHeat[$heat]['_idx'][$rid] = count($byHeat[$heat]['items']);
            $byHeat[$heat]['items'][] = [
                'resultId' => $rid,
                'place'    => $row['place'] !== null ? (int)$row['place'] : null,
                'time'     => $row['time']  !== null ? (float)$row['time']  : null,
                'lane'     => $row['lane'] !== null ? (int)$row['lane'] : null,
                'clubs'    => [], // push in rc.slot order; duplicates preserved
            ];
        }
        if ($row['clubId'] !== null) {
            $i = $byHeat[$heat]['_idx'][$rid];
            $byHeat[$heat]['items'][$i]['clubs'][] = [
                'id'   => (int)$row['clubId'],
                'name' => $row['club'],
            ];
        }
    }

    $heats = [];
    if (!empty($byHeat)) {
        ksort($byHeat, SORT_NUMERIC);
        foreach ($byHeat as $hNum => $data) {
            $heats[] = ['heat' => (int)$hNum, 'results' => array_values($data['items'])];
        }
    }
    return $heats;
}

function fetchAllResultsGrouped(PDO $pdo): array {
    // Races in table order
    $racesSql = "SELECT r.id AS raceId, a.age, r.gender, r.eventId, e.event
                 FROM races r
                 JOIN ages a   ON a.id = r.ageId
                 JOIN events e ON e.id = r.eventId
                 " . raceOrderingSql('r');
    $races = $pdo->query($racesSql)->fetchAll();
    if (!$races) return [];

    // One pass for all results + clubs
    $resultsSql = "SELECT rr.id AS raceId, a.age, rr.gender, rr.eventId, e.event,
                          rt.id AS resultId, rt.heat, rt.place, rt.time,
                          rc.slot, rc.clubId, rc.lane, c.name AS club
                   FROM races rr
                   JOIN ages a   ON a.id = rr.ageId
                   JOIN events e ON e.id = rr.eventId
                   LEFT JOIN results rt        ON rt.raceId = rr.id
                   LEFT JOIN results_clubs rc  ON rc.resultId = rt.id
                   LEFT JOIN clubs c           ON c.id = rc.clubId
                   ORDER BY rr.id ASC,
                            rt.heat ASC,
                            rt.place IS NULL ASC, rt.place ASC,
                            rt.time IS NULL ASC,  rt.time ASC,
                            rt.id ASC,
                            rc.slot ASC";
    $rows = $pdo->query($resultsSql);

    $byRace = []; // raceId -> heat -> result aggregation
    while ($row = $rows->fetch()) {
        $rid = (int)$row['raceId'];
        $byRace[$rid] ??= ['_heat' => []];

        if ($row['resultId'] === null) continue; // no results yet

        $heat = (int)$row['heat'];
        $resId = (int)$row['resultId'];
        $byRace[$rid]['_heat'][$heat] ??= ['_idx' => [], 'items' => []];

        if (!isset($byRace[$rid]['_heat'][$heat]['_idx'][$resId])) {
            $byRace[$rid]['_heat'][$heat]['_idx'][$resId] = count($byRace[$rid]['_heat'][$heat]['items']);
            $byRace[$rid]['_heat'][$heat]['items'][] = [
                'resultId' => $resId,
                'place'    => $row['place'] !== null ? (int)$row['place'] : null,
                'time'     => $row['time']  !== null ? (float)$row['time']  : null,
                'lane'     => $row['lane'] !== null ? (int)$row['lane'] : null,
                'clubs'    => [],
            ];
        }
        if ($row['clubId'] !== null) {
            $i = $byRace[$rid]['_heat'][$heat]['_idx'][$resId];
            $byRace[$rid]['_heat'][$heat]['items'][$i]['clubs'][] = [
                'id'   => (int)$row['clubId'],
                'name' => $row['club'],
            ];
        }
    }

    // Build final output preserving race order
    $out = [];
    foreach ($races as $r) {
        $rid = (int)$r['raceId'];
        $heats = [];
        $assoc = $byRace[$rid]['_heat'] ?? [];
        if (!empty($assoc)) {
            ksort($assoc, SORT_NUMERIC);
            foreach ($assoc as $hNum => $data) {
                $heats[] = ['heat' => (int)$hNum, 'results' => array_values($data['items'])];
            }
        }
        $entry = ['race' => mapRaceRow($r), 'heats' => $heats];
        // if (count($heats) >= 2) {
            $final = finalFromHeatsArray($heats);
            if ($final !== null) $entry['final'] = $final;
        // }
        $out[] = $entry;
    }
    return $out;
}

/** Replace a heat's results (allow duplicate clubs and preserve order via slot 1..4). */
function replaceResultsSet(PDO $pdo, int $raceId, int $heat, array $items): array {
    // Validate
    foreach ($items as $i => $it) {
        if (!isset($it['clubs']) || !is_array($it['clubs'])) {
            throw new InvalidArgumentException("results[$i].clubs array is required");
        }
        if (count($it['clubs']) < 1 || count($it['clubs']) > 4) {
            throw new InvalidArgumentException("results[$i].clubs must have 1 to 4 items");
        }
        foreach ($it['clubs'] as $j => $cid) {
            if (!is_int($cid) && !ctype_digit((string)$cid)) {
                throw new InvalidArgumentException("results[$i].clubs[$j] must be an integer clubId");
            }
        }
        if (isset($it['place']) && $it['place'] !== null && !is_int($it['place']) && !ctype_digit((string)$it['place'])) {
            throw new InvalidArgumentException("results[$i].place must be an integer or null");
        }
        if (isset($it['time']) && $it['time'] !== null && !is_numeric($it['time'])) {
            throw new InvalidArgumentException("results[$i].time must be a number or null");
        }

        if (isset($it['lane']) && $it['lane'] !== null &&
            !is_int($it['lane']) && !ctype_digit((string)$it['lane'])) {
            throw new InvalidArgumentException("results[$i].lane must be an integer or null");
        }
    }

    $pdo->beginTransaction();
    try {
        // Replace semantics: delete existing set for this race+heat (clubs then results)
        $pdo->prepare(
            "DELETE rc FROM results_clubs rc
             JOIN results r ON r.id = rc.resultId
             WHERE r.raceId = :r AND r.heat = :h"
        )->execute([':r' => $raceId, ':h' => $heat]);

        $pdo->prepare("DELETE FROM results WHERE raceId = :r AND heat = :h")
            ->execute([':r' => $raceId, ':h' => $heat]);

        // Inserts
        $insResult = $pdo->prepare(
            "INSERT INTO results (raceId, heat, place, time)
             VALUES (:r, :h, :p, :t)"
        );
        $insRC = $pdo->prepare(
            "INSERT INTO results_clubs (resultId, slot, clubId, lane)
             VALUES (:rid, :slot, :cid, :lane)"
        );

        foreach ($items as $it) {
            $insResult->execute([
                ':r' => $raceId,
                ':h' => $heat,
                ':p' => array_key_exists('place', $it) ? ($it['place'] !== null ? (int)$it['place'] : null) : null,
                ':t' => array_key_exists('time',  $it) ? ($it['time']  !== null ? (float)$it['time'] : null) : null,
            ]);
            $rid = (int)$pdo->lastInsertId();

            $clubs = $it['clubs']; // duplicates allowed; preserve order
            if (count($clubs) > 4) {
                throw new InvalidArgumentException("results item has more than 4 clubs");
            }

            $lane = isset($it['lane']) && $it['lane'] !== null ? (int)$it['lane'] : null;

            $slot = 1; // reset per result
            foreach ($clubs as $cidRaw) {
                if ($slot > 4) break;
                $cid = (int)$cidRaw;
                if ($cid <= 0) throw new InvalidArgumentException("Invalid clubId in results item");
                $insRC->execute([
                    ':rid'  => $rid,
                    ':slot' => $slot++,
                    ':cid'  => $cid,
                    ':lane' => $lane
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Return the full heats structure for this race
    return fetchResultsByRace($pdo, $raceId);
}


if (!defined('INSIDE_API_INCLUDE')) {
    // ===================== ROUTING CORE =====================
    $method = $_SERVER['REQUEST_METHOD'];
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
    $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    if ($scriptDir !== '' && $scriptDir !== '/') {
        $uri = preg_replace('#^' . preg_quote($scriptDir, '#') . '#', '', $uri);
    }
    $uri = '/' . ltrim($uri, '/');
    $segments = array_values(array_filter(explode('/', $uri), fn($s) => $s !== ''));

    // ===================== ROUTES =====================
    try {
        // GET /races
        if ($method === 'GET' && $segments === ['races']) {
            send_json(fetchRaces($pdo));
        }

        // GET /races/{id}
        if ($method === 'GET' && count($segments) === 2 && $segments[0] === 'races') {
            $raceId = (int)$segments[1];
            if ($raceId <= 0) send_json(['error' => 'Invalid raceId'], 400);
            $race = fetchRace($pdo, $raceId);
            if (!$race) send_json(['error' => 'Race not found'], 404);
            send_json($race);
        }

        // POST /results  (replace a heat; respond with race + heats + optional final)
        if ($method === 'POST' && $segments === ['results']) {
            $body   = read_json_body();
            $raceId = isset($body['raceId']) ? (int)$body['raceId'] : 0;
            $heat   = isset($body['heat'])   ? (int)$body['heat']   : 0;
            $items  = isset($body['results']) && is_array($body['results']) ? $body['results'] : null;

            if ($raceId <= 0) send_json(['error' => 'raceId is required and must be > 0'], 400);
            if ($heat <= 0)   send_json(['error' => 'heat is required and must be > 0'], 400);
            if ($items === null) send_json(['error' => 'results array is required (can be empty to clear)'], 400);

            $race = fetchRace($pdo, $raceId);
            if (!$race) send_json(['error' => 'Race not found'], 404);

            if (empty($items)) {
                // Clear the heat
                $pdo->beginTransaction();
                try {
                    $pdo->prepare(
                        "DELETE rc FROM results_clubs rc
                        JOIN results r ON r.id = rc.resultId
                        WHERE r.raceId = :r AND r.heat = :h"
                    )->execute([':r' => $raceId, ':h' => $heat]);
                    $pdo->prepare("DELETE FROM results WHERE raceId = :r AND heat = :h")
                        ->execute([':r' => $raceId, ':h' => $heat]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
                $heats = fetchResultsByRace($pdo, $raceId);
            } else {
                $heats = replaceResultsSet($pdo, $raceId, $heat, $items);
            }

            $resp = ['race' => $race, 'heats' => $heats];
            // if (count($heats) >= 2) {
                $final = finalFromHeatsArray($heats);
                if ($final !== null) $resp['final'] = $final;
            // }
            send_json($resp, 201);
        }

        // GET /results/{raceId}  (grouped by heat + final across all heats)
        if ($method === 'GET' && count($segments) === 2 && $segments[0] === 'results') {
            $raceId = (int)$segments[1];
            if ($raceId <= 0) send_json(['error' => 'Invalid raceId'], 400);
            $race = fetchRace($pdo, $raceId);
            if (!$race) send_json(['error' => 'Race not found'], 404);

            $heats = fetchResultsByRace($pdo, $raceId);
            $resp = ['race' => $race, 'heats' => $heats];
            // if (count($heats) >= 2) {
                $final = finalFromHeatsArray($heats);
                if ($final !== null) $resp['final'] = $final;
            // }
            send_json($resp);
        }

        // DELETE /results/{raceId}/{heat}
        if ($method === 'DELETE' && count($segments) === 3 && $segments[0] === 'results') {
            $raceId = (int)$segments[1];
            $heat   = (int)$segments[2];
            if ($raceId <= 0 || $heat <= 0) send_json(['error' => 'Invalid raceId or heat'], 400);

            $race = fetchRace($pdo, $raceId);
            if (!$race) send_json(['error' => 'Race not found'], 404);

            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "DELETE rc FROM results_clubs rc
                    JOIN results r ON r.id = rc.resultId
                    WHERE r.raceId = :r AND r.heat = :h"
                )->execute([':r' => $raceId, ':h' => $heat]);

                $del = $pdo->prepare("DELETE FROM results WHERE raceId = :r AND heat = :h");
                $del->execute([':r' => $raceId, ':h' => $heat]);

                $pdo->commit();
                $deleted = $del->rowCount();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }

            send_json(['deleted' => $deleted, 'race' => $race, 'heat' => $heat]);
        }

        // GET /results  (all races, heats + final)
        if ($method === 'GET' && $segments === ['results']) {
            $data = fetchAllResultsGrouped($pdo);
            send_json($data);
        }

        // GET /clubs  (optional ?q= substring filter)
        if ($method === 'GET' && $segments === ['clubs']) {
            // Use $_GET['q'] if present; already CORS+JSON headers are set globally
            $q = isset($_GET['q']) ? (string)$_GET['q'] : null;
            $clubs = fetchClubs($pdo, $q);
            send_json($clubs);
        }

        // Fallback
        send_json([
            'error' => 'Not found',
            'hint'  => 'Try GET /races, GET /races/{id}, POST /results, GET /results/{raceId}, DELETE /results/{raceId}/{heat}, GET /results'
        ], 404);

    } catch (InvalidArgumentException $e) {
        send_json(['error' => $e->getMessage()], 400);
    } catch (PDOException $e) {
        send_json(['error' => 'Database error', 'details' => $e->getMessage()], 500);
    } catch (Throwable $e) {
        send_json(['error' => 'Unexpected error', 'details' => $e->getMessage()], 500);
    }
}
