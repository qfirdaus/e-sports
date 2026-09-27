<?php
/**
 * Authentication Configuration
 * SAM 2026 - Role-Based Access Control
 */

// Session configuration
define('SESSION_NAME', 'SAM2026_SESSION');
$sessionTimeoutMin = function_exists('app_setting') ? (int)app_setting('securitySettingsForm.sessionTimeout', 60) : 60;
if ($sessionTimeoutMin < 5 || $sessionTimeoutMin > 480) $sessionTimeoutMin = 60;
define('SESSION_LIFETIME', $sessionTimeoutMin * 60); // in seconds
define('SESSION_PATH', '/');
define('SESSION_DOMAIN', '');
define('SESSION_SECURE', false); // Set to true in production with HTTPS
define('SESSION_HTTPONLY', true);
// Keep browser session cookie (no fixed absolute expiry while browser is open).
// Inactivity timeout is handled by app idle flow + server gc_maxlifetime.
define('SESSION_COOKIE_LIFETIME', 0);

// Password configuration
$pwdMinLen = function_exists('app_setting') ? (int)app_setting('securitySettingsForm.passwordMinLength', 8) : 8;
if ($pwdMinLen < 6 || $pwdMinLen > 20) $pwdMinLen = 8;
define('PASSWORD_MIN_LENGTH', $pwdMinLen);
define('PASSWORD_REQUIRE_UPPERCASE', function_exists('app_bool_setting') ? app_bool_setting('securitySettingsForm.passwordRequireUppercase', true) : true);
define('PASSWORD_REQUIRE_NUMBER', function_exists('app_bool_setting') ? app_bool_setting('securitySettingsForm.passwordRequireNumber', true) : true);
define('PASSWORD_REQUIRE_SPECIAL', function_exists('app_bool_setting') ? app_bool_setting('securitySettingsForm.passwordRequireSpecial', false) : false);

// Login security
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 900); // 15 minutes in seconds

// Session management
class Session {
    private static $started = false;

    private static function touchActivity(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Force session file refresh on every request to prevent premature GC logout
            // when session data is otherwise unchanged (session.lazy_write behavior).
            $_SESSION['__last_activity'] = time();
        }
    }
    
    public static function start() {
        // Check if session is already started
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::touchActivity();
            return;
        }
        
        // Check if headers have been sent
        if (headers_sent()) {
            // Headers already sent - cannot safely start a session here.
            // Avoid calling session_start() after output to prevent warnings.
            error_log('[Session] headers already sent; cannot start session in ' . __FILE__ . ' on line ' . __LINE__);
            // If a session is already active, mark as started.
            if (session_status() === PHP_SESSION_ACTIVE) {
                self::$started = true;
            }
            return;
        }
        
        // Headers not sent - can configure and start session
        if (session_status() === PHP_SESSION_NONE) {
            // Enforce lifetime at PHP engine level (important on production)
            @ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
            @ini_set('session.cookie_lifetime', (string)SESSION_COOKIE_LIFETIME);
            @ini_set('session.use_strict_mode', '1');

            session_name(SESSION_NAME);
            session_set_cookie_params([
                'lifetime' => SESSION_COOKIE_LIFETIME,
                'path' => SESSION_PATH,
                'domain' => SESSION_DOMAIN,
                'secure' => SESSION_SECURE,
                'httponly' => SESSION_HTTPONLY,
                'samesite' => 'Strict'
            ]);
            @session_start();
            if (session_status() === PHP_SESSION_ACTIVE) {
                self::$started = true;
                self::touchActivity();
            } else {
                error_log('[Session] failed to start session in ' . __FILE__);
            }
        }
    }
    
    public static function set($key, $value) {
        // Ensure session is started before accessing
        if (session_status() === PHP_SESSION_NONE) {
            self::start();
        }
        $_SESSION[$key] = $value;
    }
    
    public static function get($key, $default = null) {
        // Ensure session is started before accessing
        if (session_status() === PHP_SESSION_NONE) {
            self::start();
        }
        return $_SESSION[$key] ?? $default;
    }
    
    public static function has($key) {
        // Ensure session is started before accessing
        if (session_status() === PHP_SESSION_NONE) {
            self::start();
        }
        return isset($_SESSION[$key]);
    }
    
    public static function remove($key) {
        // Ensure session is started before accessing
        if (session_status() === PHP_SESSION_NONE) {
            self::start();
        }
        unset($_SESSION[$key]);
    }
    
    public static function destroy() {
        if (session_status() === PHP_SESSION_NONE) {
            self::start();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (isset($_COOKIE[session_name()])) {
                setcookie(session_name(), '', time() - 3600, SESSION_PATH, SESSION_DOMAIN, SESSION_SECURE, SESSION_HTTPONLY);
            }
            @session_destroy();
        }
    }
    
    public static function regenerate() {
        self::start();
        session_regenerate_id(true);
    }
}

