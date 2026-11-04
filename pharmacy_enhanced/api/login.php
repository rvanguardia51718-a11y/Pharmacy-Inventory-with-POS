<?php
/**
 * api/login.php
 * Admin-only login endpoint for Flutter mobile app.
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$username = trim($data['username'] ?? '');
$password = trim($data['password'] ?? '');

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Username and password are required']);
    exit;
}

$stmt = $conn->prepare("SELECT id, username, password, full_name, role FROM users WHERE username = ? LIMIT 1");
$stmt->bind_param("s", $username);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if (!$user || !password_verify($password, $user['password'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid username or password']);
    exit;
}

if ($user['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Admin accounts only.']);
    exit;
}

// Generate a simple JWT-like token (base64 encoded payload + HMAC)
$secret = 'pharmacy_mobile_secret_key_2024'; // Change in production
$payload = [
    'user_id'   => $user['id'],
    'username'  => $user['username'],
    'full_name' => $user['full_name'],
    'role'      => $user['role'],
    'exp'       => time() + (24 * 60 * 60) // 24 hours
];
$encodedPayload = base64_encode(json_encode($payload));
$signature = hash_hmac('sha256', $encodedPayload, $secret);
$token = $encodedPayload . '.' . $signature;

echo json_encode([
    'success'   => true,
    'token'     => $token,
    'user'      => [
        'id'        => $user['id'],
        'username'  => $user['username'],
        'full_name' => $user['full_name'],
        'role'      => $user['role'],
    ]
]);
