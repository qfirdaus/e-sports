<?php
require __DIR__ . '/profile_bootstrap.php';
try {
    if (!is_string($_POST['full_name'] ?? null)) profileReply(false, 'Enter your full name.', 422);
    $name = $profileService->rename($userId, $_POST['full_name']);
    Session::set('user_name', $name);
    profileReply(true, 'Your name has been updated.');
} catch (InvalidArgumentException $e) { profileReply(false, $e->getMessage(), 422); }
catch (Throwable $e) { error_log('[update-profile] '.get_class($e)); profileReply(false, 'Unable to update your name. Please try again.', 500); }
