<?php
// ngo_action.php — NEXFEEDAI
// Uses: notify_event() (issue 4), get_nearest_volunteers() (issue 2+3), emails (issue 6)
session_start();
require_once 'db.php';
require_once 'haversine.php';
require_once 'email_helper.php'; // Issue 6

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ngo') { redirect('login.php'); }

$ngo_id = (int)$_SESSION['user_id'];
$don_id = safe_int($_GET['id'] ?? $_POST['donation_id'] ?? 0);
$action = trim($_GET['action'] ?? $_POST['action'] ?? '');
if (!$don_id || !$action) { redirect('ngo_dashboard.php'); }

// ── ACCEPT ────────────────────────────────────────────────────
if ($action === 'accept') {
    $chk = $conn->prepare(
        "SELECT id, food_name, donor_id, total_people, latitude, longitude, address
         FROM donations WHERE id=? AND status='uploaded' LIMIT 1"
    );
    $chk->bind_param('i', $don_id); $chk->execute();
    $don = $chk->get_result()->fetch_assoc(); $chk->close();

    if (!$don) { flash('error', 'This donation is no longer available.'); redirect('ngo_dashboard.php'); }

    // Update status
    $upd = $conn->prepare("UPDATE donations SET status='accepted', ngo_id=?, assigned_at=NOW() WHERE id=? AND status='uploaded'");
    $upd->bind_param('ii', $ngo_id, $don_id); $upd->execute(); $upd->close();

    // Remove pass record if existed
    $dp = $conn->prepare("DELETE FROM donation_passes WHERE donation_id=? AND ngo_id=?");
    if ($dp) { $dp->bind_param('ii', $don_id, $ngo_id); $dp->execute(); $dp->close(); }

    // Create delivery record
    $ex = $conn->prepare('SELECT id FROM deliveries WHERE donation_id=? LIMIT 1');
    $ex->bind_param('i', $don_id); $ex->execute(); $ex->store_result();
    if ($ex->num_rows === 0) {
        $ins = $conn->prepare("INSERT INTO deliveries (donation_id, ngo_id, delivery_status) VALUES (?, ?, 'pending')");
        $ins->bind_param('ii', $don_id, $ngo_id); $ins->execute(); $ins->close();
    }
    $ex->close();

    // Get NGO name for emails
    $ngoInfo = $conn->query("SELECT name FROM users WHERE id=$ngo_id")->fetch_assoc();
    $ngoName = $ngoInfo['name'] ?? 'NGO';

    // ── ISSUE 3 FIX: Notify nearest volunteers using get_nearest_volunteers() ──
    $pickupLat = (float)($don['latitude']  ?? 0);
    $pickupLng = (float)($don['longitude'] ?? 0);
    $fn        = $don['food_name'];
    $tp        = $don['total_people'];
    $addr      = $don['address'] ?? '';

    if ($pickupLat && $pickupLng) {
        // Has GPS — notify nearest N volunteers
        $nearestVols = get_nearest_volunteers($pickupLat, $pickupLng, NOTIFY_TOP_VOLUNTEERS);

        foreach ($nearestVols as $v) {
            if ($v['dist'] !== null) {
                $distText = round($v['dist'], 1) . ' km';
                $msg = ($v['dist'] <= 5)
                    ? "🔴 Close job! Pick up \"{$fn}\" — only {$distText} from you. {$tp} people."
                    : "📦 Pickup job: \"{$fn}\" is {$distText} from you — {$tp} people.";
            } else {
                $msg = "📦 Pickup job available: \"{$fn}\" — {$tp} people. Check your dashboard!";
            }

            // In-app notification (Issue 3+4)
            notify_event($v['id'], 'job_available', $msg, $don_id);

            // Email notification (Issue 6)
            email_volunteer_job($v['id'], $fn, $tp, $addr, $ngoName, $v['dist'] ?? 0);
        }

        $notifiedCount = count($nearestVols);
    } else {
        // No GPS — notify all available volunteers
        $allVols = $conn->query("SELECT id FROM users WHERE role='volunteer' AND verification_status='approved' AND availability='available'");
        $notifiedCount = 0;
        while ($v = $allVols->fetch_assoc()) {
            notify_event((int)$v['id'], 'job_available', "📦 New pickup: \"{$fn}\" — {$tp} people.", $don_id);
            email_volunteer_job((int)$v['id'], $fn, $tp, $addr, $ngoName);
            $notifiedCount++;
        }
    }

    // Notify donor (in-app + email)
    notify_event((int)$don['donor_id'], 'donation_accepted',
        "✅ Your donation \"{$fn}\" was accepted by {$ngoName}!", $don_id);
    email_donor_accepted((int)$don['donor_id'], $fn, $ngoName);

    flash('success', "✅ Accepted! {$notifiedCount} nearest volunteer(s) notified.");
    redirect('ngo_dashboard.php');
}

