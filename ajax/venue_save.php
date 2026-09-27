<?php
// AJAX endpoint: save (insert/update) venue
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/venue_save][exception] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error.']);
    exit;
});

register_shutdown_function(function(){
    $err = error_get_last();
    if ($err) {
        http_response_code(500);
        error_log('[ajax/venue_save][shutdown] ' . $err['message']);
        echo json_encode(['success' => false, 'message' => 'Server error.']);
        exit;
    }
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    Session::start();
}

$auth = getAuth();
if (!$auth->isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your session has expired. Please sign in again.']);
    exit;
}

$userRole = Session::get('user_role');
if (!in_array($userRole, ['ADMIN', 'ORGANIZER', 'EVENT'])) {
    // allow EVENT or higher; adjust roles as needed
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$input = filter_input_array(INPUT_POST, [
    'id' => FILTER_SANITIZE_NUMBER_INT,
    'nama_venue' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'lokasi' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'kapasiti' => FILTER_SANITIZE_NUMBER_INT,
    'sukan_id' => FILTER_SANITIZE_NUMBER_INT,
    'status' => FILTER_SANITIZE_NUMBER_INT,
]);

$id = isset($input['id']) ? (int)$input['id'] : 0;
$nama = trim($input['nama_venue'] ?? '');
$lokasi = trim($input['lokasi'] ?? '');
$kapasiti = isset($input['kapasiti']) ? (int)$input['kapasiti'] : null;
$sukan_id = isset($input['sukan_id']) && $input['sukan_id'] !== '' ? (int)$input['sukan_id'] : null;
$status = isset($input['status']) ? ((int)$input['status'] === 1 ? 1 : 0) : 0;

// Basic validation
if (empty($nama) || mb_strlen($nama) < 3) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Venue name is required (at least 3 characters).']);
    exit;
}

if (mb_strlen($nama) > 255) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The name cannot exceed 255 characters.']);
    exit;
}

if ($lokasi !== '' && mb_strlen($lokasi) > 255) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The location cannot exceed 255 characters.']);
    exit;
}

// sukan_id is optional; no length validation needed

try {
    $db = getDB();
    $userId = Session::get('user_id');

    if ($id > 0) {
        $stmt = $db->prepare("UPDATE table_ref_venues SET nama_venue = :nama, lokasi = :lokasi, kapasiti = :kapasiti, sukan = :sukan, status = :status, updated_at = NOW(), updated_by = :updated_by WHERE id = :id");
        $stmt->execute([
            ':nama' => $nama,
            ':lokasi' => $lokasi !== '' ? $lokasi : null,
            ':kapasiti' => $kapasiti !== null ? $kapasiti : null,
            ':sukan' => $sukan_id !== null ? $sukan_id : null,
            ':status' => $status,
            ':updated_by' => $userId,
            ':id' => $id
        ]);
        echo json_encode(['success' => true, 'message' => 'Venue changes saved.']);
        exit;
    } else {
        $stmt = $db->prepare("INSERT INTO table_ref_venues (nama_venue, lokasi, kapasiti, sukan, status, created_at, created_by) VALUES (:nama, :lokasi, :kapasiti, :sukan, :status, NOW(), :created_by)");
        $stmt->execute([
            ':nama' => $nama,
            ':lokasi' => $lokasi !== '' ? $lokasi : null,
            ':kapasiti' => $kapasiti !== null ? $kapasiti : null,
            ':sukan' => $sukan_id !== null ? $sukan_id : null,
            ':status' => $status,
            ':created_by' => $userId
        ]);
        echo json_encode(['success' => true, 'message' => 'New venue added.']);
        exit;
    }
} catch (Exception $e) {
    error_log('[ajax/venue_save] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save the venue record.']);
    exit;
}

