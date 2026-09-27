<?php
// Temporary tables shadow real tables only for this connection; no real emails sent.
require __DIR__ . '/../config/database.php';
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_REQUIRE_UPPERCASE', true);
define('PASSWORD_REQUIRE_NUMBER', true);
define('PASSWORD_REQUIRE_SPECIAL', false);
require __DIR__ . '/../services/PasswordResetService.php';
$db = getDB();
$db->exec('CREATE TEMPORARY TABLE users (id INT PRIMARY KEY, email VARCHAR(255), status VARCHAR(20), deleted_at DATETIME NULL, password_hash VARCHAR(255), password_changed_at DATETIME NULL, password_reset_token VARCHAR(64) NULL, password_reset_expires DATETIME NULL, login_attempts INT DEFAULT 0, locked_until DATETIME NULL)');
$db->exec('CREATE TEMPORARY TABLE password_reset_requests (id INT AUTO_INCREMENT PRIMARY KEY, email_hash CHAR(64), ip_hash CHAR(64), requested_at DATETIME)');
$db->exec("INSERT INTO users (id,email,status) VALUES (1,'reset@example.test','active'), (2,'inactive@example.test','inactive')");
$s = new PasswordResetService($db);
$sent=[];
$send=static function($email,$token) use (&$sent) { $sent[]=$token; };
function check($ok,$label) { if (!$ok) throw new RuntimeException($label); echo "PASS: $label\n"; }
$s->request('missing@example.test','127.0.0.1',$send);
$s->request('inactive@example.test','127.0.0.1',$send);
check(count($sent)===0,'unknown/inactive accounts send no email');
$s->request('reset@example.test','127.0.0.1',$send);
$t=$sent[0];
check($s->valid($t),'valid token');
check($db->query('SELECT password_reset_token FROM users WHERE id=1')->fetchColumn()===hash('sha256',$t),'only token hash stored');
$s->request('reset@example.test','127.0.0.1',$send);
check(!$s->valid($t),'replacement invalidates prior link');
$t=$sent[1];
check(!$s->reset($t,'weak'),'password policy enforced');
check($s->reset($t,'SecureTest123'),'password reset succeeds');
check(!$s->reset($t,'OtherTest123'),'token replay rejected');
check(password_verify('SecureTest123',$db->query('SELECT password_hash FROM users WHERE id=1')->fetchColumn()),'new password hashed');
$s->request('reset@example.test','127.0.0.1',$send);
$t=$sent[2];
$db->exec('UPDATE users SET password_reset_expires=UTC_TIMESTAMP()-INTERVAL 1 SECOND WHERE id=1');
check(!$s->valid($t) && !$s->reset($t,'SecureTest123'),'expired token rejected');
$s->request('reset@example.test','127.0.0.1',$send);
check(count($sent)===3,'per-email rate limit');
check(!$s->valid('not-a-token'),'malformed token rejected');
$db->exec('DELETE FROM password_reset_requests');
try { $s->request('reset@example.test','127.0.0.2',static function(){throw new RuntimeException('Simulated mail failure');}); } catch (RuntimeException $e) {}
check($db->query('SELECT password_reset_token FROM users WHERE id=1')->fetchColumn()===null,'mail failure revokes token');
for($i=0;$i<10;$i++) $s->request('missing'.$i.'@example.test','127.0.0.3',$send);
$s->request('reset@example.test','127.0.0.3',$send);
check(count($sent)===3,'per-IP rate limit across different emails');
