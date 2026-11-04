<?php
/**
 * admin/inventory.php - Simplified inventory with modals matching images
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/inventory_helper.php';

restrictToAdmin();
$user_id = (int)$_SESSION['user_id'];
$message = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $act = $_POST['action'];
    
// ── ADD PRODUCT ─────────────────────────────────────────
if ($act === 'add_product') {
    // Fix: Set empty barcode to NULL (prevents duplicate entry error)
    $barcode = trim($_POST['barcode'] ?? '');
    if (empty($barcode)) {
        $barcode = null;
    }
    
    $brand_name = trim($_POST['brand_name'] ?? '');
    $generic_name = trim($_POST['generic_name'] ?? '');
    $dosage_form = trim($_POST['dosage_form'] ?? '');
    $strength = trim($_POST['strength'] ?? '');
    $category_id = intval($_POST['category_id'] ?? 0);
    $base_unit = trim($_POST['base_unit'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $cost = floatval($_POST['cost'] ?? 0);
    $threshold = max(1, intval($_POST['low_stock_threshold'] ?? 10));
    $requires_prescription = isset($_POST['requires_prescription']) ? 1 : 0;
    $prescription_type = in_array($_POST['prescription_type'] ?? '', ['OTC','Rx-only']) ? $_POST['prescription_type'] : 'OTC';
    $initial_damage = max(0, intval($_POST['initial_damage'] ?? 0));
    
    // Unit data
    $unit_names = $_POST['unit_name'] ?? [];
    $unit_convs = $_POST['unit_conv'] ?? [];
    
    // Initial stock data
    $purchase_qty = intval($_POST['purchase_qty'] ?? 0);
    $conversion_factor = max(1, intval($_POST['conversion_factor'] ?? 1));
    $initial_qty = $purchase_qty * $conversion_factor;
    $expiry_date = $_POST['expiry_date'] ?? '';
    $date_received = $_POST['date_received'] ?? date('Y-m-d');
    $purchase_unit_name = trim($_POST['purchase_unit'] ?? '');
    
    // Validation
    if (empty($brand_name) || $category_id <= 0 || $price <= 0) {
        $error = "Please fill in all required fields (Product Name, Category, Selling Price).";
    } elseif (empty($base_unit)) {
        $error = "Please select a Base Unit.";
    } elseif (empty($dosage_form)) {
        $error = "Please select a Dosage Form.";
    } elseif ($purchase_qty > 0 && $cost <= 0) {
        $error = "Cost per purchase unit is required when entering initial stock.";
    } else {
        $conn->begin_transaction();
        try {
            // Insert product - with NULL barcode support
            $stmt = $conn->prepare("
                INSERT INTO products (
                    barcode, brand_name, generic_name, dosage_form, strength, 
                    category_id, base_unit, price, vat_type, cost, low_stock_threshold,
                    requires_prescription, prescription_type
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'vatable', ?, ?, ?, ?)
            ");
            
            $stmt->bind_param(
                "sssssisddiss",
                $barcode, $brand_name, $generic_name, $dosage_form, $strength,
                $category_id, $base_unit, $price, $cost, $threshold,
                $requires_prescription, $prescription_type
            );
            
            if (!$stmt->execute()) {
                throw new Exception("Database error: " . $stmt->error);
            }
            
            $product_id = $conn->insert_id;
            $stmt->close();
            
            // Add base unit
            $stmt = $conn->prepare("
                INSERT INTO product_units (product_id, unit_name, conversion_to_base, sort_order)
                VALUES (?, ?, 1, 99)
            ");
            $stmt->bind_param("is", $product_id, $base_unit);
            $stmt->execute();
            $stmt->close();
            
            // Add purchase unit if different from base unit
            if (!empty($purchase_unit_name) && $purchase_unit_name !== $base_unit && $conversion_factor > 1) {
                $stmt = $conn->prepare("
                    INSERT IGNORE INTO product_units
                    (product_id, unit_name, conversion_to_base, sort_order)
                    VALUES (?, ?, ?, 1)
                ");
                $stmt->bind_param("isi", $product_id, $purchase_unit_name, $conversion_factor);
                $stmt->execute();
                $stmt->close();
            }

            // Add additional units if any
            if (!empty($unit_names)) {
                foreach ($unit_names as $i => $unit_name) {
                    $unit_name = trim($unit_name);
                    if (empty($unit_name) || $unit_name === 'base') continue;
                    if ($unit_name === $purchase_unit_name) continue;
                    
                    $conv = max(1, intval($unit_convs[$i] ?? 1));
                    
                    $stmt = $conn->prepare("
                        INSERT IGNORE INTO product_units 
                        (product_id, unit_name, conversion_to_base, sort_order)
                        VALUES (?, ?, ?, 0)
                    ");
                    $stmt->bind_param("isi", $product_id, $unit_name, $conv);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            
            // Add initial stock if provided
            if ($initial_qty > 0 && !empty($expiry_date)) {
                $batch_number   = date('Ymd') . '-' . str_pad($product_id, 4, '0', STR_PAD_LEFT) . '-' . rand(100, 999);
                $init_available = max(0, $initial_qty - $initial_damage);

                // CRITICAL FIX: $cost from the form is cost per PURCHASE UNIT
                // Divide by conversion_factor to get cost per BASE UNIT
                // e.g. ₱875 per box ÷ 100 tablets = ₱8.75 per tablet
                $init_conversion       = max(1, $conversion_factor);
                $init_cost_per_base    = round($cost / $init_conversion, 4);
                // Guard: selling price must always be > cost per base unit
                $init_sell_price       = $price > $init_cost_per_base ? $price : round($init_cost_per_base * 1.20, 2);

                $stmt = $conn->prepare("
                    INSERT INTO stock_batches
                    (product_id, batch_number, quantity_base, expiration_date, received_unit, received_qty,
                     purchase_cost, selling_price, damage_quantity, available_quantity, date_added)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->bind_param("isissiddiis",
                    $product_id, $batch_number, $initial_qty, $expiry_date, $base_unit, $purchase_qty,
                    $init_cost_per_base, $init_sell_price,
                    $initial_damage, $init_available, $date_received
                );
                $stmt->execute();
                $stmt->close();
            }
            
            $conn->commit();
            $message = "Product added successfully!";
            
            // Log activity
            $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'Add Product', ?)");
            $desc = "Added product: $brand_name";
            $log->bind_param("is", $user_id, $desc);
            $log->execute();
            $log->close();
            
        } catch (Exception $e) {
            $conn->rollback();
            $error = "Error: " . $e->getMessage();
        }
    }
}
    
    // ── ADD STOCK (Restock with unit conversion) - ENHANCED ────────────
    elseif ($act === 'add_stock') {
        $product_id    = intval($_POST['product_id']);
        
        // Get product details first
        $prod_stmt = $conn->prepare("SELECT brand_name, base_unit, price FROM products WHERE id = ?");
        $prod_stmt->bind_param("i", $product_id);
        $prod_stmt->execute();
        $product = $prod_stmt->get_result()->fetch_assoc();
        $prod_stmt->close();

        if (!$product) {
            $error = "Product not found.";
        } else {
            // Parse form data
            $purchase_qty = intval($_POST['purchase_qty'] ?? 0);
            $purchase_unit = trim($_POST['purchase_unit'] ?? '');
            $conversion_factor = max(1, intval($_POST['conversion_factor'] ?? 1));
            $damage_qty = max(0, intval($_POST['damage_quantity'] ?? 0));
            $purchase_cost = floatval($_POST['purchase_cost'] ?? 0);
            $selling_price = strlen(trim($_POST['batch_selling_price'] ?? '')) > 0 ? floatval($_POST['batch_selling_price']) : null;
            $expiry_date = $_POST['expiry_date'] ?? '';
            $date_received = $_POST['date_received'] ?? date('Y-m-d');
            $notes = trim($_POST['notes'] ?? '');

            if ($purchase_qty <= 0) {
                $error = "Quantity must be greater than 0.";
            } elseif (empty($expiry_date)) {
                $error = "Expiry date is required.";
            } elseif ($purchase_qty > 0 && $purchase_cost <= 0) {
                $error = "Cost per purchase unit is required.";
            } else {
                try {
                    $actual_base_unit = $product['base_unit'];
                    
                    // Calculate base quantity
                    if (!empty($purchase_unit) && $purchase_unit !== $actual_base_unit) {
                        // Get conversion factor from product_units
                        $cu = $conn->prepare("SELECT conversion_to_base FROM product_units WHERE product_id=? AND unit_name=? LIMIT 1");
                        $cu->bind_param("is", $product_id, $purchase_unit);
                        $cu->execute();
                        $cu_row = $cu->get_result()->fetch_assoc();
                        $cu->close();

                        $quantity_base = $cu_row
                            ? $purchase_qty * (int)$cu_row['conversion_to_base']
                            : $purchase_qty * $conversion_factor;
                    } else {
                        $quantity_base = $purchase_qty;
                        $purchase_unit = $actual_base_unit;
                    }

                    // Validate damage (damage is in base units now, not purchase units)
                    if ($damage_qty >= $quantity_base) {
                        throw new Exception("Damaged quantity ($damage_qty) cannot equal or exceed total base units received ($quantity_base).");
                    }

                    $batch_number = generateBatchNumber($conn, $product_id);
                    $available_qty = $quantity_base - $damage_qty;

                    // CRITICAL FIX: purchase_cost from the form is cost per PURCHASE UNIT (e.g. ₱875/box)
                    // Divide by conversion to get cost per BASE UNIT (e.g. ₱875 ÷ 100 tablets = ₱8.75/tablet)
                    // This prevents COGS being calculated as ₱875 × qty_in_tablets = massive loss
                    $actual_conversion = ($purchase_qty > 0 && $quantity_base > 0)
                        ? intval(round($quantity_base / $purchase_qty))
                        : max(1, $conversion_factor);
                    $actual_conversion    = max(1, $actual_conversion);
                    $cost_per_base_unit   = round($purchase_cost / $actual_conversion, 4);

                    // If no new selling price entered, inherit from the most recent batch
                    if ($selling_price === null) {
                        $lbq = $conn->prepare("SELECT selling_price FROM stock_batches WHERE product_id=? AND selling_price IS NOT NULL ORDER BY date_added DESC, id DESC LIMIT 1");
                        $lbq->bind_param("i", $product_id);
                        $lbq->execute();
                        $lb_row = $lbq->get_result()->fetch_assoc();
                        $lbq->close();

                        if ($lb_row) {
                            $selling_price = (float)$lb_row['selling_price'];
                        } else {
                            $selling_price = $product['price'];
                        }
                    }

                    // Guard: selling price must be > cost per base unit
                    if ($selling_price !== null && $selling_price <= $cost_per_base_unit) {
                        $selling_price = round($cost_per_base_unit * 1.20, 2);
                    }

                    $stmt = $conn->prepare("
                        INSERT INTO stock_batches
                        (product_id, batch_number, quantity_base, expiration_date, received_unit, received_qty,
                         purchase_cost, selling_price, damage_quantity, available_quantity, date_added)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param("isissiddiis",
                        $product_id, $batch_number, $quantity_base, $expiry_date, $purchase_unit, $purchase_qty,
                        $cost_per_base_unit, $selling_price, $damage_qty, $available_qty, $date_received
                    );
                    $stmt->execute();
                    $affected = $stmt->affected_rows;
                    $stmt->close();
                    
                    if ($affected > 0) {
                        $dmg_note = $damage_qty > 0 ? " ($damage_qty damaged, $available_qty available)" : "";
                        $sp_note = $selling_price !== null ? " · Sell @ ₱" . number_format($selling_price, 2) . "/" . $actual_base_unit : "";
                        $message = "Stock added successfully! Batch #$batch_number (+$available_qty sellable {$actual_base_unit}){$dmg_note}{$sp_note}";
                        
                        $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'Add Stock', ?)");
                        $desc = "Restocked {$product['brand_name']}: $purchase_qty $purchase_unit ($quantity_base base), $damage_qty damaged, $available_qty available";
                        $log->bind_param("is", $user_id, $desc);
                        $log->execute();
                        $log->close();
                    } else {
                        $error = "Failed to add stock to database.";
                    }
                    
                } catch (Exception $e) {
                    $error = "Error: " . $e->getMessage();
                }
            }
        }
    }
    
    // ── EDIT PRODUCT ─── ONLY NON-PRICE/COST FIELDS ─────────────
    elseif ($act === 'edit_product') {
        $product_id = intval($_POST['product_id'] ?? 0);
        $brand_name = trim($_POST['brand_name'] ?? '');
        $generic_name = trim($_POST['generic_name'] ?? '');
        $dosage_form = trim($_POST['dosage_form'] ?? '');
        $strength = trim($_POST['strength'] ?? '');
        $category_id = intval($_POST['category_id'] ?? 0);
        $barcode = trim($_POST['barcode'] ?? '');
        if (empty($barcode)) {
            $barcode = null;
        }
        $threshold = max(1, intval($_POST['low_stock_threshold'] ?? 10));
        $requires_prescription = isset($_POST['requires_prescription']) ? 1 : 0;
        $prescription_type = in_array($_POST['prescription_type'] ?? '', ['Rx-only','OTC']) ? $_POST['prescription_type'] : 'OTC';
        
        if (empty($brand_name) || $category_id <= 0) {
            $error = "Please fill in all required fields correctly.";
        } else {
            // Begin transaction
            $conn->begin_transaction();
            
            try {
                // Prepare the UPDATE statement with ONLY non-price/cost fields
                $stmt = $conn->prepare("
                    UPDATE products SET 
                        brand_name = ?,
                        generic_name = ?,
                        dosage_form = ?,
                        strength = ?,
                        category_id = ?,
                        barcode = ?,
                        low_stock_threshold = ?,
                        requires_prescription = ?,
                        prescription_type = ?,
                        medicine_type = ?
                    WHERE id = ?
                ");
                
                // Determine medicine_type based on prescription requirement
                $medicine_type = $requires_prescription ? 'Rx' : 'OTC';
                
                // Bind parameters (11 parameters total - NO price, NO cost)
                $stmt->bind_param(
                    "ssssississi",  // 11 characters: s,s,s,s,s,i,s,i,s,s,i
                    $brand_name,      // s
                    $generic_name,    // s
                    $dosage_form,     // s
                    $strength,        // s
                    $category_id,     // i
                    $barcode,         // s
                    $threshold,       // i
                    $requires_prescription, // i
                    $prescription_type,     // s
                    $medicine_type,         // s
                    $product_id       // i
                );
                
                // Execute the update
                if (!$stmt->execute()) {
                    throw new Exception("Database error: " . $stmt->error);
                }
                
                // Check if any rows were affected
                if ($stmt->affected_rows > 0 || $stmt->affected_rows === 0) {
                    // affected_rows can be 0 if no changes were made, which is fine
                    // We still want to show success if the query executed without error
                    $stmt->close();
                    
                    // Commit the transaction
                    $conn->commit();
                    
                    // Set success message
                    $message = "Product updated successfully!";
                    
                    // Log the activity
                    $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'Edit Product', ?)");
                    $desc = "Edited product: $brand_name (ID: $product_id)";
                    $log->bind_param("is", $user_id, $desc);
                    $log->execute();
                    $log->close();
                } else {
                    throw new Exception("No rows were updated. Product may not exist.");
                }
                
            } catch (Exception $e) {
                // Rollback on error
                $conn->rollback();
                $error = "Error updating product: " . $e->getMessage();
            }
        }
    }
    
    // ── ARCHIVE PRODUCT (replaces delete) ───────────────────
    elseif ($act === 'archive_product') {
        $id = intval($_POST['id']);
        
        $stmt = $conn->prepare("SELECT brand_name, is_archived FROM products WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$product) {
            $error = "Product not found.";
        } elseif ($product['is_archived']) {
            $error = "Product is already archived.";
        } else {
            $stmt = $conn->prepare("UPDATE products SET is_archived = 1, archived_at = NOW(), archived_by = ? WHERE id = ?");
            $stmt->bind_param("ii", $user_id, $id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            
            if ($affected > 0) {
                $message = "Product \"{$product['brand_name']}\" has been archived. You can restore it from the Archived tab.";
                $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'Archive Product', ?)");
                $desc = "Archived product: {$product['brand_name']} (ID: {$id})";
                $log->bind_param("is", $user_id, $desc);
                $log->execute();
                $log->close();
            } else {
                $error = "Failed to archive product.";
            }
        }
    }

    // ── RESTORE PRODUCT ─────────────────────────────────────
    elseif ($act === 'restore_product') {
        $id = intval($_POST['id']);
        
        $stmt = $conn->prepare("SELECT brand_name, is_archived FROM products WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$product) {
            $error = "Product not found.";
        } elseif (!$product['is_archived']) {
            $error = "Product is not archived.";
        } else {
            $stmt = $conn->prepare("UPDATE products SET is_archived = 0, archived_at = NULL, archived_by = NULL WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            
            if ($affected > 0) {
                $message = "Product \"{$product['brand_name']}\" has been restored to active inventory!";
                $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'Restore Product', ?)");
                $desc = "Restored product: {$product['brand_name']} (ID: {$id})";
                $log->bind_param("is", $user_id, $desc);
                $log->execute();
                $log->close();
            } else {
                $error = "Failed to restore product.";
            }
        }
    }

    // ── DELETE PRODUCT (permanent, only from archived) ──────
    elseif ($act === 'delete_product') {
        $id = intval($_POST['id']);
        
        // Get product name for logging
        $stmt = $conn->prepare("SELECT brand_name FROM products WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$product) {
            $error = "Product not found.";
        } else {
            // Check if product has any sales records
            $check_sales = $conn->prepare("SELECT COUNT(*) as count FROM sale_items WHERE product_id = ?");
            $check_sales->bind_param("i", $id);
            $check_sales->execute();
            $sales_result = $check_sales->get_result();
            $sales_count = $sales_result->fetch_assoc()['count'];
            $check_sales->close();
            
            if ($sales_count > 0) {
                $error = "Cannot delete product because it has {$sales_count} sales record(s). This product has been sold in the past.";
            } else {
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    // 1. Delete from stock_batches first
                    $del_batches = $conn->prepare("DELETE FROM stock_batches WHERE product_id = ?");
                    $del_batches->bind_param("i", $id);
                    $del_batches->execute();
                    $batches_deleted = $del_batches->affected_rows;
                    $del_batches->close();
                    
                    // 2. Delete from product_units
                    $del_units = $conn->prepare("DELETE FROM product_units WHERE product_id = ?");
                    $del_units->bind_param("i", $id);
                    $del_units->execute();
                    $units_deleted = $del_units->affected_rows;
                    $del_units->close();
                    
                    // 3. Delete notifications
                    $del_notifications = $conn->prepare("DELETE FROM notifications WHERE reference_id = ?");
                    $del_notifications->bind_param("i", $id);
                    $del_notifications->execute();
                    $del_notifications->close();
                    
                    // 4. Finally delete the product
                    $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $deleted = $stmt->affected_rows;
                    $stmt->close();
                    
                    if ($deleted > 0) {
                        $conn->commit();
                        $message = "Product deleted successfully! Removed {$batches_deleted} batches and {$units_deleted} unit definitions.";
                        
                        // Log activity
                        $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'Delete Product', ?)");
                        $desc = "Deleted product: {$product['brand_name']} (ID: {$id})";
                        $log->bind_param("is", $user_id, $desc);
                        $log->execute();
                        $log->close();
                    } else {
                        $conn->rollback();
                        $error = "Failed to delete product from database. No rows affected.";
                    }
                    
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Error deleting product: " . $e->getMessage();
                }
            }
        }
    }
    
    // ── DELETE BATCH (expired) ──────────────────────────────
    elseif ($act === 'delete_batch') {
        $batch_id = intval($_POST['batch_id']);
        $force    = isset($_POST['force_delete']) && $_POST['force_delete'] === '1';

        $stmt = $conn->prepare("SELECT sb.id, sb.expiration_date, sb.batch_number, p.brand_name FROM stock_batches sb JOIN products p ON p.id=sb.product_id WHERE sb.id=?");
        $stmt->bind_param("i", $batch_id);
        $stmt->execute();
        $batch = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$batch) {
            $error = "Batch not found.";
        } else {
            // Check if this batch is referenced in sale_items (FK constraint)
            $chk = $conn->prepare("SELECT COUNT(*) as cnt FROM sale_items WHERE batch_id=?");
            $chk->bind_param("i", $batch_id);
            $chk->execute();
            $used_count = (int)$chk->get_result()->fetch_assoc()['cnt'];
            $chk->close();

            if ($used_count > 0 && !$force) {
                $error = "BATCH_IN_USE:{$used_count}:{$batch['batch_number']}:{$batch_id}";
            } else {
                $conn->begin_transaction();
                try {
                    if ($used_count > 0) {
                        // Reassign sale_items to a placeholder batch
                        $fallback = $conn->prepare("SELECT id FROM stock_batches WHERE product_id=(SELECT product_id FROM stock_batches WHERE id=?) AND id != ? ORDER BY id ASC LIMIT 1");
                        $fallback->bind_param("ii", $batch_id, $batch_id);
                        $fallback->execute();
                        $fb_row = $fallback->get_result()->fetch_assoc();
                        $fallback->close();

                        if ($fb_row) {
                            $upd = $conn->prepare("UPDATE sale_items SET batch_id=? WHERE batch_id=?");
                            $upd->bind_param("ii", $fb_row['id'], $batch_id);
                            $upd->execute();
                            $upd->close();
                        } else {
                            // No other batch exists — create a ghost/archive batch first
                            $ghost = $conn->prepare("INSERT INTO stock_batches (product_id, batch_number, quantity_base, expiration_date, received_unit, received_qty, date_added) SELECT product_id, CONCAT('ARCHIVED-',batch_number), 0, expiration_date, received_unit, received_qty, date_added FROM stock_batches WHERE id=?");
                            $ghost->bind_param("i", $batch_id);
                            $ghost->execute();
                            $ghost_id = $conn->insert_id;
                            $ghost->close();
                            $upd = $conn->prepare("UPDATE sale_items SET batch_id=? WHERE batch_id=?");
                            $upd->bind_param("ii", $ghost_id, $batch_id);
                            $upd->execute();
                            $upd->close();
                        }
                    }

                    $del = $conn->prepare("DELETE FROM stock_batches WHERE id=?");
                    $del->bind_param("i", $batch_id);
                    $del->execute();
                    $deleted = $del->affected_rows;
                    $del->close();

                    if ($deleted > 0) {
                        $conn->commit();
                        $message = "Batch deleted successfully." . ($used_count > 0 ? " ({$used_count} sale record(s) reassigned to archive.)" : "");
                        $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'Delete Batch', ?)");
                        $desc = "Deleted batch #{$batch_id} ({$batch['batch_number']}) of {$batch['brand_name']}" . ($used_count > 0 ? " - force deleted, {$used_count} sale items reassigned" : "");
                        $log->bind_param("is", $user_id, $desc);
                        $log->execute();
                        $log->close();
                    } else {
                        $conn->rollback();
                        $error = "Failed to delete batch.";
                    }
                } catch (Exception $e) {
                    $conn->rollback();
                    $error = "Error: " . $e->getMessage();
                }
            }
        }
    }
    
    elseif ($act === 'add_category') {
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        if (empty($name)) {
            $error = "Category name required.";
        } else {
            $stmt = $conn->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
            $stmt->bind_param("ss", $name, $desc);
            $stmt->execute();
            $stmt->close();
            $message = "Category added successfully!";
        }
    }
    
    // ── DELETE CATEGORY ─────────────────────────────────────
    elseif ($act === 'delete_category') {
        $id = intval($_POST['id']);
        
        // Check if category is used by any products
        $check = $conn->prepare("SELECT COUNT(*) as count FROM products WHERE category_id = ?");
        $check->bind_param("i", $id);
        $check->execute();
        $result = $check->get_result();
        $product_count = $result->fetch_assoc()['count'];
        $check->close();
        
        if ($product_count > 0) {
            $error = "Cannot delete category that has {$product_count} product(s).";
        } else {
            $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            
            if ($stmt->affected_rows > 0) {
                $message = "Category deleted successfully!";
                
                // Log activity
                $log = $conn->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, 'Delete Category', ?)");
                $desc = "Deleted category ID: {$id}";
                $log->bind_param("is", $user_id, $desc);
                $log->execute();
                $log->close();
            } else {
                $error = "Failed to delete category.";
            }
            $stmt->close();
        }
    }
}

// Get filter parameters
$search = $_GET['search'] ?? '';
$category_filter = intval($_GET['category'] ?? 0);

// Build query for products - SIMPLE table (active only)
$query = "
    SELECT p.*, c.name as category_name,
           COALESCE(SUM(
               CASE WHEN sb.expiration_date >= CURDATE()
               THEN COALESCE(sb.available_quantity, sb.quantity_base - COALESCE(sb.damage_quantity, 0))
               ELSE 0 END
           ), 0) as total_stock,
           (SELECT sb2.selling_price
            FROM stock_batches sb2
            WHERE sb2.product_id = p.id
              AND sb2.expiration_date >= CURDATE()
              AND COALESCE(sb2.available_quantity, sb2.quantity_base - COALESCE(sb2.damage_quantity, 0)) > 0
              AND sb2.selling_price IS NOT NULL
            ORDER BY sb2.expiration_date ASC, sb2.id ASC
            LIMIT 1) as fifo_price,
           COUNT(DISTINCT CASE 
               WHEN sb.expiration_date > CURDATE() 
               AND COALESCE(sb.available_quantity, sb.quantity_base - COALESCE(sb.damage_quantity, 0)) > 0 
               THEN sb.selling_price END) as price_variations
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN stock_batches sb ON p.id = sb.product_id
    WHERE p.is_archived = 0
";

$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (p.brand_name LIKE ? OR p.generic_name LIKE ? OR p.barcode LIKE ?)";
    $search_param = "%$search%";
    $params = [$search_param, $search_param, $search_param];
    $types = "sss";
}

if ($category_filter > 0) {
    $query .= " AND p.category_id = ?";
    $params[] = $category_filter;
    $types .= "i";
}

$query .= " GROUP BY p.id ORDER BY p.brand_name ASC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get archived products
$archived_query = "
    SELECT p.*, c.name as category_name,
           COALESCE(SUM(COALESCE(sb.available_quantity, sb.quantity_base - sb.damage_quantity)), 0) as total_stock,
           u.full_name as archived_by_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN stock_batches sb ON p.id = sb.product_id
    LEFT JOIN users u ON p.archived_by = u.id
    WHERE p.is_archived = 1
    GROUP BY p.id
    ORDER BY p.archived_at DESC
";
$archived_products = $conn->query($archived_query)->fetch_all(MYSQLI_ASSOC);
$archived_count = count($archived_products);
$categories = $conn->query("
    SELECT c.id, c.name, COUNT(p.id) as product_count
    FROM categories c
    LEFT JOIN products p ON p.category_id = c.id
    GROUP BY c.id
    ORDER BY c.name
")->fetch_all(MYSQLI_ASSOC);

// Get all categories for categories tab
$all_categories = $conn->query("SELECT * FROM categories ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// Get batches for expiry tab - ENHANCED with price display
$batches = $conn->query("
    SELECT sb.*, p.brand_name, p.generic_name, c.name as category_name,
           DATEDIFF(sb.expiration_date, CURDATE()) as days_left,
           COALESCE(sb.available_quantity, sb.quantity_base - COALESCE(sb.damage_quantity, 0)) as sellable_quantity,
           CASE 
               WHEN sb.expiration_date <= CURDATE() THEN 'expired'
               WHEN sb.expiration_date < DATE_ADD(CURDATE(), INTERVAL 1 MONTH) THEN 'near_expiry'
               ELSE 'good'
           END as status
    FROM stock_batches sb
    JOIN products p ON p.id = sb.product_id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE sb.quantity_base > 0
    ORDER BY 
        CASE 
            WHEN sb.expiration_date <= CURDATE() THEN 1
            WHEN sb.expiration_date < DATE_ADD(CURDATE(), INTERVAL 1 MONTH) THEN 2
            ELSE 3
        END,
        sb.expiration_date ASC
")->fetch_all(MYSQLI_ASSOC);

// Stats
$total_products = count($products);

$low_stock_count = $conn->query("
    SELECT COUNT(*) FROM products p
    WHERE (
        SELECT COALESCE(SUM(COALESCE(sb.available_quantity, sb.quantity_base - COALESCE(sb.damage_quantity, 0))), 0)
        FROM stock_batches sb
        WHERE sb.product_id = p.id
          AND sb.expiration_date >= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
    ) > 0
    AND (
        SELECT COALESCE(SUM(COALESCE(sb.available_quantity, sb.quantity_base - COALESCE(sb.damage_quantity, 0))), 0)
        FROM stock_batches sb
        WHERE sb.product_id = p.id
          AND sb.expiration_date >= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
    ) <= p.low_stock_threshold
")->fetch_row()[0];

$out_of_stock_count = $conn->query("
    SELECT COUNT(DISTINCT p.id)
    FROM products p
    WHERE NOT EXISTS (
        SELECT 1 FROM stock_batches sb
        WHERE sb.product_id = p.id
          AND COALESCE(sb.available_quantity, sb.quantity_base - COALESCE(sb.damage_quantity, 0)) > 0
          AND sb.expiration_date >= DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
    )
    AND EXISTS (
        SELECT 1 FROM stock_batches sb WHERE sb.product_id = p.id
    )
")->fetch_row()[0];

$near_expiry_count = $conn->query("
    SELECT COUNT(*) FROM stock_batches
    WHERE expiration_date > CURDATE()
      AND expiration_date < DATE_ADD(CURDATE(), INTERVAL 1 MONTH)
      AND COALESCE(available_quantity, quantity_base - COALESCE(damage_quantity, 0)) > 0
")->fetch_row()[0];

$expired_count = $conn->query("
    SELECT COUNT(*) FROM stock_batches
    WHERE expiration_date <= CURDATE()
      AND COALESCE(available_quantity, quantity_base - COALESCE(damage_quantity, 0)) > 0
")->fetch_row()[0];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Inventory Management | CAD Crest</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Page-specific styles only */
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
        /* Page Header */
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

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 0.75rem;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }

