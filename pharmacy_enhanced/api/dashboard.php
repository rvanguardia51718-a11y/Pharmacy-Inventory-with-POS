<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_middleware.php';
$user = validateToken();

// ── Shared COGS expression (matches reports system 3-priority formula) ────────
// 1. purchase_cost_snapshot  2. sb.purchase_cost / pu.conversion_to_base  3. p.cost
define('COGS_EXPR',
    "si.quantity_in_base * COALESCE(
        NULLIF(si.purchase_cost_snapshot, 0),
        CASE
            WHEN COALESCE(pu.conversion_to_base, 0) > 0
            THEN sb.purchase_cost / pu.conversion_to_base
            ELSE NULLIF(sb.purchase_cost, 0)
        END,
        NULLIF(p.cost, 0),
        0
    )"
);
define('COGS_JOINS',
    "LEFT JOIN stock_batches sb ON si.batch_id = sb.id
     LEFT JOIN product_units pu
            ON pu.product_id = sb.product_id
           AND LOWER(pu.unit_name) = LOWER(sb.received_unit)"
);



// ── Stock counters ───────────────────────────────────────────────────────────
$low_stock   = $conn->query("SELECT COUNT(*) FROM products p WHERE (SELECT COALESCE(SUM(sb.quantity_base),0) FROM stock_batches sb WHERE sb.product_id=p.id AND sb.expiration_date>=DATE_ADD(CURDATE(),INTERVAL 1 MONTH))>0 AND (SELECT COALESCE(SUM(sb.quantity_base),0) FROM stock_batches sb WHERE sb.product_id=p.id AND sb.expiration_date>=DATE_ADD(CURDATE(),INTERVAL 1 MONTH))<=p.low_stock_threshold")->fetch_row()[0];
$out_of_stock= $conn->query("SELECT COUNT(DISTINCT p.id) FROM products p WHERE NOT EXISTS(SELECT 1 FROM stock_batches sb WHERE sb.product_id=p.id AND sb.quantity_base>0 AND sb.expiration_date>=DATE_ADD(CURDATE(),INTERVAL 1 MONTH)) AND EXISTS(SELECT 1 FROM stock_batches sb WHERE sb.product_id=p.id)")->fetch_row()[0];
$near_expiry = $conn->query("SELECT COUNT(*) FROM stock_batches WHERE expiration_date>CURDATE() AND expiration_date<DATE_ADD(CURDATE(),INTERVAL 1 MONTH) AND quantity_base>0")->fetch_row()[0];
$expired_items=$conn->query("SELECT COUNT(*) FROM stock_batches WHERE expiration_date<=CURDATE() AND quantity_base>0")->fetch_row()[0];
$total_products=$conn->query("SELECT COUNT(*) FROM products")->fetch_row()[0];
$total_categories=$conn->query("SELECT COUNT(*) FROM categories")->fetch_row()[0];
$total_users=$conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
$good_stock=$conn->query("SELECT COUNT(DISTINCT p.id) FROM products p WHERE (SELECT COALESCE(SUM(sb.quantity_base),0) FROM stock_batches sb WHERE sb.product_id=p.id AND sb.expiration_date>=DATE_ADD(CURDATE(),INTERVAL 1 MONTH))>p.low_stock_threshold")->fetch_row()[0];

// ── Today's stats ────────────────────────────────────────────────────────────
$today_sales        = $conn->query("SELECT COALESCE(SUM(grand_total),0) FROM sales WHERE DATE(sale_date)=CURDATE()")->fetch_row()[0];
$today_transactions = $conn->query("SELECT COUNT(*) FROM sales WHERE DATE(sale_date)=CURDATE()")->fetch_row()[0];

