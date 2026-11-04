<?php
// config/database.php
$host     = 'localhost';
$username = 'root';
$password = '';
$database = 'pharmacy_db';

// MySQLi connection
$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// PDO kept for backward compatibility
try {
    $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // PDO optional — mysqli is used everywhere
}

/**
 * logActivity()
 * Works with both old (3 columns) and new schema (product_id column added).
 * product_id is optional — defaults to NULL so old callers still work fine.
 */
function logActivity($conn, $user_id, $action, $description, $product_id = null) {
    if ($conn instanceof mysqli) {
        $stmt = $conn->prepare(
            "INSERT INTO activity_logs (user_id, action, description, product_id) VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param("issi", $user_id, $action, $description, $product_id);
        $stmt->execute();
        $stmt->close();
    } else {
        // PDO fallback
        $stmt = $conn->prepare(
            "INSERT INTO activity_logs (user_id, action, description, product_id) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$user_id, $action, $description, $product_id]);
    }
}
?>