.btn-primary {
    background: linear-gradient(135deg, var(--primary-green), #00695c);
    color: white;
}

.btn-primary:hover {
    background: linear-gradient(135deg, #00695c, var(--primary-dark));
    transform: translateY(-2px);
    box-shadow: 0 10px 25px -5px rgba(0, 77, 64, 0.3);
}


        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-outline {
            background: white;
            border: 1px solid var(--gray-200);
            color: var(--gray-700);
        }

        .btn-outline:hover {
            background: var(--gray-50);
        }

        /* Stats Cards */
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

        .stat-info h3 {
            font-size: 0.8rem;
            color: var(--gray-500);
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 0.25rem;
        }

        .stat-info .value {
            font-size: 1.8rem;
            font-weight: 800;
            color: var(--gray-800);
            line-height: 1.2;
        }

        /* Filter Bar */
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

        .filter-group {
            flex: 1;
        }

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

        /* Tabs */
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

        .tab-btn i {
            font-size: 1rem;
        }

        .tab-btn.active {
            color: var(--primary-green);
            background: white;
            border-bottom: 3px solid var(--primary-green);
        }

        .tab-content {
            padding: 1.5rem;
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Table Container - Responsive */
        .table-container {
            overflow-x: auto;
            border-radius: 0.75rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
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
        }

        td {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--gray-100);
            color: var(--gray-700);
            font-size: 0.9rem;
        }

        tbody tr:hover {
            background: var(--gray-50);
        }

        .product-name-link {
            color: var(--primary-green);
            font-weight: 700;
            text-decoration: none;
            border-bottom: 1px dashed var(--primary-green);
            cursor: pointer;
        }

        .product-name-link:hover {
            color: var(--primary-dark);
        }

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

        .price-value {
            font-weight: 700;
            color: var(--primary-green);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }

        .status-good {
            background: #dcfce7;
            color: #15803d;
        }

        .status-low {
            background: #ffedd5;
            color: #9a3412;
        }

        .status-out {
            background: #fee2e2;
            color: #b91c1c;
        }

        .action-cell {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            transition: all 0.2s;
        }

        .btn-stock {
            background: #e8f5e9;
            color: #2e7d32;
        }

        .btn-stock:hover {
            background: #c8e6c9;
        }

        .btn-archive {
            background: #fff8e1;
            color: #b45309;
        }

        .btn-archive:hover {
            background: #fef3c7;
        }

        .btn-delete {
            background: #ffebee;
            color: #c62828;
        }

        .btn-delete:hover {
            background: #ffcdd2;
        }

        /* Expiry Table */
        .expiry-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }

        .expiry-table th {
            text-align: left;
            padding: 1rem;
            background: var(--gray-50);
            color: var(--gray-600);
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            border-bottom: 1px solid var(--gray-200);
        }

        .expiry-table td {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-100);
        }

        .expired-row {
            background: #ffebee;
        }

        .warning-row {
            background: #fff3e0;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            width: 95%;
            max-width: 800px;
            max-height: 90vh;
            overflow-y: auto;
            border-radius: 1.5rem;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

.modal-header {
    padding: 1.5rem 2rem;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, var(--primary-green), #00695c);
    color: white;
    border-radius: 1.5rem 1.5rem 0 0;
}

        .modal-header h2 {
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .modal-close {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 36px;
            height: 36px;
            border-radius: 50%;
            font-size: 1.2rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .modal-close:hover {
            background: rgba(255,255,255,0.3);
            transform: rotate(90deg);
        }

        .modal-body {
            padding: 2rem;
        }

        /* Form styles */
        .form-group {
            margin-bottom: 1.25rem;
        }

        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--gray-600);
            margin-bottom: 0.5rem;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--gray-200);
            border-radius: 0.75rem;
            font-size: 0.95rem;
            transition: all 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 77, 64, 0.1);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }

        /* Stock section */
        .stock-section {
            margin: 2rem 0;
            border: 1px solid var(--gray-200);
            border-radius: 1rem;
            overflow: hidden;
        }

        .stock-header {
            background: var(--gray-50);
            padding: 1rem;
            font-weight: 700;
            font-size: 0.9rem;
            border-bottom: 1px solid var(--gray-200);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .stock-row {
            padding: 1rem;
            border-bottom: 1px solid var(--gray-200);
        }

        /* Current stock info */
        .current-stock-info {
            background: var(--gray-50);
            padding: 1rem;
            border-radius: 0.75rem;
            margin-bottom: 1.5rem;
            border: 1px solid var(--gray-200);
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .info-row:last-child {
            margin-bottom: 0;
        }

        .info-label {
            color: var(--gray-600);
        }

        .info-value {
            font-weight: 700;
            color: var(--gray-800);
        }

        /* Batch preview */
        .batch-preview {
            background: var(--gray-50);
            padding: 0.75rem 1rem;
            border-radius: 0.75rem;
            font-size: 0.85rem;
            color: var(--gray-600);
            border: 1px solid var(--gray-200);
            margin-top: 0.5rem;
        }

        .batch-preview strong {
            color: var(--primary-green);
            font-family: monospace;
        }

        /* Alert messages */
        .alert {
            padding: 1rem;
            border-radius: 0.75rem;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #a5d6a7;
        }

        .alert-error {
            background: #ffebee;
            color: #c62828;
            border: 1px solid #ef9a9a;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 4rem;
            color: var(--gray-400);
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
        }

        /* Expiry filter buttons */
        .expiry-filter-btn {
            background: #fff;
            border: 1px solid #d1d5db;
            padding: .4rem .9rem;
            border-radius: 20px;
            font-size: .78rem;
            font-weight: 600;
            cursor: pointer;
            transition: .2s;
        }
        .expiry-filter-btn.active {
            background: var(--primary-green);
            border-color: var(--primary-green);
            color: #fff;
        }

        /* Confirm Dialog */
        #confirmDialog {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }

        /* Responsive Breakpoints */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }

        @media (max-width: 992px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
            .form-row {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 1rem;
            }
            
            .filter-bar {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .tabs-header {
                flex-wrap: nowrap;
            }
            
            .tab-btn {
                padding: 0.8rem 1.2rem;
                font-size: 0.8rem;
            }
            
            .modal-content {
                width: 95%;
                margin: 10px;
            }
            
            .modal-body {
                padding: 1rem;
            }
            
            .action-cell {
                flex-direction: column;
            }
        }

        @media (max-width: 576px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .stat-card {
                padding: 1rem;
            }
            
            .stat-info .value {
                font-size: 1.4rem;
            }
            
            .page-header h1 {
                font-size: 1.5rem;
            }
            
            .modal-header {
                padding: 1rem;
            }
            
            .modal-header h2 {
                font-size: 1.2rem;
            }
            
            .form-row {
                gap: 0.5rem;
            }
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        @keyframes modalPop {
            from { transform: scale(.9); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }

        /* Stats Cards - Matching User Management Style */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 1.5rem;
    margin-bottom: 2rem;
}

.stat-card {
    background: white;
    border-radius: 24px;
    padding: 1.5rem;
    box-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.05);
    display: flex;
    align-items: center;
    gap: 1.2rem;
    transition: all 0.2s ease;
    border: 1px solid rgba(0,0,0,0.02);
    position: relative;
    overflow: hidden;
}

.stat-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #004d40, #b8860b);
    opacity: 0;
    transition: opacity 0.2s ease;
}

.stat-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 20px 35px -10px rgba(0, 77, 64, 0.2);
}

