<?php
/**
 * Report Summary
 * Access: ADMIN, ORGANIZER, VIEWER
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/rbac.php';

Session::start();
$auth = getAuth();
$auth->requireAuth();
$rbac = getRBAC();
// Enforce page-specific access (VIEWER allowed via RBAC rules)
$rbac->requirePageAccess('pages/ringkasan.php');

$page_title = 'Report Summary';

// Fetch summary of athletes per university + gender
$rows = [];
try {
    $db = getDB();
    $sql = "WITH cleaned AS (
        SELECT DISTINCT
            REPLACE(pa.no_kad_pengenalan, '-', '') AS ic_clean,
            CASE WHEN MOD(CAST(RIGHT(REPLACE(pa.no_kad_pengenalan, '-', ''), 1) AS UNSIGNED), 2) = 0 THEN 'WANITA' ELSE 'LELAKI' END AS gender,
            u.kod_universiti,
            u.nama_universiti
        FROM table_pasukan_atlet pa
        JOIN table_pasukan p ON p.id = pa.pasukan_id AND p.deleted_at IS NULL AND p.status = 1
        JOIN table_kontinjen k ON k.id = p.kontinjen_id AND k.deleted_at IS NULL AND k.status = 1
        JOIN table_ref_universiti u ON u.kod_universiti = k.kod_universiti AND u.status = 1
        WHERE pa.deleted_at IS NULL
          AND pa.no_kad_pengenalan IS NOT NULL
          AND TRIM(pa.no_kad_pengenalan) <> ''
    )
    SELECT
        kod_universiti,
        nama_universiti,
        SUM(gender = 'LELAKI') AS lelaki,
        SUM(gender = 'WANITA') AS wanita
    FROM cleaned
    GROUP BY kod_universiti, nama_universiti
    ORDER BY nama_universiti";

    $stmt = $db->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('[ringkasan] DB error: ' . $e->getMessage());
    $rows = [];
}

// Compute totals
$totals = ['LELAKI' => 0, 'WANITA' => 0];
foreach ($rows as $r) {
    $totals['LELAKI'] += (int)($r['lelaki'] ?? 0);
    $totals['WANITA'] += (int)($r['wanita'] ?? 0);
}

$unis = [];
try {
    $db = getDB();
    $sqlUnis = "SELECT kod_universiti, nama_pendek, nama_universiti FROM table_ref_universiti WHERE status = 1 ORDER BY COALESCE(NULLIF(nama_pendek,''), nama_universiti)";
    $stUn = $db->query($sqlUnis);
    $rowsUn = $stUn->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rowsUn as $u) {
        $kod = trim((string)($u['kod_universiti'] ?? ''));
        $short = trim((string)($u['nama_pendek'] ?? ''));
        $full = trim((string)($u['nama_universiti'] ?? ''));
        $display = $short !== '' ? $short : ($full !== '' ? $full : $kod);
        if ($kod === '') continue;
        $unis[] = ['kod_universiti' => $kod, 'nama_universiti' => $display];
    }
    if (empty($unis)) throw new Exception('No universities from ref table');
} catch (Exception $e) {
    // Fallback static list (legacy) — short codes only
    $unis = [
        ['kod_universiti' => 'KMS',   'nama_universiti' => 'KMS'],
        ['kod_universiti' => 'APM',   'nama_universiti' => 'APM'],
        ['kod_universiti' => 'UIAM',  'nama_universiti' => 'UIAM'],
        ['kod_universiti' => 'UKM',   'nama_universiti' => 'UKM'],
        ['kod_universiti' => 'UM',    'nama_universiti' => 'UM'],
        ['kod_universiti' => 'UMK',   'nama_universiti' => 'UMK'],
        ['kod_universiti' => 'UMS',   'nama_universiti' => 'UMS'],
        ['kod_universiti' => 'UNIMAS','nama_universiti' => 'UNIMAS'],
        ['kod_universiti' => 'UMT',   'nama_universiti' => 'UMT'],
        ['kod_universiti' => 'UPNM',  'nama_universiti' => 'UPNM'],
        ['kod_universiti' => 'UPM',   'nama_universiti' => 'UPM'],
        ['kod_universiti' => 'USIM',  'nama_universiti' => 'USIM'],
        ['kod_universiti' => 'UniSZA','nama_universiti' => 'UniSZA'],
        ['kod_universiti' => 'UiTM',  'nama_universiti' => 'UiTM'],
        ['kod_universiti' => 'UUM',   'nama_universiti' => 'UUM'],
    ];
}
// Tab 2 data: per-sport, per-gender counts by university (fixed order)
$sportMatrix = [];
$colTotals = [];
// build a lookup map for university codes using uppercase keys to avoid case mismatches
$unis_map = [];
foreach ($unis as $u) {
    $k = strtoupper($u['kod_universiti']);
    $unis_map[$k] = $u['kod_universiti'];
    $colTotals[$k] = 0;
}

// --------------------------------------------------
// AJAX endpoint: managers list by kontinjen/kod_universiti
// Returns JSON: [{ acara, pengurus, jurulatih, no_hp }, ...]
if (isset($_GET['ajax']) && $_GET['ajax'] === 'managers') {
    $kod = strtoupper(trim((string)($_GET['kod'] ?? '')));
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'rows' => []];
    try {
        $db = getDB();
        // Retrieve managers/coaches from dedicated child tables and aggregate per team
        $sql = "
            SELECT
                COALESCE(s.nama_sukan, '') AS acara,
                COALESCE(r.nama_pendek, r.nama_universiti, k.kod_universiti) AS kontinjen,
                TRIM(
                    COALESCE(
                        GROUP_CONCAT(DISTINCT TRIM(COALESCE(pp.nama, '')) SEPARATOR ' ||| '),
                        ''
                    )
                ) AS pengurus,
                TRIM(
                    COALESCE(
                        GROUP_CONCAT(DISTINCT TRIM(COALESCE(j.nama, '')) SEPARATOR ' ||| '),
                        ''
                    )
                ) AS jurulatih
            FROM table_pasukan p
            LEFT JOIN table_kontinjen k ON k.id = p.kontinjen_id
            LEFT JOIN table_ref_universiti r ON r.kod_universiti = k.kod_universiti AND r.status = 1
            LEFT JOIN table_sukan s ON s.id = p.sukan_id
            LEFT JOIN table_pasukan_pengurus pp ON pp.pasukan_id = p.id AND pp.deleted_at IS NULL
            LEFT JOIN table_pasukan_jurulatih j ON j.pasukan_id = p.id AND j.deleted_at IS NULL
            WHERE (:kod_empty = '' OR UPPER(COALESCE(k.kod_universiti, '')) = :kod_val)
              AND p.deleted_at IS NULL
            GROUP BY p.id, s.nama_sukan, k.kod_universiti, r.nama_pendek
            ORDER BY s.nama_sukan, p.id
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([':kod_empty' => $kod, ':kod_val' => $kod]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out['ok'] = true;
        $out['rows'] = $rows;
    } catch (Exception $e) {
        $out['error'] = $e->getMessage();
    }
    echo json_encode($out);
    exit;
}
// AJAX endpoint: statistik per kontinjen
if (isset($_GET['ajax']) && $_GET['ajax'] === 'statistik') {
        $kod = strtoupper(trim((string)($_GET['kod'] ?? '')));
        header('Content-Type: application/json; charset=utf-8');
        $out = ['ok' => false, 'summary' => [], 'events' => []];
    try {
        $db = getDB();
        // Total acara disertai (distinct sports for teams from this kontinjen)
                $sql = "SELECT COUNT(DISTINCT COALESCE(p.sukan_id,0)) AS cnt FROM table_pasukan p JOIN table_kontinjen k ON k.id = p.kontinjen_id WHERE (:kod_empty = '' OR UPPER(COALESCE(k.kod_universiti,'')) = :kod_val) AND p.deleted_at IS NULL AND p.status = 1";
                $st = $db->prepare($sql); $st->execute([':kod_empty' => $kod, ':kod_val' => $kod]); $r = $st->fetch(PDO::FETCH_ASSOC);
        $acara_cnt = (int)($r['cnt'] ?? 0);

        // Total managers (unique individuals)
        // Use normalized name as unique identifier for pengurus (no IC field in pengurus table)
        $sql = "SELECT COUNT(DISTINCT NULLIF(LOWER(TRIM(pp.nama)),'') ) AS cnt
            FROM table_pasukan_pengurus pp
            JOIN table_pasukan p ON p.id = pp.pasukan_id
            JOIN table_kontinjen k ON k.id = p.kontinjen_id
            WHERE (:kod_empty = '' OR UPPER(COALESCE(k.kod_universiti,'')) = :kod_val)
              AND pp.deleted_at IS NULL
              AND p.deleted_at IS NULL";
        $st = $db->prepare($sql); $st->execute([':kod_empty' => $kod, ':kod_val' => $kod]); $r = $st->fetch(PDO::FETCH_ASSOC);
        $pengurus_cnt = (int)($r['cnt'] ?? 0);

        // Total coaches (unique individuals)
        // Use normalized name as unique identifier for jurulatih
        $sql = "SELECT COUNT(DISTINCT NULLIF(LOWER(TRIM(j.nama)),'') ) AS cnt
            FROM table_pasukan_jurulatih j
            JOIN table_pasukan p ON p.id = j.pasukan_id
            JOIN table_kontinjen k ON k.id = p.kontinjen_id
            WHERE (:kod_empty = '' OR UPPER(COALESCE(k.kod_universiti,'')) = :kod_val)
              AND j.deleted_at IS NULL
              AND p.deleted_at IS NULL";
        $st = $db->prepare($sql); $st->execute([':kod_empty' => $kod, ':kod_val' => $kod]); $r = $st->fetch(PDO::FETCH_ASSOC);
        $jurulatih_cnt = (int)($r['cnt'] ?? 0);

        // Total athletes (unique individuals)
        // Prefer IC (no_kad_pengenalan), fallback to normalized name when IC is NULL/empty
        $sql = "SELECT COUNT(DISTINCT COALESCE(NULLIF(REPLACE(TRIM(pa.no_kad_pengenalan),'-',''),''), NULLIF(LOWER(TRIM(pa.nama)),'') ) ) AS cnt
            FROM table_pasukan_atlet pa
            JOIN table_pasukan p ON p.id = pa.pasukan_id
            JOIN table_kontinjen k ON k.id = p.kontinjen_id
            WHERE (:kod_empty = '' OR UPPER(COALESCE(k.kod_universiti,'')) = :kod_val)
              AND pa.deleted_at IS NULL
              AND p.deleted_at IS NULL";
        $st = $db->prepare($sql); $st->execute([':kod_empty' => $kod, ':kod_val' => $kod]); $r = $st->fetch(PDO::FETCH_ASSOC);
        $atlet_cnt = (int)($r['cnt'] ?? 0);

        // Per-event participation counts (number of athletes per sport)
                $sql = "SELECT COALESCE(s.nama_sukan, 'No Berlabel') AS acara, COUNT(pa.id) AS peserta_cnt
                                FROM table_pasukan p
                                LEFT JOIN table_sukan s ON s.id = p.sukan_id
                                LEFT JOIN table_pasukan_atlet pa ON pa.pasukan_id = p.id AND pa.deleted_at IS NULL
                                LEFT JOIN table_kontinjen k ON k.id = p.kontinjen_id
                    WHERE (:kod_empty = '' OR UPPER(COALESCE(k.kod_universiti,'')) = :kod_val)
                                    AND p.deleted_at IS NULL
                                GROUP BY s.id, s.nama_sukan
                                ORDER BY peserta_cnt DESC, acara ASC";
                $st = $db->prepare($sql); $st->execute([':kod_empty' => $kod, ':kod_val' => $kod]); $events = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $out['ok'] = true;
        $out['summary'] = [
            'jumlah_acara' => $acara_cnt,
            'jumlah_pengurus' => $pengurus_cnt,
            'jumlah_jurulatih' => $jurulatih_cnt,
            'jumlah_atlet' => $atlet_cnt,
        ];
        $out['events'] = $events;
    } catch (Exception $e) {
        $out['error'] = $e->getMessage();
    }
    echo json_encode($out);
    exit;
}
try {
    $db = getDB();

    // Include team-only entries (teams without athlete rows) so team sports appear
    // Also collect participant names per group using GROUP_CONCAT
    $sql = "WITH cleaned AS (
        SELECT DISTINCT
            REPLACE(pa.no_kad_pengenalan, '-', '') AS ic_clean,
            CASE WHEN RIGHT(REPLACE(pa.no_kad_pengenalan, '-', ''),1) REGEXP '[02468]' THEN 'WANITA' ELSE 'LELAKI' END AS gender,
            u.kod_universiti,
            u.nama_universiti,
            s.nama_sukan AS sukan,
            pa.nama AS peserta_nama
        FROM table_pasukan_atlet pa
        JOIN table_pasukan p ON p.id = pa.pasukan_id AND p.deleted_at IS NULL AND p.status = 1
        JOIN table_kontinjen k ON k.id = p.kontinjen_id AND k.deleted_at IS NULL AND k.status = 1
        JOIN table_ref_universiti u ON u.kod_universiti = k.kod_universiti AND u.status = 1
        LEFT JOIN table_sukan s ON s.id = p.sukan_id
        WHERE pa.deleted_at IS NULL
          AND pa.no_kad_pengenalan IS NOT NULL
          AND TRIM(pa.no_kad_pengenalan) <> ''
    ), teams_only AS (
        SELECT
            NULL AS ic_clean,
            'PASUKAN' AS gender,
            u.kod_universiti,
            u.nama_universiti,
            s.nama_sukan AS sukan,
            NULL AS peserta_nama
        FROM table_pasukan p
        LEFT JOIN table_pasukan_atlet pa ON pa.pasukan_id = p.id AND pa.deleted_at IS NULL
        JOIN table_kontinjen k ON k.id = p.kontinjen_id AND k.deleted_at IS NULL AND k.status = 1
        JOIN table_ref_universiti u ON u.kod_universiti = k.kod_universiti AND u.status = 1
        LEFT JOIN table_sukan s ON s.id = p.sukan_id
        WHERE p.deleted_at IS NULL
          AND pa.id IS NULL
          AND p.status = 1
    ), combined AS (
        SELECT * FROM cleaned
        UNION ALL
        SELECT * FROM teams_only
    )
    SELECT
        sukan,
        gender,
        kod_universiti,
        COUNT(*) AS cnt,
        GROUP_CONCAT(DISTINCT COALESCE(peserta_nama,'') SEPARATOR '|||') AS nama_list
    FROM combined
    GROUP BY sukan, gender, kod_universiti
    ORDER BY sukan, gender, kod_universiti";

    $stmt = $db->query($sql);
    $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $namesMatrix = [];
    foreach ($res as $r) {
        // Normalize sport name: remove control/formatting characters, collapse whitespace
        $raw_sukan = (string)($r['sukan'] ?? '');
        $sukan = preg_replace('/[\x00-\x1F\x7F]+/u', '', $raw_sukan); // remove control chars
        $sukan = preg_replace('/\p{Cf}/u', '', $sukan); // remove format chars (zero-width etc)
        $sukan = preg_replace('/\s+/u', ' ', trim($sukan));
        if ($sukan === '') { $sukan = 'No Berlabel'; }
        $gender = strtoupper($r['gender'] ?? 'LELAKI');
        $kod = strtoupper(trim((string)($r['kod_universiti'] ?? '')));
        $cnt = (int)($r['cnt'] ?? 0);
        $nama_list = $r['nama_list'] ?? '';
        $names = [];
        if ($nama_list !== '') {
            $parts = explode('|||', $nama_list);
            foreach ($parts as $p) {
                $pn = trim($p);
                if ($pn !== '') $names[] = $pn;
            }
        }
        if (!isset($sportMatrix[$sukan])) {
            $sportMatrix[$sukan] = ['LELAKI' => [], 'WANITA' => [], 'PASUKAN' => []];
        }
        $sportMatrix[$sukan][$gender][$kod] = $cnt;
        $namesMatrix[$sukan][$gender][$kod] = $names;
        if (isset($colTotals[$kod])) {
            $colTotals[$kod] += $cnt;
        } else {
            // if kod not in predefined list, still keep tally under its uppercase key
            $colTotals[$kod] = ($colTotals[$kod] ?? 0) + $cnt;
        }
    }
    ksort($sportMatrix);
} catch (Exception $e) {
    error_log('[ringkasan tab2] DB error: ' . $e->getMessage());
    $sportMatrix = [];
}
// Compute athlete-only and team-only column totals (keys are uppercase)
$colTotalsAthletes = [];
$colTotalsTeams = [];
foreach ($colTotals as $k => $v) { $colTotalsAthletes[$k] = 0; $colTotalsTeams[$k] = 0; }
foreach ($sportMatrix as $sname => $genders) {
    foreach (['LELAKI','WANITA'] as $g) {
        $counts = $genders[$g] ?? [];
        foreach ($counts as $kod => $c) {
            $kod = strtoupper($kod);
            $colTotalsAthletes[$kod] = ($colTotalsAthletes[$kod] ?? 0) + (int)$c;
        }
    }
    // teams
    $counts = $genders['PASUKAN'] ?? [];
    foreach ($counts as $kod => $c) {
        $kod = strtoupper($kod);
        $colTotalsTeams[$kod] = ($colTotalsTeams[$kod] ?? 0) + (int)$c;
    }
}

ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/reports-summary.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/reports-summary.css'); ?>">
<div class="container-fluid px-3 reports-summary">
    <div class="row mb-3">
        <div class="col-12">
            <div class="reports-eyebrow">SAM 2026 &middot; PARTICIPATION REPORTS</div><h2 class="mb-1">Report Summary</h2>
            <p class="text-muted mb-0">A quick overview of athletes by university and gender.</p>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <!-- Tabs: Pill with Icon style (from light/elements-tabs.html) -->
            <ul class="nav nav-pills mb-3" id="pillTab" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="tab-atlet" data-bs-toggle="pill" data-bs-target="#pane-atlet" type="button" role="tab" aria-controls="pane-atlet" aria-selected="true">
                        <i class="cil-people me-1"></i> Athlete Summary
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-acara" data-bs-toggle="pill" data-bs-target="#pane-acara" type="button" role="tab" aria-controls="pane-acara" aria-selected="false">
                        <i class="cil-list-rich me-1"></i> Event Summary
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-managers" data-bs-toggle="pill" data-bs-target="#pane-managers" type="button" role="tab" aria-controls="pane-managers" aria-selected="false">
                        <i class="cil-user me-1"></i> Managers & Coaches
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="tab-statistik" data-bs-toggle="pill" data-bs-target="#pane-statistik" type="button" role="tab" aria-controls="pane-statistik" aria-selected="false">
                        <i class="cil-chart-line me-1"></i> Event Statistics
                    </button>
                </li>
            </ul>
            <div class="tab-content" id="pillTabContent">
                <!-- Tab 1: Athlete Summary -->
                <div class="tab-pane fade show active" id="pane-atlet" role="tabpanel" aria-labelledby="tab-atlet">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:5%;" class="bil-col text-center">No.</th>
                                    <th style="width:65%;">University</th>
                                    <th style="width:10%;" class="text-start">Male</th>
                                    <th style="width:10%;" class="text-start">Female</th>
                                    <th style="width:10%;" class="text-start">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($rows)): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-4">No data to display.</td></tr>
                                <?php else: ?>
                                    <?php $bil = 1; ?>
                                    <?php foreach ($rows as $row):
                                        $lelaki = (int)($row['lelaki'] ?? 0);
                                        $wanita = (int)($row['wanita'] ?? 0);
                                        $jumlah = $lelaki + $wanita;
                                    ?>
                                        <tr>
                                            <td class="text-center"><?php echo $bil++; ?></td>
                                            <td><?php echo htmlspecialchars($row['nama_universiti'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td class="text-start text-primary fw-semibold"><?php echo number_format($lelaki); ?></td>
                                            <td class="text-start text-danger fw-semibold"><?php echo number_format($wanita); ?></td>
                                            <td class="text-start fw-bold"><?php echo number_format($jumlah); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th></th>
                                    <th class="text-end">Grand Total</th>
                                    <th class="text-start text-primary fw-semibold"><?php echo number_format($totals['LELAKI']); ?></th>
                                    <th class="text-start text-danger fw-semibold"><?php echo number_format($totals['WANITA']); ?></th>
                                    <th class="text-start fw-bold"><?php echo number_format($totals['LELAKI'] + $totals['WANITA']); ?></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <!-- Tab 2: Event Summary -->
                <div class="tab-pane fade" id="pane-acara" role="tabpanel" aria-labelledby="tab-acara">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th style="width:3%;" class="bil-col text-center">No.</th>
                                    <th style="width:12%;">Event</th>
                                    <th style="width:7%;">Gender</th>
                                    <?php foreach ($unis as $uni): ?>
                                        <th style="width:5%;" class="text-end"><?php echo htmlspecialchars($uni['kod_universiti'] ?? $uni['nama_universiti'], ENT_QUOTES, 'UTF-8'); ?></th>
                                    <?php endforeach; ?>
                                    <th style="width:3%;" class="text-end">∑</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($sportMatrix)): ?>
                                    <tr><td colspan="<?php echo 4 + count($unis); ?>" class="text-center py-4"><span class="badge badge-outline badge-info">None</span></td></tr>
                                <?php else: ?>
                                    <?php $bilAcara = 1; ?>
                                    <?php foreach ($sportMatrix as $sukan => $genders): ?>
                                        <?php
                                            $genderOrder = ['LELAKI','WANITA'];
                                            $firstNonEmptyIndex = null;
                                            foreach ($genderOrder as $gi => $gg) {
                                                $tmp = $genders[$gg] ?? [];
                                                if (array_sum(array_values($tmp)) > 0) { $firstNonEmptyIndex = $gi; break; }
                                            }
                                        ?>
                                        <?php foreach ($genderOrder as $idxGender => $g): ?>
                                            <?php
                                                $counts = $genders[$g] ?? [];
                                                $hasData = array_sum(array_values($counts)) > 0;
                                                if (!$hasData) { continue; }
                                            ?>
                                            <tr>
                                                <td class="text-center"><?php echo $idxGender === $firstNonEmptyIndex ? $bilAcara : ''; ?></td>
                                                <td><?php echo $idxGender === $firstNonEmptyIndex ? htmlspecialchars($sukan, ENT_QUOTES, 'UTF-8') : ''; ?></td>
                                                <td class="text-end"><?php echo ($g === 'LELAKI') ? '<i class="cil-male me-1"></i>Male' : '<i class="cil-child me-1"></i>Female'; ?></td>
                                                <?php $rowSum = 0; ?>
                                                <?php foreach ($unis as $uni): ?>
                                                    <?php
                                                        $kodKey = strtoupper($uni['kod_universiti']);
                                                        $val = (int)($counts[$kodKey] ?? $counts[$uni['kod_universiti']] ?? 0);
                                                        $rowSum += $val;
                                                        $cellNames = $namesMatrix[$sukan][$g][$kodKey] ?? [];
                                                        if (!empty($cellNames)) {
                                                            $escaped = array_map(function($n){ return htmlspecialchars($n, ENT_QUOTES, 'UTF-8'); }, $cellNames);
                                                            $namesHtml = '<div style="text-align:left">' . implode('<br>', $escaped) . '</div>';
                                                            $tooltipAttr = ' data-names-html="' . htmlspecialchars($namesHtml, ENT_QUOTES, 'UTF-8') . '" data-bs-toggle="names-tooltip" data-bs-html="true"';
                                                        } else {
                                                            $tooltipAttr = '';
                                                        }
                                                    ?>
                                                    <td class="text-end"<?php echo $tooltipAttr; ?>><?php echo $val === 0 ? '<span class="badge badge-outline badge-info">None</span>' : number_format($val); ?></td>
                                                <?php endforeach; ?>
                                                <td class="text-end fw-bold"><?php echo number_format($rowSum); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        <?php $bilAcara++; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                            <?php if (!empty($sportMatrix) && !empty($unis)): ?>
                                <?php
                                    // compute per-column totals for displayed genders
                                    $colTotalsDisplayed = [];
                                    foreach ($unis as $u) { $colTotalsDisplayed[strtoupper($u['kod_universiti'])] = 0; }
                                    foreach ($sportMatrix as $sname => $genders) {
                                        foreach (['LELAKI','WANITA'] as $g) {
                                            $counts = $genders[$g] ?? [];
                                            foreach ($counts as $kod => $c) {
                                                $kod = strtoupper($kod);
                                                $colTotalsDisplayed[$kod] = ($colTotalsDisplayed[$kod] ?? 0) + (int)$c;
                                            }
                                        }
                                    }
                                ?>
                            <tfoot class="table-light">
                                <tr>
                                    <th colspan="3" class="text-end">Total</th>
                                    <?php foreach ($unis as $uni): ?>
                                        <?php $kodKey = strtoupper($uni['kod_universiti']); $val = (int)($colTotalsDisplayed[$kodKey] ?? 0); ?>
                                        <th class="text-end fw-bold"><?php echo number_format($val); ?></th>
                                    <?php endforeach; ?>
                                    <th></th>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>

                <!-- Tab 3: Managers & Coaches -->
                <div class="tab-pane fade" id="pane-managers" role="tabpanel" aria-labelledby="tab-managers">
                    <div class="mb-3 d-flex align-items-center gap-3">
                        <div>
                            <h5 class="mb-0">Managers &amp; Coaches</h5>
                            <p class="text-muted mb-0">Managers and coaches by contingent/university.</p>
                        </div>
                        <div class="ms-auto d-flex align-items-end gap-2">
                            <div>
                                <label for="selKontinjen" class="form-label small mb-1">Select Contingent</label>
                                <select id="selKontinjen" class="form-select form-select-sm">
                                    <option value="">-- All Contingents --</option>
                                    <?php foreach ($unis as $u): ?>
                                        <option value="<?php echo htmlspecialchars(strtoupper($u['kod_universiti']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($u['nama_universiti'], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="d-flex align-items-end">
                                <button id="btnPrintManagers" type="button" class="btn btn-sm btn-outline-primary" title="Print Managers & Coaches">
                                    <i class="cil-print me-1"></i> Print
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table id="managersTable" class="table table-sm table-hover align-middle" style="table-layout:fixed;">
                            <thead class="table-light">
                                        <tr>
                                            <th style="width:5%;" class="bil-col text-center">NO.</th>
                                            <th style="width:10%;">CONTINGENTS</th>
                                            <th style="width:15%;">EVENTS</th>
                                            <th style="width:35%;">Manager</th>
                                            <th style="width:35%;">Coach</th>
                                        </tr>
                            </thead>
                            <tbody>
                                        <tr><td colspan="5" class="text-center text-muted py-4">Select a contingent to view its information.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tab 4: Event Statistics -->
                <div class="tab-pane fade" id="pane-statistik" role="tabpanel" aria-labelledby="tab-statistik">
                    <div class="mb-3 d-flex align-items-center gap-3">
                        <div>
                            <h5 class="mb-0">Event Statistics</h5>
                            <p class="text-muted mb-0">Participation statistics and charts by contingent.</p>
                        </div>
                        <div class="ms-auto d-flex align-items-end gap-2">
                            <div>
                                <label for="selKontinjenStats" class="form-label small mb-1">Select Contingent</label>
                                <select id="selKontinjenStats" class="form-select form-select-sm">
                                    <option value="">-- All Contingents --</option>
                                    <?php foreach ($unis as $u): ?>
                                        <option value="<?php echo htmlspecialchars(strtoupper($u['kod_universiti']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($u['nama_universiti'], ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="d-flex align-items-end">
                                <button id="btnPrintStats" type="button" class="btn btn-sm btn-outline-primary" title="Print Event Statistics">
                                    <i class="cil-print me-1"></i> Print
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <div id="statsSummary" class="d-flex gap-3 flex-wrap">
                            <!-- summary cards inserted here -->
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <h6 class="card-title">Participation by Event</h6>
                            <div id="statsChart" style="min-height:180px;">
                                <!-- simple bar chart will be rendered here -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
            </div>
        </div>
    </div>
</div>
<style>
/* Wider, left-aligned tooltips for participant name lists */
.tooltip .tooltip-inner {
    max-width: 640px; /* increase as needed */
    white-space: normal;
    text-align: left;
}
/* Make all table headers left-aligned on this page */
table.table thead th {
    text-align: left !important;
}
/* But center the Bil column header specifically */
table.table thead th.bil-col {
    text-align: center !important;
}
/* Tab 2 (Event Summary): header from Gender until total should be right-aligned */
#pane-acara table thead th:nth-child(n+3) {
    text-align: right !important;
}
/* Keep Bil in Tab 2 centered */
#pane-acara table thead th.bil-col {
    text-align: center !important;
}
/* Tab 3 (Managers & Coaches): all table data top-aligned */
#pane-managers #managersTable tbody td {
    vertical-align: top !important;
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function(){
    // Initialize custom name tooltips (left-justified, multi-line)
    var els = document.querySelectorAll('[data-bs-toggle="names-tooltip"]');
    els.forEach(function(el){
        var html = el.getAttribute('data-names-html') || '';
        // Use Bootstrap Tooltip API
        try {
            var tip = new bootstrap.Tooltip(el, {title: html, html: true, sanitize: false, placement: 'top'});
        } catch (e) {
            console.error('Tooltip init failed', e);
        }
    });
    // Managers tab: fetch and render DataTable
    var managersTableEl = document.getElementById('managersTable');
    var selKont = document.getElementById('selKontinjen');
    var btnPrint = document.getElementById('btnPrintManagers');
    var managersDt = null;

    // Persist active tab across reloads/transactions using sessionStorage
    var tabStorageKey = 'ringkasan.activeTab';
    function saveActiveTab(tabId) {
        try { sessionStorage.setItem(tabStorageKey, tabId); } catch (e) { /* ignore */ }
    }
    function restoreActiveTab() {
        try {
            var tabId = sessionStorage.getItem(tabStorageKey);
            if (!tabId) return;
            // try multiple strategies to find the tab trigger
            var btn = document.getElementById(tabId) || document.querySelector('[data-bs-target="#' + tabId + '"]') || document.querySelector('[href="#' + tabId + '"]') || document.querySelector('[aria-controls="' + tabId + '"]');
            if (!btn) {
                // it may be that stored value is a trigger id; try to locate a trigger that targets this id
                btn = document.querySelector('[data-bs-target="#' + tabId.replace(/^#/, '') + '"]') || document.querySelector('[href="#' + tabId.replace(/^#/, '') + '"]');
            }
            if (!btn) return;
            // Delay slightly to ensure Bootstrap tab system is initialized
            setTimeout(function(){ try { var tabObj = new bootstrap.Tab(btn); tabObj.show(); } catch (e) { /* ignore */ } }, 50);
        } catch (e) { /* ignore */ }
    }

    // Attach listener to save the tab when user switches
    var tabButtons = document.querySelectorAll('#pillTab [data-bs-toggle="pill"]');
    tabButtons.forEach(function(tb){
        try {
            tb.addEventListener('shown.bs.tab', function(e){
                if (e && e.target) {
                    // prefer saving the trigger id; fallback to target pane id
                    var idToSave = e.target.id || (e.target.getAttribute('data-bs-target') || e.target.getAttribute('href') || '').replace(/^#/, '');
                    if (idToSave) saveActiveTab(idToSave);
                }
            });
        } catch (e) { /* ignore */ }
        tb.addEventListener('click', function(){ var idToSave = this.id || (this.getAttribute('data-bs-target') || this.getAttribute('href') || '').replace(/^#/, ''); if (idToSave) saveActiveTab(idToSave); });
    });

    function renderManagers(rows) {
        var tbody = managersTableEl.querySelector('tbody');
        tbody.innerHTML = '';
            if (!rows || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">No data found.</td></tr>';
            return;
        }
        rows.forEach(function(r, idx){
            var tr = document.createElement('tr');
            var no = document.createElement('td'); no.className = 'text-center'; no.textContent = (idx+1);
            var kont = document.createElement('td'); kont.textContent = r.kontinjen || '-';
            var acara = document.createElement('td'); acara.textContent = r.acara || '-';

            // Build pengurus cell: may contain multiple entries separated by ' ||| '
            var pengurus = document.createElement('td');
            if (r.pengurus && r.pengurus.trim() !== '') {
                var parts = String(r.pengurus).split(' ||| ');
                parts.forEach(function(p) {
                    var div = document.createElement('div');
                    div.textContent = p.trim();
                    pengurus.appendChild(div);
                });
            } else {
                // show soft red badge when no pengurus
                pengurus.innerHTML = '<span class="badge bg-danger" style="opacity:.9">None</span>';
            }

            // Build jurulatih cell: may contain multiple entries separated by ' ||| '
            var jurulatih = document.createElement('td');
            if (r.jurulatih && r.jurulatih.trim() !== '') {
                var jparts = String(r.jurulatih).split(' ||| ');
                jparts.forEach(function(p) {
                    var div = document.createElement('div');
                    div.textContent = p.trim();
                    jurulatih.appendChild(div);
                });
            } else {
                // show soft red badge when no jurulatih
                jurulatih.innerHTML = '<span class="badge bg-danger" style="opacity:.9">None</span>';
            }

            tr.appendChild(no); tr.appendChild(kont); tr.appendChild(acara); tr.appendChild(pengurus); tr.appendChild(jurulatih);
            tbody.appendChild(tr);
        });
    }

    function loadManagers(kod) {
        var url = new URL(location.href);
        url.searchParams.set('ajax','managers');
        url.searchParams.set('kod', kod || '');
        // show loading
        try {
            var tbody = managersTableEl.querySelector('tbody');
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">Loading...</td></tr>';
        } catch (e) {}
        console.log('Fetching managers:', url.toString());
        fetch(url.toString(), { headers: { 'X-Requested-With':'XMLHttpRequest' } })
            .then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function(j){ console.log('Managers response:', j); if (!j) { renderManagers([]); return; } if (j.error) { console.error('Managers error:', j.error); renderManagers([]); return; } if (!j.ok) { renderManagers([]); return; } renderManagers(j.rows || []); })
            .catch(function(err){ console.error('Load managers error', err); renderManagers([]); });
    }

    function printManagers() {
        if (!managersTableEl) return alert('No schedules to print.');
        var kontText = '';
        if (selKont) {
            var opt = selKont.options[selKont.selectedIndex];
            if (opt) kontText = opt.textContent || opt.innerText || '';
        }
        var clone = managersTableEl.cloneNode(true);
        clone.querySelectorAll('[data-bs-toggle]').forEach(function(el){ el.removeAttribute('data-bs-toggle'); el.removeAttribute('data-names-html'); });

        var html = '<!doctype html><html><head><meta charset="utf-8"><title>Print Managers & Coaches</title>' +
            '<style>body{font-family:Arial,Helvetica,sans-serif;font-size:13px;padding:10px}h3{margin:0 0 8px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:6px;vertical-align:top}th{text-align:center;background:#f8f9fa}</style>' +
            '</head><body>';
        html += '<h3>Managers & Coaches</h3>';
        if (kontText) html += '<p><strong>Contingent:</strong> ' + kontText + '</p>';
        html += clone.outerHTML;
        html += '</body></html>';

        // Create hidden iframe to print without opening new tab
        var iframe = document.createElement('iframe');
        iframe.style.position = 'fixed';
        iframe.style.right = '0';
        iframe.style.bottom = '0';
        iframe.style.width = '0';
        iframe.style.height = '0';
        iframe.style.border = '0';
        iframe.style.overflow = 'hidden';
        document.body.appendChild(iframe);
        var idoc = iframe.contentDocument || iframe.contentWindow.document;
        idoc.open(); idoc.write(html); idoc.close();

        // Attempt to print once content is ready
        var tryPrint = function() {
            try {
                iframe.contentWindow.focus();
                iframe.contentWindow.print();
            } catch (e) {
                console.error('Print failed', e);
            }
            setTimeout(function(){ if (iframe.parentNode) iframe.parentNode.removeChild(iframe); }, 600);
        };

        // Use onload when available, otherwise fallback timeout
        if (iframe.onload !== undefined) {
            iframe.onload = tryPrint;
            // Some browsers may not fire onload for about:blank writes, so also fallback
            setTimeout(tryPrint, 800);
        } else {
            setTimeout(tryPrint, 500);
        }
    }

    if (selKont) {
        selKont.addEventListener('change', function(){ loadManagers(this.value); });
    }
    if (btnPrint) {
        btnPrint.addEventListener('click', function(){ printManagers(); });
    }
    // Auto-load all kontinjen on first load (empty kod = all)
    loadManagers('');

    // Statistik tab: elements and handlers
    var selKontStats = document.getElementById('selKontinjenStats');
    var statsSummary = document.getElementById('statsSummary');
    var statsChart = document.getElementById('statsChart');
    var btnPrintStats = document.getElementById('btnPrintStats');
    // keep last fetched events so we can render a print-friendly SVG
    var lastStatsEvents = [];

    function renderSummary(summary) {
        if (!statsSummary) return;
        statsSummary.innerHTML = '';
        var items = [
            {k:'jumlah_acara', t:'Events Entered'},
            {k:'jumlah_pengurus', t:'Total Managers'},
            {k:'jumlah_jurulatih', t:'Total Coaches'},
            {k:'jumlah_atlet', t:'Total Athletes'}
        ];
        items.forEach(function(it){
            var val = summary[it.k] || 0;
            var card = document.createElement('div');
            card.className = 'p-2 border rounded bg-light text-center';
            card.style.minWidth = '140px';
            card.innerHTML = '<div class="small text-muted">'+it.t+'</div><div class="h5 mb-0">'+(Number(val).toLocaleString()||'0')+'</div>';
            statsSummary.appendChild(card);
        });
    }

    function renderChart(events) {
        if (!statsChart) return;
        statsChart.innerHTML = '';
        if (!events || events.length === 0) {
            statsChart.innerHTML = '<div class="text-center text-muted py-4">No data for the chart.</div>';
            return;
        }
        // store events for possible printing
        lastStatsEvents = Array.isArray(events) ? events.slice(0) : [];
        // compute max for scaling
        var max = 0; events.forEach(function(e){ max = Math.max(max, Number(e.peserta_cnt||0)); });
        var list = document.createElement('div');
        list.className = 'd-flex flex-column gap-2';
        events.forEach(function(e){
            var row = document.createElement('div'); row.className = 'd-flex align-items-center gap-2';
            var label = document.createElement('div'); label.style.width = '30%'; label.style.flex = '0 0 30%'; label.textContent = e.acara || '-';
            var barWrap = document.createElement('div'); barWrap.style.flex = '1 1 auto';
            var bar = document.createElement('div');
            var pct = max > 0 ? (Number(e.peserta_cnt||0) / max * 100) : 0;
            bar.style.height = '18px'; bar.style.background = '#0d6efd'; bar.style.width = pct + '%'; bar.style.borderRadius = '4px';
            var count = document.createElement('div'); count.style.minWidth = '48px'; count.style.textAlign = 'right'; count.textContent = Number(e.peserta_cnt||0).toLocaleString();
            barWrap.appendChild(bar);
            row.appendChild(label); row.appendChild(barWrap); row.appendChild(count);
            list.appendChild(row);
        });
        statsChart.appendChild(list);
    }

    // Build an inline SVG representation of the events bar chart for reliable printing
    function chartEventsToSVG(events, opts) {
        opts = opts || {};
        // nominal canvas width used for viewBox calculations; SVG will scale to container width (100%)
        var width = opts.width || 900;
        var labelWidth = opts.labelWidth || Math.round(width * 0.32);
        var barHeight = opts.barHeight || 18;
        var gap = (typeof opts.gap === 'number') ? opts.gap : 12;
        var padding = 12;
        var max = 0; events.forEach(function(e){ max = Math.max(max, Number(e.peserta_cnt||0)); });
        var innerWidth = Math.max(120, width - labelWidth - padding*2 - 60);
        var totalHeight = padding*2 + events.length * (barHeight + gap) - gap;
        var svg = [];
        // container is full width so SVG scales to page width when printed
        svg.push('<div style="width:100%;margin:8px 0;">');
        svg.push('<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="' + totalHeight + '" viewBox="0 0 ' + width + ' ' + totalHeight + '" preserveAspectRatio="xMinYMin meet" style="display:block;width:100%;height:auto">');
        svg.push('<style>.lbl{font:12px Arial,Helvetica,sans-serif;fill:#222}.cnt{font:12px Arial,Helvetica,sans-serif;fill:#000}</style>');
        var y = padding;
        events.forEach(function(e, idx){
            var cnt = Number(e.peserta_cnt||0);
            var barW = (max > 0) ? Math.round((cnt / max) * innerWidth) : 0;
            var labelText = (e.acara || '-').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
            // label
            svg.push('<text x="' + (padding) + '" y="' + (y + barHeight - 4) + '" class="lbl">' + labelText + '</text>');
            // bar background (light)
            svg.push('<rect x="' + (labelWidth) + '" y="' + y + '" width="' + innerWidth + '" height="' + barHeight + '" rx="3" ry="3" fill="#e9ecef" />');
            // bar fill
            svg.push('<rect x="' + (labelWidth) + '" y="' + y + '" width="' + barW + '" height="' + barHeight + '" rx="3" ry="3" fill="#0d6efd" />');
            // count
            svg.push('<text x="' + (width - padding) + '" y="' + (y + barHeight - 4) + '" class="cnt" text-anchor="end">' + (cnt.toLocaleString ? cnt.toLocaleString() : cnt) + '</text>');
            y += barHeight + gap;
        });
        svg.push('</svg>');
        svg.push('</div>');
        return svg.join('');
    }

    function loadStats(kod) {
        var url = new URL(location.href);
        url.searchParams.set('ajax','statistik');
        url.searchParams.set('kod', kod || '');
        // loading state
        try { if (statsSummary) statsSummary.innerHTML = '<div class="text-muted">Loading...</div>'; if (statsChart) statsChart.innerHTML = '<div class="text-muted">Loading chart...</div>'; } catch (e) {}
        console.log('Fetching statistik:', url.toString());
        fetch(url.toString(), { headers: { 'X-Requested-With':'XMLHttpRequest' } })
            .then(function(r){ if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
            .then(function(j){ console.log('Statistik response:', j); if (!j) { renderSummary({}); renderChart([]); return; } if (j.error) { console.error('Statistik error:', j.error); renderSummary({}); renderChart([]); return; } if (!j.ok) { renderSummary({}); renderChart([]); return; } renderSummary(j.summary || {}); renderChart(j.events || []); })
            .catch(function(err){ console.error('Load statistik error', err); renderSummary({}); renderChart([]); });
    }

    if (selKontStats) {
        selKontStats.addEventListener('change', function(){ loadStats(this.value); });
    }
    // Auto-load all kontinjen statistics on first load
    loadStats('');
    if (btnPrintStats) {
        btnPrintStats.addEventListener('click', function(){
            // prepare printable HTML for summary + chart
            if (!statsSummary && !statsChart) return alert('No data to print.');
            var cloneSum = statsSummary ? statsSummary.cloneNode(true) : null;
            var cloneChart = statsChart ? statsChart.cloneNode(true) : null;
            if (cloneSum) cloneSum.querySelectorAll('[data-bs-toggle]').forEach(function(el){ el.removeAttribute('data-bs-toggle'); el.removeAttribute('data-names-html'); });
            if (cloneChart) cloneChart.querySelectorAll('[data-bs-toggle]').forEach(function(el){ el.removeAttribute('data-bs-toggle'); el.removeAttribute('data-names-html'); });
            var kontText = '';
            if (selKontStats) {
                var opt = selKontStats.options[selKontStats.selectedIndex];
                if (opt) kontText = opt.textContent || opt.innerText || '';
            }
            // collect stylesheets and inline styles from parent document so printed iframe has same layout
            var cssLinks = '';
            document.querySelectorAll('link[rel="stylesheet"]').forEach(function(l){ cssLinks += '<link rel="stylesheet" href="'+(l.href||'')+'">'; });
            document.querySelectorAll('style').forEach(function(s){ cssLinks += '<style>'+ (s.innerHTML || '') +'</style>'; });
            var html = '<!doctype html><html><head><meta charset="utf-8"><title>Print Event Statistics</title>' + cssLinks +
                '<style>@page{size:A4 portrait;margin:12mm}body{font-family:Arial,Helvetica,sans-serif;font-size:13px;padding:8px;margin:0;box-sizing:border-box}h3{margin:0 0 8px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ddd;padding:6px;vertical-align:top}th{text-align:center;background:#f8f9fa}.p-2{padding:.5rem}.border{border:1px solid #dee2e6}.rounded{border-radius:.25rem}.bg-light{background:#f8f9fa}.d-flex{display:flex}.flex-column{flex-direction:column}.align-items-center{align-items:center}.gap-2{gap:.5rem}.text-center{text-align:center}/* ensure containers are full width */ .print-container{width:100% !important;max-width:100% !important}</style>' +
                '</head><body>';
            html += '<h3>Event Statistics</h3>';
            if (kontText) html += '<p><strong>Contingent:</strong> ' + kontText + '</p>';
            if (cloneSum) html += '<div class="print-container">' + cloneSum.outerHTML + '</div>';
            // prefer an inline SVG snapshot for printing so bar fills/colors always appear
            var svgHtml = '';
            try {
                if (lastStatsEvents && lastStatsEvents.length > 0) {
                    svgHtml = chartEventsToSVG(lastStatsEvents, {width:800, labelWidth:260, barHeight:18, gap:12});
                } else if (cloneChart) {
                    // fallback to DOM clone if no events cached
                    svgHtml = cloneChart.outerHTML;
                }
            } catch (e) { console.error('SVG build failed', e); svgHtml = cloneChart ? cloneChart.outerHTML : ''; }
            if (svgHtml) html += '<div class="print-container">' + svgHtml + '</div>';
            html += '</body></html>';

            var iframe = document.createElement('iframe');
            iframe.style.position = 'fixed'; iframe.style.right = '0'; iframe.style.bottom = '0'; iframe.style.width = '0'; iframe.style.height = '0'; iframe.style.border = '0'; iframe.style.overflow = 'hidden';
            document.body.appendChild(iframe);
            var idoc = iframe.contentDocument || iframe.contentWindow.document;
            idoc.open(); idoc.write(html); idoc.close();
            var tryPrint = function(){ try { iframe.contentWindow.focus(); iframe.contentWindow.print(); } catch(e){ console.error('Print failed', e); } setTimeout(function(){ if (iframe.parentNode) iframe.parentNode.removeChild(iframe); }, 600); };
            if (iframe.onload !== undefined) { iframe.onload = tryPrint; setTimeout(tryPrint, 800); } else { setTimeout(tryPrint, 500); }
        });
    }
});
</script>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
