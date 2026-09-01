<?php
require_once __DIR__ . '/lib/auth.php';
requireAdmin();

$users = db()->query('SELECT id, username, email, password_hash, is_admin FROM users ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
</head>
<body>
    <h1>User Management</h1>
    <p><a href="/index.php">Back to dashboard</a> | <a href="/logout.php">Logout</a></p>
    <table border="1" cellpadding="6">
        <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Email</th>
            <th>Password Hash</th>
            <th>Admin</th>
        </tr>
        <?php foreach ($users as $u): ?>
        <tr>
            <td><?= htmlspecialchars($u['id']) ?></td>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= htmlspecialchars($u['password_hash']) ?></td>
            <td><?= $u['is_admin'] ? 'Yes' : 'No' ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
