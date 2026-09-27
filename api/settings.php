<?php
/**
 * Settings API (Phase 2)
 * - Persist/load settings payload
 * - Whitelist + validation + sanitization
 * - Secrets encryption
 * - Audit log (settings changes)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/rbac.php';

Session::start();
$auth = getAuth();
$rbac = getRBAC();

if (!$auth->isLoggedIn() || !$rbac->hasPageAccess('pages/settings.php')) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Access denied.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

const SETTINGS_KEY = 'settings_page_payload_v1';
const ENC_PREFIX = 'ENC:v1:';
const FILE_STORE_ROOT = __DIR__ . '/../storage';

function ensureSettingsTable(PDO $db): void {
    $sql = "CREATE TABLE IF NOT EXISTS app_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) NOT NULL UNIQUE,
                setting_value LONGTEXT NOT NULL,
                updated_by INT NULL,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_setting_key (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
}

function currentUserId(): ?int {
    $id = Session::get('user_id');
    if ($id === null || $id === '') return null;
    return (int)$id;
}

/**
 * @return array<string, array<string, array<string, mixed>>>
 */
function settingsSchema(): array {
    return [
        'generalSettingsForm' => [
            'siteName' => ['type' => 'string', 'max' => 120],
            'siteFullName' => ['type' => 'string', 'max' => 200],
            'siteDescription' => ['type' => 'string', 'max' => 1000],
            'siteEmail' => ['type' => 'email', 'max' => 200],
            'sitePhone' => ['type' => 'string', 'max' => 50],
            'siteAddress' => ['type' => 'string', 'max' => 1000],
        ],
        'localeSettingsForm' => [
            'timezone' => ['type' => 'enum', 'values' => ['Asia/Kuala_Lumpur', 'UTC', 'Asia/Singapore', 'Asia/Jakarta', 'Asia/Bangkok']],
            'language' => ['type' => 'enum', 'values' => ['en']],
            'dateFormat' => ['type' => 'enum', 'values' => ['d/m/Y', 'Y-m-d', 'd M Y', 'j F Y']],
            'timeFormat' => ['type' => 'enum', 'values' => ['H:i', 'h:i A']],
            'currency' => ['type' => 'enum', 'values' => ['MYR', 'USD', 'SGD']],
        ],
        'tournamentSettingsForm' => [
            'tournamentName' => ['type' => 'string', 'max' => 150],
            'tournamentEdition' => ['type' => 'string', 'max' => 80],
            'tournamentStartDate' => ['type' => 'date'],
            'tournamentEndDate' => ['type' => 'date'],
            'tournamentStatus' => ['type' => 'enum', 'values' => ['upcoming', 'ongoing', 'completed', 'cancelled']],
        ],
        'venueSettingsForm' => [
            'mainVenue' => ['type' => 'string', 'max' => 150],
            'venueAddress' => ['type' => 'string', 'max' => 1000],
            'venueCity' => ['type' => 'string', 'max' => 80],
            'venueState' => ['type' => 'string', 'max' => 80],
            'venueCapacity' => ['type' => 'int', 'min' => 0, 'max' => 1000000, 'nullable' => true],
        ],
        'registrationPeriodForm' => [
            'regOpenDate' => ['type' => 'datetime'],
            'regCloseDate' => ['type' => 'datetime'],
            'regAutoClose' => ['type' => 'bool'],
            'regAllowLate' => ['type' => 'bool'],
        ],
        'feeSettingsForm' => [
            'regFeePerContingent' => ['type' => 'decimal', 'min' => 0, 'max' => 100000, 'nullable' => true],
            'regFeePerAthlete' => ['type' => 'decimal', 'min' => 0, 'max' => 100000, 'nullable' => true],
            'regFeeRequired' => ['type' => 'bool'],
        ],
        'limitSettingsForm' => [
            'maxContingents' => ['type' => 'int', 'min' => 1, 'max' => 100000, 'nullable' => true],
            'maxAthletesPerContingent' => ['type' => 'int', 'min' => 1, 'max' => 100000, 'nullable' => true],
            'maxSportsPerContingent' => ['type' => 'int', 'min' => 1, 'max' => 100000, 'nullable' => true],
            'minAthletesPerContingent' => ['type' => 'int', 'min' => 0, 'max' => 100000, 'nullable' => true],
        ],
        'requirementSettingsForm' => [
            'registrationTerms' => ['type' => 'string', 'max' => 4000],
            'requiredDocuments[]' => ['type' => 'array_enum', 'values' => ['ic_copy', 'photo', 'medical', 'consent_letter', 'student_card', 'payment_receipt', 'other']],
        ],
        'emailNotificationForm' => [
            'emailEnabled' => ['type' => 'bool'],
            'smtpHost' => ['type' => 'string', 'max' => 200],
            'smtpPort' => ['type' => 'int', 'min' => 1, 'max' => 65535, 'nullable' => true],
            'smtpUsername' => ['type' => 'string', 'max' => 200],
            'smtpPassword' => ['type' => 'secret', 'max' => 500],
            'emailFrom' => ['type' => 'email', 'max' => 200],
            'emailFromName' => ['type' => 'string', 'max' => 200],
        ],
        'smsNotificationForm' => [
            'smsEnabled' => ['type' => 'bool'],
            'smsProvider' => ['type' => 'enum', 'values' => ['', 'twilio', 'nexmo', 'custom']],
            'smsApiKey' => ['type' => 'secret', 'max' => 500],
            'smsSenderId' => ['type' => 'string', 'max' => 30],
        ],
        'notificationTypesForm' => [
            'notificationTypes[]' => ['type' => 'array_enum', 'values' => ['registration', 'results', 'schedule', 'reminder']],
        ],
        'securitySettingsForm' => [
            'twoFactorAuth' => ['type' => 'bool'],
            'sessionTimeout' => ['type' => 'int', 'min' => 5, 'max' => 480],
            'passwordMinLength' => ['type' => 'int', 'min' => 6, 'max' => 20],
            'passwordRequireUppercase' => ['type' => 'bool'],
            'passwordRequireNumber' => ['type' => 'bool'],
            'passwordRequireSpecial' => ['type' => 'bool'],
        ],
        'permissionsForm' => [
            'permissions[]' => ['type' => 'array_enum', 'values' => ['view_results', 'edit_own_data', 'upload_documents', 'enter_results', 'view_schedule']],
        ],
        'logoSettingsForm' => [
            'headerLogoPath' => ['type' => 'string', 'max' => 255],
            'faviconPath' => ['type' => 'string', 'max' => 255],
            'backgroundImagePath' => ['type' => 'string', 'max' => 255],
        ],
        'themeSettingsForm' => [
            'primaryColor' => ['type' => 'regex', 'pattern' => '/^#[0-9a-fA-F]{6}$/'],
            'themeMode' => ['type' => 'enum', 'values' => ['light', 'dark', 'auto']],
            'navbarStyle' => ['type' => 'enum', 'values' => ['dark', 'light', 'primary']],
        ],
        'backupSettingsForm' => [
            'autoBackup' => ['type' => 'enum', 'values' => ['disabled', 'daily', 'weekly', 'monthly']],
            'backupRetention' => ['type' => 'int', 'min' => 1, 'max' => 365],
        ],
        'maintenanceForm' => [
            'maintenanceMode' => ['type' => 'bool'],
            'maintenanceMessage' => ['type' => 'string', 'max' => 1000],
        ],
        'logSettingsForm' => [
            'enableAuditLog' => ['type' => 'bool'],
            'logRetention' => ['type' => 'int', 'min' => 1, 'max' => 365],
            'logTypes[]' => ['type' => 'array_enum', 'values' => ['login', 'data_change', 'settings']],
        ],
    ];
}

