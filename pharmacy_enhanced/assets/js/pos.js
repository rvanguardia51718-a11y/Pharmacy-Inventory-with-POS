/**
 * assets/js/pos.js
 * Fixed POS JavaScript:
 *  - Multi-unit support (tablet, blister, box)
 *  - Stock check uses total base units across all batches
 *  - quantity_in_base sent to backend for FIFO deduction
 *  - 58mm thermal receipt printing with proper layout
 *  - Currency symbol ₱ (PHP)
 *  - No hardcoded localhost references
 */

let cart = [];
let productsList = [];

const barcodeInput = document.getElementById('barcode-input');
const searchInput  = document.getElementById('search-input');
const cashInput    = document.getElementById('cash-input');

document.addEventListener('DOMContentLoaded', function () {
    if (typeof products !== 'undefined') productsList = products;
    if (barcodeInput) barcodeInput.focus();
    setupEventListeners();
});

function setupEventListeners() {
    if (barcodeInput) {
        barcodeInput.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); searchByBarcode(this.value); }
        });
    }
    if (searchInput) {
        searchInput.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); searchProducts(this.value); }
        });
        searchInput.addEventListener('input', function () {
            if (this.value.length >= 2) liveSearchProducts(this.value);
        });
    }
    if (cashInput) cashInput.addEventListener('input', calculateChange);

    const discountInput = document.getElementById('discount-input');
    if (discountInput) discountInput.addEventListener('input', updateGrandTotal);
}

document.addEventListener('keydown', function (e) {
    if (e.key === 'F2') { e.preventDefault(); if (barcodeInput) barcodeInput.focus(); }
    else if (e.key === 'F3') { e.preventDefault(); if (searchInput) searchInput.focus(); }
    else if (e.key === 'F4') { e.preventDefault(); if (cashInput) cashInput.focus(); }
    else if (e.key === 'F8') { e.preventDefault(); completeTransaction(); }
    else if (e.key === 'Escape') { e.preventDefault(); clearCart(); }
});

// ── Search ────────────────────────────────────────────────────

function searchByBarcode(barcode) {
    if (!barcode) return;
    const product = productsList.find(p => p.barcode && p.barcode == barcode);
    if (product) {
        addToCartWithUnit(product);
        barcodeInput.value = '';
    } else {
        showPosAlert('Product not found for barcode: ' + barcode, 'warning');
    }
}

function searchProducts(keyword) {
    if (!keyword) return;
    const results = productsList.filter(p =>
        p.brand_name.toLowerCase().includes(keyword.toLowerCase()) ||
        (p.generic_name && p.generic_name.toLowerCase().includes(keyword.toLowerCase()))
    );
    if (results.length === 0) {
        showPosAlert('No products found matching "' + keyword + '"', 'warning');
    } else if (results.length === 1) {
        addToCartWithUnit(results[0]);
        if (searchInput) searchInput.value = '';
    } else {
        showProductSelection(results);
    }
}

function liveSearchProducts(keyword) {
    const results = productsList.filter(p =>
        p.brand_name.toLowerCase().includes(keyword.toLowerCase()) ||
        (p.generic_name && p.generic_name.toLowerCase().includes(keyword.toLowerCase()))
    );
    showSearchSuggestions(results.slice(0, 8));
}

