<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/notifications.php';

// Only admin can access this page
restrictToAdmin();

$message = '';
$error = '';

// Handle user addition
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_user') {
        $username = trim($_POST['username']);
        $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
        $full_name = trim($_POST['full_name']);
        $role = trim($_POST['role']);
        $is_pharmacist = isset($_POST['is_pharmacist']) ? 1 : 0;
        $license_number = trim($_POST['license_number'] ?? '');
        $license_expiry_date = trim($_POST['license_expiry_date'] ?? '') ?: null;

        try {
            $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role, is_pharmacist, license_number, license_expiry_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("ssssiss", $username, $password, $full_name, $role, $is_pharmacist, $license_number, $license_expiry_date);
            
            if ($stmt->execute()) {
                $message = "User added successfully!";
                logActivity($conn, $_SESSION['user_id'], 'Add User', "Added user: $username");
            }
            $stmt->close();
        } catch (Exception $e) {
            $error = "Error adding user: " . $e->getMessage();
        }
    }
    
    // Handle user deletion
    if ($_POST['action'] === 'delete_user') {
        $user_id = intval($_POST['user_id']);
        
        // Don't allow deleting yourself
        if ($user_id == $_SESSION['user_id']) {
            $error = "You cannot delete your own account!";
        } else {
            try {
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->bind_param("i", $user_id);
                
                if ($stmt->execute()) {
                    $message = "User deleted successfully!";
                    logActivity($conn, $_SESSION['user_id'], 'Delete User', "Deleted user ID: $user_id");
                }
                $stmt->close();
            } catch (Exception $e) {
                $error = "Error deleting user: " . $e->getMessage();
            }
        }
    }
    
    // Handle password reset
    if ($_POST['action'] === 'reset_password') {
        $user_id = intval($_POST['user_id']);
        $new_pass_raw = trim($_POST['new_password'] ?? '');
        if (strlen($new_pass_raw) < 6) {
            $error = "New password must be at least 6 characters.";
        } else {
            $new_password = password_hash($new_pass_raw, PASSWORD_DEFAULT);
            try {
                $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $stmt->bind_param("si", $new_password, $user_id);
                if ($stmt->execute()) {
                    $message = "Password reset successfully for user ID: $user_id";
                    logActivity($conn, $_SESSION['user_id'], 'Reset Password', "Reset password for user ID: $user_id");
                }
                $stmt->close();
            } catch (Exception $e) {
                $error = "Error resetting password: " . $e->getMessage();
            }
        }
    }
}

// Get all users with additional stats
$result = $conn->query("SELECT id, username, full_name, role, is_pharmacist, license_number, license_expiry_date, created_at FROM users ORDER BY id DESC");

$users = [];
$total_users = 0;
$admin_count = 0;
$staff_count = 0;

