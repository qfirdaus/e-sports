<?php
$iconMap = [
    'cil-speedometer' => 'ti-home',
    'cil-people' => 'ti-user',
    'cil-gamepad' => 'zmdi zmdi-gamepad',
    'cil-user' => 'ti-id-badge',
    'cil-map' => 'ti-map',
    'cil-award' => 'ti-cup',
    'cil-star' => 'ti-star',
    'cil-chart' => 'ti-bar-chart',
    'cil-settings' => 'ti-settings',
    'cil-id-card' => 'ti-id-badge',
];

$rbacDebugEnabled = isset($_GET['rbac_debug']) && (string)$_GET['rbac_debug'] === '1';

// Get current user and RBAC if authenticated (layout.php may already provide this)
$rbac = $rbac ?? null;
if (!defined('SKIP_AUTH_CHECK') && $rbac === null) {
    if (file_exists(__DIR__ . '/../config/database.php') &&
        file_exists(__DIR__ . '/../config/auth.php') &&
        file_exists(__DIR__ . '/../config/rbac.php')) {
        require_once __DIR__ . '/../config/database.php';
        require_once __DIR__ . '/../config/auth.php';
        require_once __DIR__ . '/../config/rbac.php';
        if (session_status() === PHP_SESSION_NONE) {
            Session::start();
        }
        $rbac = getRBAC();
    }
}

$contingentCode = Session::get('kod_universiti') ?? Session::get('kod_university') ?? '';
if (!$contingentCode) {
    try {
        $role = Session::get('user_role');
        $kontinjenId = Session::get('kontinjen_id');
        if ($role === 'CONTINGENT' && $kontinjenId) {
            $db = getDB();
            $stmt = $db->prepare("SELECT k.kod_universiti FROM table_kontinjen k WHERE k.id = :id AND k.deleted_at IS NULL");
            $stmt->execute([':id' => $kontinjenId]);
            $contingentCode = $stmt->fetchColumn() ?: '';
        }
    } catch (Exception $e) {
        // ignore
    }
}

$displayNavItems = $nav_items;
$useSections = isset($nav_sections) && is_array($nav_sections);

// Ensure 'Participation Certificates' child is present before $useSections is evaluated
// so it can appear under the Reports group when sections are used.
try {
    $sijilChild = [ 'title' => 'Participation Certificates', 'url' => 'pages/sijil-user.php', 'icon' => 'cil-award' ];
    if (!isset($nav_sections) || !is_array($nav_sections)) {
        $nav_sections = [];
    }
    // find Reports section, otherwise leave nav_sections intact
    $found = false;
    foreach ($nav_sections as $idx => $sec) {
        if (isset($sec['title']) && $sec['title'] === 'Reports') {
            $found = true;
            if (!isset($nav_sections[$idx]['children']) || !is_array($nav_sections[$idx]['children'])) {
                $nav_sections[$idx]['children'] = [];
            }
            $exists = false;
            foreach ($nav_sections[$idx]['children'] as $c) { if (isset($c['url']) && $c['url'] === $sijilChild['url']) { $exists = true; break; } }
            if (!$exists) $nav_sections[$idx]['children'][] = $sijilChild;
            break;
        }
    }
    // If Management section not present, add it with sijil child so it appears
    if (!$found) {
        $nav_sections[] = [ 'title' => 'Reports', 'children' => [ $sijilChild ] ];
    }
    // ensure sections mode is enabled
    $useSections = true;
} catch (Exception $e) {
    // ignore
}

// Add "Competition" section when user has access to any Competition pages
if ($rbac && (
    $rbac->hasPageAccess('pages/setup-pertandingan.php') ||
    $rbac->hasPageAccess('pages/setup-jadual.php') ||
    $rbac->hasPageAccess('pages/matches.php') ||
    $rbac->hasPageAccess('pages/round-standing.php') ||
    $rbac->hasPageAccess('pages/knockout-rule-editor.php')
)) {
    $exists = false;
    if (isset($nav_sections) && is_array($nav_sections)) {
        foreach ($nav_sections as $s) {
            if (isset($s['title']) && $s['title'] === 'Competition') { $exists = true; break; }
        }
    }
    if (!$exists) {
        $nav_sections = $nav_sections ?? [];
        $nav_sections[] = [
            'title' => 'Competition',
            'children' => [
                [
                    'title' => 'Competition Setup',
                    'url' => 'pages/setup-pertandingan.php',
                    'icon' => 'cil-settings'
                ],
                [
                    'title' => 'Schedule Setup',
                    'url' => 'pages/setup-jadual.php',
                    'icon' => 'cil-settings'
                ],
                [
                    'title' => 'Match',
                    'url' => 'pages/matches.php',
                    'icon' => 'cil-settings'
                ],
                [
                    'title' => 'Standings',
                    'url' => 'pages/round-standing.php',
                    'icon' => 'cil-chart'
                ],
                [
                    'title' => 'Knockout Rule Editor',
                    'url' => 'pages/knockout-rule-editor.php',
                    'icon' => 'cil-settings'
                ]
            ]
        ];
        $useSections = true;
    }
}

