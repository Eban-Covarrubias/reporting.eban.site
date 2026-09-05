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
        $guidingQuestion = trim($_POST['guiding_question'] ?? '');
        $whatThisTellsUs = trim($_POST['what_this_tells_us'] ?? '');
        $additionalNotes = trim($_POST['additional_notes'] ?? '');
        $stmt = db()->prepare(
            'UPDATE reports SET guiding_question = ?, what_this_tells_us = ?, additional_notes = ? WHERE id = ?'
        );
        $stmt->execute([
            $guidingQuestion !== '' ? $guidingQuestion : null,
            $whatThisTellsUs !== '' ? $whatThisTellsUs : null,
            $additionalNotes !== '' ? $additionalNotes : null,
            $id,
        ]);
        $report['guiding_question'] = $guidingQuestion !== '' ? $guidingQuestion : null;
        $report['what_this_tells_us'] = $whatThisTellsUs !== '' ? $whatThisTellsUs : null;
        $report['additional_notes'] = $additionalNotes !== '' ? $additionalNotes : null;
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

// wkhtmltopdf's bundled WebKit JS engine is old enough that it can't parse
// Chart.js 4's bundle (fails with a `let` redeclaration syntax error), so the
// interactive canvas charts render blank in the PDF. Rather than pull in an
// older charting library just for the export path, the ?pdf=1 view renders
// this dependency-free proportional-width bar chart instead - no JS needed
// at all, so it can't hit any JS-engine compatibility issue.
function renderBarRows(array $rows, string $labelKey, array $series): string {
    $maxTotal = 0.0;
    foreach ($rows as $row) {
        $total = 0.0;
        foreach ($series as $s) {
            $total += (float) $row[$s['key']];
        }
        $maxTotal = max($maxTotal, $total);
    }
    if ($maxTotal <= 0) {
        $maxTotal = 1;
    }

    $html = '<div style="display:flex;flex-wrap:wrap;gap:1rem;margin-bottom:1rem;font-size:0.85rem;">';
    foreach ($series as $s) {
        $html .= '<span><span style="display:inline-block;width:10px;height:10px;background:'
            . htmlspecialchars($s['color']) . ';border-radius:2px;margin-right:0.35rem;"></span>'
            . htmlspecialchars($s['label']) . '</span>';
    }
    $html .= '</div>';

    foreach ($rows as $row) {
        $html .= '<div style="margin-bottom:0.85rem;">';
        $html .= '<div style="font-size:0.85rem;color:var(--muted);margin-bottom:0.3rem;">'
            . htmlspecialchars($row[$labelKey]) . '</div>';
        $html .= '<div style="display:flex;height:26px;border-radius:4px;overflow:hidden;background:var(--bg);border:1px solid var(--border);">';
        foreach ($series as $s) {
            $val = (float) $row[$s['key']];
            $pct = $val / $maxTotal * 100;
            if ($pct <= 0) {
                continue;
            }
            $html .= '<div style="width:' . round($pct, 2) . '%;background:' . htmlspecialchars($s['color'])
                . ';" title="' . htmlspecialchars($s['label'] . ': ' . $val) . '"></div>';
        }
        $html .= '</div></div>';
    }
    return $html;
}
?>
<!DOCTYPE html>
<html lang="en"<?= $isPdf ? ' data-theme="light"' : '' ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($report['title']) ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <?php if (!$isPdf): ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1"></script>
    <?php endif; ?>
