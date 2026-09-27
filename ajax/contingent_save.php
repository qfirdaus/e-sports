<?php
// AJAX endpoint: save (insert/update) contingent
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/contingent_save][exception] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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
        error_log('[ajax/contingent_save][shutdown] ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        $msg = (defined('DEBUG_MODE') && DEBUG_MODE) ? $err['message'] . ' in ' . $err['file'] . ':' . $err['line'] : 'Server error.';
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../api/models/ContingentModel.php';

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
if (!in_array($userRole, ['ADMIN', 'ORGANIZER', 'CONTINGENT'])) {
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
    'nama_pegawai_untuk_dihubungi' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'alamat' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'emel' => FILTER_SANITIZE_EMAIL,
    'phone' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
    'status' => FILTER_SANITIZE_FULL_SPECIAL_CHARS,
]);

$id = isset($input['id']) ? (int)$input['id'] : 0;
$kod_universiti = trim($input['kod_universiti'] ?? '');
$nama_pegawai = trim($input['nama_pegawai_untuk_dihubungi'] ?? '');
$alamat = trim($input['alamat'] ?? '');
$emel = trim($input['emel'] ?? '');
$phone = trim($input['phone'] ?? '');
$statusInput = isset($input['status']) ? trim($input['status']) : '0';

// Validation
if (empty($kod_universiti)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please select an institution.']);
    exit;
}

if (empty($nama_pegawai) || mb_strlen($nama_pegawai) < 3) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Contact person name is required (at least 3 characters).']);
    exit;
}

if (empty($alamat) || mb_strlen($alamat) < 10) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Address is required (at least 10 characters).']);
    exit;
}

if (empty($emel) || !filter_var($emel, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
    exit;
}

// Phone is optional, but if provided, validate format
// Mobile: 01X-XXXXXXX (e.g., 010-1234567, 012-3456789)
// Landline: 0X-XXXXXXX (e.g., 03-12345678, 04-1234567)
if (!empty($phone)) {
    $cleanedPhone = preg_replace('/\s/', '', $phone);
    // Mobile pattern: 01[0-9] followed by optional dash and 7-8 digits
    $mobilePattern = '/^01[0-9]-?[0-9]{7,8}$/';
    // Landline pattern: 0[1-9] followed by optional dash and 7-9 digits
    $landlinePattern = '/^0[1-9]-?[0-9]{7,9}$/';
    
    if (!preg_match($mobilePattern, $cleanedPhone) && !preg_match($landlinePattern, $cleanedPhone)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid phone number. Example: 012-3456789 (mobile) or 03-12345678 (landline)']);
        exit;
    }
}

// Validate and set status
// Only ADMIN and ORGANIZER can change status
$canChangeStatus = in_array($userRole, ['ADMIN', 'ORGANIZER']);
$status = 0; // Default to inactive

if ($canChangeStatus) {
    // ADMIN/ORGANIZER can set status to 1 or 0
    $status = ($statusInput === '1' || $statusInput === 1) ? 1 : 0;
} else {
    // CONTINGENT users cannot change status, always set to 0 (inactive)
    $status = 0;
}

// Length validation
if (mb_strlen($nama_pegawai) > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The official name cannot exceed 100 characters.']);
    exit;
}

if (mb_strlen($alamat) > 500) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The address cannot exceed 500 characters.']);
    exit;
}

if (mb_strlen($emel) > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The email address cannot exceed 100 characters.']);
    exit;
}

if (mb_strlen($phone) > 20) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The phone number cannot exceed 20 characters.']);
    exit;
}

try {
    $model = new ContingentModel();
    $userId = Session::get('user_id');
    
    $data = [
        'kod_universiti' => $kod_universiti,
        'nama_pegawai_untuk_dihubungi' => $nama_pegawai,
        'alamat' => $alamat,
        'emel' => $emel,
        'no_telefon' => $phone,
        'status' => $status,
        'created_by' => $userId,
        'updated_by' => $userId
    ];
    
    if ($id > 0) {
        // Update existing
        $result = $model->update($id, $data);
        if ($result['success']) {
            echo json_encode(['success' => true, 'message' => 'Contingent updated successfully.']);
        } else {
            http_response_code(400);
            echo json_encode($result);
        }
    } else {
        // Check if university already has active contingent
        $checkResult = $model->checkUniversityExists($kod_universiti);
        if ($checkResult['success'] && $checkResult['exists']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'This institution already has an active contingent.']);
            exit;
        }
        
        // Create new
        $result = $model->create($data);
        if ($result['success']) {
            echo json_encode(['success' => true, 'message' => 'Contingent registered successfully.']);
        } else {
            http_response_code(400);
            echo json_encode($result);
        }
    }
} catch (Exception $e) {
    error_log('[ajax/contingent_save] ' . $e->getMessage() . " in " . $e->getFile() . ':' . $e->getLine());
    error_log($e->getTraceAsString());
    http_response_code(500);
    $msg = (defined('DEBUG_MODE') && DEBUG_MODE) ? $e->getMessage() : 'Unable to save the contingent record.';
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

