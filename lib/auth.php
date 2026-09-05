<?php
require_once __DIR__ . '/../config.php';

function db() {
    static $pdo = null;
    if ($pdo === null) {
        global $dbHost, $dbName, $dbUser, $dbPass;
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
    return $pdo;
}

function startSession() {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'secure' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function currentUser() {
    startSession();
    return $_SESSION['user'] ?? null;
}

function requireLogin() {
    if (!currentUser()) {
        header('Location: /login.php');
        exit;
    }
}

function requireRole($roles) {
    requireLogin();
    $roles = (array) $roles;
    if (!in_array(currentUser()['role'], $roles, true)) {
        http_response_code(403);
        require __DIR__ . '/../403.php';
        exit;
    }
}

// All section slugs a user can access. super_admin implicitly gets every
// section; analysts get whatever's in analyst_sections; viewers get none
// (their access is limited to saved reports, not live section pages).
function userSections($user) {
    if ($user['role'] === 'super_admin') {
        return array_column(db()->query('SELECT slug FROM sections')->fetchAll(PDO::FETCH_ASSOC), 'slug');
    }
    if ($user['role'] === 'analyst') {
        $stmt = db()->prepare('SELECT section_slug FROM analyst_sections WHERE user_id = ?');
        $stmt->execute([$user['id']]);
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'section_slug');
    }
    return [];
}

function requireSection($slug) {
    requireLogin();
    if (!in_array($slug, userSections(currentUser()), true)) {
        http_response_code(403);
        require __DIR__ . '/../403.php';
        exit;
    }
}

function ensureCsrfToken() {
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function checkCsrf() {
    return isset($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token']);
}
