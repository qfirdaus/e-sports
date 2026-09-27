<?php
/**
 * Venues Management Page
 */
require_once __DIR__ . '/../config.php';

$page_title = 'Venues';

ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/contingent-management.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/contingent-management.css'); ?>">
<link rel="stylesheet" href="<?php echo asset('css/venues.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/venues.css'); ?>">
<div class="w-100 px-3 contingent-management venues-view">
    <!-- Hero -->
    <div class="row mb-4 management-heading">
        <div class="col-12">
            <div class="card bg-light border-0 shadow-sm overflow-hidden">
                <div class="card-body py-4 d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                    <div>
                        <div class="management-eyebrow">SAM 2026 &middot; VENUE MANAGEMENT</div><h2 class="mb-1">Venue</h2>
                        <p class="text-muted mb-0">Manage competition locations and venues</p>
                    </div>

                    <div class="d-flex align-items-center gap-3">
                        <div class="management-stats">
                            <div class="me-3 text-center">
                                <div class="h5 mb-0">0</div>
                                <div class="small text-muted">Venue</div>
                            </div>
                            <div class="me-3 text-center">
                                <div class="h5 mb-0">0</div>
                                <div class="small text-muted">Capacity</div>
                            </div>
                            <div class="me-3 text-center">
                                <div class="h5 mb-0">0</div>
                                <div class="small text-muted">Sport</div>
                            </div>
                        </div>

                        <div class="btn-group">
                            <button class="btn btn-outline-secondary">Reports</button>
                            <button class="btn btn-primary" onclick="showAddVenue()">
                                <i class="cil cil-plus me-1"></i> Register New Venue
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Search removed from its own row; now displayed inline in card header -->

    <!-- Venues List -->
    <div class="row">
        <div class="col-12">
            <div class="card mb-4 shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <strong>Venue List</strong>
                        <div class="small text-muted">Manage all registered venues</div>
                    </div>
                    <div class="d-flex align-items-center">
                        <div class="input-group input-group-sm" style="min-width:260px;">
                            <span class="input-group-text"><i class="cil cil-magnifying-glass"></i></span>
                            <input type="search" class="form-control" id="venuesSearch" aria-label="Search by venue name or location" placeholder="Search by name or location...">
                        </div>
                        <select id="venuesPageSizeTopSelect" aria-label="Venues per page" class="form-select form-select-sm ms-2" style="width: auto;">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" style="width:70px;">#</th>
                                    <th scope="col">Venue Name</th>
                                    <th scope="col">Location</th>
                                    <th scope="col">Capacity</th>
                                    <th scope="col">Sport</th>
                                    <th scope="col" style="width:120px;">Status</th>
                                    <th scope="col" style="width:160px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="venuesTableBody">
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-5">
                                        <i class="cil cil-map" style="font-size: 2rem;"></i>
                                        <p class="mt-2">No venues registered. Click "Register New Venue" to get started.</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex align-items-center justify-content-between mt-2">
                        <div id="venuesPagingInfo" class="small text-muted"></div>
                        <div id="venuesPagination" class="ms-auto"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>

<!-- Add Venue Modal -->
<!-- Scoped styles: professional centered modal that avoids header overlap -->
<style>
/* Base z-index layering */
#addVenueModal { z-index: 1060 !important; }
.modal-backdrop { z-index: 1050 !important; }

/* Center modal both horizontally and vertically by default.
   JS will nudge it down if it would overlap the header. */
#addVenueModal .modal-dialog {
    position: fixed;
    left: 50%;
    top: 50%;
    transform: translate(-50%, -50%) !important;
    margin: 0;
    max-width: 1000px; /* generous professional width */
    width: min(100%, 900px);
}

#addVenueModal .modal-content {
    max-height: calc(100vh - 120px);
    overflow: auto;
}

@media (max-width: 992px) {
    #addVenueModal .modal-dialog { width: calc(100% - 3rem); max-width: 760px; }
}
@media (max-width: 576px) {
    #addVenueModal .modal-dialog { width: calc(100% - 1rem); max-width: 95%; left: 50%; top: 14%; transform: translateX(-50%) !important; }
    #addVenueModal .modal-content { max-height: calc(100vh - 96px); }
}
</style>

