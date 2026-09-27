<?php
/**
 * Sports Management Page
 */
require_once __DIR__ . '/../config.php';

$page_title = 'Sports';

ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/contingent-management.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/contingent-management.css'); ?>">
<link rel="stylesheet" href="<?php echo asset('css/sports.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/sports.css'); ?>">
<div class="w-100 px-3 contingent-management sports-view">
    <!-- Hero -->
    <div class="row mb-4 management-heading">
        <div class="col-12">
            <div class="card bg-light border-0 shadow-sm overflow-hidden">
                <div class="card-body py-4 d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                    <div>
                        <div class="management-eyebrow">SAM 2026 &middot; SPORTS MANAGEMENT</div><h2 class="mb-1">Sport</h2>
                        <p class="text-muted mb-0">Manage sports, competition events and quick actions</p>
                    </div>

                    <div class="d-flex align-items-center gap-3">
                        <div class="management-stats">
                            <div class="me-3 text-center">
                                <div class="h5 mb-0">0</div>
                                <div class="small text-muted">Sport</div>
                            </div>
                            <div class="me-3 text-center">
                                <div class="h5 mb-0">0</div>
                                <div class="small text-muted">Event</div>
                            </div>
                            <div class="me-3 text-center">
                                <div class="h5 mb-0">0</div>
                                <div class="small text-muted">Category</div>
                            </div>
                        </div>

                        <div class="btn-group">
                            <button class="btn btn-outline-secondary">Reports</button>
                            <button class="btn btn-primary" onclick="showAddSport()">
                                <i class="cil cil-plus me-1"></i> Register New Sport
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sports List -->
    <div class="row">
        <div class="col-12">
            <div class="card mb-4 shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <strong>Sports List</strong>
                        <div class="small text-muted">Manage all sports and categories</div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="input-group input-group-sm" style="min-width:220px;">
                            <span class="input-group-text"><i class="cil cil-magnifying-glass"></i></span>
                            <input type="search" class="form-control" id="sportsSearch" aria-label="Search by sport name or category" placeholder="Search by name or category...">
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" style="width:70px;">#</th>
                                    <th scope="col">Sport Name</th>
                                    <th scope="col">Category</th>
                                    <th scope="col" style="width:120px;">Status</th>
                                    <th scope="col" style="width:160px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="sportsTableBody">
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-5">
                                        <i class="cil cil-gamepad" style="font-size: 2rem;"></i>
                                        <p class="mt-2">No sports registered. Click "Register New Sport" to get started.</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
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

