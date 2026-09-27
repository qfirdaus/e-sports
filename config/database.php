<?php
/**
 * Database Configuration
 * SAM 2026 - Role-Based Access Control System
 */
require_once __DIR__ . '/SybaseConnector.php';

/**
 * Minimal .env loader for app/config/.ENV (or .env).
 * Existing process env vars are not overridden.
 */
function loadConfigEnvFiles(array $paths): void {
    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            continue;
        }
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $eqPos));
            $val = trim(substr($line, $eqPos + 1));
            if ($key === '') {
                continue;
            }
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }
            if (getenv($key) !== false) {
                continue;
            }
            putenv($key . '=' . $val);
            $_ENV[$key] = $val;
            $_SERVER[$key] = $val;
        }
    }
}

function envValue(string $key, string $default = ''): string {
    $val = getenv($key);
    if ($val === false || $val === '') {
        return $default;
    }
    return (string)$val;
}

function requiredEnv(string $key): string {
    $val = getenv($key);
    if ($val === false || trim((string)$val) === '') {
        throw new RuntimeException("Required configuration missing from .ENV: {$key}");
    }
    return (string)$val;
}

function isWindowsEnv(): bool {
    return strcasecmp((string)PHP_OS_FAMILY, 'Windows') === 0;
}

function isDockerEnv(): bool {
    if (is_file('/.dockerenv')) return true;
    $cg = @file_get_contents('/proc/1/cgroup');
    if ($cg === false) return false;
    return (strpos($cg, 'docker') !== false || strpos($cg, 'containerd') !== false);
}

function resolveMysqlProfile(): string {
    $profileRaw = getenv('MYSQL_PROFILE');
    $profile = strtolower(trim($profileRaw === false ? 'auto' : (string)$profileRaw));
    if ($profile === 'dev' || $profile === 'prod') return $profile;
    if (isWindowsEnv()) return 'prod';
    if (isDockerEnv()) return 'dev';
    return 'dev';
}

function envProfileValue(string $profile, string $name): string {
    $key = strtoupper($profile) . '_' . $name;
    return requiredEnv($key);
}

loadConfigEnvFiles([
    __DIR__ . '/.ENV',
    __DIR__ . '/.env',
]);

// Database configuration
$MYSQL_PROFILE = resolveMysqlProfile();
$DB_HOST_DEFAULT = envProfileValue($MYSQL_PROFILE, 'DB_HOST');
$DB_USER_DEFAULT = envProfileValue($MYSQL_PROFILE, 'DB_USER');
$DB_PASS_DEFAULT = envProfileValue($MYSQL_PROFILE, 'DB_PASS');
$DB_NAME_DEFAULT = envProfileValue($MYSQL_PROFILE, 'DB_NAME');
$DB_CHARSET_DEFAULT = envProfileValue($MYSQL_PROFILE, 'DB_CHARSET');

define('DB_HOST', envValue('DB_HOST', $DB_HOST_DEFAULT));
define('DB_USER', envValue('DB_USER', $DB_USER_DEFAULT));
define('DB_PASS', envValue('DB_PASS', $DB_PASS_DEFAULT));
define('DB_NAME', envValue('DB_NAME', $DB_NAME_DEFAULT));
define('DB_CHARSET', envValue('DB_CHARSET', $DB_CHARSET_DEFAULT));

// Sybase ODBC defaults (can be overridden by env vars)
define('SYBASE_ESPORTS_DSN', requiredEnv('SYBASE_ESPORTS_DSN'));
define('SYBASE_ESPORTS_USER', requiredEnv('SYBASE_ESPORTS_USER'));
define('SYBASE_ESPORTS_PASS', requiredEnv('SYBASE_ESPORTS_PASS'));
define('SYBASE_ESPORTS_HOST', requiredEnv('SYBASE_ESPORTS_HOST'));
define('SYBASE_ESPORTS_PORT', requiredEnv('SYBASE_ESPORTS_PORT'));
define('SYBASE_ESPORTS_DB', requiredEnv('SYBASE_ESPORTS_DB'));

define('SYBASE_STUDENT_DSN', requiredEnv('SYBASE_STUDENT_DSN'));
define('SYBASE_STUDENT_USER', requiredEnv('SYBASE_STUDENT_USER'));
define('SYBASE_STUDENT_PASS', requiredEnv('SYBASE_STUDENT_PASS'));
define('SYBASE_STUDENT_HOST', requiredEnv('SYBASE_STUDENT_HOST'));
define('SYBASE_STUDENT_PORT', requiredEnv('SYBASE_STUDENT_PORT'));
define('SYBASE_STUDENT_DB', requiredEnv('SYBASE_STUDENT_DB'));

