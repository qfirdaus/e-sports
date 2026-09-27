<?php
/**
 * Login Modal Component
 * Modal-based authentication form
 */
?>
<!-- Login Modal -->
<div class="modal fade" id="loginModal" tabindex="-1" aria-labelledby="loginModalLabel" aria-hidden="true" data-coreui-backdrop="static" data-coreui-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="loginModalLabel">
                    <i class="cil cil-account-logout me-2"></i>Sign In
                </h5>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="text-center mb-4">
                    <img src="<?php echo logo(LOGO_HEADER); ?>" alt="<?php echo SITE_NAME; ?>" height="60" class="mb-3">
                    <h6><?php echo SITE_NAME; ?></h6>
                    <p class="text-muted small mb-0">Championship Management System</p>
                </div>
                
                <!-- Error Alert -->
                <div class="alert alert-danger alert-dismissible fade d-none" id="loginErrorAlert" role="alert">
                    <i class="cil cil-warning me-2"></i>
                    <span id="loginErrorMessage"></span>
                    <button type="button" class="btn-close" data-coreui-dismiss="alert"></button>
                </div>
                
                <!-- Success Alert -->
                <div class="alert alert-success alert-dismissible fade d-none" id="loginSuccessAlert" role="alert">
                    <i class="cil cil-check-circle me-2"></i>
                    <span>Signed in successfully! Redirecting...</span>
                </div>
                
                <!-- Login Form -->
                <form id="loginModalForm" method="POST">
                    <input type="hidden" name="action" value="login">
                    <input type="hidden" name="return_url" id="loginReturnUrl" value="">
                    
                    <div class="mb-3">
                        <label for="modalEmail" class="form-label">Email <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="cil cil-user"></i>
                            </span>
                            <input type="email" class="form-control" id="modalEmail" name="email" autocomplete="email" 
                                   placeholder="Enter your email address" required autofocus>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="modalPassword" class="form-label">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">
                                <i class="cil cil-lock-locked"></i>
                            </span>
                            <input type="password" class="form-control" id="modalPassword" name="password" autocomplete="current-password" 
                                   placeholder="Enter your password" required>
                            <button class="btn btn-outline-secondary" type="button" id="modalTogglePassword">
                                <i class="cil cil-eye" id="modalEyeIcon"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="modalRememberMe" name="remember_me">
                        <label class="form-check-label" for="modalRememberMe">
                            Remember me
                        </label>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg" id="loginModalSubmitBtn">
                            <i class="cil cil-account-logout me-2"></i>
                            <span id="loginModalBtnText">Sign In</span>
                        </button>
                    </div>
                </form>
                
                <div class="mt-3 text-center">
                    <small class="text-muted">
                        <i class="cil cil-info me-1"></i>
                        Phase 1: Administrator sign-in only
                    </small>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Login Modal Management
