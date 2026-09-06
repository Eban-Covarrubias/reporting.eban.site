<?php
require_once __DIR__ . '/lib/auth.php';
requireSection('errors');
$user = currentUser();
$csrfToken = ensureCsrfToken();

// Break errors down by the exact message/line, not just page-level counts,
// so we can see whether a page has one persistent bug or many different ones.
$errorDetails = db()->query(
    "SELECT
       CASE WHEN page IN ('/', '/index.html', 'index') THEN '/index.html' ELSE page END AS page,
       JSON_UNQUOTE(JSON_EXTRACT(data, '$.message')) AS message,
       JSON_UNQUOTE(JSON_EXTRACT(data, '$.lineno')) AS lineno,
       JSON_UNQUOTE(JSON_EXTRACT(data, '$.colno')) AS colno,
       COUNT(*) AS occurrences,
       MIN(created_at) AS first_seen,
       MAX(created_at) AS last_seen
     FROM events
     WHERE type = 'error'
       AND page IN ('/', '/index.html', 'index', '/products.html', '/checkout.html', '/product-detail.html')
     GROUP BY page, message, lineno, colno
     ORDER BY occurrences DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$isDownload = isset($_GET['download']);
if ($isDownload) {
    header('Content-Disposition: attachment; filename="error-report.html"');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Error Report</title>
    <link rel="stylesheet" href="/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1"></script>
</head>
<body>
    <?php if (!$isDownload): ?>
    <?php include __DIR__ . '/partials/theme.php'; ?>
    <?php endif; ?>
    <header>
        <h1>Detailed Report: Which Bugs Are Breaking Which Pages?</h1>
        <?php if (!$isDownload): ?>
        <nav>
            <a href="/index.php">Back to dashboard</a>
            <a href="/reports.php">Saved Reports</a>
            <a href="/error-report.php?download=1">Download Report</a>
            <a href="/logout.php">Logout</a>
        </nav>
        <?php endif; ?>
    </header>
    <main>
    <?php if (!$isDownload): ?>
    <form method="POST" action="/save-report.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="section" value="errors">
        <input type="hidden" name="snapshot" value='<?= htmlspecialchars(json_encode($errorDetails), ENT_QUOTES) ?>'>
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
    <h2>Distinct Errors by Page</h2>
    <table>
        <tr>
            <th>Page</th>
            <th>Error Message</th>
            <th>Line:Col</th>
            <th>Occurrences</th>
            <th>First Seen</th>
            <th>Last Seen</th>
        </tr>
        <?php foreach ($errorDetails as $row): ?>
        <tr>
            <td><?= htmlspecialchars($row['page']) ?></td>
            <td><?= htmlspecialchars($row['message']) ?></td>
            <td><?= htmlspecialchars($row['lineno']) ?>:<?= htmlspecialchars($row['colno']) ?></td>
            <td><?= $row['occurrences'] ?></td>
            <td><?= htmlspecialchars($row['first_seen']) ?></td>
            <td><?= htmlspecialchars($row['last_seen']) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    </section>

    <section>
    <h2>Occurrences by Error</h2>
    <div class="chart-card">
        <canvas id="errorDetailChart" height="100"></canvas>
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
        const errorDetails = <?= json_encode($errorDetails) ?>;

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

        new Chart(document.getElementById('errorDetailChart'), {
            type: 'bar',
            data: {
                labels: errorDetails.map(function (r) { return r.page + ': ' + r.message; }),
                datasets: [{
                    label: 'Occurrences',
                    data: errorDetails.map(function (r) { return r.occurrences; }),
                    backgroundColor: errorDetails.map(function (r) { return pageColor(r.page); })
                }]
            },
            options: {
                indexAxis: 'y',
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { beginAtZero: true, title: { display: true, text: 'Occurrences' } }
                }
            }
        });
    </script>
</body>
</html>
