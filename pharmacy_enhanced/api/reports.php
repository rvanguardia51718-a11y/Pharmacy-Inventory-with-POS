<?php
/**
 * api/reports.php
 * GET /reports.php                              → full sales report data
 * GET /reports.php?action=poll_new&last_id=N   → new sales since ID N (real-time)
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth_middleware.php';

validateToken();

// ── poll_new: return new sales since last_id ──────────────────────────────────
$action = trim($_GET['action'] ?? '');
if ($action === 'poll_new') {
    $last_id      = (int)($_GET['last_id'] ?? 0);
    $payload      = validateToken(); // already validated above, call again for payload
    $auth_user_id = (int)($payload['user_id'] ?? 0);
    $auth_role    = $payload['role'] ?? 'staff';

    $where  = "WHERE s.id > ?";
    $params = [$last_id];
    $types  = "i";
    if ($auth_role !== 'admin') {
        $where  .= " AND s.user_id = ?";
        $params[] = $auth_user_id;
        $types   .= "i";
    }

    $stmt = $conn->prepare("
        SELECT s.id, s.total_amount, s.discount, s.grand_total,
               s.cash_received, s.change_amount, s.sale_date,
               u.full_name
        FROM sales s
        JOIN users u ON s.user_id = u.id
        $where
        ORDER BY s.id DESC
        LIMIT 50
    ");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();

    $new_sales = [];
    while ($r = $res->fetch_assoc()) {
        $new_sales[] = [
            'id'          => (int)$r['id'],
            'grand_total' => (float)$r['grand_total'],
            'discount'    => (float)$r['discount'],
            'sale_date'   => $r['sale_date'],
            'time'        => date('h:i A', strtotime($r['sale_date'])),
            'full_name'   => $r['full_name'],
        ];
    }
    $stmt->close();

    // Today's totals
    $today_where  = "WHERE DATE(s.sale_date) = CURDATE()";
    $today_params = [];
    $today_types  = "";
    if ($auth_role !== 'admin') {
        $today_where  .= " AND s.user_id = ?";
        $today_params[] = $auth_user_id;
        $today_types   = "i";
    }
    $ts = $conn->prepare("SELECT COUNT(*) as cnt, COALESCE(SUM(grand_total),0) as rev FROM sales s $today_where");
    if ($today_types) $ts->bind_param($today_types, ...$today_params);
    $ts->execute();
    $today = $ts->get_result()->fetch_assoc();
    $ts->close();

    echo json_encode([
        'success'   => true,
        'new_sales' => $new_sales,
        'stats'     => [
            'today_count'   => (int)$today['cnt'],
            'today_revenue' => (float)$today['rev'],
        ],
    ]);
    exit;
}

$start_date  = $_GET['start_date'] ?? date('Y-m-01');
$end_date    = $_GET['end_date']   ?? date('Y-m-d');
$user_id     = (int)($_GET['user_id'] ?? 0);

// shared WHERE clause — always uses alias "s"
$where  = "WHERE DATE(s.sale_date) BETWEEN ? AND ?";
$params = [$start_date, $end_date];
$types  = 'ss';
if ($user_id) { $where .= " AND s.user_id=?"; $params[] = $user_id; $types .= 'i'; }

// ── Sales list ────────────────────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT s.id, s.total_amount, s.discount, s.tax, s.grand_total,
           s.cash_received, s.change_amount, s.sale_date, u.full_name
    FROM sales s JOIN users u ON s.user_id=u.id
    $where ORDER BY s.sale_date DESC LIMIT 200
");
if (!$stmt) { echo json_encode(['success'=>false,'message'=>'SQL error (sales): '.$conn->error]); exit; }
$stmt->bind_param($types, ...$params);
$stmt->execute();
$sales = [];
$totalRevenue = $totalDiscount = $totalTax = 0;
$res = $stmt->get_result();
$sale_ids = [];
while ($r = $res->fetch_assoc()) {
    $sales[]        = $r;
    $totalRevenue  += $r['grand_total'];
    $totalDiscount += $r['discount'];
    $totalTax      += $r['tax'];
    $sale_ids[]     = (int)$r['id'];
}
$stmt->close();

// ── COGS per sale ─────────────────────────────────────────────────────────────
// Priority (matches web system exactly):
//  1. purchase_cost_snapshot — locked at sale time, always correct
//  2. sb.purchase_cost / pu.conversion_to_base — batch cost ÷ original unit conversion
//  3. p.cost — product master cost per base unit fallback
$totalCogs = 0;
$cogs_map  = [];
if (!empty($sale_ids)) {
    $in      = implode(',', array_map('intval', $sale_ids));
    $cogsRes = $conn->query("
        SELECT si.sale_id,
               SUM(
                 si.quantity_in_base *
                 COALESCE(
                   NULLIF(si.purchase_cost_snapshot, 0),
                   CASE
                     WHEN COALESCE(pu.conversion_to_base, 0) > 0
                     THEN sb.purchase_cost / pu.conversion_to_base
                     ELSE NULLIF(sb.purchase_cost, 0)
                   END,
                   NULLIF(p.cost, 0),
                   0
                 )
               ) AS cogs
        FROM sale_items si
        JOIN products p ON si.product_id = p.id
        LEFT JOIN stock_batches sb ON si.batch_id = sb.id
        LEFT JOIN product_units pu
               ON pu.product_id = sb.product_id
              AND LOWER(pu.unit_name) = LOWER(sb.received_unit)
        WHERE si.sale_id IN ($in)
        GROUP BY si.sale_id
    ");
    if ($cogsRes) {
        while ($cr = $cogsRes->fetch_assoc()) {
            $cogs_map[(int)$cr['sale_id']] = (float)$cr['cogs'];
            $totalCogs += (float)$cr['cogs'];
        }
    }
}

// Attach cogs + profit to each sale row (same as web system)
foreach ($sales as &$sale) {
    $sid    = (int)$sale['id'];
    $cogs   = $cogs_map[$sid] ?? 0;
    $profit = (float)$sale['grand_total'] - $cogs;
    $sale['cogs']   = round($cogs, 2);
    $sale['profit'] = round($profit, 2);
    $sale['margin'] = (float)$sale['grand_total'] > 0
        ? round(($profit / (float)$sale['grand_total']) * 100, 1)
        : 0;
}
unset($sale);

$totalCogs    = round($totalCogs, 2);
$totalProfit  = round($totalRevenue - $totalCogs, 2);
$profitMargin = $totalRevenue > 0 ? round(($totalProfit / $totalRevenue) * 100, 1) : 0;

// ── Revenue by category ───────────────────────────────────────────────────────
$catStmt = $conn->prepare("
    SELECT COALESCE(c.name,'Uncategorized') AS category, COALESCE(SUM(si.subtotal),0) AS revenue
    FROM sale_items si
    JOIN sales s ON si.sale_id=s.id
    JOIN products p ON si.product_id=p.id
    LEFT JOIN categories c ON p.category_id=c.id
    $where
    GROUP BY p.category_id ORDER BY revenue DESC LIMIT 8
");
if (!$catStmt) { $catData = []; } else {
    $catStmt->bind_param($types, ...$params);
    $catStmt->execute();
    $catData = [];
    $catRes  = $catStmt->get_result();
    while ($r = $catRes->fetch_assoc()) $catData[] = ['category'=>$r['category'],'revenue'=>(float)$r['revenue']];
    $catStmt->close();
}

// ── Revenue by staff ──────────────────────────────────────────────────────────
$staffStmt = $conn->prepare("
    SELECT u.full_name, COUNT(*) AS transactions, SUM(s.grand_total) AS revenue
    FROM sales s JOIN users u ON s.user_id=u.id
    $where GROUP BY s.user_id ORDER BY revenue DESC LIMIT 10
");
if (!$staffStmt) { $staffData = []; } else {
    $staffStmt->bind_param($types, ...$params);
    $staffStmt->execute();
    $staffData = [];
    $sr = $staffStmt->get_result();
    while ($r = $sr->fetch_assoc()) $staffData[] = ['full_name'=>$r['full_name'],'transactions'=>(int)$r['transactions'],'revenue'=>(float)$r['revenue']];
    $staffStmt->close();
}

// ── Top products ──────────────────────────────────────────────────────────────
$topStmt = $conn->prepare("
    SELECT p.brand_name AS product_name,
           SUM(si.quantity) AS total_quantity,
           SUM(si.subtotal) AS total_revenue
    FROM sale_items si
    JOIN sales s ON si.sale_id=s.id
    JOIN products p ON si.product_id=p.id
    $where GROUP BY si.product_id ORDER BY total_revenue DESC LIMIT 10
");
if (!$topStmt) { $topProducts = []; } else {
    $topStmt->bind_param($types, ...$params);
    $topStmt->execute();
    $topProducts = [];
    $tr = $topStmt->get_result();
    while ($r = $tr->fetch_assoc()) $topProducts[] = ['product_name'=>$r['product_name'],'total_quantity'=>(int)$r['total_quantity'],'total_revenue'=>(float)$r['total_revenue']];
    $topStmt->close();
}

// ── Daily trend (fix: use alias s2 to avoid conflict) ────────────────────────
$trendWhere  = "WHERE DATE(s2.sale_date) BETWEEN ? AND ?";
$trendParams = [$start_date, $end_date];
$trendTypes  = 'ss';
if ($user_id) { $trendWhere .= " AND s2.user_id=?"; $trendParams[] = $user_id; $trendTypes .= 'i'; }

$trendStmt = $conn->prepare("
    SELECT DATE(s2.sale_date) AS date, COUNT(*) AS transactions, SUM(s2.grand_total) AS revenue
    FROM sales s2 $trendWhere GROUP BY DATE(s2.sale_date) ORDER BY date ASC
");
if (!$trendStmt) { $trend = []; } else {
    $trendStmt->bind_param($trendTypes, ...$trendParams);
    $trendStmt->execute();
    $trend = [];
    $tres  = $trendStmt->get_result();
    while ($r = $tres->fetch_assoc()) $trend[] = [
        'date'         => $r['date'],
        'label'        => date('M d', strtotime($r['date'])),
        'transactions' => (int)$r['transactions'],
        'revenue'      => (float)$r['revenue'],
    ];
    $trendStmt->close();
}

echo json_encode([
    'success'      => true,
    'sales'        => $sales,
    'summary'      => [
        'total_transactions' => count($sales),
        'total_revenue'      => round($totalRevenue, 2),
        'total_discount'     => round($totalDiscount, 2),
        'total_tax'          => round($totalTax, 2),
        'avg_sale'           => count($sales) > 0 ? round($totalRevenue / count($sales), 2) : 0,
        'total_cogs'         => $totalCogs,
        'total_profit'       => $totalProfit,
        'profit_margin'      => $profitMargin,
    ],
    'by_category'  => $catData,
    'by_staff'     => $staffData,
    'top_products' => $topProducts,
    'daily_trend'  => $trend,
]);