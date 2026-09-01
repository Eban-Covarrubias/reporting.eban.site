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
</head>
<body>
    <h1>You have successfully logged out.</h1>
    <p><a href="/login.php">Log back in</a></p>
</body>
</html>
