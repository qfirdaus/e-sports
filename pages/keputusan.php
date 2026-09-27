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
<link rel="stylesheet" href="<?php echo asset('css/keputusan.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/keputusan.css'); ?>">
<div class="w-100 px-3 results-view keputusan-view">
    <!-- Hero -->
    <div class="row mb-4 results-heading">
        <div class="col-12">
            <div class="card bg-light border-0 shadow-sm overflow-hidden">
                <div class="card-body py-4 d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                    <div>
                        <div class="results-eyebrow">SAM 2026 &middot; RESULT RECORDS</div><h2 class="mb-1">Results</h2>
                        <p class="text-muted mb-0">Competition results for first, second and third places</p>
                    </div>
                    <button class="btn btn-primary" id="btnAddKeputusan">
                        <i class="cil cil-plus me-1"></i> Record New Result
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="row mb-3 results-filters">
        <div class="col-md-3">
            <label class="results-filter-label" for="filterSport">Sport</label>
            <select class="form-select" id="filterSport">
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
            <select class="form-select" id="filterKategori" disabled>
                <option value="">All Categories</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="results-filter-label" for="filterDate">Date</label>
            <input type="date" class="form-control" id="filterDate">
        </div>
        <div class="col-md-3">
            <label class="results-filter-label" for="filterStatus">Status</label>
            <select class="form-select" id="filterStatus">
                <option value="">All Statuses</option>
                <option value="completed">Completed</option>
                <option value="ongoing">Ongoing</option>
                <option value="upcoming">Upcoming</option>
            </select>
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
<div class="modal fade keputusan-editor" id="modalKeputusan" tabindex="-1" aria-hidden="true">
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
    .sukan-complete td { font-weight: 600; }
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

