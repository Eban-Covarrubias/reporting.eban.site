<?php
require_once __DIR__ . '/lib/auth.php';
requireLogin();
$user = currentUser();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare(
    "SELECT r.*, u.username AS created_by_username
     FROM reports r JOIN users u ON u.id = r.created_by
     WHERE r.id = ?"
);
$stmt->execute([$id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

// Viewers can look at any saved report; super_admin/analyst are limited to
// their assigned sections, same as the live report pages.
$canView = $user['role'] === 'viewer' || in_array($report['section'], userSections($user), true);
if (!$canView) {
    http_response_code(403);
    require __DIR__ . '/403.php';
    exit;
}
$canEdit = $user['role'] !== 'viewer' && in_array($report['section'], userSections($user), true);

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) {
        http_response_code(403);
        require __DIR__ . '/403.php';
        exit;
    }
    if (!checkCsrf()) {
        $error = 'Invalid form submission, please try again.';
    } else {
        $comments = trim($_POST['analyst_comments'] ?? '');
        $stmt = db()->prepare('UPDATE reports SET analyst_comments = ? WHERE id = ?');
        $stmt->execute([$comments !== '' ? $comments : null, $id]);
        $report['analyst_comments'] = $comments !== '' ? $comments : null;
    }
}

$csrfToken = ensureCsrfToken();
$rows = json_decode($report['snapshot'], true);
$isPdf = isset($_GET['pdf']);
$showEditForm = $canEdit && !$isPdf;

