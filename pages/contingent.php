<?php
/**
 * Contingent Management Page
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../api/models/ContingentModel.php';

$page_title = 'Contingents';

// Get current user role for status control
Session::start();
$auth = getAuth();
$auth->requireAuth();
$currentUserRole = Session::get('user_role') ?? '';
$canChangeStatus = in_array($currentUserRole, ['ADMIN', 'ORGANIZER']);
// Enforce contingent-only view for CONTINGENT users
$restrictToOwnContingent = ($currentUserRole === 'CONTINGENT');
$currentKontinjenId = Session::get('kontinjen_id') ?? null;
if ($restrictToOwnContingent && empty($currentKontinjenId)) {
    header('Location: ' . url('pages/access-denied.php'));
    exit;
}

// Fetch universities from database
$universities = [];
try {
    $pdo = getDB();
    $stmt = $pdo->query("SELECT kod_universiti, nama_universiti FROM table_ref_universiti WHERE status = 1 AND deleted_at IS NULL ORDER BY nama_universiti ASC");
    $universities = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('[contingent.php] DB error fetching universities: ' . $e->getMessage());
    // Continue with empty array if database query fails
}

// Fetch contingents from database using aggregated query (include jumlah_atlet)
$contingents = [];
$contingentStats = ['total' => 0, 'active' => 0, 'inactive' => 0];
try {
        $sql = "SELECT
    k.id,
    u.nama_universiti,
    k.kod_universiti,
    k.nama_pegawai_untuk_dihubungi,
    k.alamat,
        k.emel,
        k.no_telefon,
        COALESCE(SUM(a.cnt),0) AS jumlah_atlet,

        CASE 
            WHEN u.status = 1 THEN 'Active'
            ELSE 'Inactive'
        END AS status_universiti,

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
    u.status,
    k.created_at

ORDER BY k.created_at DESC
LIMIT 1000;";

        if ($restrictToOwnContingent && $currentKontinjenId) {
            // Inject kontingen filter into WHERE clause safely
            $filtered = preg_replace('/WHERE\s+k\.deleted_at\s+IS\s+NULL\s+AND\s+k\.status\s*=\s*1/i', "WHERE k.deleted_at IS NULL AND k.status = 1 AND k.id = :kontinjen_id", $sql);
            $stmt = $pdo->prepare($filtered);
            $stmt->execute([':kontinjen_id' => (int)$currentKontinjenId]);
        } else {
            $stmt = $pdo->query($sql);
        }
        $contingents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Update simple stats based on results
        $contingentStats['total'] = count($contingents);
        $contingentStats['active'] = count($contingents); // query returns active only
        $contingentStats['inactive'] = 0;

} catch (Exception $e) {
        error_log('[contingent.php] DB error fetching contingents (custom SQL): ' . $e->getMessage());
}

// Participant counts per kontinjen (athletes + managers + coaches)
$participantCounts = [];
try {
    // Use aggregated subqueries per team to avoid row multiplication
    $sql = "SELECT k.id AS kontinjen_id,
        COALESCE(SUM(a.cnt),0) AS total_atlet,
        COALESCE(SUM(m.cnt),0) AS total_pengurus,
        COALESCE(SUM(co.cnt),0) AS total_jurulatih,
        (COALESCE(SUM(a.cnt),0) + COALESCE(SUM(m.cnt),0) + COALESCE(SUM(co.cnt),0)) AS jumlah_keseluruhan
    FROM table_kontinjen k
    LEFT JOIN table_pasukan p ON p.kontinjen_id = k.id AND p.deleted_at IS NULL AND p.status = 1
    LEFT JOIN (SELECT pasukan_id, COUNT(*) AS cnt FROM table_pasukan_atlet WHERE deleted_at IS NULL GROUP BY pasukan_id) a ON a.pasukan_id = p.id
    LEFT JOIN (SELECT pasukan_id, COUNT(*) AS cnt FROM table_pasukan_pengurus WHERE deleted_at IS NULL GROUP BY pasukan_id) m ON m.pasukan_id = p.id
    LEFT JOIN (SELECT pasukan_id, COUNT(*) AS cnt FROM table_pasukan_jurulatih WHERE deleted_at IS NULL GROUP BY pasukan_id) co ON co.pasukan_id = p.id
    WHERE k.deleted_at IS NULL AND k.status = 1
    GROUP BY k.id";

    if ($restrictToOwnContingent && $currentKontinjenId) {
        $filtered = preg_replace('/WHERE\s+k\.deleted_at\s+IS\s+NULL\s+AND\s+k\.status\s*=\s*1/i', "WHERE k.deleted_at IS NULL AND k.status = 1 AND k.id = :kontinjen_id", $sql);
        $stmt = $pdo->prepare($filtered);
        $stmt->execute([':kontinjen_id' => (int)$currentKontinjenId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($rows as $r) {
        $participantCounts[(int)$r['kontinjen_id']] = $r;
    }
} catch (Exception $e) {
    error_log('[contingent.php] participant counts error: ' . $e->getMessage());
}

// Participant counts aggregated by kod_universiti (used to highlight dropdown options)
$uniParticipantCounts = [];
try {
    $sqlUni = "SELECT k.kod_universiti AS kod_universiti, COALESCE(SUM(a.cnt),0) + COALESCE(SUM(m.cnt),0) + COALESCE(SUM(co.cnt),0) AS jumlah_participants
        FROM table_kontinjen k
        LEFT JOIN table_pasukan p ON p.kontinjen_id = k.id AND p.deleted_at IS NULL AND p.status = 1
        LEFT JOIN (SELECT pasukan_id, COUNT(*) AS cnt FROM table_pasukan_atlet WHERE deleted_at IS NULL GROUP BY pasukan_id) a ON a.pasukan_id = p.id
        LEFT JOIN (SELECT pasukan_id, COUNT(*) AS cnt FROM table_pasukan_pengurus WHERE deleted_at IS NULL GROUP BY pasukan_id) m ON m.pasukan_id = p.id
        LEFT JOIN (SELECT pasukan_id, COUNT(*) AS cnt FROM table_pasukan_jurulatih WHERE deleted_at IS NULL GROUP BY pasukan_id) co ON co.pasukan_id = p.id
        WHERE k.deleted_at IS NULL AND k.status = 1
        GROUP BY k.kod_universiti";

    if ($restrictToOwnContingent && $currentKontinjenId) {
        $filteredUni = preg_replace('/WHERE\s+k\.deleted_at\s+IS\s+NULL\s+AND\s+k\.status\s*=\s*1/i', "WHERE k.deleted_at IS NULL AND k.status = 1 AND k.id = :kontinjen_id", $sqlUni);
        $stmtUni = $pdo->prepare($filteredUni);
        $stmtUni->execute([':kontinjen_id' => (int)$currentKontinjenId]);
        $uniRows = $stmtUni->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmtUni = $pdo->query($sqlUni);
        $uniRows = $stmtUni->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($uniRows as $ur) {
        $uniParticipantCounts[$ur['kod_universiti']] = (int)$ur['jumlah_participants'];
    }
} catch (Exception $e) {
    error_log('[contingent.php] uni participant counts error: ' . $e->getMessage());
}

ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/contingent-management.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/contingent-management.css'); ?>">
<div class="w-100 px-3 contingent-management">
    <!-- Hero -->
    <div class="row mb-4 management-heading">
        <div class="col-12">
            <div class="card bg-light border-0 shadow-sm overflow-hidden">
                <div class="card-body py-4 d-flex flex-column flex-md-row justify-content-between align-items-start gap-3">
                    <div>
                        <div class="management-eyebrow">SAM 2026 &middot; CONTINGENT REGISTRATION</div><h2 class="mb-1">Contingent</h2>
                        <p class="text-muted mb-0">Manage contingent registrations, summaries and quick actions</p>
                    </div>

                    <div class="d-flex align-items-center gap-3">
                        <div class="management-stats">
                            <div class="me-3 text-center">
                                <div class="h5 mb-0"><?php echo (int)$contingentStats['total']; ?></div>
                                <div class="small text-muted">Contingent</div>
                            </div>
                            <div class="me-3 text-center">
                                <div class="h5 mb-0"><?php echo (int)$contingentStats['active']; ?></div>
                                <div class="small text-muted">Active</div>
                            </div>
                            <div class="me-3 text-center">
                                <div class="h5 mb-0"><?php echo (int)$contingentStats['inactive']; ?></div>
                                <div class="small text-muted">Inactive</div>
                            </div>
                        </div>

                        <div class="btn-group">
                            <button class="btn btn-outline-secondary">Reports</button>
                            <button class="btn btn-primary" onclick="showRegistrationForm()">
                                <i class="cil cil-plus me-1"></i> Register New Contingent
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Registration Form Modal/Wizard -->
    <div class="modal fade" id="registrationModal" tabindex="-1" aria-labelledby="registrationModalLabel" aria-hidden="true" data-coreui-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="registrationModalLabel">New Contingent Registration</h5>
                    <button type="button" class="btn-close" data-coreui-dismiss="modal" aria-label="Close" onclick="confirmCancel()"></button>
                </div>
                <div class="modal-body">
                    <!-- Registration Form -->
                    <form id="contingentForm">
                        <input type="hidden" id="contingentId" name="id" value="">
                        <div class="registration-step" id="step1" data-step="1">
                            <div class="mb-3">
                                <label for="institution" class="form-label">INSTITUTION <span class="text-danger">*</span></label>
                                <select class="form-select" id="institution" name="institution" required>
                                    <option value="" disabled selected>Please select an institution...</option>
                                <?php foreach ($universities as $university): 
                                    $kod = $university['kod_universiti'];
                                    $uCount = isset($uniParticipantCounts[$kod]) ? (int)$uniParticipantCounts[$kod] : 0;
                                    $optLabel = htmlspecialchars($university['nama_universiti'], ENT_QUOTES, 'UTF-8');
                                    if ($uCount === 0) {
                                        $optLabel = $optLabel . ' (No participants)';
                                        $dataEmpty = ' data-empty="1"';
                                    } else {
                                        $optLabel = $optLabel . ' (' . $uCount . ' peserta)';
                                        $dataEmpty = '';
                                    }
                                ?>
                                    <option value="<?php echo htmlspecialchars($kod, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $dataEmpty; ?>><?php echo $optLabel; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php
                                // Visible fallback: list universities that currently have zero participants
                                $zeroUnis = [];
                                foreach ($universities as $u) {
                                    $k = $u['kod_universiti'];
                                    if (isset($uniParticipantCounts[$k]) && (int)$uniParticipantCounts[$k] === 0) {
                                        $zeroUnis[] = htmlspecialchars($u['nama_universiti'], ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . ')';
                                    }
                                }
                                if (!empty($zeroUnis)) {
                                    echo '<div class="form-text text-danger small mt-1">⚠️ Institusi tanpa peserta: ' . implode(', ', $zeroUnis) . '</div>';
                                }
                            ?>
                            <div class="invalid-feedback">Please select an institution</div>
                        </div>

                        <div class="mb-3">
                            <label for="contactOfficerName" class="form-label">CONTACT PERSON NAME <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="contactOfficerName" name="contactOfficerName" 
                                   placeholder="Enter the contact person name" maxlength="100" autocomplete="name" required>
                            <div class="invalid-feedback">Official name is required (at least 3 characters)</div>
                        </div>

                        <div class="mb-3">
                            <label for="address" class="form-label">ADDRESS <span class="text-danger">*</span></label>
                            <textarea class="form-control" id="address" name="address" rows="4" 
                                      placeholder="Enter the full address" maxlength="500" autocomplete="street-address" required></textarea>
                            <div class="invalid-feedback">Address is required (at least 10 characters)</div>
                            <div class="form-text">
                                <span id="addressCharCount">0</span> / 500 characters
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">EMAIL <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" id="email" name="email" 
                                   placeholder="e.g. example@email.com" autocomplete="email" required>
                            <div class="invalid-feedback">Invalid email format</div>
                        </div>

                        <div class="mb-3">
                            <label for="phone" class="form-label">PHONE NUMBER</label>
                            <input type="tel" class="form-control" id="phone" name="phone" 
                                   placeholder="e.g. 012-3456789 or 03-12345678" autocomplete="tel">
                            <div class="invalid-feedback">Invalid phone number</div>
                        </div>

                        <?php if ($canChangeStatus): ?>
                        <div class="mb-3">
                            <label for="status" class="form-label">STATUS <span class="text-danger">*</span></label>
                            <select class="form-select" id="status" name="status" required>
                                <option value="1">Active</option>
                                <option value="0" selected>Inactive</option>
                            </select>
                            <div class="invalid-feedback">Please select a status</div>
                        </div>
                        <?php endif; ?>
                        </div>
                    </form>

                    <!-- Error Summary -->
                    <div class="alert alert-danger d-none" id="errorSummary">
                        <strong><i class="cil cil-warning me-1"></i> Please correct the following errors:</strong>
                        <ul class="mb-0 mt-2" id="errorList"></ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="confirmCancel()">
                        Cancel
                    </button>
                    <button type="button" class="btn btn-success" id="submitButton" onclick="submitRegistration()">
                        <i class="cil cil-check me-1"></i> Send Registration
                    </button>
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
                        <strong>Contingent List</strong>
                        <div class="small text-muted">Manage all registered contingents</div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="input-group input-group-sm me-2" style="min-width:220px;">
                            <span class="input-group-text"><i class="cil cil-magnifying-glass"></i></span>
                            <input type="search" class="form-control" id="contingentSearch" aria-label="Search by contingent name or code" placeholder="Search by name or code...">
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover table-striped align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col" style="width:70px;">#</th>
                                    <th scope="col">University Name</th>
                                    <th scope="col">Code</th>
                                    <th scope="col">Contact Person</th>
                                    <th scope="col" style="width:140px;">Total Athletes</th>
                                    <th scope="col" style="width:120px;">Status</th>
                                    <th scope="col" style="width:160px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="contingentTableBody">
                                <?php if (empty($contingents)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-5">
                                            <i class="cil cil-info" style="font-size: 2rem;"></i>
                                            <p class="mt-2">No contingents registered. Click "Register New Contingent" to get started.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($contingents as $i => $c): ?>
                                        <tr>
                                            <td><?php echo $i + 1; ?></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($c['nama_universiti'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                                            </td>
                                            <td><?php echo htmlspecialchars($c['kod_universiti'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td>
                                                <div class="small">
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($c['nama_pegawai_untuk_dihubungi'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></div>
                                                    <?php if (!empty($c['emel'])): ?>
                                                    <div class="text-muted small">
                                                        <a href="mailto:<?php echo htmlspecialchars($c['emel'], ENT_QUOTES, 'UTF-8'); ?>">
                                                            <?php echo htmlspecialchars($c['emel'], ENT_QUOTES, 'UTF-8'); ?>
                                                        </a>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <?php
                                                // Use jumlah_atlet returned by custom SQL
                                                $countVal = (int)($c['jumlah_atlet'] ?? 0);
                                                $tooltip = 'Total Athletes: ' . $countVal;
                                                $badgeClass = ($countVal === 0) ? 'bg-danger' : 'bg-primary';
                                            ?>
                                            <td class="text-center"><span class="badge <?php echo $badgeClass; ?>" title="<?php echo htmlspecialchars($tooltip, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $countVal; ?></span></td>
                                            <td>
                                                <?php
                                                // Prefer university status returned by query
                                                $statusText = isset($c['status_universiti']) ? $c['status_universiti'] : ((isset($c['status']) && (int)$c['status'] === 1) ? 'Active' : 'Inactive');
                                                $badgeClass = ($statusText === 'Active') ? 'bg-success' : 'bg-secondary';
                                                $statusValue = ($statusText === 'Active') ? 1 : 0;
                                                ?>
                                                <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?></span>
                                            </td>
                                            <td>
                                                <a class="btn btn-sm btn-outline-primary edit-contingent" title="Edit" href="#"
                                                   data-id="<?php echo (int)$c['id']; ?>"
                                                   data-kod="<?php echo htmlspecialchars($c['kod_universiti'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                   data-nama="<?php echo htmlspecialchars($c['nama_pegawai_untuk_dihubungi'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                   data-alamat="<?php echo htmlspecialchars($c['alamat'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                   data-emel="<?php echo htmlspecialchars($c['emel'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                   data-phone="<?php echo htmlspecialchars($c['no_telefon'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                                   data-status="<?php echo (int)$statusValue; ?>"
                                                >
                                                    <i class="fa fa-edit"></i>
                                                </a>
                                                <a class="btn btn-sm btn-outline-danger delete-contingent" title="Delete" href="#" data-id="<?php echo (int)$c['id']; ?>">
                                                    <i class="fa fa-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Initialize modal fixes on page load
document.addEventListener('DOMContentLoaded', function() {
    // Ensure fixModalZIndex is available globally for this page
    if (typeof fixModalZIndex === 'undefined') {
        // Create a local version if global doesn't exist
            window.fixModalZIndex = function() {
            const modals = document.querySelectorAll('.modal.show');
            modals.forEach(modal => {
                // INCREASED: modal z-index 1060 (MUST be above navbar/header 1000)
                modal.style.zIndex = '1060';
                modal.style.position = 'fixed';
                
                // CRITICAL: Ensure modal is always above navbar/header
                const navbar = document.querySelector('.navbar');
                const header = document.querySelector('.header, .header-sticky');
                if (navbar) {
                    navbar.style.zIndex = '1000';
                }
                if (header) {
                    header.style.zIndex = '1000';
                }
                
                // Modal-dialog and modal-content inherit from modal container
                // No separate z-index needed (Bootstrap standard)
                const dialog = modal.querySelector('.modal-dialog');
                if (dialog) {
                    dialog.style.pointerEvents = 'auto';
                    dialog.style.zIndex = ''; // Remove non-standard z-index
                }
                
                const content = modal.querySelector('.modal-content');
                if (content) {
                    content.style.pointerEvents = 'auto';
                    content.style.zIndex = ''; // Remove non-standard z-index
                }
            });
            
            // Ensure all backdrops follow Bootstrap standard z-index
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach((backdrop, index) => {
                // Standard Bootstrap: backdrop z-index 1040
                backdrop.style.zIndex = '1040';
                backdrop.style.position = 'fixed';
                
                // CRITICAL: Remove duplicate backdrops (only first one should exist)
                if (index > 0) {
                    backdrop.remove();
                }
            });
            // Remove duplicate backdrops
            if (backdrops.length > 1) {
                for (let i = 1; i < backdrops.length; i++) {
                    backdrops[i].remove();
                }
            }
        };
    }
    
    // Monitor for modal backdrop and fix z-index continuously
    const modalCheckInterval = setInterval(function() {
        const visibleModals = document.querySelectorAll('.modal.show');
        if (visibleModals.length > 0) {
            if (typeof fixModalZIndex === 'function') {
                fixModalZIndex();
            }
        }
    }, 500);
    
    // Stop monitoring when page unloads
    window.addEventListener('beforeunload', function() {
        clearInterval(modalCheckInterval);
    });
    
    // Also listen for modal show events
    document.addEventListener('show.coreui.modal', function() {
        setTimeout(function() {
            if (typeof fixModalZIndex === 'function') {
                fixModalZIndex();
            }
        }, 10);
    });
    
    document.addEventListener('shown.coreui.modal', function() {
        if (typeof fixModalZIndex === 'function') {
            fixModalZIndex();
        }
        setTimeout(function() {
            if (typeof fixModalZIndex === 'function') {
                fixModalZIndex();
            }
        }, 50);
    });
});

// Registration Form State
let currentStep = 1;
const totalSteps = 1;
let formData = {};

// Reload datatable function
function reloadContingentTable(callback) {
    const tbody = document.getElementById('contingentTableBody');
    if (!tbody) {
        if (callback) callback();
        return;
    }
    
    // Show loading state
    tbody.innerHTML = '<tr><td colspan="7" class="text-center py-3"><span class="spinner-border spinner-border-sm me-2"></span>Loading data...</td></tr>';
    
    fetch('<?php echo url("ajax/contingent_list.php"); ?>', {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
    })
    .then(function(res) { return res.json(); })
    .then(function(json) {
        if (json && json.success) {
            const contingents = json.data || [];
            const stats = json.stats || {total: 0, active: 0, inactive: 0};
            
            // Update statistics in hero section
            const heroSection = document.querySelector('.card.bg-light .d-none.d-md-flex');
            if (heroSection) {
                const statDivs = heroSection.querySelectorAll('.me-3 .h5.mb-0');
                if (statDivs.length >= 3) {
                    statDivs[0].textContent = stats.total || 0;
                    statDivs[1].textContent = stats.active || 0;
                    statDivs[2].textContent = stats.inactive || 0;
                }
            }
            
            // Update table
            if (contingents.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-5"><i class="cil cil-info" style="font-size: 2rem;"></i><p class="mt-2">No contingents registered. Click "Register New Contingent" to get started.</p></td></tr>';
            } else {
                let html = '';
                contingents.forEach(function(c, i) {
                    // Determine status text from server-provided status_universiti, fallback to numeric status
                    const statusText = (c.status_universiti !== undefined) ? String(c.status_universiti) : ((c.status !== undefined && parseInt(c.status) === 1) ? 'Active' : 'Inactive');
                    const status = (c.status !== undefined) ? parseInt(c.status) : ((statusText === 'Active') ? 1 : 0);
                    const badgeClass = (statusText === 'Active') ? 'bg-success' : 'bg-secondary';
                    const jumlahAtlet = (c.jumlah_atlet !== undefined) ? parseInt(c.jumlah_atlet) : 0;
                    const athleteBadge = (jumlahAtlet === 0) ? 'bg-danger' : 'bg-primary';

                    html += '<tr>';
                    html += '<td>' + (i + 1) + '</td>';
                    html += '<td><div class="fw-semibold">' + escapeHtml(c.nama_universiti || '-') + '</div></td>';
                    html += '<td>' + escapeHtml(c.kod_universiti || '') + '</td>';
                    html += '<td><div class="small">';
                    html += '<div class="fw-semibold">' + escapeHtml(c.nama_pegawai_untuk_dihubungi || '-') + '</div>';
                    if (c.emel) {
                        html += '<div class="text-muted small"><a href="mailto:' + escapeHtml(c.emel) + '">' + escapeHtml(c.emel) + '</a></div>';
                    }
                    html += '</div></td>';
                    html += '<td class="text-center"><span class="badge ' + athleteBadge + '">' + jumlahAtlet + '</span></td>';
                    html += '<td><span class="badge ' + badgeClass + '">' + escapeHtml(statusText) + '</span></td>';
                    html += '<td>';
                    html += '<a class="btn btn-sm btn-outline-primary edit-contingent" title="Edit" href="#" ';
                    html += 'data-id="' + (c.id || 0) + '" ';
                    html += 'data-kod="' + escapeHtml(c.kod_universiti || '') + '" ';
                    html += 'data-nama="' + escapeHtml(c.nama_pegawai_untuk_dihubungi || '') + '" ';
                    html += 'data-alamat="' + escapeHtml(c.alamat || '') + '" ';
                    html += 'data-emel="' + escapeHtml(c.emel || '') + '" ';
                    html += 'data-phone="' + escapeHtml(c.no_telefon || '') + '" ';
                    html += 'data-status="' + status + '">';
                    html += '<i class="fa fa-edit"></i></a> ';
                    html += '<a class="btn btn-sm btn-outline-danger delete-contingent" title="Delete" href="#" data-id="' + (c.id || 0) + '">';
                    html += '<i class="fa fa-trash"></i></a>';
                    html += '</td>';
                    html += '</tr>';
                });
                tbody.innerHTML = html;
            }
        } else {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-3">Unable to load data. Please reload the page.</td></tr>';
        }
        
        // Execute callback after reload completes
        if (callback) callback();
    })
    .catch(function(err) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-3">Connection error. Please reload the page.</td></tr>';
        if (callback) callback();
    });
}

// Helper function to escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Global modal instance for registration modal
let registrationModalInstance = null;

// Local cleanupModalBackdrops function if not available globally
if (typeof cleanupModalBackdrops !== 'function') {
    function cleanupModalBackdrops() {
        // Remove all but one backdrop (CoreUI should manage this, but we'll ensure)
        const backdrops = document.querySelectorAll('.modal-backdrop');
        
        // If multiple backdrops exist, remove extras
        if (backdrops.length > 1) {
            for (let i = 1; i < backdrops.length; i++) {
                backdrops[i].remove();
            }
        }
        
        // Wait a bit for CoreUI to finish its animation
        setTimeout(() => {
            // Check if any modals are still showing
            const visibleModals = document.querySelectorAll('.modal.show');
            
            if (visibleModals.length === 0) {
                // No modals are showing, remove all backdrops
                backdrops.forEach(backdrop => {
                    backdrop.remove();
                });
                
                // Remove modal-open class and restore body styles
                document.body.classList.remove('modal-open');
                document.body.style.overflow = '';
                document.body.style.paddingRight = '';
            } else {
                // Modals are showing, ensure only one backdrop exists
                const remainingBackdrops = document.querySelectorAll('.modal-backdrop');
                if (remainingBackdrops.length > 1) {
                    for (let i = 1; i < remainingBackdrops.length; i++) {
                        remainingBackdrops[i].remove();
                    }
                }
            }
        }, 150); // Small delay to allow CoreUI animations to complete
    }
}

// Show registration form with optional data for editing
function showRegistrationForm(data = null) {
    // Reset form
    document.getElementById('contingentForm').reset();
    document.getElementById('contingentId').value = '';
    document.getElementById('registrationModalLabel').textContent = 'New Contingent Registration';
    
    // If data provided, populate form for editing
    if (data && data.id) {
        document.getElementById('contingentId').value = data.id || '';
        document.getElementById('institution').value = data.kod || '';
        document.getElementById('contactOfficerName').value = data.nama || '';
        document.getElementById('address').value = data.alamat || '';
        document.getElementById('email').value = data.emel || '';
        document.getElementById('phone').value = data.phone || '';
        if (document.getElementById('status')) {
            document.getElementById('status').value = data.status !== undefined ? data.status : '0';
        }
        document.getElementById('registrationModalLabel').textContent = 'Sunting Contingent';
        
        // Remove disabled/selected from placeholder option
        const institutionSelect = document.getElementById('institution');
        const placeholderOption = institutionSelect.querySelector('option[value=""]');
        if (placeholderOption) {
            placeholderOption.removeAttribute('disabled');
            placeholderOption.removeAttribute('selected');
        }
    }
    // CRITICAL: Close any existing modals first to prevent stacking
    if (typeof closeAllModals === 'function') {
        closeAllModals();
    } else {
        // Manual cleanup
        const visibleModals = document.querySelectorAll('.modal.show');
        visibleModals.forEach(modal => {
            const instance = coreui?.Modal?.getInstance(modal) || bootstrap?.Modal?.getInstance(modal);
            if (instance) {
                instance.hide();
            } else {
                modal.classList.remove('show');
                modal.style.display = 'none';
            }
        });
        if (typeof cleanupModalBackdrops === 'function') {
            cleanupModalBackdrops();
        } else {
            // Local cleanup if function not available
            const backdrops = document.querySelectorAll('.modal-backdrop');
            backdrops.forEach(b => b.remove());
            document.body.classList.remove('modal-open');
        }
    }
    
    // Hide loading overlay before showing modal
    if (typeof hideLoadingOverlayForModal === 'function') {
        hideLoadingOverlayForModal();
    }
    
    // Load saved data if exists
    loadFormData();
    
    // Reset to step 1
    currentStep = 1;
    updateStepDisplay();
    
    // Get or create modal instance
    const modalElement = document.getElementById('registrationModal');
    
    // Always get or create fresh instance
    if (registrationModalInstance) {
        // Dispose old instance if exists
        try {
            registrationModalInstance.dispose();
        } catch (e) {
            // Ignore if already disposed
        }
    }
    
    // CRITICAL: Move modal to body level to avoid stacking context issues
    if (modalElement.parentElement !== document.body) {
        document.body.appendChild(modalElement);
    }
    
    // Create modal instance: prefer CoreUI, fallback to Bootstrap, final manual fallback
    if (typeof coreui !== 'undefined' && coreui.Modal) {
        registrationModalInstance = new coreui.Modal(modalElement, {
            backdrop: true,
            keyboard: true,
            focus: true
        });

        // Hide loading overlay when modal is shown (CoreUI event)
        modalElement.addEventListener('show.coreui.modal', function() {
            if (typeof hideLoadingOverlayForModal === 'function') {
                hideLoadingOverlayForModal();
            }
            if (typeof fixModalZIndex === 'function') {
                fixModalZIndex();
            }
        });

        // Clean up backdrop on hidden (CoreUI event)
        modalElement.addEventListener('hidden.coreui.modal', function() {
            if (typeof cleanupModalBackdrops === 'function') {
                cleanupModalBackdrops();
            }
        });

    } else if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        registrationModalInstance = new bootstrap.Modal(modalElement, {
            backdrop: true,
            keyboard: true,
            focus: true
        });

        // Bootstrap events
        modalElement.addEventListener('show.bs.modal', function() {
            if (typeof hideLoadingOverlayForModal === 'function') {
                hideLoadingOverlayForModal();
            }
            if (typeof fixModalZIndex === 'function') {
                fixModalZIndex();
            }
        });

        modalElement.addEventListener('hidden.bs.modal', function() {
            if (typeof cleanupModalBackdrops === 'function') {
                cleanupModalBackdrops();
            }
        });

    } else {
        // Final fallback: simple class toggle if no modal library available
        modalElement.classList.add('show');
        modalElement.style.display = 'block';
        document.body.classList.add('modal-open');
        if (typeof hideLoadingOverlayForModal === 'function') {
            hideLoadingOverlayForModal();
        }
        if (typeof fixModalZIndex === 'function') {
            fixModalZIndex();
        }
    }
    
    // CRITICAL: Ensure modal is at body level before showing
    if (modalElement.parentElement !== document.body) {
        document.body.appendChild(modalElement);
    }
    
    // Show modal (if library instance exists), otherwise fallback already showed it
    if (registrationModalInstance && typeof registrationModalInstance.show === 'function') {
        registrationModalInstance.show();
    }
    
    // CRITICAL: Force z-index immediately and continuously
    modalElement.style.zIndex = '1060';
    modalElement.style.position = 'fixed';
    
    // Ensure navbar/header are below modal
    const navbar = document.querySelector('.navbar');
    const header = document.querySelector('.header, .header-sticky');
    if (navbar) navbar.style.zIndex = '1000';
    if (header) header.style.zIndex = '1000';
    
    // Fix z-index after showing
    setTimeout(function() {
        modalElement.style.zIndex = '1060';
        modalElement.style.position = 'fixed';
        if (navbar) navbar.style.zIndex = '1000';
        if (header) header.style.zIndex = '1000';
        if (typeof fixModalZIndex === 'function') {
            fixModalZIndex();
        }
    }, 50);
    
    // Continuous monitoring to ensure z-index is maintained
    const zIndexInterval = setInterval(function() {
        if (modalElement.classList.contains('show')) {
            modalElement.style.zIndex = '1060';
            modalElement.style.position = 'fixed';
            if (navbar) navbar.style.zIndex = '1000';
            if (header) header.style.zIndex = '1000';
        } else {
            clearInterval(zIndexInterval);
        }
    }, 100);
}

// Load form data from localStorage
function loadFormData() {
    let saved = null;
    try { saved = localStorage.getItem('contingentRegistrationData'); } catch(e) { saved = null; }
    if (saved) {
        try { formData = JSON.parse(saved); } catch(e){ formData = {}; }
        // Populate form fields
        Object.keys(formData).forEach(key => {
            const field = document.getElementById(key);
            if (field) {
                field.value = formData[key];
            }
        });
    }
}

// Save form data to localStorage
function saveFormData() {
    const fields = [
        'institution', 'contactOfficerName', 'address', 'email', 'phone'
    ];
    
    fields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            formData[fieldId] = field.value;
        }
    });
    try { localStorage.setItem('contingentRegistrationData', JSON.stringify(formData)); } catch(e) { /* ignore storage errors */ }
}

