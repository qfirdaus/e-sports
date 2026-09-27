<?php
/**
 * AJAX endpoint: Add new participant (atlet, pengurus, or jurulatih)
 * Roles: ADMIN, ORGANIZER, VIEWER
 */
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/participant_add][exception] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Server error.']);
    }
    exit;
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/rbac.php';

if (session_status() === PHP_SESSION_NONE) {
    Session::start();
}

$auth = getAuth();
if (!$auth->isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your session has expired. Please sign in again.']);
    exit;
}

$rbac = getRBAC();
// Allow ADMIN, ORGANIZER, and VIEWER to add participants from contingent-admin.php
$userRole = Session::get('user_role');
if (!in_array($userRole, ['ADMIN', 'ORGANIZER', 'VIEWER'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Only ADMIN, ORGANIZER or VIEWER roles can add participants.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $participant_type = isset($input['participant_type']) ? trim($input['participant_type']) : '';
    $pasukan_id = isset($input['pasukan_id']) ? (int)$input['pasukan_id'] : 0;
    $nama = isset($input['nama']) ? trim($input['nama']) : '';
    $no_kad_pengenalan = isset($input['no_kad_pengenalan']) ? trim($input['no_kad_pengenalan']) : null;
    $no_matrik = isset($input['no_matrik']) ? trim($input['no_matrik']) : null;
    $no_telefon = isset($input['no_telefon']) ? trim($input['no_telefon']) : null;
    $emel = isset($input['emel']) ? trim($input['emel']) : null;
    $jawatan = isset($input['jawatan']) ? trim($input['jawatan']) : null;
    $kategori_id = isset($input['kategori_id']) && !empty($input['kategori_id']) ? (int)$input['kategori_id'] : null;
    
    // Validation
    if (empty($participant_type) || !in_array($participant_type, ['atlet', 'pengurus', 'jurulatih'])) {
        throw new Exception('Invalid participant type.');
    }
    
    if ($pasukan_id <= 0) {
        throw new Exception('Invalid team ID.');
    }
    
    if (empty($nama)) {
        throw new Exception('Participant name is required.');
    }

    if ($participant_type === 'pengurus' || $participant_type === 'jurulatih') {
        if ($jawatan === null || $jawatan === '') {
            throw new Exception('A position is required for managers and coaches.');
        }
        $jawatan = mb_strtoupper($jawatan, 'UTF-8');
        $allowedJawatan = $participant_type === 'pengurus'
            ? ['PENGURUS', 'PENOLONG PENGURUS']
            : ['JURULATIH', 'PENOLONG JURULATIH'];
        if (!in_array($jawatan, $allowedJawatan, true)) {
            throw new Exception('Invalid position for the selected participant type.');
        }
    } else {
        $jawatan = null;
    }
    
    // Validate IC format if provided
    if (!empty($no_kad_pengenalan)) {
        $cleanIc = preg_replace('/\D+/', '', $no_kad_pengenalan);
        if (strlen($cleanIc) !== 12) {
            throw new Exception('The identity card number must contain 12 digits.');
        }
        $no_kad_pengenalan = $cleanIc;
    }
    
    // Validate matrik length if provided
    if (!empty($no_matrik) && mb_strlen($no_matrik) > 50) {
        throw new Exception('The matriculation number cannot exceed 50 characters.');
    }
    
    // Validate phone length if provided
    if (!empty($no_telefon) && mb_strlen($no_telefon) > 20) {
        throw new Exception('The phone number cannot exceed 20 characters.');
    }
    
    // Validate email format if provided
    if (!empty($emel)) {
        if (mb_strlen($emel) > 100) {
            throw new Exception('The email address cannot exceed 100 characters.');
        }
        if (!filter_var($emel, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format.');
        }
    }
    
    $db = getDB();
    
    // Verify pasukan exists and is active
    $checkPasukan = $db->prepare("
        SELECT id, sukan_id 
        FROM table_pasukan 
        WHERE id = :id AND deleted_at IS NULL AND status = 1
    ");
    $checkPasukan->execute([':id' => $pasukan_id]);
    $pasukan = $checkPasukan->fetch(PDO::FETCH_ASSOC);
    
    if (!$pasukan) {
        throw new Exception('Team not found or inactive.');
    }
    
    // For athletes, validate kategori_id if provided
    if ($participant_type === 'atlet' && !empty($kategori_id)) {
        $checkKategori = $db->prepare("
            SELECT id, sukan_id 
            FROM table_kategori 
            WHERE id = :id AND deleted_at IS NULL AND status = 1
        ");
        $checkKategori->execute([':id' => $kategori_id]);
        $kategori = $checkKategori->fetch(PDO::FETCH_ASSOC);
        
        if (!$kategori) {
            throw new Exception('Categories not found.');
        }
        
        if ($kategori['sukan_id'] != $pasukan['sukan_id']) {
            throw new Exception('The category does not match the team sport.');
        }
    }
    
    // Map participant type to table name and fields
    $tableMap = [
        'atlet' => [
            'table' => 'table_pasukan_atlet',
            'fields' => ['pasukan_id', 'nama', 'no_kad_pengenalan', 'no_matrik', 'kategori_id']
        ],
        'pengurus' => [
            'table' => 'table_pasukan_pengurus',
            'fields' => ['pasukan_id', 'nama', 'no_kad_pengenalan', 'no_telefon', 'emel', 'jawatan']
        ],
        'jurulatih' => [
            'table' => 'table_pasukan_jurulatih',
            'fields' => ['pasukan_id', 'nama', 'no_kad_pengenalan', 'no_telefon', 'emel', 'jawatan']
        ]
    ];
    
    $tableInfo = $tableMap[$participant_type];
    $tableName = $tableInfo['table'];
    $fields = $tableInfo['fields'];
    
    // Build insert query
    $fieldList = implode(', ', $fields);
    $placeholders = ':' . implode(', :', $fields);
    
    $insertSql = "INSERT INTO {$tableName} ({$fieldList}) VALUES ({$placeholders})";
    $insertStmt = $db->prepare($insertSql);
    
    // Prepare parameters
    $params = [
        ':pasukan_id' => $pasukan_id,
        ':nama' => $nama
    ];
    
    if (in_array('no_kad_pengenalan', $fields)) {
        $params[':no_kad_pengenalan'] = $no_kad_pengenalan;
    }
    
    if (in_array('no_matrik', $fields)) {
        $params[':no_matrik'] = $no_matrik;
    }
    
    if (in_array('no_telefon', $fields)) {
        $params[':no_telefon'] = $no_telefon;
    }
    
    if (in_array('emel', $fields)) {
        $params[':emel'] = $emel;
    }

    if (in_array('jawatan', $fields)) {
        $params[':jawatan'] = $jawatan;
    }
    
    if (in_array('kategori_id', $fields)) {
        $params[':kategori_id'] = $kategori_id;
    }
    
    $insertStmt->execute($params);
    $newId = $db->lastInsertId();
    
    // Fetch the newly created record to return
    $fetchStmt = $db->prepare("SELECT * FROM {$tableName} WHERE id = :id");
    $fetchStmt->execute([':id' => $newId]);
    $newParticipant = $fetchStmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'message' => 'Participant added successfully.',
        'participant' => $newParticipant,
        'participant_id' => $newId,
        'participant_type' => $participant_type
    ]);
    
} catch (Exception $e) {
    error_log('[ajax/participant_add] ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
