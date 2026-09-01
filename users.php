<?php
require_once __DIR__ . '/lib/auth.php';
requireAdmin();

$me = currentUser();
$error = null;
$editUser = null;

function ensureCsrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function checkCsrf() {
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
}

$csrfToken = ensureCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf()) {
        $error = 'Invalid form submission, please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $isAdmin = isset($_POST['is_admin']) ? 1 : 0;

            if ($username === '' || $email === '' || $password === '') {
                $error = 'Username, email, and password are all required.';
            } else {
                try {
                    $stmt = db()->prepare('INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $isAdmin]);
                    header('Location: /users.php');
                    exit;
                } catch (PDOException $e) {
                    $error = 'Could not create user (username or email may already be taken).';
                }
            }
        } elseif ($action === 'update') {
            $id = (int) ($_POST['id'] ?? 0);
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $isAdmin = isset($_POST['is_admin']) ? 1 : 0;

            if ($username === '' || $email === '') {
                $error = 'Username and email are required.';
            } else {
                try {
                    if ($password !== '') {
                        $stmt = db()->prepare('UPDATE users SET username = ?, email = ?, password_hash = ?, is_admin = ? WHERE id = ?');
                        $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $isAdmin, $id]);
                    } else {
                        $stmt = db()->prepare('UPDATE users SET username = ?, email = ?, is_admin = ? WHERE id = ?');
                        $stmt->execute([$username, $email, $isAdmin, $id]);
                    }
                    header('Location: /users.php');
                    exit;
                } catch (PDOException $e) {
                    $error = 'Could not update user (username or email may already be taken).';
                }
            }
        } elseif ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id === (int) $me['id']) {
                $error = 'You cannot delete your own account while logged in.';
            } else {
                $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([$id]);
                header('Location: /users.php');
                exit;
            }
        }
    }
}

if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT id, username, email, is_admin FROM users WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
}

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

    <?php if ($error): ?>
        <p style="color:red;"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <h2><?= $editUser ? 'Edit User' : 'Add User' ?></h2>
    <form method="POST" action="/users.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="action" value="<?= $editUser ? 'update' : 'create' ?>">
        <?php if ($editUser): ?>
            <input type="hidden" name="id" value="<?= (int) $editUser['id'] ?>">
        <?php endif; ?>
        <label>
            Username
            <input type="text" name="username" required value="<?= htmlspecialchars($editUser['username'] ?? '') ?>">
        </label>
        <br>
        <label>
            Email
            <input type="email" name="email" required value="<?= htmlspecialchars($editUser['email'] ?? '') ?>">
        </label>
        <br>
        <label>
            Password<?= $editUser ? ' (leave blank to keep current password)' : '' ?>
            <input type="password" name="password" <?= $editUser ? '' : 'required' ?>>
        </label>
        <br>
        <label>
            <input type="checkbox" name="is_admin" <?= !empty($editUser['is_admin']) ? 'checked' : '' ?>>
            Admin
        </label>
        <br>
        <button type="submit"><?= $editUser ? 'Save Changes' : 'Add User' ?></button>
        <?php if ($editUser): ?>
            <a href="/users.php">Cancel</a>
        <?php endif; ?>
    </form>

    <h2>All Users</h2>
    <table border="1" cellpadding="6">
        <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Email</th>
            <th>Password Hash</th>
            <th>Admin</th>
            <th>Actions</th>
        </tr>
        <?php foreach ($users as $u): ?>
        <tr>
            <td><?= htmlspecialchars($u['id']) ?></td>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= htmlspecialchars($u['password_hash']) ?></td>
            <td><?= $u['is_admin'] ? 'Yes' : 'No' ?></td>
            <td>
                <a href="/users.php?edit=<?= (int) $u['id'] ?>">Edit</a>
                <form method="POST" action="/users.php" style="display:inline;" onsubmit="return confirm('Delete user &quot;<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>&quot;? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <button type="submit">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </table>
</body>
</html>
