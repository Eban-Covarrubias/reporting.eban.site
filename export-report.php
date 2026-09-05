<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/vendor/autoload.php';
requireLogin();
$user = currentUser();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT id, title, section FROM reports WHERE id = ?');
$stmt->execute([$id]);
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

// Same permission rule as report.php: viewers can export any saved report,
// super_admin/analyst are limited to their assigned sections.
$canView = $user['role'] === 'viewer' || in_array($report['section'], userSections($user), true);
if (!$canView) {
    http_response_code(403);
    require __DIR__ . '/403.php';
    exit;
}

// Render the same report.php page wkhtmltopdf would see as a logged-in
// user (?pdf=1 strips nav/edit controls for a clean print layout), passing
// the current session cookie through so it hits the same permission checks
// instead of duplicating the report's markup here.
$reportUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/report.php?id=' . $id . '&pdf=1';

$pdf = new \Knp\Snappy\Pdf('/usr/bin/wkhtmltopdf');
$pdf->setOption('cookie', ['PHPSESSID' => session_id()]);
// Charts render via Chart.js after page load; give wkhtmltopdf's headless
// WebKit a moment to actually draw them before it snapshots the page.
$pdf->setOption('javascript-delay', 500);
$pdf->setOption('no-stop-slow-scripts', true);

try {
    $output = $pdf->getOutputFromUrl($reportUrl);
} catch (\Throwable $e) {
    http_response_code(500);
    echo 'Could not generate the PDF. This usually means wkhtmltopdf is not installed on the server.';
    exit;
}

$filename = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $report['title']) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($output));
echo $output;
