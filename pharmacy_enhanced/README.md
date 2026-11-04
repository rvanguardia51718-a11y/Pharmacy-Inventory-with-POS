# Pharmacy Inventory Management System with POS

This document provides a comprehensive overview and setup instructions for the Pharmacy Inventory Management System with Point of Sale (POS) functionality.

## Part 1: System Overview & Backend

### System Overview

This system is designed to manage pharmacy inventory efficiently, track product expiry dates, and facilitate sales through a Point of Sale interface. It features a robust authentication system with role-based access control, detailed activity logging, and comprehensive reporting capabilities. The system is built using HTML, CSS, JavaScript for the frontend, PHP (PDO) for the backend, and MySQL for the database, optimized for deployment on XAMPP (localhost).

### Database Structure (SQL)

The database `pharmacy_db` consists of the following tables:

- **`users`**: Stores user information, including roles (admin/staff) and hashed passwords.
- **`products`**: Contains general product details like barcode, brand name, generic name, category, price, and cost.
- **`product_batches`**: Crucial for expiry tracking, storing quantity and expiry date per product batch. This allows for precise FIFO (First-In, First-Out) stock management based on expiry dates.
- **`sales`**: Records overall sales transactions, including total amount, discount, tax, grand total, cash received, and change.
- **`sale_items`**: Details individual items within each sale, linking to products and specific batches.
- **`activity_logs`**: Logs significant user actions within the system for auditing and monitoring.

```sql
CREATE DATABASE IF NOT EXISTS pharmacy_db;
USE pharmacy_db;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM(\'admin\', \'staff\') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Products table (General info)
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    barcode VARCHAR(50) UNIQUE,
    brand_name VARCHAR(100) NOT NULL,
    generic_name VARCHAR(100) NOT NULL,
    category VARCHAR(50),
    price DECIMAL(10, 2) NOT NULL,
    cost DECIMAL(10, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Product Batches (Expiry tracking)
CREATE TABLE IF NOT EXISTS product_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    batch_number VARCHAR(50),
    quantity INT NOT NULL DEFAULT 0,
    expiry_date DATE NOT NULL,
    date_added TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

-- Sales table
CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    total_amount DECIMAL(10, 2) NOT NULL,
    discount DECIMAL(10, 2) DEFAULT 0,
    tax DECIMAL(10, 2) DEFAULT 0,
    grand_total DECIMAL(10, 2) NOT NULL,
    cash_received DECIMAL(10, 2) NOT NULL,
    change_amount DECIMAL(10, 2) NOT NULL,
    sale_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Sale Items table
CREATE TABLE IF NOT EXISTS sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    batch_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    subtotal DECIMAL(10, 2) NOT NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products(id),
    FOREIGN KEY (batch_id) REFERENCES product_batches(id)
);

-- Activity Logs table
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    description TEXT,
    date_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Insert default admin (password: admin123)
INSERT INTO users (username, password, full_name, role) 
VALUES (
    \'admin\',
    \'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi\', -- Hashed password for 'admin123'
    \'System Administrator\',
    \'admin\'
);
```

### Folder Structure

```
pharmacy_system/
├── admin/                  # Admin-specific pages (e.g., inventory, reports, user management)
├── assets/
│   ├── css/                # Stylesheets (style.css)
│   ├── js/                 # JavaScript files (pos.js, inventory.js)
│   └── img/                # Images
├── config/                 # Database configuration (database.php)
├── includes/               # Reusable PHP components (sidebar.php, auth_check.php)
├── php/                    # Backend logic for various modules (products.php, pos_process.php)
├── database.sql            # SQL script for database creation and initial data
├── index.php               # Dashboard/Home page
├── login.php               # User login page
├── logout.php              # User logout script
├── pos.php                 # Point of Sale interface
├── logs.php                # Activity logs viewer
└── reports.php             # Sales reports viewer
```

### Backend (PHP Core Logic)

The backend is built with PHP, utilizing PDO for secure database interactions to prevent SQL injection. Key components include:

- **`config/database.php`**: Establishes the database connection and includes a `logActivity` function for recording user actions.
- **`includes/auth_check.php`**: Manages session-based authentication, checks user login status, and implements role-based access control (`isAdmin()`, `restrictToAdmin()`).
- **`php/products.php`**: Handles CRUD operations for products and manages the addition of new product batches, ensuring inventory updates are logged.
- **`php/pos_process.php`**: Processes POS transactions, including stock reduction using a **First-In, First-Out (FIFO)** logic based on expiry dates. It ensures that products with earlier expiry dates are sold first.

### Authentication