<div class="modal fade" id="addVenueModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Register New Venue</h5>
                <button type="button" class="btn-close" aria-label="Close" onclick="closeAddVenueModal()"></button>
            </div>
            <div class="modal-body">
                <form id="venueForm">
                    <div class="mb-3">
                        <label for="venueName" class="form-label">Venue Name</label>
                        <input type="text" id="venueName" name="venueName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label for="venueLocation" class="form-label">Location</label>
                        <input type="text" id="venueLocation" name="venueLocation" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label for="venueCapacity" class="form-label">Capacity</label>
                        <input type="number" id="venueCapacity" name="venueCapacity" class="form-control" min="0">
                    </div>
                    <div class="mb-3">
                        <label for="venueSport" class="form-label">Assigned sport</label>
                        <select id="venueSport" name="venueSport" class="form-select">
                            <option value="">-- Select a sport --</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="venueStatus" class="form-label">Status</label>
                        <select id="venueStatus" name="venueStatus" class="form-select">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddVenueModal()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="submitVenue()">Save</button>
            </div>
        </div>
    </div>
</div>

<script>
let addVenueModalInstance = null;
// Pagination state
let venuesData = [];
let venuesFiltered = [];
let venuesCurrentPage = 1;
let venuesPageSize = 10; // default page size

function setVenuesPageSize(size) {
    venuesPageSize = parseInt(size) || 10;
    venuesCurrentPage = 1;
    // Keep top selector in sync
    const topSel = document.getElementById('venuesPageSizeTopSelect');
    if (topSel) topSel.value = String(venuesPageSize);
    renderVenuesTablePage();
}

function goToVenuesPage(page) {
    const totalPages = Math.max(1, Math.ceil(venuesFiltered.length / venuesPageSize));
    venuesCurrentPage = Math.min(Math.max(1, parseInt(page) || 1), totalPages);
    renderVenuesTablePage();
}

function renderVenuesTablePage() {
    const tbody = document.getElementById('venuesTableBody');
    if (!tbody) return;
    const total = venuesFiltered.length;
    const totalPages = Math.max(1, Math.ceil(total / venuesPageSize));
    if (venuesCurrentPage > totalPages) venuesCurrentPage = totalPages;
    const start = (venuesCurrentPage - 1) * venuesPageSize;
    const end = Math.min(total, start + venuesPageSize);
    if (total === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-5"><i class="cil cil-map" style="font-size: 2rem;"></i><p class="mt-2">No venues registered. Click "Register New Venue" to get started.</p></td></tr>';
    } else {
        let html = '';
        const pageItems = venuesFiltered.slice(start, end);
        pageItems.forEach((v, i) => {
            const idx = start + i + 1;
            const status = (parseInt(v.status) === 1) ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>';
            html += '<tr>';
            html += '<td>' + idx + '</td>';
            html += '<td>' + escapeHtml(v.nama_venue || '-') + '</td>';
            html += '<td>' + escapeHtml(v.lokasi || '-') + '</td>';
            html += '<td class="text-center">' + (v.kapasiti !== null ? escapeHtml(String(v.kapasiti)) : '-') + '</td>';
            html += '<td>' + escapeHtml(v.sukan_name || '-') + '</td>';
            html += '<td>' + status + '</td>';
            html += '<td>';
            html += '<a href="#" class="btn btn-sm btn-outline-primary edit-venue me-1" title="Update venue" aria-label="Update venue" data-id="' + (v.id||0) + '"> <i class="fa fa-edit"></i></a>';
            html += '<a href="#" class="btn btn-sm btn-outline-danger delete-venue" title="Delete venue" aria-label="Delete venue" data-id="' + (v.id||0) + '"> <i class="fa fa-trash"></i></a>';
            html += '</td>';
            html += '</tr>';
        });
        tbody.innerHTML = html;
    }

    // Update paging info and controls
    const infoEl = document.getElementById('venuesPagingInfo');
    if (infoEl) {
        if (total === 0) infoEl.textContent = '';
        else infoEl.textContent = 'Showing ' + (start + 1) + '–' + end + ' of ' + total;
    }

    renderVenuesPaginationControls(total, venuesCurrentPage, venuesPageSize);
}

