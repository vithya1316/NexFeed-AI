<?php
// donor_dashboard.php — NEXFEEDAI (food icon + single-click image upload)
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'donor') {
    session_destroy(); header('Location: login.php'); exit();
}
require_once 'db.php';

$uid        = (int)$_SESSION['user_id'];
$success    = get_flash('success');
$error      = get_flash('error');
$ai_warning = get_flash('ai_warning');
$unread     = unread_count($uid);

$result  = $conn->query("SELECT * FROM donations WHERE donor_id=$uid ORDER BY id DESC");
$total   = $conn->query("SELECT COUNT(*) c FROM donations WHERE donor_id=$uid")->fetch_assoc()['c'];
$waiting = $conn->query("SELECT COUNT(*) c FROM donations WHERE donor_id=$uid AND status='uploaded'")->fetch_assoc()['c'];
$active  = $conn->query("SELECT COUNT(*) c FROM donations WHERE donor_id=$uid AND status IN ('accepted','picked')")->fetch_assoc()['c'];
$done    = $conn->query("SELECT COUNT(*) c FROM donations WHERE donor_id=$uid AND status='completed'")->fetch_assoc()['c'];

function badge(string $s): array {
    return [
        'uploaded'  => ['cls'=>'b-uploaded',  'icon'=>'⏳','txt'=>'Awaiting NGO'],
        'accepted'  => ['cls'=>'b-accepted',  'icon'=>'✅','txt'=>'Accepted by NGO'],
        'picked'    => ['cls'=>'b-picked',    'icon'=>'🚛','txt'=>'In Transit'],
        'completed' => ['cls'=>'b-completed', 'icon'=>'🎉','txt'=>'Delivered'],
        'rejected'  => ['cls'=>'b-rejected',  'icon'=>'✖', 'txt'=>'Rejected'],
    ][$s] ?? ['cls'=>'b-uploaded','icon'=>'❓','txt'=>ucfirst($s)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Donor Dashboard — NEXFEEDAI</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    .hero-banner{background:linear-gradient(135deg,var(--accent),var(--accent-dk));border-radius:var(--radius-md);padding:20px 26px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;position:relative;overflow:hidden;animation:fadeUp .4s ease}
    .hero-banner::before{content:'';position:absolute;width:220px;height:220px;border-radius:50%;background:rgba(255,255,255,.07);right:-60px;top:-60px;pointer-events:none}
    .hero-title{font-family:'DM Serif Display',serif;font-size:1.4rem;color:#fff;position:relative;z-index:1}
    .hero-quote{font-size:.82rem;color:rgba(255,255,255,.8);margin-top:3px;position:relative;z-index:1;font-style:italic}
    .hero-btn{background:rgba(255,255,255,.18);color:#fff;border:1.5px solid rgba(255,255,255,.35);position:relative;z-index:1}
    .hero-btn:hover{background:rgba(255,255,255,.3)}
    .stat-card.clickable{cursor:pointer}
    .stat-card.clickable.active-filter{border-color:var(--accent);box-shadow:var(--sh-md);background:var(--accent-bg)}
    .stat-card.clickable.active-filter .stat-num{color:var(--accent)}
    .donation-card.hidden{display:none}
    .filter-label{font-size:.79rem;color:var(--accent);font-weight:600;background:var(--accent-bg);padding:4px 12px;border-radius:99px;border:1px solid var(--accent-lt);display:none;align-items:center;gap:6px}
    .filter-label.visible{display:inline-flex}

    /* FIX: Food photo upload — single click only */
    .food-photo-label {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 6px;
      padding: 18px;
      border: 2px dashed var(--accent-lt);
      border-radius: var(--radius-sm);
      cursor: pointer;
      background: var(--accent-bg);
      transition: var(--tr);
      text-align: center;
    }
    .food-photo-label:hover { border-color: var(--accent); background: var(--white); }
    .food-photo-label input[type=file] { display: none; }
    .food-photo-preview {
      width: 100%; max-height: 150px; object-fit: cover;
      border-radius: 8px; display: none; margin-bottom: 4px;
    }
  </style>
</head>
<body data-page="donor">

<nav class="navbar">
  <a href="donor_dashboard.php" class="nav-brand">
    <div class="nav-logo">🍽️</div><!-- food icon -->
    <span class="nav-title">NEXFEED<span>AI</span></span>
  </a>
  <div class="nav-right">
    <a href="notifications.php" class="notif-bell" title="Notifications">
      🔔<?php if ($unread): ?><span class="notif-count"><?= $unread ?></span><?php endif; ?>
    </a>
    <span class="nav-username">👤 <?= htmlspecialchars($_SESSION['name']) ?></span>
    <span class="role-badge">Donor</span>
    <a href="history.php" class="btn btn-ghost btn-sm">📜 History</a>
    <a href="logout.php"  class="btn btn-ghost btn-sm">🚪 Logout</a>
  </div>
</nav>

<div class="dash-wrap"><div class="dash-main">

  <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($ai_warning): ?><div class="alert alert-warning"> ⚠️ <?= htmlspecialchars($ai_warning) ?></div><?php endif; ?>

  <!-- HERO BANNER -->
  <div class="hero-banner">
    <div>
      <div class="hero-title">My Donations 🍱</div>
      <div class="hero-quote">"One plate of food saved today is one life changed forever."</div>
    </div>
    <button class="btn hero-btn"
            onclick="document.getElementById('uploadModal').classList.add('active')">
      ➕ Donate Food
    </button>
  </div>

  <!-- CLICKABLE STATS -->
  <div class="stats-row">
    <div class="stat-card clickable" onclick="filterCards('all')" id="sc-all">
      <div class="stat-icon ic-accent">🍽️</div>
      <div><div class="stat-num"><?= $total ?></div><div class="stat-label">Total Uploaded</div></div>
    </div>
    <div class="stat-card clickable" onclick="filterCards('uploaded')" id="sc-uploaded">
      <div class="stat-icon ic-orange">⏳</div>
      <div><div class="stat-num"><?= $waiting ?></div><div class="stat-label">Awaiting NGO</div></div>
    </div>
    <div class="stat-card clickable" onclick="filterCards('active')" id="sc-active">
      <div class="stat-icon ic-blue">🚛</div>
      <div><div class="stat-num"><?= $active ?></div><div class="stat-label">In Progress</div></div>
    </div>
    <div class="stat-card clickable" onclick="filterCards('completed')" id="sc-completed">
      <div class="stat-icon ic-green">🏆</div>
      <div><div class="stat-num"><?= $done ?></div><div class="stat-label">Delivered</div></div>
    </div>
  </div>

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
    <div class="sec-title" style="margin-bottom:0;">📦 Your Donations</div>
    <div class="filter-label" id="filterLabel">
      <span id="filterText"></span>
      <button onclick="filterCards('all')" style="background:none;border:none;cursor:pointer;font-size:.8rem;color:var(--accent);padding:0;font-family:'Poppins',sans-serif;">✕ Clear</button>
    </div>
  </div>

  <?php if ($result->num_rows === 0): ?>
  <div class="empty-state">
    <div class="empty-icon">🍽️</div>
    <div class="empty-title">No donations yet</div>
    <div class="empty-text">Click "Donate Food" in the banner above!</div>
  </div>
  <?php else: ?>
  <div class="cards-grid" id="cardsGrid">
    <?php while ($d = $result->fetch_assoc()): $b = badge($d['status']); ?>
    <div class="donation-card" data-status="<?= htmlspecialchars($d['status']) ?>">
      <?php if (!empty($d['image_path'])): ?>
      <div class="card-img-wrap">
        <img src="uploads/food/<?= htmlspecialchars(basename($d['image_path'])) ?>"
             alt="<?= htmlspecialchars($d['food_name']) ?>" loading="lazy"/>
        <span class="card-badge <?= $b['cls'] ?>"><?= $b['icon'] ?> <?= $b['txt'] ?></span>
      </div>
      <?php else: ?>
      <div class="card-img-ph">🍱<small><?= $b['icon'] ?> <?= $b['txt'] ?></small></div>
      <?php endif; ?>
      <div class="card-body">
        <div class="card-name"><?= htmlspecialchars($d['food_name']) ?></div>
        <?php if (!empty($d['ai_checked']) && (int)$d['ai_checked'] === 1): ?>
          <?php if ($d['ai_prediction'] === 'likely_safe'): ?>
          <div class="chip chip-ai-safe" style="display:inline-flex;align-items:center;gap:4px;margin-bottom:6px;">
            ✅ AI: Likely Safe · <?= round((float)($d['ai_confidence'] ?? 0), 1) ?>%
          </div>
          <?php elseif ($d['ai_prediction'] === 'not_recommended'): ?>
          <div class="chip chip-ai-warn" style="display:inline-flex;align-items:center;gap:4px;margin-bottom:6px;">
            ⚠️ AI: Review Flagged · <?= round((float)($d['ai_confidence'] ?? 0), 1) ?>%
          </div>
          <?php endif; ?>
        <?php endif; ?>
        <div class="card-chips">
          <span class="chip">🏷️ <?= htmlspecialchars($d['food_category'] ?? 'Food') ?></span>
          <span class="chip">👥 <?= (int)$d['total_people'] ?> people</span>
          <?php if (!empty($d['expiry_time'])): ?>
          <span class="chip">⏰ <?= date('d M, h:i A', strtotime($d['expiry_time'])) ?></span>
          <?php endif; ?>
        </div>
        <?php if (!empty($d['description'])): ?><div class="card-desc"><?= htmlspecialchars($d['description']) ?></div><?php endif; ?>
        <?php if (!empty($d['address'])): ?><div class="card-addr"><span>📍</span><span><?= htmlspecialchars($d['address']) ?></span></div><?php endif; ?>
        <div style="font-size:.7rem;color:var(--txt-light);margin-top:4px;">🕐 <?= date('d M Y, h:i A', strtotime($d['created_at'])) ?></div>
      </div>
    </div>
    <?php endwhile; ?>
  </div>
  <div id="noResults" style="display:none;">
    <div class="empty-state">
      <div class="empty-icon">🔍</div>
      <div class="empty-title">No donations in this category</div>
      <div class="empty-text"><button onclick="filterCards('all')" class="btn btn-ghost btn-sm" style="margin-top:8px;">Show all</button></div>
    </div>
  </div>
  <?php endif; ?>

</div></div>

<!-- DONATE MODAL -->
<div class="modal-overlay" id="uploadModal">
  <div class="modal-box" style="max-width:600px;">
    <div class="modal-hdr">
      <div class="modal-title">🍱 Donate Food</div>
      <button class="modal-close"
              onclick="document.getElementById('uploadModal').classList.remove('active')">✕</button>
    </div>
    <div class="modal-body">
      <form action="upload_food.php" method="POST" enctype="multipart/form-data" id="donationForm">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Food Name *</label>
            <input type="text" name="food_name" class="form-control"
                   placeholder="e.g. Idli, Dosa, Bread, Rice" required/>
          </div>
          <div class="form-group">
            <label class="form-label">Category *</label>
            <select name="food_category" class="form-control form-select">
              <option value="veg">🥦 Veg</option>
              <option value="non-veg">🍗 Non-Veg</option>
              <option value="raw">🥕 Raw / Groceries</option>
              <option value="packaged">📦 Packaged Food</option>
              <option value="bakery">🍞 Bakery Items</option>
              <option value="other">🍽️ Other / Mixed</option>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Serves (people) *</label>
            <input type="number" name="total_people" class="form-control"
                   placeholder="e.g. 20" min="1" required/>
          </div>
          <div class="form-group">
            <label class="form-label">Expiry Date & Time *</label>
            <input type="datetime-local" name="expiry_time" class="form-control" required/>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Description</label>
          <textarea name="description" class="form-control" rows="2"
                    placeholder="e.g. Chicken Biryani with raita. Contains nuts. Packed in boxes. Good for 35 adults."></textarea>
          <div class="form-hint">Mention food type, allergens, and packing. Helps NGOs decide quickly.</div>
        </div>

        <div class="form-group">
          <label class="form-label">Pickup Address *</label>
          <input type="text" name="address" id="addrInput" class="form-control"
                 placeholder="Click GPS below, or type your address" required/>
          <div style="margin-top:8px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <button type="button" class="btn btn-blue btn-sm" id="gpsBtn"
                    onclick="captureGPS({btnId:'gpsBtn',statusId:'gpsStatus',addrId:'addrInput',latId:'latVal',lngId:'lngVal'})">
              📍 Get My Live Address
            </button>
            <span id="gpsStatus" style="font-size:.75rem;"></span>
          </div>
          <div class="form-hint">GPS fills the address automatically. Edit if anything looks wrong.</div>
        </div>

        <input type="hidden" name="latitude"  id="latVal"/>
        <input type="hidden" name="longitude" id="lngVal"/>

        <!-- FIX: Single-click food image upload using label wrapping -->
        <div class="form-group">
          <label class="form-label">Food Photo</label>
          <label class="food-photo-label" for="foodImgInput">
            <input type="file" name="food_image" id="foodImgInput" accept="image/*"
                   onchange="prevFoodImg(this)"/>
            <img class="food-photo-preview" id="foodImgPreview" src="" alt=""/>
            <div id="foodImgPH">
              <div style="font-size:1.8rem;">📷</div>
              <div style="font-size:.8rem;font-weight:500;color:var(--txt-mid);margin-top:4px;">Click to add a food photo</div>
              <div style="font-size:.7rem;color:var(--txt-light);">JPG · PNG · WebP · max 5 MB</div>
            </div>
          </label>
        </div>

      </form>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-ghost"
              onclick="document.getElementById('uploadModal').classList.remove('active')">Cancel</button>
      <button type="submit" form="donationForm" class="btn btn-red">🚀 Submit Donation</button>
    </div>
  </div>
</div>


<script>
document.getElementById('uploadModal').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('active');
});

// Food photo preview — single click via label, no double-fire
function prevFoodImg(input) {
  if (input.files && input.files[0]) {
    var r = new FileReader();
    r.onload = function(e) {
      var img = document.getElementById('foodImgPreview');
      var ph  = document.getElementById('foodImgPH');
      img.src = e.target.result;
      img.style.display = 'block';
      if (ph) ph.style.display = 'none';
    };
    r.readAsDataURL(input.files[0]);
  }
}

// Stat card filter
function filterCards(filter) {
  var cards = document.querySelectorAll('.donation-card');
  var grid  = document.getElementById('cardsGrid');
  var noRes = document.getElementById('noResults');
  var label = document.getElementById('filterLabel');
  var lTxt  = document.getElementById('filterText');
  var labels = { uploaded:'⏳ Awaiting NGO', active:'🚛 In Progress', completed:'🎉 Delivered' };
  document.querySelectorAll('.stat-card.clickable').forEach(function(c) { c.classList.remove('active-filter'); });
  var sc = document.getElementById('sc-' + filter);
  if (sc && filter !== 'all') sc.classList.add('active-filter');
  var visible = 0;
  cards.forEach(function(card) {
    var s    = card.getAttribute('data-status');
    var show = filter === 'all'
            || (filter === 'uploaded'  && s === 'uploaded')
            || (filter === 'active'    && (s === 'accepted' || s === 'picked'))
            || (filter === 'completed' && s === 'completed');
    card.classList.toggle('hidden', !show);
    if (show) visible++;
  });
  if (filter !== 'all' && labels[filter]) {
    lTxt.textContent = 'Showing: ' + labels[filter];
    label.classList.add('visible');
  } else { label.classList.remove('visible'); }
  if (grid)  grid.style.display  = visible > 0 ? '' : 'none';
  if (noRes) noRes.style.display = visible === 0 ? '' : 'none';
}
</script>
<script src="/NexFeedAI/gps_helper.js"></script>
</body>
</html>