while ($row = $result->fetch_assoc()) {
    $users[] = $row;
    $total_users++;
    if ($row['role'] === 'admin') {
        $admin_count++;
    } else {
        $staff_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>User Management | CAD Crest</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap');

        /* Page-specific styles only */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-green: #004d40;
            --primary-dark: #00332c;
            --primary-light: #e8f5e9;
            --accent-gold: #b8860b;
            --bg-soft: #f0f4f8;
            --card-shadow: 0 10px 20px rgba(0, 0, 0, 0.05);
            --transition: all 0.2s ease;
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

        body { 
            background: linear-gradient(135deg, #f8fafc 0%, #f0f4f8 100%);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
        }

        /* Page Header */
        .page-header {
            background: linear-gradient(135deg, var(--primary-green) 0%, #00695c 100%);
            padding: 1.5rem 2rem;
            border-radius: 32px;
            margin-bottom: 2rem;
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: 0 20px 35px -10px rgba(0, 77, 64, 0.15);
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
        }

        .page-header h1 {
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            margin-bottom: 0.5rem;
            position: relative;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .page-header h1 i {
            background: rgba(255,255,255,0.2);
            padding: 12px;
            border-radius: 20px;
            font-size: 1.6rem;
            backdrop-filter: blur(10px);
        }

        .page-header p {
            font-size: 0.95rem;
            opacity: 0.9;
            position: relative;
            padding-left: 65px;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
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
            transition: var(--transition);
            border: 1px solid rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
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
            background: linear-gradient(90deg, var(--primary-green), var(--accent-gold));
            opacity: 0;
            transition: var(--transition);
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
            background: var(--primary-light);
            color: var(--primary-green);
            transition: var(--transition);
        }

        .stat-card:hover .stat-icon {
            transform: scale(1.05) rotate(3deg);
        }

        .stat-details h3 {
            font-size: 1.8rem;
            font-weight: 800;
            color: #1e293b;
            line-height: 1.2;
            margin-bottom: 0.2rem;
        }

        .stat-details p {
            color: #64748b;
            font-size: 0.85rem;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Messages */
        .message {
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            border-radius: 16px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideIn 0.3s ease-out;
        }

        .message i {
            font-size: 1.2rem;
        }

        .success {
            background: #dcfce7;
            color: #15803d;
            border-left: 4px solid #15803d;
        }

        .error {
            background: #fee2e2;
            color: #b91c1c;
            border-left: 4px solid #b91c1c;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Action Bar */
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 16px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 10px;
            font-family: 'Inter', sans-serif;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .btn-primary {
            background: var(--primary-green);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(0, 77, 64, 0.3);
        }

        .btn-warning {
            background: #ff9800;
            color: white;
        }

        .btn-warning:hover {
            background: #f57c00;
            transform: translateY(-2px);
        }

        .btn-danger {
            background: #dc2626;
            color: white;
        }

        .btn-danger:hover {
            background: #b91c1c;
            transform: translateY(-2px);
        }

        .btn-small {
            padding: 0.5rem 1rem;
            font-size: 0.8rem;
            border-radius: 12px;
        }

        /* Table Container */
        .table-container {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 32px;
            padding: 1.5rem;
            box-shadow: 0 20px 35px -10px rgba(0, 77, 64, 0.15);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid #f1f5f9;
        }

        .table-header h2 {
            font-size: 1.3rem;
            color: #1e293b;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .table-header h2 i {
            color: var(--primary-green);
            background: var(--primary-light);
            padding: 8px;
            border-radius: 14px;
        }

        .badge-total {
            background: linear-gradient(135deg, var(--primary-green), #00695c);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 40px;
            font-size: 0.85rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 5px 15px rgba(0, 77, 64, 0.3);
        }

        /* Table - Responsive */
        .table-responsive {
            overflow-x: auto;
            border-radius: 20px;
            background: white;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.02);
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 700px;
        }

        th {
            background: #f8fafc;
            padding: 1rem 1.2rem;
            text-align: left;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #475569;
            border-bottom: 2px solid #e2e8f0;
        }

        th:first-child {
            border-radius: 16px 0 0 0;
        }

        th:last-child {
            border-radius: 0 16px 0 0;
        }

        td {
            padding: 1rem 1.2rem;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.95rem;
            color: #334155;
        }

        tbody tr {
            transition: var(--transition);
        }

        tbody tr:hover {
            background: linear-gradient(90deg, #f8fafc 0%, #ffffff 100%);
            transform: scale(1.01);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        /* User Info */
        .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .user-avatar {
            width: 42px;
            height: 42px;
            background: linear-gradient(135deg, var(--primary-green), #00695c);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 1.1rem;
            box-shadow: 0 5px 10px rgba(0, 77, 64, 0.2);
            transition: var(--transition);
            flex-shrink: 0;
        }

        .user-avatar:hover {
            transform: scale(1.1) rotate(5deg);
        }

        .user-details {
            display: flex;
            flex-direction: column;
        }

        .user-name {
            font-weight: 700;
            color: #1e293b;
            margin-bottom: 2px;
        }

        .user-username {
            font-size: 0.75rem;
            color: #94a3b8;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        /* Badges */
        .role-badge {
            padding: 6px 14px;
            border-radius: 40px;
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            letter-spacing: 0.3px;
        }

        .badge-admin {
            background: linear-gradient(135deg, #f3e8ff, #ede9fe);
            color: #7e22ce;
            border: 1px solid rgba(126, 34, 206, 0.2);
        }

        .badge-staff {
            background: linear-gradient(135deg, #e0f2fe, #dbeafe);
            color: #0369a1;
            border: 1px solid rgba(3, 105, 161, 0.2);
        }

        .date-info {
            color: #64748b;
            font-size: 0.85rem;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .date-info i {
            color: var(--primary-green);
            opacity: 0.7;
        }

        /* Action Cell */
        .action-cell {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .current-user-badge {
            background: linear-gradient(135deg, #f1f5f9, #e2e8f0);
            color: #475569;
            padding: 0.5rem 1rem;
            border-radius: 30px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid rgba(0,0,0,0.05);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            animation: fadeIn 0.2s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: white;
            width: 90%;
            max-width: 500px;
            margin: 50px auto;
            padding: 2rem;
            border-radius: 32px;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.3);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .modal-content h2 {
            color: #1e293b;
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .modal-content h2 i {
            color: var(--primary-green);
            background: var(--primary-light);
            padding: 12px;
            border-radius: 16px;
        }

        .form-group {
            margin-bottom: 1.2rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #334155;
            font-size: 0.9rem;
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            font-size: 0.95rem;
            font-family: 'Inter', sans-serif;
            transition: var(--transition);
        }

        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: var(--primary-green);
            box-shadow: 0 0 0 3px rgba(0, 77, 64, 0.1);
        }

        .modal-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }

        .btn-outline {
            background: white;
            border: 2px solid #e2e8f0;
            color: #475569;
        }

        .btn-outline:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: linear-gradient(135deg, #f8fafc, #ffffff);
            border-radius: 30px;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: var(--primary-green);
            opacity: 0.3;
        }

        .empty-state h3 {
            color: #334155;
            margin-bottom: 0.5rem;
        }

        /* Tooltip */
        [data-tooltip] {
            position: relative;
            cursor: help;
        }

        [data-tooltip]:before {
            content: attr(data-tooltip);
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            padding: 0.5rem 0.75rem;
            background: #1e293b;
            color: white;
            font-size: 0.7rem;
            border-radius: 8px;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: all 0.2s ease;
            pointer-events: none;
            z-index: 1000;
        }

        [data-tooltip]:hover:before {
            opacity: 1;
            visibility: visible;
            transform: translateX(-50%) translateY(-5px);
        }

        /* Responsive Breakpoints */
        @media (max-width: 992px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .page-header h1 {
                font-size: 1.6rem;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .stat-card {
                padding: 1.2rem;
            }
            
            .stat-details h3 {
                font-size: 1.6rem;
            }
            
            .action-bar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .btn-primary {
                width: 100%;
                justify-content: center;
            }
            
            .action-cell {
                flex-direction: column;
            }
            
            td, th {
                padding: 0.75rem;
            }
            
            .modal-content {
                width: 95%;
                padding: 1.5rem;
                margin: 20px auto;
            }
        }

        @media (max-width: 576px) {
            .page-header {
                padding: 1.2rem;
            }
            
            .page-header h1 {
                font-size: 1.4rem;
            }
            
            .page-header h1 i {
                padding: 8px;
                font-size: 1.2rem;
            }
            
            .page-header p {
                padding-left: 45px;
            }
            
            .stat-card {
                gap: 1rem;
            }
            
            .stat-icon {
                width: 48px;
                height: 48px;
                font-size: 1.4rem;
            }
            
            .stat-details h3 {
                font-size: 1.4rem;
            }
            
            .table-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .badge-total {
                align-self: flex-start;
            }
            
            .modal-content {
                padding: 1.2rem;
            }
            
            .modal-content h2 {
                font-size: 1.3rem;
            }
        }
        
          /* Modal Enhancements */
    .modal-content {
        max-width: 650px;
        padding: 0;
        overflow: hidden;
        border-radius: 40px;
    }

    /* Modal Header */
    .modal-header {
        background: linear-gradient(135deg, #f8fafc, #f1f5f9);
        padding: 2rem 2rem 1.5rem;
        border-bottom: 1px solid #e2e8f0;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        position: relative;
    }

    .modal-header::after {
        content: '';
        position: absolute;
        bottom: -1px;
        left: 2rem;
        right: 2rem;
        height: 2px;
        background: linear-gradient(90deg, var(--primary-green), var(--accent-gold));
        border-radius: 2px;
    }

    .modal-title {
        display: flex;
        align-items: center;
        gap: 1.2rem;
    }

    .title-icon {
        width: 56px;
        height: 56px;
        background: linear-gradient(135deg, var(--primary-green), #00695c);
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 1.8rem;
        box-shadow: 0 10px 20px rgba(0, 77, 64, 0.2);
    }

    .modal-title h2 {
        font-size: 1.8rem;
        font-weight: 800;
        color: #1e293b;
        margin-bottom: 0.25rem;
    }

    .title-subtitle {
        color: #64748b;
        font-size: 0.9rem;
    }

    .modal-close-btn {
        width: 40px;
        height: 40px;
        border: none;
        background: white;
        border-radius: 14px;
        color: #64748b;
        font-size: 1.2rem;
        cursor: pointer;
        transition: var(--transition);
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
    }

    .modal-close-btn:hover {
        background: #fee2e2;
        color: #ef4444;
        transform: rotate(90deg);
    }

    /* Form Sections */
    .form-sections {
        padding: 1.5rem 2rem;
        max-height: 60vh;
        overflow-y: auto;
        scrollbar-width: thin;
        scrollbar-color: var(--primary-green) #e2e8f0;
    }

    .form-sections::-webkit-scrollbar {
        width: 6px;
    }

    .form-sections::-webkit-scrollbar-track {
        background: #e2e8f0;
        border-radius: 10px;
    }

    .form-sections::-webkit-scrollbar-thumb {
        background: var(--primary-green);
        border-radius: 10px;
    }

    .form-section {
        background: #f8fafc;
        border-radius: 24px;
        padding: 1.5rem;
        margin-bottom: 1.5rem;
        border: 1px solid #e2e8f0;
        transition: var(--transition);
    }

    .form-section:hover {
        border-color: var(--primary-green);
        box-shadow: 0 5px 20px rgba(0, 77, 64, 0.08);
    }

    .section-header {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        margin-bottom: 1.5rem;
        padding-bottom: 0.8rem;
        border-bottom: 2px dashed #cbd5e1;
    }

    .section-header i {
        font-size: 1.3rem;
        color: var(--primary-green);
        background: white;
        padding: 8px;
        border-radius: 14px;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
    }

    .section-header h3 {
        font-size: 1.1rem;
        font-weight: 700;
        color: #334155;
        flex: 1;
    }

    .optional-badge {
        background: #cbd5e1;
        color: #475569;
        padding: 0.3rem 1rem;
        border-radius: 30px;
        font-size: 0.7rem;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    /* Form Layout */
    .form-row {
        display: flex;
        gap: 1.2rem;
        margin-bottom: 1rem;
        flex-wrap: wrap;
    }

    .form-group {
        flex: 1;
        min-width: 200px;
    }

    .form-group.full-width {
        flex: 0 0 100%;
    }

    .form-group label {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        margin-bottom: 0.6rem;
        font-weight: 600;
        color: #475569;
        font-size: 0.85rem;
    }

    .form-group label i {
        color: var(--primary-green);
        font-size: 0.9rem;
    }

    .required {
        color: #ef4444;
        margin-left: 0.2rem;
    }

    /* Input Wrapper */
    .input-wrapper, .select-wrapper {
        position: relative;
        margin-bottom: 0.25rem;
    }

    .input-wrapper input, .select-wrapper select {
        width: 100%;
        padding: 0.9rem 1.2rem;
        border: 2px solid #e2e8f0;
        border-radius: 18px;
        font-size: 0.95rem;
        font-family: 'Inter', sans-serif;
        background: white;
        transition: all 0.3s ease;
    }

    .input-wrapper input:focus, .select-wrapper select:focus {
        outline: none;
        border-color: var(--primary-green);
        box-shadow: 0 0 0 4px rgba(0, 77, 64, 0.1);
    }

    .input-highlight {
        position: absolute;
        bottom: 0;
        left: 50%;
        width: 0;
        height: 2px;
        background: var(--primary-green);
        transition: all 0.3s ease;
        transform: translateX(-50%);
    }

    .input-wrapper input:focus ~ .input-highlight {
        width: 80%;
    }

    .input-hint {
        display: block;
        font-size: 0.7rem;
        color: #94a3b8;
        margin-top: 0.25rem;
        margin-left: 0.5rem;
    }

    /* Select Wrapper */
    .select-wrapper {
        position: relative;
    }

    .select-wrapper select {
        appearance: none;
        cursor: pointer;
        background: white;
    }

    .select-arrow {
        position: absolute;
        right: 1.2rem;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        pointer-events: none;
        transition: var(--transition);
    }

    .select-wrapper:hover .select-arrow {
        color: var(--primary-green);
        transform: translateY(-50%) rotate(180deg);
    }

    /* License Section */
    .license-section {
        background: linear-gradient(135deg, #fff5f5, #fff1f1);
        border: 1px solid #fecaca;
    }

    /* Simple Checkbox Style */
    .license-checkbox {
        margin-bottom: 1rem;
    }

    .checkbox-label {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        cursor: pointer;
        padding: 0.5rem;
        border-radius: 12px;
        transition: var(--transition);
    }

    .checkbox-label:hover {
        background: rgba(0, 0, 0, 0.02);
    }

    .checkbox-label input[type="checkbox"] {
        display: none;
    }

    .checkbox-custom {
        width: 22px;
        height: 22px;
        border: 2px solid #d1d5db;
        border-radius: 6px;
        background: white;
        position: relative;
        transition: var(--transition);
    }

    .checkbox-label input[type="checkbox"]:checked + .checkbox-custom {
        background: var(--primary-green);
        border-color: var(--primary-green);
    }

    .checkbox-label input[type="checkbox"]:checked + .checkbox-custom::after {
        content: '\f00c';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        color: white;
        font-size: 0.8rem;
    }

    .checkbox-text {
        font-weight: 600;
        color: #b91c1c;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-size: 1rem;
    }

    .checkbox-text i {
        font-size: 1.1rem;
    }

    .checkbox-hint {
        font-size: 0.75rem;
        color: #9ca3af;
        margin-left: 2.8rem;
        margin-top: -0.25rem;
    }

    /* License Fields */
    .license-fields {
        opacity: 0;
        max-height: 0;
        overflow: hidden;
        transition: all 0.3s ease-in-out;
        background: rgba(255, 255, 255, 0.5);
        border-radius: 20px;
        padding: 0 1rem;
    }

    .license-fields.show {
        opacity: 1;
        max-height: 300px;
        padding: 1rem;
        margin-top: 1rem;
    }

    /* License Alert */
    .license-alert {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        background: #fef9c3;
        border: 1px solid #fde047;
        border-radius: 16px;
        padding: 0.8rem 1.2rem;
        margin-top: 1rem;
        font-size: 0.85rem;
        color: #854d0e;
    }

    .license-alert i {
        font-size: 1.1rem;
        color: #eab308;
    }

    /* Modal Actions */
    .modal-actions {
        padding: 1.5rem 2rem;
        background: #f8fafc;
        border-top: 2px solid #e2e8f0;
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
    }

    .modal-actions .btn {
        padding: 0.9rem 2rem;
        border-radius: 50px;
        font-weight: 600;
        font-size: 0.95rem;
    }

    .modal-actions .btn i {
        font-size: 1rem;
    }

    .btn-outline {
        background: transparent;
        border: 2px solid #e2e8f0;
        color: #64748b;
    }

    .btn-outline:hover {
        border-color: var(--primary-green);
        color: var(--primary-green);
        background: white;
        transform: translateY(-2px);
    }

    .btn-primary {
        background: linear-gradient(135deg, var(--primary-green), #00695c);
        color: white;
        box-shadow: 0 10px 20px rgba(0, 77, 64, 0.2);
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 15px 30px rgba(0, 77, 64, 0.3);
    }

    /* Responsive */
    @media (max-width: 640px) {
        .modal-content {
            width: 95%;
            margin: 20px auto;
        }

        .modal-header {
            padding: 1.5rem;
        }

        .title-icon {
            width: 48px;
            height: 48px;
            font-size: 1.5rem;
        }

        .modal-title h2 {
            font-size: 1.5rem;
        }

        .form-sections {
            padding: 1rem;
        }

        .form-row {
            flex-direction: column;
            gap: 0.8rem;
        }

        .modal-actions {
            padding: 1.2rem;
            flex-direction: column-reverse;
        }

        .modal-actions .btn {
            width: 100%;
            justify-content: center;
        }
    }
    </style>
</head>
<body>
    <?php include '../includes/sidebar.php'; ?>
    
    <div class="main-content">
        <!-- Enhanced Header -->
        <div class="page-header">
            <div>
                <h1>
                    <i class="fa-solid fa-users-gear"></i>
                    User Management
                </h1>
                <p>Manage system users, roles, and permissions for CAD Crest Pharmaceutical</p>
            </div>
            <div style="display:flex;align-items:center;gap:.5rem;padding-top:.25rem;">
                <?php renderNotificationBell($conn); ?>
            </div>
        </div>

        <!-- Messages -->
        <?php if ($message): ?>
            <div class="message success">
                <i class="fa-regular fa-circle-check"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="message error">
                <i class="fa-regular fa-circle-exclamation"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-users"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $total_users; ?></h3>
                    <p>Total Users</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-crown"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $admin_count; ?></h3>
                    <p>Administrators</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-user-tie"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo $staff_count; ?></h3>
                    <p>Staff Members</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fa-solid fa-calendar-plus"></i>
                </div>
                <div class="stat-details">
                    <h3><?php echo date('M Y'); ?></h3>
                    <p>Current Period</p>
                </div>
            </div>
        </div>

        <!-- Action Bar -->
        <div class="action-bar">
            <div></div> <!-- Spacer -->
            <button class="btn btn-primary" onclick="showModal('addUserModal')">
                <i class="fa-solid fa-user-plus"></i>
                Add New User
            </button>
        </div>

        <!-- Users Table -->
        <div class="table-container">
            <div class="table-header">
                <h2>
                    <i class="fa-solid fa-users-viewfinder"></i>
                    System Users Directory
                </h2>
                <span class="badge-total">
                    <i class="fa-regular fa-user"></i>
                    <?php echo $total_users; ?> Registered
                </span>
            </div>

            <?php if (empty($users)): ?>
                <div class="empty-state">
                    <i class="fa-regular fa-user-slash"></i>
                    <h3>No Users Found</h3>
                    <p>There are no users in the system yet</p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>User</th>
                                <th>Role</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                            <tr>
                                <td><span style="font-weight: 700; color: var(--primary-green);">#<?php echo $user['id']; ?></span></td>
                                <td>
                                    <div class="user-info">
                                        <div class="user-avatar">
                                            <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
                                        </div>
                                        <div class="user-details">
                                            <span class="user-name"><?php echo htmlspecialchars($user['full_name']); ?></span>
                                            <span class="user-username">
                                                <i class="fa-regular fa-at"></i>
                                                <?php echo htmlspecialchars($user['username']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="role-badge <?php echo $user['role'] === 'admin' ? 'badge-admin' : 'badge-staff'; ?>">
                                        <i class="fa-solid <?php echo $user['role'] === 'admin' ? 'fa-crown' : 'fa-user'; ?>"></i>
                                        <?php echo ucfirst($user['role']); ?>
                                    </span>
                                    <?php if (!empty($user['is_pharmacist'])): ?>
                                    <br><span style="display:inline-flex;align-items:center;gap:3px;background:#fee2e2;color:#b91c1c;border:1px solid #fca5a5;border-radius:5px;padding:2px 7px;font-size:.68rem;font-weight:700;margin-top:3px;">
                                        <i class="fa-solid fa-prescription-bottle-medical"></i> Pharmacist
                                        <?php if(!empty($user['license_number'])): ?> &middot; <?php echo htmlspecialchars($user['license_number']); ?><?php endif; ?>
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="date-info" data-tooltip="<?php echo date('F j, Y', strtotime($user['created_at'])); ?>">
                                        <i class="fa-regular fa-calendar"></i>
                                        <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                                    </span>
                                </td>
                                <td class="action-cell">
                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                    <button type="button" class="btn btn-warning btn-small" data-tooltip="Reset password"
                                        onclick="openResetModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>')">
                                        <i class="fa-solid fa-key"></i>
                                        Reset
                                    </button>
                                    <button type="button" class="btn btn-danger btn-small" data-tooltip="Delete user permanently"
                                        onclick="openDeleteModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['full_name'], ENT_QUOTES); ?>')">
                                        <i class="fa-regular fa-trash-can"></i>
                                        Delete
                                    </button>
                                    <?php else: ?>
                                    <span class="current-user-badge">
                                        <i class="fa-solid fa-user-check"></i>
                                        Current User
                                    </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add User Modal -->
<div id="addUserModal" class="modal">
    <div class="modal-content">
        <!-- Modal Header with Icon -->
        <div class="modal-header">
            <div class="modal-title">
                <div class="title-icon">
                    <i class="fa-solid fa-user-plus"></i>
                </div>
                <div>
                    <h2>Add New User</h2>
                    <p class="title-subtitle">Create a new account with role-based permissions</p>
                </div>
            </div>
            <button type="button" class="modal-close-btn" onclick="hideModal('addUserModal')">
                <i class="fa-solid fa-times"></i>
            </button>
        </div>

        <form method="POST" id="addUserForm">
            <input type="hidden" name="action" value="add_user">
            
            <!-- Form Sections -->
            <div class="form-sections">
                <!-- Account Information Section -->
                <div class="form-section">
                    <div class="section-header">
                        <i class="fa-solid fa-circle-user"></i>
                        <h3>Account Information</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="username">
                                <i class="fa-regular fa-at"></i>
                                Username <span class="required">*</span>
                            </label>
                            <div class="input-wrapper">
                                <input type="text" id="username" name="username" 
                                       placeholder="Enter username" required>
                                <div class="input-highlight"></div>
                            </div>
                            <span class="input-hint">Must be unique</span>
                        </div>

                        <div class="form-group">
                            <label for="password">
                                <i class="fa-solid fa-lock"></i>
                                Password <span class="required">*</span>
                            </label>
                            <div class="input-wrapper">
                                <input type="password" id="password" name="password" 
                                       placeholder="Enter password" required>
                                <div class="input-highlight"></div>
                            </div>
                            <span class="input-hint">Min. 8 characters</span>
                        </div>
                    </div>
                </div>

                <!-- Personal Information Section -->
                <div class="form-section">
                    <div class="section-header">
                        <i class="fa-regular fa-id-card"></i>
                        <h3>Personal Information</h3>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="full_name">
                                <i class="fa-regular fa-user"></i>
                                Full Name <span class="required">*</span>
                            </label>
                            <div class="input-wrapper">
                                <input type="text" id="full_name" name="full_name" 
                                       placeholder="Enter full name" required>
                                <div class="input-highlight"></div>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="role">
                                <i class="fa-solid fa-badge-check"></i>
                                Role <span class="required">*</span>
                            </label>
                            <div class="select-wrapper">
                                <select id="role" name="role" required>
                                    <option value="staff">Staff Member</option>
                                    <option value="admin">Administrator</option>
                                </select>
                                <i class="fa-solid fa-chevron-down select-arrow"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pharmacist License Section (Conditional) -->
                <div class="form-section license-section">
                    <div class="section-header">
                        <i class="fa-solid fa-prescription-bottle-medical"></i>
                        <h3>Pharmacist License</h3>
                        <span class="optional-badge">Optional</span>
                    </div>
                    
                    <!-- Simple Checkbox -->
                    <div class="license-checkbox">
                        <label class="checkbox-label">
                            <input type="checkbox" name="is_pharmacist" id="addIsPharmacist" value="1"
                                   onchange="toggleLicenseFields()">
                            <span class="checkbox-custom"></span>
                            <span class="checkbox-text">
                                <i class="fa-solid fa-prescription-bottle-medical"></i>
                                Register as Licensed Pharmacist
                            </span>
                        </label>
                        <p class="checkbox-hint">Enables prescription sales approval</p>
                    </div>

                    <!-- License Fields (Hidden by default) -->
                    <div id="addLicenseFields" class="license-fields">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="license_number">
                                    <i class="fa-regular fa-id-card"></i>
                                    License Number
                                </label>
                                <div class="input-wrapper">
                                    <input type="text" id="license_number" name="license_number" 
                                           placeholder="e.g., PRC-2024-12345">
                                    <div class="input-highlight"></div>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="license_expiry_date">
                                    <i class="fa-regular fa-calendar"></i>
                                    Expiry Date
                                </label>
                                <div class="input-wrapper">
                                    <input type="date" id="license_expiry_date" name="license_expiry_date">
                                    <div class="input-highlight"></div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- License Info Alert -->
                        <div class="license-alert">
                            <i class="fa-solid fa-circle-info"></i>
                            <span>Pharmacist license will be verified upon registration</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Actions -->
            <div class="modal-actions">
                <button type="button" class="btn btn-outline" onclick="hideModal('addUserModal')">
                    <i class="fa-solid fa-times"></i>
                    Cancel
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-check"></i>
                    Add User
                </button>
            </div>
        </form>
    </div>
</div>


    <script>
        function showModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
        
        function hideModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
            document.body.style.overflow = '';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
                document.body.style.overflow = '';
            }
        }
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modals = document.querySelectorAll('.modal[style*="display: block"]');
                modals.forEach(modal => {
                    modal.style.display = 'none';
                    document.body.style.overflow = '';
                });
            }
        });
         function toggleLicenseFields() {
        const checkbox = document.getElementById('addIsPharmacist');
        const licenseFields = document.getElementById('addLicenseFields');
        
        if (checkbox.checked) {
            licenseFields.classList.add('show');
        } else {
            licenseFields.classList.remove('show');
        }
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        const checkbox = document.getElementById('addIsPharmacist');
        if (checkbox) {
            toggleLicenseFields(); // Set initial state
        }
    });
    </script>
    <?php renderNotificationPanel($conn); ?>

<!-- ── Reset Password Modal ─────────────────────────────────────────────── -->
<div id="resetPasswordModal" class="modal">
    <div class="modal-content" style="max-width:460px;">
        <div class="modal-header" style="background:linear-gradient(135deg,#b45309,#d97706);border-radius:16px 16px 0 0;">
            <div class="modal-title">
                <div class="title-icon" style="background:rgba(255,255,255,.2);color:#fff;">
                    <i class="fa-solid fa-key"></i>
                </div>
                <div>
                    <h2 style="color:#fff;margin:0;font-size:1.1rem;">Reset Password</h2>
                    <p class="title-subtitle" style="color:rgba(255,255,255,.8);margin:0;font-size:.8rem;">
                        Set a new password for <strong id="resetUsername"></strong>
                    </p>
                </div>
            </div>
            <button type="button" class="modal-close-btn" onclick="hideModal('resetPasswordModal')" style="color:#fff;">
                <i class="fa-solid fa-times"></i>
            </button>
        </div>

        <form method="POST" id="resetPasswordForm" onsubmit="return validateResetForm()">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="resetUserId">

            <div style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem;">

                <div class="form-group">
                    <label style="font-size:.82rem;font-weight:600;color:#475569;display:flex;align-items:center;gap:6px;margin-bottom:6px;">
                        <i class="fa-solid fa-lock" style="color:#b45309;"></i>
                        New Password <span class="required">*</span>
                    </label>
                    <div class="input-wrapper" style="position:relative;">
                        <input type="password" id="newPassword" name="new_password"
                               placeholder="Enter new password (min. 6 chars)"
                               style="width:100%;padding:.65rem 2.5rem .65rem .9rem;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;box-sizing:border-box;"
                               required minlength="6">
                        <button type="button" onclick="togglePw('newPassword','eyeNew')"
                                style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;font-size:.95rem;">
                            <i class="fa-regular fa-eye" id="eyeNew"></i>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label style="font-size:.82rem;font-weight:600;color:#475569;display:flex;align-items:center;gap:6px;margin-bottom:6px;">
                        <i class="fa-solid fa-lock-open" style="color:#b45309;"></i>
                        Confirm New Password <span class="required">*</span>
                    </label>
                    <div class="input-wrapper" style="position:relative;">
                        <input type="password" id="confirmPassword" name="confirm_password"
                               placeholder="Re-enter new password"
                               style="width:100%;padding:.65rem 2.5rem .65rem .9rem;border:1.5px solid #e2e8f0;border-radius:10px;font-size:.9rem;box-sizing:border-box;"
                               required minlength="6">
                        <button type="button" onclick="togglePw('confirmPassword','eyeConfirm')"
                                style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#94a3b8;font-size:.95rem;">
                            <i class="fa-regular fa-eye" id="eyeConfirm"></i>
                        </button>
                    </div>
                    <span id="pwMismatch" style="display:none;color:#ef4444;font-size:.75rem;margin-top:4px;">
                        <i class="fa-solid fa-circle-exclamation"></i> Passwords do not match
                    </span>
                </div>

                <div class="modal-actions" style="display:flex;gap:.75rem;justify-content:flex-end;padding-top:.5rem;">
                    <button type="button" class="btn btn-outline" onclick="hideModal('resetPasswordModal')">
                        <i class="fa-solid fa-xmark"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fa-solid fa-key"></i> Reset Password
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ── Delete Confirmation Modal ─────────────────────────────────────────── -->
<div id="deleteUserModal" class="modal">
    <div class="modal-content" style="max-width:420px;">
        <div class="modal-header" style="background:linear-gradient(135deg,#991b1b,#ef4444);border-radius:16px 16px 0 0;">
            <div class="modal-title">
                <div class="title-icon" style="background:rgba(255,255,255,.2);color:#fff;">
                    <i class="fa-regular fa-trash-can"></i>
                </div>
                <div>
                    <h2 style="color:#fff;margin:0;font-size:1.1rem;">Delete User</h2>
                    <p class="title-subtitle" style="color:rgba(255,255,255,.8);margin:0;font-size:.8rem;">This action cannot be undone</p>
                </div>
            </div>
            <button type="button" class="modal-close-btn" onclick="hideModal('deleteUserModal')" style="color:#fff;">
                <i class="fa-solid fa-times"></i>
            </button>
        </div>

        <div style="padding:1.5rem;">
            <div style="text-align:center;margin-bottom:1.25rem;">
                <div style="width:64px;height:64px;background:#fee2e2;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto .75rem;">
                    <i class="fa-regular fa-trash-can" style="font-size:1.6rem;color:#ef4444;"></i>
                </div>
                <p style="color:#1e293b;font-size:.95rem;font-weight:600;margin:0 0 .4rem;">
                    Are you sure you want to delete<br>
                    <strong id="deleteUserName" style="color:#ef4444;"></strong>?
                </p>
                <p style="color:#64748b;font-size:.82rem;margin:0;">
                    All data associated with this user will be permanently removed and cannot be recovered.
                </p>
            </div>

            <form method="POST" id="deleteUserForm">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" id="deleteUserId">
                <div style="display:flex;gap:.75rem;justify-content:center;">
                    <button type="button" class="btn btn-outline" onclick="hideModal('deleteUserModal')" style="flex:1;">
                        <i class="fa-solid fa-xmark"></i> Cancel
                    </button>
                    <button type="submit" class="btn btn-danger" style="flex:1;">
                        <i class="fa-regular fa-trash-can"></i> Yes, Delete
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openResetModal(userId, username) {
    document.getElementById('resetUserId').value = userId;
    document.getElementById('resetUsername').textContent = username;
    document.getElementById('newPassword').value = '';
    document.getElementById('confirmPassword').value = '';
    document.getElementById('pwMismatch').style.display = 'none';
    showModal('resetPasswordModal');
    setTimeout(() => document.getElementById('newPassword').focus(), 200);
}

function openDeleteModal(userId, fullName) {
    document.getElementById('deleteUserId').value = userId;
    document.getElementById('deleteUserName').textContent = fullName;
    showModal('deleteUserModal');
}

function togglePw(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'fa-regular fa-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'fa-regular fa-eye';
    }
}

function validateResetForm() {
    const np = document.getElementById('newPassword').value;
    const cp = document.getElementById('confirmPassword').value;
    const msg = document.getElementById('pwMismatch');
    if (np !== cp) {
        msg.style.display = 'block';
        return false;
    }
    msg.style.display = 'none';
    return true;
}

// Live mismatch indicator
document.getElementById('confirmPassword').addEventListener('input', function() {
    const np  = document.getElementById('newPassword').value;
    const msg = document.getElementById('pwMismatch');
    msg.style.display = (this.value && this.value !== np) ? 'block' : 'none';
});
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