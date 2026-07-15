<?php
// config/database.php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'tharu_products_db');

function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die("Critical Database Connection Failure: " . $conn->connect_error);
    }
    return $conn;
}
?>