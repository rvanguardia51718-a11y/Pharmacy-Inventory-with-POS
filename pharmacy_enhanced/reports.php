<?php
require_once 'config/database.php';
require_once 'includes/auth_check.php';
require_once 'includes/notifications.php';

// Admin and Staff can access reports
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
$is_admin = isAdmin();
$current_user_id = $_SESSION['user_id'] ?? 0;

// Check if this is a print request
$print_mode = isset($_GET['print']) && $_GET['print'] == '1';

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$chart_period = $_GET['chart_period'] ?? '7days';

// Get sales data — Admin sees all, Staff sees only their own
if ($is_admin) {
    $stmt = $conn->prepare("SELECT s.*, u.full_name FROM sales s JOIN users u ON s.user_id = u.id WHERE DATE(s.sale_date) BETWEEN ? AND ? ORDER BY s.sale_date DESC");
    $stmt->bind_param("ss", $start_date, $end_date);
} else {
    $stmt = $conn->prepare("SELECT s.*, u.full_name FROM sales s JOIN users u ON s.user_id = u.id WHERE DATE(s.sale_date) BETWEEN ? AND ? AND s.user_id = ? ORDER BY s.sale_date DESC");
    $stmt->bind_param("ssi", $start_date, $end_date, $current_user_id);
}
$stmt->execute();
$sales_result = $stmt->get_result();

$sales = [];
$total_revenue = 0;
$total_discount = 0;
$total_cogs    = 0;
$total_profit  = 0;
while ($row = $sales_result->fetch_assoc()) {
    $sales[]        = $row;
    $total_revenue  += $row['grand_total'];
    $total_discount += $row['discount'];
}
$stmt->close();

// ── COGS & Sold-As per transaction ────────────────────────────────────────────
//
// COGS = quantity_in_base × cost_per_base_unit
//
// Priority:
//  1. purchase_cost_snapshot — locked at sale time, always correct
//  2. sb.purchase_cost / pu.conversion_to_base — uses ORIGINAL conversion from
//     product_units (NOT sb.quantity_base which decreases as stock is sold)
//  3. p.cost — product master cost per base unit

$sale_ids    = array_column($sales, 'id');
$cogs_map    = [];
$sold_as_map = [];

