<?php
require_once __DIR__ . '/lib/auth.php';
requireLogin();
$user = currentUser();

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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
</head>
<body>
    <header>
        <h1>Detailed Report: Are Visits Actually Engaged?</h1>
        <?php if (!$isDownload): ?>
        <nav>
            <a href="/index.php">Back to dashboard</a>
            <a href="/engagement-report.php?download=1">Download Report</a>
            <a href="/logout.php">Logout</a>
        </nav>
        <?php endif; ?>
    </header>
    <main>
    <p>
        <strong>Guiding question:</strong> Are visits to each page genuinely engaged, or are users
        mostly bouncing quickly or sitting idle? The dashboard's time-on-page pie chart shows total
        active time per page, but doesn't say whether that time comes from many short visits or a
        few long ones, or how much of a typical visit is spent idle.
    </p>

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
    </div>
    </section>

    <section>
    <h2>What This Tells Us</h2>
    <p>
        <code>/products.html</code> has the most visits by far, but every single one is a bounce
        &mdash; nobody spends 5+ active seconds there. That's a very different story than what the
        dashboard's time-on-page pie chart alone would suggest, since a page can accumulate real
        total time purely from visit volume rather than genuine per-visit engagement.
        <code>/product-detail.html</code> and <code>/checkout.html</code> have a healthier mix of
        brief and engaged visits relative to their volume, meaning the time they show on the
        dashboard is more likely to reflect real attention, not just many quick hits.
    </p>
    <p>
        Idle percentage is fairly low and consistent across pages (roughly 8&ndash;26% of a visit),
        so idle time isn't the main driver of engagement differences here &mdash; the split between
        bounce and engaged visits is.
    </p>
    <p>
        <strong>Answer to the guiding question:</strong> engagement varies a lot by page even though
        total time-on-page (the dashboard metric) can look comparable. <code>/products.html</code>
        gets a lot of traffic but essentially no real engagement, while the pages further into the
        purchase flow hold attention better on a per-visit basis &mdash; a distinction the dashboard's
        pie chart alone can't show.
    </p>
    </section>

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
