/**
 * Custom JavaScript for CoreUI Template
 * Add your custom scripts here
 */

// Loading Overlay Management
const LoadingOverlay = {
    overlay: null,
    isVisible: false,
    
    init: function() {
        this.overlay = document.getElementById('loadingOverlay');
        if (!this.overlay) {
            return;
        }
        // Ensure it's hidden by default
        this.overlay.style.display = 'none';
        this.isVisible = false;
    },
    
    show: function(message = 'Loading...') {
        if (!this.overlay) this.init();
        if (!this.overlay || this.isVisible) return;
        
        // CRITICAL: Don't show if any modal is open
        const hasOpenModal = document.querySelector('.modal.show') || 
                            document.querySelector('.modal-backdrop.show') ||
                            document.body.classList.contains('modal-open');
        if (hasOpenModal) {
            return; // Don't show loading overlay if modal is active
        }
        
        // Update message if provided
        const messageEl = this.overlay.querySelector('p');
        if (messageEl && message) {
            messageEl.textContent = message;
        }
        
        // Show overlay
        this.overlay.style.display = 'flex';
        this.overlay.classList.remove('hide');
        this.overlay.classList.add('show');
        this.overlay.style.zIndex = '1030';
        this.overlay.style.pointerEvents = 'auto';
        document.body.classList.add('page-loading');
        this.isVisible = true;
    },
    
    hide: function() {
        if (!this.overlay) this.init();
        if (!this.overlay) return;
        
        // Hide overlay immediately (no animation delay for modals)
        this.overlay.classList.remove('show');
        this.overlay.classList.add('hide');
        this.overlay.style.display = 'none';
        this.overlay.style.zIndex = '-1';
        this.overlay.style.pointerEvents = 'none';
        this.overlay.style.opacity = '0';
        this.overlay.style.visibility = 'hidden';
        document.body.classList.remove('page-loading');
        this.isVisible = false;
        
        // Remove hide class after animation
        setTimeout(() => {
            this.overlay.classList.remove('hide');
            // Ensure it stays hidden
            this.overlay.style.display = 'none';
            this.overlay.style.zIndex = '-1';
            this.overlay.style.pointerEvents = 'none';
        }, 300);
    },
    
    // Force hide immediately (for modal scenarios)
    forceHide: function() {
        if (!this.overlay) this.init();
        if (!this.overlay) return;
        
        // Immediately hide without animation
        this.overlay.style.display = 'none';
        this.overlay.style.zIndex = '-1';
        this.overlay.style.pointerEvents = 'none';
        this.overlay.style.opacity = '0';
        this.overlay.style.visibility = 'hidden';
        this.overlay.classList.remove('show', 'hide');
        document.body.classList.remove('page-loading');
        this.isVisible = false;
    }
};

// Check page loading state immediately
(function() {
    // If page is already loaded, ensure overlay is hidden
    if (document.readyState === 'complete') {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            overlay.style.display = 'none';
        }
    } else if (document.readyState === 'loading') {
        // Page is still loading, show overlay
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            overlay.style.display = 'flex';
            overlay.classList.add('show');
            document.body.classList.add('page-loading');
        }
    }
})();

// Initialize CoreUI components when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Initialize loading overlay
    LoadingOverlay.init();
    
    // Check if page is already loaded
    if (document.readyState === 'complete') {
        // Page already loaded, ensure overlay is hidden
        LoadingOverlay.hide();
    } else {
        // Page is still loading, show overlay
        LoadingOverlay.show('Loading halaman...');
        
        // Hide loading when page is fully loaded
        window.addEventListener('load', function() {
            setTimeout(function() {
                LoadingOverlay.hide();
            }, 200); // Small delay for smooth transition
        });
    }
    
    // Navbar collapse and dropdowns are automatically initialized by CoreUI

    // Automatically reset event banner on page load (always show banner)
    resetEventBanner();

    // Add any custom initialization here
    initializeCustomComponents();
});

// Show loading on page navigation (only when actually navigating away)
window.addEventListener('beforeunload', function() {
    LoadingOverlay.show('Loading halaman...');
});

// Make LoadingOverlay globally available
window.LoadingOverlay = LoadingOverlay;

/**
 * Check if event banner was previously dismissed
 * NOTE: This function is kept for reference but banner now always shows on page load
 */
function checkEventBannerDismissed() {
    // Banner is now always shown on page load
    // This function is kept for potential future use
    const bannerContainer = document.getElementById('eventBannerContainer');
    if (bannerContainer) {
        bannerContainer.style.display = 'block';
    }
}

/**
 * Reset event banner - automatically called on page load
 * This ensures banner always shows after page refresh
 */
function resetEventBanner() {
    // Clear any dismissal flags
    localStorage.removeItem('eventBannerDismissed');
    localStorage.removeItem('eventBannerDismissedDate');
    
    const bannerContainer = document.getElementById('eventBannerContainer');
    if (bannerContainer) {
        bannerContainer.style.display = 'block';
    }
}

