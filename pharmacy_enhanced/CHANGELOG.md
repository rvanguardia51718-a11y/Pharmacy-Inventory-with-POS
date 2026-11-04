# PHARMACY SYSTEM ENHANCEMENTS v2 - CHANGELOG

## What Was Enhanced

### 1. INVENTORY MODULE

#### A. New Product Fields
- **Dosage Form** – Dropdown (Tablet, Capsule, Syrup, Suspension, Ointment, Injection, Eye Drop, Inhaler, Cream, Drops, Powder, Suppository, Patch, Emulsion, Lotion, Solution, Granules, Powder for Suspension)
- **Strength** – Free text (e.g. 20mg, 500mg, 5mg/5ml, 60ml)
- **VAT Type** – Dropdown: VATable (12%), VAT Exempt, Zero Rated
- All existing fields (base unit, price, cost, threshold) retained and improved

#### B. Advanced Inventory Filters
Filter by: Category, Dosage Form, Strength, Base Unit, Stock Status (Low / Expiring / Out of Stock)

#### C. Simplified Actions Column
- **Table Actions**: Only ➕ Add Stock and 🗑 Delete
- **Click Product Name** → Opens full Product Details Modal
- **✏️ Pencil/Edit button** inside the Product Details Modal
- Edit button removed from main table

#### D. Automatic FIFO (Stock Management)
- System auto-deducts from oldest batch first (lowest expiry date)
- When restocking: if batch number already exists → quantity is ADDED to that batch
- Expired batches and batches within 3 months of expiry cannot be sold
- No manual batch selection required anywhere

#### E. Base Unit Management (New Tab)
- New "Unit Types" tab in inventory
- Global catalog of unit types (piece, tablet, capsule, bottle, box, strip, pack, sachet, vial, ampoule, tube)
- Add new unit types dynamically
- Units appear as autocomplete suggestions when creating products

---

### 2. POS MODULE

#### Multi-Unit Selling
- When a product has multiple units (Box, Strip, Piece), a **unit selector popup** appears
- Cashier selects the unit; system automatically:
  - Converts to base units using the conversion factor
  - Calculates correct price per unit
  - Deducts correct base quantity from FIFO batches
- Cart shows: unit name, quantity in that unit, base unit quantity, unit price

---

### 3. RECEIPT FORMAT (Official BIR-style)

New receipt format:
```
POS_SN: S4-80734633  THIS SERVES AS YOUR OFFICIAL RECEIPT
Feb 22 2026 (Sun)   TM#0001

AMOXICILLIN 500MG CAPSULE
  10 CAPS × @₱5.00           50.00

TOTAL:    50.00
CASH:     100.00
CHANGE:   50.00

VAT Sales:           44.64
VAT Exempt Sales:     0.00
VAT Zero-Rated Sales: 0.00
Non-Taxable Sales:    0.00
Total Sales:         44.64
VAT (12%):            5.36
Amount Payable:      50.00

Transaction#: 12345
Customer: 1 item(s)
Cashier: ___________
```

#### VAT Calculation Formula:
- VAT is INCLUDED in selling price
- VAT Sales (excl.) = Total / 1.12
- VAT Amount = Total - (Total / 1.12)

---

### 4. SALES SUMMARY PAGE (Reports)

Enhanced with:
- Gross Sales
- VAT Sales (excl. VAT)
- VAT Amount (12%)
- VAT Exempt Sales
- Zero-Rated Sales
- Net Sales / Amount Payable
- Total Transactions
- Average Sale
- VAT Breakdown Box with detailed table
- Date range filter (unchanged)

---

## Database Migration

**REQUIRED: Run `database_upgrade.sql` before deploying**

```sql
-- Adds to products table:
ALTER TABLE products 
  ADD COLUMN dosage_form VARCHAR(50),
  ADD COLUMN strength VARCHAR(50),
  ADD COLUMN vat_type ENUM('vatable','exempt','zero_rated') DEFAULT 'vatable';

-- Adds to sales table:
ALTER TABLE sales
  ADD COLUMN vat_sales DECIMAL(10,2) DEFAULT 0,
  ADD COLUMN vat_exempt_sales DECIMAL(10,2) DEFAULT 0,
  ADD COLUMN vat_zero_rated_sales DECIMAL(10,2) DEFAULT 0,
  ADD COLUMN vat_amount DECIMAL(10,2) DEFAULT 0,
  ADD COLUMN pos_sn VARCHAR(30),
  ADD COLUMN tm_number VARCHAR(10);

-- Adds to sale_items table:
ALTER TABLE sale_items
  ADD COLUMN vat_type ENUM('vatable','exempt','zero_rated') DEFAULT 'vatable';

-- New table:
CREATE TABLE unit_types (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(30) UNIQUE NOT NULL,
  description VARCHAR(100),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## Files Changed
- `admin/inventory.php` – Completely rewritten
- `ajax/get_product_details.php` – NEW: Product details modal content
- `actions/pos_process.php` – Rewritten with VAT breakdown and multi-unit FIFO
- `pos.php` – Enhanced: multi-unit selector, new receipt format, VAT totals in sidebar
- `reports.php` – Enhanced: VAT summary stats + breakdown box
- `database_upgrade.sql` – NEW: migration script
