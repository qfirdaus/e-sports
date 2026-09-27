<?php
/**
 * RBAC Management UI Component
 * Dynamic Role-Based Access Control Management Interface
 */
?>

<!-- RBAC Management Section -->
<div class="row">
    <!-- Roles Management -->
    <div class="col-lg-6 mb-4">
        <div class="card">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <div>
                    <i class="cil cil-shield-alt me-2"></i><strong>Manage Roles</strong>
                </div>
                <button type="button" class="btn btn-sm btn-light" onclick="showCreateRoleModal()">
                    <i class="cil cil-plus me-1"></i> New Role
                </button>
            </div>
            <div class="card-body">
                <div id="rolesListContainer">
                    <div class="text-center py-3">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- User-Role Assignment -->
    <div class="col-lg-6 mb-4">
        <div class="card">
            <div class="card-header bg-success text-white">
                <i class="cil cil-people me-2"></i><strong>User Role Assignments</strong>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label for="userSelect" class="form-label">Select User:</label>
                    <select class="form-select" id="userSelect" onchange="loadUserRoles()">
                        <option value="">-- Select User --</option>
                    </select>
                </div>
                <div id="userRolesContainer">
                    <p class="text-muted text-center">Select a user to view their roles</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Page Access Rules -->
<div class="row">
    <div class="col-12 mb-4">
        <div class="card">
            <div class="card-header bg-info text-white d-flex justify-content-between align-items-center">
                <div>
                    <i class="cil cil-lock-locked me-2"></i><strong>Page Access Rules</strong>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <select id="pageRulesRoleFilter" class="form-select form-select-sm" style="min-width:240px;">
                        <option value="">-- Please Select --</option>
                        <option value="ALL">All Roles</option>
                    </select>
                    <button type="button" class="btn btn-sm btn-light" onclick="showCreatePageRuleModal()">
                        <i class="cil cil-plus me-1"></i> Rule Baru
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div id="pageRulesListContainer">
                    <div class="text-center py-3">
                        <div class="spinner-border text-info" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Create/Edit Role Modal -->
<div class="modal fade" id="roleModal" tabindex="-1" aria-labelledby="roleModalLabel" aria-hidden="true" data-coreui-backdrop="true" data-coreui-keyboard="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="roleModalLabel">New Role</h5>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal" data-bs-dismiss="modal" onclick="closeAllModals()"></button>
            </div>
            <div class="modal-body">
                <form id="roleForm">
                    <input type="hidden" id="roleId" name="role_id">
                    
                    <div class="mb-3">
                        <label for="roleCode" class="form-label">Role Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="roleCode" name="role_code" 
                               placeholder="e.g. MANAGER" required pattern="[A-Z0-9_]+" 
                               title="Uppercase letters, numbers and underscores only">
                        <div class="form-text">Unique role code (uppercase only)</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="roleName" class="form-label">Role Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="roleName" name="role_name" 
                               placeholder="e.g. Manager" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="roleDescription" class="form-label">Description</label>
                        <textarea class="form-control" id="roleDescription" name="description" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="isSystemRole" name="is_system_role">
                            <label class="form-check-label" for="isSystemRole">
                                System Role (cannot be deleted)
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Permissions:</label>
                        <div id="permissionsList" class="border rounded p-3" style="max-height: 300px; overflow-y: auto;">
                            <div class="text-center py-3">
                                <div class="spinner-border spinner-border-sm text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-coreui-dismiss="modal" data-bs-dismiss="modal" onclick="closeAllModals()">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveRole()">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Create/Edit Page Rule Modal -->
<div class="modal fade" id="pageRuleModal" tabindex="-1" aria-labelledby="pageRuleModalLabel" aria-hidden="true" data-coreui-backdrop="true" data-coreui-keyboard="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="pageRuleModalLabel">Page Access Rules</h5>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal" data-bs-dismiss="modal" onclick="closeAllModals()"></button>
            </div>
            <div class="modal-body">
                <form id="pageRuleForm">
                    <input type="hidden" id="pageRuleId" name="page_rule_id">
                    
                    <div class="mb-3">
                        <label for="pagePath" class="form-label">Page Path <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="pagePath" name="page_path" 
                               placeholder="e.g. pages/settings.php" required>
                        <div class="form-text">Path relative to the root (e.g. pages/settings.php or index.php)</div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="isPublic" name="is_public" 
                                   onchange="toggleRoleSelection()">
                            <label class="form-check-label" for="isPublic">
                                Public Page (no sign-in required)
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="requiresAuth" name="requires_auth" checked>
                            <label class="form-check-label" for="requiresAuth">
                                Memerlukan Confirmation
                            </label>
                        </div>
                    </div>
                    
                    <div class="mb-3" id="rolesSelectionContainer">
                        <label class="form-label">Allowed Roles:</label>
                        <div id="rolesCheckboxList" class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                            <div class="text-center py-2">
                                <div class="spinner-border spinner-border-sm text-primary" role="status">
                                    <span class="visually-hidden">Loading...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-coreui-dismiss="modal" data-bs-dismiss="modal" onclick="closeAllModals()">Cancel</button>
                <button type="button" class="btn btn-info" onclick="savePageRule()">Save</button>
            </div>
        </div>
    </div>
</div>

<script>
// Scoped style fix for Select2 in RBAC user assignment
(function injectRbacSelect2Style(){
    if (document.getElementById('rbac-select2-style')) return;
    const css = `
    #userRolesContainer, #userSelect { font-size: 14px; }
    #userSelect + .select2-container { width: 100% !important; }
    #userSelect + .select2-container .select2-selection--single {
        height: 38px !important;
        min-height: 38px !important;
        border: 1px solid #ced4da !important;
        border-radius: 0.375rem !important;
        display: flex !important;
        align-items: center !important;
        background-color: #fff !important;
        box-sizing: border-box !important;
    }
    #userSelect + .select2-container .select2-selection__rendered {
        line-height: 36px !important;
        padding-left: 12px !important;
        padding-right: 32px !important;
        color: #212529 !important;
        font-size: 14px !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }
    #userSelect + .select2-container .select2-selection__arrow {
        height: 36px !important;
        right: 8px !important;
        top: 0 !important;
    }
    .select2-container--open .select2-dropdown {
        z-index: 1080 !important;
    }`;
    const style = document.createElement('style');
    style.id = 'rbac-select2-style';
    style.textContent = css;
    document.head.appendChild(style);
})();

