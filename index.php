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
    <meta name="format-detection" content="address=no">
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
        h1 { font-size: 1.8rem; text-align: center }
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

        .event {
            h3 {
                cursor: pointer;
            }

            h3 {
                font-size: 1rem;

                .year {
                    font-size: .8rem;
                }

                @media (min-width: 768px) {
                    font-size: 1.2rem;

                    .year {
                        font-size: 1rem;
                    }
                }
            }

            &:not(.completed) h3 {
                color: #555;
                font-weight: normal;
            }

            .details {
                display: none;
            }

            &.open .details {
                display: flex;
            }
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

echo '<a href="#session-1">Session 1</a> &bull; <a href="#session-2">Session 2</a>&bull; <a href="#session-3">Session 3</a>';

$session = 1;
$lastCompleted = false;

foreach ($data['events'] as $i => $event) {
    $completed = isset($event['final']);

    if ($i == 0 || $i == 18 || $i == 42) {
        echo '<h3 class="mt-3" id="session-' . $session . '">Session ' . $session++ . '</h3>';
    }

    echo '<div class="event ' . ($completed ? 'completed' : 'pending') . '">';

    echo '<h3>';
    echo '<div class="row">';
    echo '<div class="col d-flex align-items-center gap-2">';
    echo $event['name'];
    if ($completed) {
        echo ' <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" class="text-success" viewBox="0 0 24 24"><!-- Icon from Material Symbols by Google - https://github.com/google/material-design-icons/blob/master/LICENSE --><path fill="currentColor" d="m10.6 16.6l7.05-7.05l-1.4-1.4l-5.65 5.65l-2.85-2.85l-1.4 1.4zM12 22q-2.075 0-3.9-.788t-3.175-2.137T2.788 15.9T2 12t.788-3.9t2.137-3.175T8.1 2.788T12 2t3.9.788t3.175 2.137T21.213 8.1T22 12t-.788 3.9t-2.137 3.175t-3.175 2.138T12 22"/></svg>';
    } else if ($lastCompleted) {
        echo ' <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" class="text-muted" viewBox="0 0 24 24"><!-- Icon from Material Symbols by Google - https://github.com/google/material-design-icons/blob/master/LICENSE --><path fill="currentColor" d="M7 13.5q.625 0 1.063-.437T8.5 12t-.437-1.062T7 10.5t-1.062.438T5.5 12t.438 1.063T7 13.5m5 0q.625 0 1.063-.437T13.5 12t-.437-1.062T12 10.5t-1.062.438T10.5 12t.438 1.063T12 13.5m5 0q.625 0 1.063-.437T18.5 12t-.437-1.062T17 10.5t-1.062.438T15.5 12t.438 1.063T17 13.5M12 22q-2.075 0-3.9-.788t-3.175-2.137T2.788 15.9T2 12t.788-3.9t2.137-3.175T8.1 2.788T12 2t3.9.788t3.175 2.137T21.213 8.1T22 12t-.788 3.9t-2.137 3.175t-3.175 2.138T12 22"/></svg>';
    }
    echo '</div>';
    echo '<div class="col-auto fw-normal">';
    if (isset($event['record'])) {
        echo $event['record']['value'];
        echo ' <span class="year">(';
        echo '<span class="d-none d-md-inline">' . $event['record']['club'] . '</span>';
        echo '<span class="d-inline d-md-none text-uppercase">' . substr($event['record']['club'], 0, 3) . '</span>';
        echo ' ' . $event['record']['year'];
        echo ')</span>';
    }
    echo '</div>';
    echo '</div>';
    echo '</h3>';

    echo '<div class="row details mb-3">';

    if ($completed) {
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
    } else {
        if (isset($event['heats'])) {
            echo '<div class="col-md-7">';

            foreach ($event['heats'] as $heat) {
                echo '<ul class="list-group mb-3">';
                echo '<li class="list-group-item active px-2 py-1">Lane&nbsp;&nbsp;' . (count($event['heats']) > 1 ? $heat['name'] : 'Final') . '</li>';
                foreach ($heat['results'] as $result) {
                    echo '<li class="list-group-item p-1">';
                    echo '<div class="row justify-content-between">';
                    echo '<div class="col-1 text-center"><span class="badge rounded-pill text-bg-secondary">' . $result['lane'] . '</span></div>';
                    
                    echo '<div class="col">' . $result['clubs'] . '</div>';
                    
                    echo '</div>';
                    echo '</li>';
                }
                echo '</ul>';
            }

            echo '</div>'; // col
        }
    }

    echo '</div>'; // row
    echo '</div>'; // event

    $lastCompleted = $completed;
}

?>

</main>

<footer>
    <div class="container py-3 mt-4 text-center">
        Last updated: <?php echo date('H:i j F Y', $data['updated']) ?><br>
        &copy; <?php echo date('Y') ?> Capital Athletics
    </div>	
</footer>

<script>
    const events = document.querySelectorAll('.event h3');

    events.forEach(event => {
        event.addEventListener('click', () => {
            // if (!event.classList.contains('completed')) return;
            
            events.forEach(e => {
                if (e !== event) e.closest('.event').classList.remove('open');
            });

            const parentEvent = event.closest('.event');

            parentEvent.classList.toggle('open');

            parentEvent.scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        });
    });
</script>

</body>
</html>
