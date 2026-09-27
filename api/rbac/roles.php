<?php
/**
 * RBAC Roles API
 * Handles CRUD operations for roles
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
                // Get all roles
                $stmt = $db->query("
                    SELECT r.*, 
                           COUNT(DISTINCT ur.user_id) as user_count,
                           COUNT(DISTINCT rp.permission_id) as permission_count
                    FROM roles r
                    LEFT JOIN user_roles ur ON r.id = ur.role_id AND ur.is_active = TRUE
                    LEFT JOIN role_permissions rp ON r.id = rp.role_id
                    GROUP BY r.id
                    ORDER BY r.role_code
                ");
                $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $roles]);
            } elseif ($action === 'get' && isset($_GET['id'])) {
                // Get single role
                $stmt = $db->prepare("SELECT * FROM roles WHERE id = :id");
                $stmt->execute([':id' => $_GET['id']]);
                $role = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($role) {
                    // Get permissions for this role
                    $stmt = $db->prepare("
                        SELECT p.* 
                        FROM permissions p
                        INNER JOIN role_permissions rp ON p.id = rp.permission_id
                        WHERE rp.role_id = :role_id
                    ");
                    $stmt->execute([':role_id' => $_GET['id']]);
                    $role['permissions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    echo json_encode(['success' => true, 'data' => $role]);
                } else {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Roles not found']);
                }
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
            
        case 'POST':
            if ($action === 'create') {
                $data = json_decode(file_get_contents('php://input'), true);
                
                $roleCode = strtoupper(trim($data['role_code'] ?? ''));
                $roleName = trim($data['role_name'] ?? '');
                $description = trim($data['description'] ?? '');
                $isSystemRole = isset($data['is_system_role']) ? (bool)$data['is_system_role'] : false;
                
                // Validation
                if (empty($roleCode) || empty($roleName)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Role code and name are required']);
                    exit;
                }
                
                // Check if role code already exists
                $stmt = $db->prepare("SELECT id FROM roles WHERE role_code = :role_code");
                $stmt->execute([':role_code' => $roleCode]);
                if ($stmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Role code already exists']);
                    exit;
                }
                
                // Create role
                $stmt = $db->prepare("
                    INSERT INTO roles (role_code, role_name, description, is_system_role)
                    VALUES (:role_code, :role_name, :description, :is_system_role)
                ");
                $stmt->execute([
                    ':role_code' => $roleCode,
                    ':role_name' => $roleName,
                    ':description' => $description,
                    ':is_system_role' => $isSystemRole
                ]);
                
                $roleId = $db->lastInsertId();
                
                // Assign permissions if provided
                if (isset($data['permissions']) && is_array($data['permissions'])) {
                    $stmt = $db->prepare("
                        INSERT INTO role_permissions (role_id, permission_id)
                        VALUES (:role_id, :permission_id)
                    ");
                    foreach ($data['permissions'] as $permissionId) {
                        $stmt->execute([
                            ':role_id' => $roleId,
                            ':permission_id' => $permissionId
                        ]);
                    }
                }
                
                // Clear RBAC cache
                if (method_exists($rbac, 'clearCache')) {
                    $rbac->clearCache();
                }
                
                echo json_encode(['success' => true, 'message' => 'Role created successfully', 'id' => $roleId]);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
            
        case 'PUT':
            if ($action === 'update' && isset($_GET['id'])) {
                $data = json_decode(file_get_contents('php://input'), true);
                $roleId = $_GET['id'];
                
                // Prevent modification of system roles (except name/description)
                $stmt = $db->prepare("SELECT is_system_role FROM roles WHERE id = :id");
                $stmt->execute([':id' => $roleId]);
                $role = $stmt->fetch();
                
                if (!$role) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Roles not found']);
                    exit;
                }
                
                // Update role
                $updates = [];
                $params = [':id' => $roleId];
                
                if (isset($data['role_name'])) {
                    $updates[] = "role_name = :role_name";
                    $params[':role_name'] = trim($data['role_name']);
                }
                
                if (isset($data['description'])) {
                    $updates[] = "description = :description";
                    $params[':description'] = trim($data['description']);
                }
                
                if (!empty($updates)) {
                    $sql = "UPDATE roles SET " . implode(', ', $updates) . " WHERE id = :id";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                }
                
                // Update permissions if provided
                if (isset($data['permissions']) && is_array($data['permissions'])) {
                    // Remove existing permissions
                    $stmt = $db->prepare("DELETE FROM role_permissions WHERE role_id = :role_id");
                    $stmt->execute([':role_id' => $roleId]);
                    
                    // Add new permissions
                    $stmt = $db->prepare("
                        INSERT INTO role_permissions (role_id, permission_id)
                        VALUES (:role_id, :permission_id)
                    ");
                    foreach ($data['permissions'] as $permissionId) {
                        $stmt->execute([
                            ':role_id' => $roleId,
                            ':permission_id' => $permissionId
                        ]);
                    }
                }
                
                // Clear RBAC cache
                if (method_exists($rbac, 'clearCache')) {
                    $rbac->clearCache();
                }
                
                echo json_encode(['success' => true, 'message' => 'Role updated successfully']);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
            
        case 'DELETE':
            if ($action === 'delete' && isset($_GET['id'])) {
                $roleId = $_GET['id'];
                
                // Check if it's a system role
                $stmt = $db->prepare("SELECT role_code, is_system_role FROM roles WHERE id = :id");
                $stmt->execute([':id' => $roleId]);
                $role = $stmt->fetch();
                
                if (!$role) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Roles not found']);
                    exit;
                }
                
                // Prevent deletion of ADMIN role
                if ($role['role_code'] === 'ADMIN') {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'The ADMIN role cannot be deleted']);
                    exit;
                }
                
                // Check if role is assigned to any users
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM user_roles WHERE role_id = :role_id AND is_active = TRUE");
                $stmt->execute([':role_id' => $roleId]);
                $result = $stmt->fetch();
                
                if ($result && $result['count'] > 0) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'This role is still assigned to users. Remove the assignments first.']);
                    exit;
                }
                
                // Delete role (cascade will handle role_permissions and user_roles)
                $stmt = $db->prepare("DELETE FROM roles WHERE id = :id");
                $stmt->execute([':id' => $roleId]);
                
                // Clear RBAC cache
                if (method_exists($rbac, 'clearCache')) {
                    $rbac->clearCache();
                }
                
                echo json_encode(['success' => true, 'message' => 'Role deleted successfully']);
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
    error_log("RBAC API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}
