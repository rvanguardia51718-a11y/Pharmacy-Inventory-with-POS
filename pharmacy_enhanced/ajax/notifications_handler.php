<?php
/**
 * ajax/notifications_handler.php
 * Enhanced handler with better responses and action support
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/inventory_helper.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$action = trim($_POST['action'] ?? '');

function getUnreadCount(mysqli $conn, int $uid): int {
    $s = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $s->bind_param("i", $uid);
    $s->execute();
    return (int)$s->get_result()->fetch_row()[0];
}

function getNotificationDetails(mysqli $conn, int $nid, int $uid): ?array {
    $s = $conn->prepare("SELECT * FROM notifications WHERE id=? AND user_id=?");
    $s->bind_param("ii", $nid, $uid);
    $s->execute();
    $result = $s->get_result();
    return $result->fetch_assoc();
}

// ── mark_read ────────────────────────────────────────────────
if ($action === 'mark_read') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { 
        echo json_encode(['success' => false, 'message' => 'Invalid id']); 
        exit; 
    }
    
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE notifications SET is_read=1, read_at=NOW() WHERE id=? AND user_id=?");
        $stmt->bind_param("ii", $id, $user_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        
        // Log this action
        $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'Mark Read', ?)");
        $desc = "Marked notification #$id as read";
        $log->bind_param("is", $user_id, $desc);
        $log->execute();
        $log->close();
        
        $conn->commit();
        echo json_encode([
            'success' => true, 
            'affected' => $affected,
            'unread_count' => getUnreadCount($conn, $user_id)
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── mark_all_read ────────────────────────────────────────────
if ($action === 'mark_all_read') {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE notifications SET is_read=1, read_at=NOW() WHERE user_id=? AND is_read=0");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        
        // Log this action
        if ($affected > 0) {
            $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'Mark All Read', ?)");
            $desc = "Marked all $affected notifications as read";
            $log->bind_param("is", $user_id, $desc);
            $log->execute();
            $log->close();
        }
        
        $conn->commit();
        echo json_encode([
            'success' => true,
            'affected' => $affected,
            'message' => "$affected notification(s) marked as read.",
            'unread_count' => 0
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── poll_new ─────────────────────────────────────────────────
if ($action === 'poll_new') {
    $last_id = (int)($_POST['last_id'] ?? 0);
    
    // Generate fresh inventory notifications
    generateInventoryNotifications($conn, $user_id);
    
    $stmt = $conn->prepare(
        "SELECT id, type, reference_id, message, created_at,
         CASE 
            WHEN type IN ('expired', 'out_of_stock') THEN 'critical'
            WHEN type IN ('near_expiry', 'low_stock') THEN 'warning'
            ELSE 'info'
         END as severity
         FROM notifications
         WHERE user_id=? AND id>? AND is_read=0
         ORDER BY id ASC LIMIT 20"
    );
    $stmt->bind_param("ii", $user_id, $last_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    // Get product names for reference_ids
    foreach ($rows as &$row) {
        if ($row['reference_id'] > 0 && in_array($row['type'], ['out_of_stock', 'low_stock', 'near_expiry'])) {
            $p = $conn->prepare("SELECT brand_name FROM products WHERE id=?");
            $p->bind_param("i", $row['reference_id']);
            $p->execute();
            $prod = $p->get_result()->fetch_assoc();
            if ($prod) {
                $row['product_name'] = $prod['brand_name'];
            }
            $p->close();
        }
    }
    
    echo json_encode([
        'success' => true,
        'notifications' => $rows,
        'unread_count' => getUnreadCount($conn, $user_id),
        'timestamp' => time()
    ]);
    exit;
}

// ── delete_batch ─────────────────────────────────────────────
if ($action === 'delete_batch') {
    $batch_id = (int)($_POST['batch_id'] ?? 0);
    if ($batch_id <= 0) { 
        echo json_encode(['success' => false, 'message' => 'Invalid batch id']); 
        exit; 
    }
    
    // Verify batch exists and is expired
    $stmt = $conn->prepare("SELECT sb.*, p.brand_name FROM stock_batches sb JOIN products p ON p.id=sb.product_id WHERE sb.id=?");
    $stmt->bind_param("i", $batch_id);
    $stmt->execute();
    $batch = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$batch) {
        echo json_encode(['success' => false, 'message' => 'Batch not found']);
        exit;
    }
    
    // Check if batch is expired OR near-expiry (expiration_date < today + 1 month) — both can be removed
    $near_expiry_cutoff = strtotime('+1 month');
    if (strtotime($batch['expiration_date']) >= $near_expiry_cutoff) {
        echo json_encode(['success' => false, 'message' => 'Only expired or near-expiry batches can be removed']);
        exit;
    }
    
    $conn->begin_transaction();
    try {
        // Delete the batch
        $del = $conn->prepare("DELETE FROM stock_batches WHERE id=?");
        $del->bind_param("i", $batch_id);
        $del->execute();
        $deleted = $del->affected_rows;
        $del->close();
        
        if ($deleted === 0) {
            throw new Exception('Failed to delete batch');
        }
        
        // Mark related expired notifications as read
        $mrk = $conn->prepare("UPDATE notifications SET is_read=1, read_at=NOW() WHERE reference_id=? AND type='expired' AND user_id=?");
        $mrk->bind_param("ii", $batch_id, $user_id);
        $mrk->execute();
        $mrk->close();
        
        // Log activity
        $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'Delete Batch', ?)");
        $desc = "Removed expired batch #$batch_id (" . $batch['brand_name'] . ")";
        $log->bind_param("is", $user_id, $desc);
        $log->execute();
        $log->close();
        
        $conn->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Expired batch removed from inventory.',
            'batch_id' => $batch_id,
            'product_name' => $batch['brand_name'],
            'unread_count' => getUnreadCount($conn, $user_id)
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// ── get_unread_count ─────────────────────────────────────────
if ($action === 'get_unread_count') {
    echo json_encode([
        'success' => true,
        'unread_count' => getUnreadCount($conn, $user_id)
    ]);
    exit;
}

// ── get_notification ─────────────────────────────────────────
if ($action === 'get_notification') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid id']);
        exit;
    }
    
    $notif = getNotificationDetails($conn, $id, $user_id);
    if (!$notif) {
        echo json_encode(['success' => false, 'message' => 'Notification not found']);
        exit;
    }
    
    echo json_encode([
        'success' => true,
        'notification' => $notif
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action: ' . htmlspecialchars($action)]);
?>