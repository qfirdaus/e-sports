<?php
/**
 * Dynamic Role-Based Access Control (RBAC)
 * SAM 2026 - Database-Driven Access Control
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

/**
 * Dynamic RBAC Class
 * Loads access rules from database with caching support
 */
class DynamicRBAC {
    private $auth;
    private $db;
    private $cache = [];
    private $cacheEnabled = true;
    private $cacheLifetime = 300; // 5 minutes
    
    // Role hierarchy (higher number = more permissions)
    private $roleHierarchy = [
        'VIEWER' => 1,
        'CONTINGENT' => 2,
        'JUDGE' => 3,
        'ORGANIZER' => 4,
        'ADMIN' => 5
    ];
    
    public function __construct() {
        $this->auth = getAuth();
        $this->db = getDB();
    }
    
    /**
     * Check if a page is public (no authentication required)
     */
    public function isPublicPage($pagePath) {
        $pagePath = $this->normalizePagePath($pagePath);
        
        // Check cache first
        $cacheKey = 'public_page_' . md5($pagePath);
        if ($this->cacheEnabled && isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT is_public 
                FROM page_access_rules 
                WHERE page_path = :page_path
            ");
            $stmt->execute([':page_path' => $pagePath]);
            $result = $stmt->fetch();
            
            $isPublic = $result ? (bool)$result['is_public'] : true; // Default to public
            
            // Cache result
            if ($this->cacheEnabled) {
                $this->cache[$cacheKey] = $isPublic;
            }
            
            return $isPublic;
        } catch (PDOException $e) {
            error_log("RBAC Error (isPublicPage): " . $e->getMessage());
            // Fallback: assume page is public if database error
            return true;
        }
    }
    
    /**
     * Check if user has access to a specific page
     */
    public function hasPageAccess($pagePath) {
        $pagePath = $this->normalizePagePath($pagePath);
        
        // Check if page is public
        if ($this->isPublicPage($pagePath)) {
            return true;
        }
        
        // Check if user is logged in
        if (!$this->auth->isLoggedIn()) {
            return false;
        }
        
        $userId = Session::get('user_id');
        $userRoles = $this->getUserRoles($userId);
        
        // Check if any of user's roles have access to this page
        try {
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM page_role_access pra
                INNER JOIN page_access_rules par ON pra.page_rule_id = par.id
                WHERE par.page_path = :page_path
                AND pra.role_id IN (" . implode(',', array_fill(0, count($userRoles), '?')) . ")
            ");
            
            $params = [':page_path' => $pagePath];
            foreach ($userRoles as $roleId) {
                $params[] = $roleId;
            }
            
            $stmt->execute($params);
            $result = $stmt->fetch();
            
            return $result && $result['count'] > 0;
        } catch (PDOException $e) {
            error_log("RBAC Error (hasPageAccess): " . $e->getMessage());
            // Fallback: deny access on error
            return false;
        }
    }
    
