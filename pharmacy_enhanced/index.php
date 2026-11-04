<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/notifications.php';

$user_role = $_SESSION['role'] ?? '';
$user_name = $_SESSION['full_name'] ?? '';

try {
    if (isAdmin()) {
        // ── Admin Dashboard Statistics ──────────────────────

        // Today's total sales
        $r = $conn->query("SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE DATE(sale_date) = CURDATE()");
        $total_sales = $r ? $r->fetch_row()[0] : 0;

      // In index.php - Admin section
// EXPIRY RULE: Expired = expiration_date < CURDATE()
// Near Expiry = expiration_date >= CURDATE() AND < DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
// Good stock  = expiration_date >= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
// Low/Out of Stock only count "good" stock (not expired, not near-expiry)
$low_stock = $conn->query("
    SELECT COUNT(*) FROM products p
    WHERE (
        SELECT COALESCE(SUM(sb.quantity_base), 0)
        FROM stock_batches sb
        WHERE sb.product_id = p.id
          AND sb.expiration_date >= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
    ) > 0
    AND (
        SELECT COALESCE(SUM(sb.quantity_base), 0)
        FROM stock_batches sb
        WHERE sb.product_id = p.id
          AND sb.expiration_date >= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
    ) <= p.low_stock_threshold
")->fetch_row()[0];

$out_of_stock = $conn->query("
    SELECT COUNT(DISTINCT p.id)
    FROM products p
    WHERE NOT EXISTS (
        SELECT 1 FROM stock_batches sb
        WHERE sb.product_id = p.id
          AND sb.quantity_base > 0
          AND sb.expiration_date >= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
    )
    AND EXISTS (
        SELECT 1 FROM stock_batches sb WHERE sb.product_id = p.id
    )
")->fetch_row()[0];

$near_expiry = $conn->query("
    SELECT COUNT(*) FROM stock_batches
    WHERE expiration_date > CURDATE()
      AND expiration_date < DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
      AND quantity_base > 0
")->fetch_row()[0];

$expired_items = $conn->query("
    SELECT COUNT(*) FROM stock_batches
    WHERE expiration_date <= CURDATE()
      AND quantity_base > 0
")->fetch_row()[0];

        // Recent Sales
        $recent_sales_result = $conn->query("
            SELECT s.*, u.full_name
            FROM sales s
            JOIN users u ON s.user_id = u.id
            ORDER BY s.sale_date DESC LIMIT 6
        ");
        $recent_sales = [];
        if ($recent_sales_result) {
            while ($row = $recent_sales_result->fetch_assoc()) {
                $recent_sales[] = $row;
            }
        }

        // ── Admin Progressive Multi-Dataset Chart Data ──────────────────────
        // Chart labels: last 7 days
        $chart_labels = [];
        $today_data = [];
        $last_week_data = [];
        $this_month_data = [];
        $this_year_data = [];

        // Today's hourly data (for the 7-label axis, show today's cumulative vs same hour last week)
        // We use last 7 days as X-axis labels
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $chart_labels[] = date('D, M j', strtotime($date));
        }

        // Dataset 1: This week's daily sales (last 7 days)
        $r = $conn->query("
            SELECT DATE(sale_date) as d, COALESCE(SUM(grand_total),0) as total
            FROM sales WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY DATE(sale_date)
        ");
        $map = [];
        if ($r) while ($row = $r->fetch_assoc()) $map[$row['d']] = $row['total'];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $today_data[] = (float)($map[$date] ?? 0);
        }

        // Dataset 2: Last week's daily sales (same 7 days, shifted 7 days back)
        $r = $conn->query("
            SELECT DATE(sale_date) as d, COALESCE(SUM(grand_total),0) as total
            FROM sales WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
              AND sale_date < DATE_SUB(CURDATE(), INTERVAL 6 DAY)
            GROUP BY DATE(sale_date)
        ");
        $map2 = [];
        if ($r) while ($row = $r->fetch_assoc()) $map2[$row['d']] = $row['total'];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-" . ($i + 7) . " days"));
            $last_week_data[] = (float)($map2[$date] ?? 0);
        }

        // Dataset 3: This month's daily sales (spread across 7 buckets = weeks of month)
        $r = $conn->query("
            SELECT WEEK(sale_date,1) as wk, COALESCE(SUM(grand_total),0) as total
            FROM sales WHERE MONTH(sale_date)=MONTH(CURDATE()) AND YEAR(sale_date)=YEAR(CURDATE())
            GROUP BY WEEK(sale_date,1) ORDER BY wk LIMIT 7
        ");
        $map3 = [];
        if ($r) { $idx = 0; while ($row = $r->fetch_assoc()) { $map3[$idx++] = $row['total']; } }
        for ($i = 0; $i < 7; $i++) $this_month_data[] = (float)($map3[$i] ?? 0);

        // Dataset 4: This year's monthly sales (Jan-Dec, mapped to 7 points)
        $r = $conn->query("
            SELECT MONTH(sale_date) as m, COALESCE(SUM(grand_total),0) as total
            FROM sales WHERE YEAR(sale_date)=YEAR(CURDATE())
            GROUP BY MONTH(sale_date) ORDER BY m
        ");
        $map4 = [];
        if ($r) while ($row = $r->fetch_assoc()) $map4[$row['m']] = $row['total'];
        // Map 12 months to 7 points (approx)
        $month_keys = [1,2,3,4,5,6,7,8,9,10,11,12];
        $year_raw = [];
        foreach ($month_keys as $m) $year_raw[] = (float)($map4[$m] ?? 0);
        // Downsample 12→7 by summing pairs
        for ($i = 0; $i < 7; $i++) {
            $idx1 = (int)floor($i * 12 / 7);
            $idx2 = (int)floor(($i+1) * 12 / 7) - 1;
            $sum = 0;
            for ($j = $idx1; $j <= $idx2; $j++) $sum += $year_raw[$j] ?? 0;
            $this_year_data[] = $sum;
        }

        // User Performance Chart Data
        $r = $conn->query("
            SELECT u.full_name, COUNT(s.id) as tx_count, COALESCE(SUM(s.grand_total),0) as total_sales
            FROM users u LEFT JOIN sales s ON s.user_id = u.id AND MONTH(s.sale_date)=MONTH(CURDATE()) AND YEAR(s.sale_date)=YEAR(CURDATE())
            WHERE u.role='staff'
            GROUP BY u.id ORDER BY total_sales DESC LIMIT 8
        ");
        $perf_names = []; $perf_tx = []; $perf_sales = [];
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $perf_names[] = $row['full_name'];
                $perf_tx[] = (int)$row['tx_count'];
                $perf_sales[] = (float)$row['total_sales'];
            }
        }

        // Keep legacy vars for metrics below chart
        $chart_data = $today_data;
        $max_sale = max($today_data) ?: 1;

        // Top Selling Products — this month (for dashboard)
        $r = $conn->query("
            SELECT p.brand_name, COALESCE(SUM(si.quantity_sold),0) as qty_sold,
                   COALESCE(SUM(si.subtotal),0) as revenue
            FROM sale_items si
            JOIN products p ON p.id = si.product_id
            JOIN sales s ON s.id = si.sale_id
            WHERE MONTH(s.sale_date)=MONTH(CURDATE()) AND YEAR(s.sale_date)=YEAR(CURDATE())
            GROUP BY p.id ORDER BY qty_sold DESC LIMIT 7
        ");
        $dash_top_names = []; $dash_top_qty = [];
        if ($r) { while ($row = $r->fetch_assoc()) { $dash_top_names[] = $row['brand_name']; $dash_top_qty[] = (int)$row['qty_sold']; } }

        // Sales by Category — this month (for dashboard)
        $r = $conn->query("
            SELECT COALESCE(c.name,'Uncategorized') as cat_name, COALESCE(SUM(si.subtotal),0) as revenue
            FROM sale_items si
            JOIN products p ON p.id = si.product_id
            LEFT JOIN categories c ON c.id = p.category_id
            JOIN sales s ON s.id = si.sale_id
            WHERE MONTH(s.sale_date)=MONTH(CURDATE()) AND YEAR(s.sale_date)=YEAR(CURDATE())
            GROUP BY c.id ORDER BY revenue DESC LIMIT 7
        ");
        $dash_cat_names = []; $dash_cat_rev = [];
        if ($r) { while ($row = $r->fetch_assoc()) { $dash_cat_names[] = $row['cat_name']; $dash_cat_rev[] = (float)$row['revenue']; } }

        // Monthly sales
        $r = $conn->query("
            SELECT COALESCE(SUM(grand_total), 0)
            FROM sales
            WHERE MONTH(sale_date) = MONTH(CURDATE())
              AND YEAR(sale_date)  = YEAR(CURDATE())
        ");
        $monthly_sales = $r ? $r->fetch_row()[0] : 0;

        // Yesterday comparison
        $r = $conn->query("SELECT COALESCE(SUM(grand_total), 0) FROM sales WHERE DATE(sale_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
        $yesterday_sales = $r ? $r->fetch_row()[0] : 0;
        $sales_change = $yesterday_sales > 0
            ? round((($total_sales - $yesterday_sales) / $yesterday_sales) * 100, 1)
            : 0;

    } else {
        // ── Staff Dashboard Statistics ───────────────────────

$stmt = $conn->prepare("
        SELECT COALESCE(COUNT(*), 0) as transaction_count,
               COALESCE(SUM(grand_total), 0) as total_amount
        FROM sales
        WHERE user_id = ? AND DATE(sale_date) = CURDATE()
    ");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $my_sales = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$my_sales) {
        $my_sales = ['transaction_count' => 0, 'total_amount' => 0];
    }

    // Total products
    $r = $conn->query("SELECT COUNT(*) FROM products");
    $total_products = $r ? $r->fetch_row()[0] : 0;

    // (low_stock_items computed below)

// Staff Dashboard Statistics
// EXPIRY RULE: Expired = expiration_date < CURDATE()
// Near Expiry = expiration_date >= CURDATE() AND < DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
// Good stock  = expiration_date >= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
// Low/Out of Stock only count "good" stock
$total_products = $conn->query("SELECT COUNT(*) FROM products")->fetch_row()[0];

$low_stock_items = $conn->query("
    SELECT COUNT(*) FROM products p
    WHERE (
        SELECT COALESCE(SUM(sb.quantity_base), 0)
        FROM stock_batches sb
        WHERE sb.product_id = p.id
          AND sb.expiration_date >= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
    ) > 0
    AND (
        SELECT COALESCE(SUM(sb.quantity_base), 0)
        FROM stock_batches sb
        WHERE sb.product_id = p.id
          AND sb.expiration_date >= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
    ) <= p.low_stock_threshold
")->fetch_row()[0];

$out_of_stock_items = $conn->query("
    SELECT COUNT(DISTINCT p.id)
    FROM products p
    WHERE NOT EXISTS (
        SELECT 1 FROM stock_batches sb
        WHERE sb.product_id = p.id
          AND sb.quantity_base > 0
          AND sb.expiration_date >= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
    )
    AND EXISTS (
        SELECT 1 FROM stock_batches sb WHERE sb.product_id = p.id
    )
")->fetch_row()[0];

// Near expiry: today <= expiration_date < today + 1 month
$near_expiry_items = $conn->query("
    SELECT COUNT(*) FROM stock_batches
    WHERE expiration_date > CURDATE()
      AND expiration_date < DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
      AND quantity_base > 0
")->fetch_row()[0];

// Expired
$expired_items = $conn->query("
    SELECT COUNT(*) FROM stock_batches
    WHERE expiration_date <= CURDATE()
      AND quantity_base > 0
")->fetch_row()[0];

    // Recent products for quick view
    $recent_products_result = $conn->query("
        SELECT p.brand_name, p.generic_name, p.price, p.base_unit,
               COALESCE((
                   SELECT SUM(sb.quantity_base)
                   FROM stock_batches sb
                   WHERE sb.product_id = p.id
                     AND sb.expiration_date >= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
               ), 0) as stock
        FROM products p
        ORDER BY p.id DESC
        LIMIT 5
    ");
    $recent_products = [];
    if ($recent_products_result) {
        while ($row = $recent_products_result->fetch_assoc()) {
            $recent_products[] = $row;
        }
    }

    // Staff performance chart data (last 7 days transactions + sales)
    $uid = $_SESSION['user_id'];
    $r = $conn->query("
        SELECT DATE(sale_date) as d, COUNT(*) as tx_count, COALESCE(SUM(grand_total),0) as total_sales,
               COALESCE(SUM(si.quantity_sold),0) as products_sold
        FROM sales s LEFT JOIN sale_items si ON si.sale_id = s.id
        WHERE s.user_id = $uid AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY DATE(s.sale_date)
    ");
    $staff_chart_labels = [];
    $staff_tx_data = [];
    $staff_sales_data = [];
    $staff_products_data = [];
    $staff_map = [];
    if ($r) { while ($row = $r->fetch_assoc()) $staff_map[$row['d']] = $row; }
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $staff_chart_labels[] = date('D', strtotime($date));
        $staff_tx_data[] = (int)($staff_map[$date]['tx_count'] ?? 0);
        $staff_sales_data[] = (float)($staff_map[$date]['total_sales'] ?? 0);
        $staff_products_data[] = (int)($staff_map[$date]['products_sold'] ?? 0);
    }

    // Inventory pie chart data for staff
    $in_stock_count = max(0, ($total_products ?? 0) - ($low_stock_items ?? 0) - ($out_of_stock_items ?? 0));
    $inv_pie_data = [
        'in_stock' => (int)$in_stock_count,
        'low_stock' => (int)($low_stock_items ?? 0),
        'out_of_stock' => (int)($out_of_stock_items ?? 0),
        'near_expiry' => (int)($near_expiry_items ?? 0),
        'expired' => (int)($expired_items ?? 0),
    ];
}
} catch (Exception $e) {
    die("Dashboard error: " . $e->getMessage());
}

// Determine Greeting
$hour = date('H');
$greeting = ($hour < 12) ? "Good Morning" : (($hour < 17) ? "Good Afternoon" : "Good Evening");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Dashboard | CAD Crest Pharmaceutical</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-green: #004d40;
            --accent-gold: #b8860b;
            --bg-soft: #f8fafc;
            --card-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05);
        }

        body { background-color: var(--bg-soft); }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.5rem;
        }

        .welcome-text h1 {
            margin: 0;
            color: var(--primary-green);
            font-size: 1.8rem;
            font-weight: 800;
        }

        .welcome-text p {
            margin: 5px 0 0;
            color: #64748b;
            font-size: 1rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 24px;
            box-shadow: var(--card-shadow);
            display: flex;
            align-items: center;
            transition: transform 0.3s ease;
            border: 1px solid rgba(0,0,0,0.02);
        }

        .stat-card:hover { transform: translateY(-5px); }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-right: 1.2rem;
        }

        .icon-sales { background: #ecfdf5; color: #10b981; }
        .icon-expired { background: #fef2f2; color: #ef4444; }
        .icon-near { background: #fffbeb; color: #f59e0b; }
        .icon-low { background: #eff6ff; color: #3b82f6; }

        .stat-details h3 {
            margin: 0;
            font-size: 0.85rem;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-details .value {
            font-size: 1.6rem;
            font-weight: 800;
            color: #1e293b;
            margin-top: 4px;
        }

        /* Layout Sections for Admin */
        .dashboard-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }

        /* Chart Container */
        .chart-container {
            background: white;
            border-radius: 24px;
            padding: 1.8rem;
            box-shadow: var(--card-shadow);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .chart-header h2 {
            font-size: 1.2rem;
            color: #1e293b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .chart-period {
            background: #f1f5f9;
            padding: 6px 12px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 600;
            color: #475569;
        }

        .chart-wrapper {
            height: 250px;
            position: relative;
        }

        .sales-metrics {
            display: flex;
            gap: 2rem;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #f1f5f9;
        }

        .metric-item {
            flex: 1;
        }

        .metric-label {
            font-size: 0.75rem;
            color: #64748b;
            text-transform: uppercase;
        }

        .metric-value {
            font-size: 1.3rem;
            font-weight: 700;
            color: #1e293b;
        }

        .metric-change {
            font-size: 0.8rem;
            margin-left: 8px;
        }

        .metric-change.positive { color: #10b981; }
        .metric-change.negative { color: #ef4444; }

        /* Layout for Staff - UPDATED */
        .staff-dashboard-content {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 1.5rem;
        }

        .main-panel, .side-panel {
            background: white;
            border-radius: 24px;
            padding: 1.8rem;
            box-shadow: var(--card-shadow);
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .panel-header h2 {
            font-size: 1.2rem;
            color: #1e293b;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* Table Styling */
        .modern-table {
            width: 100%;
            border-collapse: collapse;
        }

        .modern-table th {
            text-align: left;
            padding: 12px;
            color: #94a3b8;
            font-size: 0.75rem;
            text-transform: uppercase;
            border-bottom: 1px solid #f1f5f9;
        }

        .modern-table td {
            padding: 16px 12px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.9rem;
            color: #334155;
        }

        .status-pill {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 700;
            background: #f1f5f9;
            color: #475569;
        }

        .status-pill.completed {
            background: #c6f6d5;
            color: #22543d;
        }

        /* Staff Stats Grid - UPDATED */
        .enhanced-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card-enhanced {
            background: white;
            border-radius: 24px;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1.2rem;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            border: 1px solid rgba(0,0,0,0.02);
        }

        .stat-card-enhanced:hover {
            transform: translateY(-5px);
            box-shadow: 0 20px 40px -8px rgba(0, 77, 64, 0.15);
        }

        .stat-card-enhanced.sales .stat-icon-wrapper {
            background: #ecfdf5;
            color: #10b981;
        }

        .stat-card-enhanced.products .stat-icon-wrapper {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .stat-card-enhanced.lowstock .stat-icon-wrapper {
            background: #fff3e0;
            color: #f57c00;
        }

        .stat-card-enhanced.expiring .stat-icon-wrapper {
            background: #fee2e2;
            color: #d32f2f;
        }

        .stat-icon-wrapper {
            width: 64px;
            height: 64px;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
        }

        .stat-details-enhanced {
            flex: 1;
        }

        .stat-label-enhanced {
            display: block;
            font-size: 0.8rem;
            color: #64748b;
            margin-bottom: 0.3rem;
            font-weight: 500;
        }

        .stat-value-enhanced {
            display: block;
            font-size: 1.8rem;
            font-weight: 800;
            color: #1e293b;
            line-height: 1.2;
            margin-bottom: 0.3rem;
        }

        .stat-trend-enhanced {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            font-size: 0.7rem;
            padding: 0.2rem 0.8rem;
            background: #f1f5f9;
            border-radius: 40px;
            color: #475569;
        }

        .stat-trend-enhanced.warning {
            background: #fff3e0;
            color: #f57c00;
        }

        .stat-trend-enhanced.danger {
            background: #fee2e2;
            color: #d32f2f;
        }

        /* Quick Tips */
        .tips-container {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .tip-card {
            background: #f8fafc;
            padding: 1.2rem;
            border-radius: 16px;
        }

        .tip-card.blue { background: #eff6ff; color: #1e40af; }
        .tip-card.orange { background: #fffbeb; color: #92400e; }

        .tip-card i {
            margin-right: 8px;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }

        .product-mini-card {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 12px;
        }

        .product-mini-card .name {
            font-weight: 700;
            color: #1e293b;
        }

        .product-mini-card .price {
            color: var(--primary-green);
            font-weight: 700;
        }

        .product-mini-card .stock {
            font-size: 0.8rem;
            color: #64748b;
        }

        .view-link {
            color: var(--primary-green);
            text-decoration: none;
            font-weight: 700;
            font-size: 0.85rem;
        }

        .view-link:hover {
            text-decoration: underline;
        }

        .system-status {
            margin-top: 2rem;
            padding: 1.5rem;
            background: linear-gradient(135deg, var(--primary-green), #00251a);
            border-radius: 20px;
            color: white;
        }

        .progress-bar {
            height: 6px;
            background: rgba(255,255,255,0.1);
            border-radius: 3px;
            overflow: hidden;
            margin-top: 10px;
        }

        .progress-fill {
            width: 100%;
            height: 100%;
            background: #10b981;
        }

        /* Staff Welcome Header - NEW */
        .staff-welcome-header {
            background: linear-gradient(135deg, #004d40 0%, #00695c 100%);
            padding: 2rem;
            border-radius: 32px;
            margin-bottom: 2rem;
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: 0 20px 40px -10px rgba(0, 77, 64, 0.3);
        }

        .staff-welcome-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .welcome-content {
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .greeting-badge {
            background: rgba(255,255,255,0.15);
            padding: 0.5rem 1rem;
            border-radius: 40px;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            backdrop-filter: blur(10px);
        }

        .role-badge {
            background: #b8860b;
            color: white;
            padding: 0.3rem 1rem;
            border-radius: 40px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 1rem;
        }

        .date-display {
            margin-top: 0.5rem;
            opacity: 0.9;
            font-size: 0.95rem;
        }

        .quick-actions {
            display: flex;
            gap: 1rem;
        }

        .action-btn {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            padding: 0.8rem 1.5rem;
            border-radius: 16px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            background: rgba(255,255,255,0.15);
            color: white;
            backdrop-filter: blur(10px);
        }

        .action-btn:hover {
            transform: translateY(-2px);
            background: rgba(255,255,255,0.25);
        }

        .action-btn.primary {
            background: white;
            color: #004d40;
        }

        .action-btn.primary:hover {
            background: #f0f7f4;
        }

        /* Products Table */
        .products-table {
            overflow-x: auto;
        }

        .products-table table {
            width: 100%;
            border-collapse: collapse;
        }

        .products-table th {
            text-align: left;
            padding: 0.8rem;
            color: #64748b;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
            border-bottom: 2px solid #f1f5f9;
        }

        .products-table td {
            padding: 0.8rem;
            border-bottom: 1px solid #f1f5f9;
        }

        .product-info {
            display: flex;
            flex-direction: column;
        }

        .product-brand {
            font-weight: 700;
            color: #1e293b;
        }

        .product-generic {
            font-size: 0.7rem;
            color: #94a3b8;
        }

        .price {
            font-weight: 700;
            color: #004d40;
        }

        .stock {
            font-weight: 600;
        }

        .status-badge {
            padding: 0.3rem 0.8rem;
            border-radius: 40px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .status-good {
            background: #dcfce7;
            color: #15803d;
        }

        .status-low {
            background: #fff3e0;
            color: #f57c00;
        }

        .status-out {
            background: #fee2e2;
            color: #d32f2f;
        }

        /* Inventory Summary */
        .inventory-summary {
            margin-bottom: 1.5rem;
        }

        .summary-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.8rem 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .summary-item:last-child {
            border-bottom: none;
        }

        .summary-label {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .dot.in-stock { background: #15803d; }
        .dot.low-stock { background: #f57c00; }
        .dot.expiring { background: #d32f2f; }

        .summary-value {
            font-weight: 700;
            color: #1e293b;
        }

        .progress-section {
            margin-top: 1rem;
        }

        .progress-bar-container {
            width: 100%;
        }

        .progress-bar {
            height: 8px;
            background: #f1f5f9;
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #004d40, #00695c);
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .progress-labels {
            display: flex;
            justify-content: space-between;
            font-size: 0.7rem;
            color: #64748b;
        }

        /* Performance Card */
        .performance-card {
            background: linear-gradient(135deg, #004d40 0%, #00695c 100%);
            color: white;
        }

        .performance-card .card-header h2 i {
            color: white;
        }

        .performance-stats {
            margin: 1.5rem 0;
        }

        .performance-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.8rem 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }

        .performance-item:last-child {
            border-bottom: none;
        }

        .performance-item .label {
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .performance-item .value {
            font-weight: 700;
            font-size: 1.1rem;
        }

        .goal-progress {
            margin-top: 1rem;
        }

        .goal-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.8rem;
            opacity: 0.9;
        }

        .goal-amount {
            text-align: right;
            margin-top: 0.5rem;
            font-size: 0.8rem;
            opacity: 0.9;
        }

        /* Tips Grid */
        .tips-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }

        .tip-item {
            background: #f8fafc;
            padding: 0.8rem;
            border-radius: 16px;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .key {
            background: #004d40;
            color: white;
            padding: 0.3rem 0.6rem;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.8rem;
            min-width: 40px;
            text-align: center;
        }

        .desc {
            font-size: 0.8rem;
            color: #1e293b;
        }

        /* Alerts Card */
        .alerts-list {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .alert-item {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            padding: 1rem;
            border-radius: 16px;
        }

        .alert-item.warning {
            background: #fff3e0;
            color: #f57c00;
        }

        .alert-item.danger {
            background: #fee2e2;
            color: #d32f2f;
        }

        .alert-item i {
            font-size: 1.2rem;
        }

        .alert-content {
            flex: 1;
            font-size: 0.9rem;
        }

        .alert-content a {
            display: inline-block;
            margin-left: 0.5rem;
            color: inherit;
            font-weight: 700;
            text-decoration: underline;
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .content-card {
            background: white;
            border-radius: 24px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            margin-bottom: 1.5rem;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.2rem;
        }

        .card-header h2 {
            font-size: 1.1rem;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-header h2 i {
            color: #004d40;
        }

        .view-all-link {
            color: #004d40;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            padding: 0.4rem 1rem;
            border-radius: 40px;
            background: #f1f5f9;
            transition: all 0.3s ease;
        }

        .view-all-link:hover {
            background: #004d40;
            color: white;
        }

        @media print {
            .sidebar, .dashboard-header .date-badge { display: none; }
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .enhanced-stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .staff-dashboard-content {
                grid-template-columns: 1fr;
            }
        }

        /* ── Responsive: Tablet ── */
        @media (max-width: 1200px) {
            .admin-analytics-row {
                grid-template-columns: 1fr 1fr !important;
            }
        }

        @media (max-width: 900px) {
            .admin-analytics-row {
                grid-template-columns: 1fr !important;
            }
        }

        /* ── Responsive: Mobile ── */
        @media (max-width: 768px) {
            .welcome-content {
                flex-direction: column;
                align-items: flex-start;
            }

            .quick-actions { width: 100%; }
            .action-btn { flex: 1; justify-content: center; }

            /* Staff 6-col stats → 2-col */
            .staff-stats-grid {
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 0.8rem !important;
            }

            .stat-card { padding: 1rem 1.1rem; }
            .stat-icon { width: 46px; height: 46px; font-size: 1.1rem; border-radius: 13px; }
            .stat-details h3 { font-size: 0.72rem; }
            .stat-details .value { font-size: 1.25rem; }

            .enhanced-stats-grid { grid-template-columns: 1fr; }

            /* Staff dashboard 2-col → 1-col */
            .staff-dashboard-content {
                grid-template-columns: 1fr !important;
            }

            .content-card { padding: 1rem 1.1rem; }
            .card-header h2 { font-size: 0.92rem; }

            .tips-grid { grid-template-columns: 1fr 1fr; }

            /* Admin 3-col analytics row */
            .admin-analytics-row {
                grid-template-columns: 1fr !important;
            }

            /* Admin top-level 2-col dashboard */
            .dashboard-content {
                grid-template-columns: 1fr !important;
            }

            .chart-wrapper { height: 250px !important; }
        }

        @media (max-width: 480px) {
            .staff-stats-grid {
                grid-template-columns: 1fr 1fr !important;
            }
            .quick-actions { flex-direction: column; }
            .action-btn { width: 100%; }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <?php if (isAdmin()): ?>
            <!-- Admin Dashboard Header -->
            <div class="dashboard-header">
                <div class="welcome-text">
                    <h1><?php echo $greeting; ?>, <?php echo explode(' ', $user_name)[0]; ?>!</h1>
                    <p>Here is the status of CAD Crest for today, <?php echo date('M d, Y'); ?></p>
                </div>
                <div style="display:flex;align-items:center;gap:.75rem;">
                    <?php renderNotificationBell($conn); ?>
                    <div class="date-badge" style="background: white; padding: 10px 20px; border-radius: 15px; box-shadow: var(--card-shadow); font-weight: 700; color: var(--primary-green);">
                        <i class="fa-regular fa-calendar-check"></i> <?php echo date('M d, Y'); ?>
                    </div>
                </div>
            </div>

        <!-- Statistics Cards - Matching User Management Style -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background: #e8f5e9; color: #004d40;">
            <i class="fa-solid fa-sack-dollar"></i>
        </div>
        <div class="stat-details">
            <h3>Today's Sales</h3>
            <div class="value" style="color:#004d40;">₱<?php echo number_format($total_sales, 2); ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #fff3e0; color: #e65100;">
            <i class="fa-solid fa-boxes-stacked"></i>
        </div>
        <div class="stat-details">
            <h3>Low Stock</h3>
            <div class="value" style="color:#e65100;"><?php echo $low_stock; ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #fee2e2; color: #b91c1c;">
            <i class="fa-solid fa-circle-exclamation"></i>
        </div>
        <div class="stat-details">
            <h3>Out of Stock</h3>
            <div class="value" style="color:#b91c1c;"><?php echo $out_of_stock; ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #fff8e1; color: #f57c00;">
            <i class="fa-solid fa-hourglass-half"></i>
        </div>
        <div class="stat-details">
            <h3>Near Expiry</h3>
            <div class="value" style="color:#f57c00;"><?php echo $near_expiry; ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #fee2e2; color: #c62828;">
            <i class="fa-solid fa-calendar-xmark"></i>
        </div>
        <div class="stat-details">
            <h3>Expired</h3>
            <div class="value" style="color:#c62828;"><?php echo $expired_items; ?></div>
        </div>
    </div>
</div>

            <div class="dashboard-content">
                <!-- Sales Overview - Progressive Line Chart -->
                <div class="chart-container">
                    <div class="chart-header">
                        <h2><i class="fa-solid fa-chart-line" style="color: var(--primary-green);"></i> Sales Overview</h2>
                        <span class="chart-period">Last 7 Days vs Comparisons</span>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="salesChart"></canvas>
                    </div>
                    <div class="sales-metrics">
                        <div class="metric-item">
                            <div class="metric-label">Today's Sales</div>
                            <div class="metric-value">₱<?php echo number_format($total_sales, 2); ?></div>
                        </div>
                        <div class="metric-item">
                            <div class="metric-label">This Month</div>
                            <div class="metric-value">₱<?php echo number_format($monthly_sales, 2); ?></div>
                        </div>
                        <div class="metric-item">
                            <div class="metric-label">vs Yesterday</div>
                            <div class="metric-value">
                                <?php if ($sales_change > 0): ?>
                                    <span class="metric-change positive">↑ <?php echo $sales_change; ?>%</span>
                                <?php elseif ($sales_change < 0): ?>
                                    <span class="metric-change negative">↓ <?php echo abs($sales_change); ?>%</span>
                                <?php else: ?>
                                    <span class="metric-change">0%</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Transactions -->
                <div class="chart-container">
                    <div class="chart-header">
                        <h2><i class="fa-solid fa-clock-rotate-left" style="color: var(--primary-green);"></i> Recent Transactions</h2>
                        <a href="reports.php" class="view-link">View All →</a>
                    </div>
                    <table class="modern-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Time</th>
                                <th>Staff</th>
                                <th>Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recent_sales)): ?>
                                <tr><td colspan="5" style="text-align: center; padding: 40px; color: #94a3b8;">No transactions recorded today.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recent_sales as $sale): ?>
                                <tr>
                                    <td style="font-weight: 700;">#<?php echo $sale['id']; ?></td>
                                    <td><?php echo date('h:i A', strtotime($sale['sale_date'])); ?></td>
                                    <td><?php echo htmlspecialchars($sale['full_name']); ?></td>
                                    <td style="font-weight: 800; color: var(--primary-green);">₱<?php echo number_format($sale['grand_total'], 2); ?></td>
                                    <td><span class="status-pill completed">Completed</span></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Analytics Row: Staff Performance (compact) + Top Products + Category -->
            <div class="admin-analytics-row" style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:1.5rem; margin-top:2rem;">

                <!-- Staff Performance — compact radar/bar -->
                <div class="chart-container" style="padding:1.2rem;">
                    <div class="chart-header" style="margin-bottom:.6rem;">
                        <h2 style="font-size:.95rem;"><i class="fa-solid fa-users" style="color:var(--primary-green);"></i> Staff Performance</h2>
                        <a href="reports.php" class="view-link" style="font-size:.78rem;">Report →</a>
                    </div>
                    <div style="position:relative;height:220px;">
                        <canvas id="staffPerfRadar"></canvas>
                    </div>
                </div>

                <!-- Top Selling Products this month -->
                <div class="chart-container" style="padding:1.2rem;">
                    <div class="chart-header" style="margin-bottom:.6rem;">
                        <h2 style="font-size:.95rem;"><i class="fa-solid fa-medal" style="color:#b8860b;"></i> Top Products</h2>
                        <span class="chart-period" style="font-size:.75rem;">This Month</span>
                    </div>
                    <div style="position:relative;height:220px;">
                        <canvas id="topProductsDash"></canvas>
                    </div>
                </div>

                <!-- Sales by Category this month -->
                <div class="chart-container" style="padding:1.2rem;">
                    <div class="chart-header" style="margin-bottom:.6rem;">
                        <h2 style="font-size:.95rem;"><i class="fa-solid fa-tags" style="color:#8b5cf6;"></i> By Category</h2>
                        <span class="chart-period" style="font-size:.75rem;">This Month</span>
                    </div>
                    <div style="position:relative;height:220px;">
                        <canvas id="categoryDash"></canvas>
                    </div>
                </div>
            </div>

            <!-- System Status (Full Width) -->
            <div style="margin-top: 2rem;">
                <div class="system-status">
                    <h4 style="margin:0; font-size: 0.9rem; opacity: 0.8;">System Status</h4>
                    <p style="font-size: 1.1rem; font-weight: 700; margin: 10px 0;">All Systems Normal</p>
                    <div class="progress-bar">
                        <div class="progress-fill"></div>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- Staff Dashboard - Enhanced Version -->
            
            <!-- Welcome Header -->
            <div class="staff-welcome-header">
                <div class="welcome-content">
                    <div>
                        <span class="greeting-badge">
                            <i class="fa-regular fa-clock"></i> <?php echo $greeting; ?>
                        </span>
                        <h1>
                            <?php echo htmlspecialchars(explode(' ', $user_name)[0]); ?> 
                            <span class="role-badge">Staff</span>
                        </h1>
                        <p class="date-display">
                            <i class="fa-regular fa-calendar"></i> <?php echo date('l, F j, Y'); ?>
                        </p>
                    </div>
                    <div class="quick-actions">
                        <a href="pos.php" class="action-btn primary">
                            <i class="fa-solid fa-cart-plus"></i>
                            <span>New Sale</span>
                        </a>
                        <a href="admin/staff_inventory.php" class="action-btn secondary">
                            <i class="fa-solid fa-boxes"></i>
                            <span>Inventory</span>
                        </a>
                    </div>
                </div>
            </div>

<!-- Statistics Cards - Matching User Management Style -->
<div class="stats-grid staff-stats-grid" style="grid-template-columns: repeat(6, 1fr);">
    <div class="stat-card">
        <div class="stat-icon" style="background: #e8f5e9; color: #004d40;">
            <i class="fa-solid fa-sack-dollar"></i>
        </div>
        <div class="stat-details">
            <h3>My Sales Today</h3>
            <div class="value">₱<?php echo number_format($my_sales['total_amount'] ?? 0, 2); ?></div>
            <span class="stat-badge"><?php echo $my_sales['transaction_count'] ?? 0; ?> transactions</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon" style="background: #e8f5e9; color: #004d40;">
            <i class="fa-solid fa-pills"></i>
        </div>
        <div class="stat-details">
            <h3>Total Products</h3>
            <div class="value"><?php echo $total_products ?? 0; ?></div>
            <span class="stat-badge">Active</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon" style="background: #fff3e0; color: #e65100;">
            <i class="fa-solid fa-exclamation-triangle"></i>
        </div>
        <div class="stat-details">
            <h3>Low Stock</h3>
            <div class="value" style="color:#e65100;"><?php echo $low_stock_items ?? 0; ?></div>
            <span class="stat-badge warning">Need attention</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon" style="background: #fee2e2; color: #b91c1c;">
            <i class="fa-solid fa-circle-exclamation"></i>
        </div>
        <div class="stat-details">
            <h3>Out of Stock</h3>
            <div class="value" style="color:#b91c1c;"><?php echo $out_of_stock_items ?? 0; ?></div>
            <span class="stat-badge danger">Need restock</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon" style="background: #fff8e1; color: #f57c00;">
            <i class="fa-solid fa-hourglass-half"></i>
        </div>
        <div class="stat-details">
            <h3>Near Expiry</h3>
            <div class="value" style="color:#f57c00;"><?php echo $near_expiry_items ?? 0; ?></div>
            <span class="stat-badge danger">Next 30 days</span>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-icon" style="background: #fee2e2; color: #c62828;">
            <i class="fa-solid fa-calendar-xmark"></i>
        </div>
        <div class="stat-details">
            <h3>Expired</h3>
            <div class="value" style="color:#c62828;"><?php echo $expired_items ?? 0; ?></div>
            <span class="stat-badge danger">Remove now</span>
        </div>
    </div>
</div>

            <!-- Main Dashboard Content -->
            <div class="staff-dashboard-content">
                <!-- Left Column -->
                <div class="left-column">
                    <!-- Recent Products Section -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2>
                                <i class="fa-solid fa-cube"></i>
                                Recently Added Products
                            </h2>
                            <a href="admin/staff_inventory.php" class="view-all-link">
                                View All <i class="fa-regular fa-arrow-right"></i>
                            </a>
                        </div>

                        <?php if (!empty($recent_products)): ?>
                            <div class="products-table">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Product</th>
                                            <th>Price</th>
                                            <th>Stock</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_products as $product): ?>
                                        <?php 
                                            $stock = $product['stock'] ?? 0;
                                            $status_class = 'status-good';
                                            $status_text = 'In Stock';
                                            
                                            if ($stock == 0) {
                                                $status_class = 'status-out';
                                                $status_text = 'Out of Stock';
                                            } elseif ($stock < 10) {
                                                $status_class = 'status-low';
                                                $status_text = 'Low Stock';
                                            }
                                        ?>
                                        <tr>
                                            <td>
                                                <div class="product-info">
                                                    <span class="product-brand"><?php echo htmlspecialchars($product['brand_name']); ?></span>
                                                    <span class="product-generic"><?php echo htmlspecialchars($product['generic_name']); ?></span>
                                                </div>
                                            </td>
                                            <td class="price">₱<?php echo number_format($product['price'], 2); ?></td>
                                            <td class="stock"><?php echo $stock; ?> units</td>
                                            <td><span class="status-badge <?php echo $status_class; ?>"><?php echo $status_text; ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="empty-state">
                                <i class="fa-regular fa-box-open"></i>
                                <p>No products available</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Quick Inventory Stats -->
                    <div class="content-card">
                        <div class="card-header">
                            <h2>
                                <i class="fa-solid fa-chart-pie"></i>
                                Inventory Summary
                            </h2>
                        </div>
                        <div class="inventory-summary">
                            <div class="summary-item">
                                <div class="summary-label">
                                    <span class="dot in-stock"></span>
                                    In Stock
                                </div>
                                <div class="summary-value"><?php echo max(0, ($total_products ?? 0) - ($low_stock_items ?? 0)); ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">
                                    <span class="dot low-stock"></span>
                                    Low Stock
                                </div>
                                <div class="summary-value"><?php echo $low_stock_items ?? 0; ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">
                                    <span class="dot expiring"></span>
                                    Expiring Soon
                                </div>
                                <div class="summary-value"><?php echo $near_expiry_items ?? 0; ?></div>
                            </div>
                        </div>
                        <div class="progress-section">
                            <div class="progress-bar-container">
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo (($total_products ?? 0) > 0) ? (($total_products - ($low_stock_items ?? 0)) / $total_products * 100) : 0; ?>%"></div>
                                </div>
                                <div class="progress-labels">
                                    <span>Healthy Stock</span>
                                    <span><?php echo round((($total_products ?? 0) > 0) ? (($total_products - ($low_stock_items ?? 0)) / $total_products * 100) : 0); ?>%</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div class="right-column">
                    <!-- My Performance Bar Chart -->
                    <div class="content-card performance-card">
                        <div class="card-header">
                            <h2>
                                <i class="fa-solid fa-chart-bar"></i>
                                My Performance — Last 7 Days
                            </h2>
                            <a href="staff/sales_report.php" class="view-all-link" style="font-size:0.8rem;">Full Report →</a>
                        </div>
                        <div style="position: relative; height: 250px;">
                            <canvas id="staffPerfChart"></canvas>
                        </div>
                    </div>

                    <!-- Quick Tips Card -->
                    <div class="content-card tips-card">
                        <div class="card-header">
                            <h2>
                                <i class="fa-solid fa-lightbulb"></i>
                                Quick Tips & Shortcuts
                            </h2>
                        </div>
                        <div class="tips-grid">
                            <div class="tip-item">
                                <span class="key">F2</span>
                                <span class="desc">Focus scanner</span>
                            </div>
                            <div class="tip-item">
                                <span class="key">F3</span>
                                <span class="desc">Search products</span>
                            </div>
                            <div class="tip-item">
                                <span class="key">F4</span>
                                <span class="desc">Enter cash</span>
                            </div>
                            <div class="tip-item">
                                <span class="key">F8</span>
                                <span class="desc">Complete sale</span>
                            </div>
                            <div class="tip-item">
                                <span class="key">F9</span>
                                <span class="desc">Print receipt</span>
                            </div>
                            <div class="tip-item">
                                <span class="key">ESC</span>
                                <span class="desc">Clear cart</span>
                            </div>
                        </div>
                    </div>

                    <!-- Alerts Card -->
                    <?php if (($low_stock_items ?? 0) > 0 || ($near_expiry_items ?? 0) > 0): ?>
                    <div class="content-card alerts-card">
                        <div class="card-header">
                            <h2>
                                <i class="fa-solid fa-bell"></i>
                                Alerts
                            </h2>
                        </div>
                        <div class="alerts-list">
                            <?php if (($low_stock_items ?? 0) > 0): ?>
                            <div class="alert-item warning">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                <div class="alert-content">
                                    <strong><?php echo $low_stock_items; ?> products</strong> are running low on stock
                                    <a href="admin/staff_inventory.php?status=low_stock">View</a>
                                </div>
                            </div>
                            <?php endif; ?>
                            
                            <?php if (($near_expiry_items ?? 0) > 0): ?>
                            <div class="alert-item danger">
                                <i class="fa-solid fa-clock"></i>
                                <div class="alert-content">
                                    <strong><?php echo $near_expiry_items; ?> batches</strong> expiring within 30 days
                                    <a href="admin/staff_inventory.php#expiry">Check</a>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
    // ============================================================
    // CHART.JS INITIALIZATION — ALL DASHBOARDS
    // ============================================================
    const PALETTE = {
        green:       '#004d40',
        gold:        '#b8860b',
        teal:        '#00897b',
        amber:       '#f59e0b',
        red:         '#ef4444',
        blue:        '#3b82f6',
        purple:      '#8b5cf6',
        orange:      '#f97316',
        greenLight:  'rgba(0, 77, 64, 0.15)',
        goldLight:   'rgba(184, 134, 11, 0.15)',
        tealLight:   'rgba(0, 137, 123, 0.15)',
        amberLight:  'rgba(245, 158, 11, 0.15)',
    };

    const tooltipDefaults = {
        backgroundColor: '#1f2937',
        titleColor: '#f9fafb',
        bodyColor: '#e5e7eb',
        padding: 12,
        cornerRadius: 8,
        displayColors: true,
    };

    <?php if (isAdmin()): ?>
    // ── ADMIN: Progressive Multi-Dataset Line Chart ──────────────
    (function() {
        const ctx = document.getElementById('salesChart').getContext('2d');
        const labels   = <?php echo json_encode($chart_labels); ?>;
        const ds1      = <?php echo json_encode($today_data); ?>;
        const ds2      = <?php echo json_encode($last_week_data); ?>;
        const ds3      = <?php echo json_encode($this_month_data); ?>;
        const ds4      = <?php echo json_encode($this_year_data); ?>;

        function makeGrad(ctx, color) {
            const g = ctx.createLinearGradient(0, 0, 0, 380);
            g.addColorStop(0, color.replace('rgb', 'rgba').replace(')', ',0.25)'));
            g.addColorStop(1, color.replace('rgb', 'rgba').replace(')', ',0)'));
            return g;
        }

        // Progressive draw animation via custom plugin
        let drawProgress = 0;
        const progressPlugin = {
            id: 'progressiveDraw',
            beforeDraw(chart) {
                const {ctx: c, chartArea} = chart;
                if (!chartArea) return;
            }
        };

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: "Today's Sales",
                        data: ds1,
                        borderColor: PALETTE.green,
                        backgroundColor: 'rgba(0,77,64,0.12)',
                        borderWidth: 3,
                        pointBackgroundColor: PALETTE.green,
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 8,
                        tension: 0.4,
                        fill: true,
                    },
                    {
                        label: 'Last Week Sales',
                        data: ds2,
                        borderColor: PALETTE.gold,
                        backgroundColor: 'rgba(184,134,11,0.08)',
                        borderWidth: 2.5,
                        pointBackgroundColor: PALETTE.gold,
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 7,
                        tension: 0.4,
                        fill: true,
                        borderDash: [5, 3],
                    },
                    {
                        label: 'This Month (Weekly)',
                        data: ds3,
                        borderColor: PALETTE.teal,
                        backgroundColor: 'rgba(0,137,123,0.07)',
                        borderWidth: 2.5,
                        pointBackgroundColor: PALETTE.teal,
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 7,
                        tension: 0.4,
                        fill: false,
                    },
                    {
                        label: 'This Year (Bi-Monthly)',
                        data: ds4,
                        borderColor: PALETTE.purple,
                        backgroundColor: 'rgba(139,92,246,0.07)',
                        borderWidth: 2,
                        pointBackgroundColor: PALETTE.purple,
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 4,
                        pointHoverRadius: 7,
                        tension: 0.4,
                        fill: false,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: { usePointStyle: true, pointStyleWidth: 10, padding: 16, font: { size: 12 } }
                    },
                    tooltip: {
                        ...tooltipDefaults,
                        callbacks: {
                            label: ctx => ' ' + ctx.dataset.label + ': ₱' + ctx.parsed.y.toLocaleString('en-PH', {minimumFractionDigits:2})
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,0.04)' },
                        ticks: { callback: v => '₱' + (v >= 1000 ? (v/1000).toFixed(1)+'k' : v) }
                    },
                    x: { grid: { display: false } }
                },
                animation: {
                    duration: 2000,
                    easing: 'easeInOutCubic',
                },
                transitions: {
                    active: { animation: { duration: 400 } }
                }
            }
        });
    })();

    // ── ADMIN: Staff Performance Grouped Bar (compact) ─────────────
    (function() {
        const names       = <?php echo json_encode($perf_names); ?>;
        const txCounts    = <?php echo json_encode($perf_tx); ?>;
        const salesTotals = <?php echo json_encode($perf_sales); ?>;

        const ctx = document.getElementById('staffPerfRadar')?.getContext('2d');
        if (!ctx) return;

        if (!names.length) {
            ctx.canvas.parentNode.innerHTML = '<p style="text-align:center;color:#94a3b8;padding-top:3rem;font-size:.85rem;">No staff data this month.</p>';
            return;
        }

        // Short names to fit in compact chart
        const shortNames = names.map(n => n.split(' ')[0]);

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: shortNames,
                datasets: [
                    {
                        label: 'Transactions',
                        data: txCounts,
                        backgroundColor: 'rgba(0, 77, 64, 0.82)',
                        borderColor: '#004d40',
                        borderWidth: 0,
                        borderRadius: 5,
                        yAxisID: 'y',
                    },
                    {
                        label: 'Sales (÷100)',
                        data: salesTotals.map(v => Math.round(v / 100)),
                        backgroundColor: 'rgba(184, 134, 11, 0.80)',
                        borderColor: '#b8860b',
                        borderWidth: 0,
                        borderRadius: 5,
                        yAxisID: 'y',
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: true, position: 'bottom',
                        labels: { usePointStyle: true, padding: 10, font: { size: 10 } }
                    },
                    tooltip: {
                        ...tooltipDefaults,
                        callbacks: {
                            label: c => {
                                if (c.datasetIndex === 0) return ' Transactions: ' + txCounts[c.dataIndex];
                                return ' Sales: ₱' + salesTotals[c.dataIndex].toLocaleString('en-PH', {minimumFractionDigits:2});
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0,0,0,.04)' },
                        ticks: { font: { size: 10 }, maxTicksLimit: 5 }
                    },
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 10 } }
                    }
                },
                animation: { duration: 1200, easing: 'easeOutBack' }
            }
        });
    })();

        // ── ADMIN: Top Products Bar (Dashboard) ───────────────────────
    (function() {
        const names = <?php echo json_encode($dash_top_names); ?>;
        const qty   = <?php echo json_encode($dash_top_qty); ?>;
        const ctx   = document.getElementById('topProductsDash')?.getContext('2d');
        if (!ctx) return;
        if (!names.length) { ctx.canvas.parentNode.innerHTML = '<p style="text-align:center;color:#94a3b8;padding-top:2rem;font-size:.85rem;">No sales data this month.</p>'; return; }

        const colors = [PALETTE.green,PALETTE.teal,PALETTE.gold,PALETTE.amber,PALETTE.purple,PALETTE.blue,PALETTE.orange];
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: names,
                datasets: [{
                    label: 'Qty Sold',
                    data: qty,
                    backgroundColor: colors.map(c => c + 'cc'),
                    borderColor: colors,
                    borderWidth: 2,
                    borderRadius: 6,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { ...tooltipDefaults, callbacks: { label: c => ' ' + c.parsed.x + ' units' } }
                },
                scales: {
                    x: { beginAtZero: true, grid: { color: 'rgba(0,0,0,.04)' }, ticks: { stepSize: 1, font: { size: 10 } } },
                    y: { grid: { display: false }, ticks: { font: { size: 10 } } }
                },
                animation: { duration: 1300, easing: 'easeOutBack' }
            }
        });
    })();

    // ── ADMIN: Category Doughnut (Dashboard) ─────────────────────
    (function() {
        const names = <?php echo json_encode($dash_cat_names); ?>;
        const rev   = <?php echo json_encode($dash_cat_rev); ?>;
        const ctx   = document.getElementById('categoryDash')?.getContext('2d');
        if (!ctx) return;
        if (!names.length) { ctx.canvas.parentNode.innerHTML = '<p style="text-align:center;color:#94a3b8;padding-top:2rem;font-size:.85rem;">No category data this month.</p>'; return; }

        const colors = ['rgba(0,77,64,.85)','rgba(184,134,11,.85)','rgba(13,148,136,.85)',
                        'rgba(139,92,246,.85)','rgba(249,115,22,.85)','rgba(59,130,246,.85)','rgba(236,72,153,.85)'];
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: names,
                datasets: [{
                    data: rev,
                    backgroundColor: colors,
                    borderColor: '#fff',
                    borderWidth: 3,
                    hoverOffset: 8,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                cutout: '60%',
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, padding: 8, font: { size: 10 },
                        generateLabels: chart => chart.data.labels.map((lbl, i) => ({
                            text: lbl.length > 14 ? lbl.slice(0,13)+'…' : lbl,
                            fillStyle: chart.data.datasets[0].backgroundColor[i],
                            strokeStyle: '#fff', lineWidth: 2, hidden: false, index: i
                        }))
                    }},
                    tooltip: { ...tooltipDefaults, callbacks: {
                        label: c => ' ₱' + c.parsed.toLocaleString('en-PH', {minimumFractionDigits:2})
                    }}
                },
                animation: { animateRotate: true, animateScale: true, duration: 1400, easing: 'easeOutBack' }
            }
        });
    })();

    <?php else: ?>
    // ── STAFF: My Performance Bar Chart (Last 7 Days) ────────────
    (function() {
        const labels      = <?php echo json_encode($staff_chart_labels); ?>;
        const txData      = <?php echo json_encode($staff_tx_data); ?>;
        const salesData   = <?php echo json_encode($staff_sales_data); ?>;
        const productData = <?php echo json_encode($staff_products_data); ?>;

        const ctx = document.getElementById('staffPerfChart')?.getContext('2d');
        if (!ctx) return;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Transactions',
                        data: txData,
                        backgroundColor: 'rgba(0, 77, 64, 0.8)',
                        borderColor: PALETTE.green,
                        borderWidth: 2,
                        borderRadius: 6,
                        yAxisID: 'y',
                    },
                    {
                        label: 'Products Sold',
                        data: productData,
                        backgroundColor: 'rgba(184, 134, 11, 0.8)',
                        borderColor: PALETTE.gold,
                        borderWidth: 2,
                        borderRadius: 6,
                        yAxisID: 'y',
                    },
                    {
                        label: 'Sales (₱)',
                        data: salesData,
                        type: 'line',
                        borderColor: PALETTE.teal,
                        backgroundColor: 'rgba(0,137,123,0.1)',
                        borderWidth: 2.5,
                        pointBackgroundColor: PALETTE.teal,
                        pointBorderColor: '#fff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        tension: 0.4,
                        fill: true,
                        yAxisID: 'y1',
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: true,
                        position: 'top',
                        labels: { usePointStyle: true, padding: 12, font: { size: 11 } }
                    },
                    tooltip: {
                        ...tooltipDefaults,
                        callbacks: {
                            label: ctx => {
                                if (ctx.dataset.label === 'Sales (₱)') return ' Sales: ₱' + ctx.parsed.y.toFixed(2);
                                return ' ' + ctx.dataset.label + ': ' + ctx.parsed.y;
                            }
                        }
                    }
                },
                scales: {
                    y:  { beginAtZero: true, position: 'left',  grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { stepSize: 1 } },
                    y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false },
                          ticks: { callback: v => '₱' + (v >= 1000 ? (v/1000).toFixed(0)+'k' : v) } },
                    x: { grid: { display: false } }
                },
                animation: { duration: 1400, easing: 'easeOutQuart' }
            }
        });
    })();

    // ── STAFF: Inventory Pie Chart ────────────────────────────────
    (function() {
        const invData = <?php echo json_encode($inv_pie_data); ?>;
        const ctx = document.getElementById('invPieChart')?.getContext('2d');
        if (!ctx) return;

        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: ['In Stock', 'Low Stock', 'Out of Stock', 'Near Expiry', 'Expired'],
                datasets: [{
                    data: [
                        invData.in_stock,
                        invData.low_stock,
                        invData.out_of_stock,
                        invData.near_expiry,
                        invData.expired
                    ],
                    backgroundColor: [
                        'rgba(0, 137, 123, 0.85)',
                        'rgba(245, 158, 11, 0.85)',
                        'rgba(239, 68, 68, 0.85)',
                        'rgba(251, 146, 60, 0.75)',
                        'rgba(139, 92, 246, 0.80)',
                    ],
                    borderColor: ['#fff','#fff','#fff','#fff','#fff'],
                    borderWidth: 3,
                    hoverOffset: 10,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '62%',
                plugins: {
                    legend: {
                        display: true,
                        position: 'right',
                        labels: {
                            usePointStyle: true,
                            padding: 12,
                            font: { size: 11 },
                            generateLabels: (chart) => {
                                const d = chart.data;
                                return d.labels.map((label, i) => ({
                                    text: label + ' (' + d.datasets[0].data[i] + ')',
                                    fillStyle: d.datasets[0].backgroundColor[i],
                                    strokeStyle: '#fff',
                                    lineWidth: 2,
                                    hidden: false,
                                    index: i,
                                }));
                            }
                        }
                    },
                    tooltip: {
                        ...tooltipDefaults,
                        callbacks: {
                            label: ctx => ' ' + ctx.label + ': ' + ctx.raw + ' products'
                        }
                    }
                },
                animation: { animateRotate: true, animateScale: true, duration: 1500, easing: 'easeOutBack' }
            }
        });
    })();
    <?php endif; ?>
    </script>
    <?php renderNotificationPanel($conn); ?>
<script>
// Disable zoom & pan
(function () {
  // 1. Block Ctrl+/- / Ctrl+Scroll zoom
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && ['+', '-', '=', '_', '0'].indexOf(e.key) !== -1) {
      e.preventDefault();
    }
  });
  document.addEventListener('wheel', function (e) {
    if (e.ctrlKey || e.metaKey) { e.preventDefault(); }
  }, { passive: false });

  // 2. Block pinch-to-zoom & multi-touch pan on touch devices
  document.addEventListener('touchstart', function (e) {
    if (e.touches.length > 1) { e.preventDefault(); }
  }, { passive: false });
  document.addEventListener('touchmove', function (e) {
    if (e.touches.length > 1) { e.preventDefault(); }
    if (e.scale !== undefined && e.scale !== 1) { e.preventDefault(); }
  }, { passive: false });

  // 3. Block gesturestart/change (Safari)
  document.addEventListener('gesturestart', function (e) { e.preventDefault(); }, { passive: false });
  document.addEventListener('gesturechange', function (e) { e.preventDefault(); }, { passive: false });
  document.addEventListener('gestureend', function (e) { e.preventDefault(); }, { passive: false });
})();
</script>

</body>
</html>