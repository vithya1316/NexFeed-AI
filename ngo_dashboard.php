<?php
// ngo_dashboard.php — NEXFEEDAI
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ngo') {
    session_destroy(); header('Location: login.php'); exit();
}
require_once 'db.php';
require_once 'haversine.php';

$uid     = (int)$_SESSION['user_id'];
$success = get_flash('success');
$error   = get_flash('error');
$unread  = unread_count($uid);

// FIX: Use NGO's own saved location from users table, not AVG of donation coordinates
$loc    = $conn->query("SELECT latitude, longitude FROM users WHERE id=$uid")->fetch_assoc();
$ngoLat = (float)($loc['latitude']  ?? 0);
$ngoLng = (float)($loc['longitude'] ?? 0);

// Available donations — exclude passed ones
$availRes = $conn->query(
    "SELECT d.*, u.name AS donor_name, u.phone AS donor_phone,
            u.organization_name AS donor_org
     FROM donations d
     JOIN users u ON d.donor_id = u.id
     LEFT JOIN donation_passes dp ON dp.donation_id = d.id AND dp.ngo_id = $uid
     WHERE d.status = 'uploaded' AND dp.id IS NULL
     ORDER BY d.id DESC"
);
if (!$availRes) {
    $availRes = $conn->query("SELECT d.*, u.name AS donor_name, u.phone AS donor_phone, u.organization_name AS donor_org FROM donations d JOIN users u ON d.donor_id=u.id WHERE d.status='uploaded' ORDER BY d.id DESC");
}
$availRows = [];
while ($r = $availRes->fetch_assoc()) $availRows[] = $r;
if ($ngoLat && $ngoLng) $availRows = sort_by_distance($availRows, $ngoLat, $ngoLng);

// My accepted — no rejected
$myRes = $conn->query(
    "SELECT d.*, u.name AS donor_name,
            v.name AS vol_name, v.phone AS vol_phone
     FROM donations d
     JOIN users u ON d.donor_id = u.id
     LEFT JOIN users v ON d.volunteer_id = v.id
     WHERE d.ngo_id = $uid AND d.status != 'rejected'
     ORDER BY d.id DESC"
);
$myRows = [];
while ($r = $myRes->fetch_assoc()) $myRows[] = $r;

// ── DELIVERIES WAITING FOR NGO CONFIRMATION ──────────────────
// Donations where volunteer uploaded proof but NGO hasn't confirmed yet
$pendingConfirmRes = $conn->query(
    "SELECT d.id, d.food_name, d.total_people, d.expiry_time, d.address,
            d.created_at, u_vol.name AS volunteer_name,
            del.delivery_time, del.delivery_image
     FROM donations d
     JOIN deliveries del ON del.donation_id = d.id
     LEFT JOIN users u_vol ON u_vol.id = d.volunteer_id
     WHERE d.ngo_id = $uid AND d.status = 'delivered'
     ORDER BY del.delivery_time DESC"
);
$pendingConfirm = [];
if ($pendingConfirmRes) {
    while ($r = $pendingConfirmRes->fetch_assoc()) $pendingConfirm[] = $r;
}

// ── AVAILABLE VOLUNTEERS WITH DISTANCE ────────────────────────
$volRes = $conn->query(
    "SELECT id, name, phone, latitude, longitude, availability
     FROM users
     WHERE role='volunteer' AND verification_status='approved'
     AND availability='available'
     ORDER BY name"
);
$volunteers = [];
while ($v = $volRes->fetch_assoc()) $volunteers[] = $v;

$cutoff      = date('Y-m-d H:i:s', strtotime('-15 minutes'));
$needsAssign = array_filter($myRows, fn($d) =>
    $d['status'] === 'accepted' && empty($d['volunteer_id']) &&
    !empty($d['assigned_at']) && $d['assigned_at'] < $cutoff
);