function encryptionKey(): ?string {
    $k = getenv('SETTINGS_ENCRYPTION_KEY');
    if ($k === false || trim((string)$k) === '') return null;
    return (string)$k;
}

function encryptSecret(string $plain, string $key): string {
    $method = 'AES-256-CBC';
    $iv = random_bytes(16);
    $k = hash('sha256', $key, true);
    $cipher = openssl_encrypt($plain, $method, $k, OPENSSL_RAW_DATA, $iv);
    if ($cipher === false) throw new RuntimeException('Unable to encrypt the secret.');
    return ENC_PREFIX . base64_encode($iv) . ':' . base64_encode($cipher);
}

function decryptSecret(string $raw, ?string $key): string {
    if (!str_starts_with($raw, ENC_PREFIX)) return $raw;
    if (!$key) return '';
    $parts = explode(':', substr($raw, strlen(ENC_PREFIX)), 2);
    if (count($parts) !== 2) return '';
    $iv = base64_decode($parts[0], true);
    $cipher = base64_decode($parts[1], true);
    if ($iv === false || $cipher === false) return '';
    $k = hash('sha256', $key, true);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $k, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : (string)$plain;
}

/**
 * @return array{data: array<string, mixed>, errors: array<int, string>}
 */
function validateAndSanitizePayload(array $input, array $schema, ?array $previous): array {
    $out = [];
    $errors = [];
    $encKey = encryptionKey();

    foreach ($schema as $formId => $fields) {
        $srcForm = (isset($input[$formId]) && is_array($input[$formId])) ? $input[$formId] : [];
        $prevForm = (isset($previous[$formId]) && is_array($previous[$formId])) ? $previous[$formId] : [];
        $cleanForm = [];

        foreach ($fields as $field => $rule) {
            $type = (string)($rule['type'] ?? 'string');
            $nullable = !empty($rule['nullable']);
            $val = $srcForm[$field] ?? null;

            if ($type === 'bool') {
                $cleanForm[$field] = (bool)$val;
                continue;
            }

            if ($type === 'array_enum') {
                $arr = is_array($val) ? $val : [];
                $arr = array_values(array_unique(array_map(static fn($x) => trim((string)$x), $arr)));
                $allowed = $rule['values'] ?? [];
                $arr = array_values(array_filter($arr, static fn($x) => in_array($x, $allowed, true)));
                $cleanForm[$field] = $arr;
                continue;
            }

            $s = trim((string)($val ?? ''));
            if ($s === '' && $nullable) {
                $cleanForm[$field] = '';
                continue;
            }

            if ($type === 'email') {
                if ($s !== '' && filter_var($s, FILTER_VALIDATE_EMAIL) === false) {
                    $errors[] = "{$formId}.{$field} is invalid.";
                }
                $max = (int)($rule['max'] ?? 255);
                $cleanForm[$field] = mb_substr($s, 0, $max);
                continue;
            }

            if ($type === 'enum') {
                $allowed = $rule['values'] ?? [];
                if (!in_array($s, $allowed, true)) {
                    $errors[] = "{$formId}.{$field} is invalid.";
                    continue;
                }
                $cleanForm[$field] = $s;
                continue;
            }

            if ($type === 'date') {
                if ($s !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
                    $errors[] = "{$formId}.{$field} has an invalid date format.";
                }
                $cleanForm[$field] = $s;
                continue;
            }

            if ($type === 'datetime') {
                if ($s !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $s)) {
                    $errors[] = "{$formId}.{$field} has an invalid date/time format.";
                }
                $cleanForm[$field] = $s;
                continue;
            }

            if ($type === 'int') {
                if ($s === '' && $nullable) {
                    $cleanForm[$field] = '';
                    continue;
                }
                if (!preg_match('/^-?\d+$/', $s)) {
                    $errors[] = "{$formId}.{$field} must be a whole number.";
                    continue;
                }
                $n = (int)$s;
                if (isset($rule['min']) && $n < (int)$rule['min']) $errors[] = "{$formId}.{$field} terlalu kecil.";
                if (isset($rule['max']) && $n > (int)$rule['max']) $errors[] = "{$formId}.{$field} terlalu besar.";
                $cleanForm[$field] = (string)$n;
                continue;
            }

            if ($type === 'decimal') {
                if ($s === '' && $nullable) {
                    $cleanForm[$field] = '';
                    continue;
                }
                if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) {
                    $errors[] = "{$formId}.{$field} must be a valid decimal number.";
                    continue;
                }
                $n = (float)$s;
                if (isset($rule['min']) && $n < (float)$rule['min']) $errors[] = "{$formId}.{$field} terlalu kecil.";
                if (isset($rule['max']) && $n > (float)$rule['max']) $errors[] = "{$formId}.{$field} terlalu besar.";
                $cleanForm[$field] = number_format($n, 2, '.', '');
                continue;
            }

            if ($type === 'regex') {
                $pattern = (string)($rule['pattern'] ?? '//');
                if ($s !== '' && !preg_match($pattern, $s)) {
                    $errors[] = "{$formId}.{$field} has an invalid format.";
                    continue;
                }
                $cleanForm[$field] = $s;
                continue;
            }

            if ($type === 'secret') {
                $max = (int)($rule['max'] ?? 500);
                $s = mb_substr($s, 0, $max);
                // preserve previous encrypted value if user leaves empty
                if ($s === '' && isset($prevForm[$field]) && trim((string)$prevForm[$field]) !== '') {
                    $cleanForm[$field] = (string)$prevForm[$field];
                    continue;
                }
                if ($s === '') {
                    $cleanForm[$field] = '';
                    continue;
                }
                if (!function_exists('openssl_encrypt')) {
                    $errors[] = "{$formId}.{$field} requires OpenSSL for encryption.";
                    continue;
                }
                if (!$encKey) {
                    $errors[] = "SETTINGS_ENCRYPTION_KEY must be set before saving secrets.";
                    continue;
                }
                $cleanForm[$field] = encryptSecret($s, $encKey);
                continue;
            }

            // default string sanitize
            $max = (int)($rule['max'] ?? 500);
            $s = strip_tags($s);
            $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
            $cleanForm[$field] = mb_substr($s, 0, $max);
        }

        $out[$formId] = $cleanForm;
    }

    return ['data' => $out, 'errors' => $errors];
}

