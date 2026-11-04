<?php
/**
 * includes/inventory_helper.php
 * Core inventory logic:
 *  - Unit conversion (any unit → base units)
 *  - FIFO stock deduction by expiration date with per-batch pricing
 *  - Near-expiry blocking (< 3 months cannot be sold)
 *  - Notification + activity log helpers
 */

// ─────────────────────────────────────────────────────────────
// UNIT HELPERS
// ─────────────────────────────────────────────────────────────

/**
 * Get all sellable units for a product, sorted by sort_order ASC.
 * Returns array of ['id', 'unit_name', 'conversion_to_base', 'price_override', 'sort_order']
 */
function getProductUnits(mysqli $conn, int $product_id): array {
    $stmt = $conn->prepare(
        "SELECT * FROM product_units WHERE product_id = ? ORDER BY sort_order ASC, conversion_to_base DESC"
    );
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Convert a quantity in a given unit to base units.
 * Returns int (base units).
 */
function toBaseUnits(mysqli $conn, int $product_id, int $qty, string $unit_name): int {
    $stmt = $conn->prepare(
        "SELECT conversion_to_base FROM product_units WHERE product_id = ? AND unit_name = ?"
    );
    $stmt->bind_param("is", $product_id, $unit_name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return $qty; // fallback: assume 1:1
    return $qty * (int)$row['conversion_to_base'];
}

/**
 * Get the effective selling price for a unit.
 * If price_override is set, use it; otherwise base_price * conversion_to_base.
 */
function getUnitPrice(float $base_price, array $unit_row): float {
    if (!is_null($unit_row['price_override'])) {
        return (float)$unit_row['price_override'];
    }
    return $base_price * (int)$unit_row['conversion_to_base'];
}

// ─────────────────────────────────────────────────────────────
// STOCK QUERIES
// ─────────────────────────────────────────────────────────────

/**
 * Get detailed batch information for a product
 * Returns total_qty, damaged_qty, sellable_qty per batch
 */
function getBatchDetails(mysqli $conn, int $product_id): array {
    $stmt = $conn->prepare("
        SELECT 
            id,
            batch_number,
            expiration_date,
            quantity_base as total_qty,
            COALESCE(damage_quantity, 0) as damaged_qty,
            COALESCE(available_quantity, quantity_base - COALESCE(damage_quantity, 0)) as sellable_qty,
            selling_price,
            purchase_cost,
            CASE 
                WHEN expiration_date <= CURDATE() THEN 'expired'
                WHEN expiration_date < DATE_ADD(CURDATE(), INTERVAL 1 MONTH) THEN 'near_expiry'
                ELSE 'good'
            END as status,
            DATEDIFF(expiration_date, CURDATE()) as days_left
        FROM stock_batches
        WHERE product_id = ?
        ORDER BY expiration_date ASC
    ");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $batches = [];
    while ($row = $result->fetch_assoc()) {
        $batches[] = $row;
    }
    $stmt->close();
    return $batches;
}

/**
 * Get total sellable stock in base units for a product.
 * "Sellable" = not expired AND not within 3 months of expiry.
 */
function getSellableStock(mysqli $conn, int $product_id): int {
    $cutoff = date('Y-m-d', strtotime('+3 months'));
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(COALESCE(available_quantity, quantity_base - COALESCE(damage_quantity, 0))), 0) as total
         FROM stock_batches
         WHERE product_id = ? AND quantity_base > 0 AND expiration_date > ?"
    );
    $stmt->bind_param("is", $product_id, $cutoff);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)$row['total'];
}

/**
 * Get total stock in base units (includes near-expiry, excludes expired).
 * Used for display in inventory.
 */
function getTotalStock(mysqli $conn, int $product_id): int {
    $stmt = $conn->prepare(
        "SELECT COALESCE(SUM(COALESCE(available_quantity, quantity_base - COALESCE(damage_quantity, 0))), 0) as total
         FROM stock_batches
         WHERE product_id = ? AND quantity_base > 0 AND expiration_date >= CURDATE()"
    );
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)$row['total'];
}

/**
 * Validate that a product can be sold (has enough sellable stock).
 * Returns ['ok' => true] or ['ok' => false, 'reason' => '...']
 */
