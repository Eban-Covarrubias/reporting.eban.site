<?php
require_once __DIR__ . '/lib/auth.php';
requireLogin();
$user = currentUser();
$sections = userSections($user);
$hasPerformance = in_array('performance', $sections, true);
$hasErrors = in_array('errors', $sections, true);
$hasEngagement = in_array('engagement', $sections, true);

// Bar chart: average page load time per page, with standard deviation.
$loadTimeStats = [];
if ($hasPerformance) {
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
}

// Table: error count / rate for each tracked page (including 0s).
$errorRateByPage = [];
if ($hasErrors) {
    // Only these 4 pages are considered for error frequency / error rate.
    $trackedPages = ['/index.html', '/products.html', '/checkout.html', '/product-detail.html'];

    $errorCountRows = db()->query(
        "SELECT
           CASE WHEN page IN ('/', '/index.html', 'index') THEN '/index.html' ELSE page END AS page,
           COUNT(*) AS error_count
         FROM events
         WHERE type = 'error'
         GROUP BY CASE WHEN page IN ('/', '/index.html', 'index') THEN '/index.html' ELSE page END"
    )->fetchAll(PDO::FETCH_ASSOC);
    $errorCountByPage = [];
    foreach ($errorCountRows as $row) {
        $errorCountByPage[$row['page']] = (int) $row['error_count'];
    }

    // Error rate: errors as a percentage of page accesses (one 'static' event fires per page load).
    $accessRows = db()->query(
        "SELECT
           CASE WHEN page IN ('/', '/index.html', 'index') THEN '/index.html' ELSE page END AS page,
           COUNT(*) AS access_count
         FROM events
         WHERE type = 'static'
         GROUP BY CASE WHEN page IN ('/', '/index.html', 'index') THEN '/index.html' ELSE page END"
    )->fetchAll(PDO::FETCH_ASSOC);
    $accessCountByPage = [];
    foreach ($accessRows as $row) {
        $accessCountByPage[$row['page']] = (int) $row['access_count'];
    }

    foreach ($trackedPages as $page) {
        $errors = $errorCountByPage[$page] ?? 0;
        $accesses = $accessCountByPage[$page] ?? 0;

        if ($accesses > 0) {
            $rate = round($errors / $accesses * 100, 1);
        } else {
            $rate = $errors === 0 ? 0.0 : null;
        }
        $errorRateByPage[] = [
            'page' => $page,
            'errors' => $errors,
            'accesses' => $accesses,
            'rate' => $rate,
        ];
    }
}

// Pie chart: active time spent per page, summed across visits.
// - Paired via page_enter/page_leave (matched in order per session+page).
// - Idle gaps (2s+ of no activity, per collector.js) are subtracted so a
//   backgrounded/forgotten tab doesn't count as "engaged" time.
// - Each individual visit is also capped at 10 minutes as a backstop, in case
//   a visit ends during an idle gap that never got logged (e.g. tab closed
//   before any activity resumed to trigger the idle write).
$timeOnPageStats = [];
if ($hasEngagement) {
    $VISIT_CAP_MS = 10 * 60 * 1000;
    $timeOnPageStmt = db()->prepare(
        "WITH ordered_events AS (
            SELECT
                session_id,
                CASE WHEN page IN ('/', '/index.html', 'index') THEN '/index.html' ELSE page END AS page,
                type, client_timestamp,
                ROW_NUMBER() OVER (PARTITION BY session_id, page, type ORDER BY client_timestamp) AS rn
            FROM events
            WHERE type IN ('page_enter', 'page_leave')
              AND page IN ('/', '/index.html', 'index', '/products.html', '/checkout.html', '/product-detail.html')
         ),
         paired AS (
            SELECT e.session_id, e.page, e.client_timestamp AS enter_ts, l.client_timestamp AS leave_ts
            FROM ordered_events e
            JOIN ordered_events l ON e.session_id = l.session_id AND e.page = l.page AND e.rn = l.rn
                AND e.type = 'page_enter' AND l.type = 'page_leave'
            WHERE l.client_timestamp > e.client_timestamp
         ),
         idle_per_visit AS (
            SELECT p.session_id, p.page, p.enter_ts, p.leave_ts,
                   COALESCE(SUM(CAST(JSON_UNQUOTE(JSON_EXTRACT(ev.data, '$.durationMs')) AS UNSIGNED)), 0) AS idle_ms
            FROM paired p
            LEFT JOIN events ev
                ON ev.session_id = p.session_id
               AND CASE WHEN ev.page IN ('/', '/index.html', 'index') THEN '/index.html' ELSE ev.page END = p.page
               AND ev.type = 'idle'
               AND ev.client_timestamp BETWEEN p.enter_ts AND p.leave_ts
            GROUP BY p.session_id, p.page, p.enter_ts, p.leave_ts
         )
         SELECT page,
                SUM(LEAST(GREATEST(leave_ts - enter_ts - idle_ms, 0), :cap)) AS active_ms
         FROM idle_per_visit
         GROUP BY page
         ORDER BY active_ms DESC"
    );
    $timeOnPageStmt->bindValue(':cap', $VISIT_CAP_MS, PDO::PARAM_INT);
    $timeOnPageStmt->execute();
    $timeOnPageStats = $timeOnPageStmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
    <link rel="stylesheet" href="/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-chart-error-bars@4.4.5"></script>
