<?php
// AJAX endpoint: change password for logged-in user
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function($e){
    http_response_code(500);
    error_log('[ajax/change_password][exception] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error.']);
    exit;
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

try {
    $auth = getAuth();
    $auth->requireAuth();

    // Accept both form-encoded and JSON bodies
    $current = isset($_POST['current_password']) ? trim($_POST['current_password']) : null;
    $new = isset($_POST['new_password']) ? trim($_POST['new_password']) : null;
    $confirm = isset($_POST['confirm_password']) ? trim($_POST['confirm_password']) : null;

    if (!$current || !$new || !$confirm) {
        echo json_encode(['success' => false, 'message' => 'Please complete all fields.']);
        exit;
    }

    if ($new !== $confirm) {
        echo json_encode(['success' => false, 'message' => 'The new password and confirmation do not match.']);
        exit;
    }

    // validate password policy constants from auth.php
    $errors = [];
    if (defined('PASSWORD_MIN_LENGTH') && strlen($new) < PASSWORD_MIN_LENGTH) $errors[] = 'The password must contain at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    if (defined('PASSWORD_REQUIRE_UPPERCASE') && PASSWORD_REQUIRE_UPPERCASE && !preg_match('/[A-Z]/', $new)) $errors[] = 'The password must contain an uppercase letter.';
    if (defined('PASSWORD_REQUIRE_NUMBER') && PASSWORD_REQUIRE_NUMBER && !preg_match('/[0-9]/', $new)) $errors[] = 'The password must contain a number.';
    if (defined('PASSWORD_REQUIRE_SPECIAL') && PASSWORD_REQUIRE_SPECIAL && !preg_match('/[^a-zA-Z0-9]/', $new)) $errors[] = 'The password must contain a special character.';
    if ($errors) {
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        exit;
    }

    // get current user id from session
    $userId = Session::get('user_id');
    if (!$userId) {
        echo json_encode(['success' => false, 'message' => 'Your session has expired. Please sign in again.']);
        exit;
    }

    $db = getDB();
    $stmt = $db->prepare('SELECT password_hash, email FROM users WHERE id = :id AND deleted_at IS NULL');
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentHash = $row['password_hash'] ?? '';
    $userEmail = $row['email'] ?? '';

    // Diagnostic logging to help debug verification issues (do not log plaintext passwords)
    if (!$row) {
        error_log(sprintf('[ajax/change_password] no user row for user_id=%s', $userId));
    } else {
        error_log(sprintf('[ajax/change_password] fetched user_id=%s email=%s hash_len=%d', $userId, $userEmail, is_string($currentHash) ? strlen($currentHash) : 0));
    }

    $verifyOk = !empty($currentHash) && password_verify($current, $currentHash);
    if (!$verifyOk) {
        $hashInfo = is_string($currentHash) ? substr($currentHash, 0, 10) : 'empty';
        error_log(sprintf('[ajax/change_password] verify FAILED for user_id=%s email=%s hash_prefix=%s', $userId, $userEmail, $hashInfo));
        echo json_encode(['success' => false, 'message' => 'The current password is incorrect.']);
        exit;
    } else {
        error_log(sprintf('[ajax/change_password] verify OK for user_id=%s email=%s', $userId, $userEmail));
    }

    $newHash = password_hash($new, PASSWORD_BCRYPT);
    $uStmt = $db->prepare('UPDATE users SET password_hash = :hash, updated_at = NOW(), updated_by = :uid WHERE id = :id');
    $uStmt->execute([':hash' => $newHash, ':uid' => $userId, ':id' => $userId]);

    // optional: log activity (Auth::logActivity is private; skip external call)
    try {
        // If audit_logs table exists, insert a simple audit record without calling private methods
        $check = $db->query("SHOW TABLES LIKE 'audit_logs'");
        if ($check && $check->rowCount() > 0) {
            $ins = $db->prepare("INSERT INTO audit_logs (user_id, action, description, ip_address, user_agent) VALUES (:uid, :action, :desc, :ip, :ua)");
            $ins->execute([
                ':uid' => $userId,
                ':action' => 'change_password',
                ':desc' => 'User changed password via profile modal',
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        }
    } catch (Exception $e) { /* non-fatal */ }

    echo json_encode(['success' => true, 'message' => 'Password updated successfully.']);
    exit;
} catch (Exception $e) {
    error_log('[ajax/change_password] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error updating the password.']);
    exit;
}
