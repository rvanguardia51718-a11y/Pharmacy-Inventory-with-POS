<?php
echo "Testing pos_process.php<br>";
echo "File exists: " . (file_exists('pos_process.php') ? 'Yes' : 'No') . "<br>";
echo "File size: " . filesize('pos_process.php') . " bytes<br>";
echo "First 100 characters:<br>";
echo "<pre>";
echo htmlspecialchars(substr(file_get_contents('pos_process.php'), 0, 500));
echo "</pre>";
?>