// Ensure 'Participation Certificates' menu exists under 'Reports' and is controlled by RBAC
try {
    $sijilChild = [
        'title' => 'Participation Certificates',
        'url' => 'pages/sijil-user.php',
        'icon' => 'cil-award',
    ];
    $found = false;
    if (!isset($nav_sections) || !is_array($nav_sections)) $nav_sections = [];
    foreach ($nav_sections as &$sec) {
        if (isset($sec['title']) && $sec['title'] === 'Reports') {
            // check duplicate
            $exists = false;
            if (isset($sec['children']) && is_array($sec['children'])) {
                foreach ($sec['children'] as $c) { if (isset($c['url']) && $c['url'] === $sijilChild['url']) { $exists = true; break; } }
            } else {
                $sec['children'] = [];
            }
            if (!$exists) $sec['children'][] = $sijilChild;
            $found = true; break;
        }
    }
    unset($sec);
    if (!$found) {
        $nav_sections[] = [ 'title' => 'Reports', 'children' => [ $sijilChild ] ];
    }
} catch (Exception $e) {
    // ignore - non-critical
}

if ($rbac) {
    if ($useSections) {
        foreach ($nav_sections as $sidx => $section) {
            $visibleChildren = [];
            foreach ($section['children'] as $child) {
                if ($rbac->isNavItemVisible($child['url'])) {
                    $visibleChildren[] = $child;
                }
            }
            $nav_sections[$sidx]['children'] = $visibleChildren;
        }
    } else {
        $displayNavItems = [];
        foreach ($nav_items as $item) {
            if ($rbac->isNavItemVisible($item['url'])) {
                $displayNavItems[] = $item;
            }
        }
    }
} else {
    // Security default: if RBAC is unavailable, do not expose all menu items.
    if ($useSections) {
        foreach ($nav_sections as $sidx => $section) {
            $nav_sections[$sidx]['children'] = [];
        }
    } else {
        $displayNavItems = [];
    }
}
// auth state for logout
$auth = function_exists('getAuth') ? getAuth() : null;
$isLoggedIn = ($auth && $auth->isLoggedIn());
// RBAC diagnostic comment for troubleshooting menu visibility
try {
    if (isset($rbac) && $rbac) {
        $diagRole = Session::get('user_role') ?? '(none)';
        $diagStatus = $rbac->getPageAccessStatus('pages/sijil-user.php');
        $diagHas = $rbac->hasPageAccess('pages/sijil-user.php') ? '1' : '0';
        echo "<!-- RBAC-DIAG role={$diagRole} isLoggedIn=" . ($isLoggedIn ? '1' : '0') . " status={$diagStatus} hasAccess={$diagHas} -->\n";
    }
} catch (Exception $e) {
    // ignore
}

