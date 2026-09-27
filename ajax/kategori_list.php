<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

Session::start();
$auth = getAuth();
$auth->requireAuth();

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDB();
    $sql = "SELECT id, sukan_id, nama_kategori, kod_kategori, keterangan, penilaian, status FROM table_kategori WHERE deleted_at IS NULL ORDER BY sukan_id ASC, nama_kategori ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $rows]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'data' => []]);
}

?>