// Today's profit — revenue is grand_total (matches reports: grand_total - cogs)
$profit_res = $conn->query("
    SELECT COALESCE(SUM(sale_profit), 0) AS profit
    FROM (
        SELECT s.grand_total - COALESCE(SUM(
            ROUND(si.quantity_in_base * COALESCE(
                NULLIF(si.purchase_cost_snapshot, 0),
                CASE
                    WHEN COALESCE(pu.conversion_to_base, 0) > 0
                    THEN sb.purchase_cost / pu.conversion_to_base
                    ELSE NULLIF(sb.purchase_cost, 0)
                END,
                NULLIF(p.cost, 0),
                0
            ), 2)
        ), 0) AS sale_profit
        FROM sales s
        LEFT JOIN sale_items si ON si.sale_id    = s.id
        LEFT JOIN products p    ON si.product_id = p.id
        LEFT JOIN stock_batches sb ON si.batch_id = sb.id
        LEFT JOIN product_units pu
               ON pu.product_id  = sb.product_id
              AND LOWER(pu.unit_name) = LOWER(sb.received_unit)
        WHERE DATE(s.sale_date) = CURDATE()
        GROUP BY s.id, s.grand_total
    ) t
");
$today_profit = $profit_res ? (float)$profit_res->fetch_row()[0] : 0;

// ── Weekly revenue + profit (last 7 days) ────────────────────────────────────
$weekly_res = $conn->query("
    SELECT DATE(s.sale_date) AS date,
           SUM(s.grand_total) AS revenue,
           SUM(s.grand_total - COALESCE(sale_cogs.cogs, 0)) AS profit
    FROM sales s
    LEFT JOIN (
        SELECT si.sale_id,
               SUM(
                   ROUND(si.quantity_in_base * COALESCE(
                       NULLIF(si.purchase_cost_snapshot, 0),
                       CASE
                           WHEN COALESCE(pu.conversion_to_base, 0) > 0
                           THEN sb.purchase_cost / pu.conversion_to_base
                           ELSE NULLIF(sb.purchase_cost, 0)
                       END,
                       NULLIF(p.cost, 0),
                       0
                   ), 2)
               ) AS cogs
        FROM sale_items si
        JOIN products p ON si.product_id = p.id
        LEFT JOIN stock_batches sb ON si.batch_id = sb.id
        LEFT JOIN product_units pu
               ON pu.product_id  = sb.product_id
              AND LOWER(pu.unit_name) = LOWER(sb.received_unit)
        GROUP BY si.sale_id
    ) sale_cogs ON sale_cogs.sale_id = s.id
    WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY DATE(s.sale_date) ORDER BY date ASC
");
$weekly_map = [];
while ($row = $weekly_res->fetch_assoc()) {
    $weekly_map[$row['date']] = ['revenue' => (float)$row['revenue'], 'profit' => (float)$row['profit']];
}
$weekly_data = [];
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $weekly_data[] = [
        'label'   => date('D', strtotime($date)),
        'date'    => $date,
        'total'   => $weekly_map[$date]['revenue'] ?? 0,   // keep 'total' for compat
        'revenue' => $weekly_map[$date]['revenue'] ?? 0,
        'profit'  => $weekly_map[$date]['profit']  ?? 0,
    ];
}

// ── Today's transactions table ───────────────────────────────────────────────
$txn_res = $conn->query("
    SELECT s.id, s.grand_total, s.sale_date, u.full_name AS staff,
           GROUP_CONCAT(p.brand_name ORDER BY p.brand_name SEPARATOR ', ') AS products,
           SUM(si.quantity_in_base) AS total_qty,
           ROUND(s.grand_total - COALESCE(SUM(
               ROUND(si.quantity_in_base * COALESCE(
                   NULLIF(si.purchase_cost_snapshot, 0),
                   CASE
                       WHEN COALESCE(pu.conversion_to_base, 0) > 0
                       THEN sb.purchase_cost / pu.conversion_to_base
                       ELSE NULLIF(sb.purchase_cost, 0)
                   END,
                   NULLIF(p.cost, 0),
                   0
               ), 2)
           ), 0), 2) AS profit
    FROM sales s
    JOIN users u ON s.user_id = u.id
    LEFT JOIN sale_items si ON si.sale_id    = s.id
    LEFT JOIN products p    ON si.product_id = p.id
    LEFT JOIN stock_batches sb ON si.batch_id = sb.id
    LEFT JOIN product_units pu
           ON pu.product_id  = sb.product_id
          AND LOWER(pu.unit_name) = LOWER(sb.received_unit)
    WHERE DATE(s.sale_date) = CURDATE()
    GROUP BY s.id, s.grand_total ORDER BY s.sale_date DESC LIMIT 20
");
$today_transactions_list = [];
while ($row = $txn_res->fetch_assoc()) {
    $today_transactions_list[] = [
        'id'         => $row['id'],
        'products'   => $row['products'] ?? '-',
        'qty'        => (int)$row['total_qty'],
        'total'      => (float)$row['grand_total'],
        'profit'     => (float)$row['profit'],
        'staff'      => $row['staff'],
        'time'       => date('H:i', strtotime($row['sale_date'])),
    ];
}

// ── Recent logs ───────────────────────────────────────────────────────────────
$logs_result = $conn->query("SELECT l.action, l.description, l.date_time, u.full_name, u.role FROM activity_logs l JOIN users u ON l.user_id=u.id ORDER BY l.date_time DESC LIMIT 8");
$recent_logs = [];
while ($row = $logs_result->fetch_assoc()) $recent_logs[] = $row;

// ── Notifications ─────────────────────────────────────────────────────────────
$notif_result = $conn->query("SELECT * FROM notifications WHERE user_id={$user['user_id']} AND is_read=0 ORDER BY created_at DESC LIMIT 10");
$notifications = [];
while ($row = $notif_result->fetch_assoc()) $notifications[] = $row;

echo json_encode([
    'success' => true,
    'stats'   => [
        'total_products'     => (int)$total_products,
        'total_categories'   => (int)$total_categories,
        'total_users'        => (int)$total_users,
        'low_stock'          => (int)$low_stock,
        'out_of_stock'       => (int)$out_of_stock,
        'near_expiry'        => (int)$near_expiry,
        'expired_items'      => (int)$expired_items,
        'good_stock'         => (int)$good_stock,
        'today_sales'        => (float)$today_sales,
        'today_transactions' => (int)$today_transactions,
        'today_profit'       => (float)$today_profit,
    ],
    'daily_sales'             => $weekly_data,
    'today_transactions_list' => $today_transactions_list,
    'recent_logs'             => $recent_logs,
    'notifications'           => $notifications,
]);