// RBAC Management JavaScript
const RBACManager = {
    apiBase: <?php echo json_encode(url('api/rbac/')); ?>,
    rolesData: [],
    pageRulesRaw: [],
    
    init: function() {
        this.ensureSelect2Assets().then(() => this.initUserSelect2()).catch(() => {});
        this.bindPageRulesFilter();
        this.loadRoles();
        this.loadUsers();
        this.loadPageRules();
        this.loadPermissions();
    },
    
    bindPageRulesFilter: function() {
        const ddl = document.getElementById('pageRulesRoleFilter');
        if (!ddl) return;
        ddl.addEventListener('change', () => {
            this.renderPageRules(this.pageRulesRaw || []);
        });
    },

    renderPageRoleFilterOptions: function(roles) {
        const ddl = document.getElementById('pageRulesRoleFilter');
        if (!ddl) return;
        const selected = ddl.value || '';
        ddl.innerHTML = '<option value="">-- Please Select --</option><option value="ALL">All Roles</option>';
        (roles || []).forEach(role => {
            const opt = document.createElement('option');
            opt.value = String(role.id);
            opt.textContent = role.role_name + ' (' + role.role_code + ')';
            ddl.appendChild(opt);
        });
        if (selected && Array.from(ddl.options).some(o => o.value === selected)) {
            ddl.value = selected;
        }
    },

    ensureSelect2Assets: function() {
        return new Promise((resolve, reject) => {
            // Need jQuery for Select2
            if (!window.jQuery) {
                reject(new Error('jQuery not available'));
                return;
            }
            const done = () => resolve();
            const fail = () => reject(new Error('Select2 assets failed to load'));

            // CSS
            if (!document.querySelector('link[href*="select2.min.css"]')) {
                const css = document.createElement('link');
                css.rel = 'stylesheet';
                css.href = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css';
                document.head.appendChild(css);
            }

            // JS
            if (window.jQuery.fn && window.jQuery.fn.select2) {
                done();
                return;
            }
            const existing = document.querySelector('script[src*="select2.min.js"]');
            if (existing) {
                let retry = 0;
                const timer = setInterval(() => {
                    if (window.jQuery.fn && window.jQuery.fn.select2) {
                        clearInterval(timer);
                        done();
                        return;
                    }
                    retry++;
                    if (retry > 40) {
                        clearInterval(timer);
                        fail();
                    }
                }, 100);
                return;
            }
            const js = document.createElement('script');
            js.src = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js';
            js.onload = () => {
                if (window.jQuery.fn && window.jQuery.fn.select2) done();
                else fail();
            };
            js.onerror = fail;
            document.body.appendChild(js);
        });
    },

    initUserSelect2: function() {
        try {
            if (!window.jQuery || !window.jQuery.fn || !window.jQuery.fn.select2) return;
            const $select = window.jQuery('#userSelect');
            if (!$select.length) return;
            if ($select.data('select2')) {
                $select.select2('destroy');
            }
            $select.select2({
                width: 'resolve',
                placeholder: '-- Select User --',
                allowClear: true,
                dropdownParent: window.jQuery('#user'),
                minimumResultsForSearch: 0
            });
        } catch (e) {
            console.warn('initUserSelect2 failed:', e);
        }
    },
    
    loadRoles: async function() {
        try {
            const response = await fetch(this.apiBase + 'roles.php?action=list');
            let data = null;
            try {
                data = await response.json();
            } catch (e) {
                data = null;
            }
            
            if (response.ok && data && data.success) {
                this.rolesData = Array.isArray(data.data) ? data.data : [];
                this.renderRoles(data.data);
                this.renderPageRoleFilterOptions(this.rolesData);
            } else {
                const msg = (data && data.message) ? data.message : ('HTTP ' + response.status + ' while loading roles');
                document.getElementById('rolesListContainer').innerHTML = 
                    '<div class="alert alert-danger">Unable to load roles: ' + msg + '</div>';
            }
        } catch (error) {
            console.error('Error loading roles:', error);
            document.getElementById('rolesListContainer').innerHTML = 
                '<div class="alert alert-danger">Unable to load roles: ' + (error.message || 'Unknown error') + '</div>';
        }
    },
    
    renderRoles: function(roles) {
        const container = document.getElementById('rolesListContainer');
        
        if (roles.length === 0) {
            container.innerHTML = '<p class="text-muted text-center">No roles found</p>';
            return;
        }
        
        let html = '<div class="table-responsive"><table class="table table-hover">';
        html += '<thead><tr><th>Code</th><th>Name</th><th>User</th><th>Permissions</th><th>Actions</th></tr></thead><tbody>';
        
        roles.forEach(role => {
            html += `<tr>
                <td><code>${role.role_code}</code></td>
                <td>${role.role_name}</td>
                <td><span class="badge bg-secondary">${role.user_count || 0}</span></td>
                <td><span class="badge bg-info">${role.permission_count || 0}</span></td>
                <td>
                    <button class="btn btn-sm btn-primary" onclick="editRole(${role.id})">
                        <i class="cil cil-pencil"></i>
                    </button>
                    ${role.is_system_role ? '' : `<button class="btn btn-sm btn-danger" onclick="deleteRole(${role.id})">
                        <i class="cil cil-trash"></i>
                    </button>`}
                </td>
            </tr>`;
        });
        
        html += '</tbody></table></div>';
        container.innerHTML = html;
    },
    
    loadUsers: async function() {
        try {
            const response = await fetch(this.apiBase + 'users.php?action=list');
            let data = null;
            try {
                data = await response.json();
            } catch (e) {
                data = null;
            }
            
            if (response.ok && data && data.success) {
                this.renderUserSelect(data.data);
            } else {
                const msg = (data && data.message) ? data.message : ('HTTP ' + response.status + ' while loading users');
                const select = document.getElementById('userSelect');
                if (select) {
                    select.innerHTML = '<option value="">-- Error: ' + msg + ' --</option>';
                }
                const container = document.getElementById('userRolesContainer');
                if (container) {
                    container.innerHTML = '<div class="alert alert-danger mb-0">Unable to load user role assignments: ' + msg + '</div>';
                }
            }
        } catch (error) {
            console.error('Error loading users:', error);
            const select = document.getElementById('userSelect');
            if (select) {
                select.innerHTML = '<option value="">-- Unable to load users --</option>';
            }
            const container = document.getElementById('userRolesContainer');
            if (container) {
                container.innerHTML = '<div class="alert alert-danger mb-0">Unable to load user role assignments: ' + (error.message || 'Unknown error') + '</div>';
            }
        }
    },
    
    renderUserSelect: function(users) {
        const select = document.getElementById('userSelect');
        select.innerHTML = '<option value="">-- Select User --</option>';
        
        users.forEach(user => {
            const option = document.createElement('option');
            option.value = user.id;
            option.textContent = `${user.full_name} (${user.username})`;
            select.appendChild(option);
        });

        // Refresh Select2 after options updated
        this.initUserSelect2();
    },
    
    loadPageRules: async function() {
        try {
            const response = await fetch(this.apiBase + 'pages.php?action=list');
            const data = await response.json();
            
            if (data.success) {
                this.pageRulesRaw = Array.isArray(data.data) ? data.data : [];
                this.renderPageRules(this.pageRulesRaw);
            }
        } catch (error) {
            console.error('Error loading page rules:', error);
            document.getElementById('pageRulesListContainer').innerHTML = 
                '<div class="alert alert-danger">Unable to load rules</div>';
        }
    },
    
    renderPageRules: function(rules) {
        const container = document.getElementById('pageRulesListContainer');
        const selectedRole = (document.getElementById('pageRulesRoleFilter') || {}).value || '';
        let filtered = Array.isArray(rules) ? rules.slice() : [];
        if (selectedRole && selectedRole !== 'ALL') {
            filtered = filtered.filter(rule => {
                const ids = String(rule.role_ids || '')
                    .split(',')
                    .map(v => v.trim())
                    .filter(Boolean);
                return ids.includes(String(selectedRole));
            });
        }
        
        if (filtered.length === 0) {
            container.innerHTML = '<p class="text-muted text-center">No rules found</p>';
            return;
        }
        
        let html = '<div class="table-responsive"><table class="table table-hover">';
        html += '<thead><tr><th>Page Path</th><th>Public</th><th>Authentication Required</th><th>Role</th><th>Actions</th></tr></thead><tbody>';
        
        filtered.forEach(rule => {
            html += `<tr>
                <td><code>${rule.page_path}</code></td>
                <td>${rule.is_public ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'}</td>
                <td>${rule.requires_auth ? '<span class="badge bg-warning">Yes</span>' : '<span class="badge bg-secondary">No</span>'}</td>
                <td>${rule.allowed_roles || '<span class="text-muted">None</span>'}</td>
                <td>
                    <button class="btn btn-sm btn-info" onclick="editPageRule(${rule.id})">
                        <i class="cil cil-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-danger" onclick="deletePageRule(${rule.id})">
                        <i class="cil cil-trash"></i>
                    </button>
                </td>
            </tr>`;
        });
        
        html += '</tbody></table></div>';
        container.innerHTML = html;
    },
    
    loadPermissions: async function() {
        // This would load from permissions API if available
        // For now, we'll use a placeholder
        return [];
    }
};

