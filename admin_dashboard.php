<?php
// admin_dashboard.php — NEXFEEDAI
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    session_destroy(); header('Location: login.php'); exit();
}
require_once 'db.php';

$success = get_flash('success');
$error   = get_flash('error');
$uid     = (int)$_SESSION['user_id'];
$unread  = unread_count($uid);

// Users by status
$pending  = $conn->query("SELECT * FROM users WHERE verification_status='pending'  AND role!='admin' ORDER BY id DESC");
$rejected = $conn->query("SELECT * FROM users WHERE verification_status='rejected' AND role!='admin' ORDER BY id DESC");

// Users by role — APPROVED ONLY in the role sections
$donors     = $conn->query("SELECT * FROM users WHERE role='donor'     AND verification_status='approved' ORDER BY id DESC");
$ngos       = $conn->query("SELECT * FROM users WHERE role='ngo'       AND verification_status='approved' ORDER BY id DESC");
$volunteers = $conn->query("SELECT * FROM users WHERE role='volunteer' AND verification_status='approved' ORDER BY id DESC");

$allDons = $conn->query(
    "SELECT d.*, u.name AS donor_name, u.organization_name AS donor_org,
            n.name AS ngo_name, n.organization_name AS ngo_org,
            v.name AS vol_name
     FROM donations d
     JOIN users u ON d.donor_id=u.id
     LEFT JOIN users n ON d.ngo_id=n.id
     LEFT JOIN users v ON d.volunteer_id=v.id
     ORDER BY d.id DESC LIMIT 100"
);

// Stats — approved only
$stats = [];
foreach (['donor','ngo','volunteer'] as $r) {
    $stats[$r] = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role='$r' AND verification_status='approved'")->fetch_assoc()['c'];
}
$donTotal = (int)$conn->query("SELECT COUNT(*) c FROM donations")->fetch_assoc()['c'];
$donDone  = (int)$conn->query("SELECT COUNT(*) c FROM donations WHERE status='completed'")->fetch_assoc()['c'];

function sBadge(string $s): string {
    $m = [
        'uploaded'  => ['b-uploaded',  'Awaiting NGO'],
        'accepted'  => ['b-accepted',  'Accepted'],
        'picked'    => ['b-picked',    'In Transit'],
        'delivered' => ['b-delivered', 'Awaiting Confirm'],
        'completed' => ['b-completed', 'Delivered'],
        'rejected'  => ['b-rejected',  'Rejected'],
    ];
    [$c,$t] = $m[$s] ?? ['b-uploaded', ucfirst($s)];
    return "<span class=\"card-badge $c\" style=\"position:relative;top:auto;right:auto;font-size:.67rem;\">$t</span>";
}

