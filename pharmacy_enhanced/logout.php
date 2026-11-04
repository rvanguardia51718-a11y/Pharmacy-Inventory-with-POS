<?php
session_start();
require_once 'config/database.php';

if (isset($_SESSION['user_id']) && function_exists('logActivity')) {
    logActivity($conn, $_SESSION['user_id'], 'Logout', 'User logged out of the system');
}

// Unset all of the session variables.
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finally, destroy the session.
session_destroy();

header("Location: login.php");
exit();
?>