<?php
// complete_delivery.php — redirect only (logic is in volunteer_upload.php)
session_start();
if(!isset($_SESSION['user_id'])){header('Location: login.php');exit();}
header('Location: volunteer_dashboard.php');exit();
