<?php
/**
 * Logout Page
 * SAM 2026 - User Logout
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

// Start session
Session::start();

// Logout user
$auth = getAuth();
$auth->logout();

// Redirect to login
header('Location: ' . url('auth/login.php'));
exit;
