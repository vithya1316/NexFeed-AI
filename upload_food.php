<?php
// upload_food.php — NEXFEEDAI
// FIXED:
//   1. AI integration (ai_helper.php → Flask → store result)
//   2. Expiry validation (can't upload already-expired food)
//   3. Email notifications to nearest NGOs
//   4. AI result stored in donations table

session_start();
require_once 'db.php';
require_once 'haversine.php';
require_once 'email_helper.php';
require_once 'ai_helper.php';    // ← AI integration

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'donor') { redirect('login.php'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('donor_dashboard.php'); }

$uid  = (int)$_SESSION['user_id'];
$fn   = trim($_POST['food_name']        ?? '');
$cat  = trim($_POST['food_category']    ?? 'other');
$tp   = safe_int($_POST['total_people'] ?? 0);
$desc = trim($_POST['description']      ?? '');
$exp  = trim($_POST['expiry_time']      ?? '');
$addr = trim($_POST['address']          ?? '');
$lat  = ($_POST['latitude']  ?? '') !== '' ? (float)$_POST['latitude']  : null;
$lng  = ($_POST['longitude'] ?? '') !== '' ? (float)$_POST['longitude'] : null;

if (!$fn)    { flash('error', 'Food name required.');   redirect('donor_dashboard.php'); }
if ($tp < 1) { flash('error', 'Serves must be ≥ 1.');   redirect('donor_dashboard.php'); }
if (!$exp)   { flash('error', 'Expiry time required.'); redirect('donor_dashboard.php'); }
if (!$addr && !$lat) { flash('error', 'Enter address or use GPS.'); redirect('donor_dashboard.php'); }

// ✅ FIX: Expiry validation — reject if already expired
$expiry_ts = strtotime($exp);
if ($expiry_ts === false || $expiry_ts <= time()) {
    flash('error', '⚠️ Expiry time is in the past. Please enter a valid future expiry time.');
    redirect('donor_dashboard.php');
}

$allowed = ['veg','non-veg','raw','packaged','bakery','other'];
if (!in_array($cat, $allowed)) $cat = 'other';

// ── Save food image ───────────────────────────────────────────
$img     = '';
$imgPath = '';    // full disk path for AI check

if (!empty($_FILES['food_image']['tmp_name'])) {
    $f = $_FILES['food_image'];
    $mime = mime_content_type($f['tmp_name']);
    if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'])) {
        flash('error', 'Image must be JPG/PNG/WebP.'); redirect('donor_dashboard.php');
    }
    if ($f['size'] > 5 * 1024 * 1024) { flash('error', 'Image max 5 MB.'); redirect('donor_dashboard.php'); }
    $dir = __DIR__ . '/uploads/food/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $ext     = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $imgname = 'food_' . uniqid() . '.' . $ext;
    if (move_uploaded_file($f['tmp_name'], $dir . $imgname)) {
        $img     = $imgname;
        $imgPath = $dir . $imgname;
    }
}

// ── AI Check ─────────────────────────────────────────────────
// Run BEFORE insert so we can store result in the same row.
// If no image → skip AI. If AI fails → mark 'error', do NOT block upload.
$ai_prediction  = 'pending';
$ai_confidence  = 0.0;
$ai_checked     = 0;
$ai_warning     = '';   // shown to donor if not_recommended

if ($imgPath) {
    $ai = ai_predict_food($imgPath);

    if ($ai['error']) {
        // API down or image error — store 'error', continue normally
        $ai_prediction = 'error';
        $ai_checked    = 0;
        error_log("NexFeedAI AI check failed for donor $uid: " . $ai['error']);
    } else {
        $ai_prediction = $ai['prediction'];    // 'likely_safe' | 'not_recommended'
        $ai_confidence = $ai['confidence'];
        $ai_checked    = 1;

        if ($ai['prediction'] === 'not_recommended') {
            // Warn donor — but do NOT block the upload
            // NGO will see the red badge and decide whether to accept
            $ai_warning = "⚠️ AI flagged this food as possibly not suitable for donation ({$ai['confidence']}% confidence). It has been uploaded but NGOs will see this warning before accepting.";
        }
    }
}