const LoginModal = {
    modal: null,
    form: null,
    
    init: function() {
        this.modal = document.getElementById('loginModal');
        this.form = document.getElementById('loginModalForm');
        
        if (!this.modal || !this.form) {
            return;
        }
        
        // Initialize CoreUI modal
        if (typeof coreui !== 'undefined' && coreui.Modal) {
            this.modalInstance = new coreui.Modal(this.modal);
        } else if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            this.modalInstance = new bootstrap.Modal(this.modal);
        }
        
        // Setup form submission
        this.setupForm();
        
        // Setup password toggle
        this.setupPasswordToggle();
        
        // Setup modal events
        this.setupModalEvents();
    },
    
    show: function(returnUrl = null) {
        if (!this.modal) this.init();
        if (!this.modal) return;
        
        // Reset form
        this.resetForm();
        
        // Set return URL if provided
        if (returnUrl) {
            document.getElementById('loginReturnUrl').value = returnUrl;
        } else {
            document.getElementById('loginReturnUrl').value = window.location.href;
        }
        
        // Show modal
        if (this.modalInstance) {
            this.modalInstance.show();
        } else {
            this.modal.classList.add('show');
            this.modal.style.display = 'block';
            document.body.classList.add('modal-open');
        }
        
        // Focus on email field
        setTimeout(() => {
            document.getElementById('modalEmail').focus();
        }, 300);
    },
    
    hide: function() {
        if (!this.modal) return;
        
        if (this.modalInstance) {
            this.modalInstance.hide();
        } else {
            this.modal.classList.remove('show');
            this.modal.style.display = 'none';
            document.body.classList.remove('modal-open');
        }
    },
    
    resetForm: function() {
        if (this.form) {
            this.form.reset();
        }
        
        // Hide alerts
        document.getElementById('loginErrorAlert').classList.add('d-none');
        document.getElementById('loginSuccessAlert').classList.add('d-none');
        
        // Reset button
        const submitBtn = document.getElementById('loginModalSubmitBtn');
        const btnText = document.getElementById('loginModalBtnText');
        if (submitBtn && btnText) {
            submitBtn.disabled = false;
            btnText.textContent = 'Sign In';
        }
    },
    
    setupForm: function() {
        if (!this.form) return;
        
        this.form.addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleSubmit();
        });
    },
    
    setupPasswordToggle: function() {
        const toggleBtn = document.getElementById('modalTogglePassword');
        const passwordInput = document.getElementById('modalPassword');
        const eyeIcon = document.getElementById('modalEyeIcon');
        
        if (toggleBtn && passwordInput && eyeIcon) {
            toggleBtn.addEventListener('click', () => {
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    eyeIcon.classList.remove('cil-eye');
                    eyeIcon.classList.add('cil-eye-slash');
                } else {
                    passwordInput.type = 'password';
                    eyeIcon.classList.remove('cil-eye-slash');
                    eyeIcon.classList.add('cil-eye');
                }
            });
        }
    },
    
    setupModalEvents: function() {
        if (!this.modal) return;
        
        // Clear form when modal is hidden
        this.modal.addEventListener('hidden.coreui.modal', () => {
            this.resetForm();
        });
        
        this.modal.addEventListener('hidden.bs.modal', () => {
            this.resetForm();
        });
    },
    
    handleSubmit: function() {
        const formData = new FormData(this.form);
        const submitBtn = document.getElementById('loginModalSubmitBtn');
        const btnText = document.getElementById('loginModalBtnText');
        const errorAlert = document.getElementById('loginErrorAlert');
        const errorMessage = document.getElementById('loginErrorMessage');
        
        // Hide previous errors
        errorAlert.classList.add('d-none');
        
        // Show loading state
        if (submitBtn && btnText) {
            submitBtn.disabled = true;
            btnText.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Mengesahkan...';
        }
        
        // Show loading overlay
        if (typeof LoadingOverlay !== 'undefined') {
            LoadingOverlay.show('Signing in...');
        }
        
        // Submit via AJAX
        fetch('<?php echo url('auth/ajax-login.php'); ?>', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success message
                document.getElementById('loginSuccessAlert').classList.remove('d-none');
                
                // Show toast
                if (typeof Toast !== 'undefined') {
                    Toast.success('Signed in successfully! Welcome back.', 2000);
                }
                
                // Hide loading overlay
                if (typeof LoadingOverlay !== 'undefined') {
                    LoadingOverlay.hide();
                }
                
                // Redirect after short delay
                setTimeout(() => {
                    const returnUrl = document.getElementById('loginReturnUrl').value || '<?php echo url('pages/dashboard.php'); ?>';
                    window.location.href = returnUrl;
                }, 500);
            } else {
                // Show error
                errorMessage.textContent = data.message || 'Sign-in failed. Please try again.';
                errorAlert.classList.remove('d-none');
                
                // Reset button
                if (submitBtn && btnText) {
                    submitBtn.disabled = false;
                    btnText.textContent = 'Sign In';
                }
                
                // Hide loading overlay
                if (typeof LoadingOverlay !== 'undefined') {
                    LoadingOverlay.hide();
                }
                
                // Focus on email field
                document.getElementById('modalEmail').focus();
            }
        })
        .catch(error => {
            errorMessage.textContent = 'System error. Please try again.';
            errorAlert.classList.remove('d-none');
            
            // Reset button
            if (submitBtn && btnText) {
                submitBtn.disabled = false;
                btnText.textContent = 'Sign In';
            }
            
            // Hide loading overlay
            if (typeof LoadingOverlay !== 'undefined') {
                LoadingOverlay.hide();
            }
        });
    }
};

// Make LoginModal globally available
window.LoginModal = LoginModal;

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', function() {
    LoginModal.init();
    
    // Auto-show modal if needed (e.g., from URL parameter)
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('show_login') === '1') {
        LoginModal.show();
    }
});
</script>
