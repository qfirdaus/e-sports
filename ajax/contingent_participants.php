<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

Session::start();
$auth = getAuth();
$auth->requireAuth();

$currentRole = Session::get('user_role') ?? '';
$sessionKontinjen = Session::get('kontinjen_id') ?? null;

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = getDB();

    // Helper: prepare/execute with detailed error context
    $runQuery = function($pdo, $sql, array $params, $label) {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (Exception $ex) {
            error_log("[contingent_participants][$label] SQL error: " . $ex->getMessage());
            error_log("[contingent_participants][$label] SQL: " . $sql);
            error_log("[contingent_participants][$label] Params: " . json_encode($params));
            throw $ex;
        }
    };

    // Receive params
    $kontinjen_id = isset($_GET['kontinjen_id']) ? (int)$_GET['kontinjen_id'] : null;
    $sukan_id = isset($_GET['sukan_id']) ? (int)$_GET['sukan_id'] : null;
    $kategori_id = (isset($_GET['kategori_id']) && $_GET['kategori_id'] !== '') ? (int)$_GET['kategori_id'] : null;
    $hasKategori = ($kategori_id !== null);

    // Enforce session scoping for CONTINGENT users
    if ($currentRole === 'CONTINGENT') {
        if (empty($sessionKontinjen)) {
            throw new Exception('Access denied: no session kontinjen');
        }
        $kontinjen_id = (int)$sessionKontinjen;
    }

    if (empty($kontinjen_id)) {
        throw new Exception('Missing kontinjen_id');
    }

    // Build base team filter using positional params to avoid placeholder mismatch
    $teamWhereParts = [
        'p.kontinjen_id = ?',
        'p.deleted_at IS NULL',
        'p.status = 1',
    ];
    $paramsBase = [$kontinjen_id];
    if (!empty($sukan_id)) {
        $teamWhereParts[] = 'p.sukan_id = ?';
        $paramsBase[] = $sukan_id;
    }
    if ($hasKategori) {
        // Limit teams to those that have athletes in the selected category (server-side enforcement)
        $teamWhereParts[] = 'EXISTS (SELECT 1 FROM table_pasukan_atlet paX WHERE paX.pasukan_id = p.id AND paX.deleted_at IS NULL AND paX.kategori_id = ?)';
        $paramsBase[] = $kategori_id;
    }
    $teamWhere = implode(' AND ', $teamWhereParts);

    // Optional gender filter derived from kategori name (Wanita/Lelaki)
    $expectedGender = null;
    if (!empty($kategori_id)) {
        $stmt = $runQuery($pdo, "SELECT nama_kategori FROM table_kategori WHERE id = :kategori_id LIMIT 1", [':kategori_id' => $kategori_id], 'kategori_lookup');
        $rowKategori = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($rowKategori && isset($rowKategori['nama_kategori'])) {
            $nk = strtolower($rowKategori['nama_kategori']);
            if (strpos($nk, 'wanita') !== false || strpos($nk, 'perempuan') !== false) {
                $expectedGender = 'F';
            } elseif (strpos($nk, 'lelaki') !== false) {
                $expectedGender = 'M';
            }
        }
    }

    // Helper to derive gender from MyKad (last digit: odd=male, even=female)
    $genderFromMyKad = function($ic){
        $digits = preg_replace('/\D+/', '', (string)$ic);
        if ($digits === '') return null;
        $last = substr($digits, -1);
        if ($last === '' || !ctype_digit($last)) return null;
        return ((int)$last % 2 === 0) ? 'F' : 'M';
    };

    // Fetch participants: athletes
    // Join `table_sukan` to include readable sport name where available.
    // Include category (acara) for athletes via table_kategori
    $sqlAtlet = "SELECT pa.*, p.nama_pasukan, p.sukan_id, s.nama_sukan, kc.nama_kategori AS nama_kategori
        FROM table_pasukan_atlet pa
        JOIN table_pasukan p ON p.id = pa.pasukan_id
        LEFT JOIN table_sukan s ON s.id = p.sukan_id
        LEFT JOIN table_kategori kc ON kc.id = pa.kategori_id
        WHERE " . $teamWhere . " AND pa.deleted_at IS NULL";
    $paramsAtlet = $paramsBase;
    if ($hasKategori) {
        $sqlAtlet .= " AND pa.kategori_id = ?";
        $paramsAtlet[] = $kategori_id;
    }
    $stmt = $runQuery($pdo, $sqlAtlet, $paramsAtlet, 'atlet');
    $atlets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch managers
    // For pengurus/jurulatih there may not be a direct kategori_id on the team,
    // so try to surface a representative nama_kategori via a subquery from athletes.
    $sqlPengurus = "SELECT pg.*, p.nama_pasukan, p.sukan_id, s.nama_sukan,
        (SELECT nama_kategori FROM table_kategori kc2 WHERE kc2.id = (
            SELECT kategori_id FROM table_pasukan_atlet pa2 WHERE pa2.pasukan_id = p.id AND pa2.deleted_at IS NULL LIMIT 1
        ) LIMIT 1) AS nama_kategori
        FROM table_pasukan_pengurus pg
        JOIN table_pasukan p ON p.id = pg.pasukan_id
        LEFT JOIN table_sukan s ON s.id = p.sukan_id
        WHERE " . $teamWhere . " AND pg.deleted_at IS NULL";
    $paramsPengurus = $paramsBase;
    if ($hasKategori) {
        $sqlPengurus .= " AND EXISTS (
              SELECT 1 FROM table_pasukan_atlet pa2
              WHERE pa2.pasukan_id = p.id AND pa2.deleted_at IS NULL AND pa2.kategori_id = ?
          )";
        $paramsPengurus[] = $kategori_id;
    }
    $stmt = $runQuery($pdo, $sqlPengurus, $paramsPengurus, 'pengurus');
    $pengurus = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch coaches
    $sqlJurulatih = "SELECT jr.*, p.nama_pasukan, p.sukan_id, s.nama_sukan,
        (SELECT nama_kategori FROM table_kategori kc3 WHERE kc3.id = (
            SELECT kategori_id FROM table_pasukan_atlet pa3 WHERE pa3.pasukan_id = p.id AND pa3.deleted_at IS NULL LIMIT 1
        ) LIMIT 1) AS nama_kategori
        FROM table_pasukan_jurulatih jr
        JOIN table_pasukan p ON p.id = jr.pasukan_id
        LEFT JOIN table_sukan s ON s.id = p.sukan_id
        WHERE " . $teamWhere . " AND jr.deleted_at IS NULL";
    $paramsJurulatih = $paramsBase;
    if ($hasKategori) {
        $sqlJurulatih .= " AND EXISTS (
              SELECT 1 FROM table_pasukan_atlet pa3
              WHERE pa3.pasukan_id = p.id AND pa3.deleted_at IS NULL AND pa3.kategori_id = ?
          )";
        $paramsJurulatih[] = $kategori_id;
    }
    $stmt = $runQuery($pdo, $sqlJurulatih, $paramsJurulatih, 'jurulatih');
    $jurulatih = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Gender filtering based on expected gender from category (if any)
    if ($expectedGender !== null) {
        $pengurus = array_values(array_filter($pengurus, function($row) use ($genderFromMyKad, $expectedGender) {
            $g = $genderFromMyKad($row['no_kad_pengenalan'] ?? '');
            return $g !== null && $g === $expectedGender;
        }));
        $jurulatih = array_values(array_filter($jurulatih, function($row) use ($genderFromMyKad, $expectedGender) {
            $g = $genderFromMyKad($row['no_kad_pengenalan'] ?? '');
            return $g !== null && $g === $expectedGender;
        }));
        $atlets = array_values(array_filter($atlets, function($row) use ($genderFromMyKad, $expectedGender) {
            $g = $genderFromMyKad($row['no_kad_pengenalan'] ?? '');
            return $g !== null && $g === $expectedGender;
        }));
    }

    // Return combined result
    echo json_encode([
        'status' => 'ok',
        'kontinjen_id' => $kontinjen_id,
        'sukan_id' => $sukan_id,
        'kategori_id' => $kategori_id,
        'atlet' => $atlets,
        'pengurus' => $pengurus,
        'jurulatih' => $jurulatih,
    ]);

} catch (Exception $e) {
    error_log('[contingent_participants] ' . $e->getMessage());
    // Return a soft error payload so client can show message without triggering AJAX fail()
    http_response_code(200);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

?>