function canSell(mysqli $conn, int $product_id, int $qty_base): array {
    $available = getSellableStock($conn, $product_id);
    if ($available <= 0) {
        return ['ok' => false, 'reason' => 'out_of_stock'];
    }
    if ($qty_base > $available) {
        return ['ok' => false, 'reason' => "Only $available base units available (requested $qty_base)"];
    }
    return ['ok' => true];
}

/**
 * Check if a product has batches with different prices
 */
function hasVaryingPrices(mysqli $conn, int $product_id): bool {
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT selling_price) as price_count
        FROM stock_batches
        WHERE product_id = ? 
        AND selling_price IS NOT NULL
        AND quantity_base > 0
        AND expiration_date > CURDATE()
    ");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    return ($row['price_count'] ?? 0) > 1;
}

// ─────────────────────────────────────────────────────────────
// FIFO STOCK DEDUCTION WITH PER-BATCH PRICING
// ─────────────────────────────────────────────────────────────

/**
 * FIFO deduction of stock_batches with per-batch pricing.
 * Skips batches within 3 months of expiry (cannot be sold).
 * Returns array of ['batch_id' => int, 'deducted' => int, 'price' => float, 'subtotal' => float] on success.
 * Throws Exception on insufficient stock.
 */
function deductStockFIFO(mysqli $conn, int $product_id, int $qty_base): array {
    $cutoff = date('Y-m-d', strtotime('+3 months'));

    $stmt = $conn->prepare("
        SELECT id,
               quantity_base,
               COALESCE(available_quantity, quantity_base - COALESCE(damage_quantity, 0)) AS sellable,
               COALESCE(selling_price, 0)  AS selling_price,
               COALESCE(purchase_cost, 0)  AS purchase_cost,
               COALESCE(received_qty, 1)   AS received_qty
        FROM stock_batches
        WHERE product_id = ? AND quantity_base > 0 AND expiration_date > ?
        ORDER BY expiration_date ASC
    ");
    $stmt->bind_param("is", $product_id, $cutoff);
    $stmt->execute();
    $batches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $remaining  = $qty_base;
    $deductions = [];

    foreach ($batches as $batch) {
        if ($remaining <= 0) break;

        $sellable = (int)$batch['sellable'];
        if ($sellable <= 0) continue;

        $take       = min($remaining, $sellable);
        $sell_price = (float)$batch['selling_price'];  // per base unit
        $pcost      = (float)$batch['purchase_cost'];  // stored as cost per PURCHASE unit
        $rcv_qty    = max(1, (int)$batch['received_qty']);

        // Get the ORIGINAL quantity_base for this batch before any sales
        // = received_qty × conversion_to_base
        // We derive it from product_units to avoid using the depleted quantity_base
        // Fallback: use purchase_cost as-is if received_qty=1 (means already per base unit
        // or batch was added with base unit as purchase unit)
        $orig_qty_stmt = $conn->prepare("
            SELECT conversion_to_base
            FROM product_units
            WHERE product_id = ? AND LOWER(unit_name) = LOWER(
                (SELECT received_unit FROM stock_batches WHERE id = ? LIMIT 1)
            )
            LIMIT 1
        ");
        $orig_qty_stmt->bind_param("ii", $product_id, $batch['id']);
        $orig_qty_stmt->execute();
        $pu_row = $orig_qty_stmt->get_result()->fetch_assoc();
        $orig_qty_stmt->close();

        $conv_factor = $pu_row ? max(1, (int)$pu_row['conversion_to_base']) : 1;

        // cost_per_base = purchase_cost / conversion_factor
        // e.g. ₱500/box ÷ 100 tablets/box = ₱5.00/tablet
        // This uses the ORIGINAL conversion, not the depleted quantity_base
        $cost_per_base = $pcost / $conv_factor;

        $subtotal = $take * $sell_price;
        $cogs     = $take * $cost_per_base;

        $upd = $conn->prepare("
            UPDATE stock_batches
            SET quantity_base      = quantity_base - ?,
                available_quantity = COALESCE(available_quantity, quantity_base - COALESCE(damage_quantity, 0)) - ?
            WHERE id = ? AND quantity_base >= ?
        ");
        $upd->bind_param("iiii", $take, $take, $batch['id'], $take);
        $upd->execute();
        $upd->close();

        $deductions[] = [
            'batch_id'      => (int)$batch['id'],
            'deducted'      => $take,
            'price'         => $sell_price,
            'cost_per_base' => $cost_per_base,
            'cogs'          => $cogs,
            'subtotal'      => $subtotal,
        ];

        $remaining -= $take;
    }

    if ($remaining > 0) {
        throw new Exception("Insufficient sellable stock for product ID $product_id");
    }

    return $deductions;
}

// ─────────────────────────────────────────────────────────────
// ADD STOCK
// ─────────────────────────────────────────────────────────────

/**
 * Add a new stock batch (converts received qty → base units automatically).
 * Returns new batch id.
 */
function addStockBatch(
    mysqli $conn,
    int $product_id,
    string $batch_number,
    int $received_qty,
    string $received_unit,
    string $expiration_date,
    float $purchase_cost = 0,
    float $selling_price = null,
    int $damage_qty = 0
): int {
    $qty_base = toBaseUnits($conn, $product_id, $received_qty, $received_unit);
    $available_qty = $qty_base - $damage_qty;

    $stmt = $conn->prepare("
        INSERT INTO stock_batches
        (product_id, batch_number, quantity_base, expiration_date, received_unit, received_qty,
         purchase_cost, selling_price, damage_quantity, available_quantity)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param(
        "isisidiidi",
        $product_id, $batch_number, $qty_base, $expiration_date, $received_unit, $received_qty,
        $purchase_cost, $selling_price, $damage_qty, $available_qty
    );
    $stmt->execute();
    $id = $conn->insert_id;
    $stmt->close();
    return $id;
}

// ─────────────────────────────────────────────────────────────
// NOTIFICATIONS
// ─────────────────────────────────────────────────────────────

/**
 * Check if a notification already exists and is unread
 */
function notificationExists(mysqli $conn, int $user_id, string $type, ?int $reference_id): bool {
    $stmt = $conn->prepare(
        "SELECT id FROM notifications
         WHERE user_id = ? AND type = ? AND reference_id <=> ? AND is_read = 0
         LIMIT 1"
    );
    $stmt->bind_param("isi", $user_id, $type, $reference_id);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

/**
 * Create a notification if one doesn't already exist unread.
 * Also checks if a read notification was created in the last 24 hours
 * to prevent spam when conditions persist.
 */
function createNotification(
    mysqli $conn,
    int $user_id,
    string $type,
    ?int $reference_id,
    string $message
): void {
    // Don't create if unread notification already exists
    if (notificationExists($conn, $user_id, $type, $reference_id)) {
        return;
    }
    
    // Check if a read notification was created in the last 24 hours
    $check_read = $conn->prepare("
        SELECT id FROM notifications
        WHERE user_id = ? AND type = ? AND reference_id <=> ? 
        AND is_read = 1 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        LIMIT 1
    ");
    $check_read->bind_param("isi", $user_id, $type, $reference_id);
    $check_read->execute();
    $has_recent_read = $check_read->get_result()->num_rows > 0;
    $check_read->close();
    
    if ($has_recent_read) {
        return;
    }

    $ins = $conn->prepare(
        "INSERT INTO notifications (user_id, type, reference_id, message) VALUES (?, ?, ?, ?)"
    );
    $ins->bind_param("isis", $user_id, $type, $reference_id, $message);
    $ins->execute();
    $ins->close();
}

/**
 * Generate inventory-based notifications
 */
function generateInventoryNotifications(mysqli $conn, int $user_id): void {
    // Only admins receive notifications
    $stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || $user['role'] !== 'admin') return;

    // Clean up old read notifications (> 7 days)
    $conn->query("DELETE FROM notifications WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");

    $cutoff1m = date('Y-m-d', strtotime('+1 month'));
    $today    = date('Y-m-d');

    // ── EXPIRED batches ──────────────────────────────────────
    $exp = $conn->query("
        SELECT sb.id, p.brand_name, p.generic_name, p.id as product_id
        FROM stock_batches sb
        JOIN products p ON p.id = sb.product_id
        WHERE sb.expiration_date <= '$today' 
        AND COALESCE(sb.available_quantity, sb.quantity_base - COALESCE(sb.damage_quantity, 0)) > 0
    ");
    while ($row = $exp->fetch_assoc()) {
        createNotification($conn, $user_id, 'expired', (int)$row['id'],
            "❌ {$row['brand_name']} ({$row['generic_name']}) has EXPIRED and cannot be sold."
        );
    }

    // ── NEAR EXPIRY ──────────────────────────────────────────
    $near = $conn->query("
        SELECT sb.id, p.brand_name, p.generic_name, p.id as product_id,
               DATEDIFF(sb.expiration_date, CURDATE()) as days_left
        FROM stock_batches sb
        JOIN products p ON p.id = sb.product_id
        WHERE sb.expiration_date > '$today'
          AND sb.expiration_date < '$cutoff1m'
          AND COALESCE(sb.available_quantity, sb.quantity_base - COALESCE(sb.damage_quantity, 0)) > 0
    ");
    while ($row = $near->fetch_assoc()) {
        $days = $row['days_left'];
        $day_text = $days == 1 ? 'day' : 'days';
        createNotification($conn, $user_id, 'near_expiry', (int)$row['product_id'],
            "⏰ {$row['brand_name']} ({$row['generic_name']}) expires in {$days} {$day_text} — cannot be sold after expiry."
        );
    }

    // ── OUT OF STOCK ─────────────────────────────────────────
    $oos = $conn->query("
        SELECT p.id, p.brand_name, p.generic_name
        FROM products p
        WHERE (
            SELECT COALESCE(SUM(COALESCE(sb.available_quantity, sb.quantity_base - COALESCE(sb.damage_quantity, 0))), 0)
            FROM stock_batches sb
            WHERE sb.product_id = p.id
              AND sb.expiration_date >= '$cutoff1m'
        ) = 0
        AND EXISTS (
            SELECT 1 FROM stock_batches sb 
            WHERE sb.product_id = p.id
        )
    ");
    while ($row = $oos->fetch_assoc()) {
        createNotification($conn, $user_id, 'out_of_stock', (int)$row['id'],
            "📦 {$row['brand_name']} ({$row['generic_name']}) is OUT OF STOCK - no sellable inventory available."
        );
    }

    // ── LOW STOCK ────────────────────────────────────────────
    $low = $conn->query("
        SELECT p.id, p.brand_name, p.generic_name, p.low_stock_threshold, p.base_unit,
               (
                SELECT COALESCE(SUM(COALESCE(sb.available_quantity, sb.quantity_base - COALESCE(sb.damage_quantity, 0))), 0)
                FROM stock_batches sb
                WHERE sb.product_id = p.id
                  AND sb.expiration_date >= '$cutoff1m'
               ) as sellable
        FROM products p
        HAVING sellable > 0 AND sellable <= p.low_stock_threshold
    ");
    while ($row = $low->fetch_assoc()) {
        createNotification($conn, $user_id, 'low_stock', (int)$row['id'],
            "⚠️ {$row['brand_name']} ({$row['generic_name']}) is LOW ON STOCK: {$row['sellable']} {$row['base_unit']} remaining (threshold: {$row['low_stock_threshold']})."
        );
    }
}

// ─────────────────────────────────────────────────────────────
// BATCH NUMBER GENERATION
// ─────────────────────────────────────────────────────────────

/**
 * Generate a unique batch number in format: YYYYMMDD-XXX
 */
function generateBatchNumber(mysqli $conn, ?int $product_id = null): string {
    $date = date('Ymd');
    $max_attempts = 10;
    
    for ($i = 0; $i < $max_attempts; $i++) {
        $random = mt_rand(100, 999);
        $batch_number = $date . '-' . $random;
        
        $stmt = $conn->prepare("SELECT id FROM stock_batches WHERE batch_number = ?");
        $stmt->bind_param("s", $batch_number);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();
        
        if (!$exists) {
            return $batch_number;
        }
    }
    
    return $date . '-' . mt_rand(1000, 9999);
}
?>