<?php
/**
 * ajax/get_product_units.php
 * Returns units for a product as JSON or HTML (for the manage-units panel).
 */
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/inventory_helper.php';

$product_id = intval($_GET['product_id'] ?? 0);
$editable   = !empty($_GET['editable']);

if ($product_id <= 0) {
    if ($editable) { echo '<p style="color:red">Invalid product.</p>'; exit; }
    echo json_encode(['units' => []]);
    exit;
}

// ── POST: save units ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $user_id = (int)$_SESSION['user_id'];

    $unit_names   = $_POST['unit_name']   ?? [];
    $unit_convs   = $_POST['unit_conv']   ?? [];
    $unit_prices  = $_POST['unit_price']  ?? [];
    $unit_orders  = $_POST['unit_order']  ?? [];
    $unit_ids     = $_POST['unit_id']     ?? [];

    // Delete removed units
    $existing_ids = [];
    $ex = $conn->query("SELECT id FROM product_units WHERE product_id=$product_id");
    while($r=$ex->fetch_assoc()) $existing_ids[] = $r['id'];

    $submitted_ids = array_filter(array_map('intval', $unit_ids));
    $to_delete = array_diff($existing_ids, $submitted_ids);
    foreach ($to_delete as $did) {
        $conn->query("DELETE FROM product_units WHERE id=$did AND product_id=$product_id");
    }

    // Upsert units
    foreach ($unit_names as $i => $un) {
        $un    = trim($un);
        if (empty($un)) continue;
        $conv  = max(1, intval($unit_convs[$i] ?? 1));
        $pov   = isset($unit_prices[$i]) && $unit_prices[$i] !== '' ? floatval($unit_prices[$i]) : null;
        $order = intval($unit_orders[$i] ?? 0);
        $uid   = intval($unit_ids[$i] ?? 0);

        if ($uid > 0) {
            $st = $conn->prepare("UPDATE product_units SET unit_name=?,conversion_to_base=?,price_override=?,sort_order=? WHERE id=? AND product_id=?");
            $st->bind_param("sidiii", $un, $conv, $pov, $order, $uid, $product_id);
        } else {
            $st = $conn->prepare("INSERT IGNORE INTO product_units (product_id,unit_name,conversion_to_base,price_override,sort_order) VALUES (?,?,?,?,?)");
            $st->bind_param("isidi", $product_id, $un, $conv, $pov, $order);
        }
        $st->execute(); $st->close();
    }

    // Log
    $log = $conn->prepare("INSERT INTO activity_logs (user_id,action,description,product_id) VALUES (?,?,?,?)");
    $desc = "Updated units for product ID $product_id";
    $action = 'Edit Units';
    $log->bind_param("issi", $user_id, $action, $desc, $product_id);
    $log->execute(); $log->close();

    echo json_encode(['success' => true]);
    exit;
}

// ── GET: return units ─────────────────────────────────────
$units = getProductUnits($conn, $product_id);
$product = $conn->query("SELECT brand_name, base_unit, price FROM products WHERE id=$product_id")->fetch_assoc();

if (!$editable) {
    // Return JSON for dropdown use
    echo json_encode(['units' => $units, 'product' => $product]);
    exit;
}

