<?php
/**
 * Database Installation Script
 * SAM 2026 - Run this once to create database and tables
 * 
 * WARNING: This script should be removed or protected after installation
 */

// Security check - remove this in production or add proper authentication
$install_key = $_GET['key'] ?? '';
if ($install_key !== 'install_sam2026_2026') {
    die('Access denied. Please provide correct installation key.');
}

require_once __DIR__ . '/../config/database.php';

try {
    $dsn = "mysql:host=" . DB_HOST . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
    ];
    $db = new PDO($dsn, DB_USER, DB_PASS, $options);
    
    // Read SQL file
    $sqlFile = __DIR__ . '/schema.sql';
    if (!file_exists($sqlFile)) {
        die('Schema file not found: ' . $sqlFile);
    }
    
    $sql = file_get_contents($sqlFile);
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            if (empty($stmt)) {
                return false;
            }
            return !preg_match('/^(CREATE\\s+DATABASE|USE)\\b/i', $stmt);
        }
    );
    
    $db->exec(
        'CREATE DATABASE IF NOT EXISTS `' . DB_NAME . '` ' .
        'CHARACTER SET ' . DB_CHARSET . ' COLLATE ' . DB_CHARSET . '_unicode_ci'
    );
    $db->exec('USE `' . DB_NAME . '`');

    // Execute each statement
    foreach ($statements as $statement) {
        $db->exec($statement);
    }
    
    echo "<h2>Database Installation Successful!</h2>";
    echo "<p>Database and tables have been created successfully.</p>";
    echo "<p><strong>Default Administrator Account:</strong></p>";
    echo "<ul>";
    echo "<li>Username: <strong>admin</strong></li>";
    echo "<li>Password: <strong>admin123</strong></li>";
    echo "<li>Role: <strong>ADMIN</strong></li>";
    echo "</ul>";
    echo "<p style='color: red;'><strong>IMPORTANT:</strong> Please change the default password after first login!</p>";
    echo "<p><a href='" . BASE_URL . "auth/login.php'>Go to Login Page</a></p>";
    
} catch (PDOException $e) {
    echo "<h2>Installation Error</h2>";
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