// Function to close all modals
function closeAllModals() {
    // Close all visible modals
    const visibleModals = document.querySelectorAll('.modal.show');
    visibleModals.forEach(modal => {
        const instance = getModalInstance(modal);
        if (instance) {
            instance.hide();
        } else {
            // Fallback: manually hide
            modal.classList.remove('show');
            modal.style.display = 'none';
        }
    });
    
    // Clean up all backdrops immediately
    cleanupModalBackdrops();
}

function getModalApi() {
    if (window.coreui && window.coreui.Modal) return window.coreui.Modal;
    if (window.bootstrap && window.bootstrap.Modal) return window.bootstrap.Modal;
    return null;
}

function getModalInstance(modalEl) {
    const Api = getModalApi();
    if (!Api || !modalEl || typeof Api.getInstance !== 'function') return null;
    return Api.getInstance(modalEl);
}

function createModalInstance(modalEl, options) {
    const Api = getModalApi();
    if (!Api || !modalEl) throw new Error('Dialog library unavailable.');
    return new Api(modalEl, options || {});
}

// Function to fix modal z-index - Bootstrap Standard
function fixModalZIndex() {
    // Hide loading overlay first
    hideLoadingOverlayForModal();
    
    // Ensure all modals follow Bootstrap standard z-index
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
            // Remove any non-standard z-index
            dialog.style.zIndex = '';
        }
        
        const content = modal.querySelector('.modal-content');
        if (content) {
            content.style.pointerEvents = 'auto';
            // Remove any non-standard z-index
            content.style.zIndex = '';
        }
    });
    
    // Ensure all backdrops follow Bootstrap standard z-index
    const backdrops = document.querySelectorAll('.modal-backdrop');
    backdrops.forEach((backdrop, index) => {
        // Standard Bootstrap: backdrop z-index 1040
        backdrop.style.zIndex = '1040';
        backdrop.style.position = 'fixed';
        backdrop.style.pointerEvents = 'auto';
        
        // CRITICAL: Remove duplicate backdrops (only first one should exist)
        if (index > 0) {
            backdrop.remove();
        }
    });
    
    // Ensure loading overlay is hidden and behind everything
    const loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) {
        loadingOverlay.style.zIndex = '-1';
        loadingOverlay.style.display = 'none';
        loadingOverlay.style.pointerEvents = 'none';
    }
}