// Update step display
function updateStepDisplay() {
    // Hide all steps
    document.querySelectorAll('.registration-step').forEach(step => {
        step.classList.add('d-none');
    });
    
    // Show current step
    const currentStepEl = document.getElementById('step' + currentStep);
    if (currentStepEl) {
        currentStepEl.classList.remove('d-none');
    }
    
    // Update buttons - single step form, so show submit button
    const backButton = document.getElementById('backButton');
    const nextButton = document.getElementById('nextButton');
    const submitButton = document.getElementById('submitButton');
    
    if (backButton) {
        backButton.style.display = 'none';
    }
    if (nextButton) {
        nextButton.style.display = 'none';
    }
    if (submitButton) {
        submitButton.style.display = 'inline-block';
    }
}

// Validate current step
function validateStep(step) {
    let isValid = true;
    const errors = [];
    
    if (step === 1) {
        const institution = document.getElementById('institution');
        if (!institution.value) {
            isValid = false;
            institution.classList.add('is-invalid');
            errors.push('Please select an institution');
        } else {
            institution.classList.remove('is-invalid');
            institution.classList.add('is-valid');
        }
        
        const contactOfficerName = document.getElementById('contactOfficerName');
        if (!contactOfficerName.value || contactOfficerName.value.length < 3) {
            isValid = false;
            contactOfficerName.classList.add('is-invalid');
            errors.push('Contact person name is required (at least 3 characters)');
        } else {
            contactOfficerName.classList.remove('is-invalid');
            contactOfficerName.classList.add('is-valid');
        }
        
        const address = document.getElementById('address');
        if (!address.value || address.value.length < 10 || address.value.length > 500) {
            isValid = false;
            address.classList.add('is-invalid');
            errors.push('Address must contain 10–500 characters');
        } else {
            address.classList.remove('is-invalid');
            address.classList.add('is-valid');
        }
        
        const email = document.getElementById('email');
        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!email.value || !emailPattern.test(email.value)) {
            isValid = false;
            email.classList.add('is-invalid');
            errors.push('Invalid email format');
        } else {
            email.classList.remove('is-invalid');
            email.classList.add('is-valid');
        }
        
        const phone = document.getElementById('phone');
        // Phone is optional, but if provided, validate format
        // Mobile: 01X-XXXXXXX (e.g., 010-1234567, 012-3456789)
        // Landline: 0X-XXXXXXX (e.g., 03-12345678, 04-1234567)
        if (phone.value) {
            const cleanedPhone = phone.value.replace(/\s/g, '');
            // Mobile pattern: 01[0-9] followed by optional dash and 7-8 digits
            const mobilePattern = /^01[0-9]-?[0-9]{7,8}$/;
            // Landline pattern: 0[1-9] followed by optional dash and 7-9 digits
            const landlinePattern = /^0[1-9]-?[0-9]{7,9}$/;
            
            if (!mobilePattern.test(cleanedPhone) && !landlinePattern.test(cleanedPhone)) {
                isValid = false;
                phone.classList.add('is-invalid');
                errors.push('Invalid phone number. Example: 012-3456789 (mobile) or 03-12345678 (landline)');
            } else {
                phone.classList.remove('is-invalid');
                phone.classList.add('is-valid');
            }
        } else {
            phone.classList.remove('is-invalid', 'is-valid');
        }
    }
    
    // Show error summary
    const errorSummary = document.getElementById('errorSummary');
    const errorList = document.getElementById('errorList');
    if (!isValid && errors.length > 0) {
        errorSummary.classList.remove('d-none');
        errorList.innerHTML = errors.map(err => `<li>${err}</li>`).join('');
        // Scroll to top
        document.querySelector('.modal-body').scrollTop = 0;
    } else {
        errorSummary.classList.add('d-none');
    }
    
    return isValid;
}

