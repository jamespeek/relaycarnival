<?php

date_default_timezone_set('Australia/Sydney');

$env = file_get_contents(__DIR__ . "/.env");
$lines = explode("\n",$env);

foreach($lines as $line){
  preg_match("/([^#]+)\=(.*)/", $line, $matches);
  if (isset($matches[2])) putenv(trim($line));
}

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

function init_db() {
    try {
        $dbHost = getenv('DB_HOST');
        $dbName = getenv('DB_NAME');
        $dbUser = getenv('DB_USERNAME');
        $dbPass = getenv('DB_PASSWORD');
        $dbCharset = getenv('DB_CHARSET') ?: 'utf8mb4';

        $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=$dbCharset";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e) {
        die(json_encode(['error' => 'DB connection failed', 'details' => $e->getMessage()]));
    }

    return $pdo;
}