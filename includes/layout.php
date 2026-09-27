<?php
/**
 * Base Layout Template (Light theme)
 */

// Initialize auth and RBAC for all pages BEFORE any output
if (!defined('SKIP_AUTH_CHECK')) {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../config/auth.php';
    require_once __DIR__ . '/../config/rbac.php';

    if (session_status() === PHP_SESSION_NONE) {
        Session::start();
    }
    $auth = getAuth();
    $rbac = getRBAC();

    $scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? 'index.php'));
    $baseUrl = '/' . trim(str_replace('\\', '/', (string)BASE_URL), '/');
    if ($baseUrl === '//') {
        $baseUrl = '/';
    }

    if ($baseUrl !== '/' && strpos($scriptPath, $baseUrl . '/') === 0) {
        $relativePath = substr($scriptPath, strlen($baseUrl) + 1);
    } else {
        $relativePath = ltrim($scriptPath, '/');
    }

    if (strpos($relativePath, 'app/') === 0) {
        $relativePath = substr($relativePath, 4);
    }

    if ($relativePath === '' || $relativePath === '/' || $relativePath === 'index.php') {
        $relativePath = 'index.php';
    }

    $rbac->requirePageAccess($relativePath);
}

// Early inlined safe-storage shim: runs before any header scripts to prevent
// "Access to storage is not allowed from this context." errors thrown
// by vendor scripts that run immediately when the page loads.
?>
<script>
    (function(){
        try {
            function makeSafeStorage(){ return { getItem:function(){return null}, setItem:function(){}, removeItem:function(){}, clear:function(){} }; }
            try { if (!window.localStorage || typeof window.localStorage.getItem !== 'function') { try{ Object.defineProperty(window,'localStorage',{value:makeSafeStorage(),configurable:true}); }catch(e){ window.localStorage = makeSafeStorage(); } } else { try{ window.localStorage.getItem('__ls_test'); }catch(e){ try{ Object.defineProperty(window,'localStorage',{value:makeSafeStorage(),configurable:true}); }catch(_){ window.localStorage = makeSafeStorage(); } } }
            } catch(e) { try{ Object.defineProperty(window,'localStorage',{value:makeSafeStorage(),configurable:true}); }catch(_){ window.localStorage = makeSafeStorage(); } }
            try { if (!window.sessionStorage || typeof window.sessionStorage.getItem !== 'function') { try{ Object.defineProperty(window,'sessionStorage',{value:makeSafeStorage(),configurable:true}); }catch(e){ window.sessionStorage = makeSafeStorage(); } } else { try{ window.sessionStorage.getItem('__ss_test'); }catch(e){ try{ Object.defineProperty(window,'sessionStorage',{value:makeSafeStorage(),configurable:true}); }catch(_){ window.sessionStorage = makeSafeStorage(); } } }
            } catch(e) { try{ Object.defineProperty(window,'sessionStorage',{value:makeSafeStorage(),configurable:true}); }catch(_){ window.sessionStorage = makeSafeStorage(); } }
            try { if (!window.caches) { try{ Object.defineProperty(window,'caches',{value:{open:function(){return Promise.reject(new Error('caches unavailable'))},match:function(){return Promise.reject(new Error('caches unavailable'))},keys:function(){return Promise.resolve([])}},configurable:true}); }catch(e){ window.caches = {open:function(){return Promise.reject(new Error('caches unavailable'))}}; } }
            } catch(e) { try{ Object.defineProperty(window,'caches',{value:{open:function(){return Promise.reject(new Error('caches unavailable'))}},configurable:true}); }catch(_){ window.caches = {open:function(){return Promise.reject(new Error('caches unavailable'))}}; } }
            // navigator.storage shim: make persist/estimate safe and non-throwing
            try {
                if (!navigator.storage || typeof navigator.storage.persist !== 'function') {
                    try {
                        var safeNavStorage = {
                            persist: function(){ return Promise.resolve(false); },
                            estimate: function(){ return Promise.resolve({usage:0, quota:0}); }
                        };
                        try{ Object.defineProperty(navigator, 'storage', { value: safeNavStorage, configurable: true }); }catch(e){ navigator.storage = safeNavStorage; }
                    } catch(e) { try{ Object.defineProperty(navigator, 'storage', { value: { persist: function(){return Promise.resolve(false);}, estimate: function(){return Promise.resolve({usage:0,quota:0});} }, configurable: true }); }catch(_){ navigator.storage = { persist: function(){return Promise.resolve(false);}, estimate: function(){return Promise.resolve({usage:0,quota:0});} }; } }
                } else {
                    // ensure methods themselves don't throw when called
                    try { var _p = navigator.storage.persist; var _e = navigator.storage.estimate; } catch(err) {
                        try{ Object.defineProperty(navigator, 'storage', { value: { persist: function(){return Promise.resolve(false);}, estimate: function(){return Promise.resolve({usage:0,quota:0});} }, configurable: true }); }catch(_){ navigator.storage = { persist: function(){return Promise.resolve(false);}, estimate: function(){return Promise.resolve({usage:0,quota:0});} }; }
                    }
                }
            } catch(e) { /* ignore */ }
        } catch(e) { /* ignore overall shim errors */ }
    })();
    // Global catcher for unhandled promise rejections to capture stack/origin
    try {
        window.addEventListener('unhandledrejection', function(evt){
            try {
                console.error('UnhandledPromiseRejection captured by shim:', evt.reason);
                if (evt.reason && evt.reason.stack) console.error(evt.reason.stack);
                if (evt.promise) console.error(evt.promise);
            } catch(e) { console.error('Error logging unhandledrejection', e); }
        });
    } catch(e) { /* ignore */ }
</script>
<?php
require_once __DIR__ . '/header.php';
require_once __DIR__ . '/sidebar.php';
?>

<!-- Content Body Start -->
<div class="content-body flex-grow-1">
    <div class="container-fluid">
        <?php echo $content; ?>
        <?php require_once __DIR__ . '/footer.php'; ?>
    </div>
</div>
<!-- Content Body End -->

</div>
