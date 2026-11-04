<?php
/**
 * ajax/get_product_json.php
 * Returns product data as JSON including total_stock, units, next_expiry
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';

header('Content-Type: application/json');

$product_id = intval($_GET['product_id'] ?? 0);
if ($product_id <= 0) { echo json_encode(null); exit; }

// FIXED: Make sure all fields are selected
$stmt = $conn->prepare("
    SELECT p.*, c.name as category_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE p.id = ?
");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) { echo json_encode(null); exit; }

// Add total stock — available_quantity already excludes damage
$ts = $conn->prepare(
    "SELECT COALESCE(SUM(COALESCE(available_quantity, quantity_base - COALESCE(damage_quantity, 0))),0) as total
     FROM stock_batches WHERE product_id=? AND quantity_base>0 AND expiration_date>=CURDATE()"
);
$ts->bind_param("i", $product_id);
$ts->execute();
$product['total_stock'] = (int)$ts->get_result()->fetch_assoc()['total'];
$ts->close();

// First FIFO batch (oldest expiry with actual sellable stock)
$nx = $conn->prepare(
    "SELECT expiration_date, selling_price FROM stock_batches
     WHERE product_id=? AND expiration_date>=CURDATE()
       AND COALESCE(available_quantity, quantity_base - COALESCE(damage_quantity, 0)) > 0
     ORDER BY expiration_date ASC, id ASC LIMIT 1"
);
$nx->bind_param("i", $product_id);
$nx->execute();
$nx_row = $nx->get_result()->fetch_assoc();
$nx->close();
$product['next_expiry'] = $nx_row ? date('M d, Y', strtotime($nx_row['expiration_date'])) : null;
$product['next_batch_selling_price'] = ($nx_row && $nx_row['selling_price'] !== null) ? (float)$nx_row['selling_price'] : null;

// Units
$us = $conn->prepare(
    "SELECT unit_name, conversion_to_base, price_override, sort_order
     FROM product_units WHERE product_id=? ORDER BY sort_order ASC"
);
$us->bind_param("i", $product_id);
$us->execute();
$product['units'] = $us->get_result()->fetch_all(MYSQLI_ASSOC);
$us->close();

// Make sure numeric values are properly formatted
$product['price'] = floatval($product['price']);
$product['cost'] = floatval($product['cost']);
$product['low_stock_threshold'] = intval($product['low_stock_threshold']);
$product['requires_prescription'] = intval($product['requires_prescription']);

echo json_encode($product);
?>