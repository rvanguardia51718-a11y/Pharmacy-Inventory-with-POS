<?php
/**
 * includes/notifications.php
 * 
 * ENHANCED FLOATING NOTIFICATION SYSTEM
 * Features:
 * - Modern floating design with glass morphism
 * - Correct time display (fixes the 6 hours ago issue)
 * - Real-time updates with proper animations
 * - Working cards for expired/low stock in dashboards
 */

require_once __DIR__ . '/inventory_helper.php';

// ─── Helper: accurate time ago with proper formatting ─────────────────
function timeAgo(string $datetime): string {
    $now = new DateTime();
    $past = new DateTime($datetime);
    $diff = $now->diff($past);
    
    if ($diff->y > 0) return $diff->y . ' year' . ($diff->y > 1 ? 's' : '') . ' ago';
    if ($diff->m > 0) return $diff->m . ' month' . ($diff->m > 1 ? 's' : '') . ' ago';
    if ($diff->d > 0) return $diff->d . ' day' . ($diff->d > 1 ? 's' : '') . ' ago';
    if ($diff->h > 0) return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '') . ' ago';
    if ($diff->i > 0) return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '') . ' ago';
    if ($diff->s > 10) return $diff->s . ' seconds ago';
    return 'just now';
}

// ─── Get unread count ───────────────────────────────────────
function getUnreadCount(mysqli $conn, int $user_id): int {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $count = $result->fetch_row()[0];
    $stmt->close();
    return (int)$count;
}

// ─── Fetch all notifications ─────────────────────────────────
function fetchAllNotifications(mysqli $conn, int $user_id): array {
    $sql = "SELECT n.*, 
            CASE 
                WHEN n.type IN ('expired', 'out_of_stock') THEN 'critical'
                WHEN n.type IN ('near_expiry', 'low_stock') THEN 'warning'
                ELSE 'info'
            END as severity
            FROM notifications n 
            WHERE n.user_id=?
            ORDER BY 
              n.created_at DESC 
            LIMIT 100";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $notifications = [];
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
    $stmt->close();
    return $notifications;
}

// ─── Render notification bell with modern design ────────────
function renderNotificationBell(mysqli $conn): void {
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    if (!$user_id) return;

    // Generate fresh notifications
    generateInventoryNotifications($conn, $user_id);
    $unread_count = getUnreadCount($conn, $user_id);
    ?>
    <div class="notification-bell-container">
        <button class="notification-bell" id="notificationBell" onclick="toggleNotificationPanel()" aria-label="Notifications">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M18 8C18 6.4087 17.3679 4.88258 16.2426 3.75736C15.1174 2.63214 13.5913 2 12 2C10.4087 2 8.88258 2.63214 7.75736 3.75736C6.63214 4.88258 6 6.4087 6 8C6 15 3 17 3 17H21C21 17 18 15 18 8Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M9 17V18C9 18.7956 9.31607 19.5587 9.87868 20.1213C10.4413 20.6839 11.2044 21 12 21C12.7956 21 13.5587 20.6839 14.1213 20.1213C14.6839 19.5587 15 18.7956 15 18V17" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <?php if ($unread_count > 0): ?>
                <span class="notification-badge" id="notificationBadge">
                    <?= $unread_count > 99 ? '99+' : $unread_count ?>
                </span>
                <span class="notification-pulse"></span>
            <?php endif; ?>
        </button>
    </div>
    <?php
}