<!-- Edit Sport Modal -->
<div class="modal fade" id="editSportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="cil cil-pencil me-2"></i>Update Sport
                </h5>
                <button type="button" class="btn-close" aria-label="Close" onclick="closeEditSportModal()"></button>
            </div>
            <div class="modal-body">
                <form id="editSportForm">
                    <input type="hidden" id="editSportId" name="sportId">
                    <!-- Section 1: Sport Details -->
                    <div class="mb-4">
                        <h6 class="mb-3 text-primary border-bottom pb-2">
                            <i class="cil cil-gamepad me-2"></i>Information Sport
                        </h6>
                        <div class="mb-3">
                            <label for="editSportName" class="form-label">Sport Name <span class="text-danger">*</span></label>
                            <input type="text" id="editSportName" name="editSportName" class="form-control" required placeholder="e.g. Bola Sepak, Badminton, Renang">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="editSportCode" class="form-label">Sport Code</label>
                                <input type="text" id="editSportCode" name="editSportCode" class="form-control" placeholder="e.g. FUT, BOL, BAD">
                                <small class="text-muted">Short sport code (optional)</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="editSportStatus" class="form-label">Status</label>
                                <select id="editSportStatus" name="editSportStatus" class="form-select">
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="editSportDescription" class="form-label">Description</label>
                            <textarea id="editSportDescription" name="editSportDescription" class="form-control" rows="2" placeholder="Sport description (optional)"></textarea>
                        </div>
                    </div>

                    <!-- Section 2: Categories -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0 text-primary border-bottom pb-2 flex-grow-1">
                                <i class="cil cil-list me-2"></i>Category
                            </h6>
                            <button type="button" class="btn btn-sm btn-outline-primary ms-3" onclick="addEditCategoryField()">
                                <i class="cil cil-plus me-1"></i>Add Category
                            </button>
                        </div>
                        
                        <div id="editCategoriesContainer">
                            <!-- Dynamic category fields will be added here -->
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeEditSportModal()">
                    <i class="cil cil-x me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="updateSport()">
                    <i class="cil cil-save me-1"></i>Update Sport
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Add Sport Modal -->
<div class="modal fade" id="addSportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="cil cil-gamepad me-2"></i>Register New Sport
                </h5>
                <button type="button" class="btn-close" aria-label="Close" onclick="closeAddSportModal()"></button>
            </div>
            <div class="modal-body">
                <form id="sportForm">
                    <!-- Section 1: Sport Details -->
                    <div class="mb-4">
                        <h6 class="mb-3 text-primary border-bottom pb-2">
                            <i class="cil cil-gamepad me-2"></i>Information Sport
                        </h6>
                        <div class="mb-3">
                            <label for="sportName" class="form-label">Sport Name <span class="text-danger">*</span></label>
                            <input type="text" id="sportName" name="sportName" class="form-control" required placeholder="e.g. Bola Sepak, Badminton, Renang">
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="sportCode" class="form-label">Sport Code</label>
                                <input type="text" id="sportCode" name="sportCode" class="form-control" placeholder="e.g. FUT, BOL, BAD">
                                <small class="text-muted">Short sport code (optional)</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="sportStatus" class="form-label">Status</label>
                                <select id="sportStatus" name="sportStatus" class="form-select">
                                    <option value="1" selected>Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="sportDescription" class="form-label">Description</label>
                            <textarea id="sportDescription" name="sportDescription" class="form-control" rows="2" placeholder="Sport description (optional)"></textarea>
                        </div>
                    </div>

                    <!-- Section 2: Categories -->
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="mb-0 text-primary border-bottom pb-2 flex-grow-1">
                                <i class="cil cil-list me-2"></i>Category
                            </h6>
                            <button type="button" class="btn btn-sm btn-outline-primary ms-3" onclick="addCategoryField()">
                                <i class="cil cil-plus me-1"></i>Add Category
                            </button>
                        </div>
                        
                        <div id="categoriesContainer">
                            <!-- Dynamic category fields will be added here -->
                            <div class="text-muted small text-center py-3 border rounded bg-light">
                                <i class="cil cil-info me-1"></i>
                                Click "Add Category" to add a category to this sport
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAddSportModal()">
                    <i class="cil cil-x me-1"></i>Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="submitSport()">
                    <i class="cil cil-save me-1"></i>Save Sport
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Modal instances
let addSportModalInstance = null;
let editSportModalInstance = null;
let categoryCounter = 0;

// Helper function to escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Load sports list from API
function loadSports(callback) {
    const tbody = document.getElementById('sportsTableBody');
    if (!tbody) {
        if (callback) callback();
        return;
    }
    
    // Show loading state
    tbody.innerHTML = '<tr><td colspan="5" class="text-center py-3"><span class="spinner-border spinner-border-sm me-2"></span>Loading data...</td></tr>';
    
    // Get search term
    const searchTerm = document.getElementById('sportsSearch')?.value || '';
    
    // Build API URL
    let apiUrl = '<?php echo url("api/sports.php?action=list"); ?>';
    if (searchTerm) {
        apiUrl += '&search=' + encodeURIComponent(searchTerm);
    }
    
    fetch(apiUrl, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (data && data.success) {
            const sports = data.data || [];
            
            // Update statistics in hero section
            updateStatistics(data);
            
            // Update table
            if (sports.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-5"><i class="cil cil-gamepad" style="font-size: 2rem;"></i><p class="mt-2">No sports registered. Click "Register New Sport" to get started.</p></td></tr>';
            } else {
                let html = '';
                sports.forEach((sport, index) => {
                    const status = sport.status !== undefined ? parseInt(sport.status) : 0;
                    const badgeClass = status == 1 ? 'bg-success' : 'bg-secondary';
                    const statusText = status == 1 ? 'Active' : 'Inactive';
                    
                    html += '<tr>';
                    html += '<td>' + (index + 1) + '</td>';
                    html += '<td>';
                    html += '<div class="fw-semibold">' + escapeHtml(englishSportLabel(sport.nama_sukan || '-')) + '</div>';
                    if (sport.kod_sukan) {
                        html += '<div class="small text-muted"><code>' + escapeHtml(sport.kod_sukan) + '</code></div>';
                    }
                    if (sport.keterangan) {
                        html += '<div class="small text-muted mt-1">' + escapeHtml(sport.keterangan) + '</div>';
                    }
                    html += '</td>';
                    html += '<td>';
                    html += '<div class="d-flex flex-wrap gap-1">';
                    if (sport.categories_list && sport.categories_list.trim() !== '') {
                        // Split categories and display as badges
                        const categories = sport.categories_list.split(', ');
                        categories.forEach(function(cat) {
                            html += '<span class="badge bg-info">' + escapeHtml(englishSportLabel(cat)) + '</span>';
                        });
                    } else {
                        html += '<span class="text-muted small">No categories</span>';
                    }
                    html += '</div>';
                    html += '</td>';
                    html += '<td><span class="badge ' + badgeClass + '">' + statusText + '</span></td>';
                    html += '<td>';
                    html += '<a class="btn btn-sm btn-outline-info view-sport" title="Show" href="#" data-id="' + (sport.id || 0) + '">';
                    html += '<i class="fa fa-eye"></i></a> ';
                    html += '<a class="btn btn-sm btn-outline-primary edit-sport" title="Update" href="#" data-id="' + (sport.id || 0) + '">';
                    html += '<i class="fa fa-edit"></i></a> ';
                    html += '<a class="btn btn-sm btn-outline-danger delete-sport" title="Delete" href="#" data-id="' + (sport.id || 0) + '" data-name="' + escapeHtml(englishSportLabel(sport.nama_sukan || '')) + '">';
                    html += '<i class="fa fa-trash"></i></a>';
                    html += '</td>';
                    html += '</tr>';
                });
                tbody.innerHTML = html;
                
                // Attach event listeners
                attachEventListeners();
            }
        } else {
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-3">Unable to load data. Please reload the page.</td></tr>';
        }
        
        if (callback) callback();
    })
    .catch(error => {
        console.error('Error loading sports:', error);
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-3">Connection error. Please reload the page.</td></tr>';
        if (callback) callback();
    });
}