// ── PASS ──────────────────────────────────────────────────────
if ($action === 'reject') {
    $chk = $conn->prepare("SELECT donor_id, food_name FROM donations WHERE id=? AND status='uploaded' LIMIT 1");
    $chk->bind_param('i', $don_id); $chk->execute();
    $don = $chk->get_result()->fetch_assoc(); $chk->close();

    if ($don) {
        $ins = $conn->prepare("INSERT IGNORE INTO donation_passes (donation_id, ngo_id) VALUES (?, ?)");
        if ($ins) { $ins->bind_param('ii', $don_id, $ngo_id); $ins->execute(); $ins->close(); }

        notify_event((int)$don['donor_id'], 'donation_rejected',
            "ℹ️ An NGO passed on \"{$don['food_name']}\". It's still available to other NGOs.", $don_id);
    }
    flash('success', '✖ Passed. Donation remains visible to other NGOs.');
    redirect('ngo_dashboard.php');
}

// ── MANUAL ASSIGN ─────────────────────────────────────────────
if ($action === 'assign' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $vol_id = safe_int($_POST['volunteer_id'] ?? 0);
    if (!$vol_id) { flash('error', 'Select a volunteer.'); redirect('ngo_dashboard.php'); }

    $chk = $conn->prepare("SELECT id, food_name, total_people, latitude, longitude, address FROM donations WHERE id=? AND ngo_id=? AND status='accepted' AND (volunteer_id IS NULL OR volunteer_id=0) LIMIT 1");
    $chk->bind_param('ii', $don_id, $ngo_id); $chk->execute();
    $don = $chk->get_result()->fetch_assoc(); $chk->close();
    if (!$don) { flash('error', 'Cannot assign.'); redirect('ngo_dashboard.php'); }

    // FIX: removed orphaned $conn->prepare(...)->execute() call that ran without bind_param
    $u1 = $conn->prepare("UPDATE donations SET volunteer_id=? WHERE id=?"); $u1->bind_param('ii',$vol_id,$don_id); $u1->execute(); $u1->close();
    $u2 = $conn->prepare("UPDATE deliveries SET volunteer_id=? WHERE donation_id=?"); $u2->bind_param('ii',$vol_id,$don_id); $u2->execute(); $u2->close();

    // Calculate distance for personalised message
    $vRow   = $conn->query("SELECT latitude, longitude FROM users WHERE id=$vol_id")->fetch_assoc();
    $vLat   = (float)($vRow['latitude']  ?? 0);
    $vLng   = (float)($vRow['longitude'] ?? 0);
    $dLat   = (float)($don['latitude']   ?? 0);
    $dLng   = (float)($don['longitude']  ?? 0);
    $distMsg = '';
    if ($vLat && $vLng && $dLat && $dLng) {
        $d = haversine($dLat, $dLng, $vLat, $vLng);
        $distMsg = ' Pickup is ' . $d . ' km from you.';
    }

    $ngoInfo = $conn->query("SELECT name FROM users WHERE id=$ngo_id")->fetch_assoc();
    $ngoName = $ngoInfo['name'] ?? 'NGO';

    notify_event($vol_id, 'volunteer_assigned',
        "🔔 Assigned: \"{$don['food_name']}\" — {$don['total_people']} people.{$distMsg}", $don_id);
    email_volunteer_job($vol_id, $don['food_name'], $don['total_people'], $don['address'] ?? '', $ngoName, $dLat && $dLng && $vLat && $vLng ? haversine($dLat, $dLng, $vLat, $vLng) : 0);

    flash('success', '✅ Volunteer assigned and notified by email.');
    redirect('ngo_dashboard.php');
}

redirect('ngo_dashboard.php');
