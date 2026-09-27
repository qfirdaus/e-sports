<?php
/**
 * User Management (admin-only)
 * Copied from venues.php initially
 */
require_once __DIR__ . '/../config.php';

$page_title = 'User Management';

ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/contingent-management.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/contingent-management.css'); ?>">
<link rel="stylesheet" href="<?php echo asset('css/users.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/users.css'); ?>">
<div class="w-100 px-3 contingent-management users-view">
    <!-- Hero -->
    <div class="row mb-4 management-heading">
        <div class="col-12">
            <div class="card bg-light border-0 shadow-sm overflow-hidden">
                <div class="card-body py-4 d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                    <div>
                        <div class="management-eyebrow">SAM 2026 &middot; ACCOUNT MANAGEMENT</div><h2 class="mb-1">User Management</h2>
                        <p class="text-muted mb-0">Manage system user accounts</p>
                    </div>

                    <div class="d-flex align-items-center gap-3">
                        <div class="management-stats">
                            <div class="me-3 text-center">
                                <div class="h5 mb-0">0</div>
                                <div class="small text-muted">User</div>
                            </div>
                        </div>

                        <div class="btn-group">
                            <button class="btn btn-outline-secondary">Reports</button>
                            <button class="btn btn-primary" onclick="showAddUser()">
                                <i class="cil cil-plus me-1"></i> Add New User
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Search and List -->
    <div class="row">
        <div class="col-12">
            <div class="card mb-4 shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <strong>User List</strong>
                        <div class="small text-muted">Manage all user accounts</div>
                    </div>
                    <div class="d-flex align-items-center">
                        <div class="input-group input-group-sm" style="min-width:260px;">
                            <span class="input-group-text"><i class="cil cil-magnifying-glass"></i></span>
                            <input type="search" class="form-control" id="usersSearch" aria-label="Search by user name or email" placeholder="Search by name or email...">
                        </div>
                        <select id="usersPageSizeTopSelect" aria-label="Users per page" class="form-select form-select-sm ms-2" style="width: auto;">
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
                                    <th scope="col">Name</th>
                                    <th scope="col">Email</th>
                                    <th scope="col">Role</th>
                                    <th scope="col">Contingent</th>
                                    <th scope="col" style="width:120px;">Status</th>
                                    <th scope="col" style="width:160px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="usersTableBody">
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-5">
                                        <i class="cil cil-user" style="font-size: 2rem;"></i>
                                        <p class="mt-2">No users registered.</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex align-items-center justify-content-between mt-2">
                        <div id="usersPagingInfo" class="small text-muted"></div>
                        <div id="usersPagination" class="ms-auto"></div>
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

<!-- Add User Modal (placeholder) -->
<style>
/* Scoped styles similar to venues modal */
#addUserModal { z-index: 1060 !important; }
.modal-backdrop { z-index: 1050 !important; }
#addUserModal .modal-dialog { max-width: 900px; }
</style>

<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalTitle">Add New User</h5>
                <button type="button" class="btn-close" aria-label="Close" onclick="closeAddUserModal()"></button>
            </div>
            <div class="modal-body">
                <form id="userForm">
                    <input type="hidden" id="userId" name="id" value="">
                    <!-- username removed; login uses email now -->
                    <div class="mb-3">
                        <label for="userName" class="form-label">Full Name</label>
                        <input type="text" id="userName" name="full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label for="userEmail" class="form-label">Email</label>
                        <input type="email" id="userEmail" name="email" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label for="userPassword" class="form-label">Password (leave blank to keep the current password)</label>
                        <input type="password" id="userPassword" name="password" class="form-control">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="userRole" class="form-label">Role</label>
                            <select id="userRole" name="role" class="form-select">
                                <option value="ADMIN">ADMIN</option>
                                <option value="ORGANIZER">ORGANIZER</option>
                                <option value="CONTINGENT">CONTINGENT</option>
                                <option value="JUDGE">JUDGE</option>
                                <option value="VIEWER">VIEWER</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="userStatus" class="form-label">Status</label>
                            <select id="userStatus" name="status" class="form-select">
                                <option value="active">active</option>
                                <option value="inactive">inactive</option>
                                <option value="suspended">suspended</option>
                                <option value="pending">pending</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="userPhone" class="form-label">Phone</label>
                        <input type="text" id="userPhone" name="phone" class="form-control">
                    </div>
                    <div class="mb-3" id="kontinjenFieldWrapper">
                        <label for="userKontinjen" class="form-label">Contingent</label>
                        <select id="userKontinjen" name="kontinjen_id" class="form-select">
                            <option value="">-- None --</option>
                        </select>
                        <div class="form-text">If the selected role is <strong>CONTINGENT</strong>, this selection is required.</div>
                    </div>
                    <div class="mb-3" id="judgeCategoryWrapper" style="display: none;">
                        <label class="form-label">Allowed Categories</label>
                        <div class="form-text mb-2">Select the categories in which this judge can record results</div>
                        <div id="judgeCategoryList" style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 0.375rem; padding: 1rem;">
                            <div class="text-muted text-center py-3">Loading categories...</div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddUserModal()">Cancel</button>
                <button type="button" class="btn btn-primary" id="saveUserBtn">Save</button>
            </div>
        </div>
    </div>
