<?php
/**
 * ajax/get_product_batches.php
 * Returns batch details for a product as JSON
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

header('Content-Type: application/json');

$product_id = intval($_GET['product_id'] ?? 0);
if ($product_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid product ID']);
    exit;
}

// Get product name
$stmt = $conn->prepare("SELECT brand_name FROM products WHERE id = ?");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    echo json_encode(['success' => false, 'message' => 'Product not found']);
    exit;
}

// Get batches with detailed info
$stmt = $conn->prepare("
    SELECT 
        id,
        batch_number,
        DATE_FORMAT(expiration_date, '%M %d, %Y') as expiration_date,
        quantity_base as total_qty,
        COALESCE(damage_quantity, 0) as damaged_qty,
        COALESCE(available_quantity, quantity_base - COALESCE(damage_quantity, 0)) as sellable_qty,
        purchase_cost,
        selling_price,
        CASE 
            WHEN expiration_date <= CURDATE() THEN 'expired'
            WHEN expiration_date < DATE_ADD(CURDATE(), INTERVAL 1 MONTH) THEN 'near_expiry'
            ELSE 'good'
        END as status,
        DATEDIFF(expiration_date, CURDATE()) as days_left
    FROM stock_batches
    WHERE product_id = ? AND quantity_base > 0
    ORDER BY expiration_date ASC
");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$result = $stmt->get_result();

$batches = [];
$summary = [
    'total_qty' => 0,
    'damaged_qty' => 0,
    'sellable_qty' => 0
];

while ($row = $result->fetch_assoc()) {
    $batches[] = $row;
    $summary['total_qty'] += $row['total_qty'];
    $summary['damaged_qty'] += $row['damaged_qty'];
    $summary['sellable_qty'] += $row['sellable_qty'];
}
$stmt->close();

echo json_encode([
    'success' => true,
    'product_name' => $product['brand_name'],
    'product_id' => $product_id,
    'batches' => $batches,
    'summary' => $summary
]);
?>