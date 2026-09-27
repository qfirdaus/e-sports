<?php
// AJAX endpoint: list venues
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/venue_list][exception] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $db = getDB();

    // Join with table_sukan to translate sukan id to nama_sukan when available
    $stmt = $db->prepare("SELECT v.id, v.nama_venue, v.lokasi, v.kapasiti, v.sukan AS sukan_id, COALESCE(s.nama_sukan, v.sukan) AS sukan_name, v.status FROM table_ref_venues v LEFT JOIN table_sukan s ON s.id = v.sukan AND s.deleted_at IS NULL WHERE v.deleted_at IS NULL ORDER BY v.created_at DESC");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $countStmt = $db->prepare("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) AS inactive
        FROM table_ref_venues WHERE deleted_at IS NULL");
    $countStmt->execute();
    $stats = $countStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows, 'stats' => [
        'total' => (int)($stats['total'] ?? 0),
        'active' => (int)($stats['active'] ?? 0),
        'inactive' => (int)($stats['inactive'] ?? 0)
    ]]);
    exit;
} catch (Exception $e) {
    error_log('[ajax/venue_list] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load data.']);
    exit;
}

