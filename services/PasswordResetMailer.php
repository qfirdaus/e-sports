<?php
class PasswordResetMailer {
    public function __construct(private PDO $db) {}
    public function send(string $to, string $token): void {
        $st = $this->db->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $st->execute(['settings_page_payload_v1']);
        $f = json_decode((string)$st->fetchColumn(), true)['emailNotificationForm'] ?? [];
        $host = trim($f['smtpHost'] ?? '');
        $port = (int)($f['smtpPort'] ?? 0);
        $from = trim($f['emailFrom'] ?? '');
        if (empty($f['emailEnabled']) || !preg_match('/^[a-zA-Z0-9.-]+$/D', $host) || $port < 1 || $port > 65535 || !filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Email settings incomplete');
        $password = (string)($f['smtpPassword'] ?? '');
        if (str_starts_with($password, 'ENC:v1:')) {
            $parts = explode(':', substr($password, 7), 2);
            $key = getenv('SETTINGS_ENCRYPTION_KEY');
            if (!$key || count($parts) !== 2) throw new RuntimeException('Email credentials unavailable');
            $iv = base64_decode($parts[0], true);
            $cipher = base64_decode($parts[1], true);
            if ($iv === false || strlen($iv) !== 16 || $cipher === false) throw new RuntimeException('Invalid encrypted credentials');
            $password = openssl_decrypt($cipher, 'AES-256-CBC', hash('sha256', $key, true), OPENSSL_RAW_DATA, $iv);
            if ($password === false) throw new RuntimeException('Email credentials unavailable');
        }
        // Never derive password-reset links from the untrusted HTTP Host header.
        $base = rtrim(getenv('PASSWORD_RESET_BASE_URL') ?: 'https://sam2026.upnm.edu.my/e-sports', '/');
        if (!filter_var($base, FILTER_VALIDATE_URL) || parse_url($base, PHP_URL_SCHEME) !== 'https') throw new RuntimeException('Invalid reset base URL');
        $link = $base . '/auth/reset-password.php?token=' . rawurlencode($token);
        $body = "A password reset was requested for your SAM 2026 account.\n\nSet a new password using this link:\n" . $link . "\n\nThis link expires in 30 minutes and can only be used once. If you did not request this, ignore this email. Your password has not changed.\n\nSAM 2026 Support";
        $fp = @stream_socket_client(($port === 465 ? 'tls://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 10);
        if (!$fp) throw new RuntimeException('Email server unreachable');
        stream_set_timeout($fp, 10);
        $read = static function(array $codes) use ($fp): void {
            do {
                $line = fgets($fp, 1024);
                if ($line === false) throw new RuntimeException('Email server timeout');
            } while (isset($line[3]) && $line[3] === '-');
            if (!in_array((int)substr($line, 0, 3), $codes, true)) throw new RuntimeException('Email server rejected request');
        };
        $write = static function(string $text) use ($fp): void {
            while ($text !== '') {
                $n = fwrite($fp, $text);
                if (!$n) throw new RuntimeException('Email write failed');
                $text = substr($text, $n);
            }
        };
        $cmd = static function(string $text, array $codes) use ($write, $read): void { $write($text . "\r\n"); $read($codes); };
        try {
            $read([220]); $cmd('EHLO sam2026.upnm.edu.my', [250]);
            if ($port !== 465) {
                $cmd('STARTTLS', [220]);
                if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('Email TLS failed');
                $cmd('EHLO sam2026.upnm.edu.my', [250]);
            }
            if (!empty($f['smtpUsername'])) {
                $cmd('AUTH LOGIN', [334]); $cmd(base64_encode($f['smtpUsername']), [334]); $cmd(base64_encode($password), [235]);
            }
            $cmd('MAIL FROM:<' . $from . '>', [250]); $cmd('RCPT TO:<' . $to . '>', [250,251]); $cmd('DATA', [354]);
            $write('From: SAM 2026 <'.$from.">\r\nTo: <".$to.">\r\nSubject: Reset your SAM 2026 password\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($body))."\r\n.\r\n");
            $read([250]);
            // Acceptance above is definitive; a disconnect on QUIT must not revoke the link.
            @fwrite($fp, "QUIT\r\n");
        } finally { fclose($fp); }
    }
}