/**
 * @return array<string, mixed>
 */
function decodeStoredPayload(?string $raw): array {
    if (!$raw) return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * @return array<string, mixed>
 */
function decodeForClient(array $stored, array $schema): array {
    $encKey = encryptionKey();
    $out = $stored;
    foreach ($schema as $formId => $fields) {
        if (!isset($out[$formId]) || !is_array($out[$formId])) continue;
        foreach ($fields as $field => $rule) {
            if (($rule['type'] ?? '') !== 'secret') continue;
            $val = (string)($out[$formId][$field] ?? '');
            $out[$formId][$field] = decryptSecret($val, $encKey);
        }
    }
    return $out;
}

function flattenAssoc(array $data, string $prefix = ''): array {
    $out = [];
    foreach ($data as $k => $v) {
        $key = $prefix === '' ? (string)$k : ($prefix . '.' . $k);
        if (is_array($v)) {
            $out += flattenAssoc($v, $key);
        } else {
            $out[$key] = (string)$v;
        }
    }
    return $out;
}

function writeSettingsAudit(PDO $db, array $before, array $after): void {
    try {
        $chk = $db->query("SHOW TABLES LIKE 'audit_logs'");
        if (!$chk || $chk->rowCount() === 0) return;
        $b = flattenAssoc($before);
        $a = flattenAssoc($after);
        $changed = [];
        $allKeys = array_values(array_unique(array_merge(array_keys($b), array_keys($a))));
        foreach ($allKeys as $k) {
            $ov = $b[$k] ?? '';
            $nv = $a[$k] ?? '';
            if ($ov !== $nv) $changed[] = $k;
        }
        if (empty($changed)) return;

        $desc = 'Settings updated (' . count($changed) . ' fields): ' . implode(', ', array_slice($changed, 0, 20));
        if (count($changed) > 20) $desc .= ' ...';

        $st = $db->prepare("
            INSERT INTO audit_logs (user_id, action, entity_type, entity_id, description, ip_address, user_agent)
            VALUES (:user_id, :action, :entity_type, :entity_id, :description, :ip, :ua)
        ");
        $st->execute([
            ':user_id' => currentUserId(),
            ':action' => 'settings_update',
            ':entity_type' => 'settings',
            ':entity_id' => SETTINGS_KEY,
            ':description' => mb_substr($desc, 0, 1000),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        ]);
    } catch (Throwable $e) {
        error_log('[settings-api][audit] ' . $e->getMessage());
    }
}

function ensureDir(string $path): void {
    if (!is_dir($path)) {
        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create directory: ' . $path);
        }
    }
}

function getStoredSettings(PDO $db): array {
    $st = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = :k LIMIT 1");
    $st->execute([':k' => SETTINGS_KEY]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return decodeStoredPayload($row['setting_value'] ?? null);
}

function handleSmtpTest(): void {
    $raw = file_get_contents('php://input');
    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
        throw new InvalidArgumentException('Invalid SMTP test payload.');
    }
    $host = trim((string)($json['smtpHost'] ?? ''));
    $port = (int)($json['smtpPort'] ?? 0);
    if ($host === '' || $port <= 0 || $port > 65535) {
        throw new InvalidArgumentException('Invalid SMTP host or port.');
    }
    $timeout = 5;
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!$fp) {
        throw new RuntimeException("SMTP connection failed: {$host}:{$port} ({$errno}) {$errstr}");
    }
    fclose($fp);
    echo json_encode(['ok' => true, 'message' => "SMTP {$host}:{$port} is reachable."]);
    exit;
}

