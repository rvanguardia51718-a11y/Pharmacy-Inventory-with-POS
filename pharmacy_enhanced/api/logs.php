<?php
/**
 * api/logs.php
 * GET /logs.php               → activity logs with search/filter
 * GET /logs.php?action=poll_new&last_id=N  → new logs since ID N (real-time)
 * POST /logs.php {action:log_mobile}       → log an activity from mobile app
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_middleware.php';

validateToken();

// ── Handle POST: log a mobile action ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $act  = trim($body['action'] ?? '');

    if ($act === 'log_mobile') {
        $payload     = validateToken();
        $action_type = trim($body['action_type'] ?? 'mobile_action');
        $description = trim($body['description'] ?? '');
        $user_id     = (int)($payload['user_id'] ?? 0);

        if (empty($description)) {
            echo json_encode(['success' => false, 'message' => 'Description required']);
            exit;
        }

        $stmt = $conn->prepare(
            "INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)"
        );
        $stmt->bind_param("iss", $user_id, $action_type, $description);
        $stmt->execute();
        $stmt->close();

        echo json_encode(['success' => true, 'message' => 'Logged']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown POST action']);
    exit;
}

// ── Handle GET: poll_new — return logs newer than last_id ─────────────────────
$action = trim($_GET['action'] ?? '');
if ($action === 'poll_new') {
    $last_id = (int)($_GET['last_id'] ?? 0);

    $stmt = $conn->prepare("
        SELECT l.id, l.action, l.description, l.date_time,
               u.username, u.full_name, u.role
        FROM activity_logs l
        JOIN users u ON l.user_id = u.id
        WHERE l.id > ?
        ORDER BY l.id DESC
        LIMIT 50
    ");
    $stmt->bind_param("i", $last_id);
    $stmt->execute();
    $res  = $stmt->get_result();
    $new_logs = [];
    while ($r = $res->fetch_assoc()) {
        $new_logs[] = [
            'id'          => (int)$r['id'],
            'action'      => $r['action'],
            'description' => $r['description'],
            'date_time'   => $r['date_time'],
            'time'        => date('H:i:s', strtotime($r['date_time'])),
            'username'    => $r['username'],
            'full_name'   => $r['full_name'],
            'role'        => $r['role'],
        ];
    }
    $stmt->close();

    $today_count = (int)$conn->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(date_time)=CURDATE()")->fetch_row()[0];
    $total_count = (int)$conn->query("SELECT COUNT(*) FROM activity_logs")->fetch_row()[0];

    echo json_encode([
        'success'  => true,
        'new_logs' => $new_logs,
        'stats'    => ['today' => $today_count, 'total' => $total_count],
    ]);
    exit;
}

$search    = trim($_GET['search'] ?? '');
$user_id   = (int)($_GET['user_id'] ?? 0);
$date_from = trim($_GET['date_from'] ?? '');
$date_to   = trim($_GET['date_to'] ?? '');
$action_type = trim($_GET['action_type'] ?? '');
$limit     = min((int)($_GET['limit'] ?? 100), 500);
$offset    = (int)($_GET['offset'] ?? 0);

$where  = "WHERE 1=1";
$params = []; $types = '';

if ($search) {
    $where .= " AND (l.action LIKE ? OR l.description LIKE ? OR u.full_name LIKE ?)";
    $like   = "%$search%";
    $params = array_merge($params, [$like, $like, $like]);
    $types .= 'sss';
}
if ($user_id) {
    $where .= " AND l.user_id = ?";
    $params[] = $user_id; $types .= 'i';
}
if ($date_from) {
    $where .= " AND DATE(l.date_time) >= ?";
    $params[] = $date_from; $types .= 's';
}
if ($date_to) {
    $where .= " AND DATE(l.date_time) <= ?";
    $params[] = $date_to; $types .= 's';
}
if ($action_type) {
    $where .= " AND l.action LIKE ?";
    $params[] = "%$action_type%"; $types .= 's';
}

// Count
$countSql = "SELECT COUNT(*) FROM activity_logs l JOIN users u ON l.user_id = u.id $where";
$cStmt    = $conn->prepare($countSql);
if ($types) $cStmt->bind_param($types, ...$params);
$cStmt->execute();
$total = $cStmt->get_result()->fetch_row()[0];
$cStmt->close();

// Data
$sql  = "
    SELECT l.id, l.action, l.description, l.date_time, l.product_id,
           u.id AS user_id, u.username, u.full_name, u.role
    FROM activity_logs l
    JOIN users u ON l.user_id = u.id
    $where
    ORDER BY l.date_time DESC
    LIMIT ? OFFSET ?
";
$params[] = $limit; $types .= 'i';
$params[] = $offset; $types .= 'i';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res  = $stmt->get_result();
$logs = [];
while ($r = $res->fetch_assoc()) $logs[] = $r;
$stmt->close();

// Unique users for filter dropdown
$usersRes = $conn->query("
    SELECT DISTINCT u.id, u.full_name FROM activity_logs l
    JOIN users u ON l.user_id=u.id ORDER BY u.full_name
");
$filterUsers = [];
while ($r = $usersRes->fetch_assoc()) $filterUsers[] = $r;

// Unique action types
$actionsRes = $conn->query("SELECT DISTINCT action FROM activity_logs ORDER BY action");
$actionTypes = [];
while ($r = $actionsRes->fetch_assoc()) $actionTypes[] = $r['action'];

// Summary stats
$todayCount   = $conn->query("SELECT COUNT(*) FROM activity_logs WHERE DATE(date_time)=CURDATE()")->fetch_row()[0];
$weekCount    = $conn->query("SELECT COUNT(*) FROM activity_logs WHERE date_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_row()[0];
$uniqueUsers  = $conn->query("SELECT COUNT(DISTINCT user_id) FROM activity_logs")->fetch_row()[0];

echo json_encode([
    'success'      => true,
    'logs'         => $logs,
    'total'        => (int)$total,
    'limit'        => $limit,
    'offset'       => $offset,
    'filter_users' => $filterUsers,
    'action_types' => $actionTypes,
    'summary'      => [
        'today'        => (int)$todayCount,
        'this_week'    => (int)$weekCount,
        'unique_users' => (int)$uniqueUsers,
        'total_logs'   => (int)$total,
    ],
]);
