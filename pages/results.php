<?php
/**
 * Results (Results) Management Page
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../api/models/SportModel.php';

$page_title = 'Results';

// Get current user role
Session::start();
$auth = getAuth();
$currentUserRole = Session::get('user_role') ?? '';

// Fetch sports from database
$sports = [];
try {
    $db = getDB();
    
    // If user is a judge, filter sports to only those with assigned categories
    if ($currentUserRole === 'JUDGE') {
        $userId = Session::get('user_id');
        $stmt = $db->prepare("
            SELECT DISTINCT s.id, s.nama_sukan, s.kod_sukan, s.status
            FROM table_sukan s
            INNER JOIN table_kategori k ON s.id = k.sukan_id
            INNER JOIN judge_category_assignments jca ON k.id = jca.kategori_id
            WHERE s.deleted_at IS NULL 
            AND s.status = 1
            AND k.deleted_at IS NULL
            AND k.status = 1
            AND jca.user_id = :user_id
            AND jca.is_active = TRUE
            ORDER BY s.nama_sukan ASC
        ");
        $stmt->execute([':user_id' => $userId]);
        $sports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // For non-judges, show all sports
        $sportModel = new SportModel();
        $result = $sportModel->getAll(['limit' => 1000, 'status' => 1]);
        if ($result['success']) {
            $sports = $result['data'];
        }
    }
} catch (Exception $e) {
    error_log('[keputusan.php] DB error fetching sports: ' . $e->getMessage());
}
// Determine completion status for each sport: whether all active categories have results
$sportCompletion = [];
try {
    if (!empty($sports)) {
        $ids = array_map(function($s){ return (int)$s['id']; }, $sports);
        $in = implode(',', $ids);
        // Count active categories per sport
        $sqlTotal = "SELECT s.id AS sukan_id, COUNT(k.id) AS total_kategori
            FROM table_sukan s
            JOIN table_kategori k ON k.sukan_id = s.id AND k.deleted_at IS NULL AND k.status = 1
            WHERE s.id IN ($in)
            GROUP BY s.id";
        $totStmt = $db->query($sqlTotal);
        $totals = $totStmt ? $totStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $totalMap = [];
        foreach ($totals as $t) $totalMap[(int)$t['sukan_id']] = (int)$t['total_kategori'];

        // Count distinct kategori_ids present in table_results per sport
        $sqlHas = "SELECT sukan_id, COUNT(DISTINCT kategori_id) AS kategori_with_results
            FROM table_results
            WHERE sukan_id IN ($in) AND kategori_id IS NOT NULL AND deleted_at IS NULL
            GROUP BY sukan_id";
        $hasStmt = $db->query($sqlHas);
        $haves = $hasStmt ? $hasStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $haveMap = [];
        foreach ($haves as $h) $haveMap[(int)$h['sukan_id']] = (int)$h['kategori_with_results'];

        foreach ($ids as $sid) {
            $total = isset($totalMap[$sid]) ? $totalMap[$sid] : 0;
            $have = isset($haveMap[$sid]) ? $haveMap[$sid] : 0;
            // Consider complete only when there is at least one active category and all of them have results
            $sportCompletion[$sid] = ($total > 0 && $have >= $total) ? true : false;
        }
    }
} catch (Exception $e) {
    error_log('[keputusan.php] error computing sport completion: ' . $e->getMessage());
}

ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/results.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/results.css'); ?>">
<div class="w-100 px-3 results-view">
    <!-- Hero -->
    <div class="row mb-4 results-heading">
        <div class="col-12">
            <div class="card bg-light border-0 shadow-sm overflow-hidden">
                <div class="card-body py-4 d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                    <div>
                        <div class="results-eyebrow">SAM 2026 &middot; COMPETITION RESULTS</div><h2 class="mb-1">Results</h2>
                        <p class="text-muted mb-0">Competition results for first, second and third places</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="row mb-3 results-filters">
        <div class="col-md-3">
            <label class="results-filter-label" for="filterSport">Sport</label>
                <select class="form-control" id="filterSport">
                <option value="">All Sports</option>
                <?php foreach ($sports as $s):
                    $sid = (int)$s['id'];
                    $complete = isset($sportCompletion[$sid]) && $sportCompletion[$sid] ? 1 : 0;
                ?>
                    <option value="<?php echo $sid; ?>" data-complete="<?php echo $complete; ?>"><?php echo htmlspecialchars(english_sport_label($s['nama_sukan'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="results-filter-label" for="filterKategori">Category</label>
                <select class="form-control" id="filterKategori" disabled>
                <option value="">All Categories</option>
            </select>
        </div>
        <div class="col-md-3">
            <div class="d-flex gap-2">
                <label class="results-filter-label" for="filterStatus">Status</label>
                <select class="form-control flex-grow-1" id="filterStatus">
                    <option value="">All Statuses</option>
                    <option value="completed">Completed</option>
                    <option value="ongoing">Ongoing</option>
                    <option value="upcoming">Upcoming</option>
                </select>
                <button type="button" class="btn btn-outline-primary" id="btnExportWord" title="Export Word"><i class="fa-solid fa-file-word me-1"></i>Word</button>
                <button type="button" class="btn btn-outline-secondary" id="btnPrintResults" title="PDF"><i class="fa-solid fa-file-pdf me-1"></i>PDF</button>
            </div>
        </div>
    </div>

    <!-- Results List -->
    <div class="row">
        <div class="col-12">
            <div class="card mb-4 shadow-sm">
                <div class="card-header">
                    <strong>Results List</strong>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-fixed" style="table-layout:fixed;">
                            <thead>
                                <tr>
                                    <th scope="col" style="width:3%">#</th>
                                    <th scope="col" style="width:10%">Sport</th>
                                    <th scope="col" style="width:15%">Event</th>
                                    <th scope="col" style="width:40%">Name</th>
                                    <th scope="col" style="width:12%">Results</th>
                                    <th scope="col" style="width:10%">Status</th>
                                    <th scope="col" style="width:10%">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="keputusanBody">
                                <tr id="noKeputusanRow">
                                    <td colspan="7" class="text-center text-muted py-4">
                                        <i class="cil cil-award" style="font-size: 2rem;"></i>
                                        <p class="mt-2">No results recorded</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <div id="keputusanPager" class="d-flex justify-content-between align-items-center mt-2"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add/Edit Modal -->
<div class="modal fade" id="modalKeputusan" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalKeputusanTitle">Record New Result</h5>
                <button type="button" class="btn-close" onclick="closeKeputusanModal()" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="formKeputusan">
                    <input type="hidden" id="keputusanId" name="id">
                    
                    <div class="mb-3">
                        <label for="keputusanSukan" class="form-label">Sport <span class="text-danger">*</span></label>
                        <select class="form-select" id="keputusanSukan" name="sukan_id" required>
                            <option value="">Select Sport</option>
                            <?php foreach ($sports as $s):
                                $sid = (int)$s['id'];
                                $complete = isset($sportCompletion[$sid]) && $sportCompletion[$sid] ? 1 : 0;
                            ?>
                                <option value="<?php echo $sid; ?>" data-complete="<?php echo $complete; ?>"><?php echo htmlspecialchars(english_sport_label($s['nama_sukan'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="keputusanKategori" class="form-label">Category <span class="text-danger">*</span></label>
                        <select class="form-select" id="keputusanKategori" name="kategori_id" required disabled>
                            <option value="">Select a category</option>
                        </select>
                        <small class="text-muted">Select a sport first</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="keputusanTarikh" class="form-label">Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="keputusanTarikh" name="tarikh" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Position <span class="text-danger">*</span></label>
                        <div id="standingsContainer">
                            <p class="text-muted">Select a category first to load the participant list</p>
                        </div>
                        <small class="text-muted">First, second and third places are required. Fourth place and below are optional.</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="keputusanStatus" class="form-label">Status</label>
                        <select class="form-select" id="keputusanStatus" name="status">
                            <option value="completed">Completed</option>
                            <option value="ongoing">Ongoing</option>
                            <option value="upcoming">Upcoming</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeKeputusanModal()">Cancel</button>
                <button type="button" class="btn btn-primary" id="btnSaveKeputusan">Save</button>
                
            </div>
        </div>
    </div>
</div>

<!-- Full results modal (wide) -->
<div class="modal fade" id="modalKeputusanFull" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalKeputusanFullTitle">Full Results</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                                    <table class="table table-sm table-bordered table-nowrap" id="modalKeputusanFullTable" style="table-layout:fixed;">
                                    <thead>
                                            <tr>
                                                <th style="width:3%">#</th>
                                                <th style="width:15%">Sport</th>
                                                <th style="width:25%">Event</th>
                                                <th style="width:57%">Participant / Team Name</th>
                                            </tr>
                                    </thead>
                        <tbody>
                            <!-- Populated dynamically -->
                        </tbody>
                    </table>
                    <div id="modalKeputusanPager" class="d-flex justify-content-between align-items-center mt-2"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<style>
    /* Ensure modal table cells don't wrap and long names truncate */
    .table-nowrap td, .table-nowrap th { white-space: nowrap; }
    .modal .text-truncate { display: inline-block; vertical-align: middle; max-width: 360px; }
    .modal .text-truncate.small { max-width: 260px; }
    /* Sport completion visuals for Select2 / enhanced selects */
    .sport-complete { background: #e9f7ee !important; font-weight: 700; }
    .sport-complete .sport-label { flex: 1 1 auto; }
    .sport-complete .sport-status { color: #0f5132; margin-left: 8px; }
    .select2-container--bootstrap4 .select2-results__option.sport-complete { background: #e9f7ee; font-weight:700 }
    /* Highlight full-result rows for sports that are complete */
    .sukan-complete { background-color: rgba(237,247,237,0.6); }
    .sukan-complete td { font-weight: 400; }
    /* Ensure all table data cells in this page stay normal weight */
    #keputusanBody td,
    #modalKeputusanFullTable tbody td,
    #standingsContainer tbody td {
        font-weight: 400 !important;
    }
</style>

<style>
/* Lightweight custom dropdown to allow per-option highlighting when Select2 isn't present */
.custom-select-wrapper { position: relative; display: inline-block; width:100%; }
.custom-select-display { border:1px solid #d0d7de; padding:6px 10px; border-radius:4px; background:#fff; cursor:pointer; display:flex; align-items:center; justify-content:space-between }
.custom-select-list { position:absolute; left:0; right:0; top:100%; z-index:1200; background:#fff; border:1px solid rgba(2,6,23,0.08); border-radius:6px; box-shadow:0 8px 24px rgba(2,6,23,0.08); max-height:320px; overflow:auto; margin-top:6px; display:none }
.custom-select-item { padding:8px 12px; cursor:pointer; display:flex; align-items:center; justify-content:space-between }
.custom-select-item:hover { background:#f4f6f8 }
.custom-select-item.sport-complete { background:#e9f7ee }
.custom-select-item .label { flex:1 1 auto }
.custom-select-item .status { margin-left:8px; color:#0f5132 }
.custom-select-arrow { margin-left:8px; transform:rotate(0deg); transition:transform 0.15s }
.custom-select-open .custom-select-arrow { transform:rotate(180deg) }
</style>

<style>
/* Normalize select sizes on this page so filter selects and modal selects match */
select.form-control, select.form-select {
    box-sizing: border-box;
    padding: .35rem .6rem;
    font-size: .95rem;
    line-height: 1.4;
    height: calc(1.4rem + .9rem); /* approximate consistent height */
}
/* Select2 bootstrap4 theme adjustments */
.select2-container--bootstrap4 .select2-selection--single {
    height: calc(1.4rem + .9rem);
}
.select2-container--bootstrap4 .select2-selection__rendered {
    line-height: calc(1.4rem + .9rem);
    padding-left: .45rem;
}
.select2-container--bootstrap4 .select2-selection__arrow {
    height: calc(1.4rem + .9rem);
    top: 0.2rem;
}

/* Ensure small-standing selects remain usable but visually consistent */
select.form-select-sm, .form-select-sm {
    padding-top: .25rem;
    padding-bottom: .25rem;
    font-size: .9rem;
    height: calc(1.2rem + .6rem);
}
</style>

<?php
// Precompute inline data-URIs for critical assets so print view works even when direct HTTP
// requests are blocked by WAF or referrer rules. This inlines logos and sport icons.
$inline_assets = [];
$asset_root = realpath(__DIR__ . '/../assets');
$to_inline = [
    'img/logos/UA/kpt.png',
    'img/logos/logo-print.png',
    'img/logos/UA/UPNM.svg',
    'img/logos/logo-main.png',
    // sport icons
    'img/sukan/badminton.png', 'img/sukan/bola-jaring.png', 'img/sukan/volleyball.png', 'img/sukan/catur.png',
    'img/sukan/bola-sepak.png', 'img/sukan/ragbi.png', 'img/sukan/takraw.png', 'img/sukan/mlbb-pubg.png',
    'img/sukan/tenpin-bowling.png', 'img/sukan/olahraga.png', 'img/sukan/default.png'
];
foreach ($to_inline as $rel) {
    $full = $asset_root . '/' . $rel;
    if (file_exists($full) && is_readable($full)) {
        $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
        $data = file_get_contents($full);
        if ($data !== false) {
            if ($ext === 'svg') {
                $mime = 'image/svg+xml';
            } elseif ($ext === 'png') {
                $mime = 'image/png';
            } elseif ($ext === 'jpg' || $ext === 'jpeg') {
                $mime = 'image/jpeg';
            } else {
                $mime = 'application/octet-stream';
            }
            $inline_assets[$rel] = 'data:' . $mime . ';base64,' . base64_encode($data);
        }
    }
}
?>

<script>
var INLINE_ASSETS = <?php echo json_encode($inline_assets); ?>;
var SITE_BASE = <?php echo json_encode(BASE_URL); ?>;
(function(){
    const sportSel = document.getElementById('filterSport');
    const kategoriSel = document.getElementById('filterKategori');
    const statusSel = document.getElementById('filterStatus');
    const keputusanBody = document.getElementById('keputusanBody');
    const noRow = document.getElementById('noKeputusanRow');
    
    // Modal instance variable
    var modalKeputusanInstance = null;
    const formKeputusan = document.getElementById('formKeputusan');
    const keputusanSukan = document.getElementById('keputusanSukan');
    const keputusanKategori = document.getElementById('keputusanKategori');
    const keputusanTarikh = document.getElementById('keputusanTarikh');
    const standingsContainer = document.getElementById('standingsContainer');
    const keputusanStatus = document.getElementById('keputusanStatus');
    const keputusanId = document.getElementById('keputusanId');
    
    var currentCategoryType = null;
    var currentParticipants = [];
    var participantCount = 0;

    // Keep latest full dataset from server to avoid duplicate queries
    var latestKeputusanData = [];

    // Enhance sport selects with Select2 rendering to show completion status (icon + highlight)
    function initSportSelects(){
        try{
            if (window.jQuery && jQuery.fn && jQuery.fn.select2) {
                var $ = jQuery;

                function formatSport(opt){
                    if (!opt.id) return opt.text;
                    var el = opt.element;
                    var complete = el ? (el.getAttribute('data-complete') === '1') : false;
                    var label = opt.text || '';
                    var status = complete ? '<span class="sport-status">&#x2705;</span>' : '';
                    var cls = complete ? 'sport-complete' : '';
                    return '<div class="d-flex align-items-center '+cls+'"><span class="sport-label">'+label+'</span><span>'+status+'</span></div>';
                }

                // Filter select (global page filter)
                try{
                    var $f = $(sportSel);
                    if ($f.length && $f.data('select2')) { $f.select2('destroy'); }
                    $f.select2({ width: '100%', theme: 'bootstrap4', escapeMarkup: function(m){return m;}, templateResult: formatSport, templateSelection: function(s){ return s.text; } });
                }catch(e){ console.warn('initSportSelects: filterSport select2 init failed', e); }

                // Modal select (inside modalKeputusan) — ensure dropdown parent is modal so it renders above modal
                try{
                    var $m = $(keputusanSukan);
                    if ($m.length && $m.data('select2')) { $m.select2('destroy'); }
                    $m.select2({ width: '100%', theme: 'bootstrap4', dropdownParent: $('#modalKeputusan'), escapeMarkup: function(m){return m;}, templateResult: formatSport, templateSelection: function(s){ return s.text; } });
                }catch(e){ console.warn('initSportSelects: modal sukan select2 init failed', e); }
            }
        }catch(err){ console.warn('initSportSelects err', err); }
    }

    // Initialize sport selects after a short delay so DOM is ready and Select2 (if present) loaded
    setTimeout(initSportSelects, 50);
    
    // Native-select fallback: mark completed sports with text if Select2 isn't present
    function applyNativeSportLabels(){
        try{
            var selects = [sportSel, keputusanSukan];
            selects.forEach(function(sel){
                if (!sel) return;
                // If Select2 is attached, skip native labeling for that element
                if (window.jQuery && jQuery.fn && jQuery.fn.select2 && jQuery(sel).data('select2')) return;
                Array.from(sel.options).forEach(function(opt){
                    if (!opt) return;
                    if (!opt.getAttribute) return;
                    var complete = opt.getAttribute('data-complete');
                    if (complete === '1'){
                        if (!opt.hasAttribute('data-orig-label')){
                            opt.setAttribute('data-orig-label', opt.textContent || opt.innerText || '');
                            opt.textContent = (opt.textContent || opt.innerText || '') + ' (Lengkap)';
                        }
                    } else {
                        // restore if previously modified
                        if (opt.hasAttribute('data-orig-label')){
                            opt.textContent = opt.getAttribute('data-orig-label');
                            opt.removeAttribute('data-orig-label');
                        }
                    }
                });
            });
        }catch(e){ console.warn('applyNativeSportLabels error', e); }
    }

    // Run fallback shortly after init (covers pages without Select2)
    setTimeout(applyNativeSportLabels, 120);

    // If Select2 not present, replace select with a lightweight custom dropdown that supports per-option highlight
    function enhanceNativeSelectWithHighlight(sel){
        try{
            if (!sel) return;
            // don't double-enhance
            if (sel.dataset.enhanced === '1') return;
            // If Select2 is active, skip
            if (window.jQuery && jQuery.fn && jQuery.fn.select2 && jQuery(sel).data('select2')) return;

            var wrapper = document.createElement('div');
            wrapper.className = 'custom-select-wrapper';
            var display = document.createElement('div');
            display.className = 'custom-select-display';
            var arrow = document.createElement('span'); arrow.className = 'custom-select-arrow'; arrow.innerHTML = '▾';
            var labelSpan = document.createElement('span'); labelSpan.textContent = (sel.selectedOptions[0] ? sel.selectedOptions[0].textContent : (sel.options[0] ? sel.options[0].textContent : '')); 
            display.appendChild(labelSpan); display.appendChild(arrow);

            var list = document.createElement('div'); list.className = 'custom-select-list';

            Array.from(sel.options).forEach(function(opt){
                var item = document.createElement('div');
                item.className = 'custom-select-item';
                if (opt.getAttribute && opt.getAttribute('data-complete') === '1') item.classList.add('sport-complete');
                var lbl = document.createElement('span'); lbl.className = 'label'; lbl.textContent = opt.textContent;
                var st = document.createElement('span'); st.className = 'status'; st.innerHTML = (opt.getAttribute && opt.getAttribute('data-complete') === '1') ? '✔' : '';
                item.appendChild(lbl); item.appendChild(st);
                item.dataset.value = opt.value;
                item.addEventListener('click', function(){
                    // set original select value and dispatch change
                    try{ sel.value = this.dataset.value; sel.dispatchEvent(new Event('change', { bubbles: true })); }catch(e){}
                    labelSpan.textContent = lbl.textContent;
                    closeList();
                });
                list.appendChild(item);
            });

            function openList(){ list.style.display = 'block'; wrapper.classList.add('custom-select-open'); }
            function closeList(){ list.style.display = 'none'; wrapper.classList.remove('custom-select-open'); }

            display.addEventListener('click', function(e){ e.stopPropagation(); if (list.style.display === 'block') closeList(); else openList(); });
            document.addEventListener('click', function(){ closeList(); });

            // insert wrapper before select and hide native select
            sel.style.display = 'none'; sel.parentNode.insertBefore(wrapper, sel);
            wrapper.appendChild(display); wrapper.appendChild(list); wrapper.appendChild(sel);
            sel.dataset.enhanced = '1';
        }catch(e){ console.warn('enhanceNativeSelectWithHighlight error', e); }
    }

    // Apply enhancer to both filterSport and keputusanSukan after a short delay
    setTimeout(function(){ enhanceNativeSelectWithHighlight(sportSel); enhanceNativeSelectWithHighlight(keputusanSukan); }, 200);
    
    function fetchJSON(url){
        return fetch(url, { 
            method: 'GET', 
            credentials: 'same-origin', 
            headers: { 'Accept': 'application/json' } 
        }).then(r => r.json()).catch(()=>({success:false}));
    }
    
    function checkCategoriesWithResults(sukan_id, kategoriSelect){
        // Only check if we're in the modal (keputusanKategori)
        if (!kategoriSelect || kategoriSelect !== keputusanKategori) {
            return Promise.resolve([]);
        }
        
        if (!sukan_id) {
            return Promise.resolve([]);
        }
        
        const currentResultId = keputusanId.value || null;
        const excludeParam = currentResultId ? '&exclude_id=' + encodeURIComponent(currentResultId) : '';
        const url = <?php echo json_encode(url("ajax/check_kategori_has_result.php") . '?sukan_id='); ?> + encodeURIComponent(sukan_id) + excludeParam;
        
        return fetchJSON(url)
            .then(res=>{
                if(res && res.success && Array.isArray(res.data)){
                    return res.data; // Array of kategori IDs that have results
                }
                return [];
            })
            .catch(err=>{
                console.error('Failed to check categories with results', err);
                return [];
            });
    }
    
    function updateCategoryRestrictions(){
        // Only update if we're in the modal and sport is set and categories are loaded
        if (!keputusanSukan.value || keputusanKategori.options.length <= 1) {
            return;
        }
        
        const sukanId = keputusanSukan.value;
        
        checkCategoriesWithResults(sukanId, keputusanKategori)
            .then(kategoriWithResults => {
                // Reset all options first
                Array.from(keputusanKategori.options).forEach(opt => {
                    if(opt.value && opt.value !== ''){
                        opt.disabled = false;
                        opt.style.color = '';
                        opt.textContent = (opt.textContent || '').replace(' (Results already recorded)', '');
                    }
                });
                
                // Disable categories that have results
                Array.from(keputusanKategori.options).forEach(opt => {
                    if(opt.value && opt.value !== ''){
                        const kategoriId = parseInt(opt.value);
                        if(kategoriWithResults.includes(kategoriId)){
                            opt.disabled = true;
                            opt.style.color = '#999';
                            opt.textContent = (opt.textContent || '') + ' (Results already recorded)';
                        }
                    }
                });
            });
    }
    
    function loadKategori(sukan_id, targetSelect = null){
        const select = targetSelect || kategoriSel;
        if(!select) return Promise.resolve();
        
        select.innerHTML = '<option value="">Loading...</option>';
        select.disabled = true;
        
        if(!sukan_id){
            select.innerHTML = '<option value="">All Categories</option>';
            select.disabled = true;
            return Promise.resolve();
        }
        
        // Race the fetch against a timeout so the select doesn't remain stuck on 'Loading...'
        const fetchPromise = fetchJSON(<?php echo json_encode(url("ajax/get_kategori_by_sukan.php") . '?sukan_id='); ?> + encodeURIComponent(sukan_id));
        const timeoutMs = 5000;
        const timeoutPromise = new Promise(resolve => setTimeout(()=>resolve({ __timeout: true }), timeoutMs));

        return Promise.race([fetchPromise, timeoutPromise]).then(res => {
            if (!res || res.__timeout) {
                console.warn('loadKategori: request timed out for sukan_id=', sukan_id);
                select.innerHTML = '<option value="">Unable to load categories (timeout)</option>';
                select.disabled = true;
                
                return Promise.resolve();
            }

            console.log('loadKategori: response for sukan_id=' + sukan_id, res);
            if(res && res.success && Array.isArray(res.data) && res.data.length){
                select.innerHTML = '<option value="">Select a category</option>';
                res.data.forEach(k=>{
                    const o = document.createElement('option');
                    o.value = k.id;
                    o.textContent = englishSportLabel(k.nama_kategori) || ('Category ' + k.id);
                    select.appendChild(o);
                });
                select.disabled = false;

                // If this is the modal kategori select, check for existing results
                if (select === keputusanKategori) {
                    // Use setTimeout to ensure DOM is updated before checking
                    setTimeout(() => {
                        updateCategoryRestrictions();
                        console.log('loadKategori: updated kategori options, enabled state =', select.disabled === false);
                        // Re-init Select2 only for the modal kategori select so it reflects new options and enabled state
                        try{
                            if (window.jQuery && jQuery.fn && jQuery.fn.select2) {
                                const $k = jQuery(keputusanKategori);
                                try{ if ($k.data('select2')) { console.log('loadKategori: destroying existing select2 for kategori'); $k.select2('destroy'); } }catch(e){console.warn(e);} 
                                // Ensure underlying select disabled flag is cleared before init
                                try{ $k.prop('disabled', false); $k.removeAttr('disabled'); $k.attr('aria-disabled', 'false'); }catch(e){}

                                // Reinitialize Select2 for kategori and clean up any disabled container classes
                                    try{
                                        $k.select2({ dropdownParent: jQuery('#modalKeputusan'), width: '100%', theme: 'bootstrap4' });
                                        // Ensure Select2 selection on kategori triggers native change
                                        try{
                                            // use a namespaced handler to avoid duplicate bindings
                                            $k.off('select2:select.select2-kategori');
                                            $k.on('select2:select.select2-kategori', function(e){
                                                try{ const el = $k[0]; if (el && typeof el.dispatchEvent === 'function') el.dispatchEvent(new Event('change', { bubbles: true })); else $k.trigger('change'); }catch(err){}
                                            });
                                        }catch(e){}
                                    const $cont = $k.next('.select2-container');
                                    if ($cont && $cont.length) {
                                        $cont.removeClass('select2-container--disabled');
                                        $cont.find('.select2-selection').removeClass('select2-selection--disabled').attr('aria-disabled','false');
                                    }
                                }catch(e){ console.error('loadKategori: select2 init error', e); }

                                console.log('loadKategori: reinitialized select2 for kategori, disabled=', keputusanKategori.disabled);
                                

                                // Retry a short time later in case other code toggles disabled after init
                                setTimeout(()=>{
                                    try{
                                        $k.prop('disabled', false); $k.removeAttr('disabled'); $k.attr('aria-disabled','false');
                                        const $cont2 = $k.next('.select2-container');
                                        if ($cont2 && $cont2.length) {
                                            $cont2.removeClass('select2-container--disabled');
                                            $cont2.find('.select2-selection').removeClass('select2-selection--disabled').attr('aria-disabled','false');
                                        }
                                        console.log('loadKategori: retry ensure kategori enabled, disabled=', keputusanKategori.disabled);
                                    }catch(e){ }
                                }, 120);
                            } else {
                                // Fallback: call full init which will load Select2 if needed
                                console.log('loadKategori: select2 not present, calling initSelect2ForKeputusanModal');
                                initSelect2ForKeputusanModal();
                            }
                        }catch(e){ console.error('loadKategori: select2 init error', e); }
                    }, 100);
                }
                return Promise.resolve();
            } else {
                select.innerHTML = '<option value="">No categories for this sport</option>';
                select.disabled = true;
                // update debug UI
                
                return Promise.resolve();
            }
        }).catch(err=>{
            console.error('Failed to fetch kategori', err);
            select.innerHTML = '<option value="">Unable to load categories</option>';
            select.disabled = true;
            
            return Promise.resolve();
        });
    }
    
    function loadParticipants(kategori_id){
        console.log('loadParticipants called with kategori_id:', kategori_id);
        if(!kategori_id){
            standingsContainer.innerHTML = '<p class="text-muted">Select a category first to load the participant list</p>';
            currentParticipants = [];
            participantCount = 0;
            return Promise.resolve();
        }
        
        standingsContainer.innerHTML = '<p class="text-muted">Loading participant list...</p>';
        
        const url = <?php echo json_encode(url("ajax/get_participants_by_kategori.php") . '?kategori_id='); ?> + encodeURIComponent(kategori_id);
        console.log('Fetching participants from:', url);
        
        return fetchJSON(url)
            .then(res=>{
                console.log('Participants response:', res);
                if(res && res.success && Array.isArray(res.data)){
                    currentCategoryType = res.type;
                    currentParticipants = res.data;
                    participantCount = res.data.length;
                    console.log('Found', participantCount, 'participants, type:', res.type);
                    
                    if(res.data.length > 0){
                        // Generate dynamic table
                        generateStandingsTable(res.data);
                        updateParticipantDropdowns();
                    } else {
                        standingsContainer.innerHTML = '<p class="text-danger">No participants registered for this category</p>';
                    }
                } else {
                    console.warn('Invalid response or no data:', res);
                    standingsContainer.innerHTML = '<p class="text-danger">No participants registered</p>';
                }
            })
            .catch(err=>{
                console.error('Failed to fetch participants', err);
                standingsContainer.innerHTML = '<p class="text-danger">Unable to load participants</p>';
            });
    }
    
    // Dynamically load Select2 assets (CSS + JS + bootstrap4 theme) when needed.
    // Returns a Promise that resolves when Select2 is available.
    function loadSelect2IfNeeded(){
        return new Promise((resolve, reject) => {
            try{
                if (window.jQuery && jQuery.fn && jQuery.fn.select2) return resolve();

                console.log('loadSelect2IfNeeded: starting');
                // Load CSS if not present
                const cssHref = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css';
                const themeHref = 'https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css';
                if (!document.querySelector(`link[href*="select2.min.css"]`)){
                    const l = document.createElement('link'); l.rel='stylesheet'; l.href = cssHref; document.head.appendChild(l);
                }
                if (!document.querySelector(`link[href*="select2-bootstrap4.min.css"]`)){
                    const l2 = document.createElement('link'); l2.rel='stylesheet'; l2.href = themeHref; document.head.appendChild(l2);
                }

                // If jQuery is missing, the page already includes jQuery via layout; if not, fail
                if (!window.jQuery) {
                    return reject(new Error('jQuery not present'));
                }

                // Load Select2 JS if not loaded
                if (jQuery.fn && jQuery.fn.select2) return resolve();

                const scriptSrc = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js';
                if (document.querySelector(`script[src*="select2.min.js"]`)){
                    console.log('loadSelect2IfNeeded: select2 script tag already present, waiting for registration');
                    // wait for it to become available
                    const maxWait = 3000; let waited = 0;
                    const iv = setInterval(()=>{
                        if (jQuery.fn && jQuery.fn.select2){ clearInterval(iv); console.log('loadSelect2IfNeeded: select2 is now available'); resolve(); }
                        waited += 100; if (waited >= maxWait){ clearInterval(iv); console.error('loadSelect2IfNeeded: timeout waiting for select2'); reject(new Error('select2 load timeout')); }
                    }, 100);
                    return;
                }

                const s = document.createElement('script');
                s.src = scriptSrc;
                s.onload = function(){
                    console.log('loadSelect2IfNeeded: select2 script loaded');
                    // small delay to ensure plugin registers
                    setTimeout(()=>{
                        if (jQuery.fn && jQuery.fn.select2) { console.log('loadSelect2IfNeeded: select2 registered'); resolve(); }
                        else { console.error('loadSelect2IfNeeded: select2 not registered after load'); reject(new Error('select2 not registered')); }
                    }, 50);
                };
                s.onerror = function(){ console.error('loadSelect2IfNeeded: failed to load select2 script'); reject(new Error('Failed to load select2')); };
                document.head.appendChild(s);
            }catch(e){ reject(e); }
        });
    }

    // Initialize Select2 for all <select> elements inside the Record Results modal.
    // This preserves names/values and sets dropdownParent to the modal to work inside Bootstrap modals.
    function initSelect2ForKeputusanModal(){
        // Ensure Select2 library is loaded, then initialize selects inside modal
        function _doInit(){
            try{
                console.log('initSelect2ForKeputusanModal: _doInit start');
                if (!window.jQuery || !jQuery.fn) { console.warn('initSelect2ForKeputusanModal: jQuery not present'); return; }
                if (!jQuery.fn.select2) { console.log('initSelect2ForKeputusanModal: select2 not loaded yet'); return; }
                const $modal = jQuery('#modalKeputusan');
                if (!$modal.length) { console.warn('initSelect2ForKeputusanModal: modal element not found'); return; }

                const $selects = $modal.find('select');
                console.log('initSelect2ForKeputusanModal: found selects count=', $selects.length);
                $selects.each(function(){
                    const $el = jQuery(this);
                    // If kategori is still disabled, skip initializing it here — we'll initialize it after options load
                    try{
                        if (($el.attr('id') || $el.attr('name')) === 'keputusanKategori' && this.disabled) {
                            console.log('initSelect2ForKeputusanModal: skipping kategori init because it is disabled');
                            return; // continue
                        }
                    }catch(e){}
                    // Destroy existing Select2 to avoid duplication
                    if ($el.data('select2')){
                        try{ console.log('initSelect2ForKeputusanModal: destroying existing select2 for', $el.attr('id') || $el.attr('name')); $el.select2('destroy'); }catch(e){ console.warn(e); }
                    }

                    // Initialize with dropdownParent set to modal to avoid z-index clipping
                    try{
                        $el.select2({ dropdownParent: $modal, width: '100%', theme: 'bootstrap4' });
                        console.log('initSelect2ForKeputusanModal: initialized select2 for', $el.attr('id') || $el.attr('name'));
                        // Ensure Select2 selections trigger the native change handler for keputusanSukan
                        try{
                            if (($el.attr('id') || '') === 'keputusanSukan'){
                                $el.on('select2:select', function(e){
                                    console.log('select2:select fired for keputusanSukan, dispatching native change');
                                    try{
                                        const el = $el[0];
                                        if (el && typeof el.dispatchEvent === 'function') {
                                            el.dispatchEvent(new Event('change', { bubbles: true }));
                                        } else {
                                            $el.trigger('change');
                                        }
                                    }catch(err){ $el.trigger('change'); }
                                });
                            }
                        }catch(e){ /* ignore */ }
                    }catch(e){ console.error('initSelect2ForKeputusanModal: select2 init error for', $el.attr('id') || $el.attr('name'), e); }
                });
            }catch(e){/* ignore */}
        }

        // If select2 already present, init immediately
        if (window.jQuery && jQuery.fn && jQuery.fn.select2) {
            _doInit();
            return;
        }

        // Otherwise load Select2 CSS/JS dynamically (only once)
        loadSelect2IfNeeded().then(_doInit).catch(()=>{/* ignore load failure */});
    }

    // Initialize single-date picker (if daterangepicker plugin is available)
    // Date filter removed; no datepicker loader required

    function generateStandingsTable(participants){
        // generation token to avoid duplicate bindings/initializations when called multiple times
        const genToken = String(Date.now());
        const optionHtml = '<option value="">Select Participant</option>' +
            participants.map(p => `<option value="${p.id}" data-kontinjen="${p.kontinjen_id || ''}">${p.display_name || p.nama || p.nama_pasukan}</option>`).join('');
        
        let tableHtml = '<div class="table-responsive"><table class="table table-sm table-bordered">';
        tableHtml += '<thead><tr><th style="width: 80px;">Position</th><th>Participant</th></tr></thead>';
        tableHtml += '<tbody>';
        
        for(let i = 1; i <= participantCount; i++){
            const isRequired = i <= 3;
            const requiredAttr = isRequired ? 'required' : '';
            const requiredLabel = isRequired ? ' <span class="text-danger">*</span>' : '';
            tableHtml += `<tr>
                <td class="align-middle">${i}${requiredLabel}</td>
                <td>
                    <select class="form-select form-select-sm standings-select" data-position="${i}" data-gen="${genToken}" name="standing_${i}" ${requiredAttr}>
                        ${optionHtml}
                    </select>
                </td>
            </tr>`;
        }
        
        tableHtml += '</tbody></table></div>';
        tableHtml += '<small class="text-muted"><span class="text-danger">*</span> Required for first, second and third places only</small>';
        standingsContainer.innerHTML = tableHtml;
        
        // Add event listeners to all newly created selects (guarded by generation token)
        const selects = standingsContainer.querySelectorAll('.standings-select');
        selects.forEach(select => {
            try{
                if (select.dataset.gen !== genToken) return; // skip old selects
                if (select.dataset.bound === '1') return; // already bound
                select.addEventListener('change', function(){ updateParticipantDropdowns(this); });
                select.dataset.bound = '1';
            }catch(e){ /* ignore */ }
        });
        
        // Initialize Select2 for newly created selects inside the modal (loader will fetch library when needed)
        try{
            // Ensure Select2 is available then specifically initialize standings-selects
            loadSelect2IfNeeded().then(()=>{
                try{
                    const $modal = jQuery('#modalKeputusan');
                    // Only initialize selects created by this generation (avoid re-initializing existing ones)
                    const $stands = $modal.find(`.standings-select`).filter(function(){ return jQuery(this).data('gen') === genToken; });
                    console.log('generateStandingsTable: initializing select2 for standings-select count=', $stands.length);
                    $stands.each(function(idx){
                        const $s = jQuery(this);
                        try{
                            if ($s.data('select2-init-done')) { console.log('generateStandingsTable: select2 already initialized for idx=', idx); return; }
                        }catch(e){}
                        try{
                            // Initialize Select2 only if not already present
                            if (!$s.data('select2')){
                                $s.select2({ dropdownParent: $modal, width: '100%', theme: 'bootstrap4' });
                            }
                            $s.data('select2-init-done', true);
                            const has = !!$s.data('select2');
                            const cont = $s.next('.select2-container');
                            console.log('generateStandingsTable: init result for idx=', idx, 'hasSelect2=', has, 'containerExists=', !!(cont && cont.length));
                            // Ensure select2 events update participant disabling (attach once)
                            try{
                                $s.off('select2:select.select2-change select2:unselect.select2-change change.select2-change');
                                $s.on('select2:select.select2-change select2:unselect.select2-change change.select2-change', function(e){
                                    try{ updateParticipantDropdowns(this); }catch(err){ console.warn('select2 change hook failed', err); }
                                });
                            }catch(e){ console.warn('attach select2 events failed', e); }
                        }catch(e){ console.warn('standings-select init failed', e); }
                    });

                    // Retry shortly after only for selects that are still missing initialization
                    setTimeout(()=>{
                        try{
                            const $retries = $modal.find('.standings-select').filter(function(){ return !jQuery(this).data('select2-init-done') && jQuery(this).data('gen') === genToken; });
                            $retries.each(function(idx){
                                const $s = jQuery(this);
                                try{ console.log('generateStandingsTable: retry init for standings-select idx=', idx); if (!$s.data('select2')){ $s.select2({ dropdownParent: $modal, width: '100%', theme: 'bootstrap4' }); }
                                    try{ $s.off('select2:select.select2-change select2:unselect.select2-change change.select2-change'); $s.on('select2:select.select2-change select2:unselect.select2-change change.select2-change', function(e){ try{ updateParticipantDropdowns(this); }catch(err){} }); }catch(e){}
                                    $s.data('select2-init-done', true);
                                }catch(e){ console.warn('retry init failed', e); }
                            });
                        }catch(e){}
                    }, 120);
                }catch(e){ console.warn('generateStandingsTable select2 init error', e); }
            }).catch((err)=>{ console.warn('generateStandingsTable: loadSelect2IfNeeded failed', err); });
        }catch(e){/* ignore */}
    }
    
    // Pagination state
    var currentPage = 1;
    var pageSize = 10; // default page size (requested)

    // Small HTML escape helper used in JS rendering
    function escapeHtml(str){
        return (str||'').toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Render a participant or members list for printable views
    function renderNamesList(item){
        try{
            if (!item) return '-';
            var content = '';
            if (Array.isArray(item)){
                if (item.length === 0) return '-';
                if (item.length === 1) content = escapeHtml(item[0]);
                else {
                    var list = '<ol style="margin:0;padding-left:18px">';
                    item.forEach(function(n){ list += '<li>' + escapeHtml(n) + '</li>'; });
                    list += '</ol>';
                    content = list;
                }
            } else {
                content = escapeHtml(item);
            }
            return '<div style="margin-top:18px;padding-bottom:8px;display:block">' + content + '</div>';
        }catch(e){ console.warn('renderNamesList error', e); return '<div style="margin-top:18px;padding-bottom:8px;display:block">' + escapeHtml(String(item||'-')) + '</div>'; }
    }

    function updatePager(total){
        const pager = document.getElementById('keputusanPager');
        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        if(currentPage > totalPages) currentPage = totalPages;

        const start = (currentPage - 1) * pageSize + 1;
        const end = Math.min(total, currentPage * pageSize);
        pager.innerHTML = `
            <div class="text-muted small">Showing ${start}–${end} of ${total}</div>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="pagerPrev" ${currentPage===1? 'disabled': ''}>Previous</button>
                <button class="btn btn-outline-secondary" id="pagerNext" ${currentPage===totalPages? 'disabled': ''}>Next</button>
            </div>
        `;

        pager.querySelector('#pagerPrev').addEventListener('click', ()=>{ if(currentPage>1){ currentPage--; renderKeputusan(latestKeputusanData); } });
        pager.querySelector('#pagerNext').addEventListener('click', ()=>{ if(currentPage<totalPages){ currentPage++; renderKeputusan(latestKeputusanData); } });
    }

    function renderKeputusan(rows){
        latestKeputusanData = Array.isArray(rows) ? rows : [];
        const total = latestKeputusanData.length;

        keputusanBody.innerHTML = '';
        if(total === 0){
            noRow.style.display = '';
            document.getElementById('keputusanPager').innerHTML = '';
            return;
        }
        noRow.style.display = 'none';

        const totalPages = Math.max(1, Math.ceil(total / pageSize));
        if(currentPage > totalPages) currentPage = totalPages;

        const startIdx = (currentPage - 1) * pageSize;
        const slice = latestKeputusanData.slice(startIdx, startIdx + pageSize);

        slice.forEach((r, i)=>{
            const tr = document.createElement('tr');

            // Row number (global index)
            const globalIdx = startIdx + i + 1;

            // Winner name and kontinjen short (prefer server-provided `kontingen_short_name`)
            var winnerDisplay = '<span class="text-muted">-</span>';
            var kontingenShort = '';
            if (r.standings && Array.isArray(r.standings) && r.standings.length > 0) {
                const winner = r.standings[0];
                const fullName = (winner.participant_name || winner.nama || winner.nama_pasukan || winner.participant || winner.participant_id || '').toString();

                // Prefer kontingen_short_name returned by server
                if (winner.kontingen_short_name && String(winner.kontingen_short_name).trim() !== '') {
                    kontingenShort = String(winner.kontingen_short_name).trim();
                } else {
                    // Fallback: try to split 'Name - KOD' pattern
                    const parts = fullName.split(' - ');
                    if (parts.length > 1) {
                        kontingenShort = parts[parts.length - 1];
                    }
                }

                // Display winner name without kontingen suffix when possible
                const nameParts = fullName.split(' - ');
                if (nameParts.length > 1) {
                    winnerDisplay = escapeHtml(nameParts.slice(0, nameParts.length - 1).join(' - '));
                } else {
                    winnerDisplay = escapeHtml(fullName || '');
                }
            }

            // New column: button to open full results
            const fullBtn = `<button class="btn btn-sm btn-outline-primary open-full-btn" data-id="${r.id}">Full Results</button>`;

            // Render top-3 winners inside Name column (names only; medal icons moved to rank column in modal)
            const winners = Array.isArray(r.standings) ? r.standings.slice(0,3) : [];
            const namaLines = [];
            for (var p = 1; p <= 3; p++) {
                const w = (r.standings || []).find(x => parseInt(x.position) === p) || winners[p-1] || null;
                if (w && (w.participant_display_name || w.participant_name || w.nama || w.nama_pasukan)) {
                    const display = w.participant_display_name || w.participant_name || w.nama || w.nama_pasukan || '';
                    const pretty = escapeHtml(display.replace(/\s-\s/, ', '));
                    namaLines.push(`<div>${pretty}</div>`);
                }
            }
            const namaHtml = namaLines.length ? namaLines.join('') : '<span class="text-muted">-</span>';

            tr.innerHTML = `
                <td class="align-top text-center">${globalIdx}</td>
                <td class="align-top">${escapeHtml(englishSportLabel(r.sukan || ''))}</td>
                <td class="align-top">${escapeHtml(englishSportLabel(r.kategori || r.acara || ''))}</td>
                <td class="align-top"><div class="text-truncate" style="max-width:100%">${namaHtml}</div></td>
                <td class="align-top text-center">${fullBtn}</td>
                <td class="align-top"><span class="badge bg-${r.status === 'completed' ? 'success' : r.status === 'ongoing' ? 'warning' : 'info'}">${escapeHtml(r.status || '')}</span></td>
                <td class="align-top"></td>`;

            // Highlight row if this sport is marked complete (read from option data attribute)
            try{
                var sid = r.sukan_id || r.sukanId || r.sukanId || r.sukan_id === 0 ? r.sukan_id : null;
                if (sid) {
                    var opt = document.querySelector('#filterSport option[value="' + sid + '"]') || document.querySelector('#keputusanSukan option[value="' + sid + '"]');
                    if (opt && opt.getAttribute && opt.getAttribute('data-complete') === '1') {
                        tr.classList.add('sukan-complete');
                    }
                }
            }catch(e){}

            keputusanBody.appendChild(tr);
        });

        // Attach click handlers for full result buttons
        document.querySelectorAll('.open-full-btn').forEach(btn=>{
            btn.addEventListener('click', function(){ openFullKeputusan(this.getAttribute('data-id')); });
        });

        updatePager(total);
    }

    // Modal paging state
    var modalStandings = [];
    var modalCurrentPage = 1;
    const modalPageSize = 10; // default as requested
    var currentModalSukan = '';
    var currentModalAcara = '';

    function renderModalStandingsPage(){
        const modalEl = document.getElementById('modalKeputusanFull');
        const tbody = modalEl.querySelector('table tbody');
        const pager = document.getElementById('modalKeputusanPager');
        tbody.innerHTML = '';

        const total = modalStandings.length;
        if(total === 0){
            tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No results to display</td></tr>';
            pager.innerHTML = '';
            return;
        }

        const totalPages = Math.max(1, Math.ceil(total / modalPageSize));
        if(modalCurrentPage > totalPages) modalCurrentPage = totalPages;

        const start = (modalCurrentPage - 1) * modalPageSize;
        const slice = modalStandings.slice(start, start + modalPageSize);

                slice.forEach(s=>{
                    const tr = document.createElement('tr');
                    var posNum = parseInt(s.position) || 0;

                    const displayRaw = (s.participant_display_name || s.participant_name || s.nama || s.nama_pasukan || s.participant || s.participant_id || '-').toString();
                    const parts = displayRaw.split(' - ');
                    const namePretty = displayRaw.replace(/\s-\s/, ', ');
                    const medalMap = {1: '🥇', 2: '🥈', 3: '🥉'};
                    // Rank cell: show medal icon for 1-3, otherwise the numeric position
                    const rankCell = medalMap[posNum] ? `<span class="medal-icon">${medalMap[posNum]}</span>` : escapeHtml(String(posNum));
                    const nameDisplay = escapeHtml(namePretty);

                    tr.innerHTML = `<td class="align-top text-center">${rankCell}</td>` +
                                   `<td class="align-top">${escapeHtml(currentModalSukan || (s.sukan || ''))}</td>` +
                                   `<td class="align-top">${escapeHtml(currentModalAcara || (s.kategori || s.acara || ''))}</td>` +
                                   `<td class="align-top"><span class="text-truncate" data-bs-toggle="tooltip" title="${escapeHtml(namePretty)}">${nameDisplay}</span></td>`;
                    tbody.appendChild(tr);
                });

        // pager
        pager.innerHTML = `
            <div class="text-muted small">Showing ${start+1}–${Math.min(total, start+modalPageSize)} of ${total}</div>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-outline-secondary" id="modalPagerPrev" ${modalCurrentPage===1? 'disabled': ''}>Previous</button>
                <button class="btn btn-outline-secondary" id="modalPagerNext" ${modalCurrentPage===totalPages? 'disabled': ''}>Next</button>
            </div>
        `;

        pager.querySelector('#modalPagerPrev').addEventListener('click', ()=>{ if(modalCurrentPage>1){ modalCurrentPage--; renderModalStandingsPage(); } });
        pager.querySelector('#modalPagerNext').addEventListener('click', ()=>{ if(modalCurrentPage<totalPages){ modalCurrentPage++; renderModalStandingsPage(); } });

        // init tooltips inside modal
        if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
            modalEl.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el=>{ try{ new bootstrap.Tooltip(el); }catch(e){} });
        }
    }

    function openFullKeputusan(id){
        if(!latestKeputusanData || latestKeputusanData.length === 0){ console.warn('No cached data available for full modal'); return; }
        const rec = latestKeputusanData.find(r => String(r.id) === String(id));
        if(!rec){ console.warn('Record not found in cached data for id', id); return; }

        modalStandings = Array.isArray(rec.standings) ? rec.standings : [];
        modalCurrentPage = 1;
        currentModalSukan = rec.sukan || '';
        currentModalAcara = rec.kategori || rec.acara || '';

        // Set title
        const titleEl = document.getElementById('modalKeputusanFullTitle');
        titleEl.textContent = `Full Results – ${currentModalSukan} – ${currentModalAcara}`;

        renderModalStandingsPage();

        const modalEl = document.getElementById('modalKeputusanFull');
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            const modalInstance = new bootstrap.Modal(modalEl, {backdrop: true, keyboard: true});
            modalInstance.show();
        } else {
            modalEl.classList.add('show'); modalEl.style.display = 'block'; document.body.classList.add('modal-open');
        }
    }

    // Build printable HTML from current filter data and open in new window
    function generatePrintableView(){
        try{
            const data = Array.isArray(latestKeputusanData) ? latestKeputusanData : [];
            const rawLogo = <?php echo json_encode(asset('img/logos/UA/UPNM.svg')); ?>;
            function absolutizeAsset(p){
                try{
                    if (!p) return p;
                    // if this is an assets path, prefer inlined data URI when available
                    try{
                        if (typeof INLINE_ASSETS !== 'undefined' && p) {
                            var rel = null;
                            if (p.indexOf('/assets/') === 0) rel = p.replace(/^\/assets\//, '');
                            else if (p.indexOf('assets/') === 0) rel = p.replace(/^assets\//, '');
                            if (rel && INLINE_ASSETS[rel]) return INLINE_ASSETS[rel];
                        }
                    }catch(ee){}

                    if (/^https?:\/\//i.test(p)) return p;
                    if (p.indexOf('//') === 0) return window.location.protocol + p;
                    if (p.charAt(0) === '/') return window.location.origin + p;
                    return window.location.origin + '/' + p.replace(/^\.\//, '');
                }catch(e){ return p; }
            }
            const logo = absolutizeAsset(rawLogo);
            const titlePrimary = 'Sukan Asasi Malaysia 2026 Competition Results';
            const titleSecondary = 'Universiti Pertahanan Nasional Malaysia';

            // Group by sport
            const bySport = {};
            data.forEach(function(r){
                const sport = r.sukan || 'Unknown';
                if (!bySport[sport]) bySport[sport] = [];
                bySport[sport].push(r);
            });
            // Build printable HTML using requested 3-row result structure per event
            let html = '<!doctype html><html><head><meta charset="utf-8"><title>' + escapeHtml(titlePrimary) + '</title>';
            html += '<style>' +
                'body{font-family:Arial,Helvetica,sans-serif;color:#111;margin:18px}' +
                'header{display:flex;align-items:center;margin-bottom:12px}' +
                'header img{height:60px;margin-right:10px}' +
                'h1{font-size:16px;margin:0}' +
                'h2{font-size:12px;margin:0;color:#333;margin-top:4px}' +
                'h3{margin-top:14px;margin-bottom:8px;font-size:13px}' +
                'table{width:100%;border-collapse:collapse;margin-bottom:12px;border-spacing:0 !important}' +
                'th,td{border:1px solid #000 !important;padding:6px;font-size:12px;vertical-align:top;-webkit-print-color-adjust:exact;color-adjust:exact;background-color:#fff}' +
                'th{background:#f3f3f3;text-align:left}' +
                'td.name-cell{white-space:pre-wrap;padding-top:10px;padding-bottom:6px}' +
                '.sport-block{page-break-inside:avoid;margin-bottom:18px}' +
                'thead{display:table-header-group}' +
                'tr{page-break-inside:avoid}' +
                'td,th{page-break-inside:avoid}' +
                'td,th{box-shadow: inset -1px 0 0 #000, inset 0 -1px 0 #000}' +
                '</style>';
            html += '</head><body>';
            html += '<header><img src="' + logo + '" alt="UPNM logo"> <div><h1>' + escapeHtml(titlePrimary) + '</h1><h2>' + escapeHtml(titleSecondary) + '</h2></div></header>';

            // Icon mapping for sports by sukan_id
            const iconMap = {
                '1': 'badminton.png',
                '2': 'bola-jaring.png',
                '3': 'volleyball.png',
                '4': 'catur.png',
                '5': 'bola-sepak.png',
                '6': 'ragbi.png',
                '7': 'takraw.png',
                '8': 'mlbb-pubg.png',
                '9': 'mlbb-pubg.png',
                '10': 'tenpin-bowling.png',
                '11': 'olahraga.png'
            };

            Object.keys(bySport).forEach(function(sport, sidx){
                const rows = bySport[sport];
                // Header must be uppercase and formatted as: SPORT [NAMA SUKAN]
                html += '<div class="sport-block" style="' + (sidx>0? 'page-break-before:always;' : '') + '"><h3>' + 'SPORT [' + escapeHtml(String(sport).toUpperCase()) + ']' + '</h3>';
                // Table header: Bil (5%), Sport (10%), Event Name (15%), Results (20%), ATHLETE NAME (50%)
                html += '<table style="width:100%;border-collapse:collapse;border:1px solid #000">' +
                         '<thead>';
                html += '<tr>' +
                         '<th style="width:5%;text-align:center;vertical-align:top;border:1px solid #000">No.</th>' +
                         '<th style="width:10%;text-align:center;vertical-align:top;border:1px solid #000">Sport</th>' +
                         '<th style="width:15%;text-align:left;vertical-align:top;border:1px solid #000">Event Name</th>' +
                         '<th style="width:20%;text-align:left;vertical-align:top;border:1px solid #000">Results</th>' +
                         '<th style="width:50%;text-align:left;vertical-align:top;border:1px solid #000">ATHLETE NAME</th>' +
                         '</tr>';
                html += '</thead><tbody>';

                rows.forEach(function(r, idx){
                    const no = idx + 1;
                    const acara = r.kategori || r.acara || '-';
                    const sukanId = r.sukan_id || r.sukanId || '';
                    // Prepare winner entries for positions 1..3
                    const posMap = {};
                    if (Array.isArray(r.standings)){
                        r.standings.forEach(function(s){ posMap[String(s.position)] = s; });
                    }

                    // Helper to render names as vertical numbered list (ol)
                    function renderNamesList(item){
                        if (!item) return '-';
                        try{
                            var content = '';
                            if (Array.isArray(item)){
                                if (item.length === 0) return '-';
                                if (item.length === 1) content = escapeHtml(item[0]);
                                else {
                                    var list = '<ol style="margin:0;padding-left:18px">';
                                    item.forEach(function(n){ list += '<li>' + escapeHtml(n) + '</li>'; });
                                    list += '</ol>';
                                    content = list;
                                }
                            } else {
                                content = escapeHtml(item);
                            }
                            return '<div style="margin-top:18px;padding-bottom:8px;display:block">' + content + '</div>';
                        }catch(e){ return '<div style="margin-top:18px;padding-bottom:8px;display:block">' + escapeHtml(String(item||'-')) + '</div>'; }
                    }

                    // Row 1 (Gold Medal Winner)
                    const w1 = posMap['1'] || {};
                    html += '<tr>';
                    html += '<td rowspan="3" style="vertical-align:top;width:5%;text-align:center;border:1px solid #000">' + no + '</td>';
                    // Sport icon cell (rowspan 3)
                    var iconFile = iconMap[String(sukanId)] || 'default.png';
                    var iconRel = 'img/sukan/' + iconFile;
                    var iconPath = (typeof INLINE_ASSETS !== 'undefined' && INLINE_ASSETS[iconRel]) ? INLINE_ASSETS[iconRel] : (window.location.origin + (SITE_BASE || '') + '/assets/img/sukan/' + iconFile);
                    html += '<td rowspan="3" style="vertical-align:top;width:10%;text-align:center;border:1px solid #000"><img src="' + iconPath + '" alt="' + escapeHtml(sport) + '" style="display:inline-block;margin:0 auto;max-height:40px;max-width:100%;object-fit:contain"></td>';
                    html += '<td rowspan="3" style="vertical-align:top;width:15%">' + escapeHtml(acara) + '</td>';
                    html += '<td style="width:20%;vertical-align:top"><strong>Gold Medal Winner</strong><div style="margin-top:6px">' + escapeHtml(w1.kontingen_short_name || w1.participant_name || w1.participant_display_name || '-') + '</div></td>';
                    // names: render ordered list; if team members present use members array, else use participant_display_name
                    var nm1 = (Array.isArray(w1.members) && w1.members.length>0) ? w1.members : (w1.participant_display_name || w1.participant_name || '-');
                    html += '<td style="width:50%;vertical-align:top">' + renderNamesList(nm1) + '</td>';
                    html += '</tr>';

                    // Row 2 (Silver Medal Winner)
                    const w2 = posMap['2'] || {};
                    html += '<tr>';
                    html += '<td style="vertical-align:top"><strong>Silver Medal Winner</strong><div style="margin-top:6px">' + escapeHtml(w2.kontingen_short_name || w2.participant_name || w2.participant_display_name || '') + '</div></td>';
                    var nm2 = (Array.isArray(w2.members) && w2.members.length>0) ? w2.members : (w2.participant_display_name || w2.participant_name || '-');
                    html += '<td style="vertical-align:top">' + renderNamesList(nm2) + '</td>';
                    html += '</tr>';

                    // Row 3 (Bronze Medal Winner)
                    const w3 = posMap['3'] || {};
                    html += '<tr>';
                    html += '<td style="vertical-align:top"><strong>Bronze Medal Winner</strong><div style="margin-top:6px">' + escapeHtml(w3.kontingen_short_name || w3.participant_name || w3.participant_display_name || '') + '</div></td>';
                    var nm3 = (Array.isArray(w3.members) && w3.members.length>0) ? w3.members : (w3.participant_display_name || w3.participant_name || '-');
                    html += '<td style="vertical-align:top">' + renderNamesList(nm3) + '</td>';
                    html += '</tr>';
                });

                html += '</tbody></table></div>';
            });

            html += '</body></html>';

            // Write HTML into a hidden iframe and trigger print immediately (no visible preview window)
            const iframe = document.createElement('iframe');
            iframe.style.position = 'fixed';
            iframe.style.right = '0';
            iframe.style.bottom = '0';
            iframe.style.width = '0';
            iframe.style.height = '0';
            iframe.style.border = '0';
            iframe.style.visibility = 'hidden';
            document.body.appendChild(iframe);
            try{
                const idoc = iframe.contentWindow || iframe.contentDocument;
                idoc.document.open();
                idoc.document.write(html);
                idoc.document.close();
                var __printed = false;
                iframe.onload = function(){
                    try{
                        const doc = iframe.contentDocument || iframe.contentWindow.document;
                        const images = Array.from(doc.getElementsByTagName('img') || []);
                        let toLoad = images.length;
                        function finishPrint(){ if (__printed) return; __printed = true; try{ iframe.contentWindow.focus(); iframe.contentWindow.print(); }catch(e){ alert('Printing failed: ' + (e && e.message ? e.message : e)); } setTimeout(()=>{ try{ document.body.removeChild(iframe); }catch(e){} }, 1000); }
                        if (toLoad === 0){ finishPrint(); return; }
                        // safety timeout in case images fail to load
                        const timer = setTimeout(function(){ clearListeners(); finishPrint(); }, 3500);
                        function clearListeners(){ images.forEach(function(im){ try{ im.removeEventListener('load', onLoaded); im.removeEventListener('error', onLoaded); }catch(e){} }); }
                        function onLoaded(){ try{ toLoad = Math.max(0, toLoad-1); if (toLoad === 0){ clearTimeout(timer); clearListeners(); finishPrint(); } }catch(e){} }
                        images.forEach(function(im){ try{ if (im.complete) { onLoaded(); } else { im.addEventListener('load', onLoaded); im.addEventListener('error', onLoaded); } }catch(e){} });
                    }catch(e){ try{ __printed = true; iframe.contentWindow.focus(); iframe.contentWindow.print(); }catch(err){ alert('Printing failed: ' + (err && err.message ? err.message : err)); } setTimeout(()=>{ try{ document.body.removeChild(iframe); }catch(e){} }, 1000); }
                };
                // Fallback attempt in case onload doesn't fire quickly — only if not already printed
                setTimeout(function(){ try{ if (!__printed && iframe.contentWindow) { __printed = true; iframe.contentWindow.print(); } }catch(e){} }, 600);
            }catch(err){
                console.error('Printing via iframe failed, falling back to new window', err);
                const w = window.open('', '_blank');
                if (!w) { alert('Pop-up blocked. Please allow pop-ups for this site.'); return; }
                w.document.open();
                w.document.write(html);
                w.document.close();
            }
        }catch(err){
            console.error('generatePrintableView error', err);
            alert('Unable to build the print view: ' + (err && err.message ? err.message : '')); 
        }
    }

    function buildExportTableHtml(data){
        const rows = Array.isArray(data) ? data : [];
        let html = '<table border="1" cellspacing="0" cellpadding="6" style="border-collapse:collapse;width:100%">';
        html += '<thead><tr>'
             + '<th style="width:5%">#</th>'
             + '<th style="width:15%">Sport</th>'
             + '<th style="width:20%">Event</th>'
             + '<th style="width:15%">Position</th>'
             + '<th style="width:45%">Participant / Team Name</th>'
             + '</tr></thead><tbody>';

        rows.forEach(function(r, i){
            const sukan = escapeHtml(englishSportLabel(r.sukan || ''));
            const acara = escapeHtml(englishSportLabel(r.kategori || r.acara || ''));
            const st = Array.isArray(r.standings) ? r.standings : [];
            const byPos = {};
            st.forEach(function(x){
                const p = parseInt(x.position, 10) || 0;
                if (p > 0 && p <= 3) byPos[p] = x;
            });
            const medalNames = {1:'Gold', 2:'Silver', 3:'Bronze'};
            let firstRow = true;
            [1,2,3].forEach(function(pos){
                const w = byPos[pos] || null;
                var disp = '-';
                if (w) {
                    if (Array.isArray(w.members) && w.members.length > 0) {
                        disp = w.members.map(function(n, idx){ return (idx + 1) + '. ' + (n || ''); }).join('\n');
                    } else {
                        disp = (w.participant_display_name || w.participant_name || w.nama || w.nama_pasukan || '-');
                    }
                }
                html += '<tr>';
                if (firstRow){
                    html += '<td rowspan="3" style="text-align:center;vertical-align:top">' + (i + 1) + '</td>';
                    html += '<td rowspan="3" style="vertical-align:top">' + sukan + '</td>';
                    html += '<td rowspan="3" style="vertical-align:top">' + acara + '</td>';
                    firstRow = false;
                }
                html += '<td style="vertical-align:top">Pemenang ' + medalNames[pos] + '</td>';
                html += '<td style="vertical-align:top;white-space:pre-wrap">' + escapeHtml(disp) + '</td>';
                html += '</tr>';
            });
        });

        if (rows.length === 0){
            html += '<tr><td colspan="5" style="text-align:center;color:#666">No results</td></tr>';
        }
        html += '</tbody></table>';
        return html;
    }

    function downloadBlob(filename, mimeType, content){
        const blob = new Blob([content], { type: mimeType });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        setTimeout(function(){
            try{ document.body.removeChild(a); }catch(e){}
            URL.revokeObjectURL(url);
        }, 0);
    }

    function generateWordExport(){
        var html = '';
        try {
            html = generateOfficialReportAndEvents({ returnHtml: true, forWord: true }) || '';
        } catch (e) {
            console.warn('Word export official builder failed, fallback to simple table', e);
        }
        if (!html) {
            const data = Array.isArray(latestKeputusanData) ? latestKeputusanData : [];
            const title = 'Results Report Competition';
            const body = '<h2 style="margin:0 0 12px 0">' + title + '</h2>' + buildExportTableHtml(data);
            html = '<!doctype html><html><head><meta charset="utf-8"><title>' + title + '</title></head><body>' + body + '</body></html>';
        }
        const fn = 'keputusan_' + (new Date().toISOString().slice(0,10)) + '.doc';
        downloadBlob(fn, 'application/msword', html);
    }

    function generateExcellExport(){
        const data = Array.isArray(latestKeputusanData) ? latestKeputusanData : [];
        const title = 'Results Report Competition';
        const body = '<h2 style="margin:0 0 12px 0">' + title + '</h2>' + buildExportTableHtml(data);
        const html = '<!doctype html><html><head><meta charset="utf-8"><title>' + title + '</title></head><body>' + body + '</body></html>';
        const fn = 'keputusan_' + (new Date().toISOString().slice(0,10)) + '.xls';
        downloadBlob(fn, 'application/vnd.ms-excel', html);
    }

    // Build an official single-page A4 landscape report (medal table + stacked bar),
    // then append the per-event pages and print all together.
    // Master kontinjen list and computed statistics (server-side)
    var MASTER_KONTINJEN = <?php
        try{
            $pdo = getDB();

            // all kontinjen names
            $stmt = $pdo->prepare("SELECT COALESCE(u.nama_universiti, k.kod_universiti) AS nama_universiti FROM table_kontinjen k LEFT JOIN table_ref_universiti u ON k.kod_universiti = u.kod_universiti AND u.deleted_at IS NULL WHERE k.deleted_at IS NULL AND k.status = 1 ORDER BY nama_universiti ASC");
            $stmt->execute();
            $allKontinjen = $stmt->fetchAll(PDO::FETCH_COLUMN);

            // count kontingen and sukan
            $jumlah_kontinjen = (int)$pdo->query("SELECT COUNT(*) FROM table_kontinjen WHERE deleted_at IS NULL AND status = 1")->fetchColumn();
            $jumlah_sukan = (int)$pdo->query("SELECT COUNT(*) FROM table_sukan WHERE deleted_at IS NULL AND status = 1")->fetchColumn();

            // acara counts
            $jumlah_acara = (int)$pdo->query("SELECT COUNT(*) FROM table_kategori WHERE deleted_at IS NULL AND status = 1")->fetchColumn();
            $jumlah_acara_individu = (int)$pdo->query("SELECT COUNT(*) FROM table_kategori WHERE deleted_at IS NULL AND status = 1 AND penilaian = 'individu'")->fetchColumn();
            $jumlah_acara_berpasukan = (int)$pdo->query("SELECT COUNT(*) FROM table_kategori WHERE deleted_at IS NULL AND status = 1 AND penilaian != 'individu'")->fetchColumn();

            // count venues: prefer dedicated `table_ref_venues` if present, otherwise try category columns
            $jumlah_venue = 0;
            try{
                $tblChk = $pdo->query("SHOW TABLES LIKE 'table_ref_venues'");
                if ($tblChk && $tblChk->rowCount() > 0) {
                    $jumlah_venue = (int)$pdo->query("SELECT COUNT(*) FROM table_ref_venues WHERE deleted_at IS NULL AND status = 1")->fetchColumn();
                } else {
                    // fallback: attempt to detect venue-like column in table_kategori
                    $venueCols = ['venue_id','lokasi','tempat','tempat_pertandingan','venue'];
                    foreach($venueCols as $vc){
                        $chk = $pdo->query("SHOW COLUMNS FROM table_kategori LIKE '" . $vc . "'");
                        if ($chk && $chk->rowCount()>0){
                            $c = $pdo->query("SELECT COUNT(DISTINCT " . $vc . ") FROM table_kategori WHERE " . $vc . " IS NOT NULL AND deleted_at IS NULL")->fetchColumn();
                            $jumlah_venue = (int)$c; break;
                        }
                    }
                }
            }catch(Exception $e){ $jumlah_venue = 0; }

            // participation counts
            $jumlah_pasukan = (int)$pdo->query("SELECT COUNT(*) FROM table_pasukan WHERE deleted_at IS NULL AND status = 1")->fetchColumn();
            // Count unique athletes: prefer national id, then matric number, then (pasukan_id + name) fallback
            $jumlah_atlet = (int)$pdo->query(
                "SELECT COUNT(DISTINCT COALESCE(NULLIF(no_kad_pengenalan,''), NULLIF(no_matrik,''), CONCAT(pasukan_id,'::',nama))) FROM table_pasukan_atlet WHERE deleted_at IS NULL"
            )->fetchColumn();

            // try to count distinct pengurus/jurulatih from table_pasukan if columns exist
            // Count unique managers and coaches from their dedicated tables (if present)
            $jumlah_pengurus = 0; $jumlah_jurulatih = 0;
            try{
                $tbl = $pdo->query("SHOW TABLES LIKE 'table_pasukan_pengurus'");
                if ($tbl && $tbl->rowCount()>0){
                    $jumlah_pengurus = (int)$pdo->query("SELECT COUNT(DISTINCT COALESCE(NULLIF(no_kad_pengenalan,''), NULLIF(emel,''), CONCAT(pasukan_id,'::',nama))) FROM table_pasukan_pengurus WHERE deleted_at IS NULL")->fetchColumn();
                }
                $tbl2 = $pdo->query("SHOW TABLES LIKE 'table_pasukan_jurulatih'");
                if ($tbl2 && $tbl2->rowCount()>0){
                    $jumlah_jurulatih = (int)$pdo->query("SELECT COUNT(DISTINCT COALESCE(NULLIF(no_kad_pengenalan,''), NULLIF(emel,''), CONCAT(pasukan_id,'::',nama))) FROM table_pasukan_jurulatih WHERE deleted_at IS NULL")->fetchColumn();
                }
            }catch(Exception $e){ $jumlah_pengurus = 0; $jumlah_jurulatih = 0; }

            // compute medal totals and medal-winning kontinjen
            $total_emas = $total_perak = $total_gangsa = 0;
            $medalSet = [];
            $rs = $pdo->query("SELECT standings FROM table_results WHERE deleted_at IS NULL AND status = 'completed'");
            if ($rs) {
                foreach ($rs as $rw) {
                    $s = json_decode($rw['standings'], true);
                    if (!is_array($s)) continue;
                    foreach ($s as $entry) {
                        $pos = isset($entry['position']) ? (int)$entry['position'] : 0;
                        if ($pos === 1) $total_emas++;
                        else if ($pos === 2) $total_perak++;
                        else if ($pos === 3) $total_gangsa++;
                        $pid = isset($entry['participant_id']) ? (int)$entry['participant_id'] : 0;
                        if (!$pid) continue;
                        // try team -> kontinjen
                        $st = $pdo->prepare("SELECT COALESCE(u.nama_universiti, k.kod_universiti) AS nama_universiti FROM table_pasukan p JOIN table_kontinjen k ON p.kontinjen_id = k.id LEFT JOIN table_ref_universiti u ON k.kod_universiti = u.kod_universiti AND u.deleted_at IS NULL WHERE p.id = :pid AND p.deleted_at IS NULL AND k.deleted_at IS NULL LIMIT 1");
                        $st->execute([':pid'=>$pid]); $kn = $st->fetchColumn();
                        if ($kn) { $medalSet[$kn]=true; continue; }
                        // athlete
                        $st2 = $pdo->prepare("SELECT COALESCE(u.nama_universiti, k.kod_universiti) AS nama_universiti FROM table_pasukan_atlet pa JOIN table_pasukan p ON pa.pasukan_id = p.id JOIN table_kontinjen k ON p.kontinjen_id = k.id LEFT JOIN table_ref_universiti u ON k.kod_universiti = u.kod_universiti AND u.deleted_at IS NULL WHERE pa.id = :pid AND pa.deleted_at IS NULL AND p.deleted_at IS NULL AND k.deleted_at IS NULL LIMIT 1");
                        $st2->execute([':pid'=>$pid]); $kn2 = $st2->fetchColumn();
                        if ($kn2) { $medalSet[$kn2]=true; continue; }
                        // kontinjen id
                        $st3 = $pdo->prepare("SELECT COALESCE(u.nama_universiti, k.kod_universiti) AS nama_universiti FROM table_kontinjen k LEFT JOIN table_ref_universiti u ON k.kod_universiti = u.kod_universiti AND u.deleted_at IS NULL WHERE k.id = :pid AND k.deleted_at IS NULL LIMIT 1");
                        $st3->execute([':pid'=>$pid]); $kn3 = $st3->fetchColumn(); if ($kn3) { $medalSet[$kn3]=true; }
                    }
                }
            }
            $medalKontinjen = array_keys($medalSet);
        }catch(Exception $e){
            $allKontinjen = []; $medalKontinjen = []; $jumlah_kontinjen = $jumlah_sukan = $jumlah_acara = $jumlah_acara_individu = $jumlah_acara_berpasukan = $jumlah_venue = $jumlah_pasukan = $jumlah_atlet = $jumlah_pengurus = $jumlah_jurulatih = $total_emas = $total_perak = $total_gangsa = 0;
        }
        // output master kontinjen list
        echo json_encode($allKontinjen, JSON_UNESCAPED_UNICODE);
    ?>;
    var MEDAL_KONTINJEN = <?php echo json_encode(isset($medalKontinjen)? $medalKontinjen: [], JSON_UNESCAPED_UNICODE); ?>;
    var STATS = <?php echo json_encode([
        'jumlah_kontinjen' => isset($jumlah_kontinjen)? (int)$jumlah_kontinjen : 0,
        'jumlah_sukan' => isset($jumlah_sukan)? (int)$jumlah_sukan : 0,
        'jumlah_venue' => isset($jumlah_venue)? (int)$jumlah_venue : 0,
        'jumlah_acara' => isset($jumlah_acara)? (int)$jumlah_acara : 0,
        'jumlah_acara_individu' => isset($jumlah_acara_individu)? (int)$jumlah_acara_individu : 0,
        'jumlah_acara_berpasukan' => isset($jumlah_acara_berpasukan)? (int)$jumlah_acara_berpasukan : 0,
        'jumlah_pasukan' => isset($jumlah_pasukan)? (int)$jumlah_pasukan : 0,
        'jumlah_atlet' => isset($jumlah_atlet)? (int)$jumlah_atlet : 0,
        'jumlah_pengurus' => isset($jumlah_pengurus)? (int)$jumlah_pengurus : 0,
        'jumlah_jurulatih' => isset($jumlah_jurulatih)? (int)$jumlah_jurulatih : 0,
        'total_emas' => isset($total_emas)? (int)$total_emas : 0,
        'total_perak' => isset($total_perak)? (int)$total_perak : 0,
        'total_gangsa' => isset($total_gangsa)? (int)$total_gangsa : 0
    ], JSON_UNESCAPED_UNICODE); ?>;

    function generateOfficialReportAndEvents(opts){
        try{
            opts = opts || {};
            const isWordExport = !!opts.forWord;
            const data = Array.isArray(latestKeputusanData) ? latestKeputusanData : [];

            // Aggregate medals by kontingen_short_name (fallback to participant_display_name)
            const agg = {}; // key -> {kontinjen, emas, perak, gangsa}
            data.forEach(function(r){
                if (!r || r.status !== 'completed') return;
                const standings = Array.isArray(r.standings) ? r.standings : [];
                standings.forEach(function(s){
                    const pos = parseInt(s.position) || 0;
                    // determine kontingen key
                    const key = (s.kontingen_short_name && String(s.kontingen_short_name).trim()) || (s.participant_display_name && String(s.participant_display_name).trim()) || 'LAIN-LAIN';
                    if (!agg[key]) agg[key] = {kontinjen: key, emas:0, perak:0, gangsa:0};
                    if (pos === 1) agg[key].emas++;
                    else if (pos === 2) agg[key].perak++;
                    else if (pos === 3) agg[key].gangsa++;
                });
            });

            // Convert to array and compute totals
            const aggArr = Object.keys(agg).map(k=>{ const o=agg[k]; o.jumlah = o.emas+o.perak+o.gangsa; return o; });
            // Sort: emas desc, perak desc, gangsa desc
            aggArr.sort((a,b)=>{ if (b.emas !== a.emas) return b.emas - a.emas; if (b.perak !== a.perak) return b.perak - a.perak; return b.gangsa - a.gangsa; });

            // Build HTML for official page
            let html = '<!doctype html><html><head><meta charset="utf-8"><title>Overall Championship Medal Report</title>';
            html += '<style>' +
                    '@page{size:A4 landscape;margin:18mm}' +
                    '@media print{body{margin:0}}' +
                    'body{font-family:Arial,Helvetica,sans-serif;color:#111;margin:18px}' +
                    'h1{font-size:18px;text-align:center;font-weight:700;margin:6px 0 8px}' +
                    'p.intro{font-size:12px;text-align:left;margin:6px 0 12px}' +
                    'svg.chart{display:block;margin:6px auto}' +
                    'table.summary{width:100%;border-collapse:collapse;font-size:11px;margin-top:10px;border-spacing:0 !important}' +
                    'table.summary th,table.summary td{border:1px solid #000 !important;padding:6px;text-align:left;vertical-align:top;-webkit-print-color-adjust:exact;color-adjust:exact;background-color:#fff}' +
                        'table.summary th{background:#f3f3f3;font-weight:700}' +
                    'div.footer{font-size:12px;margin-top:10px}' +
                    '.page-break{page-break-after:always}' +
                    'thead{display:table-header-group}' +
                    'tr{page-break-inside:avoid}' +
                    'table.summary th,table.summary td{box-shadow: inset -1px 0 0 #000, inset 0 -1px 0 #000}' +
                    '</style>';
            if (isWordExport) {
                html += '<style>body,table,th,td,p,div,li,span{font-size:12px !important;line-height:1.35 !important;} h1{font-size:16px !important;} h2{font-size:13px !important;} h3{font-size:12px !important;}</style>';
            }
            html += '</head><body>';
            // three logos: left KPT, center event logo, right UPNM
            const logoKPT = (typeof INLINE_ASSETS !== 'undefined' && INLINE_ASSETS['img/logos/UA/kpt.png']) ? INLINE_ASSETS['img/logos/UA/kpt.png'] : (window.location.origin + (SITE_BASE || '') + '/assets/img/logos/UA/kpt.png');
            const logoEvent = (typeof INLINE_ASSETS !== 'undefined' && INLINE_ASSETS['img/logos/logo-print.png']) ? INLINE_ASSETS['img/logos/logo-print.png'] : (window.location.origin + (SITE_BASE || '') + '/assets/img/logos/logo-print.png');
            const logoUPNM = (typeof INLINE_ASSETS !== 'undefined' && INLINE_ASSETS['img/logos/UA/UPNM.svg']) ? INLINE_ASSETS['img/logos/UA/UPNM.svg'] : (window.location.origin + (SITE_BASE || '') + '/assets/img/logos/UA/UPNM.svg');
            if (isWordExport) {
                // Word export: remove header logos to avoid renderer size inconsistencies.
            } else {
                html += '<div style="display:flex;justify-content:center;align-items:center;margin-bottom:8px">' +
                            '<img src="' + logoKPT + '" alt="KPT" style="height:56px;width:auto;max-height:56px;max-width:180px;margin-right:18px">' +
                            '<img src="' + logoEvent + '" alt="Logo Sport" style="height:70px;width:auto;max-height:70px;max-width:220px;margin:0 18px">' +
                            '<img src="' + logoUPNM + '" alt="UPNM" style="height:56px;width:auto;max-height:56px;max-width:180px;margin-left:18px">' +
                        '</div>';
            }
            html += '<h1>OVERALL CHAMPIONSHIP MEDAL REPORT</h1>';
            html += '<p class="intro">This report presents overall medal achievements based on the official medal count from confirmed results. The analysis covers the medal totals for each contingent.</p>';

            // Add compact statistical summaries (Championship Profile, Participation Profile, Summary Prestasi)
            var profilHtml = '';
            try{
                const s = (typeof STATS !== 'undefined') ? STATS : {};
                const totalMedals = (s.total_emas||0) + (s.total_perak||0) + (s.total_gangsa||0);
                // Build Championship Profile
                profilHtml = '<div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:8px">';
                profilHtml += '<div style="flex:1;min-width:200px;border:1px solid #ddd;padding:8px;font-size:12px">';
                profilHtml += '<strong>Championship Profile</strong><ul style="margin:6px 0 0 14px;padding:0;list-style:disc;font-size:12px">';
                profilHtml += '<li>Total Contingents: ' + (s.jumlah_kontinjen||0) + '</li>';
                profilHtml += '<li>Total Sports: ' + (s.jumlah_sukan||0) + '</li>';
                profilHtml += '<li>Competition Venues: ' + (s.jumlah_venue||0) + '</li>';
                profilHtml += '<li>Competition Events: ' + (s.jumlah_acara||0) + '</li>';
                profilHtml += '<li>Individual Events: ' + (s.jumlah_acara_individu||0) + '</li>';
                profilHtml += '<li>Team Events: ' + (s.jumlah_acara_berpasukan||0) + '</li>';
                profilHtml += '</ul></div>';

                // Participation Profile
                profilHtml += '<div style="flex:1;min-width:200px;border:1px solid #ddd;padding:8px;font-size:12px">';
                profilHtml += '<strong>Participation Profile</strong><ul style="margin:6px 0 0 14px;padding:0;list-style:disc;font-size:12px">';
                profilHtml += '<li>Total Teams: ' + (s.jumlah_pasukan||0) + '</li>';
                profilHtml += '<li>Total Athletes: ' + (s.jumlah_atlet||0) + '</li>';
                profilHtml += '<li>Total Managers: ' + (s.jumlah_pengurus||0) + '</li>';
                profilHtml += '<li>Total Coaches: ' + (s.jumlah_jurulatih||0) + '</li>';
                profilHtml += '</ul>';
                profilHtml += '<div style="font-size:11px;color:#444;margin-top:6px">Athlete, manager and coach counts are based on contingent registrations, regardless of the number of events entered.</div>';
                profilHtml += '</div>';

                // Overall Performance Summary
                profilHtml += '<div style="flex:1;min-width:220px;border:1px solid #ddd;padding:8px;font-size:12px">';
                profilHtml += '<strong>Overall Performance Summary</strong>';
                profilHtml += '<div style="margin-top:6px">';
                profilHtml += '<div>Total Gold Medals: ' + (s.total_emas||0) + '</div>';
                profilHtml += '<div>Total Silver Medals: ' + (s.total_perak||0) + '</div>';
                profilHtml += '<div>Total Bronze Medals: ' + (s.total_gangsa||0) + '</div>';
                profilHtml += '<div style="margin-top:8px;font-size:11px"><strong>Percentage of Medals by Contingent</strong><ol style="margin:6px 0 0 14px;padding:0;font-size:11px">';
                // Use aggArr to compute percentages
                try{
                    const total = totalMedals || aggArr.reduce((acc,a)=> acc + (a.emas||0) + (a.perak||0) + (a.gangsa||0), 0);
                    // list all kontinjen contributions
                    aggArr.forEach(function(d){
                        const k = escapeHtml(d.kontinjen || '-');
                        const c = ( (d.emas||0) + (d.perak||0) + (d.gangsa||0) );
                        const pct = total>0 ? Math.round((c/total)*100) : 0;
                        profilHtml += '<li>' + k + ': ' + c + ' (' + pct + '%)</li>';
                    });
                }catch(e){ profilHtml += '<li>No medal data</li>'; }
                profilHtml += '</ol></div>';
                profilHtml += '</div></div>';
                profilHtml += '</div>';
                // Append profile summaries as FIRST PAGE (followed by page break)
                try{ html += '<div style="page-break-after:always">' + profilHtml + '</div>'; }catch(e){ console.warn('Failed append profilHtml', e); }
            }catch(e){ console.warn('Failed to render profil summaries', e); }

            // Chart area: stacked vertical bars
            const chartWidth = 1000;
            const chartHeight = 360; // extra space for rotated labels
            const padding = {top:20,right:20,bottom:90,left:60};
            const plotW = chartWidth - padding.left - padding.right;
            const plotH = chartHeight - padding.top - padding.bottom;

            // find max total for scaling
            const maxTotal = aggArr.reduce((m,a)=>Math.max(m,a.jumlah), 1);
            const barGap = 8;
            // compute barWidth so bars do not overlap; ensure a minimum
            const available = Math.max(1, plotW - (aggArr.length - 1) * barGap);
            let barWidth = Math.floor(available / Math.max(1, aggArr.length));
            if (barWidth < 6) barWidth = Math.max(4, Math.floor(plotW / Math.max(1, aggArr.length)));

            // muted professional colors
            const colorGold = '#C69214';
            const colorSilver = '#9EA7B0';
            const colorBronze = '#B66A40';

            // Build SVG
            let svg = '<svg class="chart" width="' + chartWidth + '" height="' + chartHeight + '" xmlns="http://www.w3.org/2000/svg">';
            svg += '<rect x="0" y="0" width="100%" height="100%" fill="white"/>';
            // Y axis ticks
            const ticks = 5;
            for(let i=0;i<=ticks;i++){
                const y = padding.top + Math.round(plotH * (i / ticks));
                const val = Math.round(maxTotal * (1 - i / ticks));
                svg += '<text x="' + (padding.left-8) + '" y="' + (y+4) + '" font-size="11" text-anchor="end" fill="#333">' + val + '</text>';
                svg += '<line x1="' + padding.left + '" y1="' + y + '" x2="' + (padding.left+plotW) + '" y2="' + y + '" stroke="#eee" stroke-width="1"/>';
            }

            // Bars
            aggArr.forEach(function(d, i){
                const total = d.jumlah;
                const x = padding.left + i * (barWidth + barGap);
                // heights proportional
                const hTotal = Math.round((total / maxTotal) * plotH);
                // draw bronze at bottom, silver middle, gold top (stacked)
                const hBronze = Math.round((d.gangsa / Math.max(1,maxTotal)) * plotH);
                const hPerak = Math.round((d.perak / Math.max(1,maxTotal)) * plotH);
                const hEmas = Math.round((d.emas / Math.max(1,maxTotal)) * plotH);

                let yCursor = padding.top + plotH;
                // bronze
                if (hBronze>0){ yCursor -= hBronze; svg += '<rect x="' + x + '" y="' + yCursor + '" width="' + barWidth + '" height="' + hBronze + '" fill="' + colorBronze + '"/>'; }
                // perak
                if (hPerak>0){ yCursor -= hPerak; svg += '<rect x="' + x + '" y="' + yCursor + '" width="' + barWidth + '" height="' + hPerak + '" fill="' + colorSilver + '"/>'; }
                // emas
                if (hEmas>0){ yCursor -= hEmas; svg += '<rect x="' + x + '" y="' + yCursor + '" width="' + barWidth + '" height="' + hEmas + '" fill="' + colorGold + '"/>'; }

                // labels: kontijen name rotated slightly to avoid overlapping
                const labelX = x + Math.round(barWidth/2);
                const labelY = padding.top + plotH + 40;
                const lbl = escapeHtml(d.kontinjen);
                svg += '<text x="' + labelX + '" y="' + labelY + '" font-size="10" text-anchor="middle" fill="#111" transform="rotate(-40 ' + labelX + ' ' + labelY + ')">' + lbl + '</text>';
            });

            svg += '</svg>';
            // Insert chart as SECOND PAGE
            html += '<div style="page-break-after:always;text-align:center">' + svg + '</div>';

            // Summary table
                html += '<table class="summary"><thead><tr>' +
                    '<th style="width:8%;text-align:center;text-transform:uppercase">Position</th>' +
                    '<th style="width:52%;text-align:left;text-transform:uppercase">Contingent</th>' +
                    '<th style="width:10%;text-align:center;text-transform:uppercase">Gold</th>' +
                    '<th style="width:10%;text-align:center;text-transform:uppercase">Silver</th>' +
                    '<th style="width:10%;text-align:center;text-transform:uppercase">Bronze</th>' +
                    '<th style="width:10%;text-align:center;text-transform:uppercase">Total</th>' +
                    '</tr></thead><tbody>';
            aggArr.forEach(function(d, i){
                html += '<tr>' +
                        '<td style="text-align:center">' + (i+1) + '</td>' +
                        '<td style="text-align:left">' + escapeHtml(d.kontinjen) + '</td>' +
                        '<td style="text-align:center">' + d.emas + '</td>' +
                        '<td style="text-align:center">' + d.perak + '</td>' +
                        '<td style="text-align:center">' + d.gangsa + '</td>' +
                        '<td style="text-align:center">' + d.jumlah + '</td>' +
                        '</tr>';
            });
            html += '</tbody></table>';

            // NOTA: list kontinjen without any medals using master and medal lists computed server-side
            (function(){
                const master = Array.isArray(MASTER_KONTINJEN) ? MASTER_KONTINJEN.map(m=> (m||'').toString().trim()).filter(Boolean) : [];
                const medal = Array.isArray(MEDAL_KONTINJEN) ? MEDAL_KONTINJEN.map(m=> (m||'').toString().trim()).filter(Boolean) : [];
                const zero = master.filter(function(m){
                    return !medal.some(function(mm){ return (mm||'').toString().trim().toLowerCase() === (m||'').toString().trim().toLowerCase(); });
                });
                if (zero.length > 0) {
                    html += '<p style="margin-top:10px;font-size:12px"><strong>NOTE:</strong> Contingents with no medals in the Sukan Asasi Malaysia 2026 championship: ' + zero.map(escapeHtml).join(', ') + '.</p>';
                } else {
                    html += '<p style="margin-top:10px;font-size:12px"><strong>NOTE:</strong> All contingents have won medals.</p>';
                }
            })();

            // concluding statement
            html += '<div class="footer">Official standings are ranked by gold medals, followed by silver and then bronze medals in the event of a tie.</div>';

            // finalization note
            html += '<p style="margin-top:12px;font-size:12px;font-style:italic">Official results were finalised at 6:00 PM on 1 February 2026</p>';
            // page break after this official page
            html += '<div class="page-break"></div>';

            // Append per-event pages (reusing earlier per-event rendering)
            // Group by sport and build event tables similar to previous printable view
            const bySport = {};
            data.forEach(function(r){
                const sport = r.sukan || 'Unknown';
                if (!bySport[sport]) bySport[sport] = [];
                bySport[sport].push(r);
            });
            Object.keys(bySport).forEach(function(sport, sidx){
                const rows = bySport[sport];
                html += '<div class="sport-block" style="' + (sidx>0? 'page-break-before:always;' : '') + '"><h3>SPORT [' + escapeHtml(String(sport).toUpperCase()) + ']' + '</h3>';
                html += '<table style="width:100%;border-collapse:collapse;margin-bottom:12px"><thead>';
                html += '<tr><th style="width:5%;text-align:center;vertical-align:top">No.</th><th style="width:10%;text-align:center;vertical-align:top">Sport</th><th style="width:15%;text-align:left;vertical-align:top">Event Name</th><th style="width:20%;text-align:left;vertical-align:top">Results</th><th style="width:50%;text-align:left;vertical-align:top">ATHLETE NAME</th></tr>';
                html += '</thead><tbody>';
                rows.forEach(function(r, idx){
                    const no = idx + 1;
                    const acara = r.kategori || r.acara || '-';
                    const sukanId = r.sukan_id || r.sukanId || '';
                    const posMap = {};
                    if (Array.isArray(r.standings)) r.standings.forEach(function(s){ posMap[String(s.position)] = s; });
                    const w1 = posMap['1'] || {};
                    const nm1 = (Array.isArray(w1.members) && w1.members.length>0) ? w1.members : (w1.participant_display_name || w1.participant_name || '-');
                    // First row: Bil, Sport icon, Event Name, Gold Medal Winner, Names
                    html += '<tr>';
                    html += '<td style="vertical-align:top;width:5%;text-align:center;border:1px solid #000">' + no + '</td>';
                    var iconFile = '';
                    switch(String(sukanId)){
                        case '1': iconFile='badminton.png'; break; case '2': iconFile='bola-jaring.png'; break; case '3': iconFile='volleyball.png'; break; case '4': iconFile='catur.png'; break; case '5': iconFile='bola-sepak.png'; break; case '6': iconFile='ragbi.png'; break; case '7': iconFile='takraw.png'; break; case '8': case '9': iconFile='mlbb-pubg.png'; break; case '10': iconFile='tenpin-bowling.png'; break; case '11': iconFile='olahraga.png'; break; default: iconFile='default.png'; }
                    // Prefer inlined asset when available; otherwise build absolute URL using SITE_BASE
                    if (isWordExport) {
                        html += '<td style="vertical-align:top;width:10%;text-align:center;border:1px solid #000">-</td>';
                    } else {
                        var iconRel = 'img/sukan/' + iconFile;
                        var iconPath = (typeof INLINE_ASSETS !== 'undefined' && INLINE_ASSETS[iconRel]) ? INLINE_ASSETS[iconRel] : (window.location.origin + (SITE_BASE || '') + '/assets/img/sukan/' + iconFile);
                        html += '<td style="vertical-align:top;width:10%;text-align:center;border:1px solid #000"><img src="' + iconPath + '" style="display:inline-block;margin:0 auto;max-height:40px;max-width:100%;object-fit:contain"></td>';
                    }
                    html += '<td style="vertical-align:top;width:15%;text-align:left;border:1px solid #000">' + escapeHtml(acara) + '</td>';
                    html += '<td style="width:20%;vertical-align:top;text-align:left;border:1px solid #000"><strong>Gold Medal Winner</strong><div style="margin-top:6px">' + escapeHtml(w1.kontingen_short_name || w1.participant_display_name || '-') + '</div></td>';
                    html += '<td style="width:50%;vertical-align:top;text-align:left;border:1px solid #000">' + renderNamesList(nm1) + '</td>';
                    html += '</tr>';
                    const w2 = posMap['2'] || {};
                    const nm2 = (Array.isArray(w2.members) && w2.members.length>0) ? w2.members : (w2.participant_display_name || w2.participant_name || '-');
                    // Second row: empty first 3 cols, Silver
                    html += '<tr>';
                    html += '<td style="vertical-align:top;width:5%;text-align:left;border:1px solid #000"></td>';
                    html += '<td style="vertical-align:top;width:10%;text-align:left;border:1px solid #000"></td>';
                    html += '<td style="vertical-align:top;width:15%;text-align:left;border:1px solid #000"></td>';
                    html += '<td style="vertical-align:top;text-align:left;border:1px solid #000"><strong>Silver Medal Winner</strong><div style="margin-top:6px">' + escapeHtml(w2.kontingen_short_name || w2.participant_display_name || '') + '</div></td>';
                    html += '<td style="vertical-align:top;text-align:left;border:1px solid #000">' + renderNamesList(nm2) + '</td>';
                    html += '</tr>';
                    const w3 = posMap['3'] || {};
                    const nm3 = (Array.isArray(w3.members) && w3.members.length>0) ? w3.members : (w3.participant_display_name || w3.participant_name || '-');
                    // Third row: empty first 3 cols, Bronze
                    html += '<tr>';
                    html += '<td style="vertical-align:top;width:5%;text-align:left;border:1px solid #000"></td>';
                    html += '<td style="vertical-align:top;width:10%;text-align:left;border:1px solid #000"></td>';
                    html += '<td style="vertical-align:top;width:15%;text-align:left;border:1px solid #000"></td>';
                    html += '<td style="vertical-align:top;text-align:left;border:1px solid #000"><strong>Bronze Medal Winner</strong><div style="margin-top:6px">' + escapeHtml(w3.kontingen_short_name || w3.participant_display_name || '') + '</div></td>';
                    html += '<td style="vertical-align:top;text-align:left;border:1px solid #000">' + renderNamesList(nm3) + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
            });

            html += '</body></html>';

            if (opts && opts.returnHtml) {
                return html;
            }

            // Print via hidden iframe
            const iframe = document.createElement('iframe');
            iframe.style.position = 'fixed'; iframe.style.right='0'; iframe.style.bottom='0'; iframe.style.width='0'; iframe.style.height='0'; iframe.style.border='0'; iframe.style.visibility='hidden'; document.body.appendChild(iframe);
            try{
                const idoc = iframe.contentWindow || iframe.contentDocument;
                idoc.document.open(); idoc.document.write(html); idoc.document.close();
                var __printed_official = false;
                iframe.onload = function(){ try{ __printed_official = true; iframe.contentWindow.focus(); iframe.contentWindow.print(); }catch(e){ alert('Official report printing failed: '+(e&&e.message?e.message:e)); } setTimeout(()=>{ try{ document.body.removeChild(iframe); }catch(e){} },1200); };
                setTimeout(function(){ try{ if (!__printed_official && iframe.contentWindow) { __printed_official = true; iframe.contentWindow.print(); } }catch(e){} },800);
            }catch(err){ console.error('Official print failed', err); alert('Unable to prepare the official report'); }
        }catch(err){ console.error('generateOfficialReportAndEvents error', err); if (!(opts && opts.returnHtml)) alert('Unable to build the official report: ' + (err && err.message ? err.message : '')); return ''; }
    }
    
    function loadKeputusan(){
        const params = new URLSearchParams();
        if(sportSel.value) params.set('sukan_id', sportSel.value);
        if(kategoriSel.value) params.set('kategori_id', kategoriSel.value);
        if(statusSel.value) params.set('status', statusSel.value);
        
        const url = <?php echo json_encode(url("ajax/keputusan_list_fixed.php") . '?'); ?> + params.toString();
        console.log('Loading keputusan from:', url);
        
        fetchJSON(url)
            .then(res=>{
                console.log('Results response:', res);
                if(res && res.success){
                    console.log('Found', res.data ? res.data.length : 0, 'results');
                    renderKeputusan(res.data || []);
                } else {
                    console.warn('No success or invalid response:', res);
                    renderKeputusan([]);
                }
            })
            .catch(err=>{
                console.error('Failed to fetch keputusan', err);
                renderKeputusan([]);
            });
    }
    
    function showAddKeputusan(){
        formKeputusan.reset();
        keputusanId.value = '';
        document.getElementById('modalKeputusanTitle').textContent = 'Record New Result';
        keputusanKategori.disabled = true;
        keputusanKategori.innerHTML = '<option value="">Select a category</option>';
        standingsContainer.innerHTML = '<p class="text-muted">Select a category first to load the participant list</p>';
        currentCategoryType = null;
        currentParticipants = [];
        participantCount = 0;
        
        const modalEl = document.getElementById('modalKeputusan');
        if (modalEl.parentElement !== document.body) document.body.appendChild(modalEl);
        
        if (typeof coreui !== 'undefined' && coreui.Modal) {
            modalKeputusanInstance = new coreui.Modal(modalEl, {backdrop: true, keyboard: true, focus: true});
            modalKeputusanInstance.show();
            try{ initSelect2ForKeputusanModal(); }catch(e){}
        } else if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            modalKeputusanInstance = new bootstrap.Modal(modalEl, {backdrop: true, keyboard: true, focus: true});
            modalKeputusanInstance.show();
            try{ initSelect2ForKeputusanModal(); }catch(e){}
        } else {
            modalEl.classList.add('show');
            modalEl.style.display = 'block';
            document.body.classList.add('modal-open');
            try{ initSelect2ForKeputusanModal(); }catch(e){}
        }
        
        // If sport is already selected, load categories and check for existing results
        if (keputusanSukan.value) {
            loadKategori(keputusanSukan.value, keputusanKategori);
        }
    }
    
    function closeKeputusanModal(){
        const modalEl = document.getElementById('modalKeputusan');
        if (modalKeputusanInstance && typeof modalKeputusanInstance.hide === 'function') {
            modalKeputusanInstance.hide();
        } else if (typeof coreui !== 'undefined' && coreui.Modal) {
            const inst = coreui.Modal.getInstance(modalEl);
            if (inst) inst.hide();
        } else if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            const inst = bootstrap.Modal.getInstance(modalEl);
            if (inst) inst.hide();
        } else {
            modalEl.classList.remove('show');
            modalEl.style.display = 'none';
            document.body.classList.remove('modal-open');
        }
    }
    
    function editKeputusan(id){
        if (window.Swal) {
            Swal.showLoading();
        }
        
            fetchJSON(<?php echo json_encode(url("ajax/keputusan_list_fixed.php") . '?id='); ?> + id)
            .then(res=>{
                if (window.Swal) Swal.close();
                
                if(res && res.success && res.data && res.data.length > 0){
                    const r = res.data[0];
                    keputusanId.value = r.id;
                    keputusanSukan.value = r.sukan_id || '';
                    keputusanTarikh.value = r.tarikh || '';
                    keputusanStatus.value = r.status || 'completed';
                    
                    document.getElementById('modalKeputusanTitle').textContent = 'Update Results';
                    
                    // Store the standings for later use
                    const standings = r.standings || [];
                    const kategoriId = r.kategori_id || '';
                    
                    // Load kategori for the sport, then set value and load participants
                    loadKategori(r.sukan_id, keputusanKategori).then(()=>{
                        keputusanKategori.value = kategoriId;
                        if(kategoriId){
                            loadParticipants(kategoriId).then(()=>{
                                // Populate standings table with existing data
                                if (standings && standings.length > 0) {
                                    standings.forEach(standing => {
                                        const position = standing.position;
                                        const participantId = standing.participant_id;
                                        const select = standingsContainer.querySelector(`.standings-select[data-position="${position}"]`);
                                        if (select && participantId) {
                                            select.value = participantId;
                                        }
                                    });
                                }
                                
                                // Update dropdowns to disable already selected options
                                updateParticipantDropdowns();

                                // If Select2 is active for kategori, set its value so UI reflects the selection
                                try{
                                    if (window.jQuery && jQuery.fn && jQuery.fn.select2) {
                                        const $k = jQuery(keputusanKategori);
                                        if ($k.data('select2')) {
                                            $k.val(kategoriId).trigger('change');
                                        }
                                    }
                                }catch(e){}
                                
                                const modalEl = document.getElementById('modalKeputusan');
                                if (modalEl.parentElement !== document.body) document.body.appendChild(modalEl);
                                
                                if (typeof coreui !== 'undefined' && coreui.Modal) {
                                    modalKeputusanInstance = new coreui.Modal(modalEl, {backdrop: true, keyboard: true, focus: true});
                                    modalKeputusanInstance.show();
                                    try{ initSelect2ForKeputusanModal(); }catch(e){}
                                } else if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                                    modalKeputusanInstance = new bootstrap.Modal(modalEl, {backdrop: true, keyboard: true, focus: true});
                                    modalKeputusanInstance.show();
                                    try{ initSelect2ForKeputusanModal(); }catch(e){}
                                } else {
                                    modalEl.classList.add('show');
                                    modalEl.style.display = 'block';
                                    document.body.classList.add('modal-open');
                                    try{ initSelect2ForKeputusanModal(); }catch(e){}
                                }
                            });
                        } else {
                            const modalEl = document.getElementById('modalKeputusan');
                            if (modalEl.parentElement !== document.body) document.body.appendChild(modalEl);
                            
                            if (typeof coreui !== 'undefined' && coreui.Modal) {
                                modalKeputusanInstance = new coreui.Modal(modalEl, {backdrop: true, keyboard: true, focus: true});
                                modalKeputusanInstance.show();
                                try{ initSelect2ForKeputusanModal(); }catch(e){}
                            } else if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                                modalKeputusanInstance = new bootstrap.Modal(modalEl, {backdrop: true, keyboard: true, focus: true});
                                modalKeputusanInstance.show();
                                try{ initSelect2ForKeputusanModal(); }catch(e){}
                            } else {
                                modalEl.classList.add('show');
                                modalEl.style.display = 'block';
                                document.body.classList.add('modal-open');
                                try{ initSelect2ForKeputusanModal(); }catch(e){}
                            }
                        }
                    });
                } else {
                    if (window.Swal) {
                        Swal.fire({
                            text: 'Results not found',
                            icon: 'error'
                        });
                    } else {
                        alert('Results not found');
                    }
                }
            })
            .catch(err=>{
                console.error('Failed to fetch keputusan', err);
                if (window.Swal) {
                    Swal.close();
                    Swal.fire({
                        text: 'Unable to load results',
                        icon: 'error'
                    });
                } else {
                    alert('Unable to load results');
                }
            });
    }
    
    function updateParticipantDropdowns(changedSelect = null){
        // prevent re-entrancy
        if (updateParticipantDropdowns._running) return;
        updateParticipantDropdowns._running = true;

        const selects = standingsContainer.querySelectorAll('.standings-select');
        if (!selects || selects.length === 0) return;
        
        // Collect all selected participant values and their kontingen
        const selectedValues = {};
        const selectedKontingen = {};
        selects.forEach(select => {
            const position = parseInt(select.getAttribute('data-position'));
            const value = select.value;
            if (value && value !== '') {
                selectedValues[position] = value;
                // try to read kontingen from selected option data attribute
                try{
                    const opt = select.options[select.selectedIndex];
                    selectedKontingen[position] = opt ? (opt.getAttribute('data-kontinjen') || '') : '';
                }catch(e){ selectedKontingen[position] = ''; }
            }
        });
        
        // If a selection was just changed, check for duplicates
        if (changedSelect) {
            const newValue = changedSelect.value;
            const changedPosition = parseInt(changedSelect.getAttribute('data-position'));
            
            if (newValue && newValue !== '') {
                // Check if this value is already selected in another position
                for (const [pos, val] of Object.entries(selectedValues)) {
                    if (parseInt(pos) !== changedPosition && val === newValue) {
                        // Duplicate found - reset the selection
                        changedSelect.value = '';
                        delete selectedValues[changedPosition];
                        
                        // Show warning
                        if (window.Swal) {
                            Swal.fire({
                                text: `This team or participant is already selected for position ${pos}`,
                                icon: 'warning',
                                timer: 2500,
                                showConfirmButton: false
                            });
                        }
                        break;
                    }
                }
            }
        }
        
        // Re-collect selected values after potential reset
        const finalSelectedValues = {};
        selects.forEach(select => {
            const position = parseInt(select.getAttribute('data-position'));
            const value = select.value;
            if (value && value !== '') {
                finalSelectedValues[position] = value;
            }
        });
        
        // Enable all options first, then disable selected ones in other dropdowns
        selects.forEach(select => {
            const currentPosition = parseInt(select.getAttribute('data-position'));
            const currentValue = select.value;
            
            Array.from(select.options).forEach(opt => {
                // Keep placeholder empty option always enabled
                if (opt.value === '') { opt.disabled = false; opt.style.color = ''; return; }

                // Determine disabling logic based on category type
                try{
                    var optValue = opt.value || '';
                    if (currentCategoryType === 'individu') {
                        // For individual categories, block by individual (participant id), not by kontingen
                        var isSelectedElsewhere = false;
                        for (const [pos, val] of Object.entries(selectedValues)) {
                            if (parseInt(pos) !== currentPosition && val && String(val) === String(optValue)) { isSelectedElsewhere = true; break; }
                        }
                        if (isSelectedElsewhere && opt.value !== currentValue) { opt.disabled = true; opt.style.color = '#999'; }
                        else { opt.disabled = false; opt.style.color = ''; }
                    } else {
                        // For team categories, continue blocking by kontingen
                        var optKont = opt.getAttribute ? (opt.getAttribute('data-kontinjen') || '') : '';
                        var isKontSelectedElsewhere = false;
                        for (const [pos, kont] of Object.entries(selectedKontingen)) {
                            if (parseInt(pos) !== currentPosition && kont && kont === optKont) { isKontSelectedElsewhere = true; break; }
                        }
                        if (isKontSelectedElsewhere && opt.value !== currentValue) { opt.disabled = true; opt.style.color = '#999'; }
                        else { opt.disabled = false; opt.style.color = ''; }
                    }
                }catch(e){ opt.disabled = false; opt.style.color = ''; }
            });
        });

        // If Select2 is used for standings-selects, update them so disabled options are reflected in the UI
        try{
            if (window.jQuery && jQuery.fn && jQuery.fn.select2) {
                const $modal = jQuery('#modalKeputusan');
                const $stands = $modal.find('.standings-select');
                $stands.each(function(){
                    const $s = jQuery(this);
                    // Ensure the underlying <option> disabled states are already set on the native select.
                    // Do NOT destroy and recreate Select2 here (causes race/errors). Instead, trigger Select2 to update.
                    try{
                        // Trigger Select2 change which causes it to re-evaluate available options when opened.
                        $s.trigger('change.select2');
                    }catch(e){
                        console.warn('updateParticipantDropdowns: select2 trigger failed', e);
                        try{ $s.trigger('change'); }catch(e){}
                    }
                });
            }
        }catch(e){
            console.warn('updateParticipantDropdowns: select2 refresh failed', e);
        } finally {
            updateParticipantDropdowns._running = false;
        }
    }
    
    function saveKeputusan(){
        if(!formKeputusan.checkValidity()){
            formKeputusan.reportValidity();
            return;
        }
        
        // Client-side validation: Check if selected category has existing results (only for new records)
        const selectedKategoriId = parseInt(keputusanKategori.value);
        const sukanId = keputusanSukan.value;
        const isNewRecord = !keputusanId.value;
        
        if(isNewRecord && selectedKategoriId && sukanId){
            // Validate category before proceeding
            if (window.Swal) {
                Swal.showLoading();
            }
            
            checkCategoriesWithResults(sukanId, keputusanKategori)
                .then(kategoriWithResults => {
                    if (window.Swal) {
                        Swal.close();
                    }
                    
                    if(kategoriWithResults.includes(selectedKategoriId)){
                        // This category has existing results - prevent saving
                        if (window.Swal) {
                            Swal.fire({
                                text: 'This category already has results. Please select another category.',
                                icon: 'error',
                                timer: 3000
                            });
                        } else {
                            alert('This category already has results. Please select another category.');
                        }
                        return;
                    }
                    
                    // Category is valid, proceed with save
                    performSaveKeputusan();
                })
                .catch(err => {
                    if (window.Swal) {
                        Swal.close();
                    }
                    console.error('Error checking category before save:', err);
                    // If check fails, still proceed (server will validate)
                    performSaveKeputusan();
                });
        } else {
            // No need to check (editing or missing data), proceed with save
            performSaveKeputusan();
        }
    }
    
    function performSaveKeputusan(){
        // Collect standings from dynamic table
        const selects = standingsContainer.querySelectorAll('.standings-select');
        const standings = [];
        const selectedIds = [];
        
        for (var i = 0; i < selects.length; i++) {
            const select = selects[i];
            const position = parseInt(select.getAttribute('data-position'));
            const participantId = select.value ? select.value.trim() : '';
            
            // Only require positions 1-3
            if (!participantId || participantId === '') {
                if (position <= 3) {
                    // Required positions 1-3
                    if (window.Swal) {
                        Swal.fire({
                            text: `Position ${position} is required`,
                            icon: 'warning'
                        });
                    } else {
                        alert(`Position ${position} is required`);
                    }
                    return;
                } else {
                    // Optional positions 4+, skip if empty
                    continue;
                }
            }
            
            // Check for duplicates
            if (selectedIds.includes(participantId)) {
                if (window.Swal) {
                    Swal.fire({
                        text: 'The same team or participant cannot be selected for more than one position',
                        icon: 'warning'
                    });
                } else {
                    alert('The same team or participant cannot be selected for more than one position');
                }
                return;
            }
            
            selectedIds.push(participantId);
            standings.push({
                position: position,
                participant_id: participantId
            });
        }
        
        // Validate required positions (1-3) are filled
        const requiredPositions = [1, 2, 3];
        const filledPositions = standings.map(s => s.position);
        const missingPositions = requiredPositions.filter(pos => !filledPositions.includes(pos));
        
        if (missingPositions.length > 0) {
            if (window.Swal) {
                Swal.fire({
                    text: `Position ${missingPositions.join(', ')} is required`,
                    icon: 'warning'
                });
            } else {
                alert(`Position ${missingPositions.join(', ')} is required`);
            }
            return;
        }
        
        const data = {
            id: keputusanId.value || null,
            sukan_id: keputusanSukan.value,
            kategori_id: keputusanKategori.value,
            tarikh: keputusanTarikh.value,
            standings: standings,
            status: keputusanStatus.value
        };
        
        if (window.Swal) {
            Swal.showLoading();
        }
        
        fetch(<?php echo json_encode(url("ajax/keputusan_save.php")); ?>, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify(data)
        })
        .then(r => r.json())
        .then(res=>{
            if (window.Swal) Swal.close();
            
            if(res.success){
                closeKeputusanModal();
                loadKeputusan();
                
                if (window.Swal) {
                    Swal.fire({
                        text: res.message || 'Result saved successfully',
                        icon: 'success',
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else {
                    alert(res.message || 'Result saved successfully');
                }
            } else {
                if (window.Swal) {
                    Swal.fire({
                        text: res.message || 'Unable to save results',
                        icon: 'error'
                    });
                } else {
                    alert(res.message || 'Unable to save results');
                }
            }
        })
        .catch(err=>{
            console.error('Failed to save keputusan', err);
            if (window.Swal) {
                Swal.close();
                Swal.fire({
                    text: 'Unable to save results',
                    icon: 'error'
                });
            } else {
                alert('Unable to save results');
            }
        });
    }
    
    function deleteKeputusan(id){
        if (window.Swal) {
            Swal.fire({
                title: 'Delete Results?',
                text: 'Are you sure you want to delete this result? This action cannot be undone.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, Delete',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#d33',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    performDelete(id);
                }
            });
        } else {
            if(!confirm('Are you sure you want to delete this result?')){
                return;
            }
            performDelete(id);
        }
    }
    
    function performDelete(id){
        if (window.Swal) {
            Swal.showLoading();
        }
        
        fetch(<?php echo json_encode(url("ajax/keputusan_delete.php")); ?>, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            credentials: 'same-origin',
            body: JSON.stringify({id: id})
        })
        .then(r => r.json())
        .then(res=>{
            if (window.Swal) Swal.close();
            
            if(res.success){
                loadKeputusan();
                
                if (window.Swal) {
                    Swal.fire({
                        text: res.message || 'Result deleted successfully',
                        icon: 'success',
                        timer: 2000,
                        showConfirmButton: false
                    });
                } else {
                    alert(res.message || 'Result deleted successfully');
                }
            } else {
                if (window.Swal) {
                    Swal.fire({
                        text: res.message || 'Unable to delete the result',
                        icon: 'error'
                    });
                } else {
                    alert(res.message || 'Unable to delete the result');
                }
            }
        })
        .catch(err=>{
            console.error('Failed to delete keputusan', err);
            if (window.Swal) {
                Swal.close();
                Swal.fire({
                    text: 'Unable to delete the result',
                    icon: 'error'
                });
            } else {
                alert('Unable to delete the result');
            }
        });
    }
    
    // Note: `openFullKeputusan` and `escapeHtml` are defined earlier; duplicates removed to avoid conflicts

    // Event listeners
    sportSel.addEventListener('change', function(){
        loadKategori(this.value);
        loadKeputusan();
    });
    
    kategoriSel.addEventListener('change', loadKeputusan);
    statusSel.addEventListener('change', loadKeputusan);

    // Datepicker listeners removed because date filter was removed
    
    keputusanSukan.addEventListener('change', function(){
        const sukanId = this.value;
        console.log('keputusanSukan change -> sukanId=', sukanId);
        keputusanKategori.value = '';
        loadParticipants('');
        
        // Load categories - the loadKategori function will automatically check for existing results
        loadKategori(sukanId, keputusanKategori).then(() => {
            // After categories are loaded, update restrictions
            updateCategoryRestrictions();
        });
    });

    // Debug: observe attribute changes on modal kategori select to catch unexpected disables
    try{
    }catch(e){/* ignore */}
    
    // Debounced kategori change handler to avoid duplicate/triple loads
    var _kategoriChangeTimer = null;
    var _kategoriLastValue = null;
    keputusanKategori.addEventListener('change', function(){
        const newVal = this.value;
        console.log('Category changed to (raw):', newVal);
        if (_kategoriLastValue === newVal) {
            console.log('Category value unchanged, ignoring');
            return;
        }
        clearTimeout(_kategoriChangeTimer);
        _kategoriChangeTimer = setTimeout(()=>{
            _kategoriLastValue = newVal;
            console.log('Category changed (debounced):', newVal);
        
        // First check: Check if selected category is disabled (has existing result)
        const selectedOption = this.options[this.selectedIndex];
        if(selectedOption && selectedOption.disabled && this.value !== ''){
            if (window.Swal) {
                Swal.fire({
                    text: 'This category already has results. Please select another category.',
                    icon: 'warning',
                    timer: 3000
                });
            } else {
                alert('This category already has results. Please select another category.');
            }
            this.value = '';
            return;
        }
        
            // Second check: Validate with server if sport is set
            if(newVal && keputusanSukan.value){
                const kategoriId = parseInt(newVal);
                checkCategoriesWithResults(keputusanSukan.value, keputusanKategori)
                    .then(kategoriWithResults => {
                        if(kategoriWithResults.includes(kategoriId)){
                            // This category has existing results - prevent selection
                            if (window.Swal) {
                                Swal.fire({
                                    text: 'This category already has results. Please select another category.',
                                    icon: 'warning',
                                    timer: 3000
                                });
                            } else {
                                alert('This category already has results. Please select another category.');
                            }
                            keputusanKategori.value = '';
                            // Re-update restrictions to ensure UI is correct
                            updateCategoryRestrictions();
                            return;
                        }
                        
                        // Category is valid, load participants
                        loadParticipants(kategoriId);
                    })
                    .catch(err => {
                        console.error('Error checking category:', err);
                        // If check fails, still allow selection but load participants
                        loadParticipants(newVal);
                    });
            } else {
                // No validation needed if sport not set, just load participants
                loadParticipants(newVal);
            }
        }, 120);
    });
    
    // Note: Date change no longer affects category restrictions since we check regardless of date
    // Event listeners for dynamic standings table are added in generateStandingsTable()
    
    // Event listeners for buttons
    document.getElementById('btnSaveKeputusan').addEventListener('click', saveKeputusan);
    // Print button: generate printable view based on current filters
    var printBtn = document.getElementById('btnPrintResults');
    if (printBtn) {
        printBtn.addEventListener('click', function(){
            try{ generateOfficialReportAndEvents(); }catch(e){ console.warn('Print failed', e); }
        });
    }
    var wordBtn = document.getElementById('btnExportWord');
    if (wordBtn) {
        wordBtn.addEventListener('click', function(){
            try{ generateWordExport(); }catch(e){ console.warn('Word export failed', e); }
        });
    }
    
    // Make closeKeputusanModal globally available
    window.closeKeputusanModal = closeKeputusanModal;
    
    // Use event delegation for dynamically created edit/delete buttons
    document.addEventListener('click', function(e){
        if(e.target.closest('.btn-edit-keputusan')){
            const id = e.target.closest('.btn-edit-keputusan').getAttribute('data-id');
            editKeputusan(parseInt(id));
        } else if(e.target.closest('.btn-delete-keputusan')){
            const id = e.target.closest('.btn-delete-keputusan').getAttribute('data-id');
            deleteKeputusan(parseInt(id));
        }
    });
    
    // Initial load
    loadKeputusan();
})();
</script>

<style>
/* Style for disabled options in participant dropdowns */
.standings-select option:disabled {
    color: #999 !important;
    font-style: italic;
    background-color: #f5f5f5;
    cursor: not-allowed;
}

/* Visual indicator for disabled options */
.standings-select option[disabled]:not([value=""]) {
    opacity: 0.6;
}

/* Style for disabled category options */
#keputusanKategori option:disabled {
    color: #999 !important;
    font-style: italic;
    background-color: #fff3cd;
    cursor: not-allowed;
    opacity: 0.7;
}

/* Standings table styling */
#standingsContainer table {
    margin-bottom: 0;
}

#standingsContainer .standings-select {
    min-width: 250px;
}

/* Table fixed layout helpers and truncation */
.table-fixed th, .table-fixed td { overflow: hidden; }
.table-fixed .text-truncate { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block; }

/* Row alignment: data top-left, except first column (#) top-center */
.table-fixed td { vertical-align: top; text-align: left; }
.table-fixed th { vertical-align: top; }
.table-fixed th:first-child, .table-fixed td:first-child { vertical-align: top; text-align: center; }

/* Modal table nowrap enforcement and tooltip name truncation */
#modalKeputusanFullTable th, #modalKeputusanFullTable td { white-space: nowrap; overflow: hidden; }
#modalKeputusanFullTable .text-truncate { max-width: 100%; display: inline-block; vertical-align: middle; }
/* Medal icon styling in rank column */
.medal-icon{font-size:1.1rem;display:inline-block}
</style>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>