<script>
(function(){
    const sportSel = document.getElementById('filterSport');
    const kategoriSel = document.getElementById('filterKategori');
    const dateInp = document.getElementById('filterDate');
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
        const url = '<?php echo url("ajax/check_kategori_has_result.php"); ?>?sukan_id=' + encodeURIComponent(sukan_id) + excludeParam;
        
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
        const fetchPromise = fetchJSON('<?php echo url("ajax/get_kategori_by_sukan.php"); ?>?sukan_id=' + encodeURIComponent(sukan_id));
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
        
        const url = '<?php echo url("ajax/get_participants_by_kategori.php"); ?>?kategori_id=' + encodeURIComponent(kategori_id);
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
                <td class="align-middle"><strong>${i}${requiredLabel}</strong></td>
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
                <td class="align-top">
                    <button class="btn btn-sm btn-outline-primary me-1 btn-edit-keputusan" data-id="${r.id}">
                        <i class="fa fa-edit"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger btn-delete-keputusan" data-id="${r.id}">
                        <i class="fa fa-trash"></i>
                    </button>
                </td>`;

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
                                   `<td class="align-top">${escapeHtml(sukan)}</td>` +
                                   `<td class="align-top">${escapeHtml(acara)}</td>` +
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
    
    function loadKeputusan(){
        const params = new URLSearchParams();
        if(sportSel.value) params.set('sukan_id', sportSel.value);
        if(kategoriSel.value) params.set('kategori_id', kategoriSel.value);
        if(dateInp.value) params.set('tarikh', dateInp.value);
        if(statusSel.value) params.set('status', statusSel.value);
        
        const url = '<?php echo url("ajax/keputusan_list.php"); ?>?' + params.toString();
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
        
        fetchJSON('<?php echo url("ajax/keputusan_list.php"); ?>?id=' + id)
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
        
        fetch('<?php echo url("ajax/keputusan_save.php"); ?>', {
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
        
        fetch('<?php echo url("ajax/keputusan_delete.php"); ?>', {
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
    
    // Open full results modal (uses cached dataset) with paging and sorted by ranking
    function openFullKeputusan(id){
        if(!latestKeputusanData || latestKeputusanData.length === 0){
            console.warn('No cached data available for full modal');
            return;
        }
        const rec = latestKeputusanData.find(r => String(r.id) === String(id));
        if(!rec){
            console.warn('Record not found in cached data for id', id);
            return;
        }

        const modalEl = document.getElementById('modalKeputusanFull');
        const tbody = modalEl.querySelector('table tbody');
        const pager = document.getElementById('modalKeputusanPager');
        tbody.innerHTML = '';
        pager.innerHTML = '';

        const sukan = rec.sukan || '';
        const acara = rec.kategori || rec.acara || '';

        // Prepare standings: clone and sort by numeric position ascending
        var standings = Array.isArray(rec.standings) ? rec.standings.slice() : [];
        standings.sort((a,b)=>{
            const pa = parseInt(a.position) || 0;
            const pb = parseInt(b.position) || 0;
            return pa - pb;
        });

        const total = standings.length;
        const pageSizeModal = 10;
        var modalPage = 1;

        function renderPage(){
            tbody.innerHTML = '';
            if(total === 0){
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No results to display</td></tr>';
                pager.innerHTML = '';
                return;
            }

            const totalPages = Math.max(1, Math.ceil(total / pageSizeModal));
            if(modalPage > totalPages) modalPage = totalPages;
            const start = (modalPage - 1) * pageSizeModal;
            const slice = standings.slice(start, start + pageSizeModal);

                slice.forEach(s=>{
                    const tr = document.createElement('tr');
                    var posNum = parseInt(s.position) || 0;

                    const displayRaw = (s.participant_display_name || s.participant_name || s.nama || s.nama_pasukan || s.participant || s.participant_id || '-').toString();
                    const nameParts = displayRaw.split(' - ');
                    const namePretty = displayRaw.replace(/\s-\s/, ', ');
                    const medalMap = {1: '🥇', 2: '🥈', 3: '🥉'};
                    // Show medal icon in first column for top 3, otherwise show numeric position
                    const rankCell = medalMap[posNum] ? `<span class="medal-icon">${medalMap[posNum]}</span>` : escapeHtml(String(posNum));
                    const nameDisplay = escapeHtml(namePretty);

                    tr.innerHTML = `<td class="align-top text-center">${rankCell}</td>` +
                                   `<td class="align-top">${escapeHtml(sukan)}</td>` +
                                   `<td class="align-top">${escapeHtml(acara)}</td>` +
                                   `<td class="align-top"><span class="text-truncate" data-bs-toggle="tooltip" title="${escapeHtml(namePretty)}">${nameDisplay}</span></td>`;
                    tbody.appendChild(tr);
                });

            // pager
            const showingStart = start + 1;
            const showingEnd = Math.min(total, start + pageSizeModal);
            pager.innerHTML = `
                <div class="text-muted small">Showing ${showingStart}–${showingEnd} of ${total}</div>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-secondary" id="modalPagerPrev" ${modalPage===1? 'disabled': ''}>Previous</button>
                    <button class="btn btn-outline-secondary" id="modalPagerNext" ${modalPage===totalPages? 'disabled': ''}>Next</button>
                </div>
            `;

            pager.querySelector('#modalPagerPrev').addEventListener('click', ()=>{ if(modalPage>1){ modalPage--; renderPage(); } });
            pager.querySelector('#modalPagerNext').addEventListener('click', ()=>{ if(modalPage<totalPages){ modalPage++; renderPage(); } });

            // init tooltips inside modal
            if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
                modalEl.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el=>{ try{ new bootstrap.Tooltip(el); }catch(e){} });
            }
        }

        // Set dynamic title
        const titleEl = document.getElementById('modalKeputusanFullTitle');
        titleEl.textContent = `Full Results – ${sukan} – ${acara}`;

        renderPage();

        // Show modal
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            const existing = modalEl.querySelectorAll('[data-bs-toggle="tooltip"]');
            existing.forEach(el=>{ try{ const inst = bootstrap.Tooltip.getInstance(el); if(inst) inst.dispose(); }catch(e){} });
            const modalInstance = new bootstrap.Modal(modalEl, {backdrop: true, keyboard: true});
            modalInstance.show();
        } else {
            modalEl.classList.add('show');
            modalEl.style.display = 'block';
            document.body.classList.add('modal-open');
        }
    }

    // Simple HTML escape for JS-inserted strings
    function escapeHtml(str){
        return (str||'').toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Event listeners
    sportSel.addEventListener('change', function(){
        loadKategori(this.value);
        loadKeputusan();
    });
    
    kategoriSel.addEventListener('change', loadKeputusan);
    dateInp.addEventListener('change', loadKeputusan);
    statusSel.addEventListener('change', loadKeputusan);
    
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
    document.getElementById('btnAddKeputusan').addEventListener('click', showAddKeputusan);
    document.getElementById('btnSaveKeputusan').addEventListener('click', saveKeputusan);
    
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

