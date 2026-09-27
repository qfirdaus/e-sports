<?php
// AJAX endpoint: get participants (players/teams) registered to a category
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/get_participants_by_kategori][exception] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error.']);
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

try {
    $kategori_id = isset($_GET['kategori_id']) ? (int)$_GET['kategori_id'] : 0;
    if (!$kategori_id) {
        echo json_encode(['success' => true, 'data' => [], 'type' => null]);
        exit;
    }

    $db = getDB();
    
    // Check if kategori_id column exists in table_pasukan_atlet
    $checkColStmt = $db->query("SHOW COLUMNS FROM table_pasukan_atlet LIKE 'kategori_id'");
    $hasKategoriId = $checkColStmt && $checkColStmt->rowCount() > 0;
    
    // First, get the category to determine if it's individual or team
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
        echo json_encode(['success' => false, 'message' => 'Categories not found']);
        exit;
    }
    
    $penilaian = $category['penilaian']; // 'individu' or 'berkumpulan' or NULL
    
    // If penilaian is not set, default to 'berkumpulan' (team-based)
    if (empty($penilaian)) {
        $penilaian = 'berkumpulan';
    }
    
    if ($penilaian === 'individu') {
        // Get individual players registered to this category
        if ($hasKategoriId) {
            // If kategori_id column exists, use it directly
            $stmt = $db->prepare("
                SELECT 
                    pa.id,
                    pa.nama,
                    pa.no_matrik,
                    pa.no_kad_pengenalan,
                    p.id AS pasukan_id,
                    p.nama_pasukan,
                    k.id AS kontinjen_id,
                    k.kod_universiti,
                    u.nama_universiti,
                    u.nama_pendek
                FROM table_pasukan_atlet pa
                JOIN table_pasukan p ON pa.pasukan_id = p.id
                JOIN table_kontinjen k ON p.kontinjen_id = k.id
                LEFT JOIN table_ref_universiti u ON k.kod_universiti = u.kod_universiti AND u.deleted_at IS NULL
                WHERE pa.kategori_id = :kategori_id
                AND pa.deleted_at IS NULL
                AND p.deleted_at IS NULL
                AND p.status = 1
                AND k.deleted_at IS NULL
                ORDER BY u.nama_universiti, k.kod_universiti, p.nama_pasukan, pa.nama
            ");
            $stmt->execute([':kategori_id' => $kategori_id]);
            $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Fallback: get all athletes from teams that have this category in their sport
            // This is a workaround if kategori_id doesn't exist in table_pasukan_atlet
            $stmt = $db->prepare("
                SELECT 
                    pa.id,
                    pa.nama,
                    pa.no_matrik,
                    pa.no_kad_pengenalan,
                    p.id AS pasukan_id,
                    p.nama_pasukan,
                    k.id AS kontinjen_id,
                    k.kod_universiti,
                    u.nama_universiti,
                    u.nama_pendek
                FROM table_pasukan_atlet pa
                JOIN table_pasukan p ON pa.pasukan_id = p.id
                JOIN table_kontinjen k ON p.kontinjen_id = k.id
                LEFT JOIN table_ref_universiti u ON k.kod_universiti = u.kod_universiti AND u.deleted_at IS NULL
                WHERE p.sukan_id = :sukan_id
                AND pa.deleted_at IS NULL
                AND p.deleted_at IS NULL
                AND p.status = 1
                AND k.deleted_at IS NULL
                ORDER BY u.nama_universiti, k.kod_universiti, p.nama_pasukan, pa.nama
            ");
            $stmt->execute([':sukan_id' => $category['sukan_id']]);
            $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Note: This fallback returns all athletes for the sport, not filtered by category
            // The kategori_id column should be added to table_pasukan_atlet for proper filtering
        }
        
        // Format for display
        $formatted = [];
        foreach ($participants as $p) {
                $kontingenLabel = !empty($p['nama_pendek']) ? $p['nama_pendek'] : (!empty($p['nama_universiti']) ? $p['nama_universiti'] : $p['kod_universiti']);
                $formatted[] = [
                    'id' => $p['id'],
                    'display_name' => $p['nama'] . 
                        ($p['no_matrik'] ? ' (' . $p['no_matrik'] . ')' : '') .
                        ' - ' . $kontingenLabel,
                    'nama' => $p['nama'],
                    'no_matrik' => $p['no_matrik'],
                    'no_kad_pengenalan' => $p['no_kad_pengenalan'],
                    'pasukan_id' => $p['pasukan_id'],
                    'pasukan_nama' => $p['nama_pasukan'],
                    'kontinjen_id' => $p['kontinjen_id'],
                    'kontinjen_nama' => $kontingenLabel,
                    'universiti' => $p['nama_universiti']
                ];
        }
    } else {
        // Get teams registered to this category (teams that have at least one player in this category)
        if ($hasKategoriId) {
            $stmt = $db->prepare("
                SELECT DISTINCT
                    p.id,
                    p.nama_pasukan,
                    k.id AS kontinjen_id,
                    k.kod_universiti,
                    u.nama_universiti,
                    u.nama_pendek,
                    COUNT(DISTINCT pa.id) AS jumlah_atlet
                FROM table_pasukan p
                JOIN table_pasukan_atlet pa ON p.id = pa.pasukan_id AND pa.kategori_id = :kategori_id
                JOIN table_kontinjen k ON p.kontinjen_id = k.id
                LEFT JOIN table_ref_universiti u ON k.kod_universiti = u.kod_universiti AND u.deleted_at IS NULL
                WHERE pa.deleted_at IS NULL
                AND p.deleted_at IS NULL
                AND p.status = 1
                AND k.deleted_at IS NULL
                GROUP BY p.id, p.nama_pasukan, k.id, k.kod_universiti, u.nama_universiti
                ORDER BY u.nama_universiti, k.kod_universiti, p.nama_pasukan
            ");
            $stmt->execute([':kategori_id' => $kategori_id]);
        } else {
            // Fallback: get teams for the sport (if kategori_id column doesn't exist)
            $stmt = $db->prepare("
                SELECT DISTINCT
                    p.id,
                    p.nama_pasukan,
                    k.id AS kontinjen_id,
                    k.kod_universiti,
                    u.nama_universiti,
                    u.nama_pendek,
                    COUNT(DISTINCT pa.id) AS jumlah_atlet
                FROM table_pasukan p
                JOIN table_pasukan_atlet pa ON p.id = pa.pasukan_id
                JOIN table_kontinjen k ON p.kontinjen_id = k.id
                LEFT JOIN table_ref_universiti u ON k.kod_universiti = u.kod_universiti AND u.deleted_at IS NULL
                WHERE p.sukan_id = :sukan_id
                AND pa.deleted_at IS NULL
                AND p.deleted_at IS NULL
                AND p.status = 1
                AND k.deleted_at IS NULL
                GROUP BY p.id, p.nama_pasukan, k.id, k.kod_universiti, u.nama_universiti
                ORDER BY u.nama_universiti, k.kod_universiti, p.nama_pasukan
            ");
            $stmt->execute([':sukan_id' => $category['sukan_id']]);
        }
        $participants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format for display
        $formatted = [];
        foreach ($participants as $p) {
            $kontingenLabel = !empty($p['nama_pendek']) ? $p['nama_pendek'] : (!empty($p['nama_universiti']) ? $p['nama_universiti'] : $p['kod_universiti']);
            $formatted[] = [
                'id' => $p['id'],
                'display_name' => $p['nama_pasukan'] . 
                    ' (' . $kontingenLabel . ')' .
                    ($p['jumlah_atlet'] > 0 ? ' - ' . $p['jumlah_atlet'] . ' atlet' : ''),
                'nama_pasukan' => $p['nama_pasukan'],
                'kontinjen_id' => $p['kontinjen_id'],
                'kontinjen_nama' => $kontingenLabel,
                'universiti' => $p['nama_universiti'],
                'jumlah_atlet' => $p['jumlah_atlet']
            ];
        }
    }

    echo json_encode([
        'success' => true, 
        'data' => $formatted,
        'type' => $penilaian,
        'kategori_nama' => $category['nama_kategori']
    ]);
    exit;
} catch (Exception $e) {
    error_log('[ajax/get_participants_by_kategori] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    $errorMessage = 'Unable to load participants.';
    if (defined('DEBUG_MODE') && DEBUG_MODE) {
        $errorMessage = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    }
    echo json_encode(['success' => false, 'message' => $errorMessage]);
    exit;
}

