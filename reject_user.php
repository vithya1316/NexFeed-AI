<?php
// reject_user.php — NEXFEEDAI
session_start(); require_once 'db.php';
if (!isset($_SESSION['user_id'])||$_SESSION['role']!=='admin') { redirect('login.php'); }
$id=safe_int($_GET['id']??0);
if (!$id) { redirect('admin_dashboard.php'); }
$stmt=$conn->prepare("UPDATE users SET verification_status='rejected' WHERE id=?");
$stmt->bind_param('i',$id);
if ($stmt->execute()) { flash('success','✖ User rejected.'); } else { flash('error','Could not reject user.'); }
$stmt->close(); redirect('admin_dashboard.php');