.stat-card:hover::before {
    opacity: 1;
}

.stat-icon {
    width: 56px;
    height: 56px;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.stat-card:hover .stat-icon {
    transform: scale(1.05) rotate(3deg);
}

.stat-details {
    flex: 1;
}

.stat-details h3 {
    font-size: 0.8rem;
    color: #64748b;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 0.25rem;
}

.stat-details .value {
    font-size: 1.8rem;
    font-weight: 800;
    color: #1e293b;
    line-height: 1.2;
    margin-bottom: 0.2rem;
}

.stat-badge {
    display: inline-block;
    font-size: 0.7rem;
    padding: 0.2rem 0.8rem;
    background: #f1f5f9;
    border-radius: 40px;
    color: #475569;
    font-weight: 600;
}

.stat-badge.warning {
    background: #fff3e0;
    color: #b8860b;
}

.stat-badge.danger {
    background: #fee2e2;
    color: #b91c1c;
}

.stat-badge.success {
    background: #e8f5e9;
    color: #004d40;
}

/* Price variance badge */
.price-variance-badge {
    background: #ede9fe;
    color: #7e22ce;
    padding: 0.2rem 0.6rem;
    border-radius: 9999px;
    font-size: 0.7rem;
    font-weight: 700;
    margin-left: 0.5rem;
    display: inline-block;
}

/* Batch table inside modal */
.batch-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
    margin-top: 1rem;
}