function showSearchSuggestions(results) {
    let dd = document.getElementById('search-suggestions');
    if (!dd) {
        dd = document.createElement('div');
        dd.id = 'search-suggestions';
        dd.style.cssText = 'position:absolute;background:#fff;border:1px solid #d1d5db;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);z-index:999;max-height:280px;overflow-y:auto;width:100%;';
        if (searchInput && searchInput.parentElement) {
            searchInput.parentElement.style.position = 'relative';
            searchInput.parentElement.appendChild(dd);
        }
    }
    if (results.length === 0) { dd.style.display = 'none'; return; }
    dd.style.display = 'block';
    dd.innerHTML = results.map(p => {
        const displayPrice = parseFloat(p.fifo_price ?? p.price);
        return `
        <div onclick="addToCartWithUnit(${JSON.stringify(p).replace(/"/g,'&quot;')}); document.getElementById('search-suggestions').style.display='none'; searchInput.value='';"
             style="padding:10px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:.87rem;"
             onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background='#fff'">
            <strong>${escapeHtml(p.brand_name)}</strong>
            <small style="color:#6b7280;margin-left:6px;">${escapeHtml(p.generic_name || '')}</small>
            <span style="float:right;color:#004d40;font-weight:600;">₱${displayPrice.toFixed(2)}</span>
            <br><small style="color:${p.total_stock > 0 ? '#16a34a' : '#dc2626'}">Stock: ${p.total_stock || 0} ${p.base_unit || ''}</small>
        </div>
        `;
    }).join('');
}

document.addEventListener('click', function (e) {
    const dd = document.getElementById('search-suggestions');
    if (dd && !dd.contains(e.target) && e.target !== searchInput) {
        dd.style.display = 'none';
    }
});

// ── Multi-unit cart addition ──────────────────────────────────

function addToCartWithUnit(product) {
    // Use FIFO batch price (oldest non-expired batch with stock) as the display price
    // The backend will recalculate exact per-batch pricing on checkout
    const displayPrice = parseFloat(product.fifo_price ?? product.price);
    // If product has multiple units, show unit selector
    if (product.units && product.units.length > 1) {
        showUnitSelector(product);
    } else {
        addToCart(product, product.base_unit || 'piece', 1, displayPrice);
    }
}

