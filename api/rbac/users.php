<?php
/**
 * RBAC User-Role Assignment API
 * Handles user role assignments
 */

require_once __DIR__ . '/../../config.php';
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
    switch ($method) {
        case 'GET':
            if ($action === 'list') {
                // Get all users with their roles
                $stmt = $db->query("
                    SELECT u.id, u.username, u.email, u.full_name, u.status,
                           GROUP_CONCAT(DISTINCT r.role_code ORDER BY r.role_code SEPARATOR ', ') as roles,
                           GROUP_CONCAT(DISTINCT r.id ORDER BY r.id SEPARATOR ',') as role_ids
                    FROM users u
                    LEFT JOIN user_roles ur ON u.id = ur.user_id AND ur.is_active = TRUE AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                    LEFT JOIN roles r ON ur.role_id = r.id
                    WHERE u.deleted_at IS NULL
                    GROUP BY u.id
                    ORDER BY u.full_name
                ");
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $users]);
            } elseif ($action === 'get' && isset($_GET['id'])) {
                // Get single user with roles
                $stmt = $db->prepare("
                    SELECT u.* 
                    FROM users u
                    WHERE u.id = :id AND u.deleted_at IS NULL
                ");
                $stmt->execute([':id' => $_GET['id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    // Get user's roles
                    $stmt = $db->prepare("
                        SELECT r.*, ur.expires_at, ur.assigned_at
                        FROM roles r
                        INNER JOIN user_roles ur ON r.id = ur.role_id
                        WHERE ur.user_id = :user_id 
                        AND ur.is_active = TRUE
                        AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                    ");
                    $stmt->execute([':user_id' => $_GET['id']]);
                    $user['roles'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    echo json_encode(['success' => true, 'data' => $user]);
                } else {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Users not found']);
                }
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
            
        case 'POST':
            if ($action === 'assign') {
                $data = json_decode(file_get_contents('php://input'), true);
                
                $userId = $data['user_id'] ?? null;
                $roleId = $data['role_id'] ?? null;
                $expiresAt = isset($data['expires_at']) ? $data['expires_at'] : null;
                
                if (!$userId || !$roleId) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'User ID and role ID are required']);
                    exit;
                }
                
                // Check if assignment already exists
                $stmt = $db->prepare("
                    SELECT id FROM user_roles 
                    WHERE user_id = :user_id AND role_id = :role_id
                ");
                $stmt->execute([':user_id' => $userId, ':role_id' => $roleId]);
                if ($existing = $stmt->fetch()) {
                    // Update existing assignment
                    $stmt = $db->prepare("
                        UPDATE user_roles 
                        SET is_active = TRUE, expires_at = :expires_at
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':id' => $existing['id'],
                        ':expires_at' => $expiresAt
                    ]);
                } else {
                    // Create new assignment
                    $currentUserId = Session::get('user_id');
                    $stmt = $db->prepare("
                        INSERT INTO user_roles (user_id, role_id, assigned_by, expires_at)
                        VALUES (:user_id, :role_id, :assigned_by, :expires_at)
                    ");
                    $stmt->execute([
                        ':user_id' => $userId,
                        ':role_id' => $roleId,
                        ':assigned_by' => $currentUserId,
                        ':expires_at' => $expiresAt
                    ]);
                }
                
                // Check admin lockout prevention
                if (method_exists($rbac, 'hasAtLeastOneAdmin')) {
                    $roleStmt = $db->prepare("SELECT role_code FROM roles WHERE id = :role_id");
                    $roleStmt->execute([':role_id' => $roleId]);
                    $role = $roleStmt->fetch();
                    
                    if ($role && $role['role_code'] !== 'ADMIN') {
                        // If removing ADMIN role, check if at least one admin remains
                        // This check happens on removal, not assignment
                    }
                }
                
                // Clear RBAC cache
                if (method_exists($rbac, 'clearCache')) {
                    $rbac->clearCache();
                }
                
                echo json_encode(['success' => true, 'message' => 'Role assigned successfully']);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
            
        case 'DELETE':
            if ($action === 'remove' && isset($_GET['user_id']) && isset($_GET['role_id'])) {
                $userId = $_GET['user_id'];
                $roleId = $_GET['role_id'];
                
                // Get role code to check if it's ADMIN
                $stmt = $db->prepare("SELECT role_code FROM roles WHERE id = :role_id");
                $stmt->execute([':role_id' => $roleId]);
                $role = $stmt->fetch();
                
                if ($role && $role['role_code'] === 'ADMIN') {
                    // Check if this is the last admin
                    $stmt = $db->prepare("
                        SELECT COUNT(*) as count
                        FROM user_roles ur
                        INNER JOIN roles r ON ur.role_id = r.id
                        WHERE r.role_code = 'ADMIN'
                        AND ur.user_id != :user_id
                        AND ur.is_active = TRUE
                        AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
                    ");
                    $stmt->execute([':user_id' => $userId]);
                    $result = $stmt->fetch();
                    
                    if (!$result || $result['count'] == 0) {
                        http_response_code(400);
                        echo json_encode(['success' => false, 'message' => 'The last ADMIN role cannot be removed. At least one administrator must remain.']);
                        exit;
                    }
                }
                
                // Remove role assignment (soft delete by setting is_active = FALSE)
                $stmt = $db->prepare("
                    UPDATE user_roles 
                    SET is_active = FALSE 
                    WHERE user_id = :user_id AND role_id = :role_id
                ");
                $stmt->execute([':user_id' => $userId, ':role_id' => $roleId]);
                
                // Clear RBAC cache
                if (method_exists($rbac, 'clearCache')) {
                    $rbac->clearCache();
                }
                
                echo json_encode(['success' => true, 'message' => 'Role removed successfully']);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    }
} catch (PDOException $e) {
    error_log("RBAC User API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}
