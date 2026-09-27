<?php
/**
 * Access Matrix
 * Role: ADMIN only
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/rbac.php';

Session::start();
$auth = getAuth();
$auth->requireAuth();
$rbac = getRBAC();
$rbac->requirePageAccess('pages/matrix-access.php');

$page_title = 'Access Matrix';

$roles = [];
$menus = [];
$usingRbacDatabase = false;
$loadError = '';

try {
    $db = getDB();

    $requiredTables = ['roles', 'page_access_rules', 'page_role_access'];
    $allTablesExist = true;
    foreach ($requiredTables as $tbl) {
        $chk = $db->prepare("
            SELECT COUNT(*) 
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :t
        ");
        $chk->execute([':t' => $tbl]);
        if ((int)$chk->fetchColumn() <= 0) {
            $allTablesExist = false;
            break;
        }
    }

    if ($allTablesExist) {
        $usingRbacDatabase = true;

        // Role order based on current system role hierarchy.
        $roleOrderExpr = "CASE UPPER(r.role_code)
            WHEN 'ADMIN' THEN 1
            WHEN 'ORGANIZER' THEN 2
            WHEN 'JUDGE' THEN 3
            WHEN 'CONTINGENT' THEN 4
            WHEN 'VIEWER' THEN 5
            ELSE 99
        END";

        $roleStmt = $db->query("
            SELECT r.id, UPPER(r.role_code) AS role_code, r.role_name
            FROM roles r
            ORDER BY {$roleOrderExpr}, r.role_code
        ");
        $roleRows = $roleStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($roleRows as $rr) {
            $roles[] = (string)$rr['role_code'];
        }

        $pageStmt = $db->query("
            SELECT id, page_path, is_public, requires_auth
            FROM page_access_rules
            ORDER BY page_path
        ");
        $pageRows = $pageStmt->fetchAll(PDO::FETCH_ASSOC);

        $mapStmt = $db->query("
            SELECT pra.page_rule_id, UPPER(r.role_code) AS role_code
            FROM page_role_access pra
            INNER JOIN roles r ON r.id = pra.role_id
        ");
        $mapRows = $mapStmt->fetchAll(PDO::FETCH_ASSOC);

        $allowedMap = [];
        foreach ($mapRows as $mr) {
            $pid = (int)$mr['page_rule_id'];
            if (!isset($allowedMap[$pid])) {
                $allowedMap[$pid] = [];
            }
            $allowedMap[$pid][] = (string)$mr['role_code'];
        }

        foreach ($pageRows as $pr) {
            $path = (string)$pr['page_path'];
            $group = 'Lain-lain';
            if (strpos($path, 'pages/') === 0) {
                $group = 'Pages';
            } elseif (strpos($path, 'auth/') === 0) {
                $group = 'Auth';
            } elseif ($path === 'index.php') {
                $group = 'Root';
            }

            $title = $path;
            if ($path === 'index.php') {
                $title = 'Dashboard';
            } elseif (preg_match('#^pages/(.+)\.php$#', $path, $m)) {
                $title = ucwords(str_replace(['-', '_'], ' ', $m[1]));
            } elseif (preg_match('#^auth/(.+)\.php$#', $path, $m)) {
                $title = 'Auth: ' . ucwords(str_replace(['-', '_'], ' ', $m[1]));
            }

            $menus[] = [
                'title' => $title,
                'group' => $group,
                'path' => $path,
                'is_public' => (int)$pr['is_public'],
                'requires_auth' => (int)$pr['requires_auth'],
                'allowed' => $allowedMap[(int)$pr['id']] ?? [],
            ];
        }
    } else {
        // Fallback if RBAC DB tables are unavailable.
        $roles = ['ADMIN', 'ORGANIZER', 'JUDGE', 'CONTINGENT', 'VIEWER'];
        $loadError = 'The new RBAC tables are incomplete. A fallback view is being used.';
        $pageAccess = [];
        $jsonPath = __DIR__ . '/../config/menu_access.json';
        if (file_exists($jsonPath)) {
            $json = @file_get_contents($jsonPath);
            $data = json_decode($json, true);
            if (isset($data['pages']) && is_array($data['pages'])) {
                $pageAccess = $data['pages'];
            }
        }
        foreach ($pageAccess as $path => $allowed) {
            $menus[] = [
                'title' => $path,
                'group' => (strpos($path, 'auth/') === 0 ? 'Auth' : (strpos($path, 'pages/') === 0 ? 'Pages' : 'Root')),
                'path' => (string)$path,
                'is_public' => 0,
                'requires_auth' => 1,
                'allowed' => is_array($allowed) ? array_values($allowed) : [],
            ];
        }
    }
} catch (Throwable $e) {
    $roles = ['ADMIN', 'ORGANIZER', 'JUDGE', 'CONTINGENT', 'VIEWER'];
    $menus = [];
    $loadError = 'Unable to load the access matrix: ' . $e->getMessage();
}

ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/matrix-access.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/matrix-access.css'); ?>">
<div class="w-100 px-3 matrix-view">
    <div class="row mb-3">
        <div class="col-12">
            <div class="matrix-eyebrow">SAM 2026 &middot; USER ACCESS</div><h2 class="mb-1">Access Matrix</h2>
            <p class="text-muted mb-0">Access matrix based on the current role-based access configuration.</p>
        </div>
    </div>
    <?php if ($loadError !== ''): ?>
        <div class="alert alert-warning"><?php echo htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($usingRbacDatabase): ?>
        <div class="alert alert-info">
            Access permissions are based on the current roles and configuration in the database.
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="matrix-panel-header"><strong>Access Permissions Matrix</strong><span><?php echo count($menus); ?> pages &middot; <?php echo count($roles); ?> roles</span></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:4%;">No.</th>
                            <th style="width:10%;">Group</th>
                            <th style="width:16%;">Menu</th>
                            <th style="width:18%;">Path</th>
                            <th class="text-center" style="width:7%;">Public</th>
                            <th class="text-center" style="width:7%;">Auth</th>
                            <?php foreach ($roles as $r): ?>
                                <th class="text-center"><?php echo htmlspecialchars($r, ENT_QUOTES, 'UTF-8'); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($menus)): ?>
                            <tr><td colspan="<?php echo 6 + count($roles); ?>" class="text-center text-muted py-4">No access data found.</td></tr>
                        <?php else: ?>
                            <?php $bil = 1; ?>
                            <?php foreach ($menus as $menu): ?>
                                <?php
                                    $title = $menu['title'] ?? '';
                                    $group = $menu['group'] ?? '';
                                    $path = (string)($menu['path'] ?? '');
                                    $isPublic = (int)($menu['is_public'] ?? 0) === 1;
                                    $requiresAuth = (int)($menu['requires_auth'] ?? 1) === 1;
                                    $allowed = $menu['allowed'] ?? [];
                                ?>
                                <tr>
                                    <td><?php echo $bil++; ?></td>
                                    <td><?php echo htmlspecialchars((string)$group, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><?php echo htmlspecialchars((string)$title, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td><code><?php echo htmlspecialchars((string)$path, ENT_QUOTES, 'UTF-8'); ?></code></td>
                                    <td class="text-center">
                                        <?php if ($isPublic): ?>
                                            <span class="badge bg-success">Yes</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($requiresAuth): ?>
                                            <span class="badge bg-primary">Yes</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">No</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php foreach ($roles as $role): ?>
                                        <?php $ok = in_array($role, $allowed, true); ?>
                                        <td class="text-center">
                                            <?php if ($isPublic): ?>
                                                <span class="badge bg-success">Allowed</span>
                                            <?php elseif ($ok): ?>
                                                <span class="badge bg-success">Allowed</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">No Access</span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require_once __DIR__ . '/../includes/layout.php';