function renderVenuesPaginationControls(totalItems, currentPage, pageSize) {
    const container = document.getElementById('venuesPagination');
    if (!container) return;
    const totalPages = Math.max(1, Math.ceil(totalItems / pageSize));
    let html = '';
    // Pagination buttons (previous, pages, next)
    html += '<nav aria-label="venues-pagination"><ul class="pagination pagination-sm mb-0">';
    const prevDisabled = (currentPage <= 1) ? ' disabled' : '';
    html += '<li class="page-item' + prevDisabled + '"><a class="page-link" href="#" data-page="' + (currentPage - 1) + '">‹</a></li>';

    // show window of pages
    const maxButtons = 5;
    let startPage = Math.max(1, currentPage - Math.floor(maxButtons/2));
    let endPage = Math.min(totalPages, startPage + maxButtons - 1);
    if (endPage - startPage < maxButtons - 1) startPage = Math.max(1, endPage - maxButtons + 1);
    for (let p = startPage; p <= endPage; p++) {
        const active = (p === currentPage) ? ' active' : '';
        html += '<li class="page-item' + active + '"><a class="page-link" href="#" data-page="' + p + '">' + p + '</a></li>';
    }

    const nextDisabled = (currentPage >= totalPages) ? ' disabled' : '';
    html += '<li class="page-item' + nextDisabled + '"><a class="page-link" href="#" data-page="' + (currentPage + 1) + '">›</a></li>';
    html += '</ul></nav>';
    
    container.innerHTML = html;
    // Wire up events for pagination links
    const links = container.querySelectorAll('.page-link');
    links.forEach(a => {
        a.addEventListener('click', function(e) {
            e.preventDefault();
            const page = parseInt(this.getAttribute('data-page')) || 1;
            goToVenuesPage(page);
        });
    });
}

// Debounce helper
function debounce(fn, delay) {
    let t = null;
    return function(...args) {
        clearTimeout(t);
        t = setTimeout(() => fn.apply(this, args), delay);
    };
}

// Filter venuesData using search query and reset pagination
function filterVenues(query) {
    const q = (query || '').trim().toLowerCase();
    if (!q) {
        venuesFiltered = venuesData.slice();
    } else {
        venuesFiltered = venuesData.filter(v => {
            const name = (v.nama_venue || '').toString().toLowerCase();
            const lokasi = (v.lokasi || '').toString().toLowerCase();
            const sukan = (v.sukan_name || '').toString().toLowerCase();
            return name.includes(q) || lokasi.includes(q) || sukan.includes(q);
        });
    }
    venuesCurrentPage = 1;
    renderVenuesTablePage();
}

function showAddVenue(data = null) {
    const modalEl = document.getElementById('addVenueModal');
    if (modalEl.parentElement !== document.body) document.body.appendChild(modalEl);
    // Prepare modal dialog for centered display; JS will ensure it does not overlap header
    try {
        const dialog = modalEl.querySelector('.modal-dialog');
        const content = modalEl.querySelector('.modal-content');
        const headerEl = document.querySelector('.header, .header-sticky, .navbar');
        const headerHeight = headerEl ? headerEl.offsetHeight : 72;
        if (dialog) {
            dialog.style.position = 'fixed';
            dialog.style.left = '50%';
            // default center
            dialog.style.top = '50%';
            dialog.style.transform = 'translate(-50%, -50%)';
            dialog.style.margin = '0';
            // ensure modal content won't extend under header
            if (content) {
                content.style.maxHeight = (window.innerHeight - headerHeight - 48) + 'px';
                content.style.overflow = 'auto';
            }

            // If the calculated top would place modal under the header, nudge it down
            // Use requestAnimationFrame to ensure layout is ready
            requestAnimationFrame(() => {
                // Compute desired top so modal is vertically centered
                const dialogHeight = dialog.offsetHeight || (dialog.getBoundingClientRect().height || 0);
                let desiredTop = Math.round((window.innerHeight - dialogHeight) / 2);
                const minTop = headerHeight + 12; // keep modal below header
                const marginBottom = 12;
                // Ensure modal doesn't overlap header
                if (desiredTop < minTop) desiredTop = minTop;
                // Ensure modal bottom fits within viewport
                const maxTop = Math.max(minTop, window.innerHeight - dialogHeight - marginBottom);
                if (desiredTop > maxTop) desiredTop = maxTop;

                // Apply top and horizontal centering only (avoid vertical translate to prevent overlap)
                dialog.style.top = desiredTop + 'px';
                dialog.style.left = '50%';
                dialog.style.transform = 'translateX(-50%)';

                // If content still overflows, reduce its max-height
                const rect = dialog.getBoundingClientRect();
                if (content) {
                    const available = Math.max(100, window.innerHeight - headerHeight - 36);
                    content.style.maxHeight = available + 'px';
                    content.style.overflow = 'auto';
                }
            });
        }
    } catch (e) {
        // ignore layout calculation errors
    }
    // If no data provided, reset form for new entry. If data is provided (edit), populate after showing.
    const form = document.getElementById('venueForm');
    if (form && data === null) {
        form.reset();
        const idField = document.getElementById('venueId');
        if (idField) {
            idField.remove();
        }
        // default status to active for new entries
        const statusEl = document.getElementById('venueStatus');
        if (statusEl) statusEl.value = '1';
    }

    if (typeof coreui !== 'undefined' && coreui.Modal) {
        addVenueModalInstance = new coreui.Modal(modalEl, {backdrop:true,keyboard:true,focus:true});
        addVenueModalInstance.show();
    } else if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        addVenueModalInstance = new bootstrap.Modal(modalEl, {backdrop:true,keyboard:true,focus:true});
        addVenueModalInstance.show();
    } else {
        modalEl.classList.add('show');
        modalEl.style.display = 'block';
        document.body.classList.add('modal-open');
    }

    // If editing (data provided), populate fields AFTER modal is prepared
    if (data && typeof data === 'object') {
        // ensure hidden id exists
        if (!document.getElementById('venueId')) {
            const input = document.createElement('input'); input.type = 'hidden'; input.id = 'venueId'; input.name = 'id';
            document.getElementById('venueForm').appendChild(input);
        }
        document.getElementById('venueId').value = data.id || '';
        document.getElementById('venueName').value = data.nama_venue || '';
        document.getElementById('venueLocation').value = data.lokasi || '';
        document.getElementById('venueCapacity').value = data.kapasiti || '';
        // if API returns sukan_id, set that, otherwise try sukan
        const sportVal = (typeof data.sukan_id !== 'undefined' && data.sukan_id !== null) ? String(data.sukan_id) : (data.sukan || '');
        const sportEl = document.getElementById('venueSport');
        if (sportEl) sportEl.value = sportVal;
        // populate status if available
        const statusEl2 = document.getElementById('venueStatus');
        if (statusEl2) statusEl2.value = (typeof data.status !== 'undefined' ? String(data.status) : '1');
    }
}

