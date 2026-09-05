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
$sessionId = session_id();

// PHP's default session handler locks the session file for the life of the
// script. wkhtmltopdf is about to make its own HTTP request back to this
// same server using this same session cookie (to render report.php as this
// user) - if we don't release the lock first, that inbound request blocks
// forever waiting on a lock we're still holding while we wait on it. Closing
// the session here is safe since nothing below reads or writes $_SESSION.
session_write_close();

// Apache's www-data has no HOME set, which makes wkhtmltopdf's underlying
// Qt/WebKit engine hang indefinitely looking for a cache/config directory.
$pdf = new \Knp\Snappy\Pdf('/usr/bin/wkhtmltopdf', [], ['HOME' => '/tmp']);
$pdf->setOption('cookie', ['PHPSESSID' => $sessionId]);

try {
    $output = $pdf->getOutput($reportUrl);
} catch (\Throwable $e) {
    http_response_code(500);
    echo 'Could not generate the PDF. Please try again, or contact the site admin if this keeps happening.';
    exit;
}

$filename = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $report['title']) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($output));
echo $output;
