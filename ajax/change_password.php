<?php
require __DIR__ . '/profile_bootstrap.php';
try {
    foreach (['current_password','new_password','confirm_password'] as $key) if (!is_string($_POST[$key] ?? null)) profileReply(false,'Please complete all fields.',422);
    $attempts = Session::get('password_change_attempts', []);
    $attempts = array_values(array_filter($attempts, static fn($time) => $time > time()-900));
    if (count($attempts) >= 5) profileReply(false, 'Too many attempts. Please try again in 15 minutes.', 429);
    $attempts[] = time(); Session::set('password_change_attempts', $attempts);
    $version = $profileService->changePassword($userId, $_POST['current_password'], $_POST['new_password'], $_POST['confirm_password']);
    Session::set('password_version', $version);
    Session::remove('password_change_attempts');
    Session::regenerate();
    profileReply(true, 'Password updated. Other sessions have been signed out.');
} catch (InvalidArgumentException $e) { profileReply(false, $e->getMessage(), 422); }
catch (Throwable $e) { error_log('[change-password] '.get_class($e)); profileReply(false, 'Unable to update your password. Please try again.', 500); }
