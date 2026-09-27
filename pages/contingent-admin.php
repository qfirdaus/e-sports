<?php
/**
 * Contingent Admin View
 * Roles: ADMIN, ORGANIZER, JUDGE, VIEWER
 * Shows all active contingents with Show/Hide inline participants
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/rbac.php';

Session::start();
$auth = getAuth();
$auth->requireAuth();
$rbac = getRBAC();
// Enforce page-specific access (allows VIEWER via RBAC rules)
$rbac->requirePageAccess('pages/contingent-admin.php');

// Get current user role for JavaScript
$currentUserRole = Session::get('user_role') ?? '';
// Allow VIEWER to manage participants the same way as ADMIN/ORGANIZER on this page
$canEdit = in_array($currentUserRole, ['ADMIN', 'ORGANIZER', 'VIEWER']);

$page_title = 'Contingent (Admin)';

// Fetch contingents (active only)
$contingents = [];
try {
    $pdo = getDB();
    $sql = "SELECT
        k.id,
        u.nama_universiti,
        k.kod_universiti,
        k.nama_pegawai_untuk_dihubungi,
        k.alamat,
        k.emel,
        k.no_telefon,
        COALESCE(SUM(a.cnt),0) AS jumlah_atlet,
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
        k.created_at
    ORDER BY k.created_at DESC
    LIMIT 1000";

    $stmt = $pdo->query($sql);
    $contingents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('[contingent-admin.php] DB error: ' . $e->getMessage());
}

ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/contingent-admin.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/contingent-admin.css'); ?>">
<div class="w-100 px-3 contingent-admin-view">
    <div class="row mb-4 contingent-heading">
        <div class="col-12">
            <div class="card bg-light border-0 shadow-sm overflow-hidden">
                <div class="card-body py-4 d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                    <div>
                        <div class="contingent-eyebrow">SAM 2026 &middot; CONTINGENT MANAGEMENT</div><h2 class="mb-1">Contingent</h2>
                        <p class="text-muted mb-0">Active contingents and their officials. Select View to see participants.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Contingent List -->
    <div class="row">
        <div class="col-12">
            <div class="card mb-4 shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <strong>Active Contingents</strong><span class="contingent-count"><?php echo count($contingents); ?> kontinjen</span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive" id="contingentTableWrap">
                        <table class="table table-hover table-striped align-middle table-fixed">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" style="width:3%;">#</th>
                                    <th scope="col" style="width:23%;">Contingent Name</th>
                                    <th scope="col" style="width:10%;">Code</th>
                                    <th scope="col" style="width:30%;">Official</th>
                                    <th scope="col" style="width:10%;">Phone</th>
                                    <th scope="col" style="width:8%;">Total Athletes</th>
                                    <th scope="col" style="width:8%;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($contingents as $idx => $c): ?>
                                    <tr data-kontinjen="<?php echo htmlspecialchars($c['id'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <td><?php echo $idx + 1; ?></td>
                                        <td><?php echo htmlspecialchars($c['nama_universiti'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($c['kod_universiti'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($c['nama_pegawai_untuk_dihubungi'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td><?php echo htmlspecialchars($c['no_telefon'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <?php $count = (int)($c['jumlah_atlet'] ?? 0); ?>
                                            <?php if ($count === 0): ?>
                                                <span class="badge badge-pill badge-danger"><?php echo $count; ?></span>
                                            <?php else: ?>
                                                <span class="badge badge-pill badge-primary"><?php echo $count; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary contingent-view-btn" data-kid="<?php echo htmlspecialchars($c['id'], ENT_QUOTES, 'UTF-8'); ?>">View</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.details-top-controls{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px}
.details-top-controls .left-controls{display:flex;align-items:center;gap:8px}
.details-top-controls .right-controls{display:flex;align-items:center;gap:8px}
.details-top-controls .right-controls .details-search{width:220px}
.details-top-controls .left-controls .details-select{max-width:60%}
.details-top-controls .left-controls .details-select.select-sukan{min-width:220px;width:220px}
.details-top-controls .left-controls .details-select.select-acara{min-width:280px;width:320px}
.details-top-controls .right-controls .details-pagesize{width:90px}
@media (max-width:768px){
    .details-top-controls{flex-wrap:wrap}
    .details-top-controls .right-controls .details-search{width:140px}
    .details-top-controls .left-controls .details-select{width:120px}
    .details-top-controls .left-controls .details-select.select-sukan{min-width:160px;width:160px}
    .details-top-controls .left-controls .details-select.select-acara{min-width:180px;width:180px}
}
.no-data-badge{display:inline-block;padding:0.18rem 0.45rem;border-radius:0.35rem;background:#fdecea;color:#842029;font-size:0.8rem}
.details-row .table{table-layout:fixed;width:100%}
.details-row .table th,.details-row .table td{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.details-row .table th:first-child,.details-row .table td:first-child{text-align:center;}
.details-row .p-3{overflow:auto}
.row-highlight{background:rgba(13,110,253,0.08);}
.role-pengurus td:first-child,.role-jurulatih td:first-child{border-left:4px solid #f0ad4e;}
.role-pengurus td,.role-jurulatih td{background:rgba(255,245,204,0.6);}
.editable-cell{cursor:pointer;position:relative;}
.editable-cell:hover{background-color:rgba(13,110,253,0.05)!important;}
.editable-cell.editing{background-color:rgba(255,193,7,0.15)!important;}
.editable-cell input{width:100%;border:1px solid #0d6efd;border-radius:3px;padding:2px 4px;font-size:inherit;font-family:inherit;}
.editable-cell .edit-indicator{position:absolute;right:4px;top:50%;transform:translateY(-50%);font-size:0.7em;color:#6c757d;opacity:0;}
.editable-cell:hover .edit-indicator{opacity:0.5;}
.row-editing{background-color:rgba(255,193,7,0.1)!important;}
</style>

<script>
(function(){
    function whenJQ(cb){ if(window.jQuery){ cb(window.jQuery); } else { setTimeout(function(){ whenJQ(cb); }, 50); } }
    
    // User role and permissions
    var userRole = '<?php echo htmlspecialchars($currentUserRole, ENT_QUOTES, 'UTF-8'); ?>';
    var canEdit = <?php echo $canEdit ? 'true' : 'false'; ?>;

    whenJQ(function($){
        function fetchParticipants(kid, sukan_id, kategori_id){
            return $.ajax({
                url: '<?php echo url('ajax/contingent_participants.php'); ?>',
                data: { kontinjen_id: kid, sukan_id: sukan_id, kategori_id: kategori_id },
                dataType: 'json'
            });
        }

        function renderDetailsRow($tr, res){
            var colspan = $tr.find('td').length || 8;
            var kid = $tr.data('kontinjen') || $tr.find('td').eq(0).text();
            var $detail = $('<tr class="details-row" data-kid="'+kid+'"><td colspan="'+colspan+'"></td></tr>');
            var $container = $('<div class="p-3 bg-light border rounded">');
            var currentSport = '';
            var currentAcara = '';
            function parseId(val){
                var n = parseInt(val,10);
                return isNaN(n) ? '' : n;
            }
            function getFilters(){
                return {
                    sportId: parseId(currentSport || $select.val() || ''),
                    kategoriId: parseId(currentAcara || $selectAcara.val() || '')
                };
            }

            var $topBar = $('<div class="details-top-controls mb-2"></div>');
            var $select = $('<select class="form-select form-select-sm details-select select-sukan"><option value="">All Sports</option></select>');
            var $selectAcara = $('<select class="form-select form-select-sm details-select select-acara"><option value="">All Events</option></select>');
            $selectAcara.prop('disabled', true);
            var $search = $('<input type="search" class="form-control form-control-sm details-search" placeholder="Search peserta...">');
            var $pageSizeSel = $('<select class="form-select form-select-sm details-pagesize"><option value="10">10</option><option value="25" selected>25</option><option value="50">50</option><option value="100">100</option><option value="200">200</option></select>');
            var $addBtn = $('<button class="btn btn-sm btn-success add-participant-btn" title="Add Participant"><i class="fa fa-plus"></i> Add</button>');
            // Only show add button if user can edit
            if (!canEdit) {
                $addBtn.hide();
            }
            var $leftControls = $('<div class="left-controls"></div>').append($select).append($selectAcara);
            var $rightControls = $('<div class="right-controls"></div>').append($addBtn).append($search).append($pageSizeSel);
            $topBar.append($leftControls).append($rightControls);

            // Create separate tables for each participant type with their own pagination
            // Manager table
            var $pagerPengurus = $('<nav aria-label="Page navigation"><ul class="pagination pagination-sm mb-0"></ul></nav>');
            var $summaryPengurus = $('<div class="text-muted small details-summary me-2"></div>');
            var $pagerWrapPengurus = $('<div class="d-flex justify-content-between align-items-center mt-2"></div>').append($summaryPengurus).append($pagerPengurus);
            var $tablePengurus = $('<div class="participant-section mb-4"><h6 class="mb-2 text-primary"><i class="fa fa-user-tie me-1"></i> Manager</h6><table class="table table-sm table-striped mb-0 table-pengurus"><colgroup>' +
                '<col style="width:3%"></col>' +
                '<col style="width:30%"></col>' +
                '<col style="width:15%"></col>' +
                '<col style="width:10%"></col>' +
                '<col style="width:10%"></col>' +
                '<col style="width:25%"></col>' +
                '<col style="width:7%"></col>' +
                '</colgroup><thead><tr>' +
                '<th>#</th>' +
                '<th>Name</th>' +
                '<th>Position</th>' +
                '<th>Phone Number</th>' +
                '<th>Sport</th>' +
                '<th>Team</th>' +
                '<th>Actions</th>' +
                '</tr></thead><tbody></tbody></table></div>');
            $tablePengurus.append($pagerWrapPengurus);
            
            // Coach table
            var $pagerJurulatih = $('<nav aria-label="Page navigation"><ul class="pagination pagination-sm mb-0"></ul></nav>');
            var $summaryJurulatih = $('<div class="text-muted small details-summary me-2"></div>');
            var $pagerWrapJurulatih = $('<div class="d-flex justify-content-between align-items-center mt-2"></div>').append($summaryJurulatih).append($pagerJurulatih);
            var $tableJurulatih = $('<div class="participant-section mb-4"><h6 class="mb-2 text-warning"><i class="fa fa-user-graduate me-1"></i> Coach</h6><table class="table table-sm table-striped mb-0 table-jurulatih"><colgroup>' +
                '<col style="width:3%"></col>' +
                '<col style="width:30%"></col>' +
                '<col style="width:15%"></col>' +
                '<col style="width:10%"></col>' +
                '<col style="width:10%"></col>' +
                '<col style="width:25%"></col>' +
                '<col style="width:7%"></col>' +
                '</colgroup><thead><tr>' +
                '<th>#</th>' +
                '<th>Name</th>' +
                '<th>Position</th>' +
                '<th>Phone Number</th>' +
                '<th>Sport</th>' +
                '<th>Team</th>' +
                '<th>Actions</th>' +
                '</tr></thead><tbody></tbody></table></div>');
            $tableJurulatih.append($pagerWrapJurulatih);
            
            // Athlete table
            var $pagerAtlet = $('<nav aria-label="Page navigation"><ul class="pagination pagination-sm mb-0"></ul></nav>');
            var $summaryAtlet = $('<div class="text-muted small details-summary me-2"></div>');
            var $pagerWrapAtlet = $('<div class="d-flex justify-content-between align-items-center mt-2"></div>').append($summaryAtlet).append($pagerAtlet);
            var $tableAtlet = $('<div class="participant-section mb-4"><h6 class="mb-2 text-success"><i class="fa fa-running me-1"></i> Athlete</h6><table class="table table-sm table-striped mb-0 table-atlet"><colgroup>' +
                '<col style="width:3%"></col>' +
                '<col style="width:35%"></col>' +
                '<col style="width:15%"></col>' +
                '<col style="width:20%"></col>' +
                '<col style="width:20%"></col>' +
                '<col style="width:7%"></col>' +
                '</colgroup><thead><tr>' +
                '<th>#</th>' +
                '<th>Name</th>' +
                '<th>Sport</th>' +
                '<th>Team</th>' +
                '<th>Event</th>' +
                '<th>Actions</th>' +
                '</tr></thead><tbody></tbody></table></div>');
            $tableAtlet.append($pagerWrapAtlet);
            
            var $summary = $('<div class="text-muted small details-summary me-2"></div>');
            var $pager = $('<nav aria-label="Page navigation" style="display:none;"><ul class="pagination pagination-sm mb-0"></ul></nav>');

            $container.append($topBar)
                .append($tablePengurus)
                .append($tableJurulatih)
                .append($tableAtlet);
            $detail.find('td').append($container);
            $tr.after($detail);
            $tr.find('.contingent-view-btn').text('Close');

            function buildRows(data){
                var list = [];
                (data.pengurus||[]).forEach(function(r){ r._role='pengurus'; list.push(r); });
                (data.jurulatih||[]).forEach(function(r){ r._role='jurulatih'; list.push(r); });
                (data.atlet||[]).forEach(function(r){ r._role='atlet'; list.push(r); });
                return list;
            }

            var rows = buildRows(res);

            var map = {};
            var mapBySport = {};
            ['atlet','pengurus','jurulatih'].forEach(function(k){
                (res[k]||[]).forEach(function(r){
                    var id = r.sukan_id || '';
                    var name = r.nama_sukan || ('Sport ' + id);
                    if (id && !map[id]) map[id]=name;

                    var aid = r.kategori_id || r.id_kategori || r.kategori || r.event_id || r.acara_id || '';
                    var aname = r.nama_kategori || r.nama_acara || r.acara || r.event_name || r.kategori || (aid?('Event '+aid):'');

                    if (id){
                        if (!mapBySport[id]) mapBySport[id] = {};
                        var key = aid || aname || '';
                        if (key && !mapBySport[id][key]) mapBySport[id][key] = aname || key;
                    }
                });
            });
            Object.keys(map).forEach(function(id){ $select.append($('<option>').val(id).text(map[id])); });

            function populateAcaraOptionsForSport(sportId){
                $selectAcara.empty();
                $selectAcara.append($('<option>').val('').text('All Events'));
                var added = 0;
                if(!sportId){
                    $selectAcara.prop('disabled', true);
                    return;
                }
                var m = mapBySport[sportId] || {};
                Object.keys(m).forEach(function(aid){ $selectAcara.append($('<option>').val(aid).text(m[aid])); added++; });
                $selectAcara.prop('disabled', added === 0);
            }

            $.ajax({ url: '<?php echo url('ajax/kategori_list.php'); ?>', dataType: 'json' }).done(function(kres){
                if (kres && kres.success && Array.isArray(kres.data)){
                    mapBySport = {};
                    kres.data.forEach(function(row){
                        var aid = row.id;
                        var sid = row.sukan_id;
                        var aname = row.nama_kategori || row.nama_acara || row.kod_kategori || ('Event '+aid);
                        if (sid){ mapBySport[sid] = mapBySport[sid] || {}; mapBySport[sid][aid] = aname; }
                    });
                }
                populateAcaraOptionsForSport($select.val() || '');
                renderPage();
            }).fail(function(){
                populateAcaraOptionsForSport($select.val() || '');
                renderPage();
            });

            var $tbodyPengurus = $tablePengurus.find('tbody');
            var $tbodyJurulatih = $tableJurulatih.find('tbody');
            var $tbodyAtlet = $tableAtlet.find('tbody');
            var pageSize = parseInt($pageSizeSel.val(), 10) || 25;
            var currentPagePengurus = 1;
            var currentPageJurulatih = 1;
            var currentPageAtlet = 1;

            function applyFilters(){
                var filterIds = getFilters();
                var sportId = filterIds.sportId;
                var kategoriId = filterIds.kategoriId;
                var q = ($search.val()||'').trim().toLowerCase();

                function matchesSearch(r){
                    var name = (r.nama || r.nama_peserta || r.nama_atlet || r.nama_pengurus || r.nama_jurulatih || '').toString().toLowerCase();
                    var ic = (r.no_kad_pengenalan || r.ic || r.no_ic || r.mykad || '').toString().toLowerCase();
                    var phone = (r.no_telefon || r.telefon || '').toString().toLowerCase();
                    var email = (r.emel || r.email || '').toString().toLowerCase();
                    var matrik = (r.no_matrik || r.matrik || '').toString().toLowerCase();
                    if (q && name.indexOf(q) === -1 && ic.indexOf(q) === -1 && phone.indexOf(q) === -1 && email.indexOf(q) === -1 && matrik.indexOf(q) === -1) return false;
                    return true;
                }

                var filteredByIds = rows.filter(function(r){
                    var role = (r._role || '').toString().toLowerCase();
                    if (sportId && String(r.sukan_id || '') !== String(sportId)) return false;
                    // Only enforce kategori filter on Athlete; allow Pengurus/Jurulatih to show with no kategori_id
                    if (kategoriId && role === 'atlet') {
                        var kidVal = r.kategori_id || r.id_kategori || r.acara_id || r.event_id;
                        if (String(kidVal || '') !== String(kategoriId)) return false;
                    }
                    return true;
                });

                var pengurus = filteredByIds.filter(function(r){
                    return (r._role||'').toString().toLowerCase() === 'pengurus' && matchesSearch(r);
                });

                var jurulatih = filteredByIds.filter(function(r){
                    return (r._role||'').toString().toLowerCase() === 'jurulatih' && matchesSearch(r);
                });

                var atlet = filteredByIds.filter(function(r){
                    return (r._role||'').toString().toLowerCase() === 'atlet' && matchesSearch(r);
                });

                return {
                    pengurus: pengurus,
                    jurulatih: jurulatih,
                    atlet: atlet,
                    total: pengurus.length + jurulatih.length + atlet.length
                };
            }

            function genderFromMyKad(ic){
                var digits = (ic||'').toString().replace(/\\D+/g,'');
                if (!digits) return null;
                var last = digits.slice(-1);
                if (!last) return null;
                return (parseInt(last,10) % 2 === 0) ? 'F' : 'M';
            }

            function roleGenderIcon(role, ic){
                var g = genderFromMyKad(ic);
                var genderLabel = g === 'F' ? 'Female' : (g === 'M' ? 'Male' : '');
                var iconClass = 'zmdi zmdi-account';
                if (role === 'pengurus') {
                    iconClass = (g === 'F') ? 'zmdi zmdi-female' : 'zmdi zmdi-male';
                } else if (role === 'jurulatih') {
                    iconClass = (g === 'F') ? 'zmdi zmdi-run' : 'zmdi zmdi-walk';
                }
                var title = (role.charAt(0).toUpperCase() + role.slice(1)) + (genderLabel ? ' - ' + genderLabel : '');
                return '<i class="'+iconClass+' text-muted" title="'+title+'"></i>';
            }

            // Inline editing functions
            function makeCellEditable($cell, participantId, participantType, fieldName, originalValue) {
                if ($cell.hasClass('editing')) return; // Already editing
                
                $cell.addClass('editing');
                $cell.closest('tr').addClass('row-editing');
                
                var currentValue = originalValue || '';
                var $input = $('<input type="text">')
                    .val(currentValue)
                    .css({
                        'width': '100%',
                        'border': '1px solid #0d6efd',
                        'border-radius': '3px',
                        'padding': '2px 4px',
                        'font-size': 'inherit',
                        'font-family': 'inherit'
                    });
                
                var $saveBtn = $('<button class="btn btn-sm btn-success ms-1" style="padding: 1px 6px; font-size: 0.75rem;">✓</button>');
                var $cancelBtn = $('<button class="btn btn-sm btn-secondary ms-1" style="padding: 1px 6px; font-size: 0.75rem;">✗</button>');
                var $btnContainer = $('<span class="ms-1"></span>').append($saveBtn).append($cancelBtn);
                
                $cell.empty().append($input).append($btnContainer);
                $input.focus().select();
                
                function finishEdit(save) {
                    if (!save) {
                        revertCell($cell, originalValue);
                        return;
                    }
                    
                    var newValue = $input.val().trim();
                    if (newValue === originalValue) {
                        revertCell($cell, originalValue);
                        return;
                    }
                    
                    saveParticipantField(participantId, participantType, fieldName, newValue, $cell, originalValue);
                }
                
                $input.on('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        finishEdit(true);
                    } else if (e.key === 'Escape') {
                        e.preventDefault();
                        finishEdit(false);
                    }
                });
                
                $saveBtn.on('click', function(e) {
                    e.stopPropagation();
                    finishEdit(true);
                });
                
                $cancelBtn.on('click', function(e) {
                    e.stopPropagation();
                    finishEdit(false);
                });
                
                // Prevent double-click from bubbling
                $cell.off('dblclick');
            }
            
            function saveParticipantField(participantId, participantType, fieldName, newValue, $cell, originalValue) {
                $cell.find('input').prop('disabled', true);
                $cell.find('button').prop('disabled', true);
                
                $.ajax({
                    url: '<?php echo url('ajax/participant_update.php'); ?>',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        participant_id: participantId,
                        participant_type: participantType,
                        field_name: fieldName,
                        field_value: newValue
                    }),
                    dataType: 'json'
                }).done(function(response) {
                    if (response.success) {
                        updateCellDisplay($cell, newValue, fieldName);
                        // Show success feedback
                        var $feedback = $('<span class="text-success ms-1" style="font-size: 0.75rem;">✓</span>');
                        $cell.append($feedback);
                        setTimeout(function() {
                            $feedback.fadeOut(function() { $(this).remove(); });
                        }, 2000);
                    } else {
                        alert('Error: ' + (response.message || 'Unable to save data.'));
                        revertCell($cell, originalValue);
                    }
                }).fail(function(xhr, status, error) {
                    alert('Unable to save data: ' + error);
                    revertCell($cell, originalValue);
                });
            }
            
            function revertCell($cell, originalValue) {
                $cell.removeClass('editing');
                $cell.closest('tr').removeClass('row-editing');
                
                if (!originalValue || originalValue.trim() === '') {
                    $cell.empty().append($('<span>').addClass('no-data-badge').text('None'));
                    var fieldName = $cell.attr('data-field');
                    if (fieldName) {
                        $cell.addClass('editable-cell').attr('data-field', fieldName).attr('data-original-value', '');
                        $cell.append($('<span>').addClass('edit-indicator').html('✎'));
                    }
                } else {
                    $cell.empty().text(originalValue).attr('title', originalValue);
                    var fieldName = $cell.attr('data-field');
                    if (fieldName) {
                        $cell.addClass('editable-cell').attr('data-field', fieldName).attr('data-original-value', originalValue);
                        $cell.append($('<span>').addClass('edit-indicator').html('✎'));
                    }
                }
                
                // Re-attach double-click handler
                attachEditHandler($cell);
            }
            
            function updateCellDisplay($cell, newValue, fieldName) {
                $cell.removeClass('editing');
                $cell.closest('tr').removeClass('row-editing');
                
                if (!newValue || newValue.trim() === '') {
                    $cell.empty().append($('<span>').addClass('no-data-badge').text('None'));
                    $cell.attr('data-original-value', '');
                } else {
                    $cell.empty().text(newValue).attr('title', newValue);
                    $cell.attr('data-original-value', newValue);
                }
                
                if (fieldName) {
                    $cell.addClass('editable-cell').attr('data-field', fieldName);
                    $cell.append($('<span>').addClass('edit-indicator').html('✎'));
                }
                
                // Re-attach double-click handler
                attachEditHandler($cell);
            }
            
            function attachEditHandler($cell) {
                // Only attach edit handler if user can edit
                if (!canEdit) return;
                
                $cell.off('dblclick').on('dblclick', function(e) {
                    e.stopPropagation();
                    var $this = $(this);
                    if ($this.hasClass('editing')) return;
                    
                    var $row = $this.closest('tr');
                    var participantId = parseInt($row.attr('data-participant-id'), 10);
                    var participantType = $row.attr('data-participant-type');
                    var fieldName = $this.attr('data-field');
                    var originalValue = $this.attr('data-original-value') || '';
                    
                    if (participantId && participantType && fieldName) {
                        makeCellEditable($this, participantId, participantType, fieldName, originalValue);
                    }
                });
            }
            
            // Helper function to build pagination for each table
            function buildPagination($pager, $summary, currentPage, totalPages, total, start, end, typeName) {
                var showingStart = total === 0 ? 0 : start + 1;
                var showingEnd = Math.min(total, end);
                
                if (total === 0) {
                    $summary.text('None ' + typeName.toLowerCase());
                } else {
                    $summary.text('Showing ' + showingStart + ' - ' + showingEnd + ' of ' + total);
                }
                
                var $ul = $pager.find('ul'); $ul.empty();
                var createPageItem = function(label, page, disabled, active){
                    var $li = $('<li class="page-item">'); 
                    if (disabled) $li.addClass('disabled'); 
                    if (active) $li.addClass('active');
                    var $a = $('<a class="page-link" href="#">').text(label).data('page', page);
                    $li.append($a); 
                    return $li;
                };
                
                if (totalPages > 1) {
                    $pager.show();
                    $ul.append(createPageItem('<<', Math.max(1, currentPage - 1), currentPage === 1, false));
                    var startPage = Math.max(1, currentPage - 2); 
                    var endPage = Math.min(totalPages, startPage + 4);
                    for (var p = startPage; p <= endPage; p++) {
                        $ul.append(createPageItem(p, p, false, p === currentPage));
                    }
                    $ul.append(createPageItem('>>', Math.min(totalPages, currentPage + 1), currentPage === totalPages, false));
                } else {
                    $pager.hide();
                }
            }

            function renderPage(){
                var filtered = applyFilters();
                var total = filtered.total;
                
                // Clear all tables
                $tbodyPengurus.empty();
                $tbodyJurulatih.empty();
                $tbodyAtlet.empty();
                
                // Helper to safely extract value
                function safeValue(val, fallback) {
                    if (val === null || val === undefined) {
                        return (fallback !== undefined) ? fallback : '';
                    }
                    var strVal = String(val);
                    if (strVal === 'null' || strVal === 'undefined' || strVal.trim() === '') {
                        return (fallback !== undefined) ? fallback : '';
                    }
                    return strVal.trim();
                }
                
                // Helper to create cell
                function cell(val, isEditable, fieldName){
                    var $td = $('<td>').addClass('text-truncate');
                    var actuallyEditable = isEditable && canEdit;
                    var displayValue = safeValue(val, '');
                    if (displayValue === '') {
                        $td.append($('<span>').addClass('no-data-badge').text('None'));
                        if (actuallyEditable) {
                            $td.addClass('editable-cell').attr('data-field', fieldName).attr('data-original-value', '');
                            $td.append($('<span>').addClass('edit-indicator').html('✎'));
                        }
                    } else {
                        $td.text(displayValue).attr('title', displayValue);
                        if (actuallyEditable) {
                            $td.addClass('editable-cell').attr('data-field', fieldName).attr('data-original-value', displayValue);
                            $td.append($('<span>').addClass('edit-indicator').html('✎'));
                        }
                    }
                    return $td;
                }
                
                // Render Manager with separate pagination
                var totalPengurus = filtered.pengurus.length;
                var totalPagesPengurus = Math.max(1, Math.ceil(totalPengurus / pageSize));
                if (currentPagePengurus > totalPagesPengurus) currentPagePengurus = totalPagesPengurus;
                var startPengurus = (currentPagePengurus - 1) * pageSize;
                var endPengurus = Math.min(totalPengurus, startPengurus + pageSize);
                var pagePengurus = filtered.pengurus.slice(startPengurus, endPengurus);
                
                if (pagePengurus.length === 0 && totalPengurus === 0) {
                    $tbodyPengurus.append('<tr><td colspan="7" class="text-center text-muted py-2">No managers match these filters.</td></tr>');
                } else if (pagePengurus.length === 0 && totalPengurus > 0) {
                    $tbodyPengurus.append('<tr><td colspan="7" class="text-center text-muted py-2">No managers on this page.</td></tr>');
                } else {
                    pagePengurus.forEach(function(r){
                        var name = safeValue(r.nama || r.nama_pengurus, '');
                        var nic = safeValue(r.no_kad_pengenalan || r.ic || r.no_ic || r.mykad, '');
                        var phone = safeValue(r.no_telefon || r.telefon, '');
                        var email = safeValue(r.emel || r.email, '');
                        var jawatan = safeValue(r.jawatan, '');
                        var sport = safeValue(r.nama_sukan, r.sukan_id ? ('Sport ' + r.sukan_id) : '');
                        var team = safeValue(r.nama_pasukan, '');
                        var pasukanId = r.pasukan_id || 0;
                        var sukanId = r.sukan_id || 0;
                        
                        var $r = $('<tr>').addClass('role-pengurus');
                        $r.attr('data-participant-id', r.id || 0);
                        $r.attr('data-participant-type', 'pengurus');
                        $r.attr('data-pasukan-id', pasukanId);
                        $r.attr('data-sukan-id', sukanId);
                        $r.attr('data-nama', name);
                        $r.attr('data-ic', nic);
                        $r.attr('data-telefon', phone);
                        $r.attr('data-emel', email);
                        $r.attr('data-jawatan', jawatan);
                        
                        // Use FA4-compatible icon for pengurus
                        $r.append($('<td class="text-center">').html('<i class="fa fa-user text-primary"></i>'));
                        $r.append(cell(name, true, 'nama'));
                        $r.append(cell(englishLabel(jawatan), true, 'jawatan'));
                        $r.append(cell(phone, true, 'no_telefon'));
                        $r.append(cell(englishSportLabel(sport), false, ''));
                        $r.append(cell(team, false, ''));
                        
                        var $actionsTd = $('<td>').addClass('text-center');
                        if (canEdit) {
                            var $updateBtn = $('<button class="btn btn-sm btn-outline-primary me-1 update-participant-btn" title="Update" style="padding: 2px 6px;"><i class="fa fa-pencil"></i></button>');
                            $actionsTd.append($updateBtn);
                            var $deleteBtn = $('<button class="btn btn-sm btn-outline-danger delete-participant-btn" title="Delete" style="padding: 2px 6px;"><i class="fa fa-trash"></i></button>');
                            $deleteBtn.data('participant-id', r.id || 0).data('participant-type', 'pengurus').data('participant-name', name);
                            $actionsTd.append($deleteBtn);
                        } else {
                            $actionsTd.append($('<span class="text-muted">-</span>'));
                        }
                        $r.append($actionsTd);
                        $tbodyPengurus.append($r);
                    });
                }
                
                // Build pagination for Manager
                buildPagination($pagerPengurus, $summaryPengurus, currentPagePengurus, totalPagesPengurus, totalPengurus, startPengurus, endPengurus, 'Manager');
                
                // Render Coach with separate pagination
                var totalJurulatih = filtered.jurulatih.length;
                var totalPagesJurulatih = Math.max(1, Math.ceil(totalJurulatih / pageSize));
                if (currentPageJurulatih > totalPagesJurulatih) currentPageJurulatih = totalPagesJurulatih;
                var startJurulatih = (currentPageJurulatih - 1) * pageSize;
                var endJurulatih = Math.min(totalJurulatih, startJurulatih + pageSize);
                var pageJurulatih = filtered.jurulatih.slice(startJurulatih, endJurulatih);
                
                if (pageJurulatih.length === 0 && totalJurulatih === 0) {
                    $tbodyJurulatih.append('<tr><td colspan="7" class="text-center text-muted py-2">No coaches match these filters.</td></tr>');
                } else if (pageJurulatih.length === 0 && totalJurulatih > 0) {
                    $tbodyJurulatih.append('<tr><td colspan="7" class="text-center text-muted py-2">No coaches on this page.</td></tr>');
                } else {
                    pageJurulatih.forEach(function(r){
                        var name = safeValue(r.nama || r.nama_jurulatih, '');
                        var nic = safeValue(r.no_kad_pengenalan || r.ic || r.no_ic || r.mykad, '');
                        var phone = safeValue(r.no_telefon || r.telefon, '');
                        var email = safeValue(r.emel || r.email, '');
                        var jawatan = safeValue(r.jawatan, '');
                        var sport = safeValue(r.nama_sukan, r.sukan_id ? ('Sport ' + r.sukan_id) : '');
                        var team = safeValue(r.nama_pasukan, '');
                        var pasukanId = r.pasukan_id || 0;
                        var sukanId = r.sukan_id || 0;
                        
                        var $r = $('<tr>').addClass('role-jurulatih');
                        $r.attr('data-participant-id', r.id || 0);
                        $r.attr('data-participant-type', 'jurulatih');
                        $r.attr('data-pasukan-id', pasukanId);
                        $r.attr('data-sukan-id', sukanId);
                        $r.attr('data-nama', name);
                        $r.attr('data-ic', nic);
                        $r.attr('data-telefon', phone);
                        $r.attr('data-emel', email);
                        $r.attr('data-jawatan', jawatan);
                        
                        // Use FA4-compatible icon for jurulatih
                        $r.append($('<td class="text-center">').html('<i class="fa fa-graduation-cap text-warning"></i>'));
                        $r.append(cell(name, true, 'nama'));
                        $r.append(cell(englishLabel(jawatan), true, 'jawatan'));
                        $r.append(cell(phone, true, 'no_telefon'));
                        $r.append(cell(englishSportLabel(sport), false, ''));
                        $r.append(cell(team, false, ''));
                        
                        var $actionsTd = $('<td>').addClass('text-center');
                        if (canEdit) {
                            var $updateBtn = $('<button class="btn btn-sm btn-outline-primary me-1 update-participant-btn" title="Update" style="padding: 2px 6px;"><i class="fa fa-pencil"></i></button>');
                            $actionsTd.append($updateBtn);
                            var $deleteBtn = $('<button class="btn btn-sm btn-outline-danger delete-participant-btn" title="Delete" style="padding: 2px 6px;"><i class="fa fa-trash"></i></button>');
                            $deleteBtn.data('participant-id', r.id || 0).data('participant-type', 'jurulatih').data('participant-name', name);
                            $actionsTd.append($deleteBtn);
                        } else {
                            $actionsTd.append($('<span class="text-muted">-</span>'));
                        }
                        $r.append($actionsTd);
                        $tbodyJurulatih.append($r);
                    });
                }
                
                // Build pagination for Coach
                buildPagination($pagerJurulatih, $summaryJurulatih, currentPageJurulatih, totalPagesJurulatih, totalJurulatih, startJurulatih, endJurulatih, 'Coach');
                
                // Render Athlete with separate pagination
                var totalAtlet = filtered.atlet.length;
                var totalPagesAtlet = Math.max(1, Math.ceil(totalAtlet / pageSize));
                if (currentPageAtlet > totalPagesAtlet) currentPageAtlet = totalPagesAtlet;
                var startAtlet = (currentPageAtlet - 1) * pageSize;
                var endAtlet = Math.min(totalAtlet, startAtlet + pageSize);
                var pageAtlet = filtered.atlet.slice(startAtlet, endAtlet);
                
                if (pageAtlet.length === 0 && totalAtlet === 0) {
                    $tbodyAtlet.append('<tr><td colspan="6" class="text-center text-muted py-2">No athletes match these filters.</td></tr>');
                } else if (pageAtlet.length === 0 && totalAtlet > 0) {
                    $tbodyAtlet.append('<tr><td colspan="6" class="text-center text-muted py-2">No athletes on this page.</td></tr>');
                } else {
                    pageAtlet.forEach(function(r, idx){
                        var name = safeValue(r.nama || r.nama_atlet, '');
                        var nic = safeValue(r.no_kad_pengenalan || r.ic || r.no_ic || r.mykad, '');
                        var matrik = safeValue(r.no_matrik || r.matrik, '');
                        var sport = safeValue(r.nama_sukan, r.sukan_id ? ('Sport ' + r.sukan_id) : '');
                        var team = safeValue(r.nama_pasukan, '');
                        var acara = safeValue(r.nama_kategori || r.nama_acara || r.kategori || r.acara || r.event_name || r.nama_event, '');
                        var pasukanId = r.pasukan_id || 0;
                        var sukanId = r.sukan_id || 0;
                        var kategoriId = r.kategori_id || r.id_kategori || r.acara_id || r.event_id || null;
                        
                        var $r = $('<tr>').addClass('role-atlet');
                        $r.attr('data-participant-id', r.id || 0);
                        $r.attr('data-participant-type', 'atlet');
                        $r.attr('data-pasukan-id', pasukanId);
                        $r.attr('data-sukan-id', sukanId);
                        $r.attr('data-kategori-id', kategoriId);
                        $r.attr('data-nama', name);
                        $r.attr('data-ic', nic);
                        $r.attr('data-matrik', matrik);
                        
                        // Use page-based counter for atlet numbering
                        var atletNumber = startAtlet + idx + 1;
                        $r.append($('<td class="text-center">').text(atletNumber));
                        $r.append(cell(name, true, 'nama'));
                        $r.append(cell(englishSportLabel(sport), false, ''));
                        $r.append(cell(team, false, ''));
                        $r.append(cell(englishSportLabel(acara), false, ''));
                        
                        var $actionsTd = $('<td>').addClass('text-center');
                        if (canEdit) {
                            var $updateBtn = $('<button class="btn btn-sm btn-outline-primary me-1 update-participant-btn" title="Update" style="padding: 2px 6px;"><i class="fa fa-pencil"></i></button>');
                            $actionsTd.append($updateBtn);
                            var $deleteBtn = $('<button class="btn btn-sm btn-outline-danger delete-participant-btn" title="Delete" style="padding: 2px 6px;"><i class="fa fa-trash"></i></button>');
                            $deleteBtn.data('participant-id', r.id || 0).data('participant-type', 'atlet').data('participant-name', name);
                            $actionsTd.append($deleteBtn);
                        } else {
                            $actionsTd.append($('<span class="text-muted">-</span>'));
                        }
                        $r.append($actionsTd);
                        $tbodyAtlet.append($r);
                    });
                }
                
                // Build pagination for Athlete
                buildPagination($pagerAtlet, $summaryAtlet, currentPageAtlet, totalPagesAtlet, totalAtlet, startAtlet, endAtlet, 'Athlete');
                
                // Attach double-click handlers to editable cells after rendering
                $tbodyPengurus.find('.editable-cell').each(function() {
                    attachEditHandler($(this));
                });
                $tbodyJurulatih.find('.editable-cell').each(function() {
                    attachEditHandler($(this));
                });
                $tbodyAtlet.find('.editable-cell').each(function() {
                    attachEditHandler($(this));
                });
            }

            function reloadFromServer(){
                currentSport = $select.val() || '';
                currentAcara = $selectAcara.val() || '';
                var ids = getFilters();
                fetchParticipants(kid, ids.sportId || '', ids.kategoriId || '').done(function(newRes){
                    if (newRes.status !== 'ok') { alert('No data'); return; }
                    rows = buildRows(newRes);
                    currentPagePengurus = 1;
                    currentPageJurulatih = 1;
                    currentPageAtlet = 1;
                    renderPage();
                }).fail(function(){ alert('Unable to load participants'); });
            }

            $select.on('change', function(){
                var sv = $(this).val() || '';
                populateAcaraOptionsForSport(sv);
                $selectAcara.val('');
                currentPagePengurus = 1;
                currentPageJurulatih = 1;
                currentPageAtlet = 1;
                reloadFromServer();
            });
            $selectAcara.on('change', function(){ 
                currentPagePengurus = 1; 
                currentPageJurulatih = 1; 
                currentPageAtlet = 1; 
                reloadFromServer(); 
            });
            $search.on('input', function(){ 
                currentPagePengurus = 1; 
                currentPageJurulatih = 1; 
                currentPageAtlet = 1; 
                renderPage(); 
            });
            $pageSizeSel.on('change', function(){ 
                pageSize = parseInt($(this).val(), 10) || 10; 
                currentPagePengurus = 1; 
                currentPageJurulatih = 1; 
                currentPageAtlet = 1; 
                renderPage(); 
            });
            
            // Separate pagination handlers for each table
            $pagerPengurus.on('click', 'a.page-link', function(e){ 
                e.preventDefault(); 
                var p = $(this).data('page'); 
                if (!p) return; 
                currentPagePengurus = parseInt(p, 10) || 1; 
                renderPage(); 
            });
            $pagerJurulatih.on('click', 'a.page-link', function(e){ 
                e.preventDefault(); 
                var p = $(this).data('page'); 
                if (!p) return; 
                currentPageJurulatih = parseInt(p, 10) || 1; 
                renderPage(); 
            });
            $pagerAtlet.on('click', 'a.page-link', function(e){ 
                e.preventDefault(); 
                var p = $(this).data('page'); 
                if (!p) return; 
                currentPageAtlet = parseInt(p, 10) || 1; 
                renderPage(); 
            });

            // Add participant modal
            var $addModal = $('<div class="modal fade" id="addParticipantModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Add Participant</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="addParticipantForm"><div class="mb-3"><label class="form-label">Participant Type <span class="text-danger">*</span></label><select class="form-select" id="addParticipantType" required><option value="">Select a type...</option><option value="atlet">Athlete</option><option value="pengurus">Manager</option><option value="jurulatih">Coach</option></select></div><div class="mb-3" id="addParticipantJawatanGroup" style="display:none;"><label class="form-label">Position <span class="text-danger">*</span></label><select class="form-select" id="addParticipantJawatan"><option value="">-- Please Select --</option></select></div><div class="mb-3"><label class="form-label">Team <span class="text-danger">*</span></label><select class="form-select" id="addParticipantPasukan" required><option value="">Select a team...</option></select></div><div class="mb-3"><label class="form-label">Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="addParticipantNama" required></div><div class="mb-3"><label class="form-label">Identity Card Number</label><input type="text" class="form-control" id="addParticipantIC" maxlength="12"></div><div class="mb-3" id="addParticipantMatrikGroup" style="display:none;"><label class="form-label">Matriculation Number</label><input type="text" class="form-control" id="addParticipantMatrik" maxlength="50"></div><div class="mb-3" id="addParticipantKategoriGroup" style="display:none;"><label class="form-label">Event/Category</label><select class="form-select" id="addParticipantKategori"><option value="">Select an event...</option></select></div><div class="mb-3" id="addParticipantPhoneGroup" style="display:none;"><label class="form-label">Phone Number</label><input type="text" class="form-control" id="addParticipantPhone" maxlength="20"></div><div class="mb-3" id="addParticipantEmailGroup" style="display:none;"><label class="form-label">Email</label><input type="email" class="form-control" id="addParticipantEmail" maxlength="100"></div></form></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="addParticipantSubmit">Save</button></div></div></div></div>');
            $('body').append($addModal);
            
            // Update participant modal (for edit/update per row)
            var $updateModal = $('<div class="modal fade" id="updateParticipantModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="updateParticipantTitle">Update Participant</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="updateParticipantForm"><input type="hidden" id="updParticipantId"><input type="hidden" id="updParticipantType"><div class="mb-3"><label class="form-label">Team</label><select class="form-select" id="updParticipantPasukan"></select></div><div class="mb-3" id="updParticipantKategoriGroup"><label class="form-label">Event/Category</label><select class="form-select" id="updParticipantKategori"><option value="">Select an event...</option></select></div><div class="mb-3"><label class="form-label">Name <span class="text-danger">*</span></label><input type="text" class="form-control" id="updParticipantName" required></div><div class="mb-3"><label class="form-label">Identity Card Number</label><input type="text" class="form-control" id="updParticipantIC" maxlength="12"></div><div class="mb-3" id="updParticipantJawatanGroup" style="display:none;"><label class="form-label">Position <span class="text-danger">*</span></label><select class="form-select" id="updParticipantJawatan"><option value="">-- Please Select --</option></select></div><div class="mb-3" id="updParticipantMatrikGroup"><label class="form-label">Matriculation Number</label><input type="text" class="form-control" id="updParticipantMatrik" maxlength="50"></div><div class="mb-3" id="updParticipantPhoneGroup"><label class="form-label">Phone Number</label><input type="text" class="form-control" id="updParticipantPhone" maxlength="20"></div><div class="mb-3" id="updParticipantEmailGroup"><label class="form-label">Email</label><input type="email" class="form-control" id="updParticipantEmail" maxlength="100"></div></form></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" id="updateParticipantSubmit">Save</button></div></div></div></div>');
            $('body').append($updateModal);
            
            // Get available pasukan for this kontinjen
            var availablePasukan = {};
            var availableKategori = {};
            var updatePasukanMap = {};
            
            function loadPasukanForKontinjen() {
                $.ajax({
                    url: '<?php echo url('ajax/pasukan_list.php'); ?>',
                    data: { kontinjen_id: kid },
                    dataType: 'json'
                }).done(function(res) {
                    if (res.success && Array.isArray(res.data)) {
                        var $select = $('#addParticipantPasukan');
                        $select.empty().append($('<option>').val('').text('Select a team...'));
                        res.data.forEach(function(p) {
                            if (p.status == 1 && !p.deleted_at) {
                                availablePasukan[p.id] = {
                                    id: p.id,
                                    nama_pasukan: p.nama_pasukan,
                                    sukan_id: p.sukan_id,
                                    nama_sukan: p.nama_sukan || ''
                                };
                                var displayText = p.nama_pasukan;
                                if (p.nama_sukan) {
                                    displayText += ' (' + p.nama_sukan + ')';
                                }
                                $select.append($('<option>').val(p.id).text(displayText).data('sukan-id', p.sukan_id));
                            }
                        });
                    }
                }).fail(function() {
                    alert('Unable to load the team list.');
                });
            }
            
            // Load pasukan when modal opens
            $addBtn.on('click', function() {
                loadPasukanForKontinjen();
                $('#addParticipantModal').modal('show');
            });

            function populateUpdatePasukanSelect(selectedId, sukanId, kategoriIdForAtlet){
                var $sel = $('#updParticipantPasukan');
                updatePasukanMap = {};
                $sel.empty().append($('<option>').val('').text('Select a team...'));
                return $.ajax({
                    url: '<?php echo url('ajax/pasukan_list.php'); ?>',
                    data: { kontinjen_id: kid, sukan_id: sukanId || '' },
                    dataType: 'json'
                }).done(function(res){
                    if (res.success && Array.isArray(res.data)) {
                        res.data.forEach(function(p){
                            if (p.status == 1 && !p.deleted_at) {
                                updatePasukanMap[p.id] = p;
                                var opt = $('<option>').val(p.id).text(p.nama_pasukan || ('Team ' + p.id));
                                opt.data('sukan-id', p.sukan_id || null);
                                opt.data('sukan-name', p.nama_sukan || '');
                                $sel.append(opt);
                            }
                        });
                        if (selectedId) {
                            $sel.val(String(selectedId));
                        }
                        $sel.data('original', selectedId ? String(selectedId) : '');
                        if ($('#updParticipantType').val() === 'atlet') {
                            var sid = sukanId || (selectedId && updatePasukanMap[selectedId] ? updatePasukanMap[selectedId].sukan_id : null);
                            populateUpdateKategoriSelect(sid, kategoriIdForAtlet);
                        } else {
                            $('#updParticipantKategori').empty().append($('<option>').val('').text('No terpakai'));
                        }
                    }
                });
            }

            function populateUpdateKategoriSelect(sukanId, selectedKategoriId){
                var $sel = $('#updParticipantKategori');
                $sel.empty().append($('<option>').val('').text('Select an event...'));
                $sel.data('original', '');
                if (!sukanId) { return; }
                $.ajax({
                    url: '<?php echo url('ajax/get_kategori_by_sukan.php'); ?>',
                    data: { sukan_id: sukanId },
                    dataType: 'json'
                }).done(function(res){
                    if (res.success && Array.isArray(res.data)) {
                        res.data.forEach(function(k){
                            var opt = $('<option>').val(k.id).text(englishSportLabel(k.nama_kategori) || ('Event ' + k.id));
                            $sel.append(opt);
                        });
                        if (selectedKategoriId) {
                            $sel.val(String(selectedKategoriId));
                        }
                        $sel.data('original', selectedKategoriId ? String(selectedKategoriId) : '');
                    }
                });
            }
            
            function populateJawatanOptions(type) {
                var $sel = $('#addParticipantJawatan');
                $sel.empty();
                $sel.append($('<option>').val('').text('-- Please Select --'));
                if (type === 'pengurus') {
                    $sel.append($('<option>').val('PENGURUS').text('Manager'));
                    $sel.append($('<option>').val('PENOLONG PENGURUS').text('Penolong Manager'));
                } else if (type === 'jurulatih') {
                    $sel.append($('<option>').val('JURULATIH').text('Coach'));
                    $sel.append($('<option>').val('PENOLONG JURULATIH').text('Penolong Coach'));
                }
                $sel.val('');
            }

            function populateUpdateJawatanOptions(type, selectedVal) {
                var $sel = $('#updParticipantJawatan');
                $sel.empty();
                $sel.append($('<option>').val('').text('-- Please Select --'));
                if (type === 'pengurus') {
                    $sel.append($('<option>').val('PENGURUS').text('Manager'));
                    $sel.append($('<option>').val('PENOLONG PENGURUS').text('Penolong Manager'));
                } else if (type === 'jurulatih') {
                    $sel.append($('<option>').val('JURULATIH').text('Coach'));
                    $sel.append($('<option>').val('PENOLONG JURULATIH').text('Penolong Coach'));
                }
                var v = (selectedVal || '').toString().trim().toUpperCase();
                $sel.val(v);
                $sel.data('original', v);
            }

            // Handle participant type change
            $('#addParticipantType').on('change', function() {
                var type = $(this).val();
                var isManagerType = (type === 'pengurus' || type === 'jurulatih');
                $('#addParticipantMatrikGroup').toggle(type === 'atlet');
                $('#addParticipantKategoriGroup').toggle(type === 'atlet');
                $('#addParticipantPhoneGroup').toggle(isManagerType);
                $('#addParticipantEmailGroup').toggle(isManagerType);
                $('#addParticipantJawatanGroup').toggle(isManagerType);
                $('#addParticipantJawatan').prop('required', isManagerType);
                if (isManagerType) {
                    populateJawatanOptions(type);
                } else {
                    $('#addParticipantJawatan').val('');
                }
                if (type === 'atlet') {
                    var pasukanId = $('#addParticipantPasukan').val();
                    if (pasukanId) {
                        loadKategoriForPasukan(pasukanId);
                    }
                }
            });
            
            // Handle pasukan change - load kategori for athletes
            $(document).on('change', '#addParticipantPasukan', function() {
                var pasukanId = $(this).val();
                var type = $('#addParticipantType').val();
                if (type === 'atlet' && pasukanId) {
                    loadKategoriForPasukan(pasukanId);
                }
            });

            // Handle pasukan change in update modal (to refresh acara list for atlet)
            $(document).on('change', '#updParticipantPasukan', function() {
                var type = ($('#updParticipantType').val() || '').toString();
                if (type !== 'atlet') { return; }
                var sel = $(this).find('option:selected');
                var sid = parseInt(sel.data('sukan-id'), 10) || null;
                populateUpdateKategoriSelect(sid, null);
                $('#updParticipantKategori').data('original', '');
            });
            
            function loadKategoriForPasukan(pasukanId) {
                var pasukan = availablePasukan[pasukanId];
                if (!pasukan || !pasukan.sukan_id) return;
                
                $.ajax({
                    url: '<?php echo url('ajax/get_kategori_by_sukan.php'); ?>',
                    data: { sukan_id: pasukan.sukan_id },
                    dataType: 'json'
                }).done(function(res) {
                    if (res.success && Array.isArray(res.data)) {
                        var $select = $('#addParticipantKategori');
                        $select.empty().append($('<option>').val('').text('Select an event...'));
                        res.data.forEach(function(k) {
                            $select.append($('<option>').val(k.id).text(englishSportLabel(k.nama_kategori)));
                        });
                    }
                });
            }
            
            // Handle add participant submit
            $('#addParticipantSubmit').on('click', function() {
                var type = $('#addParticipantType').val();
                var pasukanId = parseInt($('#addParticipantPasukan').val(), 10);
                var nama = $('#addParticipantNama').val().trim();
                var ic = $('#addParticipantIC').val().trim();
                var matrik = $('#addParticipantMatrik').val().trim();
                var phone = $('#addParticipantPhone').val().trim();
                var email = $('#addParticipantEmail').val().trim();
                var jawatan = ($('#addParticipantJawatan').val() || '').trim();
                var kategoriId = $('#addParticipantKategori').val() ? parseInt($('#addParticipantKategori').val(), 10) : null;
                
                if (!type || !pasukanId || !nama) {
                    alert('Please complete all required fields.');
                    return;
                }
                if ((type === 'pengurus' || type === 'jurulatih') && !jawatan) {
                    alert('Please select a position.');
                    return;
                }
                
                var data = {
                    participant_type: type,
                    pasukan_id: pasukanId,
                    nama: nama,
                    no_kad_pengenalan: ic || null,
                    no_matrik: (type === 'atlet' ? (matrik || null) : null),
                    no_telefon: (type === 'pengurus' || type === 'jurulatih' ? (phone || null) : null),
                    emel: (type === 'pengurus' || type === 'jurulatih' ? (email || null) : null),
                    jawatan: (type === 'pengurus' || type === 'jurulatih' ? jawatan.toUpperCase() : null),
                    kategori_id: (type === 'atlet' ? kategoriId : null)
                };
                
                $.ajax({
                    url: '<?php echo url('ajax/participant_add.php'); ?>',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify(data),
                    dataType: 'json'
                }).done(function(response) {
                    if (response.success) {
                        $('#addParticipantModal').modal('hide');
                        $('#addParticipantForm')[0].reset();
                        $('#addParticipantMatrikGroup').hide();
                        $('#addParticipantKategoriGroup').hide();
                        $('#addParticipantPhoneGroup').hide();
                        $('#addParticipantEmailGroup').hide();
                        $('#addParticipantJawatanGroup').hide();
                        $('#addParticipantJawatan').empty().append($('<option>').val('').text('-- Please Select --')).prop('required', false);
                        reloadFromServer();
                    } else {
                        alert('Error: ' + (response.message || 'Unable to add a participant.'));
                    }
                }).fail(function(xhr, status, error) {
                    var errorMsg = 'Unable to add a participant';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg += ': ' + xhr.responseJSON.message;
                    } else {
                        errorMsg += ': ' + error;
                    }
                    alert(errorMsg);
                });
            });
            
            // Handle update participant modal show + submit
            function toggleUpdateFields(type) {
                $('#updParticipantMatrikGroup').toggle(type === 'atlet');
                $('#updParticipantKategoriGroup').toggle(type === 'atlet');
                $('#updParticipantPhoneGroup').toggle(type === 'pengurus' || type === 'jurulatih');
                $('#updParticipantEmailGroup').toggle(type === 'pengurus' || type === 'jurulatih');
                $('#updParticipantJawatanGroup').toggle(type === 'pengurus' || type === 'jurulatih');
                $('#updParticipantJawatan').prop('required', type === 'pengurus' || type === 'jurulatih');
                if (type !== 'atlet') {
                    $('#updParticipantKategori').val('').data('original', '');
                }
            }

            function setUpdateField($input, value) {
                var val = value || '';
                $input.val(val);
                $input.data('original', val);
            }

            $(document).on('click', '.update-participant-btn', function(e) {
                e.preventDefault();
                var $row = $(this).closest('tr');
                var pid = parseInt($row.data('participant-id'), 10) || 0;
                var type = ($row.data('participant-type') || '').toString();
                if (!pid || !type) return;

                var name = $row.data('nama') || '';
                var ic = $row.data('ic') || '';
                var matrik = $row.data('matrik') || '';
                var phone = $row.data('telefon') || '';
                var email = $row.data('emel') || '';
                var jawatan = $row.data('jawatan') || '';
                var pasukanIdCurrent = parseInt($row.data('pasukan-id'), 10) || 0;
                var kategoriIdCurrent = parseInt($row.data('kategori-id'), 10) || null;
                var sukanIdCurrent = parseInt($row.data('sukan-id'), 10) || null;

                $('#updParticipantId').val(pid);
                $('#updParticipantType').val(type);
                $('#updateParticipantTitle').text('Update ' + (type.charAt(0).toUpperCase() + type.slice(1)));

                toggleUpdateFields(type);
                populateUpdatePasukanSelect(pasukanIdCurrent, sukanIdCurrent, type === 'atlet' ? kategoriIdCurrent : null);
                setUpdateField($('#updParticipantName'), name);
                setUpdateField($('#updParticipantIC'), ic);
                setUpdateField($('#updParticipantMatrik'), matrik);
                setUpdateField($('#updParticipantPhone'), phone);
                setUpdateField($('#updParticipantEmail'), email);
                if (type === 'pengurus' || type === 'jurulatih') {
                    populateUpdateJawatanOptions(type, jawatan);
                } else {
                    $('#updParticipantJawatan').empty().append($('<option>').val('').text('-- Please Select --')).data('original', '');
                }

                $('#updateParticipantModal').modal('show');
            });

            $('#updateParticipantSubmit').on('click', function() {
                var pid = parseInt($('#updParticipantId').val(), 10) || 0;
                var type = ($('#updParticipantType').val() || '').toString();
                if (!pid || !type) {
                    alert('Invalid participant details.');
                    return;
                }

                var name = ($('#updParticipantName').val() || '').trim();
                var ic = ($('#updParticipantIC').val() || '').trim();
                var matrik = ($('#updParticipantMatrik').val() || '').trim();
                var phone = ($('#updParticipantPhone').val() || '').trim();
                var email = ($('#updParticipantEmail').val() || '').trim();
                var jawatan = ($('#updParticipantJawatan').val() || '').trim().toUpperCase();

                if (!name) {
                    alert('Name is required.');
                    return;
                }
                if ((type === 'pengurus' || type === 'jurulatih') && !jawatan) {
                    alert('Please select a position.');
                    return;
                }

                var updates = [];
                var pasukanNew = parseInt($('#updParticipantPasukan').val(), 10) || 0;
                var kategoriNew = $('#updParticipantKategori').val() ? parseInt($('#updParticipantKategori').val(), 10) : null;

                function addIfChanged(fieldName, newVal, $field, meta) {
                    var original = ($field.data('original') || '').toString();
                    var current = (newVal === null || newVal === undefined) ? '' : newVal.toString();
                    if (original !== current) {
                        var payload = { field_name: fieldName, field_value: newVal };
                        if (meta) {
                            Object.keys(meta).forEach(function(k){ payload[k] = meta[k]; });
                        }
                        updates.push(payload);
                    }
                }

                var selectedPasukan = $('#updParticipantPasukan option:selected');
                var pasukanName = selectedPasukan.text() || '';
                var pasukanSukanId = parseInt(selectedPasukan.data('sukan-id'), 10) || null;
                var pasukanSukanName = selectedPasukan.data('sukan-name') || '';
                var selectedKategori = $('#updParticipantKategori option:selected');
                var kategoriName = selectedKategori.text() || '';

                addIfChanged('pasukan_id', pasukanNew, $('#updParticipantPasukan'), {
                    pasukan_name: pasukanName,
                    sukan_id: pasukanSukanId,
                    sukan_name: pasukanSukanName
                });
                if (type === 'atlet') {
                    addIfChanged('kategori_id', kategoriNew, $('#updParticipantKategori'), {
                        kategori_name: kategoriName
                    });
                }

                addIfChanged('nama', name, $('#updParticipantName'));
                addIfChanged('no_kad_pengenalan', ic, $('#updParticipantIC'));
                if (type === 'atlet') {
                    addIfChanged('no_matrik', matrik, $('#updParticipantMatrik'));
                }
                if (type === 'pengurus' || type === 'jurulatih') {
                    addIfChanged('no_telefon', phone, $('#updParticipantPhone'));
                    addIfChanged('emel', email, $('#updParticipantEmail'));
                    addIfChanged('jawatan', jawatan, $('#updParticipantJawatan'));
                }

                if (updates.length === 0) {
                    $('#updateParticipantModal').modal('hide');
                    return;
                }

                // Helper to update row UI without full reload
                function applyUpdatesToRow($row, updatesArr, participantType) {
                    updatesArr.forEach(function(u) {
                        var field = u.field_name;
                        var val = u.field_value || '';
                        if (field === 'nama') $row.data('nama', val);
                        if (field === 'no_kad_pengenalan') $row.data('ic', val);
                        if (field === 'no_matrik') $row.data('matrik', val);
                        if (field === 'no_telefon') $row.data('telefon', val);
                        if (field === 'emel') $row.data('emel', val);
                        if (field === 'jawatan') $row.data('jawatan', val);
                        if (field === 'pasukan_id') {
                            $row.data('pasukan-id', val);
                            if (typeof u.sukan_id !== 'undefined' && u.sukan_id !== null) {
                                $row.data('sukan-id', u.sukan_id);
                            }
                            var $cells = $row.children('td');
                            if (participantType === 'atlet') {
                                if (u.sukan_name) { $cells.eq(4).text(u.sukan_name); }
                                if (u.pasukan_name) { $cells.eq(5).text(u.pasukan_name); }
                            } else {
                                if (u.sukan_name) { $cells.eq(5).text(u.sukan_name); }
                                if (u.pasukan_name) { $cells.eq(6).text(u.pasukan_name); }
                            }
                        }
                        if (field === 'kategori_id') {
                            $row.data('kategori-id', val);
                            var $cells = $row.children('td');
                            if (participantType === 'atlet' && u.kategori_name) {
                                $cells.eq(6).text(u.kategori_name);
                            }
                        }
                        var $cell = $row.find('.editable-cell[data-field="' + field + '"]');
                        if ($cell.length) {
                            updateCellDisplay($cell, val, field);
                        }
                    });
                }

                var needsFullReload = updates.some(function(u){
                    return u.field_name === 'pasukan_id' || u.field_name === 'kategori_id';
                });

                var requests = updates.map(function(u) {
                    return $.ajax({
                        url: '<?php echo url('ajax/participant_update.php'); ?>',
                        method: 'POST',
                        contentType: 'application/json',
                        data: JSON.stringify({
                            participant_id: pid,
                            participant_type: type,
                            field_name: u.field_name,
                            field_value: u.field_value
                        }),
                        dataType: 'json'
                    });
                });

                $.when.apply($, requests).done(function() {
                    $('#updateParticipantModal').modal('hide');
                    var $row = $('.details-row').find('[data-participant-id="' + pid + '"][data-participant-type="' + type + '"]').first();
                    if (needsFullReload) {
                        reloadFromServer();
                    } else if ($row.length) {
                        applyUpdatesToRow($row, updates, type);
                    } else {
                        reloadFromServer();
                    }
                }).fail(function(xhr) {
                    var msg = 'Unable to update the participant.';
                    if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
                        msg = xhr.responseJSON.message;
                    }
                    alert(msg);
                });
            });

            // Handle delete participant
            $(document).on('click', '.delete-participant-btn', function(e) {
                e.stopPropagation();
                var $btn = $(this);
                var participantId = $btn.data('participant-id');
                var participantType = $btn.data('participant-type');
                var participantName = $btn.data('participant-name') || 'this participant';
                
                if (!confirm('Are you sure you want to delete ' + participantName + '?')) {
                    return;
                }
                
                $.ajax({
                    url: '<?php echo url('ajax/participant_delete.php'); ?>',
                    method: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        participant_id: participantId,
                        participant_type: participantType
                    }),
                    dataType: 'json'
                }).done(function(response) {
                    if (response.success) {
                        reloadFromServer();
                    } else {
                        alert('Error: ' + (response.message || 'Unable to delete the participant.'));
                    }
                }).fail(function(xhr, status, error) {
                    alert('Unable to delete the participant: ' + error);
                });
            });

            renderPage();
        }

        $(document).on('click', '.contingent-view-btn', function(e){
            e.preventDefault();
            var $btn = $(this);
            var $tr = $btn.closest('tr');
            var kid = $btn.data('kid') || $tr.data('kontinjen');
            if (!kid) return;
            var $next = $tr.next();
            if ($next.hasClass('details-row') && String($next.data('kid')) === String(kid)) { $next.remove(); $btn.text('View'); $tr.removeClass('row-highlight'); return; }
            $('.details-row').remove();
            $('.contingent-view-btn').text('View');
            $('tr.row-highlight').removeClass('row-highlight');
            fetchParticipants(kid).done(function(res){
                if (res.status !== 'ok') { alert('No data'); return; }
                renderDetailsRow($tr, res);
                $tr.find('.contingent-view-btn').text('Close');
                $tr.addClass('row-highlight');
            }).fail(function(){ alert('Unable to load participants'); });
        });
    });
})();
</script>

<?php
    $content = ob_get_clean();
    require_once __DIR__ . '/../includes/layout.php';
