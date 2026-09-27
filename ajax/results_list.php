<?php
// AJAX endpoint: list results with basic filters
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/results_list][exception] ' . $e->getMessage());
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
    $where = [];
    $params = [];
    
    // Check if user is a judge and filter by assigned categories
    $userRole = null;
    $userId = null;
    $isJudge = false;
    
    if (class_exists('Session')) {
        $auth = getAuth();
        if ($auth && $auth->isLoggedIn()) {
            $userRole = Session::get('user_role');
            $userId = Session::get('user_id');
            $isJudge = ($userRole === 'JUDGE');
        }
    }
    
    if(!empty($_GET['sukan_id'])){ $where[] = 'r.sukan_id = :sukan_id'; $params[':sukan_id'] = (int)$_GET['sukan_id']; }
    if(!empty($_GET['acara_id'])){ $where[] = 'r.acara_id = :acara_id'; $params[':acara_id'] = (int)$_GET['acara_id']; }
    if(!empty($_GET['tarikh'])){ $where[] = 'DATE(r.tarikh) = :tarikh'; $params[':tarikh'] = $_GET['tarikh']; }
    if(!empty($_GET['status'])){ $where[] = 'r.status = :status'; $params[':status'] = $_GET['status']; }
    $where[] = 'r.deleted_at IS NULL';
    
    // Filter by judge assignments if user is a judge
    if ($isJudge && $userId) {
        // For judges: only show results for categories assigned to them
        // This automatically filters to only show sports with assigned categories
        // because if a category is assigned, its sport is included
        $where[] = 'EXISTS (
            SELECT 1 FROM judge_category_assignments jca
            INNER JOIN table_kategori k ON jca.kategori_id = k.id
            WHERE jca.user_id = :judge_user_id
            AND jca.kategori_id = r.kategori_id
            AND jca.is_active = TRUE
            AND k.deleted_at IS NULL
            AND k.status = 1
        )';
        $params[':judge_user_id'] = $userId;
        
        // Additionally, ensure the sport itself has at least one assigned category
        // This handles cases where kategori_id might be NULL or when filtering by sport
        $where[] = 'EXISTS (
            SELECT 1 FROM judge_category_assignments jca2
            INNER JOIN table_kategori k2 ON jca2.kategori_id = k2.id
            WHERE jca2.user_id = :judge_user_id2
            AND k2.sukan_id = r.sukan_id
            AND jca2.is_active = TRUE
            AND k2.deleted_at IS NULL
            AND k2.status = 1
        )';
        $params[':judge_user_id2'] = $userId;
    }

    $sql = "SELECT r.id, s.nama_sukan AS sukan, COALESCE(e.nama_acara, '') AS acara, DATE_FORMAT(r.tarikh, '%Y-%m-%d') AS tarikh,
            r.tempat_pertama, r.tempat_kedua, r.tempat_ketiga, r.status
            FROM table_results r
            LEFT JOIN table_sukan s ON r.sukan_id = s.id
            LEFT JOIN table_event e ON r.acara_id = e.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY r.tarikh DESC
            LIMIT 500";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
} catch (Exception $e) {
    error_log('[ajax/results_list] ' . $e->getMessage());
    // return empty success so frontend still works
    echo json_encode(['success' => true, 'data' => []]);
    exit;
}