// Next step (not needed for single step form, but kept for compatibility)
function nextStep() {
    if (validateStep(currentStep)) {
        saveFormData();
        submitRegistration();
    }
}

// Previous step (not needed for single step form)
function previousStep() {
    // No previous step in single step form
}

// Go to specific step (not needed for single step form)
function goToStep(step) {
    currentStep = step;
    updateStepDisplay();
}

// Submit registration
function submitRegistration() {
    if (validateStep(1)) {
        // Show loading state
        const submitBtn = document.getElementById('submitButton');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Menghantar...';
        
        // Collect all form data
        saveFormData();
        
        // Prepare form data
        const formData = new FormData();
        const contingentId = document.getElementById('contingentId').value;
        if (contingentId) {
            formData.append('id', contingentId);
        }
        formData.append('kod_universiti', document.getElementById('institution').value);
        formData.append('nama_pegawai_untuk_dihubungi', document.getElementById('contactOfficerName').value);
        formData.append('alamat', document.getElementById('address').value);
        formData.append('emel', document.getElementById('email').value);
        formData.append('phone', document.getElementById('phone').value);
        // Status: only ADMIN/ORGANIZER can set it, CONTINGENT always gets 0
        const statusField = document.getElementById('status');
        if (statusField) {
            formData.append('status', statusField.value);
        } else {
            formData.append('status', '0'); // CONTINGENT users always get 0
        }
        
        // Show loading with SweetAlert
        if (window.Swal) {
            Swal.showLoading();
        }
        
        // Submit via AJAX
        fetch('<?php echo url("ajax/contingent_save.php"); ?>', {
            method: 'POST',
            credentials: 'same-origin',
            body: formData,
            headers: { 'Accept': 'application/json' }
        })
        .then(function(res) { return res.json(); })
        .then(function(json) {
            if (window.Swal) Swal.close();
            
            // Reset button state immediately
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
            
            if (json && json.success) {
                // Clear saved data
                localStorage.removeItem('contingentRegistrationData');
                
                // Close modal first
                closeModal();
                
                // Reset form completely
                resetModalForm();
                
                // Reload datatable, then show success message after reload completes
                reloadContingentTable(function() {
                    // Show success message after datatable is reloaded
                    if (window.Swal) {
                        Swal.fire({
                            text: json.message || 'Contingent saved successfully',
                            icon: 'success'
                        });
                    } else {
                        alert(json.message || 'Contingent saved successfully');
                    }
                });
            } else {
                // Show error message
                if (window.Swal) {
                    Swal.fire({
                        text: (json && json.message) || 'Unable to save the contingent',
                        icon: 'error'
                    });
                } else {
                    alert((json && json.message) || 'Unable to save the contingent');
                }
            }
        })
        .catch(function(err) {
            if (window.Swal) {
                Swal.close();
                Swal.fire({
                    text: 'Connection error. Please try again.',
                    icon: 'error'
                });
            } else {
                alert('Connection error. Please try again.');
            }
            // Reset button state on error
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        });
    }
}

