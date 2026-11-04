<?php
// includes/sidebar.php
$current_page = basename($_SERVER['PHP_SELF']);
$user_role = $_SESSION['role'] ?? '';
$user_name = $_SESSION['full_name'] ?? 'User';
$user_initial = substr($user_name, 0, 1);

// Fix logo path for subdir pages (admin/ or staff/)
$is_subdir_page = strpos($_SERVER['PHP_SELF'], '/admin/') !== false
                || strpos($_SERVER['PHP_SELF'], '/staff/') !== false;
$is_admin_page  = strpos($_SERVER['PHP_SELF'], '/admin/') !== false; // kept for compatibility
$logo_path = $is_subdir_page ? '../assets/img/nobg_logo.png' : 'assets/img/nobg_logo.png';
$base_path = $is_subdir_page ? '../' : '';

// Build nav items array
$nav_items = [
    ['href' => $base_path.'index.php', 'page' => 'index.php', 'icon' => 'fa-gauge-high', 'label' => 'Dashboard'],
    ['href' => $base_path.'pos.php', 'page' => 'pos.php', 'icon' => 'fa-cash-register', 'label' => 'Point of Sale'],
];

if ($user_role === 'admin') {
    $nav_items[] = ['href' => $base_path.'admin/inventory.php', 'page' => 'inventory.php', 'icon' => 'fa-boxes-stacked', 'label' => 'Inventory'];
    $nav_items[] = ['href' => $base_path.'reports.php', 'page' => 'reports.php', 'icon' => 'fa-chart-line', 'label' => 'Sales Reports'];
    $nav_items[] = ['href' => $base_path.'logs.php', 'page' => 'logs.php', 'icon' => 'fa-clipboard-list', 'label' => 'Activity Logs'];
    $nav_items[] = ['href' => $base_path.'admin/users.php', 'page' => 'users.php', 'icon' => 'fa-users', 'label' => 'User Management'];
} else {
    $nav_items[] = ['href' => $base_path.'admin/staff_inventory.php', 'page' => 'staff_inventory.php', 'icon' => 'fa-boxes-stacked', 'label' => 'Inventory'];
    $nav_items[] = ['href' => $base_path.'staff/sales_report.php', 'page' => 'sales_report.php', 'icon' => 'fa-chart-bar', 'label' => 'Sales Report'];
}

