<?php
// Define the password you want to use to log in
$password = 'owner123'; 

// Generate the secure bcrypt hash
$hashed_password = password_hash($password, PASSWORD_BCRYPT);

echo "<h3>Your Generated Hash:</h3>";
echo "<code style='background:#f1f5f9; padding:5px 10px; border-radius:4px; font-size:1.2rem; display:block; word-break:break-all;'> " . $hashed_password . " </code>";
echo "<p>Copy this entire string and paste it into your database's password field.</p>";
?>