// ── Insert donation (with AI columns) ────────────────────────
// FIX: corrected bind_param type string — removed erroneous space
// Params: s s i s s s d d s i  s  d  i
//         fn cat tp desc exp addr lat lng img uid  ai_pred ai_conf ai_chk
$stmt = $conn->prepare(
    'INSERT INTO donations
     (food_name, food_category, total_people, description,
      expiry_time, address, latitude, longitude, image_path, donor_id,
      ai_prediction, ai_confidence, ai_checked)
     VALUES (?,?,?,?,?,?,?,?,?,?, ?,?,?)'
);
$stmt->bind_param(
    'ssisssddsisdi',
    $fn, $cat, $tp, $desc, $exp, $addr, $lat, $lng, $img, $uid,
    $ai_prediction, $ai_confidence, $ai_checked
);

// Note: if ai columns don't exist yet, run this SQL:
// ALTER TABLE donations
//   ADD COLUMN ai_prediction ENUM('likely_safe','not_recommended','pending','error') DEFAULT 'pending',
//   ADD COLUMN ai_confidence DECIMAL(5,2) DEFAULT 0.00,
//   ADD COLUMN ai_checked TINYINT(1) DEFAULT 0;

if (!$stmt->execute()) {
    // Fallback: try without AI columns if schema not updated yet
    $stmt->close();
    $stmt2 = $conn->prepare(
        'INSERT INTO donations
         (food_name, food_category, total_people, description,
          expiry_time, address, latitude, longitude, image_path, donor_id)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt2->bind_param('ssisssddsi', $fn, $cat, $tp, $desc, $exp, $addr, $lat, $lng, $img, $uid);
    if (!$stmt2->execute()) {
        flash('error', 'Failed to upload. Please try again.'); redirect('donor_dashboard.php');
    }
    $stmt2->close();
}

$don_id    = $conn->insert_id;
if (isset($stmt) && $stmt instanceof mysqli_stmt) $stmt->close();
$donorName = htmlspecialchars($_SESSION['name']);

// ── Notify nearest NGOs (in-app + email) ─────────────────────
$aiTag = '';

if ($ai_checked) {
    if ($ai_prediction === 'likely_safe') {
        $aiTag = ' [AI: ✅ Safe]';
    } elseif ($ai_prediction === 'not_recommended') {
        $aiTag = ' [AI: ⚠️ Review]';
    }
}
if ($lat && $lng) {
    $nearestNGOs = get_nearest_ngos($lat, $lng, NOTIFY_TOP_NGOS);

    foreach ($nearestNGOs as $n) {
        $distText = ($n['dist'] !== null) ? round($n['dist'], 1) . ' km' : '';

        if ($n['dist'] !== null) {
            $msg = $n['dist'] <= 5
                ? "🔴 Very close! \"{$fn}\" ({$distText}){$aiTag} — {$tp} people."
                : "🍽️ Donation nearby: \"{$fn}\" ({$distText}){$aiTag} — {$tp} people.";
        } else {
            $msg = "🍽️ New donation: \"{$fn}\" by {$donorName}{$aiTag} — {$tp} people.";
        }

        notify_event($n['id'], 'donation_uploaded', $msg, $don_id);

        if (!empty($n['email'])) {
            send_donation_notification(
                $n['email'],
                $n['name'] ?? 'NGO',
                $fn, $donorName,
                $addr ?: 'GPS coordinates provided',
                $exp, $tp, $distText
            );
        }
    }
    $notifiedCount = count($nearestNGOs);
    $successMsg = "✅ Donation uploaded! {$notifiedCount} nearest NGO(s) notified.";
} else {
    $allNGOs = $conn->query(
        "SELECT id, email, name FROM users WHERE role='ngo' AND verification_status='approved'"
    );
    $count = 0;
    while ($n = $allNGOs->fetch_assoc()) {
        notify_event((int)$n['id'], 'donation_uploaded',
            "🍽️ New donation: \"{$fn}\" by {$donorName}{$aiTag} — {$tp} people.", $don_id);
        if (!empty($n['email'])) {
            send_donation_notification($n['email'], $n['name'] ?? 'NGO',
                $fn, $donorName, $addr ?: 'Address not provided', $exp, $tp, '');
        }
        $count++;
    }
    $successMsg = "✅ Donation uploaded! {$count} NGO(s) notified.";
}

// Show AI warning if food flagged
if ($ai_warning) {
    flash('ai_warning', $ai_warning);
}

flash('success', $successMsg);
redirect('donor_dashboard.php');
