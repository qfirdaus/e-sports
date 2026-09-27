<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/AccountProfileService.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function profileReply(bool $success, string $message, int $status = 200): never {
    http_response_code($status); echo json_encode(['success'=>$success,'message'=>$message]); exit;
}
Session::start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Allow: POST'); profileReply(false, 'Use POST for this request.', 405); }
if (!getAuth()->isLoggedIn()) profileReply(false, 'Your session has expired. Please sign in again.', 401);
if (!is_string($_POST['csrf'] ?? null) || !hash_equals((string)Session::get('profile_csrf', ''), $_POST['csrf']) || !Session::get('profile_csrf')) profileReply(false, 'Your session expired. Refresh the page and try again.', 403);
$profileService = new AccountProfileService(getDB());
$userId = (int)Session::get('user_id');
