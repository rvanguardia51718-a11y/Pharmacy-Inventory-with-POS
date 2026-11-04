<?php
// pos.php - ROOT directory
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/notifications.php';

try {
    // POS RESTRICTION: Only show products with "good" stock
    // Good stock = expiration_date >= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
    // Excludes: Expired (< today) and Near Expiry (today <= exp < today+1month)
    $query = "
        SELECT p.*,
               COALESCE(p.dosage_form, '') as dosage_form,
               COALESCE(p.strength, '') as strength,
               COALESCE(p.vat_type, 'vatable') as vat_type,
               COALESCE(p.requires_prescription, 0) as requires_prescription,
               COALESCE(p.prescription_type, 'OTC') as prescription_type,
               c.name as category_name,
               c.id as category_id,
               COALESCE(SUM(
                   CASE WHEN sb.expiration_date >= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
                        AND sb.quantity_base > 0
                        THEN COALESCE(sb.available_quantity, sb.quantity_base - sb.damage_quantity)
                        ELSE 0 END
               ), 0) as total_stock,
               (SELECT sb2.selling_price
                FROM stock_batches sb2
                WHERE sb2.product_id = p.id
                  AND sb2.expiration_date >= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
                  AND COALESCE(sb2.available_quantity, sb2.quantity_base - sb2.damage_quantity) > 0
                  AND sb2.selling_price IS NOT NULL
                ORDER BY sb2.expiration_date ASC, sb2.id ASC
                LIMIT 1) as fifo_price
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN stock_batches sb ON p.id = sb.product_id
        WHERE p.is_archived = 0
        GROUP BY p.id
        HAVING total_stock > 0
        ORDER BY p.brand_name ASC
    ";
    
    $result = $conn->query($query);
    
    $products = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            // Fetch units for multi-unit POS support
            $uid_stmt = $conn->prepare(
                "SELECT unit_name, conversion_to_base, price_override, sort_order
                 FROM product_units WHERE product_id=? ORDER BY conversion_to_base ASC, sort_order ASC"
            );
            $uid_stmt->bind_param("i", $row['id']);
            $uid_stmt->execute();
            $row['units'] = $uid_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $uid_stmt->close();
            // Backward compat
            $row['stock'] = $row['total_stock'];
            $products[] = $row;
        }
    }

    // Get categories for filter
    $cat_result = $conn->query("
        SELECT c.id, c.name 
        FROM categories c
        INNER JOIN products p ON p.category_id = c.id
        GROUP BY c.id
        ORDER BY c.name
    ");
    $categories = [];
    if ($cat_result) {
        while ($row = $cat_result->fetch_assoc()) {
            $categories[] = $row;
        }
    }

} catch (Exception $e) {
    die("Database error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>POS Terminal | CAD Crest</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Page-specific styles only */
        :root {
            --primary-green: #004d40;
            --accent-gold: #b8860b;
            --pos-bg: #f1f5f9;
            --card-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --danger: #ef4444;
            --success: #10b981;
            --warning: #f59e0b;
            --text-dark: #1e293b;
            --text-muted: #64748b;
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

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body { 
            background-color: var(--pos-bg);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            overflow-x: hidden;
            min-height: 100vh;
            position: relative;
        }

        /* Main content - adjusted to not overlap with sidebar */
        .main-content {
            margin-left: calc(280px + 24px);
            margin-right: 380px; /* Space for sale sidebar */
            width: calc(100% - 280px - 380px - 48px);
            padding: 24px 28px;
            min-height: 100vh;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1), 
                        margin-right 0.3s ease,
                        width 0.3s ease;
            position: relative;
            z-index: 1;
        }

        /* When sidebar is collapsed */
        #sidebar-toggle:checked ~ .main-content {
            margin-left: calc(80px + 24px);
            width: calc(100% - 80px - 380px - 48px);
        }

        /* RIGHT SIDEBAR - SALE SUMMARY - Fixed positioning with higher z-index */
        .sale-sidebar {
            width: 380px;
            background: white;
            height: 100vh;
            position: fixed;
            right: 0;
            top: 0;
            box-shadow: -4px 0 20px rgba(0,0,0,0.08);
            display: flex;
            flex-direction: column;
            border-left: 1px solid #e9edf4;
            z-index: 100; /* Higher than main content but lower than modals */
        }

        .sale-header {
            padding: 24px;
            border-bottom: 1px solid #eef2f6;
            background: white;
            z-index: 2;
        }

        .sale-header h2 {
            font-size: 1.35rem;
            font-weight: 600;
            color: #1a2c3e;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .cashier-chip {
            background: #e8f0fe;
            color: #1e4b7a;
            padding: 6px 14px;
            border-radius: 30px;
            font-size: 0.8rem;
            font-weight: 500;
            display: inline-block;
            margin-top: 12px;
        }

        /* Cart items container - scrollable */
        .cart-container {
            flex: 1;
            overflow-y: auto;
            padding: 16px 20px;
            background: #fafcff;
            max-height: calc(100vh - 300px); /* Adjust based on header and footer */
        }

        .cart-item {
            background: white;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.02);
            border: 1px solid #edf2f7;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.15s;
        }

        .cart-item.selected-cart-item {
            border: 2px solid #2e7d32;
            background: #f1f9f0;
        }

        .cart-item-info h4 {
            font-size: 0.95rem;
            font-weight: 600;
            color: #1e2a3a;
            margin-bottom: 4px;
        }
    
        .cart-item-info p {
            font-size: 0.75rem;
            color: #6b7a8b;
        }

        .cart-item-qty {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #f1f5f9;
            padding: 4px;
            border-radius: 30px;
        }

        .cart-item-qty button {
            width: 28px;
            height: 28px;
            border: none;
            background: white;
            border-radius: 50%;
            font-weight: 600;
            color: #2d3c4c;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            cursor: pointer;
            transition: 0.15s;
        }

        .cart-item-qty button:hover {
            background: #2e7d32;
            color: white;
        }

        .cart-item-qty button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: #e2e8f0;
        }

        .cart-item-price {
            text-align: right;
            font-weight: 700;
            color: #1f4a3d;
            font-size: 1rem;
        }

        .empty-cart {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 200px;
            color: #9aa6b2;
            text-align: center;
        }

        .empty-cart span {
            font-size: 2.8rem;
            margin-bottom: 12px;
        }

        /* Sale summary footer - fixed at bottom */
        .sale-footer {
            background: white;
            padding: 24px;
            border-top: 1px solid #e9edf4;
            box-shadow: 0 -4px 12px rgba(0,0,0,0.02);
            z-index: 2;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 0.95rem;
            color: #33475b;
        }

        .summary-row.total {
            font-size: 1.35rem;
            font-weight: 700;
            color: #1e4a3b;
            border-top: 2px solid #e2e8f0;
            margin-top: 12px;
            padding-top: 16px;
        }

        .payment-group {
            margin: 20px 0 16px;
        }

        .payment-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: #4a5b6b;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        #cash-input {
            width: 100%;
            padding: 14px 16px;
            border: 1.5px solid #dfe6ed;
            border-radius: 12px;
            font-size: 1.2rem;
            font-weight: 600;
            text-align: right;
            background: white;
            transition: 0.2s;
        }

        #cash-input:focus {
            border-color: #2e7d32;
            outline: none;
            box-shadow: 0 0 0 3px rgba(46,125,50,0.08);
        }

        #cash-input.error {
            border-color: var(--danger);
            background-color: #fff5f5;
            animation: shake 0.3s ease-in-out;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        .change-display {
            background: #e8f5e9;
            padding: 14px 18px;
            border-radius: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 16px 0 8px;
        }

        .change-label {
            font-weight: 600;
            color: #1e4a3b;
        }

        .change-amount {
            font-size: 1.6rem;
            font-weight: 700;
            color: #1e4a3b;
        }

        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }

        .btn-complete {
            flex: 2;
            background: #2e7d32;
            color: white;
            border: none;
            padding: 16px 12px;
            border-radius: 14px;
            font-weight: 600;
            font-size: 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            cursor: pointer;
            transition: 0.2s;
        }

        .btn-complete:focus {
            outline: 3px solid #ffc107;
        }

        .btn-clear {
            flex: 1;
            background: white;
            color: #b33a3a;
            border: 1.5px solid #ffe3e3;
            padding: 16px 12px;
            border-radius: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: 0.2s;
        }

        .btn-clear:focus {
            outline: 3px solid #ffc107;
        }

        /* CENTER CONTENT */
        .pos-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 1rem;
            position: relative;
            z-index: 2;
        }

        .pos-header h1 {
            margin: 0;
            color: var(--primary-green);
            font-size: 1.8rem;
            font-weight: 800;
        }

        .pos-header p {
            margin: 5px 0 0;
            color: var(--text-muted);
        }

        .clock {
            font-weight: 700;
            color: var(--primary-green);
            font-size: 1.2rem;
            background: white;
            padding: 10px 20px;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
        }

        /* SEARCH SECTION */
        .search-section {
            background: white;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 28px;
            box-shadow: var(--card-shadow);
            position: relative;
            z-index: 2;
        }

        .search-row {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .search-container {
            flex: 2;
            min-width: 300px;
        }

        .search-container label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: var(--text-muted);
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
            display: block;
        }

        .search-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .search-input-wrapper i {
            position: absolute;
            left: 12px;
            color: var(--text-muted);
            font-size: 0.9rem;
        }

        #search-input {
            width: 100%;
            padding: 12px 12px 12px 36px;
            border: 2px solid #f1f5f9;
            border-radius: 12px;
            font-size: 0.95rem;
            background: #fafdff;
            transition: 0.2s;
        }

        #search-input:focus {
            border-color: var(--primary-green);
            background: white;
            box-shadow: 0 0 0 3px rgba(0, 77, 64, 0.06);
            outline: none;
        }

        .category-filter-container {
            flex: 1;
            min-width: 200px;
        }

        .category-filter-container label {
            font-size: 0.7rem;
            text-transform: uppercase;
            color: var(--text-muted);
            font-weight: 600;
            letter-spacing: 0.5px;
            margin-bottom: 4px;
            display: block;
        }

        #category-filter {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid #f1f5f9;
            border-radius: 12px;
            font-size: 0.9rem;
            background: white;
            cursor: pointer;
        }

        #category-filter:focus {
            border-color: var(--primary-green);
            outline: none;
        }

        .shortcut-hint {
            display: inline-block;
            background: #edf2f7;
            color: #2d4c6e;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-left: 8px;
        }

        /* PRODUCT GRID - Responsive with proper z-index */
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            gap: 18px;
            margin-top: 8px;
            max-height: calc(100vh - 250px);
            overflow-y: auto;
            padding: 5px 5px 20px 5px;
            position: relative;
            z-index: 2;
        }

        .product-card {
            background: white;
            border-radius: 18px;
            padding: 18px 16px;
            border: 2px solid #f1f5f9;
            transition: all 0.2s;
            position: relative;
            cursor: pointer;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
            z-index: 2;
        }

        .product-card:hover {
            border-color: var(--primary-green);
            background: #fafefb;
            transform: translateY(-3px);
            box-shadow: 0 12px 22px rgba(0, 77, 64, 0.06);
            z-index: 3;
        }

        .product-card.selected {
            border-color: var(--accent-gold);
            background: #fffbeb;
            border-width: 2px;
            box-shadow: 0 0 0 3px rgba(184, 134, 11, 0.2);
            z-index: 3;
        }

        .product-card:focus {
            outline: none;
            border-color: var(--primary-green);
        }

        .product-badge {
            position: absolute;
            top: 14px;
            right: 16px;
            background: #e9ecf0;
            color: #3b4e5e;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 0.65rem;
            font-weight: 600;
            z-index: 2;
        }

        .product-name {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 6px;
            padding-right: 48px;
            line-height: 1.3;
        }

        .product-generic {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-bottom: 14px;
            padding-bottom: 12px;
            border-bottom: 1px dashed #e2eaf0;
        }

        .product-price {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--primary-green);
            margin-bottom: 10px;
        }

        .product-stock {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-bottom: 16px;
        }

        .stock-indicator {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--success);
        }

        .stock-indicator.low {
            background: #f59e0b;
        }

        .stock-indicator.empty {
            background: #d32f2f;
        }

        .qty-control {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f1f7fc;
            padding: 4px 6px;
            border-radius: 40px;
        }

        .qty-btn {
            width: 34px;
            height: 34px;
            border: none;
            background: white;
            border-radius: 50%;
            font-weight: 600;
            font-size: 1.1rem;
            color: #2c3e4e;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
            cursor: pointer;
            transition: 0.15s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .qty-btn:hover {
            background: var(--primary-green);
            color: white;
        }

        .qty-btn:focus {
            outline: 2px solid var(--accent-gold);
        }

        .qty-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: #e2e8f0;
        }

        .qty-value {
            font-weight: 600;
            min-width: 24px;
            text-align: center;
            color: var(--text-dark);
        }

        .add-to-cart-btn {
            font-size: 0.7rem;
            color: white;
            font-weight: 500;
            background: var(--primary-green);
            padding: 5px 12px;
            border: none;
            border-radius: 30px;
            cursor: pointer;
        }

        .add-to-cart-btn:hover {
            background: #00695c;
        }

        .add-to-cart-btn:focus {
            outline: 2px solid var(--accent-gold);
        }

        .add-to-cart-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: #94a3b8;
        }

        /* Keyboard Shortcuts Hint */
        .keyboard-hint {
            position: fixed;
            bottom: 20px;
            right: 400px;
            background: rgba(26,44,62,0.85);
            backdrop-filter: blur(4px);
            color: white;
            padding: 10px 20px;
            border-radius: 40px;
            font-size: 0.75rem;
            display: flex;
            gap: 20px;
            z-index: 90; /* Lower than sale sidebar */
            border: 1px solid rgba(255,255,255,0.15);
            flex-wrap: wrap;
            pointer-events: none;
        }

        .key {
            background: rgba(255,255,255,0.18);
            padding: 4px 10px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 0.75rem;
            font-weight: 600;
        }

        :focus {
            outline: 2px solid var(--accent-gold);
        }

        /* Unit selector badge in cart */
        .unit-badge {
            display: inline-block;
            background: #e1f5fe;
            color: #0288d1;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-right: 5px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .unit-badge:hover {
            background: #b3e5fc;
            transform: scale(1.05);
        }
        
        .unit-badge i {
            font-size: 0.6rem;
            margin-left: 2px;
        }

        /* Stock warning */
        .stock-warning {
            color: var(--warning);
            font-size: 0.65rem;
            margin-top: 2px;
            font-weight: 600;
        }

        /* Unit Selector Modal - High z-index */
        #unitSelectorModal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            flex-direction: column;
        }

        #unitSelectorModal .modal-content {
            background: #fff;
            width: 90%;
            max-width: 380px;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: 0 20px 50px rgba(0,0,0,.25);
        }

        .unit-sel-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: .8rem 1rem;
            border: 1px solid #edf2f7;
            border-radius: 10px;
            margin-bottom: .5rem;
            cursor: pointer;
            transition: .2s;
        }
        
        .unit-sel-row:hover {
            background: #e8f3f0;
            border-color: #004d40;
        }

        /* Receipt Modal - High z-index */
        #receiptModal {
            display: none;
            position: fixed;
            z-index: 9998;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
        }

        #receiptModal .modal-content {
            background: white;
            border-radius: 10px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.2);
            animation: slideIn 0.3s ease;
            width: 400px;
            max-width: 98vw;
            max-height: 92vh;
            overflow-y: auto;
            padding: 12px 10px;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* 58mm Receipt Styles — 48mm printable / 384px */
        .receipt-58mm {
            font-family: 'Courier New', 'Lucida Console', monospace;
            width: 384px;
            max-width: 100%;
            margin: 0 auto;
            padding: 4px 2px;
            background: white;
            color: black;
            font-size: 11px;
            line-height: 1.3;
            box-sizing: border-box;
        }

        .btn-primary {
            background: var(--primary-green);
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
        }

        .btn-primary:focus {
            outline: 3px solid var(--accent-gold);
        }

        /* Print styles — 48mm printable thermal paper */
        @media print {
            @page {
                size: 58mm auto;
                margin: 0;
            }
            
            body * {
                visibility: hidden;
            }
            
            #receipt-content, 
            #receipt-content * {
                visibility: visible;
            }
            
            #receipt-content {
                position: fixed;
                left: 0;
                top: 0;
                width: 58mm;
                margin: 0;
                padding: 1mm 2mm;
                background: white;
            }
            
            .receipt-58mm {
                width: 54mm;
                max-width: 54mm;
                margin: 0 auto;
                padding: 0;
                font-family: 'Courier New', 'Lucida Console', monospace !important;
                font-size: 7.5pt !important;
                line-height: 1.25 !important;
                background: white;
                color: black !important;
            }
            
            .receipt-58mm div, 
            .receipt-58mm span, 
            .receipt-58mm p {
                color: black !important;
                font-size: 7.5pt !important;
            }
            
            .modal-content {
                box-shadow: none;
                padding: 0;
                margin: 0;
                background: white;
            }
            
            button {
                display: none !important;
            }
        }

        /* Error Toast - High z-index */
        .error-toast {
            position: fixed;
            top: 20px;
            right: 400px;
            background: #fee2e2;
            border-left: 4px solid var(--danger);
            color: #991b1b;
            padding: 16px 20px;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            z-index: 10000;
            display: flex;
            align-items: center;
            gap: 12px;
            max-width: 350px;
            animation: slideInRight 0.3s ease, fadeOut 0.3s ease 4.7s forwards;
            font-weight: 500;
        }

        .error-toast i {
            font-size: 1.4rem;
            color: var(--danger);
        }

        .error-toast .close-toast {
            margin-left: auto;
            cursor: pointer;
            color: #991b1b;
            opacity: 0.7;
            transition: 0.2s;
        }

        .error-toast .close-toast:hover {
            opacity: 1;
        }

        @keyframes slideInRight {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }

        @keyframes fadeOut {
            to {
                opacity: 0;
                transform: translateY(-20px);
                visibility: hidden;
            }
        }

        /* Responsive Breakpoints */
        @media (max-width: 1400px) {
            .main-content {
                margin-right: 340px;
                width: calc(100% - 280px - 340px - 48px);
            }
            .sale-sidebar {
                width: 340px;
            }
            .keyboard-hint {
                right: 360px;
            }
        }

        @media (max-width: 1200px) {
            .main-content {
                margin-left: calc(260px + 24px);
                margin-right: 320px;
                width: calc(100% - 260px - 320px - 48px);
                padding: 20px;
            }
            
            .sale-sidebar {
                width: 320px;
            }
            
            .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            }
        }

        @media (max-width: 992px) {
            .main-content {
                margin-left: calc(240px + 24px);
                margin-right: 300px;
                width: calc(100% - 240px - 300px - 48px);
            }
            
            .sale-sidebar {
                width: 300px;
            }
            
            .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
                gap: 12px;
            }
            
            .product-card {
                padding: 14px;
            }
            
            .product-name {
                font-size: 1rem;
            }
            
            .product-price {
                font-size: 1.1rem;
            }
        }

        @media (max-width: 768px) {
            /* Stack layout on mobile */
            .main-content {
                margin-left: 0 !important;
                margin-right: 0 !important;
                width: 100% !important;
                padding: 16px !important;
                padding-bottom: 320px !important; /* Space for fixed sale sidebar */
            }
            
            #sidebar-toggle:checked ~ .main-content {
                margin-left: 0 !important;
                width: 100% !important;
            }
            
            .sale-sidebar {
                width: 100%;
                height: 300px;
                bottom: 0;
                top: auto;
                right: 0;
                left: 0;
                border-left: none;
                border-top: 1px solid #e9edf4;
                box-shadow: 0 -4px 20px rgba(0,0,0,0.1);
                z-index: 1000;
            }
            
            .sale-header {
                padding: 16px;
            }
            
            .cart-container {
                max-height: 150px;
                padding: 12px;
            }
            
            .sale-footer {
                padding: 16px;
            }
            
            .action-buttons {
                gap: 8px;
            }
            
            .btn-complete, .btn-clear {
                padding: 12px;
                font-size: 0.9rem;
            }
            
            .change-amount {
                font-size: 1.4rem;
            }
            
            .pos-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .clock {
                align-self: flex-start;
            }
            
            .search-row {
                flex-direction: column;
                gap: 12px;
            }
            
            .search-container,
            .category-filter-container {
                width: 100%;
                min-width: auto;
            }
            
            .product-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
                max-height: calc(100vh - 400px);
            }
            
            .keyboard-hint {
                display: none; /* Hide on mobile */
            }
            
            .error-toast {
                right: 16px;
                left: 16px;
                max-width: none;
                z-index: 10001;
            }
        }

        @media (max-width: 576px) {
            .product-grid {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            
            .product-card {
                padding: 12px;
            }
            
            .product-name {
                font-size: 0.9rem;
                padding-right: 30px;
            }
            
            .product-badge {
                top: 8px;
                right: 8px;
                padding: 2px 6px;
                font-size: 0.55rem;
            }
            
            .product-generic {
                font-size: 0.65rem;
                margin-bottom: 8px;
                padding-bottom: 6px;
            }
            
            .product-price {
                font-size: 1rem;
                margin-bottom: 6px;
            }
            
            .product-stock {
                font-size: 0.65rem;
                margin-bottom: 10px;
            }
            
            .qty-control {
                gap: 4px;
            }
            
            .qty-btn {
                width: 28px;
                height: 28px;
                font-size: 0.9rem;
            }
            
            .add-to-cart-btn {
                padding: 4px 8px;
                font-size: 0.6rem;
            }
            
            .pos-header h1 {
                font-size: 1.5rem;
            }
            
            .clock {
                font-size: 1rem;
                padding: 8px 16px;
            }
            
            .sale-header h2 {
                font-size: 1.2rem;
            }
            
            .summary-row.total {
                font-size: 1.2rem;
            }
            
            #cash-input {
                padding: 10px 12px;
                font-size: 1rem;
            }
        }

        /* ── Prescription Rx Styles ── */
        .rx-tag {
            position: absolute;
            top: 14px;
            right: 56px;
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
            border-radius: 6px;
            padding: 3px 7px;
            font-size: 0.6rem;
            font-weight: 700;
            letter-spacing: .3px;
            z-index: 2;
            display: flex;
            align-items: center;
            gap: 2px;
        }
        .rx-product {
            border-color: #fecaca !important;
        }
        .rx-product:hover {
            border-color: #f87171 !important;
        }
        .rx-cart-pill {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            background: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fca5a5;
            border-radius: 4px;
            padding: 1px 5px;
            font-size: 0.6rem;
            font-weight: 700;
            margin-left: 5px;
            vertical-align: middle;
        }
        /* Rx approval modal */
        #rxApprovalModal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.65);
            z-index: 10005;
            align-items: center;
            justify-content: center;
        }
        .rx-modal-box {
            background: #fff;
            border-radius: 20px;
            width: 92%;
            max-width: 450px;
            box-shadow: 0 25px 60px rgba(0,0,0,.35);
            overflow: hidden;
            animation: rxPop .22s ease;
        }
        @keyframes rxPop {
            from { opacity:0; transform: scale(.94) translateY(-12px); }
            to   { opacity:1; transform: scale(1)   translateY(0); }
        }
        .rx-modal-hdr {
            background: linear-gradient(135deg, #7f1d1d 0%, #b91c1c 100%);
            padding: 1.4rem 1.6rem;
            color: #fff;
        }
        .rx-modal-hdr h3 {
            margin: 0 0 .35rem;
            font-size: 1.05rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: .55rem;
        }
        .rx-modal-hdr p  { margin: 0; font-size: .8rem; opacity: .88; }
        .rx-modal-body   { padding: 1.5rem; }
        .rx-rx-list {
            background: #fff7ed;
            border: 1px solid #fed7aa;
            border-radius: 10px;
            padding: .7rem 1rem;
            margin-bottom: 1.1rem;
            font-size: .82rem;
            color: #92400e;
        }
        .rx-rx-list strong { color: #9a3412; display: block; margin-bottom: .35rem; }
        .rx-rx-list li { margin-left: 1.1rem; }
        .rx-err {
            background: #fee2e2;
            border-left: 3px solid #b91c1c;
            color: #7f1d1d;
            padding: .65rem 1rem;
            border-radius: 8px;
            font-size: .83rem;
            display: none;
            margin-bottom: .9rem;
        }
        .rx-ok {
            background: #dcfce7;
            border-left: 3px solid #16a34a;
            color: #14532d;
            padding: .65rem 1rem;
            border-radius: 8px;
            font-size: .83rem;
            display: none;
            margin-bottom: .9rem;
        }
        .rx-field { margin-bottom: .95rem; }
        .rx-field label {
            display: block;
            font-size: .75rem;
            font-weight: 700;
            color: #4b5563;
            margin-bottom: .32rem;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .rx-field input {
            width: 100%;
            padding: .72rem 1rem;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            font-size: .93rem;
            box-sizing: border-box;
            transition: border-color .18s;
        }
        .rx-field input:focus {
            border-color: #b91c1c;
            outline: none;
            box-shadow: 0 0 0 3px rgba(185,28,28,.1);
        }
        .rx-btns { display: flex; gap: .7rem; margin-top: 1.2rem; }
        .rx-btn  {
            flex: 1;
            padding: .82rem .5rem;
            border: none;
            border-radius: 11px;
            font-weight: 700;
            font-size: .88rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .45rem;
            transition: .18s;
        }
        .rx-btn-cancel  { background: #f3f4f6; color: #374151; }
        .rx-btn-cancel:hover  { background: #e5e7eb; }
        .rx-btn-verify  { background: #b91c1c; color: #fff; }
        .rx-btn-verify:hover  { background: #991b1b; }
        .rx-btn-proceed { background: #16a34a; color: #fff; display: none; }
        .rx-btn-proceed:hover { background: #15803d; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>

    <!-- Error Toast Container -->
    <div id="errorToastContainer" style="position: fixed; top: 20px; right: 400px; z-index: 10000;"></div>

    <!-- RIGHT SALE SUMMARY SIDEBAR -->
    <div class="sale-sidebar">
        <div class="sale-header">
            <h2>
                <span>🧾</span> Sale Summary
            </h2>
            <div style="display:flex;align-items:center;gap:.5rem;">
                <span class="cashier-chip">
                    👤 <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Cashier'); ?>
                </span>
            </div>
        </div>

        <div id="cart-container" class="cart-container">
            <div class="empty-cart">
                <span>🛒</span>
                <h3>Cart is empty</h3>
                <p>Use arrow keys to select products, Enter to add</p>
            </div>
        </div>

        <div class="sale-footer">
            <div class="summary-row">
                <span>VAT Sales (excl.)</span>
                <span>₱<span id="vat-sales-base">0.00</span></span>
            </div>
            <div class="summary-row">
                <span>VAT Exempt</span>
                <span>₱<span id="vat-exempt">0.00</span></span>
            </div>
            <div class="summary-row">
                <span>VAT (12%)</span>
                <span>₱<span id="tax">0.00</span></span>
            </div>
            <div class="summary-row total">
                <span>Total</span>
                <span>₱<span id="grand-total">0.00</span></span>
            </div>

            <div class="payment-group">
                <label>💰 Cash Received <span class="shortcut-hint">F3</span></label>
                <input type="number" id="cash-input" placeholder="0.00" step="0.01">
            </div>

            <div class="change-display">
                <span class="change-label">Change</span>
                <span class="change-amount">₱<span id="change-amount">0.00</span></span>
            </div>

            <div class="action-buttons">
                <button onclick="completeTransaction()" class="btn-complete" id="complete-btn" tabindex="0">
                    <span>✓</span> F8 - Complete
                </button>
                <button onclick="clearCart()" class="btn-clear" id="clear-btn" tabindex="0">
                    <span>✕</span> ESC
                </button>
            </div>
        </div>
    </div>

    <!-- MAIN CONTENT -->
    <div class="main-content">
        <div class="pos-header">
            <div>
                <h1>Point of Sale</h1>
                <p>CAD Crest Pharmaceutical Products Trading</p>
            </div>
            <div style="display: flex; align-items: center; gap: 15px;">
                <?php renderNotificationBell($conn); ?>
                <div class="clock" id="clock"></div>
            </div>
        </div>

        <!-- ONE SEARCH BAR + CATEGORY FILTER -->
        <div class="search-section">
            <div class="search-row">
                <div class="search-container">
                    <label>🔍 Search Products <span class="shortcut-hint">F1</span></label>
                    <div class="search-input-wrapper">
                        <i class="fa-solid fa-search"></i>
                        <input type="text" id="search-input" placeholder="Search by barcode, name, generic..." autofocus>
                    </div>
                </div>
                
                <div class="category-filter-container">
                    <label>📁 Filter by Category <span class="shortcut-hint">F2</span></label>
                    <select id="category-filter">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>">
                                <?php echo htmlspecialchars($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>
        </div>

        <!-- Product Grid -->
        <div id="product-grid" class="product-grid">
            <?php if (empty($products)): ?>
                <div style="grid-column: 1/-1; text-align: center; padding: 60px;">
                    <h3>No products in stock</h3>
                    <p>Add products in Inventory first</p>
                </div>
            <?php else: ?>
                <?php foreach ($products as $index => $p): ?>
                    <div class="product-card <?php echo $p['requires_prescription'] ? 'rx-product' : ''; ?>" 
                         data-id="<?php echo $p['id']; ?>"
                         data-name="<?php echo strtolower($p['brand_name'] . ' ' . $p['generic_name']); ?>"
                         data-barcode="<?php echo strtolower($p['barcode'] ?? ''); ?>"
                         data-category-id="<?php echo $p['category_id']; ?>"
                         data-price="<?php echo ($p['fifo_price'] !== null ? $p['fifo_price'] : $p['price']); ?>"
                         data-stock="<?php echo $p['stock']; ?>"
                         data-vat-type="<?php echo htmlspecialchars($p['vat_type'] ?? 'vatable'); ?>"
                         data-base-unit="<?php echo htmlspecialchars($p['base_unit']); ?>"
                         data-dosage-form="<?php echo strtolower(htmlspecialchars($p['dosage_form'] ?? '')); ?>"
                         data-rx="<?php echo ($p['requires_prescription'] ? '1' : '0'); ?>"
                         data-stock="<?php echo $p['stock']; ?>"
                         tabindex="0"
                         data-index="<?php echo $index; ?>">
                        
                        <span class="product-badge">#<?php echo $index + 1; ?></span>
                        <?php if ($p['requires_prescription']): ?>
                        <span class="rx-tag" title="Prescription Required">
                            <i class="fa-solid fa-prescription-bottle-medical"></i>&nbsp;Rx
                        </span>
                        <?php endif; ?>
                        <div class="product-name"><?php echo htmlspecialchars($p['brand_name']); ?></div>
                        <div class="product-generic"><?php echo htmlspecialchars($p['generic_name']); ?></div>
                        <?php if (!empty($p['barcode'])): ?>
                            <div style="font-size: 0.6rem; color: #999; margin-bottom: 5px;">
                                <i class="fa-solid fa-barcode"></i> <?php echo htmlspecialchars($p['barcode']); ?>
                            </div>
                        <?php endif; ?>
                        <?php if($p['dosage_form'] || $p['strength']): ?>
                        <div style="font-size: 0.68rem; color: #64748b; margin-bottom: 4px;">
                            <?php if($p['dosage_form']): ?><span style="background:#e1f5fe;color:#0288d1;padding:1px 5px;border-radius:3px;font-size:.65rem"><?php echo htmlspecialchars($p['dosage_form']); ?></span><?php endif; ?>
                            <?php if($p['strength']): ?><strong><?php echo htmlspecialchars($p['strength']); ?></strong><?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="product-price">₱<?php echo number_format($p['fifo_price'] !== null ? $p['fifo_price'] : $p['price'], 2); ?><small style="font-size:.62rem;color:#94a3b8;font-weight:normal"> /<?php echo htmlspecialchars($p['base_unit']); ?></small></div>
                        <div class="product-stock">
                            <span class="stock-indicator <?php echo $p['stock'] < 10 ? 'low' : ''; ?>"></span>
                            <span><?php echo $p['stock']; ?> units</span>
                        </div>
                        
                        <div style="display: flex; justify-content: flex-end; align-items: center;">
                            <button class="add-to-cart-btn" onclick="addToCart(<?php echo $p['id']; ?>)" tabindex="-1" <?php echo $p['stock'] <= 0 ? 'disabled' : ''; ?>>
                                <i class="fa-solid fa-plus" style="font-size:.75rem;"></i> Add
                            </button>
                        </div>
                        <?php if ($p['stock'] < 10 && $p['stock'] > 0): ?>
                            <div class="stock-warning">⚠️ Low stock: <?php echo $p['stock']; ?> left</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Keyboard Shortcuts Hint - Hidden on mobile -->
    <div class="keyboard-hint">
        <span><span class="key">F1</span> Search</span>
        <span><span class="key">F2</span> Category</span>
        <span><span class="key">F3</span> Cash</span>
        <span><span class="key">F8</span> Pay</span>
        <span><span class="key">F9</span> Print</span>
        <span><span class="key">ESC</span> Clear</span>
        <span><span class="key">↑↓</span> Products</span>
        <span><span class="key">Enter</span> Add</span>
        <span><span class="key">Tab</span> Cart Item</span>
        <span><span class="key">+/-</span> Cart Qty</span>
        <span><span class="key">Del</span> Remove</span>
    </div>

    <!-- Unit Selector Modal -->
    <div id="unitSelectorModal">
        <div class="modal-content">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
                <div>
                    <h3 id="usProductName" style="margin:0;color:#004d40;font-size:1.1rem"></h3>
                    <p id="usProductGeneric" style="margin:.2rem 0 0;font-size:.8rem;color:#718096"></p>
                </div>
                <button onclick="closeUnitSelector()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#718096">✕</button>
            </div>
            <p style="font-size:.8rem;color:#718096;margin-bottom:.8rem">Select selling unit:</p>
            <div id="usUnitList"></div>
        </div>
    </div>

    <!-- Receipt Modal -->
    <div id="receiptModal">
        <div class="modal-content">
            <div id="receipt-content"></div>
            <div style="display:flex; gap:10px; margin-top:14px;">
                <button onclick="printReceipt()" class="btn-primary" id="print-btn" tabindex="0" style="flex:1; padding:12px 8px; font-size:14px;">🖨️ Print (F9)</button>
                <button onclick="closeReceipt()" class="btn-primary" id="close-receipt-btn" tabindex="0" style="flex:1; padding:12px 8px; font-size:14px; background:#6b7280;">✕ Close (ESC)</button>
            </div>
        </div>
    </div>

    <script>
        // ============ POS ENGINE ============
        const products = <?php echo json_encode($products); ?>;
        window.cashierName = <?php echo json_encode($_SESSION['username'] ?? $_SESSION['user_name'] ?? 'Staff'); ?>;
        let cart = [];
        let quantities = {};
        let currentIndex = 0;
        let cartSelectedIndex = -1;
        let unitSelectorIndex = 0;
        let unitSelectorUnits = [];
        let pendingCartItem = null;

        // Initialize quantities for each product
        <?php foreach ($products as $p): ?>
            quantities[<?php echo $p['id']; ?>] = 1;
        <?php endforeach; ?>

        const TAX_RATE = 0.12;

        // Clock
        function updateClock() {
            const now = new Date();
            document.getElementById('clock').innerText = now.toLocaleTimeString();
        }
        setInterval(updateClock, 1000);
        updateClock();

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('search-input').focus();
            filterProducts();
            setTimeout(() => selectProduct(0), 100);
            
            document.getElementById('cash-input').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    completeTransaction();
                }
            });
        });

        function showError(message) {
            const container = document.getElementById('errorToastContainer');
            const toastId = 'toast-' + Date.now();
            
            const toast = document.createElement('div');
            toast.id = toastId;
            toast.className = 'error-toast';
            toast.innerHTML = `
                <i class="fa-solid fa-circle-exclamation"></i>
                <div style="flex:1;">${message}</div>
                <span class="close-toast" onclick="this.parentElement.remove()">✕</span>
            `;
            
            container.appendChild(toast);
            
            setTimeout(() => {
                const toastEl = document.getElementById(toastId);
                if (toastEl) toastEl.remove();
            }, 5000);
        }

        document.getElementById('cash-input')?.addEventListener('focus', function() {
            this.classList.remove('error');
            this.select();  // select all so typing replaces immediately
        });

        function validateCashInput() {
            const cashInput = document.getElementById('cash-input');
            const totals = calculateTotals();
            const cash = parseFloat(cashInput.value) || 0;
            
            if (cash < totals.grandTotal && cart.length > 0) {
                cashInput.classList.add('error');
                return false;
            } else {
                cashInput.classList.remove('error');
                return true;
            }
        }

        // ============ KEYBOARD NAVIGATION ============
        document.addEventListener('keydown', function(e) {
            const modal = document.getElementById('receiptModal');
            const unitModal = document.getElementById('unitSelectorModal');
            const rxModal = document.getElementById('rxApprovalModal');
            const visibleCards = document.querySelectorAll('.product-card:not([style*="display: none"])');

            // ── Handle keys while Rx approval modal is open ──
            if (rxModal && rxModal.style.display === 'flex') {
                if (e.key === 'Escape') {
                    e.preventDefault();
                    e.stopPropagation();
                    _closeRxModal();
                    return;
                }
                if (e.key === 'Enter') {
                    e.preventDefault();
                    e.stopPropagation();
                    _rxEnterKey();
                    return;
                }
                // Block all other POS hotkeys
                if (['ArrowUp','ArrowDown','F1','F2','F3','F8','+','=','-','_','Delete','Tab'].includes(e.key)) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                return;
            }

            if (unitModal.style.display === 'flex') {
                e.preventDefault();
                const unitRows = document.querySelectorAll('#usUnitList .unit-sel-row');
                
                switch(e.key) {
                    case 'ArrowUp':
                        unitSelectorIndex = Math.max(0, unitSelectorIndex - 1);
                        highlightUnitRow(unitRows);
                        break;
                    case 'ArrowDown':
                        unitSelectorIndex = Math.min(unitRows.length - 1, unitSelectorIndex + 1);
                        highlightUnitRow(unitRows);
                        break;
                    case 'Enter':
                        if (unitRows[unitSelectorIndex]) {
                            unitRows[unitSelectorIndex].click();
                        }
                        break;
                    case 'Escape':
                        closeUnitSelector();
                        break;
                }
                return;
            }
            
            if (modal.style.display === 'flex') {
                if (e.key === 'F9') {
                    e.preventDefault();
                    printReceipt();
                } else if (e.key === 'Escape') {
                    e.preventDefault();
                    closeReceipt();
                }
                return;
            }
            
            switch(e.key) {

                // ── F1: Focus Search ──────────────────────────────
                case 'F1':
                    e.preventDefault();
                    document.getElementById('search-input').focus();
                    break;

                // ── F2: Open Category Filter ──────────────────────
                case 'F2':
                    e.preventDefault();
                    {
                        const cf = document.getElementById('category-filter');
                        cf.focus();
                        try {
                            cf.showPicker();
                        } catch(err) {
                            // fallback for browsers that don't support showPicker on select
                            cf.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
                            cf.click();
                        }
                    }
                    break;

                // ── F3: Focus Cash Input ──────────────────────────
                case 'F3':
                    e.preventDefault();
                    document.getElementById('cash-input').focus();
                    break;

                // ── F8: Complete / Pay ────────────────────────────
                case 'F8':
                    e.preventDefault();
                    completeTransaction();
                    break;

                // ── ESC: Clear cart (with confirm bar) ────────────
                case 'Escape':
                    e.preventDefault();
                    if (document.getElementById('clearConfirmBar')) {
                        doClearCart();
                    } else {
                        clearCart();
                    }
                    break;

                // ── Enter: Add highlighted/first product to cart ──
                case 'Enter':
                    e.preventDefault();
                    if (document.getElementById('clearConfirmBar')) {
                        doClearCart();
                        break;
                    }
                    {
                        const highlighted = document.querySelector('.product-card.selected:not([style*="display: none"])');
                        if (highlighted) {
                            addToCart(parseInt(highlighted.dataset.id));
                            break;
                        }
                        if (e.target.id === 'search-input') {
                            let searchValue = e.target.value.trim();
                            let barcodeMatch = products.find(p => p.barcode == searchValue);
                            if (barcodeMatch) {
                                addToCart(barcodeMatch.id);
                                e.target.value = '';
                                filterProducts();
                                break;
                            }
                        }
                        const firstVisible = document.querySelector('.product-card:not([style*="display: none"])');
                        if (firstVisible) {
                            addToCart(parseInt(firstVisible.dataset.id));
                        }
                    }
                    break;

                // ── ↑↓: Navigate product grid ─────────────────────
                case 'ArrowUp':
                    e.preventDefault();
                    {
                        const liveCards = Array.from(document.querySelectorAll('.product-card:not([style*="display: none"])'));
                        if (liveCards.length) {
                            const selIdx = liveCards.findIndex(c => c.classList.contains('selected'));
                            currentIndex = Math.max(0, (selIdx < 0 ? 0 : selIdx) - 1);
                            selectProduct(currentIndex);
                        }
                    }
                    break;

                case 'ArrowDown':
                    e.preventDefault();
                    {
                        const liveCards = Array.from(document.querySelectorAll('.product-card:not([style*="display: none"])'));
                        if (liveCards.length) {
                            const selIdx = liveCards.findIndex(c => c.classList.contains('selected'));
                            currentIndex = Math.min(liveCards.length - 1, (selIdx < 0 ? -1 : selIdx) + 1);
                            selectProduct(currentIndex);
                        }
                    }
                    break;

                // ── Tab / Shift+Tab: Cycle selected cart item ─────
                case 'Tab':
                    if (cart.length > 0) {
                        e.preventDefault();
                        if (e.shiftKey) {
                            cartSelectedIndex = cartSelectedIndex <= 0 ? cart.length - 1 : cartSelectedIndex - 1;
                        } else {
                            cartSelectedIndex = cartSelectedIndex >= cart.length - 1 ? 0 : cartSelectedIndex + 1;
                        }
                        renderCart();
                        const cartEls = document.querySelectorAll('.cart-item');
                        if (cartEls[cartSelectedIndex]) cartEls[cartSelectedIndex].scrollIntoView({block:'nearest'});
                    }
                    break;

                // ── +/=: Increase qty of selected cart item ───────
                case '+':
                case '=':
                    e.preventDefault();
                    if (cart.length > 0 && cartSelectedIndex >= 0 && cart[cartSelectedIndex]) {
                        const ckPlus = cart[cartSelectedIndex].cartKey || cart[cartSelectedIndex].id;
                        updateCartQty(ckPlus, 1);
                    }
                    break;

                // ── -/_: Decrease qty of selected cart item ───────
                case '-':
                case '_':
                    e.preventDefault();
                    if (cart.length > 0 && cartSelectedIndex >= 0 && cart[cartSelectedIndex]) {
                        const ckMinus = cart[cartSelectedIndex].cartKey || cart[cartSelectedIndex].id;
                        updateCartQty(ckMinus, -1);
                    }
                    break;

                // ── Delete: Remove selected cart item ─────────────
                case 'Delete':
                    e.preventDefault();
                    if (cart.length > 0 && cartSelectedIndex >= 0 && cart[cartSelectedIndex]) {
                        const cartKey = cart[cartSelectedIndex].cartKey || cart[cartSelectedIndex].id;
                        removeFromCart(cartKey);
                    }
                    break;
            }
            
            validateCashInput();
        });

        function selectProduct(index) {
            const cards = Array.from(document.querySelectorAll('.product-card:not([style*="display: none"])'));
            cards.forEach(c => c.classList.remove('selected'));
            if (cards[index]) {
                cards[index].classList.add('selected');
                cards[index].focus();
                currentIndex = index;
            }
        }

        function highlightUnitRow(rows) {
            rows.forEach((row, idx) => {
                if (idx === unitSelectorIndex) {
                    row.style.background = '#e8f3f0';
                    row.style.borderColor = '#004d40';
                } else {
                    row.style.background = '';
                    row.style.borderColor = '#edf2f7';
                }
            });
        }

        function closeUnitSelector() {
            document.getElementById('unitSelectorModal').style.display = 'none';
            unitSelectorIndex = 0;
            pendingCartItem = null;
            document.getElementById('search-input').focus();
        }

        // ============ CART FUNCTIONS ============
        function adjustQty(productId, change) {
            if (!quantities[productId]) quantities[productId] = 1;
            let newQty = quantities[productId] + change;
            if (newQty >= 1 && newQty <= 99) {
                quantities[productId] = newQty;
                let el = document.getElementById(`qty-${productId}`);
                if (el) el.textContent = newQty;
            }
        }

        let productUnitsCache = {};

        async function addToCart(productId) {
            let product = products.find(p => p.id == productId);
            if (!product) { 
                showError('Product not found');
                return; 
            }

            if (product.stock <= 0) {
                showError(`${product.brand_name} is out of stock`);
                return;
            }

            if (!productUnitsCache[productId]) {
                try {
                    const res = await fetch(`ajax/get_product_units.php?product_id=${productId}`);
                    const data = await res.json();
                    productUnitsCache[productId] = (data.units && data.units.length > 0
                        ? data.units.sort((a, b) => a.conversion_to_base - b.conversion_to_base)
                        : [{unit_name: product.base_unit || 'piece', conversion_to_base: 1, price_override: null}]);
                } catch(e) {
                    console.error('Error fetching units:', e);
                    productUnitsCache[productId] = [{unit_name: product.base_unit || 'piece', conversion_to_base: 1, price_override: null}];
                }
            }
            
            const units = productUnitsCache[productId];
            const defaultUnit = units[0].unit_name;
            const conversionToBase = units[0].conversion_to_base;
            // Use the FIFO batch price (first batch that will actually be sold).
            // Falls back to product.price only if no batch price is set.
            const batchPrice = parseFloat(product.fifo_price ?? product.price);
            let unitPrice = units[0].price_override !== null
                ? parseFloat(units[0].price_override)
                : batchPrice * units[0].conversion_to_base;

            const basePrice = parseFloat(product.fifo_price ?? product.price);
            if (!unitPrice) unitPrice = basePrice * (conversionToBase || 1);
            if (!conversionToBase) conversionToBase = 1;
            if (!defaultUnit) defaultUnit = product.base_unit || 'piece';

            const qty = quantities[productId] || 1;
            const qtyInBase = qty * conversionToBase;

            if (qtyInBase > product.stock) {
                showError(`Only ${product.stock} base units available for ${product.brand_name}. You need ${qtyInBase}.`);
                return;
            }

            const cartKey = `${productId}_${defaultUnit}`;
            let existingItem = cart.find(i => i.cartKey === cartKey);

            if (existingItem) {
                const newQtyBase = (existingItem.quantity_in_base || existingItem.quantity) + qtyInBase;
                if (newQtyBase > product.stock) { 
                    showError(`Not enough stock for ${product.brand_name}. Only ${product.stock} base units available.`); 
                    return; 
                }
                existingItem.quantity_sold += qty;
                existingItem.quantity_in_base = newQtyBase;
                existingItem.subtotal = existingItem.quantity_sold * unitPrice;
            } else {
                cart.push({
                    requires_prescription: parseInt(product.requires_prescription) === 1,
                    id: product.id,
                    cartKey: cartKey,
                    name: product.brand_name,
                    generic: product.generic_name,
                    unit_sold: defaultUnit,
                    conversion_to_base: conversionToBase,
                    unit_price: unitPrice,
                    price: unitPrice,
                    quantity_sold: qty,
                    quantity_in_base: qtyInBase,
                    quantity: qty,
                    vat_type: product.vat_type || 'vatable',
                    subtotal: unitPrice * qty,
                    available_units: units
                });
            }

            quantities[productId] = 1;
            const qtySpan = document.getElementById(`qty-${productId}`);
            if (qtySpan) qtySpan.textContent = '1';
            renderCart();
        }

        function editCartItemUnit(cartKey) {
            const item = cart.find(i => (i.cartKey || i.id) == cartKey);
            if (!item) return;
            
            const product = products.find(p => p.id == item.id);
            if (!product) return;
            
            if (!item.available_units && !productUnitsCache[product.id]) {
                fetch(`ajax/get_product_units.php?product_id=${product.id}`)
                    .then(res => res.json())
                    .then(data => {
                        productUnitsCache[product.id] = (data.units && data.units.length > 0
                            ? data.units.sort((a, b) => a.conversion_to_base - b.conversion_to_base)
                            : [{unit_name: product.base_unit || 'piece', conversion_to_base: 1, price_override: null}]);
                        
                        pendingCartItem = {
                            cartKey: cartKey,
                            currentQty: item.quantity_sold || item.quantity || 1,
                            currentUnit: item.unit_sold
                        };
                        
                        showUnitSelectorForCart(product, productUnitsCache[product.id]);
                    })
                    .catch(e => {
                        showError('Error loading units');
                    });
            } else {
                const units = item.available_units || productUnitsCache[product.id] || 
                             [{unit_name: product.base_unit || 'piece', conversion_to_base: 1, price_override: null}];
                
                pendingCartItem = {
                    cartKey: cartKey,
                    currentQty: item.quantity_sold || item.quantity || 1,
                    currentUnit: item.unit_sold
                };
                showUnitSelectorForCart(product, units);
            }
        }

        function showUnitSelectorForCart(product, units) {
            unitSelectorUnits = units;
            unitSelectorIndex = 0;
            const qty = pendingCartItem ? pendingCartItem.currentQty : 1;
            const basePrice = parseFloat(product.fifo_price ?? product.price);

            let rows = units.map((u, idx) => {
                const up = u.price_override !== null ? parseFloat(u.price_override) : basePrice * u.conversion_to_base;
                return `
                    <div class="unit-sel-row" onclick="selectUnitForCart(${product.id},'${u.unit_name}',${u.conversion_to_base},${up})" data-index="${idx}">
                        <div>
                            <strong>${u.unit_name}</strong>
                            <small style="color:#718096"> = ${u.conversion_to_base} ${product.base_unit || 'pc'}</small>
                        </div>
                        <div style="text-align:right">
                            <strong style="color:#004d40">₱${up.toFixed(2)}</strong>
                            <div style="font-size:.72rem;color:#718096">per ${u.unit_name}</div>
                        </div>
                    </div>`;
            }).join('');

            document.getElementById('usProductName').textContent = product.brand_name;
            document.getElementById('usProductGeneric').textContent = `${product.generic_name} | Qty: ${qty}`;
            document.getElementById('usUnitList').innerHTML = rows;
            document.getElementById('unitSelectorModal').style.display = 'flex';
            
            setTimeout(() => {
                const unitRows = document.querySelectorAll('#usUnitList .unit-sel-row');
                unitSelectorIndex = 0;
                highlightUnitRow(unitRows);
            }, 50);
        }

        function selectUnitForCart(productId, unitName, conversionToBase, unitPrice) {
            if (!pendingCartItem) {
                closeUnitSelector();
                return;
            }
            
            if (unitName === pendingCartItem.currentUnit) {
                closeUnitSelector();
                return;
            }
            
            const itemIndex = cart.findIndex(i => (i.cartKey || i.id) == pendingCartItem.cartKey);
            if (itemIndex === -1) {
                closeUnitSelector();
                return;
            }
            
            const item = cart[itemIndex];
            const product = products.find(p => p.id == productId);
            if (!product) {
                closeUnitSelector();
                return;
            }
            
            const newQty = pendingCartItem.currentQty;
            const newQtyInBase = newQty * conversionToBase;
            
            if (newQtyInBase > product.stock) {
                showError(`Only ${product.stock} base units available for ${product.brand_name}. You need ${newQtyInBase}.`);
                return;
            }
            
            const newCartKey = `${productId}_${unitName}`;
            
            const existingItem = cart.find(i => (i.cartKey || i.id) == newCartKey && i !== item);
            if (existingItem) {
                existingItem.quantity_sold += newQty;
                existingItem.quantity_in_base += newQtyInBase;
                existingItem.subtotal = existingItem.quantity_sold * unitPrice;
                
                cart.splice(itemIndex, 1);
            } else {
                item.cartKey = newCartKey;
                item.unit_sold = unitName;
                item.conversion_to_base = conversionToBase;
                item.unit_price = unitPrice;
                item.price = unitPrice;
                item.quantity_in_base = newQtyInBase;
                item.subtotal = newQty * unitPrice;
            }
            
            closeUnitSelector();
            renderCart();
        }

        function removeFromCart(cartKey) {
            cart = cart.filter(i => (i.cartKey || i.id) != cartKey);
            renderCart();
        }

        function clearCart() {
            if (cart.length === 0) return;

            // Remove any existing confirm bar
            var existing = document.getElementById('clearConfirmBar');
            if (existing) { existing.remove(); return; }

            var bar = document.createElement('div');
            bar.id = 'clearConfirmBar';
            bar.style.cssText = [
                'position:fixed','bottom:80px','left:50%','transform:translateX(-50%)',
                'background:#1e293b','color:#fff','padding:12px 20px','border-radius:12px',
                'display:flex','align-items:center','gap:12px','z-index:9999',
                'box-shadow:0 8px 24px rgba(0,0,0,.35)','font-size:.9rem',
                'animation:slideInUp .2s ease'
            ].join(';');
            bar.innerHTML =
                '<span><i class="fa-solid fa-trash" style="color:#f87171;margin-right:6px;"></i>Clear all items?</span>' +
                '<button onclick="doClearCart()" style="background:#ef4444;color:#fff;border:none;padding:6px 16px;border-radius:8px;cursor:pointer;font-weight:700;"><i class="fa-solid fa-check" style="margin-right:4px;"></i>Yes <kbd style="background:rgba(255,255,255,.2);border-radius:4px;padding:1px 5px;font-size:.75rem;">Enter</kbd></button>' +
                '<button onclick="document.getElementById(&quot;clearConfirmBar&quot;).remove()" style="background:#334155;color:#fff;border:none;padding:6px 14px;border-radius:8px;cursor:pointer;">Cancel <kbd style="background:rgba(255,255,255,.2);border-radius:4px;padding:1px 5px;font-size:.75rem;">ESC</kbd></button>';

            document.body.appendChild(bar);
            // Auto-dismiss after 4s
            setTimeout(function() { var b = document.getElementById('clearConfirmBar'); if (b) b.remove(); }, 4000);
        }

        function doClearCart() {
            var bar = document.getElementById('clearConfirmBar');
            if (bar) bar.remove();
            cart = [];
            document.getElementById('cash-input').value = '';
            document.getElementById('cash-input').classList.remove('error');
            renderCart();
            document.getElementById('search-input').focus();
        }

        // ============ RENDER FUNCTIONS ============
        function calculateTotals() {
            let vatableSalesInclVAT = 0;
            let vatExemptSales = 0;
            let vatZeroRatedSales = 0;

            cart.forEach(item => {
                // Ensure subtotal is a number
                let sub = parseFloat(item.subtotal || 0);
                if (isNaN(sub)) sub = 0;
                
                if (item.vat_type === 'exempt') vatExemptSales += sub;
                else if (item.vat_type === 'zero_rated') vatZeroRatedSales += sub;
                else vatableSalesInclVAT += sub;
            });

            // Ensure all calculations use numbers
            vatableSalesInclVAT = parseFloat(vatableSalesInclVAT) || 0;
            vatExemptSales = parseFloat(vatExemptSales) || 0;
            vatZeroRatedSales = parseFloat(vatZeroRatedSales) || 0;

            const vatSalesBase = vatableSalesInclVAT / 1.12;
            const vatAmount = vatableSalesInclVAT - vatSalesBase;
            const grandTotal = vatableSalesInclVAT + vatExemptSales + vatZeroRatedSales;

            return {
                vatableSalesInclVAT: vatableSalesInclVAT,
                vatSalesBase: parseFloat(vatSalesBase.toFixed(2)),
                vatAmount: parseFloat(vatAmount.toFixed(2)),
                vatExemptSales: vatExemptSales,
                vatZeroRatedSales: vatZeroRatedSales,
                grandTotal: parseFloat(grandTotal.toFixed(2)),
                subtotal: parseFloat(grandTotal.toFixed(2)),
                tax: parseFloat(vatAmount.toFixed(2))
            };
        }

        function renderCart() {
            let container = document.getElementById('cart-container');
            let totals = calculateTotals();
            
            if (cart.length === 0) {
                container.innerHTML = `
                    <div class="empty-cart">
                        <span>🛒</span>
                        <h3>Cart is empty</h3>
                        <p>Use ↑↓ to select products, Enter to add</p>
                    </div>`;
                cartSelectedIndex = -1;
            } else {
                let html = '';
                cart.forEach((item, idx) => {
                    const cartKey = item.cartKey || item.id;
                    const selectedClass = idx === cartSelectedIndex ? 'selected-cart-item' : '';
                    html += `
                        <div class="cart-item ${selectedClass}" onclick="selectCartItem(${idx})">
                            <div class="cart-item-info">
                                <h4>${escapeHtml(item.name)}${item.requires_prescription ? '<span class="rx-cart-pill"><i class="fa-solid fa-prescription-bottle-medical"></i> Rx</span>' : ''}</h4>
                                <p style="font-size:.75rem;color:#64748b">${escapeHtml(item.generic)}</p>
                                <div style="font-size:.72rem;color:#0288d1;margin-top:2px;display:flex;align-items:center;flex-wrap:wrap;">
                                    <span class="unit-badge" onclick="event.stopPropagation(); editCartItemUnit('${cartKey}')" title="Click to change unit">
                                        ${escapeHtml(item.unit_sold || 'piece')} <i class="fa-solid fa-pencil" style="font-size:0.6rem;margin-left:2px;"></i>
                                    </span>
                                    <span style="margin-left:5px;">× ₱${(item.unit_price||item.price).toFixed(2)}</span>
                                    ${item.conversion_to_base > 1 ? `<span style="color:#94a3b8;margin-left:5px;">(${item.quantity_in_base || item.quantity} base)</span>` : ''}
                                </div>
                            </div>
                            <div style="display: flex; align-items: center; gap: 12px;">
                                <div class="cart-item-qty">
                                    <button onclick="event.stopPropagation(); updateCartQty('${cartKey}', -1)">−</button>
                                    <span>${item.quantity_sold || item.quantity}</span>
                                    <button onclick="event.stopPropagation(); updateCartQty('${cartKey}', 1)">+</button>
                                </div>
                                <div class="cart-item-price">₱${item.subtotal.toFixed(2)}</div>
                                <button onclick="event.stopPropagation(); removeFromCart('${cartKey}')" style="background:none;border:none;color:#d32f2f;cursor:pointer;font-size:1.1rem;">✕</button>
                            </div>
                        </div>
                    `;
                });
                container.innerHTML = html;
            }
            
            const vatBaseEl = document.getElementById('vat-sales-base');
            if (vatBaseEl) vatBaseEl.textContent = totals.vatSalesBase.toFixed(2);
            const vatExEl = document.getElementById('vat-exempt');
            if (vatExEl) vatExEl.textContent = totals.vatExemptSales.toFixed(2);
            document.getElementById('tax').textContent = totals.vatAmount.toFixed(2);
            document.getElementById('grand-total').textContent = totals.grandTotal.toFixed(2);
            calculateChange();
            validateCashInput();
        }

        function selectCartItem(index) {
            cartSelectedIndex = index;
            renderCart();
        }

        function updateCartQty(cartKey, change) {
            let item = cart.find(i => (i.cartKey || i.id) == cartKey);
            if (!item) return;
            const product = products.find(p => p.id == item.id);
            const qtySold = (item.quantity_sold || item.quantity) + change;
            if (qtySold <= 0) { removeFromCart(cartKey); return; }
            const newBase = qtySold * (item.conversion_to_base || 1);
            if (newBase > (product ? product.stock : 9999)) { 
                showError(`Not enough stock for ${product.brand_name}. Only ${product.stock} base units available.`); 
                return; 
            }
            item.quantity_sold = qtySold;
            item.quantity = qtySold;
            item.quantity_in_base = newBase;
            item.subtotal = (item.unit_price || item.price) * qtySold;
            renderCart();
        }

        function calculateChange() {
            let totals = calculateTotals();
            let cash = parseFloat(document.getElementById('cash-input').value) || 0;
            document.getElementById('change-amount').textContent = Math.max(0, cash - totals.grandTotal).toFixed(2);
        }

        // ============ TRANSACTION FUNCTIONS ============
        async function completeTransaction() {
            // If cart has Rx items and pharmacist not yet verified, show Rx modal
            if (cartHasRxItems() && !window.approvedPharmacist) {
                _openRxModal();
                return;
            }
            await _doCompleteSale();
        }

        // end completeTransaction

        // FIXED: _doCompleteSale with proper number parsing
        async function _doCompleteSale() {
            if (cart.length === 0) {
                showError('Cart is empty');
                return;
            }
            
            let cash = parseFloat(document.getElementById('cash-input').value) || 0;
            let totals = calculateTotals();
            
            if (cash < totals.grandTotal) {
                showError('Insufficient cash amount');
                document.getElementById('cash-input').classList.add('error');
                document.getElementById('cash-input').focus();
                return;
            }

            // Ensure all subtotals are properly parsed as numbers
            let saleData = {
                items: cart.map(i => {
                    // Parse and validate all numeric values
                    let quantity = parseInt(i.quantity_sold || i.quantity || 1);
                    let unitPrice = parseFloat(i.unit_price || i.price || 0);
                    let subtotal = parseFloat(i.subtotal || (quantity * unitPrice));
                    
                    return {
                        id: i.id,
                        unit_sold: i.unit_sold || i.unit || 'piece',
                        quantity_sold: quantity,
                        quantity_in_base: parseInt(i.quantity_in_base || i.quantity || 1),
                        unit_price: unitPrice,
                        subtotal: subtotal,
                        vat_type: i.vat_type || 'vatable',
                        name: i.name
                    };
                }),
                total_amount: parseFloat(totals.grandTotal),
                discount: 0,
                tax: parseFloat(totals.vatAmount),
                grand_total: parseFloat(totals.grandTotal),
                cash_received: parseFloat(cash),
                change_amount: parseFloat(cash - totals.grandTotal),
                vat_sales: parseFloat(totals.vatSalesBase),
                vat_exempt_sales: parseFloat(totals.vatExemptSales),
                vat_zero_rated_sales: parseFloat(totals.vatZeroRatedSales),
                vat_amount: parseFloat(totals.vatAmount)
            };

            try {
                let res = await fetch('actions/pos_process.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(saleData)
                });
                
                if (!res.ok) {
                    throw new Error(`HTTP error! status: ${res.status}`);
                }
                
                let result = await res.json();
                
                if (result.success) {
                    // Use actual sale items returned by server (correct per-batch prices)
                    showReceipt({
                        ...saleData,
                        items: result.receipt_items || saleData.items,
                        sale_id: result.sale_id,
                        receipt_number: result.receipt_number || generateReceiptNumber()
                    });
                    
                    cart.forEach(item => {
                        let product = products.find(p => p.id == item.id);
                        if (product) product.stock -= (item.quantity_in_base || item.quantity);
                    });
                    
                    cart = [];
                    renderCart();
                    filterProducts();
                    document.getElementById('cash-input').value = '';
                    document.getElementById('cash-input').classList.remove('error');
                    document.getElementById('search-input').focus();
                } else {
                    showError('Error: ' + (result.message || 'Unknown error occurred'));
                }
            } catch (e) {
                console.error('Transaction error:', e);
                showError('Transaction failed: ' + e.message);
            }
        }

        // end _doCompleteSale

        function generateReceiptNumber() {
            const date = new Date();
            return 'INV-' + date.getFullYear() + 
                   ('0' + (date.getMonth() + 1)).slice(-2) + 
                   ('0' + date.getDate()).slice(-2) + 
                   '-' + 
                   Math.floor(Math.random() * 10000).toString().padStart(4, '0');
        }

        function generate58mmReceipt(saleData) {
            const date = new Date();
            const posDate = date.toLocaleDateString('en-US', {month:'short', day:'numeric', year:'numeric'});
            const dayStr = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][date.getDay()];
            const receiptNo = saleData.receipt_number || generateReceiptNumber();
            const cashier = '<?php echo htmlspecialchars(addslashes($_SESSION['full_name'] ?? 'Cashier')); ?>';
            const posSN = saleData.pos_sn || ('S4-' + Math.floor(Math.random()*90000000+10000000));
            const tmNum = saleData.tm_number || ('TM#' + String(Math.floor(Math.random()*9999)+1).padStart(4,'0'));
            const totals = calculateTotals();

            const timeStr = date.toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit'});

            const itemLines = saleData.items.map(item => {
                const qty = item.quantity_sold || item.quantity || 1;
                const unitSold = (item.unit_sold || 'pc').substring(0, 3).toUpperCase();
                const up = (item.unit_price || item.price || 0);
                const sub = item.subtotal || (up * qty);
                const name = (item.name || '');
                const vatTag = item.vat_type === 'exempt' ? ' [VE]' : item.vat_type === 'zero_rated' ? ' [0%]' : '';

                return `
                    <div style="margin-bottom:3px; width:100%; font-size:8px;">
                        <div style="word-break:break-word; font-weight:bold; margin-bottom:1px;">${name}${vatTag}</div>
                        <div style="display:flex; align-items:baseline;">
                            <span style="flex:1; color:#333;">${qty} ${unitSold} @ ₱${up.toFixed(2)}</span>
                            <span style="width:60px; text-align:right; font-weight:bold;">₱${sub.toFixed(2)}</span>
                        </div>
                    </div>
                `;
            }).join('');

            const totalItems = saleData.items.reduce((s,i) => s + (i.quantity_sold || i.quantity || 0), 0);

            return `
                <div class="receipt-58mm">

                    <!-- HEADER -->
                    <div style="text-align:center; border-bottom:2px solid #000; padding-bottom:3px; margin-bottom:3px;">
                        <div style="font-size:11px; font-weight:bold; letter-spacing:1px; margin-bottom:2px;">CAD CREST</div>
                        <div style="font-size:9px; font-weight:bold; margin:1px 0;">Pharmaceutical</div>
                        <div style="font-size:8px; margin:1px 0;">123 Main Street, City, Philippines</div>
                        <div style="font-size:8px; margin:1px 0;">Tel: 456-7890</div>
                        <div style="font-size:8px; font-weight:bold; margin-top:2px;">POS: ${posSN}</div>
                        <div style="font-size:9px; font-weight:bold; margin-top:3px; background:#000; color:#fff; padding:2px 0; letter-spacing:1px;">OFFICIAL RECEIPT</div>
                    </div>

                    <!-- TRANSACTION INFO -->
                    <div style="text-align:center; font-size:8px; margin-bottom:4px; border-bottom:1px dashed #000; padding-bottom:3px;">
                        <div style="font-weight:bold;">${posDate} &nbsp; ${dayStr}</div>
                        <div>${timeStr} &nbsp;&nbsp; ${tmNum}</div>
                    </div>

                    <!-- ITEMS HEADER -->
                    <div style="display:flex; justify-content:space-between; font-size:8px; font-weight:bold; border-bottom:1px solid #000; margin-bottom:3px; padding-bottom:2px;">
                        <span style="flex:1;">ITEM</span>
                        <span style="width:32px; text-align:center;">QTY</span>
                        <span style="width:60px; text-align:right;">AMOUNT</span>
                    </div>

                    <!-- ITEMS SECTION -->
                    <div style="margin:3px 0;">
                        ${itemLines}
                    </div>

                    <!-- TOTALS SECTION -->
                    <div style="border-top:1px solid #000; padding-top:3px; margin-top:3px;">
                        <div style="display:flex; justify-content:space-between; font-size:8px; margin:2px 0;">
                            <span>Subtotal:</span>
                            <span>₱${totals.grandTotal.toFixed(2)}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:8px; margin:2px 0;">
                            <span>Cash:</span>
                            <span>₱${(saleData.cash_received||0).toFixed(2)}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; font-size:10px; font-weight:bold; border-top:1px solid #000; border-bottom:1px solid #000; padding:2px 0; margin:3px 0;">
                            <span>CHANGE:</span>
                            <span>₱${Math.max(0,saleData.change_amount||0).toFixed(2)}</span>
                        </div>
                    </div>

                    <!-- VAT BREAKDOWN -->
                    <div style="border-top:1px dashed #000; margin-top:3px; padding-top:3px; font-size:7.5px;">
                        <div style="font-weight:bold; margin-bottom:2px;">VAT BREAKDOWN:</div>
                        <div style="display:flex; justify-content:space-between; margin-bottom:1px;">
                            <span>VATable Sales (excl. VAT):</span>
                            <span>₱${totals.vatSalesBase.toFixed(2)}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; margin-bottom:1px;">
                            <span>VAT (12%):</span>
                            <span>₱${totals.vatAmount.toFixed(2)}</span>
                        </div>
                        <div style="display:flex; justify-content:space-between; margin-bottom:1px;">
                            <span>VAT Exempt:</span>
                            <span>₱${totals.vatExemptSales.toFixed(2)}</span>
                        </div>
                    </div>

                    <!-- FOOTER -->
                    <div style="text-align:center; border-top:1px solid #000; margin-top:3px; padding-top:3px;">
                        <div style="font-size:8px; font-weight:bold; margin-bottom:1px;">Trans #: ${saleData.sale_id || receiptNo}</div>
                        <div style="font-size:8px; margin-bottom:2px;">Items: ${totalItems} | Cashier: ${cashier}</div>
                        <div style="font-size:9px; font-weight:bold; margin:3px 0; letter-spacing:1px;">THANK YOU!</div>
                        <div style="font-size:7.5px; margin-top:2px;">Items sold are non-returnable</div>
                        <div style="font-size:7.5px; margin-top:3px;">${'*'.repeat(40)}</div>
                    </div>
                </div>
            `;
        }

        function showReceipt(saleData) {
            const receiptHTML = generate58mmReceipt(saleData);
            document.getElementById('receipt-content').innerHTML = receiptHTML;
            document.getElementById('receiptModal').style.display = 'flex';
            
            setTimeout(() => {
                document.getElementById('print-btn').focus();
            }, 100);
        }

        function printReceipt() {
            window.print();
        }

        function closeReceipt() {
            document.getElementById('receiptModal').style.display = 'none';
            document.getElementById('search-input').focus();
        }

        // ============ FILTER FUNCTIONS ============
        function filterProducts() {
            const search     = (document.getElementById('search-input')?.value || '').toLowerCase().trim();
            const categoryId = document.getElementById('category-filter')?.value || '';
            let visible = 0;

            const cards = Array.from(document.querySelectorAll('.product-card'));

            // ── Filter ────────────────────────────────────────────
            cards.forEach((card) => {
                const name       = (card.dataset.name     || '').toLowerCase();
                const barcode    = (card.dataset.barcode   || '').toLowerCase();
                const catId      = card.dataset.categoryId || '';
                const baseUnit   = (card.dataset.baseUnit  || '').toLowerCase();
                const dosage     = (card.dataset.dosageForm|| '').toLowerCase();

                const matchesSearch = !search ||
                    name.includes(search)    ||
                    barcode.includes(search) ||
                    baseUnit.includes(search)||
                    dosage.includes(search);

                const matchesCategory = !categoryId || catId === categoryId;

                const show = matchesSearch && matchesCategory;
                card.style.display = show ? 'block' : 'none';
                if (show) visible++;
            });

            let noRes = document.getElementById('posNoResults');
            if (visible === 0 && (search || categoryId)) {
                if (!noRes) {
                    noRes = document.createElement('div');
                    noRes.id = 'posNoResults';
                    noRes.style.cssText = 'grid-column:1/-1;text-align:center;padding:3rem;color:#94a3b8;';
                    noRes.innerHTML = '<i class="fa-solid fa-search" style="font-size:2.5rem;margin-bottom:1rem;display:block;"></i><p>No products match your search.</p>';
                    document.getElementById('product-grid')?.appendChild(noRes);
                }
                noRes.style.display = '';
            } else if (noRes) {
                noRes.style.display = 'none';
            }
        }

        // ============ UTILITIES ============
        function escapeHtml(text) {
            let div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ============ EVENT LISTENERS ============
        document.getElementById('search-input')?.addEventListener('input', function() {
            filterProducts();
        });
        
        document.getElementById('category-filter')?.addEventListener('change', filterProducts);
        document.getElementById('cash-input')?.addEventListener('input', function() {
            calculateChange();
            validateCashInput();
        });
    </script>
    <?php renderNotificationPanel($conn); ?>

    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <!-- PRESCRIPTION APPROVAL MODAL                                         -->
    <!-- ═══════════════════════════════════════════════════════════════════ -->
    <div id="rxApprovalModal">
        <div class="rx-modal-box">
            <div class="rx-modal-hdr">
                <h3><i class="fa-solid fa-prescription-bottle-medical"></i> Pharmacist Verification Required</h3>
                <p>One or more items in this sale require a valid prescription. A licensed pharmacist must approve before checkout can proceed.</p>
            </div>
            <div class="rx-modal-body">

                <!-- List of Rx items in the cart -->
                <div class="rx-rx-list">
                    <strong><i class="fa-solid fa-triangle-exclamation"></i>&nbsp; Prescription Items:</strong>
                    <ul id="rxItemList"></ul>
                </div>

                <div id="rxErr" class="rx-err"></div>
                <div id="rxOk"  class="rx-ok"></div>

                <div class="rx-field">
                    <label><i class="fa-solid fa-user-doctor"></i>&nbsp; Pharmacist Username</label>
                    <input type="text" id="rxUser" placeholder="Enter pharmacist username" autocomplete="off"
                           tabindex="1"
                           onkeydown="if(event.key==='Enter'||event.key==='Tab'){event.preventDefault();document.getElementById('rxPass').focus();}">
                </div>
                <div class="rx-field">
                    <label><i class="fa-solid fa-lock"></i>&nbsp; Password</label>
                    <input type="password" id="rxPass" placeholder="Enter password"
                           tabindex="2"
                           onkeydown="if(event.key==='Enter') _rxEnterKey()">
                </div>

                <div class="rx-btns">
                    <button class="rx-btn rx-btn-cancel"  onclick="_closeRxModal()">
                        <i class="fa-solid fa-xmark"></i> Cancel
                    </button>
                    <button class="rx-btn rx-btn-verify"  id="rxVerifyBtn" onclick="_rxVerify()">
                        <i class="fa-solid fa-shield-halved"></i> Verify
                    </button>
                    <button class="rx-btn rx-btn-proceed" id="rxProceedBtn" onclick="_rxProceed()">
                        <i class="fa-solid fa-circle-check"></i> Proceed to Payment
                        <span style="font-size:.72rem;opacity:.8;margin-left:4px;">(Enter)</span>
                    </button>
                </div>

            </div>
        </div>
    </div>

    <script>
    // ════════════════════════════════════════════════════════════════════════
    // PRESCRIPTION WORKFLOW — Rx approval state & modal controller
    // ════════════════════════════════════════════════════════════════════════

    window.approvedPharmacist = null;  // set after successful pharmacist verify

    /** Returns true if any item in the current cart requires a prescription */
    function cartHasRxItems() {
        return cart.some(function(i) { return i.requires_prescription; });
    }

    /** Show orange "Rx added" toast — reminds cashier approval is needed at checkout */
    function _showRxAddedNotice(productName) {
        var container = document.getElementById('errorToastContainer');
        var id = 'rx-toast-' + Date.now();
        var el = document.createElement('div');
        el.id = id;
        el.style.cssText = [
            'background:#fff7ed','border-left:4px solid #f97316','color:#9a3412',
            'padding:14px 18px','border-radius:12px',
            'box-shadow:0 8px 20px rgba(0,0,0,.1)','z-index:10000',
            'display:flex','align-items:center','gap:10px','max-width:340px',
            'animation:slideInRight .3s ease','font-weight:500','margin-bottom:8px'
        ].join(';');
        el.innerHTML =
            '<i class="fa-solid fa-prescription-bottle-medical" style="font-size:1.3rem;color:#f97316;flex-shrink:0;"></i>' +
            '<div style="flex:1;"><strong>Rx Item Added</strong><br>' +
            '<small>' + escapeHtml(productName) + ' requires pharmacist approval at checkout.</small></div>' +
            '<span onclick="this.parentElement.remove()" style="cursor:pointer;opacity:.55;font-size:1rem;">✕</span>';
        container.appendChild(el);
        setTimeout(function() { var e=document.getElementById(id); if(e) e.remove(); }, 5000);
    }

    /** Opens the Rx approval modal; lists Rx items currently in cart */
    function _openRxModal() {
        // Populate Rx items list
        var rxItems = cart.filter(function(i) { return i.requires_prescription; });
        document.getElementById('rxItemList').innerHTML =
            rxItems.map(function(i) { return '<li>' + escapeHtml(i.name) + '</li>'; }).join('');

        // Reset form
        document.getElementById('rxUser').value = '';
        document.getElementById('rxPass').value = '';
        document.getElementById('rxErr').style.display  = 'none';
        document.getElementById('rxOk').style.display   = 'none';
        var vBtn = document.getElementById('rxVerifyBtn');
        vBtn.style.display = '';
        vBtn.disabled = false;
        vBtn.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Verify';
        document.getElementById('rxProceedBtn').style.display = 'none';

        document.getElementById('rxApprovalModal').style.display = 'flex';
        setTimeout(function() { document.getElementById('rxUser').focus(); }, 80);
    }

    function _closeRxModal() {
        document.getElementById('rxApprovalModal').style.display = 'none';
        window.approvedPharmacist = null;
    }

    /** AJAX call to actions/verify_pharmacist.php */
    /** Smart Enter handler for rxPass — verify if not yet verified, proceed if already verified */
    function _rxEnterKey() {
        var proceedBtn = document.getElementById('rxProceedBtn');
        if (proceedBtn && proceedBtn.style.display === 'flex') {
            _rxProceed();
        } else {
            _rxVerify();
        }
    }

    async function _rxVerify() {
        var username = document.getElementById('rxUser').value.trim();
        var password = document.getElementById('rxPass').value.trim();
        var errEl    = document.getElementById('rxErr');
        var okEl     = document.getElementById('rxOk');
        var btn      = document.getElementById('rxVerifyBtn');

        if (!username || !password) {
            errEl.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i>&nbsp; Please enter username and password.';
            errEl.style.display = 'block';
            return;
        }

        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>&nbsp; Verifying…';
        errEl.style.display = 'none';

        try {
            var res  = await fetch('actions/verify_pharmacist.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ username: username, password: password })
            });
            var data = await res.json();

            if (data.success) {
                window.approvedPharmacist = data.pharmacist;
                okEl.innerHTML =
                    '<i class="fa-solid fa-circle-check"></i>&nbsp; Approved by <strong>' +
                    escapeHtml(data.pharmacist.name) + '</strong><br>' +
                    '<small>License: ' + escapeHtml(data.pharmacist.license_number) +
                    '&nbsp;·&nbsp; Expires: ' + data.pharmacist.license_expiry_date + '</small>';
                okEl.style.display = 'block';
                btn.style.display  = 'none';
                var proceedBtn = document.getElementById('rxProceedBtn');
                proceedBtn.style.display = 'flex';
                setTimeout(function() { proceedBtn.focus(); }, 50);
            } else {
                errEl.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i>&nbsp; ' +
                    escapeHtml(data.message || 'Verification failed.');
                errEl.style.display = 'block';
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Verify';
            }
        } catch(e) {
            errEl.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i>&nbsp; Network error. Please try again.';
            errEl.style.display = 'block';
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-shield-halved"></i> Verify';
        }
    }

    /** Called when pharmacist is verified and cashier clicks "Proceed to Payment" */
    async function _rxProceed() {
        document.getElementById('rxApprovalModal').style.display = 'none';
        await _doCompleteSale();
    }

    // ── Wrap addToCart to show Rx notice when an Rx product is added ──────
    var _baseAddToCart = addToCart;
    addToCart = async function(productId) {
        var p = products.find(function(p) { return p.id == productId; });
        if (p && parseInt(p.requires_prescription) === 1) {
            _showRxAddedNotice(p.brand_name);
        }
        await _baseAddToCart(productId);
    };

    // ── Inject Rx data into the saleData object before sending to server ──
    // We patch _doCompleteSale's saleData by wrapping the fetch call.
    // Since _doCompleteSale is defined above and builds saleData directly,
    // we patch via the native fetch interception on pos_process.php.
    var _nativeFetch = window.fetch;
    window.fetch = async function(url) {
        var args = Array.prototype.slice.call(arguments);
        if (typeof url === 'string' && url.indexOf('pos_process.php') !== -1 && args[1] && args[1].body) {
            try {
                var body = JSON.parse(args[1].body);
                var hasRx = cartHasRxItems();
                body.has_prescription_items = hasRx;
                if (hasRx && window.approvedPharmacist) {
                    body.prescription_approved    = true;
                    body.pharmacist_id            = window.approvedPharmacist.id;
                    body.pharmacist_license       = window.approvedPharmacist.license_number;
                    body.pharmacist_license_expiry = window.approvedPharmacist.license_expiry_date;
                } else {
                    body.prescription_approved = false;
                }
                args[1].body = JSON.stringify(body);
            } catch(e) {}
        }
        var result = await _nativeFetch.apply(window, args);
        // Reset pharmacist after sale attempt (success or fail handled inside _doCompleteSale)
        if (typeof url === 'string' && url.indexOf('pos_process.php') !== -1) {
            window.approvedPharmacist = null;
        }
        return result;
    };

    // ESC for Rx modal is now handled in the main keydown listener above
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