// Update statistics in hero section
function updateStatistics(data) {
    const heroSection = document.querySelector('.sports-view .management-stats');
    if (heroSection) {
        const statDivs = heroSection.querySelectorAll('.me-3 .h5.mb-0');
        if (statDivs.length >= 3) {
            // Get statistics from API
            fetch('<?php echo url("api/sports.php?action=statistics"); ?>', {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
            .then(response => response.json())
            .then(statsData => {
                if (statsData && statsData.success) {
                    const stats = statsData.data || {};
                    statDivs[0].textContent = stats.total_sports || 0;
                    statDivs[1].textContent = '0'; // Events - to be implemented later
                    statDivs[2].textContent = stats.total_categories || 0;
                }
            })
            .catch(error => {
                console.error('Error loading statistics:', error);
            });
        }
    }
}

// Attach event listeners for table actions (using event delegation like contingent page)
// Note: Event delegation is set up in DOMContentLoaded, this function is kept for compatibility
function attachEventListeners() {
    // Event listeners are handled via event delegation in DOMContentLoaded
    // This function is kept for backward compatibility but doesn't need to do anything
    // since we use event delegation at document level like contingent page
}

// View sport function
function viewSport(sportId) {
    if (window.Swal) {
        Swal.showLoading();
    }
    
    fetch('<?php echo url("api/sports.php?action=get&id="); ?>' + sportId, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (window.Swal) Swal.close();
        
        if (data && data.success && data.data) {
            const sport = data.data;
            const categories = sport.categories || [];
            
            let detailsHtml = '<div class="row mb-3">';
            detailsHtml += '<div class="col-md-6"><strong>Sport Name:</strong></div>';
            detailsHtml += '<div class="col-md-6">' + escapeHtml(englishSportLabel(sport.nama_sukan || '-')) + '</div>';
            detailsHtml += '</div>';
            
            if (sport.kod_sukan) {
                detailsHtml += '<div class="row mb-3">';
                detailsHtml += '<div class="col-md-6"><strong>Sport Code:</strong></div>';
                detailsHtml += '<div class="col-md-6"><code>' + escapeHtml(sport.kod_sukan) + '</code></div>';
                detailsHtml += '</div>';
            }
            
            if (sport.keterangan) {
                detailsHtml += '<div class="row mb-3">';
                detailsHtml += '<div class="col-md-6"><strong>Description:</strong></div>';
                detailsHtml += '<div class="col-md-6">' + escapeHtml(sport.keterangan) + '</div>';
                detailsHtml += '</div>';
            }
            
            detailsHtml += '<div class="row mb-3">';
            detailsHtml += '<div class="col-md-6"><strong>Status:</strong></div>';
            const statusBadge = sport.status == 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>';
            detailsHtml += '<div class="col-md-6">' + statusBadge + '</div>';
            detailsHtml += '</div>';
            
            let categoriesHtml = '';
            if (categories.length === 0) {
                categoriesHtml = '<p class="text-muted">No categories registered for this sport.</p>';
            } else {
                categoriesHtml = '<ul class="list-group list-group-flush">';
                categories.forEach(cat => {
                    categoriesHtml += '<li class="list-group-item d-flex justify-content-between align-items-start">';
                    categoriesHtml += '<div class="ms-2 me-auto">';
                    categoriesHtml += '<div class="fw-bold">' + escapeHtml(englishSportLabel(cat.nama_kategori || '-')) + '</div>';
                    if (cat.kod_kategori) {
                        categoriesHtml += '<small class="text-muted"><code>' + escapeHtml(cat.kod_kategori) + '</code></small>';
                    }
                    if (cat.keterangan) {
                        categoriesHtml += '<div class="small text-muted mt-1">' + escapeHtml(cat.keterangan) + '</div>';
                    }
                    if (cat.penilaian) {
                        const penilaianText = cat.penilaian === 'berkumpulan' ? 'Group' : 'Individual';
                        const penilaianBadge = cat.penilaian === 'berkumpulan' ? 'bg-primary' : 'bg-info';
                        categoriesHtml += '<div class="mt-1"><span class="badge ' + penilaianBadge + '">' + escapeHtml(penilaianText) + '</span></div>';
                    }
                    categoriesHtml += '</div>';
                    const catStatus = cat.status == 1 ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-secondary">Inactive</span>';
                    categoriesHtml += '<div>' + catStatus + '</div>';
                    categoriesHtml += '</li>';
                });
                categoriesHtml += '</ul>';
            }
            
            if (window.Swal) {
                Swal.fire({
                    title: 'Show Sport: ' + escapeHtml(englishSportLabel(sport.nama_sukan || 'Sport')),
                    html: '<div class="text-start">' + detailsHtml + '<hr><h6 class="mb-2">Category (' + categories.length + ')</h6>' + categoriesHtml + '</div>',
                    width: '700px',
                    confirmButtonText: 'Close',
                    icon: 'info'
                });
            } else {
                alert('Sport: ' + sport.nama_sukan);
            }
        } else {
            if (window.Swal) {
                Swal.fire({
                    text: data.message || 'Unable to load sport details',
                    icon: 'error'
                });
            } else {
                alert(data.message || 'Unable to load sport details');
            }
        }
    })
    .catch(error => {
        if (window.Swal) Swal.close();
        console.error('Error loading sport:', error);
        if (window.Swal) {
            Swal.fire({
                text: 'Unable to load sport details',
                icon: 'error'
            });
        } else {
            alert('Unable to load sport details');
        }
    });
}

// Edit sport function - Load data into edit modal
function editSport(sportId) {
    if (window.Swal) {
        Swal.showLoading();
    }
    
    fetch('<?php echo url("api/sports.php?action=get&id="); ?>' + sportId, {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (window.Swal) Swal.close();
        
        if (data && data.success && data.data) {
            const sport = data.data;
            
            // Populate form fields
            document.getElementById('editSportId').value = sport.id;
            document.getElementById('editSportName').value = sport.nama_sukan || '';
            document.getElementById('editSportCode').value = sport.kod_sukan || '';
            document.getElementById('editSportDescription').value = sport.keterangan || '';
            document.getElementById('editSportStatus').value = sport.status || 1;
            
            // Load categories
            loadCategoriesForEdit(sport.categories || []);
            
            // Show modal
            showEditSportModal();
        } else {
            if (window.Swal) {
                Swal.fire({
                    text: data.message || 'Unable to load sport details',
                    icon: 'error'
                });
            } else {
                alert(data.message || 'Unable to load sport details');
            }
        }
    })
    .catch(error => {
        if (window.Swal) Swal.close();
        console.error('Error loading sport:', error);
        if (window.Swal) {
            Swal.fire({
                text: 'Unable to load sport details',
                icon: 'error'
            });
        } else {
            alert('Unable to load sport details');
        }
    });
}

// Load categories into edit form
function loadCategoriesForEdit(categories) {
    const container = document.getElementById('editCategoriesContainer');
    container.innerHTML = '';
    categoryCounter = 0;
    
    if (categories.length === 0) {
        container.innerHTML = `
            <div class="text-muted small text-center py-3 border rounded bg-light">
                <i class="cil cil-info me-1"></i>
                Click "Add Category" to add a category to this sport
            </div>
        `;
    } else {
        categories.forEach(cat => {
            addEditCategoryField(cat);
        });
    }
}

// Add category field for edit modal
function addEditCategoryField(categoryData = null) {
    categoryCounter++;
    const container = document.getElementById('editCategoriesContainer');
    
    // Remove placeholder message if exists
    const placeholder = container.querySelector('.text-muted');
    if (placeholder) placeholder.remove();
    
    const categoryId = categoryData ? categoryData.id : '';
    const categoryName = categoryData ? categoryData.nama_kategori : '';
    const categoryCode = categoryData ? categoryData.kod_kategori : '';
    const categoryDesc = categoryData ? categoryData.keterangan : '';
    const categoryPenilaian = categoryData ? categoryData.penilaian : '';
    
    const categoryHtml = `
        <div class="card mb-3 category-edit-item border" data-category-id="${categoryCounter}" data-db-id="${categoryId}">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <h6 class="mb-0 text-muted small">
                        <i class="cil cil-tag me-1"></i>Category #${categoryCounter}
                    </h6>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeEditCategoryField(${categoryCounter})">
                        <i class="cil cil-trash me-1"></i>Remove
                    </button>
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label small">Category Name <span class="text-danger">*</span></label>
                        <input type="text" 
                               class="form-control form-control-sm category-edit-name" 
                               name="editCategories[${categoryCounter}][nama_kategori]"
                               value="${escapeHtml(categoryName)}"
                               required
                               placeholder="e.g. Male, Female, Terbuka">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Category Code</label>
                        <input type="text" 
                               class="form-control form-control-sm category-edit-code" 
                               name="editCategories[${categoryCounter}][kod_kategori]"
                               value="${escapeHtml(categoryCode)}"
                               placeholder="e.g. L, W, T">
                    </div>
                </div>
                <div class="row g-2 mt-2">
                    <div class="col-md-6">
                        <label class="form-label small">Description</label>
                        <input type="text" 
                               class="form-control form-control-sm category-edit-description" 
                               name="editCategories[${categoryCounter}][keterangan]"
                               value="${escapeHtml(categoryDesc)}"
                               placeholder="Category description (optional)">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Assessment</label>
                        <select class="form-select form-select-sm category-edit-penilaian" 
                                name="editCategories[${categoryCounter}][penilaian]">
                            <option value="">Select an assessment method</option>
                            <option value="berkumpulan" ${categoryPenilaian === 'berkumpulan' ? 'selected' : ''}>Group</option>
                            <option value="individu" ${categoryPenilaian === 'individu' ? 'selected' : ''}>Individual</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', categoryHtml);
}

// Remove category field from edit modal
function removeEditCategoryField(categoryId) {
    const categoryItem = document.querySelector(`.category-edit-item[data-category-id="${categoryId}"]`);
    if (categoryItem) {
        categoryItem.remove();
        
        // Show placeholder if no categories left
        const container = document.getElementById('editCategoriesContainer');
        if (container.children.length === 0) {
            container.innerHTML = `
                <div class="text-muted small text-center py-3 border rounded bg-light">
                    <i class="cil cil-info me-1"></i>
                    Click "Add Category" to add a category to this sport
                </div>
            `;
        }
    }
}

// Show edit modal
function showEditSportModal() {
    const modalEl = document.getElementById('editSportModal');
    
    if (modalEl.parentElement !== document.body) document.body.appendChild(modalEl);
    
    if (typeof coreui !== 'undefined' && coreui.Modal) {
        editSportModalInstance = new coreui.Modal(modalEl, {backdrop:true,keyboard:true,focus:true});
        editSportModalInstance.show();
    } else if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        editSportModalInstance = new bootstrap.Modal(modalEl, {backdrop:true,keyboard:true,focus:true});
        editSportModalInstance.show();
    } else {
        modalEl.classList.add('show');
        modalEl.style.display = 'block';
        document.body.classList.add('modal-open');
    }
}

// Close edit modal
function closeEditSportModal() {
    // Reset form
    document.getElementById('editSportForm').reset();
    document.getElementById('editCategoriesContainer').innerHTML = `
        <div class="text-muted small text-center py-3 border rounded bg-light">
            <i class="cil cil-info me-1"></i>
            Click "Add Category" to add a category to this sport
        </div>
    `;
    categoryCounter = 0;
    
    const modalEl = document.getElementById('editSportModal');
    if (editSportModalInstance && typeof editSportModalInstance.hide === 'function') {
        editSportModalInstance.hide();
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

// Update sport function
function updateSport() {
    const form = document.getElementById('editSportForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    const sportId = document.getElementById('editSportId').value;
    
    // Collect sport data
    const sportData = {
        nama_sukan: document.getElementById('editSportName').value.trim(),
        kod_sukan: document.getElementById('editSportCode').value.trim() || null,
        keterangan: document.getElementById('editSportDescription').value.trim() || null,
        status: parseInt(document.getElementById('editSportStatus').value)
    };
    
    // Collect categories data
    const categories = [];
    const categoryItems = document.querySelectorAll('.category-edit-item');
    
    categoryItems.forEach((item) => {
        const namaKategori = item.querySelector('.category-edit-name').value.trim();
        if (namaKategori) {
            const categoryData = {
                id: item.getAttribute('data-db-id') || null, // Existing category ID
                nama_kategori: namaKategori,
                kod_kategori: item.querySelector('.category-edit-code').value.trim() || null,
                keterangan: item.querySelector('.category-edit-description').value.trim() || null,
                penilaian: item.querySelector('.category-edit-penilaian').value.trim() || null,
                status: 1
            };
            categories.push(categoryData);
        }
    });
    
    sportData.categories = categories;
    
    // Show loading state
    const submitBtn = event.target;
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Mengemaskini...';
    
    // Show loading with SweetAlert
    if (window.Swal) {
        Swal.showLoading();
    }
    
    // API call to update sport
    fetch('<?php echo url("api/sports.php?action=update&id="); ?>' + sportId, {
        method: 'PUT',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify(sportData)
    })
    .then(response => response.json())
    .then(data => {
        if (window.Swal) Swal.close();
        
        // Reset button state
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
        
        if (data.success) {
            // Close modal first
            closeEditSportModal();
            
            // Show success message
            if (window.Swal) {
                Swal.fire({
                    text: data.message || 'Sport updated successfully!',
                    icon: 'success'
                }).then(() => {
                    // Refresh the sports table after user closes the alert
                    loadSports();
                });
            } else {
                alert(data.message || 'Sport updated successfully!');
                loadSports();
            }
        } else {
            if (window.Swal) {
                Swal.fire({
                    text: data.message || 'Unable to update the sport',
                    icon: 'error'
                });
            } else {
                alert('Error: ' + (data.message || 'Unable to update the sport'));
            }
        }
    })
    .catch(error => {
        if (window.Swal) Swal.close();
        
        console.error('Error:', error);
        
        // Reset button state
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
        
        if (window.Swal) {
            Swal.fire({
                text: 'System error: ' + error.message,
                icon: 'error'
            });
        } else {
            alert('System error: ' + error.message);
        }
    });
}


// Delete sport function
function deleteSport(sportId, sportName) {
    if (window.Swal) {
        Swal.fire({
            title: 'Delete this sport?',
            text: 'Sport "' + escapeHtml(sportName) + '" and all its categories will be deleted. This action cannot be undone',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, Delete',
            cancelButtonText: 'Cancel',
            confirmButtonColor: '#d33'
        }).then((result) => {
            if (result.isConfirmed) {
                performDelete(sportId);
            }
        });
    } else {
        if (confirm('Delete sport "' + sportName + '" and all its categories?')) {
            performDelete(sportId);
        }
    }
}

// Perform delete operation
function performDelete(sportId) {
    if (window.Swal) {
        Swal.showLoading();
    }
    
    fetch('<?php echo url("api/sports.php?action=delete&id="); ?>' + sportId, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
    })
    .then(response => response.json())
    .then(data => {
        if (window.Swal) Swal.close();
        
        if (data && data.success) {
            if (window.Swal) {
                Swal.fire({
                    text: data.message || 'Sport deleted successfully',
                    icon: 'success'
                }).then(() => {
                    loadSports();
                });
            } else {
                alert(data.message || 'Sport deleted successfully');
                loadSports();
            }
        } else {
            if (window.Swal) {
                Swal.fire({
                    text: data.message || 'Unable to delete the sport',
                    icon: 'error'
                });
            } else {
                alert(data.message || 'Unable to delete the sport');
            }
        }
    })
    .catch(error => {
        if (window.Swal) Swal.close();
        console.error('Error deleting sport:', error);
        if (window.Swal) {
            Swal.fire({
                text: 'Unable to delete the sport',
                icon: 'error'
            });
        } else {
            alert('Unable to delete the sport');
        }
    });
}

function showAddSport() {
    const modalEl = document.getElementById('addSportModal');

    // Move to body to avoid stacking context
    if (modalEl.parentElement !== document.body) document.body.appendChild(modalEl);

    if (typeof coreui !== 'undefined' && coreui.Modal) {
        addSportModalInstance = new coreui.Modal(modalEl, {backdrop:true,keyboard:true,focus:true});
        addSportModalInstance.show();
    } else if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        addSportModalInstance = new bootstrap.Modal(modalEl, {backdrop:true,keyboard:true,focus:true});
        addSportModalInstance.show();
    } else {
        modalEl.classList.add('show');
        modalEl.style.display = 'block';
        document.body.classList.add('modal-open');
    }
}

function closeAddSportModal() {
    // Reset form
    document.getElementById('sportForm').reset();
    document.getElementById('categoriesContainer').innerHTML = `
        <div class="text-muted small text-center py-3 border rounded bg-light">
            <i class="cil cil-info me-1"></i>
            Click "Add Category" to add a category to this sport
        </div>
    `;
    categoryCounter = 0;
    
    const modalEl = document.getElementById('addSportModal');
    if (addSportModalInstance && typeof addSportModalInstance.hide === 'function') {
        addSportModalInstance.hide();
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

function addCategoryField() {
    categoryCounter++;
    const container = document.getElementById('categoriesContainer');
    
    // Remove placeholder message if exists
    const placeholder = container.querySelector('.text-muted');
    if (placeholder) placeholder.remove();
    
    const categoryHtml = `
        <div class="card mb-3 category-item border" data-category-id="${categoryCounter}">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <h6 class="mb-0 text-muted small">
                        <i class="cil cil-tag me-1"></i>Category #${categoryCounter}
                    </h6>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeCategoryField(${categoryCounter})">
                        <i class="cil cil-trash me-1"></i>Remove
                    </button>
                </div>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label small">Category Name <span class="text-danger">*</span></label>
                        <input type="text" 
                               class="form-control form-control-sm category-name" 
                               name="categories[${categoryCounter}][nama_kategori]"
                               required
                               placeholder="e.g. Male, Female, Terbuka">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Category Code</label>
                        <input type="text" 
                               class="form-control form-control-sm category-code" 
                               name="categories[${categoryCounter}][kod_kategori]"
                               placeholder="e.g. L, W, T">
                    </div>
                </div>
                <div class="row g-2 mt-2">
                    <div class="col-md-6">
                        <label class="form-label small">Description</label>
                        <input type="text" 
                               class="form-control form-control-sm category-description" 
                               name="categories[${categoryCounter}][keterangan]"
                               placeholder="Category description (optional)">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small">Assessment</label>
                        <select class="form-select form-select-sm category-penilaian" 
                                name="categories[${categoryCounter}][penilaian]">
                            <option value="">Select an assessment method</option>
                            <option value="berkumpulan">Group</option>
                            <option value="individu">Individual</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', categoryHtml);
}

function removeCategoryField(categoryId) {
    const categoryItem = document.querySelector(`.category-item[data-category-id="${categoryId}"]`);
    if (categoryItem) {
        categoryItem.remove();
        
        // Show placeholder if no categories left
        const container = document.getElementById('categoriesContainer');
        if (container.children.length === 0) {
            container.innerHTML = `
                <div class="text-muted small text-center py-3 border rounded bg-light">
                    <i class="cil cil-info me-1"></i>
                    Click "Add Category" to add a category to this sport
                </div>
            `;
        }
    }
}

function submitSport() {
    const form = document.getElementById('sportForm');
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }
    
    // Collect sport data
    const sportData = {
        nama_sukan: document.getElementById('sportName').value.trim(),
        kod_sukan: document.getElementById('sportCode').value.trim() || null,
        keterangan: document.getElementById('sportDescription').value.trim() || null,
        status: parseInt(document.getElementById('sportStatus').value)
    };
    
    // Collect categories data
    const categories = [];
    const categoryItems = document.querySelectorAll('.category-item');
    
    categoryItems.forEach((item) => {
        const namaKategori = item.querySelector('.category-name').value.trim();
        if (namaKategori) {  // Only add if name is provided
            categories.push({
                nama_kategori: namaKategori,
                kod_kategori: item.querySelector('.category-code').value.trim() || null,
                keterangan: item.querySelector('.category-description').value.trim() || null,
                penilaian: item.querySelector('.category-penilaian').value.trim() || null,
                status: 1  // Default active
            });
        }
    });
    
    sportData.categories = categories;
    
    // Show loading state
    const submitBtn = event.target;
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving...';
    
    // Show loading with SweetAlert
    if (window.Swal) {
        Swal.showLoading();
    }
    
    // API call
    fetch('<?php echo url("api/sports.php?action=create"); ?>', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        credentials: 'same-origin',
        body: JSON.stringify(sportData)
    })
    .then(response => {
        // Check if response is actually JSON
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            // If not JSON, get text to see the error
            return response.text().then(text => {
                console.error('Non-JSON response:', text);
                throw new Error('Server returned non-JSON response. Check if database tables exist.');
            });
        }
        return response.json();
    })
    .then(data => {
        if (window.Swal) Swal.close();
        
        // Reset button state
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
        
        if (data.success) {
            // Close modal first
            closeAddSportModal();
            
            // Show success message
            if (window.Swal) {
                Swal.fire({
                    text: data.message || 'Sport and categories registered successfully!',
                    icon: 'success'
                }).then(() => {
                    // Refresh the sports table after user closes the alert
                    loadSports();
                });
            } else {
                alert(data.message || 'Sport and categories registered successfully!');
                // Refresh the sports table
                loadSports();
            }
        } else {
            if (window.Swal) {
                Swal.fire({
                    text: data.message || 'Unable to save the sport',
                    icon: 'error'
                });
            } else {
                alert('Error: ' + (data.message || 'Unable to save the sport'));
            }
        }
    })
    .catch(error => {
        if (window.Swal) Swal.close();
        
        console.error('Error:', error);
        
        // Reset button state
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
        
        if (window.Swal) {
            Swal.fire({
                text: 'System error: ' + error.message + '\n\nPlease ensure the database tables have been created.',
                icon: 'error'
            });
        } else {
            alert('System error: ' + error.message + '\n\nPlease ensure the database tables have been created.');
        }
    });
}