</div>

<script>
// Users list JS: fetch from server and render with search/pagination
let usersData = [];
let usersFiltered = [];
let usersCurrentPage = 1;
let usersPageSize = 10;

function escapeHtml(text) { const d = document.createElement('div'); d.textContent = text; return d.innerHTML; }

function setUsersPageSize(s){ usersPageSize = parseInt(s)||10; usersCurrentPage=1; renderUsersPage(); }

function goToUsersPage(page){ const totalPages = Math.max(1, Math.ceil(usersFiltered.length / usersPageSize)); usersCurrentPage = Math.min(Math.max(1, parseInt(page)||1), totalPages); renderUsersPage(); }

function applyUsersFilter(q){ const ql = (q||'').toString().trim().toLowerCase(); if(!ql) usersFiltered = usersData.slice(); else usersFiltered = usersData.filter(u => { const name = (u.full_name||'').toString().toLowerCase(); const email = (u.email||'').toString().toLowerCase(); const role = (u.role||'').toString().toLowerCase(); return name.includes(ql) || email.includes(ql) || role.includes(ql); }); usersCurrentPage=1; }

function renderUsersPage(){
    const tbody = document.getElementById('usersTableBody'); if(!tbody) return;
    const total = usersFiltered.length;
    const totalPages = Math.max(1, Math.ceil(total / usersPageSize));
    if(usersCurrentPage>totalPages) usersCurrentPage = totalPages;
    const start = (usersCurrentPage - 1) * usersPageSize;
    const end = Math.min(total, start + usersPageSize);
    if(total === 0){
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-5"><i class="cil cil-user" style="font-size: 2rem;"></i><p class="mt-2">No users found.</p></td></tr>';
    } else {
        let html='';
        const pageItems = usersFiltered.slice(start,end);
        pageItems.forEach((u,i)=>{
            const idx = start + i + 1;
            const statusBadge = (u.status==='active')? '<span class="badge bg-success">Active</span>': '<span class="badge bg-secondary">'+escapeHtml(u.status||'')+'</span>';
            const kontShort = (u.kontinjen_short && u.kontinjen_short.trim()!=='') ? u.kontinjen_short : (u.kontinjen_id ? ('#'+u.kontinjen_id) : '-');
            html += '<tr>';
            html += '<td>'+idx+'</td>';
            html += '<td>'+escapeHtml(u.full_name||u.username||'-')+'</td>';
            html += '<td>'+escapeHtml(u.email||'-')+'</td>';
            html += '<td>'+escapeHtml(u.role||'-')+'</td>';
            html += '<td>'+escapeHtml(kontShort)+'</td>';
            html += '<td>'+statusBadge+'</td>';
            html += '<td>';
            html += '<a href="#" class="btn btn-sm btn-outline-primary edit-user me-1" data-id="'+(u.id||0)+'"> <i class="fa fa-edit"></i></a>';
            html += '<a href="#" class="btn btn-sm btn-outline-danger delete-user" data-id="'+(u.id||0)+'"> <i class="fa fa-trash"></i></a>';
            html += '</td>';
            html += '</tr>';
        });
        tbody.innerHTML = html;
    }
    // Update paging info and controls
    const infoEl = document.getElementById('usersPagingInfo'); if(infoEl){ if(total===0) infoEl.textContent = ''; else infoEl.textContent = 'Showing ' + (start+1) + '–' + end + ' of ' + total; }
    renderUsersPaginationControls(total, usersCurrentPage, usersPageSize);
}

