<?php
/**
 * Participation Certificates
 * Access: ADMIN, CONTINGENT, VIEWER
 *
 * This page reuses the managers/coaches AJAX endpoint from pages/ringkasan.php
 * to avoid duplicating SQL/business logic. It also queries athletes directly
 * for athlete certificates. Access controlled via RBAC same as other pages.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/rbac.php';

// Keep AJAX responses JSON-clean in production (avoid HTML warnings breaking JSON.parse)
@ini_set('display_errors', 0);
@ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

Session::start();
$auth = getAuth();
$auth->requireAuth();
$rbac = getRBAC();
$rbac->requirePageAccess('pages/sijil.php');

$page_title = 'Participation Certificates';

function getSettingsPayloadRaw(PDO $db): array {
    try {
        $st = $db->prepare("SELECT setting_value FROM app_settings WHERE setting_key = :k LIMIT 1");
        $st->execute([':k' => 'settings_page_payload_v1']);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !isset($row['setting_value'])) return [];
        $decoded = json_decode((string)$row['setting_value'], true);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable $e) {
        return [];
    }
}

function decryptSettingsSecretValue(string $raw): string {
    if (!str_starts_with($raw, 'ENC:v1:')) return $raw;
    $key = getenv('SETTINGS_ENCRYPTION_KEY');
    if ($key === false || trim((string)$key) === '') return '';
    $parts = explode(':', substr($raw, strlen('ENC:v1:')), 2);
    if (count($parts) !== 2) return '';
    $iv = base64_decode($parts[0], true);
    $cipher = base64_decode($parts[1], true);
    if ($iv === false || $cipher === false) return '';
    $k = hash('sha256', (string)$key, true);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $k, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? '' : (string)$plain;
}

function getEmailNotificationSettings(PDO $db): array {
    $payload = getSettingsPayloadRaw($db);
    $f = (isset($payload['emailNotificationForm']) && is_array($payload['emailNotificationForm'])) ? $payload['emailNotificationForm'] : [];
    $smtpPasswordRaw = trim((string)($f['smtpPassword'] ?? ''));
    $smtpPassword = decryptSettingsSecretValue($smtpPasswordRaw);
    if ($smtpPassword === '' && $smtpPasswordRaw !== '') {
        $smtpPassword = $smtpPasswordRaw;
    }
    return [
        'enabled' => (bool)($f['emailEnabled'] ?? false),
        'host' => trim((string)($f['smtpHost'] ?? '')),
        'port' => (int)($f['smtpPort'] ?? 0),
        'username' => trim((string)($f['smtpUsername'] ?? '')),
        'password' => $smtpPassword,
        'from_email' => trim((string)($f['emailFrom'] ?? '')),
        'from_name' => trim((string)($f['emailFromName'] ?? 'System SAM2026')),
    ];
}

function smtpReadResponse($fp): string {
    $data = '';
    while (!feof($fp)) {
        $line = fgets($fp, 515);
        if ($line === false) break;
        $data .= $line;
        if (preg_match('/^\d{3}\s/', $line)) break;
    }
    return $data;
}

function smtpExpectCode($fp, array $codes): string {
    $resp = smtpReadResponse($fp);
    $code = (int)substr($resp, 0, 3);
    if (!in_array($code, $codes, true)) {
        throw new RuntimeException('Unexpected SMTP response: ' . trim($resp));
    }
    return $resp;
}

function smtpSendCmd($fp, string $cmd, array $okCodes): string {
    fwrite($fp, $cmd . "\r\n");
    return smtpExpectCode($fp, $okCodes);
}

function smtpSendMailWithAttachment(array $smtp, string $toEmail, string $toName, string $subject, string $htmlBody, string $attachName, string $attachBinary): void {
    $host = (string)$smtp['host'];
    $port = (int)$smtp['port'];
    $username = (string)$smtp['username'];
    $password = (string)$smtp['password'];
    $fromEmail = (string)$smtp['from_email'];
    $fromName = (string)$smtp['from_name'];

    if ($host === '' || $port <= 0 || $fromEmail === '') {
        throw new RuntimeException('SMTP settings are incomplete.');
    }

    $transportHost = ($port === 465) ? ('ssl://' . $host) : $host;
    $errno = 0;
    $errstr = '';
    $fp = @fsockopen($transportHost, $port, $errno, $errstr, 15);
    if (!$fp) {
        throw new RuntimeException("Failed sambung SMTP {$host}:{$port} ({$errno}) {$errstr}");
    }

    try {
        smtpExpectCode($fp, [220]);
        smtpSendCmd($fp, 'EHLO sam2026.local', [250]);

        if ($port === 587) {
            smtpSendCmd($fp, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Unable to enable STARTTLS.');
            }
            smtpSendCmd($fp, 'EHLO sam2026.local', [250]);
        }

        if ($username !== '') {
            smtpSendCmd($fp, 'AUTH LOGIN', [334]);
            smtpSendCmd($fp, base64_encode($username), [334]);
            smtpSendCmd($fp, base64_encode($password), [235]);
        }

        smtpSendCmd($fp, 'MAIL FROM:<' . $fromEmail . '>', [250]);
        smtpSendCmd($fp, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
        smtpSendCmd($fp, 'DATA', [354]);

        $boundary = '=_SAM2026_' . bin2hex(random_bytes(8));
        $fromHeaderName = mb_encode_mimeheader($fromName, 'UTF-8');
        $toHeaderName = mb_encode_mimeheader($toName, 'UTF-8');
        $subjectEnc = mb_encode_mimeheader($subject, 'UTF-8');

        $headers = [];
        $headers[] = 'From: ' . $fromHeaderName . ' <' . $fromEmail . '>';
        $headers[] = 'To: ' . $toHeaderName . ' <' . $toEmail . '>';
        $headers[] = 'Subject: ' . $subjectEnc;
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $headers[] = '';
        $headers[] = '--' . $boundary;
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $headers[] = '';
        $headers[] = chunk_split(base64_encode($htmlBody));
        $headers[] = '--' . $boundary;
        $headers[] = 'Content-Type: application/pdf; name="' . $attachName . '"';
        $headers[] = 'Content-Transfer-Encoding: base64';
        $headers[] = 'Content-Disposition: attachment; filename="' . $attachName . '"';
        $headers[] = '';
        $headers[] = chunk_split(base64_encode($attachBinary));
        $headers[] = '--' . $boundary . '--';
        $headers[] = '';
        $mime = implode("\r\n", $headers);
        fwrite($fp, $mime . "\r\n.\r\n");
        smtpExpectCode($fp, [250]);
        smtpSendCmd($fp, 'QUIT', [221]);
    } finally {
        fclose($fp);
    }
}

function buildCertificateHtmlForEmail(string $name, string $line2, string $templateAbsUrl): string {
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Participation Certificates</title>'
        . '<style>@page{size:210mm 297mm;margin:0}@media print{html,body{width:210mm;margin:0!important;padding:0!important}.page{width:210mm!important;height:297mm!important;margin:0!important;padding:0!important;border:0!important;box-sizing:border-box;break-inside:avoid;page-break-inside:avoid}.bg-img{object-fit:cover!important;left:-0.5mm!important;top:-0.5mm!important;width:211mm!important;height:298mm!important;max-width:none!important}}html,body{height:100%;margin:0;padding:0}body{background:#fff;font-family:Arial,Helvetica,sans-serif}.page{position:relative;width:210mm;height:297mm;overflow:hidden}.bg-img{position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;z-index:0}.cert-name{position:absolute;left:11%;top:38%;transform:translateY(-50%);width:78%;text-align:center;font-weight:700;color:#000;line-height:1.05;z-index:1;font-size:20px}.cert-sport{position:absolute;left:11%;top:48%;width:78%;text-align:center;color:#000;font-weight:700;z-index:1;font-size:20px}.page,.bg-img{-webkit-print-color-adjust:exact;print-color-adjust:exact}</style></head><body>'
        . '<div class="page">'
        . '<img class="bg-img" src="' . htmlspecialchars($templateAbsUrl, ENT_QUOTES, 'UTF-8') . '" alt="background">'
        . '<div class="cert-name">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<div class="cert-sport">' . htmlspecialchars($line2, ENT_QUOTES, 'UTF-8') . '</div>'
        . '</div></body></html>';
}

function imageFileToDataUri(string $absPath): ?string {
    if (!is_file($absPath) || !is_readable($absPath)) return null;
    $bin = @file_get_contents($absPath);
    if ($bin === false || $bin === '') return null;
    $ext = strtolower((string)pathinfo($absPath, PATHINFO_EXTENSION));
    $mime = 'image/jpeg';
    if ($ext === 'png') $mime = 'image/png';
    if ($ext === 'webp') $mime = 'image/webp';
    return 'data:' . $mime . ';base64,' . base64_encode($bin);
}

function findBinaryPath(string $binaryName): ?string {
    // Allow explicit override from environment (production friendly)
    $envKey = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $binaryName)) . '_PATH';
    $envPath = trim((string)(getenv($envKey) ?: ''));
    if ($envPath !== '' && is_file($envPath)) {
        return $envPath;
    }

    // Windows common install locations (XAMPP/production)
    if (stripos(PHP_OS, 'WIN') === 0) {
        $candidates = [];
        $bn = strtolower($binaryName);
        if ($bn === 'wkhtmltopdf') {
            $candidates = [
                'C:\\Program Files\\wkhtmltopdf\\bin\\wkhtmltopdf.exe',
                'C:\\Program Files (x86)\\wkhtmltopdf\\bin\\wkhtmltopdf.exe',
            ];
        } elseif ($bn === 'chromium' || $bn === 'chromium-browser') {
            $candidates = [
                'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
                'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
                'C:\\Program Files\\Chromium\\Application\\chrome.exe',
                'C:\\Program Files (x86)\\Chromium\\Application\\chrome.exe',
                'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
                'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
            ];
        }
        foreach ($candidates as $p) {
            if (is_file($p)) return $p;
        }
    }

    $whichCmd = (stripos(PHP_OS, 'WIN') === 0) ? 'where' : 'command -v';
    $rawPath = '';
    if (function_exists('shell_exec')) {
        $tmp = @shell_exec($whichCmd . ' ' . $binaryName);
        $rawPath = is_null($tmp) ? '' : (string)$tmp;
    }
    $pathCheck = trim($rawPath);
    if ($pathCheck === '') return null;
    $first = trim(explode("\n", $pathCheck)[0]);
    return $first !== '' ? $first : null;
}

function runCommandCapture(string $command): string {
    if (function_exists('proc_open')) {
        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $proc = @proc_open($command, $desc, $pipes);
        if (is_resource($proc)) {
            if (isset($pipes[0])) fclose($pipes[0]);
            $out = isset($pipes[1]) ? stream_get_contents($pipes[1]) : '';
            $err = isset($pipes[2]) ? stream_get_contents($pipes[2]) : '';
            if (isset($pipes[1])) fclose($pipes[1]);
            if (isset($pipes[2])) fclose($pipes[2]);
            @proc_close($proc);
            return trim((string)($out . "\n" . $err));
        }
    }
    if (function_exists('shell_exec')) {
        $res = @shell_exec($command . ' 2>&1');
        return trim((string)($res ?? ''));
    }
    return '';
}

function shellQuoteArg(string $arg): string {
    // cmd.exe on Windows does not treat single quotes as quoting characters.
    if (stripos(PHP_OS, 'WIN') === 0) {
        return '"' . str_replace('"', '\"', $arg) . '"';
    }
    return escapeshellarg($arg);
}

function shellQuoteCmd(string $binary): string {
    if (stripos(PHP_OS, 'WIN') === 0) {
        return '"' . str_replace('"', '\"', $binary) . '"';
    }
    return escapeshellcmd($binary);
}

function runPdfGenerator(string $command, string $tmpPdf): string {
    runCommandCapture($command);
    if (file_exists($tmpPdf) && filesize($tmpPdf) > 0) {
        return (string)file_get_contents($tmpPdf);
    }
    return '';
}

function renderPdfFromHtmlWithFallback(string $html, string $tmpPrefix = 'sijil_mail_'): string {
    $tmpDir = sys_get_temp_dir();
    $tmpHtml = tempnam($tmpDir, $tmpPrefix) . '.html';
    $tmpPdf = tempnam($tmpDir, $tmpPrefix) . '.pdf';
    file_put_contents($tmpHtml, $html);

    $pdf = '';
    $GLOBALS['__pdf_engine_used'] = '';
    try {
        // Primary: wkhtmltopdf
        $wkDetected = findBinaryPath('wkhtmltopdf');
        $wk = $wkDetected ?: 'wkhtmltopdf';
        $wkCmd = shellQuoteCmd($wk)
            . ' --page-size A4 --margin-top 0mm --margin-bottom 0mm --margin-left 0mm --margin-right 0mm --disable-smart-shrinking --print-media-type --enable-local-file-access '
            . shellQuoteArg($tmpHtml) . ' ' . shellQuoteArg($tmpPdf);
        $pdf = runPdfGenerator($wkCmd, $tmpPdf);
        if ($pdf !== '') $GLOBALS['__pdf_engine_used'] = 'wkhtmltopdf';

        // In Windows production, keep output stable: if wkhtmltopdf exists, do not switch engine.
        if ($pdf === '' && stripos(PHP_OS, 'WIN') === 0 && $wkDetected) {
            throw new RuntimeException('Unable to generate a PDF with wkhtmltopdf on Windows.');
        }

        // Fallback: chromium/chromium-browser headless print
        if ($pdf === '') {
            $chrome = findBinaryPath('chromium')
                ?: (findBinaryPath('chromium-browser') ?: null);
            if ($chrome) {
                $fileUrl = 'file://' . str_replace(DIRECTORY_SEPARATOR, '/', realpath($tmpHtml) ?: $tmpHtml);
                $chromeCmd = shellQuoteCmd($chrome)
                    . ' --headless --disable-gpu --no-sandbox --print-to-pdf-no-header --no-pdf-header-footer --print-to-pdf=' . shellQuoteArg($tmpPdf)
                    . ' ' . shellQuoteArg($fileUrl);
                $pdf = runPdfGenerator($chromeCmd, $tmpPdf);
                if ($pdf !== '') $GLOBALS['__pdf_engine_used'] = 'chromium';
            }
        }
    } finally {
        @unlink($tmpHtml);
        @unlink($tmpPdf);
    }

    if ($pdf === '') {
        $df = trim((string)(ini_get('disable_functions') ?: ''));
        $extra = $df !== '' ? (' disable_functions=' . $df) : '';
        throw new RuntimeException('Unable to generate the certificate PDF. Ensure wkhtmltopdf or Chrome is available and proc_open/shell_exec is enabled.' . $extra);
    }
    return $pdf;
}

function renderCertPdfBinary(string $html): string {
    return renderPdfFromHtmlWithFallback($html, 'sijil_mail_');
}

function safeSamPdfFileName(string $name): string {
    $trans = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
    $base = $trans !== false ? $trans : $name;
    $base = preg_replace('/[^A-Za-z0-9 _-]/', '', (string)$base);
    $base = preg_replace('/[\s\t\r\n]+/', '_', (string)$base);
    $base = preg_replace('/_+/', '_', (string)$base);
    $base = trim((string)$base, '_');
    if ($base === '') $base = 'PENERIMA';
    $base = strtoupper(substr($base, 0, 60));
    return 'SAM2026_' . $base . '.pdf';
}

function ensureCertificateEmailLogTable(PDO $db): void {
    $sql = "CREATE TABLE IF NOT EXISTS certificate_email_logs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                member_id BIGINT UNSIGNED NOT NULL,
                cert_type VARCHAR(30) NOT NULL,
                recipient_name VARCHAR(255) NOT NULL,
                recipient_email VARCHAR(255) NOT NULL,
                role_text VARCHAR(255) NULL,
                sent_by_user_id BIGINT UNSIGNED NULL,
                sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_member_id (member_id),
                KEY idx_cert_type (cert_type),
                KEY idx_sent_at (sent_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $db->exec($sql);
}

// load list of universities (same approach as ringkasan)
// Populate list of universities for the select (reuse ringkasan logic)
$unis = [];
try {
    $db = getDB();
    $sqlUnis = "SELECT kod_universiti, nama_pendek, nama_universiti FROM table_ref_universiti WHERE status = 1 ORDER BY COALESCE(NULLIF(nama_pendek,''), nama_universiti)";
    $stUn = $db->query($sqlUnis);
    $rowsUn = $stUn->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rowsUn as $u) {
        $kodu = trim((string)($u['kod_universiti'] ?? ''));
        $short = trim((string)($u['nama_pendek'] ?? ''));
        $full = trim((string)($u['nama_universiti'] ?? ''));
        $display = $short !== '' ? $short : ($full !== '' ? $full : $kodu);
        if ($kodu === '') continue;
        $unis[] = ['kod_universiti' => $kodu, 'nama_universiti' => $display];
    }
} catch (Exception $e) {
    $unis = [];
}
// ensure $kod is defined (may be provided via GET when returning from form)
$kod = strtoupper(trim((string)($_GET['kod'] ?? '')));
// Default to UPNM kontinjen unlupdate config file new database credential. view only and configure json for default databaseess user explicitly chooses another
if ($kod === '') {
    $kod = 'UPNM';
}

// ensure template image URL variable exists to avoid PHP notices
    if (!isset($img_url_versioned) || !$img_url_versioned) {
    $relPath = '/assets/img/sijil/sijil_atlet.jpeg';
    $fullPath = realpath(__DIR__ . '/..') . $relPath;
    $ver = @file_exists($fullPath) ? @filemtime($fullPath) : time();
    // Use app-aware URL helper so path stays valid in subfolder deployments.
    $img_url_versioned = url('assets/img/sijil/sijil_atlet.jpeg') . '?v=' . $ver;
}

$ajax = trim((string)($_GET['ajax'] ?? ''));
if ($ajax === 'athletes') {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'rows' => [], 'elapsed_ms' => null, 'error' => null, 'count' => 0];
    $k = strtoupper(trim((string)($_GET['kod'] ?? '')));
    if ($k === '') {
        echo json_encode($out);
        exit;
    }
    $t0 = microtime(true);
    try {
        $db = getDB();
        $sqlA = "SELECT pa.id AS id, pa.pasukan_id, p.sukan_id, pa.kategori_id,
                        TRIM(pa.nama) AS nama,
                        COALESCE(s.nama_sukan, '') AS sukan,
                        COALESCE(kt.nama_kategori, '') AS acara
            FROM table_pasukan_atlet pa
            JOIN table_pasukan p ON p.id = pa.pasukan_id
            LEFT JOIN table_sukan s ON s.id = p.sukan_id
            LEFT JOIN table_kategori kt ON kt.id = pa.kategori_id
            LEFT JOIN table_kontinjen k ON k.id = p.kontinjen_id
            WHERE UPPER(COALESCE(k.kod_universiti,'')) = :kod_val
              AND pa.deleted_at IS NULL
              AND p.deleted_at IS NULL
            ORDER BY s.nama_sukan, kt.nama_kategori, pa.nama";
        $stA = $db->prepare($sqlA);
        $stA->execute([':kod_val' => $k]);
        $rows = $stA->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Medal highlight mapping for athlete table:
        // - individual: standings.participant_id matches athlete id (table_pasukan_atlet.id)
        // - team event: standings.participant_id matches team id (table_pasukan.id), then highlight all athletes in that team
        if (!empty($rows)) {
            $athleteCtxExact = [];
            $teamCtxExact = [];
            $athleteCtxSukan = [];
            $teamCtxSukan = [];
            foreach ($rows as $r0) {
                $aid = (int)($r0['id'] ?? 0);
                $tid = (int)($r0['pasukan_id'] ?? 0);
                $sid = (int)($r0['sukan_id'] ?? 0);
                $kid = (int)($r0['kategori_id'] ?? 0);
                if ($sid <= 0) continue;
                if ($aid > 0) {
                    $athleteCtxExact[$sid . '|' . $kid . '|' . $aid] = true;
                    $athleteCtxSukan[$sid . '|' . $aid] = true;
                }
                if ($tid > 0) {
                    $teamCtxExact[$sid . '|' . $kid . '|' . $tid] = true;
                    $teamCtxSukan[$sid . '|' . $tid] = true;
                }
            }

            $rank = ['gold' => 3, 'silver' => 2, 'bronze' => 1];
            $athleteMedalExact = [];
            $teamMedalExact = [];
            $athleteMedalBySukan = [];
            $teamMedalBySukan = [];
            $pickBest = static function (string $curr, string $next) use ($rank): string {
                $c = $rank[$curr] ?? 0;
                $n = $rank[$next] ?? 0;
                return ($n > $c) ? $next : $curr;
            };
            $medalFromPos = static function ($pos): string {
                $p = (int)$pos;
                if ($p === 1) return 'gold';
                if ($p === 2) return 'silver';
                if ($p === 3) return 'bronze';
                return '';
            };
            $extractIds = static function ($v): array {
                if (is_array($v)) {
                    $out = [];
                    foreach ($v as $x) {
                        $n = (int)$x;
                        if ($n > 0) $out[] = $n;
                    }
                    return array_values(array_unique($out));
                }
                $s = trim((string)$v);
                if ($s === '') return [];
                if (preg_match_all('/\d+/', $s, $m)) {
                    $out = [];
                    foreach (($m[0] ?? []) as $x) {
                        $n = (int)$x;
                        if ($n > 0) $out[] = $n;
                    }
                    return array_values(array_unique($out));
                }
                return [];
            };

            $resSql = "
                SELECT sukan_id, kategori_id, standings
                FROM table_results
                WHERE deleted_at IS NULL
                  AND status = 'completed'
                  AND standings IS NOT NULL
                  AND TRIM(CAST(standings AS CHAR)) <> ''
            ";
            $resSt = $db->query($resSql);
            $resRows = $resSt ? ($resSt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
            foreach ($resRows as $rr) {
                $resSukanId = (int)($rr['sukan_id'] ?? 0);
                $resKategoriId = (int)($rr['kategori_id'] ?? 0);
                if ($resSukanId <= 0) continue;
                $decoded = json_decode((string)($rr['standings'] ?? ''), true);
                if (!is_array($decoded)) continue;
                foreach ($decoded as $item) {
                    if (!is_array($item)) continue;
                    $medal = $medalFromPos($item['position'] ?? null);
                    if ($medal === '') continue;
                    $pidList = $extractIds($item['participant_id'] ?? ($item['pasukan_id'] ?? ''));
                    foreach ($pidList as $pid) {
                        $aKeyExact = $resSukanId . '|' . $resKategoriId . '|' . $pid;
                        $tKeyExact = $resSukanId . '|' . $resKategoriId . '|' . $pid;
                        $aKeySukan = $resSukanId . '|' . $pid;
                        $tKeySukan = $resSukanId . '|' . $pid;
                        if (isset($athleteCtxExact[$aKeyExact]) || ($resKategoriId <= 0 && isset($athleteCtxSukan[$aKeySukan]))) {
                            if ($resKategoriId > 0) {
                                $prev = $athleteMedalExact[$aKeyExact] ?? '';
                                $athleteMedalExact[$aKeyExact] = $pickBest($prev, $medal);
                            } else {
                                $prev = $athleteMedalBySukan[$aKeySukan] ?? '';
                                $athleteMedalBySukan[$aKeySukan] = $pickBest($prev, $medal);
                            }
                        }
                        if (isset($teamCtxExact[$tKeyExact]) || ($resKategoriId <= 0 && isset($teamCtxSukan[$tKeySukan]))) {
                            if ($resKategoriId > 0) {
                                $prev = $teamMedalExact[$tKeyExact] ?? '';
                                $teamMedalExact[$tKeyExact] = $pickBest($prev, $medal);
                            } else {
                                $prev = $teamMedalBySukan[$tKeySukan] ?? '';
                                $teamMedalBySukan[$tKeySukan] = $pickBest($prev, $medal);
                            }
                        }
                    }
                }
            }

            foreach ($rows as &$r1) {
                $aid = (int)($r1['id'] ?? 0);
                $tid = (int)($r1['pasukan_id'] ?? 0);
                $sid = (int)($r1['sukan_id'] ?? 0);
                $kid = (int)($r1['kategori_id'] ?? 0);
                $aKeyExact = $sid . '|' . $kid . '|' . $aid;
                $tKeyExact = $sid . '|' . $kid . '|' . $tid;
                $aKeySukan = $sid . '|' . $aid;
                $tKeySukan = $sid . '|' . $tid;
                $mAth = $athleteMedalExact[$aKeyExact] ?? ($athleteMedalBySukan[$aKeySukan] ?? '');
                $mTeam = $teamMedalExact[$tKeyExact] ?? ($teamMedalBySukan[$tKeySukan] ?? '');
                $r1['medal_type'] = $mAth !== '' ? $mAth : $mTeam;
            }
            unset($r1);
        }

        $out['ok'] = true;
        $out['rows'] = $rows;
        $out['count'] = count($rows);
    } catch (Exception $e) {
        $out['ok'] = false;
        $out['error'] = $e->getMessage();
    }
    $out['elapsed_ms'] = (int)( (microtime(true) - $t0) * 1000 );
    echo json_encode($out);
    exit;
}

if ($ajax === 'penyelaras') {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'rows' => [], 'elapsed_ms' => null, 'error' => null, 'count' => 0];
    $k = strtoupper(trim((string)($_GET['kod'] ?? '')));
    if ($k === '') {
        echo json_encode($out);
        exit;
    }
    $t0 = microtime(true);
    try {
                $db = getDB();
                // Combine kontak dari table_kontinjen dan users, normalize and dedupe by key_name
                $sql = <<<SQL
SELECT
    MIN(id) AS id,
    MAX(nama) AS nama,
    MAX(emel) AS emel,
    MAX(telefon) AS telefon
FROM (
    SELECT
        id,
        UPPER(
            REGEXP_REPLACE(TRIM(nama), '[^A-Z0-9 ]', '')
        ) AS key_name,
        TRIM(nama) AS nama,
        TRIM(emel) AS emel,
        REGEXP_REPLACE(TRIM(telefon), '[^0-9]', '') AS telefon
    FROM (
                -- table_kontinjen
                SELECT
                        id,
                        nama_pegawai_untuk_dihubungi AS nama,
                        emel,
                        no_telefon AS telefon
                FROM table_kontinjen
                WHERE UPPER(COALESCE(kod_universiti,'')) = ?
                    AND deleted_at IS NULL

        UNION ALL

        -- users (join kontingen)
        SELECT
            u.id,
            u.full_name AS nama,
            u.email AS emel,
            u.phone AS telefon
        FROM users u
        JOIN table_kontinjen k ON k.id = u.kontinjen_id
        WHERE UPPER(COALESCE(k.kod_universiti,'')) = ?
          AND u.deleted_at IS NULL
          AND k.deleted_at IS NULL
    ) x
) y
GROUP BY key_name
ORDER BY nama
SQL;
        $st = $db->prepare($sql);
        // two occurrences of kod_val in the UNION -> bind twice positionally
        $st->execute([$k, $k]);
                $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                // If union returned no rows (or users table absent), fallback to table_kontinjen-only query
                if (empty($rows)) {
                    try {
                        $sql2 = "SELECT id, TRIM(nama_pegawai_untuk_dihubungi) AS nama, TRIM(emel) AS emel, TRIM(no_telefon) AS telefon FROM table_kontinjen WHERE UPPER(COALESCE(kod_universiti,'')) = ? AND deleted_at IS NULL ORDER BY nama_pegawai_untuk_dihubungi";
                        $st2 = $db->prepare($sql2);
                        $st2->execute([$k]);
                        $rows2 = $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        if (!empty($rows2)) $rows = $rows2;
                    } catch (Exception $e2) {
                        // ignore fallback error, keep original empty result
                    }
                }
        $out['ok'] = true;
        $out['rows'] = $rows;
        $out['count'] = count($rows);
    } catch (Exception $e) {
        $out['ok'] = false;
        $out['error'] = $e->getMessage();
    }
    $out['elapsed_ms'] = (int)( (microtime(true) - $t0) * 1000 );
    echo json_encode($out);
    exit;
}

// Provide a local AJAX endpoint for managers/coaches to avoid cross-page HTTP
// requests (which can return HTML Access Denied pages when RBAC blocks other
// pages). This uses the same DB helper above to return JSON.
if ($ajax === 'managers') {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'rows' => [], 'elapsed_ms' => null, 'error' => null, 'count' => 0];
    $k = strtoupper(trim((string)($_GET['kod'] ?? '')));
    if ($k === '') {
        echo json_encode($out);
        exit;
    }
    $t0 = microtime(true);
    try {
        $rows = fetch_managers_from_ringkasan($k);
        $out['ok'] = true;
        $out['rows'] = $rows;
        $out['count'] = count($rows);
    } catch (Exception $e) {
        $out['ok'] = false;
        $out['error'] = $e->getMessage();
    }
    $out['elapsed_ms'] = (int)( (microtime(true) - $t0) * 1000 );
    echo json_encode($out);
    exit;
}

// AJAX endpoint for committee members (used by ORGANISING COMMITTEE CERTIFICATES tab)
    if ($ajax === 'committee') {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'rows' => [], 'elapsed_ms' => null, 'error' => null, 'count' => 0];
    $type = strtoupper(trim((string)($_GET['type'] ?? '')));
    if ($type === '') { echo json_encode($out); exit; }
    $t0 = microtime(true);
        try {
        $db = getDB();
        ensureCertificateEmailLogTable($db);
        // Order differently depending on requested member_ref_type: STAF -> order by role_id then name; others -> order by name
        if (strtoupper($type) === 'STAF') {
            $sql = "SELECT cm.id AS id, TRIM(cm.member_name) AS member_name, TRIM(cm.member_email) AS member_email, COALESCE(cr.role_name, '') AS role_name, cm.member_ref_type, cm.member_ref_id, cm.member_phone, cm.role_id,
                           COALESCE(el.send_count, 0) AS email_send_count, el.last_sent_at AS email_last_sent_at, COALESCE(el.last_recipient_email, '') AS email_last_sent_to
                    FROM committee_members cm
                    LEFT JOIN committee_roles cr ON cr.id = cm.role_id
                    LEFT JOIN (
                        SELECT x.member_id, x.send_count, y.sent_at AS last_sent_at, y.recipient_email AS last_recipient_email
                        FROM (
                            SELECT member_id, COUNT(*) AS send_count, MAX(id) AS last_log_id
                            FROM certificate_email_logs
                            GROUP BY member_id
                        ) x
                        LEFT JOIN certificate_email_logs y
                          ON y.id = x.last_log_id
                    ) el ON el.member_id = cm.id
                    WHERE cm.deleted_at IS NULL
                      AND (cr.deleted_at IS NULL OR cr.deleted_at IS NULL)
                      AND UPPER(COALESCE(cm.member_ref_type,'')) = :ref_type
                    ORDER BY cm.role_id, cm.member_name";
        } else {
            $sql = "SELECT cm.id AS id, TRIM(cm.member_name) AS member_name, TRIM(cm.member_email) AS member_email, COALESCE(cr.role_name, '') AS role_name, cm.member_ref_type, cm.member_ref_id, cm.member_phone, cm.role_id,
                           COALESCE(el.send_count, 0) AS email_send_count, el.last_sent_at AS email_last_sent_at, COALESCE(el.last_recipient_email, '') AS email_last_sent_to
                    FROM committee_members cm
                    LEFT JOIN committee_roles cr ON cr.id = cm.role_id
                    LEFT JOIN (
                        SELECT x.member_id, x.send_count, y.sent_at AS last_sent_at, y.recipient_email AS last_recipient_email
                        FROM (
                            SELECT member_id, COUNT(*) AS send_count, MAX(id) AS last_log_id
                            FROM certificate_email_logs
                            GROUP BY member_id
                        ) x
                        LEFT JOIN certificate_email_logs y
                          ON y.id = x.last_log_id
                    ) el ON el.member_id = cm.id
                    WHERE cm.deleted_at IS NULL
                      AND (cr.deleted_at IS NULL OR cr.deleted_at IS NULL)
                      AND UPPER(COALESCE(cm.member_ref_type,'')) = :ref_type
                    ORDER BY cm.member_name";
        }
        $st = $db->prepare($sql);
        $st->execute([':ref_type' => $type]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Flag rows where member_name is not found in authoritative Sybase sources.
        if (!empty($rows) && in_array($type, ['STAF', 'PELAJAR'], true)) {
            try {
                $sybasePdo = ($type === 'STAF') ? getSybasePdoConnection('default') : getSybaseStudentPdoConnection();
                $nameExistsCache = [];
                foreach ($rows as &$rowRef) {
                    $refIdRaw = trim((string)($rowRef['member_ref_id'] ?? ''));
                    $nameRaw = trim((string)($rowRef['member_name'] ?? ''));
                    $nameKey = function_exists('mb_strtoupper') ? mb_strtoupper($nameRaw, 'UTF-8') : strtoupper($nameRaw);

                    // Primary check by unique ref_id (no staf / no matrik), fallback to name if ref_id missing.
                    $cacheKey = '';
                    $sqlName = '';
                    $params = [];

                    if ($refIdRaw !== '') {
                        if ($type === 'STAF') {
                            $cacheKey = 'RID:STAF:' . strtoupper($refIdRaw);
                            $sqlName = "SELECT TOP 1 1 AS found
                                        FROM v630staf_service_skim_all
                                        WHERE CONVERT(VARCHAR(10), ISNULL(kodstatus, '')) <> '9'
                                          AND UPPER(LTRIM(RTRIM(CONVERT(VARCHAR(50), ISNULL(nopekerja, ''))))) = ?";
                            $params = [strtoupper($refIdRaw)];
                        } else {
                            $cacheKey = 'RID:PELAJAR:' . strtoupper($refIdRaw);
                            $sqlName = "SELECT TOP 1 1 AS found
                                        FROM v210
                                        WHERE ISNULL(CONVERT(VARCHAR(10), status), '') <> '04'
                                          AND UPPER(LTRIM(RTRIM(ISNULL(CONVERT(VARCHAR(50), matrik), '')))) = ?";
                            $params = [strtoupper($refIdRaw)];
                        }
                    } elseif ($nameKey !== '') {
                        if ($type === 'STAF') {
                            $cacheKey = 'NAME:STAF:' . $nameKey;
                            $sqlName = "SELECT TOP 1 1 AS found
                                        FROM v630staf_service_skim_all
                                        WHERE CONVERT(VARCHAR(10), ISNULL(kodstatus, '')) <> '9'
                                          AND UPPER(LTRIM(RTRIM(CONVERT(VARCHAR(200), ISNULL(gelar_nama, ''))))) = ?";
                            $params = [$nameKey];
                        } else {
                            $cacheKey = 'NAME:PELAJAR:' . $nameKey;
                            $sqlName = "SELECT TOP 1 1 AS found
                                        FROM v210
                                        WHERE ISNULL(CONVERT(VARCHAR(10), status), '') <> '04'
                                          AND UPPER(LTRIM(RTRIM(CONVERT(VARCHAR(200), ISNULL(nama, ''))))) = ?";
                            $params = [$nameKey];
                        }
                    } else {
                        $rowRef['name_not_found'] = 0;
                        continue;
                    }

                    if (!array_key_exists($cacheKey, $nameExistsCache)) {
                        $stmtName = $sybasePdo->prepare($sqlName);
                        $found = false;
                        if ($stmtName) {
                            $okName = $stmtName->execute($params);
                            if ($okName) {
                                $foundRow = $stmtName->fetch(PDO::FETCH_ASSOC);
                                $found = ($foundRow !== false);
                            }
                        }
                        $nameExistsCache[$cacheKey] = $found ? 1 : 0;
                    }

                    $rowRef['name_not_found'] = ($nameExistsCache[$cacheKey] === 1) ? 0 : 1;
                }
                unset($rowRef);
            } catch (Exception $eNameCheck) {
                // If name-check fails, do not falsely highlight all rows as missing.
                foreach ($rows as &$rowRef) {
                    $rowRef['name_not_found'] = 0;
                }
                unset($rowRef);
            }
        }
        $out['ok'] = true; $out['rows'] = $rows; $out['count'] = count($rows);
    } catch (Exception $e) {
        $out['ok'] = false; $out['error'] = $e->getMessage();
    }
    $out['elapsed_ms'] = (int)( (microtime(true) - $t0) * 1000 );
    echo json_encode($out);
    exit;
}

// AJAX endpoint: list committee roles for dropdown
if ($ajax === 'committee_roles') {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'rows' => [], 'elapsed_ms' => null, 'error' => null];
    $t0 = microtime(true);
    try {
        $db = getDB();
        $sql = "SELECT id, TRIM(role_name) AS role_name FROM committee_roles WHERE deleted_at IS NULL ORDER BY role_name";
        $st = $db->prepare($sql);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out['ok'] = true; $out['rows'] = $rows;
    } catch (Exception $e) {
        $out['ok'] = false; $out['error'] = $e->getMessage();
    }
    $out['elapsed_ms'] = (int)( (microtime(true) - $t0) * 1000 );
    echo json_encode($out);
    exit;
}

// AJAX endpoint: select2 lookup for STAF from Sybase
if ($ajax === 'staff_lookup') {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'results' => [], 'error' => null];
    $q = strtoupper(trim((string)($_GET['q'] ?? $_GET['term'] ?? '')));
    $limit = (int)($_GET['limit'] ?? 100);
    if ($limit <= 0 || $limit > 500) $limit = 100;
    $t0 = microtime(true);
    try {
        $sybasePdo = getSybasePdoConnection('default');

        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql = "SELECT TOP {$limit}
                        CONVERT(VARCHAR(50), ISNULL(nopekerja, '')) AS nopekerja,
                        CONVERT(VARCHAR(200), ISNULL(gelar_nama, '')) AS gelar_nama,
                        CONVERT(VARCHAR(200), ISNULL(email, '')) AS email,
                        CONVERT(VARCHAR(50), ISNULL(handphone, '')) AS handphone,
                        CONVERT(VARCHAR(50), ISNULL(telefon_surat, '')) AS telefon_surat,
                        CONVERT(VARCHAR(50), ISNULL(telefon_pej, '')) AS telefon_pej,
                        CONVERT(VARCHAR(200), ISNULL(jawatansemasa, '')) AS jawatansemasa,
                        CONVERT(VARCHAR(200), ISNULL(jabatansemasa, '')) AS jabatansemasa
                    FROM v630staf_service_skim_all
                    WHERE CONVERT(VARCHAR(10), ISNULL(kodstatus, '')) <> '9'
                      AND (
                           UPPER(CONVERT(VARCHAR(50), ISNULL(nopekerja, ''))) LIKE ?
                        OR UPPER(CONVERT(VARCHAR(200), ISNULL(gelar_nama, ''))) LIKE ?
                        OR UPPER(CONVERT(VARCHAR(200), ISNULL(email, ''))) LIKE ?
                      )
                    ORDER BY gelar_nama";
            $stmt = $sybasePdo->prepare($sql);
            $okExec = $stmt->execute([$like, $like, $like]);
        } else {
            $sql = "SELECT TOP {$limit}
                        CONVERT(VARCHAR(50), ISNULL(nopekerja, '')) AS nopekerja,
                        CONVERT(VARCHAR(200), ISNULL(gelar_nama, '')) AS gelar_nama,
                        CONVERT(VARCHAR(200), ISNULL(email, '')) AS email,
                        CONVERT(VARCHAR(50), ISNULL(handphone, '')) AS handphone,
                        CONVERT(VARCHAR(50), ISNULL(telefon_surat, '')) AS telefon_surat,
                        CONVERT(VARCHAR(50), ISNULL(telefon_pej, '')) AS telefon_pej,
                        CONVERT(VARCHAR(200), ISNULL(jawatansemasa, '')) AS jawatansemasa,
                        CONVERT(VARCHAR(200), ISNULL(jabatansemasa, '')) AS jabatansemasa
                    FROM v630staf_service_skim_all
                    WHERE CONVERT(VARCHAR(10), ISNULL(kodstatus, '')) <> '9'
                    ORDER BY gelar_nama";
            $stmt = $sybasePdo->query($sql);
            $okExec = ($stmt !== false);
        }

        if (!$okExec || !$stmt) {
            throw new Exception('Unable to fetch staff data from Sybase.');
        }

        $rows = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $nopekerja = trim((string)($r['nopekerja'] ?? ''));
            $gelarNama = trim((string)($r['gelar_nama'] ?? ''));
            $email = trim((string)($r['email'] ?? ''));
            $handphone = trim((string)($r['handphone'] ?? ''));
            $telefonSurat = trim((string)($r['telefon_surat'] ?? ''));
            $telefonPej = trim((string)($r['telefon_pej'] ?? ''));
            $phone = $handphone !== '' ? $handphone : ($telefonSurat !== '' ? $telefonSurat : $telefonPej);
            $jawatan = trim((string)($r['jawatansemasa'] ?? ''));
            $jabatan = trim((string)($r['jabatansemasa'] ?? ''));

            $id = $nopekerja;
            if ($id === '' || $gelarNama === '') continue;

            $rows[] = [
                'id' => $id,
                'text' => ($gelarNama . ($nopekerja !== '' ? (' (' . $nopekerja . ')') : '')),
                'nopekerja' => $nopekerja,
                'gelar_nama' => $gelarNama,
                'email' => $email,
                'phone' => $phone,
                'jawatan' => $jawatan,
                'jabatansemasa' => $jabatan
            ];
        }

        $out['ok'] = true;
        $out['results'] = $rows;
    } catch (Exception $e) {
        $out['ok'] = false;
        $out['error'] = $e->getMessage();
    }
    $out['elapsed_ms'] = (int)((microtime(true) - $t0) * 1000);
    echo json_encode($out);
    exit;
}

// AJAX endpoint: select2 lookup for STUDENT from Sybase Student DB
if ($ajax === 'student_lookup') {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'results' => [], 'error' => null];
    $q = strtoupper(trim((string)($_GET['q'] ?? $_GET['term'] ?? '')));
    $limit = (int)($_GET['limit'] ?? 100);
    if ($limit <= 0 || $limit > 500) $limit = 100;
    $t0 = microtime(true);
    try {
        $sybasePdo = getSybaseStudentPdoConnection();

        if ($q !== '') {
            $like = '%' . $q . '%';
            $sql = "SELECT TOP {$limit}
                        ISNULL(CONVERT(VARCHAR(50), matrik), '') AS matrik,
                        CONVERT(VARCHAR(200), ISNULL(nama, '')) AS nama,
                        CONVERT(VARCHAR(200), ISNULL(email, '')) AS email,
                        CONVERT(VARCHAR(50), ISNULL(notel_terkini, '')) AS notel_terkini,
                        ISNULL(CONVERT(VARCHAR(10), status), '') AS status,
                        CONVERT(VARCHAR(200), ISNULL(statusketerangan, '')) AS statusketerangan
                    FROM v210
                    WHERE ISNULL(CONVERT(VARCHAR(10), status), '') <> '04'
                      AND (
                           UPPER(ISNULL(CONVERT(VARCHAR(50), matrik), '')) LIKE ?
                        OR UPPER(CONVERT(VARCHAR(200), ISNULL(nama, ''))) LIKE ?
                        OR UPPER(CONVERT(VARCHAR(200), ISNULL(email, ''))) LIKE ?
                      )
                    ORDER BY nama";
            $stmt = $sybasePdo->prepare($sql);
            $okExec = $stmt->execute([$like, $like, $like]);
        } else {
            $sql = "SELECT TOP {$limit}
                        ISNULL(CONVERT(VARCHAR(50), matrik), '') AS matrik,
                        CONVERT(VARCHAR(200), ISNULL(nama, '')) AS nama,
                        CONVERT(VARCHAR(200), ISNULL(email, '')) AS email,
                        CONVERT(VARCHAR(50), ISNULL(notel_terkini, '')) AS notel_terkini,
                        ISNULL(CONVERT(VARCHAR(10), status), '') AS status,
                        CONVERT(VARCHAR(200), ISNULL(statusketerangan, '')) AS statusketerangan
                    FROM v210
                    WHERE ISNULL(CONVERT(VARCHAR(10), status), '') <> '04'
                    ORDER BY nama";
            $stmt = $sybasePdo->query($sql);
            $okExec = ($stmt !== false);
        }

        if (!$okExec || !$stmt) {
            throw new Exception('Unable to fetch student data from Sybase.');
        }

        $rows = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $matrik = trim((string)($r['matrik'] ?? ''));
            $nama = trim((string)($r['nama'] ?? ''));
            $email = trim((string)($r['email'] ?? ''));
            $phone = trim((string)($r['notel_terkini'] ?? ''));
            $statusKeterangan = trim((string)($r['statusketerangan'] ?? ''));
            if ($matrik === '' || $nama === '') continue;

            $rows[] = [
                'id' => $matrik,
                'text' => ($nama . ' (' . $matrik . ')'),
                'matrik' => $matrik,
                'nama' => $nama,
                'email' => $email,
                'phone' => $phone,
                'statusketerangan' => $statusKeterangan
            ];
        }

        $out['ok'] = true;
        $out['results'] = $rows;
    } catch (Exception $e) {
        $out['ok'] = false;
        $out['error'] = $e->getMessage();
    }
    $out['elapsed_ms'] = (int)((microtime(true) - $t0) * 1000);
    echo json_encode($out);
    exit;
}

// AJAX endpoint: send certificate email with PDF attachment
if ($ajax === 'send_certificate_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'error' => null, 'message' => null];
    $t0 = microtime(true);
    try {
        $db = getDB();
        $smtp = getEmailNotificationSettings($db);
        if (empty($smtp['enabled'])) {
            throw new Exception('Email notifications are not enabled in Settings.');
        }

        $recipientName = trim((string)($_POST['recipient_name'] ?? ''));
        $recipientEmail = trim((string)($_POST['recipient_email'] ?? ''));
        $certType = strtolower(trim((string)($_POST['cert_type'] ?? '')));
        $roleTextInput = trim((string)($_POST['role_text'] ?? ''));
        $athleteId = (int)($_POST['athlete_id'] ?? 0);
        $memberId = (int)($_POST['member_id'] ?? 0);

        if ($recipientName === '') throw new Exception('Recipient name is required.');
        if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid recipient email address.');
        }

        $roleText = $roleTextInput;
        $templateRel = '/assets/img/sijil/sijil_penyelaras.jpeg';

        if ($certType === 'athlete') {
            if ($athleteId <= 0) throw new Exception('Invalid athlete ID.');
            $sqlSingle = "SELECT TRIM(pa.nama) AS nama, COALESCE(s.nama_sukan,'') AS sukan, COALESCE(kt.nama_kategori,'') AS acara
                          FROM table_pasukan_atlet pa
                          JOIN table_pasukan p ON p.id = pa.pasukan_id
                          LEFT JOIN table_sukan s ON s.id = p.sukan_id
                          LEFT JOIN table_kategori kt ON kt.id = pa.kategori_id
                          WHERE pa.id = :id AND pa.deleted_at IS NULL AND p.deleted_at IS NULL
                          LIMIT 1";
            $st = $db->prepare($sqlSingle);
            $st->execute([':id' => $athleteId]);
            $r = $st->fetch(PDO::FETCH_ASSOC);
            if (!$r) throw new Exception('Athlete record not found.');
            $recipientName = trim((string)($r['nama'] ?? $recipientName));
            $sukan = trim((string)($r['sukan'] ?? ''));
            $acara = trim((string)($r['acara'] ?? ''));
            $roleText = $sukan;
            if ($acara !== '') $roleText = ($roleText !== '' ? ($roleText . ' (' . $acara . ')') : $acara);
            $templateRel = '/assets/img/sijil/sijil_atlet.jpeg';
        } elseif ($certType === 'pengurus') {
            if ($roleText === '') $roleText = 'PENGURUS';
            $templateRel = '/assets/img/sijil/sijil_pengurus.jpeg';
        } elseif ($certType === 'jurulatih') {
            if ($roleText === '') $roleText = 'JURULATIH';
            $templateRel = '/assets/img/sijil/sijil_jurulatih.jpeg';
        } elseif ($certType === 'penyelaras') {
            if ($roleText === '') $roleText = 'KETUA KONTINJEN';
            $templateRel = '/assets/img/sijil/sijil_penyelaras.jpeg';
        } elseif ($certType === 'committee' || $certType === 'volunteer') {
            if ($roleText === '') $roleText = 'JAWATANKUASA';
            $templateRel = '/assets/img/sijil/sijil_penyelaras.jpeg';
        } else {
            throw new Exception('Unsupported certificate type.');
        }

        $roleText = mb_strtoupper(english_sport_label(english_label($roleText)), 'UTF-8');
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $fullImg = realpath(__DIR__ . '/..') . $templateRel;
        $ver = @file_exists($fullImg) ? @filemtime($fullImg) : time();
        $templateAbsUrl = $scheme . '://' . $host . url(ltrim($templateRel, '/')) . '?v=' . (int)$ver;
        $templateDataUri = imageFileToDataUri($fullImg);
        if ($templateDataUri) {
            $templateAbsUrl = $templateDataUri;
        }

        $pdfHtml = buildCertificateHtmlForEmail($recipientName, $roleText, $templateAbsUrl);
        $pdfBinary = renderCertPdfBinary($pdfHtml);
        $pdfEngine = (string)($GLOBALS['__pdf_engine_used'] ?? '');
        $pdfName = safeSamPdfFileName($recipientName);

        $subject = 'Participation Certificates SAM2026 - ' . $recipientName;
        $htmlBody = '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:Arial,Helvetica,sans-serif;color:#1f2937} .card{max-width:680px;margin:auto;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden} .head{background:#0d6efd;color:#fff;padding:16px 20px;font-size:18px;font-weight:700} .body{padding:20px;line-height:1.6} .meta{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin:14px 0} .foot{padding:16px 20px;background:#f9fafb;color:#6b7280;font-size:12px}</style></head><body>'
            . '<div class="card"><div class="head">Participation Certificates SAM2026</div><div class="body">'
            . '<p>Greetings <strong>' . htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') . '</strong>,</p>'
            . '<p>This email contains your participation certificate for <strong>Sukan Asasi Malaysia 2026</strong>.</p>'
            . '<div class="meta"><div><strong>Name:</strong> ' . htmlspecialchars($recipientName, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div><strong>Role / Sport:</strong> ' . htmlspecialchars($roleText, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div><strong>Attachment:</strong> ' . htmlspecialchars($pdfName, ENT_QUOTES, 'UTF-8') . '</div></div>'
            . '<p>Please find your official certificate in the attached PDF.</p>'
            . '<p>Thank you.</p></div><div class="foot">Automated SAM 2026 email. Please do not reply.</div></div></body></html>';

        smtpSendMailWithAttachment($smtp, $recipientEmail, $recipientName, $subject, $htmlBody, $pdfName, $pdfBinary);

        if (($certType === 'committee' || $certType === 'volunteer') && $memberId > 0) {
            ensureCertificateEmailLogTable($db);
            // Upsert per member_id: first send -> insert, next sends -> update last sent timestamp/details.
            $stExist = $db->prepare("SELECT id FROM certificate_email_logs WHERE member_id = :member_id ORDER BY id DESC LIMIT 1");
            $stExist->execute([':member_id' => $memberId]);
            $existingId = (int)($stExist->fetchColumn() ?: 0);
            if ($existingId > 0) {
                $stUpd = $db->prepare("UPDATE certificate_email_logs
                    SET cert_type = :cert_type,
                        recipient_name = :recipient_name,
                        recipient_email = :recipient_email,
                        role_text = :role_text,
                        sent_by_user_id = :sent_by,
                        sent_at = NOW()
                    WHERE id = :id");
                $stUpd->execute([
                    ':cert_type' => $certType,
                    ':recipient_name' => mb_strtoupper(trim((string)$recipientName), 'UTF-8'),
                    ':recipient_email' => strtolower(trim((string)$recipientEmail)),
                    ':role_text' => trim((string)$roleText),
                    ':sent_by' => (int)($_SESSION['user_id'] ?? 0) ?: null,
                    ':id' => $existingId
                ]);
            } else {
                $stLog = $db->prepare("INSERT INTO certificate_email_logs
                    (member_id, cert_type, recipient_name, recipient_email, role_text, sent_by_user_id)
                    VALUES (:member_id, :cert_type, :recipient_name, :recipient_email, :role_text, :sent_by)");
                $stLog->execute([
                    ':member_id' => $memberId,
                    ':cert_type' => $certType,
                    ':recipient_name' => mb_strtoupper(trim((string)$recipientName), 'UTF-8'),
                    ':recipient_email' => strtolower(trim((string)$recipientEmail)),
                    ':role_text' => trim((string)$roleText),
                    ':sent_by' => (int)($_SESSION['user_id'] ?? 0) ?: null
                ]);
            }
        }

        $out['ok'] = true;
        $out['message'] = 'Certificate email sent successfully ke ' . $recipientEmail;
        $out['file'] = $pdfName;
        $out['pdf_engine'] = $pdfEngine;
    } catch (Exception $e) {
        $out['ok'] = false;
        $out['error'] = $e->getMessage();
    }
    $out['elapsed_ms'] = (int)((microtime(true) - $t0) * 1000);
    echo json_encode($out);
    exit;
}

// AJAX endpoint: add a new committee member (POST)
if ($ajax === 'add_member' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'error' => null, 'exists' => false, 'id' => null];
    $t0 = microtime(true);
    try {
        $name = trim((string)($_POST['member_name'] ?? ''));
        $name = strip_tags($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = preg_replace('/\s+/u', ' ', $name);
        $name = trim($name);
        if (function_exists('mb_strtoupper')) {
            $name = mb_strtoupper($name, 'UTF-8');
        } else {
            $name = strtoupper($name);
        }
        $role_id = intval($_POST['role_id'] ?? 0);
        $ref_type = strtoupper(trim((string)($_POST['member_ref_type'] ?? '')));
        $entry_mode = strtoupper(trim((string)($_POST['member_entry_mode'] ?? '')));
        $ref_id = trim((string)($_POST['member_ref_id'] ?? ''));
        $email = trim((string)($_POST['member_email'] ?? ''));
        $phone = trim((string)($_POST['member_phone'] ?? ''));
        if ($ref_type === 'STUDENT') $ref_type = 'PELAJAR';
        if ($entry_mode === '') $entry_mode = ($ref_type === 'MANUAL') ? 'MANUAL' : 'BARU';
        if ($role_id <= 0 || !in_array($ref_type, ['STAF', 'PELAJAR', 'MANUAL'], true)) {
            throw new Exception('Incomplete data. Please fill in all required fields.');
        }
        if ($entry_mode === 'MANUAL') {
            if (!in_array($ref_type, ['STAF', 'PELAJAR'], true)) {
                throw new Exception('Please select a manual category (Staff / Student).');
            }
            if ($name === '' || $ref_id === '' || $email === '' || $phone === '') {
                throw new Exception('All fields in this form are required.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }
        } else {
            if (!in_array($ref_type, ['STAF', 'PELAJAR'], true)) {
                throw new Exception('Please select a new Staff / Student type.');
            }
            // For BARU STAF/PELAJAR, missing data from Sybase should fallback to default text
            if ($name === '') $name = 'No Record';
            if ($ref_id === '') $ref_id = 'No Record';
            if ($email === '') $email = 'No Record';
            if ($phone === '') $phone = 'No Record';
            // Email validation: allow placeholder default text for auto-filled BARU mode
            if (strtoupper($email) !== 'NO RECORDS' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }
        }
        $db = getDB();
        // check duplicate by ref_id + ref_type + role_id (allow same person in different roles)
        $sqlChk = "SELECT id FROM committee_members
                   WHERE TRIM(UPPER(member_ref_id)) = TRIM(UPPER(:ref_id))
                     AND UPPER(COALESCE(member_ref_type,'')) = :ref_type
                     AND role_id = :role_id
                     AND deleted_at IS NULL
                   LIMIT 1";
        $stChk = $db->prepare($sqlChk);
        $stChk->execute([':ref_id' => $ref_id, ':ref_type' => $ref_type, ':role_id' => $role_id]);
        $found = $stChk->fetch(PDO::FETCH_ASSOC);
        if ($found) {
            $out['ok'] = false; $out['exists'] = true; $out['error'] = 'A record already exists for this committee member.';
            $out['conflict_id'] = isset($found['id']) ? (int)$found['id'] : null;
            echo json_encode($out); exit;
        }
        // include program_id defaulting to 1 to avoid missing default DB error
        $sqlIns = "INSERT INTO committee_members (program_id, role_id, member_name, member_ref_type, member_ref_id, member_email, member_phone) VALUES (:program_id, :role_id, :name, :ref_type, :ref_id, :email, :phone)";
        $stIns = $db->prepare($sqlIns);
        $stIns->execute([':program_id' => 1, ':role_id' => $role_id, ':name' => $name, ':ref_type' => $ref_type, ':ref_id' => $ref_id, ':email' => $email, ':phone' => $phone]);

        $newId = (int)$db->lastInsertId();
        if ($newId <= 0) {
            throw new Exception('The saved record could not be verified (insert ID missing).');
        }
        $stVerify = $db->prepare("SELECT id FROM committee_members WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $stVerify->execute([':id' => $newId]);
        $v = $stVerify->fetch(PDO::FETCH_ASSOC);
        if (!$v) {
            throw new Exception('Record not found after saving. Please check the database.');
        }
        $out['ok'] = true;
        $out['id'] = $newId;
        $out['saved_db'] = DB_NAME;
    } catch (Exception $e) {
        $out['ok'] = false; $out['error'] = $e->getMessage();
    }
    $out['elapsed_ms'] = (int)( (microtime(true) - $t0) * 1000 );
    echo json_encode($out);
    exit;
}

// AJAX endpoint: update an existing committee member (POST)
if ($ajax === 'update_member' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'error' => null, 'exists' => false, 'id' => null];
    $t0 = microtime(true);
    try {
        $id = intval($_POST['member_id'] ?? 0);
        $name = trim((string)($_POST['member_name'] ?? ''));
        $name = strip_tags($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = preg_replace('/\s+/u', ' ', $name);
        $name = trim($name);
        if (function_exists('mb_strtoupper')) {
            $name = mb_strtoupper($name, 'UTF-8');
        } else {
            $name = strtoupper($name);
        }
        $role_id = intval($_POST['role_id'] ?? 0);
        $ref_type = strtoupper(trim((string)($_POST['member_ref_type'] ?? '')));
        $entry_mode = strtoupper(trim((string)($_POST['member_entry_mode'] ?? '')));
        $ref_id = trim((string)($_POST['member_ref_id'] ?? ''));
        $email = trim((string)($_POST['member_email'] ?? ''));
        $phone = trim((string)($_POST['member_phone'] ?? ''));
        if ($ref_type === 'STUDENT') $ref_type = 'PELAJAR';
        if ($entry_mode === '') $entry_mode = ($ref_type === 'MANUAL') ? 'MANUAL' : 'BARU';
        if ($id <= 0 || $role_id <= 0 || !in_array($ref_type, ['STAF', 'PELAJAR', 'MANUAL'], true)) {
            throw new Exception('Incomplete data for updating.');
        }
        if ($entry_mode === 'MANUAL') {
            if (!in_array($ref_type, ['STAF', 'PELAJAR'], true)) {
                throw new Exception('Please select a manual category (Staff / Student).');
            }
            if ($name === '' || $ref_id === '' || $email === '' || $phone === '') {
                throw new Exception('All fields in this form are required.');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }
        } else {
            if (!in_array($ref_type, ['STAF', 'PELAJAR'], true)) {
                throw new Exception('Please select a new Staff / Student type.');
            }
            if ($name === '') $name = 'No Record';
            if ($ref_id === '') $ref_id = 'No Record';
            if ($email === '') $email = 'No Record';
            if ($phone === '') $phone = 'No Record';
            if (strtoupper($email) !== 'NO RECORDS' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address.');
            }
        }
        $db = getDB();
        // duplicate check excluding current id, scoped by role_id as well
        $sqlChk = "SELECT id FROM committee_members
                   WHERE TRIM(UPPER(member_ref_id)) = TRIM(UPPER(:ref_id))
                     AND UPPER(COALESCE(member_ref_type,'')) = :ref_type
                     AND role_id = :role_id
                     AND deleted_at IS NULL
                     AND id != :id
                   LIMIT 1";
        $stChk = $db->prepare($sqlChk);
        $stChk->execute([':ref_id' => $ref_id, ':ref_type' => $ref_type, ':role_id' => $role_id, ':id' => $id]);
        $found = $stChk->fetch(PDO::FETCH_ASSOC);
        if ($found) {
            $out['ok'] = false; $out['exists'] = true; $out['error'] = 'A record already exists for this committee member.';
            $out['conflict_id'] = isset($found['id']) ? (int)$found['id'] : null;
            echo json_encode($out); exit;
        }
        $sqlUpd = "UPDATE committee_members SET role_id = :role_id, member_name = :name, member_ref_type = :ref_type, member_ref_id = :ref_id, member_email = :email, member_phone = :phone, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL";
        $stUpd = $db->prepare($sqlUpd);
        $stUpd->execute([':role_id' => $role_id, ':name' => $name, ':ref_type' => $ref_type, ':ref_id' => $ref_id, ':email' => $email, ':phone' => $phone, ':id' => $id]);
        $out['ok'] = true; $out['id'] = $id;
    } catch (Exception $e) {
        $out['ok'] = false; $out['error'] = $e->getMessage();
    }
    $out['elapsed_ms'] = (int)( (microtime(true) - $t0) * 1000 );
    echo json_encode($out);
    exit;
}

// AJAX endpoint: soft-delete a committee member (POST)
if ($ajax === 'delete_member' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'error' => null];
    $t0 = microtime(true);
    try {
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) throw new Exception('Invalid ID');
        $db = getDB();
        $sql = "UPDATE committee_members SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL";
        $st = $db->prepare($sql);
        $st->execute([':id' => $id]);
        if ($st->rowCount() > 0) {
            $out['ok'] = true;
        } else {
            $out['ok'] = false; $out['error'] = 'Records not found atau has been deleted.';
        }
    } catch (Exception $e) {
        $out['ok'] = false; $out['error'] = $e->getMessage();
    }
    $out['elapsed_ms'] = (int)( (microtime(true) - $t0) * 1000 );
    echo json_encode($out);
    exit;
}

// Handle printing all athletes for a kontinjen (multi-page HTML or combined PDF)
$printAll = trim((string)($_GET['print_all'] ?? ''));
if ($printAll !== '') {
    $kodAll = strtoupper(trim((string)($_GET['kod'] ?? '')));
    if ($kodAll === '') {
        http_response_code(400);
        echo "Contingent code is required.";
        exit;
    }
    $type = strtolower(trim((string)($_GET['type'] ?? 'athletes')));
    try {
        if ($type === 'athletes') {
            $db = getDB();
            $sqlAll = "SELECT pa.id AS id, TRIM(pa.nama) AS nama, COALESCE(s.nama_sukan,'') AS sukan, COALESCE(kt.nama_kategori,'') AS acara
                FROM table_pasukan_atlet pa
                JOIN table_pasukan p ON p.id = pa.pasukan_id
                LEFT JOIN table_sukan s ON s.id = p.sukan_id
                LEFT JOIN table_kategori kt ON kt.id = pa.kategori_id
                LEFT JOIN table_kontinjen k ON k.id = p.kontinjen_id
                WHERE UPPER(COALESCE(k.kod_universiti,'')) = :kod_val
                  AND pa.deleted_at IS NULL
                  AND p.deleted_at IS NULL
                ORDER BY s.nama_sukan, kt.nama_kategori, pa.nama";
            $stAll = $db->prepare($sqlAll);
            $stAll->execute([':kod_val' => $kodAll]);
            $rowsAll = $stAll->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } else {
            // build rows from managers endpoint
            $rowsAll = [];
            $mgrs = fetch_managers_from_ringkasan($kodAll);
            foreach ($mgrs as $m) {
                $acara = trim((string)($m['acara'] ?? ''));
                if ($type === 'pengurus') {
                    if (!empty($m['pengurus'])) {
                        $parts = explode(' ||| ', $m['pengurus']);
                        foreach ($parts as $p) {
                            $p = trim($p);
                            if ($p !== '') {
                                $nama = $p;
                                $jawatan = '';
                                if (strpos($p, '@@JAWATAN@@') !== false) {
                                    $segNama = explode('@@JAWATAN@@', $p, 2);
                                    $nama = trim((string)($segNama[0] ?? ''));
                                    $rest1 = (string)($segNama[1] ?? '');
                                    $segTel = explode('@@TEL@@', $rest1, 2);
                                    $jawatan = trim((string)($segTel[0] ?? ''));
                                }
                                $rowsAll[] = ['nama' => $nama, 'jawatan' => $jawatan, 'sukan' => '', 'acara' => $acara];
                            }
                        }
                    }
                } else if ($type === 'jurulatih') {
                    if (!empty($m['jurulatih'])) {
                        $parts = explode(' ||| ', $m['jurulatih']);
                        foreach ($parts as $p) {
                            $p = trim($p);
                            if ($p !== '') {
                                $nama = $p;
                                $jawatan = '';
                                if (strpos($p, '@@JAWATAN@@') !== false) {
                                    $segNama = explode('@@JAWATAN@@', $p, 2);
                                    $nama = trim((string)($segNama[0] ?? ''));
                                    $rest1 = (string)($segNama[1] ?? '');
                                    $segTel = explode('@@TEL@@', $rest1, 2);
                                    $jawatan = trim((string)($segTel[0] ?? ''));
                                }
                                $rowsAll[] = ['nama' => $nama, 'jawatan' => $jawatan, 'sukan' => '', 'acara' => $acara];
                            }
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        $rowsAll = [];
    }

    if (empty($rowsAll)) {
        http_response_code(404);
        echo "No athletes found for this contingent.";
        exit;
    }

    // Build combined HTML with one .page per athlete
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // choose template based on type
        if ($type === 'pengurus') {
        $img_rel = '/assets/img/sijil/sijil_pengurus.jpeg';
    } else if ($type === 'jurulatih') {
        $img_rel = '/assets/img/sijil/sijil_jurulatih.jpeg';
    } else {
        // athletes -> use new athlete template
        $img_rel = '/assets/img/sijil/sijil_atlet.jpeg';
    }
    // Build absolute URL including BASE_URL so it works in subfolder deployments
    $absTemplateUrl = $scheme . '://' . $host . url(ltrim($img_rel, '/'));

    $multiHtml = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Participation Certificates - All</title>' .
        '<style>@page{size:210mm 297mm;margin:0}@media print{html,body{width:210mm;margin:0!important;padding:0!important}.page{width:210mm!important;height:297mm!important;margin:0!important;padding:0!important;border:0!important;box-sizing:border-box;break-inside:avoid;page-break-inside:avoid}.bg-img{object-fit:cover!important;left:-0.5mm!important;top:-0.5mm!important;width:211mm!important;height:298mm!important;max-width:none!important}}html,body{height:100%;margin:0;padding:0}body{background:#fff;font-family:Arial,Helvetica,sans-serif}.page{position:relative;width:210mm;height:297mm;overflow:hidden}.bg-img{position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;z-index:0}.cert-name{position:absolute;left:50%;top:38%;transform:translate(-50%,-50%);width:78%;text-align:center;font-weight:700;color:#000;line-height:1.05;z-index:1;font-size:20px}.cert-sport{position:absolute;left:50%;top:48%;transform:translateX(-50%);width:78%;text-align:center;color:#000;font-weight:700;z-index:1;font-size:20px}.page,.bg-img{-webkit-print-color-adjust:exact;print-color-adjust:exact}</style></head><body>';

    foreach ($rowsAll as $ra) {
        $rname = trim((string)($ra['nama'] ?? ''));
        // strip phone/email in parentheses or trailing emails for cleaner name
        $rname = preg_replace('/\s*\([^)]*\)/', '', $rname);
        $rname = preg_replace('/\s+\S+@\S+$/', '', $rname);
        $rname = trim($rname);
        $rsukan = trim((string)($ra['sukan'] ?? ''));
        $racara = trim((string)($ra['acara'] ?? ''));
        // only show sport/event for athletes; pengurus/jurulatih show name only
        if ($type === 'athletes') {
            $sukan_combo = $rsukan;
            if ($racara !== '') {
                $sukan_combo = $sukan_combo !== '' ? $sukan_combo . ' (' . $racara . ')' : $racara;
            }
            // uppercase sport/event for printed certificates
            $sukan_combo = mb_strtoupper(english_label($sukan_combo), 'UTF-8');
        } else {
            // For non-athletes, show role label in the same position
            if ($type === 'pengurus') {
                $sukan_combo = trim((string)($ra['jawatan'] ?? ''));
                if ($sukan_combo === '') $sukan_combo = 'PENGURUS';
                $sukan_combo = mb_strtoupper(english_label($sukan_combo), 'UTF-8');
            } else if ($type === 'jurulatih') {
                $sukan_combo = trim((string)($ra['jawatan'] ?? ''));
                if ($sukan_combo === '') $sukan_combo = 'JURULATIH';
                $sukan_combo = mb_strtoupper(english_label($sukan_combo), 'UTF-8');
            } else if ($type === 'penyelaras') {
                $sukan_combo = mb_strtoupper(english_label('KETUA KONTINJEN'), 'UTF-8');
            } else {
                $sukan_combo = '';
            }
        }
        $multiHtml .= '<div class="page">' .
            '<img class="bg-img" src="' . htmlspecialchars($absTemplateUrl, ENT_QUOTES, 'UTF-8') . '" alt="background">' .
            '<div class="cert-name">' . htmlspecialchars($rname, ENT_QUOTES, 'UTF-8') . '</div>' .
            '<div class="cert-sport">' . htmlspecialchars(english_sport_label(english_label($sukan_combo)), ENT_QUOTES, 'UTF-8') . '</div>' .
        '</div>';
    }

    $multiHtml .= '<script> (function(){ if(window.top===window.self){ setTimeout(function(){ window.print(); },180); } })();</script></body></html>';

    // If download requested, attempt PDF generation (wkhtmltopdf/chromium fallback)
    $downloadAll = trim((string)($_GET['download'] ?? $_GET['dl'] ?? ''));
    if ($downloadAll === '1' || strtolower($downloadAll) === 'true') {
        try {
            $pdfBinary = renderPdfFromHtmlWithFallback($multiHtml, 'sijil_all_');
            $dlname = 'sijil_semua_' . $kodAll . '_' . date('Ymd_His') . '.pdf';
            $dlNameEnc = rawurlencode($dlname);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $dlname . '"; filename*=UTF-8\'\'' . $dlNameEnc);
            header('Content-Length: ' . strlen($pdfBinary));
            echo $pdfBinary;
            exit;
        } catch (Throwable $e) {
            // fallback to HTML output when PDF generation fails
        }
    }

    echo $multiHtml;
    exit;
}

// If a single print request is made (from the list), render one full-page A4 certificate
$printId = trim((string)($_GET['print_id'] ?? ''));
$download = trim((string)($_GET['download'] ?? $_GET['dl'] ?? ''));
if ($printId !== '') {
    // ensure numeric id to avoid injection (adjust if your IDs are UUIDs)
    $pid = is_numeric($printId) ? (int)$printId : 0;
    if ($pid <= 0) {
        http_response_code(400);
        echo "Invalid ID";
        exit;
    }
    try {
        $db = getDB();
        $sqlSingle = "SELECT TRIM(pa.nama) AS nama, pa.id AS id, COALESCE(s.nama_sukan,'') AS sukan, COALESCE(kt.nama_kategori,'') AS acara
            FROM table_pasukan_atlet pa
            JOIN table_pasukan p ON p.id = pa.pasukan_id
            LEFT JOIN table_sukan s ON s.id = p.sukan_id
            LEFT JOIN table_kategori kt ON kt.id = pa.kategori_id
            WHERE pa.id = :id AND pa.deleted_at IS NULL AND p.deleted_at IS NULL
            LIMIT 1";
        $st = $db->prepare($sqlSingle);
        $st->execute([':id' => $pid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $row = false;
    }

    if (!$row) {
        http_response_code(404);
        echo "Records not found.";
        exit;
    }

    $name = trim((string)($row['nama'] ?? ''));
    $sukan = trim((string)($row['sukan'] ?? ''));
    $acara = trim((string)($row['acara'] ?? ''));
    $sukan_combo = $sukan;
    if ($acara !== '') {
        $sukan_combo = $sukan_combo !== '' ? $sukan_combo . ' (' . $acara . ')' : $acara;
    }
    // uppercase sport/event for printed certificate
    $sukan_combo = mb_strtoupper(english_label($sukan_combo), 'UTF-8');

    // Prepare HTML for certificate. For PDF generation we need absolute URLs.
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Use the athlete template explicitly to avoid mismatches when $img_url_versioned
    // may point elsewhere. Build a versioned absolute URL for the athlete background.
    $athRel = '/assets/img/sijil/sijil_atlet.jpeg';
    $athFull = realpath(__DIR__ . '/..') . $athRel;
    $athVer = @file_exists($athFull) ? @filemtime($athFull) : time();
    // include BASE_URL prefix so it resolves correctly in production subfolders
    $absTemplateUrl = $scheme . '://' . $host . url('assets/img/sijil/sijil_atlet.jpeg') . '?v=' . (int)$athVer;

    $certHtml = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Participation Certificates</title>' .
        '<style>@page{size:210mm 297mm;margin:0}@media print{html,body{width:210mm;margin:0!important;padding:0!important}.page{width:210mm!important;height:297mm!important;margin:0!important;padding:0!important;border:0!important;box-sizing:border-box;break-inside:avoid;page-break-inside:avoid}.bg-img{object-fit:cover!important;left:-0.5mm!important;top:-0.5mm!important;width:211mm!important;height:298mm!important;max-width:none!important}}html,body{height:100%;margin:0;padding:0}body{background:#fff;font-family:Arial,Helvetica,sans-serif}.page{position:relative;width:210mm;height:297mm;overflow:hidden}.bg-img{position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;z-index:0}.cert-name{position:absolute;left:50%;top:38%;transform:translate(-50%,-50%);width:78%;text-align:center;font-weight:700;color:#000;line-height:1.05;z-index:1;font-size:20px}.cert-sport{position:absolute;left:50%;top:48%;transform:translateX(-50%);width:78%;text-align:center;color:#000;font-weight:700;z-index:1;font-size:20px}.page,.bg-img{-webkit-print-color-adjust:exact;print-color-adjust:exact}</style></head><body>' .
        '<div class="page">' .
            '<img class="bg-img" src="' . htmlspecialchars($absTemplateUrl, ENT_QUOTES, 'UTF-8') . '" alt="background">' .
            '<div class="cert-name">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</div>' .
            '<div class="cert-sport">' . htmlspecialchars(english_sport_label(english_label($sukan_combo)), ENT_QUOTES, 'UTF-8') . '</div>' .
        '</div>' .
        '<script> (function(){ var bg=document.querySelector(".bg-img"); function p(){ try{ if(window.top===window.self){ window.print(); } }catch(e){} } if(bg){ if(bg.complete) setTimeout(p,120); else { bg.addEventListener("load", p); bg.addEventListener("error", p); } } else setTimeout(p,200); })(); </script></body></html>';

    // If user requested download=1, try to generate PDF server-side (wkhtmltopdf/chromium fallback)
    if ($download === '1' || strtolower($download) === 'true') {
        try {
            $pdfBinary = renderPdfFromHtmlWithFallback($certHtml, 'sijil_');
            // create a safe filename using recipient name
            $raw = trim((string)$name);
            $slug = '';
            if ($raw !== '') {
                $trans = @iconv('UTF-8', 'ASCII//TRANSLIT', $raw);
                $trans = $trans !== false ? $trans : $raw;
                $slug = preg_replace('/[^A-Za-z0-9 _-]/', '', $trans);
                $slug = preg_replace('/[\s\t\r\n]+/', '_', $slug);
                $slug = preg_replace('/_+/', '_', $slug);
                $slug = trim($slug, '_');
                if ($slug === '') $slug = 'sijil';
                $slug = substr($slug, 0, 60);
            } else {
                $slug = 'sijil';
            }
            $downloadName = 'sijil_' . $pid . '_' . $slug . '.pdf';
            $downloadNameUtf = rawurlencode($downloadName);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="' . $downloadName . '"; filename*=UTF-8\'\'' . $downloadNameUtf);
            header('Content-Length: ' . strlen($pdfBinary));
            echo $pdfBinary;
            exit;
        } catch (Throwable $e) {
            // fallback to HTML page if PDF generation failed
        }
    }
    // If PDF not requested or generation failed, output the HTML certificate
    echo $certHtml;
    exit;
}

// Helper: try to fetch managers/coaches JSON from ringkasan AJAX endpoint
function fetch_managers_from_ringkasan($kod) {
    // Instead of making an HTTP request (which may be blocked by RBAC/session),
    // query the database directly using the same SQL as pages/ringkasan.php ajax=managers.
    try {
        $db = getDB();
        $sql = "
            SELECT
                COALESCE(s.nama_sukan, '') AS acara,
                COALESCE(r.nama_pendek, r.nama_universiti, k.kod_universiti) AS kontinjen,
                TRIM(
                    COALESCE(
                        GROUP_CONCAT(
                            DISTINCT CONCAT(
                                COALESCE(NULLIF(TRIM(pp.nama), ''), ''),
                                ' @@JAWATAN@@ ', COALESCE(pp.jawatan, ''),
                                ' @@TEL@@ ', COALESCE(pp.no_telefon, ''),
                                ' @@EMEL@@ ', COALESCE(pp.emel, '')
                            )
                            SEPARATOR ' ||| '
                        ),
                        ''
                    )
                ) AS pengurus,
                TRIM(
                    COALESCE(
                        GROUP_CONCAT(
                            DISTINCT CONCAT(
                                COALESCE(NULLIF(TRIM(j.nama), ''), ''),
                                ' @@JAWATAN@@ ', COALESCE(j.jawatan, ''),
                                ' @@TEL@@ ', COALESCE(j.no_telefon, ''),
                                ' @@EMEL@@ ', COALESCE(j.emel, '')
                            )
                            SEPARATOR ' ||| '
                        ),
                        ''
                    )
                ) AS jurulatih
            FROM table_pasukan p
            LEFT JOIN table_kontinjen k ON k.id = p.kontinjen_id
            LEFT JOIN table_ref_universiti r ON r.kod_universiti = k.kod_universiti AND r.status = 1
            LEFT JOIN table_sukan s ON s.id = p.sukan_id
            LEFT JOIN table_pasukan_pengurus pp ON pp.pasukan_id = p.id AND pp.deleted_at IS NULL AND NULLIF(TRIM(pp.nama), '') IS NOT NULL
            LEFT JOIN table_pasukan_jurulatih j ON j.pasukan_id = p.id AND j.deleted_at IS NULL AND NULLIF(TRIM(j.nama), '') IS NOT NULL
            WHERE (:kod_empty = '' OR UPPER(COALESCE(k.kod_universiti, '')) = :kod_val)
              AND p.deleted_at IS NULL
            GROUP BY p.id, s.nama_sukan, k.kod_universiti, r.nama_pendek
            ORDER BY s.nama_sukan, p.id
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([':kod_empty' => $kod, ':kod_val' => $kod]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return $rows;
    } catch (Exception $e) {
        return [];
    }
}

// Do not load data until a kontinjen is selected to avoid heavy initial queries
$managersRows = [];
$athletes = [];
if ($kod !== '') {
    // Fetch managers & coaches via ringkasan AJAX (reuse existing logic)
    try {
        $managersRows = fetch_managers_from_ringkasan($kod);
    } catch (Exception $e) {
        $managersRows = [];
    }

    // Do not fetch athletes here to keep page load fast.
    $athletes = [];
}

ob_start();
?>
<link rel="stylesheet" href="<?php echo asset('css/certificates.css'); ?>?v=<?php echo filemtime(__DIR__ . '/../assets/css/certificates.css'); ?>">
<div class="container-fluid px-3 certificate-view">
    <div class="row mb-3">
        <div class="col-12">
            <div class="certificate-eyebrow">SAM 2026 &middot; CERTIFICATE MANAGEMENT</div><h2 class="mb-1">Participation Certificates</h2>
            <p class="text-muted mb-0">Manage recipients, print certificates and send them by email for each category.</p>
        </div>
    </div>

    <!-- filter moved into CONTINGENT CERTIFICATES tab -->

    <div class="row">
            <div class="col-12">
                <!-- Font Awesome for tab icons (safe to include here if not globally present) -->
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
                <style>
                    .sijil-tab-icon { margin-right:0.5rem; font-size:1.05rem; vertical-align:-0.08em; }
                    .nav-tabs .nav-link { display:inline-flex; align-items:center; gap:0.25rem; }
                    #athleteBody tr.medal-gold > td { background-color: #fff4cf !important; }
                    #athleteBody tr.medal-silver > td { background-color: #edf1f5 !important; }
                    #athleteBody tr.medal-bronze > td { background-color: #f6e3d3 !important; }
                    .no-data-badge{
                        display:inline-block;
                        padding:0.18rem 0.5rem;
                        border-radius:0.35rem;
                        background:#fdecea;
                        color:#842029;
                        border:1px solid #f5c2c7;
                        font-size:0.8rem;
                        font-weight:600;
                        line-height:1.2;
                    }
                    .email-sent-badge{
                        display:inline-block;
                        margin-left:0.35rem;
                        padding:0.18rem 0.5rem;
                        border-radius:999px;
                        background:#d1e7dd;
                        color:#0f5132;
                        border:1px solid #badbcc;
                        font-size:0.72rem;
                        font-weight:700;
                        line-height:1.2;
                        white-space:nowrap;
                    }
                </style>
                <div id="tabsWrap">
                    <div class="d-flex align-items-start">
                        <ul class="nav nav-tabs" id="sijilTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="tab-kontinjen" data-bs-toggle="tab" data-bs-target="#pane-kontinjen" type="button" role="tab"><i class="fa-solid fa-flag sijil-tab-icon" aria-hidden="true"></i>Contingent</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-jawatankuasa" data-bs-toggle="tab" data-bs-target="#pane-jawatankuasa" type="button" role="tab"><i class="fa-solid fa-users-gear sijil-tab-icon" aria-hidden="true"></i>Organising Committee</button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="tab-sukarelawan" data-bs-toggle="tab" data-bs-target="#pane-sukarelawan" type="button" role="tab"><i class="fa-solid fa-hands-helping sijil-tab-icon" aria-hidden="true"></i>Volunteer</button>
                            </li>
                        </ul>
                        <div class="ms-auto ps-3">
                            <button type="button" id="btnAddMember" class="btn btn-sm btn-success">+ Add Record</button>
                        </div>
                    </div>
                        <div class="tab-content border border-top-0 p-3" id="sijilTabContent">
                            <div class="tab-pane fade show active" id="pane-kontinjen" role="tabpanel">
                                <!-- Filter for kontinjen (moved here) -->
                                <div class="card mb-3">
                                    <div class="card-body d-flex gap-3 align-items-end">
                                        <div>
                                            <form method="get" id="frmKont"><label class="certificate-filter-label" for="selectKont">Select a contingent</label>
                                                <div class="d-flex align-items-center">
                                                    <select id="selectKont" name="kod" class="form-select form-select-sm" style="min-width:360px;max-width:60%">
                                                        <option value="">-- All Contingents --</option>
                                                        <?php foreach ($unis as $u): ?>
                                                            <option value="<?php echo htmlspecialchars(strtoupper($u['kod_universiti']), ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($kod !== '' && strtoupper($u['kod_universiti']) === $kod) ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['nama_universiti'], ENT_QUOTES, 'UTF-8'); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="button" id="btnLoadList" class="btn btn-sm btn-secondary ms-2" style="min-width:120px">Show Data</button>
                                                    <span id="loadStatus" class="ms-2 text-muted"></span>
                                                    <div id="tableLoader" class="ms-auto align-self-center" style="display:none;">
                                                        <div class="spinner-border text-primary" role="status" style="width:1.6rem;height:1.6rem;vertical-align:middle;">
                                                            <span class="visually-hidden">Loading...</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                    </div>
                                </div>
                                <!-- Existing kontingen content (penyelaras/pengurus/jurulatih/atlet) -->
                                <div id="kontinjen-inner">
                                    <ul class="nav nav-pills mb-3" id="kontinjenSubNav" role="tablist" style="display:none;">
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link active" data-sub="0" type="button">Contingent Leader</button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" data-sub="1" type="button">Manager</button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" data-sub="2" type="button">Coach</button>
                                        </li>
                                        <li class="nav-item" role="presentation">
                                            <button class="nav-link" data-sub="3" type="button">Athlete</button>
                                        </li>
                                    </ul>
                                    <div id="kontinjenSubtabs" style="display:none;">
                                    <div class="tab-pane-inner">
                                        <div class="d-flex mb-2">
                                            <div class="me-auto"></div>
                                            <input type="search" id="searchPenyelaras" class="form-control form-control-sm me-2" placeholder="Search recipients..." aria-label="Search certificate recipients" style="max-width:220px;">
                                            <button type="button" id="printAllPenyelaras" class="btn btn-sm btn-primary"><i class="fa-solid fa-print me-1"></i>Print All</button>
                                        </div>
                                        <div class="table-responsive"><table class="table table-sm table-hover"><thead class="table-light"><tr><th style="width:5%" class="text-center">No</th><th style="width:55%">Contingent Leader Name</th><th style="width:20%">Email</th><th style="width:10%" class="text-center">Phone Number</th><th style="width:10%" class="text-center">Actions</th></tr></thead><tbody id="penyelarasBody"></tbody></table></div>
                                        <div class="d-flex justify-content-end align-items-center mt-2">
                                            <button type="button" id="penyelarasPrev" class="btn btn-sm btn-outline-secondary me-2">Prev</button>
                                            <span id="penyelarasPageInfo" class="me-2">Page 1/1</span>
                                            <button type="button" id="penyelarasNext" class="btn btn-sm btn-outline-secondary">Next</button>
                                        </div>
                                    </div>

                                    <div class="tab-pane-inner mt-4">
                                        <div class="d-flex mb-2">
                                            <div class="me-auto"></div>
                                            <input type="search" id="searchPengurus" class="form-control form-control-sm me-2" placeholder="Search recipients..." aria-label="Search certificate recipients" style="max-width:220px;">
                                            <button type="button" id="printAllPengurus" class="btn btn-sm btn-primary"><i class="fa-solid fa-print me-1"></i>Print All</button>
                                        </div>
                                        <div class="table-responsive"><table class="table table-sm table-hover"><thead class="table-light"><tr><th style="width:5%" class="text-center">No</th><th style="width:55%">Manager Name</th><th style="width:20%">Position</th><th style="width:10%">Phone Number</th><th style="width:10%" class="text-center">Actions</th></tr></thead><tbody id="pengurusBody"></tbody></table></div>
                                        <div class="d-flex justify-content-end align-items-center mt-2">
                                            <button type="button" id="pengurusPrev" class="btn btn-sm btn-outline-secondary me-2">Prev</button>
                                            <span id="pengurusPageInfo" class="me-2">Page 1/1</span>
                                            <button type="button" id="pengurusNext" class="btn btn-sm btn-outline-secondary">Next</button>
                                        </div>
                                    </div>

                                    <div class="tab-pane-inner mt-4">
                                        <div class="d-flex mb-2">
                                            <div class="me-auto"></div>
                                            <input type="search" id="searchJurulatih" class="form-control form-control-sm me-2" placeholder="Search recipients..." aria-label="Search certificate recipients" style="max-width:220px;">
                                            <button type="button" id="printAllJurulatih" class="btn btn-sm btn-primary"><i class="fa-solid fa-print me-1"></i>Print All</button>
                                        </div>
                                        <div class="table-responsive"><table class="table table-sm table-hover"><thead class="table-light"><tr><th style="width:5%" class="text-center">No</th><th style="width:55%">Coach Name</th><th style="width:20%">Position</th><th style="width:10%">Phone Number</th><th style="width:10%" class="text-center">Actions</th></tr></thead><tbody id="jurulatihBody"></tbody></table></div>
                                        <div class="d-flex justify-content-end align-items-center mt-2">
                                            <button type="button" id="jurulatihPrev" class="btn btn-sm btn-outline-secondary me-2">Prev</button>
                                            <span id="jurulatihPageInfo" class="me-2">Page 1/1</span>
                                            <button type="button" id="jurulatihNext" class="btn btn-sm btn-outline-secondary">Next</button>
                                        </div>
                                    </div>

                                    <div class="tab-pane-inner mt-4">
                                        <div class="d-flex mb-2">
                                            <div class="me-auto"></div>
                                            <input type="search" id="searchAtlet" class="form-control form-control-sm me-2" placeholder="Search recipients..." aria-label="Search certificate recipients" style="max-width:220px;">
                                            <button type="button" id="printAllAtlet" class="btn btn-sm btn-primary"><i class="fa-solid fa-print me-1"></i>Print All</button>
                                        </div>
                                        <div class="table-responsive"><table class="table table-sm table-hover align-middle"><thead class="table-light"><tr><th style="width:5%" class="text-center">No</th><th style="width:40%">Athlete Name</th><th style="width:30%">Sport / Event</th><th style="width:15%" class="text-center">Medals</th><th style="width:10%" class="text-center">Actions</th></tr></thead><tbody id="athleteBody"></tbody></table></div>
                                        <div class="d-flex justify-content-end align-items-center mt-2">
                                            <button type="button" id="athletePrev" class="btn btn-sm btn-outline-secondary me-2">Prev</button>
                                            <span id="athletePageInfo" class="me-2">Page 1/1</span>
                                            <button type="button" id="athleteNext" class="btn btn-sm btn-outline-secondary">Next</button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="pane-jawatankuasa" role="tabpanel">
                                <div class="card mb-3">
                                    <div class="card-body d-flex gap-3 align-items-end justify-content-between flex-wrap">
                                        <div>
                                            <div class="d-flex align-items-center">
                                                <label class="visually-hidden" for="committeeType">Committee member type</label><select id="committeeType" class="form-select form-select-sm" style="min-width:220px;max-width:40%">
                                                    <option value="">-- Select a type --</option>
                                                    <option value="STAF">Staff</option>
                                                    <option value="PELAJAR">Student</option>
                                                </select>
                                                <button type="button" id="btnLoadCommittee" class="btn btn-sm btn-secondary ms-2" style="min-width:120px">Show Data</button>
                                                <span id="committeeLoadStatus" class="ms-2 text-muted"></span>
                                                <div id="committeeLoader" class="ms-auto align-self-center" style="display:none;">
                                                    <div class="spinner-border text-primary" role="status" style="width:1.6rem;height:1.6rem;vertical-align:middle;"><span class="visually-hidden">Loading...</span></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="small text-muted d-flex align-items-center gap-2 flex-wrap ms-auto">
                                            <span class="d-inline-block border rounded" style="width:16px;height:16px;background:#fff3cd;"></span>
                                            <span>Representing More Than One Committee</span>
                                            <span class="d-inline-block border rounded" style="width:16px;height:16px;background:#f8d7da;"></span>
                                            <span>Non-UPNM Staff</span>
                                        </div>
                                    </div>
                                </div>

                                <div id="committeeWrap" style="display:none;">
                                    <div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
                                        <div class="d-flex align-items-center ms-auto">
                                            <input type="search" id="searchCommittee" class="form-control form-control-sm me-2" placeholder="Search recipients..." aria-label="Search certificate recipients" style="max-width:320px;">
                                            <button type="button" id="printAllCommittee" class="btn btn-sm btn-primary text-nowrap" style="white-space:nowrap;"><i class="fa-solid fa-print me-1"></i>Print All</button>
                                        </div>
                                    </div>
                                        <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead class="table-light"><tr>
                                                <th style="width:5%" class="text-center">No</th>
                                                <th style="width:40%">Organising Committee Member Name</th>
                                                <th style="width:30%">Committee Member Name</th>
                                                <th style="width:15%">Email</th>
                                                <th style="width:10%" class="text-center">Actions</th>
                                            </tr></thead>
                                            <tbody id="committeeBody"></tbody>
                                        </table>
                                    </div>
                                    <div class="d-flex justify-content-end align-items-center mt-2">
                                        <button type="button" id="committeePrev" class="btn btn-sm btn-outline-secondary me-2">Prev</button>
                                        <span id="committeePageInfo" class="me-2">Page 1/1</span>
                                        <button type="button" id="committeeNext" class="btn btn-sm btn-outline-secondary">Next</button>
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="pane-sukarelawan" role="tabpanel">
                                <div class="card mb-3">
                                    <div class="card-body d-flex gap-3 align-items-end justify-content-between flex-wrap">
                                        <div>
                                            <div class="d-flex align-items-center">
                                                <label class="visually-hidden" for="volunteerType">Volunteer type</label><select id="volunteerType" class="form-select form-select-sm" style="min-width:220px;max-width:40%">
                                                    <option value="">-- Select a type --</option>
                                                    <option value="STAF">Staff</option>
                                                    <option value="PELAJAR">Student</option>
                                                </select>
                                                <button type="button" id="btnLoadVolunteer" class="btn btn-sm btn-secondary ms-2" style="min-width:120px">Show Data</button>
                                                <span id="volunteerLoadStatus" class="ms-2 text-muted"></span>
                                                <div id="volunteerLoader" class="ms-auto align-self-center" style="display:none;">
                                                    <div class="spinner-border text-primary" role="status" style="width:1.6rem;height:1.6rem;vertical-align:middle;"><span class="visually-hidden">Loading...</span></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="small text-muted d-flex align-items-center gap-2 flex-wrap ms-auto">
                                            <span class="d-inline-block border rounded" style="width:16px;height:16px;background:#fff3cd;"></span>
                                            <span>Representing More Than One Committee</span>
                                            <span class="d-inline-block border rounded" style="width:16px;height:16px;background:#f8d7da;"></span>
                                            <span>Non-UPNM Student</span>
                                        </div>
                                    </div>
                                </div>

                                <div id="volunteerWrap" style="display:none;">
                                    <div class="d-flex justify-content-between align-items-center mb-2 gap-2 flex-wrap">
                                        <div class="d-flex align-items-center ms-auto">
                                            <input type="search" id="searchVolunteer" class="form-control form-control-sm me-2" placeholder="Search recipients..." aria-label="Search certificate recipients" style="max-width:320px;">
                                            <button type="button" id="printAllVolunteer" class="btn btn-sm btn-primary text-nowrap" style="white-space:nowrap;"><i class="fa-solid fa-print me-1"></i>Print All</button>
                                        </div>
                                    </div>
                                        <div class="table-responsive">
                                        <table class="table table-sm table-hover">
                                            <thead class="table-light"><tr>
                                                <th style="width:5%" class="text-center">No</th>
                                                <th style="width:40%">Volunteer Committee Member Name</th>
                                                <th style="width:30%">Committee Member Name</th>
                                                <th style="width:15%">Email</th>
                                                <th style="width:10%" class="text-center">Actions</th>
                                            </tr></thead>
                                            <tbody id="volunteerBody"></tbody>
                                        </table>
                                    </div>
                                    <div class="d-flex justify-content-end align-items-center mt-2">
                                        <button type="button" id="volunteerPrev" class="btn btn-sm btn-outline-secondary me-2">Prev</button>
                                        <span id="volunteerPageInfo" class="me-2">Page 1/1</span>
                                        <button type="button" id="volunteerNext" class="btn btn-sm btn-outline-secondary">Next</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <!-- Add Member Modal -->
                <div class="modal fade" id="addMemberModal" tabindex="-1" aria-labelledby="addMemberModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="addMemberModalLabel">Add Certificate Recipient</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <form id="addMemberForm">
                                    <input type="hidden" id="memberId" name="member_id" value="" />
                                    <div class="mb-2">
                                        <label class="form-label small">Type (Staff / Student / Manual) <span class="text-danger">*</span></label>
                                        <select id="memberRefType" name="member_ref_type" class="form-select form-select-sm" required>
                                            <option value="">-- Select a type --</option>
                                            <option value="STAF">Staff</option>
                                            <option value="PELAJAR">Student</option>
                                            <option value="MANUAL">Manual</option>
                                        </select>
                                    </div>
                                    <div class="mb-2" id="memberManualTypeWrap" style="display:none;">
                                        <label class="form-label small">Manual Category (Staff / Student) <span class="text-danger">*</span></label>
                                        <select id="memberManualType" class="form-select form-select-sm">
                                            <option value="">-- Select a category --</option>
                                            <option value="STAF">Staff</option>
                                            <option value="PELAJAR">Student</option>
                                        </select>
                                    </div>
                                    <div class="mb-2" id="memberStaffSelectWrap" style="display:none;">
                                        <label class="form-label small">Staff List (Sybase) <span class="text-danger">*</span></label>
                                        <select id="memberStaffSelect" class="form-select form-select-sm" data-placeholder="Search by name / staff number..."></select>
                                        <small class="text-muted">Select a staff member to fill in their name and staff number automatically.</small>
                                    </div>
                                    <div class="mb-2" id="memberStudentSelectWrap" style="display:none;">
                                        <label class="form-label small">Student List (Sybase) <span class="text-danger">*</span></label>
                                        <select id="memberStudentSelect" class="form-select form-select-sm" data-placeholder="Search by name / matriculation number..."></select>
                                        <small class="text-muted">Select a student to fill in their name and matriculation number automatically.</small>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small">Committee Member Name <span class="text-danger">*</span></label>
                                        <select id="memberRoleId" name="role_id" class="form-select form-select-sm" required>
                                            <option value="">-- Select Committee --</option>
                                        </select>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small">Full Name <span class="text-danger">*</span></label>
                                        <input type="text" id="memberName" name="member_name" class="form-control form-control-sm" required />
                                    </div>
                                    <div class="mb-2">
                                        <label id="memberRefIdLabel" class="form-label small">Staff / Matriculation Number <span class="text-danger">*</span></label>
                                        <input type="text" id="memberRefId" name="member_ref_id" class="form-control form-control-sm" required />
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small">Email <span class="text-danger">*</span></label>
                                        <input type="email" id="memberEmail" name="member_email" class="form-control form-control-sm" required />
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label small">Phone Number <span class="text-danger">*</span></label>
                                        <input type="text" id="memberPhone" name="member_phone" class="form-control form-control-sm" required />
                                    </div>
                                </form>
                                <div id="addMemberStatus" class="small text-danger" style="display:none;"></div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="button" id="addMemberSave" class="btn btn-sm btn-primary">Save</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal fade" id="duplicateDetailModal" tabindex="-1" aria-labelledby="duplicateDetailModalLabel" aria-hidden="true">
                    <div class="modal-dialog modal-lg" style="max-width:55vw;">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title" id="duplicateDetailModalLabel">Committee Details</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width:8%" class="text-center">No</th>
                                                <th style="width:52%">Name</th>
                                                <th style="width:40%">Committee</th>
                                            </tr>
                                        </thead>
                                        <tbody id="duplicateDetailBody"></tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
                <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
                <style>
                    #memberStaffSelectWrap.force-show,
                    #memberStudentSelectWrap.force-show,
                    #memberManualTypeWrap.force-show {
                        display: block !important;
                    }
                    .icon-action-btn {
                        width: 32px;
                        height: 30px;
                        padding: 0;
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                    }
                    .icon-action-btn .icon-glyph {
                        font-size: 1rem;
                        line-height: 1;
                    }
                </style>

                <script>
                    (function(){
                                var btn = document.getElementById('btnLoadList');
                                var status = document.getElementById('loadStatus');
                                var wrap = document.getElementById('tabsWrap');
                                var kontSubNav = document.getElementById('kontinjenSubNav');
                                var kontSub = document.getElementById('kontinjenSubtabs');
                                var athleteBody = document.getElementById('athleteBody');
                                var pengurusBody = document.getElementById('pengurusBody');
                                var jurulatihBody = document.getElementById('jurulatihBody');
                                var penyelarasBody = document.getElementById('penyelarasBody');
                                // committee elements
                                var committeeType = document.getElementById('committeeType');
                                var btnLoadCommittee = document.getElementById('btnLoadCommittee');
                                var committeeLoadStatus = document.getElementById('committeeLoadStatus');
                                var committeeLoader = document.getElementById('committeeLoader');
                                var committeeWrap = document.getElementById('committeeWrap');
                                var committeeBody = document.getElementById('committeeBody');
                                var printAllCommittee = document.getElementById('printAllCommittee');
                                var searchCommittee = document.getElementById('searchCommittee');
                                var committeePrev = document.getElementById('committeePrev');
                                var committeeNext = document.getElementById('committeeNext');
                                var committeePageInfo = document.getElementById('committeePageInfo');
                                // volunteer elements
                                var volunteerType = document.getElementById('volunteerType');
                                var btnLoadVolunteer = document.getElementById('btnLoadVolunteer');
                                var volunteerLoadStatus = document.getElementById('volunteerLoadStatus');
                                var volunteerLoader = document.getElementById('volunteerLoader');
                                var volunteerWrap = document.getElementById('volunteerWrap');
                                var volunteerBody = document.getElementById('volunteerBody');
                                var printAllVolunteer = document.getElementById('printAllVolunteer');
                                var searchVolunteer = document.getElementById('searchVolunteer');
                                var volunteerPrev = document.getElementById('volunteerPrev');
                                var volunteerNext = document.getElementById('volunteerNext');
                                var volunteerPageInfo = document.getElementById('volunteerPageInfo');
                                var lastRows = { athletes: [], pengurus: [], jurulatih: [], penyelaras: [], committee: [], volunteer: [] };
                                var committeeLoaded = false;
                                var volunteerLoaded = false;
                                var loader = document.getElementById('tableLoader');
                                var printAllPengurus = document.getElementById('printAllPengurus');
                                var printAllJurulatih = document.getElementById('printAllJurulatih');
                                var printAllAtlet = document.getElementById('printAllAtlet');
                                var printAllPenyelaras = document.getElementById('printAllPenyelaras');
                                var searchPenyelaras = document.getElementById('searchPenyelaras');
                                var searchPengurus = document.getElementById('searchPengurus');
                                var searchJurulatih = document.getElementById('searchJurulatih');
                                var searchAtlet = document.getElementById('searchAtlet');
                                var countPengurus = document.getElementById('countPengurus');
                                var countJurulatih = document.getElementById('countJurulatih');
                                var countAtlet = document.getElementById('countAtlet');
                                var countPenyelaras = document.getElementById('countPenyelaras');
                                var lastRows = { athletes: [], pengurus: [], jurulatih: [], penyelaras: [] };

                        function showLoader(){ try{ if(loader) loader.style.display = 'inline-block'; }catch(e){} }
                        function hideLoader(){ try{ if(loader) loader.style.display = 'none'; }catch(e){} }
                        function printDirect(pid){
                            try{
                                showLoader();
                                var iframe = document.createElement('iframe');
                                iframe.style.display = 'none';
                                iframe.src = window.location.pathname + '?print_id=' + encodeURIComponent(pid);
                                iframe.onload = function(){
                                    try { iframe.contentWindow.focus(); iframe.contentWindow.print(); } catch(e) { console.error('printDirect error', e); alert('Unable to start printing.'); }
                                    setTimeout(function(){ try{ document.body.removeChild(iframe); }catch(e){}; hideLoader(); }, 1500);
                                };
                                document.body.appendChild(iframe);
                            }catch(e){ console.error(e); hideLoader(); alert('Unable to start printing.'); }
                        }

                                function buildCertHtml(name, sukanCombined, templateOverride, opts){
        sukanCombined = englishSportLabel(englishLabel(sukanCombined));
                                    var templateUrl = templateOverride || <?php echo json_encode($img_url_versioned); ?>;
                                    opts = opts || {};
                                    var text = (sukanCombined||'').toString();
                                    var nudge = !!opts.nudgeIfLong && text.length > 32;
                                    var sportTop = opts.sportTop || (nudge ? '47%' : '49%');
                                    var sportSize = opts.sportSize || (nudge ? '18px' : '20px');
                                    var sportWeight = opts.sportWeight || '700';
                                    var sportTransform = opts.sportTransform || 'translateX(-50%)';
                                    var sportUpper = (opts.uppercase === false) ? false : true;
                                    var finalText = sportUpper ? text.toUpperCase() : text;
                                    finalText = escHtml(finalText).replace(/\n/g, '<br>');
                                    var html = '<!doctype html><html><head><meta charset="utf-8"><title>Participation Certificates</title>' +
                                        '<style>@page{size:210mm 297mm;margin:0}@media print{html,body{width:210mm;margin:0!important;padding:0!important}.page{width:210mm!important;height:297mm!important;margin:0!important;padding:0!important;border:0!important;box-sizing:border-box;break-inside:avoid;page-break-inside:avoid}.bg-img{object-fit:cover!important;left:-0.5mm!important;top:-0.5mm!important;width:211mm!important;height:298mm!important;max-width:none!important}}html,body{height:100%;margin:0;padding:0}body{background:#fff;font-family:Arial,Helvetica,sans-serif}.page{position:relative;width:210mm;height:297mm;overflow:hidden}.bg-img{position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;z-index:0}.cert-name{position:absolute;left:50%;top:38%;transform:translate(-50%,-50%);width:78%;text-align:center;font-weight:700;color:#000;line-height:1.05;z-index:1;font-size:18px}.cert-sport{position:absolute;left:50%;top:'+sportTop+';transform:'+sportTransform+';width:78%;text-align:center;color:#000;font-weight:'+sportWeight+';z-index:1;font-size:'+sportSize+';line-height:1.25}.page,.bg-img{-webkit-print-color-adjust:exact;print-color-adjust:exact}</style></head><body>' +
                                        '<div class="page">' +
                                            '<img class="bg-img" src="'+templateUrl+'" alt="background">' +
                                            '<div class="cert-name">'+escHtml(name||'')+'</div>' +
                                            '<div class="cert-sport">' + finalText + '</div>' +
                                        '</div>' +
                                        '<script> (function(){ if(window.top===window.self){ setTimeout(function(){ window.print(); },120); } })();<\/script></body></html>';
                                    return html;
                                }

                                function buildMedalCertHtml(name, medalText, sukanText, medalType){
                                    var medalTemplates = {
                                        gold: <?php echo json_encode(url('assets/img/sijil/sijil_emas.jpeg')); ?>,
                                        silver: <?php echo json_encode(url('assets/img/sijil/sijil_perak.jpeg')); ?>,
                                        bronze: <?php echo json_encode(url('assets/img/sijil/sijil_gangsa.jpeg')); ?>
                                    };
                                    var templateUrl = medalTemplates[medalType] || medalTemplates.gold;
                                    var html = '<!doctype html><html><head><meta charset="utf-8"><title>Achievement Certificate</title>' +
                                        '<style>@page{size:210mm 297mm;margin:0}@media print{html,body{width:210mm;margin:0!important;padding:0!important}.page{width:210mm!important;height:297mm!important;margin:0!important;padding:0!important;border:0!important;box-sizing:border-box;break-inside:avoid;page-break-inside:avoid}.bg-img{object-fit:cover!important;left:-0.5mm!important;top:-0.5mm!important;width:211mm!important;height:298mm!important;max-width:none!important}}html,body{height:100%;margin:0;padding:0}body{background:#fff;font-family:Arial,Helvetica,sans-serif}.page{position:relative;width:210mm;height:297mm;overflow:hidden}.bg-img{position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;z-index:0}.medal-name{position:absolute;left:51.8%;top:38%;transform:translate(-50%,-50%);width:78%;text-align:center;font-weight:700;color:#000;line-height:1.15;z-index:1;font-size:20px}.medal-type{position:absolute;left:51.8%;top:45.5%;transform:translate(-50%,-50%);width:78%;text-align:center;font-weight:700;color:#000;line-height:1.15;z-index:1;font-size:20px}.medal-sport{position:absolute;left:51.8%;top:52.5%;transform:translate(-50%,-50%);width:78%;text-align:center;font-weight:700;color:#000;line-height:1.15;z-index:1;font-size:20px}.page,.bg-img{-webkit-print-color-adjust:exact;print-color-adjust:exact}</style></head><body>' +
                                        '<div class="page">' +
                                            '<img class="bg-img" src="'+templateUrl+'" alt="background">' +
                                            '<div class="medal-name">'+escHtml((name||'').toString().trim())+'</div>' +
                                            '<div class="medal-type">'+escHtml((medalText||'').toString().trim().toUpperCase())+'</div>' +
                                            '<div class="medal-sport">'+escHtml((sukanText||'').toString().trim().toUpperCase())+'</div>' +
                                        '</div>' +
                                        '<script> (function(){ if(window.top===window.self){ setTimeout(function(){ window.print(); },120); } })();<\/script></body></html>';
                                    return html;
                                }

                            function printDirectHtml(html){
                                try{
                                    showLoader();
                                    var iframe = document.createElement('iframe');
                                    iframe.style.display = 'none';
                                    document.body.appendChild(iframe);
                                    var doc = iframe.contentWindow.document;
                                    doc.open(); doc.write(html); doc.close();
                                    iframe.onload = function(){ try{ iframe.contentWindow.focus(); iframe.contentWindow.print(); }catch(e){ console.error(e); } setTimeout(function(){ try{ document.body.removeChild(iframe); }catch(e){}; hideLoader(); },1500); };
                                }catch(e){ console.error(e); hideLoader(); }
                            }

                            function sendCertificateEmail(payload){
                                payload = payload || {};
                                var name = (payload.recipient_name || '').toString().trim();
                                var email = (payload.recipient_email || '').toString().trim();
                                var notifyError = function(msg){
                                    if (window.Swal) {
                                        window.Swal.fire({ icon: 'error', title: 'Error', text: msg });
                                    } else {
                                        alert(msg);
                                    }
                                };
                                if (!name) { notifyError('Recipient name is missing.'); return; }
                                if (!email) { notifyError('Recipient email address is missing.'); return; }
                                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { notifyError('Invalid recipient email address.'); return; }

                                var htmlConfirm = '<div style="text-align:center">'
                                    + '<div>' + escHtml(name) + '</div>'
                                    + '<div>' + escHtml(email) + '</div>'
                                    + '</div>';

                                var doSend = function(){
                                    showLoader();
                                    var fd = new URLSearchParams();
                                    fd.append('recipient_name', name);
                                    fd.append('recipient_email', email);
                                    fd.append('cert_type', payload.cert_type || '');
                                    fd.append('role_text', payload.role_text || '');
                                    if (payload.athlete_id) fd.append('athlete_id', String(payload.athlete_id));
                                    if (payload.member_id) fd.append('member_id', String(payload.member_id));

                                    fetch(window.location.pathname + '?ajax=send_certificate_email', {
                                        method: 'POST',
                                        body: fd,
                                        credentials: 'same-origin'
                                    })
                                    .then(function(res){ if(!res.ok) throw new Error('HTTP ' + res.status); return res.text(); })
                                    .then(function(t){
                                        var j = null;
                                        try { j = JSON.parse(t); } catch(parseErr){ throw new Error('Invalid response server'); }
                                        if (!j || !j.ok) throw new Error((j && j.error) ? j.error : 'Unable to send email.');
                                        if (window.Swal) Swal.fire({ icon:'success', title:'Success', text: j.message || 'Certificate email sent successfully.' });
                                        else alert(j.message || 'Certificate email sent successfully.');
                                        try {
                                            if ((payload.cert_type || '') === 'committee' && btnLoadCommittee) {
                                                preserveCommitteePage = currentCommitteePage;
                                                btnLoadCommittee.click();
                                            } else if ((payload.cert_type || '') === 'volunteer' && btnLoadVolunteer) {
                                                preserveVolunteerPage = currentVolunteerPage;
                                                btnLoadVolunteer.click();
                                            }
                                        } catch (e) {}
                                    })
                                    .catch(function(err){
                                        if (window.Swal) Swal.fire({ icon:'error', title:'Failed', text: err && err.message ? err.message : 'Unable to send email.' });
                                        else alert('Unable to send email: ' + (err && err.message ? err.message : 'Unknown'));
                                    })
                                    .finally(function(){ hideLoader(); });
                                };

                                if (window.Swal){
                                    Swal.fire({
                                        title: 'Email Certificate?',
                                        html: htmlConfirm,
                                        icon: 'question',
                                        width: 640,
                                        showCancelButton: true,
                                        confirmButtonText: 'Send',
                                        cancelButtonText: 'Cancel'
                                    }).then(function(res){ if (res && res.isConfirmed) doSend(); });
                                } else {
                                    if (confirm('Send the certificate email to ' + name + ' (' + email + ')?')) doSend();
                                }
                            }

                        // Pagination and rendering helpers
                        var PAGE_SIZE = 10;
                        var currentPenyelarasPage = 1, currentPengurusPage = 1, currentJurulatihPage = 1, currentAthletePage = 1;

                        // Render cell HTML with empty-value badge
                        function escHtml(s){ return (s===null||s===undefined)?'':String(s).replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
                        function cellHtml(val, center, allowHtml){
                            var v = (val===null||val===undefined)?'' : String(val).trim();
                            var cls = center ? 'text-center' : 'text-truncate';
                            if (v === '') return '<td class="'+cls+'"><span class="no-data-badge">None</span></td>'; 
                            if (allowHtml) return '<td class="'+cls+'">'+v+'</td>';
                            return '<td class="'+cls+'" title="'+escHtml(v)+'">'+escHtml(v)+'</td>';
                        }
                        function appendNoDataRow(tbodyEl, colCount){
                            if (!tbodyEl) return;
                            var tr = document.createElement('tr');
                            var html = '';
                            for (var i = 0; i < colCount; i++) {
                                html += '<td class="text-center"><span class="no-data-badge">None</span></td>';
                            }
                            tr.innerHTML = html;
                            tbodyEl.appendChild(tr);
                        }

                        function getSelectedKod(){ var sel = document.getElementById('selectKont'); return sel && sel.value ? sel.value : ''; }
                        function matchSearch(texts, keyword){
                            var q = (keyword || '').toString().trim().toLowerCase();
                            if (!q) return true;
                            for (var i = 0; i < texts.length; i++) {
                                var t = (texts[i] || '').toString().toLowerCase();
                                if (t.indexOf(q) !== -1) return true;
                            }
                            return false;
                        }
                        function normalizeRefKey(v){
                            return String(v || '').trim().toUpperCase();
                        }
                        function formatBadgeDateTime(v){
                            var s = String(v || '').trim();
                            if (!s) return '';
                            // Expected source: YYYY-MM-DD HH:MM:SS (MySQL DATETIME)
                            var m = s.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/);
                            if (m) {
                                return m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] + ';' + m[6];
                            }
                            var d = new Date(s);
                            if (!isNaN(d.getTime())) {
                                var dd = String(d.getDate()).padStart(2, '0');
                                var mm = String(d.getMonth() + 1).padStart(2, '0');
                                var yyyy = d.getFullYear();
                                var hh = String(d.getHours()).padStart(2, '0');
                                var mi = String(d.getMinutes()).padStart(2, '0');
                                var ss = String(d.getSeconds()).padStart(2, '0');
                                return dd + '/' + mm + '/' + yyyy + ' ' + hh + ':' + mi + ';' + ss;
                            }
                            return s;
                        }
                        function openDuplicateDetailModal(scope, refId){
                            try{
                                var refKey = normalizeRefKey(refId);
                                if (!refKey) return;
                                var rowsSource = (scope === 'volunteer') ? (lastRows.volunteer || []) : (lastRows.committee || []);
                                var rows = rowsSource.filter(function(r){
                                    return normalizeRefKey(r && r.member_ref_id) === refKey;
                                });
                                var body = document.getElementById('duplicateDetailBody');
                                if (!body) return;
                                body.innerHTML = '';
                                if (!rows.length) {
                                    var trEmpty = document.createElement('tr');
                                    trEmpty.innerHTML = '<td colspan="3" class="text-center text-muted">No records</td>';
                                    body.appendChild(trEmpty);
                                } else {
                                    rows.forEach(function(r, i){
                                        var tr = document.createElement('tr');
                                        var nm = String(r && r.member_name ? r.member_name : '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                        var rl = String(r && r.role_name ? r.role_name : '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                        tr.innerHTML = '<td class="text-center">' + (i + 1) + '</td>' +
                                            '<td>' + (nm || '-') + '</td>' +
                                            '<td>' + (rl || '-') + '</td>';
                                        body.appendChild(tr);
                                    });
                                }
                                var lbl = document.getElementById('duplicateDetailModalLabel');
                                if (lbl) lbl.textContent = 'Committee Details (' + refKey + ')';
                                var modalEl = document.getElementById('duplicateDetailModal');
                                if (!modalEl) return;
                                if (window.bootstrap && window.bootstrap.Modal) {
                                    window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
                                } else {
                                    modalEl.classList.add('show');
                                    modalEl.style.display = 'block';
                                    document.body.classList.add('modal-open');
                                }
                            }catch(e){
                                console.error('openDuplicateDetailModal error', e);
                            }
                        }

                        function renderPengurusPage(){
                            var rawList = lastRows.pengurus || [];
                            var q = searchPengurus ? searchPengurus.value : '';
                            var list = rawList.filter(function(p){
                                return matchSearch([p.nama, p.jawatan, p.tel], q);
                            });
                            var total = list.length;
                            var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
                            if (currentPengurusPage > pages) currentPengurusPage = pages;
                            var start = (currentPengurusPage - 1) * PAGE_SIZE;
                            var slice = list.slice(start, start + PAGE_SIZE);
                            pengurusBody.innerHTML = '';
                            if (!slice.length) {
                                appendNoDataRow(pengurusBody, 5);
                            }
                            slice.forEach(function(p, idx){
                                var nIdx = start + idx + 1;
                                var tr = document.createElement('tr');
                                var telSafe = (p.tel || '').replace(/</g,'&lt;');
                                tr.innerHTML = '<td class="text-center">'+nIdx+'</td>' +
                                    (function(){ return cellHtml(p.nama, false); })() +
                                    (function(){ return cellHtml(englishLabel(p.jawatan || ''), false); })() +
                                    (function(){ return cellHtml(p.tel || '', false); })() +
                                    '<td class="text-center">'
                                    + '<button type="button" class="btn btn-sm btn-outline-primary icon-action-btn me-1 do-print-pengurus" title="Print" aria-label="Print"><span class="icon-glyph">🖨️</span></button>'
                                    + '</td>';
                                pengurusBody.appendChild(tr);
                                tr.querySelector('.do-print-pengurus').addEventListener('click', function(){
                                    var roleText = (p.jawatan || '').toString().trim();
                                    if (!roleText) roleText = 'PENGURUS';
                                    roleText = englishLabel(roleText).toUpperCase();
                                    printDirectHtml(buildCertHtml(p.nama, roleText, <?php echo json_encode(url('assets/img/sijil/sijil_pengurus.jpeg')); ?>, { sportTop: '48.5%' }));
                                });
                            });
                            try{ document.getElementById('pengurusPageInfo').textContent = 'Page ' + currentPengurusPage + '/' + pages; }catch(e){}
                            try{ document.getElementById('pengurusPrev').disabled = currentPengurusPage <= 1; }catch(e){}
                            try{ document.getElementById('pengurusNext').disabled = currentPengurusPage >= pages; }catch(e){}
                            if (countPengurus) countPengurus.textContent = total;
                            if (printAllPengurus) printAllPengurus.disabled = total === 0;
                        }

                        function renderJurulatihPage(){
                            var rawList = lastRows.jurulatih || [];
                            var q = searchJurulatih ? searchJurulatih.value : '';
                            var list = rawList.filter(function(p){
                                return matchSearch([p.nama, p.jawatan, p.tel], q);
                            });
                            var total = list.length;
                            var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
                            if (currentJurulatihPage > pages) currentJurulatihPage = pages;
                            var start = (currentJurulatihPage - 1) * PAGE_SIZE;
                            var slice = list.slice(start, start + PAGE_SIZE);
                            jurulatihBody.innerHTML = '';
                            if (!slice.length) {
                                appendNoDataRow(jurulatihBody, 5);
                            }
                            slice.forEach(function(p, idx){
                                var nIdx = start + idx + 1;
                                var tr = document.createElement('tr');
                                var telSafe = (p.tel || '').replace(/</g,'&lt;');
                                tr.innerHTML = '<td class="text-center">'+nIdx+'</td>' +
                                    (function(){ return cellHtml(p.nama, false); })() +
                                    (function(){ return cellHtml(englishLabel(p.jawatan || ''), false); })() +
                                    (function(){ return cellHtml(p.tel || '', false); })() +
                                    '<td class="text-center">'
                                    + '<button type="button" class="btn btn-sm btn-outline-primary icon-action-btn me-1 do-print-jurulatih" title="Print" aria-label="Print"><span class="icon-glyph">🖨️</span></button>'
                                    + '</td>';
                                jurulatihBody.appendChild(tr);
                                tr.querySelector('.do-print-jurulatih').addEventListener('click', function(){
                                    var roleText = (p.jawatan || '').toString().trim();
                                    if (!roleText) roleText = 'JURULATIH';
                                    roleText = englishLabel(roleText).toUpperCase();
                                    printDirectHtml(buildCertHtml(p.nama, roleText, <?php echo json_encode(url('assets/img/sijil/sijil_jurulatih.jpeg')); ?>, { sportTop: '48.5%' }));
                                });
                            });
                            try{ document.getElementById('jurulatihPageInfo').textContent = 'Page ' + currentJurulatihPage + '/' + pages; }catch(e){}
                            try{ document.getElementById('jurulatihPrev').disabled = currentJurulatihPage <= 1; }catch(e){}
                            try{ document.getElementById('jurulatihNext').disabled = currentJurulatihPage >= pages; }catch(e){}
                            if (countJurulatih) countJurulatih.textContent = total;
                            if (printAllJurulatih) printAllJurulatih.disabled = total === 0;
                        }

                        function renderAthletePage(){
                            var rawList = lastRows.athletes || [];
                            var q = searchAtlet ? searchAtlet.value : '';
                            var list = rawList.filter(function(r){
                                return matchSearch([r.nama, r.sukan, r.acara], q);
                            });
                            var total = list.length;
                            var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
                            if (currentAthletePage > pages) currentAthletePage = pages;
                            var start = (currentAthletePage - 1) * PAGE_SIZE;
                            var slice = list.slice(start, start + PAGE_SIZE);
                            athleteBody.innerHTML = '';
                            if (!slice.length) {
                                appendNoDataRow(athleteBody, 5);
                            }
                            slice.forEach(function(r, idx){
                                var pid = r.id || '';
                                var name = (r.nama || '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                var sukan = (r.sukan || '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                var acara = (r.acara || '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                var medalType = String(r.medal_type || '').toLowerCase();
                                var medalLabel = '';
                                if (medalType === 'gold') medalLabel = 'Gold';
                                else if (medalType === 'silver') medalLabel = 'Silver';
                                else if (medalType === 'bronze') medalLabel = 'Bronze';
                                var rawSukan = (r.sukan || '').toString().trim();
                                var rawAcara = (r.acara || '').toString().trim();
                                var info = sukan;
                                if (acara !== '') info = info !== '' ? (info + ' (' + acara + ')') : acara;
                                var nIdx = start + idx + 1;
                                var tr = document.createElement('tr');
                                if (medalType === 'gold' || medalType === 'silver' || medalType === 'bronze') {
                                    tr.classList.add('medal-' + medalType);
                                }
                                var medalPrintHtml = '';
                                if (medalLabel) {
                                    medalPrintHtml = ' <button type="button" class="btn btn-sm btn-outline-primary icon-action-btn do-print-medal" title="Print Certificate Medals" aria-label="Print Certificate Medals"><span class="icon-glyph">🖨️</span></button>';
                                }
                                tr.innerHTML = '<td class="text-center">'+nIdx+'</td>'+
                                    (function(){ return cellHtml(name, false); })() +
                                    (function(){ return cellHtml(info, false); })() +
                                    '<td class="text-center">'+(medalLabel ? ('<span>'+medalLabel+'</span>' + medalPrintHtml) : '<span class="no-data-badge">None</span>')+'</td>' +
                                    '<td class="text-center">'
                                    + '<button type="button" class="btn btn-sm btn-outline-primary icon-action-btn me-1 do-print" title="Print" aria-label="Print"><span class="icon-glyph">🖨️</span></button>'
                                    + '</td>';
                                athleteBody.appendChild(tr);
                                var btnEl = tr.querySelector('.do-print');
                                if (btnEl) {
                                    btnEl.addEventListener('click', function(e){ e.preventDefault(); printDirect(pid); });
                                }
                                var medalBtnEl = tr.querySelector('.do-print-medal');
                                if (medalBtnEl) {
                                    medalBtnEl.addEventListener('click', function(e){
                                        e.preventDefault();
                                        var medalText = 'PINGAT ' + medalLabel;
                                        var sukanKategori = rawSukan;
                                        if (rawAcara !== '') sukanKategori = sukanKategori ? (sukanKategori + ' (' + rawAcara + ')') : rawAcara;
                                        printDirectHtml(buildMedalCertHtml(r.nama || '', medalText, sukanKategori, medalType));
                                    });
                                }
                            });

                        // Add Member modal wiring
                        try{
                            var btnAddMember = document.getElementById('btnAddMember');
                            var addMemberModalEl = document.getElementById('addMemberModal');
                            var addMemberModal = (typeof bootstrap !== 'undefined' && addMemberModalEl) ? new bootstrap.Modal(addMemberModalEl, { backdrop: false }) : null;
                            var duplicateDetailModalEl = document.getElementById('duplicateDetailModal');
                            var duplicateDetailModal = (typeof bootstrap !== 'undefined' && duplicateDetailModalEl) ? new bootstrap.Modal(duplicateDetailModalEl) : null;
                            var roleSelect = document.getElementById('memberRoleId');
                            var addMemberStatus = document.getElementById('addMemberStatus');
                            var addMemberSave = document.getElementById('addMemberSave');
                            var memberRefTypeEl = document.getElementById('memberRefType');
                            var memberManualTypeWrapEl = document.getElementById('memberManualTypeWrap');
                            var memberManualTypeEl = document.getElementById('memberManualType');
                            var memberNameEl = document.getElementById('memberName');
                            var memberRefIdEl = document.getElementById('memberRefId');
                            var memberRefIdLabelEl = document.getElementById('memberRefIdLabel');
                            var memberStaffWrapEl = document.getElementById('memberStaffSelectWrap');
                            var memberStaffSelectEl = document.getElementById('memberStaffSelect');
                            var memberStudentWrapEl = document.getElementById('memberStudentSelectWrap');
                            var memberStudentSelectEl = document.getElementById('memberStudentSelect');

                            function normalizeRefKey(v){
                                return String(v || '').trim().toUpperCase();
                            }

                            function openDuplicateDetail(scope, refId){
                                try{
                                    var refKey = normalizeRefKey(refId);
                                    if (!refKey) return;
                                    var rowsSource = [];
                                    if (scope === 'volunteer') rowsSource = lastRows.volunteer || [];
                                    else rowsSource = lastRows.committee || [];
                                    var rows = rowsSource.filter(function(r){
                                        return normalizeRefKey(r && r.member_ref_id) === refKey;
                                    });
                                    var body = document.getElementById('duplicateDetailBody');
                                    if (!body) return;
                                    body.innerHTML = '';
                                    if (!rows.length) {
                                        var trEmpty = document.createElement('tr');
                                        trEmpty.innerHTML = '<td colspan="3" class="text-center text-muted">No records</td>';
                                        body.appendChild(trEmpty);
                                    } else {
                                        rows.forEach(function(r, i){
                                            var tr = document.createElement('tr');
                                            var nm = String(r && r.member_name ? r.member_name : '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                            var rl = String(r && r.role_name ? r.role_name : '').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                            tr.innerHTML = '<td class="text-center">' + (i + 1) + '</td>' +
                                                '<td>' + (nm || '-') + '</td>' +
                                                '<td>' + (rl || '-') + '</td>';
                                            body.appendChild(tr);
                                        });
                                    }
                                    var lbl = document.getElementById('duplicateDetailModalLabel');
                                    if (lbl) lbl.textContent = 'Committee Details (' + refKey + ')';
                                    if (duplicateDetailModal) duplicateDetailModal.show();
                                    else if (duplicateDetailModalEl) {
                                        duplicateDetailModalEl.classList.add('show');
                                        duplicateDetailModalEl.style.display = 'block';
                                        document.body.classList.add('modal-open');
                                    }
                                }catch(e){}
                            }

                            function loadRolesIfNeeded(){
                                if (!roleSelect) return Promise.resolve();
                                if (roleSelect.dataset.loaded) return Promise.resolve();
                                var url = window.location.pathname + '?ajax=committee_roles';
                                return fetch(url, { credentials: 'same-origin' }).then(function(r){ if(!r.ok) throw new Error('HTTP ' + r.status); return r.text(); }).then(function(t){ var j = JSON.parse(t); if (!j.ok) throw new Error(j.error || 'Unable to load positions'); roleSelect.innerHTML = '<option value="">-- Select Committee --</option>'; j.rows.forEach(function(r){ var opt = document.createElement('option'); opt.value = r.id; opt.textContent = r.role_name; roleSelect.appendChild(opt); }); roleSelect.dataset.loaded = '1'; });
                            }
                            try{ window.loadRolesIfNeeded = loadRolesIfNeeded; }catch(e){}

                            // Helper to show/hide modal even if Bootstrap Modal isn't available
                            function resetAddMemberModalState(){
                                try{ var mid = document.getElementById('memberId'); if (mid) mid.value = ''; }catch(e){}
                                try{ if (memberRefTypeEl) memberRefTypeEl.disabled = false; }catch(e){}
                                try{
                                    if (memberStaffWrapEl) memberStaffWrapEl.classList.remove('force-show');
                                    if (memberStudentWrapEl) memberStudentWrapEl.classList.remove('force-show');
                                    if (memberManualTypeWrapEl) memberManualTypeWrapEl.classList.remove('force-show');
                                }catch(e){}
                                try{
                                    if (addMemberSave) {
                                        addMemberSave.textContent = 'Save';
                                        addMemberSave.dataset.editing = '0';
                                        delete addMemberSave.dataset.memberId;
                                    }
                                }catch(e){}
                                try{ var lbl = document.getElementById('addMemberModalLabel'); if (lbl) lbl.textContent = 'Add Certificate Recipient'; }catch(e){}
                            }
                            try{ window.resetAddMemberModalState = resetAddMemberModalState; }catch(e){}

                            function showAddMemberModal(){
                                try{
                                    if (addMemberModal && typeof addMemberModal.show === 'function') return addMemberModal.show();
                                }catch(e){}
                                try{
                                    if (addMemberModalEl){
                                        addMemberModalEl.classList.add('show');
                                        addMemberModalEl.style.display = 'block';
                                        document.body.classList.add('modal-open');
                                        // add light backdrop if not present
                                        if (!document.getElementById('addMemberLightBackdrop')){
                                            var lb = document.createElement('div'); lb.id = 'addMemberLightBackdrop'; lb.style.position='fixed'; lb.style.left='0'; lb.style.top='0'; lb.style.right='0'; lb.style.bottom='0'; lb.style.background='rgba(0,0,0,0.06)'; lb.style.zIndex='1040'; document.body.appendChild(lb);
                                        }
                                    }
                                }catch(e){ }
                            }
                            try{ window.showAddMemberModal = showAddMemberModal; }catch(e){}
                            function hideAddMemberModal(){
                                try{
                                    if (addMemberModal && typeof addMemberModal.hide === 'function') return addMemberModal.hide();
                                }catch(e){}
                                try{
                                    if (addMemberModalEl){
                                        addMemberModalEl.classList.remove('show');
                                        addMemberModalEl.style.display = 'none';
                                        document.body.classList.remove('modal-open');
                                        var lb = document.getElementById('addMemberLightBackdrop'); if (lb && lb.parentNode) lb.parentNode.removeChild(lb);
                                        resetAddMemberModalState();
                                    }
                                }catch(e){}
                            }
                            try{ window.hideAddMemberModal = hideAddMemberModal; }catch(e){}

                            // initialize select2 if available (after roles loaded)
                            function initRoleSelect2(){
                                try{
                                    if (!roleSelect) return;
                                    ensureSelect2Loaded(function(isLoaded){
                                        if (!isLoaded) return;
                                        var $sel = jQuery(roleSelect);
                                        if ($sel.data('select2')) { $sel.select2('destroy'); }
                                        $sel.select2({
                                            theme: 'bootstrap-5',
                                            dropdownParent: addMemberModalEl ? jQuery(addMemberModalEl) : undefined,
                                            width: '100%',
                                            placeholder: '-- Select Committee --',
                                            allowClear: true
                                        });
                                        try{
                                            var $c = $sel.next('.select2-container');
                                            if ($c && $c.length) $c.css('width', '100%');
                                        }catch(e){}
                                        $sel.off('select2:select.sijilrole select2:clear.sijilrole change.sijilrole')
                                            .on('select2:select.sijilrole select2:clear.sijilrole change.sijilrole', function(){
                                                updateAddMemberSaveState();
                                            });
                                    });
                                }catch(e){ console.warn('select2 init failed', e); }
                            }
                            try{ window.initRoleSelect2 = initRoleSelect2; }catch(e){}

                            function resetStaffSelect(){
                                try{
                                    if (window.jQuery && typeof jQuery.fn.select2 === 'function' && memberStaffSelectEl){
                                        var $staff = jQuery(memberStaffSelectEl);
                                        if ($staff.data('select2')) {
                                            $staff.val(null).trigger('change');
                                        }
                                    } else if (memberStaffSelectEl) {
                                        memberStaffSelectEl.value = '';
                                    }
                                }catch(e){}
                            }

                            function resetStudentSelect(){
                                try{
                                    if (window.jQuery && typeof jQuery.fn.select2 === 'function' && memberStudentSelectEl){
                                        var $student = jQuery(memberStudentSelectEl);
                                        if ($student.data('select2')) {
                                            $student.val(null).trigger('change');
                                        }
                                    } else if (memberStudentSelectEl) {
                                        memberStudentSelectEl.value = '';
                                    }
                                }catch(e){}
                            }

                            function normalizeRefTypeForSave(){
                                var refType = (memberRefTypeEl && memberRefTypeEl.value ? memberRefTypeEl.value : '').toUpperCase();
                                var manualType = (memberManualTypeEl && memberManualTypeEl.value ? memberManualTypeEl.value : '').toUpperCase();
                                if (refType === 'MANUAL') return manualType;
                                return refType;
                            }

                            function getSelectSingleValue(el){
                                try{
                                    if (!el) return '';
                                    var raw = '';
                                    if (window.jQuery && typeof jQuery === 'function'){
                                        var $el = jQuery(el);
                                        raw = $el.val();
                                        if ((!raw || (Array.isArray(raw) && raw.length === 0)) && $el.data('select2') && typeof $el.select2 === 'function'){
                                            var selectedData = $el.select2('data');
                                            if (selectedData && selectedData.length && selectedData[0] && selectedData[0].id != null) raw = selectedData[0].id;
                                        }
                                    } else {
                                        raw = el.value || '';
                                    }
                                    if (Array.isArray(raw)) raw = raw.length ? raw[0] : '';
                                    return String(raw || '').trim();
                                }catch(e){
                                    return '';
                                }
                            }

                            function applyDefaultIfEmpty(typeCode){
                                var t = (typeCode || '').toUpperCase();
                                if (t !== 'STAF' && t !== 'PELAJAR') return;
                                try{
                                    if (memberNameEl && String(memberNameEl.value || '').trim() === '') memberNameEl.value = 'No Record';
                                    if (memberRefIdEl && String(memberRefIdEl.value || '').trim() === '') memberRefIdEl.value = 'No Record';
                                    var memberEmailEl = document.getElementById('memberEmail');
                                    var memberPhoneEl = document.getElementById('memberPhone');
                                    if (memberEmailEl && String(memberEmailEl.value || '').trim() === '') memberEmailEl.value = 'No Record';
                                    if (memberPhoneEl && String(memberPhoneEl.value || '').trim() === '') memberPhoneEl.value = 'No Record';
                                }catch(e){}
                            }

                            function isValidEmailOrDefault(v){
                                var s = String(v || '').trim();
                                if (s.toUpperCase() === 'NO RECORDS') return true;
                                var emRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                                return emRe.test(s);
                            }

                            function validateMemberForm(showMessage){
                                showMessage = !!showMessage;
                                var typeRaw = (memberRefTypeEl && memberRefTypeEl.value ? memberRefTypeEl.value : '').toUpperCase();
                                var manualType = (memberManualTypeEl && memberManualTypeEl.value ? memberManualTypeEl.value : '').toUpperCase();
                                var roleId = (document.getElementById('memberRoleId')||{value:''}).value;
                                var name = (document.getElementById('memberName')||{value:''}).value.trim();
                                var refId = (document.getElementById('memberRefId')||{value:''}).value.trim();
                                var email = (document.getElementById('memberEmail')||{value:''}).value.trim();
                                var phone = (document.getElementById('memberPhone')||{value:''}).value.trim();
                                var typeToSave = normalizeRefTypeForSave();

                                var setErr = function(msg){
                                    if (showMessage && addMemberStatus){
                                        addMemberStatus.style.display = 'block';
                                        addMemberStatus.textContent = msg;
                                    }
                                    return { ok: false, message: msg };
                                };

                                if (!typeRaw) return setErr('Please select a type.');
                                if (typeRaw === 'MANUAL'){
                                    if (!typeToSave || (typeToSave !== 'STAF' && typeToSave !== 'PELAJAR')) {
                                        return setErr('Please select a manual category (Staff / Student).');
                                    }
                                }

                                if (!roleId) return setErr('Please select a committee member.');

                                if (typeRaw === 'STAF'){
                                    var staffVal = getSelectSingleValue(memberStaffSelectEl);
                                    if (!staffVal) return setErr('Please select a staff member from the list first.');
                                    applyDefaultIfEmpty('STAF');
                                    name = (memberNameEl && memberNameEl.value ? memberNameEl.value : '').trim();
                                    refId = (memberRefIdEl && memberRefIdEl.value ? memberRefIdEl.value : '').trim();
                                    email = (document.getElementById('memberEmail')||{value:''}).value.trim();
                                    phone = (document.getElementById('memberPhone')||{value:''}).value.trim();
                                } else if (typeRaw === 'PELAJAR'){
                                    var studentVal = getSelectSingleValue(memberStudentSelectEl);
                                    if (!studentVal) return setErr('Please select a student from the list first.');
                                    applyDefaultIfEmpty('PELAJAR');
                                    name = (memberNameEl && memberNameEl.value ? memberNameEl.value : '').trim();
                                    refId = (memberRefIdEl && memberRefIdEl.value ? memberRefIdEl.value : '').trim();
                                    email = (document.getElementById('memberEmail')||{value:''}).value.trim();
                                    phone = (document.getElementById('memberPhone')||{value:''}).value.trim();
                                } else if (typeRaw === 'MANUAL'){
                                    if (manualType !== 'STAF' && manualType !== 'PELAJAR') return setErr('Please select a manual category (Staff / Student).');
                                }

                                if (!name || !refId || !email || !phone) {
                                    return setErr('All fields in this form are required.');
                                }
                                if (!isValidEmailOrDefault(email)) {
                                    return setErr('Please enter a valid email address.');
                                }
                                if (addMemberStatus) { addMemberStatus.style.display = 'none'; addMemberStatus.textContent = ''; }
                                return { ok: true, typeToSave: typeToSave };
                            }

                            function updateAddMemberSaveState(){
                                try{
                                    if (!addMemberSave) return;
                                    var check = validateMemberForm(false);
                                    addMemberSave.disabled = !check.ok;
                                }catch(e){}
                            }

                            function ensureSelect2Loaded(callback){
                                callback = callback || function(){};
                                try{
                                    var cdnSelect2Js = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js';
                                    var cdnSelect2Css = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css';
                                    var cdnSelect2ThemeCss = 'https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css';

                                    if (!(window.jQuery && window.jQuery.fn)) {
                                        var waitJqCount = 0;
                                        var waitJqTimer = setInterval(function(){
                                            waitJqCount++;
                                            if (window.jQuery && window.jQuery.fn){
                                                clearInterval(waitJqTimer);
                                                ensureSelect2Loaded(callback);
                                            } else if (waitJqCount > 50){
                                                clearInterval(waitJqTimer);
                                                callback(false);
                                            }
                                        }, 100);
                                        return;
                                    }

                                    if (!document.getElementById('dyn-select2-css')){
                                        var css = document.createElement('link');
                                        css.id = 'dyn-select2-css';
                                        css.rel = 'stylesheet';
                                        css.href = cdnSelect2Css;
                                        document.head.appendChild(css);
                                    }
                                    if (!document.getElementById('dyn-select2-theme-css')){
                                        var css2 = document.createElement('link');
                                        css2.id = 'dyn-select2-theme-css';
                                        css2.rel = 'stylesheet';
                                        css2.href = cdnSelect2ThemeCss;
                                        document.head.appendChild(css2);
                                    }

                                    if (window.jQuery.fn.select2) return callback(true);

                                    var oldJs = document.getElementById('dyn-select2-js');
                                    if (oldJs && oldJs.parentNode) oldJs.parentNode.removeChild(oldJs);

                                    var js = document.createElement('script');
                                    js.id = 'dyn-select2-js';
                                    js.src = cdnSelect2Js + '?v=' + Date.now();
                                    js.onload = function(){
                                        callback(!!(window.jQuery && window.jQuery.fn && window.jQuery.fn.select2));
                                    };
                                    js.onerror = function(){ callback(false); };
                                    document.head.appendChild(js);
                                }catch(e){ callback(false); }
                            }

                            function initStaffSelect2(){
                                try{
                                    if (!memberStaffSelectEl) return;
                                    ensureSelect2Loaded(function(isLoaded){
                                    if (!isLoaded) {
                                        // fallback: native select with preloaded options
                                        fetch(window.location.pathname + '?ajax=staff_lookup&limit=100', { credentials: 'same-origin' })
                                            .then(function(res){ return res.json(); })
                                            .then(function(data){
                                                if (!data || !data.ok) {
                                                    if (addMemberStatus) {
                                                        addMemberStatus.style.display = 'block';
                                                        addMemberStatus.textContent = (data && data.error) ? data.error : 'Unable to load the staff list.';
                                                    }
                                                    return;
                                                }
                                                memberStaffSelectEl.innerHTML = '<option value=\"\">-- Select Staff --</option>';
                                                (data.results || []).forEach(function(r){
                                                    var opt = document.createElement('option');
                                                    opt.value = r.id || '';
                                                    opt.textContent = r.text || (r.gelar_nama || r.id || '');
                                                    opt.dataset.nopekerja = r.nopekerja || '';
                                                    opt.dataset.gelarNama = r.gelar_nama || '';
                                                    opt.dataset.email = r.email || '';
                                                    opt.dataset.phone = r.phone || '';
                                                    memberStaffSelectEl.appendChild(opt);
                                                });
                                                if (addMemberStatus) {
                                                    addMemberStatus.style.display = 'none';
                                                    addMemberStatus.textContent = '';
                                                }
                                            })
                                            .catch(function(err){
                                                if (addMemberStatus) {
                                                    addMemberStatus.style.display = 'block';
                                                    addMemberStatus.textContent = 'Unable to load staff: ' + (err && err.message ? err.message : 'Unknown');
                                                }
                                            });
                                        memberStaffSelectEl.onchange = function(){
                                            var selected = memberStaffSelectEl.options[memberStaffSelectEl.selectedIndex];
                                            if (!selected || !selected.value) return;
                                            if (memberNameEl) memberNameEl.value = selected.dataset.gelarNama || '';
                                            if (memberRefIdEl) memberRefIdEl.value = selected.dataset.nopekerja || selected.value || '';
                                            var memberEmailEl = document.getElementById('memberEmail');
                                            var memberPhoneEl = document.getElementById('memberPhone');
                                            if (memberEmailEl) memberEmailEl.value = selected.dataset.email || '';
                                            if (memberPhoneEl) memberPhoneEl.value = selected.dataset.phone || '';
                                        };
                                        return;
                                    }

                                    var $staff = jQuery(memberStaffSelectEl);
                                    if ($staff.data('select2')) { $staff.select2('destroy'); }
                                    $staff.empty();
                                    $staff.select2({
                                        theme: 'bootstrap-5',
                                        dropdownParent: addMemberModalEl ? jQuery(addMemberModalEl) : undefined,
                                        width: '100%',
                                        placeholder: memberStaffSelectEl.getAttribute('data-placeholder') || 'Search staff names...',
                                        allowClear: true,
                                        minimumInputLength: 0,
                                        ajax: {
                                            delay: 250,
                                            url: window.location.pathname + '?ajax=staff_lookup',
                                            dataType: 'json',
                                            data: function(params){
                                                return { q: params.term || '', limit: 100 };
                                            },
                                            processResults: function(data){
                                                if (!data || !data.ok){
                                                    try{
                                                        if (addMemberStatus){
                                                            addMemberStatus.style.display = 'block';
                                                            addMemberStatus.textContent = (data && data.error) ? data.error : 'Unable to load the staff list.';
                                                        }
                                                    }catch(e){}
                                                    return { results: [] };
                                                }
                                                try{
                                                    if (addMemberStatus) {
                                                        addMemberStatus.style.display = 'none';
                                                        addMemberStatus.textContent = '';
                                                    }
                                                }catch(e){}
                                                return { results: data.results || [] };
                                            }
                                        }
                                    });
                                    try{
                                        var $c = $staff.next('.select2-container');
                                        if ($c && $c.length) $c.css('width', '100%');
                                    }catch(e){}
                                    $staff.off('select2:select.sijilstaff').on('select2:select.sijilstaff', function(ev){
                                        var d = ev && ev.params && ev.params.data ? ev.params.data : null;
                                        if (!d) return;
                                        try{
                                            if (memberNameEl && d.gelar_nama) memberNameEl.value = d.gelar_nama;
                                            if (memberRefIdEl) memberRefIdEl.value = (d.nopekerja || d.id || '').toString();
                                            var memberEmailEl = document.getElementById('memberEmail');
                                            var memberPhoneEl = document.getElementById('memberPhone');
                                            if (memberEmailEl) memberEmailEl.value = d.email || '';
                                            if (memberPhoneEl) memberPhoneEl.value = d.phone || '';
                                            applyDefaultIfEmpty('STAF');
                                            setTimeout(updateAddMemberSaveState, 0);
                                        }catch(e){}
                                    });
                                    $staff.off('select2:clear.sijilstaff').on('select2:clear.sijilstaff', function(){
                                        try{
                                            if (memberNameEl) memberNameEl.value = '';
                                            if (memberRefIdEl) memberRefIdEl.value = '';
                                            var memberEmailEl = document.getElementById('memberEmail');
                                            var memberPhoneEl = document.getElementById('memberPhone');
                                            if (memberEmailEl) memberEmailEl.value = '';
                                            if (memberPhoneEl) memberPhoneEl.value = '';
                                            updateAddMemberSaveState();
                                        }catch(e){}
                                    });
                                    });
                                }catch(e){ console.warn('staff select2 init failed', e); }
                            }

                            function initStudentSelect2(){
                                try{
                                    if (!memberStudentSelectEl) return;
                                    ensureSelect2Loaded(function(isLoaded){
                                        if (!isLoaded) {
                                            fetch(window.location.pathname + '?ajax=student_lookup&limit=100', { credentials: 'same-origin' })
                                                .then(function(res){ return res.json(); })
                                                .then(function(data){
                                                    if (!data || !data.ok) {
                                                        if (addMemberStatus) {
                                                            addMemberStatus.style.display = 'block';
                                                            addMemberStatus.textContent = (data && data.error) ? data.error : 'Unable to load the student list.';
                                                        }
                                                        return;
                                                    }
                                                    memberStudentSelectEl.innerHTML = '<option value="">-- Select Student --</option>';
                                                    (data.results || []).forEach(function(r){
                                                        var opt = document.createElement('option');
                                                        opt.value = r.id || '';
                                                        opt.textContent = r.text || (r.nama || r.id || '');
                                                        opt.dataset.matrik = r.matrik || '';
                                                        opt.dataset.nama = r.nama || '';
                                                        opt.dataset.email = r.email || '';
                                                        opt.dataset.phone = r.phone || '';
                                                        memberStudentSelectEl.appendChild(opt);
                                                    });
                                                    if (addMemberStatus) { addMemberStatus.style.display = 'none'; addMemberStatus.textContent = ''; }
                                                })
                                                .catch(function(err){
                                                    if (addMemberStatus) {
                                                        addMemberStatus.style.display = 'block';
                                                        addMemberStatus.textContent = 'Unable to load students: ' + (err && err.message ? err.message : 'Unknown');
                                                    }
                                                });
                                            memberStudentSelectEl.onchange = function(){
                                                var selected = memberStudentSelectEl.options[memberStudentSelectEl.selectedIndex];
                                                if (!selected || !selected.value) return;
                                                if (memberNameEl) memberNameEl.value = selected.dataset.nama || '';
                                                if (memberRefIdEl) memberRefIdEl.value = selected.dataset.matrik || selected.value || '';
                                                var memberEmailEl = document.getElementById('memberEmail');
                                                var memberPhoneEl = document.getElementById('memberPhone');
                                                if (memberEmailEl) memberEmailEl.value = selected.dataset.email || '';
                                                if (memberPhoneEl) memberPhoneEl.value = selected.dataset.phone || '';
                                                applyDefaultIfEmpty('PELAJAR');
                                                updateAddMemberSaveState();
                                            };
                                            return;
                                        }

                                        var $student = jQuery(memberStudentSelectEl);
                                        if ($student.data('select2')) { $student.select2('destroy'); }
                                        $student.empty();
                                        $student.select2({
                                            theme: 'bootstrap-5',
                                            dropdownParent: addMemberModalEl ? jQuery(addMemberModalEl) : undefined,
                                            width: '100%',
                                            placeholder: memberStudentSelectEl.getAttribute('data-placeholder') || 'Search student names...',
                                            allowClear: true,
                                            minimumInputLength: 0,
                                            ajax: {
                                                delay: 250,
                                                url: window.location.pathname + '?ajax=student_lookup',
                                                dataType: 'json',
                                                data: function(params){ return { q: params.term || '', limit: 100 }; },
                                                processResults: function(data){
                                                    if (!data || !data.ok){
                                                        try{
                                                            if (addMemberStatus){
                                                                addMemberStatus.style.display = 'block';
                                                                addMemberStatus.textContent = (data && data.error) ? data.error : 'Unable to load the student list.';
                                                            }
                                                        }catch(e){}
                                                        return { results: [] };
                                                    }
                                                    try{
                                                        if (addMemberStatus) { addMemberStatus.style.display = 'none'; addMemberStatus.textContent = ''; }
                                                    }catch(e){}
                                                    return { results: data.results || [] };
                                                }
                                            }
                                        });
                                        try{
                                            var $c = $student.next('.select2-container');
                                            if ($c && $c.length) $c.css('width', '100%');
                                        }catch(e){}
                                        $student.off('select2:select.sijilstudent').on('select2:select.sijilstudent', function(ev){
                                            var d = ev && ev.params && ev.params.data ? ev.params.data : null;
                                            if (!d) return;
                                            try{
                                                if (memberNameEl) memberNameEl.value = d.nama || '';
                                                if (memberRefIdEl) memberRefIdEl.value = (d.matrik || d.id || '').toString();
                                                var memberEmailEl = document.getElementById('memberEmail');
                                                var memberPhoneEl = document.getElementById('memberPhone');
                                                if (memberEmailEl) memberEmailEl.value = d.email || '';
                                                if (memberPhoneEl) memberPhoneEl.value = d.phone || '';
                                                applyDefaultIfEmpty('PELAJAR');
                                                setTimeout(updateAddMemberSaveState, 0);
                                            }catch(e){}
                                        });
                                        $student.off('select2:clear.sijilstudent').on('select2:clear.sijilstudent', function(){
                                            try{
                                                if (memberNameEl) memberNameEl.value = '';
                                                if (memberRefIdEl) memberRefIdEl.value = '';
                                                var memberEmailEl = document.getElementById('memberEmail');
                                                var memberPhoneEl = document.getElementById('memberPhone');
                                                if (memberEmailEl) memberEmailEl.value = '';
                                                if (memberPhoneEl) memberPhoneEl.value = '';
                                                updateAddMemberSaveState();
                                            }catch(e){}
                                        });
                                    });
                                }catch(e){ console.warn('student select2 init failed', e); }
                            }

                            function toggleRefInputByType(type){
                                var t = (type || '').toString().toUpperCase();
                                var isStaff = (t === 'STAF');
                                var isStudent = (t === 'PELAJAR' || t === 'STUDENT');
                                var isManual = (t === 'MANUAL');
                                try{
                                    if (memberManualTypeWrapEl) memberManualTypeWrapEl.style.display = isManual ? 'block' : 'none';
                                    if (memberManualTypeEl) memberManualTypeEl.required = isManual;
                                    if (memberRefIdLabelEl) {
                                        if (isStaff) {
                                            memberRefIdLabelEl.innerHTML = 'No Staff <span class=\"text-danger\">*</span>';
                                        } else if (isStudent) {
                                            memberRefIdLabelEl.innerHTML = 'Matriculation Number <span class=\"text-danger\">*</span>';
                                        } else {
                                            memberRefIdLabelEl.innerHTML = 'Staff / Matriculation Number <span class=\"text-danger\">*</span>';
                                        }
                                    }
                                    if (memberStaffWrapEl) memberStaffWrapEl.style.display = isStaff ? 'block' : 'none';
                                    if (memberStaffSelectEl) memberStaffSelectEl.required = isStaff;
                                    if (memberStudentWrapEl) memberStudentWrapEl.style.display = isStudent ? 'block' : 'none';
                                    if (memberStudentSelectEl) memberStudentSelectEl.required = isStudent;
                                    if (memberNameEl) memberNameEl.readOnly = (isStaff || isStudent);
                                    if (memberRefIdEl) memberRefIdEl.readOnly = (isStaff || isStudent);
                                    if (!isStaff) resetStaffSelect();
                                    if (!isStudent) resetStudentSelect();
                                    if (!isStaff && !isStudent && memberNameEl && memberNameEl.value === '') memberNameEl.readOnly = false;
                                    if (!isStaff && !isStudent && memberRefIdEl && memberRefIdEl.value === '') memberRefIdEl.readOnly = false;
                                    if (!isManual && memberManualTypeEl) memberManualTypeEl.value = '';
                                }catch(e){}
                                if (isStaff) {
                                    initStaffSelect2();
                                }
                                if (isStudent) {
                                    initStudentSelect2();
                                }
                            }

                            if (memberRefTypeEl){
                                memberRefTypeEl.addEventListener('change', function(){
                                    var nextType = (memberRefTypeEl.value || '').toUpperCase();
                                    if (nextType === 'STAF' || nextType === 'PELAJAR' || nextType === 'STUDENT'){
                                        try{
                                            if (memberNameEl) memberNameEl.value = '';
                                            if (memberRefIdEl) memberRefIdEl.value = '';
                                            var memberEmailEl = document.getElementById('memberEmail');
                                            var memberPhoneEl = document.getElementById('memberPhone');
                                            if (memberEmailEl) memberEmailEl.value = '';
                                            if (memberPhoneEl) memberPhoneEl.value = '';
                                            resetStaffSelect();
                                            resetStudentSelect();
                                        }catch(e){}
                                    } else if (nextType === 'MANUAL'){
                                        try{
                                            if (memberNameEl) memberNameEl.value = '';
                                            if (memberRefIdEl) memberRefIdEl.value = '';
                                            var memberEmailEl2 = document.getElementById('memberEmail');
                                            var memberPhoneEl2 = document.getElementById('memberPhone');
                                            if (memberEmailEl2) memberEmailEl2.value = '';
                                            if (memberPhoneEl2) memberPhoneEl2.value = '';
                                            resetStaffSelect();
                                            resetStudentSelect();
                                        }catch(e){}
                                    }
                                    toggleRefInputByType(nextType);
                                    updateAddMemberSaveState();
                                });
                            }
                            if (memberManualTypeEl) memberManualTypeEl.addEventListener('change', function(){ updateAddMemberSaveState(); });
                            try{
                                ['memberRoleId','memberName','memberRefId','memberEmail','memberPhone'].forEach(function(id){
                                    var el = document.getElementById(id);
                                    if (el) {
                                        el.addEventListener('input', updateAddMemberSaveState);
                                        el.addEventListener('change', updateAddMemberSaveState);
                                    }
                                });
                            }catch(e){}

                            if (btnAddMember){
                                btnAddMember.addEventListener('click', function(){
                                    // reset form
                                    try{ document.getElementById('addMemberForm').reset(); }catch(e){}
                                    try{ resetAddMemberModalState(); }catch(e){}
                                    try{ resetStaffSelect(); }catch(e){}
                                    try{ toggleRefInputByType((memberRefTypeEl && memberRefTypeEl.value) ? memberRefTypeEl.value : ''); }catch(e){}
                                    if (addMemberStatus) { addMemberStatus.style.display = 'none'; addMemberStatus.textContent = ''; }
                                    updateAddMemberSaveState();
                                    loadRolesIfNeeded().then(function(){
                                        try{ initRoleSelect2(); }catch(e){}
                                        if (addMemberModalEl) {
                                            showAddMemberModal();
                                            // create a light backdrop element under the modal
                                            try{
                                                if (!document.getElementById('addMemberLightBackdrop')){
                                                    var lb = document.createElement('div');
                                                    lb.id = 'addMemberLightBackdrop';
                                                    lb.style.position = 'fixed';
                                                    lb.style.left = '0'; lb.style.top = '0'; lb.style.right = '0'; lb.style.bottom = '0';
                                                    lb.style.background = 'rgba(0,0,0,0.06)';
                                                    lb.style.zIndex = '1040';
                                                    document.body.appendChild(lb);
                                                }
                                            }catch(ignore){}
                                        }
                                    }).catch(function(err){ alert('Unable to load the committee list: ' + err.message); });
                                });
                            }

                            // Ensure modal state is re-initialized when shown and cleaned when hidden
                            try{
                                    if (addMemberModalEl) {
                                    addMemberModalEl.addEventListener('shown.bs.modal', function(){
                                        try{
                                            var t = (addMemberModalEl && addMemberModalEl.dataset && addMemberModalEl.dataset.forceType)
                                                ? String(addMemberModalEl.dataset.forceType).toUpperCase()
                                                : (memberRefTypeEl && memberRefTypeEl.value ? memberRefTypeEl.value : '').toUpperCase();
                                            // Hard-force wrapper visibility here to avoid stale hidden state.
                                            try{
                                                if (memberStaffWrapEl) memberStaffWrapEl.style.setProperty('display', (t === 'STAF') ? 'block' : 'none', 'important');
                                                if (memberStudentWrapEl) memberStudentWrapEl.style.setProperty('display', (t === 'PELAJAR' || t === 'STUDENT') ? 'block' : 'none', 'important');
                                                if (memberManualTypeWrapEl) memberManualTypeWrapEl.style.setProperty('display', (t === 'MANUAL') ? 'block' : 'none', 'important');
                                            }catch(e){}
                                            try{ forceEditTypeUI(t); }catch(e){}
                                            if (t === 'STAF') { try{ initStaffSelect2(); }catch(e){} }
                                            if (t === 'PELAJAR' || t === 'STUDENT') { try{ initStudentSelect2(); }catch(e){} }
                                            updateAddMemberSaveState();
                                        }catch(e){}
                                    });
                                    addMemberModalEl.addEventListener('hidden.bs.modal', function(){
                                        try{
                                            try{ if (addMemberModalEl && addMemberModalEl.dataset) delete addMemberModalEl.dataset.forceType; }catch(e){}
                                            document.body.classList.remove('modal-open');
                                            var backs = document.querySelectorAll('.modal-backdrop');
                                            backs.forEach(function(b){ if (b && b.parentNode) b.parentNode.removeChild(b); });
                                            var lb = document.getElementById('addMemberLightBackdrop');
                                            if (lb && lb.parentNode) lb.parentNode.removeChild(lb);
                                            // reset edit state
                                            try{ resetAddMemberModalState(); }catch(e){}
                                            try{ resetStaffSelect(); }catch(e){}
                                            try{ toggleRefInputByType(''); }catch(e){}
                                            updateAddMemberSaveState();
                                        }catch(e){}
                                    });
                                }
                            }catch(ex){ console.error('modal cleanup listener error', ex); }

                            if (addMemberSave){
                                addMemberSave.addEventListener('click', function(){
                                    var form = document.getElementById('addMemberForm');
                                    var name = (document.getElementById('memberName')||{value:''}).value.trim();
                                    var roleId = (document.getElementById('memberRoleId')||{value:''}).value;
                                    var refType = (document.getElementById('memberRefType')||{value:''}).value;
                                    var manualType = (document.getElementById('memberManualType')||{value:''}).value;
                                    var refId = (document.getElementById('memberRefId')||{value:''}).value.trim();
                                    var email = (document.getElementById('memberEmail')||{value:''}).value.trim();
                                    var phone = (document.getElementById('memberPhone')||{value:''}).value.trim();
                                    var validated = validateMemberForm(true);
                                    if (!validated.ok) return;
                                    var refTypeToSave = validated.typeToSave || normalizeRefTypeForSave();
                                    // re-read values after auto-default applied
                                    name = (document.getElementById('memberName')||{value:''}).value.trim();
                                    refId = (document.getElementById('memberRefId')||{value:''}).value.trim();
                                    email = (document.getElementById('memberEmail')||{value:''}).value.trim();
                                    phone = (document.getElementById('memberPhone')||{value:''}).value.trim();

                                    var fd = new URLSearchParams();
                                    fd.append('member_name', name);
                                    fd.append('role_id', roleId);
                                    fd.append('member_ref_type', refTypeToSave);
                                    fd.append('member_entry_mode', (String(refType || '').toUpperCase() === 'MANUAL') ? 'MANUAL' : 'BARU');
                                    fd.append('member_ref_id', refId);
                                    fd.append('member_email', email);
                                    fd.append('member_phone', phone);

                                    addMemberSave.disabled = true;

                                    function showSweetSuccess(msg){
                                        msg = msg || 'Record saved successfully.';
                                        var showFn = function(){
                                            // require user to click OK (no auto timer)
                                            return Swal.fire({ icon: 'success', title: 'Success', text: msg, showConfirmButton: true, confirmButtonText: 'OK', position: 'center' });
                                        };
                                        if (window.Swal){
                                            return showFn();
                                        } else {
                                            var s = document.createElement('script');
                                            s.src = 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js';
                                            s.onload = function(){ try{ showFn(); }catch(e){ alert(msg); } };
                                            document.head.appendChild(s);
                                            var l = document.createElement('link'); l.rel = 'stylesheet'; l.href = 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css'; document.head.appendChild(l);
                                        }
                                    }

                                    // decide add vs update: prefer explicit editing flag on save button, fallback to hidden memberId
                                    var memberIdEl = document.getElementById('memberId');
                                    var memberIdVal = memberIdEl ? memberIdEl.value : '';
                                    var editingFlag = (addMemberSave && addMemberSave.dataset && addMemberSave.dataset.editing === '1');
                                    var isEditing = editingFlag || (memberIdVal && parseInt(memberIdVal,10) > 0);
                                    var endpoint = isEditing ? '?ajax=update_member' : '?ajax=add_member';
                                    try{ if (addMemberSave) addMemberSave.textContent = isEditing ? 'Update' : 'Save'; }catch(e){}
                                    if (isEditing) {
                                        // ensure member_id is sent; prefer hidden input but send fallback value
                                        var midToSend = (memberIdVal && parseInt(memberIdVal,10) > 0) ? memberIdVal : ((addMemberSave && addMemberSave.dataset && addMemberSave.dataset.memberId) ? addMemberSave.dataset.memberId : '');
                                        if (midToSend) fd.append('member_id', midToSend);
                                    }
                                    try{ console.debug('Submitting member form', { memberId: memberIdVal, endpoint: endpoint, form: Array.from(fd.entries()) }); }catch(e){}
                                    fetch(window.location.pathname + endpoint, { method: 'POST', body: fd, credentials: 'same-origin' })
                                    .then(function(res){ if (!res.ok) throw new Error('HTTP ' + res.status); return res.text(); })
                                    .then(function(txt){
                                        var j = null;
                                        try { j = JSON.parse(txt); } catch(e){ throw new Error('Invalid server response'); }
                                        if (j && j.ok){
                                            if (addMemberModalEl) hideAddMemberModal();
                                            try{
                                                var typeLabel = (refTypeToSave === 'STAF') ? 'Staff' : ((refTypeToSave === 'PELAJAR') ? 'Student' : 'Manual Entry');
                                                var idLabel = refId ? ('(' + refId + ')') : '';
                                                var nameLabel = name ? (' - ' + name) : '';
                                                showSweetSuccess('Record ' + typeLabel + ' ' + idLabel + nameLabel + ' saved successfully.');
                                            }catch(e){ alert('Record saved successfully.'); }
                                            try{
                                                if (committeeLoaded && btnLoadCommittee) {
                                                    preserveCommitteePage = currentCommitteePage;
                                                    btnLoadCommittee.click();
                                                }
                                            }catch(e){}
                                            try{
                                                if (volunteerLoaded && btnLoadVolunteer) {
                                                    preserveVolunteerPage = currentVolunteerPage;
                                                    btnLoadVolunteer.click();
                                                }
                                            }catch(e){}
                                        } else {
                                            if (j && j.exists){
                                                var existsMsg = (j && j.error) ? j.error : 'A record already exists for this committee member.';
                                                if (addMemberStatus){ addMemberStatus.style.display='block'; addMemberStatus.textContent = existsMsg; }
                                                else { try{ if (window.Swal) Swal.fire({ icon: 'warning', title: 'Wujud', text: existsMsg }); else alert(existsMsg); }catch(e){ alert(existsMsg); } }
                                            } else {
                                                if (addMemberStatus){ addMemberStatus.style.display='block'; addMemberStatus.textContent = (j && j.error) ? j.error : 'Unable to save the record.'; }
                                                else { try{ if (window.Swal) Swal.fire({ icon: 'error', title: 'Failed', text: (j && j.error) ? j.error : 'Unable to save the record.' }); else alert((j && j.error) ? j.error : 'Unable to save the record.'); }catch(e){ alert((j && j.error) ? j.error : 'Unable to save the record.'); } }
                                            }
                                        }
                                    })
                                    .catch(function(err){
                                        if (addMemberStatus){ addMemberStatus.style.display='block'; addMemberStatus.textContent = err.message; }
                                        else { try{ if (window.Swal) Swal.fire({ icon: 'error', title: 'Error', text: err.message }); else alert('Error: ' + err.message); }catch(e){ alert('Error: ' + err.message); } }
                                    })
                                        .finally(function(){ addMemberSave.disabled = false; });
                                    });
                                }
                            }catch(e){ console.error('addMember wiring error', e); }
                            try{ document.getElementById('athletePageInfo').textContent = 'Page ' + currentAthletePage + '/' + pages; }catch(e){}
                            try{ document.getElementById('athletePrev').disabled = currentAthletePage <= 1; }catch(e){}
                            try{ document.getElementById('athleteNext').disabled = currentAthletePage >= pages; }catch(e){}
                            if (countAtlet) countAtlet.textContent = total;
                            if (printAllAtlet) printAllAtlet.disabled = total === 0;
                        }

                        function renderPenyelarasPage(){
                            var rawList = lastRows.penyelaras || [];
                            var q = searchPenyelaras ? searchPenyelaras.value : '';
                            var list = rawList.filter(function(r){
                                return matchSearch([r.nama, r.emel, r.telefon], q);
                            });
                            var total = list.length;
                            var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
                            if (currentPenyelarasPage > pages) currentPenyelarasPage = pages;
                            var start = (currentPenyelarasPage - 1) * PAGE_SIZE;
                            var slice = list.slice(start, start + PAGE_SIZE);
                            penyelarasBody.innerHTML = '';
                            if (!slice.length) {
                                appendNoDataRow(penyelarasBody, 5);
                            }
                            slice.forEach(function(r, idx){
                                var nIdx = start + idx + 1;
                                var tr = document.createElement('tr');
                                var nameSafe = (r.nama || '').replace(/</g,'&lt;');
                                var emailSafe = (r.emel || '').replace(/</g,'&lt;');
                                var telSafe = (r.telefon || '').replace(/</g,'&lt;');
                                tr.innerHTML = '<td class="text-center">'+nIdx+'</td>'+
                                    (function(){ return cellHtml(nameSafe, false); })() +
                                    (function(){ return cellHtml(emailSafe, false); })() +
                                    (function(){ return cellHtml(telSafe, true); })() +
                                    '<td class="text-center">'
                                    + '<button type="button" class="btn btn-sm btn-outline-primary icon-action-btn me-1 do-print-penyelaras" title="Print" aria-label="Print"><span class="icon-glyph">🖨️</span></button>'
                                    + '<button type="button" class="btn btn-sm btn-outline-info icon-action-btn do-email-penyelaras" title="Send Email" aria-label="Send Email"><span class="icon-glyph">📧</span></button>'
                                    + '</td>';
                                penyelarasBody.appendChild(tr);
                                var btn = tr.querySelector('.do-print-penyelaras');
                                if (btn) btn.addEventListener('click', function(){ printDirectHtml(buildCertHtml(r.nama || '', englishLabel('KETUA KONTINJEN'), <?php echo json_encode(url('assets/img/sijil/sijil_penyelaras.jpeg')); ?>)); });
                                var emBtn = tr.querySelector('.do-email-penyelaras');
                                if (emBtn) emBtn.addEventListener('click', function(){
                                    sendCertificateEmail({
                                        cert_type: 'penyelaras',
                                        recipient_name: r.nama || '',
                                        recipient_email: r.emel || '',
                                        role_text: 'KETUA KONTINJEN'
                                    });
                                });
                            });
                            try{ document.getElementById('penyelarasPageInfo').textContent = 'Page ' + currentPenyelarasPage + '/' + pages; }catch(e){}
                            try{ document.getElementById('penyelarasPrev').disabled = currentPenyelarasPage <= 1; }catch(e){}
                            try{ document.getElementById('penyelarasNext').disabled = currentPenyelarasPage >= pages; }catch(e){}
                            if (countPenyelaras) countPenyelaras.textContent = total;
                            if (printAllPenyelaras) printAllPenyelaras.disabled = total === 0;
                        }

                        function normalizeStoredRefType(v){
                            var t = String(v || '').trim().toUpperCase();
                            if (t === 'STUDENT') t = 'PELAJAR';
                            if (t !== 'STAF' && t !== 'PELAJAR' && t !== 'MANUAL') t = 'MANUAL';
                            return t;
                        }

                        function forceEditTypeUI(type){
                            var t = String(type || '').toUpperCase();
                            try{
                                if (memberRefTypeEl){
                                    memberRefTypeEl.value = t;
                                    memberRefTypeEl.disabled = (t === 'STAF' || t === 'PELAJAR');
                                }
                                if (memberStaffWrapEl) {
                                    memberStaffWrapEl.hidden = false;
                                    memberStaffWrapEl.classList.remove('d-none');
                                    memberStaffWrapEl.classList.remove('force-show');
                                    if (t === 'STAF') memberStaffWrapEl.classList.add('force-show');
                                    memberStaffWrapEl.style.setProperty('display', (t === 'STAF') ? 'block' : 'none', 'important');
                                }
                                if (memberStudentWrapEl) {
                                    memberStudentWrapEl.hidden = false;
                                    memberStudentWrapEl.classList.remove('d-none');
                                    memberStudentWrapEl.classList.remove('force-show');
                                    if (t === 'PELAJAR') memberStudentWrapEl.classList.add('force-show');
                                    memberStudentWrapEl.style.setProperty('display', (t === 'PELAJAR') ? 'block' : 'none', 'important');
                                }
                                if (memberManualTypeWrapEl) {
                                    memberManualTypeWrapEl.hidden = false;
                                    memberManualTypeWrapEl.classList.remove('d-none');
                                    memberManualTypeWrapEl.classList.remove('force-show');
                                    if (t === 'MANUAL') memberManualTypeWrapEl.classList.add('force-show');
                                    memberManualTypeWrapEl.style.setProperty('display', (t === 'MANUAL') ? 'block' : 'none', 'important');
                                }
                                if (memberStaffSelectEl) memberStaffSelectEl.required = (t === 'STAF');
                                if (memberStudentSelectEl) memberStudentSelectEl.required = (t === 'PELAJAR');
                                if (memberManualTypeEl) memberManualTypeEl.required = (t === 'MANUAL');
                                if (memberNameEl) memberNameEl.readOnly = (t === 'STAF' || t === 'PELAJAR');
                                if (memberRefIdEl) memberRefIdEl.readOnly = (t === 'STAF' || t === 'PELAJAR');
                            }catch(e){}
                        }

                        function openEditMemberModal(r, forcedType){
                            try{
                                if (!r) return;
                                var type = normalizeStoredRefType(forcedType || r.member_ref_type || '');
                                var desiredRole = r.role_id || '';
                                var inferManualType = (function(){
                                    var ref = String(r.member_ref_id || '').trim();
                                    if (ref === '') return '';
                                    return /-/.test(ref) ? 'STAF' : 'PELAJAR';
                                })();

                                try{ document.getElementById('addMemberForm').reset(); }catch(e){}
                                try{ resetStaffSelect(); }catch(e){}
                                try{ resetStudentSelect(); }catch(e){}
                                try{ resetAddMemberModalState(); }catch(e){}
                                try{ if (addMemberStatus) { addMemberStatus.style.display = 'none'; addMemberStatus.textContent = ''; } }catch(e){}

                                try{ var midEl = document.getElementById('memberId'); if (midEl) midEl.value = r.id || ''; }catch(e){}
                                try{ if (addMemberModalEl) addMemberModalEl.dataset.forceType = type; }catch(e){}
                                try{ if (addMemberSave) { addMemberSave.textContent = 'Update'; addMemberSave.dataset.editing = '1'; addMemberSave.dataset.memberId = String(r.id || ''); } }catch(e){}
                                try{ var lbl = document.getElementById('addMemberModalLabel'); if (lbl) lbl.textContent = 'Update Certificate Recipient'; }catch(e){}

                                try{
                                    var rt = document.getElementById('memberRefType');
                                    if (rt) rt.value = type;
                                    if (rt) rt.disabled = (type === 'STAF' || type === 'PELAJAR');
                                }catch(e){}
                                try{
                                    var mt = document.getElementById('memberManualType');
                                    if (mt) mt.value = '';
                                }catch(e){}

                                try{ toggleRefInputByType(type); }catch(e){}
                                try{ forceEditTypeUI(type); }catch(e){}

                                try{ var mn = document.getElementById('memberName'); if (mn) mn.value = r.member_name || ''; }catch(e){}
                                try{ var mr = document.getElementById('memberRefId'); if (mr) mr.value = r.member_ref_id || ''; }catch(e){}
                                try{ var me = document.getElementById('memberEmail'); if (me) me.value = r.member_email || ''; }catch(e){}
                                try{ var mp = document.getElementById('memberPhone'); if (mp) mp.value = r.member_phone || ''; }catch(e){}

                                if (type === 'MANUAL'){
                                    try{
                                        var mm = document.getElementById('memberManualType');
                                        var inferred = String(r.member_manual_type || '').toUpperCase();
                                        if (!inferred) inferred = inferManualType;
                                        if (mm && (inferred === 'STAF' || inferred === 'PELAJAR')) mm.value = inferred;
                                    }catch(e){}
                                }

                                loadRolesIfNeeded().then(function(){
                                    try{ initRoleSelect2(); }catch(e){}
                                    try{
                                        var roleEl = document.getElementById('memberRoleId');
                                        if (roleEl){
                                            roleEl.value = desiredRole || '';
                                            if (window.jQuery && typeof jQuery.fn.select2 === 'function'){
                                                jQuery(roleEl).val(desiredRole || '').trigger('change');
                                            }
                                        }
                                    }catch(e){}
                                }).catch(function(err){
                                    console.warn('loadRolesIfNeeded failed for edit', err);
                                }).finally(function(){
                                    try{
                                        if (memberStaffWrapEl) memberStaffWrapEl.style.setProperty('display', (type === 'STAF') ? 'block' : 'none', 'important');
                                        if (memberStudentWrapEl) memberStudentWrapEl.style.setProperty('display', (type === 'PELAJAR') ? 'block' : 'none', 'important');
                                        if (memberManualTypeWrapEl) memberManualTypeWrapEl.style.setProperty('display', (type === 'MANUAL') ? 'block' : 'none', 'important');
                                    }catch(e){}
                                    try{ showAddMemberModal(); }catch(e){ console.error('showAddMemberModal failed', e); }
                                    // Select2 for STAF/PELAJAR is safer to initialize after modal is visible
                                    setTimeout(function(){
                                        try{ forceEditTypeUI(type); }catch(e){}
                                        try{
                                            if (type === 'STAF' && memberStaffSelectEl){
                                                try{ initStaffSelect2(); }catch(e){}
                                                setTimeout(function(){
                                                    try{ forceEditTypeUI(type); }catch(e){}
                                                    try{
                                                        var optText = (r.member_name || '') + (r.member_ref_id ? ' (' + r.member_ref_id + ')' : '');
                                                        if (window.jQuery && typeof jQuery.fn.select2 === 'function'){
                                                            var $staff = jQuery(memberStaffSelectEl);
                                                            $staff.empty();
                                                            var opt = new Option(optText, r.member_ref_id || '', true, true);
                                                            $staff.append(opt).trigger('change');
                                                        } else {
                                                            memberStaffSelectEl.innerHTML = '';
                                                            var nopt = document.createElement('option');
                                                            nopt.value = r.member_ref_id || '';
                                                            nopt.textContent = optText;
                                                            nopt.selected = true;
                                                            memberStaffSelectEl.appendChild(nopt);
                                                            memberStaffSelectEl.dispatchEvent(new Event('change', { bubbles: true }));
                                                        }
                                                    }catch(e){}
                                                }, 260);
                                            }
                                            if (type === 'PELAJAR' && memberStudentSelectEl){
                                                try{ initStudentSelect2(); }catch(e){}
                                                setTimeout(function(){
                                                    try{ forceEditTypeUI(type); }catch(e){}
                                                    try{
                                                        var optTextS = (r.member_name || '') + (r.member_ref_id ? ' (' + r.member_ref_id + ')' : '');
                                                        if (window.jQuery && typeof jQuery.fn.select2 === 'function'){
                                                            var $student = jQuery(memberStudentSelectEl);
                                                            $student.empty();
                                                            var optS = new Option(optTextS, r.member_ref_id || '', true, true);
                                                            $student.append(optS).trigger('change');
                                                        } else {
                                                            memberStudentSelectEl.innerHTML = '';
                                                            var noptS = document.createElement('option');
                                                            noptS.value = r.member_ref_id || '';
                                                            noptS.textContent = optTextS;
                                                            noptS.selected = true;
                                                            memberStudentSelectEl.appendChild(noptS);
                                                            memberStudentSelectEl.dispatchEvent(new Event('change', { bubbles: true }));
                                                        }
                                                    }catch(e){}
                                                }, 260);
                                            }
                                        }catch(e){}
                                        try{ updateAddMemberSaveState(); }catch(e){}
                                    }, 120);
                                    try{ updateAddMemberSaveState(); }catch(e){}
                                });
                            }catch(ex){
                                console.error('openEditMemberModal error', ex);
                            }
                        }

                        // Committee render (single table)
                        var currentCommitteePage = 1;
                        var preserveCommitteePage = null;
                        function renderCommitteePage(){
                            var rawList = lastRows.committee || [];
                            var dupRefMap = Object.create(null);
                            rawList.forEach(function(r){
                                var k = normalizeRefKey((r && r.member_ref_id) ? r.member_ref_id : '');
                                if (!k) return;
                                dupRefMap[k] = (dupRefMap[k] || 0) + 1;
                            });
                            var q = searchCommittee ? searchCommittee.value : '';
                            var list = rawList.filter(function(r){
                                return matchSearch([r.member_name, r.role_name, r.member_email, r.member_ref_id], q);
                            });
                            var total = list.length;
                            var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
                            if (currentCommitteePage > pages) currentCommitteePage = pages;
                            var start = (currentCommitteePage - 1) * PAGE_SIZE;
                            var slice = list.slice(start, start + PAGE_SIZE);
                            if (!committeeBody) return;
                            committeeBody.innerHTML = '';
                            if (!slice.length) {
                                appendNoDataRow(committeeBody, 5);
                            }
                            slice.forEach(function(r, idx){
                                var nIdx = start + idx + 1;
                                var tr = document.createElement('tr');
                                var refKey = normalizeRefKey((r && r.member_ref_id) ? r.member_ref_id : '');
                                var missingName = String((r && r.name_not_found != null) ? r.name_not_found : '0') === '1';
                                if (missingName) {
                                    tr.classList.add('table-danger');
                                } else if (refKey && (dupRefMap[refKey] || 0) > 1) {
                                    tr.classList.add('table-warning');
                                }
                                var name = (r.member_name||'').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                var role = (r.role_name||'').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                var email = (r.member_email||'').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                var emailCount = parseInt(r.email_send_count || 0, 10) || 0;
                                var lastSentAt = (r.email_last_sent_at || '').toString().trim();
                                var lastSentTo = (r.email_last_sent_to || '').toString().trim();
                                var showLink = (!missingName && refKey && (dupRefMap[refKey] || 0) > 1);
                                var nameHtml = name;
                                if (showLink) {
                                    nameHtml += ' <a href="#" class="small dup-view-link" data-scope="committee" data-ref="' + refKey.replace(/"/g, '&quot;') + '" title="Show butiran" aria-label="Show butiran"><i class="fa-solid fa-circle-info"></i></a>';
                                }
                                if (emailCount > 0) {
                                    var sentTitle = 'Certificate sent';
                                    if (lastSentAt) sentTitle += ' on ' + formatBadgeDateTime(lastSentAt);
                                    if (lastSentTo) sentTitle += ' to ' + lastSentTo;
                                    nameHtml += ' <span class="email-sent-badge" title="' + sentTitle.replace(/"/g,'&quot;') + '"><i class="fa-solid fa-circle-check me-1"></i>Email Certificate</span>';
                                }
                                tr.innerHTML = '<td class="text-center">'+nIdx+'</td>'+
                                    (function(){ return cellHtml(nameHtml, false, true); })() +
                                    (function(){ return cellHtml(role, false); })() +
                                    (function(){ return cellHtml(email, false); })() +
                                    '<td class="text-center">'
                                    + '<button type="button" class="btn btn-sm btn-outline-secondary me-1 icon-action-btn do-edit-committee" title="Edit"><span class="icon-glyph">✏️</span></button>'
                                    + '<button type="button" class="btn btn-sm btn-outline-danger me-1 icon-action-btn do-delete-committee" title="Delete"><span class="icon-glyph">🗑️</span></button>'
                                    + '<button type="button" class="btn btn-sm btn-outline-primary me-1 icon-action-btn do-print-committee" title="Print" aria-label="Print"><span class="icon-glyph">🖨️</span></button>'
                                    + '<button type="button" class="btn btn-sm btn-outline-info icon-action-btn do-email-committee" title="Send Email" aria-label="Send Email"><span class="icon-glyph">📧</span></button>'
                                    + '</td>';
                                committeeBody.appendChild(tr);
                                // action buttons: edit, delete, print
                                var editBtn = tr.querySelector('.do-edit-committee');
                                var delBtn = tr.querySelector('.do-delete-committee');
                                var prBtn = tr.querySelector('.do-print-committee');
                                var emBtn = tr.querySelector('.do-email-committee');
                                var viewBtn = tr.querySelector('.dup-view-link');
                                if (viewBtn) viewBtn.addEventListener('click', function(ev){
                                    ev.preventDefault();
                                    openDuplicateDetailModal('committee', refKey);
                                });
                                if (editBtn) editBtn.addEventListener('click', function(){
                                    openEditMemberModal(r, 'STAF');
                                });
                                if (delBtn) delBtn.addEventListener('click', function(){
                                    try{
                                        var sid = r.id;
                                        if (!sid) return;
                                        var title = 'Delete this record?';
                                        var text = 'The record will be removed (hidden). Continue?';
                                        var callDelete = function(){
                                            var urlDel = window.location.pathname + '?ajax=delete_member';
                                            fetch(urlDel, { method: 'POST', body: new URLSearchParams({ id: sid }), credentials: 'same-origin' })
                                            .then(function(res){ if(!res.ok) throw new Error('HTTP ' + res.status); return res.text(); })
                                            .then(function(t){
                                                try{
                                                    var j = JSON.parse(t);
                                                }catch(parseErr){
                                                    alert('Unable to delete the record. (Invalid server response)');
                                                    return;
                                                }
                                                if (j && j.ok){
                                                    try{
                                                        var detail = (r.member_name ? r.member_name : '') + (r.member_ref_id ? ' ('+r.member_ref_id+')' : '');
                                                        if (window.Swal) { Swal.fire({ icon: 'success', title: 'Dipadam', text: 'Record ' + detail + ' has been deleted.' }); }
                                                        else { alert('Record ' + detail + ' has been deleted.'); }
                                                    }catch(e){ }
                                                    try{
                                                        if (btnLoadCommittee) {
                                                            preserveCommitteePage = currentCommitteePage;
                                                            btnLoadCommittee.click();
                                                        }
                                                    }catch(e){}
                                                } else {
                                                    alert('Unable to delete the record.');
                                                }
                                            })
                                            .catch(function(err){ alert('Error padam: ' + (err && err.message ? err.message : 'Unknown')); });
                                        };
                                        if (window.Swal){
                                            Swal.fire({ title: title, text: text, icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', cancelButtonText: 'Cancel' }).then(function(res){ if (res && res.isConfirmed) {
                                                callDelete();
                                                // show success after deletion inside callDelete's then
                                            } });
                                        } else if (confirm(text)) callDelete();
                                    }catch(ex){ console.error('delete error', ex); }
                                });
                                if (prBtn) prBtn.addEventListener('click', function(){ printDirectHtml(buildCertHtml(r.member_name || '', r.role_name || '', <?php echo json_encode(url('assets/img/sijil/sijil_penyelaras.jpeg')); ?>, { nudgeIfLong: true })); });
                                if (emBtn) emBtn.addEventListener('click', function(){
                                    sendCertificateEmail({
                                        cert_type: 'committee',
                                        member_id: r.id || '',
                                        recipient_name: r.member_name || '',
                                        recipient_email: r.member_email || '',
                                        role_text: r.role_name || ''
                                    });
                                });
                            });
                            try{ if (committeePageInfo) committeePageInfo.textContent = 'Page ' + currentCommitteePage + '/' + pages; }catch(e){}
                            try{ if (committeePrev) committeePrev.disabled = currentCommitteePage <= 1; }catch(e){}
                            try{ if (committeeNext) committeeNext.disabled = currentCommitteePage >= pages; }catch(e){}
                            if (printAllCommittee) printAllCommittee.disabled = total === 0;
                        }

                        // Volunteer render (single table) - same as committee but separate storage
                        var currentVolunteerPage = 1;
                        var preserveVolunteerPage = null;
                        function renderVolunteerPage(){
                            var rawList = lastRows.volunteer || [];
                            var dupRefMap = Object.create(null);
                            rawList.forEach(function(r){
                                var k = normalizeRefKey((r && r.member_ref_id) ? r.member_ref_id : '');
                                if (!k) return;
                                dupRefMap[k] = (dupRefMap[k] || 0) + 1;
                            });
                            var q = searchVolunteer ? searchVolunteer.value : '';
                            var list = rawList.filter(function(r){
                                return matchSearch([r.member_name, r.role_name, r.member_email, r.member_ref_id], q);
                            });
                            var total = list.length;
                            var pages = Math.max(1, Math.ceil(total / PAGE_SIZE));
                            if (currentVolunteerPage > pages) currentVolunteerPage = pages;
                            var start = (currentVolunteerPage - 1) * PAGE_SIZE;
                            var slice = list.slice(start, start + PAGE_SIZE);
                            if (!volunteerBody) return;
                            volunteerBody.innerHTML = '';
                            if (!slice.length) {
                                appendNoDataRow(volunteerBody, 5);
                            }
                            slice.forEach(function(r, idx){
                                var nIdx = start + idx + 1;
                                var tr = document.createElement('tr');
                                var refKey = normalizeRefKey((r && r.member_ref_id) ? r.member_ref_id : '');
                                var missingName = String((r && r.name_not_found != null) ? r.name_not_found : '0') === '1';
                                if (missingName) {
                                    tr.classList.add('table-danger');
                                } else if (refKey && (dupRefMap[refKey] || 0) > 1) {
                                    tr.classList.add('table-warning');
                                }
                                var name = (r.member_name||'').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                var role = (r.role_name||'').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                var email = (r.member_email||'').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                                var emailCount = parseInt(r.email_send_count || 0, 10) || 0;
                                var lastSentAt = (r.email_last_sent_at || '').toString().trim();
                                var lastSentTo = (r.email_last_sent_to || '').toString().trim();
                                var showLink = (!missingName && refKey && (dupRefMap[refKey] || 0) > 1);
                                var nameHtml = name;
                                if (showLink) {
                                    nameHtml += ' <a href="#" class="small dup-view-link" data-scope="volunteer" data-ref="' + refKey.replace(/"/g, '&quot;') + '" title="Show butiran" aria-label="Show butiran"><i class="fa-solid fa-circle-info"></i></a>';
                                }
                                if (emailCount > 0) {
                                    var sentTitle = 'Certificate sent';
                                    if (lastSentAt) sentTitle += ' on ' + formatBadgeDateTime(lastSentAt);
                                    if (lastSentTo) sentTitle += ' to ' + lastSentTo;
                                    nameHtml += ' <span class="email-sent-badge" title="' + sentTitle.replace(/"/g,'&quot;') + '"><i class="fa-solid fa-circle-check me-1"></i>Email Certificate</span>';
                                }
                                tr.innerHTML = '<td class="text-center">'+nIdx+'</td>'+
                                    (function(){ return cellHtml(nameHtml, false, true); })() +
                                    (function(){ return cellHtml(role, false); })() +
                                    (function(){ return cellHtml(email, false); })() +
                                    '<td class="text-center">'
                                    + '<button type="button" class="btn btn-sm btn-outline-secondary me-1 icon-action-btn do-edit-volunteer" title="Edit"><span class="icon-glyph">✏️</span></button>'
                                    + '<button type="button" class="btn btn-sm btn-outline-danger me-1 icon-action-btn do-delete-volunteer" title="Delete"><span class="icon-glyph">🗑️</span></button>'
                                    + '<button type="button" class="btn btn-sm btn-outline-primary me-1 icon-action-btn do-print-volunteer" title="Print" aria-label="Print"><span class="icon-glyph">🖨️</span></button>'
                                    + '<button type="button" class="btn btn-sm btn-outline-info icon-action-btn do-email-volunteer" title="Send Email" aria-label="Send Email"><span class="icon-glyph">📧</span></button>'
                                    + '</td>';
                                volunteerBody.appendChild(tr);
                                var editBtn = tr.querySelector('.do-edit-volunteer');
                                var delBtn = tr.querySelector('.do-delete-volunteer');
                                var prBtn = tr.querySelector('.do-print-volunteer');
                                var emBtn = tr.querySelector('.do-email-volunteer');
                                var viewBtn = tr.querySelector('.dup-view-link');
                                if (viewBtn) viewBtn.addEventListener('click', function(ev){
                                    ev.preventDefault();
                                    openDuplicateDetailModal('volunteer', refKey);
                                });
                                if (editBtn) editBtn.addEventListener('click', function(){
                                    openEditMemberModal(r, 'PELAJAR');
                                });
                                if (delBtn) delBtn.addEventListener('click', function(){
                                    try{
                                        var sid = r.id;
                                        if (!sid) return;
                                        var title = 'Delete this record?';
                                        var text = 'The record will be removed (hidden). Continue?';
                                        var callDelete = function(){
                                            var urlDel = window.location.pathname + '?ajax=delete_member';
                                            fetch(urlDel, { method: 'POST', body: new URLSearchParams({ id: sid }), credentials: 'same-origin' })
                                            .then(function(res){ if(!res.ok) throw new Error('HTTP ' + res.status); return res.text(); })
                                            .then(function(t){
                                                try{
                                                    var j = JSON.parse(t);
                                                }catch(parseErr){
                                                    alert('Unable to delete the record. (Invalid server response)');
                                                    return;
                                                }
                                                if (j && j.ok){
                                                    try{
                                                        var detail = (r.member_name ? r.member_name : '') + (r.member_ref_id ? ' ('+r.member_ref_id+')' : '');
                                                        if (window.Swal) { Swal.fire({ icon: 'success', title: 'Dipadam', text: 'Record ' + detail + ' has been deleted.' }); }
                                                        else { alert('Record ' + detail + ' has been deleted.'); }
                                                    }catch(e){ }
                                                    try{
                                                        if (btnLoadVolunteer) {
                                                            preserveVolunteerPage = currentVolunteerPage;
                                                            btnLoadVolunteer.click();
                                                        }
                                                    }catch(e){}
                                                } else {
                                                    alert('Unable to delete the record.');
                                                }
                                            })
                                            .catch(function(err){ alert('Error padam: ' + (err && err.message ? err.message : 'Unknown')); });
                                        };
                                        if (window.Swal){ Swal.fire({ title: title, text: text, icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', cancelButtonText: 'Cancel' }).then(function(res){ if (res && res.isConfirmed) callDelete(); }); }
                                        else if (confirm(text)) callDelete();
                                    }catch(ex){ console.error('delete error', ex); }
                                });
                                if (prBtn) prBtn.addEventListener('click', function(){ printDirectHtml(buildCertHtml(r.member_name || '', r.role_name || '', <?php echo json_encode(url('assets/img/sijil/sijil_penyelaras.jpeg')); ?>, { nudgeIfLong: true })); });
                                if (emBtn) emBtn.addEventListener('click', function(){
                                    sendCertificateEmail({
                                        cert_type: 'volunteer',
                                        member_id: r.id || '',
                                        recipient_name: r.member_name || '',
                                        recipient_email: r.member_email || '',
                                        role_text: r.role_name || ''
                                    });
                                });
                            });
                            try{ if (volunteerPageInfo) volunteerPageInfo.textContent = 'Page ' + currentVolunteerPage + '/' + pages; }catch(e){}
                            try{ if (volunteerPrev) volunteerPrev.disabled = currentVolunteerPage <= 1; }catch(e){}
                            try{ if (volunteerNext) volunteerNext.disabled = currentVolunteerPage >= pages; }catch(e){}
                            if (printAllVolunteer) printAllVolunteer.disabled = total === 0;
                        }

                        // wire prev/next buttons
                        try{
                            document.getElementById('penyelarasPrev').addEventListener('click', function(){ if (currentPenyelarasPage>1){ currentPenyelarasPage--; renderPenyelarasPage(); } });
                            document.getElementById('penyelarasNext').addEventListener('click', function(){ currentPenyelarasPage++; renderPenyelarasPage(); });
                            document.getElementById('pengurusPrev').addEventListener('click', function(){ if (currentPengurusPage>1){ currentPengurusPage--; renderPengurusPage(); } });
                            document.getElementById('pengurusNext').addEventListener('click', function(){ currentPengurusPage++; renderPengurusPage(); });
                            document.getElementById('jurulatihPrev').addEventListener('click', function(){ if (currentJurulatihPage>1){ currentJurulatihPage--; renderJurulatihPage(); } });
                            document.getElementById('jurulatihNext').addEventListener('click', function(){ currentJurulatihPage++; renderJurulatihPage(); });
                            document.getElementById('athletePrev').addEventListener('click', function(){ if (currentAthletePage>1){ currentAthletePage--; renderAthletePage(); } });
                            document.getElementById('athleteNext').addEventListener('click', function(){ currentAthletePage++; renderAthletePage(); });
                            if (searchPenyelaras) searchPenyelaras.addEventListener('input', function(){ currentPenyelarasPage = 1; renderPenyelarasPage(); });
                            if (searchPengurus) searchPengurus.addEventListener('input', function(){ currentPengurusPage = 1; renderPengurusPage(); });
                            if (searchJurulatih) searchJurulatih.addEventListener('input', function(){ currentJurulatihPage = 1; renderJurulatihPage(); });
                            if (searchAtlet) searchAtlet.addEventListener('input', function(){ currentAthletePage = 1; renderAthletePage(); });
                            if (searchCommittee) searchCommittee.addEventListener('input', function(){ currentCommitteePage = 1; renderCommitteePage(); });
                            if (searchVolunteer) searchVolunteer.addEventListener('input', function(){ currentVolunteerPage = 1; renderVolunteerPage(); });
                            // committee paging
                            if (committeePrev) committeePrev.addEventListener('click', function(){ if (currentCommitteePage>1){ currentCommitteePage--; renderCommitteePage(); } });
                            if (committeeNext) committeeNext.addEventListener('click', function(){ currentCommitteePage++; renderCommitteePage(); });
                            if (volunteerPrev) volunteerPrev.addEventListener('click', function(){ if (currentVolunteerPage>1){ currentVolunteerPage--; renderVolunteerPage(); } });
                            if (volunteerNext) volunteerNext.addEventListener('click', function(){ currentVolunteerPage++; renderVolunteerPage(); });
                        }catch(e){}

                        // subtabs: show/hide the .tab-pane-inner sections inside #kontinjenSubtabs
                        function showSubIndex(i){
                            try{
                                var container = document.getElementById('kontinjenSubtabs');
                                if(!container) return;
                                var panes = container.querySelectorAll('.tab-pane-inner');
                                panes.forEach(function(p, idx){ p.style.display = (idx === i ? '' : 'none'); });
                                if (kontSubNav){
                                    var links = kontSubNav.querySelectorAll('.nav-link');
                                    links.forEach(function(l, idx){ if(idx===i) l.classList.add('active'); else l.classList.remove('active'); });
                                }
                            }catch(e){ console.error(e); }
                        }
                        if (kontSubNav){
                            kontSubNav.querySelectorAll('.nav-link').forEach(function(btn, idx){
                                btn.addEventListener('click', function(ev){ ev.preventDefault(); showSubIndex(idx); });
                            });
                        }

                        // Hide kontinjen subnav/subtabs when switching to other main tabs
                        try{
                            var sijilTabsEl = document.getElementById('sijilTabs');
                            if (sijilTabsEl){
                                sijilTabsEl.addEventListener('shown.bs.tab', function(e){
                                    try{
                                        var target = e.target || e.srcElement;
                                        var sel = '';
                                        if (target) sel = target.getAttribute('data-bs-target') || target.dataset.bsTarget || '';
                                        if (sel === '#pane-kontinjen'){
                                            if (kontSubNav) kontSubNav.style.display = '';
                                            if (kontSub) kontSub.style.display = '';
                                            showSubIndex(0);
                                        } else {
                                            if (kontSubNav) kontSubNav.style.display = 'none';
                                            if (kontSub) kontSub.style.display = 'none';
                                        }
                                        // when user opens jawatankuasa tab, lock to STAF and auto-load once
                                        if (sel === '#pane-jawatankuasa'){
                                            try{
                                                if (committeeType) { committeeType.value = 'STAF'; committeeType.disabled = true; }
                                                if (btnLoadCommittee && !committeeLoaded) { btnLoadCommittee.click(); }
                                            }catch(ex2){ console.error(ex2); }
                                        }
                                        // when user opens sukarelawan tab, lock to PELAJAR and auto-load once
                                        if (sel === '#pane-sukarelawan'){
                                            try{
                                                if (volunteerType) { volunteerType.value = 'PELAJAR'; volunteerType.disabled = true; }
                                                if (btnLoadVolunteer && !volunteerLoaded) { btnLoadVolunteer.click(); }
                                            }catch(ex3){ console.error(ex3); }
                                        }
                                    }catch(er){ console.error(er); }
                                });
                            }
                        }catch(e){ console.error(e); }

                        // wire print all buttons to client-side print (build multi-page HTML and print via hidden iframe)
                        try{
                            var tmplPengurus = <?php echo json_encode(url('assets/img/sijil/sijil_pengurus.jpeg')); ?>;
                            var tmplJurulatih = <?php echo json_encode(url('assets/img/sijil/sijil_jurulatih.jpeg')); ?>;
                            var tmplPenyelaras = <?php echo json_encode(url('assets/img/sijil/sijil_penyelaras.jpeg')); ?>;
                            var tmplAtlet = <?php
                                $athPath = __DIR__ . '/../assets/img/sijil/sijil_atlet.jpeg';
                                $ver = @file_exists($athPath) ? @filemtime($athPath) : time();
                                echo json_encode(url('assets/img/sijil/sijil_atlet.jpeg') . '?v=' . (int)$ver);
                            ?>;

                            function buildMultiHtml(rows, type){
                                var img = type === 'pengurus' ? tmplPengurus : (type === 'jurulatih' ? tmplJurulatih : (type === 'penyelaras' ? tmplPenyelaras : tmplAtlet));
                                var html = '<!doctype html><html><head><meta charset="utf-8"><title>Print All Certificates</title>' +
                                    '<style>@page{size:210mm 297mm;margin:0}@media print{html,body{width:210mm;margin:0!important;padding:0!important}.page{width:210mm!important;height:297mm!important;margin:0!important;padding:0!important;border:0!important;box-sizing:border-box;break-inside:avoid;page-break-inside:avoid}.bg-img{object-fit:cover!important;left:-0.5mm!important;top:-0.5mm!important;width:211mm!important;height:298mm!important;max-width:none!important}}html,body{height:100%;margin:0;padding:0}body{background:#fff;font-family:Arial,Helvetica,sans-serif}.page{position:relative;width:210mm;height:297mm;overflow:hidden}.bg-img{position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;z-index:0}.cert-name{position:absolute;left:50%;top:38%;transform:translate(-50%,-50%);width:78%;text-align:center;font-weight:700;color:#000;line-height:1.05;z-index:1;font-size:20px}.cert-sport{position:absolute;left:50%;top:48%;transform:translateX(-50%);width:78%;text-align:center;color:#000;font-weight:700;z-index:1;font-size:20px}.page,.bg-img{-webkit-print-color-adjust:exact;print-color-adjust:exact}</style></head><body>';
                                rows.forEach(function(r){
                                    var raw = (r.nama || '').toString();
                                    // strip phone/email
                                    var clean = raw.replace(/\s*\([^)]*\)/g,'').replace(/\s+\S+@\S+$/,'').trim();
                                    var name = clean || raw;
                                    var sukan_combo = '';
                                    if (type === 'athletes'){
                                        var s = (r.sukan||'').toString();
                                        var a = (r.acara||'').toString();
                                        sukan_combo = s;
                                        if (a !== '') sukan_combo = sukan_combo !== '' ? (sukan_combo + ' (' + a + ')') : a;
                                        // uppercase for athletes
                                        sukan_combo = (sukan_combo||'').toString().toUpperCase();
                                    } else {
                                        // role label for other types
                                        if (type === 'pengurus') sukan_combo = ((r.jawatan || '').toString().trim() || 'PENGURUS');
                                        else if (type === 'jurulatih') sukan_combo = ((r.jawatan || '').toString().trim() || 'JURULATIH');
                                        else if (type === 'penyelaras') sukan_combo = 'KETUA KONTINJEN';
                                        else sukan_combo = '';
                                    }
                                    // nudge long text slightly up and reduce font-size
                                    var text = (sukan_combo||'').toString();
                                    var nudge = text.length > 32;
                                    var sportTop = (type === 'pengurus' || type === 'jurulatih')
                                        ? (nudge ? '46.5%' : '48.5%')
                                        : (nudge ? '47%' : '49%');
                                    var sportSize = '18px';
                                    html += '<div class="page">' +
                                        '<img class="bg-img" src="'+img+'" alt="bg">' +
                                        '<div class="cert-name" style="font-size:18px">'+(name.replace(/</g,'&lt;'))+'</div>' +
                                        '<div class="cert-sport" style="top:'+sportTop+';font-size:'+sportSize+'">'+((sukan_combo||'').toString().toUpperCase().replace(/</g,'&lt;'))+'</div>' +
                                    '</div>';
                                });
                                html += '<script> (function(){ if(window.top===window.self){ setTimeout(function(){ window.print(); },120); } })();<\/script></body></html>';
                                return html;
                            }

                            if (printAllPengurus) printAllPengurus.addEventListener('click', function(){
                                if (!lastRows.pengurus || lastRows.pengurus.length===0){ alert('No managers to print.'); return; }
                                var html = buildMultiHtml(lastRows.pengurus, 'pengurus');
                                printDirectHtml(html);
                            });

                            if (printAllJurulatih) printAllJurulatih.addEventListener('click', function(){
                                if (!lastRows.jurulatih || lastRows.jurulatih.length===0){ alert('No coaches to print.'); return; }
                                var html = buildMultiHtml(lastRows.jurulatih, 'jurulatih');
                                printDirectHtml(html);
                            });

                            if (printAllAtlet) printAllAtlet.addEventListener('click', function(){
                                if (!lastRows.athletes || lastRows.athletes.length===0){ alert('No athletes to print.'); return; }
                                var html = buildMultiHtml(lastRows.athletes, 'athletes');
                                printDirectHtml(html);
                            });
                            if (printAllPenyelaras) printAllPenyelaras.addEventListener('click', function(){
                                if (!lastRows.penyelaras || lastRows.penyelaras.length===0){ alert('No coordinators to print.'); return; }
                                var html = buildMultiHtml(lastRows.penyelaras, 'penyelaras');
                                printDirectHtml(html);
                            });
                            if (printAllCommittee) printAllCommittee.addEventListener('click', function(){
                                if (!lastRows.committee || lastRows.committee.length===0){ alert('No records to print.'); return; }
                                var rows = lastRows.committee.map(function(r){ return { nama: r.member_name || '', sukan: r.role_name || '', event: '' }; });
                                var html = buildMultiHtml(rows, 'penyelaras');
                                printDirectHtml(html);
                            });
                            if (printAllVolunteer) printAllVolunteer.addEventListener('click', function(){
                                if (!lastRows.volunteer || lastRows.volunteer.length===0){ alert('No records to print.'); return; }
                                var rows = lastRows.volunteer.map(function(r){ return { nama: r.member_name || '', sukan: r.role_name || '', event: '' }; });
                                var html = buildMultiHtml(rows, 'penyelaras');
                                printDirectHtml(html);
                            });
                        }catch(e){}
                        btn.addEventListener('click', function(){
                            var kod = (document.getElementById('selectKont') && document.getElementById('selectKont').value) ? document.getElementById('selectKont').value : '';
                            if (!kod) { status.textContent = 'Please select a contingent first.'; return; }
                            var url = window.location.pathname + '?ajax=athletes&kod=' + encodeURIComponent(kod);
                            status.textContent = '';
                            btn.disabled = true;
                            showLoader();
                            console.log('Fetching athletes from', url);
                            // fetch athletes and managers concurrently
                            var urlManagers = window.location.pathname + '?ajax=managers&kod=' + encodeURIComponent(kod);
                            fetch(url, { credentials: 'same-origin' })
                                .then(function(r){
                                    if (!r.ok) throw new Error('HTTP ' + r.status);
                                    return r.text();
                                })
                                .then(function(text){
                                    try {
                                        var j = JSON.parse(text);
                                        console.log('Athletes JSON response', j);
                                        var athletesJson = j && j.ok ? (j.rows || []) : [];
                                        // fetch managers and penyelaras concurrently
                                        var urlPenyelaras = window.location.pathname + '?ajax=penyelaras&kod=' + encodeURIComponent(kod);
                                        return Promise.all([
                                            fetch(urlManagers, { credentials: 'same-origin' }).then(function(r2){ if(!r2.ok) throw new Error('HTTP ' + r2.status); return r2.text(); }),
                                            fetch(urlPenyelaras, { credentials: 'same-origin' }).then(function(r3){ if(!r3.ok) throw new Error('HTTP ' + r3.status); return r3.text(); })
                                        ]).then(function(texts){
                                            try {
                                                var mj = JSON.parse(texts[0]);
                                                var mgrRows = mj && mj.ok ? (mj.rows || []) : [];
                                            } catch(err2) {
                                                console.error('Managers endpoint returned non-JSON:', texts[0]);
                                                throw new Error('Managers endpoint returned non-JSON');
                                            }
                                            try {
                                                var pj = JSON.parse(texts[1]);
                                                var penyRows = pj && pj.ok ? (pj.rows || []) : [];
                                            } catch(err3) {
                                                console.error('Coordinator endpoint returned non-JSON:', texts[1]);
                                                throw new Error('Coordinator endpoint returned non-JSON');
                                            }
                                            return { athletes: athletesJson, managers: mgrRows, penyelaras: penyRows };
                                        });
                                    } catch(err) {
                                        console.error('Athletes endpoint returned non-JSON:', text);
                                        throw new Error('Athletes endpoint returned non-JSON');
                                    }
                                }).then(function(all){
                                    // process managers into pengurus and jurulatih
                                    // Build deduplicated pengurus/jurulatih lists (dedupe by name only)
                                    var pengurusMap = Object.create(null);
                                    var jurulatihMap = Object.create(null);
                                    (all.managers || []).forEach(function(r){
                                        // each r may contain pengurus and jurulatih strings like: "Name (0123456789) email@example.com"
                                        if (r.pengurus) {
                                            r.pengurus.split(' ||| ').forEach(function(praw){
                                                var p = (praw||'').trim(); if(!p) return;
                                                var nama = '', jawatan = '', tel = '', emel = '';
                                                if (p.indexOf('@@JAWATAN@@') !== -1) {
                                                    var sNama = p.split('@@JAWATAN@@');
                                                    nama = (sNama[0] || '').trim();
                                                    var rest1 = (sNama[1] || '');
                                                    var sTel = rest1.split('@@TEL@@');
                                                    jawatan = (sTel[0] || '').trim();
                                                    var rest2 = (sTel[1] || '');
                                                    var sEmel = rest2.split('@@EMEL@@');
                                                    tel = (sEmel[0] || '').trim();
                                                    emel = (sEmel[1] || '').trim();
                                                } else {
                                                    var m = p.match(/\(([^)]*)\)/);
                                                    tel = m ? m[1].trim() : '';
                                                    nama = p.replace(/\s*\([^)]*\)/g,'').replace(/\s+\S+@\S+$/,'').trim();
                                                    var em = p.match(/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i);
                                                    emel = em ? em[0] : '';
                                                }
                                                if (!nama) return;
                                                if (p.replace(/\s+/g,'').toUpperCase() === '@@JAWATAN@@@@TEL@@@@EMEL@@') return;
                                                var key = (nama || p).toLowerCase();
                                                if (!pengurusMap[key]) pengurusMap[key] = { nama: nama || p, jawatan: jawatan || '', tel: tel, emel: emel || '' };
                                                else {
                                                    if (!pengurusMap[key].tel && tel) pengurusMap[key].tel = tel;
                                                    if (!pengurusMap[key].jawatan && jawatan) pengurusMap[key].jawatan = jawatan;
                                                    if (!pengurusMap[key].emel && emel) pengurusMap[key].emel = emel;
                                                }
                                            });
                                        }
                                        if (r.jurulatih) {
                                            r.jurulatih.split(' ||| ').forEach(function(jraw){
                                                var j = (jraw||'').trim(); if(!j) return;
                                                var namaj = '', jawatanj = '', jtTel = '', jtEmel = '';
                                                if (j.indexOf('@@JAWATAN@@') !== -1) {
                                                    var sjNama = j.split('@@JAWATAN@@');
                                                    namaj = (sjNama[0] || '').trim();
                                                    var jRest1 = (sjNama[1] || '');
                                                    var sjTel = jRest1.split('@@TEL@@');
                                                    jawatanj = (sjTel[0] || '').trim();
                                                    var jRest2 = (sjTel[1] || '');
                                                    var sjEmel = jRest2.split('@@EMEL@@');
                                                    jtTel = (sjEmel[0] || '').trim();
                                                    jtEmel = (sjEmel[1] || '').trim();
                                                } else {
                                                    var mj = j.match(/\(([^)]*)\)/);
                                                    jtTel = mj ? mj[1].trim() : '';
                                                    namaj = j.replace(/\s*\([^)]*\)/g,'').replace(/\s+\S+@\S+$/,'').trim();
                                                    var jm = j.match(/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i);
                                                    jtEmel = jm ? jm[0] : '';
                                                }
                                                if (!namaj) return;
                                                if (j.replace(/\s+/g,'').toUpperCase() === '@@JAWATAN@@@@TEL@@@@EMEL@@') return;
                                                var keyj = (namaj || j).toLowerCase();
                                                if (!jurulatihMap[keyj]) jurulatihMap[keyj] = { nama: namaj || j, jawatan: jawatanj || '', tel: jtTel, emel: jtEmel || '' };
                                                else {
                                                    if (!jurulatihMap[keyj].tel && jtTel) jurulatihMap[keyj].tel = jtTel;
                                                    if (!jurulatihMap[keyj].jawatan && jawatanj) jurulatihMap[keyj].jawatan = jawatanj;
                                                    if (!jurulatihMap[keyj].emel && jtEmel) jurulatihMap[keyj].emel = jtEmel;
                                                }
                                            });
                                        }
                                    });
                                    var pengurusList = Object.keys(pengurusMap).map(function(k){ return pengurusMap[k]; });
                                    var jurulatihList = Object.keys(jurulatihMap).map(function(k){ return jurulatihMap[k]; });

                                    // store lists and render paged views
                                    lastRows.pengurus = pengurusList;
                                    lastRows.jurulatih = jurulatihList;
                                    lastRows.athletes = (all.athletes || []);
                                    lastRows.penyelaras = (all.penyelaras || []);
                                    currentPenyelarasPage = 1; currentPengurusPage = 1; currentJurulatihPage = 1; currentAthletePage = 1;
                                    renderPenyelarasPage();
                                    renderPengurusPage();
                                    renderJurulatihPage();
                                    renderAthletePage();
                                    // update subtab labels with kod and counts
                                    try{
                                        function badgeHtml(n, cls){ return '<span class="badge ' + (cls||'bg-secondary') + ' ms-2">' + (parseInt(n,10)||0) + '</span>'; }
                                        var kodLbl = (getSelectedKod() || '').toUpperCase();
                                        var nav = kontSubNav;
                                        if (nav){
                                            var links = nav.querySelectorAll('.nav-link');
                                            // Contingent Leader
                                            if (links[0]) links[0].innerHTML = 'Contingent Leader ' + (kodLbl ? ('['+kodLbl+']') : '') + badgeHtml(lastRows.penyelaras.length || 0, 'bg-info text-dark');
                                            // Manager
                                            if (links[1]) links[1].innerHTML = 'Team Manager ' + (kodLbl ? ('['+kodLbl+']') : '') + badgeHtml(lastRows.pengurus.length || 0, 'bg-success');
                                            // Coach
                                            if (links[2]) links[2].innerHTML = 'Sports Coach ' + (kodLbl ? ('['+kodLbl+']') : '') + badgeHtml(lastRows.jurulatih.length || 0, 'bg-warning text-dark');
                                            // Athlete
                                            if (links[3]) links[3].innerHTML = 'Athlet Sport ' + (kodLbl ? ('['+kodLbl+']') : '') + badgeHtml(lastRows.athletes.length || 0, 'bg-primary');
                                        }
                                        if (kontSub) kontSub.style.display = '';
                                        if (kontSubNav) kontSubNav.style.display = '';
                                        showSubIndex(0);
                                    }catch(e){ console.error(e); }
                                    hideLoader();
                                    btn.disabled = false;
                                    // mark kontinjen as loaded to avoid auto-triggering again
                                    try{ window.kontinjenLoaded = true; }catch(e){}
                                }).catch(function(e){ console.error('Fetch error', e); status.textContent = 'Unable to fetch data.'; btn.disabled = false; hideLoader(); alert('Unable to fetch athlete/manager data: '+ (e && e.message ? e.message : 'Unknown')); });
                        });

                        // Auto-load default kontinjen (UPNM) on page load if select present
                        try{
                            var selKont = document.getElementById('selectKont');
                            if (selKont && selKont.value && selKont.value.toUpperCase() === 'UPNM'){
                                // trigger click once after short delay so DOM settles
                                setTimeout(function(){ if (!window.kontinjenLoaded) btn.click(); }, 250);
                            }
                        }catch(e){ console.error(e); }

                                // Committee load handler for ORGANISING COMMITTEE CERTIFICATES tab
                                if (btnLoadCommittee){
                                    btnLoadCommittee.addEventListener('click', function(){
                                        var t = (committeeType && committeeType.value) ? committeeType.value : '';
                                        if (!t){ committeeLoadStatus.textContent = 'Please select a member type.'; return; }
                                        committeeLoadStatus.textContent = ''; btnLoadCommittee.disabled = true; if (committeeLoader) committeeLoader.style.display = 'inline-block';
                                        var url = window.location.pathname + '?ajax=committee&type=' + encodeURIComponent(t);
                                        fetch(url, { credentials: 'same-origin' }).then(function(r){ if(!r.ok) throw new Error('HTTP ' + r.status); return r.text(); }).then(function(text){
                                            try{
                                                var j = JSON.parse(text);
                                                if (!j || !j.ok){ throw new Error(j && j.error ? j.error : 'Empty response'); }
                                                lastRows.committee = j.rows || [];
                                                currentCommitteePage = (preserveCommitteePage && preserveCommitteePage > 0) ? preserveCommitteePage : 1;
                                                preserveCommitteePage = null;
                                                renderCommitteePage();
                                                if (committeeWrap) committeeWrap.style.display = '';
                                                committeeLoadStatus.textContent = '';
                                                committeeLoaded = true;
                                            } catch(err){ console.error('Committee parse error', err, text); committeeLoadStatus.textContent = 'Unable to process data.'; alert('Unable to fetch committee data.'); }
                                            btnLoadCommittee.disabled = false; if (committeeLoader) committeeLoader.style.display = 'none';
                                        }).catch(function(err){ console.error('Committee fetch error', err); committeeLoadStatus.textContent = 'Unable to fetch data.'; btnLoadCommittee.disabled = false; if (committeeLoader) committeeLoader.style.display = 'none'; alert('Unable to fetch committee data: '+ (err && err.message ? err.message : 'Unknown')); });
                                    });
                                }

                                // Volunteer load handler (PELAJAR) - same as committee but stores into lastRows.volunteer
                                if (btnLoadVolunteer){
                                    btnLoadVolunteer.addEventListener('click', function(){
                                        var t = (volunteerType && volunteerType.value) ? volunteerType.value : '';
                                        if (!t){ volunteerLoadStatus.textContent = 'Please select a member type.'; return; }
                                        volunteerLoadStatus.textContent = ''; btnLoadVolunteer.disabled = true; if (volunteerLoader) volunteerLoader.style.display = 'inline-block';
                                        var url = window.location.pathname + '?ajax=committee&type=' + encodeURIComponent(t);
                                        fetch(url, { credentials: 'same-origin' }).then(function(r){ if(!r.ok) throw new Error('HTTP ' + r.status); return r.text(); }).then(function(text){
                                            try{
                                                var j = JSON.parse(text);
                                                if (!j || !j.ok){ throw new Error(j && j.error ? j.error : 'Empty response'); }
                                                lastRows.volunteer = j.rows || [];
                                                currentVolunteerPage = (preserveVolunteerPage && preserveVolunteerPage > 0) ? preserveVolunteerPage : 1;
                                                preserveVolunteerPage = null;
                                                renderVolunteerPage();
                                                if (volunteerWrap) volunteerWrap.style.display = '';
                                                volunteerLoadStatus.textContent = '';
                                                volunteerLoaded = true;
                                            } catch(err){ console.error('Volunteer parse error', err, text); volunteerLoadStatus.textContent = 'Unable to process data.'; alert('Unable to fetch volunteer data.'); }
                                            btnLoadVolunteer.disabled = false; if (volunteerLoader) volunteerLoader.style.display = 'none';
                                        }).catch(function(err){ console.error('Volunteer fetch error', err); volunteerLoadStatus.textContent = 'Unable to fetch data.'; btnLoadVolunteer.disabled = false; if (volunteerLoader) volunteerLoader.style.display = 'none'; alert('Unable to fetch volunteer data: '+ (err && err.message ? err.message : 'Unknown')); });
                                    });
                                }

                        
                    })();
                </script>
        </div>
    </div>

    </div>

<style>
@page { size: A4 portrait; margin: 0; }
@media print {
    body { background: #fff; color: #000; }
    .container-fluid { padding: 0; }
    .card { box-shadow: none; border: none; }
    .btn { display: none !important; }
    .row-cols-1 .col { page-break-inside: avoid; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function(){
    var templateUrl = <?php echo json_encode($img_url_versioned); ?>;
    function buildCertHtml(name, sukanCombined, templateOverride, opts){
        sukanCombined = englishSportLabel(englishLabel(sukanCombined));
        var tUrl = templateOverride || templateUrl;
        opts = opts || {};
        var text = (sukanCombined||'').toString();
        var nudge = !!opts.nudgeIfLong && text.length > 32;
        var sportTop = nudge ? '47%' : '49%';
        var sportSize = '18px';
            var html = '<!doctype html><html><head><meta charset="utf-8"><title>Participation Certificates</title>' +
            '<style>@page{size:210mm 297mm;margin:0}@media print{html,body{width:210mm;margin:0!important;padding:0!important}.page{width:210mm!important;height:297mm!important;margin:0!important;padding:0!important;border:0!important;box-sizing:border-box;break-inside:avoid;page-break-inside:avoid}.bg-img{object-fit:cover!important;left:-0.5mm!important;top:-0.5mm!important;width:211mm!important;height:298mm!important;max-width:none!important}}html,body{height:100%;margin:0;padding:0}body{font-family:Arial,Helvetica,sans-serif}.page{position:relative;width:210mm;height:297mm;overflow:hidden}.bg-img{position:absolute;left:0;top:0;width:100%;height:100%;object-fit:cover;z-index:0}.cert-name{position:absolute;left:50%;top:38%;transform:translate(-50%,-50%);width:78%;text-align:center;font-weight:700;color:#000;line-height:1.05;z-index:1;font-size:18px}.cert-sport{position:absolute;left:50%;top:'+sportTop+';transform:translateX(-50%);width:78%;text-align:center;color:#000;font-weight:700;z-index:1;font-size:'+sportSize+'}.page,.bg-img{-webkit-print-color-adjust:exact;print-color-adjust:exact}</style></head><body>'+
            '<div class="page">'+
                '<img class="bg-img" src="'+tUrl+'" alt="bg">'+
                '<div class="cert-name">'+(name||'')+'</div>'+
                '<div class="cert-sport">' + (text.toUpperCase()) + '</div>'+
            '</div>' +
            '<script> (function(){ if(window.top===window.self){ setTimeout(function(){ window.print(); },120); } })();<\/script></body></html>';
        return html;
    }

    window.printCertificate = function(name, sukanCombined){
        try{
            var w = window.open('about:blank','_blank');
            if(!w) { alert('Please allow pop-ups to print certificates.'); return; }
            var doc = w.document;
            doc.open();
            doc.write(buildCertHtml(name, sukanCombined));
            doc.close();
            setTimeout(function(){ try { w.focus(); w.print(); } catch(e) { console.error(e); } }, 700);
        }catch(e){ console.error(e); alert('Unable to open the print window.'); }
    };

    document.querySelectorAll('.btn-print-cert').forEach(function(btn){
        btn.addEventListener('click', function(e){
            var name = this.getAttribute('data-name') || '';
            var sukanCombined = this.getAttribute('data-sukan') || '';
            printCertificate(name, sukanCombined);
        });
    });
});
</script>
<?php
$content = ob_get_clean();
// Fallback for debugging: if content is empty, show a helpful message instead of blank page
if (is_string($content) && trim($content) === '') {
    $content = '<div class="container-fluid px-3"><div class="alert alert-warning">This page has no content to display (debug fallback).</div></div>';
}
require_once __DIR__ . '/../includes/layout.php';
