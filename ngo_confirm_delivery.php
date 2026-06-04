<?php
// ngo_confirm_delivery.php — NEXFEEDAI
// NGO confirms or disputes a delivery made by a volunteer.
// Called via POST from ngo_dashboard.php
//
// Expected POST fields:
//   donation_id  — int
//   action       — 'received' | 'not_received'
//   note         — optional text (reason if not_received)

session_start();
require_once 'db.php';
require_once 'email_helper.php';

// ── Auth ──────────────────────────────────────────────────────
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ngo') {
    redirect('login.php');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('ngo_dashboard.php');
}

$ngo_id = (int)$_SESSION['user_id'];
$don_id = safe_int($_POST['donation_id'] ?? 0);
$action = trim($_POST['action'] ?? '');
$note   = trim($_POST['note'] ?? '');

if (!$don_id || !in_array($action, ['received', 'not_received'])) {
    flash('error', 'Invalid confirmation request.');
    redirect('ngo_dashboard.php');
}

// ── Verify this donation belongs to this NGO and is in 'delivered' state ──
$stmt = $conn->prepare(
    "SELECT d.id, d.food_name, d.total_people, d.donor_id, d.volunteer_id,
            u_donor.name     AS donor_name,     u_donor.email     AS donor_email,
            u_vol.name       AS volunteer_name,  u_vol.id          AS vol_id,
            u_vol.availability AS vol_avail
     FROM donations d
     LEFT JOIN users u_donor ON u_donor.id = d.donor_id
     LEFT JOIN users u_vol   ON u_vol.id   = d.volunteer_id
     WHERE d.id = ? AND d.ngo_id = ? AND d.status = 'delivered'
     LIMIT 1"
);
$stmt->bind_param('ii', $don_id, $ngo_id);
$stmt->execute();
$don = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$don) {
    flash('error', 'Donation not found, already confirmed, or not in delivered state.');
    redirect('ngo_dashboard.php');
}

// ── Get NGO name ──────────────────────────────────────────────
$ngoInfo = $conn->query("SELECT name FROM users WHERE id = $ngo_id")->fetch_assoc();
$ngoName = $ngoInfo['name'] ?? 'NGO';

// ════════════════════════════════════════════════════════════════
// CASE A: NGO confirms receipt → mark completed
// ════════════════════════════════════════════════════════════════
if ($action === 'received') {

    // 1. Mark donation as completed
    $upd = $conn->prepare("UPDATE donations SET status = 'completed' WHERE id = ? AND ngo_id = ?");
    $upd->bind_param('ii', $don_id, $ngo_id);
    $upd->execute();
    $upd->close();

    // 2. Update delivery record
    $udel = $conn->prepare(
        "UPDATE deliveries SET delivery_status = 'confirmed', confirmed_at = NOW()
         WHERE donation_id = ?"
    );
    $udel->bind_param('i', $don_id);
    $udel->execute();
    $udel->close();

    // 3. Restore volunteer availability
    if ($don['vol_id']) {
        $restore = ($don['vol_avail'] === 'busy') ? 'available' : $don['vol_avail'];
        $ra = $conn->prepare("UPDATE users SET availability = ? WHERE id = ?");
        $ra->bind_param('si', $restore, $don['vol_id']);
        $ra->execute();
        $ra->close();

        // Notify volunteer
        notify_event(
            $don['vol_id'],
            'delivered',
            "🎉 \"{$don['food_name']}\" confirmed received by {$ngoName}. Great job!",
            $don_id
        );
    }

    // 4. Notify donor (in-app + email)
    if ($don['donor_id']) {
        notify_event(
            (int)$don['donor_id'],
            'delivered',
            "🎉 Your donation \"{$don['food_name']}\" was successfully delivered and confirmed by {$ngoName}!",
            $don_id
        );

        // Email donor
        if (!empty($don['donor_email'])) {
            send_pickup_notification(
                $don['donor_email'],
                $don['donor_name'] ?? 'Donor',
                $don['food_name'],
                $ngoName
            );
        }
    }

    flash('success', "✅ Delivery confirmed! Donation marked as completed.");

// ════════════════════════════════════════════════════════════════
// CASE B: NGO disputes receipt → mark as issue
// ════════════════════════════════════════════════════════════════
} else { // not_received

    // 1. Revert status to 'picked' so the flow can be re-attempted
    // Or use a custom 'issue' status if you add it to the ENUM
    // For now we revert to 'picked' so volunteer is notified
    $upd = $conn->prepare("UPDATE donations SET status = 'picked', volunteer_id = volunteer_id WHERE id = ?");
    $upd->bind_param('i', $don_id);
    $upd->execute();
    $upd->close();

    // 2. Update delivery record — mark disputed
    $noteEsc = $conn->real_escape_string($note);
    $conn->query(
        "UPDATE deliveries SET delivery_status = 'disputed', dispute_note = '$noteEsc'
         WHERE donation_id = $don_id"
    );

    // 3. Notify volunteer about the dispute
    if ($don['vol_id']) {
        $disputeMsg = $note
            ? "⚠️ \"{$don['food_name']}\" — NGO reported non-receipt. Reason: {$note}. Please follow up."
            : "⚠️ \"{$don['food_name']}\" — NGO reported they did not receive this delivery. Please contact them.";
        notify_event($don['vol_id'], 'general', $disputeMsg, $don_id);
    }

    // 4. Notify admin (via in-app notification to admin account)
    $adminRes = $conn->query("SELECT id FROM users WHERE role='admin' LIMIT 1");
    if ($adminRes && $adminRow = $adminRes->fetch_assoc()) {
        notify_event(
            (int)$adminRow['id'],
            'general',
            "🚨 Delivery dispute: \"{$don['food_name']}\" (Donation #{$don_id}). NGO says not received. Note: " . ($note ?: 'none'),
            $don_id
        );
    }

    flash('error', "⚠️ Dispute reported. Volunteer has been notified. Admin alerted.");
}

redirect('ngo_dashboard.php');