// Render a user table row
// $showActions = show approve/reject buttons (for pending tab)
// $showRestore = show re-approve button (for rejected tab)
function userRow(array $u, bool $showActions = false, bool $showRestore = false): string {
    $org    = !empty($u['organization_name'])
        ? '<div style="font-size:.71rem;color:var(--accent);font-weight:500;">🏢 '.htmlspecialchars($u['organization_name']).'</div>'
        : '';
    $vs     = $u['verification_status'];
    $vbadge = '<span class="vbadge vb-'.$vs.'">'.($vs==='approved'?'✅':($vs==='rejected'?'✖':'⏳')).' '.ucfirst($vs).'</span>';
    $proof  = !empty($u['id_proof'])
        ? '<a href="uploads/id_proofs/'.htmlspecialchars(basename($u['id_proof'])).'" target="_blank" class="proof-link">🪪 View</a>'
        : '—';
    $avail  = $u['role'] === 'volunteer'
        ? ucfirst(str_replace('_',' ', $u['availability'] ?? 'available'))
        : '—';

    $actions = '';
    if ($showActions) {
        $actions = '<td><div style="display:flex;gap:5px;">
            <a href="approve_user.php?id='.$u['id'].'" class="btn btn-accept btn-sm" onclick="return confirm(\'Approve?\')">✅ Approve</a>
            <a href="reject_user.php?id='.$u['id'].'"  class="btn btn-reject btn-sm" onclick="return confirm(\'Reject?\')">✖ Reject</a>
        </div></td>';
    } elseif ($showRestore) {
        $actions = '<td>
            <a href="approve_user.php?id='.$u['id'].'" class="btn btn-accept btn-sm" onclick="return confirm(\'Re-approve this user?\')">✅ Re-approve</a>
        </td>';
    }

    return '<tr>
        <td style="color:var(--txt-light);font-size:.75rem;">#'.$u['id'].'</td>
        <td>
          <div style="display:flex;align-items:center;gap:9px;">
            <div class="tbl-avatar">'.strtoupper(substr($u['name'],0,1)).'</div>
            <div>
              <div style="font-weight:600;font-size:.83rem;">'.htmlspecialchars($u['name']).'</div>
              '.$org.'
              <div style="font-size:.71rem;color:var(--txt-light);">'.htmlspecialchars($u['email']).'</div>
            </div>
          </div>
        </td>
        <td><span class="role-badge">'.$u['role'].'</span></td>
        <td style="font-size:.82rem;">'.htmlspecialchars($u['phone']).'</td>
        <td>'.$vbadge.'</td>
        <td style="font-size:.79rem;color:var(--txt-mid);">'.$avail.'</td>
        <td>'.$proof.'</td>
        '.($showActions || $showRestore ? $actions : '').'
    </tr>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Admin Dashboard — NEXFEEDAI</title>
  <link rel="stylesheet" href="style.css"/>
  <style>
    .stat-card.clickable{cursor:pointer}
    .stat-card.clickable.active-filter{border-color:var(--accent);box-shadow:var(--sh-md);background:var(--accent-bg)}
    .stat-card.clickable.active-filter .stat-num{color:var(--accent)}
    .role-section{margin-bottom:32px}
    .role-section-title{font-size:.9rem;font-weight:700;color:var(--white);background:linear-gradient(135deg,var(--accent),var(--accent-dk));padding:8px 16px;border-radius:8px;margin-bottom:10px;display:inline-flex;align-items:center;gap:7px}
    /* Rejected section uses muted red */
    .role-section-title.rejected-hdr{background:linear-gradient(135deg,#757575,#424242)}
    .role-section-title.pending-hdr{background:linear-gradient(135deg,#E65100,#BF360C)}
  </style>
</head>
<body data-page="admin">

<nav class="navbar">
  <a href="admin_dashboard.php" class="nav-brand">
    <div class="nav-logo">🍽️</div>
    <span class="nav-title">NEXFEED<span>AI</span></span>
  </a>
  <div class="nav-right">
    <a href="notifications.php" class="notif-bell">
      🔔<?php if ($unread): ?><span class="notif-count"><?= $unread ?></span><?php endif; ?>
    </a>
    <span class="nav-username">👤 <?= htmlspecialchars($_SESSION['name']) ?></span>
    <span class="role-badge">Admin</span>
    <a href="logout.php" class="btn btn-ghost btn-sm">🚪 Logout</a>
  </div>
</nav>

<div class="dash-wrap"><div class="dash-main">

  <?php if ($success): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success) ?></div><?php endif; ?>
  <?php if ($error):   ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="page-hdr">
    <div>
      <h1 class="page-title">Admin Dashboard ⚙️</h1>
      <p class="page-sub">User approvals · System overview · Donation tracking</p>
    </div>
  </div>

  <!-- STATS — click role cards to jump to that section -->
  <div class="stats-row">
    <div class="stat-card clickable" onclick="showUserTab('donors')" title="View approved donors">
      <div class="stat-icon ic-red">🙋</div>
      <div><div class="stat-num"><?= $stats['donor'] ?></div><div class="stat-label">Donors</div></div>
    </div>
    <div class="stat-card clickable" onclick="showUserTab('ngos')" title="View approved NGOs">
      <div class="stat-icon ic-blue">🏢</div>
      <div><div class="stat-num"><?= $stats['ngo'] ?></div><div class="stat-label">NGOs / Trusts</div></div>
    </div>
    <div class="stat-card clickable" onclick="showUserTab('volunteers')" title="View approved volunteers">
      <div class="stat-icon ic-orange">🚚</div>
      <div><div class="stat-num"><?= $stats['volunteer'] ?></div><div class="stat-label">Volunteers</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon ic-purple">🍽️</div>
      <div><div class="stat-num"><?= $donTotal ?></div><div class="stat-label">Donations</div></div>
    </div>
    <div class="stat-card">
      <div class="stat-icon ic-green">🎉</div>
      <div><div class="stat-num"><?= $donDone ?></div><div class="stat-label">Delivered</div></div>
    </div>
    <div class="stat-card" style="<?= $pending->num_rows > 0 ? 'border-color:#FFE082;' : '' ?>" onclick="sw(event,'t-pending');return false;" style="cursor:pointer;">
      <div class="stat-icon ic-orange">⏳</div>
      <div>
        <div class="stat-num" style="<?= $pending->num_rows > 0 ? 'color:#E65100;' : '' ?>"><?= $pending->num_rows ?></div>
        <div class="stat-label">Pending</div>
      </div>
    </div>
    <div class="stat-card" style="<?= $rejected->num_rows > 0 ? 'border-color:#FFCDD2;' : '' ?>">
      <div class="stat-icon ic-red">✖</div>
      <div>
        <div class="stat-num" style="<?= $rejected->num_rows > 0 ? 'color:#C62828;' : '' ?>"><?= $rejected->num_rows ?></div>
        <div class="stat-label">Rejected</div>
      </div>
    </div>
  </div>

  <!-- TABS -->
  <div class="tab-bar">
    <button class="tab-btn active" onclick="sw(event,'t-pending')" id="tabPending">
      ⏳ Pending (<?= $pending->num_rows ?>)
    </button>
    <button class="tab-btn" onclick="sw(event,'t-users')" id="tabUsers">
      👥 Approved Users
    </button>
    <button class="tab-btn" onclick="sw(event,'t-rejected')" id="tabRejected">
      ✖ Rejected (<?= $rejected->num_rows ?>)
    </button>
    <button class="tab-btn" onclick="sw(event,'t-donations')" id="tabDons">
      🍽️ All Donations
    </button>
  </div>

  <!-- PENDING TAB -->
  <div class="tab-panel active" id="t-pending">
    <div class="sec-title">⏳ Awaiting Approval</div>
    <?php if ($pending->num_rows === 0): ?>
    <div class="empty-state">
      <div class="empty-icon">✅</div>
      <div class="empty-title">All caught up!</div>
      <div class="empty-text">No pending requests.</div>
    </div>
    <?php else: ?>
    <div class="tbl-wrap"><table class="data-tbl">
      <thead><tr><th>#</th><th>User / Organisation</th><th>Role</th><th>Phone</th><th>Status</th><th>Avail.</th><th>ID Proof</th><th>Actions</th></tr></thead>
      <tbody>
      <?php $pending->data_seek(0); while ($u = $pending->fetch_assoc()): ?>
      <?= userRow($u, true, false) ?>
      <?php endwhile; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <!-- APPROVED USERS TAB — separated by role, NO rejected mixed in -->
  <div class="tab-panel" id="t-users">

    <div class="role-section" id="sec-donors">
      <div class="role-section-title">🙋 Approved Donors (<?= $donors->num_rows ?>)</div>
      <?php if ($donors->num_rows === 0): ?>
      <div class="alert alert-info">No approved donors yet.</div>
      <?php else: ?>
      <div class="tbl-wrap"><table class="data-tbl">
        <thead><tr><th>#</th><th>User / Organisation</th><th>Role</th><th>Phone</th><th>Status</th><th>Avail.</th><th>ID Proof</th></tr></thead>
        <tbody>
        <?php while ($u = $donors->fetch_assoc()): ?>
        <?= userRow($u) ?>
        <?php endwhile; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>

    <div class="role-section" id="sec-ngos">
      <div class="role-section-title">🏢 Approved NGOs / Trusts (<?= $ngos->num_rows ?>)</div>
      <?php if ($ngos->num_rows === 0): ?>
      <div class="alert alert-info">No approved NGOs yet.</div>
      <?php else: ?>
      <div class="tbl-wrap"><table class="data-tbl">
        <thead><tr><th>#</th><th>User / Organisation</th><th>Role</th><th>Phone</th><th>Status</th><th>Avail.</th><th>ID Proof</th></tr></thead>
        <tbody>
        <?php while ($u = $ngos->fetch_assoc()): ?>
        <?= userRow($u) ?>
        <?php endwhile; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>

    <div class="role-section" id="sec-volunteers">
      <div class="role-section-title">🚚 Approved Volunteers (<?= $volunteers->num_rows ?>)</div>
      <?php if ($volunteers->num_rows === 0): ?>
      <div class="alert alert-info">No approved volunteers yet.</div>
      <?php else: ?>
      <div class="tbl-wrap"><table class="data-tbl">
        <thead><tr><th>#</th><th>User / Organisation</th><th>Role</th><th>Phone</th><th>Status</th><th>Availability</th><th>ID Proof</th></tr></thead>
        <tbody>
        <?php while ($u = $volunteers->fetch_assoc()): ?>
        <?= userRow($u) ?>
        <?php endwhile; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </div>

  </div>

  <!-- REJECTED TAB — separate, with re-approve option -->
  <div class="tab-panel" id="t-rejected">
    <div class="sec-title" style="color:#C62828;">✖ Rejected Accounts</div>
    <?php if ($rejected->num_rows === 0): ?>
    <div class="empty-state">
      <div class="empty-icon">✅</div>
      <div class="empty-title">No rejected accounts</div>
      <div class="empty-text">All users are either approved or pending.</div>
    </div>
    <?php else: ?>
    <div class="alert alert-warning" style="margin-bottom:16px;">
      ⚠️ These accounts were rejected. You can re-approve them if needed.
    </div>
    <div class="tbl-wrap"><table class="data-tbl">
      <thead><tr><th>#</th><th>User / Organisation</th><th>Role</th><th>Phone</th><th>Status</th><th>Avail.</th><th>ID Proof</th><th>Action</th></tr></thead>
      <tbody>
      <?php while ($u = $rejected->fetch_assoc()): ?>
      <?= userRow($u, false, true) ?>
      <?php endwhile; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <!-- DONATIONS TAB -->
  <div class="tab-panel" id="t-donations">
    <div class="sec-title">🍽️ All Donations (last 100)</div>
    <div class="tbl-wrap"><table class="data-tbl">
      <thead><tr><th>#</th><th>Food</th><th>Donor</th><th>NGO</th><th>Volunteer</th><th>People</th><th>Status</th><th>Date</th></tr></thead>
      <tbody>
      <?php while ($d = $allDons->fetch_assoc()): ?>
      <tr>
        <td style="color:var(--txt-light);font-size:.75rem;">#<?= $d['id'] ?></td>
        <td>
          <div style="display:flex;align-items:center;gap:8px;">
            <?php if (!empty($d['image_path'])): ?>
            <img src="uploads/food/<?= htmlspecialchars(basename($d['image_path'])) ?>"
                 style="width:34px;height:34px;object-fit:cover;border-radius:6px;flex-shrink:0;" alt=""/>
            <?php else: ?>
            <div style="width:34px;height:34px;background:var(--accent-bg);border-radius:6px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">🍱</div>
            <?php endif; ?>
            <div>
              <div style="font-weight:600;font-size:.82rem;"><?= htmlspecialchars($d['food_name']) ?></div>
              <div style="font-size:.71rem;color:var(--txt-light);"><?= htmlspecialchars($d['food_category']) ?></div>
            </div>
          </div>
        </td>
        <td style="font-size:.81rem;">
          <?= htmlspecialchars($d['donor_name'] ?? '—') ?>
          <?php if (!empty($d['donor_org'])): ?><br><span style="font-size:.69rem;color:var(--txt-light);"><?= htmlspecialchars($d['donor_org']) ?></span><?php endif; ?>
        </td>
        <td style="font-size:.81rem;">
          <?= htmlspecialchars($d['ngo_name'] ?? '—') ?>
          <?php if (!empty($d['ngo_org'])): ?><br><span style="font-size:.69rem;color:var(--txt-light);"><?= htmlspecialchars($d['ngo_org']) ?></span><?php endif; ?>
        </td>
        <td style="font-size:.81rem;"><?= htmlspecialchars($d['vol_name'] ?? '—') ?></td>
        <td style="font-size:.81rem;text-align:center;"><?= (int)$d['total_people'] ?></td>
        <td><?= sBadge($d['status']) ?></td>
        <td style="font-size:.73rem;color:var(--txt-light);"><?= date('d M Y', strtotime($d['created_at'])) ?></td>
      </tr>
      <?php endwhile; ?>
      </tbody>
    </table></div>
  </div>

</div></div>

<script>
function sw(e, id) {
  document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
  document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
  if (e && e.currentTarget) e.currentTarget.classList.add('active');
  document.getElementById(id).classList.add('active');
  document.querySelectorAll('.stat-card.clickable').forEach(function(c){ c.classList.remove('active-filter'); });
}

function showUserTab(section) {
  document.querySelectorAll('.tab-btn').forEach(function(b){ b.classList.remove('active'); });
  document.querySelectorAll('.tab-panel').forEach(function(p){ p.classList.remove('active'); });
  document.getElementById('tabUsers').classList.add('active');
  document.getElementById('t-users').classList.add('active');
  var secMap = { donors:'sec-donors', ngos:'sec-ngos', volunteers:'sec-volunteers' };
  var sec = document.getElementById(secMap[section]);
  if (sec) setTimeout(function(){ sec.scrollIntoView({ behavior:'smooth', block:'start' }); }, 100);
}
</script>
</body>
</html>
