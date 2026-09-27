<?php
/**
 * RBAC Page Access Rules API
 * Handles page access rule configuration
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

function toTinyInt($value): int {
    if (is_bool($value)) return $value ? 1 : 0;
    if (is_int($value)) return $value === 0 ? 0 : 1;
    $s = strtolower(trim((string)$value));
    if ($s === '' || $s === '0' || $s === 'false' || $s === 'off' || $s === 'no') return 0;
    return 1;
}

function ensurePageExclusionTable(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS page_access_exclusions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            page_path VARCHAR(255) NOT NULL UNIQUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            created_by INT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function syncPageRulesFromFilesystem(PDO $db, ?int $createdBy = null): void {
    $baseDir = realpath(__DIR__ . '/../../');
    if ($baseDir === false || !is_dir($baseDir)) return;

    $publicDefaults = [
        'auth/login.php' => ['is_public' => 1, 'requires_auth' => 0],
        'auth/logout.php' => ['is_public' => 1, 'requires_auth' => 0],
        'auth/ajax-login.php' => ['is_public' => 1, 'requires_auth' => 0],
        'pages/access-denied.php' => ['is_public' => 1, 'requires_auth' => 0],
    ];

    $discovered = [];

    // Root entry pages
    $indexPath = $baseDir . DIRECTORY_SEPARATOR . 'index.php';
    if (is_file($indexPath)) $discovered[] = 'index.php';

    // All pages/*.php
    $pagesDir = $baseDir . DIRECTORY_SEPARATOR . 'pages';
    if (is_dir($pagesDir)) {
        $pageFiles = glob($pagesDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        foreach ($pageFiles as $fp) {
            $name = basename((string)$fp);
            if ($name === '' || $name[0] === '_') continue;
            $discovered[] = 'pages/' . $name;
        }
    }

    // Public/auth files
    $authDir = $baseDir . DIRECTORY_SEPARATOR . 'auth';
    if (is_dir($authDir)) {
        $authFiles = glob($authDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        foreach ($authFiles as $fp) {
            $name = basename((string)$fp);
            if ($name === '' || $name[0] === '_') continue;
            $discovered[] = 'auth/' . $name;
        }
    }

    if (empty($discovered)) return;

    $existingStmt = $db->query("SELECT page_path FROM page_access_rules");
    $existing = $existingStmt ? $existingStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $existingMap = [];
    foreach ($existing as $p) {
        $existingMap[strtolower(trim((string)$p))] = true;
    }

    $excludedStmt = $db->query("SELECT page_path FROM page_access_exclusions");
    $excluded = $excludedStmt ? $excludedStmt->fetchAll(PDO::FETCH_COLUMN) : [];
    $excludedMap = [];
    foreach ($excluded as $p) {
        $excludedMap[strtolower(trim((string)$p))] = true;
    }

    $insert = $db->prepare("
        INSERT INTO page_access_rules (page_path, is_public, requires_auth, created_by)
        VALUES (:page_path, :is_public, :requires_auth, :created_by)
    ");

    foreach ($discovered as $pagePath) {
        $key = strtolower($pagePath);
        // Respect exclusions: pages deleted by admin in settings should stay hidden.
        if (isset($excludedMap[$key])) continue;
        if (isset($existingMap[$key])) continue;
        $default = $publicDefaults[$pagePath] ?? ['is_public' => 0, 'requires_auth' => 1];
        $insert->execute([
            ':page_path' => $pagePath,
            ':is_public' => (int)$default['is_public'],
            ':requires_auth' => (int)$default['requires_auth'],
            ':created_by' => $createdBy,
        ]);
        $existingMap[$key] = true;
    }
}

try {
    ensurePageExclusionTable($db);
    switch ($method) {
        case 'GET':
            if ($action === 'list') {
                syncPageRulesFromFilesystem($db, Session::get('user_id') ? (int)Session::get('user_id') : null);
                // Get all page access rules with their roles
                $stmt = $db->query("
                    SELECT par.*,
                           GROUP_CONCAT(DISTINCT r.role_code ORDER BY r.role_code SEPARATOR ', ') as allowed_roles,
                           GROUP_CONCAT(DISTINCT r.id ORDER BY r.id SEPARATOR ',') as role_ids
                    FROM page_access_rules par
                    LEFT JOIN page_role_access pra ON par.id = pra.page_rule_id
                    LEFT JOIN roles r ON pra.role_id = r.id
                    GROUP BY par.id
                    ORDER BY par.page_path
                ");
                $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $rules]);
            } elseif ($action === 'get' && isset($_GET['id'])) {
                // Get single page rule
                $stmt = $db->prepare("SELECT * FROM page_access_rules WHERE id = :id");
                $stmt->execute([':id' => $_GET['id']]);
                $rule = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($rule) {
                    // Get roles for this page
                    $stmt = $db->prepare("
                        SELECT r.* 
                        FROM roles r
                        INNER JOIN page_role_access pra ON r.id = pra.role_id
                        WHERE pra.page_rule_id = :page_rule_id
                    ");
                    $stmt->execute([':page_rule_id' => $_GET['id']]);
                    $rule['roles'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    echo json_encode(['success' => true, 'data' => $rule]);
                } else {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Rules not found']);
                }
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
            
        case 'POST':
            if ($action === 'create') {
                $data = json_decode(file_get_contents('php://input'), true);
                
                $pagePath = trim($data['page_path'] ?? '');
                $isPublic = isset($data['is_public']) ? toTinyInt($data['is_public']) : 0;
                $requiresAuth = isset($data['requires_auth']) ? toTinyInt($data['requires_auth']) : 1;
                $roleIds = $data['role_ids'] ?? [];
                
                if (empty($pagePath)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Page path is required']);
                    exit;
                }
                
                // Check if rule already exists
                $stmt = $db->prepare("SELECT id FROM page_access_rules WHERE page_path = :page_path");
                $stmt->execute([':page_path' => $pagePath]);
                if ($existing = $stmt->fetch()) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'A rule already exists for this page']);
                    exit;
                }
                
                $currentUserId = Session::get('user_id');
                
                // Create page rule
                $stmt = $db->prepare("
                    INSERT INTO page_access_rules (page_path, is_public, requires_auth, created_by)
                    VALUES (:page_path, :is_public, :requires_auth, :created_by)
                ");
                $stmt->execute([
                    ':page_path' => $pagePath,
                    ':is_public' => $isPublic,
                    ':requires_auth' => $requiresAuth,
                    ':created_by' => $currentUserId
                ]);

                // If user manually adds a page rule, remove it from exclusion list (if existed)
                $stmt = $db->prepare("DELETE FROM page_access_exclusions WHERE page_path = :page_path");
                $stmt->execute([':page_path' => $pagePath]);
                
                $pageRuleId = $db->lastInsertId();
                
                // Assign roles if provided and not public
                if (!$isPublic && !empty($roleIds)) {
                    $stmt = $db->prepare("
                        INSERT INTO page_role_access (page_rule_id, role_id, created_by)
                        VALUES (:page_rule_id, :role_id, :created_by)
                    ");
                    foreach ($roleIds as $roleId) {
                        $stmt->execute([
                            ':page_rule_id' => $pageRuleId,
                            ':role_id' => $roleId,
                            ':created_by' => $currentUserId
                        ]);
                    }
                }
                
                // Clear RBAC cache
                if (method_exists($rbac, 'clearCache')) {
                    $rbac->clearCache();
                }
                
                echo json_encode(['success' => true, 'message' => 'Rule created successfully', 'id' => $pageRuleId]);
            } elseif ($action === 'update' && isset($_GET['id'])) {
                // POST fallback for environments that block PUT (e.g., some Windows server setups)
                $data = json_decode(file_get_contents('php://input'), true);
                if (!is_array($data)) $data = [];
                $pageRuleId = (int)$_GET['id'];

                $updates = [];
                $params = [':id' => $pageRuleId];

                if (array_key_exists('is_public', $data)) {
                    $updates[] = "is_public = :is_public";
                    $params[':is_public'] = toTinyInt($data['is_public']);
                }

                if (array_key_exists('requires_auth', $data)) {
                    $updates[] = "requires_auth = :requires_auth";
                    $params[':requires_auth'] = toTinyInt($data['requires_auth']);
                }

                if (!empty($updates)) {
                    $currentUserId = Session::get('user_id');
                    $updates[] = "updated_by = :updated_by";
                    $params[':updated_by'] = $currentUserId;
                    $sql = "UPDATE page_access_rules SET " . implode(', ', $updates) . " WHERE id = :id";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                }

                if (isset($data['role_ids']) && is_array($data['role_ids'])) {
                    $stmt = $db->prepare("DELETE FROM page_role_access WHERE page_rule_id = :page_rule_id");
                    $stmt->execute([':page_rule_id' => $pageRuleId]);

                    if (!empty($data['role_ids'])) {
                        $currentUserId = Session::get('user_id');
                        $stmt = $db->prepare("
                            INSERT INTO page_role_access (page_rule_id, role_id, created_by)
                            VALUES (:page_rule_id, :role_id, :created_by)
                        ");
                        foreach ($data['role_ids'] as $roleId) {
                            $stmt->execute([
                                ':page_rule_id' => $pageRuleId,
                                ':role_id' => $roleId,
                                ':created_by' => $currentUserId
                            ]);
                        }
                    }
                }

                if (method_exists($rbac, 'clearCache')) {
                    $rbac->clearCache();
                }

                echo json_encode(['success' => true, 'message' => 'Rule updated successfully']);
            } elseif ($action === 'delete' && isset($_GET['id'])) {
                // POST fallback for environments that block DELETE
                $pageRuleId = (int)$_GET['id'];

                $pathStmt = $db->prepare("SELECT page_path FROM page_access_rules WHERE id = :id");
                $pathStmt->execute([':id' => $pageRuleId]);
                $row = $pathStmt->fetch(PDO::FETCH_ASSOC);
                $pagePath = $row['page_path'] ?? null;
                if ($pagePath) {
                    $exStmt = $db->prepare("
                        INSERT INTO page_access_exclusions (page_path, created_by)
                        VALUES (:page_path, :created_by)
                        ON DUPLICATE KEY UPDATE page_path = VALUES(page_path)
                    ");
                    $exStmt->execute([
                        ':page_path' => $pagePath,
                        ':created_by' => Session::get('user_id') ? (int)Session::get('user_id') : null,
                    ]);
                }

                $stmt = $db->prepare("DELETE FROM page_access_rules WHERE id = :id");
                $stmt->execute([':id' => $pageRuleId]);

                if (method_exists($rbac, 'clearCache')) {
                    $rbac->clearCache();
                }

                echo json_encode(['success' => true, 'message' => 'Rule deleted successfully']);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
            
        case 'PUT':
            if ($action === 'update' && isset($_GET['id'])) {
                $data = json_decode(file_get_contents('php://input'), true);
                $pageRuleId = $_GET['id'];
                
                $updates = [];
                $params = [':id' => $pageRuleId];
                
                if (isset($data['is_public'])) {
                    $updates[] = "is_public = :is_public";
                    $params[':is_public'] = toTinyInt($data['is_public']);
                }
                
                if (isset($data['requires_auth'])) {
                    $updates[] = "requires_auth = :requires_auth";
                    $params[':requires_auth'] = toTinyInt($data['requires_auth']);
                }
                
                if (!empty($updates)) {
                    $currentUserId = Session::get('user_id');
                    $updates[] = "updated_by = :updated_by";
                    $params[':updated_by'] = $currentUserId;
                    
                    $sql = "UPDATE page_access_rules SET " . implode(', ', $updates) . " WHERE id = :id";
                    $stmt = $db->prepare($sql);
                    $stmt->execute($params);
                }
                
                // Update roles if provided
                if (isset($data['role_ids']) && is_array($data['role_ids'])) {
                    // Remove existing role assignments
                    $stmt = $db->prepare("DELETE FROM page_role_access WHERE page_rule_id = :page_rule_id");
                    $stmt->execute([':page_rule_id' => $pageRuleId]);
                    
                    // Add new role assignments
                    if (!empty($data['role_ids'])) {
                        $currentUserId = Session::get('user_id');
                        $stmt = $db->prepare("
                            INSERT INTO page_role_access (page_rule_id, role_id, created_by)
                            VALUES (:page_rule_id, :role_id, :created_by)
                        ");
                        foreach ($data['role_ids'] as $roleId) {
                            $stmt->execute([
                                ':page_rule_id' => $pageRuleId,
                                ':role_id' => $roleId,
                                ':created_by' => $currentUserId
                            ]);
                        }
                    }
                }
                
                // Clear RBAC cache
                if (method_exists($rbac, 'clearCache')) {
                    $rbac->clearCache();
                }
                
                echo json_encode(['success' => true, 'message' => 'Rule updated successfully']);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
            
        case 'DELETE':
            if ($action === 'delete' && isset($_GET['id'])) {
                $pageRuleId = $_GET['id'];

                // Capture page_path for exclusion before delete
                $pathStmt = $db->prepare("SELECT page_path FROM page_access_rules WHERE id = :id");
                $pathStmt->execute([':id' => $pageRuleId]);
                $row = $pathStmt->fetch(PDO::FETCH_ASSOC);
                $pagePath = $row['page_path'] ?? null;
                if ($pagePath) {
                    $exStmt = $db->prepare("
                        INSERT INTO page_access_exclusions (page_path, created_by)
                        VALUES (:page_path, :created_by)
                        ON DUPLICATE KEY UPDATE page_path = VALUES(page_path)
                    ");
                    $exStmt->execute([
                        ':page_path' => $pagePath,
                        ':created_by' => Session::get('user_id') ? (int)Session::get('user_id') : null,
                    ]);
                }
                
                // Delete page rule (cascade will handle page_role_access)
                $stmt = $db->prepare("DELETE FROM page_access_rules WHERE id = :id");
                $stmt->execute([':id' => $pageRuleId]);
                
                // Clear RBAC cache
                if (method_exists($rbac, 'clearCache')) {
                    $rbac->clearCache();
                }
                
                echo json_encode(['success' => true, 'message' => 'Rule deleted successfully']);
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
    error_log("RBAC Page API Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
}
