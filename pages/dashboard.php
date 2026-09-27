<?php
/**
 * Dashboard Page (improved layout)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';

$page_title = 'Dashboard';
// gather summary statistics
$summary = [
    'kontinjen' => 0,
    'sukan' => 0,
    'pasukan' => 0,
    'acara_selesai' => 0,
    'universiti' => 0,
    'venues' => 0,
    'atlet' => 0,
    'pengurus' => 0,
    'jurulatih' => 0,
];
$recent = ['contingents' => [], 'teams' => []];
try {
    $db = getDB();
    // counts
    $row = $db->query("SELECT COUNT(*) AS c FROM table_kontinjen k JOIN table_ref_universiti r ON k.kod_universiti = r.kod_universiti WHERE k.deleted_at IS NULL AND r.status = 1")->fetch(PDO::FETCH_ASSOC);
    $summary['kontinjen'] = (int)($row['c'] ?? 0);

    $row = $db->query("SELECT COUNT(*) AS c FROM table_sukan WHERE deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC);
    $summary['sukan'] = (int)($row['c'] ?? 0);

    $row = $db->query("SELECT COUNT(*) AS c FROM table_pasukan WHERE deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC);
    $summary['pasukan'] = (int)($row['c'] ?? 0);

    // acara_selesai placeholder — count events if table exists
    try {
        $r = $db->query("SELECT COUNT(*) AS c FROM table_event WHERE deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC);
        $summary['acara_selesai'] = (int)($r['c'] ?? 0);
    } catch (Exception $e) { /* ignore if no table_event */ }

    // table_ref_universiti does not have `deleted_at`; use `status` to count active records
    try {
        $row = $db->query("SELECT COUNT(*) AS c FROM table_ref_universiti WHERE status = 1")->fetch(PDO::FETCH_ASSOC);
        $summary['universiti'] = (int)($row['c'] ?? 0);
    } catch (Exception $e) {
        $summary['universiti'] = 0;
    }

    $row = $db->query("SELECT COUNT(*) AS c FROM table_ref_venues WHERE deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC);
    $summary['venues'] = (int)($row['c'] ?? 0);

    // Count distinct athletes (MyKad) only from active universities; ignore deleted/non-athlete roles
    $sqlAtlet = "
        SELECT COUNT(DISTINCT pa.no_kad_pengenalan) AS c
        FROM table_pasukan_atlet pa
        INNER JOIN table_pasukan p ON pa.pasukan_id = p.id AND p.deleted_at IS NULL AND p.status = 1
        INNER JOIN table_kontinjen k ON p.kontinjen_id = k.id AND k.deleted_at IS NULL AND k.status = 1
        INNER JOIN table_ref_universiti u ON k.kod_universiti = u.kod_universiti AND u.status = 1
        WHERE pa.deleted_at IS NULL
          AND pa.no_kad_pengenalan IS NOT NULL
          AND pa.no_kad_pengenalan <> ''
    ";
    $row = $db->query($sqlAtlet)->fetch(PDO::FETCH_ASSOC);
    $summary['atlet'] = (int)($row['c'] ?? 0);
    $row = $db->query("SELECT COUNT(*) AS c FROM table_pasukan_pengurus WHERE deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC);
    $summary['pengurus'] = (int)($row['c'] ?? 0);
    $row = $db->query("SELECT COUNT(*) AS c FROM table_pasukan_jurulatih WHERE deleted_at IS NULL")->fetch(PDO::FETCH_ASSOC);
    $summary['jurulatih'] = (int)($row['c'] ?? 0);

    // recent contingents and recent teams
    $stmt = $db->prepare("SELECT k.id, k.kod_universiti, k.nama_pegawai_untuk_dihubungi, k.emel, k.created_at FROM table_kontinjen k JOIN table_ref_universiti r ON k.kod_universiti = r.kod_universiti WHERE k.deleted_at IS NULL AND r.status = 1 ORDER BY k.created_at DESC LIMIT 6");
    $stmt->execute(); $recent['contingents'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT p.id, p.nama_pasukan, p.kontinjen_id, k.kod_universiti, p.created_at FROM table_pasukan p LEFT JOIN table_kontinjen k ON p.kontinjen_id = k.id WHERE p.deleted_at IS NULL ORDER BY p.created_at DESC LIMIT 6");
    $stmt->execute(); $recent['teams'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Medal ranking using Olympic/SEA Games rules: Gold, then Silver, then Bronze (ties share rank)
    $medalRows = [];
    try {
        // Count medals for both team and individual winners. Participant IDs in standings
        // may reference either table_pasukan.id (team) or table_pasukan_atlet.id (individual).
        $sqlMedal = "
            SELECT
                base.kod_universiti,
                base.nama_pendek,
                COALESCE(mc.emas, 0)   AS emas,
                COALESCE(mc.perak, 0)  AS perak,
                COALESCE(mc.gangsa, 0) AS gangsa
            FROM (
                SELECT DISTINCT
                    k.kod_universiti,
                    COALESCE(r.nama_pendek, k.kod_universiti) AS nama_pendek
                FROM table_kontinjen k
                JOIN table_ref_universiti r ON r.kod_universiti = k.kod_universiti
                WHERE k.deleted_at IS NULL AND k.status = 1 AND r.status = 1
            ) base
            LEFT JOIN (
                SELECT
                    COALESCE(kp.kod_universiti, ka.kod_universiti) AS kod_universiti,
                    SUM(CASE WHEN jt.position = 1 THEN 1 ELSE 0 END) AS emas,
                    SUM(CASE WHEN jt.position = 2 THEN 1 ELSE 0 END) AS perak,
                    SUM(CASE WHEN jt.position = 3 THEN 1 ELSE 0 END) AS gangsa
                FROM table_results tr
                JOIN JSON_TABLE(tr.standings, '$[*]' COLUMNS(
                    position INT PATH '$.position',
                    participant_id VARCHAR(255) PATH '$.participant_id'
                )) jt ON jt.position IN (1,2,3)
                /* try to resolve participant as a team */
                LEFT JOIN table_pasukan p ON p.id = CAST(jt.participant_id AS UNSIGNED) AND p.deleted_at IS NULL
                LEFT JOIN table_kontinjen kp ON kp.id = p.kontinjen_id AND kp.deleted_at IS NULL AND kp.status = 1
                /* try to resolve participant as an individual athlete (pa -> pasukan -> kontinjen) */
                LEFT JOIN table_pasukan_atlet pa ON pa.id = CAST(jt.participant_id AS UNSIGNED) AND pa.deleted_at IS NULL
                LEFT JOIN table_pasukan p2 ON p2.id = pa.pasukan_id AND p2.deleted_at IS NULL
                LEFT JOIN table_kontinjen ka ON ka.id = p2.kontinjen_id AND ka.deleted_at IS NULL AND ka.status = 1
                WHERE tr.deleted_at IS NULL AND tr.status = 'completed'
                GROUP BY COALESCE(kp.kod_universiti, ka.kod_universiti)
            ) mc ON mc.kod_universiti = base.kod_universiti
            ORDER BY emas DESC, perak DESC, gangsa DESC, base.nama_pendek ASC
        ";

        $mStmt = $db->query($sqlMedal);
        $rows = $mStmt ? $mStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $ranked = [];
        $position = 0; // sequential ranking (no repeats even if tied)
        foreach ($rows as $row) {
            $position++;
            $row['rank'] = $position;
            $row['jumlah'] = (int)$row['emas'] + (int)$row['perak'] + (int)$row['gangsa'];
            $ranked[] = $row;
        }
        $medalRows = $ranked;
    } catch (Exception $e) {
        error_log('[dashboard medal rank] ' . $e->getMessage());
        $medalRows = [];
    }

    // Optional debug helper: append diagnostic info when requested via ?debug_medal=1
    $medalDebug = null;
    if (isset($_GET['debug_medal']) && $_GET['debug_medal']) {
        try {
            $cntRow = $db->query("SELECT COUNT(*) AS c FROM table_results WHERE deleted_at IS NULL AND status = 'completed'")->fetch(PDO::FETCH_ASSOC);
            $latestRow = $db->query("SELECT id, tarikh, status, created_at, COALESCE(CHAR_LENGTH(standings),0) AS standings_len FROM table_results WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $sample = $db->query("SELECT id, status, created_at, SUBSTRING(standings,1,400) AS standings_sample FROM table_results WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
            $medalDebug = [
                'completed_count' => (int)($cntRow['c'] ?? 0),
                'latest' => $latestRow ?: null,
                'recent_samples' => $sample ?: []
            ];
            error_log('[dashboard debug_medal] ' . json_encode($medalDebug));
            // If debug requested, include sample of medal query results
            if (!empty($medalDebug) && !empty($rows)) {
                $medalDebug['medal_query_count'] = count($rows);
                $medalDebug['medal_query_sample'] = array_slice($rows, 0, 12);
            } else if (!empty($medalDebug)) {
                $medalDebug['medal_query_count'] = 0;
                $medalDebug['medal_query_sample'] = [];
            }
                    // Additional mapping diagnostics: resolve participant_id -> kod_universiti for latest result
                    if (!empty($latestRow['id'])) {
                        try {
                            $mapSql = "
                                SELECT tr.id AS result_id, jt.position, jt.participant_id,
                                       kp.kod_universiti AS team_kod_universiti,
                                       ka.kod_universiti AS atlet_kod_universiti
                                FROM table_results tr
                                JOIN JSON_TABLE(tr.standings, '$[*]' COLUMNS(
                                    position INT PATH '$.position',
                                    participant_id VARCHAR(255) PATH '$.participant_id'
                                )) jt ON 1=1
                                LEFT JOIN table_pasukan p ON p.id = CAST(jt.participant_id AS UNSIGNED) AND p.deleted_at IS NULL
                                LEFT JOIN table_kontinjen kp ON kp.id = p.kontinjen_id AND kp.deleted_at IS NULL
                                LEFT JOIN table_pasukan_atlet pa ON pa.id = CAST(jt.participant_id AS UNSIGNED) AND pa.deleted_at IS NULL
                                LEFT JOIN table_pasukan p2 ON p2.id = pa.pasukan_id AND p2.deleted_at IS NULL
                                LEFT JOIN table_kontinjen ka ON ka.id = p2.kontinjen_id AND ka.deleted_at IS NULL
                                WHERE tr.id = :rid
                                ORDER BY jt.position ASC
                            ";
                            $mStmt = $db->prepare($mapSql);
                            $mStmt->execute([':rid' => $latestRow['id']]);
                            $maps = $mStmt->fetchAll(PDO::FETCH_ASSOC);
                            $medalDebug['latest_mapping'] = $maps;
                        } catch (Exception $e) {
                            $medalDebug['latest_mapping_error'] = $e->getMessage();
                        }
                    }
        } catch (Exception $e) {
            error_log('[dashboard debug_medal] ' . $e->getMessage());
            $medalDebug = ['error' => $e->getMessage()];
        }
    }

} catch (Exception $e) {
    error_log('[dashboard] summary fetch error: ' . $e->getMessage());
}

ob_start();
?>
<div class="w-100 dashboard-view">
    <style>
        /* Neumorphic statistic cards */
        .neo-card{
            background: #f4f7fb;
            border: none;
            border-radius: 12px;
            box-shadow: 6px 6px 14px rgba(16,24,40,0.06), -6px -6px 14px rgba(255,255,255,0.8);
        }
        .neo-card .card-body{ background: transparent; }
        .neo-card .rounded-circle{ box-shadow: inset 2px 2px 6px rgba(0,0,0,0.06); }
        .neo-card .card-footer .text-muted.small{ font-size:0.85rem; }
        .stat-icon{ font-size:1.6rem; line-height:1; }
        /* Compact ranking table styles (reduced padding to avoid scrolling) */
        .medal-table-card { border-radius:8px; overflow:hidden; }
        .medal-table-card .card-header { 
            background: linear-gradient(180deg, #ffffff 0%, #f6f9ff 100%);
            border-bottom:1px solid rgba(14, 21, 47, 0.06);
            padding:.36rem .6rem;
            box-shadow: 0 6px 18px rgba(14,21,47,0.04);
            border-top-left-radius:8px;
            border-top-right-radius:8px;
        }
        /* Make the whole ranking box follow the neo-card appearance */
        .medal-table-card.neo-card { background: #f4f7fb; box-shadow: 6px 6px 14px rgba(16,24,40,0.06), -6px -6px 14px rgba(255,255,255,0.8); border: none; }
        .medal-table { width:100%; border-collapse:separate; border-spacing:0; }
        .medal-table thead th { background: #fbfdff; border-bottom: 1px solid #eef2f7; color:#172554; font-weight:700; font-size:0.82rem; padding:.3rem .4rem; text-transform:uppercase; letter-spacing:0.02em; white-space:nowrap; }
        .medal-table tbody tr { transition: background .08s ease; }
        .medal-table tbody tr:hover { background: #fbfdff; }
        /* Subtle zebra and highlighted backgrounds for ranking rows */
        .medal-table tbody tr:nth-child(odd) { background: rgba(15,23,42,0.02); }
        .medal-table tbody tr.top-1 { background: linear-gradient(90deg, #fff7e6 0%, #fff3d1 100%); }
        .medal-table tbody tr.top-2 { background: linear-gradient(90deg, #f6f9ff 0%, #eef6ff 100%); }
        .medal-table tbody tr.top-3 { background: linear-gradient(90deg, #fff7f0 0%, #fff2ea 100%); }
        .medal-table tbody tr.top-1 .medal-name, .medal-table tbody tr.top-1 .medal-count { color:#7a4300; }
        .medal-table tbody tr.top-2 .medal-name, .medal-table tbody tr.top-2 .medal-count { color:#0b5ed7; }
        .medal-table tbody tr.top-3 .medal-name, .medal-table tbody tr.top-3 .medal-count { color:#8b5a2b; }
        .medal-table td, .medal-table th { padding: .26rem .35rem; vertical-align: middle; font-size: .85rem; }
        .medal-rank { display:inline-flex; align-items:center; justify-content:center; min-width:28px; height:20px; border-radius:6px; background:#f1f5f9; color:#0f172a; font-weight:700; font-size:.78rem; }
        .medal-name { font-weight:600; color:#0f1724; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:10rem; font-size:.85rem; }
        .kontinjen-logo{width:28px;height:18px;object-fit:contain;border-radius:4px;margin-right:8px;flex:0 0 auto}
        .medal-count { display:inline-block; min-width:32px; padding:0.16rem 0.4rem; border-radius:6px; background:#e5e7eb; color:#0f172a; font-weight:700; font-size:.85rem; }
        .medal-badge { display:inline-block; min-width:28px; padding:.14rem .35rem; border-radius:6px; text-align:center; background:#eef2f7; color:#0b5ed7; font-weight:700; font-size:.85rem; }
        .medal-table .btn.medal-detail-btn { font-weight:700; padding:0; font-size:.85rem; }
        .medal-table .btn.medal-detail-btn:focus { box-shadow:none; }
        .medal-table-small { font-size:.78rem; color:#6b7280; }
        /* Modal positioning: top-centered without overlaying header */
        .modal-top .modal-dialog { margin-top: 60px; margin-bottom: 20px; }
        /* Lock body scroll when modal open */
        body.modal-open { overflow: hidden; padding-right: 0 !important; }
        /* Sponsors grid (below medal tally) */
        .sponsors-section { margin-top:12px; padding:12px; background:linear-gradient(180deg,#ffffff,#fbfdff); border-radius:8px; border:1px solid rgba(14,21,47,0.04); }
        .sponsors-section h6 { margin:0 0 8px 0; font-size:0.92rem; color:#1f2937; }
        .sponsors-grid { display:flex; flex-wrap:wrap; gap:10px; align-items:center; }
        .sponsors-grid img { height:40px; width:auto; object-fit:contain; border-radius:6px; background:#fff; padding:6px; box-shadow:0 6px 14px rgba(16,24,40,0.04); }
    </style>
    <link rel="stylesheet" href="<?php echo asset('css/dashboard.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/dashboard.css'); ?>">
    <div class="row align-items-center mb-4 dashboard-heading">
        <div class="col">
            <div class="dashboard-eyebrow">SAM 2026 · MANAGEMENT CENTRE</div><h1 class="h3 mb-0">Dashboard</h1>
            <p class="text-muted small mb-0">An overview of participation and contingent achievements.</p>
        </div>
        <!-- Quick action buttons removed as requested -->
    </div>

    <div class="row g-3 align-items-stretch dashboard-grid">
        <div class="col-lg-8 d-flex flex-column dashboard-main">
            <div class="row g-3 mb-3 dashboard-stats">
                <div class="col-6 col-sm-6 col-lg-3">
                    <div class="card neo-card h-100">
                        <div class="card-body d-flex flex-column align-items-center justify-content-center text-center">
                            <i class="zmdi zmdi-flag stat-icon text-primary mb-2"></i>
                            <div class="text-muted small">Contingent</div>
                            <div class="fs-4 fw-bold"><?php echo number_format($summary['kontinjen']); ?></div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-sm-6 col-lg-3">
                    <div class="card neo-card h-100">
                        <div class="card-body d-flex flex-column align-items-center justify-content-center text-center">
                            <i class="fa fa-trophy stat-icon text-success mb-2"></i>
                            <div class="text-muted small">Sport</div>
                            <div class="fs-4 fw-bold"><?php echo number_format($summary['sukan']); ?></div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-sm-6 col-lg-3">
                    <div class="card neo-card h-100">
                        <div class="card-body d-flex flex-column align-items-center justify-content-center text-center">
                            <i class="zmdi zmdi-accounts stat-icon text-info mb-2"></i>
                            <div class="text-muted small">Team</div>
                            <div class="fs-4 fw-bold"><?php echo number_format($summary['pasukan']); ?></div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-sm-6 col-lg-3">
                    <div class="card neo-card h-100">
                        <div class="card-body d-flex flex-column align-items-center justify-content-center text-center">
                            <i class="zmdi zmdi-run stat-icon text-warning mb-2"></i>
                            <div class="text-muted small">Total Athletes</div>
                            <div class="fs-4 fw-bold"><?php echo number_format($summary['atlet']); ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm h-100 dashboard-activity">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div><strong>Recent Registrations</strong><p class="dashboard-panel-note">Recently registered contingent officials</p></div>
                    <span class="dashboard-chip"><?php echo count($recent['contingents']); ?> latest records</span>
                </div>
                <div class="card-body">
                    <?php if (!empty($recent['contingents'])): ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($recent['contingents'] as $rc): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-start">
                                <div>
                                    <span class="dashboard-university"><?php echo htmlspecialchars($rc['kod_universiti'], ENT_QUOTES, 'UTF-8'); ?></span><div class="fw-semibold dashboard-person"><?php echo htmlspecialchars($rc['nama_pegawai_untuk_dihubungi'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars($rc['emel'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                                <div class="small text-muted dashboard-date"><?php $registeredAt = strtotime($rc['created_at'] ?? ''); echo $registeredAt ? date('d/m/Y · H:i', $registeredAt) : '—'; ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                        <div class="text-muted">No activity yet.</div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <div class="col-lg-4 d-flex dashboard-ranking">
                <div class="card w-100 h-100 medal-table-card neo-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div><strong>Contingent Standings</strong><p class="dashboard-panel-note">Ranked by gold, silver and bronze medals</p></div>
                    <span class="dashboard-chip"><i class="fa fa-trophy" aria-hidden="true"></i> Medals</span>
                </div>
                    <div class="card-body p-1">
                    <?php if (!empty($medalRows)): ?>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0 medal-table">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Contingent</th>
                                        <th class="text-center"><i class="cil cil-star text-warning me-1"></i>Gold</th>
                                        <th class="text-center"><i class="cil cil-star text-secondary me-1"></i>Silver</th>
                                        <th class="text-center" style="width:5rem;"><i class="cil cil-star text-danger me-1"></i>Bronze</th>
                                        <th class="text-center" style="width:5rem;">Total</th>
                                    </tr>
                                </thead>
                        <tbody>
                             <?php foreach ($medalRows as $mr): ?>
                                 <?php $r = (int)($mr['rank'] ?? 0); $rowClass = ($r==1? 'top-1' : ($r==2? 'top-2' : ($r==3? 'top-3' : ''))); ?>
                                 <tr class="<?php echo $rowClass; ?>" data-kod="<?php echo htmlspecialchars($mr['kod_universiti'], ENT_QUOTES, 'UTF-8'); ?>" data-kontinjen="<?php echo htmlspecialchars($mr['nama_pendek'] ?? ($mr['kod_universiti'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>">
                                    <td class="align-middle">
                                        <?php if ($r === 1): ?>
                                            <span class="medal-rank" style="background:linear-gradient(135deg,#ffedb3,#ffd44f); color:#7a4300;"><?php echo $r; ?></span>
                                        <?php elseif ($r === 2): ?>
                                            <span class="medal-rank" style="background:linear-gradient(135deg,#e6ebf5,#cfd6e6); color:#2d3748;"><?php echo $r; ?></span>
                                        <?php elseif ($r === 3): ?>
                                            <span class="medal-rank" style="background:linear-gradient(135deg,#ffe6d3,#f7b98a); color:#7a3b1a;"><?php echo $r; ?></span>
                                        <?php else: ?>
                                            <span class="medal-rank"><?php echo $r; ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="align-middle">
                                        <div style="display:flex;align-items:center;">
                                            <img src="<?php echo asset('img/logos/UA/' . strtoupper(trim($mr['kod_universiti'] ?? '')) . '.svg'); ?>" alt="" class="kontinjen-logo" onerror="this.style.display='none'" />
                                            <div class="medal-name" title="<?php echo htmlspecialchars($mr['nama_pendek'] ?? ($mr['kod_universiti'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($mr['nama_pendek'] ?? ($mr['kod_universiti'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></div>
                                        </div>
                                    </td>
                                    <td class="text-center"><button type="button" class="btn btn-link p-0 text-warning medal-detail-btn" data-medal="emas"><?php echo (int)($mr['emas'] ?? 0); ?></button></td>
                                    <td class="text-center"><button type="button" class="btn btn-link p-0 text-secondary medal-detail-btn" data-medal="perak"><?php echo (int)($mr['perak'] ?? 0); ?></button></td>
                                    <td class="text-center"><button type="button" class="btn btn-link p-0 text-danger medal-detail-btn" data-medal="gangsa"><?php echo (int)($mr['gangsa'] ?? 0); ?></button></td>
                                                                        <td class="text-center"><button type="button" class="btn btn-link p-0 medal-detail-btn text-dark" data-medal="all"><?php echo (int)($mr['jumlah'] ?? 0); ?></button></td>
                                 </tr>
                             <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-muted small p-3">No medal data.</div>
            <?php endif; ?>
            <?php if (!empty($medalDebug)): ?>
                <div class="p-2 mt-2 bg-light small" style="border-radius:6px;">
                    <strong>Debug Medal:</strong>
                    <pre style="white-space:pre-wrap; word-break:break-word; font-size:0.78rem; margin:6px 0 0 0;"><?php echo htmlspecialchars(json_encode($medalDebug, JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8'); ?></pre>
                </div>
            <?php endif; ?>
            <!-- Sponsors removed from dashboard; shown on public medal standings page only -->
        </div>
    </div>
</div>
<div class="modal fade modal-top" id="medalDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Medal Recipients</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="fw-semibold" id="medalDetailTitle">Medal Recipients <span id="medalDetailBadge" class="badge" style="margin-left:.4rem;"></span> <span id="medalDetailName" style="margin-left:.6rem;"></span></div>
                    <div>
                                <select class="form-select form-select-sm" id="medalDetailPageSize" style="width:90px;">
                                    <option value="5" selected>5</option>
                                    <option value="10">10</option>
                                    <option value="25">25</option>
                                    <option value="100">100</option>
                                </select>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th style="width:3%">#</th>
                                <th style="width:40%">Name</th>
                                <th style="width:15%">Contingent</th>
                                <th style="width:20%">Sport</th>
                                <th style="width:22%">Event</th>
                            </tr>
                        </thead>
                        <tbody id="medalDetailBody">
                            <tr><td colspan="5" class="text-center text-muted">Loading...</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <div class="text-muted small" id="medalDetailSummary"></div>
                    <nav aria-label="Medal pagination">
                        <ul class="pagination pagination-sm mb-0" id="medalDetailPager"></ul>
                    </nav>
                </div>
            </div>
        </div>
    </div>
</div>
<style>
    /* Hide page-size control but keep it in DOM so JS reads default */
    #medalDetailPageSize { display: none !important; }
    /* Reduce modal height to suit default pageSize=5 (slightly smaller) */
    #medalDetailModal .modal-content { height: 420px; max-height: calc(100vh - 12vh); display:flex; flex-direction:column; }
    @media (max-height: 700px) { #medalDetailModal .modal-content { height: calc(100vh - 12vh); } }

    /* Keep pager and summary anchored to bottom of modal */
    #medalDetailModal .modal-body { display:flex; flex-direction:column; padding: 1rem; flex:1 1 auto; }
    #medalDetailModal .modal-body .table-responsive { flex:1 1 auto; overflow:auto; }
    #medalDetailModal .modal-body > .d-flex.mt-2 { flex:0 0 auto; margin-top:8px; }
    /* Ensure header row doesn't collapse */
    #medalDetailModal .modal-body > .d-flex.mb-2 { flex:0 0 auto; }
</style>
<script>
(function(){
    function whenJQ(cb){ if (window.jQuery){ cb(window.jQuery); } else { setTimeout(function(){ whenJQ(cb); }, 50); } }
    whenJQ(function($){
        var medalRowsCache = [];
        var pageSize = parseInt($('#medalDetailPageSize').val(), 10) || 5;
        var currentPage = 1;
        var currentMedalDashboard = '';

        function renderPager(total){
            var totalPages = Math.max(1, Math.ceil(total / pageSize));
            if (currentPage > totalPages) currentPage = totalPages;
            var $pager = $('#medalDetailPager');
            $pager.empty();
            var add = function(label, page, disabled, active){
                var li = $('<li class="page-item">');
                if (disabled) li.addClass('disabled');
                if (active) li.addClass('active');
                var a = $('<a class="page-link" href="#">').text(label).data('page', page);
                li.append(a); $pager.append(li);
            };
            add('«', currentPage-1, currentPage===1, false);
            var start = Math.max(1, currentPage-2), end = Math.min(totalPages, start+4);
            for (var p=start; p<=end; p++){ add(p, p, false, p===currentPage); }
            add('»', currentPage+1, currentPage===totalPages, false);
            var startRow = total === 0 ? 0 : (currentPage-1)*pageSize + 1;
            var endRow = Math.min(total, currentPage*pageSize);
            $('#medalDetailSummary').text(total === 0 ? 'No data' : ('Showing '+startRow+' - '+endRow+' of '+total));
        }

        function renderDetailTable(){
            var $body = $('#medalDetailBody');
            if (!medalRowsCache.length){
                $body.html('<tr><td colspan="5" class="text-center text-muted">No recipient records.</td></tr>');
                renderPager(0); return;
            }
            var start = (currentPage-1)*pageSize;
            var end = start + pageSize;
            var pageRows = medalRowsCache.slice(start, end);
            var html = '';
            pageRows.forEach(function(r, idx){
                var firstCell = '';
                if (String(currentMedalDashboard).toLowerCase() === 'all'){
                    var m = String(r.medal || '').toLowerCase();
                    var icon = '';
                    if (m === 'emas') icon = '🥇';
                    else if (m === 'perak') icon = '🥈';
                    else if (m === 'gangsa') icon = '🥉';
                    else icon = (start+idx+1);
                    firstCell = '<td>' + icon + '</td>';
                } else {
                    firstCell = '<td>' + (start+idx+1) + '</td>';
                }

                html += '<tr>' + firstCell +
                        '<td>'+(r.nama || r.nama_pasukan || '-')+'</td>'+
                        '<td>'+(r.nama_kontinjen || r.kod_universiti || '-')+'</td>'+
                        '<td>'+(r.nama_sukan || '-')+'</td>'+
                        '<td>'+(r.nama_kategori || '-')+'</td>'+
                    '</tr>';
            });
            $body.html(html);
            renderPager(medalRowsCache.length);
        }

        $('#medalDetailPager').on('click', 'a.page-link', function(e){
            e.preventDefault();
            var p = $(this).data('page');
            if (!p) return;
            currentPage = parseInt(p,10) || 1;
            renderDetailTable();
        });

        $('#medalDetailPageSize').on('change', function(){
            pageSize = parseInt($(this).val(),10) || 10;
            currentPage = 1;
            renderDetailTable();
        });

        $(document).on('click', '.medal-detail-btn', function(){
            var $tr = $(this).closest('tr');
            var kod = $tr.data('kod');
            var name = $tr.data('kontinjen') || kod;
            var medal = $(this).data('medal');
            currentMedalDashboard = String(medal).toLowerCase();
            if (!kod || !medal) return;
            $('#medalDetailName').text(name);
            // set single badge next to title indicating selected medal
            var $badge = $('#medalDetailBadge');
            $badge.text(medal.toUpperCase());
            if (medal === 'emas') { $badge.css({'background':'#ffd700','color':'#000'}); }
            else if (medal === 'perak') { $badge.css({'background':'#c0c0c0','color':'#000'}); }
            else if (medal === 'gangsa') { $badge.css({'background':'#cd7f32','color':'#fff'}); }
            $('#medalDetailBody').html('<tr><td colspan="5" class="text-center text-muted">Loading...</td></tr>');
            $('#medalDetailSummary').text('');
            // reset pagination to defaults each time modal opens (fixed to 5)
            pageSize = parseInt($('#medalDetailPageSize').val(), 10) || 5;
            $('#medalDetailPageSize').val(String(pageSize));
            medalRowsCache = []; currentPage = 1;
            $('#medalDetailModal').modal('show');
            if (String(medal).toLowerCase() === 'all') {
                // public endpoint returns medal type per row
                $.ajax({ url: '<?php echo url('ajax/medal_recipients_public.php'); ?>', data: { kod_universiti: kod, medal: 'all' }, dataType: 'json' })
                .done(function(res){
                    if (!res || res.status !== 'ok' || !Array.isArray(res.data)){
                        $('#medalDetailBody').html('<tr><td colspan="5" class="text-center text-danger">Unable to load data</td></tr>');
                        $('#medalDetailSummary').text('Unable to load data');
                        return;
                    }
                    medalRowsCache = res.data || [];
                    renderDetailTable();
                }).fail(function(){
                    $('#medalDetailBody').html('<tr><td colspan="5" class="text-center text-danger">Unable to load data</td></tr>');
                    $('#medalDetailSummary').text('Unable to load data');
                });
            } else {
                $.ajax({
                    url: '<?php echo url('ajax/medal_recipients.php'); ?>',
                    data: { kod_universiti: kod, medal: medal },
                    dataType: 'json'
                }).done(function(res){
                    if (!res || res.status !== 'ok') {
                        $('#medalDetailBody').html('<tr><td colspan="5" class="text-center text-danger">Unable to load data</td></tr>');
                        $('#medalDetailSummary').text('Unable to load data');
                        return;
                    }
                    medalRowsCache = res.data || [];
                    renderDetailTable();
                }).fail(function(){
                    $('#medalDetailBody').html('<tr><td colspan="5" class="text-center text-danger">Unable to load data</td></tr>');
                    $('#medalDetailSummary').text('Unable to load data');
                });
            }

        // Ensure body doesn't scroll when modal is open (extra guard)
        $('#medalDetailModal').on('shown.bs.modal', function(){
            try{ document.body.style.overflow = 'hidden'; }catch(e){}
        });
        $('#medalDetailModal').on('hidden.bs.modal', function(){
            try{ document.body.style.overflow = ''; }catch(e){}
        });
        });
    });
})();
    </script>
    </div>

</div>

<?php
    $content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>