</head>
<body>
    <?php if (!$isPdf): ?>
    <?php include __DIR__ . '/partials/theme.php'; ?>
    <?php endif; ?>
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
        <div class="chart-card">
            <?php if ($isPdf): ?>
                <?= renderBarRows($rows, 'page', [
                    ['key' => 'avg_dns_ms', 'label' => 'DNS Lookup', 'color' => '#4e79a7'],
                    ['key' => 'avg_tcp_ms', 'label' => 'TCP/TLS Connect', 'color' => '#f28e2b'],
                    ['key' => 'avg_ttfb_ms', 'label' => 'TTFB (Server Wait)', 'color' => '#e15759'],
                    ['key' => 'avg_download_ms', 'label' => 'Response Download', 'color' => '#76b7b2'],
                    ['key' => 'avg_dom_processing_ms', 'label' => 'DOM Processing', 'color' => '#59a14f'],
                    ['key' => 'avg_load_event_ms', 'label' => 'Load Event', 'color' => '#edc948'],
                ]) ?>
            <?php else: ?>
                <canvas id="reportChart" height="100"></canvas>
                <noscript><p class="muted">This chart requires JavaScript; see the table above for the same data.</p></noscript>
            <?php endif; ?>
        </div>
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
        <div class="chart-card">
            <?php if ($isPdf): ?>
                <?php
                $errorBarRows = array_map(function ($row) {
                    return ['label' => $row['page'] . ': ' . $row['message'], 'occurrences' => $row['occurrences']];
                }, $rows);
                ?>
                <?= renderBarRows($errorBarRows, 'label', [
                    ['key' => 'occurrences', 'label' => 'Occurrences', 'color' => '#4e79a7'],
                ]) ?>
            <?php else: ?>
                <canvas id="reportChart" height="100"></canvas>
                <noscript><p class="muted">This chart requires JavaScript; see the table above for the same data.</p></noscript>
            <?php endif; ?>
        </div>
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
        <div class="chart-card">
            <?php if ($isPdf): ?>
                <?= renderBarRows($rows, 'page', [
                    ['key' => 'bounce_count', 'label' => 'Bounce (<5s)', 'color' => '#e15759'],
                    ['key' => 'brief_count', 'label' => 'Brief (5-60s)', 'color' => '#f28e2b'],
                    ['key' => 'engaged_count', 'label' => 'Engaged (60s+)', 'color' => '#59a14f'],
                ]) ?>
            <?php else: ?>
                <canvas id="reportChart" height="100"></canvas>
                <noscript><p class="muted">This chart requires JavaScript; see the table above for the same data.</p></noscript>
            <?php endif; ?>
        </div>
        </section>
        <?php endif; ?>

        <?php if ($showEditForm): ?>
        <section>
        <h2>Write-Up</h2>
        <form method="POST" action="/report.php?id=<?= (int) $id ?>" style="max-width: 640px;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <label>
                Guiding Question <span class="muted">(optional)</span>
                <textarea name="guiding_question" rows="2"><?= htmlspecialchars($report['guiding_question'] ?? '') ?></textarea>
            </label>
            <label>
                What This Tells Us <span class="muted">(optional)</span>
                <textarea name="what_this_tells_us" rows="4"><?= htmlspecialchars($report['what_this_tells_us'] ?? '') ?></textarea>
            </label>
            <label>
                Additional Notes <span class="muted">(optional)</span>
                <textarea name="additional_notes" rows="3"><?= htmlspecialchars($report['additional_notes'] ?? '') ?></textarea>
            </label>
            <button type="submit">Save</button>
        </form>
        </section>
        <?php else: ?>
            <?php if ($report['guiding_question']): ?>
            <section>
            <h2>Guiding Question</h2>
            <p><?= nl2br(htmlspecialchars($report['guiding_question'])) ?></p>
            </section>
            <?php endif; ?>
            <?php if ($report['what_this_tells_us']): ?>
            <section>
            <h2>What This Tells Us</h2>
            <p><?= nl2br(htmlspecialchars($report['what_this_tells_us'])) ?></p>
            </section>
            <?php endif; ?>
            <?php if ($report['additional_notes']): ?>
            <section>
            <h2>Additional Notes</h2>
            <p><?= nl2br(htmlspecialchars($report['additional_notes'])) ?></p>
            </section>
            <?php endif; ?>
            <?php if (!$report['guiding_question'] && !$report['what_this_tells_us'] && !$report['additional_notes']): ?>
            <section>
            <p class="muted">No write-up added for this report yet.</p>
            </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <?php if (!$isPdf): ?>
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
    <?php endif; ?>
</body>
</html>
