<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

$kod = isset($_GET['kod_universiti']) ? trim($_GET['kod_universiti']) : '';
$medal = isset($_GET['medal']) ? strtolower(trim($_GET['medal'])) : '';
$allowed = ['emas','perak','gangsa','all'];

if ($kod === '' || !in_array($medal, $allowed, true)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid parameter']);
    exit;
}

try {
    $db = getDB();
    $sql = "
        SELECT
            CASE jt.position WHEN 1 THEN 'emas' WHEN 2 THEN 'perak' WHEN 3 THEN 'gangsa' END AS medal,
            COALESCE(p.id, pa.id) AS participant_id,
            CASE WHEN p.id IS NOT NULL THEN p.nama_pasukan ELSE pa.nama END AS nama_pasukan,
            COALESCE(r.nama_pendek, k.kod_universiti) AS nama_kontinjen,
            COALESCE(k.kod_universiti, k2.kod_universiti) AS kod_universiti,
            COALESCE(s.nama_sukan, s2.nama_sukan) AS nama_sukan,
            kat.nama_kategori
        FROM table_results tr
        JOIN JSON_TABLE(tr.standings, '$[*]' COLUMNS(
            position INT PATH '$.position',
            participant_id VARCHAR(255) PATH '$.participant_id'
        )) jt ON 1=1
        /* try resolve as team */
        LEFT JOIN table_pasukan p ON p.id = jt.participant_id AND p.deleted_at IS NULL AND p.status = 1
        LEFT JOIN table_kontinjen k ON k.id = p.kontinjen_id AND k.deleted_at IS NULL AND k.status = 1
        /* try resolve as athlete -> team -> kontingen */
        LEFT JOIN table_pasukan_atlet pa ON pa.id = jt.participant_id AND pa.deleted_at IS NULL
        LEFT JOIN table_pasukan p2 ON p2.id = pa.pasukan_id AND p2.deleted_at IS NULL
        LEFT JOIN table_kontinjen k2 ON k2.id = p2.kontinjen_id AND k2.deleted_at IS NULL AND k2.status = 1
        /* prefer kontingen from team (p) else athlete's team (p2) */
        LEFT JOIN table_kontinjen kfinal ON kfinal.id = COALESCE(k.id, k2.id) AND kfinal.deleted_at IS NULL
        LEFT JOIN table_ref_universiti r ON r.kod_universiti = COALESCE(k.kod_universiti, k2.kod_universiti) AND r.status = 1
        /* sports: prefer p.sukan_id else p2.sukan_id */
        LEFT JOIN table_sukan s ON s.id = p.sukan_id
        LEFT JOIN table_sukan s2 ON s2.id = p2.sukan_id
        LEFT JOIN table_kategori kat ON kat.id = tr.kategori_id
        WHERE tr.deleted_at IS NULL AND tr.status = 'completed'
          AND COALESCE(k.kod_universiti, k2.kod_universiti) = :kod
        ";

    // If not requesting all medals, add a filter for the specific medal
    if ($medal !== 'all') {
        $sql .= "\n          AND CASE jt.position WHEN 1 THEN 'emas' WHEN 2 THEN 'perak' WHEN 3 THEN 'gangsa' END = :medal";
    }

    $sql .= "\n        ORDER BY COALESCE(s.nama_sukan, s2.nama_sukan) ASC, kat.nama_kategori ASC, nama_pasukan ASC";

    $stmt = $db->prepare($sql);
    if ($medal !== 'all') {
        $stmt->execute([':kod' => $kod, ':medal' => $medal]);
    } else {
        $stmt->execute([':kod' => $kod]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status' => 'ok', 'data' => $rows]);
} catch (Exception $e) {
    error_log('[medal_recipients_public] ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Unable to load medal recipients']);
}