$doneCount      = $conn->query("SELECT COUNT(*) c FROM donations WHERE ngo_id=$uid AND status='completed'")->fetch_assoc()['c'];
$pickedCount    = $conn->query("SELECT COUNT(*) c FROM donations WHERE ngo_id=$uid AND status='picked'")->fetch_assoc()['c'];
$acceptCount    = $conn->query("SELECT COUNT(*) c FROM donations WHERE ngo_id=$uid AND status='accepted'")->fetch_assoc()['c'];
$deliveredCount = $conn->query("SELECT COUNT(*) c FROM donations WHERE ngo_id=$uid AND status='delivered'")->fetch_assoc()['c'];

function badge(string $s): array {
    return [
        'accepted'  => ['cls'=>'b-accepted',  'icon'=>'✅', 'txt'=>'Accepted'],
        'picked'    => ['cls'=>'b-picked',    'icon'=>'🚛', 'txt'=>'In Transit'],
        'delivered' => ['cls'=>'b-delivered', 'icon'=>'📷', 'txt'=>'Awaiting Confirmation'],
        'completed' => ['cls'=>'b-completed', 'icon'=>'🎉', 'txt'=>'Delivered'],
    ][$s] ?? ['cls'=>'b-uploaded','icon'=>'❓','txt'=>ucfirst($s)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>NGO Dashboard — NEXFEEDAI</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    .hero-banner{background:linear-gradient(135deg,var(--accent),var(--accent-dk));border-radius:var(--radius-md);padding:20px 26px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;position:relative;overflow:hidden;animation:fadeUp .4s ease}
    .hero-banner::before{content:'';position:absolute;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.07);right:-60px;top:-60px;pointer-events:none}
    .hero-title{font-family:'DM Serif Display',serif;font-size:1.4rem;color:#fff;position:relative;z-index:1}
    .hero-quote{font-size:.82rem;color:rgba(255,255,255,.8);margin-top:3px;position:relative;z-index:1;font-style:italic}
    .stat-card.clickable{cursor:pointer}
    .stat-card.clickable.active-filter{border-color:var(--accent);box-shadow:var(--sh-md);background:var(--accent-bg)}
    .stat-card.clickable.active-filter .stat-num{color:var(--accent)}
    .donation-card.hidden{display:none}
    .filter-clear{font-size:.78rem;color:var(--accent);cursor:pointer;background:none;border:none;font-family:'Poppins',sans-serif;font-weight:600;padding:0}
    /* Distance badge in volunteer selector */
    .vol-dist-badge{display:inline-block;font-size:.67rem;font-weight:700;padding:2px 8px;border-radius:99px;margin-left:6px;}
    .vol-dist-near{background:#E8F5E9;color:#1B5E20;border:1px solid #C8E6C9;}
    .vol-dist-mid {background:#FFF8E1;color:#E65100;border:1px solid #FFE082;}
    .vol-dist-far {background:#F5F5F5;color:#757575;border:1px solid #E0E0E0;}
    /* Delivered badge */
    .b-delivered{background:#FFF8E1;color:#E65100;border:1px solid #FFE082;border-radius:99px;padding:2px 10px;font-size:.72rem;font-weight:700;}
    /* Confirmation section */
    .confirm-section{background:#FFF8E1;border:2px solid #FFB300;border-radius:var(--radius-md);padding:18px 20px;margin-bottom:22px;animation:fadeUp .4s ease}
    .confirm-section-title{font-size:1rem;font-weight:700;color:#E65100;margin:0 0 4px;}
    .confirm-section-sub{font-size:.81rem;color:#888;margin:0 0 14px;}
    .confirm-card{background:#fff;border:1px solid #FFE082;border-radius:10px;padding:16px;margin-bottom:12px;}
    .confirm-card:last-child{margin-bottom:0;}
    .confirm-card h4{margin:0 0 7px;font-size:.95rem;color:var(--txt-dark);}
    .confirm-proof-img{width:100%;max-height:220px;object-fit:cover;border-radius:8px;margin:8px 0;border:1px solid #FFE082;}
    .btn-confirm-yes{background:#2E7D32;color:#fff;border:none;padding:9px 18px;border-radius:7px;font-weight:600;cursor:pointer;font-size:.85rem;}
    .btn-confirm-yes:hover{background:#1B5E20;}
    .btn-confirm-no{background:#E53935;color:#fff;border:none;padding:9px 18px;border-radius:7px;font-weight:600;cursor:pointer;font-size:.85rem;}
    .btn-confirm-no:hover{background:#B71C1C;}
    .dispute-form{display:none;margin-top:12px;background:#FFEBEE;padding:14px;border-radius:8px;}
    .dispute-input{width:100%;margin:6px 0 10px;padding:9px;border:1px solid #FFCDD2;border-radius:6px;font-size:.85rem;font-family:inherit;}
  </style>
</head>
<body data-page="ngo">
<!-- Store volunteer+donation data for JS distance calculation -->
<script>
var volunteersData = <?= json_encode(array_map(fn($v) => [
    'id'   => (int)$v['id'],
    'name' => $v['name'],
    'phone'=> $v['phone'],
    'lat'  => (float)($v['latitude'] ?? 0),
    'lng'  => (float)($v['longitude'] ?? 0),
], $volunteers)) ?>;
</script>

<nav class="navbar">
  <a href="ngo_dashboard.php" class="nav-brand"><div class="nav-logo">🍽️</div><span class="nav-title">NEXFEED<span>AI</span></span></a>
  <div class="nav-right">
    <a href="notifications.php" class="notif-bell">🔔<?php if($unread):?><span class="notif-count"><?=$unread?></span><?php endif;?></a>
    <span class="nav-username">🏢 <?=htmlspecialchars($_SESSION['name'])?></span>
    <span class="role-badge">NGO</span>
    <a href="history.php" class="btn btn-ghost btn-sm">📜 History</a>
    <a href="logout.php"  class="btn btn-ghost btn-sm">🚪 Logout</a>
  </div>
</nav>

<div class="dash-wrap"><div class="dash-main">
  <?php if($success):?><div class="alert alert-success">✅ <?=htmlspecialchars($success)?></div><?php endif;?>
  <?php if($error):?><div class="alert alert-error">⚠️ <?=htmlspecialchars($error)?></div><?php endif;?>

  <div class="hero-banner">
    <div>
      <div class="hero-title">NGO Dashboard 🏢</div>
      <div class="hero-quote">"Every donation you accept is a family fed tonight."</div>
    </div>
  </div>

  <div class="stats-row">
    <div class="stat-card clickable" id="sc-avail" onclick="switchToTab('t-avail')">
      <div class="stat-icon ic-orange">📢</div>
      <div><div class="stat-num"><?=count($availRows)?></div><div class="stat-label">Available Now</div></div>
    </div>
    <div class="stat-card clickable" id="sc-accepted" onclick="switchToTab('t-mine','accepted')">
      <div class="stat-icon ic-blue">✅</div>
      <div><div class="stat-num"><?=$acceptCount?></div><div class="stat-label">Accepted</div></div>
    </div>
    <div class="stat-card clickable" id="sc-picked" onclick="switchToTab('t-mine','picked')">
      <div class="stat-icon ic-purple">🚛</div>
      <div><div class="stat-num"><?=$pickedCount?></div><div class="stat-label">In Transit</div></div>
    </div>
    <?php if($deliveredCount > 0):?>
    <div class="stat-card clickable" id="sc-delivered" onclick="switchToTab('t-mine','delivered')" style="border-color:#FFE082;">
      <div class="stat-icon ic-orange">📷</div>
      <div><div class="stat-num" style="color:#E65100;"><?=$deliveredCount?></div><div class="stat-label">Awaiting Confirm</div></div>
    </div>
    <?php endif;?>
    <div class="stat-card clickable" id="sc-done" onclick="switchToTab('t-mine','completed')">
      <div class="stat-icon ic-green">🎉</div>
      <div><div class="stat-num"><?=$doneCount?></div><div class="stat-label">Delivered</div></div>
    </div>
    <?php if(count($needsAssign)):?>
    <div class="stat-card" style="border-color:#FFE082;">
      <div class="stat-icon ic-orange">⚠️</div>
      <div><div class="stat-num" style="color:#E65100;"><?=count($needsAssign)?></div><div class="stat-label">Need Volunteer</div></div>
    </div>
    <?php endif;?>
  </div>

  <?php foreach($needsAssign as $mn):?>
  <div class="alert-manual">
    ⏰ <strong><?=htmlspecialchars($mn['food_name'])?></strong> — no volunteer for <?=round((time()-strtotime($mn['assigned_at']))/60)?> mins.
    <button class="btn btn-blue btn-sm" style="margin-left:8px;"
            onclick="openAssign(<?=$mn['id']?>,'<?=htmlspecialchars(addslashes($mn['food_name']))?>', <?=(float)($mn['latitude']??0)?>, <?=(float)($mn['longitude']??0)?>)">
      👤 Assign Manually
    </button>
  </div>
  <?php endforeach;?>

  <!-- ══════════════════════════════════════════════════════════
       DELIVERY CONFIRMATION SECTION
       Shows only when volunteers have uploaded delivery proofs
       ══════════════════════════════════════════════════════════ -->
  <?php if(!empty($pendingConfirm)):?>
  <div class="confirm-section">
    <div class="confirm-section-title">📷 <?=count($pendingConfirm)?> Delivery Proof<?=count($pendingConfirm)>1?'s':''?> Waiting for Your Confirmation</div>
    <div class="confirm-section-sub">The volunteer has uploaded proof of delivery. Please verify the photo and confirm receipt, or report an issue.</div>

    <?php foreach($pendingConfirm as $dc):?>
    <div class="confirm-card" id="cc_<?=(int)$dc['id']?>">
      <h4>
        🍽️ <?=htmlspecialchars($dc['food_name'])?>
        <span style="font-size:.78rem;font-weight:400;color:#888"> — <?=(int)$dc['total_people']?> people</span>
      </h4>

      <div style="font-size:.8rem;color:#666;line-height:1.7;">
        <?php if(!empty($dc['address'])):?>📍 <?=htmlspecialchars($dc['address'])?><br><?php endif;?>
        👤 Volunteer: <strong><?=htmlspecialchars($dc['volunteer_name'] ?? 'Unknown')?></strong><br>
        🕐 Delivered: <?=$dc['delivery_time'] ? date('d M Y, h:i A', strtotime($dc['delivery_time'])) : 'Unknown time'?>
      </div>

      <?php if(!empty($dc['delivery_image'])):?>
      <img src="uploads/delivery/<?=htmlspecialchars(basename($dc['delivery_image']))?>"
           alt="Delivery proof photo"
           class="confirm-proof-img"
           onerror="this.style.display='none'"/>
      <?php else:?>
      <p style="font-size:.82rem;color:#999;font-style:italic;margin:8px 0;">No proof image uploaded.</p>
      <?php endif;?>

      <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:4px;">
        <!-- Confirm received -->
        <form action="ngo_confirm_delivery.php" method="POST" style="display:inline"
              onsubmit="return confirm('Confirm that you received this food delivery?')">
          <input type="hidden" name="donation_id" value="<?=(int)$dc['id']?>">
          <input type="hidden" name="action" value="received">
          <button type="submit" class="btn-confirm-yes">✅ Confirm Receipt</button>
        </form>

        <!-- Report not received -->
        <button type="button" class="btn-confirm-no"
                onclick="toggleDisputeForm(<?=(int)$dc['id']?>)">
          ⚠️ Not Received
        </button>
      </div>

      <!-- Dispute form — hidden until button clicked -->
      <div class="dispute-form" id="df_<?=(int)$dc['id']?>">
        <form action="ngo_confirm_delivery.php" method="POST"
              onsubmit="return confirm('Report that this delivery was NOT received? The volunteer will be notified.')">
          <input type="hidden" name="donation_id" value="<?=(int)$dc['id']?>">
          <input type="hidden" name="action" value="not_received">
          <label style="font-size:.83rem;font-weight:600;color:#B71C1C;display:block;">
            Reason (optional — helps resolve the issue faster):
          </label>
          <input type="text" name="note" maxlength="300" class="dispute-input"
                 placeholder="e.g. Volunteer did not arrive, wrong food, damaged packaging...">
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button type="submit" style="background:#E53935;color:#fff;border:none;padding:9px 16px;border-radius:6px;cursor:pointer;font-weight:600;font-size:.84rem;">
              🚨 Submit Dispute
            </button>
            <button type="button"
                    onclick="toggleDisputeForm(<?=(int)$dc['id']?>)"
                    style="background:none;border:1px solid #ccc;padding:8px 14px;border-radius:6px;cursor:pointer;font-size:.84rem;color:#666;">
              Cancel
            </button>
          </div>
        </form>
      </div>
    </div>
    <?php endforeach;?>
  </div>
  <?php endif;?>

  <div class="tab-bar">
    <button class="tab-btn active" id="tabAvail" onclick="switchTab(event,'t-avail')">🆕 Available (<?=count($availRows)?>)</button>
    <button class="tab-btn" id="tabMine"  onclick="switchTab(event,'t-mine')">📋 My Accepted (<?=count($myRows)?>)</button>
  </div>

  <!-- AVAILABLE -->
  <div class="tab-panel active" id="t-avail">
    <div class="sec-title">🆕 Donations Waiting<?php if($ngoLat&&$ngoLng):?><span style="font-size:.73rem;font-weight:400;color:var(--txt-light);"> — nearest first</span><?php endif;?></div>
    <?php if(empty($availRows)):?>
    <div class="empty-state"><div class="empty-icon">🍽️</div><div class="empty-title">No new donations</div><div class="empty-text">Check back soon.</div></div>
    <?php else:?>
    <div class="cards-grid">
      <?php foreach($availRows as $d):?>
      <div class="donation-card">
        <?php if(!empty($d['image_path'])):?><div class="card-img-wrap"><img src="uploads/food/<?=htmlspecialchars(basename($d['image_path']))?>" alt="<?=htmlspecialchars($d['food_name'])?>" loading="lazy"/><span class="card-badge b-new">🆕 New</span></div>
        <?php else:?><div class="card-img-ph">🍱<small>No Photo</small></div><?php endif;?>
        <div class="card-body">
          <div class="card-name"><?=htmlspecialchars($d['food_name'])?></div>
          <?php
            // AI badge — shown to NGO so they can make informed decision
            if(!empty($d['ai_checked']) && $d['ai_checked'] == 1):
              if($d['ai_prediction'] === 'likely_safe'):?>
          <div style="display:inline-flex;align-items:center;gap:4px;font-size:.72rem;font-weight:600;color:#1B5E20;background:#E8F5E9;padding:3px 9px;border-radius:99px;border:1px solid #C8E6C9;margin-bottom:6px;">
            ✅ AI: Likely Safe · <?=round((float)($d['ai_confidence']??0),1)?>%
          </div>
          <?php elseif($d['ai_prediction'] === 'not_recommended'):?>
          <div style="display:inline-flex;align-items:center;gap:4px;font-size:.72rem;font-weight:600;color:#B71C1C;background:#FFEBEE;padding:3px 9px;border-radius:99px;border:1px solid #FFCDD2;margin-bottom:6px;">
            ⚠️ AI: Review Needed · <?=round((float)($d['ai_confidence']??0),1)?>%
          </div>
          <?php endif; elseif(!empty($d['image_path'])):?>
          <div style="display:inline-flex;align-items:center;gap:4px;font-size:.71rem;color:#8896AB;background:#F5F5F5;padding:3px 9px;border-radius:99px;border:1px solid #E0E0E0;margin-bottom:6px;">
            ❓ AI pending
          </div>
          <?php endif;?>
          <div class="card-chips">
            <span class="chip">🏷️ <?=htmlspecialchars($d['food_category'])?></span>
            <span class="chip">👥 <?=(int)$d['total_people']?> people</span>
            <?php if(!empty($d['distance_km'])&&$d['distance_km']<9999):?><span class="chip chip-dist">📍 <?=km_label($d['distance_km'])?> away</span><?php endif;?>
          </div>
          <div class="card-info">
            👤 <strong><?=htmlspecialchars($d['donor_name'])?></strong><?php if(!empty($d['donor_org'])):?> — <?=htmlspecialchars($d['donor_org'])?><?php endif;?><br>
            📞 <?=htmlspecialchars($d['donor_phone'])?>
          </div>
          <?php if(!empty($d['description'])):?><div class="card-desc"><?=htmlspecialchars($d['description'])?></div><?php endif;?>
          <?php if(!empty($d['address'])):?><div class="card-addr"><span>📍</span><span><?=htmlspecialchars($d['address'])?></span></div><?php endif;?>
          <?php if(!empty($d['expiry_time'])):?><div class="card-expiry">⏰ Expires: <?=date('d M, h:i A',strtotime($d['expiry_time']))?></div><?php endif;?>
        </div>
        <div class="card-actions">
          <a href="ngo_action.php?id=<?=$d['id']?>&action=accept" class="btn btn-accept btn-sm" onclick="return confirm('Accept this donation?')">✅ Accept</a>
          <a href="ngo_action.php?id=<?=$d['id']?>&action=reject" class="btn btn-reject btn-sm" onclick="return confirm('Pass on this? It stays visible to other NGOs.')">✖ Pass</a>
          <?php if(!empty($d['latitude'])&&!empty($d['longitude'])):?>
          <a href="https://www.openstreetmap.org/?mlat=<?=$d['latitude']?>&mlon=<?=$d['longitude']?>&zoom=16" target="_blank" class="btn btn-blue btn-sm">🗺️ Map</a>
          <?php endif;?>
        </div>
      </div>
      <?php endforeach;?>
    </div>
    <?php endif;?>
  </div>

  <!-- MY ACCEPTED -->
  <div class="tab-panel" id="t-mine">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:13px;flex-wrap:wrap;gap:8px;">
      <div class="sec-title" style="margin-bottom:0;">📋 Accepted by My NGO</div>
      <span id="mineFilterLabel" style="font-size:.78rem;color:var(--accent);font-weight:600;display:none;">
        <span id="mineFilterText"></span>
        <button class="filter-clear" onclick="filterMine('all')" style="margin-left:6px;">✕ Clear</button>
      </span>
    </div>
    <?php if(empty($myRows)):?>
    <div class="empty-state"><div class="empty-icon">📭</div><div class="empty-title">No accepted donations</div><div class="empty-text">Accept from the Available tab.</div></div>
    <?php else:?>
    <div class="cards-grid" id="mineGrid">
      <?php foreach($myRows as $d): $b=badge($d['status']);?>
      <div class="donation-card" data-status="<?=htmlspecialchars($d['status'])?>">
        <?php if(!empty($d['image_path'])):?><div class="card-img-wrap"><img src="uploads/food/<?=htmlspecialchars(basename($d['image_path']))?>" alt="" loading="lazy"/><span class="card-badge <?=$b['cls']?>"><?=$b['icon']?> <?=$b['txt']?></span></div>
        <?php else:?><div class="card-img-ph">🍱<small><?=$b['icon']?> <?=$b['txt']?></small></div><?php endif;?>
        <div class="card-body">
          <div class="card-name"><?=htmlspecialchars($d['food_name'])?></div>
          <div class="card-chips"><span class="chip">👥 <?=(int)$d['total_people']?> people</span><span class="chip">🏷️ <?=htmlspecialchars($d['food_category'])?></span></div>
          <?php if($d['status'] === 'delivered'):?>
          <div style="background:#FFF8E1;border:1px solid #FFE082;border-radius:7px;padding:9px 11px;font-size:.8rem;color:#E65100;margin:8px 0;">
            📷 Volunteer uploaded delivery proof. <strong>Please confirm receipt above.</strong>
          </div>
          <?php endif;?>
          <div class="card-info">
            🙋 Donor: <strong><?=htmlspecialchars($d['donor_name'])?></strong><br>
            🚚 Volunteer:
            <?php if(!empty($d['vol_name'])):?>
              <strong><?=htmlspecialchars($d['vol_name'])?></strong><?php if(!empty($d['vol_phone'])):?> · 📞 <?=htmlspecialchars($d['vol_phone'])?><?php endif;?>
            <?php else:?>
              <span style="color:var(--txt-light);">Not assigned</span>
              <?php if($d['status']==='accepted'):?>
              &nbsp;<button class="btn btn-blue btn-sm"
                onclick="openAssign(<?=$d['id']?>,'<?=htmlspecialchars(addslashes($d['food_name']))?>', <?=(float)($d['latitude']??0)?>, <?=(float)($d['longitude']??0)?>)">
                👤 Assign
              </button>
              <?php endif;?>
            <?php endif;?>
          </div>
          <?php if(!empty($d['address'])):?><div class="card-addr"><span>📍</span><span><?=htmlspecialchars($d['address'])?></span></div><?php endif;?>
          <?php if(!empty($d['assigned_at'])):?><div style="font-size:.7rem;color:var(--txt-light);margin-top:3px;">✅ <?=date('d M, h:i A',strtotime($d['assigned_at']))?></div><?php endif;?>
        </div>
      </div>
      <?php endforeach;?>
    </div>
    <?php endif;?>
  </div>
</div></div>

<!-- ASSIGN MODAL -->
<div class="modal-overlay" id="assignModal">
  <div class="modal-box" style="max-width:450px;">
    <div class="modal-hdr">
      <div class="modal-title">👤 Assign Volunteer</div>
      <button class="modal-close" onclick="document.getElementById('assignModal').classList.remove('active')">✕</button>
    </div>
    <form method="POST" action="ngo_action.php">
      <input type="hidden" name="action" value="assign"/>
      <input type="hidden" name="donation_id" id="assignDonId"/>
      <div class="modal-body">
        <p id="assignDonName" style="font-size:.85rem;color:var(--txt-mid);margin-bottom:4px;"></p>
        <p id="assignDistNote" style="font-size:.76rem;color:var(--txt-light);margin-bottom:14px;"></p>
        <div class="form-group">
          <label class="form-label">Select Volunteer
            <span style="font-size:.69rem;font-weight:400;color:var(--txt-light);"> — sorted by distance from pickup</span>
          </label>
          <select name="volunteer_id" id="volSelect" class="form-control form-select" required>
            <option value="">— Choose a volunteer —</option>
          </select>
          <div class="form-hint" id="noVolMsg" style="color:var(--red);display:none;">No available volunteers right now.</div>
        </div>
        <div id="volDistInfo" style="display:none;background:var(--accent-bg);border-radius:8px;border-left:3px solid var(--accent-lt);padding:9px 11px;font-size:.79rem;color:var(--txt-mid);margin-top:8px;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('assignModal').classList.remove('active')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="assignBtn">✅ Assign</button>
      </div>
    </form>
  </div>
</div>

<script>
function haversineJS(lat1, lon1, lat2, lon2) {
  var R = 6371;
  var dLat = (lat2 - lat1) * Math.PI / 180;
  var dLon = (lon2 - lon1) * Math.PI / 180;
  var a = Math.sin(dLat/2) * Math.sin(dLat/2) +
          Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
          Math.sin(dLon/2) * Math.sin(dLon/2);
  return Math.round(R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a)) * 10) / 10;
}

function openAssign(donId, donName, donLat, donLng) {
  document.getElementById('assignDonId').value = donId;
  document.getElementById('assignDonName').textContent = '📦 ' + donName;
  document.getElementById('volDistInfo').style.display = 'none';
  var sel    = document.getElementById('volSelect');
  var noMsg  = document.getElementById('noVolMsg');
  var distNote = document.getElementById('assignDistNote');
  sel.innerHTML = '<option value="">— Choose a volunteer —</option>';
  var hasDonLoc = donLat !== 0 && donLng !== 0;
  var volsWithDist = volunteersData.map(function(v) {
    var dist = null;
    if (hasDonLoc && v.lat !== 0 && v.lng !== 0) dist = haversineJS(donLat, donLng, v.lat, v.lng);
    return { id: v.id, name: v.name, phone: v.phone, dist: dist };
  });
  volsWithDist.sort(function(a, b) {
    if (a.dist === null && b.dist === null) return a.name.localeCompare(b.name);
    if (a.dist === null) return 1;
    if (b.dist === null) return -1;
    return a.dist - b.dist;
  });
  distNote.textContent = hasDonLoc
    ? '📍 Volunteers sorted by distance from pickup location'
    : '⚠️ No GPS on this donation — distances unavailable';
  volsWithDist.forEach(function(v) {
    var opt  = document.createElement('option');
    opt.value = v.id;
    var distLabel = v.dist !== null ? ' — ' + v.dist + ' km away' : '';
    var urgency = v.dist !== null && v.dist <= 5 ? ' 🟢' : (v.dist !== null && v.dist <= 15 ? ' 🟡' : (v.dist !== null ? ' 🔴' : ''));
    opt.textContent = v.name + ' · ' + v.phone + distLabel + urgency;
    opt.dataset.dist = v.dist !== null ? v.dist : '';
    opt.dataset.name = v.name;
    sel.appendChild(opt);
  });
  if (volsWithDist.length === 0) {
    noMsg.style.display = 'block';
    document.getElementById('assignBtn').disabled = true;
  } else {
    noMsg.style.display = 'none';
    document.getElementById('assignBtn').disabled = false;
  }
  document.getElementById('assignModal').classList.add('active');
}

document.getElementById('volSelect').addEventListener('change', function() {
  var opt  = this.options[this.selectedIndex];
  var info = document.getElementById('volDistInfo');
  if (!opt.value) { info.style.display = 'none'; return; }
  var dist = opt.dataset.dist;
  if (dist !== '') {
    var urgency = parseFloat(dist) <= 5
      ? '🟢 Very close — fastest pickup likely'
      : (parseFloat(dist) <= 15 ? '🟡 Reasonable distance' : '🔴 Far — consider a closer volunteer');
    info.textContent = '📍 ' + opt.dataset.name + ' is ' + dist + ' km from the pickup location. ' + urgency;
    info.style.display = 'block';
  } else {
    info.textContent = 'ℹ️ Distance unknown — no location saved for this volunteer.';
    info.style.display = 'block';
  }
});

document.getElementById('assignModal').addEventListener('click', function(e) { if(e.target===this) this.classList.remove('active'); });

function switchTab(e, id) {
  document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
  document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
  e.currentTarget.classList.add('active');
  document.getElementById(id).classList.add('active');
  document.querySelectorAll('.stat-card.clickable').forEach(function(c){ c.classList.remove('active-filter'); });
}
function switchToTab(tabId, filter) {
  document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
  document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
  document.getElementById(tabId).classList.add('active');
  if (tabId==='t-avail') { document.getElementById('tabAvail').classList.add('active'); }
  else { document.getElementById('tabMine').classList.add('active'); if(filter)filterMine(filter); }
}
function filterMine(filter) {
  var cards=document.querySelectorAll('#mineGrid .donation-card'),lbl=document.getElementById('mineFilterLabel'),txt=document.getElementById('mineFilterText');
  var names={all:'',accepted:'✅ Accepted',picked:'🚛 In Transit',delivered:'📷 Awaiting Confirmation',completed:'🎉 Delivered'};
  document.querySelectorAll('.stat-card.clickable').forEach(function(c){ c.classList.remove('active-filter'); });
  var scMap={accepted:'sc-accepted',picked:'sc-picked',delivered:'sc-delivered',completed:'sc-done'};
  if(scMap[filter]){ var el=document.getElementById(scMap[filter]); if(el)el.classList.add('active-filter'); }
  var vis=0;
  cards.forEach(function(card){ var s=card.getAttribute('data-status'),show=filter==='all'||s===filter;card.classList.toggle('hidden',!show);if(show)vis++; });
  if(filter!=='all'&&names[filter]){txt.textContent='Showing: '+names[filter];lbl.style.display='inline-flex';}else{lbl.style.display='none';}
}

// Toggle dispute form visibility
function toggleDisputeForm(donId) {
  var df  = document.getElementById('df_' + donId);
  var btn = document.querySelector('#cc_' + donId + ' .btn-confirm-no');
  if (!df) return;
  var isHidden = df.style.display === 'none' || df.style.display === '';
  df.style.display = isHidden ? 'block' : 'none';
  if (btn) btn.textContent = isHidden ? '✕ Cancel' : '⚠️ Not Received';
}

// Save NGO location silently for proximity notifications
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
