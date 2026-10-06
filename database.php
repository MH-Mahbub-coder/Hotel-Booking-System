<?php
// database.php — Aurelia Grand HMS
$servername = "localhost";
$username   = "root";
$password   = "";           // XAMPP default: no password
$dbname     = "aurelia_grand";

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    if ($_SERVER['HTTP_HOST'] == 'localhost' || $_SERVER['HTTP_HOST'] == '127.0.0.1') {
        die("Database connection failed: " . $conn->connect_error);
    } else {
        die("Service temporarily unavailable. Please try again later.");
    }
}

$conn->set_charset("utf8mb4");
?>
