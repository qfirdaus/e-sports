<?php
require_once __DIR__ . '/PasswordResetService.php';
class AccountProfileService {
    public function __construct(private PDO $db) {}
    public function rename(int $id, string $name): string {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100 || preg_match('/[\x00-\x1f\x7f]/u', $name)) throw new InvalidArgumentException('Enter your full name using 1 to 100 characters.');
        $st = $this->db->prepare("UPDATE users SET full_name = ?, updated_at = NOW(), updated_by = ? WHERE id = ? AND status = 'active' AND deleted_at IS NULL");
        $st->execute([$name, $id, $id]);
        return $name;
    }
    public function changePassword(int $id, string $current, string $new, string $confirm): string {
        if ($current === '' || $new === '' || $confirm === '') throw new InvalidArgumentException('Please complete all fields.');
        if ($new !== $confirm) throw new InvalidArgumentException('The new password and confirmation do not match.');
        if (str_contains($new, "\0")) throw new InvalidArgumentException('The password contains an invalid character.');
        $error = PasswordResetService::passwordError($new);
        if ($error !== '') throw new InvalidArgumentException($error);
        $this->db->beginTransaction();
        try {
            $st = $this->db->prepare("SELECT password_hash, password_changed_at FROM users WHERE id = ? AND status = 'active' AND deleted_at IS NULL FOR UPDATE");
            $st->execute([$id]); $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row || !password_verify($current, $row['password_hash'])) throw new InvalidArgumentException('The current password is incorrect.');
            if (password_verify($new, $row['password_hash'])) throw new InvalidArgumentException('Choose a password different from your current password.');
            // Monotonic version also revokes sessions on two changes in the same second.
            $st = $this->db->prepare('UPDATE users SET password_hash = ?, password_changed_at = GREATEST(NOW(), COALESCE(DATE_ADD(password_changed_at, INTERVAL 1 SECOND), NOW())), password_reset_token = NULL, password_reset_expires = NULL, updated_at = NOW(), updated_by = ? WHERE id = ?');
            $st->execute([password_hash($new, PASSWORD_DEFAULT), $id, $id]);
            $st = $this->db->prepare('SELECT password_changed_at FROM users WHERE id = ?'); $st->execute([$id]);
            $version = (string)$st->fetchColumn(); $this->db->commit(); return $version;
        } catch (Throwable $e) { if ($this->db->inTransaction()) $this->db->rollBack(); throw $e; }
    }
}
