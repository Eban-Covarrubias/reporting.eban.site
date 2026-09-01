<?php
require_once __DIR__ . '/lib/auth.php';
requireLogin();
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard</title>
</head>
<body>
    <h1>reporting.eban.site</h1>
    <p>Logged in as <?= htmlspecialchars($user['username']) ?> (<?= $user['is_admin'] ? 'admin' : 'basic' ?>)</p>
    <p>
        <a href="/logout.php">Logout</a>
        <?php if ($user['is_admin']): ?>
            | <a href="/users.php">User Management</a>
        <?php endif; ?>
    </p>
    <p><em>Dashboard charts coming in Part 3.</em></p>
</body>
</html>
