<?php
ob_start();
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/auth.php';
require __DIR__ . '/../services/AccountProfileService.php';
$db=getDB();
$db->exec('CREATE TEMPORARY TABLE users (id INT PRIMARY KEY, full_name VARCHAR(100), status VARCHAR(20), deleted_at DATETIME NULL, password_hash VARCHAR(255), password_changed_at DATETIME NULL, password_reset_token VARCHAR(64) NULL, password_reset_expires DATETIME NULL, updated_at DATETIME NULL, updated_by INT NULL)');
$st=$db->prepare("INSERT INTO users (id, full_name, status, password_hash, password_reset_token) VALUES (?, ?, 'active', ?, 'old-reset-token')");
$st->execute([1,'First User',password_hash(' OldPassword123! ',PASSWORD_DEFAULT)]);
$st->execute([2,'Other User',password_hash('OtherPassword123!',PASSWORD_DEFAULT)]);
$s=new AccountProfileService($db);
function checkProfile($ok,$label){if(!$ok)throw new RuntimeException($label);echo "PASS: $label\n";}
foreach ([['wrong','NewPassword123!','NewPassword123!'],[' OldPassword123! ','NewPassword123!','mismatch'],[' OldPassword123! ','weak','weak'],[' OldPassword123! ',' OldPassword123! ',' OldPassword123! ']] as $args) {
 $rejected=false;try{$s->changePassword(1,...$args);}catch(InvalidArgumentException $e){$rejected=true;}checkProfile($rejected,'invalid password change rejected');
}
$v=$s->changePassword(1,' OldPassword123! ',' NewPassword123! ',' NewPassword123! ');
$r=$db->query('SELECT * FROM users WHERE id=1')->fetch(PDO::FETCH_ASSOC);
checkProfile(password_verify(' NewPassword123! ',$r['password_hash']),'password spaces preserved');
checkProfile($r['password_reset_token']===null,'existing reset link revoked');
checkProfile($r['password_changed_at']===$v,'session password version returned');
Session::start();Session::set('user_id',1);Session::set('user_role','VIEWER');Session::set('password_version',$v);
checkProfile((new Auth())->isLoggedIn(),'current session can remain signed in');
$v2=$s->changePassword(1,' NewPassword123! ','FinalPassword123!','FinalPassword123!');
checkProfile($v2>$v,'consecutive changes have distinct session versions');
checkProfile(!(new Auth())->isLoggedIn(),'old session revoked');
checkProfile($s->rename(1,"  Nur Aisyah O'Neil  ")==="Nur Aisyah O'Neil",'name trimmed and punctuation retained');
checkProfile($db->query('SELECT full_name FROM users WHERE id=2')->fetchColumn()==='Other User','other account unchanged');
foreach(['   ',str_repeat('a',101),"Bad\nName"] as $name){$rejected=false;try{$s->rename(1,$name);}catch(InvalidArgumentException $e){$rejected=true;}checkProfile($rejected,'invalid name rejected');}
