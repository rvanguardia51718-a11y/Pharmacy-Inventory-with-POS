<?php
require_once '../config/database.php';
require_once '../includes/auth_check.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}
$is_admin = isAdmin();
$current_user_id = $_SESSION['user_id'] ?? 0;

header('Content-Type: application/json');

$sale_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_GET['sale_id']) ? (int)$_GET['sale_id'] : 0);
if ($sale_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid transaction ID.']);
    exit;
}

// Fetch the sale — staff can only view their own transactions
if ($is_admin) {
    $stmt = $conn->prepare("
        SELECT s.*, u.full_name
        FROM sales s
        JOIN users u ON s.user_id = u.id
        WHERE s.id = ?
    ");
    $stmt->bind_param("i", $sale_id);
} else {
    $stmt = $conn->prepare("
        SELECT s.*, u.full_name
        FROM sales s
        JOIN users u ON s.user_id = u.id
        WHERE s.id = ? AND s.user_id = ?
    ");
    $stmt->bind_param("ii", $sale_id, $current_user_id);
}
$stmt->execute();
$sale = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$sale) {
    echo json_encode(['success' => false, 'message' => 'Transaction not found or access denied.']);
    exit;
}

// Fetch items for this sale — join stock_batches to get batch number
$items_stmt = $conn->prepare("
    SELECT si.*,
           p.brand_name,
           p.generic_name,
           sb.batch_number
    FROM sale_items si
    JOIN products p ON p.id = si.product_id
    LEFT JOIN stock_batches sb ON sb.id = si.batch_id
    WHERE si.sale_id = ?
    ORDER BY si.id ASC
");
$items_stmt->bind_param("i", $sale_id);
$items_stmt->execute();
$items_result = $items_stmt->get_result();
$items = [];
while ($row = $items_result->fetch_assoc()) {
    $items[] = $row;
}
$items_stmt->close();

echo json_encode([
    'success' => true,
    'sale' => [
        'id'            => $sale['id'],
        'date'          => date('M d, Y', strtotime($sale['sale_date'])),
        'time'          => date('h:i A', strtotime($sale['sale_date'])),
        'staff'         => $sale['full_name'],
        'subtotal'      => $sale['total_amount'],
        'discount'      => $sale['discount'],
        'tax'           => $sale['tax'],
        'grand_total'   => $sale['grand_total'],
        'cash_received' => $sale['cash_received'],
        'change_amount' => $sale['change_amount'],
    ],
    'items' => $items,
]);