if (!empty($sale_ids)) {
    $in = implode(',', array_map('intval', $sale_ids));

    $cogs_res = $conn->query("
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
    if ($cogs_res) {
        while ($cr = $cogs_res->fetch_assoc()) {
            $cogs_map[(int)$cr['sale_id']] = (float)$cr['cogs'];
        }
    }

    // Sold-as detail: how many base units vs purchase units per transaction
    $sold_res = $conn->query("
        SELECT si.sale_id,
               SUM(si.quantity_in_base) AS qty_base_total,
               SUM(si.quantity_sold)    AS qty_pu_total,
               GROUP_CONCAT(DISTINCT si.unit_sold  ORDER BY si.unit_sold  SEPARATOR ', ') AS units_sold,
               GROUP_CONCAT(DISTINCT p.base_unit   ORDER BY p.base_unit   SEPARATOR ', ') AS base_units
        FROM sale_items si
        JOIN products p ON si.product_id = p.id
        WHERE si.sale_id IN ($in)
        GROUP BY si.sale_id
    ");
    if ($sold_res) {
        while ($sr = $sold_res->fetch_assoc()) {
            $sold_as_map[(int)$sr['sale_id']] = [
                'qty_base'   => (int)$sr['qty_base_total'],
                'qty_pu'     => (int)$sr['qty_pu_total'],
                'units_sold' => $sr['units_sold'] ?? '—',
                'base_units' => $sr['base_units']  ?? '—',
            ];
        }
    }
}

// Attach cogs + profit + sold-as to each sale
foreach ($sales as &$sale) {
    $sid     = (int)$sale['id'];
    $revenue = (float)$sale['grand_total'];
    $cogs    = $cogs_map[$sid] ?? 0;
    $profit  = $revenue - $cogs;
    $margin  = $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0;
    $sold    = $sold_as_map[$sid] ?? ['qty_base'=>0,'qty_pu'=>0,'units_sold'=>'—','base_units'=>'—'];

    $sale['revenue']    = round($revenue, 2);
    $sale['cogs']       = round($cogs, 2);
    $sale['profit']     = round($profit, 2);
    $sale['margin']     = $margin;
    $sale['outlier']    = ($revenue > 0 && $cogs > $revenue * 1.5);
    $sale['qty_base']   = $sold['qty_base'];
    $sale['qty_pu']     = $sold['qty_pu'];
    $sale['units_sold'] = $sold['units_sold'];
    $sale['base_units'] = $sold['base_units'];

    $total_cogs   += $cogs;
    $total_profit += $profit;
}
unset($sale);
$total_profit  = round($total_profit, 2);
$total_cogs    = round($total_cogs, 2);
$total_margin  = $total_revenue > 0 ? round(($total_profit / $total_revenue) * 100, 1) : 0;
$avg_profit    = count($sales) > 0 ? round($total_profit / count($sales), 2) : 0;
$outlier_count = count(array_filter($sales, fn($s) => $s['outlier']));

// Get sales by staff — Admin sees all staff, Staff sees only their own
if ($is_admin) {
    $staff_stmt = $conn->prepare("SELECT u.full_name, COUNT(*) as transaction_count, SUM(s.grand_total) as total_sales FROM sales s JOIN users u ON s.user_id = u.id WHERE DATE(s.sale_date) BETWEEN ? AND ? GROUP BY s.user_id ORDER BY total_sales DESC");
    $staff_stmt->bind_param("ss", $start_date, $end_date);
} else {
    $staff_stmt = $conn->prepare("SELECT u.full_name, COUNT(*) as transaction_count, SUM(s.grand_total) as total_sales FROM sales s JOIN users u ON s.user_id = u.id WHERE DATE(s.sale_date) BETWEEN ? AND ? AND s.user_id = ? GROUP BY s.user_id ORDER BY total_sales DESC");
    $staff_stmt->bind_param("ssi", $start_date, $end_date, $current_user_id);
}
$staff_stmt->execute();
$staff_result = $staff_stmt->get_result();

$staff_performance = [];
while ($row = $staff_result->fetch_assoc()) {
    $staff_performance[] = $row;
}
$staff_stmt->close();

// Get chart data based on selected period
$chart_data = getChartData($conn, $chart_period);

$avg_sale = count($sales) > 0 ? $total_revenue / count($sales) : 0;

// ── Admin Report Charts: Revenue Overview + Hourly Sales Activity ─

// Hourly Sales Activity — Polar Area (full period summed by hour)
$hour_stmt = $conn->prepare("
    SELECT HOUR(s.sale_date) as hr, COALESCE(SUM(s.grand_total),0) as total
    FROM sales s
    WHERE DATE(s.sale_date) BETWEEN ? AND ?
    " . ($is_admin ? "" : "AND s.user_id = $current_user_id") . "
    GROUP BY HOUR(s.sale_date) ORDER BY hr
");
$hour_stmt->bind_param("ss", $start_date, $end_date);
$hour_stmt->execute();
$hour_result = $hour_stmt->get_result();
$hour_map = [];
while ($row = $hour_result->fetch_assoc()) $hour_map[(int)$row['hr']] = $row['total'];
// 6 time-block buckets for polar chart
$time_blocks  = ['12–4 AM', '4–8 AM', '8–12 PM', '12–4 PM', '4–8 PM', '8–12 AM'];
$block_totals = [0, 0, 0, 0, 0, 0];
for ($h = 0; $h < 24; $h++) {
    $bucket = (int)floor($h / 4);
    if ($bucket >= 6) $bucket = 5;
    $block_totals[$bucket] += (float)($hour_map[$h] ?? 0);
}
$hour_stmt->close();



function getChartData($conn, $period) {
    $data = [];
    
    switch($period) {
        case 'today':
            $query = "SELECT 
                        HOUR(sale_date) as hour,
                        SUM(grand_total) as total
                      FROM sales 
                      WHERE DATE(sale_date) = CURDATE()
                      GROUP BY HOUR(sale_date)
                      ORDER BY hour ASC";
            $result = $conn->query($query);
            
            for ($i = 0; $i < 24; $i++) {
                $data[] = [
                    'label' => sprintf("%02d:00", $i),
                    'value' => 0,
                    'full_label' => date('h A', strtotime("$i:00"))
                ];
            }
            
            while ($row = $result->fetch_assoc()) {
                $data[$row['hour']]['value'] = $row['total'];
            }
            break;
            
        case '7days':
            $query = "SELECT 
                        DATE(sale_date) as date,
                        SUM(grand_total) as total
                      FROM sales 
                      WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                      GROUP BY DATE(sale_date)
                      ORDER BY date ASC";
            $result = $conn->query($query);
            
            for ($i = 6; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $data[$date] = [
                    'label' => date('D', strtotime($date)),
                    'full_label' => date('l, M d', strtotime($date)),
                    'value' => 0
                ];
            }
            
            while ($row = $result->fetch_assoc()) {
                if (isset($data[$row['date']])) {
                    $data[$row['date']]['value'] = $row['total'];
                }
            }
            $data = array_values($data);
            break;
            
        case 'month':
            $query = "SELECT 
                        DATE(sale_date) as date,
                        SUM(grand_total) as total
                      FROM sales 
                      WHERE MONTH(sale_date) = MONTH(CURDATE()) 
                        AND YEAR(sale_date) = YEAR(CURDATE())
                      GROUP BY DATE(sale_date)
                      ORDER BY date ASC";
            $result = $conn->query($query);
            
            $days_in_month = date('t');
            
            for ($i = 1; $i <= $days_in_month; $i++) {
                $date = date('Y-m-') . sprintf("%02d", $i);
                $data[$date] = [
                    'label' => date('j', strtotime($date)),
                    'full_label' => date('M j, Y', strtotime($date)),
                    'value' => 0
                ];
            }
            
            while ($row = $result->fetch_assoc()) {
                if (isset($data[$row['date']])) {
                    $data[$row['date']]['value'] = $row['total'];
                }
            }
            $data = array_values($data);
            break;
    }
    
    return $data;
}

function formatPHP($amount) {
    return '₱' . number_format($amount, 2);
}

// If print mode, use the print template
if ($print_mode) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
        <title>Sales Report - CAD Crest</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
                line-height: 1.5;
                color: #1f2937;
                background: white;
                padding: 0.5in;
            }

            .report-header {
                text-align: center;
                margin-bottom: 2rem;
                padding-bottom: 1rem;
                border-bottom: 2px solid #1a5f4b;
            }

            .company-name {
                font-size: 28px;
                font-weight: 800;
                color: #1a5f4b;
                letter-spacing: -0.02em;
                margin-bottom: 0.5rem;
            }

            .report-title {
                font-size: 20px;
                font-weight: 600;
                color: #374151;
                margin-bottom: 0.5rem;
            }

            .report-period {
                font-size: 14px;
                color: #6b7280;
            }

            .summary-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 1rem;
                margin-bottom: 2rem;
                page-break-inside: avoid;
            }

            .summary-card {
                border: 1px solid #e5e7eb;
                padding: 1rem;
                border-radius: 0.5rem;
                background: #f9fafb;
            }

            .summary-card h3 {
                font-size: 12px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: #6b7280;
                margin-bottom: 0.5rem;
            }

            .summary-card .value {
                font-size: 24px;
                font-weight: 700;
                color: #1a5f4b;
            }

            .staff-section {
                margin-bottom: 2rem;
                page-break-inside: avoid;
            }

            .staff-section h2 {
                font-size: 16px;
                font-weight: 700;
                color: #1f2937;
                margin-bottom: 1rem;
                padding-bottom: 0.5rem;
                border-bottom: 2px solid #1a5f4b;
            }

            .staff-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 12px;
            }

            .staff-table th {
                background: #f3f4f6;
                padding: 0.75rem;
                text-align: left;
                font-weight: 600;
                color: #374151;
                border-bottom: 2px solid #d1d5db;
            }

            .staff-table td {
                padding: 0.75rem;
                border-bottom: 1px solid #e5e7eb;
            }

            .transactions-section {
                margin-bottom: 2rem;
                page-break-inside: avoid;
            }

            .transactions-section h2 {
                font-size: 16px;
                font-weight: 700;
                color: #1f2937;
                margin-bottom: 1rem;
                padding-bottom: 0.5rem;
                border-bottom: 2px solid #1a5f4b;
            }

            .transactions-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 11px;
            }

            .transactions-table th {
                background: #f3f4f6;
                padding: 0.75rem 0.5rem;
                text-align: left;
                font-weight: 600;
                color: #374151;
                border-bottom: 2px solid #d1d5db;
            }

            .transactions-table td {
                padding: 0.5rem;
                border-bottom: 1px solid #e5e7eb;
            }

            .transactions-table tfoot td {
                background: #f9fafb;
                font-weight: 700;
                border-top: 2px solid #d1d5db;
            }

            .text-right {
                text-align: right;
            }

            .transaction-id {
                font-family: 'Courier New', monospace;
                font-weight: 600;
                color: #1a5f4b;
            }

            .report-footer {
                margin-top: 2rem;
                padding-top: 1rem;
                border-top: 1px solid #e5e7eb;
                font-size: 10px;
                color: #6b7280;
                text-align: center;
                page-break-after: avoid;
            }

            .generated-date {
                margin-top: 0.5rem;
            }

            @media print {
                body {
                    padding: 0;
                }
                .transactions-table tr {
                    page-break-inside: avoid;
                }
                .staff-table tr {
                    page-break-inside: avoid;
                }
            }
        </style>
    </head>
    <body>
        <div class="report-header">
            <div class="company-name">CAD CREST</div>
            <div class="report-title">Sales Transaction Report</div>
            <div class="report-period">
                <i class="fa-regular fa-calendar"></i>
                <?php echo date('F d, Y', strtotime($start_date)); ?> — <?php echo date('F d, Y', strtotime($end_date)); ?>
            </div>
        </div>

        <div class="transactions-section">
            <table class="transactions-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>ID</th>
                        <th>Staff</th>
                        <th class="text-right">Subtotal</th>
                        <th class="text-right">Discount</th>
                        <th class="text-right">Tax</th>
                        <th class="text-right">Total</th>
                        <th class="text-right">Sold (Base)</th>
                        <th class="text-right">Sold (Purchased)</th>
                        <th class="text-right" style="color:#2563eb;">Revenue</th>
                        <th class="text-right" style="color:#dc2626;">COGS</th>
                        <th class="text-right" style="color:#16a34a;">Profit</th>
                        <th class="text-right" style="color:#7c3aed;">Margin</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sales)): ?>
                        <tr>
                            <td colspan="14" style="text-align:center;padding:2rem;color:#6b7280;">
                                No transactions found for the selected period
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sales as $sale): ?>
                        <tr style="<?php echo $sale['outlier'] ? 'background:#fffbeb;' : ''; ?>">
                            <td><?php echo date('M d, Y', strtotime($sale['sale_date'])); ?></td>
                            <td><?php echo date('h:i A', strtotime($sale['sale_date'])); ?></td>
                            <td>
                                <span class="transaction-id">#<?php echo str_pad($sale['id'], 6, '0', STR_PAD_LEFT); ?></span>
                                <?php if ($sale['outlier']): ?><span style="color:#f59e0b;" title="COGS > Revenue — check cost data"> ⚠️</span><?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($sale['full_name']); ?></td>
                            <td class="text-right"><?php echo formatPHP($sale['total_amount']); ?></td>
                            <td class="text-right">-<?php echo formatPHP($sale['discount']); ?></td>
                            <td class="text-right"><?php echo formatPHP($sale['tax']); ?></td>
                            <td class="text-right"><strong><?php echo formatPHP($sale['grand_total']); ?></strong></td>
                            <td class="text-right" style="font-size:.82rem;"><?php echo $sale['qty_base']; ?> <small><?php echo htmlspecialchars($sale['base_units']); ?></small></td>
                            <td class="text-right" style="font-size:.82rem;"><?php echo $sale['qty_pu']; ?> <small><?php echo htmlspecialchars($sale['units_sold']); ?></small></td>
                            <td class="text-right" style="color:#2563eb;font-weight:600;"><?php echo formatPHP($sale['revenue']); ?></td>
                            <td class="text-right" style="color:#dc2626;font-weight:600;"><?php echo formatPHP($sale['cogs']); ?></td>
                            <td class="text-right" style="font-weight:700;color:<?php echo $sale['profit'] >= 0 ? '#16a34a' : '#dc2626'; ?>;"><?php echo formatPHP($sale['profit']); ?></td>
                            <td class="text-right" style="font-weight:600;color:#7c3aed;"><?php echo $sale['margin']; ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($sales)): ?>
                <tfoot>
                    <tr>
                        <td colspan="4"><strong>Grand Totals</strong></td>
                        <td class="text-right"><strong><?php echo formatPHP(array_sum(array_column($sales, 'total_amount'))); ?></strong></td>
                        <td class="text-right"><strong><?php echo formatPHP(array_sum(array_column($sales, 'discount'))); ?></strong></td>
                        <td class="text-right"><strong><?php echo formatPHP(array_sum(array_column($sales, 'tax'))); ?></strong></td>
                        <td class="text-right"><strong><?php echo formatPHP($total_revenue); ?></strong></td>
                        <td class="text-right"><strong><?php echo array_sum(array_column($sales,'qty_base')); ?></strong></td>
                        <td class="text-right"><strong><?php echo array_sum(array_column($sales,'qty_pu')); ?></strong></td>
                        <td class="text-right" style="color:#2563eb;"><strong><?php echo formatPHP(array_sum(array_column($sales,'revenue'))); ?></strong></td>
                        <td class="text-right" style="color:#dc2626;"><strong><?php echo formatPHP($total_cogs); ?></strong></td>
                        <td class="text-right" style="color:<?php echo $total_profit >= 0 ? '#16a34a' : '#dc2626'; ?>;"><strong><?php echo formatPHP($total_profit); ?></strong></td>
                        <td class="text-right" style="color:#7c3aed;"><strong><?php echo $total_margin; ?>%</strong></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>

        <div class="report-footer">
            <div>CAD Crest - Official Sales Report</div>
            <div class="generated-date">
                Generated on: <?php echo date('F d, Y h:i A'); ?>
            </div>
            <div style="margin-top: 1rem;">
                This is a computer-generated document. No signature is required.
            </div>
        </div>

        <script>
            window.onload = function() {
                window.print();
            };
        </script>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Sales Reports | CAD Crest</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');
        
        /* Page-specific styles only */
        :root {
            --primary: #1a5f4b;
            --primary-light: #e8f3f0;
            --primary-dark: #0e3d30;
            --accent: #c89f3c;
            --accent-light: #faf3e0;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --success: #059669;
            --danger: #dc2626;
            --warning: #d97706;
            --info: #2563eb;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1), 0 2px 4px -2px rgb(0 0 0 / 0.1);
            --shadow-md: 0 10px 15px -3px rgb(0 0 0 / 0.1), 0 4px 6px -4px rgb(0 0 0 / 0.1);
            --radius: 0.5rem;
            --radius-lg: 1rem;
            --radius-xl: 1.5rem;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f6f9fc 0%, #f1f5f9 100%);
            color: var(--gray-800);
            line-height: 1.5;
            min-height: 100vh;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2.5rem;
            flex-wrap: wrap;
            gap: 1.5rem;
        }

        .header-title h1 {
            font-size: 2.5rem;
            font-weight: 800;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.02em;
        }

        .header-title p {
            color: var(--gray-500);
            font-size: 1rem;
            font-weight: 500;
            margin-top: 0.25rem;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .btn-generate-report {
            padding: 0.625rem 1.25rem;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            transition: all 0.2s;
            text-decoration: none;
            white-space: nowrap;
            background: var(--primary);
            color: white;
            box-shadow: var(--shadow-sm);
        }

        .btn-generate-report:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* Filter Section */
        .filter-section {
            background: white;
            padding: 1.5rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1.5rem;
            border: 1px solid var(--gray-200);
        }

        .filter-group {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .filter-item {
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
        }

        .filter-item label {
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--gray-500);
        }

        .filter-item input {
            padding: 0.625rem 1rem;
            border: 2px solid var(--gray-200);
            border-radius: var(--radius);
            font-family: inherit;
            font-size: 0.875rem;
            color: var(--gray-700);
            background: var(--gray-50);
            min-width: 200px;
            transition: all 0.2s;
        }

        .filter-item input:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
            box-shadow: 0 0 0 4px rgba(26, 95, 75, 0.1);
        }

        .btn {
            padding: 0.625rem 1.25rem;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.875rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            transition: all 0.2s;
            text-decoration: none;
            white-space: nowrap;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* ===== Consistent Card Styles - All cards uniform ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
            width: 100%;
        }

        .stat-card {
            background: white;
            border-radius: 24px;
            padding: 1.2rem;
            box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.05);
            display: flex;
            align-items: center;
            gap: 0.8rem;
            transition: all 0.2s ease;
            border: 1px solid rgba(0,0,0,0.02);
            position: relative;
            overflow: hidden;
            min-width: 0;
            width: 100%;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #004d40, #b8860b);
            opacity: 0;
            transition: opacity 0.2s ease;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 35px -10px rgba(0, 77, 64, 0.2);
        }

        .stat-card:hover::before {
            opacity: 1;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.4rem;
            transition: all 0.2s ease;
            flex-shrink: 0;
            background: #e8f5e9;
            color: #004d40;
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.05) rotate(3deg);
        }

        .stat-details {
            flex: 1;
            min-width: 0;
        }

        .stat-details h3 {
            font-size: 0.7rem;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.2rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .stat-details .value {
            font-size: 1.4rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.2;
            margin-bottom: 0.2rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .stat-badge {
            display: inline-block;
            font-size: 0.65rem;
            padding: 0.15rem 0.6rem;
            background: #f1f5f9;
            border-radius: 40px;
            color: #475569;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 100%;
        }

        .stat-badge.success {
            background: #e8f5e9;
            color: #004d40;
        }

        .stat-badge.warning {
            background: #fff3e0;
            color: #b8860b;
        }

        .stat-badge.danger {
            background: #fee2e2;
            color: #b91c1c;
        }

        .chart-section {
            background: white;
            padding: 2rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            border: 1px solid var(--gray-200);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .chart-header h2 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-800);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .chart-header h2 i {
            color: var(--primary);
            font-size: 1.5rem;
        }

        .period-selector {
            display: flex;
            gap: 0.375rem;
            background: var(--gray-100);
            padding: 0.25rem;
            border-radius: var(--radius-lg);
        }

        .period-btn {
            padding: 0.5rem 1.25rem;
            border: none;
            background: transparent;
            border-radius: var(--radius);
            font-weight: 600;
            font-size: 0.875rem;
            color: var(--gray-600);
            cursor: pointer;
            transition: all 0.2s;
        }

        .period-btn.active {
            background: white;
            color: var(--primary);
            box-shadow: var(--shadow-sm);
        }

        .chart-container {
            height: 400px;
            position: relative;
            margin-top: 1rem;
        }

        /* Staff Performance */
        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--gray-800);
            margin: 2rem 0 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .section-title i {
            color: var(--primary);
            font-size: 1.75rem;
        }

        .staff-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }

        .staff-card {
            background: white;
            padding: 1.75rem;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            position: relative;
            transition: all 0.3s;
        }

        .staff-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
        }

        .staff-rank {
            position: absolute;
            top: 1.25rem;
            right: 1.25rem;
            width: 32px;
            height: 32px;
            background: var(--accent-light);
            color: var(--accent);
            border-radius: 9999px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.875rem;
            border: 2px solid white;
        }

        .staff-card h4 {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-800);
            margin-bottom: 1rem;
            padding-right: 2.5rem;
        }

        .staff-sales {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 0.75rem;
        }

        .staff-metrics {
            display: flex;
            gap: 1.5rem;
            color: var(--gray-600);
            font-size: 0.875rem;
            margin-bottom: 1.25rem;
        }

        .staff-progress {
            width: 100%;
            height: 8px;
            background: var(--gray-200);
            border-radius: 9999px;
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--accent));
            border-radius: 9999px;
            transition: width 1s ease;
        }

        /* Transactions Table - Enhanced with straight lines and uniform colors */
        .table-container {
            background: white;
            border-radius: var(--radius-lg);
            padding: 1.5rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            overflow: hidden;
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .table-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--gray-800);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .badge {
            background: var(--primary-light);
            color: var(--primary);
            padding: 0.375rem 1rem;
            border-radius: 9999px;
            font-weight: 600;
            font-size: 0.875rem;
        }

        .table-responsive {
            overflow-x: auto;
            border-radius: var(--radius);
        }

        .modern-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            min-width: 900px;
            border: 1px solid var(--gray-200);
        }

        .modern-table th {
            text-align: left;
            padding: 1rem 0.8rem;
            background: var(--gray-50);
            color: var(--gray-700);
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            border: 1px solid var(--gray-200);
            white-space: nowrap;
        }

        .modern-table td {
            padding: 1rem 0.8rem;
            border: 1px solid var(--gray-200);
            color: var(--gray-700);
            font-size: 0.85rem;
            vertical-align: middle;
        }

        .modern-table tbody tr:hover {
            background: var(--gray-50);
        }

        .modern-table tfoot td {
            padding: 1rem 0.8rem;
            border: 1px solid var(--gray-200);
            background: var(--gray-50);
            font-weight: 700;
            color: var(--gray-800);
        }

        .transaction-id {
            background: var(--gray-100);
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-family: 'Courier New', monospace;
            font-weight: 600;
            font-size: 0.8125rem;
            color: var(--primary);
            display: inline-block;
        }

        .text-right {
            text-align: right;
        }

        .amount-positive {
            color: var(--primary);
            font-weight: 600;
        }

        .amount-discount {
            color: var(--danger);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--gray-500);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: var(--gray-400);
        }

        .empty-state h3 {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--gray-600);
            margin-bottom: 0.5rem;
        }

        /* Transaction Detail Modal */
        #transactionModal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(2px);
            align-items: center;
            justify-content: center;
        }

        #transactionModal .modal-content {
            background: #fff;
            border-radius: 1rem;
            width: 90%;
            max-width: 720px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: modalPop 0.2s ease;
        }

        @keyframes modalPop {
            from { opacity:0; transform:scale(0.95) translateY(-10px); }
            to   { opacity:1; transform:scale(1) translateY(0); }
        }

        .clickable-row:hover { 
            background: #f0faf7 !important; 
            cursor: pointer;
        }
        .clickable-row:hover .transaction-id { 
            background: #d1fae5; 
            color: #059669; 
        }

        /* Responsive Breakpoints */
        @media (max-width: 1400px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 1.2rem;
            }
            
            .stat-details .value {
                font-size: 1.3rem;
            }
        }

        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
                gap: 1rem;
            }
            
            .staff-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 992px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .header-actions {
                width: 100%;
                justify-content: flex-start;
            }
            
            .filter-section {
                flex-direction: column;
                align-items: stretch;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .filter-item {
                flex: 1;
            }
            
            .staff-grid {
                grid-template-columns: 1fr;
            }
            
            .chart-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .period-selector {
                width: 100%;
                justify-content: center;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-icon {
                width: 42px;
                height: 42px;
                font-size: 1.2rem;
            }
            
            .stat-details .value {
                font-size: 1.2rem;
            }
            
            .filter-group {
                flex-direction: column;
                width: 100%;
            }
            
            .filter-item {
                width: 100%;
            }
            
            .filter-item input {
                width: 100%;
            }
            
            .chart-section {
                padding: 1rem;
            }
            
            .chart-container {
                height: 300px;
            }

            /* Charts row stacks on mobile */
            .charts-side-by-side {
                grid-template-columns: 1fr !important;
            }
            
            .table-container {
                padding: 1rem;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .section-title {
                font-size: 1.3rem;
                margin: 1.5rem 0 1rem;
            }
            
            .modal-content {
                width: 95%;
            }
            
            .modern-table th,
            .modern-table td {
                padding: 0.75rem 0.5rem;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 0.8rem;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-icon {
                width: 48px;
                height: 48px;
                font-size: 1.4rem;
            }
            
            .stat-details .value {
                font-size: 1.4rem;
            }
            
            .stat-badge {
                font-size: 0.65rem;
                padding: 0.15rem 0.6rem;
            }
            
            .header-title h1 {
                font-size: 2rem;
            }
            
            .btn-generate-report {
                padding: 0.5rem 1rem;
                font-size: 0.8rem;
            }
            
            .period-selector {
                flex-wrap: wrap;
            }
            
            .period-btn {
                padding: 0.4rem 0.8rem;
                font-size: 0.8rem;
            }
            
            .staff-card {
                padding: 1.25rem;
            }
            
            .staff-sales {
                font-size: 1.6rem;
            }
        }

        @media print {
            .sidebar, .filter-section, .period-selector, .header-actions .btn-generate-report {
                display: none !important;
            }
            
            .main-content {
                padding: 0.5in;
            }
            
            .stat-card, .chart-section, .staff-card, .table-container {
                break-inside: avoid;
                box-shadow: none;
                border: 1px solid #000;
            }
            
            .modern-table {
                border: 1px solid #000;
            }
            
            .modern-table th,
            .modern-table td {
                border: 1px solid #000;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="header-title">
                <h1>Sales Analytics</h1>
                <p><i class="fa-regular fa-calendar"></i> <?php echo date('F d, Y', strtotime($start_date)); ?> — <?php echo date('F d, Y', strtotime($end_date)); ?></p>
            </div>
            <div class="header-actions">
                <a href="?start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>&chart_period=<?php echo urlencode($chart_period); ?>&print=1" 
                   target="_blank" 
                   class="btn-generate-report">
                    <i class="fa-solid fa-file-pdf"></i> Generate Report
                </a>
                <?php renderNotificationBell($conn); ?>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="filter-section">
            <form class="filter-group" method="GET">
                <div class="filter-item">
                    <label><i class="fa-regular fa-calendar"></i> From</label>
                    <input type="date" name="start_date" value="<?php echo $start_date; ?>">
                </div>
                <div class="filter-item">
                    <label><i class="fa-regular fa-calendar"></i> To</label>
                    <input type="date" name="end_date" value="<?php echo $end_date; ?>">
                </div>
                <input type="hidden" name="chart_period" value="<?php echo $chart_period; ?>">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-magnifying-glass"></i> Apply Filter
                </button>
            </form>
        </div>

        <!-- Stats Cards - All cards now uniform with same icon background -->
        <div class="stats-grid">
            <!-- Total Revenue Card -->
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-chart-line"></i>
                </div>
                <div class="stat-details">
                    <h3>Total Revenue</h3>
                    <div class="value"><?php echo formatPHP($total_revenue); ?></div>
                    <span class="stat-badge success"><i class="fa-regular fa-circle-check"></i> Net sales</span>
                </div>
            </div>

            <!-- Transactions Card -->
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-receipt"></i>
                </div>
                <div class="stat-details">
                    <h3>Transactions</h3>
                    <div class="value"><?php echo count($sales); ?></div>
                    <span class="stat-badge"><i class="fa-regular fa-clock"></i> Total orders</span>
                </div>
            </div>

            <!-- Average Sale Card -->
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-calculator"></i>
                </div>
                <div class="stat-details">
                    <h3>Average Sale</h3>
                    <div class="value"><?php echo formatPHP($avg_sale); ?></div>
                    <span class="stat-badge"><i class="fa-regular fa-star"></i> Per transaction</span>
                </div>
            </div>

            <!-- Total COGS Card -->
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-box-open"></i>
                </div>
                <div class="stat-details">
                    <h3>Total COGS</h3>
                    <div class="value"><?php echo formatPHP($total_cogs); ?></div>
                    <span class="stat-badge danger"><i class="fa-regular fa-box"></i> Cost of goods sold</span>
                </div>
            </div>

            <!-- Total Profit Card -->
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-sack-dollar"></i>
                </div>
                <div class="stat-details">
                    <h3>Total Profit</h3>
                    <div class="value" style="color: <?php echo $total_profit >= 0 ? '#16a34a' : '#dc2626'; ?>;"><?php echo formatPHP($total_profit); ?></div>
                    <span class="stat-badge <?php echo $total_profit >= 0 ? 'success' : 'danger'; ?>">
                        <i class="fa-regular fa-arrow-trend-<?php echo $total_profit >= 0 ? 'up' : 'down'; ?>"></i> 
                        Revenue − COGS
                    </span>
                </div>
            </div>

            <!-- Profit Margin Card -->
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-percent"></i>
                </div>
                <div class="stat-details">
                    <h3>Profit Margin</h3>
                    <div class="value"><?php echo $total_margin; ?>%</div>
                    <span class="stat-badge">
                        <i class="fa-regular fa-chart-pie"></i> 
                        Avg ₱<?php echo number_format($avg_profit,2); ?>/txn
                        <?php if ($outlier_count > 0): ?> · <span style="color:#f59e0b;"><i class="fa-solid fa-triangle-exclamation"></i> <?php echo $outlier_count; ?> outlier<?php echo $outlier_count>1?'s':''; ?></span><?php endif; ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ── Charts Row: Revenue Overview + Hourly Sales Activity ──────── -->
        <div class="charts-side-by-side" style="display:grid; grid-template-columns:1fr 1fr; gap:1.5rem; margin-bottom:2rem; align-items:start;">

            <!-- Revenue Overview -->
            <div style="background:#fff; border-radius:20px; padding:1.5rem; box-shadow:0 4px 20px rgba(0,0,0,.05);">
                <div class="chart-header" style="margin-bottom:1rem;">
                    <h2>
                        <i class="fa-solid fa-chart-line"></i>
                        Revenue Overview
                    </h2>
                    <div class="period-selector">
                        <button class="period-btn <?php echo $chart_period == 'today' ? 'active' : ''; ?>" onclick="changePeriod('today')">
                            <i class="fa-regular fa-clock"></i> Today
                        </button>
                        <button class="period-btn <?php echo $chart_period == '7days' ? 'active' : ''; ?>" onclick="changePeriod('7days')">
                            <i class="fa-regular fa-calendar-week"></i> 7 Days
                        </button>
                        <button class="period-btn <?php echo $chart_period == 'month' ? 'active' : ''; ?>" onclick="changePeriod('month')">
                            <i class="fa-regular fa-calendar"></i> This Month
                        </button>
                    </div>
                </div>
                <div style="position:relative; height:280px;">
                    <canvas id="salesChart"></canvas>
                </div>
            </div>

            <!-- Hourly Sales Activity -->
            <?php if ($is_admin): ?>
            <div style="background:#fff; border-radius:20px; padding:1.5rem; box-shadow:0 4px 20px rgba(0,0,0,.05);">
                <p style="margin:0 0 1rem; font-size:.85rem; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em;">
                    <i class="fa-solid fa-clock" style="color:#0d9488; margin-right:.5rem;"></i>Hourly Sales Activity — Selected Period
                </p>
                <div style="position:relative; height:280px;">
                    <canvas id="hourlyChart"></canvas>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <!-- Staff Performance -->
        <h2 class="section-title">
            <i class="fa-solid fa-users"></i>
            Staff Performance
        </h2>
        
        <div class="staff-grid">
            <?php if (empty($staff_performance)): ?>
                <div class="empty-state" style="grid-column: 1/-1;">
                    <i class="fa-regular fa-face-frown"></i>
                    <h3>No Data Available</h3>
                    <p>No staff performance data for this period</p>
                </div>
            <?php else: ?>
                <?php 
                $max_sales = max(array_column($staff_performance, 'total_sales'));
                foreach ($staff_performance as $index => $staff): 
                ?>
                <div class="staff-card">
                    <div class="staff-rank">#<?php echo $index + 1; ?></div>
                    <h4><?php echo htmlspecialchars($staff['full_name']); ?></h4>
                    <div class="staff-sales"><?php echo formatPHP($staff['total_sales']); ?></div>
                    <div class="staff-metrics">
                        <span><i class="fa-regular fa-file-lines"></i> <?php echo $staff['transaction_count']; ?> trans</span>
                        <span><i class="fa-regular fa-star"></i> <?php echo formatPHP($staff['total_sales'] / $staff['transaction_count']); ?> avg</span>
                    </div>
                    <div class="staff-progress">
                        <div class="progress-bar" style="width: <?php echo ($staff['total_sales'] / $max_sales) * 100; ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Transactions Table - Enhanced with borders and uniform colors -->
        <div class="table-container">
            <div class="table-header">
                <h3 class="table-title">
                    <i class="fa-regular fa-rectangle-list"></i>
                    Transaction Details
                </h3>
                <span class="badge">
                    <i class="fa-regular fa-file-lines"></i> <?php echo count($sales); ?> records
                </span>
            </div>
            
            <div class="table-responsive">
                <table class="modern-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Time</th>
                            <th>ID</th>
                            <th>Staff</th>
                            <th class="text-right">Subtotal</th>
                            <th class="text-right">Discount</th>
                            <th class="text-right">Tax</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">Sold (Base)</th>
                            <th class="text-right">Sold (Purchased)</th>
                            <th class="text-right">Revenue</th>
                            <th class="text-right">COGS</th>
                            <th class="text-right">Profit</th>
                            <th class="text-right">Margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($sales)): ?>
                            <tr>
                                <td colspan="14" class="empty-state">
                                    <i class="fa-regular fa-file-excel"></i>
                                    <p>No transactions found</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($sales as $sale): ?>
                            <tr class="clickable-row"
                                onclick="openTransactionModal(<?php echo $sale['id']; ?>)"
                                style="<?php echo $sale['outlier'] ? 'background:#fffbeb;' : ''; ?>"
                                title="<?php echo $sale['outlier'] ? '⚠️ COGS exceeds revenue — check cost data' : 'Click to view details'; ?>">
                                <td><?php echo date('M d, Y', strtotime($sale['sale_date'])); ?></td>
                                <td><?php echo date('h:i A', strtotime($sale['sale_date'])); ?></td>
                                <td>
                                    <span class="transaction-id">#<?php echo str_pad($sale['id'], 6, '0', STR_PAD_LEFT); ?></span>
                                    <?php if ($sale['outlier']): ?><span style="color:#f59e0b;margin-left:4px;" title="COGS > Revenue"><i class="fa-solid fa-triangle-exclamation"></i></span><?php endif; ?>
                                </td>
                                <td>
                                    <i class="fa-regular fa-circle-user" style="color:var(--primary);margin-right:0.25rem;"></i>
                                    <?php echo htmlspecialchars($sale['full_name']); ?>
                                </td>
                                <td class="text-right amount-positive"><?php echo formatPHP($sale['total_amount']); ?></td>
                                <td class="text-right amount-discount">-<?php echo formatPHP($sale['discount']); ?></td>
                                <td class="text-right amount-positive"><?php echo formatPHP($sale['tax']); ?></td>
                                <td class="text-right" style="font-weight:700;"><?php echo formatPHP($sale['grand_total']); ?></td>
                                <td class="text-right">
                                    <?php echo $sale['qty_base']; ?> <small><?php echo htmlspecialchars($sale['base_units']); ?></small>
                                </td>
                                <td class="text-right">
                                    <?php echo $sale['qty_pu']; ?> <small><?php echo htmlspecialchars($sale['units_sold']); ?></small>
                                </td>
                                <td class="text-right"><?php echo formatPHP($sale['revenue']); ?></td>
                                <td class="text-right"><?php echo formatPHP($sale['cogs']); ?></td>
                                <td class="text-right" style="color:<?php echo $sale['profit'] >= 0 ? '#16a34a' : '#dc2626'; ?>; font-weight:700;"><?php echo formatPHP($sale['profit']); ?></td>
                                <td class="text-right"><?php echo $sale['margin']; ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php if (!empty($sales)): ?>
                    <tfoot>
                        <tr>
                            <td colspan="4"><strong>Summary Totals</strong></td>
                            <td class="text-right"><strong><?php echo formatPHP(array_sum(array_column($sales, 'total_amount'))); ?></strong></td>
                            <td class="text-right"><strong>-<?php echo formatPHP(array_sum(array_column($sales, 'discount'))); ?></strong></td>
                            <td class="text-right"><strong><?php echo formatPHP(array_sum(array_column($sales, 'tax'))); ?></strong></td>
                            <td class="text-right"><strong><?php echo formatPHP($total_revenue); ?></strong></td>
                            <td class="text-right"><strong><?php echo array_sum(array_column($sales,'qty_base')); ?></strong></td>
                            <td class="text-right"><strong><?php echo array_sum(array_column($sales,'qty_pu')); ?></strong></td>
                            <td class="text-right"><strong><?php echo formatPHP(array_sum(array_column($sales,'revenue'))); ?></strong></td>
                            <td class="text-right"><strong><?php echo formatPHP($total_cogs); ?></strong></td>
                            <td class="text-right" style="color:<?php echo $total_profit >= 0 ? '#16a34a' : '#dc2626'; ?>;"><strong><?php echo formatPHP($total_profit); ?></strong></td>
                            <td class="text-right"><strong><?php echo $total_margin; ?>%</strong></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>

    <script>
        let salesChart;
        
        function initChart() {
            const ctx = document.getElementById('salesChart').getContext('2d');
            const chartData = <?php echo json_encode($chart_data); ?>;
            
            const labels = chartData.map(item => item.full_label || item.label);
            const values = chartData.map(item => item.value);
            
            if (salesChart) salesChart.destroy();
            
            const gradient = ctx.createLinearGradient(0, 0, 0, 400);
            gradient.addColorStop(0, 'rgba(26, 95, 75, 0.3)');
            gradient.addColorStop(0.5, 'rgba(26, 95, 75, 0.1)');
            gradient.addColorStop(1, 'rgba(26, 95, 75, 0)');
            
            salesChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Revenue',
                        data: values,
                        borderColor: '#1a5f4b',
                        backgroundColor: gradient,
                        borderWidth: 3,
                        pointBackgroundColor: '#c89f3c',
                        pointBorderColor: 'white',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 8,
                        pointHoverBackgroundColor: '#c89f3c',
                        pointHoverBorderColor: 'white',
                        pointHoverBorderWidth: 3,
                        fill: true,
                        tension: 0.4,
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#1f2937',
                            titleColor: '#f9fafb',
                            bodyColor: '#e5e7eb',
                            padding: 12,
                            cornerRadius: 8,
                            displayColors: false,
                            callbacks: {
                                label: function(context) {
                                    return '₱' + context.parsed.y.toFixed(2);
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0, 0, 0, 0.05)' },
                            ticks: {
                                callback: function(value) {
                                    return '₱' + value.toLocaleString();
                                }
                            }
                        },
                        x: { grid: { display: false } }
                    },
                    interaction: { intersect: false, mode: 'index' },
                    animation: { duration: 1000, easing: 'easeInOutQuart' }
                }
            });
        }

        function changePeriod(period) {
            const url = new URL(window.location.href);
            url.searchParams.set('chart_period', period);
            window.location.href = url.toString();
        }

        document.addEventListener('DOMContentLoaded', function() {
            initChart();
            
            document.querySelectorAll('.filter-item input[type="date"]').forEach(input => {
                input.addEventListener('change', function() {
                    this.form.submit();
                });
            });
        });

        window.addEventListener('resize', function() {
            if (salesChart) salesChart.resize();
        });

        // ── Hourly Sales Activity — Polar Area ────────────────────────
        <?php if ($is_admin): ?>
        (function() {
            const tooltipDef = {
                backgroundColor:'#1f2937', titleColor:'#f9fafb',
                bodyColor:'#e5e7eb', padding:12, cornerRadius:8,
            };
            const hourLabels = <?php echo json_encode($time_blocks); ?>;
            const hourTotals = <?php echo json_encode($block_totals); ?>;
            const hourCtx = document.getElementById('hourlyChart')?.getContext('2d');
            if (hourCtx) {
                new Chart(hourCtx, {
                    type: 'polarArea',
                    data: {
                        labels: hourLabels,
                        datasets: [{
                            data: hourTotals,
                            backgroundColor: [
                                'rgba(0,77,64,.78)', 'rgba(184,134,11,.78)',
                                'rgba(13,148,136,.78)', 'rgba(139,92,246,.78)',
                                'rgba(249,115,22,.78)', 'rgba(59,130,246,.78)'
                            ],
                            borderColor: '#fff', borderWidth: 2,
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'right',
                                labels: { usePointStyle: true, padding: 14, font: { size: 12 } }
                            },
                            tooltip: { ...tooltipDef, callbacks: {
                                label: c => ' ' + c.label + ': ₱' + c.parsed.r.toLocaleString('en-PH', {minimumFractionDigits:2})
                            }}
                        },
                        scales: { r: { ticks: { backdropColor: 'transparent', font: { size: 10 } } } },
                        animation: { duration: 1400, easing: 'easeOutQuart' }
                    }
                });
            }
        })();
        <?php endif; ?>
    </script>
    <?php renderNotificationPanel($conn); ?>

    <!-- Transaction Detail Modal -->
    <div id="transactionModal">
        <div class="modal-content">
            <div style="display:flex; align-items:center; justify-content:space-between; padding:1.25rem 1.5rem; border-bottom:1px solid #e5e7eb; background:linear-gradient(135deg,#1a5f4b,#0e3d30); border-radius:1rem 1rem 0 0;">
                <div style="display:flex; align-items:center; gap:0.75rem;">
                    <div style="background:rgba(255,255,255,0.15); border-radius:0.5rem; padding:0.5rem;">
                        <i class="fa-solid fa-receipt" style="color:#fff; font-size:1.1rem;"></i>
                    </div>
                    <div>
                        <h3 id="modalTitle" style="margin:0; color:#fff; font-size:1.1rem; font-weight:700;">Transaction Details</h3>
                        <p id="modalSubtitle" style="margin:0; color:rgba(255,255,255,0.7); font-size:0.8rem;"></p>
                    </div>
                </div>
                <button onclick="closeTransactionModal()" style="background:rgba(255,255,255,0.15); border:none; border-radius:0.5rem; color:#fff; width:2rem; height:2rem; cursor:pointer; font-size:1rem; display:flex; align-items:center; justify-content:center;">✕</button>
            </div>

            <div style="padding:1.5rem;">
                <div id="modalLoading" style="text-align:center; padding:2rem; color:#6b7280;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size:2rem; color:#1a5f4b; margin-bottom:0.75rem; display:block;"></i>
                    Loading transaction details...
                </div>

                <div id="modalContent" style="display:none;">
                    <div id="modalSummary" style="display:grid; grid-template-columns:repeat(3,1fr); gap:1rem; margin-bottom:1.5rem;"></div>
                    <div style="background:#f9fafb; border-radius:0.75rem; overflow:hidden; border:1px solid #e5e7eb;">
                        <div style="padding:0.75rem 1rem; background:#f3f4f6; border-bottom:1px solid #e5e7eb;">
                            <strong style="color:#374151; font-size:0.9rem;"><i class="fa-solid fa-box" style="color:#1a5f4b; margin-right:0.4rem;"></i>Items Sold</strong>
                        </div>
                        <table style="width:100%; border-collapse:collapse;">
                            <thead>
                                <tr style="background:#e5e7eb;">
                                    <th style="padding:0.6rem 1rem; text-align:left; font-size:0.8rem; color:#6b7280; font-weight:600; text-transform:uppercase;">Product</th>
                                    <th style="padding:0.6rem 1rem; text-align:center; font-size:0.8rem; color:#6b7280; font-weight:600; text-transform:uppercase;">Qty</th>
                                    <th style="padding:0.6rem 1rem; text-align:right; font-size:0.8rem; color:#6b7280; font-weight:600; text-transform:uppercase;">Unit Price</th>
                                    <th style="padding:0.6rem 1rem; text-align:right; font-size:0.8rem; color:#6b7280; font-weight:600; text-transform:uppercase;">Total</th>
                                </tr>
                            </thead>
                            <tbody id="modalItemsBody"></tbody>
                            <tfoot id="modalTotals"></tfoot>
                        </table>
                    </div>
                </div>

                <div id="modalError" style="display:none; text-align:center; padding:2rem; color:#ef4444;">
                    <i class="fa-solid fa-circle-exclamation" style="font-size:2rem; margin-bottom:0.75rem; display:block;"></i>
                    <span id="modalErrorMsg">Failed to load transaction details.</span>
                </div>
            </div>
        </div>
    </div>

    <script>
        function openTransactionModal(saleId) {
            const modal = document.getElementById('transactionModal');
            const loading = document.getElementById('modalLoading');
            const content = document.getElementById('modalContent');
            const error = document.getElementById('modalError');

            modal.style.display = 'flex';
            loading.style.display = 'block';
            content.style.display = 'none';
            error.style.display = 'none';

            document.getElementById('modalTitle').textContent = 'Transaction #' + String(saleId).padStart(6, '0');
            document.getElementById('modalSubtitle').textContent = 'Loading details...';

            fetch('ajax/get_transaction.php?id=' + saleId)
                .then(r => r.json())
                .then(data => {
                    loading.style.display = 'none';
                    if (!data.success) {
                        error.style.display = 'block';
                        document.getElementById('modalErrorMsg').textContent = data.message || 'Failed to load.';
                        return;
                    }
                    const s = data.sale;
                    document.getElementById('modalSubtitle').textContent = s.date + ' · ' + s.time + ' · by ' + s.staff;

                    const summaryCards = [
                        { label: 'Date & Time', value: s.date + '<br><small style="color:#6b7280">' + s.time + '</small>', icon: 'fa-calendar' },
                        { label: 'Served By', value: s.staff, icon: 'fa-user' },
                        { label: 'Grand Total', value: '<span style="color:#1a5f4b;font-size:1.2rem;font-weight:800;">₱' + parseFloat(s.grand_total).toLocaleString('en-PH', {minimumFractionDigits:2}) + '</span>', icon: 'fa-peso-sign' },
                    ];
                    document.getElementById('modalSummary').innerHTML = summaryCards.map(c =>
                        `<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:0.6rem;padding:0.9rem;">
                            <div style="display:flex;align-items:center;gap:0.4rem;color:#6b7280;font-size:0.75rem;margin-bottom:0.4rem;font-weight:600;text-transform:uppercase;">
                                <i class="fa-solid ${c.icon}" style="color:#1a5f4b;"></i> ${c.label}
                            </div>
                            <div style="font-weight:600;color:#111827;">${c.value}</div>
                        </div>`
                    ).join('');

                    document.getElementById('modalItemsBody').innerHTML = data.items.map(item =>
                        `<tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:0.75rem 1rem;">
                                <div style="font-weight:600;color:#111827;">${item.brand_name}</div>
                                <div style="font-size:0.78rem;color:#6b7280;">${item.generic_name}${item.unit_sold ? ' · ' + item.unit_sold : ''}</div>
                                ${item.batch_number ? `<div style="font-size:0.72rem;background:#e0f2f1;color:#00695c;display:inline-block;padding:1px 7px;border-radius:4px;margin-top:2px;">Batch: ${item.batch_number}</div>` : ''}
                                ${item.batch_expiry_date ? `<div style="font-size:0.72rem;color:#9ca3af;">Exp: ${item.batch_expiry_date}</div>` : ''}
                            </td>
                            <td style="padding:0.75rem 1rem;text-align:center;">
                                <span style="background:#e8f3f0;color:#1a5f4b;padding:0.2rem 0.6rem;border-radius:999px;font-weight:700;font-size:0.9rem;">${item.quantity_sold}</span>
                            </td>
                            <td style="padding:0.75rem 1rem;text-align:right;color:#374151;">₱${parseFloat(item.unit_price).toLocaleString('en-PH', {minimumFractionDigits:2})}</td>
                            <td style="padding:0.75rem 1rem;text-align:right;font-weight:700;color:#111827;">₱${parseFloat(item.subtotal).toLocaleString('en-PH', {minimumFractionDigits:2})}</td>
                        </tr>`
                    ).join('');

                    document.getElementById('modalTotals').innerHTML = `
                        <tr style="background:#f9fafb;border-top:2px solid #e5e7eb;">
                            <td colspan="3" style="padding:0.6rem 1rem;text-align:right;color:#6b7280;font-size:0.85rem;">Subtotal</td>
                            <td style="padding:0.6rem 1rem;text-align:right;">₱${parseFloat(s.subtotal).toLocaleString('en-PH', {minimumFractionDigits:2})}</td>
                        </tr>
                        ${parseFloat(s.discount) > 0 ? `<tr style="background:#f9fafb;">
                            <td colspan="3" style="padding:0.4rem 1rem;text-align:right;color:#ef4444;font-size:0.85rem;">Discount</td>
                            <td style="padding:0.4rem 1rem;text-align:right;color:#ef4444;">-₱${parseFloat(s.discount).toLocaleString('en-PH', {minimumFractionDigits:2})}</td>
                        </tr>` : ''}
                        ${parseFloat(s.tax) > 0 ? `<tr style="background:#f9fafb;">
                            <td colspan="3" style="padding:0.4rem 1rem;text-align:right;color:#6b7280;font-size:0.85rem;">Tax (VAT)</td>
                            <td style="padding:0.4rem 1rem;text-align:right;">₱${parseFloat(s.tax).toLocaleString('en-PH', {minimumFractionDigits:2})}</td>
                        </tr>` : ''}
                        <tr style="background:#e8f3f0;">
                            <td colspan="3" style="padding:0.75rem 1rem;text-align:right;font-weight:800;color:#1a5f4b;">Grand Total</td>
                            <td style="padding:0.75rem 1rem;text-align:right;font-weight:800;color:#1a5f4b;font-size:1.05rem;">₱${parseFloat(s.grand_total).toLocaleString('en-PH', {minimumFractionDigits:2})}</td>
                        </tr>`;

                    content.style.display = 'block';
                })
                .catch(() => {
                    loading.style.display = 'none';
                    error.style.display = 'block';
                    document.getElementById('modalErrorMsg').textContent = 'Network error. Please try again.';
                });
        }

        function closeTransactionModal() {
            document.getElementById('transactionModal').style.display = 'none';
        }

        document.getElementById('transactionModal').addEventListener('click', function(e) {
            if (e.target === this) closeTransactionModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeTransactionModal();
        });
    </script>
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