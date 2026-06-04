<?php
// register.php — NEXFEEDAI
session_start();
if (isset($_SESSION['user_id'])) { header('Location: index.php'); exit(); }
$err = $_SESSION['register_error'] ?? '';
unset($_SESSION['register_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Register — NEXFEEDAI</title>
  <link rel="stylesheet" href="style.css"/>
</head>
<body data-page="auth">
<div class="particle" style="width:160px;height:160px;background:#1565C0;top:6%;right:3%;animation-delay:.5s;animation-duration:6s;"></div>
<div class="particle" style="width:100px;height:100px;background:#E53935;bottom:8%;left:6%;animation-delay:1.5s;animation-duration:9s;"></div>
<div class="auth-page">
  <div class="auth-card" style="max-width:1000px;">
    <div class="auth-visual">
      <div class="auth-emoji">🤝</div>
      <div class="auth-visual-title">Join the<br>Movement</div>
      <div class="auth-visual-sub">Together we eliminate food waste and feed those in need.</div>
      <div style="margin-top:20px;display:flex;flex-direction:column;gap:9px;width:100%;max-width:230px;position:relative;z-index:1;">
        <div style="background:rgba(255,255,255,.15);border-radius:11px;padding:10px 13px;display:flex;align-items:center;gap:10px;border:1px solid rgba(255,255,255,.2);">
          <span style="font-size:1.2rem;">🙋</span><div><div style="font-size:.81rem;font-weight:600;color:#fff;">Donor</div><div style="font-size:.69rem;color:rgba(255,255,255,.65);">Individual or hotel/restaurant</div></div>
        </div>
        <div style="background:rgba(255,255,255,.15);border-radius:11px;padding:10px 13px;display:flex;align-items:center;gap:10px;border:1px solid rgba(255,255,255,.2);">
          <span style="font-size:1.2rem;">🏢</span><div><div style="font-size:.81rem;font-weight:600;color:#fff;">NGO / Trust</div><div style="font-size:.69rem;color:rgba(255,255,255,.65);">Accept & distribute food</div></div>
        </div>
        <div style="background:rgba(255,255,255,.15);border-radius:11px;padding:10px 13px;display:flex;align-items:center;gap:10px;border:1px solid rgba(255,255,255,.2);">
          <span style="font-size:1.2rem;">🚚</span><div><div style="font-size:.81rem;font-weight:600;color:#fff;">Volunteer</div><div style="font-size:.69rem;color:rgba(255,255,255,.65);">Pick up & deliver</div></div>
        </div>
      </div>
    </div>
    <div class="auth-panel">
      <div class="auth-logo">
        <div class="auth-logo-icon">🍽️</div>
        <div class="auth-logo-text">NEXFEED<span>AI</span></div>
      </div>
      <h1 class="auth-heading">Create account</h1>
      <p class="auth-sub">Join thousands making a difference 🌍</p>
      <?php if ($err): ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($err) ?></div><?php endif; ?>
      <form action="register_process.php" method="POST" enctype="multipart/form-data" id="regForm" autocomplete="off">

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Full Name *</label>
            <input type="text" name="name" class="form-control" placeholder="Contact person name" required autocomplete="off"/>
          </div>
          <div class="form-group">
            <label class="form-label">Phone Number * (10 digits)</label>
            <input type="tel" name="phone" id="phoneInput" class="form-control"
                   placeholder="9876543210"
                   pattern="[0-9]{10}"
                   maxlength="10"
                   required
                   oninput="validatePhone(this)"/>
            <div class="form-hint" id="phoneHint"></div>
          </div>
        </div>

        <div class="form-group" id="orgGroup" style="display:none;">
          <label class="form-label" id="orgLabel">Organisation Name</label>
          <input type="text" name="organization_name" id="orgInput" class="form-control" placeholder="e.g. Hotel Saravana, Smile Foundation"/>
          <div class="form-hint" id="orgHint"></div>
        </div>

        <div class="form-group">
          <label class="form-label">Email Address *</label>
          <input type="email" name="email" class="form-control" placeholder="you@example.com" required autocomplete="off"/>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="form-label">Password *</label>
            <div class="pw-wrap">
              <input type="password" id="pw1" name="password" class="form-control"
                     placeholder="Min 6 characters" required minlength="6"
                     autocomplete="new-password" onpaste="return false;"/>
              <button type="button" class="pw-toggle" onclick="tgl('pw1',this)">👁️</button>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Confirm Password *</label>
            <div class="pw-wrap">
              <input type="password" id="pw2" name="confirm_password" class="form-control"
                     placeholder="Re-type password" required
                     autocomplete="new-password" onpaste="return false;"/>
              <button type="button" class="pw-toggle" onclick="tgl('pw2',this)">👁️</button>
            </div>
          </div>
        </div>
        <div class="form-hint" style="margin-top:-10px;margin-bottom:10px;color:#E65100;">⚠️ Paste disabled on password fields for security.</div>

        <div class="form-group">
          <label class="form-label">Role *</label>
          <select name="role" class="form-control form-select" required onchange="onRoleChange(this.value)">
            <option value="">— Select your role —</option>
            <option value="donor">🙋 Donor — Individual or Hotel/Restaurant</option>
            <option value="ngo">🏢 NGO / Trust — We distribute food</option>
            <option value="volunteer">🚚 Volunteer — I can deliver food</option>
          </select>
          <div class="form-hint" id="roleHint" style="color:var(--accent);font-weight:500;margin-top:5px;"></div>
        </div>

        <div class="form-group">
          <label class="form-label">ID Proof / Document</label>
          <div class="upload-zone" id="uploadZone">
            <input type="file" name="id_proof" id="idProof"
                   accept="image/jpeg,image/png,image/gif,application/pdf"
                   onchange="handleFile(this)"/>
            <div class="upload-zone-icon" id="uzIcon">📎</div>
            <div class="upload-zone-text" id="uzText">Click here to upload your ID proof</div>
            <div class="upload-zone-hint">Aadhaar · PAN · Passport · NGO certificate · JPG/PNG/PDF · Max 5 MB</div>
          </div>
          <div id="fileMsg" style="font-size:.75rem;margin-top:5px;"></div>
        </div>

        <button type="submit" class="btn btn-primary btn-full" id="subBtn">🚀 Create Account</button>
      </form>
      <p class="auth-footer" style="margin-top:12px;">Already have an account? <a href="login.php">Sign in →</a></p>
      <p style="text-align:center;font-size:.69rem;color:var(--txt-light);margin-top:10px;">⚙️ Admin approval required before login.</p>
    </div>
  </div>
</div>
<script>
function tgl(id,btn){var i=document.getElementById(id),v=i.type==='text';i.type=v?'password':'text';btn.textContent=v?'👁️':'';}

// Phone: exactly 10 digits, numbers only
function validatePhone(input) {
  input.value = input.value.replace(/[^0-9]/g, ''); // strip non-digits
  var hint = document.getElementById('phoneHint');
  var len  = input.value.length;
  if (len === 0) {
    hint.textContent = '';
    hint.style.color = '';
  } else if (len < 10) {
    hint.textContent = '⚠️ ' + (10 - len) + ' more digit(s) needed';
    hint.style.color = '#E65100';
    input.style.borderColor = '#E65100';
  } else if (len === 10) {
    hint.textContent = '✅ Looks good!';
    hint.style.color = '#2E7D32';
    input.style.borderColor = '#2E7D32';
  }
}

function onRoleChange(v) {
  var og=document.getElementById('orgGroup'),ol=document.getElementById('orgLabel'),oi=document.getElementById('orgInput'),oh=document.getElementById('orgHint'),rh=document.getElementById('roleHint');
  var hints={donor:'🍱 Upload surplus food from your home or hotel.',ngo:'🏢 Accept donations on behalf of your NGO or Trust.',volunteer:'🚚 Pick up food and deliver to NGOs.'};
  rh.textContent=hints[v]||'';
  if(v==='donor'){og.style.display='block';ol.textContent='Hotel / Restaurant Name (if applicable)';oi.placeholder='e.g. Hotel Saravana Bhavan';oi.required=false;oh.textContent='Leave blank if donating as an individual.';}
  else if(v==='ngo'){og.style.display='block';ol.textContent='NGO / Trust Name *';oi.placeholder='e.g. Smile Foundation, GRY Trust';oi.required=true;oh.textContent='This name will appear on donation cards.';}
  else{og.style.display='none';oi.required=false;oi.value='';}
}

function handleFile(input) {
  var zone=document.getElementById('uploadZone'),msg=document.getElementById('fileMsg');
  if(input.files&&input.files[0]){
    var f=input.files[0],size=(f.size/1024/1024).toFixed(2);
    if(f.size>5*1024*1024){msg.textContent='❌ File too large ('+size+' MB). Max 5 MB.';msg.style.color='var(--red)';zone.classList.remove('has-file');input.value='';return;}
    document.getElementById('uzIcon').textContent='✅';document.getElementById('uzText').textContent=f.name+' ('+size+' MB)';zone.classList.add('has-file');msg.textContent='✅ File selected!';msg.style.color='#2E7D32';
  }
}

document.getElementById('subBtn').addEventListener('click', function(e) {
  var phone = document.getElementById('phoneInput').value;
  if (phone.length !== 10) {
    e.preventDefault();
    alert('⚠️ Phone number must be exactly 10 digits.');
    document.getElementById('phoneInput').focus();
    return;
  }
  var p1=document.getElementById('pw1').value, p2=document.getElementById('pw2').value;
  if(p1&&p2&&p1!==p2){e.preventDefault();alert('⚠️ Passwords do not match!');document.getElementById('pw2').value='';document.getElementById('pw2').focus();}
});
</script>
</body>
</html>