// Optional RBAC debug dump to browser console (?rbac_debug=1)
$rbacDebugPayload = null;
if ($rbacDebugEnabled) {
    try {
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
        $scriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
        $baseUrl = '/' . trim(str_replace('\\', '/', (string)BASE_URL), '/');
        if ($baseUrl === '//') {
            $baseUrl = '/';
        }
        if ($baseUrl !== '/' && strpos($scriptPath, $baseUrl . '/') === 0) {
            $currentRelative = substr($scriptPath, strlen($baseUrl) + 1);
        } else {
            $currentRelative = ltrim($scriptPath, '/');
        }
        if (strpos($currentRelative, 'app/') === 0) {
            $currentRelative = substr($currentRelative, 4);
        }
        if ($currentRelative === '' || $currentRelative === '/') {
            $currentRelative = 'index.php';
        }

        $menuChecks = [];
        if (isset($nav_sections) && is_array($nav_sections)) {
            foreach ($nav_sections as $section) {
                if (!isset($section['children']) || !is_array($section['children'])) continue;
                foreach ($section['children'] as $child) {
                    $u = (string)($child['url'] ?? '');
                    if ($u === '') continue;
                    $menuChecks[] = [
                        'section' => (string)($section['title'] ?? ''),
                        'title' => (string)($child['title'] ?? $u),
                        'url' => $u,
                        'status' => $rbac->getPageAccessStatus($u),
                        'allowed' => $rbac->hasPageAccess($u) ? 1 : 0,
                    ];
                }
            }
        } else {
            foreach (($nav_items ?? []) as $item) {
                $u = (string)($item['url'] ?? '');
                if ($u === '') continue;
                $menuChecks[] = [
                    'section' => 'Top',
                    'title' => (string)($item['title'] ?? $u),
                    'url' => $u,
                    'status' => $rbac->getPageAccessStatus($u),
                    'allowed' => $rbac->hasPageAccess($u) ? 1 : 0,
                ];
            }
        }

        $rbacDebugPayload = [
            'email' => (string)(Session::get('user_email') ?? ''),
            'session_role' => (string)(Session::get('user_role') ?? ''),
            'session_user_id' => (string)(Session::get('user_id') ?? ''),
            'script_name' => (string)($_SERVER['SCRIPT_NAME'] ?? ''),
            'request_path' => $requestPath,
            'current_relative' => $currentRelative,
            'current_page_status' => $rbac->getPageAccessStatus($currentRelative),
            'current_page_allowed' => $rbac->hasPageAccess($currentRelative) ? 1 : 0,
            'menu_checks' => $menuChecks,
        ];
    } catch (Exception $e) {
        $rbacDebugPayload = [
            'error' => 'RBAC debug build failed: ' . $e->getMessage(),
        ];
    }
}
?>
<!-- Side Header Start -->
<div class="side-header show">
    <button class="side-header-close"><i class="zmdi zmdi-close"></i></button>
    <div class="side-header-inner custom-scroll">
        <nav class="side-header-menu" id="side-header-menu">
            <ul>
                <?php // Dashboard first ?>
                <?php if ($rbac && $rbac->isNavItemVisible('index.php')): ?>
                    <li class="<?php echo (basename($_SERVER['PHP_SELF']) === 'index.php' || basename($_SERVER['PHP_SELF']) === 'dashboard.php') ? 'active' : ''; ?>">
                        <a href="<?php echo url('index.php'); ?>"><i class="ti-home"></i> <span>Dashboard</span></a>
                    </li>
                <?php endif; ?>

                <?php if ($useSections && isset($nav_sections) && is_array($nav_sections)): ?>
                    <?php foreach ($nav_sections as $section): ?>
                        <?php if (empty($section['children'])) continue; ?>
                        <?php
                            $visibleChildren = $section['children'];
                            if (empty($visibleChildren)) continue;

                            // determine current request path to robustly match submenu URLs
                            $requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
                            $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
                            $queryString = $_SERVER['QUERY_STRING'] ?? '';
                            $sectionActive = false;
                            foreach ($visibleChildren as $childCheck) {
                                // resolve the child href the same way the link is rendered
                                $childHref = url($childCheck['url']);
                                $childPathCheck = parse_url($childHref, PHP_URL_PATH) ?: '/' . ltrim($childCheck['url'], '/');

                                // strip BASE_URL prefix if present so comparisons work when app is in subfolder
                                if (defined('BASE_URL') && BASE_URL) {
                                    $prefix = rtrim(BASE_URL, '/');
                                    if ($prefix !== '' && stripos($childPathCheck, $prefix) === 0) {
                                        $childPathCheck = substr($childPathCheck, strlen($prefix));
                                    }
                                    if ($prefix !== '' && stripos($requestPath ?? '', $prefix) === 0) {
                                        $requestPath = substr($requestPath, strlen($prefix));
                                    }
                                }

                                // normalize for safe comparisons
                                $reqNorm = rtrim(strtolower($requestPath ?? ''), '/');
                                $childNorm = rtrim(strtolower($childPathCheck), '/');
                                $phpSelfBase = strtolower(basename($_SERVER['PHP_SELF']));
                                $scriptBase = strtolower(basename($scriptName));
                                $childBase = strtolower(basename($childPathCheck));

                                // checks: basename match, exact path match, containment, or query-string reference
                                if (
                                    $phpSelfBase === $childBase
                                    || $scriptBase === $childBase
                                    || $reqNorm === $childNorm
                                    || stripos($requestPath, $childPathCheck) !== false
                                    || stripos($queryString, ltrim($childPathCheck, '/')) !== false
                                ) {
                                    $sectionActive = true; break;
                                }
                            }
                            $parentClasses = 'has-sub-menu' . ($sectionActive ? ' active open' : '');
                        ?>
                        <li class="<?php echo $parentClasses; ?>">
                            <a href="#"><i class="ti-list"></i> <span><?php echo htmlspecialchars($section['title'], ENT_QUOTES, 'UTF-8'); ?></span></a>
                            <ul class="side-header-sub-menu" <?php echo $sectionActive ? 'style="display:block;"' : ''; ?>>
                    <?php foreach ($visibleChildren as $child): ?>
                        <?php
                        $icon = $iconMap[$child['icon']] ?? 'ti-angle-right';
                        // dynamic title override for contingent-user (show University Code)
                        if (($child['url'] ?? '') === 'pages/contingent-user.php' && !empty($contingentCode)) {
                            $child['title'] = 'Contingent ' . $contingentCode;
                        }
                        $childHref = url($child['url']);
                        $childPath = parse_url($childHref, PHP_URL_PATH) ?: '/' . ltrim($child['url'], '/');

                                    // remove BASE_URL prefix if present
                                    if (defined('BASE_URL') && BASE_URL) {
                                        $prefix = rtrim(BASE_URL, '/');
                                        if ($prefix !== '' && stripos($childPath, $prefix) === 0) {
                                            $childPath = substr($childPath, strlen($prefix));
                                        }
                                    }

                                    $reqNorm = rtrim(strtolower($requestPath ?? ''), '/');
                                    $childNorm = rtrim(strtolower($childPath), '/');
                                    $phpSelfBase = strtolower(basename($_SERVER['PHP_SELF']));
                                    $scriptBase = strtolower(basename($scriptName));
                                    $childBase = strtolower(basename($childPath));

                                    $active = (
                                        $phpSelfBase === $childBase
                                        || $scriptBase === $childBase
                                        || $reqNorm === $childNorm
                                        || stripos($requestPath, $childPath) !== false
                                        || stripos($_SERVER['QUERY_STRING'] ?? '', ltrim($childPath, '/')) !== false
                                    ) ? 'active' : '';
                                    ?>
                                    <li class="<?php echo $active; ?>">
                                        <a href="<?php echo url($child['url']); ?>"><i class="<?php echo $icon; ?>"></i> <span><?php echo htmlspecialchars($child['title'], ENT_QUOTES, 'UTF-8'); ?></span></a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach ($displayNavItems as $item): ?>
                        <?php
                        $icon = $iconMap[$item['icon']] ?? 'ti-angle-right';
                        $active = $item['active'] ? 'active' : '';
                        ?>
                        <li class="<?php echo $active; ?>">
                            <a href="<?php echo url($item['url']); ?>">
                                <i class="<?php echo $icon; ?>"></i>
                                <span><?php echo htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($isLoggedIn): ?>
                    <li class="logout-link">
                        <a class="confirm-logout" href="<?php echo url('auth/logout.php'); ?>">
                            <i class="ti-power-off"></i> <span>Sign Out</span>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
</div>
<!-- Side Header End -->
<?php if ($rbacDebugEnabled && $rbacDebugPayload !== null): ?>
<script>
    (function() {
        const data = <?php echo json_encode($rbacDebugPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        console.group('[RBAC DEBUG]');
        console.log('User:', {
            email: data.email,
            session_role: data.session_role,
            session_user_id: data.session_user_id
        });
        console.log('Path:', {
            script_name: data.script_name,
            request_path: data.request_path,
            current_relative: data.current_relative,
            current_page_status: data.current_page_status,
            current_page_allowed: data.current_page_allowed
        });
        if (Array.isArray(data.menu_checks)) {
            console.table(data.menu_checks);
        } else {
            console.log('menu_checks:', data.menu_checks);
        }
        if (data.error) {
            console.error(data.error);
        }
        console.groupEnd();
    })();
</script>
<?php endif; ?>
