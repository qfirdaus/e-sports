<?php
/**
 * Role-Based Access Control (RBAC) Configuration
 * SAM 2026 - Page Access Control
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

/**
 * RBAC Class for Page Access Control
 */
class RBAC {
    private $auth;
    private $db;
    
    // Role hierarchy (higher number = more permissions)
    private $roleHierarchy = [
        'VIEWER' => 1,
        'CONTINGENT' => 2,
        'JUDGE' => 3,
        'ORGANIZER' => 4,
        'ADMIN' => 5
    ];
    
    // Public pages (accessible without authentication)
    // Only authentication-related pages are public
    private $publicPages = [
        'auth/login.php',
        'auth/logout.php',
        'auth/ajax-login.php',
        'pages/access-denied.php',
    ];
    
    // Page access rules
    // All pages require authentication except those in publicPages
    // Pages listed here require specific roles, others require any authenticated user
    private $pageAccessRules = [
        // Dashboard - all authenticated users
        'index.php' => ['ADMIN', 'ORGANIZER', 'JUDGE', 'CONTINGENT', 'VIEWER'],
        
        // Main pages - role-based defaults
        // ADMIN: all pages
        // ORGANIZER: all pages except settings-group pages (settings/university/users)
        // CONTINGENT: only allowed to access 'contingent-user' page (handled below)
        'pages/contingent.php' => ['ADMIN', 'ORGANIZER'],
        'pages/sports.php' => ['ADMIN', 'ORGANIZER'],
        'pages/pasukan.php' => ['ADMIN', 'ORGANIZER'],
        'pages/matches.php' => ['ADMIN', 'ORGANIZER', 'JUDGE'],
        'pages/match-result.php' => ['ADMIN', 'ORGANIZER', 'JUDGE'],
        'pages/round-standing.php' => ['ADMIN', 'ORGANIZER', 'JUDGE', 'VIEWER'],
        'pages/generate-knockout.php' => ['ADMIN', 'ORGANIZER', 'JUDGE'],
        'pages/bracket.php' => ['ADMIN', 'ORGANIZER', 'JUDGE', 'VIEWER'],
        'pages/edit-knockout-match.php' => ['ADMIN', 'ORGANIZER', 'JUDGE'],
        'pages/knockout-rule-editor.php' => ['ADMIN', 'ORGANIZER', 'JUDGE'],
        'pages/venues.php' => ['ADMIN', 'ORGANIZER'],
        'pages/keputusan.php' => ['ADMIN', 'ORGANIZER', 'JUDGE'],
        'pages/results.php' => ['ADMIN', 'ORGANIZER', 'JUDGE'],
        'pages/medal-tally.php' => ['ADMIN', 'ORGANIZER'],
        'pages/reports.php' => ['ADMIN', 'ORGANIZER'],
        'pages/ringkasan.php' => ['ADMIN', 'ORGANIZER', 'VIEWER'],
        // Participation Certificates - visible to ADMIN, ORGANIZER and VIEWER
        'pages/sijil.php' => ['ADMIN', 'ORGANIZER', 'VIEWER'],
        // Certificate user page (per-contingent actions) - accessible to CONTINGENT only
        'pages/sijil-user.php' => ['CONTINGENT'],
        // Competition Setup - restricted to ADMIN only
        'pages/setup-pertandingan.php' => ['ADMIN'],
        // Schedule Setup - restricted to ADMIN only
        'pages/setup-jadual.php' => ['ADMIN'],
        'pages/contingent-admin.php' => ['ADMIN', 'ORGANIZER', 'JUDGE', 'VIEWER'],
        'pages/matrix-access.php' => ['ADMIN'],
        
        // Settings - ADMIN only
        'pages/settings.php' => ['ADMIN'],
        'pages/university.php' => ['ADMIN'],
        'pages/ic_audit.php' => ['ADMIN'],
        
        // Users management - ADMIN only
        'pages/users.php' => ['ADMIN'],
        
        // New Contingent User page - accessible to CONTINGENT only
        'pages/contingent-user.php' => ['CONTINGENT'],
        'pages/pengurusan-pengguna.php' => ['ADMIN'],
    ];
    
