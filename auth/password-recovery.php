<?php
if (!isset($resetMode)) { http_response_code(404); exit; }
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/PasswordResetService.php';
require_once __DIR__ . '/../services/PasswordResetMailer.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
Session::start();
if (empty($_SESSION['recovery_csrf'])) $_SESSION['recovery_csrf'] = bin2hex(random_bytes(32));
$service = new PasswordResetService(getDB());
$error = ''; $message = ''; $done = false;
$token = (string)($_POST['token'] ?? $_GET['token'] ?? '');
$valid = $resetMode && $service->valid($token);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['recovery_csrf'], (string)($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please refresh this page and try again.';
    } elseif ($resetMode) {
        $password = (string)($_POST['password'] ?? '');
        if (!$valid) $error = 'This reset link is invalid or has expired. Please request a new link.';
        elseif ($password !== (string)($_POST['confirm_password'] ?? '')) $error = 'The passwords do not match.';
        else {
            $error = PasswordResetService::passwordError($password);
            if ($error === '') {
                try {
                    if ($service->reset($token, $password)) {
                        $done = true;
                        $message = 'Your password has been updated. Sign in with your new password.';
                        Session::destroy();
                    } else $error = 'This reset link is invalid or has expired. Please request a new link.';
                } catch (Throwable $e) { $error = 'Unable to reset your password right now. Please try again later.'; }
            }
        }
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $error = 'Enter a valid email address.';
        else {
            try {
                $mailer = new PasswordResetMailer(getDB());
                $service->request($email, (string)($_SERVER['REMOTE_ADDR'] ?? ''), [$mailer, 'send']);
            } catch (Throwable $e) {
                // Do not log the token, email, or SMTP credentials.
                error_log('[password-reset] Request failed: ' . get_class($e));
            }
            $message = 'If an eligible account exists for that email, you will receive a reset link. Check your inbox and spam folder. You can request up to three links per hour. If no email arrives, contact the secretariat.';
        }
    }
}
$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$title = $resetMode ? 'Set a new password' : 'Forgot your password?';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $h($title) ?> · SAM 2026</title><link rel="stylesheet" href="<?= $h(asset('css/password-recovery.css')) ?>"></head>
<body><main class="recovery-card">
<a href="<?= $h(url('public/index.php')) ?>"><img class="logo" src="<?= $h(asset('img/logos/logo-main.png')) ?>" alt="SAM 2026"></a>
<p class="eyebrow">SAM 2026 MANAGEMENT PORTAL</p><h1><?= $h($title) ?></h1>
<p class="intro"><?= $resetMode ? 'Choose a strong password to secure your account.' : 'Enter your registered email address and we will send you a password reset link.' ?></p>
<?php if ($error): ?><div class="notice error" role="alert"><?= $h($error) ?></div><?php endif; ?>
<?php if ($message): ?><div class="notice" role="status"><?= $h($message) ?></div><?php endif; ?>
<?php if ($resetMode && !$valid && !$done): ?><div class="notice error">This reset link is invalid or has expired.</div><a href="<?= $h(url('auth/forgot-password.php')) ?>">Request a new reset link</a>
<?php elseif (!$done && !$message): ?>
<form method="post" action="<?= $h(url($resetMode ? 'auth/reset-password.php' : 'auth/forgot-password.php')) ?>">
<input type="hidden" name="csrf" value="<?= $h($_SESSION['recovery_csrf']) ?>">
<?php if ($resetMode): ?>
<input type="hidden" name="token" value="<?= $h($token) ?>">
<label for="password">New Password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="72" required aria-describedby="password-help">
<p id="password-help" class="help">At least <?= PASSWORD_MIN_LENGTH ?> characters<?= PASSWORD_REQUIRE_UPPERCASE ? ', an uppercase letter' : '' ?><?= PASSWORD_REQUIRE_NUMBER ? ', a number' : '' ?><?= PASSWORD_REQUIRE_SPECIAL ? ', a special character' : '' ?>.</p>
<label for="confirm">Confirm Password</label><input id="confirm" name="confirm_password" type="password" autocomplete="new-password" maxlength="72" required>
<?php else: ?>
<label for="email">Email</label><input id="email" name="email" type="email" autocomplete="email" maxlength="254" required>
<?php endif; ?>
<button type="submit"><?= $resetMode ? 'Reset Password' : 'Send Reset Link' ?></button>
</form><?php endif; ?>
<a class="back" href="<?= $h(url('auth/login.php')) ?>">Back to Sign In</a>
<footer>SAM 2026 · PTMK, UPNM</footer>
</main></body></html>