</head>
<body>
    <?php include __DIR__ . '/partials/theme.php'; ?>
    <header>
        <h1>reporting.eban.site</h1>
        <p>Logged in as <?= htmlspecialchars($user['username']) ?> (<?= htmlspecialchars(ucwords(str_replace('_', ' ', $user['role']))) ?>)</p>
        <nav>
            <a href="/reports.php">Saved Reports</a>
            <a href="/logout.php">Logout</a>
            <?php if ($user['role'] === 'super_admin'): ?>
                <a href="/users.php">User Management</a>
            <?php endif; ?>
        </nav>
    </header>
    <main>
        <?php if (!$hasPerformance && !$hasErrors && !$hasEngagement): ?>
        <section>
            <p class="muted">
                You don't have access to any live report sections yet. Ask a super admin to
                assign you a section, or visit <a href="/reports.php">Saved Reports</a> to see
                reports that have already been published.
            </p>
        </section>
        <?php endif; ?>

        <?php if ($hasPerformance): ?>
        <section>
            <div class="section-header">
                <h2>Average Page Load Time by Page (&plusmn; 1 std dev)</h2>
                <a href="/load-time-report.php">Generate Report &rarr;</a>
            </div>
            <div class="chart-card">
                <canvas id="loadTimeChart" height="100"></canvas>
                <noscript><p class="muted">This chart requires JavaScript. See the <a href="/load-time-report.php">full report</a> for a data table version.</p></noscript>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($hasErrors): ?>
        <section>
            <div class="section-header">
                <h2>Error Rate by Page</h2>
                <a href="/error-report.php">Generate Report &rarr;</a>
            </div>
            <table>
                <tr><th>Page</th><th>Errors</th><th>Accesses</th><th>Error Rate</th></tr>
                <?php foreach ($errorRateByPage as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['page']) ?></td>
                    <td><?= $row['errors'] ?></td>
                    <td><?= $row['accesses'] ?></td>
                    <td><?= $row['rate'] === null ? 'n/a' : $row['rate'] . '%' ?></td>
                </tr>
                <?php endforeach; ?>
            </table>
        </section>
        <?php endif; ?>

        <?php if ($hasEngagement): ?>
        <section>
            <div class="section-header">
                <h2>Time Spent on Page (active time, idle gaps excluded)</h2>
                <a href="/engagement-report.php">Generate Report &rarr;</a>
            </div>
            <div class="chart-card small">
                <canvas id="timeOnPageChart"></canvas>
                <noscript><p class="muted">This chart requires JavaScript. See the <a href="/engagement-report.php">full report</a> for a data table version.</p></noscript>
            </div>
        </section>
        <?php endif; ?>
    </main>

    <script>
        const loadTimeStats = <?= json_encode($loadTimeStats) ?>;
        const timeOnPageStats = <?= json_encode($timeOnPageStats) ?>;

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

        function lightenColor(hex, factor) {
            const c = hex.replace('#', '');
            const r = parseInt(c.substring(0, 2), 16);
            const g = parseInt(c.substring(2, 4), 16);
            const b = parseInt(c.substring(4, 6), 16);
            const lr = Math.round(r + (255 - r) * factor);
            const lg = Math.round(g + (255 - g) * factor);
            const lb = Math.round(b + (255 - b) * factor);
            return 'rgb(' + lr + ', ' + lg + ', ' + lb + ')';
        }

        if (document.getElementById('loadTimeChart')) {
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
                    backgroundColor: loadTimeStats.map(function (r) { return pageColor(r.page); }),
                    errorBarColor: loadTimeStats.map(function (r) { return lightenColor(pageColor(r.page), 0.5); }),
                    errorBarWhiskerColor: loadTimeStats.map(function (r) { return lightenColor(pageColor(r.page), 0.5); })
                }]
            },
            options: {
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Load time (ms)' }
                    }
                }
            }
        });
        }

        if (document.getElementById('timeOnPageChart')) {
        new Chart(document.getElementById('timeOnPageChart'), {
            type: 'pie',
            data: {
                labels: timeOnPageStats.map(function (r) { return r.page; }),
                datasets: [{
                    label: 'Active time (ms)',
                    data: timeOnPageStats.map(function (r) { return Number(r.active_ms); }),
                    backgroundColor: timeOnPageStats.map(function (r) { return pageColor(r.page); })
                }]
            },
            options: {
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                const total = ctx.dataset.data.reduce(function (sum, v) { return sum + v; }, 0);
                                const pct = total > 0 ? (ctx.parsed / total * 100) : 0;
                                return pct.toFixed(1) + '%';
                            }
                        }
                    }
                }
            }
        });
        }
    </script>
</body>
</html>
