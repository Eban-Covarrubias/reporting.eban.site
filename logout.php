<?php
require_once __DIR__ . '/lib/auth.php';
startSession();
$_SESSION = [];
session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logged Out</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <header>
        <h1>reporting.eban.site</h1>
    </header>
    <main>
        <h2>You have successfully logged out.</h2>
        <p><a href="/login.php">Log back in</a></p>
    </main>
</body>
</html>
