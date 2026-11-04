<?php
// includes/auth_check.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

function isStaff() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'staff';
}

function restrictAccess() {
    if (!isLoggedIn()) {
        header("Location: ../login.php");
        exit();
    }
}

function restrictToAdmin() {
    if (!isLoggedIn()) {
        header("Location: ../login.php");
        exit();
    }
    if (!isAdmin()) {
        header("Location: ../index.php?error=unauthorized");
        exit();
    }
}

function restrictToStaff() {
    if (!isLoggedIn()) {
        header("Location: ../login.php");
        exit();
    }
    if (!isStaff() && !isAdmin()) {
        header("Location: ../index.php?error=unauthorized");
        exit();
    }
}

function getCurrentUser() {
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'full_name' => $_SESSION['full_name'] ?? null,
        'role' => $_SESSION['role'] ?? null
    ];
}

restrictAccess();
?>