// ─── Render the main notification panel - FLOATING STYLE ─────────────────────
function renderNotificationPanel(mysqli $conn): void {
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    if (!$user_id) return;

    $notifications = fetchAllNotifications($conn, $user_id);
    $unread_count = getUnreadCount($conn, $user_id);
    
    // Group notifications by date
    $grouped = [];
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    
    foreach ($notifications as $notif) {
        $date = date('Y-m-d', strtotime($notif['created_at']));
        if ($date == $today) {
            $grouped['Today'][] = $notif;
        } elseif ($date == $yesterday) {
            $grouped['Yesterday'][] = $notif;
        }
        // Older notifications are NOT shown (only Today and Yesterday per requirements)
    }

    // Determine correct path based on current page
    $current_page = $_SERVER['PHP_SELF'] ?? '';
    $base_path = '';
    
    // If we're in an admin subdirectory, we need to go up one level
    if (strpos($current_page, '/admin/') !== false) {
        $base_path = '../';
    }
    
    $handler = $base_path . 'ajax/notifications_handler.php';
    $inventory_url = $base_path . 'admin/inventory.php';
    $logs_url = $base_path . 'logs.php';
    ?>
    
    <!-- Notification Panel - Floating Style -->
    <div class="notification-floating-panel" id="notificationPanel">
        <!-- Panel Header -->
        <div class="floating-header">
            <div class="header-title">
                <h2>Notifications</h2>
                <span class="header-badge" id="notificationCount"><?= $unread_count ?> new</span>
            </div>
            <button class="header-close" onclick="toggleNotificationPanel()" aria-label="Close">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 6L6 18M6 6L18 18" stroke-linecap="round"/>
                </svg>
            </button>
        </div>

        <!-- Actions Bar -->
        <div class="floating-actions">
            <button class="mark-all-btn" id="markAllReadBtn" onclick="markAllAsRead()" <?= $unread_count == 0 ? 'disabled' : '' ?>>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M20 6L9 17L4 12" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Mark all as read
            </button>
            <span class="live-badge">
                <span class="live-dot"></span> Live
            </span>
        </div>

        <!-- Notifications List -->
        <div class="floating-list" id="notificationsList">
            <?php if (empty($notifications)): ?>
                <div class="empty-state">
                    <div class="empty-icon">🎉</div>
                    <h3>All caught up!</h3>
                    <p>No new notifications</p>
                </div>
            <?php else: ?>
                <?php foreach ($grouped as $date_label => $date_notifications): ?>
                    <div class="date-divider">
                        <span class="date-text"><?= $date_label ?></span>
                    </div>
                    <?php foreach ($date_notifications as $notif): 
                        $severity = $notif['severity'];
                        $is_read = $notif['is_read'];
                        $ref_id = (int)($notif['reference_id'] ?? 0);
                        $is_admin_user = isAdmin();
                        
                        // Map icons
                        $icons = [
                            'out_of_stock' => '📦',
                            'low_stock' => '⚠️',
                            'near_expiry' => '⏰',
                            'expired' => '❌',
                            'product_added' => '➕',
                            'product_updated' => '✏️',
                            'product_deleted' => '🗑️',
                            'stock_added' => '📥'
                        ];
                        $icon = $icons[$notif['type']] ?? '🔔';
                        
                        // Background color based on severity and read status
                        $bg_class = $is_read ? 'read' : $severity;
                    ?>
                        <div class="notification-card <?= $bg_class ?>" 
                             data-id="<?= $notif['id'] ?>" 
                             data-type="<?= $notif['type'] ?>"
                             id="notification-<?= $notif['id'] ?>">
                            
                            <div class="card-icon">
                                <?= $icon ?>
                            </div>

                            <div class="card-content">
                                <div class="card-message"><?= htmlspecialchars($notif['message']) ?></div>
                                <div class="card-time">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <circle cx="12" cy="12" r="10"/>
                                        <path d="M12 6v6l4 2"/>
                                    </svg>
                                    <?= $date_label ?>
                                </div>
                                
                                <?php if (!$is_read): ?>
                                <div class="card-actions">
                                    <?php if (in_array($notif['type'], ['out_of_stock', 'low_stock']) && $ref_id > 0): ?>
                                        <button class="action-btn primary" onclick="openRestockFromNotif(<?= $ref_id ?>, <?= $notif['id'] ?>)">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M12 5v14M5 12h14" stroke-linecap="round"/>
                                            </svg>
                                            Restock
                                        </button>
                                    <?php elseif (in_array($notif['type'], ['near_expiry', 'expired']) && $ref_id > 0 && $is_admin_user): ?>
                                        <button class="action-btn danger" onclick="removeFromNotif(<?= $ref_id ?>, <?= $notif['id'] ?>, '<?= htmlspecialchars(addslashes($notif['message'])) ?>')">
                                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                                            </svg>
                                            Remove
                                        </button>
                                    <?php elseif (in_array($notif['type'], ['near_expiry', 'expired']) && !$is_admin_user): ?>
                                        <span style="font-size:0.7rem;color:#94a3b8;font-style:italic;">Admin only</span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>

                            <?php if (!$is_read): ?>
                            <button class="mark-read-btn" onclick="markAsRead(<?= $notif['id'] ?>, this)" title="Mark as read">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M8 12l2 2 6-6"/>
                                </svg>
                            </button>
                            <?php else: ?>
                            <div class="read-check" title="Read">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="3">
                                    <path d="M20 6L9 17L4 12" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="floating-footer">
            <a href="<?= $logs_url ?>" class="footer-link">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6z"/>
                    <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>
                </svg>
                View Activity Logs
            </a>
        </div>
    </div>

    <!-- Backdrop -->
    <div class="notification-backdrop" id="notificationBackdrop" onclick="toggleNotificationPanel()"></div>

    <!-- Toast Container -->
    <div class="toast-container" id="toastContainer"></div>

    <style>
    /* ===== Floating Notification Styles ===== */
    .notification-bell-container {
        position: relative;
        display: inline-block;
    }

    .notification-bell {
        background: white;
        border: none;
        width: 44px;
        height: 44px;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: #004d40;
        transition: all 0.3s ease;
        position: relative;
        box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
    }

    .notification-bell:hover {
        background: #f0fdf4;
        transform: translateY(-2px);
        box-shadow: 0 8px 25px rgba(0, 77, 64, 0.15);
    }

    .notification-badge {
        position: absolute;
        top: -6px;
        right: -6px;
        background: linear-gradient(135deg, #dc2626, #b91c1c);
        color: white;
        font-size: 11px;
        font-weight: 700;
        min-width: 20px;
        height: 20px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 5px;
        border: 2px solid white;
        animation: badgePop 0.3s ease;
        z-index: 2;
    }

    .notification-pulse {
        position: absolute;
        top: -6px;
        right: -6px;
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background: rgba(220, 38, 38, 0.3);
        animation: pulse 2s infinite;
        z-index: 1;
    }

    @keyframes badgePop {
        0% { transform: scale(0); }
        80% { transform: scale(1.1); }
        100% { transform: scale(1); }
    }

    @keyframes pulse {
        0% { transform: scale(1); opacity: 1; }
        100% { transform: scale(2); opacity: 0; }
    }

    /* ===== Floating Panel ===== */
    .notification-floating-panel {
        position: fixed;
        top: 80px;
        right: 24px;
        width: 420px;
        max-width: calc(100vw - 48px);
        background: rgba(255, 255, 255, 0.98);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        border-radius: 24px;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        z-index: 9999;
        display: flex;
        flex-direction: column;
        opacity: 0;
        visibility: hidden;
        transform: translateY(-10px) scale(0.95);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        border: 1px solid rgba(255, 255, 255, 0.5);
    }

    .notification-floating-panel.active {
        opacity: 1;
        visibility: visible;
        transform: translateY(0) scale(1);
    }

    /* Backdrop */
    .notification-backdrop {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.3);
        backdrop-filter: blur(4px);
        -webkit-backdrop-filter: blur(4px);
        z-index: 9998;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
    }

    .notification-backdrop.active {
        opacity: 1;
        visibility: visible;
    }

    /* Panel Header */
    .floating-header {
        padding: 20px 24px;
        background: linear-gradient(135deg, #004d40, #00695c);
        border-radius: 24px 24px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .header-title h2 {
        margin: 0;
        font-size: 1.3rem;
        font-weight: 700;
        color: white;
        letter-spacing: -0.01em;
    }

    .header-badge {
        font-size: 0.75rem;
        background: rgba(255, 255, 255, 0.2);
        padding: 4px 12px;
        border-radius: 40px;
        color: white;
        margin-top: 4px;
        display: inline-block;
    }

    .header-close {
        background: rgba(255, 255, 255, 0.2);
        border: none;
        width: 36px;
        height: 36px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        color: white;
        transition: all 0.2s;
    }

    .header-close:hover {
        background: rgba(255, 255, 255, 0.3);
        transform: rotate(90deg);
    }

    /* Actions Bar */
    .floating-actions {
        padding: 12px 20px;
        background: white;
        border-bottom: 1px solid #edf2f7;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .mark-all-btn {
        background: none;
        border: none;
        color: #64748b;
        font-size: 0.85rem;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 6px 12px;
        border-radius: 10px;
        transition: all 0.2s;
    }

    .mark-all-btn:hover:not(:disabled) {
        background: #f1f5f9;
        color: #004d40;
    }

    .mark-all-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .live-badge {
        background: #f1f5f9;
        padding: 4px 12px;
        border-radius: 40px;
        font-size: 0.7rem;
        color: #64748b;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .live-dot {
        width: 8px;
        height: 8px;
        background: #10b981;
        border-radius: 50%;
        animation: blink 2s infinite;
    }

    @keyframes blink {
        0%, 100% { opacity: 1; }
        50% { opacity: 0.3; }
    }

    /* Notifications List */
    .floating-list {
        flex: 1;
        overflow-y: auto;
        max-height: 60vh;
        padding: 16px;
        background: #f8fafc;
    }

    .date-divider {
        margin: 16px 0 8px;
        padding-left: 8px;
    }

    .date-text {
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #94a3b8;
        background: #f1f5f9;
        padding: 2px 12px;
        border-radius: 40px;
    }

    /* Notification Cards */
    .notification-card {
        background: white;
        border-radius: 18px;
        padding: 16px;
        margin-bottom: 10px;
        display: flex;
        gap: 14px;
        transition: all 0.3s ease;
        border: 1px solid #edf2f7;
        position: relative;
        animation: cardSlide 0.3s ease;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
    }

    .notification-card.critical {
        background: linear-gradient(135deg, #fff5f5, white);
        border-left: 4px solid #dc2626;
    }

    .notification-card.warning {
        background: linear-gradient(135deg, #fffbeb, white);
        border-left: 4px solid #f97316;
    }

    .notification-card.info {
        border-left: 4px solid #3b82f6;
    }

    .notification-card.read {
        opacity: 0.7;
        background: #f8fafc;
    }

    .notification-card:hover {
        transform: translateX(-4px) translateY(-2px);
        box-shadow: 0 12px 25px -8px rgba(0, 0, 0, 0.15);
    }

    @keyframes cardSlide {
        from {
            opacity: 0;
            transform: translateX(20px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    .card-icon {
        width: 40px;
        height: 40px;
        background: #f1f5f9;
        border-radius: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.4rem;
        flex-shrink: 0;
    }

    .card-content {
        flex: 1;
    }

    .card-message {
        font-size: 0.9rem;
        color: #1e293b;
        font-weight: 500;
        margin-bottom: 4px;
        line-height: 1.4;
        padding-right: 24px;
    }

    .card-time {
        font-size: 0.7rem;
        color: #94a3b8;
        display: flex;
        align-items: center;
        gap: 4px;
        margin-bottom: 8px;
    }

    .card-actions {
        display: flex;
        gap: 8px;
        margin-top: 8px;
    }

    .action-btn {
        padding: 6px 14px;
        border-radius: 30px;
        font-size: 0.7rem;
        font-weight: 600;
        border: none;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: all 0.2s;
    }

    .action-btn.primary {
        background: #004d40;
        color: white;
    }

    .action-btn.primary:hover {
        background: #00695c;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 77, 64, 0.3);
    }

    .action-btn.warning {
        background: #f97316;
        color: white;
    }

    .action-btn.warning:hover {
        background: #fb923c;
        transform: translateY(-2px);
    }

    .action-btn.danger {
        background: #dc2626;
        color: white;
    }

    .action-btn.danger:hover {
        background: #ef4444;
        transform: translateY(-2px);
    }

    .mark-read-btn {
        position: absolute;
        top: 16px;
        right: 16px;
        background: none;
        border: none;
        color: #94a3b8;
        cursor: pointer;
        padding: 4px;
        border-radius: 8px;
        transition: all 0.2s;
        opacity: 0;
    }

    .notification-card:hover .mark-read-btn {
        opacity: 1;
    }

    .mark-read-btn:hover {
        color: #004d40;
        background: #e6f7e6;
    }

    .read-check {
        position: absolute;
        top: 16px;
        right: 16px;
    }

    /* Footer */
    .floating-footer {
        padding: 16px 24px;
        border-top: 1px solid #edf2f7;
        background: white;
        border-radius: 0 0 24px 24px;
    }

    .footer-link {
        display: flex;
        align-items: center;
        gap: 8px;
        color: #004d40;
        text-decoration: none;
        font-size: 0.85rem;
        font-weight: 600;
        transition: all 0.2s;
    }

    .footer-link:hover {
        gap: 12px;
        color: #00695c;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 40px 20px;
    }

    .empty-icon {
        font-size: 4rem;
        margin-bottom: 16px;
        animation: float 3s infinite ease-in-out;
    }

    @keyframes float {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-10px); }
    }

    .empty-state h3 {
        color: #1e293b;
        font-size: 1.1rem;
        margin-bottom: 4px;
    }

    .empty-state p {
        color: #94a3b8;
        font-size: 0.85rem;
    }

    /* Toast Container */
    .toast-container {
        position: fixed;
        bottom: 24px;
        right: 24px;
        z-index: 10000;
        display: flex;
        flex-direction: column;
        gap: 12px;
        max-width: 360px;
    }

    .toast {
        background: white;
        border-radius: 16px;
        padding: 16px;
        box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
        display: flex;
        align-items: center;
        gap: 12px;
        animation: toastIn 0.3s ease;
        border-left: 4px solid #004d40;
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
    }

    .toast.success { border-left-color: #10b981; }
    .toast.warning { border-left-color: #f97316; }
    .toast.error { border-left-color: #dc2626; }

    @keyframes toastIn {
        from {
            opacity: 0;
            transform: translateX(100%);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    @keyframes toastOut {
        to {
            opacity: 0;
            transform: translateX(100%);
        }
    }

    .toast-icon {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
    }

    .toast.success .toast-icon { background: #dcfce7; color: #10b981; }
    .toast.warning .toast-icon { background: #fff3cd; color: #f97316; }
    .toast.error .toast-icon { background: #fee2e2; color: #dc2626; }

    .toast-content {
        flex: 1;
        font-size: 0.9rem;
        color: #1e293b;
    }

    .toast-close {
        background: none;
        border: none;
        color: #94a3b8;
        cursor: pointer;
        padding: 4px;
        font-size: 1.1rem;
    }

    /* Responsive */
    @media (max-width: 640px) {
        .notification-floating-panel {
            top: 0;
            right: 0;
            width: 100%;
            height: 100vh;
            max-width: 100%;
            border-radius: 0;
        }
        
        .floating-list {
            max-height: calc(100vh - 180px);
        }
    }
    </style>

    <script>
    // Notification System JavaScript
    (function() {
        let lastNotificationId = <?= !empty($notifications) ? (int)$notifications[0]['id'] : 0 ?>;
        const handlerUrl = '<?= $handler ?>';
        const inventoryUrl = '<?= $inventory_url ?>';
        
        // Initialize from localStorage
        let readNotifications = new Set();
        
        // Load read notifications from localStorage
        try {
            const saved = localStorage.getItem('readNotifications');
            if (saved) {
                readNotifications = new Set(JSON.parse(saved));
            }
        } catch (e) {
            console.error('Error loading read notifications:', e);
        }

        // Save read notifications to localStorage
        function saveReadNotifications() {
            try {
                localStorage.setItem('readNotifications', JSON.stringify([...readNotifications]));
            } catch (e) {
                console.error('Error saving read notifications:', e);
            }
        }

        // Update badge count
        function updateBadge(count) {
            const badge = document.getElementById('notificationBadge');
            const countSpan = document.getElementById('notificationCount');
            const markAllBtn = document.getElementById('markAllReadBtn');
            const bell = document.querySelector('.notification-bell');
            
            if (count > 0) {
                if (badge) {
                    badge.textContent = count > 99 ? '99+' : count;
                } else {
                    const newBadge = document.createElement('span');
                    newBadge.className = 'notification-badge';
                    newBadge.id = 'notificationBadge';
                    newBadge.textContent = count > 99 ? '99+' : count;
                    bell.appendChild(newBadge);
                    
                    const pulse = document.createElement('span');
                    pulse.className = 'notification-pulse';
                    bell.appendChild(pulse);
                }
                
                if (countSpan) countSpan.textContent = count + ' new';
                if (markAllBtn) markAllBtn.disabled = false;
            } else {
                if (badge) badge.remove();
                const pulse = bell.querySelector('.notification-pulse');
                if (pulse) pulse.remove();
                if (countSpan) countSpan.textContent = '0 new';
                if (markAllBtn) markAllBtn.disabled = true;
            }
        }

        // Toggle panel
        window.toggleNotificationPanel = function() {
            const panel = document.getElementById('notificationPanel');
            const backdrop = document.getElementById('notificationBackdrop');
            
            panel.classList.toggle('active');
            backdrop.classList.toggle('active');
            
            if (panel.classList.contains('active')) {
                document.body.style.overflow = 'hidden';
                fetchUnreadCount();
            } else {
                document.body.style.overflow = '';
            }
        };

        // Fetch current unread count
        function fetchUnreadCount() {
            fetch(handlerUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=get_unread_count'
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    updateBadge(data.unread_count);
                }
            });
        }

        // Mark single notification as read
        window.markAsRead = function(id, button) {
            fetch(handlerUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_read&id=' + id
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    const item = document.getElementById('notification-' + id);
                    
                    // Remove unread styling
                    item.classList.remove('critical', 'warning', 'info');
                    item.classList.add('read');
                    
                    // Remove mark read button
                    if (button) button.remove();
                    
                    // Add read indicator
                    if (!item.querySelector('.read-check')) {
                        const readCheck = document.createElement('div');
                        readCheck.className = 'read-check';
                        readCheck.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="3"><path d="M20 6L9 17L4 12" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                        item.appendChild(readCheck);
                    }
                    
                    // Remove action buttons
                    item.querySelectorAll('.card-actions').forEach(actions => {
                        actions.remove();
                    });
                    
                    // Add to read set and save
                    readNotifications.add(id);
                    saveReadNotifications();
                    
                    updateBadge(data.unread_count);
                    showToast('Notification marked as read', 'success');
                }
            });
        };

        // Mark all as read
        window.markAllAsRead = function() {
            fetch(handlerUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_all_read'
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.querySelectorAll('.notification-card').forEach(item => {
                        item.classList.remove('critical', 'warning', 'info');
                        item.classList.add('read');
                        
                        const markBtn = item.querySelector('.mark-read-btn');
                        if (markBtn) markBtn.remove();
                        
                        const actions = item.querySelector('.card-actions');
                        if (actions) actions.remove();
                        
                        if (!item.querySelector('.read-check')) {
                            const readCheck = document.createElement('div');
                            readCheck.className = 'read-check';
                            readCheck.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="3"><path d="M20 6L9 17L4 12" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                            item.appendChild(readCheck);
                        }
                        
                        const id = parseInt(item.dataset.id);
                        if (id) readNotifications.add(id);
                    });
                    
                    saveReadNotifications();
                    updateBadge(0);
                    showToast('All notifications marked as read', 'success');
                }
            });
        };

        // Open restock modal from notification
        window.openRestockFromNotif = function(productId, notifId) {
            fetch(handlerUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_read&id=' + notifId
            }).then(() => {
                toggleNotificationPanel();
                
                const isInventoryPage = window.location.pathname.includes('inventory.php');
                
                if (isInventoryPage) {
                    if (typeof window.openRestockModal === 'function') {
                        fetch('<?= $base_path ?>ajax/get_product_json.php?product_id=' + productId)
                            .then(res => res.json())
                            .then(product => {
                                if (product) {
                                    window.openRestockModal(product.id, product.brand_name);
                                }
                            });
                    } else {
                        window.location.href = inventoryUrl + '?restock=' + productId;
                    }
                } else {
                    window.location.href = inventoryUrl + '?restock=' + productId;
                }
            });
        };

        // Open product modal from notification
        window.openProductFromNotif = function(productId, notifId) {
            fetch(handlerUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=mark_read&id=' + notifId
            }).then(() => {
                toggleNotificationPanel();
                
                const isInventoryPage = window.location.pathname.includes('inventory.php');
                
                if (isInventoryPage) {
                    if (typeof window.showProductDetails === 'function') {
                        window.showProductDetails(productId);
                    } else {
                        window.location.href = inventoryUrl + '?view=' + productId;
                    }
                } else {
                    window.location.href = inventoryUrl + '?view=' + productId;
                }
            });
        };

        // Remove batch from notification (with system confirmation modal - admin only)
        window.removeFromNotif = function(batchId, notifId, message) {
            // Show system confirmation modal (no browser alert)
            showNotifRemoveModal(batchId, notifId, message);
        };

        // Show remove confirmation modal
        function showNotifRemoveModal(batchId, notifId, message) {
            // Create modal if it doesn't exist
            let modal = document.getElementById('notifRemoveModal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'notifRemoveModal';
                modal.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:99999;align-items:center;justify-content:center;';
                modal.innerHTML = `
                    <div style="background:#fff;border-radius:1.5rem;padding:2rem 2.5rem;max-width:440px;width:90%;box-shadow:0 25px 60px rgba(0,0,0,.3);text-align:center;animation:modalPop .25s ease;">
                        <div style="width:64px;height:64px;background:#fef2f2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.2rem;">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                        </div>
                        <h3 style="font-size:1.2rem;font-weight:800;color:#1f2937;margin-bottom:.5rem;">Confirm Removal</h3>
                        <p id="notifRemoveMsg" style="color:#6b7280;margin-bottom:1rem;font-size:.9rem;line-height:1.5;">Are you sure you want to permanently remove this item?</p>
                        <div style="background:#fff7f7;border:1px solid #fecaca;border-radius:.75rem;padding:.75rem 1rem;margin-bottom:1.5rem;font-size:.8rem;color:#991b1b;">
                            <strong>⚠</strong> This permanently removes the batch from inventory. Cannot be undone.
                        </div>
                        <div style="display:flex;gap:.75rem;justify-content:center;">
                            <button id="notifRemoveCancel" style="padding:.7rem 1.8rem;border:2px solid #d1d5db;background:#fff;border-radius:.9rem;font-weight:700;color:#374151;cursor:pointer;">Cancel</button>
                            <button id="notifRemoveOk" style="padding:.7rem 1.8rem;background:linear-gradient(135deg,#dc2626,#b91c1c);border:none;border-radius:.9rem;font-weight:700;color:#fff;cursor:pointer;display:flex;align-items:center;gap:.5rem;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                                Confirm Remove
                            </button>
                        </div>
                    </div>
                `;
                document.body.appendChild(modal);
            }
            
            const msgEl = document.getElementById('notifRemoveMsg');
            if (msgEl) msgEl.textContent = 'Are you sure you want to permanently remove this item?\n' + message;
            
            document.getElementById('notifRemoveCancel').onclick = () => { modal.style.display = 'none'; };
            modal.style.display = 'flex';
            
            document.getElementById('notifRemoveOk').onclick = function() {
                this.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M2 12h20"/></svg> Removing...';
                this.disabled = true;
                modal.style.display = 'none';
                
                fetch(handlerUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'action=delete_batch&batch_id=' + batchId
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const item = document.getElementById('notification-' + notifId);
                        if (item) {
                            item.style.animation = 'toastOut 0.3s ease forwards';
                            setTimeout(() => item.remove(), 300);
                        }
                        readNotifications.add(notifId);
                        saveReadNotifications();
                        updateBadge(data.unread_count);
                        showToast('Batch removed from inventory', 'success');
                    } else {
                        showToast(data.message || 'Error removing batch', 'error');
                    }
                });
            };
        };

        // Delete batch action (legacy - now uses system modal)
        window.deleteBatch = function(batchId, notifId) {
            showNotifRemoveModal(batchId, notifId, 'Expired batch removal');
        };

        // Show toast notification
        function showToast(message, type = 'info') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            const icons = {
                success: '✓',
                warning: '⚠️',
                error: '✕'
            };
            
            toast.innerHTML = `
                <div class="toast-icon">${icons[type] || 'ℹ️'}</div>
                <div class="toast-content">${message}</div>
                <button class="toast-close" onclick="this.parentElement.remove()">×</button>
            `;
            
            container.appendChild(toast);
            
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.style.animation = 'toastOut 0.3s ease forwards';
                    setTimeout(() => toast.remove(), 300);
                }
            }, 5000);
        }

        // Real-time polling (every 10 seconds)
        function pollNewNotifications() {
            fetch(handlerUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'action=poll_new&last_id=' + lastNotificationId
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.notifications && data.notifications.length > 0) {
                    data.notifications.forEach(notif => {
                        if (!readNotifications.has(notif.id) && notif.id > lastNotificationId) {
                            lastNotificationId = notif.id;
                            addNewNotification(notif);
                        }
                    });
                    updateBadge(data.unread_count);
                }
            })
            .catch(err => console.error('Polling error:', err));
        }

        // Add new notification to list
        function addNewNotification(notif) {
            const list = document.getElementById('notificationsList');
            const emptyState = list.querySelector('.empty-state');
            if (emptyState) emptyState.remove();
            
            // Determine severity
            let severity = 'info';
            if (['expired', 'out_of_stock'].includes(notif.type)) severity = 'critical';
            if (['near_expiry', 'low_stock'].includes(notif.type)) severity = 'warning';
            
            const icons = {
                'out_of_stock': '📦',
                'low_stock': '⚠️',
                'near_expiry': '⏰',
                'expired': '❌',
                'product_added': '➕',
                'product_updated': '✏️',
                'product_deleted': '🗑️',
                'stock_added': '📥'
            };
            
            const refId = notif.reference_id || 0;
            
            // Check if we need a Today divider
            let todayDivider = list.querySelector('.date-divider:first-child .date-text');
            if (!todayDivider || todayDivider.textContent !== 'Today') {
                const divider = document.createElement('div');
                divider.className = 'date-divider';
                divider.innerHTML = '<span class="date-text">Today</span>';
                list.prepend(divider);
            }
            
            const notifHtml = `
                <div class="notification-card ${severity}" data-id="${notif.id}" data-type="${notif.type}" id="notification-${notif.id}">
                    <div class="card-icon">${icons[notif.type] || '🔔'}</div>
                    <div class="card-content">
                        <div class="card-message">${escapeHtml(notif.message)}</div>
                        <div class="card-time">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 6v6l4 2"/>
                            </svg>
                            Today
                        </div>
                        <div class="card-actions">
                            ${(notif.type === 'out_of_stock' || notif.type === 'low_stock') && refId > 0 ? 
                                '<button class="action-btn primary" onclick="openRestockFromNotif(' + refId + ', ' + notif.id + ')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14" stroke-linecap="round"/></svg>Restock</button>' : ''}
                            ${(notif.type === 'expired' || notif.type === 'near_expiry') && refId > 0 ? 
                                '<button class="action-btn danger" onclick="removeFromNotif(' + refId + ', ' + notif.id + ', \'' + escapeHtml(notif.message) + '\')"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>Remove</button>' : ''}
                        </div>
                    </div>
                    <button class="mark-read-btn" onclick="markAsRead(${notif.id}, this)">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"/>
                            <path d="M8 12l2 2 6-6"/>
                        </svg>
                    </button>
                </div>
            `;
            
            const firstCard = list.querySelector('.notification-card');
            if (firstCard) {
                firstCard.insertAdjacentHTML('beforebegin', notifHtml);
            } else {
                list.querySelector('.date-divider').insertAdjacentHTML('afterend', notifHtml);
            }
            
            // Show real-time toast
            const toastContainer = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${severity === 'critical' ? 'error' : severity === 'warning' ? 'warning' : 'success'}`;
            toast.innerHTML = `
                <div class="toast-icon">${icons[notif.type] || '🔔'}</div>
                <div class="toast-content"><strong>New:</strong> ${escapeHtml(notif.message)}</div>
                <button class="toast-close" onclick="this.parentElement.remove()">×</button>
            `;
            toastContainer.appendChild(toast);
            
            setTimeout(() => {
                if (toast.parentElement) {
                    toast.style.animation = 'toastOut 0.3s ease forwards';
                    setTimeout(() => toast.remove(), 300);
                }
            }, 5000);
        }

        // Escape HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Start polling
        setInterval(pollNewNotifications, 10000);

        // Close on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const panel = document.getElementById('notificationPanel');
                if (panel && panel.classList.contains('active')) {
                    toggleNotificationPanel();
                }
            }
        });

        // Apply read states from localStorage
        function applyReadStates() {
            document.querySelectorAll('.notification-card').forEach(item => {
                const id = parseInt(item.dataset.id);
                if (readNotifications.has(id)) {
                    item.classList.remove('critical', 'warning', 'info');
                    item.classList.add('read');
                    const markBtn = item.querySelector('.mark-read-btn');
                    if (markBtn) markBtn.remove();
                    
                    if (!item.querySelector('.read-check')) {
                        const readCheck = document.createElement('div');
                        readCheck.className = 'read-check';
                        readCheck.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="3"><path d="M20 6L9 17L4 12" stroke-linecap="round" stroke-linejoin="round"/></svg>';
                        item.appendChild(readCheck);
                    }
                    
                    const actions = item.querySelector('.card-actions');
                    if (actions) actions.remove();
                }
            });
        }

        document.addEventListener('DOMContentLoaded', applyReadStates);
    })();
    </script>
    <?php
}
?>