function showUnitSelector(product) {
    const existing = document.getElementById('unitSelectorModal');
    if (existing) existing.remove();

    const modal = document.createElement('div');
    modal.id = 'unitSelectorModal';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px);';
    modal.innerHTML = `
        <div style="background:#fff;border-radius:1.25rem;padding:2rem;width:90%;max-width:440px;box-shadow:0 20px 50px rgba(0,0,0,.25);">
            <h3 style="margin:0 0 1rem;color:#004d40;font-size:1.1rem;">
                <i class="fa-solid fa-box-open"></i> Select Unit — ${escapeHtml(product.brand_name)}
            </h3>
            <p style="color:#6b7280;font-size:.85rem;margin-bottom:1.25rem;">Total stock: <strong>${product.total_stock || 0} ${product.base_unit}</strong></p>
            <div style="display:grid;gap:.75rem;">
                ${product.units.map(u => {
                    const unitPrice = u.price_override != null ? parseFloat(u.price_override) : parseFloat(product.price) * parseInt(u.conversion_to_base);
                    const stockInUnit = Math.floor((product.total_stock || 0) / parseInt(u.conversion_to_base));
                    return `<button onclick="
                        addToCart(${JSON.stringify(product).replace(/"/g,'&quot;')},
                            '${u.unit_name}', 1, ${unitPrice}, ${u.conversion_to_base});
                        document.getElementById('unitSelectorModal').remove();"
                        style="padding:.9rem 1rem;border:2px solid #e5e7eb;border-radius:.75rem;background:#fff;cursor:pointer;text-align:left;transition:.2s;display:flex;justify-content:space-between;align-items:center;"
                        onmouseover="this.style.borderColor='#004d40';this.style.background='#f0fdf4'"
                        onmouseout="this.style.borderColor='#e5e7eb';this.style.background='#fff'">
                        <span>
                            <strong style="color:#1f2937;">${u.unit_name}</strong>
                            ${parseInt(u.conversion_to_base) > 1 ? `<small style="color:#6b7280;margin-left:6px;">= ${u.conversion_to_base} ${product.base_unit}</small>` : ''}
                        </span>
                        <span>
                            <strong style="color:#004d40;">₱${unitPrice.toFixed(2)}</strong>
                            <small style="color:${stockInUnit > 0 ? '#16a34a' : '#dc2626'};margin-left:8px;">${stockInUnit} avail</small>
                        </span>
                    </button>`;
                }).join('')}
            </div>
            <button onclick="document.getElementById('unitSelectorModal').remove()"
                style="margin-top:1rem;width:100%;padding:.65rem;border:1px solid #d1d5db;border-radius:.75rem;background:#fff;cursor:pointer;color:#6b7280;font-size:.85rem;">
                Cancel
            </button>
        </div>`;
    document.body.appendChild(modal);
}

function addToCart(product, unitName, qty, unitPrice, conversionToBase) {
    unitName       = unitName       || product.base_unit || 'piece';
    conversionToBase = parseInt(conversionToBase || 1);
    unitPrice      = parseFloat(unitPrice || product.price);
    qty            = parseInt(qty || 1);

    const cartKey  = product.id + '_' + unitName;
    const existing = cart.find(item => item.cartKey === cartKey);

    const totalBaseStock = parseInt(product.total_stock || 0);
    const addingBase     = qty * conversionToBase;

    // Calculate how many base units are already in cart for this product
    const usedBase = cart
        .filter(i => i.id === product.id)
        .reduce((sum, i) => sum + (i.quantity * i.conversionToBase), 0);

    if (usedBase + addingBase > totalBaseStock) {
        const avail = Math.floor((totalBaseStock - usedBase) / conversionToBase);
        showPosAlert(`Insufficient stock for ${product.brand_name} (${unitName}). Available: ${avail}`, 'danger');
        return;
    }

    if (existing) {
        existing.quantity   += qty;
        existing.subtotal    = existing.quantity * existing.unitPrice;
    } else {
        cart.push({
            cartKey,
            id: product.id,
            barcode: product.barcode,
            brand_name: product.brand_name,
            generic_name: product.generic_name || '',
            unitName,
            unitPrice,
            conversionToBase,
            quantity: qty,
            subtotal: qty * unitPrice,
            max_stock: totalBaseStock,
            base_unit: product.base_unit || 'piece'
        });
    }

    renderCart();
    // Update the product's cached stock for subsequent checks in this session
    product.total_stock = totalBaseStock - addingBase;
}

// ── Cart rendering ────────────────────────────────────────────

function renderCart() {
    const cartBody = document.getElementById('cart-body');
    if (!cartBody) return;

    cartBody.innerHTML = '';
    let total = 0;

    cart.forEach((item, index) => {
        const subtotal = item.unitPrice * item.quantity;
        total += subtotal;

        const row = document.createElement('tr');
        row.innerHTML = `
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;">
                <strong style="color:#1f2937;font-size:.9rem;">${escapeHtml(item.brand_name)}</strong>
                ${item.generic_name ? `<br><small style="color:#6b7280;">${escapeHtml(item.generic_name)}</small>` : ''}
                <br><small style="background:#e0f2f1;color:#004d40;padding:1px 6px;border-radius:10px;font-size:.7rem;">${escapeHtml(item.unitName)}</small>
            </td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;white-space:nowrap;">₱${item.unitPrice.toFixed(2)}</td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;white-space:nowrap;">
                <div style="display:flex;align-items:center;gap:6px;">
                    <button onclick="updateQuantity(${index}, -1)"
                        style="background:#f59e0b;color:#fff;border:none;width:28px;height:28px;border-radius:6px;cursor:pointer;font-size:1rem;line-height:1;">−</button>
                    <span style="font-weight:700;min-width:24px;text-align:center;">${item.quantity}</span>
                    <button onclick="updateQuantity(${index}, 1)"
                        style="background:#004d40;color:#fff;border:none;width:28px;height:28px;border-radius:6px;cursor:pointer;font-size:1rem;line-height:1;">+</button>
                </div>
            </td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;font-weight:700;color:#004d40;white-space:nowrap;">₱${subtotal.toFixed(2)}</td>
            <td style="padding:10px 12px;border-bottom:1px solid #f1f5f9;">
                <button onclick="removeItem(${index})"
                    style="background:#dc2626;color:#fff;border:none;width:28px;height:28px;border-radius:6px;cursor:pointer;">✕</button>
            </td>`;
        cartBody.appendChild(row);
    });

    const totalEl = document.getElementById('total-amount');
    if (totalEl) totalEl.textContent = total.toFixed(2);
    updateGrandTotal();
}

function updateQuantity(index, change) {
    if (!cart[index]) return;
    const item   = cart[index];
    const newQty = item.quantity + change;

    if (newQty <= 0) { removeItem(index); return; }

    // Recalculate available stock
    const usedBase = cart
        .filter((ci, i) => ci.id === item.id && i !== index)
        .reduce((sum, ci) => sum + (ci.quantity * ci.conversionToBase), 0);
    const maxForThisUnit = Math.floor((item.max_stock - usedBase) / item.conversionToBase);

    if (newQty > maxForThisUnit) {
        showPosAlert(`Only ${maxForThisUnit} ${item.unitName}(s) available for ${item.brand_name}`, 'warning');
        return;
    }

    item.quantity = newQty;
    item.subtotal = newQty * item.unitPrice;
    renderCart();
}

function removeItem(index) {
    // Restore cached stock
    const item = cart[index];
    const p    = productsList.find(p => p.id === item.id);
    if (p) p.total_stock = (parseInt(p.total_stock) || 0) + (item.quantity * item.conversionToBase);
    cart.splice(index, 1);
    renderCart();
}

function updateGrandTotal() {
    const total     = parseFloat(document.getElementById('total-amount')?.textContent || 0);
    const discount  = parseFloat(document.getElementById('discount-input')?.value || 0);
    const grandTotal = Math.max(0, total - discount);
    const grandEl   = document.getElementById('grand-total');
    if (grandEl) grandEl.textContent = grandTotal.toFixed(2);
    calculateChange();
}

function calculateChange() {
    const grandTotal = parseFloat(document.getElementById('grand-total')?.textContent || 0);
    const cash       = parseFloat(cashInput?.value || 0);
    const change     = Math.max(0, cash - grandTotal);
    const changeEl   = document.getElementById('change-amount');
    if (changeEl) changeEl.textContent = change.toFixed(2);
}

// ── Complete transaction ──────────────────────────────────────

async function completeTransaction() {
    if (cart.length === 0) { showPosAlert('Cart is empty!', 'warning'); return; }

    const grandTotal = parseFloat(document.getElementById('grand-total')?.textContent || 0);
    const cash       = parseFloat(cashInput?.value || 0);

    if (cash < grandTotal) {
        showPosAlert('Insufficient cash! Please enter the correct amount.', 'danger');
        if (cashInput) cashInput.focus();
        return;
    }

    // Build items payload — include quantity_in_base for backend FIFO
    const items = cart.map(item => ({
        id:               item.id,
        quantity_sold:    item.quantity,
        quantity_in_base: item.quantity * item.conversionToBase,
        unit_sold:        item.unitName,
        unit_price:       item.unitPrice,
        subtotal:         item.quantity * item.unitPrice,
        vat_type:         'vatable'
    }));

    const totalAmount = parseFloat(document.getElementById('total-amount')?.textContent || 0);
    const discount    = parseFloat(document.getElementById('discount-input')?.value || 0);

    const payload = {
        items,
        total_amount:        totalAmount,
        discount,
        tax:                 0,
        grand_total:         grandTotal,
        cash_received:       cash,
        change_amount:       cash - grandTotal,
        vat_sales:           grandTotal,
        vat_exempt_sales:    0,
        vat_zero_rated_sales:0,
        vat_amount:          grandTotal * 0.12
    };

    // Disable button to prevent double-submit
    const btn = document.querySelector('[onclick="completeTransaction()"]');
    if (btn) { btn.disabled = true; btn.textContent = 'Processing...'; }

    try {
        const resp = await fetch('../actions/pos_process.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload)
        });

        const result = await resp.json();

        if (result.success) {
            // Capture cart snapshot for receipt before clearing
            const receiptCart = JSON.parse(JSON.stringify(cart));
            printReceipt58mm(result.sale_id, receiptCart, totalAmount, discount, grandTotal, cash, cash - grandTotal);
            clearCart();
            showPosAlert(`Transaction complete! Sale #${result.sale_id}`, 'success');
        } else {
            showPosAlert('Error: ' + result.message, 'danger');
        }
    } catch (err) {
        console.error(err);
        showPosAlert('Network error. Please check your connection and try again.', 'danger');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-check"></i> Complete Sale (F8)'; }
    }
}

