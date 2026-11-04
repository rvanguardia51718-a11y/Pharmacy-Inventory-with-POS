<?php
/**
 * ajax/get_product_details.php
 * Returns full product details HTML for the details modal
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/inventory_helper.php';

$product_id = intval($_GET['product_id'] ?? 0);
if ($product_id <= 0) { echo '<p style="color:#dc2626">Invalid product ID.</p>'; exit; }

$product = $conn->query("
    SELECT p.*, c.name AS category_name
    FROM products p LEFT JOIN categories c ON c.id=p.category_id
    WHERE p.id=$product_id
")->fetch_assoc();

if (!$product) { echo '<p style="color:#dc2626">Product not found.</p>'; exit; }

$units = $conn->query("SELECT * FROM product_units WHERE product_id=$product_id ORDER BY conversion_to_base DESC, sort_order ASC")->fetch_all(MYSQLI_ASSOC);

$cutoff3m = date('Y-m-d', strtotime('+3 months'));
$batches = $conn->query("
    SELECT *, DATEDIFF(expiration_date, CURDATE()) AS days_left
    FROM stock_batches WHERE product_id=$product_id AND quantity_base > 0
    ORDER BY expiration_date ASC
")->fetch_all(MYSQLI_ASSOC);

$sellable = getSellableStock($conn, $product_id);
$total    = getTotalStock($conn, $product_id);
$vat_label = ['vatable'=>'VATable (12%)','exempt'=>'VAT Exempt','zero_rated'=>'Zero Rated'][$product['vat_type']??'vatable'] ?? 'VATable (12%)';
$vat_cls   = ['vatable'=>'badge-vatable','exempt'=>'badge-exempt','zero_rated'=>'badge-zero'][$product['vat_type']??'vatable'] ?? 'badge-vatable';

// VAT calculation display
$price = floatval($product['price']);
if (($product['vat_type']??'vatable') === 'vatable') {
    $vat_base = $price / 1.12;
    $vat_amt  = $price - $vat_base;
    $vat_info = "₱" . number_format($vat_base, 2) . " + ₱" . number_format($vat_amt, 2) . " VAT";
} else {
    $vat_info = "No VAT applied";
}
?>
<style>
.pd-grid{display:grid;grid-template-columns:1fr 1fr;gap:.9rem;margin-bottom:1.2rem;}
.pd-card{background:#f8fafc;padding:.9rem;border-radius:9px;}
.pd-card h4{margin:0 0 .25rem;font-size:.72rem;text-transform:uppercase;color:#718096;}
.pd-card p{margin:0;font-weight:700;font-size:.95rem;color:#2d3748;}
.pd-card small{color:#718096;font-size:.76rem;}
.unit-tag{background:#e1f5fe;color:#0288d1;padding:.25rem .7rem;border-radius:20px;font-size:.78rem;font-weight:600;display:inline-block;margin:.12rem;}
.edit-pencil-btn{background:none;border:1px solid #004d40;color:#004d40;padding:.38rem .9rem;border-radius:7px;cursor:pointer;font-size:.82rem;font-weight:600;display:inline-flex;align-items:center;gap:5px;}
.edit-pencil-btn:hover{background:#004d40;color:#fff;}
.btable{width:100%;border-collapse:collapse;font-size:.82rem;margin-top:.5rem;}
.btable th{padding:.42rem .65rem;background:#f8fafc;font-weight:700;font-size:.7rem;text-transform:uppercase;color:#718096;}
.btable td{padding:.48rem .65rem;border-bottom:1px solid #edf2f7;}
.badge{padding:3px 9px;border-radius:20px;font-size:.68rem;font-weight:700;text-transform:uppercase;display:inline-block;}
.badge-success{background:#c6f6d5;color:#22543d;}
.badge-warning{background:#feebc8;color:#744210;}
.badge-danger{background:#fed7d7;color:#822727;}
.badge-blocked{background:#e9d8fd;color:#553c9a;}
.badge-vatable{background:#d1fae5;color:#065f46;}
.badge-exempt{background:#fef3c7;color:#92400e;}
.badge-zero{background:#dbeafe;color:#1e40af;}
</style>

<div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1.4rem;flex-wrap:wrap;gap:.5rem">
    <div>
        <h2 style="margin:0;color:#004d40;font-size:1.4rem"><?= htmlspecialchars($product['brand_name']) ?></h2>
        <p style="margin:.2rem 0 0;color:#718096;font-style:italic"><?= htmlspecialchars($product['generic_name']) ?></p>
        <?php if($product['barcode']): ?><code style="font-size:.78rem"><?= htmlspecialchars($product['barcode']) ?></code><?php endif; ?>
    </div>
   
</div>

<div class="pd-grid">
    <div class="pd-card"><h4>Category</h4><p><?= htmlspecialchars($product['category_name']??'—') ?></p></div>
    <div class="pd-card"><h4>Dosage Form</h4><p><?= $product['dosage_form'] ? htmlspecialchars($product['dosage_form']) : '<span style="color:#a0aec0">Not specified</span>' ?></p></div>
    <div class="pd-card"><h4>Strength</h4><p><?= $product['strength'] ? htmlspecialchars($product['strength']) : '<span style="color:#a0aec0">Not specified</span>' ?></p></div>
    <div class="pd-card"><h4>Base Unit</h4><p><code><?= htmlspecialchars($product['base_unit']) ?></code></p></div>
    <div class="pd-card">
        <h4>Selling Price</h4>
        <p>₱<?= number_format($price, 2) ?> / <?= htmlspecialchars($product['base_unit']) ?></p>
        <small><?= $vat_info ?></small>
    </div>
    <div class="pd-card">
        <h4>VAT Type</h4>
        <p><span class="badge <?= $vat_cls ?>"><?= $vat_label ?></span></p>
    </div>
    <div class="pd-card">
        <h4>Sellable Stock</h4>
        <p style="color:<?= $sellable==0?'#e53e3e':($sellable<=$product['low_stock_threshold']?'#dd6b20':'#38a169') ?>"><?= $sellable ?> <?= htmlspecialchars($product['base_unit']) ?></p>
        <small>Total incl. near-expiry: <?= $total ?></small>
    </div>
    <div class="pd-card">
        <h4>Cost / Low Stock Threshold</h4>
        <p>₱<?= number_format($product['cost'],2) ?> &nbsp;|&nbsp; <?= $product['low_stock_threshold'] ?> base units</p>
    </div>
</div>

<!-- Sellable Units -->
<div style="margin-bottom:1.2rem">
    <h4 style="margin:0 0 .5rem;font-size:.8rem;text-transform:uppercase;color:#718096">Sellable Units</h4>
    <?php if(empty($units)): ?>
        <p style="color:#a0aec0;font-size:.85rem">No units configured.</p>
    <?php else: foreach($units as $u):
        $unit_price = is_null($u['price_override']) ? ($price * $u['conversion_to_base']) : floatval($u['price_override']);
    ?>
        <span class="unit-tag"><?= htmlspecialchars($u['unit_name']) ?> (×<?= $u['conversion_to_base'] ?> = ₱<?= number_format($unit_price,2) ?>)</span>
    <?php endforeach; endif; ?>
</div>

<!-- Stock Batches -->
<div>
    <h4 style="margin:0 0 .5rem;font-size:.8rem;text-transform:uppercase;color:#718096">Stock Batches (FIFO by Expiry)</h4>
    <?php if(empty($batches)): ?>
        <p style="color:#a0aec0;font-size:.85rem">No stock batches.</p>
    <?php else: ?>
    <table class="btable">
        <thead><tr><th>Batch #</th><th>Avail.</th><th>Damaged</th><th>Purchase Cost</th><th>Sell Price</th><th>Expiry</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach($batches as $b):
            $d=$b['days_left'];
            $sc='badge-success'; $sl='Good';
            if($d<=0){$sc='badge-danger';$sl='EXPIRED';}
            elseif($d<=90){$sc='badge-blocked';$sl='Blocked';}
            elseif($d<=180){$sc='badge-warning';$sl='Near Expiry';}
            $avail_qty = isset($b['available_quantity']) ? (int)$b['available_quantity'] : (int)$b['quantity_base'];
            $dmg_qty   = isset($b['damage_quantity'])    ? (int)$b['damage_quantity']    : 0;
            $batch_sell = isset($b['selling_price']) && $b['selling_price'] !== null ? '₱'.number_format((float)$b['selling_price'],2) : '<span style="color:#a0aec0">Same as product</span>';
            $batch_cost = isset($b['purchase_cost']) && $b['purchase_cost'] !== null ? '₱'.number_format((float)$b['purchase_cost'],2) : '<span style="color:#a0aec0">—</span>';
        ?>
        <tr>
            <td><code><?= htmlspecialchars($b['batch_number']) ?></code></td>
            <td><strong style="color:<?= $avail_qty>0?'#2d3748':'#e53e3e' ?>"><?= $avail_qty ?></strong></td>
            <td><?= $dmg_qty > 0 ? '<span style="color:#dd6b20;font-weight:600">'.$dmg_qty.'</span>' : '<span style="color:#a0aec0">0</span>' ?></td>
            <td><?= $batch_cost ?></td>
            <td><?= $batch_sell ?></td>
            <td><?= date('M d, Y', strtotime($b['expiration_date'])) ?></td>
            <td><span class="badge <?= $sc ?>"><?= $sl ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
