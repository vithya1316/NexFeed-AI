<?php
// volunteer_dashboard.php — NEXFEEDAI
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'volunteer') {
    session_destroy(); header('Location: login.php'); exit();
}
require_once 'db.php';
require_once 'haversine.php';

$uid     = (int)$_SESSION['user_id'];
$success = get_flash('success');
$error   = get_flash('error');
$info    = $_SESSION['vol_info'] ?? '';
unset($_SESSION['vol_info']);
$unread  = unread_count($uid);

$volInfo = $conn->query("SELECT * FROM users WHERE id=$uid")->fetch_assoc();
$avail   = $volInfo['availability'] ?? 'available';

$locRes  = $conn->query("SELECT latitude, longitude FROM users WHERE id=$uid")->fetch_assoc();
$vLat    = (float)($locRes['latitude']  ?? 0);
$vLng    = (float)($locRes['longitude'] ?? 0);

// Available jobs — exclude declined ones
$jobRes = $conn->query(
    "SELECT d.*, u.name AS donor_name, u.phone AS donor_phone,
            n.name AS ngo_name, n.phone AS ngo_phone
     FROM donations d
     JOIN users u ON d.donor_id = u.id
     LEFT JOIN users n ON d.ngo_id = n.id
     LEFT JOIN volunteer_passes vp
           ON vp.donation_id = d.id AND vp.volunteer_id = $uid
     WHERE d.status = 'accepted'
       AND (d.volunteer_id IS NULL OR d.volunteer_id = 0)
       AND vp.id IS NULL
     ORDER BY d.id DESC"
);
if (!$jobRes) {
    $jobRes = $conn->query(
        "SELECT d.*, u.name AS donor_name, u.phone AS donor_phone,
                n.name AS ngo_name, n.phone AS ngo_phone
         FROM donations d
         JOIN users u ON d.donor_id = u.id
         LEFT JOIN users n ON d.ngo_id = n.id
         WHERE d.status = 'accepted'
           AND (d.volunteer_id IS NULL OR d.volunteer_id = 0)
         ORDER BY d.id DESC"
    );
}
$jobRows = [];
while ($r = $jobRes->fetch_assoc()) $jobRows[] = $r;
if ($vLat && $vLng) $jobRows = sort_by_distance($jobRows, $vLat, $vLng);

// My active deliveries — now includes 'delivered' status (waiting NGO confirm)
$myRes = $conn->query(
    "SELECT d.*, u.name AS donor_name,
            n.name AS ngo_name, n.phone AS ngo_phone,
            del.pickup_image, del.delivery_image
     FROM donations d
     JOIN users u ON d.donor_id = u.id
     LEFT JOIN users n ON d.ngo_id = n.id
     LEFT JOIN deliveries del ON del.donation_id = d.id AND del.volunteer_id = $uid
     WHERE d.volunteer_id = $uid
       AND d.status IN ('accepted','picked','delivered')
     ORDER BY d.id DESC"
);
$myRows = [];
while ($r = $myRes->fetch_assoc()) $myRows[] = $r;

$doneCount = (int)$conn->query(
    "SELECT COUNT(*) c FROM donations WHERE volunteer_id=$uid AND status='completed'"
)->fetch_assoc()['c'];

$availCfg = [
    'available'    => ['dot'=>'#66BB6A','color'=>'#2E7D32','label'=>'Available'],
    'busy'         => ['dot'=>'#EF5350','color'=>'#C62828','label'=>'Busy'],
    'weekday_only' => ['dot'=>'#42A5F5','color'=>'#1565C0','label'=>'Weekday Only'],
    'weekend_only' => ['dot'=>'#AB47BC','color'=>'#6A1B9A','label'=>'Weekend Only'],
];
$ac = $availCfg[$avail] ?? $availCfg['available'];

