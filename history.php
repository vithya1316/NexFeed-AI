<?php
session_start();
if(!isset($_SESSION['user_id'])){header('Location: login.php');exit();}
require_once 'db.php';
$uid=(int)$_SESSION['user_id'];$role=$_SESSION['role'];$unread=unread_count($uid);
switch($role){
  case 'donor':$sql="SELECT d.*,del.pickup_image,del.delivery_image,del.pickup_time,del.delivery_time,n.name AS ngo_name,v.name AS vol_name FROM donations d LEFT JOIN deliveries del ON del.donation_id=d.id LEFT JOIN users n ON d.ngo_id=n.id LEFT JOIN users v ON d.volunteer_id=v.id WHERE d.donor_id=$uid AND d.status='completed' ORDER BY del.delivery_time DESC,d.id DESC";break;
  case 'ngo':$sql="SELECT d.*,del.pickup_image,del.delivery_image,del.pickup_time,del.delivery_time,u.name AS donor_name,v.name AS vol_name FROM donations d LEFT JOIN deliveries del ON del.donation_id=d.id JOIN users u ON d.donor_id=u.id LEFT JOIN users v ON d.volunteer_id=v.id WHERE d.ngo_id=$uid AND d.status='completed' ORDER BY del.delivery_time DESC,d.id DESC";break;
  case 'volunteer':$sql="SELECT d.*,del.pickup_image,del.delivery_image,del.pickup_time,del.delivery_time,u.name AS donor_name,n.name AS ngo_name FROM donations d LEFT JOIN deliveries del ON del.donation_id=d.id AND del.volunteer_id=$uid JOIN users u ON d.donor_id=u.id LEFT JOIN users n ON d.ngo_id=n.id WHERE d.volunteer_id=$uid AND d.status='completed' ORDER BY del.delivery_time DESC,d.id DESC";break;
  case 'admin':$sql="SELECT d.*,del.pickup_image,del.delivery_image,del.pickup_time,del.delivery_time,u.name AS donor_name,n.name AS ngo_name,v.name AS vol_name FROM donations d LEFT JOIN deliveries del ON del.donation_id=d.id JOIN users u ON d.donor_id=u.id LEFT JOIN users n ON d.ngo_id=n.id LEFT JOIN users v ON d.volunteer_id=v.id WHERE d.status='completed' ORDER BY del.delivery_time DESC,d.id DESC";break;
  default:header('Location: login.php');exit();
}
$result=$conn->query($sql);$rows=[];$total=0;
if($result){while($r=$result->fetch_assoc()){$total+=(int)($r['total_people']??0);$rows[]=$r;}}
$back=['admin'=>'admin_dashboard.php','donor'=>'donor_dashboard.php','ngo'=>'ngo_dashboard.php','volunteer'=>'volunteer_dashboard.php'][$role]??'index.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>History — NEXFEEDAI</title>
  <link rel="stylesheet" href="style.css"/>
</head>
<body data-page="<?=$role?>">
<nav class="navbar">
  <a href="<?=$back?>" class="nav-brand"><div class="nav-logo">🍽️</div><span class="nav-title">NEXFEED<span>AI</span></span></a>
  <div class="nav-right">
    <a href="notifications.php" class="notif-bell">🔔<?php if($unread):?><span class="notif-count"><?=$unread?></span><?php endif;?></a>
    <span class="nav-username">👤 <?=htmlspecialchars($_SESSION['name'])?></span>
    <span class="role-badge"><?=ucfirst($role)?></span>
    <a href="<?=$back?>" class="btn btn-ghost btn-sm">← Back</a>
    <a href="logout.php"  class="btn btn-ghost btn-sm">🚪 Logout</a>
  </div>