// Reset modal form completely
function resetModalForm() {
    const form = document.getElementById('contingentForm');
    if (form) {
        form.reset();
        document.getElementById('contingentId').value = '';
        document.getElementById('registrationModalLabel').textContent = 'New Contingent Registration';
        
        // Clear all validation states
        form.querySelectorAll('input, select, textarea').forEach(field => {
            field.classList.remove('is-valid', 'is-invalid');
        });
        
        // Reset institution select placeholder
        const institutionSelect = document.getElementById('institution');
        if (institutionSelect) {
            const placeholderOption = institutionSelect.querySelector('option[value=""]');
            if (placeholderOption) {
                placeholderOption.setAttribute('disabled', 'disabled');
                placeholderOption.setAttribute('selected', 'selected');
            }
        }
        
        // Reset submit button state
        const submitBtn = document.getElementById('submitButton');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="cil cil-check me-1"></i> Send Registration';
        }
        
        // Reset step display
        currentStep = 1;
        updateStepDisplay();
        
        // Clear error summary
        const errorSummary = document.getElementById('errorSummary');
        if (errorSummary) {
            errorSummary.classList.add('d-none');
        }
    }
}

// Close modal helper
function closeModal() {
    const modalElement = document.getElementById('registrationModal');
    if (registrationModalInstance) {
        registrationModalInstance.hide();
    } else if (typeof coreui !== 'undefined' && coreui.Modal) {
        const modal = coreui.Modal.getInstance(modalElement);
        if (modal) {
            modal.hide();
        }
    } else if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const modal = bootstrap.Modal.getInstance(modalElement);
        if (modal) {
            modal.hide();
        }
    } else {
        // Fallback: hide using class
        modalElement.classList.remove('show');
        modalElement.style.display = 'none';
        document.body.classList.remove('modal-open');
    }
    
    // Clean up any lingering backdrops
    if (typeof cleanupModalBackdrops === 'function') {
        cleanupModalBackdrops();
    }
    
    // Reset form after modal is closed
    setTimeout(function() {
        resetModalForm();
    }, 300); // Small delay to ensure modal is fully closed
}

