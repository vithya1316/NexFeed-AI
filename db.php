<?php
// db.php — NEXFEEDAI
// ISSUE 4 FIX: Replaced priority labels (high/normal/low) with
// event_type labels that describe WHAT happened — not importance level.
//
// event_type values (what happened):
//   'donation_uploaded'  — donor uploaded food
//   'donation_accepted'  — NGO accepted
//   'donation_rejected'  — NGO passed
//   'volunteer_assigned' — volunteer was assigned manually
//   'job_available'      — volunteer notified of pickup job
//   'picked_up'          — volunteer picked up food
//   'delivered'          — delivery completed
//   'account_approved'   — admin approved user account
//   'account_rejected'   — admin rejected user account
//   'general'            — any other message

if (defined('DB_LOADED')) return;
define('DB_LOADED', true);

define('DB_HOST', 'localhost');
define('DB_USER', 'root');    // change for hosting
define('DB_PASS', '');        // change for hosting
define('DB_NAME', 'nexfeedai');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    error_log('DB error: ' . $conn->connect_error);
    die('<p style="font-family:sans-serif;color:red;padding:30px;">Database connection failed. Check db.php config.</p>');
}
$conn->set_charset('utf8mb4');

// ── Core helpers ──────────────────────────────────────────────
function safe_int($v, int $d = 0): int { return is_numeric($v) ? (int)$v : $d; }
function safe_str($v, string $d = ''): string { return trim((string)($v ?? $d)); }
function redirect(string $url): void  { header('Location: ' . $url); exit(); }

function flash(string $key, string $msg): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION[$key] = $msg;
}
function get_flash(string $key): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $v = $_SESSION[$key] ?? ''; unset($_SESSION[$key]); return $v;
}

// ── ISSUE 4 FIX: notify_event() — uses event_type, not priority ──
/**
 * Store an in-app notification.
 *
 * @param int    $uid        User to notify
 * @param string $event_type What happened (see list above)
 * @param string $message    Human-readable message
 * @param int    $don_id     Related donation ID (0 if none)
 */
function notify_event(int $uid, string $event_type, string $message, int $don_id = 0): void {
    global $conn;
    $d = $don_id ?: null;

    // Try with event_type column first
    $s = $conn->prepare(
        'INSERT INTO notifications (user_id, message, event_type, donation_id, is_read, created_at)
         VALUES (?, ?, ?, ?, 0, NOW())'
    );
    if ($s) {
        $s->bind_param('issi', $uid, $message, $event_type, $d);
        if (!$s->execute()) {
            // Fallback: insert without event_type (old schema)
            $s->close();
            $s2 = $conn->prepare('INSERT INTO notifications (user_id, message, donation_id, is_read, created_at) VALUES (?,?,?,0,NOW())');
            if ($s2) { $s2->bind_param('isi', $uid, $message, $d); $s2->execute(); $s2->close(); }
        } else { $s->close(); }
    }
}

// Backward compatible alias — old code that calls notify() still works
function notify(int $uid, string $msg, string $type='info', int $did=0, string $p='normal'): void {
    // Map old 'type' to event_type as best we can
    $event = match($type) {
        'success' => 'delivered',
        'warning' => 'volunteer_assigned',
        default   => 'general',
    };
    notify_event($uid, $event, $msg, $did);
}

/**
 * Notify available volunteers — wrapper used by ngo_action.php
 */
function notify_volunteers(string $msg, string $type='info', int $did=0, string $p='normal'): void {
    global $conn;
    $res = $conn->query("SELECT id FROM users WHERE role='volunteer' AND verification_status='approved' AND availability='available'");
    while ($r = $res->fetch_assoc()) notify_event((int)$r['id'], 'job_available', $msg, $did);
}

/**
 * Unread notification count
 */
function unread_count(int $uid): int {
    global $conn;
    $r = $conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id=$uid AND is_read=0");
    return $r ? (int)$r->fetch_assoc()['c'] : 0;
}
