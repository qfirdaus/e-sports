<?php
/**
 * Sports API
 * Handles CRUD operations for sports and categories
 */

// Suppress error display, log errors instead
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/rbac.php';
require_once __DIR__ . '/models/SportModel.php';

// Set JSON header early and prevent any output
header('Content-Type: application/json; charset=utf-8');
ob_start();

try {
    Session::start();
    
    $auth = getAuth();
    $rbac = getRBAC();
    
    // Check authentication
    if (!$auth->isLoggedIn()) {
        ob_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Please sign in']);
        ob_end_flush();
        exit;
    }
    
    // Check page access (ADMIN and ORGANIZER can manage sports)
    if (!$rbac->hasPageAccess('pages/sports.php')) {
        ob_clean();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        ob_end_flush();
        exit;
    }
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    
    // Get current user ID for audit fields
    $currentUserId = Session::get('user_id');
    
    $sportModel = new SportModel();
    
    switch ($method) {
        case 'GET':
            if ($action === 'list') {
                // Get all sports with pagination
                $params = [
                    'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 50,
                    'offset' => isset($_GET['offset']) ? (int)$_GET['offset'] : 0,
                    'search' => $_GET['search'] ?? '',
                    'status' => isset($_GET['status']) ? (int)$_GET['status'] : null
                ];
                
                $result = $sportModel->getAll($params);
                echo json_encode($result);
                
            } elseif ($action === 'get' && isset($_GET['id'])) {
                // Get single sport with categories
                $result = $sportModel->getById((int)$_GET['id']);
                if ($result['success']) {
                    echo json_encode($result);
                } else {
                    http_response_code(404);
                    echo json_encode($result);
                }
                
            } elseif ($action === 'statistics') {
                // Get statistics
                $result = $sportModel->getStatistics();
                echo json_encode($result);
                
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
            
        case 'POST':
            if ($action === 'create') {
                // Get JSON input
                $input = file_get_contents('php://input');
                $data = json_decode($input, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
                    exit;
                }
                
                // Add current user ID for audit
                $data['created_by'] = $currentUserId;
                
                $result = $sportModel->create($data);
                
                if ($result['success']) {
                    http_response_code(201);
                } else {
                    http_response_code(400);
                }
                
                echo json_encode($result);
                
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
            
        case 'PUT':
            if ($action === 'update' && isset($_GET['id'])) {
                // Get JSON input
                $input = file_get_contents('php://input');
                $data = json_decode($input, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
                    exit;
                }
                
                // Add current user ID for audit
                $data['updated_by'] = $currentUserId;
                
                $result = $sportModel->update((int)$_GET['id'], $data);
                
                if (!$result['success']) {
                    http_response_code(400);
                }
                
                echo json_encode($result);
                
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
            
        case 'DELETE':
            if ($action === 'delete' && isset($_GET['id'])) {
                $result = $sportModel->delete((int)$_GET['id'], $currentUserId);
                
                if (!$result['success']) {
                    http_response_code(400);
                }
                
                echo json_encode($result);
                
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed']);
            break;
    }
    
} catch (PDOException $e) {
    ob_clean(); // Clear any output
    error_log("Sports API PDO Error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    http_response_code(500);
    
    // Check if it's a table doesn't exist error
    $errorMsg = $e->getMessage();
    if (strpos($errorMsg, "doesn't exist") !== false || strpos($errorMsg, "Unknown table") !== false) {
        echo json_encode([
            'success' => false, 
            'message' => 'The database tables do not exist. Ensure table_sukan and table_kategori have been created.'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'System error: ' . $errorMsg]);
    }
    ob_end_flush();
    exit;
} catch (Exception $e) {
    ob_clean(); // Clear any output
    error_log("Sports API Error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'System error: ' . $e->getMessage()]);
    ob_end_flush();
    exit;
} catch (Error $e) {
    // Catch fatal errors (PHP 7+)
    ob_clean();
    error_log("Sports API Fatal Error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error fatal: ' . $e->getMessage()]);
    ob_end_flush();
    exit;
}

// Clean any unexpected output
ob_end_flush();