/**
 * @return array<int, string>
 */
function getExistingTables(PDO $db, array $tables): array {
    $out = [];
    foreach ($tables as $t) {
        try {
            $st = $db->query("SHOW TABLES LIKE " . $db->quote($t));
            if ($st && $st->rowCount() > 0) $out[] = $t;
        } catch (Throwable $e) {
            // ignore table check errors per table
        }
    }
    return $out;
}

function handleCreateBackup(PDO $db): void {
    $backupDir = FILE_STORE_ROOT . '/backups';
    ensureDir($backupDir);
    $stamp = date('Ymd_His');

    $dbName = DB_NAME;
    $host = DB_HOST;
    $user = DB_USER;
    $pass = DB_PASS;

    $outfileSql = $backupDir . "/backup_{$stamp}.sql";
    $mysqldump = stripos(PHP_OS_FAMILY, 'Windows') === 0 ? 'mysqldump.exe' : 'mysqldump';
    $cmd = $mysqldump
        . ' --single-transaction --quick --skip-lock-tables'
        . ' -h ' . escapeshellarg($host)
        . ' -u ' . escapeshellarg($user)
        . ' -p' . escapeshellarg($pass)
        . ' ' . escapeshellarg($dbName)
        . ' > ' . escapeshellarg($outfileSql) . ' 2>&1';

    $output = @shell_exec($cmd);
    if (is_file($outfileSql) && filesize($outfileSql) > 0) {
        $file = basename($outfileSql);
        echo json_encode([
            'ok' => true,
            'message' => 'SQL backup generated successfully.',
            'download_url' => url('api/settings.php?action=download_file&file=' . rawurlencode($file)),
            'file' => $file,
            'mode' => 'sql',
        ]);
        exit;
    }

    // Fallback JSON backup
    $outfileJson = $backupDir . "/backup_{$stamp}.json";
    $coreTables = [
        'users', 'table_ref_universiti', 'table_kontinjen', 'table_sukan', 'table_kategori',
        'table_pasukan', 'table_pasukan_atlet', 'table_pasukan_pengurus', 'table_pasukan_jurulatih',
        'app_settings', 'audit_logs'
    ];
    $tables = getExistingTables($db, $coreTables);
    $payload = ['generated_at' => date('c'), 'database' => DB_NAME, 'tables' => []];
    foreach ($tables as $t) {
        try {
            $rows = $db->query("SELECT * FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $payload['tables'][$t] = $rows;
        } catch (Throwable $e) {
            $payload['tables'][$t] = ['_error' => $e->getMessage()];
        }
    }
    file_put_contents($outfileJson, json_encode($payload, JSON_UNESCAPED_UNICODE));
    $file = basename($outfileJson);
    echo json_encode([
        'ok' => true,
        'message' => 'Backup fallback JSON dijana.',
        'download_url' => url('api/settings.php?action=download_file&file=' . rawurlencode($file)),
        'file' => $file,
        'mode' => 'json',
        'note' => trim((string)$output),
    ]);
    exit;
}

function handleExportData(PDO $db): void {
    $exportDir = FILE_STORE_ROOT . '/exports';
    ensureDir($exportDir);
    $stamp = date('Ymd_His');
    $zipPath = $exportDir . "/export_{$stamp}.zip";
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Unable to create the export ZIP file.');
    }

    $tables = getExistingTables($db, [
        'table_ref_universiti', 'table_kontinjen', 'table_sukan', 'table_kategori',
        'table_pasukan', 'table_pasukan_atlet', 'table_pasukan_pengurus', 'table_pasukan_jurulatih',
        'users'
    ]);

    foreach ($tables as $t) {
        $rows = $db->query("SELECT * FROM `{$t}`")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (empty($rows)) {
            $zip->addFromString($t . '.csv', '');
            continue;
        }
        $headers = array_keys($rows[0]);
        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, $headers);
        foreach ($rows as $r) {
            $line = [];
            foreach ($headers as $h) $line[] = $r[$h] ?? '';
            fputcsv($csv, $line);
        }
        rewind($csv);
        $content = stream_get_contents($csv);
        fclose($csv);
        $zip->addFromString($t . '.csv', $content ?: '');
    }

    $zip->close();
    $file = basename($zipPath);
    echo json_encode([
        'ok' => true,
        'message' => 'Data export generated successfully.',
        'download_url' => url('api/settings.php?action=download_file&folder=exports&file=' . rawurlencode($file)),
        'file' => $file,
    ]);
    exit;
}

