<?php
// notifications.php — NEXFEEDAI
// ISSUE 4 FIX: Shows event_type based icons/labels (not wrong priority levels)
// ISSUE 5 FIX: Shows FULL history — no login_time filter, grouped by date
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit(); }
require_once 'db.php';

$uid  = (int)$_SESSION['user_id'];
$role = $_SESSION['role'];

// Mark single read
if (isset($_GET['read'])) {
    $nid = safe_int($_GET['read']);
    if ($nid) {
        $s = $conn->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?");
        $s->bind_param('ii', $nid, $uid); $s->execute(); $s->close();
    }
    redirect('notifications.php');
}
// Mark all read
if (isset($_GET['all'])) {
    $conn->query("UPDATE notifications SET is_read=1 WHERE user_id=$uid");
    redirect('notifications.php');
}

// ── ISSUE 5 FIX: Fetch FULL history — no login_time filter ───
// Old bug: was filtering by $_SESSION['login_time'] so only showed
// notifications from the current login session.
// Fix: remove that filter completely — show all, ordered newest first.
$notifs = $conn->query(
    "SELECT n.*, d.food_name
     FROM notifications n
     LEFT JOIN donations d ON d.id = n.donation_id
     WHERE n.user_id = $uid
     ORDER BY n.created_at DESC
     LIMIT 200"
);

$unread = unread_count($uid);
$back   = ['admin'=>'admin_dashboard.php','donor'=>'donor_dashboard.php',
           'ngo'=>'ngo_dashboard.php','volunteer'=>'volunteer_dashboard.php'][$role] ?? 'index.php';

// ── ISSUE 4 FIX: Event-type icons and labels ─────────────────
// Instead of "High / Normal / Info" we show what actually happened
$eventConfig = [
    'donation_uploaded'  => ['icon'=>'🍱', 'label'=>'New Donation',      'color'=>'#1565C0', 'bg'=>'#E3F2FD'],
    'donation_accepted'  => ['icon'=>'✅', 'label'=>'Donation Accepted',  'color'=>'#2E7D32', 'bg'=>'#E8F5E9'],
    'donation_rejected'  => ['icon'=>'↩',  'label'=>'Donation Passed',    'color'=>'#E65100', 'bg'=>'#FFF8E1'],
    'volunteer_assigned' => ['icon'=>'👤', 'label'=>'Volunteer Assigned', 'color'=>'#6A1B9A', 'bg'=>'#F3E5F5'],
    'job_available'      => ['icon'=>'📦', 'label'=>'Job Available',      'color'=>'#1565C0', 'bg'=>'#E3F2FD'],
    'picked_up'          => ['icon'=>'🚛', 'label'=>'Picked Up',          'color'=>'#7B1FA2', 'bg'=>'#F3E5F5'],
    'delivered'          => ['icon'=>'🎉', 'label'=>'Delivered!',         'color'=>'#1B5E20', 'bg'=>'#E8F5E9'],
    'account_approved'   => ['icon'=>'🎊', 'label'=>'Account Approved',   'color'=>'#1B5E20', 'bg'=>'#E8F5E9'],
    'account_rejected'   => ['icon'=>'❌', 'label'=>'Account Rejected',   'color'=>'#C62828', 'bg'=>'#FFEBEE'],
    'general'            => ['icon'=>'🔔', 'label'=>'Notification',       'color'=>'#455A64', 'bg'=>'#ECEFF1'],
];

// ── ISSUE 5 FIX: Group notifications by date ─────────────────
$groups = ['Today' => [], 'Yesterday' => [], 'Older' => []];
$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));

$allNotifs = [];
while ($n = $notifs->fetch_assoc()) $allNotifs[] = $n;

foreach ($allNotifs as $n) {
    $date = date('Y-m-d', strtotime($n['created_at']));
    if ($date === $today)     $groups['Today'][]     = $n;
    elseif ($date === $yesterday) $groups['Yesterday'][] = $n;
    else                          $groups['Older'][]     = $n;
}

