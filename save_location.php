<?php
// save_location.php — NEXFEEDAI
// Saves user's GPS location so they receive location-based priority notifications.
// Called via AJAX from dashboard with POST: { lat, lng }
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'msg' => 'Not logged in']);
    exit();
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'POST required']);
    exit();
}

$uid = (int)$_SESSION['user_id'];
$lat = isset($_POST['lat']) && is_numeric($_POST['lat']) ? (float)$_POST['lat'] : null;
$lng = isset($_POST['lng']) && is_numeric($_POST['lng']) ? (float)$_POST['lng'] : null;

if (!$lat || !$lng) {
    echo json_encode(['ok' => false, 'msg' => 'Invalid coordinates']);
    exit();
}

$stmt = $conn->prepare("UPDATE users SET latitude=?, longitude=? WHERE id=?");
$stmt->bind_param('ddi', $lat, $lng, $uid);
$ok = $stmt->execute();
$stmt->close();

echo json_encode(['ok' => $ok, 'lat' => $lat, 'lng' => $lng]);