// Calculate positions for highlight
$position_map = [
    'index.php' => 16,
    'pos.php' => 70,
    'inventory.php' => 124,
    'staff_inventory.php' => 124,
    'sales_report.php' => 178,
    'reports.php' => 178,
    'logs.php' => 232,
    'users.php' => 286
];
$highlight_top = $position_map[$current_page] ?? 16;
?>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

    /* ===== SIDEBAR ONLY STYLES ===== */
    /* These styles ONLY affect the sidebar, not other pages */
    
    #nav-toggle {
        display: none;
    }

    /* Main content adjustment - only affects pages with sidebar */
    .main-content {
        margin-left: calc(280px + 24px) !important;
        padding: 24px !important;
        min-height: 100vh !important;
        background: #f8fafc !important;
        transition: margin-left 0.3s ease !important;
        width: calc(100% - 280px - 24px) !important;
        box-sizing: border-box !important;
    }

    #nav-toggle:checked ~ .main-content {
        margin-left: calc(80px + 24px) !important;
        width: calc(100% - 80px - 24px) !important;
    }

    /* Sidebar container */
    #nav-bar {
        position: fixed;
        left: 12px;
        top: 12px;
        bottom: 12px;
        width: 280px;
        background: #004d40;
        border-radius: 24px;
        display: flex;
        flex-direction: column;
        color: #f5f6fa;
        transition: width 0.3s ease;
        z-index: 1000;
        box-shadow: 0 20px 35px -10px rgba(0, 0, 0, 0.3);
        font-family: 'Inter', sans-serif;
        overflow: hidden; /* Hides all scrollbars */
    }

    #nav-bar * {
        box-sizing: border-box;
    }

    /* Header with logo */
    #nav-header {
        padding: 0 20px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        display: flex;
        align-items: center;
        gap: 12px;
        position: relative;
        flex-shrink: 0;
        background: #004d40;
        height: 80px;
        min-height: 80px;
    }

    .sidebar-logo-img {
        width: 42px;
        height: 42px;
        object-fit: contain;
        filter: drop-shadow(0 4px 8px rgba(184, 134, 11, 0.3));
        flex-shrink: 0;
    }

    .sidebar-logo-text {
        overflow: hidden;
        white-space: nowrap;
        transition: opacity 0.3s;
    }

    .sidebar-logo-text strong {
        display: block;
        font-size: 1.1rem;
        font-weight: 700;
        color: white;
        letter-spacing: 0.5px;
    }

    .sidebar-logo-text small {
        display: block;
        font-size: 0.6rem;
        color: #b8860b;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        margin-top: 2px;
    }

    /* Hamburger menu */
    .sidebar-toggle-label {
        position: absolute;
        right: 16px;
        top: 50%;
        transform: translateY(-50%);
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        border-radius: 8px;
        transition: background 0.2s;
    }

    .sidebar-toggle-label:hover {
        background: rgba(255, 255, 255, 0.1);
    }

    .sidebar-burger {
        position: relative;
        width: 18px;
        height: 2px;
        background: #f5f6fa;
        border-radius: 4px;
    }

    .sidebar-burger::before,
    .sidebar-burger::after {
        content: '';
        position: absolute;
        width: 18px;
        height: 2px;
        background: #f5f6fa;
        border-radius: 4px;
        transition: all 0.2s;
    }

    .sidebar-burger::before { top: -6px; }
    .sidebar-burger::after { top: 6px; }

    /* Navigation area - NO SCROLLBAR */
    #nav-content {
        flex: 1;
        padding: 16px 0;
        position: relative;
        background: #004d40;
        overflow: visible; /* Changed from overflow-y: auto */
        height: auto;
    }

    /* Highlight effect */
    #nav-content-highlight {
        position: absolute;
        left: 16px;
        top: <?php echo $highlight_top; ?>px;
        width: calc(100% - 16px);
        height: 52px;
        background: #f8fafc;
        border-radius: 16px 0 0 16px;
        transition: top 0.2s ease;
        z-index: 0;
        opacity: 0.15;
        pointer-events: none;
    }

    #nav-content-highlight::before,
    #nav-content-highlight::after {
        content: '';
        position: absolute;
        right: 0;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        pointer-events: none;
    }

    #nav-content-highlight::before {
        bottom: 100%;
        box-shadow: 14px 14px #f8fafc;
    }

    #nav-content-highlight::after {
        top: 100%;
        box-shadow: 14px -14px #f8fafc;
    }

    /* Navigation buttons */
    .sidebar-nav-btn {
        position: relative;
        margin-left: 16px;
        height: 52px;
        display: flex;
        align-items: center;
        color: #b0bec5;
        text-decoration: none;
        z-index: 1;
        transition: all 0.2s;
        white-space: nowrap;
        border-radius: 12px 0 0 12px;
        padding-right: 16px;
        width: calc(100% - 16px);
    }

    .sidebar-nav-btn i {
        min-width: 2.8rem;
        text-align: center;
        font-size: 1.1rem;
        transition: transform 0.2s;
    }

    .sidebar-nav-btn span {
        font-size: 0.9rem;
        font-weight: 500;
        letter-spacing: 0.2px;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .sidebar-nav-btn:hover {
        color: white;
        background: rgba(255, 255, 255, 0.1);
        transform: translateX(4px);
    }

    .sidebar-nav-btn:hover i {
        transform: scale(1.1);
        color: #b8860b;
    }

    .sidebar-nav-btn.active {
        color: white;
        background: #b8860b;
    }

    .sidebar-nav-btn.active i {
        color: white;
    }

    /* Hover highlight movement */
    .sidebar-nav-btn:nth-of-type(1):hover ~ #nav-content-highlight { top: 16px; }
    .sidebar-nav-btn:nth-of-type(2):hover ~ #nav-content-highlight { top: 68px; }
    .sidebar-nav-btn:nth-of-type(3):hover ~ #nav-content-highlight { top: 120px; }
    .sidebar-nav-btn:nth-of-type(4):hover ~ #nav-content-highlight { top: 172px; }
    .sidebar-nav-btn:nth-of-type(5):hover ~ #nav-content-highlight { top: 224px; }
    .sidebar-nav-btn:nth-of-type(6):hover ~ #nav-content-highlight { top: 276px; }
    .sidebar-nav-btn:nth-of-type(7):hover ~ #nav-content-highlight { top: 328px; }
    .sidebar-nav-btn:nth-of-type(8):hover ~ #nav-content-highlight { top: 380px; }

    /* User section */
    .sidebar-user-section {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 16px 20px;
        border-top: 1px solid rgba(255, 255, 255, 0.1);
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        background: #004d40;
        flex-shrink: 0;
    }

    .sidebar-user-avatar {
        width: 42px;
        height: 42px;
        background: linear-gradient(135deg, #b8860b, #e8b84b);
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 1.1rem;
        color: white;
        flex-shrink: 0;
        box-shadow: 0 5px 10px rgba(184, 134, 11, 0.3);
    }

    .sidebar-user-info {
        overflow: hidden;
    }

    .sidebar-user-name {
        display: block;
        font-size: 0.95rem;
        font-weight: 700;
        color: white;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        line-height: 1.3;
        margin-bottom: 2px;
    }

    .sidebar-user-role {
        display: block;
        font-size: 0.7rem;
        color: #b8860b;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* Logout section */
    .sidebar-footer {
        padding: 16px 20px;
        flex-shrink: 0;
        background: #004d40;
    }

    .sidebar-logout-btn {
        display: flex;
        align-items: center;
        gap: 14px;
        padding: 12px 16px;
        color: #fc8181;
        text-decoration: none;
        border-radius: 14px;
        font-size: 0.9rem;
        font-weight: 600;
        transition: all 0.2s;
        background: rgba(252, 129, 129, 0.05);
        white-space: nowrap;
    }

    .sidebar-logout-btn i {
        width: 22px;
        text-align: center;
        font-size: 1rem;
    }

    .sidebar-logout-btn:hover {
        background: #fc8181;
        color: white;
        transform: translateX(4px);
    }

    /* Collapsed state */
    #nav-toggle:checked ~ #nav-bar {
        width: 80px;
    }

    #nav-toggle:checked ~ #nav-bar .sidebar-logo-text,
    #nav-toggle:checked ~ #nav-bar .sidebar-nav-btn span,
    #nav-toggle:checked ~ #nav-bar .sidebar-user-info,
    #nav-toggle:checked ~ #nav-bar .sidebar-logout-btn span {
        opacity: 0;
        width: 0;
        display: none;
    }

    #nav-toggle:checked ~ #nav-bar .sidebar-logo-img {
        display: none;
    }

    #nav-toggle:checked ~ #nav-bar .sidebar-nav-btn {
        justify-content: center;
        padding: 0;
        margin-left: 0;
        width: 100%;
    }

    #nav-toggle:checked ~ #nav-bar .sidebar-user-section {
        justify-content: center;
        padding: 16px 0;
    }

    #nav-toggle:checked ~ #nav-bar .sidebar-logout-btn {
        justify-content: center;
        padding: 12px 0;
    }

    #nav-toggle:checked ~ #nav-bar .sidebar-toggle-label {
        right: 50%;
        transform: translateX(50%);
        top: 0;
        bottom: 0;
        height: 100%;
        width: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    #nav-toggle:checked ~ #nav-bar #nav-content-highlight {
        opacity: 0;
    }

    /* Tooltips on hover when collapsed */
    #nav-toggle:checked ~ #nav-bar .sidebar-nav-btn:hover::after,
    #nav-toggle:checked ~ #nav-bar .sidebar-logout-btn:hover::after {
        content: attr(data-tip);
        position: absolute;
        left: 100%;
        top: 50%;
        transform: translateY(-50%);
        background: #004d40;
        color: white;
        padding: 8px 12px;
        border-radius: 8px;
        font-size: 0.8rem;
        white-space: nowrap;
        margin-left: 10px;
        border-left: 3px solid #b8860b;
        z-index: 1000;
        pointer-events: none;
    }

    /* Mobile responsive */
    @media (max-width: 768px) {
        #nav-bar {
            left: 0;
            top: 0;
            bottom: 0;
            border-radius: 0;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
        }

        #nav-toggle:checked ~ #nav-bar {
            transform: translateX(0);
        }

        .main-content {
            margin-left: 0 !important;
            width: 100% !important;
        }

        .mobile-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        #nav-toggle:checked ~ .mobile-overlay {
            display: block;
        }

        /* Hide tooltips on mobile */
        .sidebar-nav-btn:hover::after,
        .sidebar-logout-btn:hover::after {
            display: none !important;
        }
    }

    .mobile-overlay {
        display: none;
    }

    /* Remove all scrollbars completely */
    #nav-bar::-webkit-scrollbar,
    #nav-content::-webkit-scrollbar,
    .sidebar-nav-btn::-webkit-scrollbar,
    .sidebar-user-section::-webkit-scrollbar,
    .sidebar-footer::-webkit-scrollbar {
        display: none;
        width: 0;
        height: 0;
    }

    /* Firefox scrollbar hide */
    #nav-bar, #nav-content {
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
</style>

