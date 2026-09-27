<?php
/**
 * RBAC Permissions API
 * Handles permissions listing (for role assignment)
 */

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/rbac.php';

Session::start();
header('Content-Type: application/json');

$auth = getAuth();
$rbac = getRBAC();
$db = getDB();

// Check if user is admin
if (!$auth->isLoggedIn() || !$rbac->hasPageAccess('pages/settings.php')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    if ($method === 'GET' && $action === 'list') {
        // Get all permissions
        $stmt = $db->query("
            SELECT p.*, 
                   COUNT(DISTINCT rp.role_id) as role_count
            FROM permissions p
            LEFT JOIN role_permissions rp ON p.id = rp.permission_id
            GROUP BY p.id
            ORDER BY p.module, p.permission_name
        ");
        $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $permissions]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log("RBAC Permissions API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}

