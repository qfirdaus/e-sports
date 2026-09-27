<?php
// AJAX endpoint: bulk upload pasukan from CSV
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/pasukan_bulk_upload][exception] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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
        error_log('[ajax/pasukan_bulk_upload][shutdown] ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
        $msg = (defined('DEBUG_MODE') && DEBUG_MODE) ? $err['message'] . ' in ' . $err['file'] . ':' . $err['line'] : 'Server error.';
        echo json_encode(['success' => false, 'message' => $msg]);
        exit;
    }
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../api/models/PasukanModel.php';
require_once __DIR__ . '/../api/models/ContingentModel.php';
require_once __DIR__ . '/../api/models/SportModel.php';

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

// Check if file was uploaded
if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No file was uploaded or an error occurred.']);
    exit;
}

$file = $_FILES['csv_file'];
$fileName = $file['name'];
$fileTmpPath = $file['tmp_name'];
$fileSize = $file['size'];

// Validate file type
$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if ($fileExtension !== 'csv') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Only CSV files are allowed.']);
    exit;
}

// Validate file size (5MB max)
if ($fileSize > 5 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'The file exceeds 5 MB.']);
    exit;
}

try {
    // Read CSV file
    $csvData = [];
    
    // Read entire file content to handle BOM properly
    $fileContent = file_get_contents($fileTmpPath);
    if ($fileContent === false) {
        throw new Exception('Unable to read the CSV file.');
    }
    
    // Remove UTF-8 BOM if present
    if (substr($fileContent, 0, 3) === "\xEF\xBB\xBF") {
        $fileContent = substr($fileContent, 3);
    }
    
    // Split into lines
    $lines = explode("\n", $fileContent);
    
    // Process each line
    foreach ($lines as $line) {
        $trimmed = trim($line);
        
        // Skip empty lines and comment lines (starting with #)
        if (empty($trimmed) || (strlen($trimmed) > 0 && $trimmed[0] === '#')) {
            continue;
        }
        
        // Parse CSV line
        $row = str_getcsv($trimmed);
        // Clean up row - trim all cells and remove BOM from first cell if present
        $row = array_map(function($cell) {
            $cell = trim($cell ?? '');
            // Remove any remaining BOM characters
            if (substr($cell, 0, 3) === "\xEF\xBB\xBF") {
                $cell = substr($cell, 3);
            }
            return $cell;
        }, $row);
        
        // Only add if first cell is not empty
        if (!empty($row) && !empty($row[0])) {
            $csvData[] = $row;
        }
    }
    
    if (empty($csvData)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'The CSV file is empty or contains no valid data.']);
        exit;
    }
    
    // Debug: Log first few rows for troubleshooting (only in debug mode)
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        error_log('[pasukan_bulk_upload] First 3 rows: ' . json_encode(array_slice($csvData, 0, 3)));
    }
    
    // Parse CSV data into teams structure
    $teams = [];
    $currentTeam = null;
    $currentSection = null;
    $lineNumber = 0;
    
    foreach ($csvData as $row) {
        $lineNumber++;
        
        // Skip completely empty rows
        $isEmpty = true;
        foreach ($row as $cell) {
            if (!empty(trim($cell ?? ''))) {
                $isEmpty = false;
                break;
            }
        }
        if ($isEmpty) {
            // If we have a current team and hit empty row, it might be separator
            // Continue to next row but keep current section
            continue;
        }
        
        // Get first cell and normalize it
        $firstCell = trim($row[0] ?? '');
        $rowType = strtoupper($firstCell);
        $rowType = ['MANAGER' => 'PENGURUS', 'COACH' => 'JURULATIH', 'ATHLETE' => 'ATLET'][$rowType] ?? $rowType;
        
        if ($rowType === 'TEAM') {
            // Start new team
            if ($currentTeam !== null) {
                // Validate previous team before starting new one
                if (empty($currentTeam['atlet']) || count($currentTeam['atlet']) === 0) {
                    throw new Exception("Row $lineNumber: Team '" . ($currentTeam['nama_pasukan'] ?? 'Unknown') . "' must have at least one athlete.");
                }
                $teams[] = $currentTeam;
            }
            
            $currentTeam = [
                'nama_pasukan' => trim($row[1] ?? ''),
                'kontinjen_id' => isset($row[2]) ? (int)trim($row[2]) : 0,
                'sukan_id' => isset($row[3]) ? (int)trim($row[3]) : 0,
                'status' => isset($row[4]) ? (int)trim($row[4]) : 1,
                'pengurus' => [],
                'jurulatih' => [],
                'atlet' => [],
                'created_by' => Session::get('user_id'),
                'updated_by' => Session::get('user_id')
            ];

            // If user is CONTINGENT, override kontinjen_id with session value
            $sessionRole = Session::get('user_role');
            if ($sessionRole === 'CONTINGENT') {
                $sessionKontinjen = Session::get('kontinjen_id');
                $currentTeam['kontinjen_id'] = $sessionKontinjen ? (int)$sessionKontinjen : 0;
            }
            
            // Validate team header
            if (empty($currentTeam['nama_pasukan'])) {
                throw new Exception("Row $lineNumber: Team name is required.");
            }
            if (empty($currentTeam['kontinjen_id'])) {
                throw new Exception("Row $lineNumber: Contingent ID is required.");
            }
            if (empty($currentTeam['sukan_id'])) {
                throw new Exception("Row $lineNumber: Sport ID is required.");
            }
            
            $currentSection = null;
        } elseif ($rowType === 'PENGURUS') {
            if ($currentTeam === null) {
                throw new Exception("Row $lineNumber: A MANAGER row must follow a TEAM row.");
            }
            // Check if this is a header row or a data row
            // In user's format: PENGURUS, nama, IC, phone, email (data row)
            // OR: PENGURUS, nama, no_kad_pengenalan, no_telefon, emel (header row)
            $secondCell = trim($row[1] ?? '');
            $isHeaderRow = false;
            $headerKeywords = ['nama', 'name', 'no_kad_pengenalan', 'ic', 'no_telefon', 'phone', 'emel', 'email'];
            
            if (!empty($secondCell) && in_array(strtolower($secondCell), array_map('strtolower', $headerKeywords))) {
                $isHeaderRow = true;
            }
            
            if ($isHeaderRow) {
                // This is a header row, just set the section and continue
                $currentSection = 'pengurus';
                error_log("[pasukan_bulk_upload] Skipping PENGURUS header row at line $lineNumber");
                continue;
            } else {
                // This is a data row - process it immediately
                $currentSection = 'pengurus';
                error_log("[pasukan_bulk_upload] Processing PENGURUS data row at line $lineNumber: " . implode(', ', array_slice($row, 0, 5)));
                // Don't continue - fall through to process as data row below
            }
        } elseif ($rowType === 'JURULATIH') {
            if ($currentTeam === null) {
                throw new Exception("Row $lineNumber: A COACH row must follow a TEAM row.");
            }
            // Check if this is a header row or a data row
            $secondCell = trim($row[1] ?? '');
            $isHeaderRow = false;
            $headerKeywords = ['nama', 'name', 'no_kad_pengenalan', 'ic', 'no_telefon', 'phone', 'emel', 'email'];
            
            if (!empty($secondCell) && in_array(strtolower($secondCell), array_map('strtolower', $headerKeywords))) {
                $isHeaderRow = true;
            }
            
            if ($isHeaderRow) {
                // This is a header row, just set the section and continue
                $currentSection = 'jurulatih';
                error_log("[pasukan_bulk_upload] Skipping JURULATIH header row at line $lineNumber");
                continue;
            } else {
                // This is a data row - process it immediately
                $currentSection = 'jurulatih';
                error_log("[pasukan_bulk_upload] Processing JURULATIH data row at line $lineNumber: " . implode(', ', array_slice($row, 0, 5)));
                // Don't continue - fall through to process as data row below
            }
        } elseif ($rowType === 'ATLET') {
            if ($currentTeam === null) {
                throw new Exception("Row $lineNumber: An ATHLETE row must follow a TEAM row.");
            }
            // Check if this is a header row or a data row
            // Header row typically has "ATLET" and column headers like "nama", "no_kad_pengenalan", etc.
            // Data row has "ATLET" in column 1 and actual athlete name in column 2
            $secondCell = trim($row[1] ?? '');
            $thirdCell = trim($row[2] ?? '');
            $fourthCell = trim($row[3] ?? '');
            
            // Check if this looks like a header row
            // Header rows typically contain column names like: nama, no_kad_pengenalan, no_matrik, kategori_id
            $isHeaderRow = false;
            $headerKeywords = ['nama', 'name', 'no_kad_pengenalan', 'ic', 'no_matrik', 'matrik', 'kategori', 'kategori_id', 'category'];
            
            foreach ([$secondCell, $thirdCell, $fourthCell] as $cell) {
                $cellLower = strtolower($cell);
                foreach ($headerKeywords as $keyword) {
                    if (strpos($cellLower, $keyword) !== false) {
                        $isHeaderRow = true;
                        break 2; // Break out of both loops
                    }
                }
            }
            
            // Also check if second cell is empty, "nama", "Name", "ATLET", or "-"
            if (!$isHeaderRow && (empty($secondCell) || $secondCell === 'nama' || $secondCell === 'Name' || strtoupper($secondCell) === 'ATLET' || $secondCell === '-')) {
                $isHeaderRow = true;
            }
            
            if ($isHeaderRow) {
                // This is a header row, just set the section and continue
                $currentSection = 'atlet';
                error_log("[pasukan_bulk_upload] Skipping header row at line $lineNumber: " . implode(', ', array_slice($row, 0, 5)));
                continue; // Skip header row
            } else {
                // This is a data row (has actual name in column 2)
                $currentSection = 'atlet';
                // Don't continue - fall through to process as data row below
            }
        }
        
        // Process data rows for current section
        // Note: When rowType is 'ATLET'/'PENGURUS'/'JURULATIH' and it's a data row, we need to process it
        if ($currentTeam !== null && $currentSection !== null) {
            // Process data row (could be standard format or header format)
            // Data row for current section
            if ($currentSection === 'pengurus') {
                error_log("[pasukan_bulk_upload] Processing pengurus section - rowType: '$rowType', row: " . json_encode(array_slice($row, 0, 5)));
                // Format depends on whether row[0] is "PENGURUS" or the name
                // If rowType is "PENGURUS", then: PENGURUS, nama (row[1]), IC (row[2]), phone (row[3]), email (row[4])
                // If rowType is not "PENGURUS", then: nama (row[0]), IC (row[1]), phone (row[2]), email (row[3])
                
                // Check if row[0] is "PENGURUS" (meaning nama is in row[1])
                // If rowType is already 'PENGURUS', we know this is a data row, not a header
                if ($rowType === 'PENGURUS') {
                    $nama = trim($row[1] ?? '');
                    $ic = isset($row[2]) ? trim($row[2]) : '';
                    $phone = isset($row[3]) ? trim($row[3]) : '';
                    $email = isset($row[4]) ? trim($row[4]) : '';
                } else {
                    // Standard format: nama is in row[0]
                    $nama = trim($row[0] ?? '');
                    $ic = isset($row[1]) ? trim($row[1]) : '';
                    $phone = isset($row[2]) ? trim($row[2]) : '';
                    $email = isset($row[3]) ? trim($row[3]) : '';
                }
                
                if (!empty($nama) && $nama !== '-' && $nama !== 'PENGURUS') {
                    
                    // Handle case where IC might be in column 2 or 3
                    // If column 1 is "-", check if column 2 has IC or phone
                    if ($ic === '-' || empty($ic)) {
                        // Try column 2 as phone if it looks like a phone number
                        if (!empty($phone) && is_numeric($phone)) {
                            // Column 2 might be phone, column 3 might be email
                            $phone = $phone;
                            $email = isset($row[3]) ? trim($row[3]) : '';
                        }
                    }
                    
                    error_log("[pasukan_bulk_upload] Adding pengurus: nama='$nama', ic='$ic', phone='$phone', email='$email'");
                    $currentTeam['pengurus'][] = [
                        'nama' => $nama,
                        'no_kad_pengenalan' => ($ic !== '-' && !empty($ic)) ? $ic : '',
                        'no_telefon' => ($phone !== '-' && !empty($phone)) ? $phone : '',
                        'emel' => ($email !== '-' && !empty($email)) ? $email : ''
                    ];
                }
            } elseif ($currentSection === 'jurulatih') {
                error_log("[pasukan_bulk_upload] Processing jurulatih section - rowType: '$rowType', row: " . json_encode(array_slice($row, 0, 5)));
                // Format depends on whether row[0] is "JURULATIH" or the name
                // If rowType is "JURULATIH", then: JURULATIH, nama (row[1]), IC (row[2]), phone (row[3]), email (row[4])
                // If rowType is not "JURULATIH", then: nama (row[0]), IC (row[1]), phone (row[2]), email (row[3])
                
                // Check if row[0] is "JURULATIH" (meaning nama is in row[1])
                // If rowType is already 'JURULATIH', we know this is a data row, not a header
                if ($rowType === 'JURULATIH') {
                    $nama = trim($row[1] ?? '');
                    $ic = isset($row[2]) ? trim($row[2]) : '';
                    $phone = isset($row[3]) ? trim($row[3]) : '';
                    $email = isset($row[4]) ? trim($row[4]) : '';
                } else {
                    // Standard format: nama is in row[0]
                    // But first check if this is a header row (only if rowType is NOT 'JURULATIH')
                    $firstCell = trim($row[0] ?? '');
                    $headerKeywords = ['nama', 'name', 'no_kad_pengenalan', 'ic', 'no_telefon', 'phone', 'emel', 'email'];
                    if (in_array(strtolower($firstCell), array_map('strtolower', $headerKeywords))) {
                        continue; // Skip header row
                    }
                    
                    $nama = trim($row[0] ?? '');
                    $ic = isset($row[1]) ? trim($row[1]) : '';
                    $phone = isset($row[2]) ? trim($row[2]) : '';
                    $email = isset($row[3]) ? trim($row[3]) : '';
                }
                
                if (!empty($nama) && $nama !== '-' && $nama !== 'JURULATIH') {
                    
                    // Handle case where IC might be in column 2 or 3
                    if ($ic === '-' || empty($ic)) {
                        if (!empty($phone) && is_numeric($phone)) {
                            $phone = $phone;
                            $email = isset($row[3]) ? trim($row[3]) : '';
                        }
                    }
                    
                    error_log("[pasukan_bulk_upload] Adding jurulatih: nama='$nama', ic='$ic', phone='$phone', email='$email'");
                    $currentTeam['jurulatih'][] = [
                        'nama' => $nama,
                        'no_kad_pengenalan' => ($ic !== '-' && !empty($ic)) ? $ic : '',
                        'no_telefon' => ($phone !== '-' && !empty($phone)) ? $phone : '',
                        'emel' => ($email !== '-' && !empty($email)) ? $email : ''
                    ];
                }
            } elseif ($currentSection === 'atlet') {
                // Format: nama,no_kad_pengenalan,no_matrik,kategori_id
                // User's format: ATLET, nama, IC+matrik_combined, (empty), kategori_id
                // So: row[0]=ATLET (row type), row[1]=nama, row[2]=IC+matrik, row[3]=empty, row[4]=empty, row[5]=kategori_id
                // OR if row[0] is not "ATLET", then: row[0]=nama, row[1]=IC+matrik, row[2]=empty, row[3]=empty, row[4]=kategori_id
                
                // Check if row[0] is "ATLET" (meaning nama is in row[1])
                // Format: ATLET, nama (col B), IC (col C), matrik (col D), kategori_id (col E)
                if ($rowType === 'ATLET') {
                    $nama = trim($row[1] ?? '');
                    $icRaw = isset($row[2]) ? trim($row[2]) : ''; // Column C: IC only (12 digits without dashes)
                    $matrik = isset($row[3]) ? trim($row[3]) : ''; // Column D: Matrik only
                    $kategoriId = isset($row[4]) ? trim($row[4]) : ''; // Column E: kategori_id
                } else {
                    // Standard format: nama is in row[0]
                    $nama = trim($row[0] ?? '');
                    $icRaw = isset($row[1]) ? trim($row[1]) : '';
                    $matrik = isset($row[2]) ? trim($row[2]) : '';
                    $kategoriId = isset($row[3]) ? trim($row[3]) : '';
                    // Fallback to row[4] if row[3] is empty for kategori_id
                    if (empty($kategoriId) && isset($row[4])) {
                        $kategoriId = trim($row[4]);
                    }
                }
                
                if (!empty($nama) && $nama !== '-' && $nama !== 'ATLET') {
                    // Parse IC - handle both formats (with dashes or without)
                    $ic = '';
                    
                    if (!empty($icRaw) && $icRaw !== '-') {
                        // Remove any whitespace and non-digit characters (except dashes)
                        $icRaw = trim($icRaw);
                        
                        // Extract only digits first
                        $digitsOnly = preg_replace('/\D/', '', $icRaw);
                        
                        // Check if IC has dashes (format: YYMMDD-GG-NN)
                        if (preg_match('/^(\d{6}-\d{2}-\d{2,4})/', $icRaw, $matches)) {
                            // Already has dashes - use the matched portion only
                            $ic = $matches[1];
                        } elseif (strlen($digitsOnly) >= 12) {
                            // 12 or more digits without dashes - convert to format: YYMMDD-GG-NN
                            $digits = substr($digitsOnly, 0, 12);
                            if (strlen($digits) === 12) {
                                // Format: YYMMDD-GG-NN (e.g., 071110080645 -> 071110-08-0645)
                                $ic = substr($digits, 0, 6) . '-' . substr($digits, 6, 2) . '-' . substr($digits, 8, 4);
                            } else {
                                // If not exactly 12, use as is but limit to 20 chars
                                $ic = substr($digits, 0, 20);
                            }
                        } elseif (strlen($digitsOnly) > 0 && strlen($digitsOnly) < 12) {
                            // Less than 12 digits - might be partial, store as is but limit length
                            $ic = substr($digitsOnly, 0, 20);
                        } else {
                            // No digits found - leave empty
                            $ic = '';
                        }
                        
                        // Final validation: Ensure IC doesn't exceed 20 characters (database limit)
                        // This is critical - the database column is VARCHAR(20)
                        if (strlen($ic) > 20) {
                            error_log("[pasukan_bulk_upload] ERROR: IC exceeds 20 chars: '$ic' (length: " . strlen($ic) . "), truncating");
                            $ic = substr($ic, 0, 20);
                        }
                        
                        // Additional safety: If IC still looks invalid, clear it
                        if (!empty($ic) && !preg_match('/^[\d-]+$/', $ic)) {
                            error_log("[pasukan_bulk_upload] WARNING: IC contains invalid characters: '$ic', clearing it");
                            $ic = '';
                        }
                        
                        // Log for debugging
                        if (defined('DEBUG_MODE') && DEBUG_MODE) {
                            error_log("[pasukan_bulk_upload] IC conversion - raw: '$icRaw', digits: '$digitsOnly', final: '$ic' (length: " . strlen($ic) . ")");
                        }
                    }
                    
                    // Log the parsed data for debugging
                    if (defined('DEBUG_MODE') && DEBUG_MODE) {
                        error_log("[pasukan_bulk_upload] Parsed ATLET row $lineNumber - nama: '$nama', IC: '$ic' (length: " . strlen($ic) . "), matrik: '$matrik', kategori_id: '$kategoriId'");
                        error_log("[pasukan_bulk_upload] Raw row data: " . json_encode($row));
                    }
                    
                    $currentTeam['atlet'][] = [
                        'nama' => $nama,
                        'no_kad_pengenalan' => $ic,
                        'no_matrik' => $matrik,
                        'kategori_id' => !empty($kategoriId) && is_numeric($kategoriId) ? (int)$kategoriId : null
                    ];
                }
            }
        } else {
            // Unknown row type - could be data row without section, or invalid format
            if ($currentTeam === null) {
                throw new Exception("Row $lineNumber: Invalid data. Start with a TEAM row. Found: '" . htmlspecialchars($firstCell, ENT_QUOTES, 'UTF-8') . "'");
            } else {
                // If we have a team but no section, skip this row (might be formatting issue)
                continue;
            }
        }
    }
    
    // Add last team
    if ($currentTeam !== null) {
        if (empty($currentTeam['atlet']) || count($currentTeam['atlet']) === 0) {
            throw new Exception("Team '" . ($currentTeam['nama_pasukan'] ?? 'Unknown') . "' must have at least one athlete.");
        }
        $teams[] = $currentTeam;
    }
    
    if (empty($teams)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No teams found in the CSV file.']);
        exit;
    }
    
    // Validate foreign keys
    $contingentModel = new ContingentModel();
    $sportModel = new SportModel();
    
    $validContingents = [];
    $validSports = [];
    
    foreach ($teams as $team) {
        // Validate kontinjen_id
        if (!isset($validContingents[$team['kontinjen_id']])) {
            $contingentResult = $contingentModel->getById($team['kontinjen_id']);
            $validContingents[$team['kontinjen_id']] = $contingentResult['success'];
        }
        if (!$validContingents[$team['kontinjen_id']]) {
            throw new Exception("Contingent ID " . $team['kontinjen_id'] . " is invalid for team '" . $team['nama_pasukan'] . "'.");
        }
        
        // Validate sukan_id
        if (!isset($validSports[$team['sukan_id']])) {
            $sportResult = $sportModel->getById($team['sukan_id']);
            $validSports[$team['sukan_id']] = $sportResult['success'];
        }
        if (!$validSports[$team['sukan_id']]) {
            throw new Exception("Sport ID " . $team['sukan_id'] . " is invalid for team '" . $team['nama_pasukan'] . "'.");
        }
    }
    
    // Validate status - only ADMIN and ORGANIZER can set status to active
    $canChangeStatus = in_array($userRole, ['ADMIN', 'ORGANIZER']);
    if (!$canChangeStatus) {
        foreach ($teams as &$team) {
            $team['status'] = 0; // CONTINGENT users cannot set status to active
        }
        unset($team);
    }
    
    // Process bulk upload
    $pasukanModel = new PasukanModel();
    $result = $pasukanModel->bulkCreate($teams);
    
    if ($result['success_count'] > 0) {
        echo json_encode([
            'success' => true,
            'message' => "Successfully uploaded {$result['success_count']} of {$result['total']} teams.",
            'total' => $result['total'],
            'success_count' => $result['success_count'],
            'failed_count' => $result['failed_count'],
            'errors' => $result['errors']
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => "Unable to upload all teams.",
            'total' => $result['total'],
            'success_count' => $result['success_count'],
            'failed_count' => $result['failed_count'],
            'errors' => $result['errors']
        ]);
    }
    
} catch (Exception $e) {
    error_log('[ajax/pasukan_bulk_upload] ' . $e->getMessage() . " in " . $e->getFile() . ':' . $e->getLine());
    http_response_code(400);
    $msg = (defined('DEBUG_MODE') && DEBUG_MODE) ? $e->getMessage() : 'Unable to process the CSV file.';
    echo json_encode(['success' => false, 'message' => $msg]);
    exit;
}