// Database connection class
class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
            ];
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Database connection failed. Please check your configuration.");
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    // Prevent cloning
    private function __clone() {}
    
    // Prevent unserialization
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}

// Helper function to get database connection
function getDB() {
    return Database::getInstance()->getConnection();
}

// New dynamic Sybase PDO helpers (recommended for new code)
function getSybasePdoConnection(string $connectionName = 'default'): PDO {
    return SybaseConnector::connect($connectionName);
}

function getSybaseStudentPdoConnection(): PDO {
    return SybaseConnector::connect('student');
}

// Helper function to get Sybase (ODBC) connection
function getSybaseConnection($dsn = null, $user = null, $pass = null) {
    $dsn = $dsn ?: (getenv('SYBASE_ESPORTS_DSN') ?: SYBASE_ESPORTS_DSN);
    $user = $user ?: (getenv('SYBASE_ESPORTS_USER') ?: SYBASE_ESPORTS_USER);
    $pass = ($pass !== null) ? $pass : (getenv('SYBASE_ESPORTS_PASS') ?: SYBASE_ESPORTS_PASS);

    if (!function_exists('odbc_connect')) {
        throw new Exception('The ODBC extension is unavailable on the PHP server.');
    }

    $conn = @odbc_connect($dsn, $user, $pass);
    if (!$conn) {
        $code = function_exists('odbc_error') ? @odbc_error() : '';
        $msg = function_exists('odbc_errormsg') ? @odbc_errormsg() : '';
        throw new Exception('Sybase connection failed' . ($code ? ' [' . $code . ']' : '') . ($msg ? ': ' . $msg : '.'));
    }

    return $conn;
}

// Legacy ODBC helper for student DSN (existing ODBC code path compatibility)
function getSybaseStudentConnection($dsn = null, $user = null, $pass = null) {
    $dsn = $dsn ?: (getenv('SYBASE_STUDENT_DSN') ?: (defined('SYBASE_STUDENT_DSN') ? SYBASE_STUDENT_DSN : ''));
    $user = $user ?: (getenv('SYBASE_STUDENT_USER') ?: (defined('SYBASE_STUDENT_USER') ? SYBASE_STUDENT_USER : ''));
    $pass = ($pass !== null) ? $pass : (getenv('SYBASE_STUDENT_PASS') ?: (defined('SYBASE_STUDENT_PASS') ? SYBASE_STUDENT_PASS : ''));

    if (!function_exists('odbc_connect')) {
        throw new Exception('The ODBC extension is unavailable on the PHP server.');
    }

    $conn = @odbc_connect($dsn, $user, $pass);
    if (!$conn) {
        $code = function_exists('odbc_error') ? @odbc_error() : '';
        $msg = function_exists('odbc_errormsg') ? @odbc_errormsg() : '';
        throw new Exception('Sybase student connection failed' . ($code ? ' [' . $code . ']' : '') . ($msg ? ': ' . $msg : '.'));
    }
    return $conn;
}

function getSybaseOdbcErrorMessage($conn = null) {
    if (function_exists('odbc_errormsg')) {
        try {
            $msg = $conn ? @odbc_errormsg($conn) : @odbc_errormsg();
            if ($msg) return (string)$msg;
        } catch (Throwable $e) {
            // ignore and fallback
        }
    }
    return 'ODBC is unavailable. Ensure the ODBC extension is enabled.';
}

function sybaseOdbcPrepare($conn, $sql) {
    if (!function_exists('odbc_prepare')) {
        throw new Exception('ODBC function unavailable: odbc_prepare');
    }
    return @call_user_func('odbc_prepare', $conn, $sql);
}

function sybaseOdbcExecute($stmt, array $params = []) {
    if (!function_exists('odbc_execute')) {
        throw new Exception('ODBC function unavailable: odbc_execute');
    }
    return @call_user_func('odbc_execute', $stmt, $params);
}

function sybaseOdbcExec($conn, $sql) {
    if (!function_exists('odbc_exec')) {
        throw new Exception('ODBC function unavailable: odbc_exec');
    }
    return @call_user_func('odbc_exec', $conn, $sql);
}

function sybaseOdbcFetchArray($stmt) {
    if (!function_exists('odbc_fetch_array')) {
        throw new Exception('ODBC function unavailable: odbc_fetch_array');
    }
    return @call_user_func('odbc_fetch_array', $stmt);
}

function sybaseOdbcClose($conn) {
    if (function_exists('odbc_close')) {
        @call_user_func('odbc_close', $conn);
    }
}
