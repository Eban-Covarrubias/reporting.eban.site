<?php
require_once __DIR__ . '/lib/auth.php';
requireLogin();
$user = currentUser();

// Bar chart: average page load time per page, with standard deviation.
$loadTimeStats = db()->query(
    "SELECT
       CASE WHEN page IN ('/', '/index.html', 'index') THEN '/index.html' ELSE page END AS page,
       AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.loadTimeMs')) AS DECIMAL(10,2))) AS avg_load_time,
       STDDEV(CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.loadTimeMs')) AS DECIMAL(10,2))) AS stddev_load_time,
       COUNT(*) AS n
     FROM events
     WHERE type = 'performance'
     GROUP BY CASE WHEN page IN ('/', '/index.html', 'index') THEN '/index.html' ELSE page END
     ORDER BY avg_load_time DESC"
)->fetchAll(PDO::FETCH_ASSOC);

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
    <script src="https://cdn.jsdelivr.net/npm/chartjs-chart-error-bars@4"></script>
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

    <h2>Average Page Load Time by Page (&plusmn; 1 std dev)</h2>
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
        const loadTimeStats = <?= json_encode($loadTimeStats) ?>;
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

        new Chart(document.getElementById('loadTimeChart'), {
            type: 'barWithErrorBars',
            data: {
                labels: loadTimeStats.map(function (r) { return r.page; }),
                datasets: [{
                    label: 'Avg load time (ms)',
                    data: loadTimeStats.map(function (r) {
                        const avg = Number(r.avg_load_time);
                        const stddev = Number(r.stddev_load_time) || 0;
                        return {
                            y: avg,
                            yMin: Math.max(0, avg - stddev),
                            yMax: avg + stddev
                        };
                    }),
                    backgroundColor: loadTimeStats.map(function (r) { return pageColor(r.page); })
                }]
            },
            options: {
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Load time (ms)' }
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
