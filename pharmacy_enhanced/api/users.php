<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_middleware.php';

$authUser      = validateToken();
$currentUserId = $authUser['user_id'];

// ── Auto-add is_active column if missing ─────────────────────────────────────
$col = $conn->query("SHOW COLUMNS FROM users LIKE 'is_active'");
if ($col->num_rows === 0) {
    $conn->query("ALTER TABLE users ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
}

// ── GET list ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['id'])) {
    $search = trim($_GET['search'] ?? '');
    $role   = $_GET['role'] ?? '';

    $where  = "WHERE 1=1";
    $params = []; $types = '';
    if ($search) {
        $like = "%$search%";
        $where .= " AND (u.username LIKE ? OR u.full_name LIKE ?)";
        $params = [$like, $like]; $types = 'ss';
    }
    if ($role) {
        $where .= " AND u.role = ?";
        $params[] = $role; $types .= 's';
    }

    $sql = "
        SELECT u.id, u.username, u.full_name, u.role,
               u.is_active, u.created_at,
               (SELECT COUNT(*) FROM sales s WHERE s.user_id = u.id) AS sales_count,
               (SELECT COUNT(*) FROM activity_logs l WHERE l.user_id = u.id) AS log_count
        FROM users u
        $where
        ORDER BY u.id DESC
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        echo json_encode(['success'=>false,'message'=>'Query error: '.$conn->error]);
        exit;
    }
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res   = $stmt->get_result();
    $users = [];
    while ($r = $res->fetch_assoc()) $users[] = $r;
    $stmt->close();

    $total  = (int)$conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
    $admins = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetch_row()[0];
    $staff  = (int)$conn->query("SELECT COUNT(*) FROM users WHERE role='staff'")->fetch_row()[0];

    echo json_encode([
        'success' => true,
        'users'   => $users,
        'summary' => ['total'=>$total,'admins'=>$admins,'staff'=>$staff],
    ]);
    exit;
}

// ── GET single ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
    $id   = (int)$_GET['id'];
    $stmt = $conn->prepare("SELECT id, username, full_name, role, is_active, created_at FROM users WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }
    echo json_encode(['success'=>true,'user'=>$user]);
    exit;
}

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data   = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';

    if ($action === 'add_user') {
        $username = trim($data['username'] ?? '');
        $fullname = trim($data['full_name'] ?? '');
        $role     = $data['role'] ?? 'staff';
        $password = $data['password'] ?? '';
        if (!$username || !$fullname || !$password) {
            echo json_encode(['success'=>false,'message'=>'All fields required']); exit;
        }
        $check = $conn->prepare("SELECT id FROM users WHERE username=?");
        $check->bind_param('s', $username); $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            echo json_encode(['success'=>false,'message'=>'Username already exists']); $check->close(); exit;
        }
        $check->close();
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO users (username,full_name,role,password,is_active) VALUES (?,?,?,?,1)");
        $stmt->bind_param('ssss', $username, $fullname, $role, $hash);
        $ok = $stmt->execute(); $stmt->close();
        echo json_encode(['success'=>$ok]); exit;
    }

    if ($action === 'edit_user') {
        $id       = (int)($data['id'] ?? 0);
        $fullname = trim($data['full_name'] ?? '');
        $role     = $data['role'] ?? 'staff';
        $stmt = $conn->prepare("UPDATE users SET full_name=?,role=? WHERE id=?");
        $stmt->bind_param('ssi', $fullname, $role, $id);
        $ok = $stmt->execute(); $stmt->close();
        echo json_encode(['success'=>$ok]); exit;
    }

    if ($action === 'reset_password') {
        $id   = (int)($data['id'] ?? 0);
        $pass = $data['new_password'] ?? '';
        if (!$pass) { echo json_encode(['success'=>false,'message'=>'Password required']); exit; }
        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE users SET password=? WHERE id=?");
        $stmt->bind_param('si', $hash, $id);
        $ok = $stmt->execute(); $stmt->close();
        echo json_encode(['success'=>$ok]); exit;
    }

    if ($action === 'toggle_status') {
        $id   = (int)($data['id'] ?? 0);
        $curr = (int)$conn->query("SELECT is_active FROM users WHERE id=$id")->fetch_row()[0];
        $new  = $curr ? 0 : 1;
        $conn->query("UPDATE users SET is_active=$new WHERE id=$id");
        echo json_encode(['success'=>true,'is_active'=>$new]); exit;
    }

    if ($action === 'delete_user') {
        $id = (int)($data['id'] ?? 0);
        if ($id === $currentUserId) {
            echo json_encode(['success'=>false,'message'=>'Cannot delete your own account']); exit;
        }
        $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute(); $stmt->close();
        echo json_encode(['success'=>$ok]); exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
}

http_response_code(405);
echo json_encode(['success'=>false,'message'=>'Method not allowed']);