// ── 58mm Thermal Receipt ──────────────────────────────────────

function printReceipt58mm(saleId, receiptCart, total, discount, grand, cash, change) {
    const w = window.open('', '_blank', 'width=320,height=600');
    if (!w) { showPosAlert('Pop-up blocked. Please allow pop-ups to print.', 'warning'); return; }

    const now = new Date();
    const dateStr = now.toLocaleDateString('en-PH', { year:'numeric', month:'short', day:'numeric' });
    const timeStr = now.toLocaleTimeString('en-PH', { hour:'2-digit', minute:'2-digit' });

    const itemsHtml = receiptCart.map(item => {
        const sub = (item.quantity * item.unitPrice).toFixed(2);
        // Wrap long product names for 58mm
        const name = item.brand_name + (item.unitName !== item.base_unit ? ` (${item.unitName})` : '');
        return `
          <tr>
            <td colspan="3" style="padding-bottom:1px;word-break:break-word;">${escapeHtml(name)}</td>
          </tr>
          <tr>
            <td style="padding-left:8px;color:#555;">${item.quantity} x ₱${item.unitPrice.toFixed(2)}</td>
            <td></td>
            <td style="text-align:right;font-weight:600;">₱${sub}</td>
          </tr>`;
    }).join('');

    const receiptHtml = `<!DOCTYPE html>
<html><head>
<meta charset="UTF-8">
<title>Receipt #${saleId}</title>
<style>
  @page { margin: 0; size: 58mm auto; }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: 'Courier New', Courier, monospace;
    font-size: 10px;
    width: 56mm;
    margin: 0 auto;
    padding: 4mm 2mm;
    color: #000;
    line-height: 1.4;
  }
  .center { text-align: center; }
  .right  { text-align: right; }
  .bold   { font-weight: bold; }
  .store  { font-size: 13px; font-weight: bold; }
  .dashed { border-top: 1px dashed #000; margin: 3px 0; }
  .solid  { border-top: 1px solid  #000; margin: 3px 0; }
  table   { width: 100%; border-collapse: collapse; }
  td      { font-size: 9.5px; padding: 1px 0; vertical-align: top; }
  .total-row td { font-size: 10.5px; font-weight: 700; }
  .grand-row td { font-size: 12px; font-weight: 900; }
  .footer { font-size: 9px; text-align: center; margin-top: 4px; }
  @media print {
    body { width: 56mm; }
    .no-print { display: none; }
  }
</style>
</head>
<body>
<div class="center">
  <div class="store">🏥 CAD CREST PHARMACY</div>
  <div style="font-size:9px;">Official Receipt</div>
  <div style="font-size:9px;">Receipt #${saleId}</div>
  <div style="font-size:9px;">${dateStr} ${timeStr}</div>
  ${window.cashierName ? `<div style="font-size:9px;">Cashier: ${escapeHtml(window.cashierName)}</div>` : ''}
</div>
<div class="dashed"></div>
<table>
  <tbody>
    ${itemsHtml}
  </tbody>
</table>
<div class="dashed"></div>
<table>
  <tr><td>Subtotal:</td><td class="right">₱${total.toFixed(2)}</td></tr>
  ${discount > 0 ? `<tr><td>Discount:</td><td class="right">-₱${discount.toFixed(2)}</td></tr>` : ''}
  <tr class="grand-row"><td>TOTAL:</td><td class="right">₱${grand.toFixed(2)}</td></tr>
  <tr><td>Cash:</td><td class="right">₱${cash.toFixed(2)}</td></tr>
  <tr class="bold"><td>Change:</td><td class="right">₱${change.toFixed(2)}</td></tr>
</table>
<div class="solid"></div>
<div class="footer">
  Thank you for your purchase!<br>
  This is a computer-generated receipt.<br>
  VAT Reg: TIN 000-000-000-VAT
</div>
<script>window.onload=function(){window.print();window.onafterprint=function(){window.close();};}<\/script>
</body></html>`;

    w.document.write(receiptHtml);
    w.document.close();
}