// Global function to clean up modal backdrops
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
            
            // Ensure backdrop z-index is correct
            fixModalZIndex();
        }
    }, 150); // Small delay to allow CoreUI animations to complete
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Clean up any lingering backdrops on page load
    cleanupModalBackdrops();
    
    // Hide loading overlay if it's still visible
    hideLoadingOverlayForModal();
    
    // CRITICAL: Move all modals to body level on page load (Bootstrap best practice)
    // This prevents stacking context issues with parent containers
    // IMPORTANT: Only select elements with class "modal" (NOT tab-pane or other elements)
    const allModals = document.querySelectorAll('div.modal, .modal');
    allModals.forEach(modal => {
        // Double-check it's actually a modal element (not a tab-pane or other element)
        // Exclude tab-panes and other elements that might have "modal" in their class list
        if (modal.classList.contains('modal') && 
            !modal.classList.contains('tab-pane') && 
            !modal.id.includes('display') && 
            !modal.id.includes('system') &&
            modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
    });
    
    // Global event listener for ALL modal hidden events
    document.addEventListener('hidden.coreui.modal', function(e) {
        // Clean up backdrops when any modal is hidden
        cleanupModalBackdrops();
    });
    
    // Also listen for Bootstrap modal events (if using Bootstrap)
    document.addEventListener('hidden.bs.modal', function(e) {
        cleanupModalBackdrops();
    });
    
    // Only initialize if we're on the user tab
    const userTab = document.getElementById('user-tab');
    if (userTab) {
        const initRbacSection = function() {
            RBACManager.init();
            // Clean up any backdrops when tab is shown
            cleanupModalBackdrops();
            // Hide loading overlay
            hideLoadingOverlayForModal();
        };

        // CoreUI tab event
        userTab.addEventListener('shown.coreui.tab', initRbacSection);
        // Bootstrap tab event (settings.php currently uses data-bs-toggle="tab")
        userTab.addEventListener('shown.bs.tab', initRbacSection);
        // Fallback for environments where shown event does not fire as expected
        userTab.addEventListener('click', function() {
            setTimeout(function() {
                const userPane = document.getElementById('user');
                if (userPane && (userPane.classList.contains('active') || userPane.classList.contains('show'))) {
                    initRbacSection();
                }
            }, 120);
        });
        
        // Also initialize if tab is already active
        if (userTab.classList.contains('active')) {
            initRbacSection();
        } else if (window.location.hash === '#user') {
            initRbacSection();
        }
    }
    
    // CRITICAL: Hide loading overlay when any modal is shown
    document.addEventListener('show.coreui.modal', function(e) {
        // Force hide loading overlay immediately
        hideLoadingOverlayForModal();
        
        // Also check and hide after a short delay
        setTimeout(hideLoadingOverlayForModal, 10);
        
        // Fix z-index when modal is shown
        setTimeout(fixModalZIndex, 10);
    });
    
    // Also hide on modal show event (Bootstrap)
    document.addEventListener('show.bs.modal', function(e) {
        hideLoadingOverlayForModal();
        setTimeout(hideLoadingOverlayForModal, 10);
    });
    
    // Fix z-index when modal is fully shown
    document.addEventListener('shown.coreui.modal', function() {
        // Ensure loading overlay is hidden
        hideLoadingOverlayForModal();
        fixModalZIndex();
        // Also fix after multiple delays to ensure DOM is updated
        setTimeout(fixModalZIndex, 10);
        setTimeout(fixModalZIndex, 50);
        setTimeout(fixModalZIndex, 100);
    });
    
    // Continuously monitor and fix z-index while modal is open
    setInterval(function() {
        const visibleModals = document.querySelectorAll('.modal.show');
        if (visibleModals.length > 0) {
            fixModalZIndex();
        }
    }, 500); // Check every 500ms while modals are open
    
    // Monitor for modal backdrop creation and fix z-index
    const modalObserver = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length > 0) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1) { // Element node
                        // If modal backdrop is added, fix z-index
                        if (node.classList && node.classList.contains('modal-backdrop')) {
                            hideLoadingOverlayForModal();
                            node.style.zIndex = '1040';
                            node.style.position = 'fixed';
                            // Fix z-index after backdrop is added
                            setTimeout(fixModalZIndex, 10);
                        }
                        // If modal is shown, fix z-index
                        if (node.classList && node.classList.contains('modal')) {
                            if (node.classList.contains('show')) {
                                hideLoadingOverlayForModal();
                                setTimeout(fixModalZIndex, 10);
                            }
                        }
                    }
                });
            }
            
            // Also check for attribute changes (like class changes)
            if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
                const target = mutation.target;
                if (target.classList.contains('modal') && target.classList.contains('show')) {
                    setTimeout(fixModalZIndex, 10);
                }
                if (target.classList.contains('modal-backdrop')) {
                    setTimeout(fixModalZIndex, 10);
                }
            }
        });
    });
    
    // Observe body for modal/backdrop additions and changes
    modalObserver.observe(document.body, {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class', 'style']
    });
    
    // Clean up backdrops when clicking outside modal
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-backdrop')) {
            // Find and close the associated modal
            const modals = document.querySelectorAll('.modal.show');
            modals.forEach(modal => {
                const instance = getModalInstance(modal);
                if (instance) {
                    instance.hide();
                }
            });
            cleanupModalBackdrops();
        }
    });
    
    // Clean up on page unload
    window.addEventListener('beforeunload', function() {
        cleanupModalBackdrops();
        hideLoadingOverlayForModal();
    });
});

function rbacNotify(type, message, title) {
    const text = message || '';
    if (typeof Swal !== 'undefined' && Swal && typeof Swal.fire === 'function') {
        return Swal.fire({
            icon: type || 'info',
            title: title || (type === 'error' ? 'Error' : 'Makluman'),
            text: text
        });
    }
    if (typeof Toast !== 'undefined') {
        if (type === 'error' && typeof Toast.error === 'function') { Toast.error(text); return Promise.resolve(); }
        if (type === 'success' && typeof Toast.success === 'function') { Toast.success(text); return Promise.resolve(); }
        if (typeof Toast.info === 'function') { Toast.info(text); return Promise.resolve(); }
    }
    console.log('[RBAC][' + (type || 'info') + '] ' + text);
    return Promise.resolve();
}

async function ensureRbacSwal() {
    if (typeof Swal !== 'undefined' && Swal && typeof Swal.fire === 'function') {
        return true;
    }
    return await new Promise((resolve) => {
        const existing = document.querySelector('script[src*="sweetalert2"]');
        if (existing) {
            let retry = 0;
            const timer = setInterval(() => {
                if (typeof Swal !== 'undefined' && Swal && typeof Swal.fire === 'function') {
                    clearInterval(timer);
                    resolve(true);
                    return;
                }
                retry++;
                if (retry > 40) {
                    clearInterval(timer);
                    resolve(false);
                }
            }, 100);
            return;
        }
        const js = document.createElement('script');
        js.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js';
        js.onload = () => resolve(!!(window.Swal && window.Swal.fire));
        js.onerror = () => resolve(false);
        document.body.appendChild(js);
    });
}

