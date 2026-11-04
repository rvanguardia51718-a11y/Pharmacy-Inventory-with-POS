<?php
/**
 * actions/pos_process.php
 * FIFO sale processor — correct per-batch pricing, correct totals, correct receipt
 *
 * PRICE RULES:
 *  - selling_price in stock_batches = price per BASE UNIT (e.g. ₱26/tablet)
 *  - unit_price in sale_items       = price per SOLD UNIT (e.g. ₱2,600/box)
 *  - purchase_cost_snapshot         = cost per BASE UNIT (for COGS)
 *  - COGS    = quantity_in_base × purchase_cost_snapshot
 *  - Revenue = quantity_sold × unit_price
 *  - Grand Total recalculated from real FIFO subtotals (not client estimate)
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/inventory_helper.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

$input = file_get_contents('php://input');
$data  = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

if (!$data || empty($data['items'])) {
    echo json_encode(['success' => false, 'message' => 'Empty cart']);
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
if ($user_id === 0) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated']);
    exit;
}

$cash     = (float)($data['cash_received'] ?? 0);
$discount = (float)($data['discount']      ?? 0);
$pos_sn   = 'S4-' . rand(10000000, 99999999);
$tm_num   = 'TM#' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);

try {
    $conn->begin_transaction();

    // ── STEP 1: Validate stock ─────────────────────────────────────────────────
    foreach ($data['items'] as $item) {
        $product_id       = (int)($item['id'] ?? 0);
        $quantity_in_base = (int)($item['quantity_in_base'] ?? $item['quantity_sold'] ?? 1);

        if ($quantity_in_base <= 0) {
            throw new Exception("Invalid quantity for product ID $product_id");
        }

        $avail_stmt = $conn->prepare("
            SELECT COALESCE(SUM(COALESCE(available_quantity, quantity_base - COALESCE(damage_quantity,0))), 0) AS total
            FROM stock_batches
            WHERE product_id = ? AND quantity_base > 0
              AND expiration_date > DATE_ADD(CURDATE(), INTERVAL 3 MONTH)
        ");
        $avail_stmt->bind_param('i', $product_id);
        $avail_stmt->execute();
        $avail = (int)$avail_stmt->get_result()->fetch_assoc()['total'];
        $avail_stmt->close();

        if ($quantity_in_base > $avail) {
            $pn = $conn->prepare("SELECT brand_name FROM products WHERE id = ?");
            $pn->bind_param('i', $product_id);
            $pn->execute();
            $pname = $pn->get_result()->fetch_assoc()['brand_name'] ?? "Product #$product_id";
            $pn->close();
            throw new Exception("Insufficient stock for $pname: need $quantity_in_base, only $avail available.");
        }
    }

    // ── STEP 2: FIFO deduction for all items ───────────────────────────────────
    $processed = [];

    foreach ($data['items'] as $item) {
        $product_id       = (int)($item['id'] ?? 0);
        $quantity_sold    = (int)($item['quantity_sold'] ?? $item['quantity'] ?? 1);
        $unit_sold        = trim($item['unit_sold'] ?? 'piece');
        $vat_type         = $conn->real_escape_string($item['vat_type'] ?? 'vatable');
        $quantity_in_base = (int)($item['quantity_in_base'] ?? 0);

        if ($quantity_in_base <= 0) {
            $quantity_in_base = !empty($unit_sold)
                ? toBaseUnits($conn, $product_id, $quantity_sold, $unit_sold)
                : $quantity_sold;
        }

        // conversion = base units per sold unit  (e.g. 100 tablets per box)
        $conversion = ($quantity_sold > 0 && $quantity_in_base > 0)
            ? max(1, intval(round($quantity_in_base / $quantity_sold)))
            : 1;

        $deductions    = deductStockFIFO($conn, $product_id, $quantity_in_base);
        $item_subtotal = array_sum(array_column($deductions, 'subtotal'));

        $processed[] = compact(
            'product_id','quantity_sold','quantity_in_base',
            'unit_sold','vat_type','conversion','deductions','item_subtotal'
        );
    }

    // ── STEP 3: Recalculate totals from real FIFO subtotals ────────────────────
    $actual_vatable    = 0.0;
    $actual_vat_exempt = 0.0;
    $actual_vat_zero   = 0.0;

    foreach ($processed as $pi) {
        $vt = $pi['vat_type'];
        if     ($vt === 'exempt')     $actual_vat_exempt += $pi['item_subtotal'];
        elseif ($vt === 'zero_rated') $actual_vat_zero   += $pi['item_subtotal'];
        else                          $actual_vatable     += $pi['item_subtotal'];
    }

    // VAT-inclusive pricing: subtotals already include 12% VAT
    $actual_vat_base   = round($actual_vatable / 1.12, 2);
    $actual_vat_amount = round($actual_vatable - $actual_vat_base, 2);
    $actual_grand      = round($actual_vatable + $actual_vat_exempt + $actual_vat_zero, 2);
    $actual_change     = max(0, round($cash - $actual_grand, 2));

    // ── STEP 4: Insert sales header ────────────────────────────────────────────
    $stmt = $conn->prepare("
        INSERT INTO sales (
            user_id, total_amount, discount, tax, grand_total,
            cash_received, change_amount, vat_sales, vat_exempt_sales,
            vat_zero_rated_sales, vat_amount, pos_sn, tm_number
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        'idddddddddsss',
        $user_id, $actual_grand, $discount, $actual_vat_amount, $actual_grand,
        $cash, $actual_change, $actual_vat_base, $actual_vat_exempt,
        $actual_vat_zero, $actual_vat_amount, $pos_sn, $tm_num
    );
    $stmt->execute();
    $sale_id = $conn->insert_id;
    $stmt->close();

    // ── STEP 5: Insert sale_items + build receipt ──────────────────────────────
    $receipt_items = [];

    foreach ($processed as $pi) {
        $product_id       = $pi['product_id'];
        $quantity_sold    = $pi['quantity_sold'];
        $quantity_in_base = $pi['quantity_in_base'];
        $unit_sold        = $pi['unit_sold'];
        $vat_type         = $pi['vat_type'];
        $conversion       = $pi['conversion'];
        $deductions       = $pi['deductions'];
        $item_subtotal    = $pi['item_subtotal'];

        foreach ($deductions as $ded) {
            $batch_qty_base = $ded['deducted'];       // base units from this batch
            $price_per_base = $ded['price'];          // ₱/base unit from batch
            $cost_per_base  = $ded['cost_per_base'];  // ₱/base unit cost (for COGS)
            $batch_subtotal = $ded['subtotal'];       // batch_qty_base × price_per_base

            // qty_sold for this row = base units from this batch ÷ conversion
            $batch_qty_sold = max(1, intval(round($batch_qty_base / $conversion)));

            // unit_price = price per SOLD UNIT
            // 1 box @ ₱26/tablet × 100 tablets/box = ₱2,600/box  ✅
            $unit_price = round($price_per_base * $conversion, 4);

            $is = $conn->prepare("
                INSERT INTO sale_items
                    (sale_id, product_id, batch_id, unit_sold,
                     quantity_sold, quantity_in_base, unit_price,
                     purchase_cost_snapshot, subtotal, vat_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $is->bind_param(
                'iiisiiidds',
                $sale_id, $product_id, $ded['batch_id'], $unit_sold,
                $batch_qty_sold, $batch_qty_base,
                $unit_price, $cost_per_base,
                $batch_subtotal, $vat_type
            );
            $is->execute();
            $is->close();
        }

        // Product name for receipt
        $pd = $conn->prepare("SELECT brand_name, generic_name FROM products WHERE id = ?");
        $pd->bind_param("i", $product_id);
        $pd->execute();
        $product = $pd->get_result()->fetch_assoc();
        $pd->close();

        // Receipt: show total item price ÷ quantity_sold as unit price
        // e.g. 1 box = ₱2,630 total → ₱2,630/box  ✅
        // e.g. 91 tablets (2 batches) = ₱920 ÷ 91 = ₱10.11/tablet
        $receipt_unit_price = $quantity_sold > 0
            ? round($item_subtotal / $quantity_sold, 4)
            : 0;

        $receipt_items[] = [
            'name'          => $product['brand_name'],
            'generic_name'  => $product['generic_name'],
            'quantity_sold' => $quantity_sold,
            'unit_sold'     => $unit_sold,
            'unit_price'    => $receipt_unit_price,
            'subtotal'      => $item_subtotal,
            'vat_type'      => $vat_type,
            'deductions'    => $deductions,
        ];
    }

    // ── STEP 6: Log and commit ─────────────────────────────────────────────────
    $log_desc = "Sale #$sale_id | Total: ₱" . number_format($actual_grand, 2);
    $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'Sale', ?)");
    $log->bind_param('is', $user_id, $log_desc);
    $log->execute();
    $log->close();

    $conn->commit();

    echo json_encode([
        'success'        => true,
        'sale_id'        => $sale_id,
        'receipt_number' => $pos_sn . '-' . $sale_id,
        'message'        => "Sale #$sale_id completed successfully!",
        'grand_total'    => $actual_grand,
        'receipt_items'  => $receipt_items,
    ]);

} catch (Exception $e) {
    $conn->rollback();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>