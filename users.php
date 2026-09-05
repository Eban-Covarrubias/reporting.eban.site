<?php
require_once __DIR__ . '/lib/auth.php';
requireRole('super_admin');

$me = currentUser();
$error = null;
$editUser = null;
$editSections = [];

$allSections = db()->query('SELECT slug, name FROM sections ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$validRoles = ['super_admin', 'analyst', 'viewer'];

function saveAnalystSections(int $userId, array $sectionSlugs) {
    $stmt = db()->prepare('DELETE FROM analyst_sections WHERE user_id = ?');
    $stmt->execute([$userId]);
    if ($sectionSlugs) {
        $insert = db()->prepare('INSERT INTO analyst_sections (user_id, section_slug) VALUES (?, ?)');
        foreach ($sectionSlugs as $slug) {
            $insert->execute([$userId, $slug]);
        }
    }
}

$csrfToken = ensureCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!checkCsrf()) {
        $error = 'Invalid form submission, please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $role = in_array($_POST['role'] ?? '', $validRoles, true) ? $_POST['role'] : 'viewer';
        $sectionSlugs = $role === 'analyst' ? array_intersect($_POST['sections'] ?? [], array_column($allSections, 'slug')) : [];

        if ($action === 'create') {
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($username === '' || $email === '' || $password === '') {
                $error = 'Username, email, and password are all required.';
            } else {
                try {
                    $stmt = db()->prepare('INSERT INTO users (username, email, password_hash, role) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $role]);
                    saveAnalystSections((int) db()->lastInsertId(), $sectionSlugs);
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

            if ($username === '' || $email === '') {
                $error = 'Username and email are required.';
            } elseif ($id === (int) $me['id'] && $role !== 'super_admin') {
                $error = 'You cannot demote your own account while logged in.';
            } else {
                try {
                    if ($password !== '') {
                        $stmt = db()->prepare('UPDATE users SET username = ?, email = ?, password_hash = ?, role = ? WHERE id = ?');
                        $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $role, $id]);
                    } else {
                        $stmt = db()->prepare('UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?');
                        $stmt->execute([$username, $email, $role, $id]);
                    }
                    saveAnalystSections($id, $sectionSlugs);
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
    $stmt = db()->prepare('SELECT id, username, email, role FROM users WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $editUser = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editUser) {
        $stmt = db()->prepare('SELECT section_slug FROM analyst_sections WHERE user_id = ?');
        $stmt->execute([$editUser['id']]);
        $editSections = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'section_slug');
    }
}

$users = db()->query(
    "SELECT u.id, u.username, u.email, u.password_hash, u.role,
            GROUP_CONCAT(a.section_slug ORDER BY a.section_slug SEPARATOR ', ') AS sections
     FROM users u
     LEFT JOIN analyst_sections a ON a.user_id = u.id
     GROUP BY u.id
     ORDER BY u.id"
)->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management</title>
    <link rel="stylesheet" href="/css/style.css">
    <script>
        function toggleSections() {
            var role = document.getElementById('roleSelect').value;
            document.getElementById('sectionsField').style.display = role === 'analyst' ? 'block' : 'none';
        }
    </script>
</head>
<body>
    <header>
        <h1>User Management</h1>
        <nav>
            <a href="/index.php">Back to dashboard</a>
            <a href="/reports.php">Saved Reports</a>
            <a href="/logout.php">Logout</a>
        </nav>
    </header>
    <main>
        <?php if ($error): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <section>
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
                <label>
                    Email
                    <input type="email" name="email" required value="<?= htmlspecialchars($editUser['email'] ?? '') ?>">
                </label>
                <label>
                    Password<?= $editUser ? ' (leave blank to keep current password)' : '' ?>
                    <input type="password" name="password" <?= $editUser ? '' : 'required' ?>>
                </label>
                <label>
                    Role
                    <select id="roleSelect" name="role" onchange="toggleSections()">
                        <?php foreach ($validRoles as $r): ?>
                        <option value="<?= $r ?>" <?= ($editUser['role'] ?? 'viewer') === $r ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $r)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div id="sectionsField" style="display: <?= ($editUser['role'] ?? '') === 'analyst' ? 'block' : 'none' ?>;">
                    <label>Sections (analyst only)</label>
                    <?php foreach ($allSections as $s): ?>
                    <label>
                        <input type="checkbox" name="sections[]" value="<?= htmlspecialchars($s['slug']) ?>" <?= in_array($s['slug'], $editSections, true) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($s['name']) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <button type="submit"><?= $editUser ? 'Save Changes' : 'Add User' ?></button>
                <?php if ($editUser): ?>
                    <a href="/users.php">Cancel</a>
                <?php endif; ?>
            </form>
        </section>

        <section>
        <h2>All Users</h2>
        <table>
        <tr>
            <th>ID</th>
            <th>Username</th>
            <th>Email</th>
            <th>Password Hash</th>
            <th>Role</th>
            <th>Sections</th>
            <th>Actions</th>
        </tr>
        <?php foreach ($users as $u): ?>
        <tr>
            <td><?= htmlspecialchars($u['id']) ?></td>
            <td><?= htmlspecialchars($u['username']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><?= htmlspecialchars($u['password_hash']) ?></td>
            <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $u['role']))) ?></td>
            <td><?= $u['sections'] ? htmlspecialchars($u['sections']) : ($u['role'] === 'super_admin' ? 'all' : '&mdash;') ?></td>
            <td>
                <a href="/users.php?edit=<?= (int) $u['id'] ?>">Edit</a>
                <form class="inline" method="POST" action="/users.php" onsubmit="return confirm('Delete user &quot;<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>&quot;? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <button type="submit">Delete</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </table>
        </section>
    </main>
</body>
</html>