async function rbacConfirm(message, title, confirmText) {
    await ensureRbacSwal();
    if (typeof Swal !== 'undefined' && Swal && typeof Swal.fire === 'function') {
        const res = await Swal.fire({
            icon: 'warning',
            title: title || 'Confirmation',
            text: message || '',
            showCancelButton: true,
            confirmButtonText: confirmText || 'Yes',
            cancelButtonText: 'Cancel',
            reverseButtons: true
        });
        return !!(res && res.isConfirmed);
    }
    if (typeof Toast !== 'undefined' && typeof Toast.info === 'function') {
        Toast.info(message || 'Confirmation required.');
    }
    return false;
}

// Helper functions - Modal instances
let roleModalInstance = null;
let pageRuleModalInstance = null;

// Function to hide loading overlay when modal opens
function hideLoadingOverlayForModal() {
    // Use forceHide for immediate hiding when modals are involved
    if (typeof LoadingOverlay !== 'undefined') {
        if (LoadingOverlay.forceHide) {
            LoadingOverlay.forceHide();
        } else {
            LoadingOverlay.hide();
        }
    }
    
    // Also directly hide the overlay element with aggressive styles
    const loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) {
        loadingOverlay.style.display = 'none';
        loadingOverlay.style.zIndex = '-1';
        loadingOverlay.style.pointerEvents = 'none';
        loadingOverlay.style.opacity = '0';
        loadingOverlay.style.visibility = 'hidden';
        loadingOverlay.classList.remove('show', 'hide');
    }
}

async function showCreateRoleModal() {
    // CRITICAL: Close any existing modals first to prevent stacking
    closeAllModals();
    
    // Hide loading overlay before showing modal
    hideLoadingOverlayForModal();
    
    document.getElementById('roleForm').reset();
    document.getElementById('roleId').value = '';
    document.getElementById('roleModalLabel').textContent = 'New Role';
    
    // Load permissions
    await loadPermissionsForRole();
    
    // Get or create modal instance
    const modalElement = document.getElementById('roleModal');
    
    // Always get or create fresh instance
    if (roleModalInstance) {
        // Dispose old instance if exists
        try {
            roleModalInstance.dispose();
        } catch (e) {
            // Ignore if already disposed
        }
    }
    
    // CRITICAL: Move modal to body level to avoid stacking context issues
    if (modalElement.parentElement !== document.body) {
        document.body.appendChild(modalElement);
    }
    
    roleModalInstance = createModalInstance(modalElement, {
        backdrop: true,
        keyboard: true,
        focus: true
    });
    
    // Hide loading overlay when modal is shown
    modalElement.addEventListener('show.coreui.modal', function() {
        hideLoadingOverlayForModal();
        // Ensure z-index is correct
        fixModalZIndex();
    });
    
    // Clean up backdrop on hidden
    modalElement.addEventListener('hidden.coreui.modal', function() {
        cleanupModalBackdrops();
    });
    
    // Show modal
    roleModalInstance.show();
    
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
        fixModalZIndex();
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

async function loadPermissionsForRole() {
    const container = document.getElementById('permissionsList');
    container.innerHTML = '<div class="text-center py-2"><div class="spinner-border spinner-border-sm text-primary"></div> Loading permissions...</div>';
    
    try {
        // Load permissions from API (if available) or use static list
        const permissions = await fetchPermissions();
        
        if (!permissions || permissions.length === 0) {
            container.innerHTML = '<div class="alert alert-warning">No permissions are available. Run the database migration first.</div>';
            return;
        }
        
        // Group permissions by module
        const grouped = {};
        permissions.forEach(perm => {
            const module = perm.module || 'other';
            if (!grouped[module]) {
                grouped[module] = [];
            }
            grouped[module].push(perm);
        });
        
        let html = '';
        Object.keys(grouped).sort().forEach(module => {
            html += `<div class="mb-3">
                <h6 class="text-primary border-bottom pb-1">${module.toUpperCase()}</h6>`;
            
            grouped[module].forEach(perm => {
                html += `<div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="permissions[]" 
                           value="${perm.id}" id="perm_${perm.id}">
                    <label class="form-check-label" for="perm_${perm.id}">
                        <strong>${perm.permission_name || perm.permission_code}</strong>
                        ${perm.description ? `<br><small class="text-muted">${perm.description}</small>` : ''}
                    </label>
                </div>`;
            });
            
            html += '</div>';
        });
        
        container.innerHTML = html;
    } catch (error) {
        console.error('Error loading permissions:', error);
        container.innerHTML = '<div class="alert alert-danger">Unable to load permissions: ' + (error.message || 'Unknown error') + '</div>';
    }
}

async function fetchPermissions() {
    try {
        const response = await fetch('<?php echo BASE_URL; ?>api/rbac/permissions.php?action=list');
        if (!response.ok) {
            console.warn('Permissions API not available, using fallback');
            return getFallbackPermissions();
        }
        const data = await response.json();
        if (data.success && data.data && data.data.length > 0) {
            return data.data;
        }
        // If API returns empty, use fallback
        return getFallbackPermissions();
    } catch (error) {
        console.warn('Error fetching permissions, using fallback:', error);
        return getFallbackPermissions();
    }
}

function getFallbackPermissions() {
    // Fallback permissions list if database doesn't have them yet
    return [
        { id: 1, permission_code: 'user.create', permission_name: 'Create User', description: 'Create new users', module: 'users' },
        { id: 2, permission_code: 'user.edit', permission_name: 'Edit User', description: 'Update user details', module: 'users' },
        { id: 3, permission_code: 'user.delete', permission_name: 'Delete User', description: 'Delete users', module: 'users' },
        { id: 4, permission_code: 'user.view', permission_name: 'View User', description: 'View user list', module: 'users' },
        { id: 5, permission_code: 'role.create', permission_name: 'Create Role', description: 'Create new roles', module: 'rbac' },
        { id: 6, permission_code: 'role.edit', permission_name: 'Edit Role', description: 'Update roles', module: 'rbac' },
        { id: 7, permission_code: 'role.delete', permission_name: 'Delete Role', description: 'Delete roles', module: 'rbac' },
        { id: 8, permission_code: 'role.assign', permission_name: 'Assign Role', description: 'Assign roles to users', module: 'rbac' },
        { id: 9, permission_code: 'contingent.create', permission_name: 'Create Contingent', description: 'Create new contingents', module: 'contingent' },
        { id: 10, permission_code: 'contingent.edit', permission_name: 'Edit Contingent', description: 'Update contingent details', module: 'contingent' },
        { id: 11, permission_code: 'contingent.delete', permission_name: 'Delete Contingent', description: 'Delete contingents', module: 'contingent' },
        { id: 12, permission_code: 'sports.create', permission_name: 'Create Sport', description: 'Create new sports', module: 'sports' },
        { id: 13, permission_code: 'sports.edit', permission_name: 'Edit Sport', description: 'Update sport details', module: 'sports' },
        { id: 14, permission_code: 'sports.delete', permission_name: 'Delete Sport', description: 'Delete sports', module: 'sports' },
        { id: 15, permission_code: 'results.create', permission_name: 'Create Results', description: 'Enter results', module: 'results' },
        { id: 16, permission_code: 'results.edit', permission_name: 'Edit Results', description: 'Update results', module: 'results' },
        { id: 17, permission_code: 'results.delete', permission_name: 'Delete Results', description: 'Delete results', module: 'results' },
        { id: 18, permission_code: 'settings.edit', permission_name: 'Edit Settings', description: 'Update system settings', module: 'settings' }
    ];
}

async function saveRole() {
    const form = document.getElementById('roleForm');
    const formData = new FormData(form);
    const roleId = document.getElementById('roleId').value;
    
    // Get selected permissions
    const permissions = Array.from(document.querySelectorAll('input[name="permissions[]"]:checked'))
        .map(cb => parseInt(cb.value));
    
    const data = {
        role_code: document.getElementById('roleCode').value.toUpperCase(),
        role_name: document.getElementById('roleName').value,
        description: document.getElementById('roleDescription').value,
        is_system_role: document.getElementById('isSystemRole').checked,
        permissions: permissions
    };
    
    try {
        const url = RBACManager.apiBase + 'roles.php?action=' + (roleId ? 'update&id=' + roleId : 'create');
        const method = roleId ? 'PUT' : 'POST';
        
        const response = await fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            if (typeof Toast !== 'undefined') {
                Toast.success(result.message || 'Role saved successfully');
            }
            
            // Close modal
            if (roleModalInstance) {
                roleModalInstance.hide();
            } else {
                const modal = getModalInstance(document.getElementById('roleModal'));
                if (modal) modal.hide();
            }
            
            // Clean up backdrop (cleanupModalBackdrops will handle it via event listener)
            
            // Reload roles list
            RBACManager.loadRoles();
        } else {
            await rbacNotify('error', result.message || 'Unable to save the role', 'Error');
        }
    } catch (error) {
        console.error('Error saving role:', error);
        await rbacNotify('error', 'System error. Please try again.', 'Error');
    }
}

