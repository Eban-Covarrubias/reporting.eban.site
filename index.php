<?php
require_once __DIR__ . '/lib/auth.php';
requireLogin();
$user = currentUser();

// Line chart: load time per page-load event, one series per page, over time.
$loadTimeRows = db()->query(
    "SELECT
       CASE WHEN page IN ('/', '/index.html', 'index') THEN '/index.html' ELSE page END AS page,
       created_at,
       JSON_UNQUOTE(JSON_EXTRACT(data, '$.loadTimeMs')) AS load_time_ms
     FROM events
     WHERE type = 'performance'
     ORDER BY created_at ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$loadTimeByPage = [];
foreach ($loadTimeRows as $row) {
    if ($row['load_time_ms'] === null) {
        continue;
    }
    $page = $row['page'];
    if (!isset($loadTimeByPage[$page])) {
        $loadTimeByPage[$page] = [];
    }
    $loadTimeByPage[$page][] = [
        'x' => strtotime($row['created_at']) * 1000,
        'y' => (float) $row['load_time_ms'],
    ];
}

// Bar chart: error count by page.
$errorRows = db()->query(
    "SELECT
       CASE WHEN page IN ('/', '/index.html', 'index') THEN '/index.html' ELSE page END AS page,
       COUNT(*) AS error_count
     FROM events
     WHERE type = 'error'
     GROUP BY CASE WHEN page IN ('/', '/index.html', 'index') THEN '/index.html' ELSE page END
     ORDER BY error_count DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// Grid: distinct session count by page.
$sessionRows = db()->query(
    "SELECT
       CASE WHEN page IN ('/', '/index.html', 'index') THEN '/index.html' ELSE page END AS page,
       COUNT(DISTINCT session_id) AS session_count
     FROM events
     GROUP BY CASE WHEN page IN ('/', '/index.html', 'index') THEN '/index.html' ELSE page END
     ORDER BY session_count DESC"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
</head>
<body>
    <h1>reporting.eban.site</h1>
    <p>Logged in as <?= htmlspecialchars($user['username']) ?> (<?= $user['is_admin'] ? 'admin' : 'basic' ?>)</p>
    <p>
        <a href="/logout.php">Logout</a>
        <?php if ($user['is_admin']): ?>
            | <a href="/users.php">User Management</a>
        <?php endif; ?>
    </p>

    <h2>Page Load Time Over Time (by page)</h2>
    <canvas id="loadTimeChart" height="100"></canvas>

    <h2>Error Frequency by Page</h2>
    <canvas id="errorChart" height="100"></canvas>

    <h2>Sessions by Page</h2>
    <table border="1" cellpadding="6">
        <tr><th>Page</th><th>Session Count</th></tr>
        <?php foreach ($sessionRows as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['page']) ?></td>
            <td><?= (int) $row['session_count'] ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <script>
        const loadTimeByPage = <?= json_encode($loadTimeByPage) ?>;
        const errorRows = <?= json_encode($errorRows) ?>;

        const palette = ['#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f', '#edc948', '#b07aa1'];
        let colorIndex = 0;
        const colorForPage = {};
        function pageColor(page) {
            if (!colorForPage[page]) {
                colorForPage[page] = palette[colorIndex % palette.length];
                colorIndex++;
            }
            return colorForPage[page];
        }

        const loadTimeDatasets = Object.keys(loadTimeByPage).map(function (page) {
            const color = pageColor(page);
            return {
                label: page,
                data: loadTimeByPage[page],
                borderColor: color,
                backgroundColor: color,
                tension: 0.2,
                pointRadius: 3,
            };
        });

        new Chart(document.getElementById('loadTimeChart'), {
            type: 'line',
            data: { datasets: loadTimeDatasets },
            options: {
                parsing: false,
                scales: {
                    x: {
                        type: 'linear',
                        title: { display: true, text: 'Time' },
                        ticks: {
                            callback: function (value) {
                                return new Date(value).toLocaleString();
                            }
                        }
                    },
                    y: {
                        title: { display: true, text: 'Load time (ms)' },
                        beginAtZero: true
                    }
                }
            }
        });

        new Chart(document.getElementById('errorChart'), {
            type: 'bar',
            data: {
                labels: errorRows.map(function (r) { return r.page; }),
                datasets: [{
                    label: 'Errors',
                    data: errorRows.map(function (r) { return r.error_count; }),
                    backgroundColor: errorRows.map(function (r) { return pageColor(r.page); })
                }]
            },
            options: {
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'Error count' } }
                }
            }
        });
    </script>
</body>
</html>
