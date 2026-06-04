<?php
// login_process.php — NEXFEEDAI
session_start();
require_once 'db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('login.php'); }

$email    = strtolower(trim($_POST['email'] ?? ''));
$password = trim($_POST['password'] ?? '');
if (!$email || !$password)                       { flash('login_error','Please enter email and password.'); redirect('login.php'); }
if (!filter_var($email, FILTER_VALIDATE_EMAIL))  { flash('login_error','Invalid email format.'); redirect('login.php'); }

$stmt = $conn->prepare('SELECT id,name,email,password,role,verification_status FROM users WHERE email=? LIMIT 1');
$stmt->bind_param('s',$email); $stmt->execute();
$user = $stmt->get_result()->fetch_assoc(); $stmt->close();

if (!$user) { flash('login_error','No account found with that email.'); redirect('login.php'); }

$match = false;
if (password_verify($password,$user['password'])) { $match=true; }
elseif (md5($password)===$user['password']) {
    $h=$conn->prepare('UPDATE users SET password=? WHERE id=?');
    $h->bind_param('si',password_hash($password,PASSWORD_BCRYPT),$user['id']); $h->execute(); $h->close();
    $match=true;
}
if (!$match) { flash('login_error','Incorrect password.'); redirect('login.php'); }

if ($user['role']!=='admin' && $user['verification_status']!=='approved') {
    $msgs=['pending'=>'Account pending admin approval.','rejected'=>'Account rejected. Contact support.'];
    flash('login_error',$msgs[$user['verification_status']]??'Account not active.'); redirect('login.php');
}

// Destroy old session completely to prevent cross-role contamination
$_SESSION=[];
if (ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']); }
session_destroy(); session_start(); session_regenerate_id(true);

$_SESSION['user_id']    = (int)$user['id'];
$_SESSION['name']       = $user['name'];
$_SESSION['email']      = $user['email'];
$_SESSION['role']       = $user['role'];
$_SESSION['login_time'] = time();

$m=['admin'=>'admin_dashboard.php','donor'=>'donor_dashboard.php','ngo'=>'ngo_dashboard.php','volunteer'=>'volunteer_dashboard.php'];
redirect($m[$user['role']] ?? 'login.php');
