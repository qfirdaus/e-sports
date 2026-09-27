<?php
require_once __DIR__ . "/english-labels.php";
/**
 * Site Configuration
 * CoreUI Bootstrap Admin Template
 * SAM 2026 - Role-Based Access Control System
 */

/* ============================================================
 * BASE URL (AUTO-DETECT, FUTURE-PROOF)
 * ============================================================
 * - Root domain      → BASE_URL = ''
 * - Subfolder app    → BASE_URL = '/folder'
 * - Works for HTTP / HTTPS / any port
 */
$docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
$appRoot = realpath(__DIR__ . '/..');
$baseUrl = '';

if ($docRoot && $appRoot && strpos($appRoot, $docRoot) === 0) {
    $baseUrl = str_replace('\\', '/', substr($appRoot, strlen($docRoot)));
}

// Debug logging: emit which nav items/sections were marked active when DEBUG_MODE is enabled
if (defined('DEBUG_MODE') && DEBUG_MODE) {
    try {
        foreach ($nav_items as $ni) {
            if (!empty($ni['active'])) {
                error_log('[nav_active] nav_item active -> ' . ($ni['title'] ?? $ni['url'] ?? json_encode($ni)));
            }
        }
        if (isset($nav_sections) && is_array($nav_sections)) {
            foreach ($nav_sections as $sec) {
                $secTitle = $sec['title'] ?? '(section)';
                if (isset($sec['children']) && is_array($sec['children'])) {
                    foreach ($sec['children'] as $ch) {
                        if (!empty($ch['active'])) {
                            error_log('[nav_active] nav_section child active -> ' . $secTitle . ' :: ' . ($ch['title'] ?? $ch['url'] ?? json_encode($ch)));
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log('[nav_active] debug logging failed: ' . $e->getMessage());
    }
}

$baseUrl = rtrim($baseUrl, '/');
define('BASE_URL', $baseUrl);

/* ============================================================
 * RUNTIME SETTINGS (LOADED FROM app_settings)
 * ============================================================ */
if (file_exists(__DIR__ . '/database.php')) {
    require_once __DIR__ . '/database.php';
}

$runtime_settings = [];
if (function_exists('getDB')) {
    try {
        $db = getDB();
        $chk = $db->query("SHOW TABLES LIKE 'app_settings'");
        if ($chk && $chk->rowCount() > 0) {
            $st = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = :k LIMIT 1");
            $st->execute([':k' => 'settings_page_payload_v1']);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['setting_value'])) {
                $decoded = json_decode((string)$row['setting_value'], true);
                if (is_array($decoded)) {
                    $runtime_settings = $decoded;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[config] load runtime settings failed: ' . $e->getMessage());
        $runtime_settings = [];
    }
}

if (!function_exists('app_settings_all')) {
    function app_settings_all(): array {
        global $runtime_settings;
        return is_array($runtime_settings) ? $runtime_settings : [];
    }
}

if (!function_exists('app_setting')) {
    function app_setting(string $path, $default = null) {
        $data = app_settings_all();
        if ($path === '') return $default;
        $segments = explode('.', $path);
        $cur = $data;
        foreach ($segments as $seg) {
            if (!is_array($cur) || !array_key_exists($seg, $cur)) {
                return $default;
            }
            $cur = $cur[$seg];
        }
        return $cur;
    }
}

if (!function_exists('app_bool_setting')) {
    function app_bool_setting(string $path, bool $default = false): bool {
        $v = app_setting($path, $default);
        if (is_bool($v)) return $v;
        $s = strtolower(trim((string)$v));
        if (in_array($s, ['1', 'true', 'yes', 'on'], true)) return true;
        if (in_array($s, ['0', 'false', 'no', 'off', ''], true)) return false;
        return $default;
    }
}

/* ============================================================
 * SITE SETTINGS
 * ============================================================ */
define('SITE_NAME', (string)app_setting('generalSettingsForm.siteName', 'Sukan Asasi Malaysia 2026'));
define('SITE_FULL_NAME', (string)app_setting('generalSettingsForm.siteFullName', 'Sukan Asasi Malaysia'));
define('SITE_DESCRIPTION', (string)app_setting('generalSettingsForm.siteDescription', 'Sukan Asasi Malaysia Championship Management System'));
define('SITE_TITLE', 'Dashboard');

/* ============================================================
 * LOCALE SETTINGS
 * ============================================================ */
$appTz = (string)app_setting('localeSettingsForm.timezone', 'Asia/Kuala_Lumpur');
if (!in_array($appTz, timezone_identifiers_list(), true)) {
    $appTz = 'Asia/Kuala_Lumpur';
}
define('APP_TIMEZONE', $appTz);
date_default_timezone_set(APP_TIMEZONE);
define('APP_LANGUAGE', 'en');
define('APP_DATE_FORMAT', (string)app_setting('localeSettingsForm.dateFormat', 'd/m/Y'));
define('APP_TIME_FORMAT', (string)app_setting('localeSettingsForm.timeFormat', 'H:i'));

/* ============================================================
 * DEBUG MODE
 * ============================================================ */
define('DEBUG_MODE', false);

/* ============================================================
 * SESSION & AUTH INITIALIZATION
 * ============================================================ */
if (file_exists(__DIR__ . '/auth.php')) {
    require_once __DIR__ . '/auth.php';
    Session::start();
}

// Keep legacy language cookies consistent with the English-only interface.
if (($_COOKIE['sam_lang'] ?? '') !== APP_LANGUAGE && !headers_sent()) {
    $lang = APP_LANGUAGE;
    setcookie('sam_lang', $lang, [
        'expires' => time() + (86400 * 365),
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

// Global maintenance mode gate (allow ADMIN and essential auth/settings endpoints)
try {
    $maintenance = app_bool_setting('maintenanceForm.maintenanceMode', false);
    if ($maintenance) {
        $script = strtolower(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '')));
        $allowList = [
            '/auth/login.php',
            '/auth/logout.php',
            '/pages/settings.php',
            '/api/settings.php',
            '/pages/access-denied.php',
        ];
        $isAllowedPath = false;
        foreach ($allowList as $ap) {
            if (str_ends_with($script, strtolower($ap))) {
                $isAllowedPath = true;
                break;
            }
        }

        $role = '';
        if (class_exists('Session')) {
            $role = strtoupper((string)Session::get('user_role', ''));
        }
        $isAdmin = ($role === 'ADMIN');
        if (!$isAllowedPath && !$isAdmin) {
            http_response_code(503);
            $msg = trim((string)app_setting('maintenanceForm.maintenanceMessage', 'The system is undergoing maintenance. Please try again later.'));
            if ($msg === '') $msg = 'The system is undergoing maintenance. Please try again later.';
            echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>System Maintenance</title>'
                . '<style>body{font-family:Arial,sans-serif;background:#f8f9fa;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}.card{max-width:640px;background:#fff;border:1px solid #ddd;border-radius:8px;padding:24px;box-shadow:0 8px 24px rgba(0,0,0,.08)}h1{margin:0 0 12px;font-size:22px}p{margin:0;color:#444;line-height:1.6}</style>'
                . '</head><body><div class="card"><h1>System Under Maintenance</h1><p>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</p></div></body></html>';
            exit;
        }
    }
} catch (Throwable $e) {
    error_log('[config] maintenance gate failed: ' . $e->getMessage());
}

/* ============================================================
 * HELPER FUNCTIONS
 * ============================================================ */

/**
 * Asset URL helper
 * Example: asset('css/custom.css')
 * Output : /assets/css/custom.css
 */
function asset($path) {
    return BASE_URL . '/assets/' . ltrim($path, '/');
}

/**
 * Page URL helper
 * Example: url('auth/login.php')
 * Output : /auth/login.php
 */
function url($path) {
    return BASE_URL . '/' . ltrim($path, '/');
}

/**
 * Logo helper
 */
function logo($filename) {
    return asset('img/logos/' . $filename);
}

/* ============================================================
 * LOGO CONFIGURATION
 * ============================================================ */
define('LOGO_HEADER', (string)app_setting('logoSettingsForm.headerLogoPath', 'apple-icon-180x180.png'));
define('LOGO_FAVICON', (string)app_setting('logoSettingsForm.faviconPath', 'favicon.ico'));
define('LOGO_APPLE_TOUCH', 'apple-icon-180x180.png');
define('LOGO_ANDROID', 'android-icon-192x192.png');

/* ============================================================
 * NAVIGATION MENU
 * ============================================================ */
$nav_items = [
    [ 'title' => 'Dashboard', 'icon' => 'cil-speedometer', 'url' => 'index.php', 'active' => false ],
    [ 'title' => 'Contingents', 'icon' => 'cil-people', 'url' => 'pages/contingent.php', 'active' => false ],
    // removed top-level User Management; moved under Settings as 'User'
    [ 'title' => 'Sports', 'icon' => 'cil-gamepad', 'url' => 'pages/sports.php', 'active' => false ],
    [ 'title' => 'Teams', 'icon' => 'cil-people', 'url' => 'pages/pasukan.php', 'active' => false ],
    [ 'title' => 'Venue', 'icon' => 'cil-map', 'url' => 'pages/venues.php', 'active' => false ],
    [ 'title' => 'Results', 'icon' => 'cil-award', 'url' => 'pages/results.php', 'active' => false ],
    [ 'title' => 'Medal Tally', 'icon' => 'cil-star', 'url' => 'pages/medal-tally.php', 'active' => false ],
    [ 'title' => 'Reports', 'icon' => 'cil-chart', 'url' => 'pages/reports.php', 'active' => false ],
    [ 'title' => 'System Settings', 'icon' => 'cil-settings', 'url' => 'pages/settings.php', 'active' => false ],
];

/* ============================================================
 * NAVIGATION SECTIONS (grouped)
 * Rendered by sidebar as collapsible groups. Individual child
 * visibility is controlled by RBAC via `isNavItemVisible()`.
 */
$nav_sections = [
    [
        'title' => 'Management',
        'children' => [
            ['title' => 'Contingents', 'icon' => 'cil-people', 'url' => 'pages/contingent.php'],
            ['title' => 'Sports', 'icon' => 'cil-gamepad', 'url' => 'pages/sports.php'],
            ['title' => 'Teams', 'icon' => 'cil-people', 'url' => 'pages/pasukan.php'],
            ['title' => 'Venue', 'icon' => 'cil-map', 'url' => 'pages/venues.php'],
            ['title' => 'Results', 'icon' => 'cil-award', 'url' => 'pages/keputusan.php'],
            // 'Contingent User' visibility is controlled below so we can restrict it to CONTINGENT only
        ],
    ],
    [
        'title' => 'Reports',
        'children' => [
            ['title' => 'Summary', 'icon' => 'cil-chart', 'url' => 'pages/ringkasan.php'],
            ['title' => 'Participation Certificates', 'icon' => 'cil-id-card', 'url' => 'pages/sijil.php'],
            ['title' => 'Results', 'icon' => 'cil-award', 'url' => 'pages/results.php'],
            ['title' => 'Contingents', 'icon' => 'cil-people', 'url' => 'pages/contingent-admin.php'],
                // Checklist removed - no longer shown in menu
        ],
    ],
    [
        'title' => 'Settings',
        'children' => [
            ['title' => 'System Settings', 'icon' => 'cil-settings', 'url' => 'pages/settings.php'],
            ['title' => 'Universities', 'icon' => 'cil-building', 'url' => 'pages/university.php'],
            ['title' => 'User', 'icon' => 'cil-user', 'url' => 'pages/pengurusan-pengguna.php'],
            ['title' => 'Audit MyKad', 'icon' => 'cil-id-card', 'url' => 'pages/ic_audit.php'],
            ['title' => 'Access Matrix', 'icon' => 'cil-lock-unlocked', 'url' => 'pages/matrix-access.php'],
        ],
    ],
];

/* ============================================================
 * ACTIVE MENU DETECTION
 * ============================================================ */
$current_page = basename($_SERVER['PHP_SELF']);
$script_path  = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
$relative_path = ltrim(str_replace(BASE_URL, '', $script_path), '/');

// normalize helpers
function _nav_normalize($p) {
    $p = trim((string)$p);
    $p = preg_replace('#^/+#', '/', $p);
    $p = rtrim($p, '/');
    return strtolower($p === '' ? '/' : $p);
}

// Keep 'Contingent User' menu entry available in config, then let RBAC decide visibility.
// This removes dependency on legacy Session::user_role and avoids menu/access mismatch.
try {
    if (isset($nav_sections[0]) && isset($nav_sections[0]['children']) && is_array($nav_sections[0]['children'])) {
        $exists = false;
        foreach ($nav_sections[0]['children'] as $c) {
            if (isset($c['url']) && trim((string)$c['url']) === 'pages/contingent-user.php') {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $nav_sections[0]['children'][] = ['title' => 'Contingent User', 'icon' => 'cil-people', 'url' => 'pages/contingent-user.php'];
        }
    }
} catch (Exception $e) {
    // ignore errors and keep default nav_sections
}

$current_page_base = strtolower(basename($_SERVER['PHP_SELF']));
$script_base = strtolower(basename($script_path));
$rel_norm = _nav_normalize('/' . ltrim($relative_path, '/'));

foreach ($nav_items as &$item) {
    $item_path = '/' . ltrim($item['url'], '/');
    $item_norm = _nav_normalize($item_path);
    $item_base = strtolower(basename($item_path));

    // Mark active if any reasonable match: basename, exact normalized path, or containment
    if (
        $item_base === $current_page_base ||
        $item_base === $script_base ||
        $item_norm === $rel_norm ||
        stripos($rel_norm, $item_norm) !== false
    ) {
        $item['active'] = true;
    }
}
unset($item);

// Also mark children in nav_sections active so markup that uses "active" flag works
if (isset($nav_sections) && is_array($nav_sections)) {
    foreach ($nav_sections as $sidx => $section) {
        if (empty($section['children']) || !is_array($section['children'])) continue;
        foreach ($section['children'] as $cidx => $child) {
            $child_path = '/' . ltrim($child['url'], '/');
            $child_norm = _nav_normalize($child_path);
            $child_base = strtolower(basename($child_path));

            if (
                $child_base === $current_page_base ||
                $child_base === $script_base ||
                $child_norm === $rel_norm ||
                stripos($rel_norm, $child_norm) !== false
            ) {
                $nav_sections[$sidx]['children'][$cidx]['active'] = true;
            }
        }
    }
}
