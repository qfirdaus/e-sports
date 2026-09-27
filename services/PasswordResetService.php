<?php
/** Single-use, hashed reset tokens. No account details are returned to callers. */
class PasswordResetService {
    public function __construct(private PDO $db) {}

    public function request(string $email, string $ip, callable $send): void {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return;
        // Serialize the rate check so simultaneous requests cannot bypass it.
        $lock = $this->db->query("SELECT GET_LOCK('sam_password_reset_rate', 5)")->fetchColumn();
        if (!$lock) throw new RuntimeException('Reset service busy');
        try {
            $this->db->exec('DELETE FROM password_reset_requests WHERE requested_at < UTC_TIMESTAMP() - INTERVAL 1 DAY');
            $emailKey = hash('sha256', $email);
            $ipKey = hash('sha256', $ip);
            $st = $this->db->prepare('SELECT SUM(email_hash = ?) AS email_count, SUM(ip_hash = ?) AS ip_count FROM password_reset_requests WHERE requested_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR');
            $st->execute([$emailKey, $ipKey]);
            $counts = $st->fetch(PDO::FETCH_ASSOC);
            if ((int)$counts['email_count'] >= 3 || (int)$counts['ip_count'] >= 10) return;
            $this->db->prepare('INSERT INTO password_reset_requests (email_hash, ip_hash, requested_at) VALUES (?, ?, UTC_TIMESTAMP())')->execute([$emailKey, $ipKey]);
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('sam_password_reset_rate')");
        }
        $st = $this->db->prepare("SELECT id, email FROM users WHERE email = ? AND status = 'active' AND deleted_at IS NULL LIMIT 1");
        $st->execute([$email]);
        $user = $st->fetch(PDO::FETCH_ASSOC);
        if (!$user) return;
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $this->db->prepare('UPDATE users SET password_reset_token = ?, password_reset_expires = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE) WHERE id = ?')->execute([$hash, $user['id']]);
        try { $send($user['email'], $token); }
        catch (Throwable $e) {
            $this->db->prepare('UPDATE users SET password_reset_token = NULL, password_reset_expires = NULL WHERE id = ? AND password_reset_token = ?')->execute([$user['id'], $hash]);
            throw $e;
        }
    }

    public static function passwordError(string $password): string {
        if (strlen($password) < PASSWORD_MIN_LENGTH) return 'Use at least ' . PASSWORD_MIN_LENGTH . ' characters.';
        if (strlen($password) > 72) return 'Use no more than 72 bytes for your password.';
        if (PASSWORD_REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $password)) return 'Include an uppercase letter.';
        if (PASSWORD_REQUIRE_NUMBER && !preg_match('/[0-9]/', $password)) return 'Include a number.';
        if (PASSWORD_REQUIRE_SPECIAL && !preg_match('/[^a-zA-Z0-9]/', $password)) return 'Include a special character.';
        return '';
    }

    public function valid(string $token): bool {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
        $st = $this->db->prepare("SELECT id FROM users WHERE password_reset_token = ? AND password_reset_expires > UTC_TIMESTAMP() AND status = 'active' AND deleted_at IS NULL");
        $st->execute([hash('sha256', $token)]);
        return (bool)$st->fetchColumn();
    }

    public function reset(string $token, string $password): bool {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token) || self::passwordError($password) !== '') return false;
        // One conditional UPDATE consumes the token atomically, including concurrent submissions.
        $st = $this->db->prepare("UPDATE users SET password_hash = ?, password_changed_at = NOW(), password_reset_token = NULL, password_reset_expires = NULL, login_attempts = 0, locked_until = NULL WHERE password_reset_token = ? AND password_reset_expires > UTC_TIMESTAMP() AND status = 'active' AND deleted_at IS NULL");
        $st->execute([password_hash($password, PASSWORD_DEFAULT), hash('sha256', $token)]);
        return $st->rowCount() === 1;
    }
}
