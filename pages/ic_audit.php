<?php
/**
 * IC Audit (MyKad) — Read-only report page
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

Session::start();
$auth = getAuth();
$auth->requireAuth();
require_once __DIR__ . '/../config/rbac.php';
$rbac = getRBAC();
$rbac->requirePageAccess('pages/ic_audit.php');

$page_title = 'MyKad Audit — Team Athletes';

function validateMyKad($ic) {
    $raw = (string)$ic;
    $norm = preg_replace('/\D+/', '', $raw);
    $reasons = [];
    $gender = 'Unknown';
    $dob = null;

    if ($norm === '') {
        $reasons[] = 'Identity card number missing';
        return [ 'raw'=>$raw, 'normalized'=>$norm, 'status'=>'INVALID', 'reasons'=>$reasons, 'gender'=>$gender, 'date_of_birth'=>$dob ];
    }

    if (strlen($norm) !== 12) {
        $reasons[] = 'Normalised length is not 12 digits';
        if (preg_match('/(\d)$/', $norm, $m)) { $gender = ((int)$m[1] % 2 === 1) ? 'Male' : 'Female'; }
        return [ 'raw'=>$raw, 'normalized'=>$norm, 'status'=>'INVALID', 'reasons'=>$reasons, 'gender'=>$gender, 'date_of_birth'=>$dob ];
    }

    $yy = (int)substr($norm, 0, 2);
    $mm = (int)substr($norm, 2, 2);
    $dd = (int)substr($norm, 4, 2);
    $place = substr($norm, 6, 2);
    $lastDigit = (int)substr($norm, 11, 1);

    if (is_numeric($lastDigit)) { $gender = ($lastDigit % 2 === 1) ? 'Male' : 'Female'; }

    $status = 'VALID';
    if ($place === '00' || $place === '99') { $reasons[] = "Invalid place code: {$place}"; $status = 'INVALID'; }

    $today = new DateTimeImmutable('today');
    $year19 = 1900 + $yy;
    $year20 = 2000 + $yy;
    $dobCandidate = null;
    $validDate = false;

    $isValidDate = function($y, $m, $d){ return checkdate((int)$m, (int)$d, (int)$y); };

    if ($isValidDate($year20, $mm, $dd)) {
        $d20 = DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year20, $mm, $dd));
        if ($d20 && $d20 <= $today) { $dobCandidate = $d20; $validDate = true; }
    }
    if (!$validDate && $isValidDate($year19, $mm, $dd)) {
        $d19 = DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year19, $mm, $dd));
        if ($d19 && $d19 <= $today) { $dobCandidate = $d19; $validDate = true; }
    }

    if (!$validDate) {
        $futureFlag = false;
        if ($isValidDate($year20, $mm, $dd)) {
            $d20 = DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year20, $mm, $dd));
            if ($d20 && $d20 > $today) $futureFlag = true;
        }
        if ($isValidDate($year19, $mm, $dd)) {
            $d19 = DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $year19, $mm, $dd));
            if ($d19 && $d19 > $today) $futureFlag = true;
        }

        if ($futureFlag) {
            $reasons[] = 'Date of birth is in the future — review required (CHECK)';
            $status = ($status === 'INVALID') ? 'INVALID' : 'CHECK';
            if (isset($d20) && $d20 > $today) $dobCandidate = $d20;
            elseif (isset($d19) && $d19 > $today) $dobCandidate = $d19;
        } else {
            $reasons[] = 'Invalid date of birth';
            $status = 'INVALID';
        }
    }

    if ($dobCandidate instanceof DateTimeImmutable) {
        $dob = $dobCandidate->format('Y-m-d');
    }

    if ($status === 'VALID' && count($reasons) > 0) {
        $status = 'CHECK';
    }

    return [
        'raw' => $raw,
        'normalized' => $norm,
        'status' => $status,
        'reasons' => $reasons,
        'gender' => $gender,
        'date_of_birth' => $dob
    ];
}

// Fetch athletes
$rows = [];
try {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT 
            pa.id, 
            pa.pasukan_id, 
            pa.nama, 
            pa.no_kad_pengenalan,
            p.nama_pasukan
        FROM table_pasukan_atlet pa
        LEFT JOIN table_pasukan p ON pa.pasukan_id = p.id AND p.deleted_at IS NULL
        WHERE pa.deleted_at IS NULL
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('[ic_audit] DB error: ' . $e->getMessage());
}

// Build items + counts
$items = [];
$counts = ['VALID'=>0,'CHECK'=>0,'INVALID'=>0];
foreach ($rows as $row) {
    $res = validateMyKad($row['no_kad_pengenalan'] ?? '');
    $isValid = ($res['status'] ?? '') === 'VALID';
    $displayRaw = $isValid ? ($res['normalized'] ?? ($res['raw'] ?? '')) : ($res['raw'] ?? '');
    $items[] = [
        'id' => $row['id'] ?? null,
        'nama' => $row['nama'] ?? '-',
        'pasukan' => $row['nama_pasukan'] ?? '-',
        // Show cleaned IC for valid records, keep original for invalid
        'raw' => $displayRaw,
        'normalized' => $res['normalized'] ?? '',
        'gender' => $res['gender'] ?? '-',
        'date_of_birth' => $res['date_of_birth'] ?? '-',
        'status' => $res['status'] ?? '-',
        'reasons' => is_array($res['reasons'] ?? null) ? implode('; ', $res['reasons']) : (string)($res['reasons'] ?? '')
    ];
    $counts[$res['status']] = ($counts[$res['status']] ?? 0) + 1;
}

// Render page
ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/ic-audit.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/ic-audit.css'); ?>">
<div class="w-100 px-3 ic-audit-view">
    <div class="row mb-3">
        <div class="col-12">
            <div class="audit-eyebrow">SAM 2026 &middot; ATHLETE DATA AUDIT</div><h3>MyKad Audit — Team Athletes</h3>
            <p class="text-muted">Audit only — no changes are made to the database.</p>
            <div class="card mb-3">
                <div class="card-body d-flex justify-content-between align-items-center gap-3">
                    <div>
                        <span class="badge bg-success me-2">VALID: <?php echo $counts['VALID']; ?></span>
                        <span class="badge bg-warning text-dark me-2">CHECK: <?php echo $counts['CHECK']; ?></span>
                        <span class="badge bg-danger">INVALID: <?php echo $counts['INVALID']; ?></span>
                    </div>
                    <div class="d-flex align-items-center">
                        <div class="input-group input-group-sm me-2" style="min-width:260px;">
                            <span class="input-group-text"><i class="cil cil-magnifying-glass"></i></span>
                            <input type="search" id="icSearch" aria-label="Search by name or MyKad" class="form-control" placeholder="Search by name or identity card number...">
                        </div>
                        <select id="icPageSizeSelect" aria-label="Records per page" class="form-select form-select-sm me-2" style="width:auto;">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                        <select id="filterStatusSelect" aria-label="Tapis status audit" class="form-select form-select-sm" style="width:auto;">
                            <option value="">All</option>
                            <option value="VALID">Valid</option>
                            <option value="CHECK">Check</option>
                            <option value="INVALID" selected>Invalid</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="table-responsive audit-table-panel">
                <table id="icAuditTable" class="table table-sm table-striped table-hover" style="width:100%">
                    <thead class="table-light">
                        <tr>
                            <th>No</th>
                            <th>Name</th>
                            <th>Team</th>
                            <th>Original IC</th>
                            <th>Normalised IC</th>
                            <th>Gender</th>
                            <th>Date of Birth</th>
                            <th>Status</th>
                            <th>Issue / Notes</th>
                        </tr>
                    </thead>
                    <tbody id="icTableBody"></tbody>
                </table>
            </div>
            <div class="d-flex align-items-center justify-content-between mt-2">
                <div id="icPagingInfo" class="small text-muted"></div>
                <div id="icPagination" class="ms-auto"></div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>
<style>.table-danger{--bs-table-bg:#f8d7da}.table-warning{--bs-table-bg:#fff3cd}.table-success{--bs-table-bg:#d1e7dd}.no-scroll{overflow:visible}</style>
<script>window.icItems = <?php echo json_encode($items, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;</script>
<script>
(function(){
    var icData = window.icItems || [];
    var icFiltered = icData.slice();
    var icCurrentPage = 1;
    var icPageSize = 10;

    function escHtml(s){ var d=document.createElement('div'); d.textContent = s; return d.innerHTML; }
    function setIcPageSize(v){ icPageSize = parseInt(v) || 10; icCurrentPage = 1; renderIcPage(); }
    function goToIcPage(p){ var totalPages = Math.max(1, Math.ceil(icFiltered.length / icPageSize)); icCurrentPage = Math.min(Math.max(1, parseInt(p)||1), totalPages); renderIcPage(); }

    function applyIcFilter(q, status){ var ql = (q||'').toString().trim().toLowerCase(); icFiltered = icData.filter(function(item){ var matchesQ = true; if (ql){ var n=(item.nama||'').toString().toLowerCase(); var r=(item.raw||'').toString().toLowerCase(); var c=(item.normalized||'').toString().toLowerCase(); matchesQ = n.indexOf(ql)!==-1 || r.indexOf(ql)!==-1 || c.indexOf(ql)!==-1; } var matchesStatus = true; if (status) matchesStatus = ((item.status||'') === status); return matchesQ && matchesStatus; }); icCurrentPage = 1; }

    function renderIcPage(){ var tbody = document.getElementById('icTableBody'); if(!tbody) return; var total = icFiltered.length; var totalPages = Math.max(1, Math.ceil(total / icPageSize)); if(icCurrentPage>totalPages) icCurrentPage = totalPages; var start = (icCurrentPage-1)*icPageSize; var end = Math.min(total, start+icPageSize); if(total===0){ tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-5">No records found.</td></tr>'; } else { var html=''; var slice = icFiltered.slice(start,end); for(var i=0;i<slice.length;i++){ var it = slice[i]; var rowIdx = start + i + 1; var cls=''; if(it.status==='VALID') cls='table-success'; if(it.status==='CHECK') cls='table-warning'; if(it.status==='INVALID') cls='table-danger'; html += '<tr class="'+cls+'">'; html += '<td>'+rowIdx+'</td>'; html += '<td>'+escHtml(it.nama||'-')+'</td>'; html += '<td>'+escHtml(it.pasukan||'-')+'</td>'; html += '<td>'+escHtml(it.raw||'-')+'</td>'; html += '<td>'+escHtml(it.normalized||'-')+'</td>'; html += '<td>'+escHtml(it.gender||'-')+'</td>'; html += '<td>'+escHtml(it.date_of_birth||'-')+'</td>'; html += '<td>'+escHtml(it.status||'-')+'</td>'; html += '<td>'+escHtml(it.reasons||'')+'</td>'; html += '</tr>'; } tbody.innerHTML = html; } var infoEl = document.getElementById('icPagingInfo'); if(infoEl){ if(total===0) infoEl.textContent=''; else infoEl.textContent = 'Showing ' + (start+1) + '–' + end + ' of ' + total; } renderIcPaginationControls(total, icCurrentPage, icPageSize); }
    function renderIcPaginationControls(totalItems, currentPage, pageSize){ var container = document.getElementById('icPagination'); if(!container) return; var totalPages = Math.max(1, Math.ceil(totalItems / pageSize)); var html = '<nav aria-label="ic-pagination"><ul class="pagination pagination-sm mb-0">'; var prevDisabled = (currentPage<=1)?' disabled':''; html += '<li class="page-item'+prevDisabled+'"><a class="page-link" href="#" data-page="'+(currentPage-1)+'">‹</a></li>'; var maxButtons = 5; var startPage = Math.max(1, currentPage - Math.floor(maxButtons/2)); var endPage = Math.min(totalPages, startPage + maxButtons -1); if(endPage - startPage < maxButtons -1) startPage = Math.max(1, endPage - maxButtons +1); for(var p=startPage;p<=endPage;p++){ var active = (p===currentPage)?' active':''; html += '<li class="page-item'+active+'"><a class="page-link" href="#" data-page="'+p+'">'+p+'</a></li>'; } var nextDisabled = (currentPage>=totalPages)?' disabled':''; html += '<li class="page-item'+nextDisabled+'"><a class="page-link" href="#" data-page="'+(currentPage+1)+'">›</a></li>'; html += '</ul></nav>'; container.innerHTML = html; var links = container.querySelectorAll('.page-link'); for(var i=0;i<links.length;i++){ links[i].addEventListener('click', function(e){ e.preventDefault(); var p = parseInt(this.getAttribute('data-page'))||1; goToIcPage(p); }); } }

    document.addEventListener('DOMContentLoaded', function(){ var search = document.getElementById('icSearch'); if(search){ search.addEventListener('input', function(){ var status = document.getElementById('filterStatusSelect').value || ''; applyIcFilter(this.value, status); renderIcPage(); }); } var ps = document.getElementById('icPageSizeSelect'); if(ps){ ps.addEventListener('change', function(){ setIcPageSize(this.value); }); } var statusSel = document.getElementById('filterStatusSelect'); if(statusSel){ statusSel.addEventListener('change', function(){ var q = (document.getElementById('icSearch')||{value:''}).value || ''; applyIcFilter(q, this.value || ''); renderIcPage(); }); } var initStatus = (document.getElementById('filterStatusSelect')||{value:''}).value || ''; applyIcFilter((document.getElementById('icSearch')||{value:''}).value || '', initStatus); try{ document.getElementById('icPageSizeSelect').value = '10'; icPageSize = 10; }catch(e){} renderIcPage(); });
})();
</script>
