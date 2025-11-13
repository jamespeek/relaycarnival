<?php

include_once 'utils.php';

if (isset($_GET['refresh']) && $_GET['refresh'] == getenv('SYNC_KEY')) {
    $json = file_get_contents(getenv('JSON_URL'));
    file_put_contents('data/results.json', $json);
}

$data = json_decode(file_get_contents('data/results.json'), true);

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Capital Athletics <?php echo $data['title'] ?></title>
    <meta name="description" content="Capital Athletics <?php echo $data['title'] ?> results page">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <style>
        :root {
            --bs-primary: #0f3f89;
            --bs-primary-rgb: 15, 63, 137;
        }

        html {
            scroll-behavior: auto !important;
        }

        .list-group {
            --bs-list-group-active-bg: var(--bs-primary);
            --bs-list-group-active-border-color: var(--bs-primary);
        }

        header, footer { color: #fff; background-color: var(--bs-primary); }
        h1 { font-size: 1.8rem }
        h2 { font-size: 1.6rem }
        h3 { font-size: 1.2rem }

        h3 {
            border-bottom: 1px solid var(--bs-primary);
            padding-bottom: .2rem;
            margin-bottom: 1rem;
            page-break-before: always;
        }

        h2 + .event h3 {
            page-break-before: avoid;
        }

        #final {
            page-break-before: always;
        }

        thead {
            tr {
                border: 0;
            }
            th {
                font-weight: normal;
                color: #fff !important;
                background-color: var(--bs-primary) !important;
            }
            th:first-child {
                border: 0;
                border-top-left-radius: var(--bs-border-radius);
            }
            th:last-child {
                border: 0;
                border-top-right-radius: var(--bs-border-radius);
            }
        }

        .col-1 {
            width: 48px;
            padding-right: 0;
        }
        h1, h2, h3 {
            page-break-after: avoid;
            color: var(--bs-primary);
        }

        .event, table {
            page-break-inside: avoid;
        }

        @media print {
            header { display: none; }
            .container,
            .container-fluid {
                font-size: 9pt;
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            .event:not(:has(ul)) {
                display: none;
            }
        }
    </style>
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo getenv('GTAG') ?>"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());

        gtag('config', '<?php echo getenv('GTAG') ?>');
    </script>
</head>
<body>

<header>
    <div class="container py-3 mb-3 text-center">
        <img src="assets/logo-capital-athletics.png" alt="Capital Athletics logo" title="Capital Athletics" width="300" height="49">
    </div>	
</header>

<main class="container">

<h1><?php echo $data['title'] ?></h1>

<?php

if (isset($data['points'])) {
    echo '<h2>Points tally</h2>';
    echo '<table class="table table-striped table-bordered">';
    echo '<thead><tr><th>Club</th><th style="width:100px" class="text-center">Score</th></tr></thead>';
    echo '<tbody>';
    foreach ($data['points'] as $row) {
        echo '<tr>';
        echo '<td>' . $row['club'] . '</td>';
        echo '<td class="text-end">' . $row['points'] . '</td>';
        echo '</tr>';
    }
    echo '</tbody>';
    echo '</table>';
}

echo '<h2>Event results</h2>';

foreach ($data['events'] as $event) {
    echo '<div class="event">';

    echo '<h3>';
    echo '<div class="row">';
    echo '<div class="col">' . $event['name'] . '</div>';
    echo '<div class="col-auto fw-normal">';
    if (isset($event['record'])) {
        echo '<span class="d-none d-sm-inline">Record: </span>' . $event['record']['value'] . ' (' . $event['record']['year'] . ')';
    }
    echo '</div>';
    echo '</div>';
    echo '</h3>';

    if (isset($event['heats']) || isset($event['final'])) {
        echo '<div class="row mb-3">';

        echo '<div class="col-md-7">';

        if (isset($event['heats'])) {
            foreach ($event['heats'] as $heat) {
                echo '<ul class="list-group mb-3">';
                echo '<li class="list-group-item active px-2 py-1">' . $heat['name'] . '</li>';
                foreach ($heat['results'] as $result) {
                    echo '<li class="list-group-item p-1">';
                    echo '<div class="row justify-content-between">';
                    echo '<div class="col-1 text-center"><span class="badge rounded-pill text-bg-secondary">' . $result['place'] . '</span></div>';
                    
                    echo '<div class="col">' . $result['clubs'] . '</div>';
                    
                    if (isset($result['time'])) {
                        if (isset($result['record'])) {
                            echo '<div class="col-auto"><span class="badge text-bg-warning">';
                        } else {
                            echo '<div class="col-auto"><span class="badge text-bg-light">';
                        }
                        echo $result['time'];
                        echo '</span></div>';
                    }
                    echo '</div>';
                    echo '</li>';
                }
                echo '</ul>';
            }
        }

        echo '<ul class="list-group mb-3">';
        echo '<li class="list-group-item active px-2 py-1">Final</li>';
        foreach ($event['final'] as $result) {
            echo '<li class="list-group-item p-1">';
            echo '<div class="row justify-content-between">';
            echo '<div class="col-1 text-center"><span class="badge rounded-pill text-bg-secondary">' . $result['place'] . '</span></div>';

            echo '<div class="col">' . $result['clubs'] . '</div>';

            if (isset($result['time'])) {
                if (isset($result['record'])) {
                    echo '<div class="col-auto"><span class="badge text-bg-warning">';
                } else {
                    echo '<div class="col-auto"><span class="badge text-bg-light">';
                }
                echo $result['time'];
                echo '</span></div>';
            }

            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</div>'; // col

        echo '<div class="col-md-5">';
        echo '<ul class="list-group mb-3">';
        echo '<li class="list-group-item active px-2 py-1">Points</li>';
        foreach ($event['points'] as $row) {
            echo '<li class="list-group-item px-2 py-1">';
            echo '<div class="row justify-content-between">';
            echo '<div class="col">' . $row['club'] . '</div>';
            echo '<div class="col-auto"><span class="badge text-bg-primary">' . $row['points'] . '</span></div>';
            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</div>'; // col

        echo '</div>'; // row
    }
    
    echo '</div>'; // event
}

?>

</main>

<footer>
    <div class="container py-3 mt-5 text-center">
        &copy; <?php echo date('Y') ?> Capital Athletics
    </div>	
</footer>

</body>
</html>
