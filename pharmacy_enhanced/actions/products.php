<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth_check.php';

restrictToAdmin();

$action = $_GET['action'] ?? '';

if ($action == 'add') {
    $barcode = trim($_POST['barcode']);
    $brand_name = trim($_POST['brand_name']);
    $generic_name = trim($_POST['generic_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $price = floatval($_POST['price']);
    $cost = floatval($_POST['cost']);

    try {
        $check = $pdo->prepare("SELECT id FROM products WHERE barcode = ?");
        $check->execute([$barcode]);
        if ($check->rowCount() > 0) {
            die("Error: Barcode already exists");
        }

        $stmt = $pdo->prepare("INSERT INTO products (barcode, brand_name, generic_name, category, price, cost) VALUES (?, ?, ?, ?, ?, ?)");
        $result = $stmt->execute([$barcode, $brand_name, $generic_name, $category, $price, $cost]);
        
        if ($result) {
            $product_id = $pdo->lastInsertId();
            logActivity($pdo, $_SESSION['user_id'], 'Product Added', "Added product: $brand_name");
            header("Location: ../admin/inventory.php?success=added");
            exit;
        } else {
            die("Error: Failed to insert product");
        }
    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}

if ($action == 'edit') {
    $id = intval($_POST['id']);
    $barcode = trim($_POST['barcode']);
    $brand_name = trim($_POST['brand_name']);
    $generic_name = trim($_POST['generic_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $price = floatval($_POST['price']);
    $cost = floatval($_POST['cost']);

    try {
        $check = $pdo->prepare("SELECT id FROM products WHERE barcode = ? AND id != ?");
        $check->execute([$barcode, $id]);
        if ($check->rowCount() > 0) {
            die("Error: Barcode already exists for another product");
        }

        $stmt = $pdo->prepare("UPDATE products SET barcode = ?, brand_name = ?, generic_name = ?, category = ?, price = ?, cost = ? WHERE id = ?");
        $result = $stmt->execute([$barcode, $brand_name, $generic_name, $category, $price, $cost, $id]);
        
        if ($result) {
            logActivity($pdo, $_SESSION['user_id'], 'Product Edited', "Edited product ID: $id - $brand_name");
            header("Location: ../admin/inventory.php?success=updated");
            exit;
        } else {
            die("Error: Failed to update product");
        }
    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}

if ($action == 'delete') {
    $id = intval($_GET['id']);
    
    try {
        $stmt = $pdo->prepare("SELECT brand_name FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $product = $stmt->fetch();
        
        if (!$product) {
            die("Error: Product not found");
        }
        
        $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
        $result = $stmt->execute([$id]);
        
        if ($result) {
            logActivity($pdo, $_SESSION['user_id'], 'Product Deleted', "Deleted product: {$product['brand_name']}");
            header("Location: ../admin/inventory.php?success=deleted");
            exit;
        } else {
            die("Error: Failed to delete product");
        }
    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}

if ($action == 'add_batch') {
    $product_id = intval($_POST['product_id']);
    $batch_number = trim($_POST['batch_number']);
    $quantity = intval($_POST['quantity']);
    $expiry_date = $_POST['expiry_date'];

    if ($quantity <= 0) {
        die("Error: Quantity must be greater than 0");
    }

    if (strtotime($expiry_date) <= strtotime(date('Y-m-d'))) {
        die("Error: Expiry date must be in the future");
    }

    try {
        $check = $pdo->prepare("SELECT id, brand_name FROM products WHERE id = ?");
        $check->execute([$product_id]);
        $product = $check->fetch();
        
        if (!$product) {
            die("Error: Product not found");
        }

        $stmt = $pdo->prepare("INSERT INTO product_batches (product_id, batch_number, quantity, expiry_date) VALUES (?, ?, ?, ?)");
        $result = $stmt->execute([$product_id, $batch_number, $quantity, $expiry_date]);
        
        if ($result) {
            logActivity($pdo, $_SESSION['user_id'], 'Stock Added', "Added $quantity units to batch $batch_number for product: {$product['brand_name']}");
            header("Location: ../admin/inventory.php?success=batch_added");
            exit;
        } else {
            die("Error: Failed to add batch");
        }
    } catch (PDOException $e) {
        die("Database error: " . $e->getMessage());
    }
}
?>