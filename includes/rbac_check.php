<?php
/**
 * RBAC Check Helper
 * Include this file at the top of pages that need role-based access control
 * 
 * Usage:
 * require_once __DIR__ . '/includes/rbac_check.php';
 * 
 * Or use in page:
 * $rbac->requirePageAccess(__FILE__);
 */

if (!defined('SKIP_RBAC_CHECK')) {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../config/rbac.php';
    
    Session::start();
    $auth = getAuth();
    $rbac = getRBAC();
    
    // Require authentication
    $auth->requireAuth();
    
    // Get current page path
    $currentPage = $_SERVER['PHP_SELF'] ?? 'index.php';
    $scriptPath = str_replace('\\', '/', $currentPage);
    $basePath = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] . BASE_URL);
    $relativePath = str_replace($basePath, '', $scriptPath);
    $relativePath = ltrim($relativePath, '/');
    
    // Normalize path
    if (empty($relativePath) || $relativePath === 'index.php') {
        $relativePath = 'index.php';
    }
    
    // Check page access
    $rbac->requirePageAccess($relativePath);
}

