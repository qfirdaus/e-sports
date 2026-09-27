<?php
// AJAX endpoint: delete university (currently disabled) — returns JSON
// Ensure JSON is returned even on fatal errors
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/university_delete][exception] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Server error.']);
    }
    exit;
});

register_shutdown_function(function(){
    $err = error_get_last();
    if ($err) {
        http_response_code(500);
        error_log('[ajax/university_delete][shutdown] ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        $msg = (defined('DEBUG_MODE') && DEBUG_MODE) ? $err['message'] . ' in ' . $err['file'] . ':' . $err['line'] : 'Server error.';
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
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
if (!in_array($userRole, ['ADMIN', 'ORGANIZER'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid ID.']);
    exit;
}

// Log attempt for auditing
error_log('[ajax/university_delete] user=' . Session::get('user_id') . ' attempted delete id=' . $id);

try {
    $db = getDB();
    // Try update including deleted_by; if column doesn't exist, fallback to update without deleted_by
    try {
        $stmt = $db->prepare("UPDATE table_ref_universiti SET deleted_at = NOW(), deleted_by = :deleted_by WHERE id = :id");
        $stmt->execute([':deleted_by' => Session::get('user_id'), ':id' => $id]);
    } catch (Exception $inner) {
        // Check SQLSTATE / message for unknown column and try simpler update
        error_log('[ajax/university_delete][fallback attempt] ' . $inner->getMessage());
        $stmt = $db->prepare("UPDATE table_ref_universiti SET deleted_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    echo json_encode(['success' => true, 'message' => 'University record deleted.']);
    exit;
} catch (Exception $e) {
    error_log('[ajax/university_delete] ' . $e->getMessage());
    http_response_code(500);
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Unable to delete the record.']);
    }
    exit;
}
