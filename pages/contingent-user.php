<?php
/**
 * Contingent User Management (view-only)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../api/models/ContingentModel.php';

// Start session & auth
Session::start();
$auth = getAuth();
$auth->requireAuth();
$currentUserRole = Session::get('user_role') ?? '';
$restrictToOwnContingent = ($currentUserRole === 'CONTINGENT');

// Restrict this page to CONTINGENT role only
if ($currentUserRole !== 'CONTINGENT') {
    header('Location: ' . url('pages/access-denied.php'));
    exit;
}
$currentKontinjenId = Session::get('kontinjen_id') ?? null;

if ($restrictToOwnContingent && empty($currentKontinjenId)) {
    header('Location: ' . url('pages/access-denied.php'));
    exit;
}

$page_title = 'Contingent User';
// Override page title with kod_universiti if available (matches menu)
$contingentCode = Session::get('kod_universiti') ?? Session::get('kod_university') ?? '';
if (!$contingentCode && $restrictToOwnContingent && !empty($currentKontinjenId)) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT k.kod_universiti FROM table_kontinjen k WHERE k.id = :id AND k.deleted_at IS NULL");
        $stmt->execute([':id' => $currentKontinjenId]);
        $contingentCode = $stmt->fetchColumn() ?: '';
    } catch (Exception $e) {
        // ignore lookup errors and keep default title
    }
}
if ($contingentCode) {
    $page_title = 'Contingent ' . $contingentCode;
}

// Fetch contingents and participant counts (reuse existing queries)
$contingents = [];
try {
    $pdo = getDB();
    $where = "k.deleted_at IS NULL AND k.status = 1";
    if ($restrictToOwnContingent && $currentKontinjenId) {
        $where .= " AND k.id = :kontinjen_id";
    }

    $sql = "SELECT
        k.id,
        u.nama_universiti,
        k.kod_universiti,
        k.nama_pegawai_untuk_dihubungi,
        k.alamat,
        k.emel,
        k.no_telefon,
        COALESCE(SUM(a.cnt),0) AS jumlah_atlet,
        CASE WHEN u.status = 1 THEN 'Active' ELSE 'Inactive' END AS status_universiti,
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
    WHERE " . $where . "
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
    LIMIT 1000";

    if ($restrictToOwnContingent && $currentKontinjenId) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':kontinjen_id' => (int)$currentKontinjenId]);
    } else {
        $stmt = $pdo->query($sql);
    }
    $contingents = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('[contingent-user.php] DB error: ' . $e->getMessage());
}
// Determine current contingent name (if page is restricted to own contingent)
$contingentName = '';
if (!empty($contingents) && isset($contingents[0]['nama_universiti'])) {
    $contingentName = $contingents[0]['nama_universiti'];
}

ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/contingent-admin.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/contingent-admin.css'); ?>">
<link rel="stylesheet" href="<?php echo asset('css/contingent-user.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/contingent-user.css'); ?>">
<div class="w-100 px-3 contingent-admin-view contingent-user-view">
    <div class="row mb-4 contingent-heading">
        <div class="col-12">
            <div class="card bg-light border-0 shadow-sm overflow-hidden">
                <div class="card-body py-4 d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                    <div>
                        <div class="contingent-eyebrow">SAM 2026 &middot; MY CONTINGENT</div>
                        <h2 class="mb-1"><?php echo htmlspecialchars($page_title, ENT_QUOTES, "UTF-8"); ?></h2>
                        <p class="text-muted mb-0">View your contingent profile, officials and registered participants.</p>
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
                        <strong>Contingent Overview</strong>
                        <p class="contingent-description">Select View Participants to explore teams, sports and events.</p>
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
                                    <th scope="col" style="width:8%;">Status</th>
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
                                            <span class="badge badge-pill badge-primary"><?php echo $count; ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($c['status_universiti'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-primary contingent-view-btn" data-kid="<?php echo htmlspecialchars($c['id'], ENT_QUOTES, 'UTF-8'); ?>">View Participants</button>
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

/* Highlight rows for Manager and Coach */
.details-row tr.role-pengurus td, .details-row tr.role-jurulatih td {
    background-color: rgba(255, 245, 204, 0.9); /* light yellow */
}
.details-row tr.role-pengurus td:first-child, .details-row tr.role-jurulatih td:first-child {
    border-left: 4px solid #f0ad4e; /* accent */
}
/* Soft red badge for empty values */
.no-data-badge{display:inline-block;padding:0.18rem 0.45rem;border-radius:0.35rem;background:#fdecea;color:#842029;font-size:0.8rem}

/* Force single-line rows inside details table and enable truncation */
.details-row .table{table-layout:fixed;width:100%}
.details-row .table th,.details-row .table td{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.details-row .table th:first-child,.details-row .table td:first-child{text-align:center;}
.details-row .p-3{overflow:auto}
.role-pengurus td:first-child,.role-jurulatih td:first-child{border-left:4px solid #f0ad4e;}
.role-pengurus td,.role-jurulatih td{background:rgba(255,245,204,0.6);}
</style>

<script>
// Inline details expansion (view-only)
(function(){
    function whenJQ(cb){ if(window.jQuery){ cb(window.jQuery); } else { setTimeout(function(){ whenJQ(cb); }, 50); } }

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
            var $container = $('<div class="p-3 participant-section">');
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
            // Top bar: left-aligned selects (sport + acara) and right-aligned search + pager controls
            var $topBar = $('<div class="details-top-controls mb-2"></div>');
            var $select = $('<select class="form-select form-select-sm details-select select-sukan"><option value="">All Sports</option></select>');
            var $selectAcara = $('<select class="form-select form-select-sm details-select select-acara"><option value="">All Events</option></select>');
            // disable acara until categories are loaded and populated for chosen sport
            $selectAcara.prop('disabled', true);
            var $search = $('<input type="search" class="form-control form-control-sm details-search" placeholder="Search peserta...">');
            var $pageSizeSel = $('<select class="form-select form-select-sm details-pagesize"><option value="5">5</option><option value="10">10</option><option value="25" selected>25</option><option value="50">50</option><option value="100">100</option></select>');
            // left and right control groups
            var $leftControls = $('<div class="left-controls"></div>').append($select).append($selectAcara);
            var $rightControls = $('<div class="right-controls"></div>').append($search).append($pageSizeSel);
            $topBar.append($leftControls).append($rightControls);

            var $table = $('<table class="table table-sm table-striped mb-0"><colgroup>' +
                '<col style="width:3%"></col>' +
                '<col style="width:27%"></col>' +
                '<col style="width:15%"></col>' +
                '<col style="width:10%"></col>' +
                '<col style="width:10%"></col>' +
                '<col style="width:15%"></col>' +
                '<col style="width:20%"></col>' +
                '</colgroup><thead><tr>' +
                '<th>#</th>' +
                '<th>Name</th>' +
                '<th>Position</th>' +
                '<th>Role</th>' +
                '<th>Sport</th>' +
                '<th>Team</th>' +
                '<th>Event</th>' +
                '</tr></thead><tbody></tbody></table>');
            var $summary = $('<div class="text-muted small details-summary me-2"></div>');
            var $pager = $('<nav aria-label="Page navigation"><ul class="pagination pagination-sm mb-0"></ul></nav>');
            // keep search + pagesize in top-right; summary + pager sit below the table
            $rightControls.append($search).append($pageSizeSel);

            var $pagerWrap = $('<div class="d-flex justify-content-between align-items-center mt-2"></div>');
            $pagerWrap.append($summary).append($pager);

            $container.append($topBar).append($table).append($pagerWrap);
            $detail.find('td').append($container);
            $tr.after($detail);
            // mark originating button as Hide
            $tr.find('.contingent-view-btn').text('Hide Participants');

            function buildRows(data){
                var list = [];
                (data.pengurus||[]).forEach(function(r){ r._role='Manager'; list.push(r); });
                (data.jurulatih||[]).forEach(function(r){ r._role='Coach'; list.push(r); });
                (data.atlet||[]).forEach(function(r){ r._role='Athlete'; list.push(r); });
                return list;
            }

            var rows = buildRows(res);

            // Populate sport select and acara select
            var map = {};
            var mapAcara = {};
            // sport -> acara mapping for dependent dropdown
            var mapBySport = {};
            ['atlet','pengurus','jurulatih'].forEach(function(k){
                (res[k]||[]).forEach(function(r){
                    var id = r.sukan_id || '';
                    var name = r.nama_sukan || ('Sport ' + id);
                    if (id && !map[id]) map[id]=name;

                    var aid = r.kategori_id || r.id_kategori || r.kategori || r.event_id || r.acara_id || '';
                    var aname = r.nama_kategori || r.nama_acara || r.acara || r.event_name || r.kategori || (aid?('Event '+aid):'');
                    if (aid){ if(!mapAcara[aid]) mapAcara[aid]=aname; }
                    else { if(aname && !mapAcara[aname]) mapAcara[aname]=aname; }

                    // populate mapBySport
                    if (id){
                        if (!mapBySport[id]) mapBySport[id] = {};
                        var key = aid || aname || '';
                        if (key && !mapBySport[id][key]) mapBySport[id][key] = aname || key;
                    }
                });
            });
            Object.keys(map).forEach(function(id){ $select.append($('<option>').val(id).text(map[id])); });

            // function to populate acara options filtered by sport id (or all when empty)
            function populateAcaraOptionsForSport(sportId){
                $selectAcara.empty();
                $selectAcara.append($('<option>').val('').text('All Events'));
                var added = 0;
                if(!sportId){
                    // no sport selected: keep disabled and do not list all acara
                    $selectAcara.prop('disabled', true);
                    return;
                }
                var m = mapBySport[sportId] || {};
                Object.keys(m).forEach(function(aid){ $selectAcara.append($('<option>').val(aid).text(m[aid])); added++; });
                if(added === 0){
                    // no acara for this sport
                    $selectAcara.prop('disabled', true);
                } else {
                    $selectAcara.prop('disabled', false);
                }
            }

            // Load canonical acara list from server (table_kategori) and build mapBySport
            $.ajax({ url: '<?php echo url('ajax/kategori_list.php'); ?>', dataType: 'json' }).done(function(kres){
                if (kres && kres.success && Array.isArray(kres.data)){
                    mapAcara = {};
                    mapBySport = {};
                    kres.data.forEach(function(row){
                        var aid = row.id;
                        var sid = row.sukan_id;
                        var aname = row.nama_kategori || row.nama_acara || row.kod_kategori || ('Event '+aid);
                        if (aid) mapAcara[aid] = aname;
                        if (sid){ mapBySport[sid] = mapBySport[sid] || {}; mapBySport[sid][aid] = aname; }
                    });
                } else {
                    // if server returns no categories, try to build mapBySport from participant-derived mapBySport
                    // mapBySport may already be populated from participants above
                }
                // initialize acara options filtered by currently selected sport (if any)
                populateAcaraOptionsForSport($select.val() || '');
                // now render page
                renderPage();
            }).fail(function(){
                // fallback: use participant-derived mapBySport
                populateAcaraOptionsForSport($select.val() || '');
                renderPage();
            });

            var $tbody = $table.find('tbody');
            var pageSize = parseInt($pageSizeSel.val(),10) || 25;
            var currentPage = 1;

            function applyFilters(){
                var q = ($search.val()||'').trim().toLowerCase();
                var filterIds = getFilters();
                var sportId = filterIds.sportId;
                var kategoriId = filterIds.kategoriId;

                function matchesSearch(r){
                    var name = (r.nama || r.nama_peserta || r.nama_atlet || r.nama_pengurus || r.nama_jurulatih || '').toString().toLowerCase();
                    if (q && name.indexOf(q) === -1) return false;
                    return true;
                }

                var filteredByIds = rows.filter(function(r){
                    if (sportId && String(r.sukan_id || '') !== String(sportId)) return false;
                    if (kategoriId){
                        var kidVal = r.kategori_id || r.id_kategori || r.acara_id || r.event_id;
                        if (String(kidVal || '') !== String(kategoriId)) return false;
                    }
                    return true;
                });

                var managers = filteredByIds.filter(function(r){
                    var role = (r._role||'').toString();
                    return (role === 'Manager' || role === 'Coach') && matchesSearch(r);
                });

                var athletes = filteredByIds.filter(function(r){
                    return (r._role||'') === 'Athlete' && matchesSearch(r);
                });

                var ordered = [];
                managers.filter(function(m){ return (m._role||'') === 'Manager'; }).forEach(function(m){ ordered.push(m); });
                managers.filter(function(m){ return (m._role||'') === 'Coach'; }).forEach(function(m){ ordered.push(m); });
                athletes.forEach(function(a){ ordered.push(a); });
                return ordered;
            }

            function genderFromMyKad(ic){
                var digits = (ic||'').toString().replace(/\D+/g,'');
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

            function renderPage(){
                var filtered = applyFilters();
                var total = filtered.length;
                var totalPages = Math.max(1, Math.ceil(total / pageSize));
                if (currentPage > totalPages) currentPage = totalPages;
                var start = (currentPage - 1) * pageSize;
                var end = start + pageSize;
                $tbody.empty();
                if (total === 0) { $tbody.append('<tr><td colspan="7">No participants match these filters.</td></tr>'); }
                var pageRows = filtered.slice(start, end);
                var athleteBefore = filtered.slice(0, start).filter(function(r){ return (r._role||'').toLowerCase() === 'athlete'; }).length;
                var athleteCounter = athleteBefore;
                pageRows.forEach(function(r, idx){
                    var name = r.nama || r.nama_peserta || r.nama_atlet || r.nama_pengurus || r.nama_jurulatih || '';
                    var nic = r.no_kad_pengenalan || r.ic || r.no_ic || r.mykad || '';
                    var jawatan = r.jawatan || '';
                    var role = r._role || '';
                    var sport = r.nama_sukan || ('Sport ' + (r.sukan_id || ''));
                    // For Manager and Coach, always show 'None' for Event (use empty value so badge is rendered)
                    var acara = r.nama_acara || r.nama_kategori || r.kategori || r.acara || r.event_name || r.nama_event || '';
                    if ((role||'').toString().toLowerCase() === 'pengurus' || (role||'').toString().toLowerCase() === 'jurulatih') {
                        acara = '';
                    }
                    var team = r.nama_pasukan || '';
                    var $r = $('<tr>');
                    var roleClass = 'role-' + (role||'').toString().toLowerCase();
                    $r.addClass(roleClass);
                    function cell(val){
                        if (val === null || val === undefined || String(val).trim() === '') {
                            return $('<td>').append($('<span>').addClass('no-data-badge').text('None'));
                        }
                        var display = String(val);
                        return $('<td>').addClass('text-truncate').text(display).attr('title', display);
                    }
                    var roleLower = (role||'').toString().toLowerCase();
                    if (roleLower === 'atlet') {
                        athleteCounter++;
                        $r.append($('<td class="text-center">').text(athleteCounter));
                    } else {
                        var iconHtml = roleGenderIcon(roleLower, nic);
                        $r.append($('<td class="text-center">').html(iconHtml));
                    }
                    $r.append(cell(name));
                    $r.append(cell(englishLabel(jawatan)));
                    $r.append(cell(englishLabel(role)));
                    $r.append(cell(englishSportLabel(sport)));
                    $r.append(cell(team));
                    $r.append(cell(englishSportLabel(acara)));
                    $tbody.append($r);
                });

                // Render pager (simple prev/next + page numbers upto 5 pages)
                var $ul = $pager.find('ul'); $ul.empty();
                var createPageItem = function(label, page, disabled, active){
                    var $li = $('<li class="page-item">'); if (disabled) $li.addClass('disabled'); if (active) $li.addClass('active');
                    var $a = $('<a class="page-link" href="#">').text(label).data('page', page);
                    $li.append($a); return $li;
                };
                $ul.append(createPageItem('<<', Math.max(1,currentPage-1), currentPage===1, false));
                var startPage = Math.max(1, currentPage - 2); var endPage = Math.min(totalPages, startPage + 4);
                for (var p = startPage; p <= endPage; p++){ $ul.append(createPageItem(p, p, false, p===currentPage)); }
                $ul.append(createPageItem('>>', Math.min(totalPages,currentPage+1), currentPage===totalPages, false));
                // Update summary
                var showingStart = total === 0 ? 0 : start + 1;
                var showingEnd = Math.min(total, end);
                if (total === 0) {
                    $summary.text('No data');
                } else {
                    $summary.text('Showing ' + showingStart + ' - ' + showingEnd + ' of ' + total);
                }
            }

            // Handlers
            function reloadFromServer(){
                currentSport = $select.val() || '';
                currentAcara = $selectAcara.val() || '';
                var ids = getFilters();
                fetchParticipants(kid, ids.sportId || '', ids.kategoriId || '' ).done(function(newRes){
                    if (newRes.status !== 'ok') { alert('No data'); return; }
                    rows = buildRows(newRes);
                    renderPage();
                }).fail(function(){ alert('Unable to load participants'); });
            }

            $select.on('change', function(){
                // repopulate acara options for the selected sport and reset acara selection
                var sv = $(this).val() || '';
                populateAcaraOptionsForSport(sv);
                $selectAcara.val('');
                currentPage = 1;
                reloadFromServer();
            });
            $selectAcara.on('change', function(){ currentPage = 1; reloadFromServer(); });
            $search.on('input', function(){ currentPage = 1; renderPage(); });
            $pageSizeSel.on('change', function(){ pageSize = parseInt($(this).val(),10) || 25; currentPage = 1; renderPage(); });
            $pager.on('click', 'a.page-link', function(e){ e.preventDefault(); var p = $(this).data('page'); if (!p) return; currentPage = parseInt(p,10)||1; renderPage(); });

            // initial render
            renderPage();
        }

        $(document).on('click', '.contingent-view-btn', function(e){
            e.preventDefault();
            var $btn = $(this);
            var $tr = $btn.closest('tr');
            var kid = $btn.data('kid') || $tr.data('kontinjen');
            if (!kid) return;
            var $next = $tr.next();
            // if its own details row is open, close it and reset label
            if ($next.hasClass('details-row') && String($next.data('kid')) === String(kid)) { $next.remove(); $btn.text('View Participants'); return; }
            // close any other open details and reset other buttons
            $('.details-row').remove();
            $('.contingent-view-btn').text('View Participants');
            fetchParticipants(kid).done(function(res){
                if (res.status !== 'ok') { alert('No data'); return; }
                renderDetailsRow($tr, res);
                // set this button to Hide after rendering
                $tr.find('.contingent-view-btn').text('Hide Participants');
            }).fail(function(){ alert('Unable to load participants'); });
        });
    });
})();
</script>

<?php
    $content = ob_get_clean();
    require_once __DIR__ . '/../includes/layout.php';