<!-- Mobile overlay -->
<div class="mobile-overlay" onclick="document.getElementById('nav-toggle').checked = false"></div>

<input type="checkbox" id="nav-toggle" <?php echo isset($_COOKIE['sidebar_collapsed']) && $_COOKIE['sidebar_collapsed'] == 'true' ? 'checked' : ''; ?>>

<!-- Sidebar -->
<div id="nav-bar">
    <!-- Header with Logo -->
    <div id="nav-header">
        <img class="sidebar-logo-img" src="<?php echo $logo_path; ?>" alt="CAD CREST"
             onerror="this.src='data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSI0MiIgaGVpZ2h0PSI0MiI+PHJlY3Qgd2lkdGg9IjQyIiBoZWlnaHQ9IjQyIiBmaWxsPSIjYjg4NjBiIiByeD0iOCIvPjx0ZXh0IHg9IjIxIiB5PSIyNyIgZm9udC1zaXplPSIxNCIgZm9udC13ZWlnaHQ9ImJvbGQiIHRleHQtYW5jaG9yPSJtaWRkbGUiIGZpbGw9IndoaXRlIj5DQzwvdGV4dD48L3N2Zz4=';">
        <div class="sidebar-logo-text">
            <strong>CAD CREST</strong>
            <small>Pharmaceutical Trading</small>
        </div>
        <label for="nav-toggle" class="sidebar-toggle-label">
            <span class="sidebar-burger"></span>
        </label>
    </div>

    <!-- Navigation Content -->
    <div id="nav-content">
        <!-- Moving highlight -->
        <div id="nav-content-highlight"></div>

        <!-- Menu Items -->
        <?php foreach ($nav_items as $item): 
            $is_active = ($item['page'] === $current_page);
        ?>
        <a href="<?php echo $item['href']; ?>" class="sidebar-nav-btn <?php echo $is_active ? 'active' : ''; ?>" data-tip="<?php echo $item['label']; ?>">
            <i class="fas <?php echo $item['icon']; ?>"></i>
            <span><?php echo $item['label']; ?></span>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- User Section -->
    <div class="sidebar-user-section">
        <div class="sidebar-user-avatar"><?php echo strtoupper($user_initial); ?></div>
        <div class="sidebar-user-info">
            <span class="sidebar-user-name"><?php echo htmlspecialchars($user_name ?: 'User'); ?></span>
            <span class="sidebar-user-role"><?php echo $user_role === 'admin' ? 'Administrator' : 'Staff'; ?></span>
        </div>
    </div>

    <!-- Logout -->
    <div class="sidebar-footer">
        <button type="button" onclick="showLogoutModal()" class="sidebar-logout-btn" data-tip="Logout"
                style="background:none;border:none;width:100%;cursor:pointer;text-align:left;">
            <i class="fa-solid fa-right-from-bracket"></i>
            <span>Logout</span>
        </button>
    </div>
