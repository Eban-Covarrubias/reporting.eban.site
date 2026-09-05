<?php
require_once __DIR__ . '/lib/auth.php';
requireLogin();
$user = currentUser();

$sectionNames = [];
foreach (db()->query('SELECT slug, name FROM sections')->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $sectionNames[$s['slug']] = $s['name'];
}

// Viewers see every saved report regardless of section (saved reports are
// their entire accessible surface). super_admin/analyst are still limited
// to the sections they can see live.
if ($user['role'] === 'viewer') {
    $reports = db()->query(
        "SELECT r.id, r.title, r.section, r.created_at, u.username AS created_by
         FROM reports r JOIN users u ON u.id = r.created_by
         ORDER BY r.created_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} else {
    $sections = userSections($user);
    if (!$sections) {
        $reports = [];
    } else {
        $placeholders = implode(',', array_fill(0, count($sections), '?'));
        $stmt = db()->prepare(
            "SELECT r.id, r.title, r.section, r.created_at, u.username AS created_by
             FROM reports r JOIN users u ON u.id = r.created_by
             WHERE r.section IN ($placeholders)
             ORDER BY r.created_at DESC"
        );
        $stmt->execute($sections);
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Saved Reports</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <header>
        <h1>Saved Reports</h1>
        <nav>
            <?php if ($user['role'] !== 'viewer'): ?>
                <a href="/index.php">Back to dashboard</a>
            <?php endif; ?>
            <a href="/logout.php">Logout</a>
        </nav>
    </header>
    <main>
        <?php if (!$reports): ?>
        <p class="muted">No saved reports yet<?= $user['role'] !== 'viewer' ? ' for your assigned sections' : '' ?>.</p>
        <?php else: ?>
        <table>
            <tr>
                <th>Title</th>
                <th>Section</th>
                <th>Created By</th>
                <th>Created At</th>
            </tr>
            <?php foreach ($reports as $r): ?>
            <tr>
                <td><a href="/report.php?id=<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['title']) ?></a></td>
                <td><?= htmlspecialchars($sectionNames[$r['section']] ?? $r['section']) ?></td>
                <td><?= htmlspecialchars($r['created_by']) ?></td>
                <td><?= htmlspecialchars($r['created_at']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </main>
</body>
</html>
