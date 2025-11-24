<?php

require_once __DIR__ . '/utils.php';

define('INSIDE_API_INCLUDE', true);
require_once __DIR__ . '/api/index.php';

$pdo = init_db();
$results = fetchAllResultsGrouped($pdo);

$jsonObj = [
    'title' => getenv('EVENT_NAME'),
    'updated' => time(),
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
        $clubStr = trim($row[3]);
        $recordsMap[$key] = ['record' => parseTimeToSeconds($timeStr), 'date' => $dateStr, 'club' => $clubStr];
    }
    fclose($fh);
}

$pointsLookup = [13, 11, 10, 9, 8, 7, 6, 5, 4, 3, 2, 1];
$clubsScore = [];

$heatLetters = ['A', 'B', 'C'];

function adjustTime($time, $age) {
    if ($age != 'Open' && (int)str_replace('U', '', $age) < 13) {
        $time = ceil($time * 10) / 10;
    }

    return $time;
}

function adjustFormatTime($time, $age) {
    if ($age != 'Open' && (int)str_replace('U', '', $age) < 13) {
        return formatTime(adjustTime($time, $age)) . '¹';
    }

    return formatTime($time);
}

foreach ($results as $counter => $raceBlock) {
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

    $eventObj = [
        'name' => '#' . ($counter + 1) . ': ' . $eventName,
    ];

    if (isset($recordsMap[$eventName])) {
        $eventObj['record'] = [
            'value' => formatTime($recordsMap[$eventName]['record']),
            'year' => $recordsMap[$eventName]['date'],
            'club' => $recordsMap[$eventName]['club']
        ];
    }

    $hasResults = false;
    foreach ($raceBlock['final']['results'] as $result) {
        if ($result['time']) {
            $hasResults = true;
            break;
        }
    }

    if ($raceBlock['heats'] && $raceBlock['heats'][0]) {
        if (count($raceBlock['heats']) > 1 || !$hasResults) {
            $eventObj['heats'] = [];

            foreach ($raceBlock['heats'] as $heat) {
                $heatObj = [
                    'name' => 'Heat ' . $heatLetters[$heat['heat']-1],
                    'results' => []
                ];

                foreach ($heat['results'] as $result) {
                    $clubs = array_column($result['clubs'], 'name');

                    $resultObj = [];
                    if ($hasResults) {
                        $resultObj['place'] = $result['place'] ? addOrdinalSuffix($result['place']) : 'X';
                    } else {
                        $resultObj['lane'] = $result['lane'];
                    }
                    $resultObj['clubs'] = formatClubList($clubs);

                    if ($result['time']) {
                        $resultObj['time'] = adjustFormatTime($result['time'], $age);

                        if (isset($recordsMap[$eventName]) && adjustTime($result['time'], $age) <= $recordsMap[$eventName]['record']) {
                            $resultObj['record'] = true;
                        }
                    }

                    $heatObj['results'][] = $resultObj;
                }

                $eventObj['heats'][] = $heatObj;
            }
        }

        // final
		if ($hasResults) {
            $finalObj = [];

			foreach ($raceBlock['final']['results'] as $result) {
				$clubs = array_column($result['clubs'], 'name');

				$resultObj = [];
				$resultObj['place'] = $result['place'] ? addOrdinalSuffix($result['place']) : 'X';
				$resultObj['clubs'] = formatClubList($clubs);

				if ($result['time']) {
					$resultObj['time'] = adjustFormatTime($result['time'], $age);

					if (isset($recordsMap[$eventName]) && adjustTime($result['time'], $age) <= $recordsMap[$eventName]['record']) {
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

				$eventObj['final'] = $finalObj;
			}
		}

        uasort($raceClubs, function ($a, $b) {
            return $b['points'] <=> $a['points'];
        });

        $pointsObj = [];
        foreach ($raceClubs as $name => $club) {
            $pointsObj[] = ['club' => $name, 'points' => number_format($club['points'], 2), 'count' => $club['count']];
        }

        if ($pointsObj) $eventObj['points'] = $pointsObj;
    }

    $jsonObj['events'][] = $eventObj;
}

if (count($clubsScore)) {
    arsort($clubsScore);

    $jsonObj['points'] = [];

    foreach ($clubsScore as $club => $score) {
        $jsonObj['points'][] = ['club' => $club, 'points' => number_format($score, 2)];
    }
}

header('Content-Type: application/json; charset=utf-8');

echo json_encode($jsonObj);