</div>

<!-- ── Logout Confirmation Modal ──────────────────────────────────────────── -->
<div id="logoutModal" style="
    display:none;
    position:fixed;
    inset:0;
    background:rgba(0,0,0,.45);
    z-index:99999;
    align-items:center;
    justify-content:center;
    backdrop-filter:blur(3px);
">
    <div style="
        background:#fff;
        border-radius:20px;
        width:90%;
        max-width:380px;
        box-shadow:0 24px 48px -8px rgba(0,0,0,.25);
        overflow:hidden;
        animation:logoutSlideIn .22s ease;
    ">
        <!-- Header -->
        <div style="
            background:linear-gradient(135deg,#004d40,#00695c);
            padding:1.25rem 1.5rem;
            display:flex;
            align-items:center;
            gap:.9rem;
        ">
            <div style="
                width:42px;height:42px;
                background:rgba(255,255,255,.18);
                border-radius:12px;
                display:flex;align-items:center;justify-content:center;
                flex-shrink:0;
            ">
                <i class="fa-solid fa-right-from-bracket" style="color:#fff;font-size:1.1rem;"></i>
            </div>
            <div>
                <div style="color:#fff;font-weight:800;font-size:1rem;">Sign Out</div>
                <div style="color:rgba(255,255,255,.75);font-size:.78rem;">CAD Crest Pharmacy</div>
            </div>
            <button onclick="hideLogoutModal()" style="
                margin-left:auto;background:rgba(255,255,255,.18);border:none;
                width:32px;height:32px;border-radius:50%;cursor:pointer;
                display:flex;align-items:center;justify-content:center;color:#fff;font-size:.9rem;
            "><i class="fa-solid fa-times"></i></button>
        </div>

        <!-- Body -->
        <div style="padding:1.5rem;text-align:center;">
            <div style="
                width:64px;height:64px;
                background:#fee2e2;
                border-radius:50%;
                display:flex;align-items:center;justify-content:center;
                margin:0 auto .9rem;
            ">
                <i class="fa-solid fa-door-open" style="font-size:1.7rem;color:#ef4444;"></i>
            </div>
            <p style="font-size:.98rem;font-weight:700;color:#1e293b;margin:0 0 .4rem;">
                Are you sure you want to sign out?
            </p>
            <p style="font-size:.82rem;color:#64748b;margin:0 0 1.5rem;">
                You will be redirected to the login page.
            </p>

            <div style="display:flex;gap:.75rem;">
                <button onclick="hideLogoutModal()" style="
                    flex:1;padding:.7rem;border:1.5px solid #e2e8f0;background:#fff;
                    border-radius:10px;font-size:.88rem;font-weight:600;color:#475569;
                    cursor:pointer;transition:background .15s;
                " onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                    <i class="fa-solid fa-xmark"></i> Cancel
                </button>
                <a href="<?php echo $base_path; ?>logout.php" style="
                    flex:1;padding:.7rem;background:linear-gradient(135deg,#ef4444,#dc2626);
                    border-radius:10px;font-size:.88rem;font-weight:700;color:#fff;
                    text-decoration:none;display:flex;align-items:center;justify-content:center;gap:.4rem;
                    transition:opacity .15s;
                " onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
                    <i class="fa-solid fa-right-from-bracket"></i> Sign Out
                </a>
            </div>
        </div>
    </div>
</div>

<style>
@keyframes logoutSlideIn {
    from { transform: translateY(-20px); opacity: 0; }
    to   { transform: translateY(0);     opacity: 1; }
}
</style>

<script>
    function showLogoutModal() {
        const m = document.getElementById('logoutModal');
        m.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    function hideLogoutModal() {
        const m = document.getElementById('logoutModal');
        m.style.display = 'none';
        document.body.style.overflow = '';
    }
    // Close on backdrop click
    document.getElementById('logoutModal').addEventListener('click', function(e) {
        if (e.target === this) hideLogoutModal();
    });
    // Close on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && document.getElementById('logoutModal').style.display === 'flex') {
            hideLogoutModal();
        }
    });

    document.getElementById('nav-toggle').addEventListener('change', function() {
        document.cookie = 'sidebar_collapsed=' + this.checked + '; path=/; max-age=' + (30 * 24 * 60 * 60);
    });

    document.querySelector('.mobile-overlay')?.addEventListener('click', function() {
        document.getElementById('nav-toggle').checked = false;
    });
</script>