- **Admin and Staff Login**: Separate roles with distinct access privileges.
- **Secure Password Hashing**: Passwords are hashed using `password_hash()` and verified with `password_verify()` for enhanced security.
- **Session-based Authentication**: User sessions are managed to maintain login state.
- **Role-based Access Control**: Admin users have full access, while staff users have restricted access (e.g., POS only).
- **Logout System**: Securely terminates user sessions and logs the logout activity.

### Inventory & Expiry Logic

- **Batch-level Expiry Tracking**: Products are tracked by individual batches, each with its own expiry date and quantity. This is critical for pharmacies where precise expiry management is essential.
- **Near Expiry Warnings**: The system highlights items that are 30 days or less from their expiry date (orange). Expired items are highlighted in red.
- **FIFO Stock Reduction**: During a sale, the system automatically deducts stock from the batch with the earliest expiry date first, ensuring minimal waste.

### Activity Log System

All significant user actions are recorded in the `activity_logs` table, providing an audit trail. This includes:

- User login/logout
- Product additions, edits, and deletions
- Stock adjustments (batch additions)
- Sales completions

Each log entry includes the user who performed the action, the action type, a description, and a timestamp.

## Part 2: POS System, UI/UX & Setup

### POS System (with Keyboard Shortcuts)

The Point of Sale interface is designed for speed and efficiency, featuring:

- **Barcode Scanning Input**: Quick product lookup via barcode.
- **Product Search**: Ability to search for products by name.
- **Cart Management**: Add to cart, quantity update, and item removal.
- **Automatic Calculations**: Subtotal, discount, tax (optional), grand total, cash received, and change calculation.
- **Transaction Saving**: Records sales and updates inventory in real-time.
- **Receipt Printing**: Generates a printable receipt layout (designed for thermal 80mm printers).
- **Keyboard Shortcuts**: Essential for rapid operations:
    - `F2`: Focus barcode input
    - `F3`: Focus product search
    - `F4`: Focus cash input
    - `F8`: Complete transaction
    - `DEL`: Remove selected cart item
    - `ESC`: Clear cart
    - `CTRL + P`: Print receipt (browser default, but can be customized)

### UI Design (HTML + CSS + JS)

The user interface is designed to be professional, modern, and intuitive, adhering to a pharmacy theme with a green and purple color scheme.

- **Modern & Professional**: Clean typography, soft gradients, rounded corners, and subtle shadows create a polished look.
- **Color Scheme**: Green (primary) and Purple (secondary accent) are used consistently for branding and visual hierarchy.
- **Navigation**: Features a clear sidebar navigation for main modules and a top navbar for user-specific information.
- **Dashboard**: Utilizes cards to display key metrics and recent activities.
- **Clean Tables**: Data is presented in easy-to-read tables with hover effects.
- **Modal Forms**: Add/Edit product forms are implemented as modals for a seamless user experience.
- **Responsive Layout**: Optimized for both desktop and tablet views.

### Setup Instructions

To set up and run the Pharmacy Inventory Management System on your local machine using XAMPP:

1.  **Install XAMPP**: If you don't have XAMPP installed, download and install it from the official Apache Friends website (https://www.apachefriends.org/index.html).
2.  **Start Apache and MySQL**: Open the XAMPP Control Panel and start the Apache and MySQL services.
3.  **Place Project Files**: Copy the entire `pharmacy_system` folder into the `htdocs` directory of your XAMPP installation (e.g., `C:\xampp\htdocs\pharmacy_system`).
4.  **Create Database**: 
    a. Open your web browser and go to `http://localhost/phpmyadmin`.
    b. Click on the `SQL` tab.
    c. Copy the SQL code from `pharmacy_system/database.sql` and paste it into the SQL editor.
    d. Click `Go` to execute the script. This will create the `pharmacy_db` database and all necessary tables, including a default admin user (username: `admin`, password: `admin123`).
5.  **Configure Database Connection**: The `config/database.php` file is pre-configured for XAMPP defaults (`root` user with no password). If your MySQL configuration is different, update this file accordingly.
6.  **Access the System**: Open your web browser and navigate to `http://localhost/pharmacy_system/login.php`.
7.  **Login**: Use the default admin credentials:
    -   **Username**: `admin`
    -   **Password**: `admin123`

### Extra Enhancements

-   **Input Validation**: Server-side input validation is implemented to ensure data integrity and security.
-   **Error Handling**: Basic error handling is in place for database operations.
-   **Security**: PDO prepared statements are used throughout to prevent SQL injection. Direct URL access to protected pages is prevented without proper authentication.
-   **Scalability**: The modular design allows for easy expansion of features and modules.

This system provides a solid foundation for a professional pharmacy inventory and POS solution, designed with best practices in mind for security, usability, and maintainability.
