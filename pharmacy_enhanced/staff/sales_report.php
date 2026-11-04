<?php
/**
 * staff/sales_report.php
 * Staff can only view TODAY's sales. Resets at midnight automatically.
 *
 * FIXES APPLIED:
 *  1. requireLogin() did not exist → replaced with restrictAccess() (correct function in auth_check.php)
 *  2. .page-wrap had hardcoded margin-left:250px → replaced with .main-content so sidebar collapse works
 *  3. Added renderNotificationBell() + renderNotificationPanel() for consistency with other pages
 *  4. Added totals footer row to the table
 *  5. Improved modal with cash/change rows and better error states
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/notifications.php';

// FIX 1: requireLogin() does not exist — use restrictAccess() from auth_check.php
restrictAccess();

$user_id   = (int)$_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? 'Staff';
$user_role = $_SESSION['role']      ?? 'staff';

// Staff only sees TODAY
$today = date('Y-m-d');

// Fetch only today's sales for this staff member
$sales_stmt = $conn->prepare("
    SELECT s.id, s.total_amount, s.discount, s.tax, s.grand_total,
           s.cash_received, s.change_amount, s.sale_date, u.full_name
    FROM sales s
    JOIN users u ON s.user_id = u.id
    WHERE DATE(s.sale_date) = ? AND s.user_id = ?
    ORDER BY s.sale_date DESC
");
$sales_stmt->bind_param("si", $today, $user_id);
$sales_stmt->execute();
$sales = $sales_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$sales_stmt->close();

$total_revenue = array_sum(array_column($sales, 'grand_total'));
$tx_count      = count($sales);
$avg_sale      = $tx_count > 0 ? $total_revenue / $tx_count : 0;

// ── COGS, Profit, Sold-As (same logic as admin reports.php) ──────────────────
$total_cogs   = 0;
$total_profit = 0;
$cogs_map     = [];
$sold_as_map  = [];

if (!empty($sales)) {
    $sale_ids = array_column($sales, 'id');
    $in       = implode(',', array_map('intval', $sale_ids));

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

    $sold_res = $conn->query("
        SELECT si.sale_id,
               SUM(si.quantity_in_base) AS qty_base_total,
               SUM(si.quantity_sold)    AS qty_pu_total,
               GROUP_CONCAT(DISTINCT si.unit_sold ORDER BY si.unit_sold SEPARATOR ', ') AS units_sold,
               GROUP_CONCAT(DISTINCT p.base_unit  ORDER BY p.base_unit  SEPARATOR ', ') AS base_units
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
$total_cogs   = round($total_cogs, 2);
$total_profit = round($total_profit, 2);
$total_margin = $total_revenue > 0 ? round(($total_profit / $total_revenue) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>My Sales Today | CAD Crest</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-green: #004d40;
            --primary-light: #e8f3f0;
            --accent:        #b8860b;
            --bg-soft:       #f0f4f8;
            --danger:        #dc2626;
            --radius:        .75rem;
        }
        body { background: var(--bg-soft); margin: 0; font-family: 'Segoe UI', sans-serif; }

        /* FIX 2: removed .page-wrap — use .main-content injected by sidebar.php so collapse works */
        .page-header     { margin-bottom: 1.5rem; }
        .page-header h1  { font-size: 1.5rem; color: var(--primary-green); margin: 0; display: flex; align-items: center; gap: .6rem; }
        .page-header p   { color: #6b7280; margin: .25rem 0 0; font-size: .9rem; }

        .summary-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .summary-card { background: #fff; border-radius: var(--radius); padding: 1.25rem; box-shadow: 0 2px 8px rgba(0,0,0,.06); text-align: center; border-top: 3px solid var(--primary-green); }
        .summary-card .val { font-size: 1.6rem; font-weight: 800; color: var(--primary-green); }
        .summary-card .lbl { font-size: .78rem; color: #6b7280; margin-top: .25rem; }

        .reset-notice { background: #fef3c7; border: 1px solid #fcd34d; border-radius: var(--radius); padding: .75rem 1rem; font-size: .83rem; color: #92400e; margin-bottom: 1.25rem; display: flex; align-items: center; gap: .5rem; }

        .table-wrap { background: #fff; border-radius: var(--radius); overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
        table { width: 100%; border-collapse: collapse; }
        thead th { background: var(--primary-green); color: #fff; padding: .75rem 1rem; text-align: left; font-size: .82rem; white-space: nowrap; }
        tbody tr:nth-child(even) { background: #f9fafb; }
        tbody tr:hover { background: #e8f3f0; cursor: pointer; }
        tbody td { padding: .75rem 1rem; font-size: .84rem; border-bottom: 1px solid #f1f5f9; }
        tfoot td { padding: .75rem 1rem; font-weight: 700; background: #f3f4f6; border-top: 2px solid #d1d5db; font-size: .84rem; }
        .tx-link { color: var(--primary-green); font-weight: 700; text-decoration: underline dotted; }
        .empty-cell { text-align: center !important; padding: 3rem !important; color: #94a3b8; }

        /* Modal */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(2px); }
        .modal-overlay.open { display: flex; }
        .modal-box { background: #fff; border-radius: 1.25rem; width: 90%; max-width: 640px; max-height: 88vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(0,0,0,.25); animation: popIn .2s ease; }
        @keyframes popIn { from { opacity:0; transform:scale(.95) translateY(-8px); } to { opacity:1; transform:scale(1) translateY(0); } }
        .modal-header { display: flex; justify-content: space-between; align-items: flex-start; padding: 1.25rem 1.5rem; background: linear-gradient(135deg, var(--primary-green), #00695c); border-radius: 1.25rem 1.25rem 0 0; }
        .modal-header-text h3 { margin: 0; color: #fff; font-size: 1.05rem; font-weight: 700; }
        .modal-header-text p  { margin: .15rem 0 0; color: rgba(255,255,255,.7); font-size: .8rem; }
        .close-btn { background: rgba(255,255,255,.15); border: none; border-radius: .5rem; color: #fff; width: 2rem; height: 2rem; cursor: pointer; font-size: 1rem; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .close-btn:hover { background: rgba(255,255,255,.3); }
        .modal-body { padding: 1.5rem; }
        .modal-summary { display: grid; grid-template-columns: repeat(3, 1fr); gap: .75rem; margin-bottom: 1.25rem; }
        .ms-card { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: .6rem; padding: .85rem; }
        .ms-lbl { font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; margin-bottom: .3rem; display: flex; align-items: center; gap: .3rem; }
        .ms-val { font-weight: 700; color: #111827; font-size: .95rem; }
        .items-wrap { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: .75rem; overflow: hidden; }
        .items-wrap-hdr { padding: .7rem 1rem; background: #f3f4f6; border-bottom: 1px solid #e5e7eb; font-size: .88rem; font-weight: 700; color: #374151; }
        .items-table { width: 100%; border-collapse: collapse; }
        .items-table th { padding: .55rem .9rem; background: #e5e7eb; font-size: .76rem; color: #6b7280; font-weight: 600; text-transform: uppercase; text-align: left; }
        .items-table td { padding: .7rem .9rem; border-bottom: 1px solid #f3f4f6; font-size: .84rem; }
        .qty-badge { background: #e8f3f0; color: var(--primary-green); padding: .2rem .55rem; border-radius: 999px; font-weight: 700; }
        .total-row td { background: #e8f3f0 !important; color: var(--primary-green) !important; font-weight: 800 !important; }
        .modal-loading, .modal-error { text-align: center; padding: 2.5rem; color: #9ca3af; }
        .modal-error { color: var(--danger); }

        @media (max-width: 600px) {
            .modal-summary { grid-template-columns: 1fr 1fr; }
            .summary-grid  { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/sidebar.php'; ?>

<div class="main-content">

    <!-- Page Header -->
    <div class="page-header">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
            <div>
                <h1><i class="fa-solid fa-chart-bar"></i> My Sales – Today</h1>
                <p>
                    <?php echo date('l, F d, Y'); ?>
                    &nbsp;|&nbsp; Logged in as <strong><?php echo htmlspecialchars($user_name); ?></strong>
                    &nbsp;·&nbsp;
                    <span style="background:#e8f3f0;color:var(--primary-green);padding:.15rem .55rem;border-radius:999px;font-size:.78rem;font-weight:700;text-transform:uppercase;">
                        <?php echo ucfirst(htmlspecialchars($user_role)); ?>
                    </span>
                </p>
            </div>
            <?php renderNotificationBell($conn); /* FIX 3 */ ?>
        </div>
    </div>

    <!-- Reset notice -->
    <div class="reset-notice">
        <i class="fa-solid fa-clock"></i>
        This report shows <strong>today's transactions only</strong>. It automatically resets at midnight each day.
    </div>

    <!-- Summary cards -->
    <div class="summary-grid">
        <div class="summary-card">
            <div class="val"><?php echo $tx_count; ?></div>
            <div class="lbl"><i class="fa-solid fa-receipt"></i> Transactions Today</div>
        </div>
        <div class="summary-card">
            <div class="val">₱<?php echo number_format($total_revenue, 2); ?></div>
            <div class="lbl"><i class="fa-solid fa-peso-sign"></i> Total Revenue</div>
        </div>
        <div class="summary-card">
            <div class="val">₱<?php echo number_format($avg_sale, 2); ?></div>
            <div class="lbl"><i class="fa-solid fa-calculator"></i> Avg. Sale</div>
        </div>
    </div>

    <!-- Transactions Table -->
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>ID</th>
                    <th>Staff</th>
                    <th>Subtotal</th>
                    <th>Discount</th>
                    <th>Tax</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($sales)): ?>
                <tr><td colspan="8" class="empty-cell">
                    <i class="fa-regular fa-calendar-xmark" style="font-size:2rem;display:block;margin-bottom:.5rem;"></i>
                    No transactions recorded today.
                </td></tr>
            <?php else: ?>
                <?php foreach ($sales as $s): ?>
                <tr onclick="openTxModal(<?php echo (int)$s['id']; ?>)" title="Click to view details">
                    <td><?php echo date('M d, Y', strtotime($s['sale_date'])); ?></td>
                    <td><?php echo date('h:i A',  strtotime($s['sale_date'])); ?></td>
                    <td><span class="tx-link">#<?php echo str_pad($s['id'], 6, '0', STR_PAD_LEFT); ?></span></td>
                    <td><?php echo htmlspecialchars($s['full_name']); ?></td>
                    <td>₱<?php echo number_format($s['total_amount'], 2); ?></td>
                    <td>-₱<?php echo number_format($s['discount'],    2); ?></td>
                    <td>₱<?php echo number_format($s['tax'],          2); ?></td>
                    <td><strong>₱<?php echo number_format($s['grand_total'], 2); ?></strong></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
            <?php if (!empty($sales)): ?>
            <tfoot>
                <tr>
                    <td colspan="4">Totals</td>
                    <td>₱<?php echo number_format(array_sum(array_column($sales, 'total_amount')), 2); ?></td>
                    <td>-₱<?php echo number_format(array_sum(array_column($sales, 'discount')),    2); ?></td>
                    <td>₱<?php echo number_format(array_sum(array_column($sales, 'tax')),          2); ?></td>
                    <td>₱<?php echo number_format($total_revenue, 2); ?></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>

</div><!-- /.main-content -->

<!-- ── Transaction Detail Modal ─────────────────────────────────────────── -->
<div class="modal-overlay" id="txModalOverlay">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-header-text">
                <h3 id="txModalTitle">Transaction Details</h3>
                <p  id="txModalSubtitle"></p>
            </div>
            <button class="close-btn" onclick="closeTxModal()">✕</button>
        </div>
        <div class="modal-body" id="txModalBody">
            <div class="modal-loading">
                <i class="fa-solid fa-spinner fa-spin" style="font-size:2rem;color:#004d40;display:block;margin-bottom:.75rem;"></i>
                Loading…
            </div>
        </div>
    </div>
</div>

<?php renderNotificationPanel($conn); /* FIX 3 */ ?>

<script>
function esc(t) {
    if (!t) return '';
    return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function peso(v) {
    return '₱' + parseFloat(v || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 });
}

function openTxModal(saleId) {
    const overlay = document.getElementById('txModalOverlay');
    const body    = document.getElementById('txModalBody');

    document.getElementById('txModalTitle').textContent    = 'Transaction #' + String(saleId).padStart(6, '0');
    document.getElementById('txModalSubtitle').textContent = '';
    body.innerHTML = `<div class="modal-loading">
        <i class="fa-solid fa-spinner fa-spin" style="font-size:2rem;color:#004d40;display:block;margin-bottom:.75rem;"></i>
        Loading…</div>`;
    overlay.classList.add('open');

    fetch('../ajax/get_transaction.php?sale_id=' + saleId)
        .then(r => r.json())
        .then(data => {
            if (!data || !data.sale) {
                body.innerHTML = `<div class="modal-error">
                    <i class="fa-solid fa-circle-exclamation" style="font-size:2rem;display:block;margin-bottom:.5rem;"></i>
                    Transaction not found or access denied.</div>`;
                return;
            }
            renderModal(data.sale, data.items || []);
        })
        .catch(() => {
            body.innerHTML = `<div class="modal-error">
                <i class="fa-solid fa-circle-exclamation" style="font-size:2rem;display:block;margin-bottom:.5rem;"></i>
                Network error. Please try again.</div>`;
        });
}

function renderModal(s, items) {
    document.getElementById('txModalSubtitle').textContent =
        (s.date || '') + ' · ' + (s.time || '') + ' · by ' + (s.staff || s.full_name || '');

    const summaryCards = [
        { label: 'Date & Time', value: esc(s.date) + '<br><small style="color:#6b7280">' + esc(s.time) + '</small>', icon: 'fa-calendar' },
        { label: 'Served By',   value: esc(s.staff || s.full_name), icon: 'fa-user' },
        { label: 'Grand Total', value: '<span style="color:#1a5f4b;font-size:1.2rem;font-weight:800;">' + peso(s.grand_total) + '</span>', icon: 'fa-peso-sign' },
    ];

    const summaryHtml = summaryCards.map(c =>
        `<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:0.6rem;padding:0.9rem;">
            <div style="display:flex;align-items:center;gap:0.4rem;color:#6b7280;font-size:0.75rem;margin-bottom:0.4rem;font-weight:600;text-transform:uppercase;">
                <i class="fa-solid ${c.icon}" style="color:#1a5f4b;"></i> ${c.label}
            </div>
            <div style="font-weight:600;color:#111827;">${c.value}</div>
        </div>`
    ).join('');

    const itemRows = items.map(i =>
        `<tr style="border-bottom:1px solid #f3f4f6;">
            <td style="padding:0.75rem 1rem;">
                <div style="font-weight:600;color:#111827;">${esc(i.brand_name)}</div>
                <div style="font-size:0.78rem;color:#6b7280;">${esc(i.generic_name)}${i.unit_sold ? ' · ' + esc(i.unit_sold) : ''}</div>
                ${i.batch_number ? `<div style="font-size:0.72rem;background:#e0f2f1;color:#00695c;display:inline-block;padding:1px 7px;border-radius:4px;margin-top:2px;">Batch: ${esc(i.batch_number)}</div>` : ''}
                ${i.batch_expiry_date ? `<div style="font-size:0.72rem;color:#9ca3af;">Exp: ${esc(i.batch_expiry_date)}</div>` : ''}
            </td>
            <td style="padding:0.75rem 1rem;text-align:center;">
                <span style="background:#e8f3f0;color:#1a5f4b;padding:0.2rem 0.6rem;border-radius:999px;font-weight:700;font-size:0.9rem;">${i.quantity_sold || i.quantity_in_base || 0}</span>
            </td>
            <td style="padding:0.75rem 1rem;text-align:right;color:#374151;">${peso(i.unit_price)}</td>
            <td style="padding:0.75rem 1rem;text-align:right;font-weight:700;color:#111827;">${peso(i.subtotal)}</td>
        </tr>`
    ).join('');

    const totalsHtml = `
        <tr style="background:#f9fafb;border-top:2px solid #e5e7eb;">
            <td colspan="3" style="padding:0.6rem 1rem;text-align:right;color:#6b7280;font-size:0.85rem;">Subtotal</td>
            <td style="padding:0.6rem 1rem;text-align:right;">${peso(s.subtotal || s.total_amount)}</td>
        </tr>
        ${parseFloat(s.discount) > 0 ? `<tr style="background:#f9fafb;">
            <td colspan="3" style="padding:0.4rem 1rem;text-align:right;color:#ef4444;font-size:0.85rem;">Discount</td>
            <td style="padding:0.4rem 1rem;text-align:right;color:#ef4444;">-${peso(s.discount)}</td>
        </tr>` : ''}
        ${parseFloat(s.tax) > 0 ? `<tr style="background:#f9fafb;">
            <td colspan="3" style="padding:0.4rem 1rem;text-align:right;color:#6b7280;font-size:0.85rem;">Tax (VAT)</td>
            <td style="padding:0.4rem 1rem;text-align:right;">${peso(s.tax)}</td>
        </tr>` : ''}
        <tr style="background:#e8f3f0;">
            <td colspan="3" style="padding:0.75rem 1rem;text-align:right;font-weight:800;color:#1a5f4b;">Grand Total</td>
            <td style="padding:0.75rem 1rem;text-align:right;font-weight:800;color:#1a5f4b;font-size:1.05rem;">${peso(s.grand_total)}</td>
        </tr>
        <tr style="background:#f9fafb;">
            <td colspan="3" style="padding:0.5rem 1rem;text-align:right;color:#6b7280;font-size:0.83rem;">Cash Received</td>
            <td style="padding:0.5rem 1rem;text-align:right;color:#6b7280;">${peso(s.cash_received)}</td>
        </tr>
        <tr style="background:#f9fafb;">
            <td colspan="3" style="padding:0.5rem 1rem;text-align:right;color:#6b7280;font-size:0.83rem;">Change</td>
            <td style="padding:0.5rem 1rem;text-align:right;color:#6b7280;">${peso(s.change_amount)}</td>
        </tr>`;

    document.getElementById('txModalBody').innerHTML = `
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:1.25rem;">
            ${summaryHtml}
        </div>
        <div class="items-wrap">
            <div class="items-wrap-hdr">
                <i class="fa-solid fa-box" style="color:#004d40;margin-right:.4rem;"></i>Items Sold
            </div>
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th style="text-align:center;">Qty</th>
                        <th style="text-align:right;">Unit Price</th>
                        <th style="text-align:right;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>${itemRows}</tbody>
                <tfoot>${totalsHtml}</tfoot>
            </table>
        </div>`;
}

function closeTxModal() {
    document.getElementById('txModalOverlay').classList.remove('open');
}
document.getElementById('txModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeTxModal();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeTxModal(); });
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