<?php
// AJAX endpoint: get contingent list
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/contingent_list][exception] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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

try {
        // Use aggregated SQL to include jumlah_atlet
        $pdo = getDB();
        $currentRole = Session::get('user_role') ?? null;
        $currentKontinjenId = Session::get('kontinjen_id') ?? null;
        $sql = "SELECT
    k.id,
    u.nama_universiti,
    k.kod_universiti,
    k.nama_pegawai_untuk_dihubungi,
    k.alamat,
    k.emel,
    k.no_telefon,
    COALESCE(SUM(a.cnt),0) AS jumlah_atlet,

    CASE 
        WHEN u.status = 1 THEN 'Active'
        ELSE 'Inactive'
    END AS status_universiti,

    k.created_at
FROM table_kontinjen k

INNER JOIN table_ref_universiti u
    ON k.kod_universiti = u.kod_universiti
    AND u.status = 1

LEFT JOIN table_pasukan p
    ON p.kontinjen_id = k.id
    AND p.deleted_at IS NULL
    AND p.status = 1

LEFT JOIN (
    SELECT pasukan_id, COUNT(*) AS cnt
    FROM table_pasukan_atlet
    WHERE deleted_at IS NULL
    GROUP BY pasukan_id
) a ON a.pasukan_id = p.id

WHERE k.deleted_at IS NULL
    AND k.status = 1

GROUP BY
    k.id,
    u.nama_universiti,
    k.kod_universiti,
    k.nama_pegawai_untuk_dihubungi,
    k.alamat,
    k.emel,
    k.no_telefon,
    u.status,
    k.created_at

ORDER BY k.created_at DESC
LIMIT 1000;";

          // If the logged in user is CONTINGENT, only return their own contingent
          if ($currentRole === 'CONTINGENT' && $currentKontinjenId) {
                $stmt = $pdo->prepare($sql . " AND k.id = :kontinjen_id");
                $stmt->execute([':kontinjen_id' => (int)$currentKontinjenId]);
          } else {
                $stmt = $pdo->query($sql);
          }
          $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stats = ['total' => count($rows), 'active' => count($rows), 'inactive' => 0];

        echo json_encode([
                'success' => true,
                'data' => $rows,
                'stats' => $stats
        ]);
} catch (Exception $e) {
    error_log('[ajax/contingent_list] ' . $e->getMessage() . " in " . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    $msg = (defined('DEBUG_MODE') && DEBUG_MODE) ? $e->getMessage() : 'Unable to load the contingent list.';
    echo json_encode([
        'success' => false,
        'message' => $msg,
        'data' => [],
        'stats' => ['total' => 0, 'active' => 0, 'inactive' => 0]
    ]);
    exit;
}

