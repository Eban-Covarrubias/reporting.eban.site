<?php
require_once __DIR__ . '/lib/auth.php';
requireSection('performance');
$user = currentUser();
$csrfToken = ensureCsrfToken();

// Break the total load time down into the actual Navigation Timing phases,
// averaged per page, instead of just the single loadTimeMs total shown on
// the main dashboard.
$phaseStats = db()->query(
    "WITH phases AS (
        SELECT
            CASE WHEN page IN ('/', '/index.html', 'index') THEN '/index.html' ELSE page END AS page,
            GREATEST(CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.timing.domainLookupEnd')) AS DECIMAL(10,2)) - CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.timing.domainLookupStart')) AS DECIMAL(10,2)), 0) AS dns_ms,
            GREATEST(CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.timing.connectEnd')) AS DECIMAL(10,2)) - CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.timing.connectStart')) AS DECIMAL(10,2)), 0) AS tcp_ms,
            GREATEST(CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.timing.responseStart')) AS DECIMAL(10,2)) - CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.timing.requestStart')) AS DECIMAL(10,2)), 0) AS ttfb_ms,
            GREATEST(CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.timing.responseEnd')) AS DECIMAL(10,2)) - CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.timing.responseStart')) AS DECIMAL(10,2)), 0) AS download_ms,
            GREATEST(CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.timing.domComplete')) AS DECIMAL(10,2)) - CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.timing.responseEnd')) AS DECIMAL(10,2)), 0) AS dom_processing_ms,
            GREATEST(CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.timing.loadEventEnd')) AS DECIMAL(10,2)) - CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.timing.loadEventStart')) AS DECIMAL(10,2)), 0) AS load_event_ms
        FROM events
        WHERE type = 'performance'
          AND page IN ('/', '/index.html', 'index', '/products.html', '/checkout.html', '/product-detail.html')
     )
     SELECT page,
            ROUND(AVG(dns_ms), 2) AS avg_dns_ms,
            ROUND(AVG(tcp_ms), 2) AS avg_tcp_ms,
            ROUND(AVG(ttfb_ms), 2) AS avg_ttfb_ms,
            ROUND(AVG(download_ms), 2) AS avg_download_ms,
            ROUND(AVG(dom_processing_ms), 2) AS avg_dom_processing_ms,
            ROUND(AVG(load_event_ms), 2) AS avg_load_event_ms,
            COUNT(*) AS n
     FROM phases
     GROUP BY page
     ORDER BY page"
)->fetchAll(PDO::FETCH_ASSOC);

$isDownload = isset($_GET['download']);
if ($isDownload) {
    header('Content-Disposition: attachment; filename="load-time-report.html"');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Load Time Report</title>
    <link rel="stylesheet" href="/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1"></script>
</head>
<body>
    <?php if (!$isDownload): ?>
    <?php include __DIR__ . '/partials/theme.php'; ?>
    <?php endif; ?>
    <header>
        <h1>Detailed Report: Where Does Page Load Time Go?</h1>
        <?php if (!$isDownload): ?>
        <nav>
            <a href="/index.php">Back to dashboard</a>
            <a href="/reports.php">Saved Reports</a>
            <a href="/load-time-report.php?download=1">Download Report</a>
            <a href="/logout.php">Logout</a>
        </nav>
        <?php endif; ?>
    </header>
    <main>
    <?php if (!$isDownload): ?>
    <form method="POST" action="/save-report.php" class="report-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="section" value="performance">
        <input type="hidden" name="snapshot" value='<?= htmlspecialchars(json_encode($phaseStats), ENT_QUOTES) ?>'>
        <div class="report-title-bar">
            <input type="text" name="title" placeholder="Report Title" aria-label="Report Title" required>
            <button type="submit">Save as Report</button>
        </div>
        <div class="report-field">
            <label>
                Guiding Question <span class="muted">(optional)</span>
                <textarea name="guiding_question" rows="2"></textarea>
            </label>
        </div>
    <?php endif; ?>

    <section>
    <h2>Average Time per Load Phase, by Page (ms)</h2>
    <table>
        <tr>
            <th>Page</th>
            <th>DNS Lookup</th>
            <th>TCP/TLS Connect</th>
            <th>TTFB (Server Wait)</th>
            <th>Response Download</th>
            <th>DOM Processing</th>
            <th>Load Event</th>
            <th>Samples</th>
        </tr>
        <?php foreach ($phaseStats as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['page']) ?></td>
            <td><?= $row['avg_dns_ms'] ?></td>
            <td><?= $row['avg_tcp_ms'] ?></td>
            <td><?= $row['avg_ttfb_ms'] ?></td>
            <td><?= $row['avg_download_ms'] ?></td>
            <td><?= $row['avg_dom_processing_ms'] ?></td>
            <td><?= $row['avg_load_event_ms'] ?></td>
            <td><?= $row['n'] ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </section>

    <section>
    <h2>Load Time Composition by Page</h2>
    <div class="chart-card">
        <canvas id="phaseChart" height="100"></canvas>
        <noscript><p class="muted">This chart requires JavaScript; see the table above for the same data.</p></noscript>
    </div>
    </section>

    <?php if (!$isDownload): ?>
        <div class="report-field">
            <label>
                What This Tells Us <span class="muted">(optional)</span>
                <textarea name="what_this_tells_us" rows="4"></textarea>
            </label>
        </div>
        <div class="report-field">
            <label>
                Additional Notes <span class="muted">(optional)</span>
                <textarea name="additional_notes" rows="3"></textarea>
            </label>
        </div>
    </form>
    <?php endif; ?>
    </main>

    <script>
        const phaseStats = <?= json_encode($phaseStats) ?>;
        const labels = phaseStats.map(function (r) { return r.page; });

        const phaseSeries = [
            { key: 'avg_dns_ms', label: 'DNS Lookup', color: '#4e79a7' },
            { key: 'avg_tcp_ms', label: 'TCP/TLS Connect', color: '#f28e2b' },
            { key: 'avg_ttfb_ms', label: 'TTFB (Server Wait)', color: '#e15759' },
            { key: 'avg_download_ms', label: 'Response Download', color: '#76b7b2' },
            { key: 'avg_dom_processing_ms', label: 'DOM Processing', color: '#59a14f' },
            { key: 'avg_load_event_ms', label: 'Load Event', color: '#edc948' }
        ];

        new Chart(document.getElementById('phaseChart'), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: phaseSeries.map(function (p) {
                    return {
                        label: p.label,
                        data: phaseStats.map(function (r) { return Number(r[p.key]); }),
                        backgroundColor: p.color
                    };
                })
            },
            options: {
                scales: {
                    x: { stacked: true },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        title: { display: true, text: 'Time (ms)' }
                    }
                }
            }
        });
    </script>
</body>
</html>
