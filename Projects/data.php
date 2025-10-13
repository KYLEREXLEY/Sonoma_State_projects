<?php
header('Content-Type: application/json');

// ---THE SAME CREDS AS DB.php ---
$dbHost = "localhost";
$dbName = "name";
$dbUser = "username";
$dbPass = "password";

// Which node to plot
$node = $_GET['n'] ?? 'node_1';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(time_received, '%Y-%m-%d %H:%i') AS time_received,
               temperature
        FROM sensor_data
        WHERE node_name = :n
        ORDER BY time_received ASC
    ");
    $stmt->execute([':n' => $node]);

    echo json_encode($stmt->fetchAll());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
