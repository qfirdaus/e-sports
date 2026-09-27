<?php
// AJAX endpoint: list active sports
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/sport_list][exception] ' . $e->getMessage());
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
    $db = getDB();
    
    // Check if user is a judge and filter sports to only those with assigned categories
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
        // Only return sports that have at least one assigned category for this judge
        $stmt = $db->prepare("
            SELECT DISTINCT s.id, s.nama_sukan, s.kod_sukan 
            FROM table_sukan s
            INNER JOIN table_kategori k ON s.id = k.sukan_id
            INNER JOIN judge_category_assignments jca ON k.id = jca.kategori_id
            WHERE s.deleted_at IS NULL 
            AND s.status = 1
            AND k.deleted_at IS NULL
            AND k.status = 1
            AND jca.user_id = :user_id
            AND jca.is_active = TRUE
            ORDER BY s.nama_sukan ASC
        ");
        $stmt->execute([':user_id' => $userId]);
    } else {
        // Show all sports for non-judges
        $stmt = $db->prepare("
            SELECT id, nama_sukan, kod_sukan 
            FROM table_sukan 
            WHERE deleted_at IS NULL 
            AND status = 1 
            ORDER BY nama_sukan ASC
        ");
        $stmt->execute();
    }
    
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
} catch (Exception $e) {
    error_log('[ajax/sport_list] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load sports.']);
    exit;
}
