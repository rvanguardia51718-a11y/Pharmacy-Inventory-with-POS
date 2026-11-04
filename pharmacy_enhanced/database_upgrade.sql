-- ============================================================
-- PHARMACY SYSTEM UPGRADE SCRIPT
-- Run this on top of your existing database.sql
-- ============================================================

USE pharmacy_db;

-- Add new columns to products table
ALTER TABLE products 
  ADD COLUMN IF NOT EXISTS dosage_form VARCHAR(50) DEFAULT NULL AFTER generic_name,
  ADD COLUMN IF NOT EXISTS strength VARCHAR(50) DEFAULT NULL AFTER dosage_form,
  ADD COLUMN IF NOT EXISTS vat_type ENUM('vatable','exempt','zero_rated') NOT NULL DEFAULT 'vatable' AFTER price;

-- Add vat breakdown columns to sales table
ALTER TABLE sales
  ADD COLUMN IF NOT EXISTS vat_sales DECIMAL(10,2) DEFAULT 0 AFTER tax,
  ADD COLUMN IF NOT EXISTS vat_exempt_sales DECIMAL(10,2) DEFAULT 0 AFTER vat_sales,
  ADD COLUMN IF NOT EXISTS vat_zero_rated_sales DECIMAL(10,2) DEFAULT 0 AFTER vat_exempt_sales,
  ADD COLUMN IF NOT EXISTS vat_amount DECIMAL(10,2) DEFAULT 0 AFTER vat_zero_rated_sales,
  ADD COLUMN IF NOT EXISTS pos_sn VARCHAR(30) DEFAULT NULL AFTER change_amount,
  ADD COLUMN IF NOT EXISTS tm_number VARCHAR(10) DEFAULT NULL AFTER pos_sn;

-- Add vat_type to sale_items for proper reporting
ALTER TABLE sale_items
  ADD COLUMN IF NOT EXISTS vat_type ENUM('vatable','exempt','zero_rated') NOT NULL DEFAULT 'vatable' AFTER unit_price;

-- Global unit types table for reusable unit catalog
CREATE TABLE IF NOT EXISTS unit_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(30) NOT NULL UNIQUE,
  description VARCHAR(100) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default unit types
INSERT IGNORE INTO unit_types (name, description) VALUES
  ('piece', 'Single item'),
  ('tablet', 'Single tablet'),
  ('capsule', 'Single capsule'),
  ('bottle', 'Single bottle'),
  ('box', 'Box of items'),
  ('strip', 'Blister strip'),
  ('pack', 'Pack of items'),
  ('sachet', 'Single sachet'),
  ('vial', 'Single vial'),
  ('ampoule', 'Single ampoule'),
  ('tube', 'Single tube');