function handleDownloadFile(): void {
    $folder = trim((string)($_GET['folder'] ?? 'backups'));
    $allowedFolders = ['backups', 'exports'];
    if (!in_array($folder, $allowedFolders, true)) $folder = 'backups';
    $file = basename((string)($_GET['file'] ?? ''));
    if ($file === '') {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid filename.']);
        exit;
    }
    $path = FILE_STORE_ROOT . '/' . $folder . '/' . $file;
    if (!is_file($path)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'File not found.']);
        exit;
    }
    $mime = 'application/octet-stream';
    if (str_ends_with(strtolower($file), '.sql')) $mime = 'application/sql';
    if (str_ends_with(strtolower($file), '.json')) $mime = 'application/json';
    if (str_ends_with(strtolower($file), '.zip')) $mime = 'application/zip';
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

function handleViewLogs(PDO $db): void {
    $st = $db->prepare("SELECT id, user_id, action, entity_type, entity_id, description, ip_address, created_at FROM audit_logs ORDER BY id DESC LIMIT 200");
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['ok' => true, 'rows' => $rows]);
    exit;
}

function handleClearLogs(PDO $db): void {
    $raw = file_get_contents('php://input');
    $json = json_decode((string)$raw, true);
    $days = (int)($json['days'] ?? app_setting('logSettingsForm.logRetention', 90));
    if ($days < 1) $days = 1;
    if ($days > 3650) $days = 3650;
    $st = $db->prepare("DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL :d DAY)");
    $st->bindValue(':d', $days, PDO::PARAM_INT);
    $st->execute();
    echo json_encode(['ok' => true, 'message' => 'Old logs cleared successfully.', 'deleted' => $st->rowCount()]);
    exit;
}