function renderUsersPaginationControls(totalItems, currentPage, pageSize){ const container = document.getElementById('usersPagination'); if(!container) return; const totalPages = Math.max(1, Math.ceil(totalItems / pageSize)); let html = '<nav aria-label="users-pagination"><ul class="pagination pagination-sm mb-0">'; const prevDisabled = (currentPage<=1)?' disabled':''; html += '<li class="page-item'+prevDisabled+'"><a class="page-link" href="#" data-page="'+(currentPage-1)+'">‹</a></li>'; const maxButtons = 5; let startPage = Math.max(1, currentPage - Math.floor(maxButtons/2)); let endPage = Math.min(totalPages, startPage + maxButtons -1); if(endPage - startPage < maxButtons -1) startPage = Math.max(1, endPage - maxButtons +1); for(let p=startPage;p<=endPage;p++){ const active = (p===currentPage)?' active':''; html += '<li class="page-item'+active+'"><a class="page-link" href="#" data-page="'+p+'">'+p+'</a></li>'; } const nextDisabled = (currentPage>=totalPages)?' disabled':''; html += '<li class="page-item'+nextDisabled+'"><a class="page-link" href="#" data-page="'+(currentPage+1)+'">›</a></li>'; html += '</ul></nav>'; container.innerHTML = html; // wire links
    container.querySelectorAll('.page-link').forEach(a=>{ a.addEventListener('click', function(e){ e.preventDefault(); const p = parseInt(this.getAttribute('data-page'))||1; goToUsersPage(p); }); });
}

function loadUsers(){ const tbody = document.getElementById('usersTableBody'); if(tbody) tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">Loading...</td></tr>'; fetch('<?php echo url('ajax/users_list.php'); ?>', { credentials: 'same-origin', headers: { 'Accept':'application/json'} }).then(r=>r.json()).then(json=>{ if(json && json.success){ usersData = json.data || []; usersFiltered = usersData.slice(); usersCurrentPage = 1; renderUsersPage(); } else { if(tbody) tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-3">Unable to load data.</td></tr>'; } }).catch(err=>{ if(tbody) tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-3">Connection error.</td></tr>'; }); }

