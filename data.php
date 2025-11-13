<?php

include_once 'utils.php';

header('Content-Type: application/json; charset=utf-8');

$dataObj = [
    'title' => getenv('EVENT_NAME'),
    'events' => []
];

$csvFile = 'data/records.csv';

$recordsMap = [];
if (($fh = fopen($csvFile, 'r')) !== false) {
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < 2) continue; // skip malformed rows
        $key = trim($row[0]);
        $timeStr = trim($row[1]);
        $dateStr = trim($row[2]);
        $recordsMap[$key] = ['record' => parseTimeToSeconds($timeStr), 'date' => $dateStr];
    }
    fclose($fh);
}

$apiUrl = getenv('API_URL') . '/results';

// --- fetch the API response ---
$response = @file_get_contents($apiUrl);

if ($response === false) {
    die(json_encode(['error' => 'Unable to connect to API']));
}

$data = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die(json_encode(['error' => 'Invalid API response']));
}

if (!isset($data['data']) || !is_array($data['data'])) {
die(json_encode(['error' => 'Invalid API response']));
}

$pointsLookup = [13, 11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1];
$clubsScore = [];

$heatLetters = ['A', 'B', 'C'];

foreach ($data['data'] as $counter => $raceBlock) {
    if (!isset($raceBlock['race'])) continue;

    $race = $raceBlock['race'];
    $age = $race['age'];
    $gender = $race['gender'];
    $gender = $gender == 'M'
        ? (
            $age == 'Open'
                ? 'men'
                : 'boys'
        )
        : (
            $gender == 'F'
                ? (
                    $age == 'Open'
                        ? 'women'
                        : 'girls'
                )
                : 'mixed'
        );

    $eventName = $age . ' ' . $gender . ' ' . $race['event'];

    $raceClubs = [];

    $timeAdjustment = 0;
    if ($age != 'Open' && (int)str_replace('U', '', $age) < 13) {
        $timeAdjustment = .24;
    }

    $eventObj = [
        'name' => '#' . ($counter + 1) . ': ' . $eventName,
    ];

    if (isset($recordsMap[$eventName])) {
        $eventObj['record'] = [
            'value' => formatTime($recordsMap[$eventName]['record']),
            'year' => $recordsMap[$eventName]['date']
        ];
    }

    if ($raceBlock['heats'] && $raceBlock['heats'][0] && $raceBlock['heats'][0]['results'][0]['time']) {
        if (count($raceBlock['heats']) > 1) {
            $eventObj['heats'] = [];

            foreach ($raceBlock['heats'] as $heat) {
                $heatObj = [
                    'name' => 'Heat ' . $heatLetters[$heat['heat']-1],
                    'results' => []
                ];

                foreach ($heat['results'] as $result) {
                    $clubs = array_column($result['clubs'], 'name');

                    $resultObj = [];
                    $resultObj['place'] = $result['place'] ? addOrdinalSuffix($result['place']) : 'X';
                    $resultObj['clubs'] = formatClubList($clubs);

                    if ($result['time']) {
                        $resultObj['time'] = formatTime($result['time'] + $timeAdjustment);

                        if (isset($recordsMap[$eventName]) && $result['time'] <= $recordsMap[$eventName]['record']) {
                            $resultObj['record'] = true;
                        }
                    }

                    $heatObj['results'][] = $resultObj;
                }

                $eventObj['heats'][] = $heatObj;
            }
        }

        // final
        $finalObj = [];
        foreach ($raceBlock['final']['results'] as $result) {
            $clubs = array_column($result['clubs'], 'name');

            $resultObj = [];
            $resultObj['place'] = $result['place'] ? addOrdinalSuffix($result['place']) : 'X';
            $resultObj['clubs'] = formatClubList($clubs);

            if ($result['time']) {
                $resultObj['time'] = formatTime($result['time'] + $timeAdjustment);

                if (isset($recordsMap[$eventName]) && $result['time'] <= $recordsMap[$eventName]['record']) {
                    $resultObj['record'] = true;
                }
            }

            $finalObj[] = $resultObj;

            if ($result['place'] == null || $result['place'] > 12) {
                $points_per_club = 1 / 4;
            } else {
                $points_per_club = $pointsLookup[$result['place']-1] / 4;
            }

            foreach ($clubs as $i => $club) {
                if (!isset($clubsScore[$club])) $clubsScore[$club] = 0;
                if (!isset($raceClubs[$club])) $raceClubs[$club] = ['count' => 0, 'points' => 0];

                $copies = count($clubs) == 1 ? 4 : (count($clubs) == 2 ? 2 : 1);
                $copies = min(4 - $raceClubs[$club]['count'], $copies);
                
                $points = $raceClubs[$club]['count'] < 4 ? $copies * $points_per_club : 0;
                $points = number_format($points, 2);

                $clubsScore[$club] += $points;
                $raceClubs[$club]['count'] += $copies;
                $raceClubs[$club]['points'] += $points;
            }
        }

        if ($finalObj) $eventObj['final'] = $finalObj;

        uasort($raceClubs, function ($a, $b) {
            return $b['points'] <=> $a['points'];
        });

        $pointsObj = [];
        foreach ($raceClubs as $name => $club) {
            $pointsObj[] = ['club' => $name, 'points' => number_format($club['points'], 2), 'count' => $club['count']];
        }

        if ($pointsObj) $eventObj['points'] = $pointsObj;
    }

    $dataObj['events'][] = $eventObj;
}

if (count($clubsScore)) {
    arsort($clubsScore);

    $dataObj['points'] = [];

    foreach ($clubsScore as $club => $score) {
        $dataObj['points'][] = ['club' => $club, 'points' => number_format($score, 2)];
    }
}

echo json_encode($dataObj);

function addOrdinalSuffix ($num) {
    if (!in_array($num % 100, [11, 12, 13])) {
        switch ($num % 10) {
            case 1: return $num . 'st';
            case 2: return $num . 'nd';
            case 3: return $num . 'rd';
        }
    }
    return $num . 'th';
}

function formatClubList($clubs) {
    // Count occurrences
    $counts = array_count_values($clubs);

    // Unique club names
    $unique = array_keys($counts);

    // Sort: highest count first, then alphabetically
    usort($unique, function($a, $b) use ($counts) {
        $diff = $counts[$b] - $counts[$a];
        return $diff !== 0 ? $diff : strcasecmp($a, $b);
    });

    // Build formatted output
    $formatted = array_map(function($name) use ($counts) {
        return $counts[$name] > 1 ? "{$name}&nbsp;x&nbsp;{$counts[$name]}" : $name;
    }, $unique);

    return implode(', ', $formatted);
}

function formatTime(float $seconds): string {
    if ($seconds < 60) {
        return number_format($seconds, 2) . 's';
    }

    $minutes = floor($seconds / 60);
    $remainder = $seconds - ($minutes * 60);
    // Always show two digits for seconds, and two decimal places for fractions
    return sprintf("%d:%05.2fs", $minutes, $remainder);
}

function parseTimeToSeconds(string $s): float {
    $s = trim($s);
    if ($s === '') return 0.0;

    // Use dot as decimal separator (in case of locales)
    $s = str_replace(',', '.', $s);

    if (strpos($s, ':') !== false) {
        // "mm:ss.ms"
        [$m, $sec] = array_pad(explode(':', $s, 2), 2, '0');
        return ((int)$m) * 60 + (float)$sec;
    }

    // "ss.ms" or "ss"
    return (float)$s;
}