// ── Helpers ───────────────────────────────────────────────────

function clearCart() {
    // Restore cached stock
    cart.forEach(item => {
        const p = productsList.find(p => p.id === item.id);
        if (p) p.total_stock = (parseInt(p.total_stock) || 0) + (item.quantity * item.conversionToBase);
    });
    cart = [];
    if (cashInput) cashInput.value = '';
    const disc = document.getElementById('discount-input');
    if (disc) disc.value = '0';
    renderCart();
    if (barcodeInput) barcodeInput.focus();
}

function showProductSelection(results) {
    const existing = document.getElementById('productSelectionModal');
    if (existing) existing.remove();

    const modal = document.createElement('div');
    modal.id = 'productSelectionModal';
    modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;display:flex;align-items:center;justify-content:center;backdrop-filter:blur(4px);';
    modal.innerHTML = `
        <div style="background:#fff;border-radius:1.25rem;padding:2rem;width:90%;max-width:560px;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 20px 50px rgba(0,0,0,.25);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <h3 style="margin:0;color:#004d40;">Select Product</h3>
                <button onclick="document.getElementById('productSelectionModal').remove()" style="background:none;border:none;font-size:1.5rem;cursor:pointer;color:#6b7280;">✕</button>
            </div>
            <div style="overflow-y:auto;flex:1;">
                ${results.map(p => `
                    <div onclick="addToCartWithUnit(${JSON.stringify(p).replace(/"/g,'&quot;')}); document.getElementById('productSelectionModal').remove();"
                         style="padding:12px;border:1px solid #e5e7eb;border-radius:.75rem;margin-bottom:.5rem;cursor:pointer;transition:.2s;"
                         onmouseover="this.style.borderColor='#004d40';this.style.background='#f0fdf4'"
                         onmouseout="this.style.borderColor='#e5e7eb';this.style.background='#fff'">
                        <div style="display:flex;justify-content:space-between;">
                            <div>
                                <strong>${escapeHtml(p.brand_name)}</strong>
                                <span style="color:#6b7280;font-size:.85rem;margin-left:6px;">${escapeHtml(p.generic_name || '')}</span>
                            </div>
                            <strong style="color:#004d40;">₱${parseFloat(p.price).toFixed(2)}</strong>
                        </div>
                        <small style="color:${parseInt(p.total_stock)>0?'#16a34a':'#dc2626'};">
                            Stock: ${p.total_stock || 0} ${p.base_unit || ''}
                        </small>
                    </div>
                `).join('')}
            </div>
        </div>`;
    document.body.appendChild(modal);
}

function showPosAlert(message, type) {
    const colors = { success:'#16a34a', danger:'#dc2626', warning:'#f59e0b', info:'#2563eb' };
    const alerts = document.getElementById('pos-alerts');
    if (alerts) {
        alerts.innerHTML = `<div style="padding:.75rem 1rem;border-radius:.75rem;background:${colors[type]||colors.info}15;border:1px solid ${colors[type]||colors.info}40;color:${colors[type]||colors.info};font-weight:600;margin-bottom:.75rem;">
            ${message}
        </div>`;
        setTimeout(() => { if (alerts) alerts.innerHTML = ''; }, 4000);
    } else {
        // Fallback toast
        const toast = document.createElement('div');
        toast.style.cssText = `position:fixed;bottom:24px;right:24px;background:${colors[type]||colors.info};color:#fff;padding:12px 20px;border-radius:10px;font-weight:600;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.2);max-width:320px;`;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => toast.remove(), 4000);
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
}