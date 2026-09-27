<?php
/**
 * Public Medal Standings - Reuses internal `medal-tally` logic but exposed publicly.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';

$page_title = 'Medal Standings';

$tally = [];
$totals = ['emas' => 0, 'perak' => 0, 'gangsa' => 0];

try {
    $db = getDB();

    $sql = "
        SELECT
            base.kod_universiti,
            base.nama_pendek,
            base.nama_universiti,
            COALESCE(mc.emas, 0)   AS emas,
            COALESCE(mc.perak, 0)  AS perak,
            COALESCE(mc.gangsa, 0) AS gangsa
        FROM (
            SELECT DISTINCT
                k.kod_universiti,
                COALESCE(r.nama_pendek, k.kod_universiti) AS nama_pendek,
                COALESCE(r.nama_universiti, r.nama_pendek, k.kod_universiti) AS nama_universiti
            FROM table_kontinjen k
            JOIN table_ref_universiti r ON r.kod_universiti = k.kod_universiti
            WHERE k.deleted_at IS NULL AND k.status = 1 AND r.status = 1
        ) base
        LEFT JOIN (
            SELECT
                k.kod_universiti,
                SUM(CASE WHEN jt.position = 1 THEN 1 ELSE 0 END) AS emas,
                SUM(CASE WHEN jt.position = 2 THEN 1 ELSE 0 END) AS perak,
                SUM(CASE WHEN jt.position = 3 THEN 1 ELSE 0 END) AS gangsa
            FROM table_results tr
            JOIN JSON_TABLE(tr.standings, '$[*]' COLUMNS(
                position INT PATH '$.position',
                participant_id VARCHAR(255) PATH '$.participant_id'
            )) jt ON jt.position IN (1,2,3)
            -- participant_id may refer to a team (table_pasukan.id) or an individual athlete (table_pasukan_atlet.id)
            LEFT JOIN table_pasukan p ON p.id = CAST(jt.participant_id AS UNSIGNED) AND p.deleted_at IS NULL
            LEFT JOIN table_pasukan_atlet pa ON pa.id = CAST(jt.participant_id AS UNSIGNED) AND pa.deleted_at IS NULL
            LEFT JOIN table_pasukan p2 ON p2.id = pa.pasukan_id AND p2.deleted_at IS NULL
            -- determine kontinjen via team.kontinjen_id or athlete->team->kontinjen
            JOIN table_kontinjen k ON k.id = COALESCE(p.kontinjen_id, p2.kontinjen_id) AND k.deleted_at IS NULL AND k.status = 1
            JOIN table_ref_universiti r ON r.kod_universiti = k.kod_universiti AND r.status = 1
            WHERE tr.deleted_at IS NULL AND tr.status = 'completed'
            GROUP BY k.kod_universiti
        ) mc ON mc.kod_universiti = base.kod_universiti
        ORDER BY emas DESC, perak DESC, gangsa DESC, base.nama_pendek ASC
    ";

    $stmt = $db->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

    $ranked = [];
    $position = 0;
    foreach ($rows as $row) {
        $position++;
        $row['rank'] = $position;
        $row['jumlah'] = (int)$row['emas'] + (int)$row['perak'] + (int)$row['gangsa'];
        $ranked[] = $row;
        $totals['emas'] += (int)$row['emas'];
        $totals['perak'] += (int)$row['perak'];
        $totals['gangsa'] += (int)$row['gangsa'];
    }
    $tally = $ranked;
} catch (Exception $e) {
    error_log('[public/medal-standings] error: ' . $e->getMessage());
    $tally = [];
}

ob_start();
// Normalize backend keys to the template's expected keys
$standings = [];
foreach ($tally as $r) {
    $standings[] = [
        // Prefer full university name where available
        'kontinjen_nama' => $r['nama_universiti'] ?? $r['nama_pendek'] ?? ($r['kontinjen_nama'] ?? ''),
        // short name for mobile view
        'nama_pendek' => $r['nama_pendek'] ?? ($r['nama_universiti'] ?? ($r['kontinjen_nama'] ?? '')),
        'kod_universiti' => $r['kod_universiti'] ?? '',
        'gold' => isset($r['emas']) ? (int)$r['emas'] : (isset($r['gold']) ? (int)$r['gold'] : 0),
        'silver' => isset($r['perak']) ? (int)$r['perak'] : (isset($r['silver']) ? (int)$r['silver'] : 0),
        'bronze' => isset($r['gangsa']) ? (int)$r['gangsa'] : (isset($r['bronze']) ? (int)$r['bronze'] : 0),
        'total' => isset($r['jumlah']) ? (int)$r['jumlah'] : (isset($r['total']) ? (int)$r['total'] : ((int)($r['emas'] ?? $r['gold'] ?? 0) + (int)($r['perak'] ?? $r['silver'] ?? 0) + (int)($r['gangsa'] ?? $r['bronze'] ?? 0))),
        'rank' => isset($r['rank']) ? (int)$r['rank'] : 0,
    ];
}
?>
<!-- Page markup continues (styles and table header are above) -->
<div class="container mt-3 mb-4 public-ranking-card medal-showcase">
        <div class="row mb-2">
            <div class="col-12 text-center medal-header">
                <div class="medal-logos mb-2">
                    <div style="display:flex;justify-content:center;align-items:center;gap:18px;">
                        <img src="../assets/img/logos/UA/kpt.png" alt="KPT" width="72" height="72" onerror="this.style.display='none'" />
                        <img src="<?php echo asset('img/logos/medal-rank.png'); ?>" alt="SAM2026" width="72" height="72" onerror="this.style.display='none'" />
                        <img src="../assets/img/logos/UA/UPNM.svg" alt="UPNM" width="72" height="72" onerror="this.style.display='none'" />
                    </div>
                </div>
                <span class="standings-kicker">SAM 2026 · CHAMPIONSHIP STANDINGS</span><h2 class="mb-0">Every medal, a moment of pride.</h2>
                <div class="text-muted small">Contingent standings ranked by gold, silver and bronze medals.</div>
            </div>
        </div>

        <div class="medal-leaders" aria-label="Top three contingents">
            <?php foreach (array_slice($standings, 0, 3) as $leader): ?>
                <div class="medal-leader leader-<?php echo (int)$leader['rank']; ?>">
                    <div class="leader-heading">
                        <span class="leader-rank">RANK <?php echo (int)$leader['rank']; ?></span>
                        <?php if ((int)$leader['rank'] === 1): ?>
                            <span class="champion-badge"><span aria-hidden="true">★</span> Congratulations, Champions!</span>
                        <?php endif; ?>
                    </div>
                    <?php if ((int)$leader['rank'] === 1): ?>
                        <span class="champion-stars" aria-hidden="true"><span>★</span><span>✦</span><span>★</span><span>✧</span><span>★</span><span>✦</span><span>★</span><span>✧</span></span>
                    <?php endif; ?>
                    <div class="leader-identity">
                        <img src="<?php echo htmlspecialchars(asset('img/logos/UA/' . strtoupper(trim($leader['kod_universiti'])) . '.svg'), ENT_QUOTES, 'UTF-8'); ?>" alt="" onerror="this.hidden=true">
                        <strong><?php echo htmlspecialchars($leader['nama_pendek'], ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <div class="leader-medals"><span><b><?php echo (int)$leader['gold']; ?></b> Gold</span><span><b><?php echo (int)$leader['silver']; ?></b> Silver</span><span><b><?php echo (int)$leader['bronze']; ?></b> Bronze</span></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="medal-section-heading"><div><h3>Overall standings</h3><p>Select a medal count to view the recipients.</p></div><span><?php echo count($standings); ?> contingents</span></div>
        <div class="row">
            <div class="col-12">
                <div class="medal-wrapper mx-auto">
                    <!-- logo moved above header -->
                    <div class="table-responsive">
                        <table class="table table-medal" id="medalTable">
                        <thead>
                            <tr>
                                <th class="col-rank text-left">No.</th>
                                <th class="col-kontinjen text-left">Contingent</th>
                                <th class="medal-col text-center" aria-label="gold">
                                    <svg class="icon-medal" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="34" height="34" aria-hidden="true">
                                        <defs>
                                            <linearGradient id="g-gold" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#FFE78A"/><stop offset="1" stop-color="#FFC107"/></linearGradient>
                                        </defs>
                                        <circle cx="24" cy="18" r="10" fill="url(#g-gold)" stroke="#D4A017" stroke-width="1"/>
                                        <path d="M14 6 L18 6 L24 18 L30 6 L34 6 L26 26 L22 26 Z" fill="#D4A017" opacity="0.9"/>
                                    </svg><span class="medal-column-label">Gold</span>
                                </th>
                                <th class="medal-col text-center" aria-label="silver">
                                    <svg class="icon-medal" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="34" height="34" aria-hidden="true">
                                        <defs>
                                            <linearGradient id="g-silver" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#F0F4F8"/><stop offset="1" stop-color="#C7CDD9"/></linearGradient>
                                        </defs>
                                        <circle cx="24" cy="18" r="10" fill="url(#g-silver)" stroke="#9AA5B2" stroke-width="1"/>
                                        <path d="M14 6 L18 6 L24 18 L30 6 L34 6 L26 26 L22 26 Z" fill="#9AA5B2" opacity="0.9"/>
                                    </svg><span class="medal-column-label">Silver</span>
                                </th>
                                <th class="medal-col text-center" aria-label="bronze">
                                    <svg class="icon-medal" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" width="34" height="34" aria-hidden="true">
                                        <defs>
                                            <linearGradient id="g-bronze" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#F3D6C0"/><stop offset="1" stop-color="#C0845A"/></linearGradient>
                                        </defs>
                                        <circle cx="24" cy="18" r="10" fill="url(#g-bronze)" stroke="#9A5C3A" stroke-width="1"/>
                                        <path d="M14 6 L18 6 L24 18 L30 6 L34 6 L26 26 L22 26 Z" fill="#9A5C3A" opacity="0.9"/>
                                    </svg><span class="medal-column-label">Bronze</span>
                                </th>
                                <th class="medal-col text-center" aria-label="total">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($standings as $row): ?>
                            <tr <?php if (in_array((int)$row['rank'], [1,2,3])) { echo 'class="rank-'.(int)$row['rank'].'"'; } ?> data-kod="<?php echo htmlspecialchars($row['kod_universiti']); ?>" data-name="<?php echo htmlspecialchars(strtolower($row['nama_pendek'] ?? $row['kontinjen_nama'])); ?>" data-gold="<?php echo (int)$row['gold']; ?>" data-silver="<?php echo (int)$row['silver']; ?>" data-bronze="<?php echo (int)$row['bronze']; ?>" data-total="<?php echo (int)$row['total']; ?>" data-rank="<?php echo (int)$row['rank']; ?>">
                                <td class="rank-cell col-rank">
                                    <?php if ((int)$row['rank'] === 1): ?>
                                        <div class="rank-icon" title="Gold">
                                            <svg width="28" height="28" viewBox="0 0 28 28" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                                <circle cx="14" cy="9" r="7" fill="#FFD54A" stroke="#D4A017" stroke-width="1" />
                                                <rect x="11" y="16" width="6" height="8" fill="#D4A017" rx="1" />
                                            </svg>
                                        </div>
                                    <?php elseif ((int)$row['rank'] === 2): ?>
                                        <div class="rank-icon" title="Silver">
                                            <svg width="28" height="28" viewBox="0 0 28 28" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                                <circle cx="14" cy="9" r="7" fill="#C7CDD9" stroke="#9AA5B2" stroke-width="1" />
                                                <rect x="11" y="16" width="6" height="8" fill="#9AA5B2" rx="1" />
                                            </svg>
                                        </div>
                                    <?php elseif ((int)$row['rank'] === 3): ?>
                                        <div class="rank-icon" title="Bronze">
                                            <svg width="28" height="28" viewBox="0 0 28 28" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                                <circle cx="14" cy="9" r="7" fill="#D8A07A" stroke="#9A5C3A" stroke-width="1" />
                                                <rect x="11" y="16" width="6" height="8" fill="#9A5C3A" rx="1" />
                                            </svg>
                                        </div>
                                    <?php else: ?>
                                        <div class="rank-text"><?php echo $row['rank']; ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="col-kontinjen">
                                    <div style="display:flex;align-items:center;">
                                        <img src="../assets/img/logos/UA/<?php echo htmlspecialchars(strtoupper(trim($row['kod_universiti'])), ENT_QUOTES, 'UTF-8'); ?>.svg" alt="" class="kontinjen-logo" onerror="this.style.display='none'" />
                                            <div class="kontinjen-name"><?php echo htmlspecialchars($row['nama_pendek'] ?? $row['kontinjen_nama']); ?></div>
                                    </div>
                                </td>
                                <td class="medal-col text-center"><button type="button" class="medal-text medal-detail-btn-public" data-medal="emas">
                                    <?php echo $row['gold']; ?></button></td>
                                <td class="medal-col text-center"><button type="button" class="medal-text medal-detail-btn-public" data-medal="perak">
                                    <?php echo $row['silver']; ?></button></td>
                                <td class="medal-col text-center"><button type="button" class="medal-text medal-detail-btn-public" data-medal="gangsa">
                                    <?php echo $row['bronze']; ?></button></td>
                                <td class="medal-col text-center"><button type="button" class="total-count medal-detail-total-public" data-medal="total"><?php echo $row['total']; ?></button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <div class="last-updated text-muted small mt-2 text-center"><?php
                        try {
                            $dt = new DateTime('now', new DateTimeZone('Asia/Kuala_Lumpur'));
                            echo 'Display dikemas kini: ' . $dt->format('d/m/Y H:i');
                        } catch (Exception $e) {
                            echo 'Display dikemas kini: ' . date('d/m/Y H:i');
                        }
                        ?></div>
                </div>
            </div>
        </div>
    </div>

    <link rel="stylesheet" href="<?php echo asset('css/public-medals.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/public-medals.css'); ?>">
    <!-- Styles (kept near content for simplicity) -->
    <style>
    :root{--accent-50:#e8f0ff;--accent-100:#cfe0ff;--accent-300:#8fb8ff;--accent-600:#2563eb;--accent-700:#1e40af;--muted:#374151;--gold:#f59e0b;--silver:#94a3b8;--bronze:#c0845a;--card-bg:#ffffff;--card-shadow:0 6px 18px rgba(2,6,23,0.06)}
    /* Centered, constrained wrapper for desktop to keep medal tally compact */
    .medal-wrapper{ max-width:680px; margin:0 auto; }
    @media (max-width:767px){ .medal-wrapper{ max-width:100%; padding:0 8px; } }
    .table-medal{width:100%;border-collapse:separate;border-spacing:0 4px;font-size:0.88rem}
    .table-medal thead th{background:transparent;border-bottom:0;padding:3px 6px 6px;color:var(--muted);font-weight:600;font-size:0.9rem;vertical-align:top}
    .table-medal tbody tr{background:var(--card-bg);border-radius:6px;box-shadow:var(--card-shadow);transition:transform .12s ease,box-shadow .12s ease}
    .table-medal tbody tr:hover{transform:translateY(-1px);box-shadow:0 6px 14px rgba(2,6,23,0.04)}
    .table-medal td{vertical-align:middle;padding:4px 6px;border:0}
    .rank-text{font-weight:400;font-size:0.82rem;color:var(--muted);display:inline-block;text-align:center}
    .medal-text{font-weight:400;color:var(--muted);display:inline-block;min-width:28px;font-size:0.92rem}
    .total-count{font-size:0.92rem;color:#111;font-weight:400}
    .kontinjen-name{font-weight:600;font-size:0.92rem}
    /* Column width and alignment enforcement */
    .table-medal thead th.col-rank, .table-medal tbody td.col-rank { width:56px; text-align:center }
    .table-medal thead th.col-kontinjen, .table-medal tbody td.col-kontinjen { width:46%; text-align:left }
    .table-medal thead th.medal-col, .table-medal tbody td.medal-col { width:9%; text-align:center }
    .input-with-icon{position:relative;display:inline-block}
    .input-with-icon .icon-search{position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;z-index:3;pointer-events:none}
    /* Controls removed: medal-search and medal-select styles not needed */
    .icon-medal{width:26px;height:26px;vertical-align:middle}
    .kontinjen-logo{width:28px;height:16px;object-fit:contain;border-radius:3px;margin-right:8px;flex:0 0 auto}
    .medal-logos{display:flex;justify-content:center;align-items:center}
    .medal-logos img{width:72px;height:72px;object-fit:contain}
    /* Top-3 row highlights */
    .table-medal tbody tr.rank-1 { background-color: rgba(255,236,179,0.36) }
    .table-medal tbody tr.rank-2 { background-color: rgba(226,232,240,0.36) }
    .table-medal tbody tr.rank-3 { background-color: rgba(236,208,193,0.36) }
    .rank-icon svg{display:block;margin:0 auto}
    </style>

    <!-- Medal detail modal (public) -->
    <style>
    /* Modal: fixed, professional layout for paginated table (vanilla CSS only) */
    /* Container positioning: horizontally centered, slightly from top */
    #medalDetailModalPublic .modal-dialog {
        position: fixed;
        left: 50%;
        transform: translateX(-50%);
        top: 6vh; /* slightly from top center */
        margin: 0;
        width: min(1200px, 98vw);
        max-width: 98vw;
        z-index: 1050;
    }

    /* Fixed modal size tuned for default page-size 5 (row ~44px); adjust as needed */
    #medalDetailModalPublic .modal-content {
        height: 480px; /* reduced height to suit 5 rows */
        max-height: calc(100vh - 12vh);
        box-shadow: 0 18px 40px rgba(2,6,23,0.18);
        border-radius: 8px;
        overflow: hidden; /* enforce internal scrolling */
        display: flex;
        flex-direction: column;
        background: #fff;
        border: 1px solid rgba(15,23,42,0.06);
        font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
    }

    /* Modal header: standard height */
    #medalDetailModalPublic .modal-header {
        flex: 0 0 auto;
        padding: 14px 18px;
        border-bottom: 1px solid rgba(15,23,42,0.04);
        background: linear-gradient(180deg, rgba(245,248,255,0.6), rgba(255,255,255,0));
    }

    /* Modal body: column layout so table area can scroll while paginator stays visible */
    #medalDetailModalPublic .modal-body {
        flex: 1 1 auto;
        padding: 12px 18px;
        display: flex;
        flex-direction: column;
    }

    /* Keep paginator area visible at bottom */
    #medalDetailModalPublic .modal-footer {
        flex: 0 0 auto;
        padding: 10px 18px;
        border-top: 1px solid rgba(15,23,42,0.04);
        background: #fff;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    /* Table layout: header sticky, table area scrolls inside the responsive wrapper */
    #medalDetailModalPublic .table-responsive { width: 100%; flex: 1 1 auto; overflow: auto; }
    #medalDetailModalPublic table { width: 100%; border-collapse: collapse; font-size: 0.95rem; min-width: 520px }
    /* Fix column widths so pagination doesn't shift layout */
    #medalDetailModalPublic table { table-layout: fixed; }
    #medalDetailModalPublic thead th:nth-child(1) { width: 6%; }
    #medalDetailModalPublic thead th:nth-child(2) { width: 34%; }
    #medalDetailModalPublic thead th:nth-child(3) { width: 20%; }
    #medalDetailModalPublic thead th:nth-child(4) { width: 20%; }
    #medalDetailModalPublic thead th:nth-child(5) { width: 20%; }
    #medalDetailModalPublic td, #medalDetailModalPublic th { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    #medalDetailModalPublic thead th {
        position: sticky;
        top: 0; /* stick to the top of modal body */
        background: #ffffff;
        z-index: 3;
        padding: 10px 12px;
        text-align: left;
        font-weight: 600;
        border-bottom: 1px solid rgba(15,23,42,0.06);
    }

    #medalDetailModalPublic tbody { }
    #medalDetailModalPublic tbody tr { border-bottom: 1px solid rgba(15,23,42,0.04); }
    #medalDetailModalPublic td, #medalDetailModalPublic th { padding: 10px 12px; vertical-align: middle }

    /* When table has many rows, modal-body provides the scroll; header remains visible */
    /* Empty state: reserve same space but show placeholder inside the scrolling area */
    #medalDetailModalPublic .empty-state {
        min-height: 360px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: #667085;
        font-size: 1rem;
        background: linear-gradient(180deg, rgba(250,251,255,0.5), rgba(255,255,255,0.5));
        border-radius: 6px;
        margin: 6px 0;
    }

    /* Responsive safety: ensure modal never overflows viewport */
    @media (max-height: 700px) {
        #medalDetailModalPublic .modal-content { height: calc(100vh - 12vh); }
    }

    @media (max-width: 520px) {
        #medalDetailModalPublic .modal-content { width: 95vw; height: calc(100vh - 10vh) }
        #medalDetailModalPublic thead th { font-size: 0.9rem }
    }
    </style>
    <style>
    /* Hide pagesize control from users but keep it in DOM so JS can read default */
    #medalDetailPageSizePublic { display: none !important; }
    </style>
    <div class="modal fade modal-top" id="medalDetailModalPublic" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xxl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Medal Recipients</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <div class="fw-semibold" id="medalDetailTitlePublic"></div>
                        <div class="badge bg-light text-dark" id="medalDetailMedalPublic"></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Contingent</th>
                                    <th>Sport</th>
                                    <th>Event</th>
                                </tr>
                            </thead>
                            <tbody id="medalDetailBodyPublic">
                                <tr><td colspan="5" class="text-center text-muted">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <div class="text-muted small" id="medalDetailSummaryPublic"></div>
                        <div class="d-flex align-items-center gap-2">
                            <select class="form-select form-select-sm" id="medalDetailPageSizePublic" style="width:80px;">
                                    <option value="5" selected>5</option>
                                    <option value="10">10</option>
                                <option value="25">25</option>
                                <option value="50">50</option>
                            </select>
                            <nav aria-label="Medal pagination">
                                <ul class="pagination pagination-sm mb-0" id="medalDetailPagerPublic"></ul>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Client-side filter and sort for medal table
    (function(){
        var table = document.getElementById('medalTable');
        var tbody = table.querySelector('tbody');
        var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));

        // Search and sort controls removed from UI; keep JS minimal

        function renderRows(list){
            tbody.innerHTML = '';
            list.forEach(function(r){ tbody.appendChild(r); });
        }

        function filterRows(q){
            if(!q){ renderRows(rows.slice()); return; }
            q = q.trim().toLowerCase();
            var filtered = rows.filter(function(r){ return r.getAttribute('data-name').indexOf(q) !== -1; });
            renderRows(filtered);
        }

        function sortRows(mode){
            var sorted = rows.slice();
            if(mode === 'rank_desc') sorted.sort(function(a,b){ return parseInt(a.getAttribute('data-rank')) - parseInt(b.getAttribute('data-rank')); });
            else if(mode === 'gold_desc') sorted.sort(function(a,b){ return parseInt(b.getAttribute('data-gold')) - parseInt(a.getAttribute('data-gold')) || parseInt(a.getAttribute('data-rank')) - parseInt(b.getAttribute('data-rank')); });
            else if(mode === 'total_desc') sorted.sort(function(a,b){ return parseInt(b.getAttribute('data-total')) - parseInt(a.getAttribute('data-total')) || parseInt(a.getAttribute('data-rank')) - parseInt(b.getAttribute('data-rank')); });
            else if(mode === 'name_asc') sorted.sort(function(a,b){ return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name')); });
            rows = sorted;
            renderRows(rows.slice());
        }

        // initial sort (default to Gold)
        sortRows('gold_desc');
    })();
    </script>

    <script>
    (function(){
        // Public medal detail modal behavior (uses jQuery + bootstrap present in layout)
        function whenJQ(cb){ if (window.jQuery){ cb(window.jQuery); } else { setTimeout(function(){ whenJQ(cb); }, 50); } }
        whenJQ(function($){
            var cache = [];
            var pageSize = parseInt($('#medalDetailPageSizePublic').val(), 10) || 10, currentPage = 1;
            var currentMedalPublic = ''; // 'emas'|'perak'|'gangsa'|'all' - used to control first-column rendering

            function renderPager(total, $pager, summaryEl){
                var totalPages = Math.max(1, Math.ceil(total / pageSize));
                if (currentPage > totalPages) currentPage = totalPages;
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
                summaryEl.text(total === 0 ? 'No records' : ('Showing '+startRow+' - '+endRow+' of '+total));
            }

            function renderPage($body, $pager, summaryEl){
                if (!cache.length){ $body.html('<tr><td colspan="5" class="text-center text-muted">No recipients.</td></tr>'); renderPager(0, $pager, summaryEl); return; }
                var start = (currentPage-1)*pageSize; var end = start + pageSize; var pageRows = cache.slice(start, end);
                var html = '';
                pageRows.forEach(function(r, idx){
                    // Determine first column content: numbering by default, but if currentMedalPublic === 'all' show medal icon per row
                    var firstCell = '';
                    if (String(currentMedalPublic).toLowerCase() === 'all'){
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
                    '<td>'+(r.nama_pasukan || '-')+'</td>'+
                    '<td>'+(String(r.nama_kontinjen || r.kod_universiti || '-').toUpperCase())+'</td>'+
                    '<td>'+(r.nama_sukan || '-')+'</td>'+
                    '<td>'+(r.nama_kategori || '-')+'</td>'+
                '</tr>'; });
                $body.html(html);
                renderPager(cache.length, $pager, summaryEl);
            }

            $('#medalDetailPagerPublic').on('click', 'a.page-link', function(e){ e.preventDefault(); var p = $(this).data('page'); if (!p) return; currentPage = parseInt(p,10) || 1; renderPage($('#medalDetailBodyPublic'), $('#medalDetailPagerPublic'), $('#medalDetailSummaryPublic')); });
            $('#medalDetailPageSizePublic').on('change', function(){ pageSize = parseInt($(this).val(),10) || 10; currentPage = 1; renderPage($('#medalDetailBodyPublic'), $('#medalDetailPagerPublic'), $('#medalDetailSummaryPublic')); });

            $(document).on('click', '.medal-detail-btn-public', function(){
                var $tr = $(this).closest('tr');
                var kod = $tr.data('kod');
                var name = $tr.data('name') || kod;
                var medal = $(this).data('medal');
                if (!kod || !medal) return;
                $('#medalDetailTitlePublic').text(String(name).toUpperCase());
                // Show English medal label and colored badge
                var med = String(medal).toLowerCase();
                currentMedalPublic = med;
                if (med === 'emas'){
                    $('#medalDetailMedalPublic').text('Gold').removeClass().addClass('badge bg-warning text-dark');
                } else if (med === 'perak'){
                    $('#medalDetailMedalPublic').text('Silver').removeClass().addClass('badge bg-secondary text-dark');
                } else if (med === 'gangsa'){
                    $('#medalDetailMedalPublic').text('Bronze').removeClass().addClass('badge').css({'background':'#cd7f32','color':'#fff'});
                } else {
                    $('#medalDetailMedalPublic').text(medal.toUpperCase()).removeClass().addClass('badge bg-light text-dark');
                }
                $('#medalDetailBodyPublic').html('<tr><td colspan="5" class="text-center text-muted">Loading...</td></tr>');
                $('#medalDetailSummaryPublic').text(''); cache = []; currentPage = 1;
                // ensure pageSize matches selected control in case user changed it previously
                pageSize = parseInt($('#medalDetailPageSizePublic').val(), 10) || pageSize;
                var $modal = $('#medalDetailModalPublic');
                $modal.modal('show');
                $.ajax({ url: '<?php echo url('ajax/medal_recipients_public.php'); ?>', data: { kod_universiti: kod, medal: medal }, dataType: 'json' })
                .done(function(res){ if (!res || res.status !== 'ok'){ $('#medalDetailBodyPublic').html('<tr><td colspan="5" class="text-center text-danger">Failed to load data</td></tr>'); $('#medalDetailSummaryPublic').text('Failed to load data'); return; } cache = res.data || []; renderPage($('#medalDetailBodyPublic'), $('#medalDetailPagerPublic'), $('#medalDetailSummaryPublic')); })
                .fail(function(){ $('#medalDetailBodyPublic').html('<tr><td colspan="5" class="text-center text-danger">Network error</td></tr>'); $('#medalDetailSummaryPublic').text('Network error'); });
            });

            // Click handler for Total count: fetch all three medal types and merge results client-side
            $(document).on('click', '.medal-detail-total-public', function(){
                var $tr = $(this).closest('tr');
                var kod = $tr.data('kod');
                var name = $tr.data('name') || kod;
                if (!kod) return;
                $('#medalDetailTitlePublic').text(String(name).toUpperCase());
                $('#medalDetailMedalPublic').text('ALL');
                currentMedalPublic = 'all';
                $('#medalDetailBodyPublic').html('<tr><td colspan="5" class="text-center text-muted">Loading...</td></tr>');
                $('#medalDetailSummaryPublic').text(''); cache = []; currentPage = 1;
                // ensure pageSize matches selected control in case user changed it previously
                pageSize = parseInt($('#medalDetailPageSizePublic').val(), 10) || pageSize;
                var $modal = $('#medalDetailModalPublic'); $modal.modal('show');
                var endpoint = '<?php echo url('ajax/medal_recipients_public.php'); ?>';
                $.ajax({ url: endpoint, data: { kod_universiti: kod, medal: 'all' }, dataType: 'json' })
                .done(function(res){
                    if (!res || res.status !== 'ok' || !Array.isArray(res.data)){
                        $('#medalDetailBodyPublic').html('<tr><td colspan="5" class="text-center text-danger">Failed to load data</td></tr>');
                        $('#medalDetailSummaryPublic').text('Failed to load data');
                        return;
                    }
                    cache = res.data || [];
                    renderPage($('#medalDetailBodyPublic'), $('#medalDetailPagerPublic'), $('#medalDetailSummaryPublic'));
                })
                .fail(function(){
                    $('#medalDetailBodyPublic').html('<tr><td colspan="5" class="text-center text-danger">Failed to load data</td></tr>'); $('#medalDetailSummaryPublic').text('Failed to load data');
                });
            });
        });
    })();
    </script>

<?php
$content = ob_get_clean();
?>
<!-- Sponsors and footer removed from public medal standings as requested -->
<?php
require_once __DIR__ . '/../includes/layout_public.php';
?>
