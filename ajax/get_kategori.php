<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';

$sukan_id = isset($_GET['sukan_id']) ? (int)$_GET['sukan_id'] : 0;
if ($sukan_id <= 0) {
    echo json_encode([]);
    exit;
}
try {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT id, nama_kategori, kod_kategori
        FROM table_kategori
        WHERE status = 1
          AND sukan_id = :sukan_id
          AND deleted_at IS NULL
        ORDER BY nama_kategori ASC
    ");
    $stmt->execute([':sukan_id' => $sukan_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log('[get_kategori] ' . $e->getMessage());
    echo json_encode(['success' => false, 'data' => []], JSON_UNESCAPED_UNICODE);
}
