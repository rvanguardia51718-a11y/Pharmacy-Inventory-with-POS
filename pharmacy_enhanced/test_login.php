<?php
require_once 'config/database.php';

// Hash for 'staff123'
$staff_password = password_hash('staff123', PASSWORD_DEFAULT);

// Update all staff users
$stmt = $conn->prepare("UPDATE users SET password = ? WHERE role = 'staff'");
$stmt->bind_param("s", $staff_password);

if ($stmt->execute()) {
    $affected = $stmt->affected_rows;
    echo "<h2>✅ Staff Passwords Updated Successfully!</h2>";
    echo "<p>Changed password for <strong>$affected</strong> staff user(s) to: <strong>staff123</strong></p>";
} else {
    echo "<h2>❌ Error updating passwords</h2>";
    echo "<p>" . $conn->error . "</p>";
}

$stmt->close();

// Show updated staff users
$result = $conn->query("SELECT id, username, full_name FROM users WHERE role = 'staff'");
echo "<h3>Updated Staff Accounts:</h3>";
echo "<ul>";
while ($row = $result->fetch_assoc()) {
    echo "<li><strong>" . $row['username'] . "</strong> - " . $row['full_name'] . " (password: staff123)</li>";
}
echo "</ul>";

echo "<br><a href='login.php' style='padding: 10px 20px; background: #2e7d32; color: white; text-decoration: none; border-radius: 5px;'>Go to Login Page</a>";
?>