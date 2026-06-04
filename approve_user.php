<?php
// approve_user.php — NEXFEEDAI (Issue 6: sends email on approval)
session_start();
require_once 'db.php';
require_once 'email_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { redirect('login.php'); }
$id = safe_int($_GET['id'] ?? 0);
if (!$id) { redirect('admin_dashboard.php'); }

$stmt = $conn->prepare("UPDATE users SET verification_status='approved' WHERE id=?");
$stmt->bind_param('i', $id);
if ($stmt->execute() && $stmt->affected_rows > 0) {
    // In-app notification
    notify_event($id, 'account_approved',
        '🎊 Your account has been approved! You can now log in to NEXFEEDAI.', 0);
    // Email notification
    email_account_approved($id);
    flash('success', '✅ User approved and email sent.');
} else {
    flash('error', 'Could not approve user.');
}
$stmt->close();
redirect('admin_dashboard.php');
