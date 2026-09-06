<?php
require_once __DIR__ . '/lib/auth.php';
requireLogin();
$user = currentUser();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!checkCsrf()) {
        $error = 'Invalid form submission, please try again.';
    } else {
        $deleteId = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT section, is_example FROM reports WHERE id = ?');
        $stmt->execute([$deleteId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);

        // Same rule as editing a report on report.php: viewers can never
        // delete, everyone else only within their own assigned sections.
        // Example reports are never deletable, regardless of role, so
        // there's always a reference report to look at per section.
        $canDelete = $target
            && !$target['is_example']
            && $user['role'] !== 'viewer'
            && in_array($target['section'], userSections($user), true);

        if ($canDelete) {
            $stmt = db()->prepare('DELETE FROM reports WHERE id = ?');
            $stmt->execute([$deleteId]);
            header('Location: /reports.php');
            exit;
        }
        $error = $target && $target['is_example']
            ? 'Example reports can\'t be deleted.'
            : 'You do not have permission to delete that report.';
    }
}

$csrfToken = ensureCsrfToken();
$userSections = userSections($user);

$sectionNames = [];
foreach (db()->query('SELECT slug, name FROM sections')->fetchAll(PDO::FETCH_ASSOC) as $s) {
    $sectionNames[$s['slug']] = $s['name'];
}

// Viewers see every saved report regardless of section (saved reports are
// their entire accessible surface). super_admin/analyst are still limited
// to the sections they can see live.
if ($user['role'] === 'viewer') {
    $reports = db()->query(
        "SELECT r.id, r.title, r.section, r.is_example, r.created_at, u.username AS created_by
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
            "SELECT r.id, r.title, r.section, r.is_example, r.created_at, u.username AS created_by
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
    <?php include __DIR__ . '/partials/theme.php'; ?>
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
        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <?php if (!$reports): ?>
        <p class="muted">No saved reports yet<?= $user['role'] !== 'viewer' ? ' for your assigned sections' : '' ?>.</p>
        <?php else: ?>
        <table>
            <tr>
                <th>Title</th>
                <th>Section</th>
                <th>Created By</th>
                <th>Created At</th>
                <th>Actions</th>
            </tr>
            <?php foreach ($reports as $r): ?>
            <?php $canDeleteRow = !$r['is_example'] && $user['role'] !== 'viewer' && in_array($r['section'], $userSections, true); ?>
            <tr>
                <td>
                    <a href="/report.php?id=<?= (int) $r['id'] ?>"><?= htmlspecialchars($r['title']) ?></a>
                    <?php if ($r['is_example']): ?> <span class="muted">(example)</span><?php endif; ?>
                </td>
                <td><?= htmlspecialchars($sectionNames[$r['section']] ?? $r['section']) ?></td>
                <td><?= htmlspecialchars($r['created_by']) ?></td>
                <td><?= htmlspecialchars($r['created_at']) ?></td>
                <td>
                    <?php if ($canDeleteRow): ?>
                    <form class="inline" method="POST" action="/reports.php" onsubmit="return confirm('Delete report &quot;<?= htmlspecialchars($r['title'], ENT_QUOTES) ?>&quot;? This cannot be undone.');">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                        <button type="submit">Delete</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
    </main>
</body>
</html>
