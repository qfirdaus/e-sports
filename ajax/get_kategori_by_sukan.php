<?php
// AJAX endpoint: get kategori by sukan_id
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/get_kategori_by_sukan][exception] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    Session::start();
}

try {
    $sukan_id = isset($_GET['sukan_id']) ? (int)$_GET['sukan_id'] : 0;
    if (!$sukan_id) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

    $db = getDB();
    
    // Check if user is a judge and filter by assigned categories
    $userRole = null;
    $userId = null;
    $isJudge = false;
    
    $auth = getAuth();
    if ($auth && $auth->isLoggedIn()) {
        $userRole = Session::get('user_role');
        $userId = Session::get('user_id');
        $isJudge = ($userRole === 'JUDGE');
    }
    
    if ($isJudge && $userId) {
        // Filter categories to only those assigned to the judge
        $stmt = $db->prepare("
            SELECT k.id, k.nama_kategori 
            FROM table_kategori k
            INNER JOIN judge_category_assignments jca ON k.id = jca.kategori_id
            WHERE k.sukan_id = :sukan_id 
            AND k.deleted_at IS NULL 
            AND k.status = 1
            AND jca.user_id = :user_id
            AND jca.is_active = TRUE
            ORDER BY k.nama_kategori ASC
        ");
        $stmt->execute([
            ':sukan_id' => $sukan_id,
            ':user_id' => $userId
        ]);
    } else {
        // Show all categories for non-judges
        $stmt = $db->prepare("
            SELECT id, nama_kategori 
            FROM table_kategori 
            WHERE sukan_id = :sukan_id 
            AND deleted_at IS NULL 
            AND status = 1 
            ORDER BY nama_kategori ASC
        ");
        $stmt->execute([':sukan_id' => $sukan_id]);
    }
    
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
} catch (Exception $e) {
    error_log('[ajax/get_kategori_by_sukan] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load categories.']);
    exit;
}
