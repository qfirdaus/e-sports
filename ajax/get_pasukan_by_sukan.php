<?php
// AJAX endpoint: get pasukan by sukan_id
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/get_pasukan_by_sukan][exception] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $sukan_id = isset($_GET['sukan_id']) ? (int)$_GET['sukan_id'] : 0;
    if (!$sukan_id) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT id, nama_pasukan FROM table_pasukan WHERE sukan_id = :sukan_id AND deleted_at IS NULL AND status = 1 ORDER BY nama_pasukan ASC");
    $stmt->execute([':sukan_id' => $sukan_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
} catch (Exception $e) {
    error_log('[ajax/get_pasukan_by_sukan] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load teams.']);
    exit;
}
