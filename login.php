<?php
// login.php — NEXFEEDAI
session_start();
if (isset($_SESSION['user_id'])) { header('Location: index.php'); exit(); }
$err = $_SESSION['login_error']      ?? '';
$ok  = $_SESSION['register_success'] ?? '';
unset($_SESSION['login_error'], $_SESSION['register_success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Login — NEXFEEDAI</title>
  <link rel="stylesheet" href="style.css"/>
</head>
<body data-page="auth">
<div class="particle" style="width:180px;height:180px;background:#E53935;top:5%;left:3%;animation-delay:0s;animation-duration:7s;"></div>
<div class="particle" style="width:120px;height:120px;background:#1565C0;top:70%;right:5%;animation-delay:2s;animation-duration:5s;"></div>
<div class="auth-page">
  <div class="auth-card">
    <!-- LEFT -->
    <div class="auth-visual">
      <div class="auth-emoji">🍽️</div>
      <div class="auth-visual-title">Feed Someone<br>Today</div>
      <div class="auth-visual-sub">Smart food redistribution connecting donors, NGOs and volunteers.</div>
      <div class="auth-stats">
        <div class="auth-stat"><div class="auth-stat-num">2.4k</div><div class="auth-stat-label">Meals Saved</div></div>
        <div class="auth-stat"><div class="auth-stat-num">180+</div><div class="auth-stat-label">NGOs</div></div>
        <div class="auth-stat"><div class="auth-stat-num">98%</div><div class="auth-stat-label">Delivered</div></div>
      </div>
      <div style="display:flex;gap:8px;margin-top:20px;flex-wrap:wrap;justify-content:center;position:relative;z-index:1;">
        <div style="background:rgba(255,255,255,.15);padding:7px 13px;border-radius:10px;font-size:.77rem;color:#fff;border:1px solid rgba(255,255,255,.2);">🙋 Donor</div>
        <div style="background:rgba(255,255,255,.15);padding:7px 13px;border-radius:10px;font-size:.77rem;color:#fff;border:1px solid rgba(255,255,255,.2);">🏢 NGO</div>
        <div style="background:rgba(255,255,255,.15);padding:7px 13px;border-radius:10px;font-size:.77rem;color:#fff;border:1px solid rgba(255,255,255,.2);">🚚 Volunteer</div>
      </div>
    </div>
    <!-- RIGHT -->
    <div class="auth-panel">
      <div class="auth-logo">
        <div class="auth-logo-icon">🍽️</div>
        <div class="auth-logo-text">NEXFEED<span>AI</span></div>
      </div>
      <h1 class="auth-heading">Welcome back 👋</h1>
      <p class="auth-sub">Sign in to continue making an impact</p>
      <?php if ($err): ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>
      <?php if ($ok):  ?><div class="alert alert-success">✅ <?= htmlspecialchars($ok) ?></div><?php endif; ?>
      <form action="login_process.php" method="POST" autocomplete="off">
        <div class="form-group">
          <label class="form-label">Email Address</label>
          <input type="email" name="email" class="form-control" placeholder="you@example.com" required autocomplete="email"/>
        </div>
        <div class="form-group">
          <label class="form-label">Password</label>
          <div class="pw-wrap">
            <input type="password" id="pw" name="password" class="form-control" placeholder="Enter your password" required/>
            <button type="button" class="pw-toggle" id="pwtgl">👁️</button>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-full" style="margin-top:6px;">🔑 Sign In</button>
      </form>
      <div class="auth-divider">or</div>
      <p class="auth-footer">Don't have an account? <a href="register.php">Create one free →</a></p>
      <p style="text-align:center;font-size:.69rem;color:var(--txt-light);margin-top:18px;">🔒 Secure · Accounts require admin approval</p>
    </div>
  </div>
</div>
<script>
!function(){var b=document.getElementById('pwtgl'),i=document.getElementById('pw'),v=false;b.onclick=function(){v=!v;i.type=v?'text':'password';b.textContent=v?'🙈':'👁️'};}();
</script>
</body>
</html>
