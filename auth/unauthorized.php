<?php
/**
 * Unauthorized Access Page
 * SAM 2026 - Access Denied
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

Session::start();
$auth = getAuth();

// If not logged in, redirect to login
if (!$auth->isLoggedIn()) {
    header('Location: ' . url('auth/login.php'));
    exit;
}

$user = $auth->getUser();
$page_title = 'Access Denied';

ob_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, shrink-to-fit=no">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title><?php echo $page_title; ?> - <?php echo SITE_NAME; ?></title>
    
    <!-- CoreUI CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@coreui/coreui@4.3.0/dist/css/coreui.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@coreui/icons@3.0.1/css/all.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo asset('css/custom.css'); ?>">
    
    <style>
        .unauthorized-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }
        .unauthorized-card {
            max-width: 500px;
            width: 100%;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body>
    <div class="unauthorized-container">
        <div class="card unauthorized-card">
            <div class="card-body text-center p-5">
                <div class="mb-4">
                    <i class="cil cil-lock-locked" style="font-size: 5rem; color: #dc3545;"></i>
                </div>
                <h2 class="mb-3">Access Denied</h2>
                <p class="text-muted mb-4">
                    You do not have permission to access this page.
                </p>
                
                <?php if ($user): ?>
                    <div class="alert alert-info mb-4">
                        <strong>Your Role:</strong> <?php echo htmlspecialchars($user['role']); ?><br>
                        <small>Only users with the required roles can access this page.</small>
                    </div>
                <?php endif; ?>
                
                <div class="d-grid gap-2">
                    <a href="<?php echo url('index.php'); ?>" class="btn btn-primary">
                        <i class="cil cil-home me-2"></i> Back ke Dashboard
                    </a>
                    <a href="<?php echo url('auth/logout.php'); ?>" class="btn btn-outline-secondary">
                        <i class="cil cil-account-logout me-2"></i> Sign Out
                    </a>
                </div>
                
                <div class="mt-4">
                    <small class="text-muted">
                        If you believe this is an error, please contact the system administrator.
                    </small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- CoreUI JS -->
    <script src="https://cdn.jsdelivr.net/npm/@coreui/coreui@4.3.0/dist/js/coreui.bundle.min.js"></script>
</body>
</html>
<?php
$content = ob_get_clean();
echo $content;
?>
