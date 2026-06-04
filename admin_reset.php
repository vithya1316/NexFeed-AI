<?php
// admin_reset.php — Run ONCE then DELETE this file
require_once 'db.php';
$email='admin@nexfeedai.com';$password='admin123';
$hash=password_hash($password,PASSWORD_BCRYPT);
$chk=$conn->query("SELECT id FROM users WHERE email='$email' LIMIT 1");
if($chk->num_rows>0){
  $stmt=$conn->prepare("UPDATE users SET password=?,verification_status='approved',role='admin' WHERE email=?");
  $stmt->bind_param('ss',$hash,$email);$stmt->execute();$stmt->close();
  echo "<h2 style='font-family:sans-serif;color:green;padding:30px;'>✅ Password reset!<br><br>Email: <b>admin@nexfeedai.com</b><br>Password: <b>admin123</b><br><br><a href='login.php'>→ Login</a><br><br><span style='color:red;'>⚠️ DELETE this file now!</span></h2>";
}else{
  $stmt=$conn->prepare("INSERT INTO users (name,email,password,phone,role,verification_status,availability) VALUES ('Admin',?,?,'0000000000','admin','approved','available')");
  $stmt->bind_param('ss',$email,$hash);$stmt->execute();$stmt->close();
  echo "<h2 style='font-family:sans-serif;color:green;padding:30px;'>✅ Admin created!<br><br>Email: <b>admin@nexfeedai.com</b><br>Password: <b>admin123</b><br><br><a href='login.php'>→ Login</a><br><br><span style='color:red;'>⚠️ DELETE this file now!</span></h2>";
}
?>
