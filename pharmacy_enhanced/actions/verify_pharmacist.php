<?php
/**
 * actions/verify_pharmacist.php
 * AJAX endpoint — verifies a pharmacist's credentials before allowing Rx checkout.
 *
 * POST JSON: { "username": "...", "password": "..." }
 * Response:
 *   { "success": true,  "pharmacist": { id, name, license_number, license_expiry_date } }
 *   { "success": false, "message": "..." }
 *
 * Destination: C:\xampp\htdocs\pharmacy_enhanced\actions\verify_pharmacist.php
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$username = trim($input['username'] ?? '');
$password = trim($input['password'] ?? '');

if (!$username || !$password) {
    echo json_encode(['success' => false, 'message' => 'Username and password are required.']);
    exit;
}

// Must be is_pharmacist=1 with a stored license number
$stmt = $conn->prepare("
    SELECT id, full_name, password, license_number, license_expiry_date
    FROM users
    WHERE username = ? AND is_pharmacist = 1
    LIMIT 1
");
$stmt->bind_param('s', $username);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['success' => false, 'message' => 'No licensed pharmacist found with that username.']);
    exit;
}

if (!password_verify($password, $user['password'])) {
    echo json_encode(['success' => false, 'message' => 'Incorrect password.']);
    exit;
}

if (empty($user['license_number'])) {
    echo json_encode(['success' => false, 'message' => 'Pharmacist has no license number on record. Contact admin.']);
    exit;
}

if (empty($user['license_expiry_date'])) {
    echo json_encode(['success' => false, 'message' => 'Pharmacist license expiry date is not set. Contact admin.']);
    exit;
}

$expiry = new DateTime($user['license_expiry_date']);
$today  = new DateTime('today');
if ($expiry < $today) {
    echo json_encode([
        'success' => false,
        'message' => 'Pharmacist license expired on ' . $expiry->format('M d, Y') . '. Please update license records.'
    ]);
    exit;
}

// All checks passed — return pharmacist info
echo json_encode([
    'success'    => true,
    'pharmacist' => [
        'id'                  => (int)$user['id'],
        'name'                => $user['full_name'],
        'license_number'      => $user['license_number'],
        'license_expiry_date' => $user['license_expiry_date'],
    ]
]);