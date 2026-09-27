<?php
// AJAX endpoint: list events for a sport
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/event_list][exception] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = getDB();
    $sukan_id = isset($_GET['sukan_id']) ? (int)$_GET['sukan_id'] : 0;
    $sql = "SELECT id, nama_acara, sukan_id FROM table_event WHERE deleted_at IS NULL";
    $params = [];
    if($sukan_id){
        $sql .= " AND sukan_id = :sukan_id";
        $params[':sukan_id'] = $sukan_id;
    }
    $sql .= " AND status = 1 ORDER BY nama_acara ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
} catch (Exception $e) {
    // If table_event doesn't exist, return empty success
    error_log('[ajax/event_list] ' . $e->getMessage());
    echo json_encode(['success' => true, 'data' => []]);
    exit;
}