document.addEventListener('DOMContentLoaded', function(){ // wire search & page size
    const search = document.getElementById('usersSearch'); if(search){ search.addEventListener('input', function(){ applyUsersFilter(this.value); renderUsersPage(); }); }
    const ps = document.getElementById('usersPageSizeTopSelect'); if(ps){ ps.addEventListener('change', function(){ setUsersPageSize(this.value); }); }

    // delegate edit/delete
    document.addEventListener('click', function(e){
        const editBtn = e.target.closest && e.target.closest('.edit-user');
        if(editBtn){
            e.preventDefault();
            const id = editBtn.getAttribute('data-id');
            if(!id) return;
            // fetch user and populate form with robust error handling
            fetch('<?php echo url('ajax/users_get.php'); ?>?id='+encodeURIComponent(id), { credentials: 'same-origin', headers: { 'Accept':'application/json' } })
                .then(async (r) => {
                    if (!r.ok) {
                        const txt = await r.text().catch(()=>'(no body)');
                        console.error('users_get error', r.status, txt);
                        alert('Unable to load users: ' + r.status);
                        return null;
                    }
                    try {
                        return await r.json();
                    } catch (ex) {
                        const txt = await r.text().catch(()=>'(no body)');
                        console.error('users_get invalid json', ex, txt);
                        alert('Unable to process the server response');
                        return null;
                    }
                })
                .then(j => {
                    if (!j) return;
                    if (j && j.success && j.data) {
                        const u = j.data;
                        document.getElementById('userId').value = u.id || '';
                        document.getElementById('userName').value = u.full_name || '';
                        document.getElementById('userEmail').value = u.email || '';
                        document.getElementById('userPassword').value = '';
                        document.getElementById('userRole').value = u.role || 'VIEWER';
                        document.getElementById('userStatus').value = u.status || 'pending';
                        document.getElementById('userPhone').value = u.phone || '';
                        // ensure kontinjen options loaded then set, then show modal for edit (don't reset form)
                        loadKontinjenOptions().then(()=>{
                            const sel = document.getElementById('userKontinjen'); if(sel){ sel.value = u.kontinjen_id || ''; }
                            applyRoleRequirement();
                            // Load judge category assignments if user is a judge
                            if (u.role === 'JUDGE') {
                                // Load assignments first, then categories will render with them
                                loadJudgeCategoryAssignments(u.id);
                            }
                            // set modal title for edit then open modal for editing without resetting
                            const titleEl = document.getElementById('userModalTitle'); if(titleEl) titleEl.textContent = 'Update User';
                            showAddUser(false);
                        });
                    } else {
                        alert((j && j.message) || 'Unable to load users');
                    }
                })
                .catch(err => { console.error('users_get fetch failed', err); alert('Connection error.'); });
        }

        const delBtn = e.target.closest && e.target.closest('.delete-user');
        if(delBtn){
            e.preventDefault();
            const id = delBtn.getAttribute('data-id'); if(!id) return; if(!confirm('Delete this user?')) return;
            fetch('<?php echo url('ajax/users_delete.php'); ?>', { method:'POST', credentials:'same-origin', headers:{ 'Accept':'application/json','Content-Type':'application/x-www-form-urlencoded' }, body: 'id='+encodeURIComponent(id) }).then(r=>r.json()).then(j=>{ if(j && j.success){ loadUsers(); } else { alert((j && j.message) || 'Unable to delete.'); } }).catch(()=>{ alert('Connection error.'); });
        }
    });

    loadUsers();

    // Save handler with client-side required enforcement for CONTINGENT
    const saveBtn = document.getElementById('saveUserBtn');
    if (saveBtn) {
        saveBtn.addEventListener('click', function(e){
            e.preventDefault();
            const form = document.getElementById('userForm');
            if (!form) return;
            // client-side enforcement: if role == CONTINGENT, kontinjen must be set
            const roleVal = document.getElementById('userRole').value;
            const kontinjenSel = document.getElementById('userKontinjen');
            if(roleVal === 'CONTINGENT'){
                if(!kontinjenSel || !kontinjenSel.value){ alert('Please select a contingent for the CONTINGENT role'); return; }
            }
            const fd = new FormData(form);
            // POST as urlencoded
            const body = new URLSearchParams();
            for (const pair of fd.entries()) { body.append(pair[0], pair[1]); }
            fetch('<?php echo url('ajax/users_save.php'); ?>', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(r=>r.json()).then(j=>{
                if (j && j.success) {
                    const userId = document.getElementById('userId').value;
                    const isEdit = !!userId;
                    const successMessage = isEdit ? 'User updated successfully' : 'User added successfully';
                    
                    // Save judge category assignments if user is a judge
                    if (roleVal === 'JUDGE' && userId) {
                        return saveJudgeCategoryAssignments(userId).then(() => {
                            closeAddUserModal();
                            loadUsers();
                            // Show success message
                            if (window.Swal) {
                                Swal.fire({
                                    text: successMessage,
                                    icon: 'success',
                                    timer: 2000,
                                    showConfirmButton: false
                                });
                            } else {
                                alert(successMessage);
                            }
                        }).catch(() => {
                            // Even if assignment save fails, user is saved, so continue
                            closeAddUserModal();
                            loadUsers();
                            // Show success message
                            if (window.Swal) {
                                Swal.fire({
                                    text: successMessage,
                                    icon: 'success',
                                    timer: 2000,
                                    showConfirmButton: false
                                });
                            } else {
                                alert(successMessage);
                            }
                        });
                    } else {
                        closeAddUserModal();
                        loadUsers();
                        // Show success message
                        if (window.Swal) {
                            Swal.fire({
                                text: successMessage,
                                icon: 'success',
                                timer: 2000,
                                showConfirmButton: false
                            });
                        } else {
                            alert(successMessage);
                        }
                    }
                } else {
                    const errorMessage = (j && j.message) || 'Unable to save the user';
                    if (window.Swal) {
                        Swal.fire({
                            text: errorMessage,
                            icon: 'error'
                        });
                    } else {
                        alert(errorMessage);
                    }
                }
            }).catch(()=>{ 
                if (window.Swal) {
                    Swal.fire({
                        text: 'Connection error. Please try again.',
                        icon: 'error'
                    });
                } else {
                    alert('Connection error.');
                }
            });
        });
    }
    

    // role change: toggle kontinuen requirement (applies to global function defined below)
    const roleSel = document.getElementById('userRole');
    if(roleSel){ roleSel.addEventListener('change', applyRoleRequirement); }

    // ensure options loaded on page load and hide wrapper by default (will call global loader)
    if (typeof loadKontinjenOptions === 'function') {
        loadKontinjenOptions().then(()=>{ if (typeof applyRoleRequirement === 'function') applyRoleRequirement(); });
    }
});

// stub functions for modal
function showAddUser(reset){
    // reset form for new user only when reset is true or undefined
    const shouldReset = (typeof reset === 'undefined') ? true : !!reset;
    const form = document.getElementById('userForm');
    if(shouldReset && form){ form.reset(); document.getElementById('userId').value = ''; const titleEl = document.getElementById('userModalTitle'); if(titleEl) titleEl.textContent = 'Add New User'; }
    if (typeof loadKontinjenOptions === 'function') loadKontinjenOptions().then(()=>{ if (typeof applyRoleRequirement === 'function') applyRoleRequirement(); });
    const modal=document.getElementById('addUserModal');
    if(modal){
        // Prefer Bootstrap Modal API if present
        try{
            if (window.bootstrap && bootstrap.Modal){
                let inst = bootstrap.Modal.getInstance(modal) || new bootstrap.Modal(modal);
                inst.show();
                return;
            }
        }catch(e){ console.warn('bootstrap modal show failed', e); }
        // fallback: add classes and display
        modal.classList.add('show');
        modal.style.display = 'block';
        // add backdrop if not present
        if (!document.querySelector('.modal-backdrop')){
            const bd = document.createElement('div'); bd.className = 'modal-backdrop fade show'; document.body.appendChild(bd);
        }
    }
}
// alias for backward compatibility with older callers
function showAddUserModal(){ showAddUser(); }
function closeAddUserModal(){ const modal=document.getElementById('addUserModal'); if(!modal) return; try{ if(window.bootstrap && bootstrap.Modal){ let inst = bootstrap.Modal.getInstance(modal); if(inst){ inst.hide(); return; } } }catch(e){ console.warn('bootstrap modal hide failed', e); }
    modal.classList.remove('show'); modal.style.display='none'; const bd = document.querySelector('.modal-backdrop'); if(bd) bd.parentNode.removeChild(bd);
}
function submitUser(){ alert('Save user - not yet implemented'); }
</script>

<script>
// Global kontinjen helpers (must be global so modal/open flows can call them)
let kontinjenLoaded = false;
function applyRoleRequirement(){ 
    const rEl = document.getElementById('userRole'); 
    const r = rEl ? rEl.value : null; 
    const wrapper = document.getElementById('kontinjenFieldWrapper'); 
    const sel = document.getElementById('userKontinjen'); 
    if(r === 'CONTINGENT'){ 
        if(wrapper) wrapper.style.display='block'; 
        if(sel) sel.setAttribute('required','required'); 
    } else { 
        if(sel) sel.removeAttribute('required'); 
        if(wrapper) wrapper.style.display='none'; 
    }
    
    // Show/hide judge category assignment section
    const judgeWrapper = document.getElementById('judgeCategoryWrapper');
    const currentUserRole = '<?php echo Session::get("user_role"); ?>';
    const canAssignCategories = (currentUserRole === 'ADMIN' || currentUserRole === 'ORGANIZER');
    
    if (r === 'JUDGE' && canAssignCategories && judgeWrapper) {
        judgeWrapper.style.display = 'block';
        loadJudgeCategories();
    } else if (judgeWrapper) {
        judgeWrapper.style.display = 'none';
    }
}

function loadKontinjenOptions(){
    return new Promise((resolve, reject)=>{
        if(kontinjenLoaded){ resolve(); return; }
        fetch('<?php echo url('ajax/kontinjen_list.php'); ?>', { credentials:'same-origin', headers:{ 'Accept':'application/json' } })
            .then(r=>r.json()).then(j=>{
                const sel = document.getElementById('userKontinjen'); if(!sel){ kontinjenLoaded = true; resolve(); return; }
                sel.innerHTML = '<option value="">-- None --</option>';
                if(j && j.success && Array.isArray(j.data)){
                    j.data.forEach(c=>{ const opt = document.createElement('option'); opt.value = c.id; opt.textContent = (c.nama_universiti || c.kod_universiti || c.name || ('Contingent '+c.id)); sel.appendChild(opt); });
                }
                kontinjenLoaded = true; resolve();
            }).catch(err=>{ kontinjenLoaded = true; resolve(); });
    });
}

// Judge category assignment functions
let judgeCategoriesLoaded = false;
let selectedJudgeCategories = [];

function loadJudgeCategories() {
    const container = document.getElementById('judgeCategoryList');
    if (!container) return;
    
    container.innerHTML = '<div class="text-muted text-center py-3">Loading categories...</div>';
    
    // Load available categories
    fetch('<?php echo url('ajax/judge_category_assignments.php'); ?>?action=available', {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(j => {
        if (!j || !j.success || !Array.isArray(j.data)) {
            container.innerHTML = '<div class="text-danger text-center py-3">Unable to load categories</div>';
            return;
        }
        
        const categories = j.data;
        if (categories.length === 0) {
            container.innerHTML = '<div class="text-muted text-center py-3">No categories available</div>';
            return;
        }
        
        // Load current assignments if editing a judge
        const userId = document.getElementById('userId').value;
        if (userId) {
            loadJudgeCategoryAssignments(userId).then(() => {
                renderJudgeCategories(categories);
            });
        } else {
            selectedJudgeCategories = [];
            renderJudgeCategories(categories);
        }
    })
    .catch(err => {
        console.error('Failed to load judge categories', err);
        container.innerHTML = '<div class="text-danger text-center py-3">Unable to load categories</div>';
    });
}

function loadJudgeCategoryAssignments(userId) {
    return fetch('<?php echo url('ajax/judge_category_assignments.php'); ?>?action=list&user_id=' + encodeURIComponent(userId), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
    })
    .then(r => r.json())
    .then(j => {
        if (j && j.success && Array.isArray(j.data)) {
            selectedJudgeCategories = j.data.map(a => parseInt(a.kategori_id));
        } else {
            selectedJudgeCategories = [];
        }
    })
    .catch(err => {
        console.error('Failed to load judge assignments', err);
        selectedJudgeCategories = [];
    });
}

function renderJudgeCategories(categories) {
    const container = document.getElementById('judgeCategoryList');
    if (!container) return;
    
    let html = '';
    
    categories.forEach(sport => {
        html += `<div class="mb-3 pb-3 border-bottom">`;
        html += `<strong>${escapeHtml(sport.nama_sukan || 'Sport ' + sport.sukan_id)}</strong>`;
        html += `<div class="mt-2 ms-3">`;
        
        if (sport.categories && sport.categories.length > 0) {
            sport.categories.forEach(cat => {
                const isChecked = selectedJudgeCategories.includes(parseInt(cat.id));
                html += `<div class="form-check">`;
                html += `<input class="form-check-input" type="checkbox" value="${cat.id}" id="judge_cat_${cat.id}" ${isChecked ? 'checked' : ''} onchange="updateJudgeCategorySelection(${cat.id}, this.checked)">`;
                html += `<label class="form-check-label" for="judge_cat_${cat.id}">${escapeHtml(cat.nama_kategori || 'Category ' + cat.id)}</label>`;
                html += `</div>`;
            });
        } else {
            html += `<div class="text-muted small">No categories</div>`;
        }
        
        html += `</div></div>`;
    });
    
    container.innerHTML = html || '<div class="text-muted text-center py-3">No categories available</div>';
    judgeCategoriesLoaded = true;
}

function updateJudgeCategorySelection(kategoriId, isSelected) {
    const id = parseInt(kategoriId);
    if (isSelected) {
        if (!selectedJudgeCategories.includes(id)) {
            selectedJudgeCategories.push(id);
        }
    } else {
        selectedJudgeCategories = selectedJudgeCategories.filter(cid => cid !== id);
    }
}

function escapeHtml(text) {
    const d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
}

// Function to save judge category assignments
function saveJudgeCategoryAssignments(userId) {
    return fetch('<?php echo url('ajax/judge_category_assignments.php'); ?>?action=assign', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 
            'Accept': 'application/json',
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            user_id: parseInt(userId),
            kategori_ids: selectedJudgeCategories
        })
    })
    .then(r => r.json())
    .then(j => {
        if (!j || !j.success) {
            console.warn('Failed to save judge category assignments:', j && j.message);
        }
    });
}
</script>
