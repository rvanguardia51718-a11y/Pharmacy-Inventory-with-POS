-- ============================================================
-- PHARMACY INVENTORY & POS SYSTEM - ENHANCED DATABASE SCHEMA
-- ============================================================

CREATE DATABASE IF NOT EXISTS pharmacy_db;
USE pharmacy_db;

-- ============================================================
-- USERS TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'staff') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- CATEGORIES TABLE
-- ============================================================
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================
-- PRODUCTS TABLE
-- base_unit = the smallest/base unit (e.g., "piece", "tablet", "ml")
-- price     = price per base unit (VAT-inclusive)
-- ============================================================
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barcode VARCHAR(50) UNIQUE,
    brand_name VARCHAR(100) NOT NULL,
    generic_name VARCHAR(100) NOT NULL,
    category_id INT,
    base_unit VARCHAR(30) NOT NULL DEFAULT 'piece',
    price DECIMAL(10,2) NOT NULL,
    cost  DECIMAL(10,2) NOT NULL,
    low_stock_threshold INT NOT NULL DEFAULT 10,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

-- ============================================================
-- PRODUCT_UNITS TABLE  (flexible multi-unit system)
-- Each row = one sellable unit for a product
-- conversion_to_base: how many base units equal 1 of this unit
--   box (100 tablets)  → conversion_to_base = 100
--   blister (10 tabs)  → conversion_to_base = 10
--   piece/tablet       → conversion_to_base = 1  (always include base)
--   bottle (single)    → conversion_to_base = 1
-- price_override NULL  → auto-calc from base price * conversion
-- ============================================================
CREATE TABLE IF NOT EXISTS product_units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    unit_name VARCHAR(30) NOT NULL,
    conversion_to_base INT NOT NULL DEFAULT 1,
    price_override DECIMAL(10,2) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    UNIQUE KEY uq_product_unit (product_id, unit_name)
);

-- ============================================================
-- STOCK_BATCHES TABLE
-- ALL quantities stored in base units ONLY.
-- FIFO deduction ordered by expiration_date ASC.
-- Products within 3 months of expiration CANNOT be sold.
-- ============================================================
CREATE TABLE IF NOT EXISTS stock_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    batch_number VARCHAR(50) NOT NULL,
    quantity_base INT NOT NULL DEFAULT 0,
    expiration_date DATE NOT NULL,
    received_unit VARCHAR(30) NOT NULL DEFAULT 'piece',
    received_qty INT NOT NULL DEFAULT 0,
    date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product_expiry (product_id, expiration_date),
    INDEX idx_expiry (expiration_date)
);

-- ============================================================
-- SALES TABLE
-- VAT is already included in item prices; tax = VAT portion for receipts
-- ============================================================
CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    total_amount DECIMAL(10,2) NOT NULL,
    discount DECIMAL(10,2) DEFAULT 0,
    tax DECIMAL(10,2) DEFAULT 0,
    grand_total DECIMAL(10,2) NOT NULL,
    cash_received DECIMAL(10,2) NOT NULL,
    change_amount DECIMAL(10,2) NOT NULL,
    sale_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- ============================================================
-- SALE_ITEMS TABLE
-- unit_sold        = unit used at checkout (box / blister / piece / bottle …)
-- quantity_sold    = qty in the sold unit
-- quantity_in_base = qty actually deducted from stock_batches
-- unit_price       = price per unit_sold at time of sale
-- ============================================================
CREATE TABLE IF NOT EXISTS sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    batch_id INT NOT NULL,
    unit_sold VARCHAR(30) NOT NULL DEFAULT 'piece',
    quantity_sold INT NOT NULL,
    quantity_in_base INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (batch_id) REFERENCES stock_batches(id)
);

-- ============================================================
-- NOTIFICATIONS TABLE
-- Persisted, system-wide notifications with read tracking.
-- ============================================================
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type ENUM(
        'out_of_stock','low_stock','near_expiry','expired',
        'product_added','product_updated','product_deleted','stock_added'
    ) NOT NULL,
    reference_id INT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_read (user_id, is_read),
    INDEX idx_type (type),
    INDEX idx_created (created_at)
);

-- ============================================================
-- ACTIVITY_LOGS TABLE
-- Every action that affects inventory/sales.
-- ============================================================
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    product_id INT NULL,
    date_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    INDEX idx_user (user_id),
    INDEX idx_product (product_id),
    INDEX idx_datetime (date_time)
);

-- ============================================================
-- DEFAULT DATA
-- ============================================================
INSERT INTO categories (name, description) VALUES
('Tablet',     'Solid oral dosage forms'),
('Capsule',    'Solid dosage forms with shell'),
('Syrup',      'Liquid oral preparations'),
('Injection',  'Sterile parenteral preparations'),
('Ointment',   'Semi-solid topical preparations'),
('Eye Drop',   'Sterile ophthalmic solutions'),
('Inhaler',    'Respiratory medications'),
('Vitamin',    'Dietary supplements'),
('Antibiotic', 'Anti-bacterial medications'),
('Pain Relief','Analgesic medications');

-- Default admin — password: admin123
INSERT INTO users (username, password, full_name, role)
VALUES ('admin','$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','System Administrator','admin');

-- ============================================================
-- MIGRATION NOTES (upgrading from the old schema)
-- ============================================================
-- 1. Run the new CREATE TABLE statements above on your DB.
-- 2. Copy old product_batches → stock_batches:
--    INSERT INTO stock_batches
--      (product_id, batch_number, quantity_base, expiration_date, received_unit, received_qty)
--    SELECT product_id, COALESCE(batch_number,'LEGACY'),
--           quantity, expiry_date, 'piece', quantity
--    FROM product_batches;
--
-- 3. Seed base units for existing products:
--    INSERT IGNORE INTO product_units (product_id, unit_name, conversion_to_base, sort_order)
--    SELECT id, base_unit, 1, 99 FROM products;
-- ============================================================
