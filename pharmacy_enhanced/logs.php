<?php
require_once 'config/database.php';
require_once 'includes/auth_check.php';
require_once 'includes/notifications.php';

// ONLY ADMIN can access logs
restrictToAdmin();

// Get logs with user details - OPTIMIZED QUERY
$result = $conn->query("
    SELECT l.*, u.username, u.full_name, u.role 
    FROM activity_logs l 
    JOIN users u ON l.user_id = u.id 
    ORDER BY l.date_time DESC 
    LIMIT 200
");

$logs = [];
while ($row = $result->fetch_assoc()) {
    $logs[] = $row;
}

// Group logs by date (do this in PHP instead of multiple queries)
$grouped_logs = [];
$today = date('Y-m-d');
$unique_users = [];
$today_logs = 0;

foreach ($logs as $log) {
    $date = date('Y-m-d', strtotime($log['date_time']));
    if (!isset($grouped_logs[$date])) {
        $grouped_logs[$date] = [];
    }
    $grouped_logs[$date][] = $log;
    
    // Calculate stats while looping
    $unique_users[$log['user_id']] = true;
    if ($date === $today) {
        $today_logs++;
    }
}

$total_logs = count($logs);
$unique_users_count = count($unique_users);

// Function to format currency in descriptions
function formatDescriptionWithPeso($description) {
    // Check if description contains sale-related keywords and currency patterns
    if (strpos(strtolower($description), 'sale') !== false || 
        strpos(strtolower($description), 'total') !== false ||
        strpos(strtolower($description), 'amount') !== false ||
        strpos(strtolower($description), '₱') !== false ||
        strpos($description, '$') !== false) {
        
        // Replace $ with ₱ for any dollar amounts
        $description = preg_replace('/\$\s*(\d+(?:,\d{3})*(?:\.\d{2})?)/', '₱$1', $description);
        
        // Format numbers to ensure proper currency display
        $description = preg_replace('/(?:₱)\s*(\d+(?:\.\d{2})?)/', '₱$1', $description);
    }
    
    return $description;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Activity Logs | CAD Crest</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Page-specific styles only */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-green: #004d40;
            --primary-dark: #00332c;
            --primary-light: #e8f5e9;
            --accent-gold: #b8860b;
            --bg-soft: #f0f4f8;
            --card-shadow: 0 10px 20px rgba(0, 0, 0, 0.05);
            --transition: all 0.2s ease;
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

        body { 
            background: #f5f7fa;
            font-family: 'Inter', sans-serif;
        }

        /* Page Header */
        .page-header {
            background: var(--primary-green);
            padding: 1.5rem 2rem;
            border-radius: 16px;
            margin-bottom: 1.5rem;
            color: white;
            box-shadow: var(--card-shadow);
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-header h1 i {
            background: rgba(255,255,255,0.15);
            padding: 12px;
            border-radius: 12px;
        }

        .page-header p {
            font-size: 0.95rem;
            opacity: 0.9;
            margin-top: 0.5rem;
            padding-left: 52px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 1.25rem;
            box-shadow: var(--card-shadow);
            display: flex;
            align-items: center;
            gap: 1rem;
            border: 1px solid #edf2f7;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            background: var(--primary-light);
            color: var(--primary-green);
        }

        .stat-details h3 {
            font-size: 1.8rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.2;
        }

        .stat-details p {
            color: #64748b;
            font-size: 0.85rem;
            font-weight: 500;
        }

        /* Search Bar - Simplified */
        .action-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }

        .search-wrapper {
            flex: 1;
            min-width: 280px;
            position: relative;
        }

        .search-wrapper i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
        }

        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.95rem;
            background: white;
            transition: var(--transition);
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 77, 64, 0.1);
        }

        .filter-chips {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .chip {
            padding: 0.75rem 1.25rem;
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 30px;
            font-size: 0.9rem;
            font-weight: 500;
            color: #475569;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .chip:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        .chip.active {
            background: var(--primary-green);
            color: white;
            border-color: var(--primary-green);
        }

        .chip i {
            font-size: 0.85rem;
        }

        /* Logs Container */
        .logs-container {
            background: white;
            border-radius: 20px;
            padding: 1.5rem;
            box-shadow: var(--card-shadow);
            border: 1px solid #edf2f7;
        }

        .logs-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #e2e8f0;
        }

        .logs-header h2 {
            font-size: 1.3rem;
            color: #1e293b;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .badge-total {
            background: #f1f5f9;
            color: #475569;
            padding: 0.5rem 1rem;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        /* Date Group */
        .date-group {
            margin-bottom: 2rem;
        }

        .log-date-header {
            margin: 1rem 0 0.75rem;
            padding: 0.75rem 1rem;
            background: #f8fafc;
            border-radius: 10px;
            font-weight: 600;
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.95rem;
            border-left: 3px solid var(--primary-green);
        }

        .date-count {
            margin-left: auto;
            background: white;
            color: var(--primary-green);
            padding: 0.25rem 0.75rem;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            border: 1px solid #e2e8f0;
        }

        /* Table Styles - Responsive */
        .table-responsive {
            overflow-x: auto;
            border-radius: 12px;
            border: 1px solid #edf2f7;
        }

        .modern-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }

        .modern-table th {
            text-align: left;
            padding: 1rem;
            background: #f8fafc;
            color: #475569;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #e2e8f0;
        }

        .modern-table td {
            padding: 1rem;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.9rem;
            color: #334155;
        }

        .modern-table tbody tr:hover {
            background: #f8fafc;
        }

        /* User Info */
        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .user-avatar {
            width: 36px;
            height: 36px;
            background: var(--primary-green);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 1rem;
            flex-shrink: 0;
        }

        .user-details {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 600;
            color: #1e293b;
        }

        .user-username {
            font-size: 0.7rem;
            color: #94a3b8;
        }

        /* Badges */
        .role-badge, .action-badge {
            padding: 0.4rem 0.75rem;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .role-admin {
            background: #f3e8ff;
            color: #7e22ce;
        }

        .role-staff {
            background: #e0f2fe;
            color: #0369a1;
        }

        .badge-login { background: #e0f2fe; color: #0369a1; }
        .badge-sale { background: #dcfce7; color: #15803d; }
        .badge-product { background: #fef3c7; color: #b45309; }
        .badge-stock { background: #f3e8ff; color: #7e22ce; }
        .badge-user { background: #ffe4e6; color: #be123c; }
        .badge-default { background: #f1f5f9; color: #475569; }

        .timestamp {
            color: #64748b;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .description-text {
            color: #475569;
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        /* Currency highlight */
        .currency-amount {
            font-weight: 600;
            color: var(--primary-green);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: var(--primary-green);
            opacity: 0.3;
        }

        /* No Results Message */
        .no-results {
            text-align: center;
            padding: 3rem;
            color: #94a3b8;
            font-size: 1rem;
            background: #f8fafc;
            border-radius: 12px;
            margin: 1rem 0;
        }

        .no-results i {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            color: var(--primary-green);
            opacity: 0.3;
        }

        /* Responsive Breakpoints */
        @media (max-width: 992px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .page-header h1 {
                font-size: 1.6rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .action-bar {
                flex-direction: column;
            }
            
            .search-wrapper {
                width: 100%;
            }
            
            .filter-chips {
                width: 100%;
                justify-content: flex-start;
            }
            
            .chip {
                padding: 0.6rem 1rem;
                font-size: 0.8rem;
            }
            
            .logs-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .modern-table td, 
            .modern-table th {
                padding: 0.75rem;
            }
            
            .log-date-header {
                padding: 0.5rem 1rem;
            }
        }

        @media (max-width: 576px) {
            .page-header {
                padding: 1.2rem;
            }
            
            .page-header h1 {
                font-size: 1.4rem;
            }
            
            .page-header h1 i {
                padding: 8px;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-details h3 {
                font-size: 1.5rem;
            }
            
            .logs-container {
                padding: 1rem;
            }
            
            .user-info {
                gap: 6px;
            }
            
            .user-avatar {
                width: 30px;
                height: 30px;
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1>
                    <i class="fa-solid fa-shield-halved"></i>
                    Security Audit Logs
                </h1>
                <p>Activity monitoring for CAD Crest Pharmaceutical</p>
            </div>
            <div style="display:flex;align-items:center;gap:.5rem;padding-top:.25rem;">
                <?php renderNotificationBell($conn); ?>
            </div>
        </div>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-clipboard-list"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo number_format($total_logs); ?></h3>
                    <p>Total Events</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $unique_users_count; ?></h3>
                    <p>Active Users</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-calendar-day"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $today_logs; ?></h3>
                    <p>Today's Events</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-clock"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo date('H:i'); ?></h3>
                    <p>Last Update</p>
                </div>
            </div>
        </div>

        <!-- Search and Filter Bar -->
        <div class="action-bar">
            <div class="search-wrapper">
                <i class="fa-solid fa-search"></i>
                <input type="text" class="search-input" placeholder="Search logs..." id="searchInput">
            </div>
            <div class="filter-chips">
                <button class="chip active" onclick="filterLogs('all', this)">
                    <i class="fa-regular fa-clock"></i> All
                </button>
                <button class="chip" onclick="filterLogs('today', this)">
                    <i class="fa-regular fa-sun"></i> Today
                </button>
                <button class="chip" onclick="filterLogs('week', this)">
                    <i class="fa-regular fa-calendar"></i> This Week
                </button>
            </div>
        </div>

        <!-- Logs Container -->
        <div class="logs-container">
            <div class="logs-header">
                <h2>
                    <i class="fa-solid fa-clock-rotate-left"></i>
                    Activity Timeline
                </h2>
                <span class="badge-total">
                    <i class="fa-solid fa-list"></i>
                    Last 200 entries
                </span>
            </div>

            <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <i class="fa-regular fa-clipboard"></i>
                    <h3>No Activity Logs Found</h3>
                    <p>There are no logs to display at this moment</p>
                </div>
            <?php else: ?>
                <?php foreach ($grouped_logs as $date => $date_logs): ?>
                    <?php 
                    // Calculate days ago for filtering
                    $date_obj = new DateTime($date);
                    $today_obj = new DateTime();
                    $week_ago = new DateTime('-7 days');
                    $days_ago = $today_obj->diff($date_obj)->days;
                    
                    // Determine if this is within last 7 days
                    $is_within_week = ($date_obj >= $week_ago);
                    ?>
                    <div class="date-group" 
                         data-date="<?php echo $date; ?>" 
                         data-days-ago="<?php echo $days_ago; ?>"
                         data-is-today="<?php echo ($date === $today) ? '1' : '0'; ?>"
                         data-is-week="<?php echo $is_within_week ? '1' : '0'; ?>">
                        
                        <div class="log-date-header">
                            <i class="fa-regular fa-calendar"></i>
                            <?php 
                            if ($date === $today) {
                                echo 'Today';
                            } elseif ($date === date('Y-m-d', strtotime('yesterday'))) {
                                echo 'Yesterday';
                            } else {
                                echo $date_obj->format('M d, Y');
                            }
                            ?>
                            <span class="date-count">
                                <?php echo count($date_logs); ?> events
                            </span>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="modern-table">
                                <thead>
                                    <tr>
                                        <th>Time</th>
                                        <th>User</th>
                                        <th>Role</th>
                                        <th>Action</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($date_logs as $log): 
                                        $action = strtolower($log['action']);
                                        $badge_class = 'badge-default';
                                        if (strpos($action, 'login') !== false) $badge_class = 'badge-login';
                                        elseif (strpos($action, 'sale') !== false) $badge_class = 'badge-sale';
                                        elseif (strpos($action, 'product') !== false) $badge_class = 'badge-product';
                                        elseif (strpos($action, 'stock') !== false) $badge_class = 'badge-stock';
                                        elseif (strpos($action, 'user') !== false) $badge_class = 'badge-user';
                                        
                                        // Format description with Philippine Peso
                                        $formatted_description = formatDescriptionWithPeso($log['description']);
                                    ?>
                                    <tr>
                                        <td class="timestamp">
                                            <i class="fa-regular fa-clock"></i>
                                            <?php echo date('H:i:s', strtotime($log['date_time'])); ?>
                                        </td>
                                        <td>
                                            <div class="user-info">
                                                <div class="user-avatar">
                                                    <?php echo strtoupper(substr($log['username'], 0, 1)); ?>
                                                </div>
                                                <div class="user-details">
                                                    <span class="user-name"><?php echo htmlspecialchars($log['full_name']); ?></span>
                                                    <span class="user-username">@<?php echo htmlspecialchars($log['username']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="role-badge <?php echo $log['role'] === 'admin' ? 'role-admin' : 'role-staff'; ?>">
                                                <?php echo ucfirst($log['role']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="action-badge <?php echo $badge_class; ?>">
                                                <?php echo htmlspecialchars($log['action']); ?>
                                            </span>
                                        </td>
                                        <td class="description-text" title="<?php echo htmlspecialchars($formatted_description); ?>">
                                            <?php 
                                            // Highlight currency amounts in the description
                                            $display_description = $formatted_description;
                                            $display_description = preg_replace(
                                                '/(₱\s*\d+(?:\.\d{2})?)/', 
                                                '<span class="currency-amount">$1</span>', 
                                                $display_description
                                            );
                                            echo $display_description; 
                                            ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <!-- No Results Message (hidden by default) -->
                <div id="noResultsMessage" class="no-results" style="display: none;">
                    <i class="fa-regular fa-face-frown"></i>
                    <h3>No logs match your filter</h3>
                    <p>Try adjusting your filter or search criteria</p>
                </div>
                
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Debounced search for better performance
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }

        // Global variables for filter state
        let currentFilter = 'all';
        let searchTerm = '';

        // Optimized search
        const searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('keyup', debounce(function(e) {
                searchTerm = e.target.value.toLowerCase().trim();
                applyFilters();
            }, 300));
        }

        // Filter function for chips
        function filterLogs(filter, element) {
            // Update active state
            document.querySelectorAll('.chip').forEach(chip => chip.classList.remove('active'));
            element.classList.add('active');
            
            // Update current filter
            currentFilter = filter;
            
            // Apply all filters
            applyFilters();
        }

        // Main filter function that combines date filter and search
        function applyFilters() {
            const dateGroups = document.querySelectorAll('.date-group');
            let anyVisible = false;
            
            dateGroups.forEach(group => {
                // Get filter attributes
                const isToday = group.dataset.isToday === '1';
                const isWeek = group.dataset.isWeek === '1';
                
                // Determine if this group passes the date filter
                let passesDateFilter = true;
                
                if (currentFilter === 'today') {
                    passesDateFilter = isToday;
                } else if (currentFilter === 'week') {
                    passesDateFilter = isWeek;
                } // else 'all' passes all groups
                
                if (!passesDateFilter) {
                    group.style.display = 'none';
                    return;
                }
                
                // If passes date filter, check search within this group
                const rows = group.querySelectorAll('.modern-table tbody tr');
                let groupHasVisibleRows = false;
                
                rows.forEach(row => {
                    const text = row.textContent.toLowerCase();
                    const matchesSearch = searchTerm === '' || text.includes(searchTerm);
                    
                    if (matchesSearch) {
                        row.style.display = '';
                        groupHasVisibleRows = true;
                    } else {
                        row.style.display = 'none';
                    }
                });
                
                // Show/hide the entire group based on whether it has visible rows
                if (groupHasVisibleRows) {
                    group.style.display = '';
                    anyVisible = true;
                } else {
                    group.style.display = 'none';
                }
            });
            
            // Show/hide the no results message
            const noResults = document.getElementById('noResultsMessage');
            if (noResults) {
                noResults.style.display = anyVisible ? 'none' : 'block';
            }
        }

        // Reset filters function (optional)
        function resetFilters() {
            // Reset to 'all' filter
            const allChip = document.querySelector('.chip[onclick*="all"]');
            if (allChip) {
                document.querySelectorAll('.chip').forEach(chip => chip.classList.remove('active'));
                allChip.classList.add('active');
                currentFilter = 'all';
            }
            
            // Clear search
            if (searchInput) {
                searchInput.value = '';
                searchTerm = '';
            }
            
            // Apply filters
            applyFilters();
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Apply initial filters (shows all)
            applyFilters();
            
            // Add keyboard shortcut to clear filters (ESC)
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && (searchInput === document.activeElement || e.target.tagName !== 'INPUT')) {
                    resetFilters();
                    if (searchInput) searchInput.blur();
                }
            });
        });
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