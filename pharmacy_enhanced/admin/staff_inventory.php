<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/notifications.php';

// Get current user role
$user_role = $_SESSION['role'] ?? '';
$user_name = $_SESSION['full_name'] ?? 'Staff';

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$category_filter = isset($_GET['category']) ? intval($_GET['category']) : 0;
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Build main products query with filters
$query = "
    SELECT p.*, 
           c.name as category_name,
           COALESCE(SUM(CASE WHEN sb.expiration_date >= DATE_ADD(CURDATE(), INTERVAL 1 MONTH) THEN sb.quantity_base ELSE 0 END), 0) as total_stock,
           MIN(sb.expiration_date) as earliest_expiry,
           COUNT(DISTINCT sb.id) as batch_count
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN stock_batches sb ON p.id = sb.product_id
    WHERE 1=1
";

$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (p.brand_name LIKE ? OR p.generic_name LIKE ? OR p.barcode LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if (!empty($category_filter)) {
    $query .= " AND p.category_id = ?";
    $params[] = $category_filter;
    $types .= "i";
}

$query .= " GROUP BY p.id";

// Apply status filter after grouping
if (!empty($status_filter)) {
    if ($status_filter === 'low_stock') {
        $query .= " HAVING total_stock < p.low_stock_threshold AND total_stock > 0";
    } elseif ($status_filter === 'out_of_stock') {
        $query .= " HAVING total_stock = 0";
    } elseif ($status_filter === 'in_stock') {
        $query .= " HAVING total_stock >= p.low_stock_threshold";
    }
}

$query .= " ORDER BY p.brand_name ASC";

// Execute main query
$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$products_result = $stmt->get_result();

$products = [];
$total_stock_value = 0;
while ($row = $products_result->fetch_assoc()) {
    $products[] = $row;
    $total_stock_value += $row["total_stock"] * $row["cost"];
}
$stmt->close();

// Get categories for filter
$categories_result = $conn->query("
    SELECT c.id, c.name, COUNT(p.id) as product_count
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    GROUP BY c.id
    ORDER BY c.name
");
$categories = [];
while ($row = $categories_result->fetch_assoc()) {
    $categories[] = $row;
}

// Statistics
$total_products = count($products);
$total_stock = array_sum(array_column($products, 'total_stock'));
$low_stock_count = count(array_filter($products, function($p) { 
    return $p['total_stock'] > 0 && $p['total_stock'] <= $p['low_stock_threshold']; 
}));
$out_of_stock_count = count(array_filter($products, function($p) { 
    return $p['total_stock'] == 0; 
}));
$expiring_count_stat = $conn->query("
    SELECT COUNT(*) FROM stock_batches
    WHERE expiration_date > CURDATE()
      AND expiration_date < DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
      AND quantity_base > 0
")->fetch_row()[0];
$expired_count_stat = $conn->query("
    SELECT COUNT(*) FROM stock_batches
    WHERE expiration_date <= CURDATE() AND quantity_base > 0
")->fetch_row()[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Inventory | CAD Crest Pharmaceutical</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
:root {
    --primary-green: #004d40;
    --primary-dark: #00332c;
    --primary-light: #e8f5e9;
    --accent-gold: #b8860b;
    --bg-soft: #f0f4f8;
    --card-shadow: 0 10px 20px rgba(0, 0, 0, 0.05);
    --transition: all 0.2s ease;
    --danger: #dc2626;
    --warning: #f59e0b;
    --success: #10b981;
    --gray-50: #f9fafb;
    --gray-100: #f3f4f6;
    --gray-200: #e5e7eb;
    --gray-300: #d1d5db;
    --gray-400: #9ca3af;
    --gray-500: #6b7280;
    --gray-600: #4b5563;
    --gray-700: #374151;
    --gray-800: #1f293b;
}

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-header h1 {
            font-size: 2rem;
            font-weight: 800;
            color: var(--primary-green);
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .page-header h1 i {
            background: var(--primary-light);
            padding: 0.75rem;
            border-radius: 1rem;
            color: var(--primary-green);
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 1rem;
            border: 1px solid var(--gray-100);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            background: var(--primary-light);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-green);
            font-size: 1.5rem;
        }

        .stat-details h3 {
            font-size: 0.8rem;
            color: var(--gray-500);
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 0.25rem;
        }

        .stat-details .value {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--gray-800);
            line-height: 1.2;
        }

        .filter-bar {
            background: white;
            padding: 1.25rem;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            margin-bottom: 2rem;
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            border: 1px solid var(--gray-100);
        }

        .filter-group { flex: 1; }

        .filter-group label {
            display: block;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray-500);
            text-transform: uppercase;
            margin-bottom: 0.5rem;
        }

        .filter-group input,
        .filter-group select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-200);
            border-radius: 0.5rem;
            font-size: 0.9rem;
            transition: all 0.2s;
        }

        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 77, 64, 0.1);
        }

        .tabs-container {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            overflow: hidden;
            border: 1px solid var(--gray-100);
        }

        .tabs-header {
            background: var(--gray-50);
            display: flex;
            border-bottom: 1px solid var(--gray-200);
            overflow-x: auto;
        }

        .tab-btn {
            padding: 1rem 2rem;
            border: none;
            background: none;
            font-weight: 600;
            color: var(--gray-600);
            cursor: pointer;
            white-space: nowrap;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.9rem;
            transition: all 0.2s;
        }

        .tab-btn i { font-size: 1rem; }

        .tab-btn.active {
            color: var(--primary-green);
            background: white;
            border-bottom: 3px solid var(--primary-green);
        }

        .tab-content { padding: 1.5rem; display: none; }
        .tab-content.active { display: block; }

        .table-container {
            overflow-x: auto;
            border-radius: 0.75rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
            table-layout: fixed;
        }

        /* Even column distribution for products table */
        #productsTab table th:nth-child(1),
        #productsTab table td:nth-child(1) { width: 22%; }

        #productsTab table th:nth-child(2),
        #productsTab table td:nth-child(2) { width: 14%; }

        #productsTab table th:nth-child(3),
        #productsTab table td:nth-child(3) { width: 11%; text-align: center; }

        #productsTab table td:nth-child(3) code { display: block; text-align: center; }

        #productsTab table th:nth-child(4),
        #productsTab table td:nth-child(4) { width: 13%; text-align: right; }

        #productsTab table th:nth-child(5),
        #productsTab table td:nth-child(5) { width: 10%; text-align: center; }

        #productsTab table th:nth-child(6),
        #productsTab table td:nth-child(6) { width: 16%; text-align: center; }

        #productsTab table th:nth-child(7),
        #productsTab table td:nth-child(7) { width: 14%; text-align: center; }

        /* Prevent text overflow */
        td, th {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Allow product name cell to wrap */
        #productsTab table td:nth-child(1) {
            white-space: normal;
        }

        th {
            text-align: left;
            padding: 1rem 1.5rem;
            background: var(--gray-50);
            color: var(--gray-600);
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            border-bottom: 1px solid var(--gray-200);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            color: var(--gray-700);
            font-size: 0.9rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        tbody tr:hover { background: var(--gray-50); }

        .product-name-link {
            color: var(--primary-green);
            font-weight: 700;
            text-decoration: none;
            border-bottom: 1px dashed var(--primary-green);
            cursor: pointer;
        }

        .product-name-link:hover { color: var(--primary-dark); }

        .product-generic {
            font-size: 0.75rem;
            color: var(--gray-500);
            margin-top: 0.25rem;
        }

        .category-badge {
            background: var(--gray-100);
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray-700);
            display: inline-block;
        }

        .price-value { font-weight: 700; color: var(--primary-green); }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-good { background: #dcfce7; color: #15803d; }
        .status-low  { background: #ffedd5; color: #9a3412; }
        .status-out  { background: #fee2e2; color: #b91c1c; }

        .expiry-table { width: 100%; border-collapse: collapse; min-width: 800px; table-layout: fixed; }

        #expiryTab table th:nth-child(1),
        #expiryTab table td:nth-child(1) { width: 28%; white-space: normal; }

        #expiryTab table th:nth-child(2),
        #expiryTab table td:nth-child(2) { width: 22%; }

        #expiryTab table th:nth-child(3),
        #expiryTab table td:nth-child(3) { width: 15%; text-align: center; }

        #expiryTab table th:nth-child(4),
        #expiryTab table td:nth-child(4) { width: 10%; text-align: center; }

        #expiryTab table th:nth-child(5),
        #expiryTab table td:nth-child(5) { width: 13%; text-align: center; }

        #expiryTab table th:nth-child(6),
        #expiryTab table td:nth-child(6) { width: 12%; text-align: center; }

        .expiry-table th {
            text-align: left;
            padding: 1rem 1.5rem;
            background: var(--gray-50);
            color: var(--gray-600);
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            border-bottom: 1px solid var(--gray-200);
        }

        .expiry-table td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            color: var(--gray-700);
            font-size: 0.9rem;
        }

        .expiry-table tbody tr:hover { background: var(--gray-50); }
        .expiry-table tr.expired-row { background: #fef2f2; }
        .expiry-table tr.expired-row:hover { background: #fee2e2; }
        .expiry-table tr.warning-row { background: #fffbeb; }
        .expiry-table tr.warning-row:hover { background: #fef3c7; }

        .days-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-block;
        }
        .days-urgent  { background: #fee2e2; color: #b91c1c; }
        .days-warning { background: #ffedd5; color: #9a3412; }

        .empty-state { text-align: center; padding: 4rem 2rem; color: var(--gray-400); }
        .empty-state i { font-size: 3rem; margin-bottom: 1rem; display: block; }
        .empty-state p { font-size: 0.9rem; }

        .expiry-filter-btn {
            padding: 0.4rem 1rem;
            border: 1px solid var(--gray-300);
            border-radius: 20px;
            background: white;
            font-size: 0.8rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .expiry-filter-btn.active,
        .expiry-filter-btn:hover { background: var(--gray-100); }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 1rem; }
            .stat-details .value { font-size: 1.5rem; }
        }
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>

    <div class="main-content">

        <!-- Header -->
        <div class="page-header">
            <h1>
                <i class="fa-solid fa-warehouse"></i>
                Inventory Management
            </h1>
            <div class="header-actions">
                <?php if (function_exists('renderNotificationBell')) renderNotificationBell($conn); ?>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon" style="background:#e8f5e9;color:#004d40;">
                    <i class="fa-solid fa-pills"></i>
                </div>
                <div class="stat-details">
                    <h3>Total Products</h3>
                    <div class="value"><?php echo number_format($total_products); ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#fff3e0;color:#e65100;">
                    <i class="fa-solid fa-exclamation-triangle"></i>
                </div>
                <div class="stat-details">
                    <h3>Low Stock</h3>
                    <div class="value" style="color:#e65100;"><?php echo $low_stock_count; ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#fee2e2;color:#b91c1c;">
                    <i class="fa-solid fa-circle-exclamation"></i>
                </div>
                <div class="stat-details">
                    <h3>Out of Stock</h3>
                    <div class="value" style="color:#b91c1c;"><?php echo $out_of_stock_count; ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#fff8e1;color:#f57c00;">
                    <i class="fa-solid fa-hourglass-half"></i>
                </div>
                <div class="stat-details">
                    <h3>Near Expiry</h3>
                    <div class="value" style="color:#f57c00;"><?php echo $expiring_count_stat; ?></div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#fee2e2;color:#c62828;">
                    <i class="fa-solid fa-calendar-xmark"></i>
                </div>
                <div class="stat-details">
                    <h3>Expired</h3>
                    <div class="value" style="color:#c62828;"><?php echo $expired_count_stat; ?></div>
                </div>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="filter-bar">
            <div class="filter-group">
                <label><i class="fa-solid fa-search"></i> Search</label>
                <input type="text" id="searchInput" placeholder="Search products, batches, or categories..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
            <div class="filter-group">
                <label><i class="fa-solid fa-tag"></i> Category</label>
                <select id="categoryFilter">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $category_filter == $cat['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?> (<?php echo $cat['product_count']; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs-container">
            <div class="tabs-header">
                <button class="tab-btn active" onclick="openTab(event, 'productsTab')">
                    <i class="fa-solid fa-pills"></i> Products
                </button>
                <button class="tab-btn" onclick="openTab(event, 'expiryTab')">
                    <i class="fa-solid fa-clock"></i> Expiry Tracking
                </button>
            </div>

            <!-- Products Tab -->
            <div id="productsTab" class="tab-content active">
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Base Unit</th>
                                <th>Selling Price</th>
                                <th>Quantity</th>
                                <th style="white-space:nowrap;">Prescription</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($products)): ?>
                            <tr>
                                <td colspan="7" class="empty-state">
                                    <i class="fa-solid fa-box-open"></i>
                                    <p>No products found</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($products as $product):
                                $status_badge = 'status-good';
                                $status_text  = 'In Stock';
                                if ($product['total_stock'] <= 0) {
                                    $status_badge = 'status-out';
                                    $status_text  = 'Out of Stock';
                                } elseif ($product['total_stock'] < $product['low_stock_threshold']) {
                                    $status_badge = 'status-low';
                                    $status_text  = 'Low Stock';
                                }
                            ?>
                            <tr class="product-row"
                                data-name="<?php echo strtolower(htmlspecialchars($product['brand_name'])); ?>"
                                data-generic="<?php echo strtolower(htmlspecialchars($product['generic_name'] ?? '')); ?>"
                                data-barcode="<?php echo strtolower(htmlspecialchars($product['barcode'] ?? '')); ?>"
                                data-category="<?php echo strtolower(htmlspecialchars($product['category_name'] ?? '')); ?>"
                                data-cat-id="<?php echo (int)($product['category_id'] ?? 0); ?>">
                                <td>
                                    <span class="product-name-link"><?php echo htmlspecialchars($product['brand_name']); ?></span>
                                    <div class="product-generic"><?php echo htmlspecialchars($product['generic_name'] ?? ''); ?></div>
                                    <?php if (!empty($product['strength'])): ?>
                                    <div style="font-size:0.7rem;color:var(--gray-500);"><?php echo htmlspecialchars($product['strength']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="category-badge"><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></span>
                                </td>
                                <td><code><?php echo htmlspecialchars($product['base_unit']); ?></code></td>
                                <td><span class="price-value">₱<?php echo number_format($product['price'], 2); ?></span></td>
                                <td><?php echo $product['total_stock']; ?></td>
                                <td>
                                    <?php if (!empty($product['requires_prescription'])): ?>
                                        <span style="display:inline-flex;align-items:center;gap:4px;background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;border-radius:6px;padding:3px 10px;font-size:0.72rem;font-weight:700;">
                                            <i class="fa-solid fa-prescription-bottle-medical"></i> Rx-only
                                        </span>
                                    <?php else: ?>
                                        <span style="display:inline-flex;align-items:center;gap:4px;background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;border-radius:6px;padding:3px 10px;font-size:0.72rem;font-weight:700;">
                                            <i class="fa-solid fa-check"></i> OTC
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-badge <?php echo $status_badge; ?>"><?php echo $status_text; ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Expiry Tracking Tab -->
            <div id="expiryTab" class="tab-content">
                <?php
                $all_expiry_batches = [];
                $eq = $conn->query("
                    SELECT sb.*, p.brand_name, p.generic_name, c.name as category_name,
                           DATEDIFF(sb.expiration_date, CURDATE()) as days_left
                    FROM stock_batches sb
                    JOIN products p ON sb.product_id = p.id
                    LEFT JOIN categories c ON p.category_id = c.id
                    WHERE sb.quantity_base > 0
                      AND sb.expiration_date < DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
                    ORDER BY
                        CASE WHEN sb.expiration_date <= CURDATE() THEN 1 ELSE 2 END,
                        sb.expiration_date ASC
                ");
                if ($eq) {
                    while ($row = $eq->fetch_assoc()) $all_expiry_batches[] = $row;
                }
                ?>
                <div style="margin-bottom:1rem;display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
                    <button class="expiry-filter-btn active" data-filter="all" onclick="filterExpiry('all')">All</button>
                    <button class="expiry-filter-btn" data-filter="expired" onclick="filterExpiry('expired')" style="color:#c62828;border-color:#c62828;">Expired</button>
                    <button class="expiry-filter-btn" data-filter="near_expiry" onclick="filterExpiry('near_expiry')" style="color:#f57c00;border-color:#f57c00;">Near Expiry</button>
                </div>
                <?php if (empty($all_expiry_batches)): ?>
                <div class="empty-state">
                    <i class="fa-regular fa-calendar-check"></i>
                    <p>No products are expired or expiring within the next 30 days</p>
                </div>
                <?php else: ?>
                <div class="table-container">
                    <table class="expiry-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Batch #</th>
                                <th>Expiry Date</th>
                                <th>Total Qty</th>
                                <th>Days Left</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_expiry_batches as $batch):
                                $days = (int)$batch['days_left'];
                                if ($days <= 0) {
                                    $row_class    = 'expired-row';
                                    $badge_class  = 'status-out';
                                    $status_text  = 'Expired';
                                    $days_display = '<span class="days-badge days-urgent">Expired ' . abs($days) . ' days ago</span>';
                                    $expiry_type  = 'expired';
                                } else {
                                    $row_class    = $days <= 7 ? 'expired-row' : 'warning-row';
                                    $badge_class  = $days <= 7 ? 'status-out' : 'status-low';
                                    $status_text  = 'Near Expiry';
                                    $days_display = '<span class="days-badge ' . ($days <= 7 ? 'days-urgent' : 'days-warning') . '">' . $days . ' days</span>';
                                    $expiry_type  = 'near_expiry';
                                }
                            ?>
                            <tr class="<?php echo $row_class; ?>" data-expiry-type="<?php echo $expiry_type; ?>">
                                <td>
                                    <span class="product-name-link"><?php echo htmlspecialchars($batch['brand_name']); ?></span>
                                    <div class="product-generic"><?php echo htmlspecialchars($batch['generic_name'] ?? ''); ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($batch['batch_number'])): ?>
                                        <code><?php echo htmlspecialchars($batch['batch_number']); ?></code>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M d, Y', strtotime($batch['expiration_date'])); ?></td>
                                <td><?php echo $batch['quantity_base']; ?></td>
                                <td><?php echo $days_display; ?></td>
                                <td>
                                    <span class="status-badge <?php echo $badge_class; ?>"><?php echo $status_text; ?></span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function openTab(evt, tabName) {
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.getElementById(tabName).classList.add('active');
            evt.currentTarget.classList.add('active');
        }

        const searchInput    = document.getElementById('searchInput');
        const categoryFilter = document.getElementById('categoryFilter');
        let filterTimeout;

        function applyFilters() {
            clearTimeout(filterTimeout);
            filterTimeout = setTimeout(() => {
                const q   = searchInput.value.toLowerCase().trim();
                const cat = categoryFilter.value;
                document.querySelectorAll('.product-row').forEach(row => {
                    const nameMatch   = row.dataset.name.includes(q) || row.dataset.generic.includes(q) || row.dataset.barcode.includes(q) || row.dataset.category.includes(q);
                    const catMatch    = !cat || row.dataset.catId === cat;
                    row.style.display = (nameMatch && catMatch) ? '' : 'none';
                });
            }, 200);
        }

        searchInput.addEventListener('input', applyFilters);
        categoryFilter.addEventListener('change', applyFilters);

        function filterExpiry(type) {
            document.querySelectorAll('.expiry-filter-btn').forEach(b => b.classList.toggle('active', b.dataset.filter === type));
            document.querySelectorAll('#expiryTab tbody tr').forEach(row => {
                row.style.display = (type === 'all' || row.dataset.expiryType === type) ? '' : 'none';
            });
        }
    </script>

    <?php if (function_exists('renderNotificationPanel')) renderNotificationPanel($conn); ?>
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