<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require __DIR__ . '/../config.php';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

function respond($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

// events.data is stored as a JSON string column; decode it for the response
// so API consumers get real nested JSON instead of an escaped string.
function rowOut($row) {
    if ($row && isset($row['data']) && $row['data'] !== null) {
        $row['data'] = json_decode($row['data'], true);
    }
    return $row;
}

$path = trim($_GET['path'] ?? '', '/');
$segments = $path === '' ? [] : explode('/', $path);
$method = $_SERVER['REQUEST_METHOD'];

if (($segments[0] ?? '') === '') {
    respond(['error' => 'No route specified'], 404);
}

// GET /api/events/type/{type} - all events of one type
if ($segments[0] === 'events' && ($segments[1] ?? null) === 'type' && isset($segments[2]) && $method === 'GET') {
    $stmt = $pdo->prepare('SELECT * FROM events WHERE type = ? ORDER BY id DESC');
    $stmt->execute([$segments[2]]);
    respond(array_map('rowOut', $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// GET /api/sessions/{session_id}/events - all events for one session
if ($segments[0] === 'sessions' && isset($segments[1]) && ($segments[2] ?? null) === 'events' && $method === 'GET') {
    $stmt = $pdo->prepare('SELECT * FROM events WHERE session_id = ? ORDER BY id ASC');
    $stmt->execute([$segments[1]]);
    respond(array_map('rowOut', $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// /api/events and /api/events/{id}
if ($segments[0] === 'events') {
    $id = $segments[1] ?? null;

    if ($method === 'GET' && $id === null) {
        $stmt = $pdo->query('SELECT * FROM events ORDER BY id DESC LIMIT 500');
        respond(array_map('rowOut', $stmt->fetchAll(PDO::FETCH_ASSOC)));
    }

    if ($method === 'GET' && $id !== null) {
        $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) respond(['error' => 'Not found'], 404);
        respond(rowOut($row));
    }

    if ($method === 'POST' && $id === null) {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $stmt = $pdo->prepare(
            'INSERT INTO events (session_id, page, type, client_timestamp, data, client_ip)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $body['session_id'] ?? 'unknown',
            $body['page'] ?? 'unknown',
            $body['type'] ?? 'unknown',
            $body['client_timestamp'] ?? null,
            isset($body['data']) ? json_encode($body['data']) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
        respond(['id' => (int) $pdo->lastInsertId()], 201);
    }

    if ($method === 'PUT' && $id !== null) {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $stmt = $pdo->prepare(
            'UPDATE events SET session_id = ?, page = ?, type = ?, client_timestamp = ?, data = ? WHERE id = ?'
        );
        $stmt->execute([
            $body['session_id'] ?? 'unknown',
            $body['page'] ?? 'unknown',
            $body['type'] ?? 'unknown',
            $body['client_timestamp'] ?? null,
            isset($body['data']) ? json_encode($body['data']) : null,
            $id,
        ]);
        respond(['updated' => $stmt->rowCount() > 0]);
    }

    if ($method === 'DELETE' && $id !== null) {
        $stmt = $pdo->prepare('DELETE FROM events WHERE id = ?');
        $stmt->execute([$id]);
        respond(['deleted' => $stmt->rowCount() > 0]);
    }
}

respond(['error' => 'Route not found'], 404);