function handleUploadAsset(PDO $db): void {
    $type = trim((string)($_POST['asset_type'] ?? ''));
    if ($type === '') throw new InvalidArgumentException('asset_type is required.');
    if (empty($_FILES['asset_file']) || !is_array($_FILES['asset_file'])) {
        throw new InvalidArgumentException('No asset file was received.');
    }
    $f = $_FILES['asset_file'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('File upload error: ' . (int)$f['error']);
    }

    $rules = [
        'headerLogo' => [
            'max' => 3 * 1024 * 1024,
            'ext' => ['png', 'jpg', 'jpeg', 'webp', 'svg'],
            'mime' => ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml'],
        ],
        'favicon' => [
            'max' => 1 * 1024 * 1024,
            'ext' => ['ico', 'png', 'svg'],
            'mime' => ['image/x-icon', 'image/vnd.microsoft.icon', 'image/png', 'image/svg+xml'],
        ],
        'backgroundImage' => [
            'max' => 5 * 1024 * 1024,
            'ext' => ['png', 'jpg', 'jpeg', 'webp'],
            'mime' => ['image/png', 'image/jpeg', 'image/webp'],
        ],
    ];
    if (!isset($rules[$type])) {
        throw new InvalidArgumentException('Invalid asset_type.');
    }
    $rule = $rules[$type];
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0) throw new RuntimeException('Invalid file size.');
    if ($size > (int)$rule['max']) {
        $maxMb = number_format(((int)$rule['max']) / (1024 * 1024), 0);
        throw new RuntimeException('The file size exceeds ' . $maxMb . 'MB.');
    }

    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $rule['ext'], true)) {
        throw new RuntimeException('File format not allowed.');
    }
    $tmpPath = (string)$f['tmp_name'];
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Invalid uploaded file.');
    }
    $detectedMime = '';
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi !== false) {
            $detected = finfo_file($fi, $tmpPath);
            finfo_close($fi);
            $detectedMime = is_string($detected) ? strtolower(trim($detected)) : '';
        }
    } elseif (function_exists('mime_content_type')) {
        $detected = @mime_content_type($tmpPath);
        $detectedMime = is_string($detected) ? strtolower(trim($detected)) : '';
    }
    if ($detectedMime === '') {
        throw new RuntimeException('Unable to detect the uploaded file MIME type.');
    }
    if (!in_array($detectedMime, $rule['mime'], true)) {
        // allow jpg/jpeg alias
        $isJpgAlias = in_array($detectedMime, ['image/jpg', 'image/pjpeg'], true) && in_array('image/jpeg', $rule['mime'], true);
        if (!$isJpgAlias) {
            throw new RuntimeException('File MIME type not allowed: ' . $detectedMime);
        }
    }

    $targetDirRel = 'assets/img/logos';
    $settingField = '';
    if ($type === 'headerLogo') $settingField = 'headerLogoPath';
    elseif ($type === 'favicon') $settingField = 'faviconPath';
    elseif ($type === 'backgroundImage') {
        $settingField = 'backgroundImagePath';
        $targetDirRel = 'assets/img/backgrounds';
    }

    $targetDir = __DIR__ . '/../' . $targetDirRel;
    ensureDir($targetDir);
    $safeName = $type . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetFile = $targetDir . '/' . $safeName;
    if (!@move_uploaded_file((string)$f['tmp_name'], $targetFile)) {
        throw new RuntimeException('Unable to save the uploaded file.');
    }

    // persist into settings payload
    $stored = getStoredSettings($db);
    if (!isset($stored['logoSettingsForm']) || !is_array($stored['logoSettingsForm'])) $stored['logoSettingsForm'] = [];
    $stored['logoSettingsForm'][$settingField] = $safeName;
    $encoded = json_encode($stored, JSON_UNESCAPED_UNICODE);
    $st = $db->prepare("
        INSERT INTO app_settings (setting_key, setting_value, updated_by)
        VALUES (:k, :v, :u)
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_by = VALUES(updated_by),
            updated_at = CURRENT_TIMESTAMP
    ");
    $st->execute([
        ':k' => SETTINGS_KEY,
        ':v' => $encoded,
        ':u' => currentUserId(),
    ]);

    echo json_encode([
        'ok' => true,
        'message' => 'File uploaded successfully.',
        'field' => $settingField,
        'filename' => $safeName,
        'url' => url($targetDirRel . '/' . $safeName),
    ]);
    exit;
}

