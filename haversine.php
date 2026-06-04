<?php
/**
 * haversine.php — NEXFEEDAI
 * ==========================
 * ISSUE 2 FIX: Distance Calculation
 *
 * WRONG (old approach):
 *   AVG(latitude), AVG(longitude) — this was averaging multiple donation
 *   coordinates which gave a random middle point, not the NGO's real location.
 *
 * CORRECT approach:
 *   Each user (NGO, volunteer) has their OWN latitude/longitude in the users table.
 *   This is saved when they open their dashboard (save_location.php via JS).
 *   We use THAT location for all distance calculations.
 *
 * ISSUE 3 FIX: Notification Logic
 *   - Notify TOP N nearest (not all) NGOs
 *   - Notify TOP N nearest available volunteers
 *   - Sorted strictly by distance (Haversine)
 */

if (defined('HAVERSINE_LOADED')) return;
define('HAVERSINE_LOADED', true);

// ── How many nearest users to notify ─────────────────────────
define('NOTIFY_TOP_NGOS',       5);  // notify 5 nearest NGOs
define('NOTIFY_TOP_VOLUNTEERS', 5);  // notify 5 nearest volunteers

/**
 * Haversine formula — accurate great-circle distance between two GPS points.
 * Returns distance in kilometres (rounded to 2 decimal places).
 *
 * Note: Haversine is accurate to ~0.5% for distances under 500 km.
 * For food donation (< 50 km typical range), this is more than sufficient.
 */
function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float {
    if ($lat1 == 0 || $lon1 == 0 || $lat2 == 0 || $lon2 == 0) return 99999.0;
    $R    = 6371.0; // Earth radius in km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a    = sin($dLat/2) * sin($dLat/2)
          + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
          * sin($dLon/2) * sin($dLon/2);
    $c    = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return round($R * $c, 2);
}

/**
 * Add distance_km to each row and sort nearest first.
 * Uses the row's own latitude/longitude (donor's location).
 *
 * @param array  $rows     DB rows with latitude/longitude columns
 * @param float  $refLat   Reference point latitude  (NGO or volunteer location)
 * @param float  $refLng   Reference point longitude
 */
function sort_by_distance(array $rows, float $refLat, float $refLng): array {
    foreach ($rows as &$r) {
        $lat = (float)($r['latitude']  ?? 0);
        $lng = (float)($r['longitude'] ?? 0);
        $r['distance_km'] = ($lat && $lng) ? haversine($refLat, $refLng, $lat, $lng) : 99999.0;
    }
    unset($r);
    usort($rows, fn($a, $b) => $a['distance_km'] <=> $b['distance_km']);
    return $rows;
}

/**
 * Human-readable distance label
 */
function km_label(float $km): string {
    if ($km >= 9999) return '—';
    if ($km < 1)     return (int)round($km * 1000) . ' m';
    return $km . ' km';
}

/**
 * ISSUE 3 FIX: Get top N nearest approved NGOs (with coordinates)
 * FIX: Now also SELECTs name and email so upload_food.php can send emails.
 * Returns array of ['id' => int, 'name' => string, 'email' => string, 'dist' => float|null]
 * Sorted nearest first.
 *
 * @param float $donorLat  Donor's latitude
 * @param float $donorLng  Donor's longitude
 * @param int   $topN      How many to notify (default: NOTIFY_TOP_NGOS)
 */
function get_nearest_ngos(float $donorLat, float $donorLng, int $topN = NOTIFY_TOP_NGOS): array {
    global $conn;

    // FIX: Added name, email to SELECT so callers can send email notifications
    $res = $conn->query(
        "SELECT id, name, email, latitude, longitude
         FROM users
         WHERE role='ngo'
           AND verification_status='approved'
           AND latitude IS NOT NULL
           AND latitude != 0
           AND longitude IS NOT NULL
           AND longitude != 0"
    );

    $withDist = [];
    $noDist   = []; // NGOs without location — notify them too but after nearby ones

    while ($row = $res->fetch_assoc()) {
        $dist = haversine($donorLat, $donorLng, (float)$row['latitude'], (float)$row['longitude']);
        $withDist[] = [
            'id'    => (int)$row['id'],
            'name'  => $row['name']  ?? '',
            'email' => $row['email'] ?? '',
            'dist'  => $dist,
        ];
    }

    // FIX: Also SELECT name, email for NGOs without saved location
    $res2 = $conn->query(
        "SELECT id, name, email FROM users
         WHERE role='ngo'
           AND verification_status='approved'
           AND (latitude IS NULL OR latitude = 0)"
    );
    while ($row = $res2->fetch_assoc()) {
        $noDist[] = [
            'id'    => (int)$row['id'],
            'name'  => $row['name']  ?? '',
            'email' => $row['email'] ?? '',
            'dist'  => null,
        ];
    }

    // Sort by distance
    usort($withDist, fn($a, $b) => $a['dist'] <=> $b['dist']);

    // Take top N from nearby, then append no-location ones
    $nearest = array_slice($withDist, 0, $topN);

    // If we have fewer than topN with location, fill up with no-location NGOs
    $remaining = $topN - count($nearest);
    if ($remaining > 0 && !empty($noDist)) {
        $nearest = array_merge($nearest, array_slice($noDist, 0, $remaining));
    }

    return $nearest;
}

/**
 * ISSUE 3 FIX: Get top N nearest available volunteers
 * Called when NGO accepts a donation.
 *
 * @param float $pickupLat  Donation pickup latitude
 * @param float $pickupLng  Donation pickup longitude
 * @param int   $topN       How many to notify
 */
function get_nearest_volunteers(float $pickupLat, float $pickupLng, int $topN = NOTIFY_TOP_VOLUNTEERS): array {
    global $conn;

    // Only available volunteers with saved GPS location
    $res = $conn->query(
        "SELECT id, latitude, longitude
         FROM users
         WHERE role='volunteer'
           AND verification_status='approved'
           AND availability='available'
           AND latitude IS NOT NULL
           AND latitude != 0
           AND longitude IS NOT NULL
           AND longitude != 0"
    );

    $withDist = [];
    $noDist   = [];

    while ($row = $res->fetch_assoc()) {
        $dist = haversine($pickupLat, $pickupLng, (float)$row['latitude'], (float)$row['longitude']);
        $withDist[] = ['id' => (int)$row['id'], 'dist' => $dist];
    }

    // Volunteers without location
    $res2 = $conn->query(
        "SELECT id FROM users
         WHERE role='volunteer'
           AND verification_status='approved'
           AND availability='available'
           AND (latitude IS NULL OR latitude = 0)"
    );
    while ($row = $res2->fetch_assoc()) {
        $noDist[] = ['id' => (int)$row['id'], 'dist' => null];
    }

    usort($withDist, fn($a, $b) => $a['dist'] <=> $b['dist']);

    $nearest   = array_slice($withDist, 0, $topN);
    $remaining = $topN - count($nearest);
    if ($remaining > 0 && !empty($noDist)) {
        $nearest = array_merge($nearest, array_slice($noDist, 0, $remaining));
    }

    return $nearest;
}
