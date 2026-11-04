<?php
/**
 * api/notifications.php
 * GET  → list unread notifications for admin
 * POST → mark as read / mark all read
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_middleware.php';

$authUser = validateToken();
$userId   = $authUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $res = $conn->query("
        SELECT * FROM notifications WHERE user_id=$userId
        ORDER BY created_at DESC LIMIT 50
    ");
    $notifs = [];
    $unread = 0;
    while ($r = $res->fetch_assoc()) {
        $notifs[] = $r;
        if (!$r['is_read']) $unread++;
    }
    echo json_encode(['success'=>true,'notifications'=>$notifs,'unread_count'=>$unread]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data   = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';

    if ($action === 'mark_read') {
        $id   = (int)($data['id'] ?? 0);
        $conn->query("UPDATE notifications SET is_read=1,read_at=NOW() WHERE id=$id AND user_id=$userId");
        echo json_encode(['success'=>true]);
        exit;
    }

    if ($action === 'mark_all_read') {
        $conn->query("UPDATE notifications SET is_read=1,read_at=NOW() WHERE user_id=$userId AND is_read=0");
        echo json_encode(['success'=>true]);
        exit;
    }
}

http_response_code(405);
echo json_encode(['success'=>false,'message'=>'Method not allowed']);
