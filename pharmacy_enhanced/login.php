<?php
session_start();
require_once __DIR__ . '/config/database.php';

// Debug - remove this after testing
if (!isset($conn)) {
    die("Error: Database connection failed in login.php");
}

$error = "";

if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (!empty($username) && !empty($password)) {
        // Use MySQLi prepare
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? LIMIT 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            
            // Check password
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];

                // Log activity
                $action = "Login";
                $description = "User logged into the system";
                $log_stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, description) VALUES (?, ?, ?)");
                $log_stmt->bind_param("iss", $user['id'], $action, $description);
                $log_stmt->execute();
                $log_stmt->close();

                header("Location: index.php");
                exit();
            } else {
                $error = "Invalid username or password";
            }
        } else {
            $error = "Invalid username or password";
        }
        $stmt->close();
    } else {
        $error = "Please fill in all fields";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Login | CAD Crest Pharmaceutical</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-green: #004d40;
            --accent-gold: #b8860b;
            --bg-light: #f4f7f6;
            --text-dark: #2c3e50;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #004d40 0%, #00251a 100% );
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-card {
            background: white;
            width: 100%;
            max-width: 420px;
            padding: 40px;
            border-radius: 24px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 6px;
            background: linear-gradient(90deg, var(--primary-green), var(--accent-gold));
        }

        .logo-container {
            margin-bottom: 25px;
        }

        .logo-container img {
            width: 120px;
            height: auto;
            filter: drop-shadow(0 4px 8px rgba(0,0,0,0.1));
        }

        .company-name {
            color: var(--primary-green);
            font-size: 1.4rem;
            font-weight: 700;
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .system-title {
            color: #666;
            font-size: 0.9rem;
            margin-bottom: 30px;
            font-weight: 400;
        }

        .form-group {
            text-align: left;
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--text-dark);
            margin-bottom: 8px;
            margin-left: 4px;
        }

        .form-group input {
            width: 100%;
            padding: 14px 18px;
            border: 2px solid #edf2f7;
            border-radius: 12px;
            font-size: 1rem;
            transition: all 0.3s ease;
            box-sizing: border-box;
            background: #f8fafc;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary-green);
            background: white;
            box-shadow: 0 0 0 4px rgba(0, 77, 64, 0.1);
        }

        .btn-login {
            width: 100%;
            padding: 16px;
            background: var(--primary-green);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
            box-shadow: 0 4px 12px rgba(0, 77, 64, 0.2);
        }

        .btn-login:hover {
            background: #00695c;
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(0, 77, 64, 0.3);
        }

        .error-msg {
            background: #fff5f5;
            color: #c53030;
            padding: 12px;
            border-radius: 10px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            border-left: 4px solid #c53030;
            text-align: left;
        }

        .footer-text {
            margin-top: 30px;
            font-size: 0.75rem;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="logo-container">
            <!-- Ensure your logo is in assets/img/logo.jpg -->
            <img src="assets/img/nobg_logo.png" alt="CAD Crest Logo">
        </div>
        <div class="company-name">CAD Crest</div>
        <div class="system-title">Pharmaceutical Products Trading</div>

        <?php if ($error): ?>
            <div class="error-msg"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder="Enter your username" required>
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter your password" required>
            </div>
            <button type="submit" class="btn-login">SIGN IN</button>
        </form>

        <div class="footer-text">
            &copy; <?php echo date('Y'); ?> CAD Crest Pharmaceutical. All rights reserved.
        </div>
    </div>
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