    // If present, menu_access.json will be loaded to override static rules
    private $ensureAdmin = true;
    
    private $useDatabase = false;
    private $strictMode = true;
    private $temporaryAdminFullAccess = false;
    
    public function __construct() {
        $this->auth = getAuth();
        $this->db = getDB();
        
        // Load JSON-based menu access if available
        $this->loadMenuAccessJson();

        // Check if dynamic RBAC tables exist
        $this->useDatabase = $this->checkDatabaseTables();
    }
    
    /**
     * Check if dynamic RBAC tables exist
     */
    private function checkDatabaseTables() {
        try {
            $required = ['roles', 'user_roles', 'page_access_rules', 'page_role_access'];
            foreach ($required as $table) {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*)
                    FROM information_schema.tables
                    WHERE table_schema = DATABASE()
                      AND table_name = :t
                ");
                $stmt->execute([':t' => $table]);
                if ((int)$stmt->fetchColumn() <= 0) {
                    return false;
                }
            }
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * @return array<int, int>
     */
    private function getActiveUserRoleIds(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT role_id
            FROM user_roles
            WHERE user_id = :user_id
            AND is_active = TRUE
            AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($rows)) return [];
        return array_values(array_map('intval', $rows));
    }

    /**
     * In strict mode, enforce session role as effective role if available.
     * This prevents stale multi-role assignments from granting unexpected access.
     *
     * @return array<int, int>
     */
    private function getEffectiveUserRoleIds(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT ur.role_id, UPPER(r.role_code) AS role_code
            FROM user_roles ur
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = :user_id
            AND ur.is_active = TRUE
            AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
        ");
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rows = is_array($rows) ? $rows : [];

        $sessionRole = strtoupper(trim((string)Session::get('user_role', '')));
        if (!empty($rows) && $sessionRole !== '') {
            $filtered = array_values(array_filter($rows, static function ($r) use ($sessionRole) {
                return strtoupper(trim((string)($r['role_code'] ?? ''))) === $sessionRole;
            }));
            if (!empty($filtered)) {
                return array_values(array_map(static fn($r) => (int)$r['role_id'], $filtered));
            }
        }

        if (!empty($rows)) {
            return array_values(array_map(static fn($r) => (int)$r['role_id'], $rows));
        }

        // Fallback bridge: if user_roles is empty, infer from users.role to avoid total lockout.
        // This keeps strict page rules, but tolerates legacy user-role data during migration.
        $legacyStmt = $this->db->prepare("
            SELECT UPPER(TRIM(role)) AS role_code
            FROM users
            WHERE id = :user_id
            LIMIT 1
        ");
        $legacyStmt->execute([':user_id' => $userId]);
        $legacyRole = strtoupper(trim((string)$legacyStmt->fetchColumn()));
        if ($legacyRole === '') {
            $legacyRole = $sessionRole;
        }
        if ($legacyRole === '') {
            return [];
        }

        $roleIdStmt = $this->db->prepare("
            SELECT id
            FROM roles
            WHERE UPPER(TRIM(role_code)) = :role_code
            LIMIT 1
        ");
        $roleIdStmt->execute([':role_code' => $legacyRole]);
        $roleId = (int)$roleIdStmt->fetchColumn();
        return $roleId > 0 ? [$roleId] : [];
    }

    /**
     * @return array<int, string>
     */
    private function getActiveUserRoleCodes(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT DISTINCT UPPER(r.role_code) AS role_code
            FROM user_roles ur
            INNER JOIN roles r ON r.id = ur.role_id
            WHERE ur.user_id = :user_id
            AND ur.is_active = TRUE
            AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
        ");
        $stmt->execute([':user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!is_array($rows)) return [];
        return array_values(array_unique(array_map(static fn($v) => strtoupper((string)$v), $rows)));
    }

    /**
     * Load menu access configuration from JSON file if present.
     * This allows maintaining RBAC in `app/config/menu_access.json`.
     */
    private function loadMenuAccessJson() {
        $path = __DIR__ . '/menu_access.json';
        if (!file_exists($path)) {
            return;
        }

        $json = @file_get_contents($path);
        if ($json === false) {
            return;
        }

        $data = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
            return;
        }

        // Replace page access rules if provided
        if (!empty($data['pages']) && is_array($data['pages'])) {
            $this->pageAccessRules = $data['pages'];
        }

        // Replace public pages if provided
        if (isset($data['public_pages']) && is_array($data['public_pages']) && !empty($data['public_pages'])) {
            $this->publicPages = $data['public_pages'];
        }

        // ensure_admin flag (optional)
        if (isset($data['ensure_admin'])) {
            $this->ensureAdmin = (bool)$data['ensure_admin'];
        }
    }

    /**
     * Static rule fallback (menu_access.json / in-code defaults).
     */
    private function hasStaticPageAccess(string $pagePath): bool {
        $pagePath = $this->normalizePagePath($pagePath);
        if ($this->isPublicPage($pagePath)) {
            return true;
        }
        if (!$this->auth->isLoggedIn()) {
            return false;
        }
        $sessionRole = strtoupper(trim((string)Session::get('user_role', '')));
        if ($sessionRole === '') {
            return false;
        }
        if (!isset($this->pageAccessRules[$pagePath]) || !is_array($this->pageAccessRules[$pagePath])) {
            return false;
        }
        $allowedRoles = array_map(static fn($r) => strtoupper(trim((string)$r)), $this->pageAccessRules[$pagePath]);
        return in_array($sessionRole, $allowedRoles, true);
    }
    
    /**
     * Check if a page is public (no authentication required)
     */
    public function isPublicPage($pagePath) {
        $pagePath = $this->normalizePagePath($pagePath);
        
        // Only pages explicitly in publicPages list are public
        // All other pages require authentication
        return in_array($pagePath, $this->publicPages);
    }
    
    /**
     * Check if user has access to a specific page
     */
    public function hasPageAccess($pagePath) {
        // Normalize page path
        $pagePath = $this->normalizePagePath($pagePath);
        // (no debug logging)
        
        // Check if page is public
        if ($this->isPublicPage($pagePath)) {
            return true;
        }
        
        // Page requires authentication - check if user is logged in
        if (!$this->auth->isLoggedIn()) {
            return false;
        }

        // Dashboard landing must be accessible to any authenticated user.
        if ($pagePath === 'index.php') {
            return true;
        }

        // Temporary bypass: allow ADMIN full access until RBAC page-role setup is complete.
        // Set $temporaryAdminFullAccess = false after migration is finalized.
        if ($this->temporaryAdminFullAccess) {
            $roleCheck = strtoupper((string)Session::get('user_role', ''));
            if ($roleCheck === 'ADMIN') {
                return true;
            }
        }
        
        // Strict mode: access must come from DB rules only
        if ($this->strictMode) {
            if (!$this->useDatabase) {
                // If RBAC DB tables are unavailable, use static rules as safe compatibility fallback.
                return $this->hasStaticPageAccess($pagePath);
            }
            try {
                $userId = (int)Session::get('user_id');
                if ($userId <= 0) return false;

                $roleIds = $this->getEffectiveUserRoleIds($userId);
                if (empty($roleIds)) {
                    // All users must have user_roles in strict mode
                    return false;
                }

                // All pages must have page_access_rules entry in strict mode
                $pageStmt = $this->db->prepare("
                    SELECT id, is_public, requires_auth
                    FROM page_access_rules
                    WHERE page_path = :page_path
                    LIMIT 1
                ");
                $pageStmt->execute([':page_path' => $pagePath]);
                $pageRule = $pageStmt->fetch(PDO::FETCH_ASSOC);
                if (!$pageRule) {
                    // Compatibility fallback while DB page rules are being completed
                    return $this->hasStaticPageAccess($pagePath);
                }

                if ((int)$pageRule['is_public'] === 1 || (int)$pageRule['requires_auth'] === 0) {
                    return true;
                }

                $in = implode(',', array_fill(0, count($roleIds), '?'));
                $sql = "
                    SELECT COUNT(*) AS count
                    FROM page_role_access
                    WHERE page_rule_id = ?
                    AND role_id IN ($in)
                ";
                $stmt = $this->db->prepare($sql);
                $params = array_merge([(int)$pageRule['id']], $roleIds);
                $stmt->execute($params);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result && (int)$result['count'] > 0) {
                    return true;
                }
                // Compatibility fallback while role-page mappings are being completed
                return $this->hasStaticPageAccess($pagePath);
            } catch (PDOException $e) {
                error_log("RBAC Error (hasPageAccess strict): " . $e->getMessage());
                return $this->hasStaticPageAccess($pagePath);
            }
        }

        // Non-strict compatibility path (kept for rollback safety)
        if ($this->useDatabase) {
            try {
                $userId = (int)Session::get('user_id');
                $roleIds = $this->getActiveUserRoleIds($userId);
                if (!empty($roleIds)) {
                    $sql = "
                        SELECT COUNT(*) as count
                        FROM page_role_access pra
                        INNER JOIN page_access_rules par ON pra.page_rule_id = par.id
                        WHERE par.page_path = ?
                        AND pra.role_id IN (" . implode(',', array_fill(0, count($roleIds), '?')) . ")
                    ";
                    $stmt = $this->db->prepare($sql);
                    $params = array_merge([$pagePath], $roleIds);
                    $stmt->execute($params);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    return $result && (int)$result['count'] > 0;
                }
            } catch (PDOException $e) {
                error_log("RBAC Error (hasPageAccess): " . $e->getMessage());
            }
        }
        return false;
    }
    
    /**
     * Require access to a page (trigger modal or redirect if no access)
     * This is the central method for blocking direct URL access
     */
    public function requirePageAccess($pagePath) {
        // Normalize page path
        $pagePath = $this->normalizePagePath($pagePath);
        
        // Get access status
        $accessStatus = $this->getPageAccessStatus($pagePath);
        
        // Handle based on access status
        switch ($accessStatus) {
            case 'public':
                // Public page - allow access
                return;
                
            case 'requires_auth':
                // User not logged in - redirect to login page
                $this->redirectToLogin($pagePath);
                return;
                
            case 'allowed':
                // User has access - clear any unauthorized flags
                Session::remove('unauthorized_access');
                Session::remove('unauthorized_page');
                return;
                
            case 'denied':
                // User is logged in but doesn't have permission
                // Show access denied state
                $this->handleAccessDenied($pagePath);
                return;
        }
    }
    
    /**
     * Handle access denied for authenticated users without permission
     */
    private function handleAccessDenied($pagePath) {
        if (isset($_GET['rbac_debug']) && (string)$_GET['rbac_debug'] === '1') {
            $payload = $this->buildDeniedDebugPayload((string)$pagePath);
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
            }
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>RBAC Debug</title></head><body>';
            echo '<h3>RBAC Debug (Access Denied)</h3>';
            echo '<pre style="white-space:pre-wrap;word-break:break-word;background:#f7f7f9;border:1px solid #ddd;padding:12px;">' . htmlspecialchars((string)$json, ENT_QUOTES, 'UTF-8') . '</pre>';
            echo '<script>console.group("[RBAC DEBUG DENIED]");console.log(' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ');console.groupEnd();</script>';
            echo '</body></html>';
            exit;
        }

        // Set unauthorized flag
        Session::set('unauthorized_access', true);
        Session::set('unauthorized_page', $pagePath);
        Session::set('access_denied_reason', 'insufficient_permissions');
        
        // Try to redirect if headers not sent
        if (!headers_sent()) {
            $this->redirectToUnauthorized();
            exit; // Stop execution after redirect
        }
        // If headers already sent, stop page rendering and let JavaScript handle redirect
        // Output minimal content and exit
        $deniedUrl = url('pages/access-denied.php');
        echo '<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($deniedUrl) . '"></head><body><script>window.location.href="' . htmlspecialchars($deniedUrl) . '";</script></body></html>';
        exit;
    }

    /**
     * Build diagnostics for denied access flow.
     *
     * @return array<string,mixed>
     */
    private function buildDeniedDebugPayload(string $pagePath): array {
        $normalized = $this->normalizePagePath($pagePath);
        $userId = (int)Session::get('user_id');
        $sessionRole = (string)Session::get('user_role', '');
        $sessionEmail = (string)Session::get('user_email', '');

        $payload = [
            'input_page' => $pagePath,
            'normalized_page' => $normalized,
            'is_logged_in' => $this->auth->isLoggedIn() ? 1 : 0,
            'session' => [
                'user_id' => $userId,
                'user_role' => $sessionRole,
                'user_email' => $sessionEmail,
            ],
            'flags' => [
                'strictMode' => $this->strictMode ? 1 : 0,
                'useDatabase' => $this->useDatabase ? 1 : 0,
            ],
            'public_page' => $this->isPublicPage($normalized) ? 1 : 0,
            'static_fallback_allowed' => $this->hasStaticPageAccess($normalized) ? 1 : 0,
        ];

        if (!$this->useDatabase || $userId <= 0) {
            return $payload;
        }

        try {
            $payload['effective_role_ids'] = $this->getEffectiveUserRoleIds($userId);

            $codesStmt = $this->db->prepare("
                SELECT DISTINCT UPPER(r.role_code) AS role_code
                FROM user_roles ur
                INNER JOIN roles r ON r.id = ur.role_id
                WHERE ur.user_id = :user_id
                AND ur.is_active = TRUE
                AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
            ");
            $codesStmt->execute([':user_id' => $userId]);
            $payload['active_user_roles'] = $codesStmt->fetchAll(PDO::FETCH_COLUMN);

            $legacyStmt = $this->db->prepare("SELECT role FROM users WHERE id = :user_id LIMIT 1");
            $legacyStmt->execute([':user_id' => $userId]);
            $payload['legacy_users_role'] = $legacyStmt->fetchColumn();

            $pageStmt = $this->db->prepare("
                SELECT id, page_path, is_public, requires_auth
                FROM page_access_rules
                WHERE page_path = :page_path
                LIMIT 1
            ");
            $pageStmt->execute([':page_path' => $normalized]);
            $rule = $pageStmt->fetch(PDO::FETCH_ASSOC);
            $payload['db_page_rule'] = $rule ?: null;

            if ($rule && !empty($payload['effective_role_ids'])) {
                $in = implode(',', array_fill(0, count($payload['effective_role_ids']), '?'));
                $sql = "
                    SELECT pra.role_id, r.role_code
                    FROM page_role_access pra
                    INNER JOIN roles r ON r.id = pra.role_id
                    WHERE pra.page_rule_id = ?
                    AND pra.role_id IN ($in)
                ";
                $stmt = $this->db->prepare($sql);
                $params = array_merge([(int)$rule['id']], $payload['effective_role_ids']);
                $stmt->execute($params);
                $payload['db_matching_page_roles'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $payload['db_matching_page_roles'] = [];
            }
        } catch (Throwable $e) {
            $payload['debug_error'] = $e->getMessage();
        }

        return $payload;
    }
    
    /**
     * Check if user has minimum role level
     */
    public function hasMinimumRole($requiredRole) {
        if (!$this->auth->isLoggedIn()) {
            return false;
        }

        if (!$this->useDatabase) {
            return false;
        }

        try {
            $userId = (int)Session::get('user_id');
            if ($userId <= 0) return false;
            $codes = $this->getActiveUserRoleCodes($userId);
            if (empty($codes)) return false;

            $userLevel = 0;
            foreach ($codes as $code) {
                $lvl = (int)($this->roleHierarchy[$code] ?? 0);
                if ($lvl > $userLevel) $userLevel = $lvl;
            }

            $requiredRoleNorm = strtoupper((string)$requiredRole);
            $requiredLevel = (int)($this->roleHierarchy[$requiredRoleNorm] ?? 0);
            if ($requiredLevel <= 0) return false;

            return $userLevel >= $requiredLevel;
        } catch (PDOException $e) {
            error_log("RBAC Error (hasMinimumRole): " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Require minimum role level
     */
    public function requireMinimumRole($requiredRole) {
        $this->auth->requireAuth();
        
        if (!$this->hasMinimumRole($requiredRole)) {
            $this->redirectToUnauthorized();
        }
    }
    
    /**
     * Normalize page path for comparison
     */
    private function normalizePagePath($path) {
        // Normalize separators and trim leading slashes
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        // Normalize common workspace prefix 'app/' so paths like 'app/pages/..' become 'pages/..'
        if (strpos($path, 'app/') === 0) {
            $path = substr($path, 4);
        }

        // Remove BASE_URL prefix (supports both with and without leading slash)
        $baseUrl = trim(str_replace('\\', '/', (string)BASE_URL), '/');
        if ($baseUrl !== '') {
            if (strpos($path, $baseUrl . '/') === 0) {
                $path = substr($path, strlen($baseUrl) + 1);
            } elseif ($path === $baseUrl) {
                $path = '';
            }
        }

        // Canonical alias: treat dashboard page as index for access rules/menu mapping.
        if ($path === 'pages/dashboard.php') {
            return 'index.php';
        }

        // If path is already normalized (starts with 'pages/', 'auth/', or 'index.php'), return as is
        if (strpos($path, 'pages/') === 0 || strpos($path, 'auth/') === 0 || $path === 'index.php') {
            // Remove query string
            $path = strtok($path, '?');
            return $path;
        }

        // Remove base URL if present
        $path = str_replace(BASE_URL, '', $path);
        $path = ltrim($path, '/');
        
        // Remove query string
        $path = strtok($path, '?');
        
        // (remaining normalization continues)
        
        // Remove document root if present
        $docRoot = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? '');
        if (strpos($path, $docRoot) === 0) {
            $path = str_replace($docRoot, '', $path);
            $path = ltrim($path, '/');
        }
        
        // Remove BASE_URL if still present
        $baseUrl = ltrim(BASE_URL, '/');
        if (strpos($path, $baseUrl) === 0) {
            $path = substr($path, strlen($baseUrl));
            $path = ltrim($path, '/');
        }
        
        // Handle index.php
        if (empty($path) || $path === 'index.php' || $path === '/') {
            return 'index.php';
        }

        return $path;
    }
    
    /**
     * Trigger login modal (for modal-based auth)
     * Store return URL in session and set flag for JavaScript to show modal
     * NOTE: This is kept for backward compatibility but redirectToLogin is now used
     */
    private function triggerLoginModal($returnUrl = null) {
        // Redirect to login page instead of showing modal
        $this->redirectToLogin($returnUrl);
    }
    
    /**
     * Redirect to login page with return URL (fallback for non-modal flow)
     */
    private function redirectToLogin($returnUrl = null) {
        $loginUrl = url('auth/login.php');
        if ($returnUrl) {
            $loginUrl .= '?return=' . urlencode($returnUrl);
        }
        
        // Check if headers have already been sent
        if (!headers_sent()) {
            header('Location: ' . $loginUrl);
            exit;
        } else {
            // Headers already sent - use JavaScript redirect as fallback
            echo '<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($loginUrl) . '"></head><body><script>window.location.href="' . htmlspecialchars($loginUrl) . '";</script><p>Redirecting to <a href="' . htmlspecialchars($loginUrl) . '">login page</a>...</p></body></html>';
            exit;
        }
    }
    
    /**
     * Redirect to unauthorized/access denied page
     */
    private function redirectToUnauthorized() {
        // Check if headers have already been sent
        if (!headers_sent()) {
            // Check if user is logged in
            if ($this->auth->isLoggedIn()) {
                // Authenticated user without permission - show access denied page
                header('Location: ' . url('pages/access-denied.php'));
            } else {
                // Unauthenticated user - redirect to login
                header('Location: ' . url('auth/login.php'));
            }
            exit; // Stop execution after redirect
        } else {
            // Headers already sent - use JavaScript redirect as fallback
            $redirectUrl = $this->auth->isLoggedIn() 
                ? url('pages/access-denied.php')
                : url('auth/login.php');
            echo '<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($redirectUrl) . '"></head><body><script>window.location.href="' . htmlspecialchars($redirectUrl) . '";</script><p>Redirecting...</p></body></html>';
            exit;
        }
    }
    
    /**
     * Get allowed pages for current user
     */
    public function getAllowedPages() {
        if (!$this->auth->isLoggedIn()) {
            return [];
        }

        if (!$this->useDatabase) {
            return [];
        }
        try {
            $userId = (int)Session::get('user_id');
            $roleIds = $this->getEffectiveUserRoleIds($userId);
            if (empty($roleIds)) return [];
            $in = implode(',', array_fill(0, count($roleIds), '?'));
            $sql = "
                SELECT DISTINCT par.page_path
                FROM page_access_rules par
                LEFT JOIN page_role_access pra ON pra.page_rule_id = par.id
                WHERE par.is_public = 1
                OR pra.role_id IN ($in)
                ORDER BY par.page_path
            ";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($roleIds);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log("RBAC Error (getAllowedPages): " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if navigation item should be visible
     * This determines whether a page appears in the navigation bar
     * Based on user's authentication status and role
     */
    public function isNavItemVisible($pagePath) {
        // Normalize page path
        $pagePath = $this->normalizePagePath($pagePath);
        
        // User must be logged in to see navigation items
        if (!$this->auth->isLoggedIn()) {
            return false;
        }
        // Check if user has access to the page
        return $this->hasPageAccess($pagePath);
    }
    
    /**
     * Check if a page can be accessed via direct URL
     * This is used to block direct URL access
     */
    public function canAccessPage($pagePath) {
        // Normalize page path
        $pagePath = $this->normalizePagePath($pagePath);
        
        // Public pages can always be accessed
        if ($this->isPublicPage($pagePath)) {
            return true;
        }
        
        // For protected pages, user must have access
        return $this->hasPageAccess($pagePath);
    }
    
    /**
     * Get page access status for current user
     * Returns: 'public', 'allowed', 'requires_auth', 'denied'
     */
    public function getPageAccessStatus($pagePath) {
        $pagePath = $this->normalizePagePath($pagePath);
        
        // Check if public
        if ($this->isPublicPage($pagePath)) {
            return 'public';
        }
        
        // Check if user is logged in
        if (!$this->auth->isLoggedIn()) {
            return 'requires_auth';
        }
        
        // Check if user has access
        if ($this->hasPageAccess($pagePath)) {
            return 'allowed';
        }
        
        // User is logged in but doesn't have access
        return 'denied';
    }
    
    /**
     * Clear RBAC cache
     */
    public function clearCache() {
        if ($this->useDatabase) {
            try {
                $this->db->exec("DELETE FROM rbac_cache WHERE expires_at < NOW()");
            } catch (PDOException $e) {
                error_log("RBAC Error (clearCache): " . $e->getMessage());
            }
        }
    }
    
    /**
     * Check if at least one admin exists (prevent lockout)
     */
    public function hasAtLeastOneAdmin() {
        if (!$this->useDatabase) {
            // Fallback: check users table directly
            try {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) as count
                    FROM users
                    WHERE role = 'ADMIN' AND status = 'active'
                ");
                $stmt->execute();
                $result = $stmt->fetch();
                return $result && $result['count'] > 0;
            } catch (PDOException $e) {
                return true; // Assume admin exists on error
            }
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM users u
                INNER JOIN user_roles ur ON u.id = ur.user_id
                INNER JOIN roles r ON ur.role_id = r.id
                WHERE r.role_code = 'ADMIN'
                AND u.status = 'active'
                AND ur.is_active = TRUE
                AND (ur.expires_at IS NULL OR ur.expires_at > NOW())
            ");
            $stmt->execute();
            $result = $stmt->fetch();
            
            return $result && $result['count'] > 0;
        } catch (PDOException $e) {
            error_log("RBAC Error (hasAtLeastOneAdmin): " . $e->getMessage());
            return true; // Assume admin exists on error to prevent lockout
        }
    }
}

// Global RBAC instance
function getRBAC() {
    static $rbac = null;
    if ($rbac === null) {
        $rbac = new RBAC();
    }
    return $rbac;
}