// Authentication class
class Auth {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    /**
     * Authenticate user
     */
    public function login($email, $password) {
        try {
            // Get user by email only
            $stmt = $this->db->prepare("
                  SELECT id, username, email, password_hash, full_name, role, status, 
                      login_attempts, locked_until, kontinjen_id
                FROM users 
                WHERE email = :email 
                AND deleted_at IS NULL
            ");
            $stmt->execute([
                'email' => $email
            ]);
            $user = $stmt->fetch();
            
            if (!$user) {
                return ['success' => false, 'message' => 'Invalid email address or password'];
            }
            
            // Check if account is locked
            if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                $remaining = ceil((strtotime($user['locked_until']) - time()) / 60);
                return ['success' => false, 'message' => "Akaun dikunci. Cuba lagi dalam {$remaining} minit."];
            }
            
            // Check if account is active
            if ($user['status'] !== 'active') {
                return ['success' => false, 'message' => 'This account is inactive. Please contact the administrator.'];
            }

            // If user role is CONTINGENT, ensure kontinjen_id is set
            if (isset($user['role']) && $user['role'] === 'CONTINGENT') {
                if (empty($user['kontinjen_id'])) {
                    return ['success' => false, 'message' => 'The contingent account is not configured. Please contact the administrator.'];
                }
            }
            
            // Verify password
            if (empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
                // Only increment attempts if password hash exists (wrap in try-catch to prevent login failure)
                if (!empty($user['password_hash'])) {
                    try {
                        $this->incrementLoginAttempts($user['id']);
                    } catch (Exception $e) {
                        error_log("Failed to increment login attempts: " . $e->getMessage());
                        // Continue - don't let this block login attempt
                    }
                }
                return ['success' => false, 'message' => 'Invalid email address or password'];
            }
            
            // All active users with valid credentials can login
            // No role restriction - all roles (ADMIN, ORGANIZER, JUDGE, CONTINGENT, VIEWER) can login
            
            // Create session FIRST (before any database operations that might fail)
            $this->createSession($user);
            
            // Reset login attempts (wrap in try-catch to prevent login failure)
            try {
                $this->resetLoginAttempts($user['id']);
            } catch (Exception $e) {
                error_log("Reset login attempts error: " . $e->getMessage());
                // Continue - session already created
            }
            
            // Update last login (wrap in try-catch to prevent login failure)
            try {
                $this->updateLastLogin($user['id']);
            } catch (Exception $e) {
                error_log("Update last login error: " . $e->getMessage());
                // Continue - session already created
            }
            
            // Log login (non-critical, don't let it fail login)
            try {
                $this->logActivity($user['id'], 'login', 'User logged in');
            } catch (Exception $e) {
                error_log("Log activity error: " . $e->getMessage());
                // Continue - session already created
            }
            
            return ['success' => true, 'user' => $user];
            
        } catch (PDOException $e) {
            error_log("Login Error: " . $e->getMessage());
            error_log("Login Error Trace: " . $e->getTraceAsString());
            // Return more detailed error in development (remove in production)
            $errorMessage = 'System error. Please try again.';
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                $errorMessage .= ' (' . $e->getMessage() . ')';
            }
            return ['success' => false, 'message' => $errorMessage, 'error' => $e->getMessage()];
        } catch (Exception $e) {
            error_log("Login General Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'System error. Please try again.', 'error' => $e->getMessage()];
        }
    }
    
    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        return Session::has('user_id') && Session::has('user_role');
    }
    
    /**
     * Check if user has specific role
     */
    public function hasRole($role) {
        return $this->isLoggedIn() && Session::get('user_role') === $role;
    }
    
    /**
     * Require authentication
     * Redirects to login page if user is not logged in
     */
    public function requireAuth() {
        if (!$this->isLoggedIn()) {
            // Store return URL for redirect after login
            $returnUrl = $_SERVER['REQUEST_URI'] ?? url('index.php');
            
            // For AJAX requests, return JSON
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode([
                    'requires_auth' => true,
                    'redirect' => url('auth/login.php?return=' . urlencode($returnUrl))
                ]);
                exit;
            }
            
            // For regular requests, redirect to login page
            $loginUrl = url('auth/login.php');
            if ($returnUrl && $returnUrl !== url('index.php')) {
                $loginUrl .= '?return=' . urlencode($returnUrl);
            }
            header('Location: ' . $loginUrl);
            exit;
        }
    }
    
    /**
     * Require specific role
     */
    public function requireRole($role) {
        $this->requireAuth();
        if (!$this->hasRole($role)) {
            header('Location: ' . url('auth/unauthorized.php'));
            exit;
        }
    }
    
    /**
     * Get current user
     */
    public function getUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        $userId = Session::get('user_id');
        $stmt = $this->db->prepare("SELECT id, username, email, full_name, role, status FROM users WHERE id = :id AND deleted_at IS NULL");
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch();
    }
    
    /**
     * Logout user
     */
    public function logout() {
        if ($this->isLoggedIn()) {
            $userId = Session::get('user_id');
            $this->logActivity($userId, 'logout', 'User logged out');
            $this->destroySession();
        }
        Session::destroy();
    }
    
    /**
     * Increment login attempts
     */
    private function incrementLoginAttempts($userId) {
        try {
            // First, get current login attempts
            $stmt = $this->db->prepare("SELECT login_attempts FROM users WHERE id = :id");
            $stmt->execute(['id' => $userId]);
            $current = $stmt->fetch();
            $newAttempts = ($current['login_attempts'] ?? 0) + 1;
            
            // Check if we should lock the account
            $lockedUntil = null;
            if ($newAttempts >= MAX_LOGIN_ATTEMPTS) {
                $lockedUntil = date('Y-m-d H:i:s', time() + LOCKOUT_DURATION);
            }
            
            // Update with calculated values (avoid CASE statement parameter issues)
            if ($lockedUntil) {
                $stmt = $this->db->prepare("
                    UPDATE users 
                    SET login_attempts = :attempts, locked_until = :locked_until
                    WHERE id = :id
                ");
                $stmt->execute([
                    'id' => $userId,
                    'attempts' => $newAttempts,
                    'locked_until' => $lockedUntil
                ]);
            } else {
                $stmt = $this->db->prepare("
                    UPDATE users 
                    SET login_attempts = :attempts, locked_until = NULL
                    WHERE id = :id
                ");
                $stmt->execute([
                    'id' => $userId,
                    'attempts' => $newAttempts
                ]);
            }
        } catch (PDOException $e) {
            error_log("Increment Login Attempts Error: " . $e->getMessage());
            // Don't throw - just log the error
        }
    }
    
    /**
     * Reset login attempts
     */
    private function resetLoginAttempts($userId) {
        try {
            $stmt = $this->db->prepare("UPDATE users SET login_attempts = 0, locked_until = NULL WHERE id = :id");
            $stmt->execute(['id' => $userId]);
        } catch (PDOException $e) {
            error_log("Reset Login Attempts Error: " . $e->getMessage());
            // Don't throw - just log the error
        }
    }
    
    /**
     * Update last login
     */
    private function updateLastLogin($userId) {
        try {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $stmt = $this->db->prepare("
                UPDATE users 
                SET last_login = NOW(), last_login_ip = :ip 
                WHERE id = :id
            ");
            $stmt->execute(['id' => $userId, 'ip' => $ip]);
        } catch (PDOException $e) {
            error_log("Update Last Login Error: " . $e->getMessage());
            // Don't throw - just log the error
        }
    }
    
    /**
     * Create user session
     */
    private function createSession($user) {
        Session::regenerate();
        Session::set('user_id', $user['id']);
        Session::set('user_username', $user['username']);
        Session::set('user_email', $user['email']);
        Session::set('user_name', $user['full_name']);
            // Normalize and save role (ensure consistent uppercase)
            $normalizedRole = isset($user['role']) ? strtoupper($user['role']) : null;
            Session::set('user_role', $normalizedRole);
        // Save contingent id for CONTINGENT users (may be NULL for other roles)
        if (isset($user['kontinjen_id'])) {
            Session::set('kontinjen_id', $user['kontinjen_id']);
        } else {
            Session::remove('kontinjen_id');
        }
        Session::set('logged_in_at', date('Y-m-d H:i:s'));
    }
    
    /**
     * Destroy session
     */
    private function destroySession() {
        Session::remove('user_id');
        Session::remove('user_username');
        Session::remove('user_email');
        Session::remove('user_name');
        Session::remove('user_role');
        Session::remove('logged_in_at');
    }
    
    /**
     * Log user activity
     */
    private function logActivity($userId, $action, $description = null, $entityType = null, $entityId = null) {
        try {
            // Check if audit_logs table exists (use try-catch to handle if table doesn't exist)
            try {
                $checkTable = $this->db->query("SHOW TABLES LIKE 'audit_logs'");
                if ($checkTable->rowCount() == 0) {
                    // Table doesn't exist, skip logging
                    return;
                }
            } catch (PDOException $e) {
                // Table check failed, skip logging
                return;
            }
            
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            
            // Use simple INSERT with all fields (allow NULL values in database)
            $stmt = $this->db->prepare("
                INSERT INTO audit_logs (user_id, action, entity_type, entity_id, description, ip_address, user_agent)
                VALUES (:user_id, :action, :entity_type, :entity_id, :description, :ip, :user_agent)
            ");
            $stmt->execute([
                'user_id' => $userId,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'description' => $description,
                'ip' => $ip,
                'user_agent' => $userAgent
            ]);
        } catch (PDOException $e) {
            error_log("Audit Log Error: " . $e->getMessage());
            // Don't throw - logging is not critical for login
        } catch (Exception $e) {
            error_log("Audit Log General Error: " . $e->getMessage());
            // Don't throw - logging is not critical for login
        }
    }
}

// Global auth instance
function getAuth() {
    static $auth = null;
    if ($auth === null) {
        $auth = new Auth();
    }
    return $auth;
}