async function editRole(id) {
    try {
        const response = await fetch(RBACManager.apiBase + 'roles.php?action=get&id=' + id);
        const result = await response.json();
        
        if (result.success && result.data) {
            const role = result.data;
            
            document.getElementById('roleId').value = role.id;
            document.getElementById('roleCode').value = role.role_code;
            document.getElementById('roleName').value = role.role_name;
            document.getElementById('roleDescription').value = role.description || '';
            document.getElementById('isSystemRole').checked = role.is_system_role == 1;
            
            document.getElementById('roleModalLabel').textContent = 'Edit Role';
            
            // Load permissions and check assigned ones
            await loadPermissionsForRole();
            
            // Wait a bit for permissions to load, then check assigned ones
            setTimeout(() => {
                if (role.permissions && role.permissions.length > 0) {
                    role.permissions.forEach(perm => {
                        const checkbox = document.getElementById('perm_' + perm.id);
                        if (checkbox) checkbox.checked = true;
                    });
                }
            }, 500);
            
            // CRITICAL: Close any existing modals first
            closeAllModals();
            
            // Hide loading overlay before showing modal
            hideLoadingOverlayForModal();
            
            // Get or create modal instance
            const modalElement = document.getElementById('roleModal');
            
            // Always get or create fresh instance
            if (roleModalInstance) {
                try {
                    roleModalInstance.dispose();
                } catch (e) {
                    // Ignore if already disposed
                }
            }
            
            // CRITICAL: Move modal to body level to avoid stacking context issues
            if (modalElement.parentElement !== document.body) {
                document.body.appendChild(modalElement);
            }
            
            roleModalInstance = createModalInstance(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
            
            // Hide loading overlay when modal is shown
            modalElement.addEventListener('show.coreui.modal', function() {
                hideLoadingOverlayForModal();
                fixModalZIndex();
            });
            
            // Clean up backdrop on hidden
            modalElement.addEventListener('hidden.coreui.modal', function() {
                cleanupModalBackdrops();
            });
            
            // Show modal
            roleModalInstance.show();
            
            // CRITICAL: Force z-index immediately and continuously
            modalElement.style.zIndex = '1060';
            modalElement.style.position = 'fixed';
            
            // Ensure navbar/header are below modal
            const navbar = document.querySelector('.navbar');
            const header = document.querySelector('.header, .header-sticky');
            if (navbar) navbar.style.zIndex = '1000';
            if (header) header.style.zIndex = '1000';
            
            setTimeout(function() {
                modalElement.style.zIndex = '1060';
                modalElement.style.position = 'fixed';
                if (navbar) navbar.style.zIndex = '1000';
                if (header) header.style.zIndex = '1000';
                fixModalZIndex();
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
        } else {
            await rbacNotify('error', 'Unable to load roles', 'Error');
        }
    } catch (error) {
        console.error('Error loading role:', error);
        await rbacNotify('error', 'System error. Please try again.', 'Error');
    }
}

async function deleteRole(id) {
    const ok = await rbacConfirm('Are you sure you want to delete this role? This action cannot be undone.', 'Delete Role', 'Delete');
    if (!ok) {
        return;
    }
    
    try {
        const response = await fetch(RBACManager.apiBase + 'roles.php?action=delete&id=' + id, {
            method: 'DELETE'
        });
        
        const result = await response.json();
        
        if (result.success) {
            if (typeof Toast !== 'undefined') {
                Toast.success(result.message || 'Role deleted successfully');
            }
            RBACManager.loadRoles();
        } else {
            await rbacNotify('error', result.message || 'Unable to delete the role', 'Error');
        }
    } catch (error) {
        console.error('Error deleting role:', error);
        await rbacNotify('error', 'System error. Please try again.', 'Error');
    }
}

async function loadUserRoles() {
    const userId = document.getElementById('userSelect').value;
    const container = document.getElementById('userRolesContainer');
    
    if (!userId) {
        container.innerHTML = '<p class="text-muted text-center">Select a user to view their roles</p>';
        return;
    }
    
    container.innerHTML = '<div class="text-center py-3"><div class="spinner-border text-primary" role="status"></div></div>';
    
    try {
        const response = await fetch(RBACManager.apiBase + 'users.php?action=get&id=' + userId);
        const result = await response.json();
        
        if (result.success && result.data) {
            const user = result.data;
            const currentRoles = user.roles || [];
            const currentRoleIds = currentRoles.map(r => r.id);
            
            // Load all available roles
            const rolesResponse = await fetch(RBACManager.apiBase + 'roles.php?action=list');
            const rolesData = await rolesResponse.json();
            const allRoles = rolesData.success ? rolesData.data : [];
            
            let html = `<div class="mb-3">
                <h6>Current Roles for: <strong>${user.full_name}</strong></h6>
                <p class="text-muted small">${user.email}</p>
            </div>`;
            
            if (allRoles.length === 0) {
                html += '<p class="text-muted">No roles available</p>';
            } else {
                html += '<div class="list-group">';
                allRoles.forEach(role => {
                    const isAssigned = currentRoleIds.includes(role.id);
                    html += `<div class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong>${role.role_name}</strong> <code class="small">${role.role_code}</code>
                            ${role.description ? `<br><small class="text-muted">${role.description}</small>` : ''}
                        </div>
                        <div>
                            ${isAssigned 
                                ? `<button class="btn btn-sm btn-danger" onclick="removeUserRole(${user.id}, ${role.id})">
                                    <i class="cil cil-minus"></i> Remove
                                </button>`
                                : `<button class="btn btn-sm btn-success" onclick="assignUserRole(${user.id}, ${role.id})">
                                    <i class="cil cil-plus"></i> Assign
                                </button>`
                            }
                        </div>
                    </div>`;
                });
                html += '</div>';
            }
            
            container.innerHTML = html;
        } else {
            container.innerHTML = '<div class="alert alert-danger">Unable to load user roles</div>';
        }
    } catch (error) {
        console.error('Error loading user roles:', error);
        container.innerHTML = '<div class="alert alert-danger">System error. Please try again.</div>';
    }
}

async function assignUserRole(userId, roleId) {
    try {
        const response = await fetch(RBACManager.apiBase + 'users.php?action=assign', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                user_id: userId,
                role_id: roleId
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            if (typeof Toast !== 'undefined') {
                Toast.success(result.message || 'Role assigned successfully');
            }
            loadUserRoles();
        } else {
            await rbacNotify('error', result.message || 'Unable to assign the role', 'Error');
        }
    } catch (error) {
        console.error('Error assigning role:', error);
        await rbacNotify('error', 'System error. Please try again.', 'Error');
    }
}

async function removeUserRole(userId, roleId) {
    const ok = await rbacConfirm('Are you sure you want to remove this role from the user?', 'Buang Role', 'Remove');
    if (!ok) {
        return;
    }
    
    try {
        const response = await fetch(
            RBACManager.apiBase + `users.php?action=remove&user_id=${userId}&role_id=${roleId}`,
            { method: 'DELETE' }
        );
        
        const result = await response.json();
        
        if (result.success) {
            if (typeof Toast !== 'undefined') {
                Toast.success(result.message || 'Role removed successfully');
            }
            loadUserRoles();
        } else {
            await rbacNotify('error', result.message || 'Unable to remove the role', 'Error');
        }
    } catch (error) {
        console.error('Error removing role:', error);
        await rbacNotify('error', 'System error. Please try again.', 'Error');
    }
}

async function showCreatePageRuleModal() {
    // CRITICAL: Close any existing modals first to prevent stacking
    closeAllModals();
    
    // Hide loading overlay before showing modal
    hideLoadingOverlayForModal();
    
    document.getElementById('pageRuleForm').reset();
    document.getElementById('pageRuleId').value = '';
    document.getElementById('pageRuleModalLabel').textContent = 'New Page Access Rule';
    document.getElementById('isPublic').checked = false;
    document.getElementById('requiresAuth').checked = true;
    toggleRoleSelection();
    
    await loadRolesForPageRule();
    
    // Get or create modal instance
    const modalElement = document.getElementById('pageRuleModal');
    
    // Always get or create fresh instance
    if (pageRuleModalInstance) {
        // Dispose old instance if exists
        try {
            pageRuleModalInstance.dispose();
        } catch (e) {
            // Ignore if already disposed
        }
    }
    
    // CRITICAL: Move modal to body level to avoid stacking context issues
    if (modalElement.parentElement !== document.body) {
        document.body.appendChild(modalElement);
    }
    
    pageRuleModalInstance = createModalInstance(modalElement, {
        backdrop: true,
        keyboard: true,
        focus: true
    });
    
    // Hide loading overlay when modal is shown
    modalElement.addEventListener('show.coreui.modal', function() {
        hideLoadingOverlayForModal();
        // Ensure z-index is correct
        fixModalZIndex();
    });
    
    // Clean up backdrop on hidden
    modalElement.addEventListener('hidden.coreui.modal', function() {
        cleanupModalBackdrops();
    });
    
    // Show modal
    pageRuleModalInstance.show();
    
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
        fixModalZIndex();
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

async function loadRolesForPageRule() {
    const container = document.getElementById('rolesCheckboxList');
    container.innerHTML = '<div class="text-center py-2"><div class="spinner-border spinner-border-sm text-primary"></div></div>';
    
    try {
        const response = await fetch(RBACManager.apiBase + 'roles.php?action=list');
        const result = await response.json();
        
        if (result.success && result.data) {
            const roles = result.data;
            
            if (roles.length === 0) {
                container.innerHTML = '<p class="text-muted text-center">No roles available</p>';
                return;
            }
            
            let html = '';
            roles.forEach(role => {
                html += `<div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="role_ids[]" 
                           value="${role.id}" id="role_${role.id}">
                    <label class="form-check-label" for="role_${role.id}">
                        <strong>${role.role_name}</strong> <code class="small">${role.role_code}</code>
                    </label>
                </div>`;
            });
            
            container.innerHTML = html;
        } else {
            container.innerHTML = '<p class="text-muted text-center">No roles available</p>';
        }
    } catch (error) {
        console.error('Error loading roles:', error);
        container.innerHTML = '<div class="alert alert-danger">Unable to load roles</div>';
    }
}

async function savePageRule() {
    const pageRuleId = document.getElementById('pageRuleId').value;
    const pagePath = document.getElementById('pagePath').value.trim();
    const isPublic = document.getElementById('isPublic').checked;
    const requiresAuth = document.getElementById('requiresAuth').checked;
    
    if (!pagePath) {
        await rbacNotify('error', 'Page path is required', 'Error');
        return;
    }
    
    // Get selected role IDs
    const roleIds = isPublic ? [] : Array.from(document.querySelectorAll('input[name="role_ids[]"]:checked'))
        .map(cb => parseInt(cb.value));
    
    const data = {
        page_path: pagePath,
        is_public: isPublic,
        requires_auth: requiresAuth,
        role_ids: roleIds
    };
    
    try {
        const url = RBACManager.apiBase + 'pages.php?action=' + (pageRuleId ? 'update&id=' + pageRuleId : 'create');
        // Production-safe: use POST for update/create (some Windows servers block PUT)
        const method = 'POST';
        
        const response = await fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            if (typeof Toast !== 'undefined') {
                Toast.success(result.message || 'Rule saved successfully');
            }
            
            // Close modal
            if (pageRuleModalInstance) {
                pageRuleModalInstance.hide();
            } else {
                const modal = getModalInstance(document.getElementById('pageRuleModal'));
                if (modal) modal.hide();
            }
            
            // Clean up backdrop (cleanupModalBackdrops will handle it via event listener)
            
            // Reload page rules list
            RBACManager.loadPageRules();
        } else {
            await rbacNotify('error', result.message || 'Unable to save the rule', 'Error');
        }
    } catch (error) {
        console.error('Error saving page rule:', error);
        await rbacNotify('error', 'System error. Please try again.', 'Error');
    }
}

async function editPageRule(id) {
    try {
        const response = await fetch(RBACManager.apiBase + 'pages.php?action=get&id=' + id);
        const result = await response.json();
        
        if (result.success && result.data) {
            const rule = result.data;
            
            document.getElementById('pageRuleId').value = rule.id;
            document.getElementById('pagePath').value = rule.page_path;
            document.getElementById('isPublic').checked = rule.is_public == 1;
            document.getElementById('requiresAuth').checked = rule.requires_auth == 1;
            
            document.getElementById('pageRuleModalLabel').textContent = 'Edit Page Access Rules';
            
            toggleRoleSelection();
            
            // Load roles and check assigned ones
            await loadRolesForPageRule();
            
            // Wait a bit for roles to load, then check assigned ones
            setTimeout(() => {
                if (rule.roles && rule.roles.length > 0) {
                    rule.roles.forEach(role => {
                        const checkbox = document.getElementById('role_' + role.id);
                        if (checkbox) checkbox.checked = true;
                    });
                }
            }, 500);
            
            // CRITICAL: Close any existing modals first
            closeAllModals();
            
            // Hide loading overlay before showing modal
            hideLoadingOverlayForModal();
            
            // Get or create modal instance
            const modalElement = document.getElementById('pageRuleModal');
            
            // Always get or create fresh instance
            if (pageRuleModalInstance) {
                try {
                    pageRuleModalInstance.dispose();
                } catch (e) {
                    // Ignore if already disposed
                }
            }
            
            // CRITICAL: Move modal to body level to avoid stacking context issues
            if (modalElement.parentElement !== document.body) {
                document.body.appendChild(modalElement);
            }
            
            pageRuleModalInstance = createModalInstance(modalElement, {
                backdrop: true,
                keyboard: true,
                focus: true
            });
            
            // Hide loading overlay when modal is shown
            modalElement.addEventListener('show.coreui.modal', function() {
                hideLoadingOverlayForModal();
                fixModalZIndex();
            });
            
            // Clean up backdrop on hidden
            modalElement.addEventListener('hidden.coreui.modal', function() {
                cleanupModalBackdrops();
            });
            
            // Show modal
            pageRuleModalInstance.show();
            
            // CRITICAL: Force z-index immediately and continuously
            modalElement.style.zIndex = '1060';
            modalElement.style.position = 'fixed';
            
            // Ensure navbar/header are below modal
            const navbar = document.querySelector('.navbar');
            const header = document.querySelector('.header, .header-sticky');
            if (navbar) navbar.style.zIndex = '1000';
            if (header) header.style.zIndex = '1000';
            
            setTimeout(function() {
                modalElement.style.zIndex = '1060';
                modalElement.style.position = 'fixed';
                if (navbar) navbar.style.zIndex = '1000';
                if (header) header.style.zIndex = '1000';
                fixModalZIndex();
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
        } else {
            await rbacNotify('error', 'Unable to load rules', 'Error');
        }
    } catch (error) {
        console.error('Error loading page rule:', error);
        await rbacNotify('error', 'System error. Please try again.', 'Error');
    }
}

async function deletePageRule(id) {
    const ok = await rbacConfirm('Are you sure you want to delete this rule? This action cannot be undone.', 'Delete Rule', 'Delete');
    if (!ok) {
        return;
    }
    
    try {
        const response = await fetch(RBACManager.apiBase + 'pages.php?action=delete&id=' + id, {
            // Production-safe: use POST for delete (some Windows servers block DELETE)
            method: 'POST'
        });
        
        const result = await response.json();
        
        if (result.success) {
            if (typeof Toast !== 'undefined') {
                Toast.success(result.message || 'Rule deleted successfully');
            }
            RBACManager.loadPageRules();
        } else {
            await rbacNotify('error', result.message || 'Unable to delete the rule', 'Error');
        }
    } catch (error) {
        console.error('Error deleting page rule:', error);
        await rbacNotify('error', 'System error. Please try again.', 'Error');
    }
}

function toggleRoleSelection() {
    const isPublic = document.getElementById('isPublic').checked;
    const container = document.getElementById('rolesSelectionContainer');
    container.style.display = isPublic ? 'none' : 'block';
    
    // Uncheck all roles if page is public
    if (isPublic) {
        document.querySelectorAll('input[name="role_ids[]"]').forEach(cb => cb.checked = false);
    }
}
</script>
