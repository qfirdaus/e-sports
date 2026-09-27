<?php
// AJAX endpoint: save (insert/update) university
// Install error/exception handlers to ensure JSON output on fatal errors
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/university_save][exception] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Server error.']);
    }
    exit;
});

register_shutdown_function(function(){
    $err = error_get_last();
    if ($err) {
        http_response_code(500);
        error_log('[ajax/university_save][shutdown] ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        $msg = (defined('DEBUG_MODE') && DEBUG_MODE) ? $err['message'] . ' in ' . $err['file'] . ':' . $err['line'] : 'Server error.';
        echo json_encode(['success' => false, 'message' => $msg]);
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
if (!in_array($userRole, ['ADMIN', 'ORGANIZER'])) {
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
    'kod_universiti' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'nama_universiti' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'jenis_universiti' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'negeri' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'negara' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'emel_rasmi' => FILTER_SANITIZE_EMAIL,
    'no_tel' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'status' => FILTER_SANITIZE_NUMBER_INT,
]);

$id = isset($input['id']) ? (int)$input['id'] : 0;
$kod = trim($input['kod_universiti'] ?? '');
$nama = trim($input['nama_universiti'] ?? '');
$jenis = trim($input['jenis_universiti'] ?? '');
$negeri = trim($input['negeri'] ?? '');
$negara = trim($input['negara'] ?? '');
$emel = trim($input['emel_rasmi'] ?? '');
$tel = trim($input['no_tel'] ?? '');
$status = isset($input['status']) ? ((int)$input['status'] === 1 ? 1 : 0) : 0;

// Basic length validation to avoid SQL truncation warnings
// Adjust MAX_* values if your schema allows longer fields
define('MAX_KOD_LEN', 32);
define('MAX_NAMA_LEN', 255);
define('MAX_JENIS_LEN', 100);
define('MAX_NEGERI_LEN', 100);
define('MAX_NEGARA_LEN', 100);
define('MAX_EMEL_LEN', 150);
define('MAX_TEL_LEN', 50);

if (mb_strlen($kod) > MAX_KOD_LEN) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The university code exceeds the maximum length ' . MAX_KOD_LEN]);
    exit;
}
if (mb_strlen($nama) > MAX_NAMA_LEN) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The university name exceeds the maximum length ' . MAX_NAMA_LEN]);
    exit;
}
if (mb_strlen($jenis) > MAX_JENIS_LEN) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The university type exceeds the maximum length ' . MAX_JENIS_LEN]);
    exit;
}
if (mb_strlen($negeri) > MAX_NEGERI_LEN) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The state exceeds the maximum length ' . MAX_NEGERI_LEN]);
    exit;
}
if (mb_strlen($negara) > MAX_NEGARA_LEN) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The country exceeds the maximum length ' . MAX_NEGARA_LEN]);
    exit;
}
if (mb_strlen($emel) > MAX_EMEL_LEN) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The email address exceeds the maximum length ' . MAX_EMEL_LEN]);
    exit;
}
if (mb_strlen($tel) > MAX_TEL_LEN) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The phone number exceeds the maximum length ' . MAX_TEL_LEN]);
    exit;
}

// Validate jenis_universiti against allowed enum values in DB
$allowedJenis = ['Awam', 'Swasta', 'Luar Negara'];
if ($jenis === '') {
    $jenis = 'Awam';
}
if (!in_array($jenis, $allowedJenis, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid university type. Select Public, Private or Overseas.']);
    exit;
}

try {
    $db = getDB();
    if ($id > 0) {
        $stmt = $db->prepare("UPDATE table_ref_universiti SET kod_universiti = :kod, nama_universiti = :nama, jenis_universiti = :jenis, negeri = :negeri, negara = :negara, emel_rasmi = :emel, no_tel = :tel, status = :status WHERE id = :id");
        $stmt->execute([
            ':kod' => $kod,
            ':nama' => $nama,
            ':jenis' => $jenis,
            ':negeri' => $negeri,
            ':negara' => $negara,
            ':emel' => $emel,
            ':tel' => $tel,
            ':status' => $status,
            ':id' => $id
        ]);
        echo json_encode(['success' => true, 'message' => 'University changes saved.']);
        exit;
    } else {
        $stmt = $db->prepare("INSERT INTO table_ref_universiti (kod_universiti, nama_universiti, jenis_universiti, negeri, negara, emel_rasmi, no_tel, status, created_at) VALUES (:kod, :nama, :jenis, :negeri, :negara, :emel, :tel, :status, NOW())");
        $stmt->execute([
            ':kod' => $kod,
            ':nama' => $nama,
            ':jenis' => $jenis,
            ':negeri' => $negeri,
            ':negara' => $negara,
            ':emel' => $emel,
            ':tel' => $tel,
            ':status' => $status,
        ]);
        echo json_encode(['success' => true, 'message' => 'New university added.']);
        exit;
    }
} catch (Exception $e) {
    // Log full exception
    error_log('[ajax/university_save] ' . $e->getMessage() . " in " . $e->getFile() . ':' . $e->getLine());
    error_log($e->getTraceAsString());
    http_response_code(500);
    $msg = (defined('DEBUG_MODE') && DEBUG_MODE) ? $e->getMessage() : 'Unable to save the university record.';
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

