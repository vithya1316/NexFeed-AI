<?php
// index.php
if (defined('IS_INDEX')) return;
define('IS_INDEX', true);
session_start();
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    $m = ['admin'=>'admin_dashboard.php','donor'=>'donor_dashboard.php','ngo'=>'ngo_dashboard.php','volunteer'=>'volunteer_dashboard.php'];
    header('Location: '.($m[$_SESSION['role']] ?? 'login.php'));
} else { header('Location: login.php'); }
exit();