    /**
     * Get user's active roles
     */
    public function getUserRoles($userId) {
        $cacheKey = 'user_roles_' . $userId;
        if ($this->cacheEnabled && isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }
        
        try {
            $stmt = $this->db->prepare("
                SELECT role_id 
                FROM user_roles 
                WHERE user_id = :user_id 
                AND is_active = TRUE
                AND (expires_at IS NULL OR expires_at > NOW())
            ");
            $stmt->execute([':user_id' => $userId]);
            $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // Cache result
            if ($this->cacheEnabled) {
                $this->cache[$cacheKey] = $roles;
            }
            
            return $roles;
        } catch (PDOException $e) {
            error_log("RBAC Error (getUserRoles): " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get user's role codes (for backward compatibility)
     */
    public function getUserRoleCodes($userId) {
        $roleIds = $this->getUserRoles($userId);
        if (empty($roleIds)) {
            return [];
        }
        
        try {
            $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
            $stmt = $this->db->prepare("
                SELECT role_code 
                FROM roles 
                WHERE id IN ($placeholders)
            ");
            $stmt->execute($roleIds);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log("RBAC Error (getUserRoleCodes): " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if user has permission for an action
     */
    public function hasPermission($actionCode, $userId = null) {
        if ($userId === null) {
            if (!$this->auth->isLoggedIn()) {
                return false;
            }
            $userId = Session::get('user_id');
        }
        
        // Get user's roles
        $userRoles = $this->getUserRoles($userId);
        if (empty($userRoles)) {
            return false;
        }
        
        try {
            // Check if action requires permission
            $stmt = $this->db->prepare("
                SELECT requires_permission 
                FROM action_permissions 
                WHERE action_code = :action_code
            ");
            $stmt->execute([':action_code' => $actionCode]);
            $action = $stmt->fetch();
            
            if (!$action) {
                // Action not defined - default to requiring permission
                return false;
            }
            
            if (!$action['requires_permission']) {
                // Action doesn't require permission
                return true;
            }
            
            // Check if user's roles have the required permissions
            $placeholders = implode(',', array_fill(0, count($userRoles), '?'));
            $stmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM action_permission_rules apr
                INNER JOIN action_permissions ap ON apr.action_id = ap.id
                INNER JOIN role_permissions rp ON apr.permission_id = rp.permission_id
                WHERE ap.action_code = :action_code
                AND rp.role_id IN ($placeholders)
            ");
            
            $params = [':action_code' => $actionCode];
            foreach ($userRoles as $roleId) {
                $params[] = $roleId;
            }
            
            $stmt->execute($params);
            $result = $stmt->fetch();
            
            return $result && $result['count'] > 0;
        } catch (PDOException $e) {
            error_log("RBAC Error (hasPermission): " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Require access to a page (trigger modal or redirect if no access)
     */
    public function requirePageAccess($pagePath) {
        $pagePath = $this->normalizePagePath($pagePath);
        $accessStatus = $this->getPageAccessStatus($pagePath);
        
        switch ($accessStatus) {
            case 'public':
                return;
                
            case 'requires_auth':
                $this->triggerLoginModal($pagePath);
                return;
                
            case 'allowed':
                Session::remove('unauthorized_access');
                Session::remove('unauthorized_page');
                return;
                
            case 'denied':
                $this->handleAccessDenied($pagePath);
                return;
        }
    }
    
    /**
     * Get page access status
     */
    public function getPageAccessStatus($pagePath) {
        $pagePath = $this->normalizePagePath($pagePath);
        
        if ($this->isPublicPage($pagePath)) {
            return 'public';
        }
        
        if (!$this->auth->isLoggedIn()) {
            return 'requires_auth';
        }
        
        if ($this->hasPageAccess($pagePath)) {
            return 'allowed';
        }
        
        return 'denied';
    }
    
    /**
     * Check if navigation item should be visible
     */
    public function isNavItemVisible($pagePath) {
        $pagePath = $this->normalizePagePath($pagePath);
        
        // Public pages are always visible
        if ($this->isPublicPage($pagePath)) {
            return true;
        }
        
        // For protected pages, check if user has access
        return $this->hasPageAccess($pagePath);
    }
    
    /**
     * Normalize page path for comparison
     */
    private function normalizePagePath($path) {
        // If path is already normalized, return as is
        if (strpos($path, 'pages/') === 0 || $path === 'index.php') {
            $path = strtok($path, '?');
            return $path;
        }
        
        // Remove base URL
        $path = str_replace(BASE_URL, '', $path);
        $path = ltrim($path, '/');
        
        // Remove query string
        $path = strtok($path, '?');
        
        // Handle Windows paths
        $path = str_replace('\\', '/', $path);
        
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
     * Trigger login modal
     */
    private function triggerLoginModal($returnUrl = null) {
        if ($returnUrl) {
            Session::set('login_return_url', $returnUrl);
        } else {
            $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
            Session::set('login_return_url', $currentUrl);
        }
        Session::set('show_login_modal', true);
        
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            if (!headers_sent()) {
                header('Content-Type: application/json');
                echo json_encode([
                    'requires_auth' => true,
                    'show_modal' => true
                ]);
                exit;
            }
        }
    }
    
    /**
     * Handle access denied
     */
    private function handleAccessDenied($pagePath) {
        Session::set('unauthorized_access', true);
        Session::set('unauthorized_page', $pagePath);
        Session::set('access_denied_reason', 'insufficient_permissions');
        
        if (!headers_sent()) {
            $this->redirectToUnauthorized();
            exit;
        }
        
        $deniedUrl = url('pages/access-denied.php');
        echo '<!DOCTYPE html><html><head><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($deniedUrl) . '"></head><body><script>window.location.href="' . htmlspecialchars($deniedUrl) . '";</script></body></html>';
        exit;
    }
    
    /**
     * Redirect to unauthorized page
     */
    private function redirectToUnauthorized() {
        if ($this->auth->isLoggedIn()) {
            header('Location: ' . url('pages/access-denied.php'));
        } else {
            header('Location: ' . url('auth/login.php'));
        }
        exit;
    }
    
    /**
     * Clear cache
     */
    public function clearCache() {
        $this->cache = [];
        
        // Clear database cache if exists
        try {
            $this->db->exec("DELETE FROM rbac_cache WHERE expires_at < NOW()");
        } catch (PDOException $e) {
            error_log("RBAC Error (clearCache): " . $e->getMessage());
        }
    }
    
    /**
     * Check if at least one admin exists (prevent lockout)
     */
    public function hasAtLeastOneAdmin() {
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

// Global Dynamic RBAC instance
function getDynamicRBAC() {
    static $rbac = null;
    if ($rbac === null) {
        $rbac = new DynamicRBAC();
    }
    return $rbac;
}
