<?php
// register_process.php — NEXFEEDAI
session_start();
require_once 'db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { redirect('register.php'); }

$name     = trim($_POST['name'] ?? '');
$email    = strtolower(trim($_POST['email'] ?? ''));
$password = trim($_POST['password'] ?? '');
$confirm  = trim($_POST['confirm_password'] ?? '');
$role     = trim($_POST['role'] ?? '');
$org      = trim($_POST['organization_name'] ?? '');

// Strip everything except digits from phone for validation
$phoneRaw  = trim($_POST['phone'] ?? '');
$phoneClean= preg_replace('/[^0-9]/', '', $phoneRaw);

$errs = [];
if (!$name)                                     $errs[] = 'Full name is required.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errs[] = 'A valid email is required.';
if (strlen($password) < 6)                      $errs[] = 'Password must be at least 6 characters.';
if ($password !== $confirm)                     $errs[] = 'Passwords do not match.';

// ── Phone: exactly 10 digits ──────────────────────────────────
if (strlen($phoneClean) !== 10) {
    $errs[] = 'Phone number must be exactly 10 digits (e.g. 9876543210).';
}

if (!in_array($role, ['donor','ngo','volunteer'])) $errs[] = 'Please select a valid role.';
if ($role === 'ngo' && empty($org))               $errs[] = 'NGO/Trust name is required.';

if ($errs) { flash('register_error', implode(' · ', $errs)); redirect('register.php'); }

// Duplicate email check
$chk = $conn->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
$chk->bind_param('s', $email); $chk->execute(); $chk->store_result();
if ($chk->num_rows) { flash('register_error', 'An account with that email already exists.'); redirect('register.php'); }
$chk->close();

// ID Proof upload
$id_proof = '';
if (!empty($_FILES['id_proof']['tmp_name'])) {
    $f = $_FILES['id_proof']; $mime = mime_content_type($f['tmp_name']);
    if (!in_array($mime, ['image/jpeg','image/png','image/gif','application/pdf'])) {
        flash('register_error', 'ID Proof must be JPG, PNG or PDF.'); redirect('register.php');
    }
    if ($f['size'] > 5 * 1024 * 1024) { flash('register_error', 'ID Proof must be under 5 MB.'); redirect('register.php'); }
    $dir = __DIR__ . '/uploads/id_proofs/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $fn  = 'id_' . uniqid() . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . $fn)) { flash('register_error', 'Failed to save ID proof.'); redirect('register.php'); }
    $id_proof = $fn;
}

$hash = password_hash($password, PASSWORD_BCRYPT);

// Try insert with organization_name column
$stmt = $conn->prepare(
    'INSERT INTO users (name, organization_name, email, password, phone, role, id_proof, verification_status, availability)
     VALUES (?, ?, ?, ?, ?, ?, ?, \'pending\', \'available\')'
);
if ($stmt) {
    $stmt->bind_param('sssssss', $name, $org, $email, $hash, $phoneClean, $role, $id_proof);
    if ($stmt->execute()) {
        flash('register_success', '✅ Account created! Awaiting admin approval. You can login once approved.');
        redirect('login.php');
    }
    $stmt->close();
}

// Fallback without org_name column
$stmt2 = $conn->prepare(
    'INSERT INTO users (name, email, password, phone, role, id_proof, verification_status, availability)
     VALUES (?, ?, ?, ?, ?, ?, \'pending\', \'available\')'
);
$stmt2->bind_param('ssssss', $name, $email, $hash, $phoneClean, $role, $id_proof);
if ($stmt2->execute()) {
    flash('register_success', '✅ Account created! Awaiting admin approval.');
    redirect('login.php');
}
$stmt2->close();
flash('register_error', 'Registration failed. Please try again.'); redirect('register.php');