$totalCount = count($allNotifs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Notifications — NEXFEEDAI</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    /* ISSUE 4 FIX: Event-type label style — no priority level labels */
    .event-badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: .67rem;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 99px;
      margin-right: 6px;
      white-space: nowrap;
    }
    /* ISSUE 5 FIX: Date group heading */
    .date-group-heading {
      font-size: .78rem;
      font-weight: 700;
      color: var(--txt-light);
      text-transform: uppercase;
      letter-spacing: .6px;
      padding: 16px 0 8px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .date-group-heading::after {
      content: '';
      flex: 1;
      height: 1px;
      background: var(--border);
    }
    .notif-item.unread {
      border-left: 4px solid var(--red);
      background: linear-gradient(135deg, #FFFAF9, var(--white));
    }
    .notif-item .notif-dot {
      width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; margin-top: 5px;
    }
    .nd-unread { background: var(--red); }
    .nd-read   { background: #DDD; }
  </style>
</head>
<body data-page="<?= $role ?>">

<nav class="navbar">
  <a href="<?= $back ?>" class="nav-brand">
    <div class="nav-logo">🍽️</div>
    <span class="nav-title">NEXFEED<span>AI</span></span>
  </a>
  <div class="nav-right">
    <span class="nav-username">👤 <?= htmlspecialchars($_SESSION['name']) ?></span>
    <span class="role-badge"><?= ucfirst($role) ?></span>
    <a href="<?= $back ?>" class="btn btn-ghost btn-sm">← Back</a>
    <a href="logout.php"   class="btn btn-ghost btn-sm">🚪 Logout</a>
  </div>
</nav>

<div class="dash-wrap"><div class="dash-main">

  <div class="page-hdr">
    <div>
      <h1 class="page-title">🔔 Notifications</h1>
      <!-- ISSUE 5 FIX: Shows total, not "this session" -->
      <p class="page-sub"><?= $unread ?> unread · <?= $totalCount ?> total</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <?php if ($unread > 0): ?>
      <a href="notifications.php?all=1" class="btn btn-blue btn-sm">✅ Mark all read</a>
      <?php endif; ?>
      <a href="<?= $back ?>" class="btn btn-ghost btn-sm">← Back</a>
    </div>
  </div>

  <?php if ($totalCount === 0): ?>
  <div class="empty-state">
    <div class="empty-icon">🔔</div>
    <div class="empty-title">No notifications yet</div>
    <div class="empty-text">You'll be notified here when actions happen.</div>
  </div>

  <?php else: ?>

  <!-- ISSUE 5 FIX: Grouped by Today / Yesterday / Older -->
  <?php foreach ($groups as $groupName => $groupItems): ?>
    <?php if (empty($groupItems)) continue; ?>

    <div class="date-group-heading">
      <?php
      if ($groupName === 'Today')     echo '📅 Today';
      elseif ($groupName === 'Yesterday') echo '📅 Yesterday';
      else echo '📅 Older';
      ?>
      <span style="font-size:.71rem;font-weight:400;color:var(--txt-light);">
        (<?= count($groupItems) ?>)
      </span>
    </div>

    <div class="notif-list" style="margin-bottom:8px;">
      <?php foreach ($groupItems as $n):
        $et  = $n['event_type'] ?? 'general';
        $cfg = $eventConfig[$et] ?? $eventConfig['general'];
        $read = (bool)$n['is_read'];
      ?>
      <div class="notif-item <?= !$read ? 'unread' : '' ?>"
           style="opacity:<?= $read ? '.78' : '1' ?>;">
        <div class="notif-dot <?= !$read ? 'nd-unread' : 'nd-read' ?>"></div>

        <!-- ISSUE 4 FIX: Event-type icon in a colored badge, not priority label -->
        <div style="font-size:1.1rem;flex-shrink:0;"><?= $cfg['icon'] ?></div>

        <div class="notif-content" style="flex:1;">
          <div class="notif-msg">
            <span class="event-badge"
                  style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>;border:1px solid <?= $cfg['color'] ?>33;">
              <?= htmlspecialchars($cfg['label']) ?>
            </span>
            <?= htmlspecialchars($n['message']) ?>
          </div>
          <div class="notif-time" style="display:flex;align-items:center;gap:10px;margin-top:3px;">
            <span>🕐 <?= date('d M Y, h:i A', strtotime($n['created_at'])) ?></span>
            <?php if (!empty($n['food_name'])): ?>
            <span style="font-size:.69rem;color:var(--txt-light);">re: <?= htmlspecialchars($n['food_name']) ?></span>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!$read): ?>
        <a href="notifications.php?read=<?= $n['id'] ?>"
           class="btn btn-blue btn-sm" style="flex-shrink:0;white-space:nowrap;">
          ✓ Read
        </a>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <?php endif; ?>
</div></div>
</body>
</html>
