<?php
require_once __DIR__ . '/../config.php';

// Get current user and RBAC if authenticated
$currentUser = null;
$rbac = null;

if (!defined('SKIP_AUTH_CHECK')) {
    if (file_exists(__DIR__ . '/../config/database.php') && 
        file_exists(__DIR__ . '/../config/auth.php') && 
        file_exists(__DIR__ . '/../config/rbac.php')) {
        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/../config/auth.php';
        require_once __DIR__ . '/../config/rbac.php';
        // Session should already be started in config.php, but ensure it's started
        if (session_status() === PHP_SESSION_NONE) {
            Session::start();
        }
        $auth = getAuth();
        $rbac = getRBAC();
        if ($auth->isLoggedIn()) {
            $currentUser = $auth->getUser();
        }
    }
}
?>
<header class="header header-sticky mb-4">
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark w-100">
        <div class="w-100 px-3">
                    <a class="navbar-brand" href="<?php echo url('pages/dashboard.php'); ?>">
                <img src="<?php echo logo(LOGO_HEADER); ?>" alt="<?php echo SITE_NAME; ?>" class="navbar-logo" height="40">
                <span class="ms-2 d-none d-md-inline"><strong><?php echo SITE_NAME; ?></strong></span>
            </a>
            <button class="navbar-toggler" type="button" data-coreui-toggle="collapse" data-coreui-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php 
                    // Centralized navigation filtering using RBAC
                    // This ensures navigation items are only shown if user has access
                    $displayNavItems = [];
                    if (isset($rbac)) {
                        foreach ($nav_items as $item) {
                            // Check if navigation item should be visible based on pageAccessRules
                            if ($rbac->isNavItemVisible($item['url'])) {
                                $displayNavItems[] = $item;
                            }
                        }
                    } else {
                        // If RBAC not available, show all items (fallback)
                        $displayNavItems = $nav_items;
                    }
                    
                    // Display filtered navigation items
                    foreach ($displayNavItems as $item): 
                        // Check if current page matches
                        $currentPage = basename($_SERVER['PHP_SELF']);
                        $itemPage = basename($item['url']);
                        $isActive = ($currentPage === $itemPage) || ($item['active'] ?? false);
                    ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $isActive ? 'active' : ''; ?>" 
                               href="<?php echo url($item['url']); ?>"
                               <?php if (!isset($rbac) || !$rbac->canAccessPage($item['url'])): ?>
                               data-requires-auth="true"
                               <?php endif; ?>>
                                <i class="nav-icon cil <?php echo $item['icon']; ?> me-1"></i>
                                <?php echo $item['title']; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <ul class="navbar-nav">
                    <?php if ($currentUser): ?>
                        <!-- Authenticated User Menu -->
                        <li class="nav-item">
                            <a class="nav-link" href="#">
                                <i class="icon icon-lg cil cil-bell"></i>
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#">
                                <i class="icon icon-lg cil cil-envelope-open"></i>
                            </a>
                        </li>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-coreui-toggle="dropdown" aria-expanded="false">
                                <div class="avatar avatar-sm">
                                    <?php
                                    $userName = $currentUser['full_name'] ?? 'User';
                                    $userInitials = strtoupper(substr($userName, 0, 2));
                                    ?>
                                    <div class="avatar-img bg-primary text-white d-flex align-items-center justify-content-center" style="font-weight: bold;">
                                        <?php echo htmlspecialchars($userInitials); ?>
                                    </div>
                                </div>
                            </a>
                            <div class="dropdown-menu dropdown-menu-end">
                                <div class="dropdown-header bg-light py-2">
                                    <div class="fw-semibold"><?php echo htmlspecialchars($userName); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars($currentUser['email'] ?? ''); ?></small>
                                    <div class="mt-1">
                                        <span class="badge bg-primary"><?php echo htmlspecialchars($currentUser['role'] ?? 'GUEST'); ?></span>
                                    </div>
                                </div>
                                <hr class="dropdown-divider">
                                <a class="dropdown-item trigger-change-password" href="#">
                                    <i class="icon me-2 cil cil-lock-locked"></i> Change Password
                                </a>
                                <a class="dropdown-item confirm-logout" href="<?php echo url('auth/logout.php'); ?>">
                                    <i class="icon me-2 cil cil-account-logout"></i> Sign Out
                                </a>
                            </div>
                        </li>
                    <?php else: ?>
                        <!-- Guest User - Show Login Button -->
                        <li class="nav-item">
                            <button type="button" class="btn btn-primary btn-sm ms-2" onclick="if(typeof LoginModal !== 'undefined') LoginModal.show(); else window.location.href='<?php echo url('auth/login.php'); ?>';">
                                <i class="cil cil-account-logout me-1"></i> Sign In
                            </button>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>
    <div class="bg-white py-2 px-3">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb my-0">
                <li class="breadcrumb-item">
                    <a href="<?php echo url('pages/dashboard.php'); ?>">Primary</a>
                </li>
                <?php if (isset($page_title) && $page_title !== 'Dashboard'): ?>
                    <li class="breadcrumb-item active"><?php echo $page_title; ?></li>
                <?php endif; ?>
            </ol>
        </nav>
    </div>
</header>

