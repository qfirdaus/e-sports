<?php
/**
 * Footer Section (Light theme)
 */
?>
<!-- Footer Section Start -->
<footer class="footer-section mt-auto">
    <div class="container-fluid">
        <div class="footer-copyright text-center">
            <p class="text-body-light">
                <?php echo date('Y'); ?> &copy; <?php echo SITE_NAME; ?>. Powered by Universiti Pertahanan Nasional Malaysia (UPNM)
            </p>
        </div>
    </div>
</footer>
<!-- Footer Section End -->
<!-- Footer Section End -->

<!-- Small script: if page content is shorter than viewport, pin footer to bottom -->
<script>
    (function(){
        var footer = document.querySelector('.footer-section');
        var contentBody = document.querySelector('.content-body');
        // use the full content-body as the layout container so footer spans the full content area
        var layoutContainer = contentBody || document.querySelector('.main-wrapper');
        var innerContainer = contentBody ? contentBody.querySelector('.container-fluid') : null;
        // Global container reference used by updateStickyFooter; declare to avoid ReferenceError
        var container = null;

        function applyFixedLayout(pin){
            try{
                if (!footer || !container || !contentBody) return;
                if (pin) {
                    document.body.classList.add('sticky-footer-fixed');
                    // compute layout container rect relative to viewport so footer spans full content area
                    var rect = layoutContainer.getBoundingClientRect();
                    footer.style.boxSizing = 'border-box';
                    // set left and width so footer covers the same horizontal space as content-body
                    footer.style.left = Math.max(0, rect.left) + 'px';
                    footer.style.width = rect.width + 'px';
                    // clear right to avoid conflicts
                    footer.style.right = '';
                    // make inner container full width and remove gutters
                    try{
                        var fc = footer.querySelector('.container-fluid');
                        if (fc) {
                            fc.style.maxWidth = 'none';
                            fc.style.width = '100%';
                            fc.style.paddingLeft = '0';
                            fc.style.paddingRight = '0';
                        }
                    }catch(e){}
                    // ensure content has space for fixed footer
                    contentBody.style.paddingBottom = footer.offsetHeight + 'px';
                } else {
                    document.body.classList.remove('sticky-footer-fixed');
                    footer.style.left = '';
                    footer.style.width = '';
                    footer.style.boxSizing = '';
                    footer.style.right = '';
                    try{ var fc = footer.querySelector('.container-fluid'); if(fc) { fc.style.maxWidth=''; fc.style.width = ''; fc.style.paddingLeft=''; fc.style.paddingRight=''; } }catch(e){}
                    contentBody.style.paddingBottom = '';
                }
            } catch(e) { console && console.warn && console.warn(e); }
        }

        function updateStickyFooter(){
            try{
                if (!footer || !container) return;
                var docHeight = Math.max(document.documentElement.scrollHeight, document.body.scrollHeight);
                var viewH = window.innerHeight || document.documentElement.clientHeight;
                var pin = docHeight <= viewH + 2;
                applyFixedLayout(pin);
            } catch(e) { console && console.warn && console.warn(e); }
        }

        // Recompute when sidebar visibility changes. Observe side-header class mutations.
        function watchSidebar(){
            try{
                var side = document.querySelector('.side-header');
                if (!side) return;
                var mo = new MutationObserver(function(){ setTimeout(updateStickyFooter, 80); });
                mo.observe(side, { attributes: true, attributeFilter: ['class'] });
            }catch(e){ }
        }

        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function(){ container = contentBody ? contentBody.querySelector('.container-fluid') : document.querySelector('.container-fluid'); watchSidebar(); updateStickyFooter(); });
        else { container = contentBody ? contentBody.querySelector('.container-fluid') : document.querySelector('.container-fluid'); watchSidebar(); updateStickyFooter(); }
        window.addEventListener('resize', updateStickyFooter);
        // also update after a short delay for pages that load content async
        setTimeout(updateStickyFooter, 600);
    })();
</script>

<!-- JS -->
<script src="<?php echo asset('light/js/vendor/modernizr-3.6.0.min.js'); ?>"></script>
<script src="<?php echo asset('light/js/vendor/jquery-3.3.1.min.js'); ?>"></script>
<script src="<?php echo asset('light/js/vendor/popper.min.js'); ?>"></script>
<script src="<?php echo asset('light/js/vendor/bootstrap.min.js'); ?>"></script>
<script src="<?php echo asset('light/js/plugins/perfect-scrollbar.min.js'); ?>"></script>
<script src="<?php echo asset('light/js/plugins/tippy4.min.js.js'); ?>"></script>
<script src="<?php echo asset('light/js/main.js'); ?>"></script>

