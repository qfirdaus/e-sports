<?php
// AJAX endpoint: get pasukan (team) list
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/pasukan_list][exception] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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
require_once __DIR__ . '/../api/models/PasukanModel.php';

if (session_status() === PHP_SESSION_NONE) {
    Session::start();
}

$auth = getAuth();
if (!$auth->isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your session has expired. Please sign in again.']);
    exit;
}

try {
    $pasukanModel = new PasukanModel();
    
    // Check if requesting a single team by ID
    if (isset($_GET['id']) && !empty($_GET['id'])) {
        $id = (int)$_GET['id'];
        $result = $pasukanModel->getById($id);
        
        if ($result['success']) {
            echo json_encode([
                'success' => true,
                'data' => $result['data']
            ]);
        } else {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => $result['message'] ?? 'Teams not found',
                'data' => null
            ]);
        }
        exit;
    }
    
    // Get filter parameters
    $params = [
        'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : 1000,
        'offset' => isset($_GET['offset']) ? (int)$_GET['offset'] : 0,
        'search' => isset($_GET['search']) ? trim($_GET['search']) : '',
        'kontinjen_id' => isset($_GET['kontinjen_id']) && $_GET['kontinjen_id'] !== '' ? (int)$_GET['kontinjen_id'] : null,
        'sukan_id' => isset($_GET['sukan_id']) && $_GET['sukan_id'] !== '' ? (int)$_GET['sukan_id'] : null,
        'status' => isset($_GET['status']) && $_GET['status'] !== '' ? (int)$_GET['status'] : null
    ];
    // If user is CONTINGENT, override any requested kontinjen_id with session value
    $sessionRole = Session::get('user_role') ?? null;
    $sessionKontinjen = Session::get('kontinjen_id') ?? null;
    if ($sessionRole === 'CONTINGENT') {
        $params['kontinjen_id'] = $sessionKontinjen ? (int)$sessionKontinjen : null;
    }
    
    // Get teams
    $result = $pasukanModel->getAll($params);
    
    // Get statistics
    $statsResult = $pasukanModel->getStatistics();
    
    if ($result['success'] && $statsResult['success']) {
        echo json_encode([
            'success' => true,
            'data' => $result['data'],
            'stats' => $statsResult['data']
        ]);
    } else {
        http_response_code(500);
        $errorMessage = $result['message'] ?? 'Unable to load team data';
        echo json_encode([
            'success' => false,
            'message' => $errorMessage,
            'data' => [],
            'stats' => $statsResult['data'] ?? ['total' => 0, 'active' => 0, 'inactive' => 0]
        ]);
    }
} catch (Exception $e) {
    error_log('[ajax/pasukan_list] ' . $e->getMessage() . " in " . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    $msg = (defined('DEBUG_MODE') && DEBUG_MODE) ? $e->getMessage() : 'Unable to load the team list.';
    echo json_encode([
        'success' => false,
        'message' => $msg,
        'data' => [],
        'stats' => ['total' => 0, 'active' => 0, 'inactive' => 0]
    ]);
    exit;
}