try {
    $db = getDB();
    ensureSettingsTable($db);
    $schema = settingsSchema();
    $action = strtolower(trim((string)($_GET['action'] ?? '')));

    if ($action === 'download_file' && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        handleDownloadFile();
    }
    if ($action === 'test_smtp' && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        handleSmtpTest();
    }
    if ($action === 'create_backup' && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        handleCreateBackup($db);
    }
    if ($action === 'export_data' && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        handleExportData($db);
    }
    if ($action === 'view_logs' && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        handleViewLogs($db);
    }
    if ($action === 'clear_logs' && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        handleClearLogs($db);
    }
    if ($action === 'upload_asset' && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        handleUploadAsset($db);
    }

    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'GET') {
        $st = $db->prepare("SELECT setting_value, updated_at FROM app_settings WHERE setting_key = :k LIMIT 1");
        $st->execute([':k' => SETTINGS_KEY]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $storedData = decodeStoredPayload($row['setting_value'] ?? null);
        $data = decodeForClient($storedData, $schema);
        $data['localeSettingsForm']['language'] = 'en';
        echo json_encode([
            'ok' => true,
            'data' => $data,
            'updated_at' => $row['updated_at'] ?? null,
        ]);
        exit;
    }

    if ($method === 'POST' || $method === 'PUT') {
        $raw = file_get_contents('php://input');
        $json = json_decode((string)$raw, true);
        if (!is_array($json)) {
            throw new InvalidArgumentException('Invalid payload.');
        }
        $payload = $json['data'] ?? null;
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Settings data must be an object.');
        }

        $stPrev = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = :k LIMIT 1");
        $stPrev->execute([':k' => SETTINGS_KEY]);
        $rowPrev = $stPrev->fetch(PDO::FETCH_ASSOC);
        $prevStored = decodeStoredPayload($rowPrev['setting_value'] ?? null);

        $payload['localeSettingsForm']['language'] = 'en';
        $validated = validateAndSanitizePayload($payload, $schema, $prevStored);
        if (!empty($validated['errors'])) {
            http_response_code(422);
            echo json_encode([
                'ok' => false,
                'error' => 'Validation failed.',
                'errors' => $validated['errors'],
            ]);
            exit;
        }
        $toStore = $validated['data'];

        $encoded = json_encode($toStore, JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode settings data.');
        }
        if (strlen($encoded) > 2_000_000) {
            throw new RuntimeException('Settings data is too large.');
        }

        $st = $db->prepare("
            INSERT INTO app_settings (setting_key, setting_value, updated_by)
            VALUES (:k, :v, :u)
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP
        ");
        $st->execute([
            ':k' => SETTINGS_KEY,
            ':v' => $encoded,
            ':u' => currentUserId(),
        ]);

        writeSettingsAudit($db, decodeForClient($prevStored, $schema), decodeForClient($toStore, $schema));

        echo json_encode(['ok' => true, 'message' => 'Settings saved successfully.']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Unsupported method.']);
} catch (Throwable $e) {
    error_log('[settings-api] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
