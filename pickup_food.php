<?php
// pickup_food.php — NEXFEEDAI
session_start();
require_once 'db.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'volunteer') { redirect('login.php'); }

$vol_id = (int)$_SESSION['user_id'];
$don_id = safe_int($_GET['id'] ?? 0);
$action = trim($_GET['action'] ?? '');
if (!$don_id || !in_array($action, ['accept', 'reject'])) { redirect('volunteer_dashboard.php'); }

// ── ACCEPT ────────────────────────────────────────────────────
if ($action === 'accept') {
    $chk = $conn->prepare(
        "SELECT id, food_name, ngo_id, donor_id
         FROM donations
         WHERE id=? AND status='accepted'
         AND (volunteer_id IS NULL OR volunteer_id=0) LIMIT 1"
    );
    $chk->bind_param('i', $don_id);
    $chk->execute();
    $don = $chk->get_result()->fetch_assoc();
    $chk->close();

    if (!$don) {
        flash('error', '⚠️ This job was just taken by another volunteer.');
        redirect('volunteer_dashboard.php');
    }

    $upd = $conn->prepare("UPDATE donations SET volunteer_id=? WHERE id=? AND status='accepted'");
    $upd->bind_param('ii', $vol_id, $don_id);
    $upd->execute();
    $upd->close();

    $upd2 = $conn->prepare("UPDATE deliveries SET volunteer_id=?, pickup_time=NOW() WHERE donation_id=?");
    $upd2->bind_param('ii', $vol_id, $don_id);
    $upd2->execute();
    $upd2->close();

    // Remove this volunteer's pass record if they had declined it before
    $del = $conn->prepare("DELETE FROM volunteer_passes WHERE donation_id=? AND volunteer_id=?");
    if ($del) { $del->bind_param('ii', $don_id, $vol_id); $del->execute(); $del->close(); }

    if ($don['ngo_id']) {
        notify((int)$don['ngo_id'],
            "🚚 A volunteer accepted pickup for \"{$don['food_name']}\".",
            'info', $don_id, 'normal'
        );
    }

    flash('success', '✅ Job accepted! Head to the pickup address now.');
    redirect('volunteer_dashboard.php');
}

// ── DECLINE ───────────────────────────────────────────────────
// Record in volunteer_passes so this job disappears from THIS
// volunteer's available list only. Other volunteers still see it.
if ($action === 'reject') {
    // Check the job exists and is still open
    $chk = $conn->prepare(
        "SELECT id FROM donations
         WHERE id=? AND status='accepted'
         AND (volunteer_id IS NULL OR volunteer_id=0) LIMIT 1"
    );
    $chk->bind_param('i', $don_id);
    $chk->execute();
    $chk->store_result();
    $exists = $chk->num_rows > 0;
    $chk->close();

    if ($exists) {
        // INSERT IGNORE handles duplicate declines safely
        $ins = $conn->prepare(
            "INSERT IGNORE INTO volunteer_passes (donation_id, volunteer_id) VALUES (?, ?)"
        );
        if ($ins) {
            $ins->bind_param('ii', $don_id, $vol_id);
            $ins->execute();
            $ins->close();
        }
    }

    // Show as blue info message (not green success)
    $_SESSION['vol_info'] = '↩ Job declined. It has been removed from your list.';
    redirect('volunteer_dashboard.php');
}
