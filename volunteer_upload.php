<?php
// volunteer_upload.php — NEXFEEDAI
// FIXED: Delivery → status='delivered', not 'completed'
//        NGO must confirm receipt before 'completed' is set.
session_start();
require_once 'db.php';
require_once 'email_helper.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'volunteer') { redirect('login.php'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('volunteer_dashboard.php'); }

$vol_id = (int)$_SESSION['user_id'];
$don_id = safe_int($_POST['donation_id'] ?? 0);
$type   = trim($_POST['type'] ?? '');
if (!$don_id || !in_array($type, ['pickup','delivery'])) { flash('error','Invalid request.'); redirect('volunteer_dashboard.php'); }

$chk = $conn->prepare(
    "SELECT d.id, d.status, d.ngo_id, d.donor_id, d.food_name, d.total_people,
            del.id AS del_id
     FROM donations d
     LEFT JOIN deliveries del ON del.donation_id = d.id
     WHERE d.id = ? AND d.volunteer_id = ? LIMIT 1"
);
$chk->bind_param('ii', $don_id, $vol_id); $chk->execute();
$row = $chk->get_result()->fetch_assoc(); $chk->close();
if (!$row) { flash('error','Unauthorised.'); redirect('volunteer_dashboard.php'); }

if ($type === 'pickup') {
    if ($row['status'] !== 'accepted') { flash('error','Cannot mark pickup now.'); redirect('volunteer_dashboard.php'); }
    $fkey = 'pickup_image';
    $next = 'picked';
    $dir  = __DIR__ . '/uploads/pickup/';
} else {
    if ($row['status'] !== 'picked') { flash('error','Upload pickup proof first.'); redirect('volunteer_dashboard.php'); }
    $fkey = 'delivery_image';
    $next = 'delivered';   // ✅ NOT 'completed' — NGO must confirm
    $dir  = __DIR__ . '/uploads/delivery/';
}

// ── Save uploaded photo ───────────────────────────────────────
if (empty($_FILES[$fkey]['tmp_name'])) { flash('error','Select a proof photo.'); redirect('volunteer_dashboard.php'); }
$f    = $_FILES[$fkey];
$mime = mime_content_type($f['tmp_name']);
if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'])) { flash('error','Photo must be JPG/PNG/WebP.'); redirect('volunteer_dashboard.php'); }
if ($f['size'] > 5 * 1024 * 1024) { flash('error','Photo max 5 MB.'); redirect('volunteer_dashboard.php'); }
if (!is_dir($dir)) mkdir($dir, 0755, true);
$ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
$fn  = $type . '_' . uniqid() . '.' . $ext;
if (!move_uploaded_file($f['tmp_name'], $dir . $fn)) { flash('error','Failed to save photo.'); redirect('volunteer_dashboard.php'); }

// ── Update donations table ────────────────────────────────────
$upd = $conn->prepare("UPDATE donations SET status = ? WHERE id = ? AND volunteer_id = ?");
$upd->bind_param('sii', $next, $don_id, $vol_id); $upd->execute(); $upd->close();

// ── Update deliveries table ───────────────────────────────────
if ($row['del_id']) {
    if ($type === 'pickup') {
        $ud = $conn->prepare(
            "UPDATE deliveries SET pickup_image = ?, pickup_time = NOW(), delivery_status = 'picked'
             WHERE donation_id = ?"
        );
    } else {
        $ud = $conn->prepare(
            "UPDATE deliveries SET delivery_image = ?, delivery_time = NOW(), delivery_status = 'pending_confirmation'
             WHERE donation_id = ?"
        );
    }
    $ud->bind_param('si', $fn, $don_id); $ud->execute(); $ud->close();
}

// ── Post-upload notifications ─────────────────────────────────
if ($next === 'delivered') {
    // ✅ Only notify NGO — do NOT restore volunteer, do NOT email donor yet
    // Those happen in ngo_confirm_delivery.php after NGO confirms
    if ($row['ngo_id']) {
        notify_event(
            (int)$row['ngo_id'],
            'delivered',
            "📷 \"{$row['food_name']}\" delivery proof uploaded. Please confirm receipt on your dashboard.",
            $don_id
        );
    }
    flash('success', '📷 Delivery proof uploaded! Waiting for NGO to confirm receipt.');
} else {
    if ($row['ngo_id']) {
        notify_event(
            (int)$row['ngo_id'],
            'picked_up',
            "🚛 \"{$row['food_name']}\" has been picked up and is on the way.",
            $don_id
        );
    }
    flash('success', '🚚 Picked up! Now deliver to the NGO and upload delivery proof.');
}

redirect('volunteer_dashboard.php');
