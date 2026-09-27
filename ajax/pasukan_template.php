<?php
// AJAX endpoint: Generate CSV template for bulk pasukan upload
ini_set('display_errors', '0');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/pasukan_template][exception] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Error: ' . $e->getMessage();
    exit;
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../api/models/ContingentModel.php';
require_once __DIR__ . '/../api/models/SportModel.php';

if (session_status() === PHP_SESSION_NONE) {
    Session::start();
}

$auth = getAuth();
if (!$auth->isLoggedIn()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Your session has expired. Please sign in again.';
    exit;
}

$userRole = Session::get('user_role');
if (!in_array($userRole, ['ADMIN', 'ORGANIZER', 'CONTINGENT'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'You do not have permission.';
    exit;
}

try {
    // Fetch contingents and sports for template
    $contingentModel = new ContingentModel();
    $contingentsResult = $contingentModel->getAll(['limit' => 1000, 'status' => 1]);
    $contingents = $contingentsResult['success'] ? $contingentsResult['data'] : [];
    
    $sportModel = new SportModel();
    $sportsResult = $sportModel->getAll(['limit' => 1000, 'status' => 1]);
    $sports = $sportsResult['success'] ? $sportsResult['data'] : [];
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_pasukan_' . date('Y-m-d') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // Output UTF-8 BOM for Excel compatibility
    echo "\xEF\xBB\xBF";
    
    // Instructions/Comments rows
    echo "# Bulk Team Upload Template\n";
    echo "# Format: Each team uses multiple rows\n";
    echo "# TEAM row: team_name,contingent_id,sport_id,status\n";
    echo "# MANAGER row: name,identity_card_number,phone_number,email\n";
    echo "# COACH row: name,identity_card_number,phone_number,email\n";
    echo "# ATHLETE row: name,identity_card_number,matriculation_number,category_id\n";
    echo "# Leave a blank row between teams\n";
    echo "#\n";
    
    // Contingent reference
    if (!empty($contingents)) {
        echo "# Contingent Reference:\n";
        foreach ($contingents as $c) {
            echo "#   " . (int)$c['id'] . " = " . htmlspecialchars($c['nama_universiti'] ?? '', ENT_QUOTES, 'UTF-8') . "\n";
        }
        echo "#\n";
    }
    
    // Sports reference
    if (!empty($sports)) {
        echo "# Sport Reference:\n";
        foreach ($sports as $s) {
            echo "#   " . (int)$s['id'] . " = " . htmlspecialchars($s['nama_sukan'] ?? '', ENT_QUOTES, 'UTF-8') . "\n";
        }
        echo "#\n";
    }
    
    // Sample data - Team 1
    $sampleContingentId = !empty($contingents) ? (int)$contingents[0]['id'] : 1;
    $sampleSportId = !empty($sports) ? (int)$sports[0]['id'] : 1;
    
    echo "TEAM,Sample Team A," . $sampleContingentId . "," . $sampleSportId . ",1\n";
    echo "MANAGER,Manager Name,IC Manager,0123456789,manager@example.com\n";
    echo "COACH,Coach Name,IC Coach,0123456788,coach@example.com\n";
    echo "ATHLETE,Athlete Name 1,IC Athlete 1,MAT123456,1\n";
    echo "ATHLETE,Athlete Name 2,IC Athlete 2,MAT123457,1\n";
    echo "\n";
    
    // Sample data - Team 2
    echo "TEAM,Sample Team B," . $sampleContingentId . "," . $sampleSportId . ",1\n";
    echo "MANAGER,Manager Name B,IC Manager B,0123456787,managerb@example.com\n";
    echo "COACH,Coach Name B,IC Coach B,0123456786,coachb@example.com\n";
    echo "ATHLETE,Athlete Name B1,IC Athlete B1,MAT123458,1\n";
    echo "\n";
    
} catch (Exception $e) {
    error_log('[ajax/pasukan_template] ' . $e->getMessage() . " in " . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    $msg = (defined('DEBUG_MODE') && DEBUG_MODE) ? $e->getMessage() : 'Unable to generate the template.';
    echo $msg;
    exit;
}

