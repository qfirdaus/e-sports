<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

Session::start();
$auth = getAuth();
$auth->requireAuth();

// Only ADMIN can delete users
if (Session::get('user_role') !== 'ADMIN') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if ($id <= 0) throw new Exception('Invalid id');

    $pdo = getDB();
    $stmt = $pdo->prepare('UPDATE users SET deleted_at = NOW() WHERE id = :id');
    $stmt->execute([':id' => $id]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

?>
