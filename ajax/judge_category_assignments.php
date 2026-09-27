<?php
// AJAX endpoint: Manage judge category assignments
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/judge_category_assignments][exception] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Server error.']);
    }
    exit;
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    Session::start();
}

$auth = getAuth();
if (!$auth->isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your session has expired. Please sign in again.']);
    exit;
}

$userRole = Session::get('user_role');
$userId = Session::get('user_id');
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $db = getDB();
    
    switch ($action) {
        case 'list':
            // Get all assigned categories for a judge
            $targetUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
            
            if (!$targetUserId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'User ID is required']);
                exit;
            }
            
            // Check if user has permission (ADMIN, ORGANIZER, or the judge themselves)
            if (!in_array($userRole, ['ADMIN', 'ORGANIZER']) && $targetUserId != $userId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied']);
                exit;
            }
            
            $stmt = $db->prepare("
                SELECT jca.id, jca.kategori_id, jca.assigned_at,
                       k.nama_kategori, k.sukan_id,
                       s.nama_sukan
                FROM judge_category_assignments jca
                INNER JOIN table_kategori k ON jca.kategori_id = k.id
                INNER JOIN table_sukan s ON k.sukan_id = s.id
                WHERE jca.user_id = :user_id
                AND jca.is_active = TRUE
                AND k.deleted_at IS NULL
                AND s.deleted_at IS NULL
                ORDER BY s.nama_sukan ASC, k.nama_kategori ASC
            ");
            $stmt->execute([':user_id' => $targetUserId]);
            $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $assignments]);
            break;
            
        case 'available':
            // Get all available categories (grouped by sport) for assignment UI
            // Only ADMIN and ORGANIZER can access this
            if (!in_array($userRole, ['ADMIN', 'ORGANIZER'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied. Only ADMIN and ORGANIZER roles are allowed.']);
                exit;
            }
            
            $stmt = $db->query("
                SELECT k.id AS kategori_id, k.nama_kategori, k.sukan_id,
                       s.id AS sukan_id, s.nama_sukan, s.kod_sukan
                FROM table_kategori k
                INNER JOIN table_sukan s ON k.sukan_id = s.id
                WHERE k.deleted_at IS NULL
                AND k.status = 1
                AND s.deleted_at IS NULL
                AND s.status = 1
                ORDER BY s.nama_sukan ASC, k.nama_kategori ASC
            ");
            $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Group by sport
            $grouped = [];
            foreach ($categories as $cat) {
                $sukanId = $cat['sukan_id'];
                if (!isset($grouped[$sukanId])) {
                    $grouped[$sukanId] = [
                        'sukan_id' => $sukanId,
                        'nama_sukan' => $cat['nama_sukan'],
                        'kod_sukan' => $cat['kod_sukan'],
                        'categories' => []
                    ];
                }
                $grouped[$sukanId]['categories'][] = [
                    'id' => $cat['kategori_id'],
                    'nama_kategori' => $cat['nama_kategori']
                ];
            }
            
            echo json_encode(['success' => true, 'data' => array_values($grouped)]);
            break;
            
        case 'assign':
            // Assign categories to a judge (requires ADMIN or ORGANIZER)
            if (!in_array($userRole, ['ADMIN', 'ORGANIZER'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied. Only ADMIN and ORGANIZER roles are allowed.']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                $input = $_POST;
            }
            
            $targetUserId = isset($input['user_id']) ? (int)$input['user_id'] : 0;
            $kategoriIds = isset($input['kategori_ids']) && is_array($input['kategori_ids']) 
                ? array_map('intval', $input['kategori_ids']) 
                : [];
            
            if (!$targetUserId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'User ID is required']);
                exit;
            }
            
            // Verify target user is a judge
            $userStmt = $db->prepare("SELECT id, role FROM users WHERE id = :id AND deleted_at IS NULL");
            $userStmt->execute([':id' => $targetUserId]);
            $targetUser = $userStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$targetUser) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Users not found']);
                exit;
            }
            
            if ($targetUser['role'] !== 'JUDGE') {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Categories can only be assigned to users with the JUDGE role']);
                exit;
            }
            
            // Validate all kategori_ids exist
            if (!empty($kategoriIds)) {
                $placeholders = implode(',', array_fill(0, count($kategoriIds), '?'));
                $checkStmt = $db->prepare("
                    SELECT id FROM table_kategori 
                    WHERE id IN ($placeholders) 
                    AND deleted_at IS NULL 
                    AND status = 1
                ");
                $checkStmt->execute($kategoriIds);
                $validKategoriIds = $checkStmt->fetchAll(PDO::FETCH_COLUMN);
                
                if (count($validKategoriIds) !== count($kategoriIds)) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Some categories are invalid or inactive']);
                    exit;
                }
                
                $kategoriIds = $validKategoriIds;
            }
            
            // Start transaction
            $db->beginTransaction();
            
            try {
                // Deactivate all existing assignments for this judge
                $deactivateStmt = $db->prepare("
                    UPDATE judge_category_assignments 
                    SET is_active = FALSE 
                    WHERE user_id = :user_id
                ");
                $deactivateStmt->execute([':user_id' => $targetUserId]);
                
                // Insert new assignments
                if (!empty($kategoriIds)) {
                    $insertStmt = $db->prepare("
                        INSERT INTO judge_category_assignments (user_id, kategori_id, assigned_by, is_active)
                        VALUES (:user_id, :kategori_id, :assigned_by, TRUE)
                        ON DUPLICATE KEY UPDATE 
                            is_active = TRUE,
                            assigned_by = :assigned_by_update,
                            assigned_at = CURRENT_TIMESTAMP
                    ");
                    
                    foreach ($kategoriIds as $kategoriId) {
                        $insertStmt->execute([
                            ':user_id' => $targetUserId,
                            ':kategori_id' => $kategoriId,
                            ':assigned_by' => $userId,
                            ':assigned_by_update' => $userId
                        ]);
                    }
                }
                
                $db->commit();
                echo json_encode([
                    'success' => true, 
                    'message' => 'Category assigned successfully',
                    'assigned_count' => count($kategoriIds)
                ]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }
            break;
            
        case 'remove':
            // Remove a specific category assignment
            if (!in_array($userRole, ['ADMIN', 'ORGANIZER'])) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Access denied. Only ADMIN and ORGANIZER roles are allowed.']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                $input = $_POST;
            }
            
            $assignmentId = isset($input['assignment_id']) ? (int)$input['assignment_id'] : 0;
            
            if (!$assignmentId) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Assignment ID is required']);
                exit;
            }
            
            $stmt = $db->prepare("
                UPDATE judge_category_assignments 
                SET is_active = FALSE 
                WHERE id = :id
            ");
            $stmt->execute([':id' => $assignmentId]);
            
            if ($stmt->rowCount() === 0) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Assignment not found']);
                exit;
            }
            
            echo json_encode(['success' => true, 'message' => 'Assignment removed successfully']);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    error_log('[ajax/judge_category_assignments] ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

