<?php
/**
 * Production-grade dynamic Sybase connector.
 * - Windows: prefer PDO ODBC
 * - Linux/Docker: prefer PDO dblib, fallback PDO ODBC
 */
class SybaseConnector
{
    /**
     * @var array<string, PDO>
     */
    private static array $pool = [];

    public static function isWindows(): bool
    {
        $family = (string)(PHP_OS_FAMILY ?? '');
        if ($family !== '') {
            return strcasecmp($family, 'Windows') === 0;
        }
        return stripos((string)PHP_OS, 'WIN') === 0;
    }

    public static function isLinux(): bool
    {
        $family = (string)(PHP_OS_FAMILY ?? '');
        if ($family !== '') {
            return strcasecmp($family, 'Linux') === 0;
        }
        return stripos((string)PHP_OS, 'LINUX') !== false;
    }

    /**
     * Connect to Sybase using dynamic driver selection.
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public static function connect(string $connectionName = 'default'): PDO
    {
        $configs = self::getConnectionConfigs();
        if (!isset($configs[$connectionName]['sybase'])) {
            throw new InvalidArgumentException("Konfigurasi Sybase '{$connectionName}' not found.");
        }

        $cfg = $configs[$connectionName]['sybase'];
        $driver = self::resolveDriver($cfg);
        $poolKey = $connectionName . ':' . $driver;
        if (isset(self::$pool[$poolKey])) {
            return self::$pool[$poolKey];
        }

        $dsn = self::buildDsn($cfg, $driver);
        $user = (string)($cfg['username'] ?? '');
        $pass = (string)($cfg['password'] ?? '');

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
            self::$pool[$poolKey] = $pdo;
            return $pdo;
        } catch (PDOException $e) {
            $safeMeta = [
                'connection' => $connectionName,
                'driver' => $driver,
                'host' => (string)($cfg['host'] ?? ''),
                'port' => (string)($cfg['port'] ?? ''),
                'database' => (string)($cfg['database'] ?? ''),
                'dsn_odbc' => (string)($cfg['dsn_odbc'] ?? ''),
            ];
            error_log('[SybaseConnector] Connection failed: ' . json_encode($safeMeta) . ' | ' . $e->getMessage());
            throw new RuntimeException(
                "Sybase connection failed for connection '{$connectionName}' menggunakan driver '{$driver}'.",
                0,
                $e
            );
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function getConnectionConfigs(): array
    {
        $defaultHost = getenv('SYBASE_ESPORTS_HOST') ?: '172.16.2.14';
        $defaultPort = (int)(getenv('SYBASE_ESPORTS_PORT') ?: 5004);
        $defaultDb = getenv('SYBASE_ESPORTS_DB') ?: 'ehrmdb';
        $defaultUser = getenv('SYBASE_ESPORTS_USER') ?: (defined('SYBASE_ESPORTS_USER') ? SYBASE_ESPORTS_USER : '');
        $defaultPass = getenv('SYBASE_ESPORTS_PASS') ?: (defined('SYBASE_ESPORTS_PASS') ? SYBASE_ESPORTS_PASS : '');
        $defaultDsnOdbc = getenv('SYBASE_ESPORTS_DSN') ?: (defined('SYBASE_ESPORTS_DSN') ? SYBASE_ESPORTS_DSN : '');

        $studentHost = getenv('SYBASE_STUDENT_HOST') ?: $defaultHost;
        $studentPort = (int)(getenv('SYBASE_STUDENT_PORT') ?: $defaultPort);
        $studentDb = getenv('SYBASE_STUDENT_DB')
            ?: (defined('SYBASE_STUDENT_DB') ? SYBASE_STUDENT_DB : $defaultDb);
        $studentUser = getenv('SYBASE_STUDENT_USER')
            ?: (defined('SYBASE_STUDENT_USER') ? SYBASE_STUDENT_USER : $defaultUser);
        $studentPass = getenv('SYBASE_STUDENT_PASS')
            ?: (defined('SYBASE_STUDENT_PASS') ? SYBASE_STUDENT_PASS : $defaultPass);
        $studentDsnOdbc = getenv('SYBASE_STUDENT_DSN')
            ?: (defined('SYBASE_STUDENT_DSN') ? SYBASE_STUDENT_DSN : $defaultDsnOdbc);

        $studentPreferOdbcRaw = getenv('SYBASE_STUDENT_PREFER_ODBC');
        $studentPreferOdbc = in_array(strtolower((string)$studentPreferOdbcRaw), ['1', 'true', 'yes', 'on'], true);

        return [
            'default' => [
                'sybase' => [
                    'host' => $defaultHost,
                    'port' => $defaultPort,
                    'database' => $defaultDb,
                    'username' => $defaultUser,
                    'password' => $defaultPass,
                    'dsn_odbc' => $defaultDsnOdbc,
                    'prefer_odbc' => false,
                ],
            ],
            'student' => [
                'sybase' => [
                    'host' => $studentHost,
                    'port' => $studentPort,
                    'database' => $studentDb,
                    'username' => $studentUser,
                    'password' => $studentPass,
                    'dsn_odbc' => $studentDsnOdbc,
                    // Linux/Docker default: use dblib first. Set env SYBASE_STUDENT_PREFER_ODBC=1 to force ODBC.
                    'prefer_odbc' => $studentPreferOdbc,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private static function resolveDriver(array $cfg): string
    {
        $hasDblib = extension_loaded('pdo_dblib');
        $hasOdbc = extension_loaded('pdo_odbc');

        if (self::isWindows()) {
            if ($hasOdbc) {
                return 'odbc';
            }
            throw new RuntimeException('Windows requires PDO ODBC (pdo_odbc), but the extension is unavailable.');
        }

        if (!empty($cfg['prefer_odbc'])) {
            if ($hasOdbc) {
                return 'odbc';
            }
            if ($hasDblib) {
                return 'dblib';
            }
        }

        if ($hasDblib) {
            return 'dblib';
        }
        if ($hasOdbc) {
            return 'odbc';
        }
        throw new RuntimeException('No Sybase driver is available. Enable pdo_dblib or pdo_odbc.');
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private static function buildDsn(array $cfg, string $driver): string
    {
        if ($driver === 'dblib') {
            $host = trim((string)($cfg['host'] ?? ''));
            $port = (int)($cfg['port'] ?? 0);
            $db = trim((string)($cfg['database'] ?? ''));
            if ($host === '' || $port <= 0 || $db === '') {
                throw new RuntimeException('Incomplete Sybase dblib configuration (host/port/database).');
            }
            return "dblib:host={$host}:{$port};dbname={$db}";
        }

        $dsnOdbc = trim((string)($cfg['dsn_odbc'] ?? ''));
        if ($dsnOdbc === '') {
            throw new RuntimeException('Sybase ODBC configuration is incomplete (dsn_odbc).');
        }
        return "odbc:{$dsnOdbc}";
    }
}
