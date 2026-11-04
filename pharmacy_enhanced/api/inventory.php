<?php
/**
 * api/inventory.php
 * GET  /inventory.php               → list products (with stock + batch info)
 * GET  /inventory.php?id=N          → single product detail
 * GET  /inventory.php?action=archived → archived products
 * POST /inventory.php               → add / edit / delete / archive product
 *                                     POST /inventory.php?action=categories
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_middleware.php';

$authUser = validateToken();
$userId   = $authUser['user_id'];

// ── Categories sub-resource ──────────────────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'categories') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $res  = $conn->query("SELECT * FROM categories ORDER BY name ASC");
        $cats = [];
        while ($r = $res->fetch_assoc()) $cats[] = $r;
        echo json_encode(['success' => true, 'categories' => $cats]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data   = json_decode(file_get_contents('php://input'), true);
        $action = $data['action'] ?? '';

        if ($action === 'add_category') {
            $name = trim($data['name'] ?? '');
            $desc = trim($data['description'] ?? '');
            if (empty($name)) { echo json_encode(['success'=>false,'message'=>'Name required']); exit; }
            $stmt = $conn->prepare("INSERT INTO categories (name,description) VALUES (?,?)");
            $stmt->bind_param("ss", $name, $desc);
            $ok = $stmt->execute(); $stmt->close();
            logActivity($conn, $userId, 'Add Category', "Added category: $name");
            echo json_encode(['success' => $ok]);
            exit;
        }

        if ($action === 'edit_category') {
            $id   = (int)($data['id'] ?? 0);
            $name = trim($data['name'] ?? '');
            $desc = trim($data['description'] ?? '');
            $stmt = $conn->prepare("UPDATE categories SET name=?,description=? WHERE id=?");
            $stmt->bind_param("ssi", $name, $desc, $id);
            $ok = $stmt->execute(); $stmt->close();
            logActivity($conn, $userId, 'Edit Category', "Edited category ID: $id");
            echo json_encode(['success' => $ok]);
            exit;
        }

        if ($action === 'delete_category') {
            $id   = (int)($data['id'] ?? 0);
            $stmt = $conn->prepare("DELETE FROM categories WHERE id=?");
            $stmt->bind_param("i", $id);
            $ok = $stmt->execute(); $stmt->close();
            logActivity($conn, $userId, 'Delete Category', "Deleted category ID: $id");
            echo json_encode(['success' => $ok]);
            exit;
        }
    }
    echo json_encode(['success' => false, 'message' => 'Unknown request']);
    exit;
}

// ── GET: archived products ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'archived') {
    // Ensure archive columns exist
    $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0");
    $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS archived_at DATETIME DEFAULT NULL");
    $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS archived_by INT DEFAULT NULL");

    $res = $conn->query("
        SELECT p.*, c.name AS category_name,
               COALESCE((
                   SELECT SUM(sb.quantity_base)
                   FROM stock_batches sb
                   WHERE sb.product_id = p.id
               ), 0) AS total_stock,
               u.full_name AS archived_by_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN users u ON p.archived_by = u.id
        WHERE p.is_archived = 1
        ORDER BY p.archived_at DESC
    ");
    $archived = [];
    if ($res) { while ($r = $res->fetch_assoc()) $archived[] = $r; }
    echo json_encode(['success' => true, 'archived' => $archived]);
    exit;
}

// ── Single product ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id'])) {
    $id   = (int)$_GET['id'];
    $stmt = $conn->prepare("
        SELECT p.*, c.name AS category_name,
               COALESCE((
                   SELECT SUM(sb.quantity_base) FROM stock_batches sb
                   WHERE sb.product_id = p.id
                     AND sb.expiration_date >= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
               ),0) AS available_stock
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$product) { echo json_encode(['success'=>false,'message'=>'Not found']); exit; }

    // batches
    $bStmt = $conn->prepare("
        SELECT * FROM stock_batches WHERE product_id = ? ORDER BY expiration_date ASC
    ");
    $bStmt->bind_param("i", $id);
    $bStmt->execute();
    $bRes    = $bStmt->get_result();
    $batches = [];
    while ($r = $bRes->fetch_assoc()) $batches[] = $r;
    $bStmt->close();

    // units
    $uStmt = $conn->prepare("SELECT * FROM product_units WHERE product_id = ? ORDER BY sort_order");
    $uStmt->bind_param("i", $id);
    $uStmt->execute();
    $uRes  = $uStmt->get_result();
    $units = [];
    while ($r = $uRes->fetch_assoc()) $units[] = $r;
    $uStmt->close();

    echo json_encode(['success'=>true,'product'=>$product,'batches'=>$batches,'units'=>$units]);
    exit;
}

// ── Product list ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $search   = trim($_GET['search'] ?? '');
    $category = (int)($_GET['category_id'] ?? 0);
    $filter   = $_GET['filter'] ?? 'all';

    $having = '';
    switch ($filter) {
        case 'low_stock':
            $having = "HAVING available_stock > 0 AND available_stock <= p.low_stock_threshold"; break;
        case 'near_expiry':
            $having = "HAVING near_expiry_count > 0"; break;
        case 'expired':
            $having = "HAVING expired_count > 0"; break;
        case 'out_of_stock':
            $having = "HAVING available_stock = 0"; break;
    }

    $where = "WHERE p.is_archived = 0";
    $params = [];
    $types  = '';
    if ($search) { $where .= " AND (p.brand_name LIKE ? OR p.generic_name LIKE ? OR p.barcode LIKE ?)"; $like="%$search%"; $params=[$like,$like,$like]; $types='sss'; }
    if ($category) { $where .= " AND p.category_id = ?"; $params[]=$category; $types.='i'; }

    $sql = "
        SELECT p.*, c.name AS category_name,
               COALESCE((
                   SELECT SUM(sb.quantity_base) FROM stock_batches sb
                   WHERE sb.product_id = p.id
                     AND sb.expiration_date >= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
               ),0) AS available_stock,
               (SELECT COUNT(*) FROM stock_batches sb WHERE sb.product_id = p.id
                AND sb.expiration_date > CURDATE()
                AND sb.expiration_date < DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
                AND sb.quantity_base > 0) AS near_expiry_count,
               (SELECT COUNT(*) FROM stock_batches sb WHERE sb.product_id = p.id
                AND sb.expiration_date <= CURDATE() AND sb.quantity_base > 0) AS expired_count
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        $where
        GROUP BY p.id
        $having
        ORDER BY p.brand_name ASC
        LIMIT 200
    ";

    $stmt = $conn->prepare($sql);
    if ($types) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res      = $stmt->get_result();
    $products = [];
    while ($r = $res->fetch_assoc()) $products[] = $r;
    $stmt->close();

    echo json_encode(['success'=>true,'products'=>$products,'count'=>count($products)]);
    exit;
}

// ── POST: add / edit / delete / archive product ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data   = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';

    // ── ADD PRODUCT ──────────────────────────────────────────────────────────
    if ($action === 'add_product') {
        $brand_name   = trim($data['brand_name'] ?? '');
        $generic_name = trim($data['generic_name'] ?? '');
        $category_id  = (int)($data['category_id'] ?? 0);
        $dosage_form  = trim($data['dosage_form'] ?? '');
        $strength     = trim($data['strength'] ?? '');
        $barcode      = trim($data['barcode'] ?? '') ?: null;
        $base_unit    = trim($data['base_unit'] ?? 'piece');
        $price        = (float)($data['price'] ?? 0);
        $cost         = (float)($data['cost'] ?? 0);
        $threshold    = max(1, (int)($data['low_stock_threshold'] ?? 10));
        $vat_type     = trim($data['vat_type'] ?? 'vatable');
        $purchase_unit   = trim($data['purchase_unit'] ?? $base_unit);
        $conv_factor     = max(1, (int)($data['conversion_factor'] ?? 1));
        $purchase_qty    = (int)($data['purchase_qty'] ?? 0);

        if (empty($brand_name)) {
            echo json_encode(['success' => false, 'message' => 'Product name required']); exit;
        }
        if (!$category_id) {
            echo json_encode(['success' => false, 'message' => 'Category required']); exit;
        }

        $stmt = $conn->prepare("
            INSERT INTO products
                (brand_name, generic_name, category_id, dosage_form, strength,
                 barcode, base_unit, price, cost, low_stock_threshold, vat_type)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "ssississdis",
            $brand_name, $generic_name, $category_id, $dosage_form, $strength,
            $barcode, $base_unit, $price, $cost, $threshold, $vat_type
        );
        $ok = $stmt->execute();
        $new_id = $conn->insert_id;
        $stmt->close();

        if (!$ok) {
            echo json_encode(['success' => false, 'message' => $conn->error]); exit;
        }

        if ($purchase_qty > 0 && $new_id) {
            $exp_date  = date('Y-m-d', strtotime('+1 year'));
            $batch_num = 'BATCH-' . date('Ymd') . '-' . $new_id;
            $qty_base  = $purchase_qty * $conv_factor;
            $sb = $conn->prepare("
                INSERT INTO stock_batches
                    (product_id, batch_number, quantity_base, expiration_date, received_unit, received_qty)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $sb->bind_param("isisis", $new_id, $batch_num, $qty_base, $exp_date, $purchase_unit, $purchase_qty);
            $sb->execute();
            $sb->close();
        }

        logActivity($conn, $userId, 'Add Product', "Added product: $brand_name", $new_id);
        echo json_encode(['success' => true, 'id' => $new_id, 'message' => 'Product added successfully']);
        exit;
    }

    // ── ADD CATEGORY ─────────────────────────────────────────────────────────
    if ($action === 'add_category') {
        $name = trim($data['name'] ?? '');
        $desc = trim($data['description'] ?? '');
        if (empty($name)) {
            echo json_encode(['success' => false, 'message' => 'Category name required']); exit;
        }
        $chk = $conn->prepare("SELECT id FROM categories WHERE name = ? LIMIT 1");
        $chk->bind_param("s", $name);
        $chk->execute();
        $chk->store_result();
        if ($chk->num_rows > 0) {
            $chk->close();
            echo json_encode(['success' => false, 'message' => "Category \"$name\" already exists"]); exit;
        }
        $chk->close();
        $stmt = $conn->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
        $stmt->bind_param("ss", $name, $desc);
        $ok = $stmt->execute();
        $stmt->close();
        if ($ok) logActivity($conn, $userId, 'Add Category', "Added category: $name");
        echo json_encode(['success' => $ok, 'message' => $ok ? "Category \"$name\" added" : $conn->error]);
        exit;
    }

    // ── DELETE ───────────────────────────────────────────────────────────────
    if ($action === 'delete_product') {
        $id   = (int)($data['id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM products WHERE id=?");
        $stmt->bind_param("i", $id);
        $ok = $stmt->execute(); $stmt->close();
        if ($ok) logActivity($conn, $userId, 'Delete Product', "Deleted product ID: $id", $id);
        echo json_encode(['success' => $ok]);
        exit;
    }

    // ── EDIT ─────────────────────────────────────────────────────────────────
    if ($action === 'edit_product') {
        $id           = (int)($data['id'] ?? 0);
        $brand_name   = trim($data['brand_name'] ?? '');
        $generic_name = trim($data['generic_name'] ?? '');
        $category_id  = (int)($data['category_id'] ?? 0);
        $base_unit    = trim($data['base_unit'] ?? 'piece');
        $price        = (float)($data['price'] ?? 0);
        $cost         = (float)($data['cost'] ?? 0);
        $threshold    = max(1, (int)($data['low_stock_threshold'] ?? 10));
        $barcode      = trim($data['barcode'] ?? '') ?: null;

        $stmt = $conn->prepare("
            UPDATE products SET brand_name=?,generic_name=?,category_id=?,
            base_unit=?,price=?,cost=?,low_stock_threshold=?,barcode=? WHERE id=?
        ");
        $stmt->bind_param("ssiidddsi", $brand_name,$generic_name,$category_id,
                          $base_unit,$price,$cost,$threshold,$barcode,$id);
        $ok = $stmt->execute(); $stmt->close();
        if ($ok) logActivity($conn, $userId, 'Edit Product', "Edited: $brand_name (ID: $id)", $id);
        echo json_encode(['success' => $ok]);
        exit;
    }

    // ── ARCHIVE PRODUCT ───────────────────────────────────────────────────────
    if ($action === 'archive_product') {
        $id = (int)($data['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
        // Ensure columns exist
        $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS is_archived TINYINT(1) NOT NULL DEFAULT 0");
        $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS archived_at DATETIME DEFAULT NULL");
        $conn->query("ALTER TABLE products ADD COLUMN IF NOT EXISTS archived_by INT DEFAULT NULL");
        $stmt = $conn->prepare("UPDATE products SET is_archived=1, archived_at=NOW(), archived_by=? WHERE id=?");
        $stmt->bind_param("ii", $userId, $id);
        $ok = $stmt->execute(); $stmt->close();
        if ($ok) logActivity($conn, $userId, 'Archive Product', "Archived product ID: $id", $id);
        echo json_encode(['success' => $ok]);
        exit;
    }

    // ── UNARCHIVE PRODUCT ─────────────────────────────────────────────────────
    if ($action === 'unarchive_product') {
        $id = (int)($data['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
        $stmt = $conn->prepare("UPDATE products SET is_archived=0, archived_at=NULL, archived_by=NULL WHERE id=?");
        $stmt->bind_param("i", $id);
        $ok = $stmt->execute(); $stmt->close();
        if ($ok) logActivity($conn, $userId, 'Restore Product', "Restored product ID: $id", $id);
        echo json_encode(['success' => $ok]);
        exit;
    }

    // ── ADD STOCK BATCH ───────────────────────────────────────────────────────
    if ($action === 'add_stock') {
        $product_id    = (int)($data['product_id'] ?? 0);
        $purchase_unit = trim($data['purchase_unit'] ?? '');
        $purchase_qty  = max(1, (int)($data['purchase_qty'] ?? 1));
        $conv_factor   = max(1, (int)($data['conversion_factor'] ?? 1));
        $purchase_cost = (float)($data['purchase_cost'] ?? 0);
        $damage_qty    = max(0, (int)($data['damage_quantity'] ?? 0));
        $selling_price = (isset($data['selling_price']) && $data['selling_price'] !== '') ? (float)$data['selling_price'] : null;
        $expiry        = trim($data['expiration_date'] ?? '');
        $date_received = trim($data['date_received'] ?? date('Y-m-d'));

        if (!$product_id || $purchase_qty <= 0 || empty($expiry)) {
            echo json_encode(['success'=>false,'message'=>'Invalid stock data']); exit;
        }

        $pr = $conn->prepare("SELECT base_unit, price FROM products WHERE id=? LIMIT 1");
        $pr->bind_param("i", $product_id);
        $pr->execute();
        $product_row = $pr->get_result()->fetch_assoc();
        $pr->close();
        $base_unit = $product_row['base_unit'] ?? 'piece';
        if (empty($purchase_unit)) $purchase_unit = $base_unit;

        $quantity_base = $purchase_qty * $conv_factor;
        $available_qty = max(0, $quantity_base - $damage_qty);
        $cost_per_base = $conv_factor > 0 ? round($purchase_cost / $conv_factor, 4) : $purchase_cost;

        if ($selling_price === null) {
            $lbq = $conn->prepare("SELECT selling_price FROM stock_batches WHERE product_id=? AND selling_price IS NOT NULL ORDER BY date_added DESC, id DESC LIMIT 1");
            $lbq->bind_param("i", $product_id);
            $lbq->execute();
            $lb = $lbq->get_result()->fetch_assoc();
            $lbq->close();
            $selling_price = $lb ? (float)$lb['selling_price'] : (float)($product_row['price'] ?? 0);
        }
        if ($selling_price <= $cost_per_base && $cost_per_base > 0) {
            $selling_price = round($cost_per_base * 1.20, 2);
        }

        $batch_num = 'BATCH-' . date('Ymd') . '-' . $product_id . '-' . rand(100,999);

        $stmt = $conn->prepare("
            INSERT INTO stock_batches
            (product_id, batch_number, quantity_base, expiration_date, received_unit, received_qty,
             purchase_cost, selling_price, damage_quantity, available_quantity, date_added)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ");
        // quantity_base is set to available_qty (base minus damage) because POS
        // deducts from quantity_base during sales — damaged units must not be included.
        $stmt->bind_param("isissiddiis",
            $product_id, $batch_num, $available_qty, $expiry, $purchase_unit, $purchase_qty,
            $cost_per_base, $selling_price, $damage_qty, $available_qty, $date_received
        );
        $ok = $stmt->execute(); $stmt->close();
        if ($ok) {
            $desc = "Restocked {$purchase_qty} {$purchase_unit} (x{$conv_factor} = {$quantity_base} base), {$damage_qty} damaged, {$available_qty} available";
            logActivity($conn, $userId, 'Add Stock', $desc, $product_id);
        }
        echo json_encode(['success' => $ok, 'batch_number' => $batch_num]);
        exit;
    }

    // ── REMOVE BATCH ─────────────────────────────────────────────────────────
    if ($action === 'remove_batch') {
        $id = (int)($data['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }
        $stmt = $conn->prepare("UPDATE stock_batches SET quantity_base=0, available_quantity=0 WHERE id=?");
        $stmt->bind_param("i", $id);
        $ok = $stmt->execute(); $stmt->close();
        if ($ok) logActivity($conn, $userId, 'Remove Batch', "Removed batch ID: $id");
        echo json_encode(['success' => $ok]);
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action']);
    exit;
}

// ── GET: expiry batches ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'expiry_batches') {
    $filter = $_GET['filter'] ?? 'all';
    $extra  = '';
    switch ($filter) {
        case 'expired':    $extra = "AND sb.expiration_date <= CURDATE()"; break;
        case 'near_expiry':$extra = "AND sb.expiration_date > CURDATE() AND sb.expiration_date < DATE_ADD(CURDATE(), INTERVAL 1 MONTH)"; break;
        case 'good':       $extra = "AND sb.expiration_date >= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)"; break;
    }
    $res = $conn->query("
        SELECT sb.id, sb.batch_number, sb.quantity_base, sb.expiration_date,
               COALESCE(sb.damage_quantity, 0) AS damage_quantity,
               COALESCE(sb.available_quantity, sb.quantity_base - COALESCE(sb.damage_quantity,0)) AS available_quantity,
               COALESCE(sb.available_quantity, sb.quantity_base - COALESCE(sb.damage_quantity,0)) AS sellable_quantity,
               sb.selling_price,
               DATEDIFF(sb.expiration_date, CURDATE()) AS days_left,
               p.brand_name, p.generic_name,
               CASE
                   WHEN sb.expiration_date <= CURDATE() THEN 'expired'
                   WHEN sb.expiration_date < DATE_ADD(CURDATE(), INTERVAL 1 MONTH) THEN 'near_expiry'
                   ELSE 'good'
               END AS status
        FROM stock_batches sb
        JOIN products p ON p.id = sb.product_id
        WHERE sb.quantity_base > 0
          AND p.is_archived = 0
          $extra
        ORDER BY
            CASE WHEN sb.expiration_date <= CURDATE() THEN 1
                 WHEN sb.expiration_date < DATE_ADD(CURDATE(), INTERVAL 1 MONTH) THEN 2
                 ELSE 3 END,
            sb.expiration_date ASC
        LIMIT 300
    ");
    $batches = [];
    if ($res) { while ($r = $res->fetch_assoc()) $batches[] = $r; }
    echo json_encode(['success'=>true, 'batches'=>$batches]);
    exit;
}

http_response_code(405);
echo json_encode(['success'=>false,'message'=>'Method not allowed']);