</nav>
<div class="dash-wrap"><div class="dash-main">
  <div class="page-hdr"><div><h1 class="page-title">🎉 Delivery History</h1><p class="page-sub">All completed deliveries</p></div><a href="<?=$back?>" class="btn btn-ghost btn-sm">← Back</a></div>
  <div class="stats-row">
    <div class="stat-card"><div class="stat-icon ic-green">✅</div><div><div class="stat-num"><?=count($rows)?></div><div class="stat-label">Completed</div></div></div>
    <div class="stat-card"><div class="stat-icon ic-orange">👥</div><div><div class="stat-num"><?=$total?></div><div class="stat-label">People Fed</div></div></div>
  </div>
  <?php if(empty($rows)):?>
  <div class="empty-state"><div class="empty-icon">📦</div><div class="empty-title">No completed deliveries</div><div class="empty-text">They will appear here after delivery.</div></div>
  <?php else:?>
  <div class="sec-title">📋 All Deliveries (<?=count($rows)?>)</div>
  <?php foreach($rows as $d):?>
  <div class="hist-card">
    <div class="hist-thumb"><?php if(!empty($d['image_path'])):?><img src="uploads/food/<?=htmlspecialchars(basename($d['image_path']))?>" alt=""/><?php else:?><div class="hist-thumb-ph">🍱</div><?php endif;?></div>
    <div class="hist-body">
      <div class="hist-name"><?=htmlspecialchars($d['food_name'])?></div>
      <div class="hist-meta">
        <span class="chip">🏷️ <?=htmlspecialchars($d['food_category'])?></span>
        <span class="chip">👥 <?=(int)$d['total_people']?> people</span>
        <span class="card-badge b-completed" style="position:relative;top:auto;right:auto;font-size:.67rem;">🎉 Delivered</span>
      </div>
      <?php if($role==='donor'):?><div style="font-size:.79rem;color:var(--txt-mid);margin-bottom:4px;">🏢 <?=htmlspecialchars($d['ngo_name']??'—')?> &nbsp;·&nbsp; 🚚 <?=htmlspecialchars($d['vol_name']??'—')?></div>
      <?php elseif($role==='ngo'):?><div style="font-size:.79rem;color:var(--txt-mid);margin-bottom:4px;">🙋 <?=htmlspecialchars($d['donor_name']??'—')?> &nbsp;·&nbsp; 🚚 <?=htmlspecialchars($d['vol_name']??'—')?></div>
      <?php elseif($role==='volunteer'):?><div style="font-size:.79rem;color:var(--txt-mid);margin-bottom:4px;">🙋 <?=htmlspecialchars($d['donor_name']??'—')?> &nbsp;→&nbsp; 🏢 <?=htmlspecialchars($d['ngo_name']??'—')?></div>
      <?php elseif($role==='admin'):?><div style="font-size:.79rem;color:var(--txt-mid);margin-bottom:4px;">🙋 <?=htmlspecialchars($d['donor_name']??'—')?> → 🏢 <?=htmlspecialchars($d['ngo_name']??'—')?> → 🚚 <?=htmlspecialchars($d['vol_name']??'—')?></div><?php endif;?>
      <?php if(!empty($d['address'])):?><div style="font-size:.75rem;color:var(--txt-light);margin-bottom:6px;">📍 <?=htmlspecialchars($d['address'])?></div><?php endif;?>
      <div class="hist-proof-row">
        <?php if(!empty($d['pickup_image'])):?><a href="uploads/pickup/<?=htmlspecialchars(basename($d['pickup_image']))?>" target="_blank" class="proof-link">📷 Pickup Proof</a><?php endif;?>
        <?php if(!empty($d['delivery_image'])):?><a href="uploads/delivery/<?=htmlspecialchars(basename($d['delivery_image']))?>" target="_blank" class="proof-link">🤝 Delivery Proof</a><?php endif;?>
      </div>
      <div class="hist-time">
        <?php if(!empty($d['pickup_time'])):?>🚚 <?=date('d M Y, h:i A',strtotime($d['pickup_time']))?><?php endif;?>
        <?php if(!empty($d['delivery_time'])):?> &nbsp;·&nbsp; ✅ <?=date('d M Y, h:i A',strtotime($d['delivery_time']))?><?php endif;?>
      </div>
    </div>
  </div>
  <?php endforeach;?>
  <?php endif;?>
</div></div>
</body>
</html>
