<?php
require_once __DIR__ . '/api.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$servername = getenv('AURELIA_DB_HOST') ?: 'localhost';
$username = getenv('AURELIA_DB_USER') ?: 'root';
$password = getenv('AURELIA_DB_PASSWORD') ?: '';
$dbname = getenv('AURELIA_DB_NAME') ?: 'aurelia_grand';
$port = (int)(getenv('AURELIA_DB_PORT') ?: 3306);

if (getenv('APP_ENV') === 'production' && ($username === 'root' || $password === '')) {
    throw new RuntimeException('Production database credentials are not configured.');
}

$conn = new mysqli($servername, $username, $password, $dbname, $port);
$conn->set_charset('utf8mb4');
