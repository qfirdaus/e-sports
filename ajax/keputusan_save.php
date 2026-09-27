<?php
// AJAX endpoint: save (insert/update) keputusan (results)
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/keputusan_save][exception] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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
if (!in_array($userRole, ['ADMIN', 'ORGANIZER', 'JUDGE'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied. Only ADMIN, ORGANIZER and JUDGE roles are allowed.']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $id = isset($input['id']) ? (int)$input['id'] : null;
    $sukan_id = isset($input['sukan_id']) ? (int)$input['sukan_id'] : 0;
    $kategori_id = isset($input['kategori_id']) ? (int)$input['kategori_id'] : 0;
    $tarikh = isset($input['tarikh']) ? trim($input['tarikh']) : '';
    $standings = isset($input['standings']) && is_array($input['standings']) ? $input['standings'] : [];
    $status = isset($input['status']) ? trim($input['status']) : 'completed';
    
    // Validation
    if (empty($sukan_id)) {
        throw new Exception('Sport is required');
    }
    if (empty($kategori_id)) {
        throw new Exception('Category is required');
    }
    if (empty($tarikh)) {
        throw new Exception('Date is required');
    }
    
    // Validate date format
    $dateObj = DateTime::createFromFormat('Y-m-d', $tarikh);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $tarikh) {
        throw new Exception('Invalid date format');
    }
    
    $db = getDB();
    $userId = Session::get('user_id');
    
    // Get category to determine type
    $catStmt = $db->prepare("
        SELECT id, nama_kategori, penilaian, sukan_id 
        FROM table_kategori 
        WHERE id = :kategori_id 
        AND deleted_at IS NULL 
        AND status = 1
    ");
    $catStmt->execute([':kategori_id' => $kategori_id]);
    $category = $catStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$category) {
        throw new Exception('Categories not found');
    }
    
    if ($category['sukan_id'] != $sukan_id) {
        throw new Exception('The category does not match the selected sport');
    }
    
    // Check if judge is allowed to record results for this category
    if ($userRole === 'JUDGE') {
        $checkAssignmentStmt = $db->prepare("
            SELECT id FROM judge_category_assignments 
            WHERE user_id = :user_id 
            AND kategori_id = :kategori_id 
            AND is_active = TRUE
        ");
        $checkAssignmentStmt->execute([
            ':user_id' => $userId,
            ':kategori_id' => $kategori_id
        ]);
        
        if (!$checkAssignmentStmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You are not allowed to record results for this category']);
            exit;
        }
    }
    
    $penilaian = $category['penilaian'];
    if (empty($penilaian)) {
        $penilaian = 'berkumpulan'; // Default to team-based
    }
    
    // Get participant count for this category
    $participantCount = 0;
    if ($penilaian === 'individu') {
        $checkColStmt = $db->query("SHOW COLUMNS FROM table_pasukan_atlet LIKE 'kategori_id'");
        $hasKategoriId = $checkColStmt && $checkColStmt->rowCount() > 0;
        if ($hasKategoriId) {
            $countStmt = $db->prepare("
                SELECT COUNT(*) as cnt FROM table_pasukan_atlet 
                WHERE kategori_id = :kategori_id AND deleted_at IS NULL
            ");
            $countStmt->execute([':kategori_id' => $kategori_id]);
            $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
            $participantCount = (int)$countResult['cnt'];
        } else {
            // Fallback: count by sukan_id
            $countStmt = $db->prepare("
                SELECT COUNT(DISTINCT pa.id) as cnt 
                FROM table_pasukan_atlet pa
                JOIN table_pasukan p ON pa.pasukan_id = p.id
                WHERE p.sukan_id = :sukan_id AND pa.deleted_at IS NULL AND p.deleted_at IS NULL
            ");
            $countStmt->execute([':sukan_id' => $sukan_id]);
            $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
            $participantCount = (int)$countResult['cnt'];
        }
    } else {
        // Team-based: count distinct teams
        $checkColStmt = $db->query("SHOW COLUMNS FROM table_pasukan_atlet LIKE 'kategori_id'");
        $hasKategoriId = $checkColStmt && $checkColStmt->rowCount() > 0;
        if ($hasKategoriId) {
            $countStmt = $db->prepare("
                SELECT COUNT(DISTINCT p.id) as cnt 
                FROM table_pasukan p
                JOIN table_pasukan_atlet pa ON p.id = pa.pasukan_id
                WHERE pa.kategori_id = :kategori_id 
                AND pa.deleted_at IS NULL AND p.deleted_at IS NULL AND p.status = 1
            ");
            $countStmt->execute([':kategori_id' => $kategori_id]);
            $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
            $participantCount = (int)$countResult['cnt'];
        } else {
            // Fallback: count by sukan_id
            $countStmt = $db->prepare("
                SELECT COUNT(DISTINCT p.id) as cnt 
                FROM table_pasukan p
                WHERE p.sukan_id = :sukan_id AND p.deleted_at IS NULL AND p.status = 1
            ");
            $countStmt->execute([':sukan_id' => $sukan_id]);
            $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
            $participantCount = (int)$countResult['cnt'];
        }
    }
    
    if ($participantCount === 0) {
        throw new Exception('No participants registered for this category');
    }
    
    // Validate standings array
    if (empty($standings) || !is_array($standings)) {
        throw new Exception('Standings are required. Select participants for first, second and third place.');
    }
    
    // Validate required positions (1-3) are filled
    $positions = [];
    $participantIds = [];
    $requiredPositions = [1, 2, 3];
    $filledRequiredPositions = [];
    
    foreach ($standings as $standing) {
        if (!isset($standing['position']) || !isset($standing['participant_id'])) {
            throw new Exception('Invalid standings format. Each entry must have position and participant_id values.');
        }
        
        $pos = (int)$standing['position'];
        $participantId = trim($standing['participant_id']);
        
        // Skip empty participant IDs (for optional positions 4+)
        if (empty($participantId)) {
            continue;
        }
        
        // Check for duplicate positions
        if (in_array($pos, $positions)) {
            throw new Exception("Position {$pos} diduplikasi.");
        }
        $positions[] = $pos;
        
        // Check for duplicate participants
        if (in_array($participantId, $participantIds)) {
            throw new Exception('The same team or participant cannot be selected for more than one position');
        }
        $participantIds[] = $participantId;
        
        // Track which required positions are filled
        if (in_array($pos, $requiredPositions)) {
            $filledRequiredPositions[] = $pos;
        }
    }
    
    // Validate required positions (1-3) are all filled
    $missingRequiredPositions = array_diff($requiredPositions, $filledRequiredPositions);
    if (!empty($missingRequiredPositions)) {
        throw new Exception('Position ' . implode(', ', $missingRequiredPositions) . ' is required');
    }
    
    // Validate positions don't have gaps (if position 4 is filled, positions 1-3 must exist)
    // But allow positions 4+ to be optional
    if (!empty($positions)) {
        sort($positions);
        $minPos = min($positions);
        $maxPos = max($positions);
        
        // If max position is > 3, ensure no gaps between 1 and max
        if ($maxPos > 3) {
            for ($i = 1; $i <= $maxPos; $i++) {
                // Positions 1-3 must exist, positions 4+ can be skipped
                if ($i <= 3 && !in_array($i, $positions)) {
                    throw new Exception("Position {$i} is required");
                }
            }
        }
    }
    
    // Validate all participants are registered to the category
    foreach ($standings as $standing) {
        $participantId = trim($standing['participant_id']);
        
        if ($penilaian === 'individu') {
            // Validate atlet ID
            $checkColStmt = $db->query("SHOW COLUMNS FROM table_pasukan_atlet LIKE 'kategori_id'");
            $hasKategoriId = $checkColStmt && $checkColStmt->rowCount() > 0;
            if ($hasKategoriId) {
                $checkStmt = $db->prepare("
                    SELECT id FROM table_pasukan_atlet 
                    WHERE id = :id AND kategori_id = :kategori_id AND deleted_at IS NULL
                ");
                $checkStmt->execute([':id' => (int)$participantId, ':kategori_id' => $kategori_id]);
            } else {
                // Fallback: check by sukan_id
                $checkStmt = $db->prepare("
                    SELECT pa.id FROM table_pasukan_atlet pa
                    JOIN table_pasukan p ON pa.pasukan_id = p.id
                    WHERE pa.id = :id AND p.sukan_id = :sukan_id 
                    AND pa.deleted_at IS NULL AND p.deleted_at IS NULL
                ");
                $checkStmt->execute([':id' => (int)$participantId, ':sukan_id' => $sukan_id]);
            }
            if (!$checkStmt->fetch()) {
                throw new Exception("Participant ID {$participantId} is not registered in this category");
            }
        } else {
            // Validate pasukan ID
            $checkColStmt = $db->query("SHOW COLUMNS FROM table_pasukan_atlet LIKE 'kategori_id'");
            $hasKategoriId = $checkColStmt && $checkColStmt->rowCount() > 0;
            if ($hasKategoriId) {
                $checkStmt = $db->prepare("
                    SELECT p.id FROM table_pasukan p
                    JOIN table_pasukan_atlet pa ON p.id = pa.pasukan_id
                    WHERE p.id = :id AND pa.kategori_id = :kategori_id 
                    AND pa.deleted_at IS NULL AND p.deleted_at IS NULL AND p.status = 1
                ");
                $checkStmt->execute([':id' => (int)$participantId, ':kategori_id' => $kategori_id]);
            } else {
                // Fallback: check by sukan_id
                $checkStmt = $db->prepare("
                    SELECT p.id FROM table_pasukan p
                    WHERE p.id = :id AND p.sukan_id = :sukan_id 
                    AND p.deleted_at IS NULL AND p.status = 1
                ");
                $checkStmt->execute([':id' => (int)$participantId, ':sukan_id' => $sukan_id]);
            }
            if (!$checkStmt->fetch()) {
                throw new Exception("Team ID {$participantId} is not registered in this category");
            }
        }
    }
    
    // Prepare standings JSON
    $standingsJson = json_encode($standings);
    if ($standingsJson === false) {
        throw new Exception('Unable to prepare standings data');
    }
    
    // Check if table_results has kategori_id column
    $checkColStmt = $db->query("SHOW COLUMNS FROM table_results LIKE 'kategori_id'");
    $hasKategoriId = $checkColStmt && $checkColStmt->rowCount() > 0;
    
    // Check for duplicate results (same kategori, regardless of date)
    if ($hasKategoriId) {
        $dupStmt = $db->prepare("
            SELECT id FROM table_results 
            WHERE kategori_id = :kategori_id 
            AND deleted_at IS NULL
            " . ($id ? "AND id != :id" : "") . "
        ");
        $dupParams = [':kategori_id' => $kategori_id];
        if ($id) {
            $dupParams[':id'] = $id;
        }
        $dupStmt->execute($dupParams);
        if ($dupStmt->fetch()) {
            throw new Exception('Results already exist for this category');
        }
    } else {
        // Fallback: check by sukan_id if kategori_id doesn't exist
        // Note: This is less precise but prevents duplicates at sport level
        $dupStmt = $db->prepare("
            SELECT id FROM table_results 
            WHERE sukan_id = :sukan_id 
            AND deleted_at IS NULL
            " . ($id ? "AND id != :id" : "") . "
        ");
        $dupParams = [':sukan_id' => $sukan_id];
        if ($id) {
            $dupParams[':id'] = $id;
        }
        $dupStmt->execute($dupParams);
        if ($dupStmt->fetch()) {
            throw new Exception('Results already exist for this sport. Add the kategori_id column for more precise filtering.');
        }
    }
    
    
    if ($id) {
        // Update existing result
        if ($hasKategoriId) {
            $stmt = $db->prepare("
                UPDATE table_results 
                SET sukan_id = :sukan_id,
                    kategori_id = :kategori_id,
                    tarikh = :tarikh,
                    standings = :standings,
                    status = :status,
                    updated_at = CURRENT_TIMESTAMP,
                    updated_by = :updated_by
                WHERE id = :id AND deleted_at IS NULL
            ");
            $stmt->execute([
                ':id' => $id,
                ':sukan_id' => $sukan_id,
                ':kategori_id' => $kategori_id,
                ':tarikh' => $tarikh,
                ':standings' => $standingsJson,
                ':status' => $status,
                ':updated_by' => $userId
            ]);
        } else {
            // Fallback if kategori_id doesn't exist yet
            $stmt = $db->prepare("
                UPDATE table_results 
                SET sukan_id = :sukan_id,
                    tarikh = :tarikh,
                    standings = :standings,
                    status = :status,
                    updated_at = CURRENT_TIMESTAMP,
                    updated_by = :updated_by
                WHERE id = :id AND deleted_at IS NULL
            ");
            $stmt->execute([
                ':id' => $id,
                ':sukan_id' => $sukan_id,
                ':tarikh' => $tarikh,
                ':standings' => $standingsJson,
                ':status' => $status,
                ':updated_by' => $userId
            ]);
        }
        
        if ($stmt->rowCount() === 0) {
            throw new Exception('Results not found or cannot be updated');
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Result updated successfully',
            'id' => $id
        ]);
    } else {
        // Insert new result
        if ($hasKategoriId) {
            $stmt = $db->prepare("
                INSERT INTO table_results (
                    sukan_id, kategori_id, tarikh, 
                    standings, status, created_by
                ) VALUES (
                    :sukan_id, :kategori_id, :tarikh,
                    :standings, :status, :created_by
                )
            ");
            $stmt->execute([
                ':sukan_id' => $sukan_id,
                ':kategori_id' => $kategori_id,
                ':tarikh' => $tarikh,
                ':standings' => $standingsJson,
                ':status' => $status,
                ':created_by' => $userId
            ]);
        } else {
            // Fallback if kategori_id doesn't exist yet
            $stmt = $db->prepare("
                INSERT INTO table_results (
                    sukan_id, tarikh, 
                    standings, status, created_by
                ) VALUES (
                    :sukan_id, :tarikh,
                    :standings, :status, :created_by
                )
            ");
            $stmt->execute([
                ':sukan_id' => $sukan_id,
                ':tarikh' => $tarikh,
                ':standings' => $standingsJson,
                ':status' => $status,
                ':created_by' => $userId
            ]);
        }
        
        $newId = $db->lastInsertId();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Result recorded successfully',
            'id' => $newId
        ]);
    }
} catch (Exception $e) {
    error_log('[ajax/keputusan_save] ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
}

