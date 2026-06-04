<?php

if (defined('EMAIL_HELPER_LOADED')) return;
define('EMAIL_HELPER_LOADED', true);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';
require_once __DIR__ . '/phpmailer/src/Exception.php';

// ✅ YOUR CONFIG
define('GMAIL_USER',      'nexfeedaisystem@gmail.com');
define('GMAIL_PASS',      'oypbsfqrmtgsboxe'); // no spaces
define('GMAIL_FROM_NAME', 'NEXFEEDAI Team');


// ── Core send function ─────────────────────────────────────────
function send_email(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    try {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = GMAIL_USER;
        $mail->Password   = GMAIL_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(GMAIL_USER, GMAIL_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('Email failed: ' . $e->getMessage());
        return false;
    }
}

// ── Internal helper: fetch name + email by user ID ────────────
function _get_user_email_name(int $uid): array {
    global $conn;
    $s = $conn->prepare('SELECT name, email FROM users WHERE id=? LIMIT 1');
    if (!$s) return ['', ''];
    $s->bind_param('i', $uid);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    $s->close();
    return [$row['name'] ?? '', $row['email'] ?? ''];
}

// ── 1. Account approved (admin → user) ────────────────────────
function email_account_approved(int $userId): bool {
    [$name, $email] = _get_user_email_name($userId);
    if (!$email) return false;

    $body = "
        <div style='font-family:sans-serif;max-width:520px;margin:auto'>
        <h2 style='color:#16a34a'>🎊 Your NEXFEEDAI account is approved!</h2>
        <p>Hi <strong>" . htmlspecialchars($name) . "</strong>,</p>
        <p>Great news — your account has been <strong>approved</strong> by our admin team.</p>
        <p>You can now <a href='http://localhost/NexFeedAI/login.php'>log in here</a> and start using the platform.</p>
        <p style='color:#6b7280;font-size:13px'>— NEXFEEDAI Team</p>
        </div>";

    return send_email($email, $name, '✅ Your NEXFEEDAI Account is Approved', $body);
}

// ── 2. Account rejected (admin → user) ────────────────────────
function email_account_rejected(int $userId): bool {
    [$name, $email] = _get_user_email_name($userId);
    if (!$email) return false;

    $body = "
        <div style='font-family:sans-serif;max-width:520px;margin:auto'>
        <h2 style='color:#dc2626'>❌ NEXFEEDAI Account Update</h2>
        <p>Hi <strong>" . htmlspecialchars($name) . "</strong>,</p>
        <p>Unfortunately your account application could not be approved at this time.</p>
        <p>Please contact us if you believe this is an error.</p>
        <p style='color:#6b7280;font-size:13px'>— NEXFEEDAI Team</p>
        </div>";

    return send_email($email, $name, 'NEXFEEDAI Account Status Update', $body);
}

// ── 3. Notify volunteer of a pickup job ───────────────────────
function email_volunteer_job(int $volId, string $foodName, int $totalPeople,
                              string $address, string $ngoName, float $distKm = 0): bool {
    [$name, $email] = _get_user_email_name($volId);
    if (!$email) return false;

    $distLine = ($distKm > 0)
        ? "<p>📍 Distance from you: <strong>" . round($distKm, 1) . " km</strong></p>"
        : '';
    $addrLine = $address
        ? "<p>📌 Pickup address: <strong>" . htmlspecialchars($address) . "</strong></p>"
        : '';

    $body = "
        <div style='font-family:sans-serif;max-width:520px;margin:auto'>
        <h2 style='color:#d97706'>📦 New Pickup Job Available</h2>
        <p>Hi <strong>" . htmlspecialchars($name) . "</strong>,</p>
        <p>A new food donation has been accepted by <strong>" . htmlspecialchars($ngoName) . "</strong>
           and needs a volunteer for pickup.</p>
        <table style='border-collapse:collapse;width:100%'>
          <tr><td style='padding:6px 0'><strong>Food:</strong></td><td>" . htmlspecialchars($foodName) . "</td></tr>
          <tr><td style='padding:6px 0'><strong>Serves:</strong></td><td>{$totalPeople} people</td></tr>
        </table>
        {$addrLine}
        {$distLine}
        <p><a href='http://localhost/NexFeedAI/volunteer_dashboard.php'
              style='background:#d97706;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none'>
           View on Dashboard →</a></p>
        <p style='color:#6b7280;font-size:13px'>— NEXFEEDAI Team</p>
        </div>";

    return send_email($email, $name, "📦 Pickup Job: {$foodName}", $body);
}

// ── 4. Notify donor that their donation was accepted ──────────
function email_donor_accepted(int $donorId, string $foodName, string $ngoName): bool {
    [$name, $email] = _get_user_email_name($donorId);
    if (!$email) return false;

    $body = "
        <div style='font-family:sans-serif;max-width:520px;margin:auto'>
        <h2 style='color:#16a34a'>✅ Your Donation Was Accepted!</h2>
        <p>Hi <strong>" . htmlspecialchars($name) . "</strong>,</p>
        <p>Great news! Your donation <strong>\"" . htmlspecialchars($foodName) . "\"</strong>
           has been accepted by <strong>" . htmlspecialchars($ngoName) . "</strong>.</p>
        <p>A volunteer will be assigned to pick it up shortly. Thank you for your generosity!</p>
        <p><a href='http://localhost/NexFeedAI/donor_dashboard.php'
              style='background:#16a34a;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none'>
           View on Dashboard →</a></p>
        <p style='color:#6b7280;font-size:13px'>— NEXFEEDAI Team</p>
        </div>";

    return send_email($email, $name, "✅ Donation Accepted: {$foodName}", $body);
}

// ── 5. Notify NGO of a new donation nearby ────────────────────
function send_donation_notification(string $toEmail, string $toName, string $foodName,
                                     string $donorName, string $address,
                                     string $expiry, int $totalPeople, string $distance): bool {
    $distLine = $distance
        ? "<p>📍 Distance: <strong>{$distance}</strong></p>"
        : '';

    $body = "
        <div style='font-family:sans-serif;max-width:520px;margin:auto'>
        <h2 style='color:#2563eb'>🍽️ New Food Donation Near You</h2>
        <p>Hi <strong>" . htmlspecialchars($toName) . "</strong>,</p>
        <p>A new donation is available and waiting for an NGO to accept it.</p>
        <table style='border-collapse:collapse;width:100%'>
          <tr><td style='padding:6px 0'><strong>Food:</strong></td><td>" . htmlspecialchars($foodName) . "</td></tr>
          <tr><td style='padding:6px 0'><strong>Donor:</strong></td><td>" . htmlspecialchars($donorName) . "</td></tr>
          <tr><td style='padding:6px 0'><strong>Serves:</strong></td><td>{$totalPeople} people</td></tr>
          <tr><td style='padding:6px 0'><strong>Expires:</strong></td><td>" . htmlspecialchars($expiry) . "</td></tr>
          <tr><td style='padding:6px 0'><strong>Address:</strong></td><td>" . htmlspecialchars($address) . "</td></tr>
        </table>
        {$distLine}
        <p><a href='http://localhost/NexFeedAI/ngo_dashboard.php'
              style='background:#2563eb;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none'>
           Accept on Dashboard →</a></p>
        <p style='color:#6b7280;font-size:13px'>— NEXFEEDAI Team</p>
        </div>";

    return send_email($toEmail, $toName, "🍽️ New Donation: {$foodName}", $body);
}

// ── 6. Notify donor that delivery was completed (NGO confirmed) ──
function send_pickup_notification(string $toEmail, string $toName,
                                   string $foodName, string $ngoName): bool {
    $body = "
        <div style='font-family:sans-serif;max-width:520px;margin:auto'>
        <h2 style='color:#16a34a'>🎉 Your Donation Was Successfully Delivered!</h2>
        <p>Hi <strong>" . htmlspecialchars($toName) . "</strong>,</p>
        <p>Your donation <strong>\"" . htmlspecialchars($foodName) . "\"</strong>
           has been delivered and confirmed received by
           <strong>" . htmlspecialchars($ngoName) . "</strong>.</p>
        <p>Thank you for helping feed people in need. Your kindness made a real difference! 🙏</p>
        <p><a href='http://localhost/NexFeedAI/donor_dashboard.php'
              style='background:#16a34a;color:#fff;padding:10px 20px;border-radius:6px;text-decoration:none'>
           View History →</a></p>
        <p style='color:#6b7280;font-size:13px'>— NEXFEEDAI Team</p>
        </div>";

    return send_email($toEmail, $toName, "🎉 Donation Delivered: {$foodName}", $body);
}