// Search functionality
document.addEventListener('DOMContentLoaded', function() {
    // Load sports on page load
    loadSports();
    
    // Search input event listener
    const searchInput = document.getElementById('sportsSearch');
    if (searchInput) {
        let searchTimeout;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                loadSports();
            }, 500); // Debounce search by 500ms
        });
        
        // Also trigger on Enter key
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(searchTimeout);
                loadSports();
            }
        });
    }
    
    // Handle action buttons using event delegation (like contingent page)
    document.addEventListener('click', function(e) {
        // View sport button
        var viewBtn = e.target.closest && e.target.closest('.view-sport');
        if (viewBtn) {
            e.preventDefault();
            var sportId = viewBtn.getAttribute('data-id');
            if (sportId) viewSport(sportId);
            return;
        }
        
        // Edit sport button
        var editBtn = e.target.closest && e.target.closest('.edit-sport');
        if (editBtn) {
            e.preventDefault();
            var sportId = editBtn.getAttribute('data-id');
            if (sportId) editSport(sportId);
            return;
        }
        
        // Delete sport button
        var delBtn = e.target.closest && e.target.closest('.delete-sport');
        if (delBtn) {
            e.preventDefault();
            var sportId = delBtn.getAttribute('data-id');
            var sportName = delBtn.getAttribute('data-name');
            if (sportId) deleteSport(sportId, sportName);
            return;
        }
    });
});
</script>

<style>
/* Ensure modals are scrollable when content exceeds screen height */
.modal-dialog-scrollable {
    max-height: calc(100vh - 2rem);
    margin: 11rem auto;
    display: flex;
    flex-direction: column;
}

.modal-dialog-scrollable .modal-content {
    max-height: 100%;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.modal-dialog-scrollable .modal-header {
    flex-shrink: 0;
}

.modal-dialog-scrollable .modal-body {
    overflow-y: auto;
    overflow-x: hidden;
    scroll-behavior: smooth;
    flex: 1 1 auto;
    max-height: calc(100vh - 200px); /* Accounts for header + footer + margins */
    padding: 1rem;
}

.modal-dialog-scrollable .modal-footer {
    flex-shrink: 0;
}

/* For smaller screens */
@media (max-height: 600px) {
    .modal-dialog-scrollable .modal-body {
        max-height: calc(100vh - 150px);
    }
}

/* Ensure modal doesn't overflow on mobile */
@media (max-width: 768px) {
    .modal-dialog-scrollable {
        max-height: calc(100vh - 1rem);
        margin: 0.5rem;
    }
    
    .modal-dialog-scrollable .modal-body {
        max-height: calc(100vh - 180px);
    }
}
</style>

