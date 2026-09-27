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

    // Basic list of users (exclude soft-deleted)
    // Include kontinjen short name (nama_pendek) via join when available
    $sql = "SELECT u.id, u.username, u.email, u.full_name, u.role, u.kontinjen_id, u.status, u.phone, u.last_login, u.created_at,
                   COALESCE(r.nama_pendek, r.nama_universiti, k.kod_universiti) AS kontinjen_short
            FROM users u
            LEFT JOIN table_kontinjen k ON u.kontinjen_id = k.id
            LEFT JOIN table_ref_universiti r ON k.kod_universiti = r.kod_universiti AND r.deleted_at IS NULL
            WHERE u.deleted_at IS NULL
            ORDER BY u.created_at DESC";
    $stmt = $pdo->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    echo json_encode(['success' => true, 'data' => $rows]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

?>