<?php
$idleTimeoutEnabled = false;
try {
    if (function_exists('getAuth')) {
        $authInstance = getAuth();
        $idleTimeoutEnabled = $authInstance && $authInstance->isLoggedIn();
    } elseif (class_exists('Session')) {
        $idleTimeoutEnabled = Session::has('user_id');
    }
} catch (Exception $e) {
    $idleTimeoutEnabled = false;
}
?>
<?php if ($idleTimeoutEnabled): ?>
<script>
(function(){
    // PRODUCTION CONFIG:
    // - Warning after 10 minutes idle
    // - Force logout 1 minute after warning (if user does not click Stay Connected)
    var warningAfterMs = 10 * 60 * 1000;
    var forceLogoutAfterMs = 60 * 1000;
    var logoutUrl = <?php echo json_encode(url('auth/logout.php')); ?>;

    var lastActivityAt = Date.now();
    var warningShown = false;
    var tickTimer = null;
    var countdownTimer = null;

    function markActivity(){
        // If warning state is set but modal is not visible (render/load edge case),
        // recover to normal active state instead of forcing logout unexpectedly.
        if (warningShown) {
            var swalVisible = false;
            try { swalVisible = !!(window.Swal && window.Swal.isVisible && window.Swal.isVisible()); } catch(e) {}
            if (!swalVisible) {
                stayConnected();
            } else {
                return; // require explicit click when warning is visibly shown
            }
        }
        lastActivityAt = Date.now();
    }

    function attachActivityListeners(){
        ['mousemove','mousedown','keydown','scroll','touchstart','click'].forEach(function(evt){
            window.addEventListener(evt, markActivity, { passive: true });
        });
    }

    function startTicker(){
        if (tickTimer) clearInterval(tickTimer);
        tickTimer = setInterval(function(){
            if (warningShown) return;
            if ((Date.now() - lastActivityAt) >= warningAfterMs) {
                showWarning();
            }
        }, 1000);
    }

    function goLogout(){
        try { if (countdownTimer) clearInterval(countdownTimer); } catch(e){}
        window.location.href = logoutUrl + '?reason=idle';
    }

    function ensureSwal(cb){
        if (window.Swal && typeof window.Swal.fire === 'function') return cb(true);
        var js = document.createElement('script');
        js.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js';
        js.onload = function(){ cb(!!(window.Swal && window.Swal.fire)); };
        js.onerror = function(){ cb(false); };
        document.head.appendChild(js);
    }

    function stayConnected(){
        warningShown = false;
        lastActivityAt = Date.now();
        try { if (countdownTimer) clearInterval(countdownTimer); } catch(e){}
        try {
            if (window.Swal && window.Swal.isVisible && window.Swal.isVisible()) {
                window.Swal.close();
            }
        } catch(e){}
    }

    function showWarning(){
        warningShown = true;
        var secondsLeft = Math.max(1, Math.floor(forceLogoutAfterMs / 1000));

        ensureSwal(function(ok){
            if (!ok) {
                // Fallback if SweetAlert cannot load
                if (confirm('Your session is about to expire. Click OK to stay signed in or Cancel to sign out.')) {
                    stayConnected();
                } else {
                    goLogout();
                }
                return;
            }

            window.Swal.fire({
                icon: 'warning',
                title: 'Your Session Is About to Expire',
                html: 'You have been inactive. The system will sign you out in <b id="idle-countdown">' + secondsLeft + '</b> saat.',
                showCancelButton: true,
                confirmButtonText: 'Stay Connected',
                cancelButtonText: 'Logout',
                allowOutsideClick: false,
                allowEscapeKey: false,
                didOpen: function(){
                    if (countdownTimer) clearInterval(countdownTimer);
                    countdownTimer = setInterval(function(){
                        secondsLeft--;
                        if (secondsLeft <= 0) {
                            clearInterval(countdownTimer);
                            goLogout();
                            return;
                        }
                        var cEl = document.getElementById('idle-countdown');
                        if (cEl) cEl.textContent = String(Math.max(0, secondsLeft));
                    }, 1000);
                }
            }).then(function(result){
                if (result.isConfirmed) {
                    stayConnected();
                    return;
                }
                goLogout();
            });
        });
    }

    attachActivityListeners();
    startTicker();
})();
</script>
<?php endif; ?>
</body>
</html>