// ── Render editable HTML ──────────────────────────────────
$base_price = $product ? (float)$product['price'] : 0;
?>
<style>
.mu-table{width:100%;border-collapse:collapse;font-size:.88rem;}
.mu-table th{background:#f8fafc;padding:.6rem .8rem;text-align:left;font-weight:700;font-size:.75rem;text-transform:uppercase;color:#718096;border-bottom:2px solid #edf2f7;}
.mu-table td{padding:.65rem .8rem;border-bottom:1px solid #edf2f7;}
.mu-table input{padding:.4rem .6rem;border:1px solid #ddd;border-radius:6px;font-size:.85rem;width:90%;}
.mu-table .base-row{background:#f0fdf4;}
.btn-sv{background:#004d40;color:#fff;border:none;padding:.6rem 1.4rem;border-radius:8px;font-weight:700;cursor:pointer;margin-top:.75rem;}
.btn-addrow{background:none;border:1px dashed #004d40;color:#004d40;padding:.4rem .9rem;border-radius:6px;cursor:pointer;font-size:.82rem;font-weight:600;margin-top:.5rem;}
.del-unit-btn{background:#fff5f5;border:none;color:#c53030;width:26px;height:26px;border-radius:5px;cursor:pointer;font-size:.85rem;}
.eff-price{color:#718096;font-size:.75rem;margin-top:2px;}
</style>

<form id="unitsForm">
<input type="hidden" name="product_id" value="<?= $product_id ?>">
<table class="mu-table" id="unitsTable">
<thead>
<tr>
    <th>Unit Name</th>
    <th>= X base units</th>
    <th>Price Override (optional)</th>
    <th>Sort Order</th>
    <th>Eff. Price</th>
    <th></th>
</tr>
</thead>
<tbody id="unitsTbody">
<?php foreach($units as $u):
    $eff = $u['price_override'] !== null ? (float)$u['price_override'] : $base_price * (int)$u['conversion_to_base'];
    $is_base = ((int)$u['conversion_to_base'] === 1 && $u['unit_name'] === ($product['base_unit']??'piece'));
?>
<tr class="<?= $is_base?'base-row':'' ?>">
    <td>
        <input type="hidden" name="unit_id[]" value="<?= $u['id'] ?>">
        <input type="text" name="unit_name[]" value="<?= htmlspecialchars($u['unit_name']) ?>" <?= $is_base?'readonly style="background:#f0fdf4;font-weight:600"':'' ?>>
    </td>
    <td><input type="number" name="unit_conv[]" value="<?= $u['conversion_to_base'] ?>" min="1" <?= $is_base?'readonly style="background:#f0fdf4"':'' ?>></td>
    <td><input type="number" name="unit_price[]" step="0.01" placeholder="auto (₱<?= number_format($eff,2) ?>)" value="<?= $u['price_override']!==null?$u['price_override']:'' ?>"></td>
    <td><input type="number" name="unit_order[]" value="<?= $u['sort_order'] ?>" min="0"></td>
    <td><div class="eff-price">₱<?= number_format($eff,2) ?></div></td>
    <td><?php if(!$is_base): ?><button type="button" class="del-unit-btn" onclick="this.closest('tr').remove()">✕</button><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<button type="button" class="btn-addrow" onclick="addMURow()">+ Add Unit</button><br>
<button type="button" class="btn-sv" onclick="saveUnits()">💾 Save Units</button>
<span id="mu_msg" style="margin-left:10px;font-size:.85rem"></span>
</form>

<script>
function addMURow(){
    const tbody = document.getElementById('unitsTbody');
    const tr = document.createElement('tr');
    tr.innerHTML=`
      <td><input type="hidden" name="unit_id[]" value="0"><input type="text" name="unit_name[]" placeholder="e.g. box"></td>
      <td><input type="number" name="unit_conv[]" min="1" value="1" placeholder=""></td>
      <td><input type="number" name="unit_price[]" step="0.01" placeholder="auto"></td>
      <td><input type="number" name="unit_order[]" value="0" min="0"></td>
      <td></td>
      <td><button type="button" class="del-unit-btn" onclick="this.closest('tr').remove()">✕</button></td>
    `;
    tbody.appendChild(tr);
}

function saveUnits(){
    const form = document.getElementById('unitsForm');
    const data = new FormData(form);
    fetch('<?= basename(__FILE__) ?>', { method:'POST', body:new URLSearchParams(data) })
    .then(r=>r.json())
    .then(res=>{
        const msg = document.getElementById('mu_msg');
        if(res.success){ msg.style.color='#059669'; msg.textContent='✅ Units saved!'; }
        else { msg.style.color='#dc2626'; msg.textContent='❌ Save failed'; }
        setTimeout(()=>msg.textContent='', 3000);
    });
}
</script>
