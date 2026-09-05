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
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
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
        <form method="POST" action="/save-report.php" class="row" style="align-items: center; margin-top: 1rem;">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="section" value="errors">
            <input type="hidden" name="snapshot" value='<?= htmlspecialchars(json_encode($errorDetails), ENT_QUOTES) ?>'>
            <input type="text" name="title" placeholder="Report title" required style="max-width: 260px;">
            <button type="submit">Save as Report</button>
        </form>
        <?php endif; ?>
    </header>
    <main>
    <p>
        <strong>Guiding question:</strong> Which specific errors are actually breaking each page,
        how often does each one fire, and has it been an ongoing problem or a one-off?
    </p>

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
    </div>
    </section>

    <section>
    <h2>What This Tells Us</h2>
    <p>
        Both pages with recorded errors have exactly <strong>one distinct bug each</strong>, not a
        scattering of different issues &mdash; <code>/product-detail.html</code> throws
        <code>ReferenceError: cart is not defined</code> at line 273, and
        <code>/checkout.html</code> throws <code>ReferenceError: paymentProcessor is not defined</code>
        at line 219. Since the dashboard's error rate table already shows both pages at 100%, this
        confirms these aren't intermittent bugs affected by browser/timing differences &mdash; they
        are unconditional script errors that fire on literally every load.
    </p>
    <p>
        The first-seen/last-seen range for both errors spans from the earliest test traffic through
        the most recent, meaning these bugs have been present and unfixed the entire time data has
        been collected, rather than being a regression introduced recently.
    </p>
    <p>
        <strong>Answer to the guiding question:</strong> the site has exactly two known, persistent,
        unconditional bugs &mdash; a missing <code>cart</code> reference on the product detail page and
        a missing <code>paymentProcessor</code> reference on checkout. Both are single-line reference
        errors, which is about as cheap a fix as a bug can be, but because they fire on every load of
        an already-important page (checkout), they're worth prioritizing over anything intermittent.
    </p>
    </section>

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