// Load sport options for dropdown
function loadSportOptions(callback) {
    const sel = document.getElementById('venueSport');
    if (!sel) { if (callback) callback(); return; }
    const cur = sel.value;
    fetch('<?php echo url("ajax/sport_list.php"); ?>', { method: 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
    .then(res => res.json())
    .then(json => {
        if (json && json.success) {
            sel.innerHTML = '<option value="">-- Select a sport --</option>';
            (json.data || []).forEach(s => {
                const opt = document.createElement('option');
                opt.value = s.id;
                opt.textContent = englishSportLabel(s.nama_sukan) + (s.kod_sukan ? (' ('+s.kod_sukan+')') : '');
                sel.appendChild(opt);
            });
            if (cur) sel.value = cur;
        }
        if (callback) callback();
    })
    .catch(err => { if (callback) callback(); });
}

function closeAddVenueModal() {
    const modalEl = document.getElementById('addVenueModal');
    if (addVenueModalInstance && typeof addVenueModalInstance.hide === 'function') {
        addVenueModalInstance.hide();
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
    // clear form and id field after close
    const form = document.getElementById('venueForm');
    if (form) {
        form.reset();
        const idField = document.getElementById('venueId');
        if (idField) idField.remove();
    }
}

function submitVenue() {
    const form = document.getElementById('venueForm');
    if (!form.checkValidity()) { form.reportValidity(); return; }

    const id = document.getElementById('venueId') ? document.getElementById('venueId').value : '';
    const name = document.getElementById('venueName').value.trim();
    const lokasi = document.getElementById('venueLocation').value.trim();
    const kapasiti = document.getElementById('venueCapacity').value;
    const sukan_id = document.getElementById('venueSport') ? document.getElementById('venueSport').value : '';
    const status = document.getElementById('venueStatus') ? document.getElementById('venueStatus').value : '1';

    const btn = document.querySelector('#addVenueModal .btn-primary');
    const origText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    const payload = new URLSearchParams();
    if (id) payload.append('id', id);
    payload.append('nama_venue', name);
    payload.append('lokasi', lokasi);
    if (kapasiti !== '') payload.append('kapasiti', kapasiti);
    if (sukan_id !== '') payload.append('sukan_id', sukan_id);
    if (typeof status !== 'undefined' && status !== '') payload.append('status', status);

    fetch('<?php echo url("ajax/venue_save.php"); ?>', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        body: payload
    })
    .then(res => res.json())
    .then(json => {
        btn.disabled = false;
        btn.innerHTML = origText;
        if (json && json.success) {
            closeAddVenueModal();
            // reload list
            reloadVenuesTable();
        } else {
            alert((json && json.message) || 'Unable to save the venue');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = origText;
        alert('Connection error. Please try again.');
    });
}

// Load venues via AJAX and render table
function reloadVenuesTable(callback) {
    const tbody = document.getElementById('venuesTableBody');
    if (!tbody) { if (callback) callback(); return; }
    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-3"><span class="spinner-border spinner-border-sm me-2"></span>Loading data...</td></tr>';

    fetch('<?php echo url("ajax/venue_list.php"); ?>', { method: 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
    .then(res => res.json())
    .then(json => {
        if (json && json.success) {
            const data = json.data || [];
            // store fetched data and render paginated view
            venuesData = data;
            venuesFiltered = venuesData.slice();
            venuesCurrentPage = 1;
            renderVenuesTablePage();
            // update hero stats if provided
            if (json.stats) {
                const hero = document.querySelector('.venues-view .management-stats');
                if (hero) {
                    const statDivs = hero.querySelectorAll('.me-3 .h5.mb-0');
                    if (statDivs.length >= 3) {
                        statDivs[0].textContent = json.stats.total || 0;
                        statDivs[1].textContent = json.stats.active || 0;
                        statDivs[2].textContent = json.stats.inactive || 0;
                    }
                }
            }
        } else {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-3">Unable to load data.</td></tr>';
        }
        if (callback) callback();
    })
    .catch(err => {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-3">Connection error.</td></tr>';
        if (callback) callback();
    });
}

// Escape helper
function escapeHtml(text) {
    const d = document.createElement('div'); d.textContent = text; return d.innerHTML;
}

// Edit / Delete handlers
document.addEventListener('click', function(e) {
    const editBtn = e.target.closest && e.target.closest('.edit-venue');
    if (editBtn) {
        e.preventDefault();
        const id = editBtn.getAttribute('data-id');
        // fetch single venue via list and populate form
        fetch('<?php echo url("ajax/venue_list.php"); ?>', { method: 'GET', credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(res => res.json())
        .then(json => {
            if (json && json.success) {
                const item = (json.data || []).find(x => String(x.id) === String(id));
                if (item) {
                    // ensure sport options loaded then show modal with data
                    loadSportOptions(function(){ showAddVenue(item); });
                }
            }
        });
    }

    const delBtn = e.target.closest && e.target.closest('.delete-venue');
    if (delBtn) {
        e.preventDefault();
        const id = delBtn.getAttribute('data-id');
        if (!id) return;
        deleteVenue(id, function(success){ if (success) reloadVenuesTable(); });
    }
});

// Reusable delete function
function deleteVenue(id, callback) {
    const doDelete = function() {
        fetch('<?php echo url("ajax/venue_delete.php"); ?>', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'id=' + encodeURIComponent(id)
        })
        .then(res => res.json())
        .then(json => {
            if (json && json.success) {
                if (callback) callback(true);
            } else {
                alert((json && json.message) || 'Unable to delete.');
                if (callback) callback(false);
            }
        })
        .catch(err => { alert('Connection error.'); if (callback) callback(false); });
    };

    if (typeof Swal !== 'undefined') {
        Swal.fire({
            title: 'Delete this venue?',
            text: 'This action cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, padam',
            cancelButtonText: 'Cancel'
        }).then(function(result){
            if (result && result.isConfirmed) doDelete();
            else if (callback) callback(false);
        });
    } else {
        if (!confirm('Delete this venue?')) { if (callback) callback(false); return; }
        doDelete();
    }
}

// Initial load
document.addEventListener('DOMContentLoaded', function() {
    loadSportOptions(function(){ reloadVenuesTable(); });

    // Wire search input with debounce to filter results client-side
    const searchEl = document.getElementById('venuesSearch');
    if (searchEl) {
        const handler = debounce(function(e) {
            filterVenues(e.target.value || '');
        }, 250);
        searchEl.addEventListener('input', handler);
        // support Enter key immediate search
        searchEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                filterVenues(searchEl.value || '');
            }
        });
    }

    // Wire top page-size selector
    const topSizeSel = document.getElementById('venuesPageSizeTopSelect');
    if (topSizeSel) {
        topSizeSel.value = String(venuesPageSize);
        topSizeSel.addEventListener('change', function() { setVenuesPageSize(this.value); });
    }
});
</script>


