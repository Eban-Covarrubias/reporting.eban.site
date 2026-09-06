<?php
require_once __DIR__ . '/lib/auth.php';
requireSection('engagement');
$user = currentUser();
$csrfToken = ensureCsrfToken();

// Bucket each paired page_enter/page_leave visit by how much *active* time
// (idle gaps excluded) it involved, and separately track what fraction of
// each visit was spent idle. This digs deeper than the dashboard's simple
// total-active-time pie chart into whether visits are genuinely engaged.
$engagementStats = db()->query(
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
     ),
     visit_metrics AS (
        SELECT page,
               GREATEST(leave_ts - enter_ts - idle_ms, 0) AS active_ms,
               GREATEST(leave_ts - enter_ts, 0) AS raw_ms,
               LEAST(idle_ms, GREATEST(leave_ts - enter_ts, 0)) AS idle_ms_clamped
        FROM idle_per_visit
     )
     SELECT page,
            SUM(CASE WHEN active_ms < 5000 THEN 1 ELSE 0 END) AS bounce_count,
            SUM(CASE WHEN active_ms >= 5000 AND active_ms < 60000 THEN 1 ELSE 0 END) AS brief_count,
            SUM(CASE WHEN active_ms >= 60000 THEN 1 ELSE 0 END) AS engaged_count,
            ROUND(AVG(CASE WHEN raw_ms > 0 THEN idle_ms_clamped / raw_ms * 100 ELSE 0 END), 1) AS avg_idle_pct,
            COUNT(*) AS total_visits
     FROM visit_metrics
     GROUP BY page
     ORDER BY page"
)->fetchAll(PDO::FETCH_ASSOC);

$isDownload = isset($_GET['download']);
if ($isDownload) {
    header('Content-Disposition: attachment; filename="engagement-report.html"');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Engagement Report</title>
    <link rel="stylesheet" href="/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1"></script>
</head>
<body>
    <?php if (!$isDownload): ?>
    <?php include __DIR__ . '/partials/theme.php'; ?>
    <?php endif; ?>
    <header>
        <h1>Detailed Report: Are Visits Actually Engaged?</h1>
        <?php if (!$isDownload): ?>
        <nav>
            <a href="/index.php">Back to dashboard</a>
            <a href="/reports.php">Saved Reports</a>
            <a href="/engagement-report.php?download=1">Download Raw Data Report</a>
            <a href="/logout.php">Logout</a>
        </nav>
        <?php endif; ?>
    </header>
    <main>
    <?php if (!$isDownload): ?>
    <form method="POST" action="/save-report.php" class="report-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="section" value="engagement">
        <input type="hidden" name="snapshot" value='<?= htmlspecialchars(json_encode($engagementStats), ENT_QUOTES) ?>'>
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
    <h2>Visit Engagement Buckets by Page</h2>
    <p class="muted">
        Bounce = under 5s of active time. Brief = 5&ndash;60s. Engaged = 60s or more.
        Active time excludes idle gaps (2s+ with no activity), same as the dashboard.
    </p>
    <table>
        <tr>
            <th>Page</th>
            <th>Bounce (&lt;5s)</th>
            <th>Brief (5&ndash;60s)</th>
            <th>Engaged (60s+)</th>
            <th>Avg. Idle % of Visit</th>
            <th>Total Visits</th>
        </tr>
        <?php foreach ($engagementStats as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['page']) ?></td>
            <td><?= $row['bounce_count'] ?></td>
            <td><?= $row['brief_count'] ?></td>
            <td><?= $row['engaged_count'] ?></td>
            <td><?= $row['avg_idle_pct'] ?>%</td>
            <td><?= $row['total_visits'] ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </section>

    <section>
    <h2>Bounce / Brief / Engaged Visits by Page</h2>
    <div class="chart-card">
        <canvas id="engagementChart" height="100"></canvas>
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
        const engagementStats = <?= json_encode($engagementStats) ?>;

        new Chart(document.getElementById('engagementChart'), {
            type: 'bar',
            data: {
                labels: engagementStats.map(function (r) { return r.page; }),
                datasets: [
                    {
                        label: 'Bounce (<5s)',
                        data: engagementStats.map(function (r) { return r.bounce_count; }),
                        backgroundColor: '#e15759'
                    },
                    {
                        label: 'Brief (5-60s)',
                        data: engagementStats.map(function (r) { return r.brief_count; }),
                        backgroundColor: '#f28e2b'
                    },
                    {
                        label: 'Engaged (60s+)',
                        data: engagementStats.map(function (r) { return r.engaged_count; }),
                        backgroundColor: '#59a14f'
                    }
                ]
            },
            options: {
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, beginAtZero: true, title: { display: true, text: 'Visit count' } }
                }
            }
        });
    </script>
</body>
</html>