function badge(string $s): array {
    return [
        'accepted'  => ['cls'=>'b-accepted', 'icon'=>'📦', 'txt'=>'Ready to Pick Up'],
        'picked'    => ['cls'=>'b-picked',   'icon'=>'🚛', 'txt'=>'In Transit'],
        'delivered' => ['cls'=>'b-delivered','icon'=>'⏳', 'txt'=>'Waiting NGO Confirmation'],
    ][$s] ?? ['cls'=>'b-uploaded','icon'=>'❓','txt'=>ucfirst($s)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Volunteer Dashboard — NEXFEEDAI</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    .hero-banner{background:linear-gradient(135deg,var(--accent),var(--accent-dk));border-radius:var(--radius-md);padding:20px 26px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;position:relative;overflow:hidden;animation:fadeUp .4s ease}
    .hero-banner::before{content:'';position:absolute;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.07);right:-60px;top:-60px;pointer-events:none}
    .hero-title{font-family:'DM Serif Display',serif;font-size:1.4rem;color:#fff;position:relative;z-index:1}
    .hero-quote{font-size:.82rem;color:rgba(255,255,255,.8);margin-top:3px;position:relative;z-index:1;font-style:italic}

    /* File upload styles */
    .proof-upload-label{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;padding:12px;border:2px dashed #C8D4E8;border-radius:8px;cursor:pointer;background:var(--white);transition:var(--tr);text-align:center;user-select:none;}
    .proof-upload-label:hover{border-color:var(--accent);background:var(--accent-bg);}
    .proof-upload-label input[type=file]{display:none;}
    .proof-upload-preview{width:100%;max-height:80px;object-fit:cover;border-radius:6px;display:none;margin-bottom:4px;}
    .proof-upload-txt{font-size:.76rem;font-weight:500;color:var(--txt-mid);}

    /* Camera widget styles */
    .camera-container{display:none;flex-direction:column;align-items:center;gap:8px;margin-top:8px;width:100%;}
    .camera-video{width:100%;border-radius:8px;border:2px solid var(--accent);background:#000;}
    .camera-captured{width:100%;max-height:120px;object-fit:cover;border-radius:8px;display:none;margin-top:6px;border:2px solid #2E7D32;}
    .btn-camera{width:100%;margin-top:6px;background:#37474F;color:#fff;border:none;padding:9px;border-radius:7px;font-size:.82rem;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:5px;}
    .btn-camera:hover{background:#263238;}
    .btn-capture{background:#2E7D32;color:#fff;border:none;padding:9px 20px;border-radius:7px;font-weight:600;cursor:pointer;font-size:.85rem;}
    .btn-capture:hover{background:#1B5E20;}
    .btn-cam-cancel{background:none;border:1px solid #ccc;padding:8px 16px;border-radius:7px;cursor:pointer;font-size:.83rem;color:#666;}
    .camera-status{font-size:.75rem;margin-top:3px;min-height:16px;}

    /* Delivered waiting badge */
    .b-delivered{background:#FFF8E1;color:#E65100;border-radius:99px;padding:2px 9px;font-size:.72rem;font-weight:700;border:1px solid #FFE082;}
    .delivered-wait-box{background:#FFF8E1;border:1px solid #FFE082;border-radius:8px;padding:11px 13px;font-size:.81rem;color:#E65100;margin-top:8px;line-height:1.5;}
  </style>
</head>
<body data-page="volunteer">

<nav class="navbar">
  <a href="volunteer_dashboard.php" class="nav-brand">
    <div class="nav-logo">🍽️</div>
    <span class="nav-title">NEXFEED<span>AI</span></span>
  </a>
  <div class="nav-right">
    <a href="notifications.php" class="notif-bell">
      🔔<?php if ($unread): ?><span class="notif-count"><?= $unread ?></span><?php endif; ?>
    </a>
    <span class="nav-username">🚚 <?= htmlspecialchars($_SESSION['name']) ?></span>
    <span class="role-badge">Volunteer</span>
    <a href="history.php" class="btn btn-ghost btn-sm">📜 History</a>
    <a href="logout.php"  class="btn btn-ghost btn-sm">🚪 Logout</a>
  </div>
</nav>

<div class="dash-wrap"><div class="dash-main">

  <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($info):    ?><div class="alert alert-info">↩ <?= htmlspecialchars($info) ?></div><?php endif; ?>

  <div class="hero-banner">
    <div>
      <div class="hero-title">Volunteer Hub 🚚</div>
      <div class="hero-quote">"Your wheels carry hope to those who have none."</div>
    </div>
  </div>

  <!-- AVAILABILITY BAR -->
  <div class="avail-bar">
    <div style="display:flex;align-items:center;gap:12px;">
      <div class="avail-dot" style="background:<?= $ac['dot'] ?>;box-shadow:0 0 0 3px <?= $ac['dot'] ?>33;"></div>
      <div>
        <div style="font-size:.9rem;font-weight:700;color:<?= $ac['color'] ?>;"><?= $ac['label'] ?></div>
        <div style="font-size:.73rem;color:var(--txt-light);">Your current availability</div>
      </div>
    </div>
    <form action="toggle_availability.php" method="POST" style="display:flex;gap:6px;flex-wrap:wrap;">
      <?php foreach ($availCfg as $key => $cfg): ?>
      <button type="submit" name="availability" value="<?= $key ?>"
              class="btn btn-sm <?= $avail === $key ? 'btn-primary' : 'btn-ghost' ?>">
        <?= $cfg['label'] ?>
      </button>
      <?php endforeach; ?>
    </form>
  </div>

  <!-- STATS -->
  <div class="stats-row">
    <div class="stat-card">
      <div class="stat-icon ic-orange">📢</div>
      <div><div class="stat-num"><?= count($jobRows) ?></div><div class="stat-label">Available Jobs</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon ic-blue">🚛</div>
      <div><div class="stat-num"><?= count($myRows) ?></div><div class="stat-label">Active</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon ic-green">🏆</div>
      <div><div class="stat-num"><?= $doneCount ?></div><div class="stat-label">Completed</div></div>
    </div>
  </div>

  <!-- TABS -->
  <div class="tab-bar">
    <button class="tab-btn active" onclick="sw(event,'t-jobs')">
      📢 Available Jobs (<?= count($jobRows) ?>)
    </button>
    <button class="tab-btn" onclick="sw(event,'t-mine')">
      🚛 My Active (<?= count($myRows) ?>)
    </button>
  </div>

  <!-- TAB: Available Jobs -->
  <div class="tab-panel active" id="t-jobs">
    <div class="sec-title">📢 Pickup Jobs
      <?php if ($vLat && $vLng): ?>
      <span style="font-size:.73rem;font-weight:400;color:var(--txt-light);"> — nearest first</span>
      <?php endif; ?>
    </div>

    <?php if ($avail === 'busy'): ?>
    <div class="alert alert-warning">
      ⚠️ You are set as <strong>Busy</strong>. Change your status above to accept jobs.
    </div>
    <?php endif; ?>

    <?php if (empty($jobRows)): ?>
    <div class="empty-state">
      <div class="empty-icon">📭</div>
      <div class="empty-title">No jobs right now</div>
      <div class="empty-text">Check back soon — NGOs are accepting donations.</div>
    </div>
    <?php else: ?>
    <div class="cards-grid">
      <?php foreach ($jobRows as $d): ?>
      <div class="donation-card">
        <?php if (!empty($d['image_path'])): ?>
        <div class="card-img-wrap">
          <img src="uploads/food/<?= htmlspecialchars(basename($d['image_path'])) ?>"
               alt="<?= htmlspecialchars($d['food_name']) ?>" loading="lazy"/>
          <span class="card-badge b-accepted">📦 Ready</span>
        </div>
        <?php else: ?>
        <div class="card-img-ph">🍱<small>No Photo</small></div>
        <?php endif; ?>
        <div class="card-body">
          <div class="card-name"><?= htmlspecialchars($d['food_name']) ?></div>
          <div class="card-chips">
            <span class="chip">👥 <?= (int)$d['total_people'] ?> people</span>
            <span class="chip">🏷️ <?= htmlspecialchars($d['food_category']) ?></span>
            <?php if (!empty($d['distance_km']) && $d['distance_km'] < 9999): ?>
            <span class="chip chip-dist">📍 <?= km_label($d['distance_km']) ?></span>
            <?php endif; ?>
          </div>
          <div class="card-info">
            <strong>📦 Pick up:</strong><br>
            🙋 <?= htmlspecialchars($d['donor_name']) ?> · 📞 <?= htmlspecialchars($d['donor_phone']) ?><br>
            📍 <?= htmlspecialchars($d['address'] ?? 'N/A') ?>
            <?php if (!empty($d['latitude']) && !empty($d['longitude'])): ?>
            <br><a href="https://www.openstreetmap.org/?mlat=<?= $d['latitude'] ?>&mlon=<?= $d['longitude'] ?>&zoom=16"
               target="_blank" style="color:var(--accent);font-size:.73rem;font-weight:600;">🗺️ Map</a>
            <?php endif; ?>
          </div>
          <div class="card-info">
            <strong>🏢 Deliver to:</strong><br>
            <?= htmlspecialchars($d['ngo_name'] ?? 'Assigned NGO') ?>
            <?php if (!empty($d['ngo_phone'])): ?> · 📞 <?= htmlspecialchars($d['ngo_phone']) ?><?php endif; ?>
          </div>
          <?php if (!empty($d['expiry_time'])): ?>
          <div class="card-expiry">⏰ Expires: <?= date('d M, h:i A', strtotime($d['expiry_time'])) ?></div>
          <?php endif; ?>
        </div>
        <div class="card-actions">
          <a href="pickup_food.php?id=<?= $d['id'] ?>&action=accept"
             class="btn btn-accept btn-sm"
             onclick="return confirm('Accept this job?')">✅ Accept</a>
          <a href="pickup_food.php?id=<?= $d['id'] ?>&action=reject"
             class="btn btn-reject btn-sm"
             onclick="return confirm('Decline this job?')">✖ Decline</a>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- TAB: My Active -->
  <div class="tab-panel" id="t-mine">
    <div class="sec-title">🚛 My Active Deliveries</div>

    <?php if (empty($myRows)): ?>
    <div class="empty-state">
      <div class="empty-icon">🚗</div>
      <div class="empty-title">No active deliveries</div>
      <div class="empty-text">Accept a job from Available Jobs!</div>
    </div>
    <?php else: ?>
    <div class="cards-grid">
      <?php foreach ($myRows as $d): $b = badge($d['status']); ?>
      <div class="donation-card">

        <?php if (!empty($d['image_path'])): ?>
        <div class="card-img-wrap">
          <img src="uploads/food/<?= htmlspecialchars(basename($d['image_path'])) ?>" alt="" loading="lazy"/>
          <span class="card-badge <?= $b['cls'] ?>"><?= $b['icon'] ?> <?= $b['txt'] ?></span>
        </div>
        <?php else: ?>
        <div class="card-img-ph">🍱<small><?= $b['icon'] ?> <?= $b['txt'] ?></small></div>
        <?php endif; ?>

        <div class="card-body">
          <div class="card-name"><?= htmlspecialchars($d['food_name']) ?></div>
          <div class="card-info">
            <strong>📦 Pick up:</strong><br>
            🙋 <?= htmlspecialchars($d['donor_name']) ?><br>
            📍 <?= htmlspecialchars($d['address'] ?? 'N/A') ?>
            <?php if (!empty($d['latitude']) && !empty($d['longitude'])): ?>
            <br><a href="https://www.openstreetmap.org/?mlat=<?= $d['latitude'] ?>&mlon=<?= $d['longitude'] ?>&zoom=16"
               target="_blank" style="color:var(--accent);font-size:.73rem;font-weight:600;">🗺️ Map</a>
            <?php endif; ?>
          </div>
          <div class="card-info">
            <strong>🏢 Deliver to:</strong><br>
            <?= htmlspecialchars($d['ngo_name'] ?? 'N/A') ?>
            <?php if (!empty($d['ngo_phone'])): ?> · 📞 <?= htmlspecialchars($d['ngo_phone']) ?><?php endif; ?>
          </div>

          <?php if ($d['status'] === 'accepted'): ?>
          <!-- ── PICKUP PROOF UPLOAD ────────────────────────── -->
          <div class="upload-section">
            <span class="upload-section-label">📷 Upload pickup proof</span>
            <form action="volunteer_upload.php" method="POST" enctype="multipart/form-data"
                  onsubmit="return reqPhoto('pu_file_<?= $d['id'] ?>')">
              <input type="hidden" name="donation_id" value="<?= $d['id'] ?>"/>
              <input type="hidden" name="type" value="pickup"/>

              <!-- File picker -->
              <label class="proof-upload-label" for="pu_file_<?= $d['id'] ?>">
                <input type="file" name="pickup_image" id="pu_file_<?= $d['id'] ?>"
                       accept="image/*"
                       onchange="thumbPreview(this,'pu_prev_<?= $d['id'] ?>','pu_txt_<?= $d['id'] ?>')"/>
                <img class="proof-upload-preview" id="pu_prev_<?= $d['id'] ?>" src="" alt=""/>
                <div class="proof-upload-txt" id="pu_txt_<?= $d['id'] ?>">🖼️ Tap to select from gallery</div>
              </label>

              <!-- Camera button -->
              <button type="button" class="btn-camera"
                      onclick="startCamera('pu_file_<?= $d['id'] ?>','pu_cam_<?= $d['id'] ?>','pu_prev_<?= $d['id'] ?>','pu_txt_<?= $d['id'] ?>','pu_stat_<?= $d['id'] ?>')">
                📷 Use Live Camera Instead
              </button>

              <!-- Camera container (hidden until button clicked) -->
              <div class="camera-container" id="pu_cam_<?= $d['id'] ?>">
                <video class="camera-video" id="pu_vid_<?= $d['id'] ?>" autoplay playsinline></video>
                <div style="display:flex;gap:8px;margin-top:4px;">
                  <button type="button" class="btn-capture"
                          onclick="capturePhoto('pu_vid_<?= $d['id'] ?>','pu_file_<?= $d['id'] ?>','pu_cam_<?= $d['id'] ?>','pu_prev_<?= $d['id'] ?>','pu_txt_<?= $d['id'] ?>','pu_stat_<?= $d['id'] ?>')">
                    📸 Capture
                  </button>
                  <button type="button" class="btn-cam-cancel"
                          onclick="stopCamera('pu_cam_<?= $d['id'] ?>','pu_vid_<?= $d['id'] ?>')">
                    ✕ Cancel
                  </button>
                </div>
              </div>
              <div class="camera-status" id="pu_stat_<?= $d['id'] ?>"></div>

              <button type="submit" class="btn btn-blue btn-full" style="margin-top:8px;">
                🚚 Upload &amp; Mark Picked Up
              </button>
            </form>
          </div>

          <?php elseif ($d['status'] === 'picked'): ?>
          <!-- ── DELIVERY PROOF UPLOAD ──────────────────────── -->
          <div class="upload-section purple">
            <span class="upload-section-label">📷 Upload delivery proof</span>
            <p style="font-size:.76rem;color:#E65100;margin:3px 0 8px;">
              ⚠️ After uploading, the NGO must confirm receipt to complete the job.
            </p>
            <?php if (!empty($d['pickup_image'])): ?>
            <div style="margin-bottom:7px;font-size:.72rem;color:var(--txt-light);">
              Pickup proof: <a href="uploads/pickup/<?= htmlspecialchars(basename($d['pickup_image'])) ?>"
                 target="_blank" style="color:var(--accent);font-weight:600;">View ↗</a>
            </div>
            <?php endif; ?>
            <form action="volunteer_upload.php" method="POST" enctype="multipart/form-data"
                  onsubmit="return reqPhoto('dl_file_<?= $d['id'] ?>')">
              <input type="hidden" name="donation_id" value="<?= $d['id'] ?>"/>
              <input type="hidden" name="type" value="delivery"/>

              <label class="proof-upload-label" for="dl_file_<?= $d['id'] ?>">
                <input type="file" name="delivery_image" id="dl_file_<?= $d['id'] ?>"
                       accept="image/*"
                       onchange="thumbPreview(this,'dl_prev_<?= $d['id'] ?>','dl_txt_<?= $d['id'] ?>')"/>
                <img class="proof-upload-preview" id="dl_prev_<?= $d['id'] ?>" src="" alt=""/>
                <div class="proof-upload-txt" id="dl_txt_<?= $d['id'] ?>">🖼️ Tap to select from gallery</div>
              </label>

              <button type="button" class="btn-camera"
                      onclick="startCamera('dl_file_<?= $d['id'] ?>','dl_cam_<?= $d['id'] ?>','dl_prev_<?= $d['id'] ?>','dl_txt_<?= $d['id'] ?>','dl_stat_<?= $d['id'] ?>')">
                📷 Use Live Camera Instead
              </button>

              <div class="camera-container" id="dl_cam_<?= $d['id'] ?>">
                <video class="camera-video" id="dl_vid_<?= $d['id'] ?>" autoplay playsinline></video>
                <div style="display:flex;gap:8px;margin-top:4px;">
                  <button type="button" class="btn-capture"
                          onclick="capturePhoto('dl_vid_<?= $d['id'] ?>','dl_file_<?= $d['id'] ?>','dl_cam_<?= $d['id'] ?>','dl_prev_<?= $d['id'] ?>','dl_txt_<?= $d['id'] ?>','dl_stat_<?= $d['id'] ?>')">
                    📸 Capture
                  </button>
                  <button type="button" class="btn-cam-cancel"
                          onclick="stopCamera('dl_cam_<?= $d['id'] ?>','dl_vid_<?= $d['id'] ?>')">
                    ✕ Cancel
                  </button>
                </div>
              </div>
              <div class="camera-status" id="dl_stat_<?= $d['id'] ?>"></div>

              <button type="submit" class="btn btn-purple btn-full" style="margin-top:8px;">
                ✅ Upload &amp; Submit Delivery Proof
              </button>
            </form>
          </div>

          <?php elseif ($d['status'] === 'delivered'): ?>
          <!-- ── DELIVERED — WAITING FOR NGO CONFIRMATION ───── -->
          <div class="delivered-wait-box">
            <strong>⏳ Waiting for NGO to confirm receipt.</strong><br>
            You uploaded your delivery proof. The NGO will verify and mark it complete.<br>
            <?php if (!empty($d['delivery_image'])): ?>
            <a href="uploads/delivery/<?= htmlspecialchars(basename($d['delivery_image'])) ?>"
               target="_blank" style="color:var(--accent);font-size:.78rem;font-weight:600;margin-top:4px;display:inline-block;">
              📷 View your delivery photo ↗
            </a>
            <?php endif; ?>
          </div>
          <?php endif; ?>

        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div></div>

<script>
// ── Tab switching ─────────────────────────────────────────────
function sw(e, id) {
  document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
  document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
  e.currentTarget.classList.add('active');
  document.getElementById(id).classList.add('active');
}

// ── File picker preview ───────────────────────────────────────
function thumbPreview(input, previewId, txtId) {
  if (input.files && input.files[0]) {
    var r = new FileReader();
    r.onload = function(e) {
      var img = document.getElementById(previewId);
      var txt = document.getElementById(txtId);
      if (img) { img.src = e.target.result; img.style.display = 'block'; }
      if (txt) { txt.style.display = 'none'; }
    };
    r.readAsDataURL(input.files[0]);
  }
}

// ── Validate a photo was selected before submitting ───────────
function reqPhoto(fileInputId) {
  var inp = document.getElementById(fileInputId);
  if (!inp || !inp.files || !inp.files[0]) {
    alert('⚠️ Please select or capture a proof photo first.');
    return false;
  }
  return true;
}

// ── Camera: active streams tracker ───────────────────────────
var _streams = {};

function setStatus(id, msg, color) {
  var el = document.getElementById(id);
  if (el) { el.textContent = msg; el.style.color = color || '#888'; }
}

// ── Start camera ──────────────────────────────────────────────
function startCamera(fileId, camContainerId, previewId, txtId, statusId) {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    setStatus(statusId, '⚠️ Camera not supported. Please upload a file.', '#E65100');
    return;
  }
  setStatus(statusId, '⏳ Requesting camera...', '#888');
  var constraints = {
    video: { facingMode: { ideal: 'environment' }, width: { ideal: 1280 }, height: { ideal: 720 } }
  };
  navigator.mediaDevices.getUserMedia(constraints)
  .then(function(stream) {
    _streams[camContainerId] = stream;
    var vid = document.getElementById(camContainerId.replace('_cam_','_vid_'));
    if (vid) { vid.srcObject = stream; }
    document.getElementById(camContainerId).style.display = 'flex';
    setStatus(statusId, '📷 Camera ready — tap Capture when positioned', '#2E7D32');
  })
  .catch(function(err) {
    var msg = err.name === 'NotAllowedError'
      ? '❌ Camera denied. Allow in browser settings, or upload a file.'
      : '❌ Camera error: ' + err.message;
    setStatus(statusId, msg, '#E53935');
  });
}

// ── Stop camera ───────────────────────────────────────────────
function stopCamera(camContainerId, vidId) {
  if (_streams[camContainerId]) {
    _streams[camContainerId].getTracks().forEach(function(t){ t.stop(); });
    delete _streams[camContainerId];
  }
  document.getElementById(camContainerId).style.display = 'none';
  var vid = document.getElementById(vidId);
  if (vid) { vid.srcObject = null; }
}

// ── Capture photo from video and inject into file input ───────
function capturePhoto(vidId, fileInputId, camContainerId, previewId, txtId, statusId) {
  var vid = document.getElementById(vidId);
  if (!vid || !vid.srcObject) { setStatus(statusId, '❌ Camera not ready.', '#E53935'); return; }

  var canvas = document.createElement('canvas');
  canvas.width  = vid.videoWidth  || 1280;
  canvas.height = vid.videoHeight || 720;
  canvas.getContext('2d').drawImage(vid, 0, 0);

  canvas.toBlob(function(blob) {
    if (!blob) { setStatus(statusId, '❌ Capture failed. Try again.', '#E53935'); return; }

    // Inject into <input type="file"> via DataTransfer
    try {
      var file = new File([blob], 'camera_proof_' + Date.now() + '.jpg', { type: 'image/jpeg' });
      var dt   = new DataTransfer();
      dt.items.add(file);
      document.getElementById(fileInputId).files = dt.files;
    } catch(e) {
      setStatus(statusId, '⚠️ DataTransfer unsupported. Please upload a file instead.', '#E65100');
      stopCamera(camContainerId, vidId);
      return;
    }

    // Show captured preview
    var prev = document.getElementById(previewId);
    var txt  = document.getElementById(txtId);
    if (prev) { prev.src = URL.createObjectURL(blob); prev.style.display = 'block'; }
    if (txt)  { txt.style.display = 'none'; }

    stopCamera(camContainerId, vidId);
    setStatus(statusId, '✅ Photo captured! Submit the form to save.', '#2E7D32');
  }, 'image/jpeg', 0.92);
}

// ── Stop all cameras on page unload ──────────────────────────
window.addEventListener('beforeunload', function() {
  Object.keys(_streams).forEach(function(k) {
    _streams[k].getTracks().forEach(function(t){ t.stop(); });
  });
});

// ── Silently save volunteer location (runs once) ──────────────
if (navigator.geolocation) {
  navigator.geolocation.getCurrentPosition(function(pos) {
    var fd = new FormData();
    fd.append('lat', pos.coords.latitude);
    fd.append('lng', pos.coords.longitude);
    fetch('save_location.php', { method: 'POST', body: fd });
  }, null, { enableHighAccuracy: true, timeout: 8000 });
}
</script>
</body>
</html>