// Make resetEventBanner available globally for manual reset if needed
window.resetEventBanner = resetEventBanner;

/**
 * Initialize custom components
 */
function initializeCustomComponents() {
    // Example: Add click handlers for custom elements
    const customButtons = document.querySelectorAll('[data-custom-action]');
    customButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const action = this.getAttribute('data-custom-action');
            console.log('Custom action:', action);
            // Add your custom logic here
        });
    });
}

/**
 * Toast Notification System - Custom Implementation
 * Works independently without Bootstrap/CoreUI Toast API
 */
const Toast = {
    container: null,
    
    init: function() {
        // Create toast container if it doesn't exist
        if (!document.getElementById('toastContainer')) {
            this.container = document.createElement('div');
            this.container.id = 'toastContainer';
            this.container.className = 'toast-container-custom';
            document.body.appendChild(this.container);
        } else {
            this.container = document.getElementById('toastContainer');
        }
    },
    
    show: function(message, type = 'info', duration = 3000) {
        this.init();
        
        // Create toast element
        const toastId = 'toast-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
        const toast = document.createElement('div');
        toast.id = toastId;
        toast.className = 'toast-custom toast-custom-' + type;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.setAttribute('aria-atomic', 'true');
        
        const icon = this.getIcon(type);
        const bgColor = this.getColor(type);
        
        toast.innerHTML = `
            <div class="toast-custom-content">
                <div class="toast-custom-icon">
                    <i class="${icon}"></i>
                </div>
                <div class="toast-custom-message">
                    ${message}
                </div>
                <button type="button" class="toast-custom-close" onclick="this.parentElement.parentElement.remove()" aria-label="Close">
                    <i class="cil cil-x"></i>
                </button>
            </div>
        `;
        
        // Set background color
        toast.style.backgroundColor = bgColor;
        
        this.container.appendChild(toast);
        
        // Trigger animation by adding show class
        setTimeout(function() {
            toast.classList.add('toast-custom-show');
        }, 10);
        
        // Auto-hide after duration
        setTimeout(function() {
            toast.classList.remove('toast-custom-show');
            toast.classList.add('toast-custom-hide');
            setTimeout(function() {
                if (toast.parentNode) {
                    toast.remove();
                }
            }, 300);
        }, duration);
        
        return toast;
    },
    
    getColor: function(type) {
        const colors = {
            'success': '#28a745',
            'error': '#dc3545',
            'warning': '#ffc107',
            'info': '#17a2b8',
            'primary': '#007bff'
        };
        return colors[type] || colors['info'];
    },
    
    getIcon: function(type) {
        const icons = {
            'success': 'cil cil-check-circle',
            'error': 'cil cil-warning',
            'warning': 'cil cil-warning',
            'info': 'cil cil-info',
            'primary': 'cil cil-info'
        };
        return icons[type] || 'cil cil-info';
    },
    
    success: function(message, duration) {
        return this.show(message, 'success', duration);
    },
    
    error: function(message, duration) {
        return this.show(message, 'error', duration);
    },
    
    warning: function(message, duration) {
        return this.show(message, 'warning', duration);
    },
    
    info: function(message, duration) {
        return this.show(message, 'info', duration);
    }
};

// Make Toast globally available
window.Toast = Toast;

/**
 * Show toast notification (legacy function for compatibility)
 */
function showToast(message, type = 'info') {
    return Toast.show(message, type);
}

/**
 * Handle form submissions with AJAX (example)
 */
function handleAjaxForm(formSelector, successCallback) {
    const form = document.querySelector(formSelector);
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            // Add your AJAX form submission logic here
            if (successCallback) {
                successCallback();
            }
        });
    }
}

/**
 * Intercept protected links and actions to show login modal if needed
 */
document.addEventListener('DOMContentLoaded', function() {
    // Intercept clicks on protected links
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a[data-requires-auth]');
        if (link) {
            e.preventDefault();
            const href = link.getAttribute('href');
            
            // Check if user is logged in (you can check via a data attribute or API call)
            const isLoggedIn = document.body.getAttribute('data-user-logged-in') === 'true';
            
            if (!isLoggedIn) {
                // Show login modal with return URL
                if (typeof LoginModal !== 'undefined') {
                    LoginModal.show(href);
                }
            } else {
                // User is logged in, proceed normally
                window.location.href = href;
            }
        }
    });
    
    // Intercept form submissions that require auth
    document.addEventListener('submit', function(e) {
        const form = e.target.closest('form[data-requires-auth]');
        if (form) {
            const isLoggedIn = document.body.getAttribute('data-user-logged-in') === 'true';
            
            if (!isLoggedIn) {
                e.preventDefault();
                if (typeof LoginModal !== 'undefined') {
                    LoginModal.show(window.location.href);
                }
            }
        }
    });
});
