<?php
// toggle_availability.php — NEXFEEDAI
// Also saves volunteer's GPS location when they set themselves available/active
// so location-based job notifications work correctly
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'volunteer') { redirect('login.php'); }
$vol_id  = (int)$_SESSION['user_id'];
$allowed = ['available','busy','weekday_only','weekend_only'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new = trim($_POST['availability'] ?? '');
    if (!in_array($new, $allowed)) { redirect('volunteer_dashboard.php'); }
} else {
    $cur = $conn->query("SELECT availability FROM users WHERE id=$vol_id")->fetch_assoc();
    $new = ($cur['availability'] === 'available') ? 'busy' : 'available';
}

$stmt = $conn->prepare("UPDATE users SET availability=? WHERE id=?");
$stmt->bind_param('si', $new, $vol_id);
$stmt->execute();
$stmt->close();

$labels = ['available'=>'Available','busy'=>'Busy','weekday_only'=>'Weekday Only','weekend_only'=>'Weekend Only'];
flash('success', 'Status: ' . ($labels[$new] ?? $new));
redirect('volunteer_dashboard.php');