// Confirm cancel
function confirmCancel() {
    // Immediate cancel: clear saved data and close without confirmation
    localStorage.removeItem('contingentRegistrationData');
    closeModal();
    // Form will be reset by closeModal's setTimeout
}

// Real-time validation
document.addEventListener('DOMContentLoaded', function() {
    // Address character counter
    const addressField = document.getElementById('address');
    if (addressField) {
        addressField.addEventListener('input', function() {
            const count = this.value.length;
            const charCountEl = document.getElementById('addressCharCount');
            if (charCountEl) {
                charCountEl.textContent = count;
            }
            if (count > 500) {
                this.value = this.value.substring(0, 500);
                if (charCountEl) {
                    charCountEl.textContent = 500;
                }
            }
        });
    }
    
    // Phone number formatting
    const phoneField = document.getElementById('phone');
    if (phoneField) {
        phoneField.addEventListener('input', function() {
            let value = this.value.replace(/\D/g, '');
            if (value.length > 0) {
                // Check if mobile (starts with 01) or landline
                if (value.startsWith('01')) {
                    // Mobile: 01X-XXXXXXX
                    if (value.length <= 3) {
                        this.value = value;
                    } else if (value.length <= 10) {
                        this.value = value.substring(0, 3) + '-' + value.substring(3);
                    } else {
                        this.value = value.substring(0, 3) + '-' + value.substring(3, 10);
                    }
                } else {
                    // Landline: 0X-XXXXXXX (can be 7-9 digits after prefix)
                    if (value.length <= 2) {
                        this.value = value;
                    } else if (value.length <= 10) {
                        this.value = value.substring(0, 2) + '-' + value.substring(2);
                    } else {
                        this.value = value.substring(0, 2) + '-' + value.substring(2, 10);
                    }
                }
            }
        });
    }
    
    // Real-time validation on blur
    const allFields = document.querySelectorAll('#registrationModal input, #registrationModal select, #registrationModal textarea');
    allFields.forEach(field => {
        field.addEventListener('blur', function() {
            if (this.value) {
                // Validate based on current step
                const step = currentStep;
                validateStep(step);
            }
        });
    });
    
    // Also ensure modal z-index is fixed when modal is interacted with
    const registrationModal = document.getElementById('registrationModal');
    if (registrationModal) {
        // CRITICAL: Move modal to body level on page load (Bootstrap best practice)
        // This prevents stacking context issues with parent containers
        if (registrationModal.parentElement !== document.body) {
            document.body.appendChild(registrationModal);
        }
        
        // Monitor for any changes to modal - AGGRESSIVE
        const modalObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                    const target = mutation.target;
                    if (target.classList.contains('modal') && target.classList.contains('show')) {
                        // Fix z-index immediately - no delay
                        target.style.zIndex = '1060';
                        target.style.position = 'fixed';
                        const dialog = target.querySelector('.modal-dialog');
                        if (dialog) {
                            dialog.style.pointerEvents = 'auto';
                            dialog.style.zIndex = ''; // Remove non-standard z-index
                        }
                        const content = target.querySelector('.modal-content');
                        if (content) {
                            content.style.pointerEvents = 'auto';
                            content.style.zIndex = ''; // Remove non-standard z-index
                        }
                        // Fix all backdrops
                        const backdrops = document.querySelectorAll('.modal-backdrop');
                        backdrops.forEach(b => {
                            b.style.zIndex = '1040';
                            b.style.position = 'fixed';
                        });
                        if (typeof fixModalZIndex === 'function') {
                            fixModalZIndex();
                        }
                    }
                }
            });
        });
        
        modalObserver.observe(registrationModal, {
            attributes: true,
            attributeFilter: ['class', 'style']
        });
    }
    
    // CRITICAL: Monitor body for backdrop creation and fix immediately
    const backdropObserver = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length > 0) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1 && node.classList && node.classList.contains('modal-backdrop')) {
                        // Backdrop was just added - ensure only one exists
                        const allBackdrops = document.querySelectorAll('.modal-backdrop');
                        
                        // If more than one backdrop exists, remove extras
                        if (allBackdrops.length > 1) {
                            // Keep the first one, remove all others
                            for (let i = 1; i < allBackdrops.length; i++) {
                                allBackdrops[i].remove();
                            }
                        }
                        
                        // Fix z-index for remaining backdrop(s)
                        const remainingBackdrops = document.querySelectorAll('.modal-backdrop');
                        remainingBackdrops.forEach(b => {
                            b.style.zIndex = '1040';
                            b.style.position = 'fixed';
                            b.style.opacity = '0.5';
                        });
                        
                        // Ensure modal is above backdrop AND navbar/header
                        const visibleModals = document.querySelectorAll('.modal.show');
                        visibleModals.forEach(modal => {
                            modal.style.zIndex = '1060';
                            modal.style.position = 'fixed';
                            
                            // CRITICAL: Ensure modal is always above navbar/header
                            const navbar = document.querySelector('.navbar');
                            const header = document.querySelector('.header, .header-sticky');
                            if (navbar) navbar.style.zIndex = '1000';
                            if (header) header.style.zIndex = '1000';
                            const dialog = modal.querySelector('.modal-dialog');
                            if (dialog) {
                                dialog.style.pointerEvents = 'auto';
                                dialog.style.zIndex = ''; // Remove non-standard z-index
                            }
                            const content = modal.querySelector('.modal-content');
                            if (content) {
                                content.style.pointerEvents = 'auto';
                                content.style.zIndex = ''; // Remove non-standard z-index
                            }
                        });
                    }
                });
            }
        });
    });
    
    // Observe body for backdrop additions
    backdropObserver.observe(document.body, {
        childList: true,
        subtree: true
    });
    
    // Also continuously monitor for duplicate backdrops AND loading overlay
    setInterval(function() {
        // Check for duplicate backdrops
        const backdrops = document.querySelectorAll('.modal-backdrop');
        if (backdrops.length > 1) {
            // Keep only the first one
            for (let i = 1; i < backdrops.length; i++) {
                backdrops[i].remove();
            }
        }
        // Ensure backdrop z-index is correct
        backdrops.forEach(b => {
            b.style.zIndex = '1040';
            b.style.position = 'fixed';
        });
        // Ensure modal is above backdrop AND navbar/header
        const visibleModals = document.querySelectorAll('.modal.show');
        visibleModals.forEach(modal => {
            modal.style.zIndex = '1060';
            modal.style.position = 'fixed';
            
            // CRITICAL: Ensure modal is always above navbar/header
            const navbar = document.querySelector('.navbar');
            const header = document.querySelector('.header, .header-sticky');
            if (navbar) navbar.style.zIndex = '1000';
            if (header) header.style.zIndex = '1000';
        });
        
        // CRITICAL: Force hide loading overlay if any modal is open
        const hasOpenModal = visibleModals.length > 0 || backdrops.length > 0 || document.body.classList.contains('modal-open');
        const loadingOverlay = document.getElementById('loadingOverlay');
        if (hasOpenModal && loadingOverlay) {
            // Force hide loading overlay immediately
            loadingOverlay.style.display = 'none';
            loadingOverlay.style.visibility = 'hidden';
            loadingOverlay.style.opacity = '0';
            loadingOverlay.style.zIndex = '-1';
            loadingOverlay.style.pointerEvents = 'none';
            loadingOverlay.classList.remove('show', 'hide');
            loadingOverlay.style.position = 'fixed';
            loadingOverlay.style.top = '-9999px';
            loadingOverlay.style.left = '-9999px';
        }
    }, 100); // Check every 100ms
    
    // Handle edit and delete buttons
    document.addEventListener('click', function(e) {
        // Edit button
        var editBtn = e.target.closest && e.target.closest('.edit-contingent');
        if (editBtn) {
            e.preventDefault();
            var data = {
                id: editBtn.getAttribute('data-id'),
                kod: editBtn.getAttribute('data-kod'),
                nama: editBtn.getAttribute('data-nama'),
                alamat: editBtn.getAttribute('data-alamat'),
                emel: editBtn.getAttribute('data-emel'),
                phone: editBtn.getAttribute('data-phone'),
                status: editBtn.getAttribute('data-status')
            };
            showRegistrationForm(data);
        }
        
        // Delete button
        var delBtn = e.target.closest && e.target.closest('.delete-contingent');
        if (delBtn) {
            e.preventDefault();
            var id = delBtn.getAttribute('data-id');
            if (!id) return;
            
            if (window.Swal) {
                Swal.fire({
                    title: 'Delete this contingent?',
                    text: 'The contingent will be deleted. This action cannot be undone',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Delete',
                    cancelButtonText: 'Cancel'
                }).then(function(r) {
                    if (r.isConfirmed) {
                        // Show loading
                        Swal.showLoading();
                        
                        // Call AJAX delete endpoint
                        fetch('<?php echo url("ajax/contingent_delete.php"); ?>', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Accept': 'application/json' },
                            body: new URLSearchParams({ id: id })
                        })
                        .then(function(res) { return res.json(); })
                        .then(function(json) {
                            if (json && json.success) {
                                // Reload datatable, then show success message after reload completes
                                reloadContingentTable(function() {
                                    Swal.fire({
                                        text: json.message || 'Contingent dipadam',
                                        icon: 'success'
                                    });
                                });
                            } else {
                                Swal.fire({
                                    text: (json && json.message) || 'Operation not allowed',
                                    icon: 'error'
                                });
                            }
                        })
                        .catch(function(err) {
                            Swal.fire({
                                text: 'Server error. Please try again.',
                                icon: 'error'
                            });
                        });
                    }
                });
            } else {
                if (confirm('Delete this contingent?')) {
                    alert('Deletion is not enabled. Please contact the administrator for assistance.');
                }
            }
        }
    });
});
</script>
<script>
// Highlight options with no participants (improve visibility in browsers that support option styling)
document.addEventListener('DOMContentLoaded', function() {
    var sel = document.getElementById('institution');
    if (!sel) return;
    for (var i=0;i<sel.options.length;i++){
        var opt = sel.options[i];
        if (opt.getAttribute('data-empty') === '1'){
            try { opt.style.backgroundColor = '#fff3f3'; opt.style.color = '#b71c1c'; } catch(e){}
        }
    }
});
</script>

<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
?>
