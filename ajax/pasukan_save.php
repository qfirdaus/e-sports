<?php
// AJAX endpoint: save (insert/update) pasukan (team)
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/pasukan_save][exception] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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
        error_log('[ajax/pasukan_save][shutdown] ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        $msg = (defined('DEBUG_MODE') && DEBUG_MODE) ? $err['message'] . ' in ' . $err['file'] . ':' . $err['line'] : 'Server error.';
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../api/models/PasukanModel.php';

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

// Get raw POST data for JSON arrays
$rawInput = file_get_contents('php://input');
$jsonData = json_decode($rawInput, true);

// Fallback to regular POST if JSON decode fails
if ($jsonData === null) {
    $jsonData = $_POST;
}

$id = isset($jsonData['id']) ? (int)$jsonData['id'] : 0;
$kontinjen_id = isset($jsonData['kontinjen_id']) ? (int)$jsonData['kontinjen_id'] : 0;
$sukan_id = isset($jsonData['sukan_id']) ? (int)$jsonData['sukan_id'] : 0;
$nama_pasukan = isset($jsonData['nama_pasukan']) ? trim($jsonData['nama_pasukan']) : '';
$status = isset($jsonData['status']) ? (int)$jsonData['status'] : 1;

// Get pengurus, jurulatih, and atlet arrays
$pengurus = isset($jsonData['pengurus']) && is_array($jsonData['pengurus']) ? $jsonData['pengurus'] : [];
$jurulatih = isset($jsonData['jurulatih']) && is_array($jsonData['jurulatih']) ? $jsonData['jurulatih'] : [];
$atlet = isset($jsonData['atlet']) && is_array($jsonData['atlet']) ? $jsonData['atlet'] : [];

// Validation
// If user is CONTINGENT, force kontinjen_id from session and do not trust input
$sessionRole = Session::get('user_role');
$sessionKontinjen = Session::get('kontinjen_id');
if ($sessionRole === 'CONTINGENT') {
    $kontinjen_id = $sessionKontinjen ? (int)$sessionKontinjen : 0;
}

if (empty($kontinjen_id) || $kontinjen_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please select a contingent.']);
    exit;
}

if (empty($sukan_id) || $sukan_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please select a sport.']);
    exit;
}

if (empty($nama_pasukan) || mb_strlen($nama_pasukan) < 3) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Team name is required (at least 3 characters).']);
    exit;
}

if (mb_strlen($nama_pasukan) > 200) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The team name cannot exceed 200 characters.']);
    exit;
}

// Validate status - only ADMIN and ORGANIZER can change status
$canChangeStatus = in_array($userRole, ['ADMIN', 'ORGANIZER']);
if (!$canChangeStatus) {
    $status = 0; // CONTINGENT users cannot set status to active
}

try {
    $model = new PasukanModel();
    $userId = Session::get('user_id');
    
    $data = [
        'kontinjen_id' => $kontinjen_id,
        'sukan_id' => $sukan_id,
        'nama_pasukan' => $nama_pasukan,
        'status' => $status,
        'created_by' => $userId,
        'updated_by' => $userId,
        'pengurus' => $pengurus,
        'jurulatih' => $jurulatih,
        'atlet' => $atlet
    ];
    
    if ($id > 0) {
        // Update existing
        $result = $model->update($id, $data);
        if ($result['success']) {
            echo json_encode(['success' => true, 'message' => 'Team updated successfully.']);
        } else {
            http_response_code(400);
            echo json_encode($result);
        }
    } else {
        // Create new
        $result = $model->create($data);
        if ($result['success']) {
            echo json_encode(['success' => true, 'message' => 'Team registered successfully.']);
        } else {
            http_response_code(400);
            echo json_encode($result);
        }
    }
} catch (Exception $e) {
    error_log('[ajax/pasukan_save] ' . $e->getMessage() . " in " . $e->getFile() . ':' . $e->getLine());
    error_log($e->getTraceAsString());
    http_response_code(500);
    $msg = (defined('DEBUG_MODE') && DEBUG_MODE) ? $e->getMessage() : 'Unable to save the team record.';
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