.batch-table th {
    background: #f8fafc;
    padding: 0.75rem;
    text-align: left;
    font-weight: 700;
    font-size: 0.7rem;
    text-transform: uppercase;
    color: #64748b;
    border-bottom: 2px solid #e2e8f0;
}

.batch-table td {
    padding: 0.75rem;
    border-bottom: 1px solid #edf2f7;
    color: #334155;
}

.batch-table tbody tr:hover {
    background: #f8fafc;
}

.batch-expired {
    background: #fef2f2;
}

.batch-near-expiry {
    background: #fffbeb;
}

.total-qty {
    font-weight: 600;
}

.damaged-qty {
    font-weight: 600;
    color: #f97316;
}

.sellable-qty {
    font-weight: 700;
}

/* Responsive */
@media (max-width: 1200px) {
    .stats-grid {
        grid-template-columns: repeat(4, 1fr);
    }
}

@media (max-width: 992px) {
    .stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
    }
    
    .stat-card {
        padding: 1.2rem;
    }
    
    .stat-details .value {
        font-size: 1.5rem;
    }
}

@media (max-width: 480px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
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
                <?php 
                if (function_exists('renderNotificationBell')) {
                    renderNotificationBell($conn); 
                }
                ?>
                <button class="btn btn-primary" onclick="openModal('addProductModal')">
                    <i class="fa-solid fa-plus"></i> Add New Product
                </button>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error && strpos($error, 'BATCH_IN_USE:') === 0):
            // Parse: BATCH_IN_USE:{count}:{batch_number}:{batch_id}
            $parts     = explode(':', $error, 4);
            $in_use_count  = (int)($parts[1] ?? 0);
            $in_use_batch  = $parts[2] ?? '';
            $in_use_id     = (int)($parts[3] ?? 0);
        ?>
        <div class="alert alert-error" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.8rem;">
            <span>
                <i class="fa-solid fa-circle-exclamation"></i>
                Cannot delete batch <strong><code><?php echo htmlspecialchars($in_use_batch); ?></code></strong> — it is referenced in
                <strong><?php echo $in_use_count; ?> sale record(s)</strong>.
            </span>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Force-delete this batch and reassign its sale records? This cannot be undone.');">
                <input type="hidden" name="action"       value="delete_batch">
                <input type="hidden" name="batch_id"     value="<?php echo $in_use_id; ?>">
                <input type="hidden" name="force_delete" value="1">
                <button type="submit" style="padding:.45rem 1.1rem;background:#dc2626;color:#fff;border:none;border-radius:9px;font-weight:700;font-size:.85rem;cursor:pointer;">
                    <i class="fa-solid fa-trash"></i> Force Delete
                </button>
            </form>
        </div>
        <?php elseif ($error): ?>
        <div class="alert alert-error">
            <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

       <!-- Statistics Cards - Matching User Management Style -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background: #e8f5e9; color: #004d40;">
            <i class="fa-solid fa-pills"></i>
        </div>
        <div class="stat-details">
            <h3>Total Products</h3>
            <div class="value" style="color:#004d40;"><?php echo $total_products; ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #fff3e0; color: #e65100;">
            <i class="fa-solid fa-exclamation-triangle"></i>
        </div>
        <div class="stat-details">
            <h3>Low Stock</h3>
            <div class="value" style="color:#e65100;"><?php echo $low_stock_count; ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #fee2e2; color: #b91c1c;">
            <i class="fa-solid fa-circle-exclamation"></i>
        </div>
        <div class="stat-details">
            <h3>Out of Stock</h3>
            <div class="value" style="color:#b91c1c;"><?php echo $out_of_stock_count; ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #fff8e1; color: #f57c00;">
            <i class="fa-solid fa-clock"></i>
        </div>
        <div class="stat-details">
            <h3>Near Expiry</h3>
            <div class="value" style="color:#f57c00;"><?php echo $near_expiry_count; ?></div>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background: #fee2e2; color: #c62828;">
            <i class="fa-solid fa-calendar-xmark"></i>
        </div>
        <div class="stat-details">
            <h3>Expired</h3>
            <div class="value" style="color:#c62828;"><?php echo $expired_count; ?></div>
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

        <!-- Tabs Container -->
        <div class="tabs-container">
            <div class="tabs-header">
                <button class="tab-btn active" onclick="openTab(event, 'productsTab')">
                    <i class="fa-solid fa-pills"></i> Products
                </button>
                <button class="tab-btn" onclick="openTab(event, 'expiryTab')">
                    <i class="fa-solid fa-clock"></i> Expiry Tracking
                </button>
                <button class="tab-btn" onclick="openTab(event, 'categoriesTab')">
                    <i class="fa-solid fa-tags"></i> Categories
                </button>
                <button class="tab-btn" onclick="openTab(event, 'archivedTab')" style="position:relative;">
                    <i class="fa-solid fa-box-archive"></i> Archived
                    <?php if ($archived_count > 0): ?>
                    <span style="background:#f59e0b;color:#fff;border-radius:999px;font-size:0.7rem;font-weight:700;padding:1px 7px;margin-left:4px;"><?php echo $archived_count; ?></span>
                    <?php endif; ?>
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
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($products)): ?>
                            <tr>
                                <td colspan="8" class="empty-state">
                                    <i class="fa-solid fa-box-open"></i>
                                    <p>No products found</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($products as $product): 
                                // Show price with variance badge if multiple batch prices exist
                                $price_display = '₱' . number_format($product['fifo_price'] !== null ? (float)$product['fifo_price'] : (float)$product['price'], 2);
                                if ($product['price_variations'] > 1) {
                                    $price_display = '₱' . number_format($product['fifo_price'] ?? $product['price'], 2) . ' <span class="price-variance-badge">varies by batch</span>';
                                }
                                
                                $stock_class = '';
                                $status_badge = 'status-good';
                                $status_text = 'In Stock';
                                
                                if ($product['total_stock'] <= 0) {
                                    $stock_class = 'stock-out';
                                    $status_badge = 'status-out';
                                    $status_text = 'Out of Stock';
                                } elseif ($product['total_stock'] < $product['low_stock_threshold']) {
                                    $stock_class = 'stock-low';
                                    $status_badge = 'status-low';
                                    $status_text = 'Low Stock';
                                }
                            ?>
                            <tr class="product-row" onclick="showProductDetails(<?php echo $product['id']; ?>)" style="cursor:pointer;"
                                data-name="<?php echo strtolower(htmlspecialchars($product['brand_name'])); ?>"
                                data-generic="<?php echo strtolower(htmlspecialchars($product['generic_name'] ?? '')); ?>"
                                data-barcode="<?php echo strtolower(htmlspecialchars($product['barcode'] ?? '')); ?>"
                                data-category="<?php echo strtolower(htmlspecialchars($product['category_name'] ?? '')); ?>"
                                data-unit="<?php echo strtolower(htmlspecialchars($product['base_unit'] ?? '')); ?>"
                                data-dosage="<?php echo strtolower(htmlspecialchars($product['dosage_form'] ?? '')); ?>"
                                data-cat-id="<?php echo (int)($product['category_id'] ?? 0); ?>">
                                <td>
                                    <a class="product-name-link" onclick="event.stopPropagation(); showProductDetails(<?php echo $product['id']; ?>)">
                                        <?php echo htmlspecialchars($product['brand_name']); ?>
                                    </a>
                                    <div class="product-generic"><?php echo htmlspecialchars($product['generic_name']); ?></div>
                                    <?php if (!empty($product['strength'])): ?>
                                    <div style="font-size:0.7rem; color:var(--gray-500);"><?php echo htmlspecialchars($product['strength']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="category-badge"><?php echo htmlspecialchars($product['category_name'] ?? 'Uncategorized'); ?></span>
                                </td>
                                <td><code><?php echo htmlspecialchars($product['base_unit']); ?></code></td>
                                <td><span class="price-value"><?php echo $price_display; ?></span></td>
                                <td>
                                    <span class="stock-value <?php echo $stock_class; ?>">
                                        <?php echo $product['total_stock']; ?>
                                    </span>
                                </td>
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
                                    <span class="status-badge <?php echo $status_badge; ?>">
                                        <?php echo $status_text; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-cell" onclick="event.stopPropagation();">
                                        <button class="action-btn btn-stock" onclick="openRestockModal(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars(addslashes($product['brand_name'])); ?>')">
                                            <i class="fa-solid fa-plus"></i> Restock
                                        </button>
                                        <button class="action-btn btn-archive" onclick="confirmArchive(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars(addslashes($product['brand_name'])); ?>')" title="Archive product (recoverable)">
                                            <i class="fa-solid fa-box-archive"></i> Archive
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Expiry Tracking Tab - FIXED with price column -->
            <div id="expiryTab" class="tab-content">
                <div style="margin-bottom: 1rem; display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                    <button class="expiry-filter-btn active" data-filter="all" onclick="filterExpiry('all')">All</button>
                    <button class="expiry-filter-btn" data-filter="expired" onclick="filterExpiry('expired')" style="color:#c62828; border-color:#c62828;">Expired</button>
                    <button class="expiry-filter-btn" data-filter="near_expiry" onclick="filterExpiry('near_expiry')" style="color:#f57c00; border-color:#f57c00;">Near Expiry</button>
                    <button class="expiry-filter-btn" data-filter="good" onclick="filterExpiry('good')" style="color:#2e7d32; border-color:#2e7d32;">Good</button>
                </div>
                
                <div class="table-container">
                    <table class="expiry-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Batch #</th>
                                <th>Expiry Date</th>
                                <th>Total Qty</th>
                                <th>Damaged</th>
                                <th>Sellable Qty</th>
                                <th>Selling Price</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($batches)): ?>
                            <tr><td colspan="9" style="text-align:center; padding: 2rem; color: #94a3b8;">No stock batches found.</td></tr>
                            <?php else: ?>
                            <?php foreach ($batches as $batch): 
                                $row_class = '';
                                if ($batch['status'] === 'expired') $row_class = 'expired-row';
                                elseif ($batch['status'] === 'near_expiry') $row_class = 'warning-row';
                            ?>
                            <tr class="expiry-row <?php echo $row_class; ?>" data-status="<?php echo $batch['status']; ?>" data-name="<?php echo strtolower(htmlspecialchars($batch['brand_name'] . ' ' . $batch['generic_name'])); ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($batch['brand_name']); ?></strong>
                                    <br><small><?php echo htmlspecialchars($batch['generic_name']); ?></small>
                                </td>
                                <td><code><?php echo htmlspecialchars($batch['batch_number']); ?></code></td>
                                <td><?php echo date('M d, Y', strtotime($batch['expiration_date'])); ?></td>
                                <td class="total-qty"><?php echo (int)$batch['quantity_base']; ?></td>
                                <td class="damaged-qty" style="color: #f97316;"><?php echo (int)($batch['damage_quantity'] ?? 0); ?></td>
                                <td class="sellable-qty" style="font-weight: 700; color: <?php echo $batch['sellable_quantity'] > 0 ? '#16a34a' : '#dc2626' ?>;">
                                    <?php echo (int)$batch['sellable_quantity']; ?>
                                </td>
                                <td>
                                    <?php if ($batch['selling_price']): ?>
                                        <strong style="color: var(--primary-green);">₱<?php echo number_format($batch['selling_price'], 2); ?></strong>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $status_labels = [
                                        'expired' => '<span class="status-badge status-out">Expired</span>',
                                        'near_expiry' => '<span class="status-badge status-low">Near Expiry</span>',
                                        'good' => '<span class="status-badge status-good">Good</span>'
                                    ];
                                    echo $status_labels[$batch['status']] ?? '';
                                    ?>
                                </td>
                                <td>
                                    <?php if ($batch['status'] === 'expired' || $batch['status'] === 'near_expiry'): ?>
                                    <button class="action-btn btn-delete" onclick="event.stopPropagation(); showRemoveConfirmModal(<?php echo $batch['id']; ?>, '<?php echo htmlspecialchars(addslashes($batch['batch_number'])); ?>', '<?php echo htmlspecialchars(addslashes($batch['brand_name'])); ?>', '<?php echo $batch['status'] === 'expired' ? 'Expired' : 'Near Expiry'; ?>')">
                                        <i class="fa-solid fa-trash"></i> Remove
                                    </button>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Categories Tab -->
            <div id="categoriesTab" class="tab-content">
                <div style="display: flex; justify-content: flex-end; margin-bottom: 1rem;">
                    <button class="btn btn-primary" onclick="openModal('addCategoryModal')">
                        <i class="fa-solid fa-plus"></i> Add Category
                    </button>
                </div>
                <div class="table-container">
                    <table class="expiry-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category Name</th>
                                <th>Description</th>
                                <th>Products</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_categories as $cat): 
                                $product_count = $conn->query("SELECT COUNT(*) as count FROM products WHERE category_id = {$cat['id']}")->fetch_assoc()['count'];
                            ?>
                            <tr>
                                <td><?php echo $cat['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($cat['name']); ?></strong></td>
                                <td><?php echo htmlspecialchars($cat['description'] ?? '—'); ?></td>
                                <td><span class="badge"><?php echo $product_count; ?></span></td>
                                <td>
                                    <?php if ($product_count == 0): ?>
                                    <button type="button" class="action-btn btn-delete" 
                                        onclick="confirmCategoryDelete(<?php echo $cat['id']; ?>, '<?php echo htmlspecialchars(addslashes($cat['name'])); ?>')">
                                        <i class="fa-solid fa-trash"></i> Delete
                                    </button>
                                    <?php else: ?>
                                    <span class="badge" style="background: #e0e0e0;">In Use (<?php echo $product_count; ?> products)</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Archived Products Tab -->
            <div id="archivedTab" class="tab-content">
                <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:.75rem;padding:1rem 1.25rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem;">
                    <i class="fa-solid fa-circle-info" style="color:#b45309;font-size:1.2rem;"></i>
                    <div>
                        <strong style="color:#92400e;">Archived Products</strong>
                        <p style="margin:0;color:#78350f;font-size:.875rem;">These products are hidden from active inventory and the POS. All data (batches, costs, history) is preserved. Restore to make them available again, or permanently delete if no longer needed.</p>
                    </div>
                </div>
                <div class="table-container">
                    <table class="expiry-table">
                        <thead>
                            <tr>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Total Stock</th>
                                <th>Archived On</th>
                                <th>Archived By</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($archived_products)): ?>
                            <tr>
                                <td colspan="7" class="empty-state">
                                    <i class="fa-solid fa-box-archive" style="color:#d1d5db;"></i>
                                    <p>No archived products</p>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($archived_products as $ap): ?>
                            <tr style="background:#fafafa;color:#6b7280;">
                                <td>
                                    <strong style="color:#374151;"><?php echo htmlspecialchars($ap['brand_name']); ?></strong>
                                    <div style="font-size:.8rem;color:#9ca3af;"><?php echo htmlspecialchars($ap['generic_name'] ?? ''); ?></div>
                                    <?php if (!empty($ap['strength'])): ?>
                                    <div style="font-size:.7rem;color:#d1d5db;"><?php echo htmlspecialchars($ap['strength']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><span class="category-badge" style="opacity:.7;"><?php echo htmlspecialchars($ap['category_name'] ?? 'Uncategorized'); ?></span></td>
                                <td>₱<?php echo number_format($ap['price'], 2); ?></td>
                                <td><span style="color:#9ca3af;"><?php echo $ap['total_stock']; ?> units</span></td>
                                <td style="font-size:.82rem;"><?php echo $ap['archived_at'] ? date('M d, Y', strtotime($ap['archived_at'])) : '—'; ?></td>
                                <td style="font-size:.82rem;"><?php echo htmlspecialchars($ap['archived_by_name'] ?? '—'); ?></td>
                                <td>
                                    <div class="action-cell" onclick="event.stopPropagation();">
                                        <button class="action-btn btn-stock" onclick="confirmRestore(<?php echo $ap['id']; ?>, '<?php echo htmlspecialchars(addslashes($ap['brand_name'])); ?>')" title="Restore to active inventory">
                                            <i class="fa-solid fa-rotate-left"></i> Restore
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div id="productDetailsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>
                    <i class="fa-solid fa-circle-info"></i>
                    <span id="detailProductName"></span>
                </h2>
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <button class="btn btn-outline" id="editProductBtn" onclick="editCurrentProduct()" title="Edit Medicine" style="padding: 0.5rem 1rem; font-size:0.85rem;">
                        <i class="fa-solid fa-pencil"></i> Edit
                    </button>
                    <button class="modal-close" onclick="closeModal('productDetailsModal')">✕</button>
                </div>
            </div>
            <div class="modal-body" id="productDetailsContent">
                <div style="text-align: center; padding: 3rem;">
                    <i class="fa-solid fa-spinner fa-spin fa-2x" style="color: var(--primary-green);"></i>
                    <p>Loading product details...</p>
                </div>
            </div>
        </div>
    </div>

    <!-- ADD NEW MEDICINE MODAL -->
    <div id="addProductModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h2>
                    <i class="fa-solid fa-plus-circle"></i>
                    Quick Add - Add New Product
                </h2>
                <button class="modal-close" onclick="closeModal('addProductModal')">✕</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_product">
                <div class="modal-body">
                    <!-- Basic Info -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Product Name *</label>
                            <input type="text" name="brand_name" class="form-control" required placeholder="e.g., Dolfenal">
                        </div>
                        <div class="form-group">
                            <label>Category *</label>
                            <select name="category_id" class="form-control" required>
                                <option value="">Select category</option>
                                <option value="1">Analgesic</option>
                                <option value="2">Antibiotic</option>
                                <option value="3">Antihistamine</option>
                                <option value="4">Vitamin</option>
                                <?php foreach ($all_categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Dosage Form *</label>
                            <select name="dosage_form" id="dosageFormSelect" class="form-control" required onchange="updateStrengthOptions()">
                                <option value="">Select Dosage Form</option>
                                <?php
                                $dosage_forms = [
                                    'Tablet', 'Capsule', 'Caplet', 'Syrup', 'Suspension', 'Drops (Oral)',
                                    'Eye Drops', 'Ear Drops', 'Nasal Spray', 'Injection (Ampule)', 'Injection (Vial)',
                                    'Cream', 'Ointment', 'Gel', 'Lotion', 'Suppository', 'Inhaler', 'Nebule',
                                    'Powder', 'Sachet', 'Patch (Transdermal)', 'Solution', 'Elixir', 'Emulsion'
                                ];
                                foreach ($dosage_forms as $form) {
                                    echo "<option value=\"$form\">$form</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Strength</label>
                            <select name="strength" id="strengthSelect" class="form-control">
                                <option value="">Select dosage form first</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Generic Name</label>
                            <input type="text" name="generic_name" class="form-control" placeholder="e.g., Mefenamic Acid">
                        </div>
                        <div class="form-group">
                            <label>Barcode</label>
                            <input type="text" name="barcode" class="form-control" placeholder="Optional">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Low Stock Threshold</label>
                            <input type="number" name="low_stock_threshold" class="form-control" value="10" min="1">
                        </div>
                        <div class="form-group"><!-- spacer --></div>
                    </div>


                    <!-- Prescription Flag -->
                    <div class="form-row" style="margin-top:.5rem;">
                        <div class="form-group" style="display:flex;align-items:center;gap:1rem;background:#fff8f8;border:1px solid #fecaca;border-radius:12px;padding:1rem 1.2rem;">
                            <label class="toggle-label" style="margin:0;cursor:pointer;display:flex;align-items:center;gap:.75rem;font-weight:600;color:#374151;">
                                <input type="checkbox" name="requires_prescription" id="addRxCheck" value="1"
                                       style="width:18px;height:18px;accent-color:#b91c1c;cursor:pointer;"
                                       onchange="document.getElementById('addPrescType').style.display=this.checked?'block':'none'">
                                <span><i class="fa-solid fa-prescription-bottle-medical" style="color:#b91c1c;"></i>
                                &nbsp;Requires Prescription (Rx)</span>
                            </label>
                            <span style="font-size:.78rem;color:#9ca3af;">Check if this medicine requires a doctor's prescription.</span>
                        </div>
                        <div class="form-group" id="addPrescType" style="display:none;">
                            <label>Prescription Type</label>
                            <select name="prescription_type" class="form-control">
                                <option value="Rx-only" selected>Rx-only</option>
                                <option value="OTC">OTC (Over the Counter)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Combined Unit & Stock Section -->
                    <div class="stock-section">
                        <!-- WHAT YOU SELL -->
                        <div class="stock-header" style="background: #e8f5e9; color: var(--primary-green);">
                            <i class="fa-solid fa-tag"></i> What You Sell &nbsp;<small style="font-weight:400; color:var(--gray-500);">(Base Unit — smallest unit dispensed to patient)</small>
                        </div>
                        <div class="stock-row">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Base Unit <span style="color:var(--danger)">*</span> <small style="color:var(--gray-400); font-weight:400;">(smallest selling unit)</small></label>
                                    <select name="base_unit" id="baseUnitSelect" class="form-control" required>
                                        <option value="">Select Base Unit</option>
                                        <option value="Tablet">Tablet</option>
                                        <option value="Capsule">Capsule</option>
                                        <option value="Bottle">Bottle</option>
                                        <option value="Vial">Vial</option>
                                        <option value="Ampule">Ampule</option>
                                        <option value="Sachet">Sachet</option>
                                        <option value="Tube">Tube</option>
                                        <option value="Piece">Piece</option>
                                        <option value="Pack">Pack</option>
                                        <option value="Box">Box</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Selling Price per Base Unit (₱) <span style="color:var(--danger)">*</span></label>
                                    <input type="number" name="price" class="form-control" step="0.01" min="0" required placeholder="0.00">
                                </div>
                            </div>
                        </div>

                        <!-- WHAT YOU BUY -->
                        <div class="stock-header" style="background: #fff8e1; color: #b45309; border-top: 1px solid var(--gray-200);">
                            <i class="fa-solid fa-truck"></i> What You Buy &nbsp;<small style="font-weight:400; color:var(--gray-500);">(Purchase Unit — how stock arrives)</small>
                        </div>
                        <div class="stock-row">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Purchase Unit <small style="color:var(--gray-400); font-weight:400;">(how you bought it)</small></label>
                                    <select name="purchase_unit" id="purchaseUnitSelect" class="form-control">
                                        <option value="">Select Purchase Unit</option>
                                        <option value="Tablet">Tablet</option>
                                        <option value="Capsule">Capsule</option>
                                        <option value="Bottle">Bottle</option>
                                        <option value="Vial">Vial</option>
                                        <option value="Ampule">Ampule</option>
                                        <option value="Sachet">Sachet</option>
                                        <option value="Tube">Tube</option>
                                        <option value="Piece">Piece</option>
                                        <option value="Pack">Pack</option>
                                        <option value="Box">Box</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Conversion Factor <small style="color:var(--gray-400); font-weight:400;">(base units per purchase unit)</small></label>
                                    <input type="number" name="conversion_factor" id="conversionFactor" class="form-control" value="1" min="1" oninput="computeStock()">
                                    <small style="color:var(--gray-500); font-size:0.75rem;">e.g., 1 Box = 100 Tablets → enter <strong>100</strong></small>
                                </div>
                            </div>
                        </div>
                        <div class="stock-row">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Purchase Quantity <small style="color:var(--gray-400); font-weight:400;">(number of purchase units)</small></label>
                                    <input type="number" name="purchase_qty" id="purchaseQty" class="form-control" value="0" min="0" oninput="computeStock()">
                                </div>
                                <div class="form-group">
                                    <label>Cost per Purchase Unit (₱)</label>
                                    <input type="number" name="cost" id="costPerPurchase" class="form-control" step="0.01" min="0" required placeholder="0.00" oninput="computeStock()">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>
                                        <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;"></i>
                                        Damaged Units
                                        <small style="color:var(--gray-400);font-weight:400;">(deducted from available stock, cannot be sold)</small>
                                    </label>
                                    <input type="number" name="initial_damage" id="initialDamage" class="form-control" value="0" min="0" oninput="computeStock()">
                                </div>
                                <div class="form-group"><!-- spacer --></div>
                            </div>
                        </div>

                        <!-- AUTO-COMPUTED -->
                        <div class="stock-header" style="background: #f0f4ff; color: #3730a3; border-top: 1px solid var(--gray-200);">
                            <i class="fa-solid fa-calculator"></i> Auto-Computed &nbsp;<small style="font-weight:400; color:var(--gray-500);">(read-only — calculated by system)</small>
                        </div>
                        <div class="stock-row" style="background: #fafbff;">
                            <div class="form-row">
                                <div class="form-group">
                                    <label style="color:#3730a3;">
                                        <i class="fa-solid fa-lock" style="font-size:0.7rem;"></i>
                                        Stock in Base Unit
                                        <small style="color:var(--gray-400); font-weight:400;">(purchase_qty × conversion_factor)</small>
                                    </label>
                                    <input type="number" name="initial_qty" id="stockBaseUnit" class="form-control"
                                           readonly placeholder="0"
                                           style="background:#e8eaf6; font-weight:700; color:#3730a3;">
                                </div>
                                <div class="form-group">
                                    <label style="color:#3730a3;">
                                        <i class="fa-solid fa-lock" style="font-size:0.7rem;"></i>
                                        Cost per Base Unit (₱)
                                        <small style="color:var(--gray-400); font-weight:400;">(cost ÷ conversion_factor)</small>
                                    </label>
                                    <input type="number" id="costPerBase" class="form-control"
                                           readonly placeholder="0.00"
                                           style="background:#e8eaf6; font-weight:700; color:#3730a3;">
                                </div>
                            </div>
                        </div>

                        <!-- EXPIRY & DATES -->
                        <div class="stock-header" style="border-top: 1px solid var(--gray-200);">
                            <i class="fa-solid fa-calendar"></i> Dates & Notes
                        </div>
                        <div class="stock-row">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Expiration Date</label>
                                    <input type="date" name="expiry_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+2 years')); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Date Received</label>
                                    <input type="date" name="date_received" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="stock-row">
                            <div class="form-group">
                                <label>Notes</label>
                                <textarea name="notes" class="form-control" rows="2" placeholder="e.g., Prescription required, store in cool dry place"></textarea>
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1rem;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addProductModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-save"></i> Add Product
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ENHANCED RESTOCK MODAL - Matches Add Product Flow -->
    <div id="restockModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h2>
                    <i class="fa-solid fa-boxes"></i>
                    Restock: <span id="restockProductName"></span>
                </h2>
                <button class="modal-close" onclick="closeModal('restockModal')">✕</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_stock">
                <input type="hidden" name="product_id" id="restockProductId">
                <div class="modal-body">
                    <!-- Current Stock Info -->
                    <div class="current-stock-info">
                        <div class="info-row">
                            <span class="info-label">Product:</span>
                            <span class="info-value" id="restockProductInfo"></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Current batch expires on:</span>
                            <span class="info-value" id="currentBatchExpiry">-</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">On hand:</span>
                            <span class="info-value" id="currentStock">0 units</span>
                        </div>
                    </div>

                    <!-- AUTO-GENERATED BATCH NUMBER -->
                    <div class="form-group">
                        <label>Batch Number (Auto-generated)</label>
                        <input type="text" class="form-control" id="batchNumberDisplay" value="Will be generated on save" readonly>
                        <div class="batch-preview">
                            <i class="fa-solid fa-info-circle"></i> New batch: <strong id="batchPreview"><?php echo generateBatchNumber($conn); ?></strong>
                        </div>
                    </div>

                    <!-- WHAT YOU BUY Section - Matches Add Product -->
                    <div class="stock-section">
                        <div class="stock-header" style="background: #fff8e1; color: #b45309;">
                            <i class="fa-solid fa-truck"></i> What You Buy &nbsp;<small style="font-weight:400; color:var(--gray-500);">(Purchase Unit — how stock arrives)</small>
                        </div>
                        
                        <div class="stock-row">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Purchase Unit <small style="color:var(--gray-400);">(how you bought it)</small></label>
                                    <select name="purchase_unit" id="restockUnit" class="form-control" onchange="updateRestockConversion()">
                                        <option value="">Same as Base Unit</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Conversion Factor <small style="color:var(--gray-400);">(base units per purchase unit)</small></label>
                                    <input type="number" name="conversion_factor" id="restockConversion" class="form-control" value="1" min="1" oninput="updateRestockBasePreview()">
                                    <small id="restockConvHint" style="color:var(--gray-500);font-size:.75rem;"></small>
                                </div>
                            </div>
                        </div>

                        <div class="stock-row">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Purchase Quantity <small style="color:var(--gray-400);">(number of purchase units)</small></label>
                                    <input type="number" name="purchase_qty" id="restockQuantity" class="form-control" value="0" min="0" required oninput="updateRestockBasePreview()">
                                </div>
                                <div class="form-group">
                                    <label>Cost per Purchase Unit (₱)</label>
                                    <input type="number" name="purchase_cost" id="restockCost" class="form-control" step="0.01" min="0" required placeholder="0.00" oninput="updateRestockBasePreview()">
                                </div>
                            </div>
                        </div>

                        <div class="stock-row">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>
                                        <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b;"></i>
                                        Damaged Units
                                        <small style="color:var(--gray-400);font-weight:400;">(in base units, cannot be sold)</small>
                                    </label>
                                    <input type="number" name="damage_quantity" id="restockDamage" class="form-control" min="0" value="0" placeholder="0" oninput="updateRestockBasePreview()">
                                </div>
                                <div class="form-group">
                                    <label>
                                        <i class="fa-solid fa-tag" style="color:#2e7d32;"></i>
                                        New Selling Price (₱)
                                        <small style="color:var(--gray-400);">optional — per base unit</small>
                                    </label>
                                    <input type="number" name="batch_selling_price" id="restockSellingPrice" class="form-control" step="0.01" min="0" placeholder="Leave blank to keep current">
                                    <small id="restockCurrentPriceHint" style="color:var(--gray-500);font-size:.75rem;margin-top:.25rem;display:block;"></small>
                                </div>
                            </div>
                        </div>

                        <!-- AUTO-COMPUTED -->
                        <div class="stock-header" style="background: #f0f4ff; color: #3730a3;">
                            <i class="fa-solid fa-calculator"></i> Auto-Computed &nbsp;<small style="font-weight:400; color:var(--gray-500);">(read-only — calculated by system)</small>
                        </div>
                        
                        <div class="stock-row" style="background: #fafbff;">
                            <div class="form-row">
                                <div class="form-group">
                                    <label style="color:#3730a3;">
                                        <i class="fa-solid fa-lock" style="font-size:0.7rem;"></i>
                                        Stock in Base Unit
                                        <small style="color:var(--gray-400);">(purchase_qty × conversion)</small>
                                    </label>
                                    <input type="number" id="restockBaseQtyDisplay" class="form-control" readonly placeholder="0" style="background:#e8eaf6; font-weight:700; color:#3730a3;">
                                </div>
                                <div class="form-group">
                                    <label style="color:#3730a3;">
                                        <i class="fa-solid fa-lock" style="font-size:0.7rem;"></i>
                                        Sellable Quantity
                                        <small style="color:var(--gray-400);">(base units − damaged)</small>
                                    </label>
                                    <input type="number" id="restockAvailQtyDisplay" class="form-control" readonly placeholder="0" style="background:#e8eaf6; font-weight:700; color:#16a34a;">
                                </div>
                            </div>
                            <div class="form-row" style="margin-top:0.5rem;">
                                <div class="form-group">
                                    <label style="color:#3730a3;">
                                        <i class="fa-solid fa-lock" style="font-size:0.7rem;"></i>
                                        Cost per Base Unit (₱)
                                        <small style="color:var(--gray-400);">(cost ÷ conversion)</small>
                                    </label>
                                    <input type="number" id="restockCostPerBase" class="form-control" readonly placeholder="0.00" style="background:#e8eaf6; font-weight:700; color:#3730a3;">
                                </div>
                                <div class="form-group">
                                    <label style="color:#3730a3;">
                                        <i class="fa-solid fa-lock" style="font-size:0.7rem;"></i>
                                        Total Cost (₱)
                                    </label>
                                    <input type="number" id="restockTotalCost" class="form-control" readonly placeholder="0.00" style="background:#e8eaf6; font-weight:700; color:#3730a3;">
                                </div>
                            </div>
                        </div>

                        <!-- EXPIRY & DATES -->
                        <div class="stock-header">
                            <i class="fa-solid fa-calendar"></i> Dates & Notes
                        </div>
                        
                        <div class="stock-row">
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Expiration Date *</label>
                                    <input type="date" name="expiry_date" class="form-control" required value="<?php echo date('Y-m-d', strtotime('+2 years')); ?>">
                                </div>
                                <div class="form-group">
                                    <label>Date Received</label>
                                    <input type="date" name="date_received" class="form-control" value="<?php echo date('Y-m-d'); ?>" readonly>
                                </div>
                            </div>
                        </div>
                        
                        <div class="stock-row">
                            <div class="form-group">
                                <label>Notes / Handling Instructions</label>
                                <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes for the receiving team"></textarea>
                            </div>
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1rem;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('restockModal')">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fa-solid fa-check"></i> Submit Restock
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Category Modal -->
    <div id="addCategoryModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <div class="modal-header">
                <h2>
                    <i class="fa-solid fa-tag"></i>
                    Add Category
                </h2>
                <button class="modal-close" onclick="closeModal('addCategoryModal')">✕</button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="add_category">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Category Name *</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('addCategoryModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Category</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Product Modal - SIMPLIFIED - NO PRICE, NO COST -->
    <div id="editProductModal" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <h2>
                    <i class="fa-solid fa-pencil"></i>
                    Edit Medicine
                </h2>
                <button class="modal-close" onclick="closeModal('editProductModal')">✕</button>
            </div>
            <form method="POST" action="inventory.php">
                <input type="hidden" name="action" value="edit_product">
                <input type="hidden" name="product_id" id="editProductId">
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Product Name *</label>
                            <input type="text" name="brand_name" id="editBrandName" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label>Category *</label>
                            <select name="category_id" id="editCategoryId" class="form-control" required>
                                <option value="">Select category</option>
                                <?php foreach ($all_categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Dosage Form *</label>
                            <select name="dosage_form" id="editDosageForm" class="form-control" required onchange="updateEditStrengthOptions()">
                                <option value="">Select Dosage Form</option>
                                <?php
                                $dosage_forms = [
                                    'Tablet', 'Capsule', 'Caplet', 'Syrup', 'Suspension', 'Drops (Oral)',
                                    'Eye Drops', 'Ear Drops', 'Nasal Spray', 'Injection (Ampule)', 'Injection (Vial)',
                                    'Cream', 'Ointment', 'Gel', 'Lotion', 'Suppository', 'Inhaler', 'Nebule',
                                    'Powder', 'Sachet', 'Patch (Transdermal)', 'Solution', 'Elixir', 'Emulsion'
                                ];
                                foreach ($dosage_forms as $form) {
                                    echo "<option value=\"$form\">$form</option>";
                                }
                                ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Strength</label>
                            <select name="strength" id="editStrengthSelect" class="form-control">
                                <option value="">Select dosage form first</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Generic Name</label>
                            <input type="text" name="generic_name" id="editGenericName" class="form-control">
                        </div>
                        <div class="form-group">
                            <label>Barcode</label>
                            <input type="text" name="barcode" id="editBarcode" class="form-control">
                        </div>
                    </div>
                    
                    <!-- NO PRICE FIELD, NO COST FIELD - ONLY LOW STOCK THRESHOLD -->
                    <div class="form-row">
                        <div class="form-group">
                            <label>Low Stock Threshold</label>
                            <input type="number" name="low_stock_threshold" id="editThreshold" class="form-control" min="1">
                        </div>
                        <div class="form-group"><!-- spacer --></div>
                    </div>

                    <!-- Prescription Flag -->
                    <div class="form-row" style="margin-top:.5rem;">
                        <div class="form-group" style="display:flex;align-items:center;gap:1rem;background:#fff8f8;border:1px solid #fecaca;border-radius:12px;padding:1rem 1.2rem;">
                            <label style="margin:0;cursor:pointer;display:flex;align-items:center;gap:.75rem;font-weight:600;color:#374151;">
                                <input type="checkbox" name="requires_prescription" id="editRxCheck" value="1"
                                       style="width:18px;height:18px;accent-color:#b91c1c;cursor:pointer;"
                                       onchange="document.getElementById('editPrescType').style.display=this.checked?'block':'none'">
                                <span><i class="fa-solid fa-prescription-bottle-medical" style="color:#b91c1c;"></i>
                                &nbsp;Requires Prescription (Rx)</span>
                            </label>
                        </div>
                        <div class="form-group" id="editPrescType" style="display:none;">
                            <label>Prescription Type</label>
                            <select name="prescription_type" id="editPrescTypeSelect" class="form-control">
                                <option value="Rx-only">Rx-only</option>
                                <option value="OTC">OTC</option>
                            </select>
                        </div>
                    </div>

                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 1rem;">
                        <button type="button" class="btn btn-outline" onclick="closeModal('editProductModal')">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-save"></i> Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Custom Confirm Dialog -->
    <div id="confirmDialog">
        <div style="background:#fff;border-radius:1.25rem;padding:2rem;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.25);text-align:center;">
            <div id="confirmIconWrap" style="width:56px;height:56px;background:#fef2f2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1rem;">
                <i id="confirmIcon" class="fa-solid fa-triangle-exclamation" style="color:#dc2626;font-size:1.5rem;"></i>
            </div>
            <h3 id="confirmTitle" style="font-size:1.1rem;font-weight:700;color:#1f2937;margin-bottom:.75rem;">Confirm</h3>
            <p id="confirmMessage" style="color:#6b7280;margin-bottom:1.5rem;font-size:.9rem;line-height:1.5;"></p>
            <div style="display:flex;gap:.75rem;justify-content:center;">
                <button id="confirmCancel" style="padding:.65rem 1.5rem;border:1px solid #d1d5db;background:#fff;border-radius:.75rem;font-weight:600;color:#374151;cursor:pointer;font-size:.9rem;">Cancel</button>
                <button id="confirmOk" style="padding:.65rem 1.5rem;background:#dc2626;border:none;border-radius:.75rem;font-weight:600;color:#fff;cursor:pointer;font-size:.9rem;">
                    <i id="confirmOkIcon" class="fa-solid fa-trash"></i> <span id="confirmOkText">Delete</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Confirm Removal Modal -->
    <div id="removeConfirmModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:10000;align-items:center;justify-content:center;">
        <div style="background:#fff;border-radius:1.5rem;padding:2rem 2.5rem;max-width:440px;width:90%;box-shadow:0 25px 60px rgba(0,0,0,.3);text-align:center;animation:modalPop .25s ease;">
            <div style="width:64px;height:64px;background:#fef2f2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.2rem;">
                <i class="fa-solid fa-trash-can" style="color:#dc2626;font-size:1.8rem;"></i>
            </div>
            <h3 style="font-size:1.2rem;font-weight:800;color:#1f2937;margin-bottom:.5rem;">Confirm Removal</h3>
            <p id="removeConfirmMessage" style="color:#6b7280;margin-bottom:1.5rem;font-size:.9rem;line-height:1.6;">Are you sure you want to permanently remove this item?</p>
            <div style="background:#fff7f7;border:1px solid #fecaca;border-radius:.75rem;padding:.75rem 1rem;margin-bottom:1.5rem;font-size:.85rem;color:#991b1b;">
                <i class="fa-solid fa-exclamation-circle"></i> This will permanently remove the batch from the database and cannot be undone.
            </div>
            <div style="display:flex;gap:.75rem;justify-content:center;">
                <button onclick="closeRemoveConfirmModal()" style="padding:.7rem 1.8rem;border:2px solid #d1d5db;background:#fff;border-radius:.9rem;font-weight:700;color:#374151;cursor:pointer;font-size:.9rem;transition:all .2s;">Cancel</button>
                <button id="removeConfirmOk" style="padding:.7rem 1.8rem;background:linear-gradient(135deg,#dc2626,#b91c1c);border:none;border-radius:.9rem;font-weight:700;color:#fff;cursor:pointer;font-size:.9rem;display:flex;align-items:center;gap:.5rem;transition:all .2s;">
                    <i class="fa-solid fa-trash"></i> Confirm Remove
                </button>
            </div>
        </div>
    </div>

    <script>
    // Global variables
    let currentTab = 'productsTab';
    let _expiryFilter = 'all';
    let searchTimeout;
    let currentProductId = null;
    let confirmCallback = null;

    // ============================================================
    // TAB FUNCTIONS
    // ============================================================
    function openTab(evt, tabId) {
        document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        evt.currentTarget.classList.add('active');
        
        currentTab = tabId;
        
        if (tabId === 'expiryTab') {
            _expiryFilter = 'all';
            document.querySelectorAll('.expiry-filter-btn').forEach(b => {
                b.classList.remove('active');
                if (b.dataset.filter === 'all') b.classList.add('active');
            });
        }
        
        performSearch();
    }

    // ============================================================
    // SEARCH FUNCTIONS
    // ============================================================
    function performSearch() {
        const searchInput = document.getElementById('searchInput');
        const categoryFilter = document.getElementById('categoryFilter');
        
        if (!searchInput) return;
        
        const searchTerm = searchInput.value.toLowerCase().trim();
        const catId = categoryFilter ? categoryFilter.value : '';
        
        switch(currentTab) {
            case 'productsTab':
                searchProductsTab(searchTerm, catId);
                break;
            case 'expiryTab':
                searchExpiryTab(searchTerm, catId);
                break;
            case 'categoriesTab':
                searchCategoriesTab(searchTerm);
                break;
        }
    }

    function searchProductsTab(searchTerm, catId) {
        document.querySelectorAll('.product-row').forEach(row => {
            const matchesSearch = !searchTerm ||
                (row.dataset.name || '').includes(searchTerm) ||
                (row.dataset.generic || '').includes(searchTerm) ||
                (row.dataset.barcode || '').includes(searchTerm) ||
                (row.dataset.unit || '').includes(searchTerm) ||
                (row.dataset.dosage || '').includes(searchTerm) ||
                (row.dataset.category || '').includes(searchTerm);
            
            const matchesCat = !catId || row.dataset.catId === catId;
            
            row.style.display = (matchesSearch && matchesCat) ? '' : 'none';
        });
    }

    function searchExpiryTab(searchTerm, catId) {
        let selectedCategoryName = '';
        if (catId) {
            const categorySelect = document.getElementById('categoryFilter');
            const selectedOption = categorySelect?.options[categorySelect.selectedIndex];
            if (selectedOption) {
                selectedCategoryName = selectedOption.textContent.split(' (')[0].trim().toLowerCase();
            }
        }
        
        document.querySelectorAll('.expiry-row').forEach(row => {
            const matchesSearch = !searchTerm || (row.dataset.name || '').includes(searchTerm);
            const matchesFilter = _expiryFilter === 'all' || row.dataset.status === _expiryFilter;
            
            const categoryCell = row.querySelector('td:nth-child(1)');
            const categoryText = categoryCell ? categoryCell.textContent.trim().toLowerCase() : '';
            
            let matchesCategory = true;
            if (catId && selectedCategoryName) {
                matchesCategory = categoryText.includes(selectedCategoryName);
            }
            
            row.style.display = (matchesSearch && matchesFilter && matchesCategory) ? '' : 'none';
        });
    }

    function searchCategoriesTab(searchTerm) {
        document.querySelectorAll('#categoriesTab tbody tr').forEach(row => {
            const text = row.textContent.toLowerCase();
            const matchesSearch = !searchTerm || text.includes(searchTerm);
            row.style.display = matchesSearch ? '' : 'none';
        });
    }

    function filterExpiry(filter) {
        _expiryFilter = filter;
        document.querySelectorAll('.expiry-filter-btn').forEach(b => {
            b.classList.toggle('active', b.dataset.filter === filter);
        });
        performSearch();
    }

    // ============================================================
    // REMOVE CONFIRMATION MODAL
    // ============================================================
    let _pendingRemoveBatchId = null;

    function showRemoveConfirmModal(batchId, batchNum, productName, statusText) {
        _pendingRemoveBatchId = batchId;
        const msg = document.getElementById('removeConfirmMessage');
        if (msg) {
            msg.innerHTML = `Are you sure you want to permanently remove:<br><strong>${productName}</strong><br>Batch: <code>${batchNum}</code><br>Status: <span style="color:${statusText==='Expired'?'#dc2626':'#f57c00'};font-weight:700;">${statusText}</span>`;
        }
        const modal = document.getElementById('removeConfirmModal');
        if (modal) modal.style.display = 'flex';

        const okBtn = document.getElementById('removeConfirmOk');
        if (okBtn) {
            okBtn.onclick = function() {
                if (!_pendingRemoveBatchId) return;
                okBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Removing...';
                okBtn.disabled = true;

                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_batch">
                    <input type="hidden" name="batch_id" value="${_pendingRemoveBatchId}">
                `;
                document.body.appendChild(form);
                form.submit();
            };
        }
    }

    function closeRemoveConfirmModal() {
        const modal = document.getElementById('removeConfirmModal');
        if (modal) modal.style.display = 'none';
        _pendingRemoveBatchId = null;
    }

    document.addEventListener('click', function(e) {
        const modal = document.getElementById('removeConfirmModal');
        if (modal && e.target === modal) closeRemoveConfirmModal();
    });

    function debouncedSearch() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(performSearch, 300);
    }

    // ============================================================
    // ARCHIVE / RESTORE / DELETE FUNCTIONS
    // ============================================================
    function confirmArchive(id, name) {
        const message = `Archive "${name}"?\n\nThe product will be hidden from active inventory and the POS.\nAll batch data, costs, and history are preserved.\nYou can restore it at any time from the Archived tab.`;

        showConfirm(message, function() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'archive_product';
            form.appendChild(actionInput);

            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'id';
            idInput.value = id;
            form.appendChild(idInput);

            document.body.appendChild(form);
            form.submit();
        }, 'archive');
    }

    function confirmRestore(id, name) {
        const message = `Restore "${name}" to active inventory?\n\nThe product will be visible in inventory and available in the POS again.`;

        showConfirm(message, function() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'restore_product';
            form.appendChild(actionInput);

            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'id';
            idInput.value = id;
            form.appendChild(idInput);

            document.body.appendChild(form);
            form.submit();
        }, 'restore');
    }

    function confirmCategoryDelete(id, name) {
        const message = `Delete category "${name}"? This cannot be undone.`;
        
        showConfirm(message, function() {
            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'delete_category';
            form.appendChild(actionInput);
            
            const idInput = document.createElement('input');
            idInput.type = 'hidden';
            idInput.name = 'id';
            idInput.value = id;
            form.appendChild(idInput);
            
            document.body.appendChild(form);
            form.submit();
        });
    }

    function confirmDeleteBatch(batchId, batchNumber) {
        // Redirects to in-system modal instead of browser confirm()
        showRemoveConfirmModal(batchId, batchNumber, batchNumber, 'Expired');
    }

    function showConfirm(message, callback, type) {
        const dialog = document.getElementById('confirmDialog');
        const msgEl = document.getElementById('confirmMessage');
        const okBtn = document.getElementById('confirmOk');
        const cancelBtn = document.getElementById('confirmCancel');
        const titleEl = document.getElementById('confirmTitle');
        const iconEl = document.getElementById('confirmIcon');
        const iconWrap = document.getElementById('confirmIconWrap');
        const okIconEl = document.getElementById('confirmOkIcon');
        const okTextEl = document.getElementById('confirmOkText');

        if (!dialog || !msgEl || !okBtn || !cancelBtn) {
            if (confirm(message)) callback();
            return;
        }

        // Style based on type
        if (type === 'archive') {
            titleEl.textContent = 'Archive Product';
            iconWrap.style.background = '#fff8e1';
            iconEl.className = 'fa-solid fa-box-archive';
            iconEl.style.color = '#b45309';
            okBtn.style.background = '#f59e0b';
            okIconEl.className = 'fa-solid fa-box-archive';
            okTextEl.textContent = 'Archive';
        } else if (type === 'restore') {
            titleEl.textContent = 'Restore Product';
            iconWrap.style.background = '#f0fdf4';
            iconEl.className = 'fa-solid fa-rotate-left';
            iconEl.style.color = '#16a34a';
            okBtn.style.background = '#16a34a';
            okIconEl.className = 'fa-solid fa-rotate-left';
            okTextEl.textContent = 'Restore';
        } else {
            titleEl.textContent = 'Confirm Delete';
            iconWrap.style.background = '#fef2f2';
            iconEl.className = 'fa-solid fa-triangle-exclamation';
            iconEl.style.color = '#dc2626';
            okBtn.style.background = '#dc2626';
            okIconEl.className = 'fa-solid fa-trash';
            okTextEl.textContent = 'Delete';
        }

        msgEl.textContent = message;
        confirmCallback = callback;
        dialog.style.display = 'flex';

        okBtn.onclick = function() {
            dialog.style.display = 'none';
            if (confirmCallback) {
                confirmCallback();
                confirmCallback = null;
            }
        };

        cancelBtn.onclick = function() {
            dialog.style.display = 'none';
            confirmCallback = null;
        };
    }

    // ============================================================
    // MODAL FUNCTIONS
    // ============================================================
    function openModal(id) {
        document.getElementById(id).classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('active');
        document.body.style.overflow = '';
    }

    // ============================================================
    // PRODUCT DETAILS FUNCTIONS - SIMPLIFIED (NO PRICE/COST)
    // ============================================================
    function showProductDetails(productId) {
        currentProductId = productId;
        openModal('productDetailsModal');
        
        document.getElementById('productDetailsContent').innerHTML = `
            <div style="text-align: center; padding: 3rem;">
                <i class="fa-solid fa-spinner fa-spin fa-2x" style="color: var(--primary-green);"></i>
                <p>Loading product details...</p>
            </div>
        `;
        
        fetch('../ajax/get_product_details.php?product_id=' + productId)
            .then(response => response.text())
            .then(html => {
                document.getElementById('productDetailsContent').innerHTML = html;
                
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = html;
                const nameElement = tempDiv.querySelector('h2');
                if (nameElement) {
                    document.getElementById('detailProductName').textContent = nameElement.textContent;
                }
            })
            .catch(error => {
                document.getElementById('productDetailsContent').innerHTML = `
                    <div class="empty-state">
                        <i class="fa-solid fa-exclamation-circle"></i>
                        <p>Error loading product details</p>
                    </div>
                `;
            });
    }

    // SIMPLIFIED editCurrentProduct function - NO PRICE, NO COST
    function editCurrentProduct() {
        if (!currentProductId) return;
        
        fetch('../ajax/get_product_json.php?product_id=' + currentProductId)
            .then(r => r.json())
            .then(data => {
                if (!data) {
                    alert('Error loading product data');
                    return;
                }
                
                // Set form values (NO PRICE, NO COST)
                document.getElementById('editProductId').value = data.id || currentProductId;
                document.getElementById('editBrandName').value = data.brand_name || '';
                document.getElementById('editGenericName').value = data.generic_name || '';
                document.getElementById('editBarcode').value = data.barcode || '';
                document.getElementById('editCategoryId').value = data.category_id || '';
                document.getElementById('editThreshold').value = data.low_stock_threshold || 10;
                
                // Set Rx checkbox
                var rxCheck = document.getElementById('editRxCheck');
                if (rxCheck) {
                    rxCheck.checked = !!(data.requires_prescription);
                    var editPrescType = document.getElementById('editPrescType');
                    if (editPrescType) {
                        editPrescType.style.display = rxCheck.checked ? 'block' : 'none';
                    }
                }
                
                // Set prescription type
                var editPrescSel = document.getElementById('editPrescTypeSelect');
                if (editPrescSel) {
                    editPrescSel.value = data.prescription_type || 'Rx-only';
                }
                
                // Set dosage form and strength
                document.getElementById('editDosageForm').value = data.dosage_form || '';
                populateStrengthSelect(document.getElementById('editStrengthSelect'), data.dosage_form || '', data.strength || '');
                
                // Close details modal and open edit modal
                closeModal('productDetailsModal');
                openModal('editProductModal');
            })
            .catch(error => {
                console.error('Error loading product data:', error);
                alert('Failed to load product data for editing');
            });
    }

    // ============================================================
    // STOCK FUNCTIONS
    // ============================================================
    function computeStock() {
        const qty    = parseFloat(document.getElementById('purchaseQty').value)      || 0;
        const conv   = parseFloat(document.getElementById('conversionFactor').value) || 1;
        const cost   = parseFloat(document.getElementById('costPerPurchase').value)  || 0;
        const damage = parseFloat(document.getElementById('initialDamage')?.value)   || 0;
        const base   = qty * conv;
        const avail  = Math.max(0, base - damage);

        document.getElementById('stockBaseUnit').value = avail.toFixed(0);
        document.getElementById('costPerBase').value   = conv > 0 ? (cost / conv).toFixed(2) : '';
    }

    // ENHANCED: Restock modal functions
    function openRestockModal(productId, productName) {
        document.getElementById('restockProductId').value = productId;
        document.getElementById('restockProductName').textContent = productName;
        document.getElementById('restockProductInfo').textContent = productName;

        // Reset all fields
        document.getElementById('restockQuantity').value = '';
        document.getElementById('restockCost').value = '';
        document.getElementById('restockDamage').value = '0';
        document.getElementById('restockSellingPrice').value = '';
        document.getElementById('restockConversion').value = '1';
        document.getElementById('restockBaseQtyDisplay').value = '';
        document.getElementById('restockAvailQtyDisplay').value = '';
        document.getElementById('restockCostPerBase').value = '';
        document.getElementById('restockTotalCost').value = '';
        
        const unitSel = document.getElementById('restockUnit');
        unitSel.innerHTML = '<option value="">Same as Base Unit</option>';
        document.getElementById('restockConvHint').textContent = '';
        
        window._restockUnits = [];
        window._restockBaseUnit = '';
        window._restockBasePrice = 0;

        // Fetch product details
        fetch('../ajax/get_product_json.php?product_id=' + productId)
            .then(r => r.json())
            .then(data => {
                if (!data) return;
                window._restockBaseUnit = data.base_unit || 'piece';
                window._restockBasePrice = parseFloat(data.price) || 0;
                document.getElementById('restockProductInfo').textContent = data.brand_name + ' (' + window._restockBaseUnit + ')';
                document.getElementById('currentStock').textContent = (data.total_stock || 0) + ' ' + window._restockBaseUnit;
                document.getElementById('currentBatchExpiry').textContent = data.next_expiry || 'No existing batch';
                
                const spHint = document.getElementById('restockCurrentPriceHint');
                if (spHint && data.price) {
                    spHint.innerHTML = '<i class="fa-solid fa-circle-info" style="color:#0ea5e9;"></i> Current price: <strong>₱' + parseFloat(data.price).toFixed(2) + ' / ' + window._restockBaseUnit + '</strong>. Enter a new price below to apply it to this batch only.';
                }
            });

        // Fetch available units
        fetch('../ajax/get_product_units.php?product_id=' + productId)
            .then(r => r.json())
            .then(data => {
                window._restockUnits = data.units || [];
                
                window._restockUnits.forEach(u => {
                    if (parseInt(u.conversion_to_base) > 1) {
                        const opt = document.createElement('option');
                        opt.value = u.unit_name;
                        opt.dataset.conv = u.conversion_to_base;
                        opt.textContent = u.unit_name + ' (= ' + u.conversion_to_base + ' ' + window._restockBaseUnit + ')';
                        unitSel.appendChild(opt);
                    }
                });
            });

        const date = new Date();
        const bpNum = date.getFullYear() + String(date.getMonth()+1).padStart(2,'0') + String(date.getDate()).padStart(2,'0') + '-' + Math.floor(Math.random()*900+100);
        document.getElementById('batchPreview').textContent = bpNum;

        openModal('restockModal');
    }

    function updateRestockConversion() {
        const sel = document.getElementById('restockUnit');
        const conv = parseInt(sel.selectedOptions[0]?.dataset?.conv || '1');
        document.getElementById('restockConversion').value = conv;
        const hint = document.getElementById('restockConvHint');
        if (conv > 1) {
            hint.textContent = '1 ' + sel.value + ' = ' + conv + ' ' + (window._restockBaseUnit || 'base units');
        } else {
            hint.textContent = '';
        }
        updateRestockBasePreview();
    }

    function updateRestockBasePreview() {
        const qty    = parseInt(document.getElementById('restockQuantity')?.value) || 0;
        const conv   = parseInt(document.getElementById('restockConversion')?.value) || 1;
        const cost   = parseFloat(document.getElementById('restockCost')?.value) || 0;
        const damage = parseInt(document.getElementById('restockDamage')?.value) || 0;
        const base   = qty * conv;
        const avail  = Math.max(0, base - damage);
        const costPerBase = conv > 0 ? cost / conv : 0;
        const totalCost = cost * qty;

        document.getElementById('restockBaseQtyDisplay').value = base;
        document.getElementById('restockAvailQtyDisplay').value = avail;
        document.getElementById('restockCostPerBase').value = costPerBase.toFixed(2);
        document.getElementById('restockTotalCost').value = totalCost.toFixed(2);
    }

    // ============================================================
    // STRENGTH OPTIONS
    // ============================================================
    const strengthMap = {
        'Tablet': ['125 mg','250 mg','500 mg','650 mg','1000 mg'],
        'Capsule': ['125 mg','250 mg','500 mg','650 mg','1000 mg'],
        'Caplet': ['125 mg','250 mg','500 mg','650 mg','1000 mg'],
        'Syrup': ['100 mg/5ml','125 mg/5ml','200 mg/5ml','250 mg/5ml'],
        'Suspension': ['100 mg/5ml','125 mg/5ml','200 mg/5ml','250 mg/5ml'],
        'Drops (Oral)': ['100 mg/ml','125 mg/ml','50 mg/ml'],
        'Eye Drops': ['0.1%','0.3%','0.5%','1%'],
        'Ear Drops': ['0.1%','0.3%','0.5%','1%'],
        'Nasal Spray': ['50 mcg','100 mcg','0.05%','0.1%'],
        'Injection (Ampule)': ['250 mg/2ml','500 mg/2ml','1g/10ml'],
        'Injection (Vial)': ['250 mg/2ml','500 mg/2ml','1g/10ml'],
        'Cream': ['1%','2%','5%','10%'],
        'Ointment': ['1%','2%','5%','10%'],
        'Gel': ['1%','2%','5%'],
        'Lotion': ['1%','2%','5%'],
        'Suppository': ['125 mg','250 mg','500 mg'],
        'Inhaler': ['100 mcg','200 mcg','400 mcg'],
        'Nebule': ['0.5 mg/ml','1 mg/ml','2.5 mg/2.5ml'],
        'Powder': ['125 mg','250 mg','500 mg'],
        'Sachet': ['250 mg','500 mg','1 g'],
        'Patch (Transdermal)': ['5 mg/24hr','10 mg/24hr','25 mcg/hr','50 mcg/hr'],
        'Solution': ['125 mg/5ml','250 mg/5ml','1%','2%'],
        'Elixir': ['125 mg/5ml','250 mg/5ml'],
        'Emulsion': ['125 mg/5ml','250 mg/5ml'],
    };

    function populateStrengthSelect(selectEl, dosageForm, currentVal) {
        const opts = strengthMap[dosageForm] || [];
        selectEl.innerHTML = '<option value="">Select Strength</option>';
        opts.forEach(opt => {
            const o = document.createElement('option');
            o.value = opt;
            o.textContent = opt;
            if (opt === currentVal) o.selected = true;
            selectEl.appendChild(o);
        });
        if (currentVal && !opts.includes(currentVal)) {
            const o = document.createElement('option');
            o.value = currentVal;
            o.textContent = currentVal;
            o.selected = true;
            selectEl.appendChild(o);
        }
    }

    function updateStrengthOptions() {
        const dosage = document.getElementById('dosageFormSelect').value;
        populateStrengthSelect(document.getElementById('strengthSelect'), dosage, '');
    }

    function updateEditStrengthOptions() {
        const dosage = document.getElementById('editDosageForm').value;
        const curVal = document.getElementById('editStrengthSelect').value;
        populateStrengthSelect(document.getElementById('editStrengthSelect'), dosage, curVal);
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // ============================================================
    // INITIALIZATION
    // ============================================================
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('searchInput');
        const categoryFilter = document.getElementById('categoryFilter');
        
        if (searchInput) {
            searchInput.addEventListener('input', debouncedSearch);
        }
        
        if (categoryFilter) {
            categoryFilter.addEventListener('change', performSearch);
        }
        
        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('modal')) {
                e.target.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
        
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    modal.classList.remove('active');
                });
                document.body.style.overflow = '';
                
                const dialog = document.getElementById('confirmDialog');
                if (dialog && dialog.style.display === 'flex') {
                    dialog.style.display = 'none';
                    confirmCallback = null;
                }
                
                const removeModal = document.getElementById('removeConfirmModal');
                if (removeModal && removeModal.style.display === 'flex') {
                    removeModal.style.display = 'none';
                }
            }
        });
        
        currentTab = 'productsTab';
    });
    </script>

    <?php 
    if (function_exists('renderNotificationPanel')) {
        renderNotificationPanel($conn); 
    }
    ?>
    
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