$sectionNames = [];
foreach (db()->query('SELECT slug, name FROM sections')->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $sectionNames[$s['slug']] = $s['name'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($report['title']) ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
</head>
<body>
    <header>
        <h1><?= htmlspecialchars($report['title']) ?></h1>
        <p class="muted">
            Section: <?= htmlspecialchars($sectionNames[$report['section']] ?? $report['section']) ?>
            &middot; Saved by <?= htmlspecialchars($report['created_by_username']) ?>
            on <?= htmlspecialchars($report['created_at']) ?>
        </p>
        <?php if (!$isPdf): ?>
        <nav>
            <a href="/reports.php">Back to Saved Reports</a>
            <?php if ($user['role'] !== 'viewer'): ?>
                <a href="/index.php">Dashboard</a>
            <?php endif; ?>
            <a href="/export-report.php?id=<?= (int) $id ?>">Download PDF</a>
            <a href="/logout.php">Logout</a>
        </nav>
        <?php endif; ?>
    </header>
    <main>
        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <?php if ($report['section'] === 'performance'): ?>
        <section>
        <h2>Average Time per Load Phase, by Page (ms)</h2>
        <table>
            <tr>
                <th>Page</th><th>DNS Lookup</th><th>TCP/TLS Connect</th><th>TTFB (Server Wait)</th>
                <th>Response Download</th><th>DOM Processing</th><th>Load Event</th><th>Samples</th>
            </tr>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['page']) ?></td>
                <td><?= htmlspecialchars($row['avg_dns_ms']) ?></td>
                <td><?= htmlspecialchars($row['avg_tcp_ms']) ?></td>
                <td><?= htmlspecialchars($row['avg_ttfb_ms']) ?></td>
                <td><?= htmlspecialchars($row['avg_download_ms']) ?></td>
                <td><?= htmlspecialchars($row['avg_dom_processing_ms']) ?></td>
                <td><?= htmlspecialchars($row['avg_load_event_ms']) ?></td>
                <td><?= htmlspecialchars($row['n']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </section>
        <section>
        <h2>Load Time Composition by Page</h2>
        <div class="chart-card"><canvas id="reportChart" height="100"></canvas></div>
        </section>

        <?php elseif ($report['section'] === 'errors'): ?>
        <section>
        <h2>Distinct Errors by Page</h2>
        <table>
            <tr><th>Page</th><th>Error Message</th><th>Line:Col</th><th>Occurrences</th><th>First Seen</th><th>Last Seen</th></tr>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['page']) ?></td>
                <td><?= htmlspecialchars($row['message']) ?></td>
                <td><?= htmlspecialchars($row['lineno']) ?>:<?= htmlspecialchars($row['colno']) ?></td>
                <td><?= htmlspecialchars($row['occurrences']) ?></td>
                <td><?= htmlspecialchars($row['first_seen']) ?></td>
                <td><?= htmlspecialchars($row['last_seen']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </section>
        <section>
        <h2>Occurrences by Error</h2>
        <div class="chart-card"><canvas id="reportChart" height="100"></canvas></div>
        </section>

        <?php elseif ($report['section'] === 'engagement'): ?>
        <section>
        <h2>Visit Engagement Buckets by Page</h2>
        <table>
            <tr><th>Page</th><th>Bounce (&lt;5s)</th><th>Brief (5&ndash;60s)</th><th>Engaged (60s+)</th><th>Avg. Idle % of Visit</th><th>Total Visits</th></tr>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['page']) ?></td>
                <td><?= htmlspecialchars($row['bounce_count']) ?></td>
                <td><?= htmlspecialchars($row['brief_count']) ?></td>
                <td><?= htmlspecialchars($row['engaged_count']) ?></td>
                <td><?= htmlspecialchars($row['avg_idle_pct']) ?>%</td>
                <td><?= htmlspecialchars($row['total_visits']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        </section>
        <section>
        <h2>Bounce / Brief / Engaged Visits by Page</h2>
        <div class="chart-card"><canvas id="reportChart" height="100"></canvas></div>
        </section>
        <?php endif; ?>

        <section>
        <h2>Analyst Comments</h2>
        <?php if ($showEditForm): ?>
        <form method="POST" action="/report.php?id=<?= (int) $id ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <label>
                <textarea name="analyst_comments" rows="6" style="width: 100%; background: var(--bg); color: var(--text); border: 1px solid var(--border); border-radius: 6px; padding: 0.6rem;"><?= htmlspecialchars($report['analyst_comments'] ?? '') ?></textarea>
            </label>
            <button type="submit">Save Comments</button>
        </form>
        <?php else: ?>
            <?php if ($report['analyst_comments']): ?>
                <p><?= nl2br(htmlspecialchars($report['analyst_comments'])) ?></p>
            <?php else: ?>
                <p class="muted">No comments yet.</p>
            <?php endif; ?>
        <?php endif; ?>
        </section>
    </main>

    <script>
        const rows = <?= json_encode($rows) ?>;
        const section = <?= json_encode($report['section']) ?>;

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

        const canvas = document.getElementById('reportChart');
        if (canvas && section === 'performance') {
            const labels = rows.map(function (r) { return r.page; });
            const phaseSeries = [
                { key: 'avg_dns_ms', label: 'DNS Lookup', color: '#4e79a7' },
                { key: 'avg_tcp_ms', label: 'TCP/TLS Connect', color: '#f28e2b' },
                { key: 'avg_ttfb_ms', label: 'TTFB (Server Wait)', color: '#e15759' },
                { key: 'avg_download_ms', label: 'Response Download', color: '#76b7b2' },
                { key: 'avg_dom_processing_ms', label: 'DOM Processing', color: '#59a14f' },
                { key: 'avg_load_event_ms', label: 'Load Event', color: '#edc948' }
            ];
            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: phaseSeries.map(function (p) {
                        return { label: p.label, data: rows.map(function (r) { return Number(r[p.key]); }), backgroundColor: p.color };
                    })
                },
                options: {
                    animation: false,
                    scales: {
                        x: { stacked: true },
                        y: { stacked: true, beginAtZero: true, title: { display: true, text: 'Time (ms)' } }
                    }
                }
            });
        } else if (canvas && section === 'errors') {
            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: rows.map(function (r) { return r.page + ': ' + r.message; }),
                    datasets: [{
                        label: 'Occurrences',
                        data: rows.map(function (r) { return r.occurrences; }),
                        backgroundColor: rows.map(function (r) { return pageColor(r.page); })
                    }]
                },
                options: {
                    animation: false,
                    indexAxis: 'y',
                    plugins: { legend: { display: false } },
                    scales: { x: { beginAtZero: true, title: { display: true, text: 'Occurrences' } } }
                }
            });
        } else if (canvas && section === 'engagement') {
            new Chart(canvas, {
                type: 'bar',
                data: {
                    labels: rows.map(function (r) { return r.page; }),
                    datasets: [
                        { label: 'Bounce (<5s)', data: rows.map(function (r) { return r.bounce_count; }), backgroundColor: '#e15759' },
                        { label: 'Brief (5-60s)', data: rows.map(function (r) { return r.brief_count; }), backgroundColor: '#f28e2b' },
                        { label: 'Engaged (60s+)', data: rows.map(function (r) { return r.engaged_count; }), backgroundColor: '#59a14f' }
                    ]
                },
                options: {
                    animation: false,
                    scales: {
                        x: { stacked: true },
                        y: { stacked: true, beginAtZero: true, title: { display: true, text: 'Visit count' } }
                    }
                }
            });
        }
    </script>
</body>
</html>
