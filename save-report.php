<?php
require_once __DIR__ . '/lib/auth.php';
requireLogin();
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /index.php');
    exit;
}

if (!checkCsrf()) {
    http_response_code(400);
    echo 'Invalid form submission, please go back and try again.';
    exit;
}

$section = $_POST['section'] ?? '';
$validSections = ['performance', 'errors', 'engagement'];
if (!in_array($section, $validSections, true)) {
    http_response_code(400);
    echo 'Invalid section.';
    exit;
}

// Saving a report requires the same section access as viewing its live page.
requireSection($section);

$title = trim($_POST['title'] ?? '');
$rows = json_decode($_POST['snapshot'] ?? '', true);

if ($title === '' || !is_array($rows)) {
    http_response_code(400);
    echo 'Missing report title or snapshot data.';
    exit;
}

$stmt = db()->prepare('INSERT INTO reports (title, section, created_by, snapshot) VALUES (?, ?, ?, ?)');
$stmt->execute([$title, $section, $user['id'], json_encode($rows)]);

header('Location: /report.php?id=' . db()->lastInsertId());
exit;
