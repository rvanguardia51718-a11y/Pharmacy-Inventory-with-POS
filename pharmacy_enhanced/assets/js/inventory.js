// Inventory Management JavaScript

// ============================================
// MODAL FUNCTIONS
// ============================================

// Show modal
function showModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'block';
        
        // Focus on first input field
        setTimeout(() => {
            const firstInput = modal.querySelector('input:not([type=hidden])');
            if (firstInput) firstInput.focus();
        }, 100);
    }
}

// Hide modal
function hideModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.style.display = 'none';
    }
}

// ============================================
// PRODUCT FUNCTIONS
// ============================================

// Add batch/stock
function addBatch(productId, productName) {
    document.getElementById('batchProductId').value = productId;
    document.getElementById('batchProductName').textContent = productName;
    
    // Set minimum date to tomorrow
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    const expiryInput = document.querySelector('#addBatchModal input[name="expiry_date"]');
    if (expiryInput) {
        expiryInput.min = tomorrow.toISOString().split('T')[0];
    }
    
    showModal('addBatchModal');
}

// Edit product
function editProduct(product) {
    // Parse if product is a string
    if (typeof product === 'string') {
        try {
            product = JSON.parse(product);
        } catch (e) {
            console.error('Error parsing product:', e);
            return;
        }
    }
    
    // Populate edit form
    document.getElementById('edit_product_id').value = product.id || '';
    document.getElementById('edit_barcode').value = product.barcode || '';
    document.getElementById('edit_brand_name').value = product.brand_name || '';
    document.getElementById('edit_generic_name').value = product.generic_name || '';
    document.getElementById('edit_category').value = product.category || '';
    document.getElementById('edit_price').value = product.price || '';
    document.getElementById('edit_cost').value = product.cost || '';
    
    showModal('editProductModal');
}

// Confirm delete
function confirmDelete(productId, productName) {
    document.getElementById('delete_product_name').textContent = productName;
    document.getElementById('confirm_delete_btn').href = '../actions/products.php?action=delete&id=' + productId;
    showModal('deleteProductModal');
}

// ============================================
// FORM VALIDATION
// ============================================

// Validate product form (add/edit)
function validateProductForm(form) {
    const barcode = form.barcode.value.trim();
    const brandName = form.brand_name.value.trim();
    const price = parseFloat(form.price.value);
    const cost = parseFloat(form.cost.value);
    
    if (!barcode) {
        alert('Barcode is required!');
        form.barcode.focus();
        return false;
    }
    
    if (!brandName) {
        alert('Brand name is required!');
        form.brand_name.focus();
        return false;
    }
    
    if (isNaN(price) || price <= 0) {
        alert('Please enter a valid price!');
        form.price.focus();
        return false;
    }
    
    if (isNaN(cost) || cost <= 0) {
        alert('Please enter a valid cost!');
        form.cost.focus();
        return false;
    }
    
    if (cost > price) {
        if (!confirm('Warning: Cost is higher than price. Are you sure?')) {
            return false;
        }
    }
    
    return true;
}

// Validate batch form
function validateBatchForm(form) {
    const batchNumber = form.batch_number.value.trim();
    const quantity = parseInt(form.quantity.value);
    const expiryDate = new Date(form.expiry_date.value);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    
    if (!batchNumber) {
        alert('Batch number is required!');
        form.batch_number.focus();
        return false;
    }
    
    if (isNaN(quantity) || quantity <= 0) {
        alert('Quantity must be greater than 0!');
        form.quantity.focus();
        return false;
    }
    
    if (!form.expiry_date.value) {
        alert('Expiry date is required!');
        form.expiry_date.focus();
        return false;
    }
    
    if (expiryDate <= today) {
        alert('Expiry date must be in the future!');
        form.expiry_date.focus();
        return false;
    }
    
    return true;
}

// ============================================
// FILTER FUNCTIONS
// ============================================

// Apply filters
function applyFilters() {
    const category = document.getElementById('categoryFilter')?.value || '';
    const search = document.getElementById('searchFilter')?.value || '';
    
    let url = window.location.pathname.split('/').pop();
    let params = [];
    
    if (category) params.push('category=' + encodeURIComponent(category));
    if (search) params.push('search=' + encodeURIComponent(search));
    
    if (params.length > 0) {
        url += '?' + params.join('&');
    }
    
    window.location.href = url;
}

// Reset filters
function resetFilters() {
    window.location.href = window.location.pathname.split('/').pop();
}

// ============================================
// BULK ACTIONS
// ============================================

// Print barcode labels
function printBarcode(productId, productName, barcode) {
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
            <head>
                <title>Barcode Label</title>
                <style>
                    body { 
                        font-family: 'Courier New', monospace; 
                        margin: 20px; 
                        text-align: center;
                    }
                    .label {
                        border: 1px solid #000;
                        padding: 20px;
                        width: 300px;
                        margin: 20px auto;
                    }
                    .product-name {
                        font-size: 14px;
                        font-weight: bold;
                        margin-bottom: 10px;
                    }
                    .barcode {
                        font-size: 24px;
                        font-weight: bold;
                        letter-spacing: 2px;
                        margin: 10px 0;
                    }
                    .price {
                        font-size: 18px;
                        color: #2e7d32;
                        font-weight: bold;
                    }
                </style>
            </head>
            <body>
                <div class="label">
                    <div class="product-name">${productName}</div>
                    <div class="barcode">${barcode}</div>
                    <div class="price">₱${document.getElementById('edit_price')?.value || '0.00'}</div>
                </div>
                <script>window.print();</script>
            </body>
        </html>
    `);
    printWindow.document.close();
}

// Export inventory to CSV
function exportToCSV() {
    const table = document.querySelector('.batch-table');
    if (!table) return;
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    for (let row of rows) {
        const rowData = [];
        const cols = row.querySelectorAll('td, th');
        
        for (let col of cols) {
            // Skip actions column
            if (rowData.length < 6) {
                rowData.push('"' + col.innerText.replace(/"/g, '""') + '"');
            }
        }
        
        csv.push(rowData.join(','));
    }
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'inventory_export_' + new Date().toISOString().split('T')[0] + '.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}

// ============================================
// EVENT LISTENERS
// ============================================

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.style.display = 'none';
    }
}

// Close modal with ESC key
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const modals = document.querySelectorAll('.modal');
        modals.forEach(modal => {
            modal.style.display = 'none';
        });
    }
});

// Initialize tooltips and other features
document.addEventListener('DOMContentLoaded', function() {
    console.log('Inventory.js loaded successfully');
    
    // Add input validation for number fields
    const numberInputs = document.querySelectorAll('input[type="number"]');
    numberInputs.forEach(input => {
        input.addEventListener('keypress', function(e) {
            if (e.key === '-' || e.key === 'e') {
                e.preventDefault();
            }
        });
    });
    
    // Set min date for expiry date inputs
    const expiryInputs = document.querySelectorAll('input[name="expiry_date"]');
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    expiryInputs.forEach(input => {
        input.min = tomorrow.toISOString().split('T')[0];
    });
});