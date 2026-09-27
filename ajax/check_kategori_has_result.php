<?php
// AJAX endpoint: check which categories have results for a given sport (regardless of date)
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/check_kategori_has_result][exception] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

try {
    $sukan_id = isset($_GET['sukan_id']) ? (int)$_GET['sukan_id'] : 0;
    $exclude_id = isset($_GET['exclude_id']) ? (int)$_GET['exclude_id'] : null;
    
    if (!$sukan_id) {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }
    
    $db = getDB();
    
    // Check if kategori_id column exists in table_results
    $checkColStmt = $db->query("SHOW COLUMNS FROM table_results LIKE 'kategori_id'");
    $hasKategoriId = $checkColStmt && $checkColStmt->rowCount() > 0;
    
    $kategoriIds = [];
    
    if ($hasKategoriId) {
        // Query by kategori_id only (no date restriction)
        $sql = "SELECT DISTINCT kategori_id 
                FROM table_results 
                WHERE sukan_id = :sukan_id 
                AND kategori_id IS NOT NULL
                AND deleted_at IS NULL";
        
        if ($exclude_id) {
            $sql .= " AND id != :exclude_id";
        }
        
        $stmt = $db->prepare($sql);
        $params = [':sukan_id' => $sukan_id];
        if ($exclude_id) {
            $params[':exclude_id'] = $exclude_id;
        }
        $stmt->execute($params);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['kategori_id']) {
                $kategoriIds[] = (int)$row['kategori_id'];
            }
        }
    } else {
        // Fallback: if kategori_id doesn't exist, we can't determine which specific categories have results
        // So we return empty array (no categories disabled)
        $kategoriIds = [];
    }
    
    echo json_encode(['success' => true, 'data' => $kategoriIds]);
    exit;
} catch (Exception $e) {
    error_log('[ajax/check_kategori_has_result] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to check categories.']);
    exit;
}
?>

