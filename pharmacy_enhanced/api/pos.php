<?php
/**
 * api/pos.php  — Mobile Flutter POS endpoint
 * Same FIFO stock deduction logic as actions/pos_process.php
 * but uses TOKEN auth instead of session auth
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_middleware.php';

// Token auth — gets user_id from JWT token
$authUser = validateToken();
$user_id  = (int)$authUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'POST only']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['items'])) {
    echo json_encode(['success' => false, 'message' => 'Empty cart']);
    exit;
}

$total    = (float)($data['total_amount']  ?? 0);
$discount = (float)($data['discount']      ?? 0);
$tax      = (float)($data['tax']           ?? 0);
$grand    = (float)($data['grand_total']   ?? $total);
$cash     = (float)($data['cash_received'] ?? $grand);
$change   = (float)($data['change_amount'] ?? max(0, $cash - $grand));

$pos_sn = 'MOB-' . rand(10000000, 99999999);
$tm_num = 'TM#' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

try {
    $conn->begin_transaction();

    // ── Pre-validate ALL items before touching the DB ─────────────────────────
    foreach ($data['items'] as $item) {
        $product_id       = (int)($item['id'] ?? 0);
        $quantity_in_base = (int)($item['quantity_in_base'] ?? $item['quantity_sold'] ?? $item['quantity'] ?? 1);

        $avail_stmt = $conn->prepare(
            "SELECT COALESCE(SUM(quantity_base),0) AS total
             FROM stock_batches
             WHERE product_id=? AND quantity_base>0 AND expiration_date>=CURDATE()"
        );
        $avail_stmt->bind_param('i', $product_id);
        $avail_stmt->execute();
        $avail = (int)$avail_stmt->get_result()->fetch_assoc()['total'];
        $avail_stmt->close();

        if ($quantity_in_base > $avail) {
            $ns = $conn->prepare("SELECT brand_name FROM products WHERE id=?");
            $ns->bind_param('i', $product_id);
            $ns->execute();
            $name = $ns->get_result()->fetch_assoc()['brand_name'] ?? "Product #$product_id";
            $ns->close();
            throw new Exception("Not enough stock for $name (have: $avail, need: $quantity_in_base)");
        }
    }

    // ── Insert sale header ────────────────────────────────────────────────────
    // Check which columns exist in sales table
    $cols = $conn->query("SHOW COLUMNS FROM sales")->fetch_all(MYSQLI_ASSOC);
    $colNames = array_column($cols, 'Field');

    $hasVat    = in_array('vat_sales', $colNames);
    $hasPossSn = in_array('pos_sn', $colNames);
    $hasTm     = in_array('tm_number', $colNames);

    if ($hasVat && $hasPossSn && $hasTm) {
        $stmt = $conn->prepare("
            INSERT INTO sales (user_id, total_amount, discount, tax, grand_total,
                cash_received, change_amount, vat_sales, vat_exempt_sales,
                vat_zero_rated_sales, vat_amount, pos_sn, tm_number)
            VALUES (?,?,?,?,?,?,?,0,0,0,0,?,?)
        ");
        $stmt->bind_param('iddddddss', $user_id, $total, $discount, $tax, $grand,
            $cash, $change, $pos_sn, $tm_num);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO sales (user_id, total_amount, discount, tax, grand_total,
                cash_received, change_amount)
            VALUES (?,?,?,?,?,?,?)
        ");
        $stmt->bind_param('idddddd', $user_id, $total, $discount, $tax, $grand, $cash, $change);
    }
    $stmt->execute();
    $sale_id = $conn->insert_id;
    $stmt->close();

    // ── Process each item with TRUE FIFO deduction ────────────────────────────
    $saleItemCols = $conn->query("SHOW COLUMNS FROM sale_items")->fetch_all(MYSQLI_ASSOC);
    $saleItemColNames = array_column($saleItemCols, 'Field');
    $hasQtySold  = in_array('quantity_sold', $saleItemColNames);
    $hasQtyBase  = in_array('quantity_in_base', $saleItemColNames);
    $hasBatchId  = in_array('batch_id', $saleItemColNames);
    $hasUnitSold = in_array('unit_sold', $saleItemColNames);
    $hasVatType  = in_array('vat_type', $saleItemColNames);

    foreach ($data['items'] as $item) {
        $product_id       = (int)($item['id'] ?? 0);
        $quantity_in_base = (int)($item['quantity_in_base'] ?? $item['quantity_sold'] ?? $item['quantity'] ?? 1);
        $unit_price       = (float)($item['unit_price'] ?? $item['price'] ?? 0);
        $subtotal         = (float)($item['subtotal'] ?? ($quantity_in_base * $unit_price));

        // TRUE FIFO — oldest expiry first
        $remaining      = $quantity_in_base;
        $first_batch_id = null;

        $fifo = $conn->prepare(
            "SELECT id, quantity_base FROM stock_batches
             WHERE product_id=? AND quantity_base>0 AND expiration_date>=CURDATE()
             ORDER BY expiration_date ASC, id ASC"
        );
        $fifo->bind_param('i', $product_id);
        $fifo->execute();
        $batches = $fifo->get_result()->fetch_all(MYSQLI_ASSOC);
        $fifo->close();

        foreach ($batches as $batch) {
            if ($remaining <= 0) break;
            $take = min($remaining, (int)$batch['quantity_base']);
            $upd  = $conn->prepare(
                "UPDATE stock_batches SET quantity_base=quantity_base-? WHERE id=? AND quantity_base>=?"
            );
            $upd->bind_param('iii', $take, $batch['id'], $take);
            $upd->execute();
            if ($upd->affected_rows === 0) { $upd->close(); throw new Exception("Race condition on batch {$batch['id']}. Retry."); }
            $upd->close();
            if ($first_batch_id === null) $first_batch_id = $batch['id'];
            $remaining -= $take;
        }

        if ($remaining > 0) throw new Exception("Stock depleted mid-transaction for product #$product_id");

        // Insert sale_item — adapt to actual columns
        if ($hasQtySold && $hasQtyBase && $hasBatchId && $hasUnitSold && $hasVatType) {
            $unit_sold = 'piece';
            $vat_type  = 'vatable';
            $si = $conn->prepare("
                INSERT INTO sale_items (sale_id, product_id, batch_id, unit_sold,
                    quantity_sold, quantity_in_base, unit_price, subtotal, vat_type)
                VALUES (?,?,?,?,?,?,?,?,?)
            ");
            $si->bind_param('iiisiiids', $sale_id, $product_id, $first_batch_id, $unit_sold,
                $quantity_in_base, $quantity_in_base, $unit_price, $subtotal, $vat_type);
        } else {
            // Fallback for simpler sale_items schema
            $si = $conn->prepare("
                INSERT INTO sale_items (sale_id, product_id, quantity, unit_price, subtotal)
                VALUES (?,?,?,?,?)
            ");
            $si->bind_param('iiidd', $sale_id, $product_id, $quantity_in_base, $unit_price, $subtotal);
        }
        $si->execute();
        $si->close();
    }

    // ── Activity log ──────────────────────────────────────────────────────────
    $log_desc = "Mobile Sale #$sale_id | Total: ₱" . number_format($grand, 2);
    $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'Sale', ?)");
    $log->bind_param('is', $user_id, $log_desc);
    $log->execute();
    $log->close();

    $conn->commit();

    echo json_encode([
        'success'        => true,
        'sale_id'        => $sale_id,
        'receipt_number' => $pos_sn . '-' . $sale_id,
        'message'        => "Sale #$sale_id completed!",
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}